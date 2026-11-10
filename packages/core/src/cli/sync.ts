import { copyFileSync, existsSync, mkdirSync, readdirSync, statSync, writeFileSync } from "node:fs";
import path from "node:path";
import type { LumoraLockfile } from "../types/config";
import {
  loadPhpSiteConfig,
  resolveFrameworkDoc,
  resolvePackageVersion,
  resolveRuntimeDir,
  sha256File,
  sriSha384File,
  toPosixPath,
} from "./paths";

export interface SyncOptions {
  cwd?: string;
  forceStubs?: boolean;
  withAgents?: boolean;
  silent?: boolean;
}

export function syncWebsite(options: SyncOptions = {}): LumoraLockfile {
  const siteRoot = path.resolve(options.cwd ?? process.cwd());
  const runtimeDir = resolveRuntimeDir();
  const version = resolvePackageVersion();

  const runtimePhpCore = path.join(runtimeDir, "php", "core");
  const runtimePhpStubs = path.join(runtimeDir, "php", "stubs");
  const runtimeCoreJs = path.join(runtimeDir, "js", "lumora-core.js");

  const targetCoreDir = path.join(siteRoot, "core");
  const targetJsDir = path.join(siteRoot, "public", "assets", "js");
  const targetCssDir = path.join(siteRoot, "public", "assets", "css");

  mkdirSync(targetCoreDir, { recursive: true });
  mkdirSync(targetJsDir, { recursive: true });
  mkdirSync(targetCssDir, { recursive: true });

  // 1. Copy all PHP core files into <website>/core/
  const coreFiles = readdirSync(runtimePhpCore)
    .filter((f) => f.endsWith(".php"))
    .sort();

  const coreSha256: Record<string, string> = {};

  for (const file of coreFiles) {
    const srcFile = path.join(runtimePhpCore, file);
    const destFile = path.join(targetCoreDir, file);
    copyFileSync(srcFile, destFile);
    const relKey = `/core/${file}`;
    coreSha256[relKey] = sha256File(destFile);
  }

  // 2. Copy shared client ES module runtime into <website>/public/assets/js/lumora-core.js
  const destCoreJs = path.join(targetJsDir, "lumora-core.js");
  copyFileSync(runtimeCoreJs, destCoreJs);
  coreSha256["/public/assets/js/lumora-core.js"] = sha256File(destCoreJs);

  // 3. Ensure root stubs exist (index.php, router.php, .htaccess)
  const stubMap: Array<{ stub: string; target: string }> = [
    { stub: "index.stub.php", target: "index.php" },
    { stub: "router.stub.php", target: "router.php" },
    { stub: "htaccess.stub.conf", target: ".htaccess" },
  ];
  for (const item of stubMap) {
    const dest = path.join(siteRoot, item.target);
    if (options.forceStubs || !existsSync(dest)) {
      copyFileSync(path.join(runtimePhpStubs, item.stub), dest);
    }
  }

  // 3b. Optionally sync consumer AGENTS.md and .cursorrules for agentic coding
  if (options.withAgents) {
    const agentsSrc = resolveFrameworkDoc("AGENTS.md");
    if (agentsSrc) {
      copyFileSync(agentsSrc, path.join(siteRoot, "AGENTS.md"));
    }
    const cursorSrc = resolveFrameworkDoc(".cursorrules");
    if (cursorSrc) {
      copyFileSync(cursorSrc, path.join(siteRoot, ".cursorrules"));
    }
  }

  // 4. Compute SHA-384 SRI hashes for fonts, compiled CSS, core JS, and vendor JS
  const sri: Record<string, string> = {};
  sri["/public/assets/js/lumora-core.js"] = sriSha384File(destCoreJs);

  const compiledCssPath = path.join(targetCssDir, "lumora.min.css");
  if (existsSync(compiledCssPath)) {
    sri["/public/assets/css/lumora.min.css"] = sriSha384File(compiledCssPath);
  }

  // Scan public/fonts/ for .woff2 files
  const fontsDir = path.join(siteRoot, "public", "fonts");
  if (existsSync(fontsDir) && statSync(fontsDir).isDirectory()) {
    const fontFiles = readdirSync(fontsDir)
      .filter((f) => f.toLowerCase().endsWith(".woff2"))
      .sort();
    for (const fontFile of fontFiles) {
      const fullFont = path.join(fontsDir, fontFile);
      sri[`/public/fonts/${fontFile}`] = sriSha384File(fullFont);
    }
  }

  // Also check lumora.config.php for any additional declared fonts or vendor_js
  if (existsSync(path.join(siteRoot, "lumora.config.php"))) {
    try {
      const siteCfg = loadPhpSiteConfig(siteRoot) as {
        assets?: {
          compiled_css?: string;
          core_js?: string;
          fonts?: string[];
          vendor_js?: string[];
        };
      };
      const assets = siteCfg.assets ?? {};
      const declaredPaths = [
        ...(assets.fonts ?? []),
        ...(assets.vendor_js ?? []),
        ...(assets.compiled_css ? [assets.compiled_css] : []),
        ...(assets.core_js ? [assets.core_js] : []),
      ];
      for (const rel of declaredPaths) {
        const cleanRel = "/" + toPosixPath(rel).replace(/^\/+/, "");
        const absFile = path.join(siteRoot, cleanRel);
        if (existsSync(absFile) && statSync(absFile).isFile()) {
          sri[cleanRel] = sriSha384File(absFile);
        }
      }
    } catch {
      // Ignore if lumora.config.php is not yet created
    }
  }

  const sortedSri: Record<string, string> = {};
  for (const k of Object.keys(sri).sort()) {
    sortedSri[k] = sri[k]!;
  }

  const lockData: LumoraLockfile = {
    framework: "@astrake/lumora-webserver",
    version,
    core_sha256: coreSha256,
    sri: sortedSri,
  };

  const lockFile = path.join(siteRoot, "lumora-lock.json");
  writeFileSync(lockFile, JSON.stringify(lockData, null, 2) + "\n", "utf8");

  if (!options.silent) {
    console.log(
      `[lumora-web sync] Synced ${coreFiles.length} core PHP modules + lumora-core.js (${Object.keys(sortedSri).length} SRI hashes) -> lumora-lock.json`
    );
  }

  return lockData;
}
