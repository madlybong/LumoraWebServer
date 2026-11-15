import { randomBytes } from "node:crypto";
import { existsSync } from "node:fs";
import path from "node:path";
import { evalPhpJson, resolveRuntimeDir, toPosixPath } from "./paths";

export type AdminCliSubcommand =
  | "create-user"
  | "list-users"
  | "reset-password"
  | "disable-2fa"
  | "revoke-sessions"
  | "hash-password";

export interface AdminCliOptions {
  cwd?: string;
  subcommand: string;
  email?: string;
  name?: string;
  role?: string;
  password?: string;
  silent?: boolean;
}

export function printAdminHelp(): void {
  console.log(`
@astrake/lumora-webserver — Admin CLI

Usage:
  lumora-web admin <subcommand> [options]

Subcommands:
  create-user      Create an admin user (default role: owner)
                   --email <email> [--role owner|editor|viewer] [--name <name>] [--password <pass>]
  list-users       List registered admin users in the site's SQLite vault
  reset-password   Reset an admin user's password and revoke active sessions
                   --email <email> [--password <pass>]
  disable-2fa      Disable TOTP 2FA and clear recovery codes for a user
                   --email <email>
  revoke-sessions  Revoke active sessions (all users, or pass --email <email>)
  hash-password    Generate an Argon2id password hash for admin.initial_owner
                   [--password <pass>]
`);
}

function generateStrongPassword(): string {
  return randomBytes(15).toString("base64url");
}

function resolveBootstrapPhp(siteRoot: string): string {
  const siteBootstrap = path.join(siteRoot, "core", "bootstrap.php");
  if (existsSync(siteBootstrap)) {
    return toPosixPath(siteBootstrap);
  }
  return toPosixPath(path.join(resolveRuntimeDir(), "php", "core", "bootstrap.php"));
}

export function runAdminCli(options: AdminCliOptions): Record<string, unknown> {
  const siteRoot = path.resolve(options.cwd ?? process.cwd());
  const rootPosix = toPosixPath(siteRoot);
  const bootstrapPosix = resolveBootstrapPhp(siteRoot);
  const sub = options.subcommand.trim().toLowerCase();

  if (sub === "hash-password") {
    const pwd = options.password ?? generateStrongPassword();
    if (pwd.length < 10) {
      throw new Error("Password must be at least 10 characters.");
    }
    const script = `
      define('LUMORA_BOOTSTRAPPED', true);
      require_once ${JSON.stringify(bootstrapPosix)};
      $hash = \\Lumora\\Core\\AdminAuth::hashPassword(${JSON.stringify(pwd)});
      echo json_encode(['ok' => true, 'password_hash' => $hash], JSON_UNESCAPED_SLASHES);
    `;
    const res = evalPhpJson<{ ok: boolean; password_hash: string }>(script, siteRoot);
    const out: Record<string, unknown> = {
      ok: true,
      password_generated: !options.password,
      password: pwd,
      password_hash: res.password_hash,
    };
    if (!options.silent) {
      if (!options.password) {
        console.log(`[lumora-web admin] Generated password (shown once): ${pwd}`);
      }
      console.log(`[lumora-web admin] Argon2id hash: ${res.password_hash}`);
    }
    return out;
  }

  if (sub === "create-user") {
    const email = options.email?.trim().toLowerCase() ?? "";
    if (!email) {
      throw new Error("create-user requires --email <email>");
    }
    const role = (options.role ?? "owner").trim().toLowerCase();
    const name = options.name?.trim() ?? "";
    const pwd = options.password ?? generateStrongPassword();
    const script = `
      define('LUMORA_BOOTSTRAPPED', true);
      require_once ${JSON.stringify(bootstrapPosix)};
      \\Lumora\\Core\\Bootstrap::init(${JSON.stringify(rootPosix)});
      $pdo = \\Lumora\\Core\\AdminDb::connect(${JSON.stringify(rootPosix)});
      if (!$pdo instanceof \\PDO) {
        echo json_encode(['ok' => false, 'error' => 'SQLite admin database is unavailable.']);
        exit(0);
      }
      $res = \\Lumora\\Core\\AdminAuth::createUser(
        $pdo,
        ${JSON.stringify(email)},
        ${JSON.stringify(name)},
        ${JSON.stringify(role)},
        ${JSON.stringify(pwd)},
        ${JSON.stringify(rootPosix)}
      );
      if (!empty($res['ok'])) {
        \\Lumora\\Core\\AdminDb::logAudit(
          null,
          'cli',
          'cli.create_user',
          'admin_user',
          (string)($res['user']['id'] ?? ''),
          ['email' => $res['user']['email'], 'role' => $res['user']['role']],
          ${JSON.stringify(rootPosix)}
        );
      }
      \\Lumora\\Core\\AdminDb::resetMigrationCache();
      \\Lumora\\Core\\InquiryDb::resetConnection();
      echo json_encode($res, JSON_UNESCAPED_SLASHES);
    `;
    const res = evalPhpJson<{ ok: boolean; error?: string; user?: Record<string, unknown> }>(script, siteRoot);
    if (!res.ok) {
      throw new Error(res.error ?? "Failed to create admin user.");
    }
    const out: Record<string, unknown> = {
      ok: true,
      user: res.user,
      password_generated: !options.password,
      password: pwd,
    };
    if (!options.silent) {
      console.log(
        `[lumora-web admin] Created ${String(res.user?.role ?? role)} user: ${String(res.user?.email ?? email)}`
      );
      if (!options.password) {
        console.log(`[lumora-web admin] Temporary password (shown once): ${pwd}`);
      }
    }
    return out;
  }

  if (sub === "list-users") {
    const script = `
      define('LUMORA_BOOTSTRAPPED', true);
      require_once ${JSON.stringify(bootstrapPosix)};
      \\Lumora\\Core\\Bootstrap::init(${JSON.stringify(rootPosix)});
      $pdo = \\Lumora\\Core\\AdminDb::connect(${JSON.stringify(rootPosix)});
      $rows = $pdo ? $pdo->query('SELECT id, email, name, role, totp_enabled, is_active, last_login_at, created_at FROM admin_users ORDER BY id ASC')->fetchAll(\\PDO::FETCH_ASSOC) : [];
      \\Lumora\\Core\\AdminDb::resetMigrationCache();
      \\Lumora\\Core\\InquiryDb::resetConnection();
      echo json_encode(['ok' => true, 'users' => $rows], JSON_UNESCAPED_SLASHES);
    `;
    const res = evalPhpJson<{ ok: boolean; users: Array<Record<string, unknown>> }>(script, siteRoot);
    if (!options.silent) {
      if (res.users.length === 0) {
        console.log("[lumora-web admin] No admin users registered.");
      } else {
        for (const u of res.users) {
          console.log(
            `#${String(u.id)}  ${String(u.email)}  [${String(u.role)}]  active=${Boolean(Number(u.is_active))}  2fa=${Boolean(Number(u.totp_enabled))}  last_login=${String(u.last_login_at || "never")}`
          );
        }
      }
    }
    return res as unknown as Record<string, unknown>;
  }

  if (sub === "reset-password") {
    const email = options.email?.trim().toLowerCase() ?? "";
    if (!email) {
      throw new Error("reset-password requires --email <email>");
    }
    const pwd = options.password ?? generateStrongPassword();
    if (pwd.length < 10) {
      throw new Error("Password must be at least 10 characters.");
    }
    const script = `
      define('LUMORA_BOOTSTRAPPED', true);
      require_once ${JSON.stringify(bootstrapPosix)};
      \\Lumora\\Core\\Bootstrap::init(${JSON.stringify(rootPosix)});
      $pdo = \\Lumora\\Core\\AdminDb::connect(${JSON.stringify(rootPosix)});
      if (!$pdo instanceof \\PDO) {
        echo json_encode(['ok' => false, 'error' => 'SQLite admin database is unavailable.']);
        exit(0);
      }
      $stmt = $pdo->prepare('SELECT id, email FROM admin_users WHERE email = :e LIMIT 1');
      $stmt->execute([':e' => ${JSON.stringify(email)}]);
      $row = $stmt->fetch(\\PDO::FETCH_ASSOC);
      if (!is_array($row)) {
        \\Lumora\\Core\\AdminDb::resetMigrationCache();
        \\Lumora\\Core\\InquiryDb::resetConnection();
        echo json_encode(['ok' => false, 'error' => 'Admin user not found.']);
        exit(0);
      }
      $uid = (int)$row['id'];
      $hash = \\Lumora\\Core\\AdminAuth::hashPassword(${JSON.stringify(pwd)});
      $now = gmdate('Y-m-d\\TH:i:s\\Z');
      $pdo->prepare('UPDATE admin_users SET password_hash = :h, updated_at = :u WHERE id = :id')
        ->execute([':h' => $hash, ':u' => $now, ':id' => $uid]);
      \\Lumora\\Core\\AdminAuth::revokeUserSessions($pdo, $uid, null);
      \\Lumora\\Core\\AdminDb::logAudit(null, 'cli', 'cli.reset_password', 'admin_user', (string)$uid, ['email' => $row['email']], ${JSON.stringify(rootPosix)});
      \\Lumora\\Core\\AdminDb::resetMigrationCache();
      \\Lumora\\Core\\InquiryDb::resetConnection();
      echo json_encode(['ok' => true, 'email' => $row['email'], 'user_id' => $uid], JSON_UNESCAPED_SLASHES);
    `;
    const res = evalPhpJson<{ ok: boolean; error?: string; email?: string }>(script, siteRoot);
    if (!res.ok) {
      throw new Error(res.error ?? "Failed to reset password.");
    }
    const out: Record<string, unknown> = {
      ok: true,
      email: res.email ?? email,
      password_generated: !options.password,
      password: pwd,
    };
    if (!options.silent) {
      console.log(`[lumora-web admin] Reset password and revoked active sessions for ${email}.`);
      if (!options.password) {
        console.log(`[lumora-web admin] New password (shown once): ${pwd}`);
      }
    }
    return out;
  }

  if (sub === "disable-2fa") {
    const email = options.email?.trim().toLowerCase() ?? "";
    if (!email) {
      throw new Error("disable-2fa requires --email <email>");
    }
    const script = `
      define('LUMORA_BOOTSTRAPPED', true);
      require_once ${JSON.stringify(bootstrapPosix)};
      \\Lumora\\Core\\Bootstrap::init(${JSON.stringify(rootPosix)});
      $pdo = \\Lumora\\Core\\AdminDb::connect(${JSON.stringify(rootPosix)});
      if (!$pdo instanceof \\PDO) {
        echo json_encode(['ok' => false, 'error' => 'SQLite admin database is unavailable.']);
        exit(0);
      }
      $stmt = $pdo->prepare('SELECT id, email FROM admin_users WHERE email = :e LIMIT 1');
      $stmt->execute([':e' => ${JSON.stringify(email)}]);
      $row = $stmt->fetch(\\PDO::FETCH_ASSOC);
      if (!is_array($row)) {
        \\Lumora\\Core\\AdminDb::resetMigrationCache();
        \\Lumora\\Core\\InquiryDb::resetConnection();
        echo json_encode(['ok' => false, 'error' => 'Admin user not found.']);
        exit(0);
      }
      $uid = (int)$row['id'];
      $now = gmdate('Y-m-d\\TH:i:s\\Z');
      $pdo->prepare('UPDATE admin_users SET totp_enabled = 0, totp_secret_enc = "", totp_pending_enc = "", totp_last_step = 0, recovery_codes_json = "[]", updated_at = :u WHERE id = :id')
        ->execute([':u' => $now, ':id' => $uid]);
      \\Lumora\\Core\\AdminDb::logAudit(null, 'cli', 'cli.disable_2fa', 'admin_user', (string)$uid, ['email' => $row['email']], ${JSON.stringify(rootPosix)});
      \\Lumora\\Core\\AdminDb::resetMigrationCache();
      \\Lumora\\Core\\InquiryDb::resetConnection();
      echo json_encode(['ok' => true, 'email' => $row['email']], JSON_UNESCAPED_SLASHES);
    `;
    const res = evalPhpJson<{ ok: boolean; error?: string; email?: string }>(script, siteRoot);
    if (!res.ok) {
      throw new Error(res.error ?? "Failed to disable 2FA.");
    }
    if (!options.silent) {
      console.log(`[lumora-web admin] Disabled 2FA and cleared recovery codes for ${email}.`);
    }
    return res as unknown as Record<string, unknown>;
  }

  if (sub === "revoke-sessions") {
    const email = options.email?.trim().toLowerCase() ?? "";
    const script = `
      define('LUMORA_BOOTSTRAPPED', true);
      require_once ${JSON.stringify(bootstrapPosix)};
      \\Lumora\\Core\\Bootstrap::init(${JSON.stringify(rootPosix)});
      $pdo = \\Lumora\\Core\\AdminDb::connect(${JSON.stringify(rootPosix)});
      if (!$pdo instanceof \\PDO) {
        echo json_encode(['ok' => false, 'error' => 'SQLite admin database is unavailable.']);
        exit(0);
      }
      $email = ${JSON.stringify(email)};
      if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $uid = $stmt->fetchColumn();
        if ($uid === false) {
          \\Lumora\\Core\\AdminDb::resetMigrationCache();
          \\Lumora\\Core\\InquiryDb::resetConnection();
          echo json_encode(['ok' => false, 'error' => 'Admin user not found.']);
          exit(0);
        }
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM admin_sessions WHERE user_id = :id');
        $countStmt->execute([':id' => (int)$uid]);
        $revoked = (int)$countStmt->fetchColumn();
        \\Lumora\\Core\\AdminAuth::revokeUserSessions($pdo, (int)$uid, null);
        \\Lumora\\Core\\AdminDb::logAudit(null, 'cli', 'cli.revoke_sessions', 'admin_user', (string)$uid, ['revoked' => $revoked], ${JSON.stringify(rootPosix)});
      } else {
        $revoked = (int)$pdo->query('SELECT COUNT(*) FROM admin_sessions')->fetchColumn();
        $pdo->exec('DELETE FROM admin_sessions');
        \\Lumora\\Core\\AdminDb::logAudit(null, 'cli', 'cli.revoke_sessions', 'admin_session', 'all', ['revoked' => $revoked], ${JSON.stringify(rootPosix)});
      }
      \\Lumora\\Core\\AdminDb::resetMigrationCache();
      \\Lumora\\Core\\InquiryDb::resetConnection();
      echo json_encode(['ok' => true, 'revoked_count' => $revoked], JSON_UNESCAPED_SLASHES);
    `;
    const res = evalPhpJson<{ ok: boolean; error?: string; revoked_count?: number }>(script, siteRoot);
    if (!res.ok) {
      throw new Error(res.error ?? "Failed to revoke sessions.");
    }
    if (!options.silent) {
      console.log(
        `[lumora-web admin] Revoked ${res.revoked_count ?? 0} active session(s)${email ? ` for ${email}` : ""}.`
      );
    }
    return res as unknown as Record<string, unknown>;
  }

  throw new Error(
    `Unknown admin subcommand "${options.subcommand}". Expected create-user, list-users, reset-password, disable-2fa, revoke-sessions, or hash-password.`
  );
}
