import { createHash } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

export function resolvePackageRoot(): string {
  const currentDir = path.dirname(fileURLToPath(import.meta.url));
  const candidates = [
    path.resolve(currentDir, "../.."),
    path.resolve(currentDir, "../../.."),
    path.resolve(currentDir, ".."),
  ];
  for (const cand of candidates) {
    if (existsSync(path.join(cand, "runtime", "php", "core", "bootstrap.php"))) {
      return cand;
    }
  }
  throw new Error(`Could not locate @astrake/lumora-webserver runtime directory from ${currentDir}`);
}

export function resolveRuntimeDir(): string {
  return path.join(resolvePackageRoot(), "runtime");
}

export function resolveFrameworkDoc(filename: string): string | null {
  const pkgRoot = resolvePackageRoot();
  const candidates = [
    path.join(pkgRoot, filename),
    path.resolve(pkgRoot, "../..", filename),
  ];
  for (const cand of candidates) {
    if (existsSync(cand)) {
      return cand;
    }
  }
  return null;
}

export function resolvePackageVersion(): string {
  try {
    const pkgPath = path.join(resolvePackageRoot(), "package.json");
    const parsed = JSON.parse(readFileSync(pkgPath, "utf8")) as { version?: string };
    return parsed.version ?? "0.1.0";
  } catch {
    return "0.1.0";
  }
}

export function toPosixPath(p: string): string {
  return p.replace(/\\/g, "/");
}

export function sha256Buffer(buf: Buffer | Uint8Array | string): string {
  return createHash("sha256").update(buf).digest("hex");
}

export function sha256File(filePath: string): string {
  return sha256Buffer(readFileSync(filePath));
}

export function sriSha384Buffer(buf: Buffer | Uint8Array | string): string {
  return "sha384-" + createHash("sha384").update(buf).digest("base64");
}

export function sriSha384File(filePath: string): string {
  return sriSha384Buffer(readFileSync(filePath));
}

export function evalPhpJson<T>(phpScript: string, cwd: string, envOverrides: Record<string, string> = {}): T {
  const proc = Bun.spawnSync(["php", "-r", phpScript], {
    cwd,
    env: { ...process.env, ...envOverrides },
    stdout: "pipe",
    stderr: "pipe",
  });
  if (proc.exitCode !== 0) {
    const errText = new TextDecoder().decode(proc.stderr).trim();
    const outText = new TextDecoder().decode(proc.stdout).trim();
    throw new Error(`PHP evaluation failed (exit ${proc.exitCode}): ${errText || outText}`);
  }
  const rawOut = new TextDecoder().decode(proc.stdout).trim();
  return JSON.parse(rawOut) as T;
}

export function loadPhpSiteConfig(siteRoot: string): Record<string, unknown> {
  const cfgPath = path.join(siteRoot, "lumora.config.php");
  if (!existsSync(cfgPath)) {
    throw new Error(`Missing lumora.config.php in ${siteRoot}`);
  }
  const script = `
    define('LUMORA_BOOTSTRAPPED', true);
    $cfg = require ${JSON.stringify(toPosixPath(cfgPath))};
    echo json_encode($cfg, JSON_UNESCAPED_SLASHES);
  `;
  return evalPhpJson<Record<string, unknown>>(script, siteRoot);
}

export function loadPhpSiteRoutes(siteRoot: string): {
  routes: Record<string, Record<string, unknown>>;
  redirects: Record<string, string>;
} {
  const routesPath = path.join(siteRoot, "content", "routes.php");
  if (!existsSync(routesPath)) {
    return { routes: {}, redirects: {} };
  }
  const script = `
    define('LUMORA_BOOTSTRAPPED', true);
    $loaded = require ${JSON.stringify(toPosixPath(routesPath))};
    if (isset($loaded['routes']) && is_array($loaded['routes'])) {
      echo json_encode([
        'routes' => $loaded['routes'],
        'redirects' => is_array($loaded['redirects'] ?? null) ? $loaded['redirects'] : [],
      ], JSON_UNESCAPED_SLASHES);
    } else {
      echo json_encode(['routes' => is_array($loaded) ? $loaded : [], 'redirects' => []], JSON_UNESCAPED_SLASHES);
    }
  `;
  return evalPhpJson(script, siteRoot);
}
