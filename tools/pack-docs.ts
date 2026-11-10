import { copyFileSync, existsSync, unlinkSync } from "node:fs";
import path from "node:path";

const mode = process.argv[2] ?? "copy";
const pkgDir = process.cwd();
const rootDir = path.resolve(pkgDir, "../..");

const files = [
  "README.md",
  "CHANGELOG.md",
  "LICENSE",
  "llms.txt",
  "llms-full.txt",
  "AGENTS.md",
  ".cursorrules",
];

if (mode === "copy") {
  for (const file of files) {
    const src = path.join(rootDir, file);
    const dest = path.join(pkgDir, file);
    if (existsSync(src)) {
      copyFileSync(src, dest);
    }
  }
} else if (mode === "clean") {
  for (const file of files) {
    const dest = path.join(pkgDir, file);
    try {
      unlinkSync(dest);
    } catch {
      // Ignore if already removed
    }
  }
}
