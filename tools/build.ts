import { readFileSync, rmSync, writeFileSync } from "node:fs";
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

// 2. Build apps/starter dist/ bundle
const starterDir = path.join(rootDir, "apps/starter");
await buildWebsiteDist({ cwd: starterDir });

console.log("Built 2 projects (packages/core + apps/starter).");
