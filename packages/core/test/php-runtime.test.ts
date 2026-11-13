import { describe, expect, test } from "bun:test";
import { mkdirSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { evalPhpJson, resolveRuntimeDir, toPosixPath } from "../src/cli/paths";

const starterDir = path.resolve(import.meta.dir, "../../../apps/starter");

describe("Headless PHP 8.2+ Micro-Kernel Runtime", () => {
  test("Security::csrfToken & verifyCsrf are CGNAT-resilient and respect opt-in compat_constants", () => {
    const securityPhp = toPosixPath(path.join(resolveRuntimeDir(), "php", "core", "security.php"));
    const tempDir = path.join(starterDir, "storage", `.test-csrf-${Date.now()}`);
    mkdirSync(tempDir, { recursive: true });

    try {
      const res = evalPhpJson<{
        valid_same_ip: boolean;
        valid_rotated_ip: boolean;
        invalid_different_ua: boolean;
        invalid_expired: boolean;
        compat_defined: boolean;
        alias_loaded: boolean;
        legacy_global_absent: boolean;
      }>(
        `
        define('LUMORA_BOOTSTRAPPED', true);
        require_once ${JSON.stringify(securityPhp)};
        $root = ${JSON.stringify(toPosixPath(tempDir))};
        $_SERVER['REMOTE_ADDR'] = '103.21.244.10';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 15; In-CGNAT)';
        \\Lumora\\Core\\Security::boot([
          'brand' => ['id' => 'acme'],
          'security' => [
            'csrf_ttl_sec' => 7200,
            'compat_constants' => ['LEGACY_APP_BOOTSTRAPPED'],
          ],
        ], $root);

        $now = 1760000000;
        $token = \\Lumora\\Core\\Security::csrfToken($root, $now);

        $validSameIp = \\Lumora\\Core\\Security::verifyCsrf($root, $token, $now + 30);

        $_SERVER['REMOTE_ADDR'] = '49.36.128.99';
        $validRotatedIp = \\Lumora\\Core\\Security::verifyCsrf($root, $token, $now + 60);

        $_SERVER['HTTP_USER_AGENT'] = 'Curl/8.0 Attacker';
        $invalidUa = \\Lumora\\Core\\Security::verifyCsrf($root, $token, $now + 60);

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 15; In-CGNAT)';
        $invalidExpired = \\Lumora\\Core\\Security::verifyCsrf($root, $token, $now + 7205);

        echo json_encode([
          'valid_same_ip' => $validSameIp,
          'valid_rotated_ip' => $validRotatedIp,
          'invalid_different_ua' => $invalidUa,
          'invalid_expired' => $invalidExpired,
          'compat_defined' => defined('LEGACY_APP_BOOTSTRAPPED') && LEGACY_APP_BOOTSTRAPPED === true,
          'alias_loaded' => class_exists(\\Astrake\\Lumora\\Core\\Security::class, false),
          'legacy_global_absent' => !isset($GLOBALS['astrakeCspNonce']) && !isset($GLOBALS['orinaCspNonce']),
        ]);
        `,
        starterDir
      );

      expect(res.valid_same_ip).toBe(true);
      expect(res.valid_rotated_ip).toBe(true);
      expect(res.invalid_different_ua).toBe(false);
      expect(res.invalid_expired).toBe(false);
      expect(res.compat_defined).toBe(true);
      expect(res.alias_loaded).toBe(true);
      expect(res.legacy_global_absent).toBe(true);
    } finally {
      rmSync(tempDir, { recursive: true, force: true });
    }
  });

  test("LegalRegistry defaults to neutral GLOBAL privacy/terms and supports arbitrary custom overrides + territory opt-in", () => {
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
        ['id' => 'custom-clinical', 'title' => '6. Clinical Addendum', 'body' => '<p>On-premise data remains inside facility LAN.</p>'],
    ],
];
`,
      "utf8"
    );

    writeFileSync(
      path.join(tempDir, "content", "legal", "refund-policy.php"),
      `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }
return [
    'title' => 'Refund Policy',
    'lead' => 'Simple 14-day refund terms.',
    'sections' => [
        ['id' => 'refunds', 'title' => '1. Eligibility', 'body' => '<p>Full refund within 14 days.</p>'],
    ],
];
`,
      "utf8"
    );

    try {
      const res = evalPhpJson<{
        global_slugs: string[];
        global_privacy_has_dpdp: boolean;
        global_terms_has_local_jur: boolean;
        disabled_slugs: string[];
        privacy_title: string;
        has_dpdp: boolean;
        has_custom_section: boolean;
        terms_has_jurisdiction: boolean;
        custom_slug_title: string;
        custom_slug_no_statutory: boolean;
      }>(
        `
        define('LUMORA_BOOTSTRAPPED', true);
        require_once ${JSON.stringify(runtimeCore + "/render.php")};
        require_once ${JSON.stringify(runtimeCore + "/legal-registry.php")};

        $neutralRoot = ${JSON.stringify(toPosixPath(tempDir + "/neutral-empty"))};
        @mkdir($neutralRoot, 0750, true);
        $neutralCfg = [
          'brand' => ['name' => 'Indie Studio', 'domain' => 'studio.example.com'],
        ];
        $globalSlugs = \\Lumora\\Core\\LegalRegistry::activeSlugs($neutralCfg, $neutralRoot);
        $globalPrivacy = \\Lumora\\Core\\LegalRegistry::resolveDocument('privacy', $neutralCfg, $neutralRoot);
        $globalTerms = \\Lumora\\Core\\LegalRegistry::resolveDocument('terms', $neutralCfg, $neutralRoot);

        $disabledCfg = [
          'brand' => ['name' => 'Alex Blog', 'domain' => 'alex.example.com'],
          'features' => ['legal_registry' => false],
        ];
        $disabledSlugs = \\Lumora\\Core\\LegalRegistry::activeSlugs($disabledCfg, $neutralRoot);

        $optInCfg = [
          'brand' => [
            'name' => 'MedCore Health',
            'legal_entity' => 'MedCore Systems Pvt. Ltd.',
            'domain' => 'medcore.example.com',
            'address' => ['locality' => 'Hyderabad', 'country' => 'IN'],
          ],
          'legal' => [
            'territories' => ['IN', 'EU'],
            'governing_law' => 'Hyderabad, India',
          ],
        ];
        $root = ${JSON.stringify(toPosixPath(tempDir))};
        $privacy = \\Lumora\\Core\\LegalRegistry::resolveDocument('privacy', $optInCfg, $root);
        $terms = \\Lumora\\Core\\LegalRegistry::resolveDocument('terms', $optInCfg, $root);
        $refund = \\Lumora\\Core\\LegalRegistry::resolveDocument('refund-policy', $neutralCfg, $root);

        $secIds = array_column($privacy['sections'], 'id');
        echo json_encode([
          'global_slugs' => $globalSlugs,
          'global_privacy_has_dpdp' => str_contains(json_encode($globalPrivacy), 'DPDP'),
          'global_terms_has_local_jur' => str_contains(json_encode($globalTerms), 'the applicable local jurisdiction'),
          'disabled_slugs' => $disabledSlugs,
          'privacy_title' => $privacy['title'],
          'has_dpdp' => str_contains($privacy['lead'], 'DPDP'),
          'has_custom_section' => in_array('custom-clinical', $secIds, true),
          'terms_has_jurisdiction' => str_contains(json_encode($terms['sections']), 'Hyderabad, India'),
          'custom_slug_title' => $refund['title'] ?? '',
          'custom_slug_no_statutory' => !str_contains(json_encode($refund), 'DPDP') && !str_contains(json_encode($refund), 'Grievance'),
        ]);
        `,
        starterDir
      );

      expect(res.global_slugs).toEqual(["privacy", "terms"]);
      expect(res.global_privacy_has_dpdp).toBe(false);
      expect(res.global_terms_has_local_jur).toBe(true);
      expect(res.disabled_slugs).toEqual([]);
      expect(res.privacy_title).toBe("Custom Brand Privacy Policy");
      expect(res.has_dpdp).toBe(true);
      expect(res.has_custom_section).toBe(true);
      expect(res.terms_has_jurisdiction).toBe(true);
      expect(res.custom_slug_title).toBe("Refund Policy");
      expect(res.custom_slug_no_statutory).toBe(true);
    } finally {
      rmSync(tempDir, { recursive: true, force: true });
    }
  });

  test("SchemaGraph supports Person mode, city-only PostalAddress, opt-in SoftwareApplication & schema_nodes", () => {
    const runtimeCore = toPosixPath(path.join(resolveRuntimeDir(), "php", "core"));
    const res = evalPhpJson<{
      person_types: string[];
      person_has_legal_name: boolean;
      person_has_address: boolean;
      city_address: Record<string, string>;
      custom_node_found: boolean;
      software_emitted: boolean;
    }>(
      `
      define('LUMORA_BOOTSTRAPPED', true);
      require_once ${JSON.stringify(runtimeCore + "/schema-graph.php")};

      $personGraph = \\Lumora\\Core\\SchemaGraph::build([
        'brand' => [
          'name' => 'Alex Rivera',
          'origin' => 'https://alex.example.com',
          'schema_org_type' => 'Person',
          'address' => [],
        ],
      ]);
      $personTypes = array_column($personGraph['@graph'], '@type');
      $personPrimary = $personGraph['@graph'][0];

      $orgGraph = \\Lumora\\Core\\SchemaGraph::build([
        'brand' => [
          'name' => 'Pulse Audio',
          'legal_entity' => 'Pulse Hardware Inc.',
          'expose_legal_entity_in_schema' => true,
          'origin' => 'https://pulse.example.com',
          'primary_offering_schema' => true,
          'operating_system' => 'Embedded Linux',
          'address' => ['locality' => 'Berlin', 'country' => 'DE'],
        ],
      ], [
        'schema_nodes' => [
          ['@type' => 'Product', 'name' => 'Pulse DAC-1'],
        ],
      ]);
      $orgTypes = array_column($orgGraph['@graph'], '@type');
      $orgPrimary = $orgGraph['@graph'][0];

      echo json_encode([
        'person_types' => $personTypes,
        'person_has_legal_name' => array_key_exists('legalName', $personPrimary),
        'person_has_address' => array_key_exists('address', $personPrimary),
        'city_address' => $orgPrimary['address'] ?? [],
        'custom_node_found' => in_array('Product', $orgTypes, true),
        'software_emitted' => in_array('SoftwareApplication', $orgTypes, true),
      ]);
      `,
      starterDir
    );

    expect(res.person_types).toEqual(["Person", "WebSite"]);
    expect(res.person_has_legal_name).toBe(false);
    expect(res.person_has_address).toBe(false);
    expect(res.city_address).toEqual({
      "@type": "PostalAddress",
      addressLocality: "Berlin",
      addressCountry: "DE",
    });
    expect(res.custom_node_found).toBe(true);
    expect(res.software_emitted).toBe(true);
  });

  test("Discovery supports configurable endpoints, custom framework_label, content/llms.php override, and BlogEngine indexing", () => {
    const runtimeCore = toPosixPath(path.join(resolveRuntimeDir(), "php", "core"));
    const tempDir = path.join(starterDir, "storage", `.test-discovery-${Date.now()}`);
    mkdirSync(path.join(tempDir, "content", "blog"), { recursive: true });
    writeFileSync(
      path.join(tempDir, "content", "routes.php"),
      `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }
return ['routes' => ['/' => ['template' => 'home', 'meta' => ['title' => 'Home', 'description' => 'Main page']]], 'redirects' => []];
`,
      "utf8"
    );
    writeFileSync(
      path.join(tempDir, "content", "blog", "launch-post.php"),
      `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }
return ['slug' => 'launch-post', 'title' => 'Release Notes', 'date' => '2026-11-10', 'status' => 'published', 'summary' => 'Neutral release post.', 'body' => '<p>Hello</p>'];
`,
      "utf8"
    );

    try {
      const res = evalPhpJson<{
        llms_has_blog: boolean;
        llms_has_dpdp: boolean;
        sec_txt_disabled: boolean;
        custom_php_override_works: boolean;
      }>(
        `
        define('LUMORA_BOOTSTRAPPED', true);
        require_once ${JSON.stringify(runtimeCore + "/render.php")};
        require_once ${JSON.stringify(runtimeCore + "/router.php")};
        require_once ${JSON.stringify(runtimeCore + "/legal-registry.php")};
        require_once ${JSON.stringify(runtimeCore + "/blog-engine.php")};
        require_once ${JSON.stringify(runtimeCore + "/docs-engine.php")};
        require_once ${JSON.stringify(runtimeCore + "/discovery.php")};

        $root = ${JSON.stringify(toPosixPath(tempDir))};
        $cfg = [
          'brand' => [
            'name' => 'Pulse Audio',
            'origin' => 'https://pulse.example.com',
            'framework_label' => 'custom-engine',
          ],
          'discovery' => ['security_txt' => false],
          'features' => ['blog_engine' => true, 'legal_registry' => false],
        ];
        $llms = \\Lumora\\Core\\Discovery::renderLlmsTxt($cfg, $root, true);
        $secServed = \\Lumora\\Core\\Discovery::tryServe('/.well-known/security.txt', $cfg, $root);

        file_put_contents($root . '/content/llms.php', "<?php\\ndeclare(strict_types=1);\\nreturn ['summary' => '# Programmatic LLMS Summary'];\\n");
        ob_start();
        \\Lumora\\Core\\Discovery::tryServe('/llms.txt', $cfg, $root);
        $overrideOut = ob_get_clean();

        echo json_encode([
          'llms_has_blog' => str_contains($llms, 'https://pulse.example.com/blog/launch-post/'),
          'llms_has_dpdp' => str_contains($llms, 'DPDP'),
          'sec_txt_disabled' => ($secServed === false),
          'custom_php_override_works' => ($overrideOut === '# Programmatic LLMS Summary'),
        ]);
        `,
        starterDir
      );

      expect(res.llms_has_blog).toBe(true);
      expect(res.llms_has_dpdp).toBe(false);
      expect(res.sec_txt_disabled).toBe(true);
      expect(res.custom_php_override_works).toBe(true);
    } finally {
      rmSync(tempDir, { recursive: true, force: true });
    }
  });

  test("InquiryEngine preserves app channels without core alias injection and captures attribution", () => {
    const runtimeCore = toPosixPath(path.join(resolveRuntimeDir(), "php", "core"));
    const tempDir = path.join(starterDir, "storage", `.test-inquiry-attr-${Date.now()}`);
    mkdirSync(path.join(tempDir, "content"), { recursive: true });
    writeFileSync(
      path.join(tempDir, "content", "channels.php"),
      `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }
return [
    'default_channel' => 'sales',
    'channels' => [
        'sales' => ['code' => 'SLS-01', 'label' => 'Sales', 'heading' => 'Talk to Sales', 'sla' => '1 Day', 'button_text' => 'Send', 'fields' => []],
    ],
];
`,
      "utf8"
    );

    try {
      const res = evalPhpJson<{
        resolved_dpdp_topic: string;
        aliases_empty: boolean;
        attr_source: string;
        attr_cta: string;
        outbox_has_attr: boolean;
      }>(
        `
        define('LUMORA_BOOTSTRAPPED', true);
        require_once ${JSON.stringify(runtimeCore + "/security.php")};
        require_once ${JSON.stringify(runtimeCore + "/inquiry-db.php")};
        require_once ${JSON.stringify(runtimeCore + "/hub-client.php")};
        require_once ${JSON.stringify(runtimeCore + "/inquiry-engine.php")};

        $root = ${JSON.stringify(toPosixPath(tempDir))};
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Lumora-Test/1.0';
        $cfg = [
          'brand' => ['id' => 'acme', 'prefix' => 'ACM', 'name' => 'Acme', 'domain' => 'acme.example.com'],
          'hub' => ['enabled' => true, 'endpoint' => ''],
        ];
        \\Lumora\\Core\\Security::boot($cfg, $root);

        $chCfg = \\Lumora\\Core\\InquiryEngine::loadChannelConfig($root);
        $resolvedTopic = \\Lumora\\Core\\InquiryEngine::resolveChannel([], ['topic' => 'dpdp'], $root);

        $now = time();
        $csrf = \\Lumora\\Core\\Security::csrfToken($root, $now - 5);
        $handleRes = \\Lumora\\Core\\InquiryEngine::handle($cfg, $root, [
          '_csrf' => $csrf,
          '_ts' => (string)($now - 5),
          'name' => 'Alex Rivera',
          'email' => 'alex@example.com',
          'subject' => 'Quote Request',
          'message' => 'Testing first-party UTM and CTA attribution tracking.',
          'consent' => '1',
          '_attr_utm_source' => 'docs',
          '_attr_cta_id' => 'pricing-cta',
        ], $now);

        $pending = \\Lumora\\Core\\InquiryDb::getPendingOutbox(5, $root);
        $detailsDecoded = json_decode((string)($pending[0]['details_json'] ?? '{}'), true);
        \\Lumora\\Core\\InquiryDb::resetConnection();

        echo json_encode([
          'resolved_dpdp_topic' => $resolvedTopic,
          'aliases_empty' => empty($chCfg['aliases']) && empty($chCfg['referer_map']),
          'attr_source' => $handleRes['attribution']['utm_source'] ?? '',
          'attr_cta' => $handleRes['attribution']['cta_id'] ?? '',
          'outbox_has_attr' => ($detailsDecoded['_attribution']['utm_source'] ?? '') === 'docs',
        ]);
        `,
        starterDir
      );

      expect(res.resolved_dpdp_topic).toBe("sales");
      expect(res.aliases_empty).toBe(true);
      expect(res.attr_source).toBe("docs");
      expect(res.attr_cta).toBe("pricing-cta");
      expect(res.outbox_has_attr).toBe(true);
    } finally {
      rmSync(tempDir, { recursive: true, force: true });
    }
  });

  test("InquiryDb::prune enforces configured retention windows and supports retention_days_pii = 0 opt-out", () => {
    const runtimeCore = toPosixPath(path.join(resolveRuntimeDir(), "php", "core"));
    const tempDir = path.join(starterDir, "storage", `.test-prune-${Date.now()}`);
    mkdirSync(tempDir, { recursive: true });

    try {
      const res = evalPhpJson<{
        recent_name: string;
        redacted_name: string;
        redacted_email: string;
        purged_exists: boolean;
        opt_out_preserved_name: string;
      }>(
        `
        define('LUMORA_BOOTSTRAPPED', true);
        require_once ${JSON.stringify(runtimeCore + "/security.php")};
        require_once ${JSON.stringify(runtimeCore + "/inquiry-db.php")};

        $root = ${JSON.stringify(toPosixPath(tempDir))};
        \\Lumora\\Core\\Security::boot(['brand' => ['id' => 'test']], $root);
        $pdo = \\Lumora\\Core\\InquiryDb::connect($root);

        $now = time();
        $recentIso = gmdate('Y-m-d\\TH:i:s\\Z', $now - 10 * 86400);
        $old100dIso = gmdate('Y-m-d\\TH:i:s\\Z', $now - 100 * 86400);
        $old200dIso = gmdate('Y-m-d\\TH:i:s\\Z', $now - 200 * 86400);

        $seedCfg = ['legal' => ['retention_days_pii' => 0, 'retention_days_logs' => 0]];
        \\Lumora\\Core\\InquiryDb::saveSubmission([
          'receipt_id' => 'TST-20261101-0001',
          'channel' => 'general',
          'channel_label' => 'General',
          'full_name' => 'Recent Visitor',
          'email' => 'recent@example.com',
          'subject' => 'Recent',
          'message' => 'Recent message body',
          'ip_hash' => 'hash1',
          'created_at' => $recentIso,
        ], $seedCfg, $root);

        \\Lumora\\Core\\InquiryDb::saveSubmission([
          'receipt_id' => 'TST-20260801-0002',
          'channel' => 'general',
          'channel_label' => 'General',
          'full_name' => 'Hundred Days Visitor',
          'email' => 'old100@example.com',
          'subject' => 'Old 100d',
          'message' => 'Should be redacted after 90 days',
          'ip_hash' => 'hash2',
          'created_at' => $old100dIso,
        ], $seedCfg, $root);

        \\Lumora\\Core\\InquiryDb::saveSubmission([
          'receipt_id' => 'TST-20260401-0003',
          'channel' => 'general',
          'channel_label' => 'General',
          'full_name' => 'Two Hundred Days Visitor',
          'email' => 'old200@example.com',
          'subject' => 'Old 200d',
          'message' => 'Should be purged after 180 days',
          'ip_hash' => 'hash3',
          'created_at' => $old200dIso,
        ], $seedCfg, $root);

        // First test retention_days_pii = 0 preserves old records untouched
        \\Lumora\\Core\\InquiryDb::prune($pdo, ['legal' => ['retention_days_pii' => 0, 'retention_days_logs' => 180]]);
        $optOutPreserved = $pdo->query("SELECT full_name FROM contact_submissions WHERE receipt_id = 'TST-20260801-0002'")->fetchColumn();

        // Now enforce 90/180 retention
        \\Lumora\\Core\\InquiryDb::prune($pdo, ['legal' => ['retention_days_pii' => 90, 'retention_days_logs' => 180]]);

        $r1 = $pdo->query("SELECT full_name FROM contact_submissions WHERE receipt_id = 'TST-20261101-0001'")->fetchColumn();
        $r2 = $pdo->query("SELECT full_name, email FROM contact_submissions WHERE receipt_id = 'TST-20260801-0002'")->fetch();
        $r3 = $pdo->query("SELECT COUNT(*) FROM contact_submissions WHERE receipt_id = 'TST-20260401-0003'")->fetchColumn();

        \\Lumora\\Core\\InquiryDb::resetConnection();

        echo json_encode([
          'recent_name' => (string)$r1,
          'redacted_name' => (string)($r2['full_name'] ?? ''),
          'redacted_email' => (string)($r2['email'] ?? ''),
          'purged_exists' => ((int)$r3 > 0),
          'opt_out_preserved_name' => (string)$optOutPreserved,
        ]);
        `,
        starterDir
      );

      expect(res.opt_out_preserved_name).toBe("Hundred Days Visitor");
      expect(res.recent_name).toBe("Recent Visitor");
      expect(res.redacted_name).toBe("[REDACTED]");
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

        $cfg = \\Lumora\\Core\\Bootstrap::init($starterRoot);
        $cssUrl = \\Lumora\\Core\\Render::assetUrl('/public/assets/css/lumora.min.css', $tempRoot);
        $fontUrl = \\Lumora\\Core\\Render::assetUrl('/public/fonts/manrope.woff2', $tempRoot);
        $docRoute = \\Lumora\\Core\\Router::resolve('/docs/operator/getting-started/', $cfg, $starterRoot);
        $hubSecret = \\Lumora\\Core\\HubClient::resolveHubSecret(['hub' => ['shared_secret' => '']], $tempRoot);

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
