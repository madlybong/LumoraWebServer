import { watch } from "node:fs";
import path from "node:path";
import { compileBrandCss } from "./css-compiler";
import { syncWebsite } from "./sync";

export interface DevServerOptions {
  cwd?: string;
  port?: number;
  host?: string;
}

export async function runDevServer(options: DevServerOptions = {}): Promise<void> {
  const siteRoot = path.resolve(options.cwd ?? process.cwd());
  const host = options.host ?? "127.0.0.1";
  const port = options.port ?? 8080;

  syncWebsite({ cwd: siteRoot });
  await compileBrandCss({ cwd: siteRoot, minify: false });

  console.log(`\n🚀 [lumora-web dev] Starting PHP 8.2+ dev server at http://${host}:${port}/`);
  console.log(`   Watching css/ and templates/ for live Tailwind v4 + SRI rebuilds...\n`);

  const cssDir = path.join(siteRoot, "css");
  let debounceTimer: ReturnType<typeof setTimeout> | null = null;

  try {
    watch(cssDir, { recursive: true }, (_event, filename) => {
      if (!filename || filename.startsWith(".lumora-")) return;
      if (debounceTimer) clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        compileBrandCss({ cwd: siteRoot, minify: false }).catch((err) => {
          console.error("[lumora-web dev] CSS compile error:", err);
        });
      }, 120);
    });
  } catch {
    // Directory watch is optional if css/ doesn't exist
  }

  const phpProc = Bun.spawn(["php", "-S", `${host}:${port}`, "router.php"], {
    cwd: siteRoot,
    stdout: "inherit",
    stderr: "inherit",
  });

  await phpProc.exited;
}
