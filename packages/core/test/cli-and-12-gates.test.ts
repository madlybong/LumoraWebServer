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
      expect(distResult.generatedStubs).toContain("/releases/");
      expect(distResult.generatedStubs).toContain("/legal/privacy/");
      expect(distResult.generatedStubs).toContain("/legal/terms/");

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
          brandId: "acme",
          brandName: "Acme Labs",
          brandPrefix: "ACM",
          domain: "acme.example.com",
          legalEntity: "Acme Corp",
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
        expect(existsSync(path.join(tmpDir, "content", "legal", "privacy.php"))).toBe(true);
        expect(existsSync(path.join(tmpDir, "content", "legal", "terms.php"))).toBe(true);

        await buildWebsiteDist({ cwd: tmpDir, silent: true });
        const verifyReport = await verifyWebsite({ cwd: tmpDir, silent: true });
        expect(verifyReport.passed).toBe(true);
      } finally {
        rmSync(tmpDir, { recursive: true, force: true });
      }
    },
    30000
  );

  test(
    "Profile A (Personal Portfolio, legal_registry = false, Person schema) passes build & 12 gates without legal stubs",
    async () => {
      const tmpDir = mkdtempSync(path.join(os.tmpdir(), "lumora-profile-a-"));
      try {
        await initWebsite({
          cwd: tmpDir,
          brandId: "alex-dev",
          brandName: "Alex Rivera",
          brandPrefix: "ALX",
          domain: "alex.example.com",
          silent: true,
        });

        writeFileSync(
          path.join(tmpDir, "lumora.config.php"),
          `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }
return [
    'brand' => [
        'id'              => 'alex-dev',
        'prefix'          => 'ALX',
        'name'            => 'Alex Rivera',
        'domain'          => 'alex.example.com',
        'origin'          => 'https://alex.example.com',
        'version'         => '0.1.0',
        'locale'          => 'en-US',
        'schema_org_type' => 'Person',
        'contact_email'   => 'hello@alex.example.com',
        'address'         => [],
    ],
    'legal' => [
        'territories'        => ['GLOBAL'],
        'retention_days_pii' => 0,
    ],
    'discovery' => [
        'security_txt' => false,
    ],
    'features' => [
        'legal_registry' => false,
        'blog_engine'    => false,
    ],
];
`,
          "utf8"
        );

        const dist = await buildWebsiteDist({ cwd: tmpDir, silent: true });
        expect(dist.generatedStubs).not.toContain("/legal/privacy/");
        expect(dist.generatedStubs).not.toContain("/legal/company/");

        const report = await verifyWebsite({ cwd: tmpDir, silent: true });
        expect(report.passed).toBe(true);
      } finally {
        rmSync(tmpDir, { recursive: true, force: true });
      }
    },
    30000
  );

  test(
    "Profile B (Commercial Site with 2 legal slugs & city-only address) builds only privacy/terms and passes 12 gates",
    async () => {
      const tmpDir = mkdtempSync(path.join(os.tmpdir(), "lumora-profile-b-"));
      try {
        await initWebsite({
          cwd: tmpDir,
          brandId: "acme-pulse",
          brandName: "Pulse Audio",
          brandPrefix: "PLS",
          domain: "pulse.example.com",
          legalEntity: "Parent Billing Co",
          silent: true,
        });

        writeFileSync(
          path.join(tmpDir, "lumora.config.php"),
          `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }
return [
    'brand' => [
        'id'                            => 'acme-pulse',
        'prefix'                        => 'PLS',
        'name'                          => 'Pulse Audio',
        'legal_entity'                  => 'Parent Billing Co',
        'expose_legal_entity_in_schema' => false,
        'expose_legal_entity_in_llms'   => false,
        'domain'                        => 'pulse.example.com',
        'origin'                        => 'https://pulse.example.com',
        'locale'                        => 'en',
        'schema_org_type'               => 'Organization',
        'contact_email'                 => 'hello@pulse.example.com',
        'address'                       => ['locality' => 'Berlin', 'country' => 'DE'],
    ],
    'legal' => [
        'territories' => ['GLOBAL'],
        'slugs'       => ['privacy', 'terms'],
    ],
    'discovery' => [
        'security_txt' => false,
    ],
    'features' => [
        'legal_registry' => true,
        'inquiry_engine' => true,
    ],
];
`,
          "utf8"
        );

        const dist = await buildWebsiteDist({ cwd: tmpDir, silent: true });
        expect(dist.generatedStubs).toContain("/legal/privacy/");
        expect(dist.generatedStubs).toContain("/legal/terms/");
        expect(dist.generatedStubs).not.toContain("/legal/company/");
        expect(dist.generatedStubs).not.toContain("/legal/cookies/");

        const report = await verifyWebsite({ cwd: tmpDir, silent: true });
        expect(report.passed).toBe(true);
      } finally {
        rmSync(tmpDir, { recursive: true, force: true });
      }
    },
    30000
  );

  test(
    "Profile C (Regulated Enterprise Portal with IN/EU opt-in and 6 legal slugs) passes build & 12 gates",
    async () => {
      const tmpDir = mkdtempSync(path.join(os.tmpdir(), "lumora-profile-c-"));
      try {
        await initWebsite({
          cwd: tmpDir,
          brandId: "medcore",
          brandName: "MedCore Clinical",
          brandPrefix: "MED",
          domain: "medcore.example.com",
          legalEntity: "MedCore Health Systems Pvt. Ltd.",
          silent: true,
        });

        writeFileSync(
          path.join(tmpDir, "lumora.config.php"),
          `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }
return [
    'brand' => [
        'id'                            => 'medcore',
        'prefix'                        => 'MED',
        'name'                          => 'MedCore Clinical',
        'legal_entity'                  => 'MedCore Health Systems Pvt. Ltd.',
        'expose_legal_entity_in_schema' => true,
        'expose_legal_entity_in_llms'   => true,
        'domain'                        => 'medcore.example.com',
        'origin'                        => 'https://medcore.example.com',
        'locale'                        => 'en-IN',
        'primary_offering_schema'       => true,
        'security_email'                => 'security@medcore.example.com',
        'address'                       => [
            'street'      => '100 Health Tech Park',
            'locality'    => 'Hyderabad',
            'region'      => 'Telangana',
            'postal_code' => '500081',
            'country'     => 'IN',
        ],
    ],
    'legal' => [
        'territories'         => ['IN', 'EU', 'GLOBAL'],
        'entity_type'         => 'Private Limited Company',
        'grievance_officer'   => 'Data Protection Officer',
        'grievance_email'     => 'dpo@medcore.example.com',
        'slugs'               => ['privacy', 'terms', 'cookies', 'accessibility', 'security', 'company'],
        'retention_days_pii'  => 90,
        'retention_days_logs' => 180,
    ],
    'features' => [
        'legal_registry' => true,
        'inquiry_engine' => true,
    ],
];
`,
          "utf8"
        );

        const dist = await buildWebsiteDist({ cwd: tmpDir, silent: true });
        expect(dist.generatedStubs).toContain("/legal/privacy/");
        expect(dist.generatedStubs).toContain("/legal/company/");

        const report = await verifyWebsite({ cwd: tmpDir, silent: true });
        expect(report.passed).toBe(true);
      } finally {
        rmSync(tmpDir, { recursive: true, force: true });
      }
    },
    30000
  );

  test(
    "compileBrandCss compiles real Tailwind v4 utility rules and adaptive tokens on current platform",
    async () => {
      const cssResult = await compileBrandCss({ cwd: starterDir, minify: true, silent: true });
      const compiled = readFileSync(cssResult.outputPath, "utf8");
      expect(cssResult.bytes).toBeGreaterThan(2000);
      expect(compiled).toContain("--lumora-bg");
      expect(compiled).toContain(".lumora-container");
      expect(compiled).toContain("display:flex");
    },
    30000
  );

  test(
    "initWebsite({ withAdmin: true }) passes all 12 hardened compliance gates",
    async () => {
      const tmpDir = mkdtempSync(path.join(os.tmpdir(), "lumora-init-admin-test-"));
      try {
        await initWebsite({
          cwd: tmpDir,
          brandId: "admin-init",
          brandName: "Admin Init Corp",
          brandPrefix: "AIN",
          domain: "admin-init.example.com",
          legalEntity: "Admin Init Ltd.",
          withAdmin: true,
          silent: true,
        });

        await buildWebsiteDist({ cwd: tmpDir, silent: true });
        const report = await verifyWebsite({ cwd: tmpDir, silent: true });
        for (const gate of report.gates) {
          if (!gate.passed) {
            throw new Error(`Init+Admin Gate ${gate.gate} (${gate.name}) failed:\n${gate.details.join("\n")}`);
          }
        }
        expect(report.passed).toBe(true);
      } finally {
        rmSync(tmpDir, { recursive: true, force: true });
      }
    },
    45000
  );

  test(
    "syncWebsite({ withAdmin: true, forceStubs: true, withAgents: true }) upgrades an existing v0.1.x website in-place and passes all 12 gates",
    async () => {
      const tmpDir = mkdtempSync(path.join(os.tmpdir(), "lumora-upgrade-admin-test-"));
      try {
        await initWebsite({
          cwd: tmpDir,
          brandId: "legacy-site",
          brandName: "Legacy Brand",
          brandPrefix: "LEG",
          domain: "legacy.example.com",
          legalEntity: "Legacy Corp",
          withAdmin: false,
          silent: true,
        });

        expect(existsSync(path.join(tmpDir, "public", "assets", "admin", "lumora-admin.js"))).toBe(false);

        const lock = syncWebsite({
          cwd: tmpDir,
          withAdmin: true,
          forceStubs: true,
          withAgents: true,
          silent: true,
        });

        expect(Object.keys(lock.core_sha256).length).toBeGreaterThanOrEqual(17);
        expect(existsSync(path.join(tmpDir, "public", "assets", "admin", "lumora-admin.js"))).toBe(true);
        expect(existsSync(path.join(tmpDir, "public", "assets", "admin", "lumora-admin.css"))).toBe(true);
        expect(existsSync(path.join(tmpDir, "public", "uploads", ".htaccess"))).toBe(true);
        expect(existsSync(path.join(tmpDir, "public", "uploads", "index.html"))).toBe(true);
        expect(existsSync(path.join(tmpDir, "AGENTS.md"))).toBe(true);
        expect(existsSync(path.join(tmpDir, ".cursorrules"))).toBe(true);
        expect(existsSync(path.join(tmpDir, "UPGRADING.md"))).toBe(true);

        const cfgRaw = readFileSync(path.join(tmpDir, "lumora.config.php"), "utf8");
        expect(cfgRaw).toContain("'admin_panel'    => true");
        expect(cfgRaw).toContain("'admin' => [");

        const pkg = JSON.parse(readFileSync(path.join(tmpDir, "package.json"), "utf8")) as {
          scripts?: Record<string, string>;
        };
        expect(pkg.scripts?.admin).toBe("lumora-web admin");

        const dist = await buildWebsiteDist({ cwd: tmpDir, silent: true });
        expect(dist.generatedStubs).toContain("/admin/");
        expect(dist.generatedStubs).toContain("/admin/api/");

        const report = await verifyWebsite({ cwd: tmpDir, silent: true });
        for (const gate of report.gates) {
          if (!gate.passed) {
            throw new Error(`Upgrade+Admin Gate ${gate.gate} (${gate.name}) failed:\n${gate.details.join("\n")}`);
          }
        }
        expect(report.passed).toBe(true);
      } finally {
        rmSync(tmpDir, { recursive: true, force: true });
      }
    },
    45000
  );
});
