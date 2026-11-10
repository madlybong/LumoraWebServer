import { describe, expect, test } from "bun:test";
import { mkdirSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { evalPhpJson, resolveRuntimeDir, toPosixPath } from "../src/cli/paths";

const starterDir = path.resolve(import.meta.dir, "../../../apps/starter");

describe("Headless PHP 8.2+ Micro-Kernel Runtime", () => {
  test("Security::csrfToken & verifyCsrf are CGNAT-resilient (decoupled from IP rotation, bound to UA + TTL)", () => {
    const securityPhp = toPosixPath(path.join(resolveRuntimeDir(), "php", "core", "security.php"));
    const tempDir = path.join(starterDir, "storage", `.test-csrf-${Date.now()}`);
    mkdirSync(tempDir, { recursive: true });

    try {
      const res = evalPhpJson<{
        valid_same_ip: boolean;
        valid_rotated_ip: boolean;
        invalid_different_ua: boolean;
        invalid_expired: boolean;
      }>(
        `
        define('LUMORA_BOOTSTRAPPED', true);
        require_once ${JSON.stringify(securityPhp)};
        $root = ${JSON.stringify(toPosixPath(tempDir))};
        $_SERVER['REMOTE_ADDR'] = '103.21.244.10';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 15; In-CGNAT)';
        \\Astrake\\Lumora\\Core\\Security::boot(['brand' => ['id' => 'auruvi'], 'security' => ['csrf_ttl_sec' => 7200]], $root);

        $now = 1760000000;
        $token = \\Astrake\\Lumora\\Core\\Security::csrfToken($root, $now);

        $validSameIp = \\Astrake\\Lumora\\Core\\Security::verifyCsrf($root, $token, $now + 30);

        // Simulate CGNAT IP rotation mid-session
        $_SERVER['REMOTE_ADDR'] = '49.36.128.99';
        $validRotatedIp = \\Astrake\\Lumora\\Core\\Security::verifyCsrf($root, $token, $now + 60);

        // Simulate User-Agent mismatch
        $_SERVER['HTTP_USER_AGENT'] = 'Curl/8.0 Attacker';
        $invalidUa = \\Astrake\\Lumora\\Core\\Security::verifyCsrf($root, $token, $now + 60);

        // Restore User-Agent and test TTL expiration (>7200s)
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 15; In-CGNAT)';
        $invalidExpired = \\Astrake\\Lumora\\Core\\Security::verifyCsrf($root, $token, $now + 7205);

        echo json_encode([
          'valid_same_ip' => $validSameIp,
          'valid_rotated_ip' => $validRotatedIp,
          'invalid_different_ua' => $invalidUa,
          'invalid_expired' => $invalidExpired,
        ]);
        `,
        starterDir
      );

      expect(res.valid_same_ip).toBe(true);
      expect(res.valid_rotated_ip).toBe(true);
      expect(res.invalid_different_ua).toBe(false);
      expect(res.invalid_expired).toBe(false);
    } finally {
      rmSync(tempDir, { recursive: true, force: true });
    }
  });

  test("LegalRegistry generates territory-parameterized documents and merges project-level overrides", () => {
    const runtimeCore = toPosixPath(path.join(resolveRuntimeDir(), "php", "core"));
    const tempDir = path.join(starterDir, "storage", `.test-legal-${Date.now()}`);
    mkdirSync(path.join(tempDir, "content", "legal"), { recursive: true });

    writeFileSync(
      path.join(tempDir, "content", "legal", "privacy.php"),
      `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }
return [
    'merge_territory' => true,
    'title' => 'Custom Brand Privacy Policy',
    'append_sections' => [
        ['id' => 'custom-hipaa', 'title' => '6. Clinical Addendum', 'body' => '<p>On-premise clinical data remains inside facility LAN.</p>'],
    ],
];
`,
      "utf8"
    );

    try {
      const res = evalPhpJson<{
        privacy_title: string;
        has_dpdp: boolean;
        has_custom_section: boolean;
        terms_has_jurisdiction: boolean;
      }>(
        `
        define('LUMORA_BOOTSTRAPPED', true);
        require_once ${JSON.stringify(runtimeCore + "/render.php")};
        require_once ${JSON.stringify(runtimeCore + "/legal-registry.php")};

        $cfg = [
          'brand' => [
            'name' => 'Auruvi HMS',
            'legal_entity' => 'Astrake India',
            'domain' => 'auruvi.com',
            'address' => ['locality' => 'Kolkata', 'country' => 'IN'],
          ],
          'legal' => [
            'territories' => ['IN', 'EU'],
            'governing_law' => 'Kolkata, West Bengal, India',
          ],
        ];
        $root = ${JSON.stringify(toPosixPath(tempDir))};
        $privacy = \\Astrake\\Lumora\\Core\\LegalRegistry::resolveDocument('privacy', $cfg, $root);
        $terms = \\Astrake\\Lumora\\Core\\LegalRegistry::resolveDocument('terms', $cfg, $root);

        $secIds = array_column($privacy['sections'], 'id');
        echo json_encode([
          'privacy_title' => $privacy['title'],
          'has_dpdp' => str_contains($privacy['lead'], 'DPDP'),
          'has_custom_section' => in_array('custom-hipaa', $secIds, true),
          'terms_has_jurisdiction' => str_contains(json_encode($terms['sections']), 'Kolkata, West Bengal, India'),
        ]);
        `,
        starterDir
      );

      expect(res.privacy_title).toBe("Custom Brand Privacy Policy");
      expect(res.has_dpdp).toBe(true);
      expect(res.has_custom_section).toBe(true);
      expect(res.terms_has_jurisdiction).toBe(true);
    } finally {
      rmSync(tempDir, { recursive: true, force: true });
    }
  });

  test("InquiryDb::prune enforces 90-day DPDP PII redaction and 180-day CERT-In deletion", () => {
    const runtimeCore = toPosixPath(path.join(resolveRuntimeDir(), "php", "core"));
    const tempDir = path.join(starterDir, "storage", `.test-prune-${Date.now()}`);
    mkdirSync(tempDir, { recursive: true });

    try {
      const res = evalPhpJson<{
        recent_name: string;
        redacted_name: string;
        redacted_email: string;
        purged_exists: boolean;
      }>(
        `
        define('LUMORA_BOOTSTRAPPED', true);
        require_once ${JSON.stringify(runtimeCore + "/security.php")};
        require_once ${JSON.stringify(runtimeCore + "/inquiry-db.php")};

        $root = ${JSON.stringify(toPosixPath(tempDir))};
        \\Astrake\\Lumora\\Core\\Security::boot(['brand' => ['id' => 'test']], $root);
        $pdo = \\Astrake\\Lumora\\Core\\InquiryDb::connect($root);

        $now = time();
        $recentIso = gmdate('Y-m-d\\TH:i:s\\Z', $now - 10 * 86400);
        $old100dIso = gmdate('Y-m-d\\TH:i:s\\Z', $now - 100 * 86400);
        $old200dIso = gmdate('Y-m-d\\TH:i:s\\Z', $now - 200 * 86400);

        \\Astrake\\Lumora\\Core\\InquiryDb::saveSubmission([
          'receipt_id' => 'TST-20261101-0001',
          'channel' => 'general',
          'channel_label' => 'General',
          'full_name' => 'Recent Visitor',
          'email' => 'recent@example.com',
          'subject' => 'Recent',
          'message' => 'Recent message body',
          'ip_hash' => 'hash1',
          'created_at' => $recentIso,
        ], [], $root);

        \\Astrake\\Lumora\\Core\\InquiryDb::saveSubmission([
          'receipt_id' => 'TST-20260801-0002',
          'channel' => 'general',
          'channel_label' => 'General',
          'full_name' => 'Hundred Days Visitor',
          'email' => 'old100@example.com',
          'subject' => 'Old 100d',
          'message' => 'Should be redacted under DPDP 90d rule',
          'ip_hash' => 'hash2',
          'created_at' => $old100dIso,
        ], [], $root);

        \\Astrake\\Lumora\\Core\\InquiryDb::saveSubmission([
          'receipt_id' => 'TST-20260401-0003',
          'channel' => 'general',
          'channel_label' => 'General',
          'full_name' => 'Two Hundred Days Visitor',
          'email' => 'old200@example.com',
          'subject' => 'Old 200d',
          'message' => 'Should be purged under CERT-In 180d rule',
          'ip_hash' => 'hash3',
          'created_at' => $old200dIso,
        ], [], $root);

        \\Astrake\\Lumora\\Core\\InquiryDb::prune($pdo, ['legal' => ['retention_days_pii' => 90, 'retention_days_logs' => 180]]);

        $r1 = $pdo->query("SELECT full_name FROM contact_submissions WHERE receipt_id = 'TST-20261101-0001'")->fetchColumn();
        $r2 = $pdo->query("SELECT full_name, email FROM contact_submissions WHERE receipt_id = 'TST-20260801-0002'")->fetch();
        $r3 = $pdo->query("SELECT COUNT(*) FROM contact_submissions WHERE receipt_id = 'TST-20260401-0003'")->fetchColumn();

        \\Astrake\\Lumora\\Core\\InquiryDb::resetConnection();

        echo json_encode([
          'recent_name' => (string)$r1,
          'redacted_name' => (string)($r2['full_name'] ?? ''),
          'redacted_email' => (string)($r2['email'] ?? ''),
          'purged_exists' => ((int)$r3 > 0),
        ]);
        `,
        starterDir
      );

      expect(res.recent_name).toBe("Recent Visitor");
      expect(res.redacted_name).toBe("[REDACTED-90D]");
      expect(res.redacted_email).toBe("redacted@lumora.invalid");
      expect(res.purged_exists).toBe(false);
    } finally {
      rmSync(tempDir, { recursive: true, force: true });
    }
  });

  test("Render::assetUrl appends SRI cache-busting query string on CSS/JS, Router resolves multi-track docs, and HubClient resolves vault secret", () => {
    const runtimeCore = toPosixPath(path.join(resolveRuntimeDir(), "php", "core"));
    const tempDir = path.join(starterDir, "storage", `.test-audit-${Date.now()}`);
    mkdirSync(path.join(tempDir, "storage"), { recursive: true });
    writeFileSync(
      path.join(tempDir, "lumora-lock.json"),
      JSON.stringify({
        sri: {
          "/public/assets/css/lumora.min.css": "sha384-testcsshash",
          "/public/fonts/manrope.woff2": "sha384-testfonthash",
        },
      }),
      "utf8"
    );
    writeFileSync(
      path.join(tempDir, "storage", "hub-secret.php"),
      "<?php\ndeclare(strict_types=1);\nif (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }\nreturn 'vault_file_hub_secret_key_32_bytes';\n",
      "utf8"
    );

    try {
      const res = evalPhpJson<{
        css_url: string;
        font_url: string;
        doc_track_status: number;
        resolved_secret: string;
      }>(
        `
        define('LUMORA_BOOTSTRAPPED', true);
        require_once ${JSON.stringify(runtimeCore + "/bootstrap.php")};
        $starterRoot = ${JSON.stringify(toPosixPath(starterDir))};
        $tempRoot = ${JSON.stringify(toPosixPath(tempDir))};

        $cfg = \\Astrake\\Lumora\\Core\\Bootstrap::init($starterRoot);
        $cssUrl = \\Astrake\\Lumora\\Core\\Render::assetUrl('/public/assets/css/lumora.min.css', $tempRoot);
        $fontUrl = \\Astrake\\Lumora\\Core\\Render::assetUrl('/public/fonts/manrope.woff2', $tempRoot);
        $docRoute = \\Astrake\\Lumora\\Core\\Router::resolve('/docs/operator/getting-started/', $cfg, $starterRoot);
        $hubSecret = \\Astrake\\Lumora\\Core\\HubClient::resolveHubSecret(['hub' => ['shared_secret' => '']], $tempRoot);

        echo json_encode([
          'css_url' => $cssUrl,
          'font_url' => $fontUrl,
          'doc_track_status' => $docRoute['status'] ?? 404,
          'resolved_secret' => $hubSecret,
        ]);
        `,
        starterDir
      );

      expect(res.css_url).toMatch(/^\/public\/assets\/css\/lumora\.min\.css\?v=[a-f0-9]{8}$/);
      expect(res.font_url).toBe("/public/fonts/manrope.woff2");
      expect(res.doc_track_status).toBe(200);
      expect(res.resolved_secret).toBe("vault_file_hub_secret_key_32_bytes");
    } finally {
      rmSync(tempDir, { recursive: true, force: true });
    }
  });
});
