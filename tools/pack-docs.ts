import { copyFileSync, existsSync, unlinkSync } from "node:fs";
import path from "node:path";

const mode = process.argv[2] ?? "copy";
const pkgDir = process.cwd();
const rootDir = path.resolve(pkgDir, "../..");

const files: Array<{ src: string; dest: string }> = [
  { src: "README.md", dest: "README.md" },
  { src: "CHANGELOG.md", dest: "CHANGELOG.md" },
  { src: "LICENSE", dest: "LICENSE" },
  { src: "llms.txt", dest: "llms.txt" },
  { src: "llms-full.txt", dest: "llms-full.txt" },
  { src: "AGENTS.md", dest: "AGENTS.md" },
  { src: ".cursorrules", dest: ".cursorrules" },
  { src: "docs/user/UPGRADING.md", dest: "UPGRADING.md" },
];

if (mode === "copy") {
  for (const file of files) {
    const src = path.join(rootDir, file.src);
    const dest = path.join(pkgDir, file.dest);
    if (existsSync(src)) {
      copyFileSync(src, dest);
    }
  }
} else if (mode === "clean") {
  for (const file of files) {
    const dest = path.join(pkgDir, file.dest);
    try {
      unlinkSync(dest);
    } catch {
      // Ignore if already removed
    }
  }
}
