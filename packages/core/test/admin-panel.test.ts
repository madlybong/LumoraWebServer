import { afterAll, describe, expect, test } from "bun:test";
import { spawn } from "node:child_process";
import {
  cpSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import net from "node:net";
import os from "node:os";
import path from "node:path";
import { runAdminCli } from "../src/cli/admin";
import { buildWebsiteDist } from "../src/cli/build-dist";
import { initWebsite } from "../src/cli/init";
import { evalPhpJson, resolveRuntimeDir, toPosixPath } from "../src/cli/paths";
import { syncWebsite } from "../src/cli/sync";
import { verifyWebsite } from "../src/cli/verify";
import { LumoraWebConfigSchema } from "../src/types/config";

const REPO_ROOT = path.resolve(import.meta.dir, "..", "..", "..");
const STARTER_DIR = path.join(REPO_ROOT, "apps", "starter");
const RUNTIME_CORE_POSIX = toPosixPath(path.join(resolveRuntimeDir(), "php", "core"));

const tempDirs: string[] = [];
const childProcesses: Array<{ kill: () => void }> = [];

function createTempDir(prefix: string): string {
  const dir = mkdtempSync(path.join(os.tmpdir(), `lumora-${prefix}-`));
  tempDirs.push(dir);
  return dir;
}

function copyStarterWithoutStorage(targetDir: string): void {
  cpSync(STARTER_DIR, targetDir, {
    recursive: true,
    filter: (src) => {
      const rel = toPosixPath(path.relative(STARTER_DIR, src));
      if (rel === "storage" || rel.startsWith("storage/") || rel === "dist" || rel.startsWith("dist/")) {
        return false;
      }
      if (rel === "node_modules" || rel.startsWith("node_modules/")) {
        return false;
      }
      return true;
    },
  });
}

function phpBootstrapSnippet(rootDir: string, useSiteCore = false): string {
  const rootPosix = toPosixPath(rootDir);
  const bootstrapPath = useSiteCore
    ? toPosixPath(path.join(rootDir, "core", "bootstrap.php"))
    : `${RUNTIME_CORE_POSIX}/bootstrap.php`;
  return `
    define('LUMORA_BOOTSTRAPPED', true);
    $lumoraRoot = ${JSON.stringify(rootPosix)};
    require_once ${JSON.stringify(bootstrapPath)};
    $lumoraConfig = \\Lumora\\Core\\Bootstrap::init($lumoraRoot);
  `;
}

function phpHelpersSnippet(): string {
  return `
    $bindLatestSessionCookie = static function (PDO $pdo) use ($lumoraRoot): void {
      $rawToken = bin2hex(random_bytes(32));
      $hash = hash_hmac('sha256', 'lumora_admin_sess|' . $rawToken, \\Lumora\\Core\\Security::secretKey($lumoraRoot));
      $maxId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM admin_sessions')->fetchColumn();
      if ($maxId > 0) {
        $pdo->prepare('UPDATE admin_sessions SET session_hash = :h WHERE id = :id')->execute([':h' => $hash, ':id' => $maxId]);
      }
      $_COOKIE[\\Lumora\\Core\\AdminAuth::cookieName()] = $rawToken;
    };

    $loginSessionAs = static function (PDO $pdo, array $cfg, string $email, string $password) use ($lumoraRoot, $bindLatestSessionCookie): array {
      $res = \\Lumora\\Core\\AdminAuth::login($pdo, $cfg, $lumoraRoot, $email, $password);
      $bindLatestSessionCookie($pdo);
      $_SERVER['HTTP_X_LUMORA_CSRF'] = (string)($res['csrf_token'] ?? '');
      return $res;
    };

    $callApi = static function (string $action, string $method, array $body, array $cfg, array $query = []) use ($lumoraRoot): array {
      $_SERVER['REQUEST_METHOD'] = $method;
      $_GET = array_merge($query, ['action' => $action]);
      $GLOBALS['lumoraAdminTestBody'] = $body;
      http_response_code(200);
      ob_start();
      \\Lumora\\Core\\AdminController::serveApi($cfg, $lumoraRoot, '/admin/', '');
      $raw = (string)ob_get_clean();
      return ['code' => http_response_code(), 'raw' => $raw, 'json' => json_decode($raw, true)];
    };
  `;
}

function phpCleanupSnippet(): string {
  return `
    $pdo = null;
    if (class_exists(\\Lumora\\Core\\AdminDb::class, false)) {
      \\Lumora\\Core\\AdminDb::resetMigrationCache();
    }
    if (class_exists(\\Lumora\\Core\\InquiryDb::class, false)) {
      \\Lumora\\Core\\InquiryDb::resetConnection();
    }
  `;
}

async function getFreePort(): Promise<number> {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.unref();
    server.on("error", reject);
    server.listen(0, "127.0.0.1", () => {
      const addr = server.address();
      if (!addr || typeof addr === "string") {
        server.close();
        reject(new Error("Failed to allocate ephemeral port"));
        return;
      }
      const { port } = addr;
      server.close(() => resolve(port));
    });
  });
}

async function waitForHttp(url: string, timeoutMs = 8000): Promise<void> {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    try {
      const res = await fetch(url);
      if (res.status > 0) {
        return;
      }
    } catch {
      // retry until php -S accepts connections
    }
    await new Promise((r) => setTimeout(r, 80));
  }
  throw new Error(`Timed out waiting for PHP dev server at ${url}`);
}

async function stopChildProcess(proc: ReturnType<typeof spawn>): Promise<void> {
  if (proc.exitCode !== null || proc.killed) {
    return;
  }
  await new Promise<void>((resolve) => {
    const timer = setTimeout(resolve, 1500);
    proc.once("exit", () => {
      clearTimeout(timer);
      resolve();
    });
    try {
      proc.kill();
    } catch {
      clearTimeout(timer);
      resolve();
    }
  });
}

afterAll(() => {
  for (const proc of childProcesses) {
    try {
      proc.kill();
    } catch {
      // ignore
    }
  }
  for (const dir of tempDirs) {
    try {
      rmSync(dir, { recursive: true, force: true });
    } catch {
      // ignore transient Windows file locks after process exit
    }
  }
});

describe("Admin Panel & Hybrid SQLite CMS", () => {
  test("LumoraWebConfigSchema defaults features.admin_panel to false and validates admin block", () => {
    const baseConfig = {
      brand: {
        id: "acme",
        prefix: "ACM",
        name: "Acme Corp",
        domain: "acme.example.com",
        origin: "https://acme.example.com",
      },
      legal_entity: {
        name: "Acme Holdings Ltd",
        type: "Private Limited",
        address: "100 Market St",
        email: "legal@acme.example.com",
      },
    };

    const parsedDefault = LumoraWebConfigSchema.parse(baseConfig);
    expect(parsedDefault.features?.admin_panel ?? false).toBe(false);

    const validFull = LumoraWebConfigSchema.safeParse({
      ...baseConfig,
      features: {
        admin_panel: true,
      },
      admin: {
        path: "/_console/",
        session_ttl_sec: 3600,
        require_2fa: true,
        allowed_roles: ["owner", "editor"],
        max_login_attempts: 4,
        lockout_window_sec: 600,
        max_upload_bytes: 2 * 1024 * 1024,
        branding: {
          title: "Acme Control",
          logo: "/public/brand/logo.svg",
          logo_dark: "/public/brand/logo-dark.svg",
          default_theme: "dark",
        },
        initial_owner: {
          email: "owner@acme.example.com",
          name: "Acme Owner",
          password_hash: "$argon2id$v=19$m=65536,t=4,p=1$c2FsdHlzYWx0c2FsdA$hashvalue",
        },
      },
    });
    expect(validFull.success).toBe(true);

    const invalidTheme = LumoraWebConfigSchema.safeParse({
      ...baseConfig,
      features: { admin_panel: true },
      admin: {
        branding: {
          default_theme: "sepia",
        },
      },
    });
    expect(invalidTheme.success).toBe(false);

    const invalidRole = LumoraWebConfigSchema.safeParse({
      ...baseConfig,
      features: { admin_panel: true },
      admin: {
        allowed_roles: ["superadmin"],
      },
    });
    expect(invalidRole.success).toBe(false);

    const invalidTtl = LumoraWebConfigSchema.safeParse({
      ...baseConfig,
      features: { admin_panel: true },
      admin: {
        session_ttl_sec: -10,
      },
    });
    expect(invalidTtl.success).toBe(false);
  });

  test("Zero-write public GET guarantee leaves storage/ uncreated across all engines", () => {
    const siteDir = createTempDir("zero-write");
    copyStarterWithoutStorage(siteDir);
    expect(existsSync(path.join(siteDir, "storage"))).toBe(false);

    const res = evalPhpJson<{
      db_before: boolean;
      posts_count: number;
      docs_tracks: number;
      legal_slugs: string[];
      legal_resolved: boolean;
      route_template: string | null;
      storage_exists_after: boolean;
      admin_db_exists_after: boolean;
    }>(
      `
      ${phpBootstrapSnippet(siteDir)}
      $existing = \\Lumora\\Core\\AdminDb::connectExisting($lumoraRoot);
      $posts = \\Lumora\\Core\\BlogEngine::listPosts($lumoraRoot);
      $docsEngine = new \\Lumora\\Core\\DocsEngine($lumoraRoot . '/content/docs');
      $manifest = $docsEngine->getManifest(false);
      $slugs = \\Lumora\\Core\\LegalRegistry::activeSlugs($lumoraConfig, $lumoraRoot);
      $legalDoc = \\Lumora\\Core\\LegalRegistry::resolveDocument($slugs[0] ?? 'privacy', $lumoraConfig, $lumoraRoot);
      $matched = \\Lumora\\Core\\Router::resolve('/', $lumoraConfig, $lumoraRoot);
      ${phpCleanupSnippet()}
      echo json_encode([
        'db_before' => $existing !== null,
        'posts_count' => count($posts),
        'docs_tracks' => count($manifest['tracks'] ?? []),
        'legal_slugs' => $slugs,
        'legal_resolved' => is_array($legalDoc),
        'route_template' => $matched['template'] ?? null,
        'storage_exists_after' => is_dir($lumoraRoot . '/storage'),
        'admin_db_exists_after' => is_file($lumoraRoot . '/storage/admin.sqlite'),
      ]);
      `,
      siteDir
    );

    expect(res.db_before).toBe(false);
    expect(res.posts_count).toBeGreaterThan(0);
    expect(res.docs_tracks).toBeGreaterThan(0);
    expect(res.legal_slugs.length).toBeGreaterThan(0);
    expect(res.legal_resolved).toBe(true);
    expect(res.route_template).toBe("home");
    expect(res.storage_exists_after).toBe(false);
    expect(res.admin_db_exists_after).toBe(false);
    expect(existsSync(path.join(siteDir, "storage"))).toBe(false);
  });

  test("AES-256-GCM settings encryption roundtrip, tamper rejection, and dispatch/hub overlay", () => {
    const siteDir = createTempDir("crypto-overlay");
    copyStarterWithoutStorage(siteDir);

    const res = evalPhpJson<{
      decrypted: string | null;
      is_secret: number;
      contains_plaintext: boolean;
      tampered: string | null;
      save_code: number;
      status_code: number;
      masked_has_secret: boolean;
      masked_has_secret_flag: boolean;
      hub_enabled: boolean;
      hub_endpoint: string;
      hub_resolved_secret: string;
    }>(
      `
      ${phpBootstrapSnippet(siteDir)}
      ${phpHelpersSnippet()}
      $pdo = \\Lumora\\Core\\AdminDb::connect($lumoraRoot);
      $secret = 'whsec_test_9876543210_secret';
      \\Lumora\\Core\\AdminDb::setSetting('hub.shared_secret', $secret, true, null, $lumoraRoot);
      $row = $pdo->query("SELECT value_enc, is_secret FROM admin_settings WHERE key = 'hub.shared_secret'")->fetch(\\PDO::FETCH_ASSOC);
      $decrypted = \\Lumora\\Core\\AdminDb::getSetting('hub.shared_secret', $lumoraRoot, true);

      // Tamper ciphertext byte
      $raw = (string)($row['value_enc'] ?? '');
      $tamperedEnc = substr($raw, 0, -4) . (substr($raw, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
      $stmt = $pdo->prepare("UPDATE admin_settings SET value_enc = :v WHERE key = 'hub.shared_secret'");
      $stmt->execute([':v' => $tamperedEnc]);
      $tampered = \\Lumora\\Core\\AdminDb::getSetting('hub.shared_secret', $lumoraRoot, true);

      // Seed owner + verify dispatch.save & dispatch.status encrypted overlay + HubClient overlay
      $hash = \\Lumora\\Core\\AdminAuth::hashPassword('SuperStrongOwner#2026');
      $lumoraConfig['admin']['initial_owner'] = [
        'email' => 'owner@overlay.test',
        'name' => 'Overlay Owner',
        'password_hash' => $hash,
      ];
      $GLOBALS['lumoraConfig'] = $lumoraConfig;
      \\Lumora\\Core\\AdminAuth::syncInitialOwnerFromConfig($pdo, $lumoraConfig);
      $_SERVER['HTTP_USER_AGENT'] = 'LumoraTestAgent/1.0';
      $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
      $loginSessionAs($pdo, $lumoraConfig, 'owner@overlay.test', 'SuperStrongOwner#2026');

      $saveRes = $callApi('dispatch.save', 'POST', [
        'hub' => [
          'enabled' => true,
          'endpoint' => 'https://hub.overlay.test/inquiries',
          'shared_secret' => 'hub-live-secret-xyz',
        ],
      ], $lumoraConfig);

      $statusRes = $callApi('dispatch.status', 'GET', [], $lumoraConfig);
      $hubLoaded = \\Lumora\\Core\\HubClient::resolveHubConfig($lumoraConfig, $lumoraRoot);
      $hubSecret = \\Lumora\\Core\\HubClient::resolveHubSecret($lumoraConfig, $lumoraRoot);
      ${phpCleanupSnippet()}
      echo json_encode([
        'decrypted' => $decrypted,
        'is_secret' => (int)($row['is_secret'] ?? 0),
        'contains_plaintext' => str_contains($raw, $secret),
        'tampered' => $tampered,
        'save_code' => $saveRes['code'],
        'status_code' => $statusRes['code'],
        'masked_has_secret' => str_contains($statusRes['raw'], 'hub-live-secret-xyz'),
        'masked_has_secret_flag' => (bool)($statusRes['json']['hub']['has_secret'] ?? false),
        'hub_enabled' => (bool)($hubLoaded['enabled'] ?? false),
        'hub_endpoint' => (string)($hubLoaded['endpoint'] ?? ''),
        'hub_resolved_secret' => $hubSecret,
      ]);
      `,
      siteDir
    );

    expect(res.decrypted).toBe("whsec_test_9876543210_secret");
    expect(res.is_secret).toBe(1);
    expect(res.contains_plaintext).toBe(false);
    expect(res.tampered).toBeNull();
    expect(res.save_code).toBe(200);
    expect(res.status_code).toBe(200);
    expect(res.masked_has_secret).toBe(false);
    expect(res.masked_has_secret_flag).toBe(true);
    expect(res.hub_enabled).toBe(true);
    expect(res.hub_endpoint).toBe("https://hub.overlay.test/inquiries");
    expect(res.hub_resolved_secret).toBe("hub-live-secret-xyz");
  });

  test("Argon2id auth, rate-limit 429, CSRF, UA binding, RBAC, and last-owner guard", () => {
    const siteDir = createTempDir("auth-rbac");
    copyStarterWithoutStorage(siteDir);

    const res = evalPhpJson<{
      hash_is_argon2id: boolean;
      verify_ok: boolean;
      verify_bad: boolean;
      lockout_code: number;
      missing_csrf_code: number;
      ua_mismatch_code: number;
      viewer_read_code: number;
      viewer_write_code: number;
      editor_blog_save_code: number;
      editor_users_create_code: number;
      disallowed_role_code: number;
      disallowed_login_code: number;
      demote_last_owner_code: number;
      delete_last_owner_code: number;
    }>(
      `
      ${phpBootstrapSnippet(siteDir)}
      ${phpHelpersSnippet()}
      $lumoraConfig['admin']['max_login_attempts'] = 3;
      $lumoraConfig['admin']['lockout_window_sec'] = 300;
      $lumoraConfig['admin']['allowed_roles'] = ['owner', 'editor', 'viewer'];
      $ownerPass = 'OwnerPass#2026!!';
      $hash = \\Lumora\\Core\\AdminAuth::hashPassword($ownerPass);
      $lumoraConfig['admin']['initial_owner'] = [
        'email' => 'owner@rbac.test',
        'name' => 'Primary Owner',
        'password_hash' => $hash,
      ];
      $GLOBALS['lumoraConfig'] = $lumoraConfig;

      $pdo = \\Lumora\\Core\\AdminDb::connect($lumoraRoot);
      \\Lumora\\Core\\AdminAuth::syncInitialOwnerFromConfig($pdo, $lumoraConfig);

      // Rate limit lockout check from a test IP
      $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
      $_SERVER['HTTP_USER_AGENT'] = 'LumoraAuthSuite/1.0';
      $_COOKIE = [];
      $_SERVER['HTTP_X_LUMORA_CSRF'] = \\Lumora\\Core\\Security::csrfToken($lumoraRoot);
      for ($i = 0; $i < 3; $i++) {
        $callApi('auth.login', 'POST', ['email' => 'owner@rbac.test', 'password' => 'WrongPassword123'], $lumoraConfig);
      }
      $lockoutRes = $callApi('auth.login', 'POST', ['email' => 'owner@rbac.test', 'password' => $ownerPass], $lumoraConfig);

      // Login from clean IP
      $_SERVER['REMOTE_ADDR'] = '198.51.100.43';
      $ownerRes = $loginSessionAs($pdo, $lumoraConfig, 'owner@rbac.test', $ownerPass);
      $ownerRow = $pdo->query("SELECT id FROM admin_users WHERE email = 'owner@rbac.test'")->fetch(\\PDO::FETCH_ASSOC);
      $ownerId = (int)$ownerRow['id'];
      $validCsrf = (string)($ownerRes['csrf_token'] ?? '');

      // Invalid session CSRF on mutating action
      $_SERVER['HTTP_X_LUMORA_CSRF'] = 'invalid-csrf-token';
      $missingCsrfRes = $callApi('blog.save', 'POST', ['slug' => 'nope', 'title' => 'Nope'], $lumoraConfig);

      // UA mismatch rejection with valid CSRF
      $_SERVER['HTTP_X_LUMORA_CSRF'] = $validCsrf;
      $_SERVER['HTTP_USER_AGENT'] = 'DifferentUserAgent/2.0';
      $uaMismatchRes = $callApi('overview.get', 'GET', [], $lumoraConfig);

      // Restore UA and test last-owner guards + user creation
      $_SERVER['HTTP_USER_AGENT'] = 'LumoraAuthSuite/1.0';
      $demoteLast = $callApi('users.update', 'POST', ['id' => $ownerId, 'role' => 'editor'], $lumoraConfig);
      $deleteLast = $callApi('users.delete', 'POST', ['id' => $ownerId], $lumoraConfig);

      // Create editor & viewer
      $callApi('users.create', 'POST', [
        'email' => 'editor@rbac.test',
        'name' => 'Editor User',
        'role' => 'editor',
        'password' => 'EditorPass#2026!',
      ], $lumoraConfig);
      $callApi('users.create', 'POST', [
        'email' => 'viewer@rbac.test',
        'name' => 'Viewer User',
        'role' => 'viewer',
        'password' => 'ViewerPass#2026!',
      ], $lumoraConfig);

      // Viewer permissions
      $loginSessionAs($pdo, $lumoraConfig, 'viewer@rbac.test', 'ViewerPass#2026!');
      $viewerRead = $callApi('overview.get', 'GET', [], $lumoraConfig);
      $viewerWrite = $callApi('blog.save', 'POST', [
        'slug' => 'blocked',
        'title' => 'Blocked',
        'content_html' => '<p>x</p>',
        'status' => 'draft',
      ], $lumoraConfig);

      // Editor permissions
      $loginSessionAs($pdo, $lumoraConfig, 'editor@rbac.test', 'EditorPass#2026!');
      $editorBlog = $callApi('blog.save', 'POST', [
        'slug' => 'editor-post',
        'title' => 'Editor Post',
        'content_html' => '<p>Hello</p>',
        'status' => 'draft',
      ], $lumoraConfig);
      $editorUsersCreate = $callApi('users.create', 'POST', [
        'email' => 'x@rbac.test',
        'role' => 'viewer',
        'password' => 'Pass#20261234',
      ], $lumoraConfig);

      // Disallowed role enforcement when allowed_roles shrinks to ['owner']
      $restrictedCfg = $lumoraConfig;
      $restrictedCfg['admin']['allowed_roles'] = ['owner'];
      $disallowedRole = $callApi('blog.save', 'POST', [
        'slug' => 'restricted-role-post',
        'title' => 'Restricted',
        'content_html' => '<p>Blocked</p>',
        'status' => 'draft',
      ], $restrictedCfg);

      $_SERVER['HTTP_X_LUMORA_CSRF'] = \\Lumora\\Core\\Security::csrfToken($lumoraRoot);
      $disallowedLogin = $callApi('auth.login', 'POST', [
        'email' => 'editor@rbac.test',
        'password' => 'EditorPass#2026!',
      ], $restrictedCfg);

      ${phpCleanupSnippet()}
      echo json_encode([
        'hash_is_argon2id' => str_starts_with($hash, '$argon2id$'),
        'verify_ok' => \\Lumora\\Core\\AdminAuth::verifyPassword($ownerPass, $hash),
        'verify_bad' => \\Lumora\\Core\\AdminAuth::verifyPassword('wrong', $hash),
        'lockout_code' => $lockoutRes['code'],
        'missing_csrf_code' => $missingCsrfRes['code'],
        'ua_mismatch_code' => $uaMismatchRes['code'],
        'viewer_read_code' => $viewerRead['code'],
        'viewer_write_code' => $viewerWrite['code'],
        'editor_blog_save_code' => $editorBlog['code'],
        'editor_users_create_code' => $editorUsersCreate['code'],
        'disallowed_role_code' => $disallowedRole['code'],
        'disallowed_login_code' => $disallowedLogin['code'],
        'demote_last_owner_code' => $demoteLast['code'],
        'delete_last_owner_code' => $deleteLast['code'],
      ]);
      `,
      siteDir
    );

    expect(res.hash_is_argon2id).toBe(true);
    expect(res.verify_ok).toBe(true);
    expect(res.verify_bad).toBe(false);
    expect(res.lockout_code).toBe(429);
    expect(res.missing_csrf_code).toBe(403);
    expect(res.ua_mismatch_code).toBe(401);
    expect(res.viewer_read_code).toBe(200);
    expect(res.viewer_write_code).toBe(403);
    expect(res.editor_blog_save_code).toBe(200);
    expect(res.editor_users_create_code).toBe(403);
    expect(res.disallowed_role_code).toBe(403);
    expect(res.disallowed_login_code).toBe(403);
    expect(res.demote_last_owner_code).toBeGreaterThanOrEqual(400);
    expect(res.delete_last_owner_code).toBeGreaterThanOrEqual(400);
  }, 15000);

  test("RFC 6238 TOTP 2FA enrollment, verification, replay rejection, recovery codes, and otpauth URI", () => {
    const siteDir = createTempDir("totp-2fa");
    copyStarterWithoutStorage(siteDir);

    const res = evalPhpJson<{
      pending_required: boolean;
      verify_code: number;
      verify_ok: boolean;
      replay_rejected: boolean;
      recovery_ok: boolean;
      recovery_reuse_rejected: boolean;
    }>(
      `
      ${phpBootstrapSnippet(siteDir)}
      ${phpHelpersSnippet()}
      $pass = 'TotpOwner#2026!!';
      $lumoraConfig['admin']['initial_owner'] = [
        'email' => 'totp@example.test',
        'name' => 'TOTP Owner',
        'password_hash' => \\Lumora\\Core\\AdminAuth::hashPassword($pass),
      ];
      $GLOBALS['lumoraConfig'] = $lumoraConfig;
      $pdo = \\Lumora\\Core\\AdminDb::connect($lumoraRoot);
      \\Lumora\\Core\\AdminAuth::syncInitialOwnerFromConfig($pdo, $lumoraConfig);

      $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
      $_SERVER['HTTP_USER_AGENT'] = 'LumoraTotpTest/1.0';
      $secret = \\Lumora\\Core\\AdminAuth::generateTotpSecret();
      $rec = \\Lumora\\Core\\AdminAuth::generateRecoveryCodes(4);
      $codesList = $rec['plain'];
      $recHashes = $rec['hashes'];

      $userRow = $pdo->query("SELECT id FROM admin_users WHERE email = 'totp@example.test'")->fetch(\\PDO::FETCH_ASSOC);
      $userId = (int)$userRow['id'];

      $encSecret = \\Lumora\\Core\\AdminDb::encryptValue($secret, $lumoraRoot);
      $stmt = $pdo->prepare("UPDATE admin_users SET totp_enabled = 1, totp_secret_enc = :s, recovery_codes_json = :r WHERE id = :id");
      $stmt->execute([':s' => $encSecret, ':r' => json_encode($recHashes), ':id' => $userId]);

      // Step 1: password-only login issues pending (unverified) session requiring 2FA
      $step1 = $loginSessionAs($pdo, $lumoraConfig, 'totp@example.test', $pass);
      $now = time();
      $totpCode = \\Lumora\\Core\\AdminAuth::hotp(\\Lumora\\Core\\AdminAuth::base32Decode($secret), (int)floor($now / 30), 6);

      // Step 2: complete 2FA via API controller
      $verifyRes = $callApi('auth.verify-2fa', 'POST', ['code' => $totpCode], $lumoraConfig);

      // Replay the exact same TOTP code immediately inside same 30s step
      $freshUser = \\Lumora\\Core\\AdminAuth::getUserById($pdo, $userId);
      $replayRes = \\Lumora\\Core\\AdminAuth::verifyUserSecondFactor($pdo, $freshUser, $totpCode);

      // Single-use recovery code succeeds once, fails twice
      $freshUser2 = \\Lumora\\Core\\AdminAuth::getUserById($pdo, $userId);
      $recoveryFirst = \\Lumora\\Core\\AdminAuth::verifyUserSecondFactor($pdo, $freshUser2, (string)$codesList[0]);
      $freshUser3 = \\Lumora\\Core\\AdminAuth::getUserById($pdo, $userId);
      $recoverySecond = \\Lumora\\Core\\AdminAuth::verifyUserSecondFactor($pdo, $freshUser3, (string)$codesList[0]);

      ${phpCleanupSnippet()}
      echo json_encode([
        'pending_required' => (bool)($step1['requires_2fa'] ?? false),
        'verify_code' => $verifyRes['code'],
        'verify_ok' => (bool)($verifyRes['json']['ok'] ?? false),
        'replay_rejected' => empty($replayRes['ok']),
        'recovery_ok' => !empty($recoveryFirst['ok']),
        'recovery_reuse_rejected' => empty($recoverySecond['ok']),
      ]);
      `,
      siteDir
    );

    expect(res.pending_required).toBe(true);
    expect(res.verify_code).toBe(200);
    expect(res.verify_ok).toBe(true);
    expect(res.replay_rejected).toBe(true);
    expect(res.recovery_ok).toBe(true);
    expect(res.recovery_reuse_rejected).toBe(true);
  }, 15000);

  test("Dual-path Owner bootstrap via initial_owner config and LUMORA_ADMIN_SETUP_TOKEN", () => {
    const siteA = createTempDir("bootstrap-initial-owner");
    copyStarterWithoutStorage(siteA);
    const resA = evalPhpJson<{ has_owner: boolean; setup_available: boolean; login_ok: boolean }>(
      `
      ${phpBootstrapSnippet(siteA)}
      ${phpHelpersSnippet()}
      $pass = 'ConfigOwner#2026!';
      $lumoraConfig['admin']['initial_owner'] = [
        'email' => 'cfg@owner.test',
        'name' => 'Cfg Owner',
        'password_hash' => \\Lumora\\Core\\AdminAuth::hashPassword($pass),
      ];
      $GLOBALS['lumoraConfig'] = $lumoraConfig;
      $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
      $_SERVER['HTTP_USER_AGENT'] = 'LumoraSetupTest/1.0';

      $statusRes = $callApi('auth.status', 'GET', [], $lumoraConfig);
      $_SERVER['HTTP_X_LUMORA_CSRF'] = (string)($statusRes['json']['bootstrap_csrf'] ?? '');
      $loginRes = $callApi('auth.login', 'POST', ['email' => 'cfg@owner.test', 'password' => $pass], $lumoraConfig);
      ${phpCleanupSnippet()}
      echo json_encode([
        'has_owner' => (bool)($statusRes['json']['has_owner'] ?? false),
        'setup_available' => (bool)($statusRes['json']['setup_available'] ?? true),
        'login_ok' => (bool)($loginRes['json']['ok'] ?? false),
      ]);
      `,
      siteA
    );
    expect(resA.has_owner).toBe(true);
    expect(resA.setup_available).toBe(false);
    expect(resA.login_ok).toBe(true);

    const siteB = createTempDir("bootstrap-setup-token");
    copyStarterWithoutStorage(siteB);
    const resB = evalPhpJson<{
      has_owner_before: boolean;
      setup_available_before: boolean;
      bad_token_code: number;
      good_token_code: number;
      good_token_ok: boolean;
      second_setup_code: number;
    }>(
      `
      ${phpBootstrapSnippet(siteB)}
      ${phpHelpersSnippet()}
      $lumoraConfig['admin']['initial_owner'] = null;
      $GLOBALS['lumoraConfig'] = $lumoraConfig;
      putenv('LUMORA_ADMIN_SETUP_TOKEN=SetupToken_2026_Secure');
      $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
      $_SERVER['HTTP_USER_AGENT'] = 'LumoraSetupTest/1.0';

      $statusBefore = $callApi('auth.status', 'GET', [], $lumoraConfig);
      $_SERVER['HTTP_X_LUMORA_CSRF'] = (string)($statusBefore['json']['bootstrap_csrf'] ?? '');

      $badRes = $callApi('auth.setup', 'POST', [
        'setup_token' => 'WrongSetupToken',
        'email' => 'setup@owner.test',
        'name' => 'Setup Owner',
        'password' => 'SetupOwner#2026!',
      ], $lumoraConfig);

      $goodRes = $callApi('auth.setup', 'POST', [
        'setup_token' => 'SetupToken_2026_Secure',
        'email' => 'setup@owner.test',
        'name' => 'Setup Owner',
        'password' => 'SetupOwner#2026!',
      ], $lumoraConfig);

      $secondRes = $callApi('auth.setup', 'POST', [
        'setup_token' => 'SetupToken_2026_Secure',
        'email' => 'setup2@owner.test',
        'name' => 'Setup Owner 2',
        'password' => 'SetupOwner#2026!',
      ], $lumoraConfig);

      putenv('LUMORA_ADMIN_SETUP_TOKEN=');
      ${phpCleanupSnippet()}
      echo json_encode([
        'has_owner_before' => (bool)($statusBefore['json']['has_owner'] ?? true),
        'setup_available_before' => (bool)($statusBefore['json']['setup_available'] ?? false),
        'bad_token_code' => $badRes['code'],
        'good_token_code' => $goodRes['code'],
        'good_token_ok' => (bool)($goodRes['json']['ok'] ?? false),
        'second_setup_code' => $secondRes['code'],
      ]);
      `,
      siteB
    );
    expect(resB.has_owner_before).toBe(false);
    expect(resB.setup_available_before).toBe(true);
    expect(resB.bad_token_code).toBeGreaterThanOrEqual(400);
    expect(resB.good_token_code).toBe(200);
    expect(resB.good_token_ok).toBe(true);
    expect(resB.second_setup_code).toBeGreaterThanOrEqual(400);
  }, 15000);

  test("AdminSanitizer HTML XSS stripping, Gate-06 img rules, and processImageUpload polyglot rejection", () => {
    const siteDir = createTempDir("sanitizer-upload");
    copyStarterWithoutStorage(siteDir);

    const pngBase64 =
      "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=";
    const validPngPath = path.join(siteDir, "valid-1x1.png");
    writeFileSync(validPngPath, Buffer.from(pngBase64, "base64"));

    const polyglotPath = path.join(siteDir, "polyglot.png");
    writeFileSync(polyglotPath, "<?php echo 'pwned'; ?>\x89PNG\r\n\x1a\n");

    const svgPath = path.join(siteDir, "xss.svg");
    writeFileSync(svgPath, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');

    const res = evalPhpJson<{
      sanitized: string;
      upload_ok: boolean;
      upload_width: number;
      upload_height: number;
      upload_url: string;
      uploads_htaccess_exists: boolean;
      polyglot_rejected: boolean;
      svg_rejected: boolean;
    }>(
      `
      ${phpBootstrapSnippet(siteDir)}
      $dirty = '<p onclick="evil()">Hello <script>alert(1)</script>'
        . '<a href="javascript:alert(1)">bad</a> '
        . '<a href="https://example.com" onfocus="x()">good</a>'
        . '<iframe src="https://evil.test"></iframe>'
        . '<img src="javascript:alert(1)" width="10" height="10" alt="js">'
        . '<img src="/public/missing-dims.png" alt="nodim">'
        . '<img src="/public/uploads/ok.png" width="120" height="80" alt="Safe Img" onerror="alert(2)">'
        . '</p>';
      $sanitized = \\Lumora\\Core\\AdminSanitizer::sanitizeHtml($dirty, $lumoraRoot);

      $uploadOk = \\Lumora\\Core\\AdminSanitizer::processImageUpload([
        'tmp_name' => ${JSON.stringify(toPosixPath(validPngPath))},
        'name' => 'valid-1x1.png',
        'error' => UPLOAD_ERR_OK,
        'size' => filesize(${JSON.stringify(toPosixPath(validPngPath))}),
      ], $lumoraRoot, $lumoraConfig, 'Sample Tiny PNG');

      $polyRes = \\Lumora\\Core\\AdminSanitizer::processImageUpload([
        'tmp_name' => ${JSON.stringify(toPosixPath(polyglotPath))},
        'name' => 'polyglot.png',
        'error' => UPLOAD_ERR_OK,
        'size' => filesize(${JSON.stringify(toPosixPath(polyglotPath))}),
      ], $lumoraRoot, $lumoraConfig, 'Bad');
      $polyRejected = empty($polyRes['ok']);

      $svgRes = \\Lumora\\Core\\AdminSanitizer::processImageUpload([
        'tmp_name' => ${JSON.stringify(toPosixPath(svgPath))},
        'name' => 'xss.svg',
        'error' => UPLOAD_ERR_OK,
        'size' => filesize(${JSON.stringify(toPosixPath(svgPath))}),
      ], $lumoraRoot, $lumoraConfig, 'Bad SVG');
      $svgRejected = empty($svgRes['ok']);

      ${phpCleanupSnippet()}
      echo json_encode([
        'sanitized' => $sanitized,
        'upload_ok' => is_array($uploadOk) && !empty($uploadOk['public_path']),
        'upload_width' => (int)($uploadOk['width'] ?? 0),
        'upload_height' => (int)($uploadOk['height'] ?? 0),
        'upload_url' => (string)($uploadOk['public_path'] ?? ''),
        'uploads_htaccess_exists' => is_file($lumoraRoot . '/public/uploads/.htaccess'),
        'polyglot_rejected' => $polyRejected,
        'svg_rejected' => $svgRejected,
      ]);
      `,
      siteDir
    );

    expect(res.sanitized).not.toContain("<script");
    expect(res.sanitized).not.toContain("onclick=");
    expect(res.sanitized).not.toContain("onerror=");
    expect(res.sanitized).not.toContain("javascript:");
    expect(res.sanitized).not.toContain("<iframe");
    expect(res.sanitized).not.toContain("missing-dims.png");
    expect(res.sanitized).toContain('href="https://example.com"');
    expect(res.sanitized).toContain('src="/public/uploads/ok.png"');
    expect(res.sanitized).toContain('width="120"');
    expect(res.sanitized).toContain('height="80"');
    expect(res.sanitized).toContain('alt="Safe Img"');

    expect(res.upload_ok).toBe(true);
    expect(res.upload_width).toBe(1);
    expect(res.upload_height).toBe(1);
    expect(res.upload_url.startsWith("/public/uploads/")).toBe(true);
    expect(res.uploads_htaccess_exists).toBe(true);
    expect(res.polyglot_rejected).toBe(true);
    expect(res.svg_rejected).toBe(true);
  });

  test("Hybrid SQLite CMS live resolution across Blog, Docs, Routing, Legal, Channels, Releases, and Inquiries", () => {
    const siteDir = createTempDir("hybrid-cms");
    copyStarterWithoutStorage(siteDir);

    mkdirSync(path.join(siteDir, "releases"), { recursive: true });
    writeFileSync(path.join(siteDir, "releases", "lumora-cli-2.0.tar.gz"), "fake-release-payload-v2");

    const res = evalPhpJson<{
      blog_public_has_live: boolean;
      blog_public_has_draft: boolean;
      blog_draft_get_null: boolean;
      docs_found: boolean;
      docs_search_found: boolean;
      redirect_code: number;
      redirect_target: string;
      loop_redirect_code: number;
      admin_path_redirect_code: number;
      legal_has_cookies: boolean;
      legal_resolved_title: string;
      channel_present: boolean;
      release_manifest_has_item: boolean;
      release_sums_has_item: boolean;
      inquiry_status_updated: string;
      csv_formula_neutralized: boolean;
      redacted_name: string;
      redacted_email: string;
    }>(
      `
      ${phpBootstrapSnippet(siteDir)}
      ${phpHelpersSnippet()}
      $pass = 'CmsOwner#2026!!';
      $lumoraConfig['admin']['initial_owner'] = [
        'email' => 'cms@example.test',
        'name' => 'CMS Owner',
        'password_hash' => \\Lumora\\Core\\AdminAuth::hashPassword($pass),
      ];
      $GLOBALS['lumoraConfig'] = $lumoraConfig;
      $pdo = \\Lumora\\Core\\AdminDb::connect($lumoraRoot);
      \\Lumora\\Core\\AdminAuth::syncInitialOwnerFromConfig($pdo, $lumoraConfig);
      $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
      $_SERVER['HTTP_USER_AGENT'] = 'LumoraCmsSuite/1.0';
      $loginSessionAs($pdo, $lumoraConfig, 'cms@example.test', $pass);

      // 1. Blog live + draft isolation
      $callApi('blog.save', 'POST', [
        'slug' => 'cms-live-post',
        'title' => 'CMS Live Post',
        'summary' => 'Published via SQLite CMS',
        'content_html' => '<p>Hello from <strong>SQLite CMS</strong></p>',
        'status' => 'published',
        'author' => 'CMS Owner',
        'tags' => ['cms'],
      ], $lumoraConfig);
      $callApi('blog.save', 'POST', [
        'slug' => 'cms-secret-draft',
        'title' => 'Secret Draft Post',
        'summary' => 'Should remain hidden',
        'content_html' => '<p>Draft text</p>',
        'status' => 'draft',
      ], $lumoraConfig);
      $publicPosts = \\Lumora\\Core\\BlogEngine::listPosts($lumoraRoot, false);
      $publicSlugs = array_column($publicPosts, 'slug');
      putenv('LUMORA_FORCE_PRODUCTION=1');
      $draftPost = \\Lumora\\Core\\BlogEngine::getPost('cms-secret-draft', $lumoraRoot);
      putenv('LUMORA_FORCE_PRODUCTION=');

      // 2. Docs track + article
      $callApi('docs.track-save', 'POST', [
        'track_id' => 'admin-track',
        'title' => 'Admin Track',
        'description' => 'SQLite Docs Track',
        'sort_order' => 1,
      ], $lumoraConfig);
      $callApi('docs.save', 'POST', [
        'track_id' => 'admin-track',
        'slug' => 'cms-guide',
        'title' => 'CMS Integration Guide',
        'summary' => 'Hybrid docs guide',
        'markdown' => "## Getting Started\\n\\nHybrid SQLite docs work seamlessly.",
        'status' => 'published',
        'sort_order' => 1,
      ], $lumoraConfig);
      $docsEngine = new \\Lumora\\Core\\DocsEngine($lumoraRoot . '/content/docs');
      $docArticle = $docsEngine->renderArticle('admin-track', 'cms-guide');
      $docSearch = $docsEngine->searchArticles('seamlessly');

      // 3. Routing redirect + safety guards
      $callApi('routing.save', 'POST', [
        'source_path' => '/old-cms-path/',
        'target_url' => '/new-cms-path/',
        'status_code' => 301,
        'is_active' => true,
      ], $lumoraConfig);
      $unsafeTarget = $callApi('routing.save', 'POST', [
        'source_path' => '/bad-js/',
        'target_url' => 'javascript:alert(1)',
        'status_code' => 302,
      ], $lumoraConfig);
      $protectedAdminTarget = $callApi('routing.save', 'POST', [
        'source_path' => '/admin/hijack/',
        'target_url' => '/contact/',
        'status_code' => 302,
      ], $lumoraConfig);
      $resolvedRedirect = \\Lumora\\Core\\Router::resolve('/old-cms-path/', $lumoraConfig, $lumoraRoot);

      // 4. Legal registry override
      $callApi('legal.save', 'POST', [
        'slug' => 'cookies',
        'doc_no' => 'DOC-COOKIES-01',
        'title' => 'Cookie Notice (CMS)',
        'lead' => 'Managed in SQLite',
        'version' => '2.0',
        'date' => '2026-11-15',
        'status' => 'effective',
        'sections' => [['id' => 'policy', 'title' => 'Policy', 'body' => '<p>Minimal cookies only.</p>']],
        'is_active' => true,
      ], $lumoraConfig);
      $activeLegal = \\Lumora\\Core\\LegalRegistry::activeSlugs($lumoraConfig, $lumoraRoot);
      $legalDoc = \\Lumora\\Core\\LegalRegistry::resolveDocument('cookies', $lumoraConfig, $lumoraRoot);

      // 5. Inquiry channels override
      $callApi('channels.save', 'POST', [
        'channel_key' => 'enterprise_audit',
        'label' => 'Enterprise Audit',
        'description' => 'SQLite custom channel',
        'sla_window' => '4 Hours',
        'is_active' => true,
      ], $lumoraConfig);
      $channelConfig = \\Lumora\\Core\\InquiryEngine::loadChannelConfig($lumoraRoot);
      $channelPresent = isset($channelConfig['channels']['enterprise_audit']) || isset($channelConfig['enterprise_audit']);

      // 6. Release artifact manifest & SHA256SUMS
      $callApi('releases.save', 'POST', [
        'version' => '2.0.0',
        'filename' => 'lumora-cli-2.0.tar.gz',
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 23,
        'channel' => 'stable',
        'platform' => 'linux',
        'release_notes' => 'CMS release',
        'is_active' => true,
      ], $lumoraConfig);
      ob_start();
      \\Lumora\\Core\\ReleaseServer::tryServe('/releases/release-manifest.json', $lumoraConfig, $lumoraRoot);
      $manifestOut = (string)ob_get_clean();
      ob_start();
      \\Lumora\\Core\\ReleaseServer::tryServe('/releases/SHA256SUMS', $lumoraConfig, $lumoraRoot);
      $sumsOut = (string)ob_get_clean();

      // 7. Inquiry storage -> update -> CSV formula sanitization -> GDPR redaction
      $inqRef = 'INQ-20261115-TEST01';
      \\Lumora\\Core\\InquiryDb::saveSubmission([
        'receipt_id' => $inqRef,
        'channel' => 'general',
        'full_name' => "=CMD|'/C calc'!A0",
        'email' => 'alice.pii@example.com',
        'phone' => '+15551234567',
        'organization' => 'Acme Corp',
        'subject' => 'Quote request',
        'message' => 'Please quote pricing',
        'consultation_mode' => 'online',
      ], $lumoraConfig, $lumoraRoot);

      $callApi('inquiries.update', 'POST', [
        'receipt_id' => $inqRef,
        'triage_status' => 'in_review',
        'internal_notes' => 'Follow up on Monday',
      ], $lumoraConfig);
      $inqGetBefore = $callApi('inquiries.get', 'GET', [], $lumoraConfig, ['receipt_id' => $inqRef]);
      $exportRes = $callApi('inquiries.export', 'GET', [], $lumoraConfig, ['format' => 'csv']);

      $callApi('inquiries.redact', 'POST', ['receipt_id' => $inqRef], $lumoraConfig);
      $inqGetAfter = $callApi('inquiries.get', 'GET', [], $lumoraConfig, ['receipt_id' => $inqRef]);

      ${phpCleanupSnippet()}
      echo json_encode([
        'blog_public_has_live' => in_array('cms-live-post', $publicSlugs, true),
        'blog_public_has_draft' => in_array('cms-secret-draft', $publicSlugs, true),
        'blog_draft_get_null' => $draftPost === null,
        'docs_found' => (bool)($docArticle['found'] ?? false),
        'docs_search_found' => count($docSearch) > 0,
        'redirect_code' => (int)($resolvedRedirect['status'] ?? 0),
        'redirect_target' => (string)($resolvedRedirect['redirect'] ?? ''),
        'loop_redirect_code' => $unsafeTarget['code'],
        'admin_path_redirect_code' => $protectedAdminTarget['code'],
        'legal_has_cookies' => in_array('cookies', $activeLegal, true),
        'legal_resolved_title' => (string)($legalDoc['title'] ?? ''),
        'channel_present' => $channelPresent,
        'release_manifest_has_item' => str_contains($manifestOut, 'lumora-cli-2.0.tar.gz'),
        'release_sums_has_item' => str_contains($sumsOut, 'lumora-cli-2.0.tar.gz'),
        'inquiry_status_updated' => (string)($inqGetBefore['json']['item']['triage_status'] ?? ''),
        'csv_formula_neutralized' => str_contains($exportRes['raw'], "'=CMD"),
        'redacted_name' => (string)($inqGetAfter['json']['item']['full_name'] ?? ''),
        'redacted_email' => (string)($inqGetAfter['json']['item']['email'] ?? ''),
      ]);
      `,
      siteDir
    );

    expect(res.blog_public_has_live).toBe(true);
    expect(res.blog_public_has_draft).toBe(false);
    expect(res.blog_draft_get_null).toBe(true);
    expect(res.docs_found).toBe(true);
    expect(res.docs_search_found).toBe(true);
    expect(res.redirect_code).toBe(301);
    expect(res.redirect_target).toBe("/new-cms-path/");
    expect(res.loop_redirect_code).toBe(422);
    expect(res.admin_path_redirect_code).toBe(422);
    expect(res.legal_has_cookies).toBe(true);
    expect(res.legal_resolved_title).toBe("Cookie Notice (CMS)");
    expect(res.channel_present).toBe(true);
    expect(res.release_manifest_has_item).toBe(true);
    expect(res.release_sums_has_item).toBe(true);
    expect(res.inquiry_status_updated).toBe("in_review");
    expect(res.csv_formula_neutralized).toBe(true);
    expect(res.redacted_name).toBe("[REDACTED]");
    expect(res.redacted_email).toBe("redacted@redacted.invalid");
  });

  test("lumora-web admin CLI, stealth /_console/ live HTTP, robots.txt non-disclosure, and 12-Gate Verify", async () => {
    const siteDir = createTempDir("cli-http-verify");
    await initWebsite({
      cwd: siteDir,
      brandId: "stealth",
      brandName: "Stealth Brand",
      brandPrefix: "STL",
      domain: "stealth.example.com",
      legalEntity: "Stealth Systems LLC",
      withAdmin: true,
      silent: true,
    });

    const configPath = path.join(siteDir, "lumora.config.php");
    let cfgContent = readFileSync(configPath, "utf8");
    cfgContent = cfgContent
      .replace(/['"]path['"]\s*=>\s*['"]\/admin\/['"]/, "'path' => '/_console/'")
      .replace("Stealth Brand Admin", "Stealth Brand Console");
    expect(cfgContent).toContain("'/_console/'");
    writeFileSync(configPath, cfgContent, "utf8");

    await syncWebsite({ cwd: siteDir, silent: true });

    const hashRes = runAdminCli({ cwd: siteDir, subcommand: "hash-password", password: "CliPassword#2026!", silent: true });
    expect(String(hashRes.password_hash)).toStartWith("$argon2id$");

    runAdminCli({
      cwd: siteDir,
      subcommand: "create-user",
      email: "cli-owner@stealth.example.com",
      name: "CLI Owner",
      role: "owner",
      password: "CliOwnerPass#2026!",
      silent: true,
    });
    runAdminCli({
      cwd: siteDir,
      subcommand: "create-user",
      email: "cli-editor@stealth.example.com",
      name: "CLI Editor",
      role: "editor",
      password: "CliEditorPass#2026!",
      silent: true,
    });

    const listed = runAdminCli({ cwd: siteDir, subcommand: "list-users", silent: true }) as {
      users: Array<{ email: string; role: string }>;
    };
    expect(listed.users.some((u) => u.email === "cli-owner@stealth.example.com" && u.role === "owner")).toBe(true);
    expect(listed.users.some((u) => u.email === "cli-editor@stealth.example.com" && u.role === "editor")).toBe(true);

    runAdminCli({
      cwd: siteDir,
      subcommand: "reset-password",
      email: "cli-owner@stealth.example.com",
      password: "UpdatedOwner#2026!",
      silent: true,
    });
    runAdminCli({
      cwd: siteDir,
      subcommand: "disable-2fa",
      email: "cli-owner@stealth.example.com",
      silent: true,
    });
    runAdminCli({
      cwd: siteDir,
      subcommand: "revoke-sessions",
      email: "cli-owner@stealth.example.com",
      silent: true,
    });

    const port = await getFreePort();
    const phpProc = spawn("php", ["-S", `127.0.0.1:${port}`, "-t", siteDir, path.join(siteDir, "router.php")], {
      cwd: siteDir,
      stdio: "ignore",
    });
    childProcesses.push(phpProc);

    try {
      const baseUrl = `http://127.0.0.1:${port}`;
      await waitForHttp(`${baseUrl}/`);

      const robotsRes = await fetch(`${baseUrl}/robots.txt`);
      expect(robotsRes.status).toBe(200);
      const robotsTxt = await robotsRes.text();
      expect(robotsTxt).not.toContain("/_console");
      expect(robotsTxt).not.toContain("/admin/");

      const oldAdminRes = await fetch(`${baseUrl}/admin/`);
      expect(oldAdminRes.status).toBe(404);

      const consoleRes = await fetch(`${baseUrl}/_console/`);
      expect(consoleRes.status).toBe(200);
      expect(consoleRes.headers.get("cache-control") ?? "").toContain("no-store");
      expect(consoleRes.headers.get("x-robots-tag") ?? "").toContain("noindex");
      const shellHtml = await consoleRes.text();
      expect(shellHtml).toContain("Stealth Brand Console");
      expect(shellHtml).toContain("lumora-admin.js");
      expect(shellHtml).toContain("lumora-admin.css");

      const ua = "LumoraHttpTest/1.0";
      const statusRes = await fetch(`${baseUrl}/_console/api/?action=auth.status`, {
        headers: { "User-Agent": ua },
      });
      expect(statusRes.status).toBe(200);
      const statusJson = (await statusRes.json()) as { ok: boolean; bootstrap_csrf?: string };
      expect(statusJson.ok).toBe(true);
      const bootstrapCsrf = statusJson.bootstrap_csrf ?? "";
      expect(bootstrapCsrf.length).toBeGreaterThan(10);

      const unauthOverview = await fetch(`${baseUrl}/_console/api/?action=overview.get`, {
        headers: { "User-Agent": ua },
      });
      expect(unauthOverview.status).toBe(401);

      const loginRes = await fetch(`${baseUrl}/_console/api/?action=auth.login`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "User-Agent": ua,
          "X-Lumora-Csrf": bootstrapCsrf,
        },
        body: JSON.stringify({
          email: "cli-owner@stealth.example.com",
          password: "UpdatedOwner#2026!",
        }),
      });
      expect(loginRes.status).toBe(200);
      const loginJson = (await loginRes.json()) as { ok: boolean; csrf_token?: string };
      expect(loginJson.ok).toBe(true);
      const sessionCsrf = loginJson.csrf_token ?? "";
      expect(sessionCsrf.length).toBeGreaterThan(10);

      const setCookieHeader = loginRes.headers.get("set-cookie") ?? "";
      const cookiePair = setCookieHeader.split(";")[0] ?? "";
      expect(cookiePair.length).toBeGreaterThan(5);

      const overviewRes = await fetch(`${baseUrl}/_console/api/?action=overview.get`, {
        headers: {
          Cookie: cookiePair,
          "X-Lumora-Csrf": sessionCsrf,
          "User-Agent": ua,
        },
      });
      expect(overviewRes.status).toBe(200);
      const overviewJson = (await overviewRes.json()) as {
        ok: boolean;
        stats?: Record<string, unknown>;
        badges?: Record<string, number>;
      };
      expect(overviewJson.ok).toBe(true);
      expect(overviewJson.stats).toBeDefined();
      expect(overviewJson.badges).toBeDefined();

      const apiCall = async (
        action: string,
        method: "GET" | "POST" = "GET",
        body?: Record<string, unknown>
      ) => {
        const headers: Record<string, string> = {
          Cookie: cookiePair,
          "X-Lumora-Csrf": sessionCsrf,
          "User-Agent": ua,
        };
        if (body !== undefined) {
          headers["Content-Type"] = "application/json";
        }
        const res = await fetch(`${baseUrl}/_console/api/?action=${action}`, {
          method,
          headers,
          body: body !== undefined ? JSON.stringify(body) : undefined,
        });
        const data = (await res.json()) as Record<string, any>;
        return { status: res.status, data };
      };

      // Profile self-service: update display name + non-current session revocation guard
      const profileUpd = await apiCall("profile.update", "POST", { name: "Updated Stealth Owner" });
      expect(profileUpd.status).toBe(200);
      expect(profileUpd.data.ok).toBe(true);
      expect(profileUpd.data.user?.name).toBe("Updated Stealth Owner");

      const revokeMissing = await apiCall("profile.revoke-session", "POST", { session_id: 999999 });
      expect(revokeMissing.status).toBe(200);
      expect(revokeMissing.data.ok).toBe(true);
      expect(revokeMissing.data.deleted).toBe(0);

      // Scheduled blog visibility, SEO metadata & revision rollback
      const saveV1 = await apiCall("blog.save", "POST", {
        slug: "commercial-launch",
        title: "Commercial Launch v1",
        summary: "Initial summary",
        content_html: "<p>First body</p>",
        status: "scheduled",
        date: "2099-12-31",
        meta_title: "SEO Launch Title",
        meta_description: "SEO Launch Desc",
        canonical_url: "/blog/commercial-launch",
      });
      expect(saveV1.status).toBe(200);
      expect(saveV1.data.ok).toBe(true);
      expect(saveV1.data.post?.meta_title).toBe("SEO Launch Title");

      const phpScheduledCheck = Bun.spawnSync(
        [
          "php",
          "-r",
          `define('LUMORA_BOOTSTRAPPED', true);
require ${JSON.stringify(path.join(siteDir, "core", "bootstrap.php").replace(/\\/g, "/"))};
$root = ${JSON.stringify(siteDir.replace(/\\/g, "/"))};
$pubPost = \\Lumora\\Core\\BlogEngine::getPost('commercial-launch', $root, false);
$draftPost = \\Lumora\\Core\\BlogEngine::getPost('commercial-launch', $root, true);
$pubList = array_column(\\Lumora\\Core\\BlogEngine::listPosts($root, false), 'slug');
echo json_encode([
  'pub_null' => $pubPost === null,
  'draft_meta_title' => $draftPost['meta_title'] ?? '',
  'in_public_list' => in_array('commercial-launch', $pubList, true),
]);`,
        ],
        { cwd: siteDir }
      );
      expect(phpScheduledCheck.exitCode).toBe(0);
      const schedEval = JSON.parse(phpScheduledCheck.stdout.toString()) as {
        pub_null: boolean;
        draft_meta_title: string;
        in_public_list: boolean;
      };
      expect(schedEval.pub_null).toBe(true);
      expect(schedEval.in_public_list).toBe(false);
      expect(schedEval.draft_meta_title).toBe("SEO Launch Title");

      const saveV2 = await apiCall("blog.save", "POST", {
        slug: "commercial-launch",
        title: "Commercial Launch v2",
        summary: "Updated summary",
        content_html: "<p>Second body</p>",
        status: "published",
        date: "2026-01-15",
        meta_title: "SEO Launch Title v2",
      });
      expect(saveV2.status).toBe(200);
      expect(saveV2.data.ok).toBe(true);

      const revList = await apiCall("revisions.list&entity_type=blog&entity_key=commercial-launch");
      expect(revList.status).toBe(200);
      expect(revList.data.ok).toBe(true);
      expect(Array.isArray(revList.data.revisions)).toBe(true);
      expect(revList.data.revisions.length).toBeGreaterThanOrEqual(2);
      const v1Revision = revList.data.revisions.find(
        (r: { title?: string }) => r.title === "Commercial Launch v1"
      );
      expect(v1Revision).toBeDefined();

      const revRestore = await apiCall("revisions.restore", "POST", { id: v1Revision.id });
      expect(revRestore.status).toBe(200);
      expect(revRestore.data.ok).toBe(true);

      const republish = await apiCall("blog.save", "POST", {
        slug: "commercial-launch",
        title: "Commercial Launch Published",
        summary: "Published summary",
        content_html: "<p>Published body</p>",
        status: "published",
        date: "2026-01-15",
        meta_title: "SEO Launch Title Final",
        meta_description: "SEO Launch Description Final",
        canonical_url: "/blog/commercial-launch",
      });
      expect(republish.status).toBe(200);
      expect(republish.data.ok).toBe(true);

      // Visual channel builder & bulk inquiry CRM
      const saveChannel = await apiCall("channels.save", "POST", {
        channel_key: "enterprise",
        label: "Enterprise Desk",
        description: "Custom B2B desk",
        sla_window: "4 Hours",
        code: "ENT-01",
        fields: [
          {
            name: "company_size",
            label: "Company Size",
            type: "select",
            required: true,
            options: ["1-50", "51-500"],
          },
        ],
      });
      expect(saveChannel.status).toBe(200);
      expect(saveChannel.data.ok).toBe(true);

      const listChannels = await apiCall("channels.list");
      expect(listChannels.status).toBe(200);
      expect(listChannels.data.ok).toBe(true);
      expect(listChannels.data.channels?.enterprise?.fields?.length).toBe(1);

      const inqList = await apiCall("inquiries.list&date_from=2020-01-01&date_to=2030-12-31");
      expect(inqList.status).toBe(200);
      expect(inqList.data.ok).toBe(true);
      expect(Array.isArray(inqList.data.items)).toBe(true);
      const bulkTargetIds =
        inqList.data.items.length > 0 ? [inqList.data.items[0].receipt_id] : ["LUM-MISSING-ID"];
      const bulkRes = await apiCall("inquiries.bulk", "POST", {
        operation: "status",
        receipt_ids: bulkTargetIds,
        triage_status: "in_review",
      });
      expect(bulkRes.status).toBe(200);
      expect(bulkRes.data.ok).toBe(true);
      if (inqList.data.items.length > 0) {
        expect(bulkRes.data.affected).toBeGreaterThanOrEqual(1);
      }

      // Routing 404 monitor, redirect hit telemetry, auto-clear & toggle
      const missingRes = await fetch(`${baseUrl}/missing-legacy-endpoint`);
      expect(missingRes.status).toBe(404);

      const routingBefore = await apiCall("routing.list");
      expect(routingBefore.status).toBe(200);
      expect(
        routingBefore.data.not_found_log?.some(
          (row: { path?: string }) => row.path === "/missing-legacy-endpoint/"
        )
      ).toBe(true);

      const saveRedirect = await apiCall("routing.save", "POST", {
        source_path: "/missing-legacy-endpoint",
        target_url: "/contact",
        status_code: 301,
        is_active: 1,
      });
      expect(saveRedirect.status).toBe(200);
      expect(saveRedirect.data.ok).toBe(true);

      const routingAfterSave = await apiCall("routing.list");
      expect(routingAfterSave.status).toBe(200);
      expect(
        routingAfterSave.data.not_found_log?.some(
          (row: { path?: string }) => row.path === "/missing-legacy-endpoint/"
        )
      ).toBe(false);
      const savedRedir = routingAfterSave.data.sqlite_redirects?.find(
        (r: { source_path?: string }) => r.source_path === "/missing-legacy-endpoint"
      );
      expect(savedRedir).toBeDefined();

      const hitRes = await fetch(`${baseUrl}/missing-legacy-endpoint`, { redirect: "manual" });
      expect(hitRes.status).toBe(301);

      const routingAfterHit = await apiCall("routing.list");
      const hitRedir = routingAfterHit.data.sqlite_redirects?.find(
        (r: { id?: number }) => Number(r.id) === Number(savedRedir.id)
      );
      expect(Number(hitRedir?.hits ?? 0)).toBeGreaterThanOrEqual(1);

      const toggleOff = await apiCall("routing.toggle", "POST", {
        id: savedRedir.id,
        is_active: 0,
      });
      expect(toggleOff.status).toBe(200);
      expect(toggleOff.data.ok).toBe(true);

      const clear404 = await apiCall("routing.404-clear", "POST", { path: "" });
      expect(clear404.status).toBe(200);
      expect(clear404.data.ok).toBe(true);

      // Discovery settings, per-route SEO overrides & cleanup before buildWebsiteDist
      const saveDiscovery = await apiCall("discovery.settings-save", "POST", {
        llms_txt: "# Custom LLMs Index\n",
        llms_full_txt: "# Custom LLMs Full\n",
        security_txt: "Contact: mailto:sec@stealth.example.com\n",
        robots_extra: "Crawl-delay: 2",
      });
      expect(saveDiscovery.status).toBe(200);
      expect(saveDiscovery.data.ok).toBe(true);

      const saveRouteSeo = await apiCall("discovery.route-seo-save", "POST", {
        route_path: "/",
        meta_title: "Custom Home SEO Title",
        meta_description: "Custom Home SEO Description",
        robots: "index, follow",
      });
      expect(saveRouteSeo.status).toBe(200);
      expect(saveRouteSeo.data.ok).toBe(true);

      const overriddenHome = await fetch(`${baseUrl}/`);
      expect(overriddenHome.status).toBe(200);
      const overriddenHomeHtml = await overriddenHome.text();
      expect(overriddenHomeHtml).toContain("Custom Home SEO Title");
      expect(overriddenHomeHtml).toContain("Custom Home SEO Description");

      const previewDiscovery = await apiCall("discovery.preview");
      expect(previewDiscovery.status).toBe(200);
      expect(previewDiscovery.data.ok).toBe(true);
      expect(previewDiscovery.data.llms_txt).toContain("# Custom LLMs Index");
      expect(previewDiscovery.data.robots_txt).toContain("Crawl-delay: 2");
      expect(previewDiscovery.data.security_txt).toContain("mailto:sec@stealth.example.com");

      // Static media discovery & Hub diagnostic test ping
      writeFileSync(
        path.join(siteDir, "public", "brand", "sample.png"),
        Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=", "base64")
      );
      const mediaList = await apiCall("media.list");
      expect(mediaList.status).toBe(200);
      expect(mediaList.data.ok).toBe(true);
      expect(
        mediaList.data.items?.some(
          (item: { source_origin?: string; public_path?: string; width?: number; height?: number }) =>
            item.source_origin === "static"
            && item.public_path === "/public/brand/sample.png"
            && Number(item.width) === 1
            && Number(item.height) === 1
        )
      ).toBe(true);

      const hubPing = await apiCall("dispatch.test", "POST", { type: "hub" });
      expect(hubPing.data.ok).toBe(false);
      expect(hubPing.data.type).toBe("hub");

      // CMS snapshot export & import roundtrip
      const exportRes = await apiCall("system.export-cms");
      expect(exportRes.status).toBe(200);
      expect(exportRes.data.ok).toBe(true);
      expect(exportRes.data.format).toBe("lumora-cms-snapshot-v1");
      expect(exportRes.data.tables?.cms_blog_posts?.length).toBeGreaterThanOrEqual(1);

      const importRes = await apiCall("system.import-cms", "POST", {
        format: "lumora-cms-snapshot-v1",
        tables: exportRes.data.tables,
      });
      expect(importRes.status).toBe(200);
      expect(importRes.data.ok).toBe(true);
      expect(Number(importRes.data.counts?.cms_blog_posts ?? 0)).toBeGreaterThanOrEqual(1);

      // Delete temporary route SEO override and custom security_txt before buildWebsiteDist verification gates
      const deleteRouteSeo = await apiCall("discovery.route-seo-delete", "POST", {
        route_path: "/",
      });
      expect(deleteRouteSeo.status).toBe(200);
      expect(deleteRouteSeo.data.ok).toBe(true);

      await apiCall("discovery.settings-save", "POST", {
        llms_txt: "# Custom LLMs Index\n",
        llms_full_txt: "# Custom LLMs Full\n",
        security_txt: "",
        robots_extra: "",
      });
    } finally {
      await stopChildProcess(phpProc);
    }

    await buildWebsiteDist({ cwd: siteDir, silent: true });
    expect(existsSync(path.join(siteDir, "dist", "_console", "index.php"))).toBe(true);
    expect(existsSync(path.join(siteDir, "dist", "_console", "api", "index.php"))).toBe(true);
    expect(existsSync(path.join(siteDir, "dist", "public", "uploads", ".htaccess"))).toBe(true);

    const report = await verifyWebsite({ cwd: siteDir, silent: true });
    expect(report.gates.length).toBe(12);
    expect(report.passed).toBe(true);
  }, 45000);
});
