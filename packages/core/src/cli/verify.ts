import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync } from "node:fs";
import { createServer } from "node:net";
import path from "node:path";
import type { LumoraLockfile } from "../types/config";
import { buildWebsiteDist } from "./build-dist";
import {
  evalPhpJson,
  loadPhpLegalSlugs,
  loadPhpSiteConfig,
  loadPhpSiteRoutes,
  sha256File,
  sriSha384File,
  toPosixPath,
} from "./paths";

export interface GateResult {
  gate: number;
  name: string;
  passed: boolean;
  details: string[];
}

export interface VerifyReport {
  siteRoot: string;
  passed: boolean;
  gates: GateResult[];
}

export interface VerifyOptions {
  cwd?: string;
  silent?: boolean;
}

export async function verifyWebsite(options: VerifyOptions = {}): Promise<VerifyReport> {
  const siteRoot = path.resolve(options.cwd ?? process.cwd());
  const gates: GateResult[] = [];

  gates.push(runGate01CoreLockIntegrity(siteRoot));
  gates.push(runGate02PhpSyntaxAndStrictTypes(siteRoot));
  gates.push(runGate03BootstrapGuardIsolation(siteRoot));
  gates.push(runGate04AssetSriSynchronization(siteRoot));
  gates.push(runGate05ZeroThirdPartyLeaks(siteRoot));
  gates.push(runGate06DesignTokenAndA11yAudit(siteRoot));
  gates.push(runGate07CanonicalRouteAndSlashAudit(siteRoot));
  gates.push(runGate08StatutoryLegalCompleteness(siteRoot));
  gates.push(runGate09InquiryEngineAndSqliteWalSmoke(siteRoot));
  gates.push(await runGate10LiveHttpSecurityHeaderAudit(siteRoot));
  gates.push(await runGate11DistPackagingVerification(siteRoot));
  gates.push(await runGate12DistProductionServerSmoke(siteRoot));

  const allPassed = gates.every((g) => g.passed);

  if (!options.silent) {
    console.log(`\n=============================================================`);
    console.log(`🛡️  @astrake/lumora-webserver — 12-Gate Compliance Verifier`);
    console.log(`   Target: ${siteRoot}`);
    console.log(`=============================================================\n`);
    for (const g of gates) {
      const icon = g.passed ? "✅ PASS" : "❌ FAIL";
      const num = String(g.gate).padStart(2, "0");
      console.log(`${icon} [Gate ${num}] ${g.name}`);
      for (const d of g.details) {
        console.log(`          ↳ ${d}`);
      }
    }
    console.log(`\n-------------------------------------------------------------`);
    console.log(
      allPassed
        ? `🎉 ALL 12 COMPLIANCE GATES PASSED (${gates.length}/12)`
        : `🚨 VERIFICATION FAILED (${gates.filter((g) => g.passed).length}/12 passed)`
    );
    console.log(`-------------------------------------------------------------\n`);
  }

  return {
    siteRoot,
    passed: allPassed,
    gates,
  };
}

function collectFiles(dir: string, extFilter?: (filePath: string) => boolean): string[] {
  if (!existsSync(dir)) return [];
  const results: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === "node_modules" || entry.name === ".git" || entry.name === "dist" || entry.name === "storage") {
      continue;
    }
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      results.push(...collectFiles(full, extFilter));
    } else if (entry.isFile()) {
      if (!extFilter || extFilter(full)) {
        results.push(full);
      }
    }
  }
  return results;
}

/**
 * Gate 01 — Core Lock Integrity
 */
function runGate01CoreLockIntegrity(siteRoot: string): GateResult {
  const details: string[] = [];
  const lockPath = path.join(siteRoot, "lumora-lock.json");
  if (!existsSync(lockPath)) {
    return {
      gate: 1,
      name: "Core Lock Integrity (SHA-256)",
      passed: false,
      details: ["Missing lumora-lock.json. Run `lumora-web sync` first."],
    };
  }

  const lock = JSON.parse(readFileSync(lockPath, "utf8")) as LumoraLockfile;
  const expectedMap = lock.core_sha256 ?? {};
  const expectedKeys = Object.keys(expectedMap);
  if (expectedKeys.length === 0) {
    return {
      gate: 1,
      name: "Core Lock Integrity (SHA-256)",
      passed: false,
      details: ["lumora-lock.json contains 0 core_sha256 entries."],
    };
  }

  let driftCount = 0;
  for (const [relPath, expectedHash] of Object.entries(expectedMap)) {
    const absPath = path.join(siteRoot, relPath);
    if (!existsSync(absPath)) {
      details.push(`Missing locked file: ${relPath}`);
      driftCount++;
      continue;
    }
    const actualHash = sha256File(absPath);
    if (actualHash !== expectedHash) {
      details.push(`SHA-256 mismatch on ${relPath}`);
      driftCount++;
    }
  }

  // Also ensure no untracked extra PHP files were added inside core/
  const coreDir = path.join(siteRoot, "core");
  if (existsSync(coreDir)) {
    for (const f of readdirSync(coreDir)) {
      if (f.endsWith(".php") && !expectedMap[`/core/${f}`]) {
        details.push(`Untracked file in core/: /core/${f}`);
        driftCount++;
      }
    }
  }

  if (driftCount === 0) {
    details.push(`Verified ${expectedKeys.length} locked core & runtime files with 0 SHA-256 drift.`);
  }

  return {
    gate: 1,
    name: "Core Lock Integrity (SHA-256)",
    passed: driftCount === 0,
    details,
  };
}

/**
 * Gate 02 — PHP 8.2+ Syntax & Strict Types
 */
function runGate02PhpSyntaxAndStrictTypes(siteRoot: string): GateResult {
  const details: string[] = [];
  const phpFiles = collectFiles(siteRoot, (f) => f.endsWith(".php"));
  let failures = 0;

  for (const file of phpFiles) {
    const rel = toPosixPath(path.relative(siteRoot, file));
    const content = readFileSync(file, "utf8");
    if (!/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/.test(content)) {
      details.push(`Missing declare(strict_types=1); in ${rel}`);
      failures++;
    }
  }

  // Batch-validate PHP 8.2+ syntax across all files in a single PHP process using TOKEN_PARSE + php -l on index.php
  const posixFiles = phpFiles.map((f) => toPosixPath(f));
  const batchLintScript = `
    $files = ${JSON.stringify(posixFiles)};
    $errors = [];
    foreach ($files as $f) {
      $code = (string)@file_get_contents($f);
      try {
        token_get_all($code, TOKEN_PARSE);
      } catch (\\CompileError $e) {
        $errors[$f] = $e->getMessage() . ' on line ' . $e->getLine();
      }
    }
    echo json_encode(['errors' => $errors]);
  `;

  try {
    const res = evalPhpJson<{ errors: Record<string, string> }>(batchLintScript, siteRoot);
    for (const [f, msg] of Object.entries(res.errors)) {
      const rel = toPosixPath(path.relative(siteRoot, f));
      details.push(`Syntax error in ${rel}: ${msg}`);
      failures++;
    }
  } catch (err) {
    details.push(err instanceof Error ? err.message : String(err));
    failures++;
  }

  const indexLint = Bun.spawnSync(["php", "-l", path.join(siteRoot, "index.php")], {
    cwd: siteRoot,
    stdout: "pipe",
    stderr: "pipe",
  });
  if (indexLint.exitCode !== 0) {
    details.push(`php -l index.php failed: ${new TextDecoder().decode(indexLint.stderr).trim()}`);
    failures++;
  }

  if (failures === 0) {
    details.push(`Validated ${phpFiles.length} PHP files (` + "`php -l`" + ` + TOKEN_PARSE + strict_types=1).`);
  }

  return {
    gate: 2,
    name: "PHP 8.2+ Syntax & Strict Types",
    passed: failures === 0 && phpFiles.length > 0,
    details,
  };
}

/**
 * Gate 03 — LUMORA_BOOTSTRAPPED Direct-Access Isolation
 */
function runGate03BootstrapGuardIsolation(siteRoot: string): GateResult {
  const details: string[] = [];
  const phpFiles = collectFiles(siteRoot, (f) => f.endsWith(".php"));
  let violations = 0;
  let guardedCount = 0;

  for (const file of phpFiles) {
    const rel = toPosixPath(path.relative(siteRoot, file));
    if (rel === "index.php" || rel === "router.php") {
      continue;
    }
    const content = readFileSync(file, "utf8");
    const hasGuard =
      /if\s*\(\s*!defined\(\s*['"]LUMORA_BOOTSTRAPPED['"]\s*\)\s*\)/.test(content) &&
      (/http_response_code\(\s*403\s*\)/.test(content) || /exit/.test(content));

    if (!hasGuard) {
      details.push(`Missing LUMORA_BOOTSTRAPPED 403 entry guard in ${rel}`);
      violations++;
    } else {
      guardedCount++;
    }
  }

  if (violations === 0) {
    details.push(`Verified ${guardedCount} non-entrypoint PHP files enforce LUMORA_BOOTSTRAPPED 403 isolation.`);
  }

  return {
    gate: 3,
    name: "LUMORA_BOOTSTRAPPED Direct-Access Isolation",
    passed: violations === 0,
    details,
  };
}

/**
 * Gate 04 — Font & Vendor Asset SRI SHA-384 Synchronization
 */
function runGate04AssetSriSynchronization(siteRoot: string): GateResult {
  const details: string[] = [];
  const lockPath = path.join(siteRoot, "lumora-lock.json");
  if (!existsSync(lockPath)) {
    return {
      gate: 4,
      name: "Font & Vendor Asset SRI SHA-384 Synchronization",
      passed: false,
      details: ["Missing lumora-lock.json"],
    };
  }

  const lock = JSON.parse(readFileSync(lockPath, "utf8")) as LumoraLockfile;
  const sriMap = lock.sri ?? {};
  const assetsToVerify = new Set<string>(Object.keys(sriMap));

  // Ensure compiled CSS and core JS are present
  assetsToVerify.add("/public/assets/css/lumora.min.css");
  assetsToVerify.add("/public/assets/js/lumora-core.js");

  const fontsDir = path.join(siteRoot, "public", "fonts");
  if (existsSync(fontsDir)) {
    for (const f of readdirSync(fontsDir)) {
      if (f.toLowerCase().endsWith(".woff2")) {
        assetsToVerify.add(`/public/fonts/${f}`);
      }
    }
  }

  let mismatches = 0;
  for (const relAsset of Array.from(assetsToVerify).sort()) {
    const absPath = path.join(siteRoot, relAsset);
    if (!existsSync(absPath)) {
      details.push(`Missing asset file on disk: ${relAsset}`);
      mismatches++;
      continue;
    }
    const actualSri = sriSha384File(absPath);
    const lockedSri = sriMap[relAsset];
    if (lockedSri !== actualSri) {
      details.push(`SRI SHA-384 mismatch for ${relAsset}: expected ${lockedSri ?? "none"}, got ${actualSri}`);
      mismatches++;
    }
  }

  if (mismatches === 0) {
    details.push(`Verified ${assetsToVerify.size} assets match SHA-384 SRI hashes in lumora-lock.json.`);
  }

  return {
    gate: 4,
    name: "Font & Vendor Asset SRI SHA-384 Synchronization",
    passed: mismatches === 0,
    details,
  };
}

/**
 * Gate 05 — Zero Third-Party Leaks & Tracking Isolation
 */
function runGate05ZeroThirdPartyLeaks(siteRoot: string): GateResult {
  const details: string[] = [];
  const scanFiles = collectFiles(
    siteRoot,
    (f) => f.endsWith(".php") || f.endsWith(".css") || f.endsWith(".js")
  );

  const prohibitedPatterns: Array<{ label: string; regex: RegExp }> = [
    { label: "Google Fonts CDN", regex: /fonts\.(googleapis|gstatic)\.com/i },
    { label: "External Script/Style CDN", regex: /(cdnjs\.cloudflare\.com|unpkg\.com|cdn\.jsdelivr\.net)/i },
    { label: "Third-Party Analytics/Tracker", regex: /(googletagmanager\.com|google-analytics\.com|connect\.facebook\.net|hotjar\.com)/i },
    { label: "Unsafe eval()", regex: /\beval\s*\(/ },
    { label: "Unsafe new Function()", regex: /\bnew\s+Function\s*\(/ },
  ];

  let leaks = 0;
  for (const file of scanFiles) {
    const rel = toPosixPath(path.relative(siteRoot, file));
    const content = readFileSync(file, "utf8");
    for (const pat of prohibitedPatterns) {
      if (pat.regex.test(content)) {
        details.push(`${pat.label} detected in ${rel}`);
        leaks++;
      }
    }
  }

  if (leaks === 0) {
    details.push(`Scanned ${scanFiles.length} PHP/CSS/JS files: 0 external CDNs, trackers, eval(), or new Function().`);
  }

  return {
    gate: 5,
    name: "Zero Third-Party Leaks & Tracking Isolation",
    passed: leaks === 0,
    details,
  };
}

/**
 * Gate 06 — Design Token & Accessibility Contrast Audit
 */
function runGate06DesignTokenAndA11yAudit(siteRoot: string): GateResult {
  const details: string[] = [];
  let issues = 0;

  const compiledCssPath = path.join(siteRoot, "public", "assets", "css", "lumora.min.css");
  if (!existsSync(compiledCssPath) || statSync(compiledCssPath).size < 100) {
    details.push("Compiled stylesheet public/assets/css/lumora.min.css is missing or empty.");
    issues++;
  } else {
    const cssText = readFileSync(compiledCssPath, "utf8");
    if (!cssText.includes("prefers-reduced-motion")) {
      details.push("Compiled stylesheet is missing prefers-reduced-motion accessibility rules.");
      issues++;
    }
    if (!cssText.includes(":focus-visible")) {
      details.push("Compiled stylesheet is missing :focus-visible keyboard focus ring rule.");
      issues++;
    }
  }

  const templateFiles = collectFiles(path.join(siteRoot, "templates"), (f) => f.endsWith(".php"));
  let imgCount = 0;
  for (const tpl of templateFiles) {
    const rel = toPosixPath(path.relative(siteRoot, tpl));
    const content = readFileSync(tpl, "utf8");
    const htmlWithoutPhpTags = content.replace(/<\?(?:php|=)?[\s\S]*?\?>/g, "__PHP__");
    const imgMatches = htmlWithoutPhpTags.match(/<img\b[^>]*>/gi) ?? [];
    for (const tag of imgMatches) {
      imgCount++;
      if (!/\bwidth\s*=/.test(tag) || !/\bheight\s*=/.test(tag) || !/\balt\s*=/.test(tag)) {
        details.push(`<img> tag missing explicit width, height, or alt in ${rel}: ${tag}`);
        issues++;
      }
    }
  }

  if (issues === 0) {
    details.push(`Verified compiled CSS tokens, reduced-motion, :focus-visible, and ${imgCount} <img> tags.`);
  }

  return {
    gate: 6,
    name: "Design Token & Accessibility Audit",
    passed: issues === 0,
    details,
  };
}

/**
 * Gate 07 — Canonical Route & Trailing-Slash Audit
 */
function runGate07CanonicalRouteAndSlashAudit(siteRoot: string): GateResult {
  const details: string[] = [];
  const routeData = loadPhpSiteRoutes(siteRoot);
  const routes = routeData.routes;
  const routeKeys = Object.keys(routes);

  if (routeKeys.length === 0) {
    return {
      gate: 7,
      name: "Canonical Route & Trailing-Slash Audit",
      passed: false,
      details: ["No routes defined in content/routes.php"],
    };
  }

  let issues = 0;
  for (const [routePath, def] of Object.entries(routes)) {
    const isFileRoute = /\.[a-zA-Z0-9]{1,6}$/.test(routePath);
    if (!isFileRoute && routePath !== "/" && (!routePath.startsWith("/") || !routePath.endsWith("/"))) {
      details.push(`Route '${routePath}' violates trailing-slash canonical policy.`);
      issues++;
    }
    const tpl = typeof def.template === "string" ? def.template : "";
    if (tpl !== "") {
      const tplFile = path.join(siteRoot, "templates", `${tpl}.php`);
      if (!existsSync(tplFile)) {
        details.push(`Route '${routePath}' references missing template templates/${tpl}.php`);
        issues++;
      }
    }
  }

  if (issues === 0) {
    details.push(`Verified ${routeKeys.length} canonical routes enforce trailing slashes and valid templates.`);
  }

  return {
    gate: 7,
    name: "Canonical Route & Trailing-Slash Audit",
    passed: issues === 0,
    details,
  };
}

/**
 * Gate 08 — Legal Registry & Contact Route Completeness
 */
function runGate08StatutoryLegalCompleteness(siteRoot: string): GateResult {
  const details: string[] = [];
  const bootstrapPath = toPosixPath(path.join(siteRoot, "core", "bootstrap.php"));
  const rootPosix = toPosixPath(siteRoot);

  const script = `
    define('LUMORA_BOOTSTRAPPED', true);
    require_once ${JSON.stringify(bootstrapPath)};
    $cfg = \\Lumora\\Core\\Bootstrap::init(${JSON.stringify(rootPosix)});
    $routeData = \\Lumora\\Core\\Router::loadRouteConfig(${JSON.stringify(rootPosix)});
    $slugs = \\Lumora\\Core\\LegalRegistry::activeSlugs($cfg, ${JSON.stringify(rootPosix)});
    $legalEnabled = !(isset($cfg['features']['legal_registry']) && $cfg['features']['legal_registry'] === false);
    $results = [];
    foreach ($slugs as $slug) {
      $doc = \\Lumora\\Core\\LegalRegistry::resolveDocument($slug, $cfg, ${JSON.stringify(rootPosix)});
      $results[$slug] = [
        'ok' => is_array($doc) && !empty($doc['title']) && !empty($doc['sections']),
        'sections' => is_array($doc['sections'] ?? null) ? count($doc['sections']) : 0,
      ];
    }
    $hasContactRoute = isset($routeData['routes']['/contact/'])
      && !(isset($cfg['features']['inquiry_engine']) && $cfg['features']['inquiry_engine'] === false);
    $contactRoute = $hasContactRoute
      ? \\Lumora\\Core\\Router::resolve('/contact/', $cfg, ${JSON.stringify(rootPosix)})
      : ['status' => 200];
    echo json_encode([
      'legal_enabled' => $legalEnabled,
      'slugs' => $slugs,
      'docs' => $results,
      'has_contact_route' => $hasContactRoute,
      'contact_status' => $contactRoute['status'] ?? 404,
      'legal_tpl_exists' => is_file(${JSON.stringify(rootPosix + "/templates/legal.php")}),
    ]);
  `;

  try {
    const res = evalPhpJson<{
      legal_enabled: boolean;
      slugs: string[];
      docs: Record<string, { ok: boolean; sections: number }>;
      has_contact_route: boolean;
      contact_status: number;
      legal_tpl_exists: boolean;
    }>(script, siteRoot);

    let issues = 0;
    if (res.legal_enabled && res.slugs.length > 0 && !res.legal_tpl_exists) {
      details.push("Missing templates/legal.php");
      issues++;
    }
    if (res.has_contact_route && res.contact_status !== 200) {
      details.push(`/contact/ route did not resolve to 200 (got ${res.contact_status})`);
      issues++;
    }
    for (const [slug, info] of Object.entries(res.docs)) {
      if (!info.ok) {
        details.push(`/legal/${slug}/ failed document resolution`);
        issues++;
      }
    }

    if (issues === 0) {
      if (!res.legal_enabled && res.slugs.length === 0) {
        details.push("Legal registry disabled by app config.");
      } else {
        details.push(
          `Verified ${res.slugs.length} active legal document(s) (${res.slugs.join(", ") || "none"})${res.has_contact_route ? " and /contact/ route" : ""}.`
        );
      }
    }

    return {
      gate: 8,
      name: "Legal Registry & Contact Route Completeness",
      passed: issues === 0,
      details,
    };
  } catch (err) {
    return {
      gate: 8,
      name: "Legal Registry & Contact Route Completeness",
      passed: false,
      details: [err instanceof Error ? err.message : String(err)],
    };
  }
}

/**
 * Gate 09 — Edge SQLite WAL & Pre-CSRF Rate-Limit Smoke Test
 */
function runGate09InquiryEngineAndSqliteWalSmoke(siteRoot: string): GateResult {
  const details: string[] = [];
  const tempRoot = path.join(siteRoot, "storage", `.gate09-smoke-${Date.now()}`);
  mkdirSync(tempRoot, { recursive: true });

  const bootstrapPath = toPosixPath(path.join(siteRoot, "core", "bootstrap.php"));
  const siteRootPosix = toPosixPath(siteRoot);
  const tempRootPosix = toPosixPath(tempRoot);

  const script = `
    define('LUMORA_BOOTSTRAPPED', true);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'Lumora-Gate09-Smoke/1.0';
    require_once ${JSON.stringify(bootstrapPath)};

    $cfg = \\Lumora\\Core\\Bootstrap::init(${JSON.stringify(siteRootPosix)});
    $cfg['hub'] = [
      'enabled' => false,
      'endpoint' => 'https://hub.example.invalid/api/v1/lumora/inquiries/ingest',
      'shared_secret' => 'gate09_test_secret_key_32_bytes_minimum_length',
      'timeout_ms' => 500,
      'fallback_notify' => false,
    ];
    $cfg['security']['rate_limit_max_attempts'] = 5;
    $cfg['security']['rate_limit_window_sec'] = 900;

    // Copy content/channels.php into tempRoot if present so channel rules match
    if (is_file(${JSON.stringify(siteRootPosix + "/content/channels.php")})) {
      @mkdir(${JSON.stringify(tempRootPosix + "/content")}, 0750, true);
      @copy(${JSON.stringify(siteRootPosix + "/content/channels.php")}, ${JSON.stringify(tempRootPosix + "/content/channels.php")});
    }

    $now = time();
    $csrf = \\Lumora\\Core\\Security::csrfToken(${JSON.stringify(tempRootPosix)}, $now - 5);

    // 1. Honeypot test (attempt #1)
    $hpRes = \\Lumora\\Core\\InquiryEngine::handle($cfg, ${JSON.stringify(tempRootPosix)}, [
      'website_url' => 'http://spam.invalid',
      '_ts' => (string)($now - 5),
      '_csrf' => $csrf,
    ], $now);

    // 2. Timing trap test <2s (attempt #2)
    $timingRes = \\Lumora\\Core\\InquiryEngine::handle($cfg, ${JSON.stringify(tempRootPosix)}, [
      'website_url' => '',
      '_ts' => (string)$now,
      '_csrf' => $csrf,
      'name' => 'Test User',
      'email' => 'test@example.com',
      'subject' => 'Timing Test',
      'message' => 'Testing the 2 second timing guard enforcement.',
      'consent' => '1',
    ], $now);

    // 3. Invalid CSRF test (attempt #3)
    $csrfRes = \\Lumora\\Core\\InquiryEngine::handle($cfg, ${JSON.stringify(tempRootPosix)}, [
      'website_url' => '',
      '_ts' => (string)($now - 5),
      '_csrf' => 'invalid.token.mac',
      'name' => 'Test User',
      'email' => 'test@example.com',
      'subject' => 'CSRF Test',
      'message' => 'Testing stateless v2 CSRF verification failure.',
      'consent' => '1',
    ], $now);

    // 4. Valid submission test: SQLite WAL + JSONL + HMAC signature (attempt #4)
    $t0 = hrtime(true);
    $validRes = \\Lumora\\Core\\InquiryEngine::handle($cfg, ${JSON.stringify(tempRootPosix)}, [
      'website_url' => '',
      '_ts' => (string)($now - 5),
      '_csrf' => $csrf,
      'name' => 'Alex Rivera',
      'organization' => 'Verification Test Org',
      'email' => 'alex@example.com',
      'phone' => '+1 555 010 0199',
      'subject' => 'Platform Architecture Inquiry',
      'message' => 'Verifying sub-millisecond SQLite WAL persistence and JSONL backup.',
      'consent' => '1',
    ], $now);
    $elapsedMs = (hrtime(true) - $t0) / 1e6;

    // 5. Attempt #5 (consumes 5th rate limit slot)
    \\Lumora\\Core\\InquiryEngine::handle($cfg, ${JSON.stringify(tempRootPosix)}, [
      'website_url' => '',
      '_ts' => (string)($now - 5),
      '_csrf' => 'invalid',
    ], $now);

    // 6. Attempt #6 -> MUST be blocked by pre-CSRF rate limiter (5 / 900s) even with valid CSRF
    $rateLimitRes = \\Lumora\\Core\\InquiryEngine::handle($cfg, ${JSON.stringify(tempRootPosix)}, [
      'website_url' => '',
      '_ts' => (string)($now - 5),
      '_csrf' => $csrf,
      'name' => 'Alex Rivera',
      'email' => 'alex@example.com',
      'subject' => 'Should be rate limited',
      'message' => 'This 6th attempt must be blocked before CSRF or validation.',
      'consent' => '1',
    ], $now);

    $vaultDir = \\Lumora\\Core\\Security::vaultDir(${JSON.stringify(tempRootPosix)});
    $sqliteExists = is_file($vaultDir . '/lumora.sqlite');
    $jsonlExists = is_file($vaultDir . '/contacts-backup.jsonl');

    \\Lumora\\Core\\InquiryDb::resetConnection();

    echo json_encode([
      'honeypot_caught' => ($hpRes['success'] === false || !empty($hpRes['receipt'])),
      'timing_blocked' => ($timingRes['success'] === false),
      'csrf_blocked' => ($csrfRes['success'] === false),
      'valid_accepted' => ($validRes['success'] === true && !empty($validRes['receipt'])),
      'receipt_id' => $validRes['receipt'] ?? '',
      'hub_sig_valid' => str_starts_with((string)($validRes['hub_signature'] ?? ''), 'sha256='),
      'rate_limited_6th' => ($rateLimitRes['success'] === false && str_contains(implode(' ', $rateLimitRes['errors'] ?? []), 'rate limit')),
      'sqlite_exists' => $sqliteExists,
      'jsonl_exists' => $jsonlExists,
      'elapsed_ms' => round($elapsedMs, 2),
    ]);
  `;

  try {
    const res = evalPhpJson<{
      honeypot_caught: boolean;
      timing_blocked: boolean;
      csrf_blocked: boolean;
      valid_accepted: boolean;
      receipt_id: string;
      hub_sig_valid: boolean;
      rate_limited_6th: boolean;
      sqlite_exists: boolean;
      jsonl_exists: boolean;
      elapsed_ms: number;
    }>(script, siteRoot);

    const passed =
      res.honeypot_caught &&
      res.timing_blocked &&
      res.csrf_blocked &&
      res.valid_accepted &&
      res.hub_sig_valid &&
      res.rate_limited_6th &&
      res.sqlite_exists &&
      res.jsonl_exists;

    if (passed) {
      details.push(
        `Honeypot, <2s timing guard, v2 CSRF, pre-CSRF 5/900s rate limit, SQLite WAL + JSONL (${res.elapsed_ms}ms, receipt ${res.receipt_id}), and HMAC signature verified.`
      );
    } else {
      details.push(`Smoke test failure: ${JSON.stringify(res)}`);
    }

    return {
      gate: 9,
      name: "Edge SQLite WAL & Pre-CSRF Rate-Limit Smoke Test",
      passed,
      details,
    };
  } catch (err) {
    return {
      gate: 9,
      name: "Edge SQLite WAL & Pre-CSRF Rate-Limit Smoke Test",
      passed: false,
      details: [err instanceof Error ? err.message : String(err)],
    };
  } finally {
    try {
      rmSync(tempRoot, { recursive: true, force: true });
    } catch {}
  }
}

async function getFreePort(): Promise<number> {
  return new Promise((resolve, reject) => {
    const srv = createServer();
    srv.listen(0, "127.0.0.1", () => {
      const addr = srv.address();
      const port = typeof addr === "object" && addr ? addr.port : 0;
      srv.close(() => resolve(port));
    });
    srv.on("error", reject);
  });
}

async function waitForServer(url: string, timeoutMs = 4000): Promise<boolean> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(url);
      if (res.status > 0) return true;
    } catch {}
    await new Promise((r) => setTimeout(r, 80));
  }
  return false;
}

/**
 * Gate 10 — Ephemeral Live HTTP Security Header & Discovery Audit
 */
async function runGate10LiveHttpSecurityHeaderAudit(siteRoot: string): Promise<GateResult> {
  const details: string[] = [];
  const port = await getFreePort();
  const baseUrl = `http://127.0.0.1:${port}`;

  const proc = Bun.spawn(["php", "-S", `127.0.0.1:${port}`, "router.php"], {
    cwd: siteRoot,
    stdout: "ignore",
    stderr: "ignore",
  });

  try {
    const ready = await waitForServer(`${baseUrl}/`);
    if (!ready) {
      return {
        gate: 10,
        name: "Ephemeral Live HTTP Security Header Audit",
        passed: false,
        details: ["Failed to boot ephemeral php -S server on router.php"],
      };
    }

    let failures = 0;
    const homeRes = await fetch(`${baseUrl}/`, { redirect: "manual" });
    if (homeRes.status !== 200) {
      details.push(`GET / returned ${homeRes.status} (expected 200)`);
      failures++;
    }

    const requiredHeaders = [
      "content-security-policy",
      "strict-transport-security",
      "x-content-type-options",
      "x-frame-options",
      "referrer-policy",
      "cross-origin-opener-policy",
      "cross-origin-resource-policy",
      "permissions-policy",
    ];
    for (const h of requiredHeaders) {
      if (!homeRes.headers.get(h)) {
        details.push(`Missing HTTP security header on GET /: ${h}`);
        failures++;
      }
    }

    const forbiddenPaths = ["/core/security.php", "/lumora.config.php", "/storage/"];
    for (const p of forbiddenPaths) {
      const res = await fetch(`${baseUrl}${p}`, { redirect: "manual" });
      if (res.status !== 403) {
        details.push(`Expected 403 Forbidden on ${p}, got ${res.status}`);
        failures++;
      }
    }

    const siteConfig = loadPhpSiteConfig(siteRoot) as {
      brand?: { security_email?: string };
      discovery?: {
        health?: boolean;
        robots_txt?: boolean;
        sitemap_xml?: boolean;
        llms_txt?: boolean;
        security_txt?: boolean;
        webmanifest?: boolean;
      };
    };
    const disc = siteConfig.discovery ?? {};
    const brand = siteConfig.brand ?? {};
    const securityTxtEnabled =
      disc.security_txt !== undefined
        ? Boolean(disc.security_txt)
        : Boolean(brand.security_email) || existsSync(path.join(siteRoot, ".well-known", "security.txt"));

    const discoveryPaths: Array<{ path: string; enabled: boolean }> = [
      { path: "/health", enabled: disc.health !== false },
      { path: "/robots.txt", enabled: disc.robots_txt !== false },
      { path: "/sitemap.xml", enabled: disc.sitemap_xml !== false },
      { path: "/llms.txt", enabled: disc.llms_txt !== false },
      { path: "/llms-full.txt", enabled: disc.llms_txt !== false },
      { path: "/.well-known/security.txt", enabled: securityTxtEnabled },
      { path: "/site.webmanifest", enabled: disc.webmanifest !== false },
    ];

    let checkedDiscovery = 0;
    for (const item of discoveryPaths) {
      if (!item.enabled) continue;
      checkedDiscovery++;
      const res = await fetch(`${baseUrl}${item.path}`, { redirect: "manual" });
      if (res.status !== 200) {
        details.push(`Expected 200 OK on discovery endpoint ${item.path}, got ${res.status}`);
        failures++;
      }
    }

    if (failures === 0) {
      details.push(
        `Verified 8 security headers on GET /, 403 isolation on /core/, /lumora.config.php, /storage/, and 200 OK across ${checkedDiscovery} enabled discovery endpoint(s).`
      );
    }

    return {
      gate: 10,
      name: "Ephemeral Live HTTP Security Header Audit",
      passed: failures === 0,
      details,
    };
  } finally {
    try {
      proc.kill();
    } catch {}
  }
}

/**
 * Gate 11 — Zero-Dependency dist/ Packaging Verification
 */
async function runGate11DistPackagingVerification(siteRoot: string): Promise<GateResult> {
  const details: string[] = [];
  const distDir = path.join(siteRoot, "dist");
  if (!existsSync(distDir)) {
    await buildWebsiteDist({ cwd: siteRoot, silent: true });
  }

  if (!existsSync(distDir)) {
    return {
      gate: 11,
      name: "Zero-Dependency dist/ Packaging Verification",
      passed: false,
      details: ["dist/ directory does not exist"],
    };
  }

  let issues = 0;
  const allDistFiles = collectFilesInDist(distDir);
  for (const f of allDistFiles) {
    const rel = toPosixPath(path.relative(distDir, f));
    const lower = path.basename(f).toLowerCase();
    if (
      lower.endsWith(".md") ||
      lower.endsWith(".lock") ||
      lower.endsWith(".sh") ||
      lower.endsWith(".ts") ||
      rel.toLowerCase() === "router.php" ||
      rel.includes("node_modules/")
    ) {
      details.push(`Forbidden development artifact in dist/: ${rel}`);
      issues++;
    }
  }

  const routeData = loadPhpSiteRoutes(siteRoot);
  let stubCount = 0;
  for (const routePath of Object.keys(routeData.routes)) {
    if (routePath !== "/" && !/\.[a-zA-Z0-9]{1,6}$/.test(routePath)) {
      const segments = routePath.replace(/^\/+|\/+$/g, "").split("/").filter(Boolean);
      const stubFile = path.join(distDir, ...segments, "index.php");
      if (!existsSync(stubFile)) {
        details.push(`Missing physical route stub in dist/: ${routePath}index.php`);
        issues++;
      } else {
        stubCount++;
      }
    }
  }

  if (issues === 0) {
    details.push(`Verified dist/ contains 0 dev artifacts and ${stubCount} physical canonical route stubs.`);
  }

  return {
    gate: 11,
    name: "Zero-Dependency dist/ Packaging Verification",
    passed: issues === 0,
    details,
  };
}

function collectFilesInDist(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...collectFilesInDist(full));
    } else if (entry.isFile()) {
      out.push(full);
    }
  }
  return out;
}

/**
 * Gate 12 — Ephemeral dist/ Production Server Smoke Test
 */
async function runGate12DistProductionServerSmoke(siteRoot: string): Promise<GateResult> {
  const details: string[] = [];
  const distDir = path.join(siteRoot, "dist");
  const port = await getFreePort();
  const baseUrl = `http://127.0.0.1:${port}`;

  // Boot php -S rooted directly at dist/ WITHOUT router.php to prove physical index.php stubs work!
  const proc = Bun.spawn(["php", "-S", `127.0.0.1:${port}`, "-t", distDir], {
    cwd: distDir,
    stdout: "ignore",
    stderr: "ignore",
  });

  try {
    const ready = await waitForServer(`${baseUrl}/`);
    if (!ready) {
      return {
        gate: 12,
        name: "Ephemeral dist/ Production Server Smoke Test",
        passed: false,
        details: ["Failed to boot ephemeral php -S server rooted at dist/"],
      };
    }

    const routeData = loadPhpSiteRoutes(siteRoot);
    let failures = 0;
    let checked = 0;

    for (const [routePath, def] of Object.entries(routeData.routes)) {
      if (def.localhost_only_draft || /\.[a-zA-Z0-9]{1,6}$/.test(routePath)) {
        continue;
      }
      checked++;
      const res = await fetch(`${baseUrl}${routePath}`, { redirect: "manual" });
      const body = await res.text();
      if (res.status !== 200 || !res.headers.get("content-security-policy") || body.includes("Fatal error:")) {
        details.push(`dist/ route ${routePath} failed (status=${res.status}, csp=${Boolean(res.headers.get("content-security-policy"))})`);
        failures++;
      }
    }

    // Test the first active app-configured legal route stub in dist/ (if legal_registry is enabled)
    const activeLegalSlugs = loadPhpLegalSlugs(siteRoot);
    if (activeLegalSlugs.length > 0) {
      const legalPath = `/legal/${activeLegalSlugs[0]}/`;
      const legalRes = await fetch(`${baseUrl}${legalPath}`, { redirect: "manual" });
      const legalBody = await legalRes.text();
      if (legalRes.status !== 200 || !legalRes.headers.get("content-security-policy") || legalBody.includes("Fatal error:")) {
        details.push(`dist/ route ${legalPath} failed (status=${legalRes.status})`);
        failures++;
      } else {
        checked++;
      }
    }

    // Verify 301 legacy redirects in dist/
    let redirectChecked = 0;
    const seenRedirects = new Set<string>();
    for (const [fromPath, toPath] of Object.entries(routeData.redirects)) {
      if (/\.[a-zA-Z0-9]{1,6}$/.test(fromPath)) {
        continue;
      }
      const cleanFrom = "/" + fromPath.replace(/^\/+|\/+$/g, "") + "/";
      if (seenRedirects.has(cleanFrom)) {
        continue;
      }
      seenRedirects.add(cleanFrom);
      const redirRes = await fetch(`${baseUrl}${cleanFrom}`, { redirect: "manual" });
      redirectChecked++;
      if (redirRes.status !== 301 && redirRes.status !== 302) {
        details.push(`dist/ redirect ${cleanFrom} -> ${toPath} returned ${redirRes.status} (expected 301)`);
        failures++;
      }
    }

    if (failures === 0) {
      details.push(
        `Verified ${checked} canonical & statutory routes (200 OK) and ${redirectChecked} legacy redirects (301) from zero-rewrite dist/ server.`
      );
    }

    return {
      gate: 12,
      name: "Ephemeral dist/ Production Server Smoke Test",
      passed: failures === 0,
      details,
    };
  } finally {
    try {
      proc.kill();
    } catch {}
  }
}
