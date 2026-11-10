import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { resolvePackageRoot, resolveRuntimeDir, toPosixPath } from "./paths";
import { syncWebsite } from "./sync";

export interface CssCompileOptions {
  cwd?: string;
  minify?: boolean;
  silent?: boolean;
}

/**
 * Resolves `@import` chains for `lumora-base.css` and `brand-theme.css` and compiles via Tailwind CSS v4 CLI
 * into `<website>/public/assets/css/lumora.min.css`, then updates `lumora-lock.json` SRI checksums.
 */
export async function compileBrandCss(options: CssCompileOptions = {}): Promise<{
  outputPath: string;
  bytes: number;
}> {
  const siteRoot = path.resolve(options.cwd ?? process.cwd());
  const runtimeDir = resolveRuntimeDir();
  const baseCssPath = path.join(runtimeDir, "css", "lumora-base.css");

  const brandCssPath = path.join(siteRoot, "css", "brand.css");
  const brandThemePath = path.join(siteRoot, "css", "brand-theme.css");
  const outputPath = path.join(siteRoot, "public", "assets", "css", "lumora.min.css");

  mkdirSync(path.dirname(outputPath), { recursive: true });

  const baseCssContent = readFileSync(baseCssPath, "utf8");
  const themeCssContent = existsSync(brandThemePath) ? readFileSync(brandThemePath, "utf8") : "";
  let brandCssContent = existsSync(brandCssPath) ? readFileSync(brandCssPath, "utf8") : "";

  // Strip local @import references to lumora-base.css and brand-theme.css since we inline them deterministically
  brandCssContent = brandCssContent
    .replace(/@import\s+["'][^"']*lumora-base\.css["']\s*;/g, "")
    .replace(/@import\s+["'][^"']*brand-theme\.css["']\s*;/g, "");

  const combinedInput = `${baseCssContent}\n${themeCssContent}\n${brandCssContent}\n`;
  const tempInputPath = path.join(siteRoot, "css", ".lumora-combined-build.css");
  mkdirSync(path.dirname(tempInputPath), { recursive: true });
  writeFileSync(tempInputPath, combinedInput, "utf8");

  try {
    const tailwindCliCandidates = [
      path.join(siteRoot, "node_modules", ".bin", process.platform === "win32" ? "tailwindcss.cmd" : "tailwindcss"),
      path.join(
        resolvePackageRoot(),
        "node_modules",
        ".bin",
        process.platform === "win32" ? "tailwindcss.cmd" : "tailwindcss"
      ),
      path.join(
        resolvePackageRoot(),
        "..",
        "..",
        "node_modules",
        ".bin",
        process.platform === "win32" ? "tailwindcss.cmd" : "tailwindcss"
      ),
    ];

    const cliBin = tailwindCliCandidates.find((c) => existsSync(c));
    let compiledSuccessfully = false;

    if (cliBin) {
      const args = ["-i", tempInputPath, "-o", outputPath];
      if (options.minify !== false) {
        args.push("--minify");
      }
      const proc = Bun.spawnSync(
        process.platform === "win32" ? ["cmd.exe", "/c", cliBin, ...args] : [cliBin, ...args],
        {
          cwd: siteRoot,
          stdout: "pipe",
          stderr: "pipe",
        }
      );
      if (proc.exitCode === 0 && existsSync(outputPath)) {
        compiledSuccessfully = true;
      }
    }

    if (!compiledSuccessfully) {
      // Deterministic fallback compiler: converts @theme into :root variables and minifies CSS
      const fallbackCss = transformThemeAndMinifyCss(combinedInput);
      writeFileSync(outputPath, fallbackCss, "utf8");
    }
  } finally {
    try {
      rmSync(tempInputPath, { force: true });
    } catch {}
  }

  // Re-sync lockfile so lumora.min.css SRI SHA-384 is always up to date
  syncWebsite({ cwd: siteRoot, silent: true });

  const bytes = readFileSync(outputPath).byteLength;
  if (!options.silent) {
    console.log(
      `[lumora-web css] Compiled ${toPosixPath(path.relative(siteRoot, outputPath))} (${bytes} bytes)`
    );
  }

  return { outputPath, bytes };
}

function transformThemeAndMinifyCss(rawCss: string): string {
  let out = rawCss.replace(/@import\s+["']tailwindcss["']\s*;/g, "");
  // Convert @theme { ... } blocks into :root { ... } custom property declarations
  out = out.replace(/@theme\s*\{/g, ":root {");
  // Unwrap @layer base / components / utilities for clean standalone cascade
  out = out.replace(/@layer\s+[a-zA-Z0-9_-]+\s*\{([\s\S]*?)\n\}/g, "$1");
  // Strip comments and collapse redundant whitespace
  out = out
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/\s+/g, " ")
    .replace(/\s*([{};:,])\s*/g, "$1")
    .trim();
  return out + "\n";
}
