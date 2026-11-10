import { describe, expect, test } from "bun:test";
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import { buildWebsiteDist } from "../src/cli/build-dist";
import { compileBrandCss } from "../src/cli/css-compiler";
import { initWebsite } from "../src/cli/init";
import { syncWebsite } from "../src/cli/sync";
import { verifyWebsite } from "../src/cli/verify";

const starterDir = path.resolve(import.meta.dir, "../../../apps/starter");

describe("CLI Toolchain & 12-Gate Compliance Verifier on apps/starter", () => {
  test(
    "syncs runtime, compiles Tailwind v4 CSS, builds zero-dependency dist/, and passes all 12 compliance gates",
    async () => {
      const lock = syncWebsite({ cwd: starterDir, silent: true });
      expect(Object.keys(lock.core_sha256).length).toBeGreaterThanOrEqual(13);

      const cssResult = await compileBrandCss({ cwd: starterDir, minify: true, silent: true });
      expect(cssResult.bytes).toBeGreaterThan(200);

      const distResult = await buildWebsiteDist({ cwd: starterDir, silent: true });
      expect(distResult.generatedStubs).toContain("/contact/");
      expect(distResult.generatedStubs).toContain("/docs/");
      expect(distResult.generatedStubs).toContain("/legal/privacy/");

      const report = await verifyWebsite({ cwd: starterDir, silent: true });
      for (const gate of report.gates) {
        if (!gate.passed) {
          console.error(`Failed Gate ${gate.gate} (${gate.name}):`, gate.details);
        }
        expect(gate.passed).toBe(true);
      }
      expect(report.passed).toBe(true);
      expect(report.gates.length).toBe(12);
    },
    30000
  );

  test(
    "initWebsite non-destructively merges existing consumer package.json, .gitignore, AGENTS.md, and passes 12 gates",
    async () => {
      const tmpDir = mkdtempSync(path.join(os.tmpdir(), "lumora-init-test-"));
      try {
        writeFileSync(
          path.join(tmpDir, "package.json"),
          JSON.stringify({
            name: "existing-brand-site",
            version: "1.2.0",
            scripts: { custom: "echo custom" },
          }),
          "utf8"
        );
        writeFileSync(path.join(tmpDir, ".gitignore"), "# Custom ignore\n.DS_Store\n", "utf8");

        await initWebsite({
          cwd: tmpDir,
          brandId: "auruvi",
          brandName: "Auruvi Labs",
          brandPrefix: "AUR",
          domain: "auruvi.com",
          legalEntity: "Auruvi Labs India",
          withAgents: true,
          silent: true,
        });

        const pkg = JSON.parse(readFileSync(path.join(tmpDir, "package.json"), "utf8")) as {
          name: string;
          version: string;
          scripts: Record<string, string>;
          dependencies: Record<string, string>;
        };
        expect(pkg.name).toBe("existing-brand-site");
        expect(pkg.version).toBe("1.2.0");
        expect(pkg.scripts.custom).toBe("echo custom");
        expect(pkg.scripts.sync).toBe("lumora-web sync");
        expect(pkg.scripts.verify).toBe("lumora-web verify");
        expect(pkg.dependencies["@astrake/lumora-webserver"]).toBe("latest");

        const gitignore = readFileSync(path.join(tmpDir, ".gitignore"), "utf8");
        expect(gitignore).toContain("# Custom ignore");
        expect(gitignore).toContain("dist/");
        expect(gitignore).toContain("storage/");

        expect(existsSync(path.join(tmpDir, "AGENTS.md"))).toBe(true);
        expect(existsSync(path.join(tmpDir, ".cursorrules"))).toBe(true);
        expect(existsSync(path.join(tmpDir, "LUMORA_SETUP.md"))).toBe(true);

        await buildWebsiteDist({ cwd: tmpDir, silent: true });
        const verifyReport = await verifyWebsite({ cwd: tmpDir, silent: true });
        expect(verifyReport.passed).toBe(true);
      } finally {
        rmSync(tmpDir, { recursive: true, force: true });
      }
    },
    30000
  );
});
