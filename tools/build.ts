import { existsSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { buildWebsiteDist } from "../packages/core/src/cli/build-dist";

const rootDir = path.resolve(import.meta.dir, "..");
const tscBinary = path.join(
  rootDir,
  "node_modules",
  ".bin",
  process.platform === "win32" ? "tsc.exe" : "tsc"
);

// 1. Build packages/core
const coreDir = path.join(rootDir, "packages/core");
try {
  rmSync(path.join(coreDir, "dist"), { recursive: true, force: true });
} catch {}

const tscProc = Bun.spawn([tscBinary, "-p", "tsconfig.build.json"], {
  cwd: coreDir,
  stdout: "inherit",
  stderr: "inherit",
});
const tscCode = await tscProc.exited;
if (tscCode !== 0) {
  process.exit(tscCode);
}

const coreBuild = Bun.spawn(
  [
    process.execPath,
    "build",
    "./src/index.ts",
    "./src/types/index.ts",
    "./src/cli/index.ts",
    "./bin/lumora-web.ts",
    "--outdir",
    "./dist",
    "--target",
    "bun",
    "--packages",
    "external",
  ],
  {
    cwd: coreDir,
    stdout: "inherit",
    stderr: "inherit",
  }
);
const coreCode = await coreBuild.exited;
if (coreCode !== 0) {
  process.exit(coreCode);
}

const binJsPath = path.join(coreDir, "dist", "bin", "lumora-web.js");
writeFileSync(binJsPath, readFileSync(binJsPath, "utf8").replace(/\r\n/g, "\n"), "utf8");

// 2. Build packages/admin (AOT compiled Vue 3 + shadcn-vue + Tailwind v4 runtime assets)
const adminDir = path.join(rootDir, "packages/admin");
const viteNames = process.platform === "win32" ? ["vite.exe", "vite.cmd"] : ["vite"];
const viteCandidates = [adminDir, rootDir].flatMap((base) =>
  viteNames.map((bin) => path.join(base, "node_modules", ".bin", bin))
);
const viteBin = viteCandidates.find((candidate) => existsSync(candidate));
if (!viteBin) {
  console.error(`Unable to locate Vite binary in:\n${viteCandidates.join("\n")}`);
  process.exit(1);
}
const adminBuild = Bun.spawn([viteBin, "build"], {
  cwd: adminDir,
  stdout: "inherit",
  stderr: "inherit",
});
const adminCode = await adminBuild.exited;
if (adminCode !== 0) {
  process.exit(adminCode);
}

// Verify Gate 05 & Gate 06 compliance on compiled admin bundle
const compiledAdminJs = readFileSync(path.join(coreDir, "runtime/admin/lumora-admin.js"), "utf8");
const compiledAdminCss = readFileSync(path.join(coreDir, "runtime/admin/lumora-admin.css"), "utf8");
for (const forbidden of [/\beval\s*\(/, /new\s+Function\s*\(/, /fonts\.googleapis\.com/i, /unpkg\.com|cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com/i]) {
  if (forbidden.test(compiledAdminJs) || forbidden.test(compiledAdminCss)) {
    console.error(`Gate 05 violation in compiled admin bundle (${forbidden})`);
    process.exit(1);
  }
}

// 3. Build apps/starter dist/ bundle
const starterDir = path.join(rootDir, "apps/starter");
await buildWebsiteDist({ cwd: starterDir });

console.log("Built 3 projects (packages/core + packages/admin + apps/starter).");
