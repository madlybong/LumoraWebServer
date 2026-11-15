import { randomBytes } from "node:crypto";
import { existsSync, watch, type FSWatcher } from "node:fs";
import path from "node:path";
import { compileBrandCss } from "./css-compiler";
import { evalPhpJson, loadPhpSiteConfig, normalizeAdminPathTs, toPosixPath } from "./paths";
import { syncWebsite } from "./sync";

export interface DevServerOptions {
  cwd?: string;
  port?: number;
  host?: string;
}

interface DevAdminState {
  enabled: boolean;
  adminPath: string;
  hasOwner: boolean;
}

function inspectDevAdminState(siteRoot: string): DevAdminState {
  try {
    const config = loadPhpSiteConfig(siteRoot) as {
      features?: { admin_panel?: boolean };
      admin?: { path?: string };
    };
    const enabled = Boolean(config.features?.admin_panel);
    const adminPath = normalizeAdminPathTs(config.admin?.path);
    if (!enabled) {
      return { enabled: false, adminPath, hasOwner: true };
    }

    const inquiryDb = path.join(siteRoot, "core", "inquiry-db.php");
    const adminDb = path.join(siteRoot, "core", "admin-db.php");
    const adminAuth = path.join(siteRoot, "core", "admin-auth.php");
    if (!existsSync(inquiryDb) || !existsSync(adminDb) || !existsSync(adminAuth)) {
      return { enabled, adminPath, hasOwner: false };
    }

    const script = `
      define('LUMORA_BOOTSTRAPPED', true);
      require_once ${JSON.stringify(toPosixPath(inquiryDb))};
      require_once ${JSON.stringify(toPosixPath(adminDb))};
      require_once ${JSON.stringify(toPosixPath(adminAuth))};
      $pdo = \\Lumora\\Core\\AdminDb::connectExisting(${JSON.stringify(toPosixPath(siteRoot))});
      $hasOwner = ($pdo !== null) ? \\Lumora\\Core\\AdminAuth::hasAnyOwner($pdo) : false;
      echo json_encode(['has_owner' => $hasOwner], JSON_UNESCAPED_SLASHES);
    `;
    const res = evalPhpJson<{ has_owner?: boolean }>(script, siteRoot);
    return { enabled, adminPath, hasOwner: Boolean(res.has_owner) };
  } catch {
    return { enabled: false, adminPath: "/admin/", hasOwner: true };
  }
}

export async function runDevServer(options: DevServerOptions = {}): Promise<void> {
  const siteRoot = path.resolve(options.cwd ?? process.cwd());
  const host = options.host ?? "127.0.0.1";
  const port = options.port ?? 8080;

  syncWebsite({ cwd: siteRoot });
  await compileBrandCss({ cwd: siteRoot, minify: false });

  const adminState = inspectDevAdminState(siteRoot);
  const existingSetupToken = (process.env.LUMORA_ADMIN_SETUP_TOKEN ?? "").trim();
  const phpEnv: Record<string, string | undefined> = { ...process.env };
  let activeSetupToken: string | null = null;
  let generatedSetupToken = false;

  if (adminState.enabled && !adminState.hasOwner) {
    if (existingSetupToken.length >= 16) {
      activeSetupToken = existingSetupToken;
    } else {
      activeSetupToken = randomBytes(16).toString("hex");
      phpEnv.LUMORA_ADMIN_SETUP_TOKEN = activeSetupToken;
      generatedSetupToken = true;
    }
  }

  console.log(`\n🚀 [lumora-web dev] Starting PHP 8.2+ dev server`);
  console.log(`   Website URL:        http://${host}:${port}/ (http://localhost:${port}/)`);
  if (adminState.enabled) {
    console.log(`   Admin Console URL:  http://${host}:${port}${adminState.adminPath} (http://localhost:${port}${adminState.adminPath})`);
    if (!adminState.hasOwner && activeSetupToken) {
      const originNote = generatedSetupToken ? "ephemeral 128-bit token for this dev session" : "from LUMORA_ADMIN_SETUP_TOKEN";
      console.log(`   First-Time Setup:   No owner account exists yet (${originNote}).`);
      console.log(`   Setup Token:        ${activeSetupToken}`);
      console.log(`   CLI Alternative:    bunx lumora-web admin create-user --email admin@example.com --role owner`);
    }
  }
  console.log(`   Watching css/, templates/, and content/ for live Tailwind v4 + SRI rebuilds...\n`);

  const watchDirs = ["css", "templates", "content"].map((d) => path.join(siteRoot, d));
  const watchers: FSWatcher[] = [];
  let debounceTimer: ReturnType<typeof setTimeout> | null = null;

  for (const dir of watchDirs) {
    if (!existsSync(dir)) continue;
    try {
      const watcher = watch(dir, { recursive: true }, (_event, filename) => {
        if (!filename) return;
        const baseName = path.basename(String(filename));
        if (baseName.startsWith(".lumora-") || baseName.startsWith("lumora.min.css")) {
          return;
        }
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
          compileBrandCss({ cwd: siteRoot, minify: false }).catch((err) => {
            console.error("[lumora-web dev] CSS compile error:", err);
          });
        }, 120);
      });
      watchers.push(watcher);
    } catch {
      // Directory watch is optional on platforms without recursive watch support
    }
  }

  const phpProc = Bun.spawn(["php", "-S", `${host}:${port}`, "router.php"], {
    cwd: siteRoot,
    env: phpEnv,
    stdout: "inherit",
    stderr: "inherit",
  });

  try {
    await phpProc.exited;
  } finally {
    if (debounceTimer) clearTimeout(debounceTimer);
    for (const watcher of watchers) {
      try {
        watcher.close();
      } catch {}
    }
  }
}
