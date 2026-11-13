import {
  copyFileSync,
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import path from "node:path";
import { compileBrandCss } from "./css-compiler";
import { loadPhpLegalSlugs, loadPhpSiteConfig, loadPhpSiteRoutes, resolveRuntimeDir, toPosixPath } from "./paths";
import { syncWebsite } from "./sync";

export interface BuildDistOptions {
  cwd?: string;
  silent?: boolean;
}

export interface BuildDistResult {
  distDir: string;
  generatedStubs: string[];
}

export async function buildWebsiteDist(options: BuildDistOptions = {}): Promise<BuildDistResult> {
  const siteRoot = path.resolve(options.cwd ?? process.cwd());
  const runtimeDir = resolveRuntimeDir();
  const distDir = path.join(siteRoot, "dist");

  // 1. Sync core runtime & compile brand CSS first
  syncWebsite({ cwd: siteRoot, silent: options.silent });
  await compileBrandCss({ cwd: siteRoot, minify: true, silent: options.silent });

  // 2. Clean existing dist/
  rmSync(distDir, { recursive: true, force: true });
  mkdirSync(distDir, { recursive: true });

  // 3. Copy root production entry files
  const rootFiles = ["index.php", "lumora.config.php", "lumora-lock.json", ".htaccess"];
  for (const file of rootFiles) {
    const src = path.join(siteRoot, file);
    if (existsSync(src)) {
      copyFileSync(src, path.join(distDir, file));
    }
  }

  // Copy optional .well-known/security.txt and site.webmanifest if customized at root
  if (existsSync(path.join(siteRoot, ".well-known", "security.txt"))) {
    mkdirSync(path.join(distDir, ".well-known"), { recursive: true });
    copyFileSync(
      path.join(siteRoot, ".well-known", "security.txt"),
      path.join(distDir, ".well-known", "security.txt")
    );
  }
  if (existsSync(path.join(siteRoot, "site.webmanifest"))) {
    copyFileSync(path.join(siteRoot, "site.webmanifest"), path.join(distDir, "site.webmanifest"));
  }

  // Emit nginx.lumora.conf from runtime stub
  const nginxStub = path.join(runtimeDir, "php", "stubs", "nginx.stub.conf");
  if (existsSync(nginxStub)) {
    copyFileSync(nginxStub, path.join(distDir, "nginx.lumora.conf"));
  }

  // 4. Copy core/, templates/, content/, public/, and optional releases/ (filtering forbidden extensions)
  for (const dirName of ["core", "templates", "content", "public", "releases"]) {
    const srcDir = path.join(siteRoot, dirName);
    if (existsSync(srcDir) && statSync(srcDir).isDirectory()) {
      copyDirectoryFiltered(srcDir, path.join(distDir, dirName));
    }
  }

  // 5. If content/docs/manifest.json exists, inline .md article bodies into dist/content/docs/manifest.json
  //    so dist/ contains zero .md files while DocsEngine still renders all articles!
  const srcDocsManifest = path.join(siteRoot, "content", "docs", "manifest.json");
  const distDocsManifest = path.join(distDir, "content", "docs", "manifest.json");
  if (existsSync(srcDocsManifest) && existsSync(distDocsManifest)) {
    try {
      const manifest = JSON.parse(readFileSync(srcDocsManifest, "utf8")) as {
        version?: string;
        tracks?: Record<
          string,
          {
            articles?: Array<Record<string, unknown>>;
          }
        >;
      };
      if (manifest.tracks) {
        for (const track of Object.values(manifest.tracks)) {
          for (const article of track.articles ?? []) {
            if (typeof article.file === "string") {
              const mdPath = path.join(siteRoot, "content", "docs", article.file);
              if (existsSync(mdPath)) {
                article.markdown = readFileSync(mdPath, "utf8");
              }
            }
          }
        }
      }
      writeFileSync(distDocsManifest, JSON.stringify(manifest, null, 2) + "\n", "utf8");
    } catch {
      // Ignore malformed manifest
    }
  }

  // 6. Initialize protected dist/storage/ directory with 403 guard & .htaccess
  const distStorage = path.join(distDir, "storage");
  mkdirSync(distStorage, { recursive: true });
  writeFileSync(
    path.join(distStorage, "index.php"),
    "<?php\ndeclare(strict_types=1);\nif (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }\nhttp_response_code(403);\nexit('403 Forbidden');\n",
    "utf8"
  );
  writeFileSync(
    path.join(distStorage, ".htaccess"),
    "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
    "utf8"
  );

  // 7. Generate physical zero-rewrite route stubs (dist/<route>/index.php)
  const siteConfig = loadPhpSiteConfig(siteRoot) as {
    features?: { docs_engine?: boolean; blog_engine?: boolean };
  };
  const routeConfig = loadPhpSiteRoutes(siteRoot);
  const canonicalRoutes = new Set<string>();

  for (const routePath of Object.keys(routeConfig.routes)) {
    if (routePath !== "/" && !/\.[a-zA-Z0-9]{1,6}$/.test(routePath)) {
      const clean = "/" + routePath.replace(/^\/+|\/+$/g, "") + "/";
      canonicalRoutes.add(clean);
    }
  }

  for (const redirectFrom of Object.keys(routeConfig.redirects)) {
    if (redirectFrom !== "/" && !/\.[a-zA-Z0-9]{1,6}$/.test(redirectFrom)) {
      const clean = "/" + redirectFrom.replace(/^\/+|\/+$/g, "") + "/";
      canonicalRoutes.add(clean);
    }
  }

  // Generate physical stubs for app-configured legal routes
  for (const legalSlug of loadPhpLegalSlugs(siteRoot)) {
    canonicalRoutes.add(`/legal/${legalSlug}/`);
  }

  // Include docs article routes if docs_engine is enabled
  if (siteConfig.features?.docs_engine && existsSync(srcDocsManifest)) {
    try {
      const manifest = JSON.parse(readFileSync(srcDocsManifest, "utf8")) as {
        tracks?: Record<string, { articles?: Array<{ slug?: string }> }>;
      };
      for (const [trackKey, track] of Object.entries(manifest.tracks ?? {})) {
        for (const art of track.articles ?? []) {
          if (art.slug) {
            canonicalRoutes.add(`/docs/${art.slug}/`);
            canonicalRoutes.add(`/docs/${trackKey}/${art.slug}/`);
          }
        }
      }
    } catch {}
  }

  // Include blog article routes if blog_engine is enabled
  const blogDir = path.join(siteRoot, "content", "blog");
  if (siteConfig.features?.blog_engine && existsSync(blogDir)) {
    for (const f of readdirSync(blogDir)) {
      if (f.endsWith(".php") && f !== "index.php") {
        const slug = f.slice(0, -4);
        canonicalRoutes.add(`/blog/${slug}/`);
      }
    }
  }

  const generatedStubs: string[] = [];
  for (const routePath of Array.from(canonicalRoutes).sort()) {
    const segments = routePath.replace(/^\/+|\/+$/g, "").split("/").filter(Boolean);
    if (segments.length === 0) continue;

    const stubDir = path.join(distDir, ...segments);
    mkdirSync(stubDir, { recursive: true });
    const stubFile = path.join(stubDir, "index.php");
    const relToRoot = segments.map(() => "..").join("/");

    const stubPhp = `<?php
declare(strict_types=1);
/**
 * @astrake/lumora-webserver — Generated Zero-Rewrite Route Stub (${routePath})
 */
$GLOBALS['lumoraForcedRoute'] = ${JSON.stringify(routePath)};
require __DIR__ . '/${relToRoot}/index.php';
`;
    writeFileSync(stubFile, stubPhp, "utf8");
    generatedStubs.push(routePath);
  }

  if (!options.silent) {
    console.log(
      `[lumora-web build] Assembled zero-dependency dist/ bundle with ${generatedStubs.length} physical route stubs.`
    );
  }

  return { distDir, generatedStubs };
}

function copyDirectoryFiltered(srcDir: string, destDir: string): void {
  mkdirSync(destDir, { recursive: true });
  const entries = readdirSync(srcDir, { withFileTypes: true });

  for (const entry of entries) {
    const name = entry.name;
    if (name === "node_modules" || name === ".git" || name === "dist" || name === "storage") {
      continue;
    }
    const lower = name.toLowerCase();
    if (
      lower.endsWith(".md") ||
      lower.endsWith(".lock") ||
      lower.endsWith(".sh") ||
      lower.endsWith(".ts") ||
      lower.startsWith(".lumora-")
    ) {
      continue;
    }

    const srcPath = path.join(srcDir, name);
    const destPath = path.join(destDir, name);

    if (entry.isDirectory()) {
      copyDirectoryFiltered(srcPath, destPath);
    } else if (entry.isFile()) {
      copyFileSync(srcPath, destPath);
    }
  }
}
