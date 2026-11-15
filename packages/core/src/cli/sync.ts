import { copyFileSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
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
  withAdmin?: boolean;
  silent?: boolean;
}

const UPLOADS_HTACCESS =
  [
    "<IfModule mod_php.c>",
    "    php_flag engine off",
    "</IfModule>",
    '<FilesMatch "\\.(?i:php[0-9]?|phtml|phar|pl|py|cgi|sh|svg|svgz|html?|xhtml)$">',
    "    <IfModule mod_authz_core.c>",
    "        Require all denied",
    "    </IfModule>",
    "    <IfModule !mod_authz_core.c>",
    "        Order deny,allow",
    "        Deny from all",
    "    </IfModule>",
    "</FilesMatch>",
    "<IfModule mod_headers.c>",
    '    Header always set X-Content-Type-Options "nosniff"',
    "</IfModule>",
    "Options -Indexes -ExecCGI",
    "",
  ].join("\n");

function ensureTrailingCommaBeforeRootClose(phpSource: string): string {
  const match = /\];\s*$/.exec(phpSource);
  if (!match) return phpSource;
  const head = phpSource.slice(0, match.index).replace(/\s+$/, "");
  const tail = phpSource.slice(match.index);
  if (head.endsWith(",") || head.endsWith("[")) {
    return `${head}\n${tail}`;
  }
  return `${head},\n${tail}`;
}

function enableAdminInPhpConfig(rawConfig: string): string {
  let next = rawConfig;

  if (/(['"])admin_panel\1\s*=>\s*(false|null|0)/i.test(next)) {
    next = next.replace(/(['"])admin_panel\1\s*=>\s*(false|null|0)/gi, `'admin_panel'    => true`);
  } else if (!/(['"])admin_panel\1\s*=>\s*true/i.test(next)) {
    if (/(['"])features\1\s*=>\s*\[/.test(next)) {
      next = next.replace(/(['"])features\1\s*=>\s*\[/, `'features' => [\n        'admin_panel'    => true,`);
    } else if (/\];\s*$/.test(next)) {
      next = ensureTrailingCommaBeforeRootClose(next).replace(
        /\];\s*$/,
        `    'features' => [\n        'admin_panel' => true,\n    ],\n];\n`
      );
    }
  }

  if (!/(['"])admin\1\s*=>\s*\[/.test(next) && /\];\s*$/.test(next)) {
    const brandNameMatch = /(['"])name\1\s*=>\s*'([^']*)'/.exec(next);
    const consoleTitle = brandNameMatch?.[2] ? `${brandNameMatch[2].replace(/'/g, "\\'")} Console` : "Admin Console";
    next = ensureTrailingCommaBeforeRootClose(next).replace(
      /\];\s*$/,
      `    'admin' => [\n        'path'     => '/admin/',\n        'branding' => [\n            'title'         => '${consoleTitle}',\n            'logo'          => '/public/brand/icon.svg',\n            'default_theme' => 'system',\n        ],\n    ],\n];\n`
    );
  }

  return next;
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

  // 3b. Optionally sync consumer AGENTS.md, .cursorrules, and UPGRADING.md for agentic coding
  if (options.withAgents) {
    const agentsSrc = resolveFrameworkDoc("AGENTS.md");
    if (agentsSrc) {
      copyFileSync(agentsSrc, path.join(siteRoot, "AGENTS.md"));
    }
    const cursorSrc = resolveFrameworkDoc(".cursorrules");
    if (cursorSrc) {
      copyFileSync(cursorSrc, path.join(siteRoot, ".cursorrules"));
    }
    const upgradingSrc = resolveFrameworkDoc("UPGRADING.md") ?? resolveFrameworkDoc("docs/user/UPGRADING.md");
    if (upgradingSrc) {
      copyFileSync(upgradingSrc, path.join(siteRoot, "UPGRADING.md"));
    }
  }

  // 3c. Optionally enable features.admin_panel and admin config on existing websites (`--with-admin`)
  if (options.withAdmin) {
    const cfgPath = path.join(siteRoot, "lumora.config.php");
    if (existsSync(cfgPath)) {
      const currentCfg = readFileSync(cfgPath, "utf8");
      const updatedCfg = enableAdminInPhpConfig(currentCfg);
      if (updatedCfg !== currentCfg) {
        writeFileSync(cfgPath, updatedCfg, "utf8");
      }
    }

    const pkgPath = path.join(siteRoot, "package.json");
    if (existsSync(pkgPath)) {
      try {
        const pkg = JSON.parse(readFileSync(pkgPath, "utf8")) as Record<string, unknown>;
        const existingScripts = (pkg.scripts as Record<string, string> | undefined) ?? {};
        if (!existingScripts.admin) {
          pkg.scripts = {
            ...existingScripts,
            admin: "lumora-web admin",
          };
          writeFileSync(pkgPath, JSON.stringify(pkg, null, 2) + "\n", "utf8");
        }
      } catch {
        // Ignore malformed package.json
      }
    }
  }

  // 4. Compute SHA-384 SRI hashes for fonts, compiled CSS, core JS, vendor JS, and opt-in admin panel
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

  let adminEnabled = false;
  // Also check lumora.config.php for features.admin_panel and any additional declared fonts or vendor_js
  if (existsSync(path.join(siteRoot, "lumora.config.php"))) {
    try {
      const siteCfg = loadPhpSiteConfig(siteRoot) as {
        features?: {
          admin_panel?: boolean;
        };
        assets?: {
          compiled_css?: string;
          core_js?: string;
          fonts?: string[];
          vendor_js?: string[];
        };
      };
      adminEnabled = Boolean(siteCfg.features?.admin_panel);
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

  // 4b. Opt-in AOT-compiled Admin Panel assets (public/assets/admin/lumora-admin.{js,css})
  const targetAdminDir = path.join(siteRoot, "public", "assets", "admin");
  if (adminEnabled || options.withAdmin) {
    const runtimeAdminDir = path.join(runtimeDir, "admin");
    const adminAssets = ["lumora-admin.css", "lumora-admin.js"];
    mkdirSync(targetAdminDir, { recursive: true });
    for (const assetName of adminAssets) {
      const srcAsset = path.join(runtimeAdminDir, assetName);
      if (!existsSync(srcAsset)) {
        throw new Error(
          `Missing prebuilt admin runtime asset: ${srcAsset}. Run \`bun run build\` in LumoraWebServer first.`
        );
      }
      const destAsset = path.join(targetAdminDir, assetName);
      copyFileSync(srcAsset, destAsset);
      const relKey = `/public/assets/admin/${assetName}`;
      coreSha256[relKey] = sha256File(destAsset);
      sri[relKey] = sriSha384File(destAsset);
    }

    const uploadsDir = path.join(siteRoot, "public", "uploads");
    mkdirSync(uploadsDir, { recursive: true });
    const uploadsHtaccess = path.join(uploadsDir, ".htaccess");
    if (!existsSync(uploadsHtaccess)) {
      writeFileSync(uploadsHtaccess, UPLOADS_HTACCESS, "utf8");
    }
    const uploadsIndex = path.join(uploadsDir, "index.html");
    if (!existsSync(uploadsIndex)) {
      writeFileSync(uploadsIndex, '<!doctype html><meta name="robots" content="noindex">\n', "utf8");
    }
  } else if (existsSync(targetAdminDir)) {
    rmSync(targetAdminDir, { recursive: true, force: true });
  }

  const sortedCoreSha256: Record<string, string> = {};
  for (const k of Object.keys(coreSha256).sort()) {
    sortedCoreSha256[k] = coreSha256[k]!;
  }

  const sortedSri: Record<string, string> = {};
  for (const k of Object.keys(sri).sort()) {
    sortedSri[k] = sri[k]!;
  }

  const lockData: LumoraLockfile = {
    framework: "@astrake/lumora-webserver",
    version,
    core_sha256: sortedCoreSha256,
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
