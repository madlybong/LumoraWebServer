import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { compileBrandCss } from "./css-compiler";
import { resolveFrameworkDoc, resolvePackageVersion } from "./paths";
import { syncWebsite } from "./sync";

export interface InitWebsiteOptions {
  cwd?: string;
  brandId?: string;
  brandName?: string;
  brandPrefix?: string;
  domain?: string;
  legalEntity?: string;
  withAgents?: boolean;
  withAdmin?: boolean;
  silent?: boolean;
}

export async function initWebsite(options: InitWebsiteOptions = {}): Promise<void> {
  const siteRoot = path.resolve(options.cwd ?? process.cwd());
  const brandId = options.brandId ?? "my-site";
  const brandName = options.brandName ?? "My Brand";
  const brandPrefix = options.brandPrefix ?? "SITE";
  const domain = options.domain ?? "example.com";
  const legalEntity = options.legalEntity?.trim() || "";
  const withAdmin = Boolean(options.withAdmin);
  const pkgVersion = resolvePackageVersion();

  mkdirSync(path.join(siteRoot, "css"), { recursive: true });
  mkdirSync(path.join(siteRoot, "content", "legal"), { recursive: true });
  mkdirSync(path.join(siteRoot, "templates", "layout"), { recursive: true });
  mkdirSync(path.join(siteRoot, "public", "brand"), { recursive: true });
  mkdirSync(path.join(siteRoot, "public", "fonts"), { recursive: true });

  const legalEntityLine = legalEntity !== "" ? `\n        'legal_entity'  => '${legalEntity}',` : "";
  const adminBlock = withAdmin
    ? `\n    'admin' => [\n        'path'     => '/admin/',\n        'branding' => [\n            'title'         => '${brandName} Console',\n            'logo'          => '/public/brand/icon.svg',\n            'default_theme' => 'system',\n        ],\n    ],`
    : "";

  const configPath = path.join(siteRoot, "lumora.config.php");
  if (!existsSync(configPath)) {
    writeFileSync(
      configPath,
      `<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'brand' => [
        'id'            => '${brandId}',
        'prefix'        => '${brandPrefix}',
        'name'          => '${brandName}',${legalEntityLine}
        'domain'        => '${domain}',
        'origin'        => 'https://${domain}',
        'version'       => '${pkgVersion}',
        'locale'        => 'en',
        'theme_color'   => '#09090b',
        'color_scheme'  => 'light dark',
        'category'      => 'BusinessApplication',
        'slogan'        => 'Web Platform Powered by Lumora',
        'contact_email' => 'contact@${domain}',
        'address'       => [],
    ],
    'security' => [
        'canonical_https_redirect' => true,
        'strip_www_redirect'       => true,
        'csp_style_unsafe_inline'  => true,
        'csp_media_self'           => false,
        'rate_limit_max_attempts'  => 5,
        'rate_limit_window_sec'    => 900,
        'csrf_ttl_sec'             => 7200,
    ],
    'assets' => [
        'compiled_css' => '/public/assets/css/lumora.min.css',
        'core_js'      => '/public/assets/js/lumora-core.js',
        'fonts'        => [],
        'vendor_js'    => [],
    ],
    'hub' => [
        'enabled'         => false,
        'endpoint'        => getenv('LUMORA_HUB_ENDPOINT') ?: '',
        'shared_secret'   => getenv('LUMORA_HUB_SECRET') ?: '',
        'timeout_ms'      => 1500,
        'fallback_notify' => true,
    ],
    'legal' => [
        'territories'         => ['GLOBAL'],
        'slugs'               => ['privacy', 'terms'],
        'retention_days_pii'  => 90,
        'retention_days_logs' => 180,
    ],
    'discovery' => [
        'security_txt' => false,
    ],
    'features' => [
        'docs_engine'    => false,
        'blog_engine'    => false,
        'release_server' => false,
        'legal_registry' => true,
        'inquiry_engine' => true,
        'admin_panel'    => ${withAdmin ? "true" : "false"},
    ],${adminBlock}
];
`,
      "utf8"
    );
  }

  const themeCssPath = path.join(siteRoot, "css", "brand-theme.css");
  if (!existsSync(themeCssPath)) {
    writeFileSync(
      themeCssPath,
      `@theme {
  --font-sans: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
}

:root {
  accent-color: #2563eb;
  --lumora-bg: light-dark(#ffffff, #09090b);
  --lumora-bg-subtle: light-dark(#fafafa, #0f1117);
  --lumora-surface: light-dark(#ffffff, #18181b);
  --lumora-surface-muted: light-dark(#f4f4f5, #27272a);
  --lumora-fg: light-dark(#09090b, #fafafa);
  --lumora-fg-muted: light-dark(#52525b, #a1a1aa);
  --lumora-border: light-dark(#e4e4e7, #27272a);
  --lumora-border-strong: light-dark(#d4d4d8, #3f3f46);
  --lumora-accent: #2563eb;
  --lumora-accent-hover: #1d4ed8;
  --lumora-accent-fg: light-dark(#1d4ed8, #60a5fa);
  --lumora-accent-soft: light-dark(#eff6ff, rgba(37, 99, 235, 0.16));
  --lumora-success-bg: light-dark(#ecfdf5, rgba(16, 185, 129, 0.14));
  --lumora-success-fg: light-dark(#047857, #6ee7b7);
  --lumora-success-border: light-dark(#a7f3d0, rgba(16, 185, 129, 0.35));
  --lumora-warning-bg: light-dark(#fffbeb, rgba(245, 158, 11, 0.14));
  --lumora-warning-fg: light-dark(#b45309, #fcd34d);
  --lumora-warning-border: light-dark(#fde68a, rgba(245, 158, 11, 0.35));
  --lumora-danger-bg: light-dark(#fef2f2, rgba(239, 68, 68, 0.14));
  --lumora-danger-fg: light-dark(#b91c1c, #fca5a5);
  --lumora-danger-border: light-dark(#fecaca, rgba(239, 68, 68, 0.35));
  --lumora-radius-sm: 0.375rem;
  --lumora-radius-md: 0.625rem;
  --lumora-radius-lg: 0.875rem;
  --lumora-shadow-sm: light-dark(0 1px 2px 0 rgba(9, 9, 11, 0.05), 0 1px 2px 0 rgba(0, 0, 0, 0.35));
  --lumora-shadow-md: light-dark(0 8px 24px -6px rgba(9, 9, 11, 0.1), 0 10px 28px -6px rgba(0, 0, 0, 0.55));
}
`,
      "utf8"
    );
  }

  const brandCssPath = path.join(siteRoot, "css", "brand.css");
  if (!existsSync(brandCssPath)) {
    writeFileSync(
      brandCssPath,
      `@import "tailwindcss";

@layer base {
  html {
    background-color: var(--lumora-bg);
    color: var(--lumora-fg);
    font-family: var(--font-sans);
    -webkit-font-smoothing: antialiased;
  }

  body {
    margin: 0;
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    background-color: var(--lumora-bg-subtle);
    color: var(--lumora-fg);
    line-height: 1.65;
  }

  :focus-visible {
    outline: 2px solid var(--lumora-accent);
    outline-offset: 2px;
  }
}

@layer components {
  .lumora-container {
    width: 100%;
    max-width: 72rem;
    margin-inline: auto;
    padding-inline: 1.25rem;
  }

  @media (min-width: 640px) {
    .lumora-container {
      padding-inline: 2rem;
    }
  }

  .lumora-skip-link {
    position: absolute;
    left: -9999px;
    width: 1px;
    height: 1px;
    overflow: hidden;
  }

  .lumora-skip-link:focus-visible {
    position: fixed;
    left: 1rem;
    top: 1rem;
    width: auto;
    height: auto;
    z-index: 80;
    padding: 0.625rem 1rem;
    border-radius: var(--lumora-radius-md);
    background-color: var(--lumora-accent);
    color: #ffffff;
    font-size: 0.875rem;
    font-weight: 600;
  }

  .lumora-header {
    position: sticky;
    top: 0;
    z-index: 40;
    border-bottom: 1px solid var(--lumora-border);
    background-color: color-mix(in srgb, var(--lumora-bg) 88%, transparent);
    backdrop-filter: blur(12px);
  }

  .lumora-nav-link {
    display: inline-flex;
    align-items: center;
    padding: 0.4375rem 0.75rem;
    border-radius: var(--lumora-radius-sm);
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--lumora-fg-muted);
    text-decoration: none;
  }

  .lumora-nav-link:hover,
  .lumora-nav-link[aria-current="page"] {
    background-color: var(--lumora-surface-muted);
    color: var(--lumora-fg);
  }

  .lumora-card {
    background-color: var(--lumora-surface);
    border: 1px solid var(--lumora-border);
    border-radius: var(--lumora-radius-lg);
    box-shadow: var(--lumora-shadow-sm);
  }

  .lumora-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.625rem 1.125rem;
    min-height: 2.5rem;
    border-radius: var(--lumora-radius-md);
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    border: 1px solid transparent;
  }

  .lumora-btn-primary {
    background-color: var(--lumora-accent);
    color: #ffffff;
  }

  .lumora-btn-secondary {
    background-color: var(--lumora-surface);
    color: var(--lumora-fg);
    border-color: var(--lumora-border-strong);
  }

  .lumora-btn-ghost {
    background-color: transparent;
    color: var(--lumora-fg-muted);
    padding-inline: 0.625rem;
  }

  .lumora-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.25rem 0.625rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    border: 1px solid var(--lumora-border);
    background-color: var(--lumora-surface-muted);
    color: var(--lumora-fg-muted);
    text-decoration: none;
  }

  .lumora-badge-accent {
    background-color: var(--lumora-accent-soft);
    color: var(--lumora-accent-fg);
  }

  .lumora-input,
  .lumora-textarea {
    width: 100%;
    border-radius: var(--lumora-radius-md);
    border: 1px solid var(--lumora-border-strong);
    background-color: var(--lumora-surface);
    color: var(--lumora-fg);
    padding: 0.625rem 0.875rem;
    font-size: 0.9375rem;
  }

  .lumora-input[aria-invalid="true"],
  .lumora-textarea[aria-invalid="true"],
  .lumora-input:user-invalid,
  .lumora-textarea:user-invalid {
    border-color: var(--lumora-danger-fg);
  }

  .lumora-field-error {
    margin-top: 0.375rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--lumora-danger-fg);
  }

  .lumora-field-error:empty {
    display: none;
  }

  .lumora-alert-success {
    border-radius: var(--lumora-radius-md);
    border: 1px solid var(--lumora-success-border);
    background-color: var(--lumora-success-bg);
    color: var(--lumora-success-fg);
    padding: 0.875rem 1rem;
  }

  .lumora-alert-danger {
    border-radius: var(--lumora-radius-md);
    border: 1px solid var(--lumora-danger-border);
    background-color: var(--lumora-danger-bg);
    color: var(--lumora-danger-fg);
    padding: 0.875rem 1rem;
  }

  dialog[data-lumora-nav-drawer] {
    margin: 0 0 0 auto;
    height: 100dvh;
    max-height: 100dvh;
    width: min(22rem, 88vw);
    border: 0;
    border-left: 1px solid var(--lumora-border);
    background-color: var(--lumora-surface);
    color: var(--lumora-fg);
    padding: 1.25rem;
  }

  dialog[data-lumora-nav-drawer]::backdrop {
    background-color: rgba(9, 9, 11, 0.58);
    backdrop-filter: blur(4px);
  }

  [data-lumora-theme-toggle] [data-theme-icon="sun"] {
    display: none;
  }

  [data-lumora-theme-toggle] [data-theme-icon="moon"] {
    display: inline-flex;
  }

  :root:has(> head > meta[name="color-scheme"][content="dark"]) [data-lumora-theme-toggle] [data-theme-icon="sun"] {
    display: inline-flex;
  }

  :root:has(> head > meta[name="color-scheme"][content="dark"]) [data-lumora-theme-toggle] [data-theme-icon="moon"] {
    display: none;
  }

  @media (prefers-color-scheme: dark) {
    :root:not(:has(> head > meta[name="color-scheme"][content="light"])) [data-lumora-theme-toggle] [data-theme-icon="sun"] {
      display: inline-flex;
    }

    :root:not(:has(> head > meta[name="color-scheme"][content="light"])) [data-lumora-theme-toggle] [data-theme-icon="moon"] {
      display: none;
    }
  }
}
`,
      "utf8"
    );
  }

  const routesPath = path.join(siteRoot, "content", "routes.php");
  if (!existsSync(routesPath)) {
    writeFileSync(
      routesPath,
      `<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'routes' => [
        '/' => [
            'template' => 'home',
            'meta'     => [
                'title'       => '${brandName} — Overview',
                'description' => '${brandName} overview and capabilities.',
                'path'        => '/',
            ],
        ],
        '/contact/' => [
            'template' => 'contact',
            'meta'     => [
                'title'       => 'Contact — ${brandName}',
                'description' => 'Get in touch with ${brandName}.',
                'path'        => '/contact/',
                'schema_type' => 'contact',
            ],
        ],
        '/legal/' => [
            'template' => 'legal-index',
            'meta'     => [
                'title'       => 'Legal & Governance — ${brandName}',
                'description' => 'Privacy Policy, Terms of Use, and governance notices for ${brandName}.',
                'path'        => '/legal/',
                'schema_type' => 'legal',
            ],
        ],
    ],
    'redirects' => [
        '/privacy/' => '/legal/privacy/',
        '/terms/'   => '/legal/terms/',
    ],
];
`,
      "utf8"
    );
  }

  const channelsPath = path.join(siteRoot, "content", "channels.php");
  if (!existsSync(channelsPath)) {
    writeFileSync(
      channelsPath,
      `<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'default_channel' => 'general',
    'honeypot_mode'   => 'reject',
    'channels'        => [
        'general'  => [
            'code'        => 'CHN-01',
            'label'       => 'General Inquiry',
            'heading'     => 'Send a Message',
            'sla'         => '1-2 Business Days',
            'button_text' => 'Submit Inquiry',
            'fields'      => [],
        ],
    ],
];
`,
      "utf8"
    );
  }

  const privacyLegalPath = path.join(siteRoot, "content", "legal", "privacy.php");
  if (!existsSync(privacyLegalPath)) {
    writeFileSync(
      privacyLegalPath,
      `<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'title'    => 'Privacy Policy',
    'lead'     => 'How information submitted on this website is handled and protected.',
    'sections' => [
        [
            'id'    => 'overview',
            'title' => '1. Data Collection',
            'body'  => '<p>This website operates without advertising trackers or third-party analytics cookies. Contact details are collected only when voluntarily submitted via forms.</p>',
        ],
        [
            'id'    => 'retention',
            'title' => '2. Retention & Requests',
            'body'  => '<p>To update or delete submitted contact information, reach out via the contact details listed on this website.</p>',
        ],
    ],
];
`,
      "utf8"
    );
  }

  const termsLegalPath = path.join(siteRoot, "content", "legal", "terms.php");
  if (!existsSync(termsLegalPath)) {
    writeFileSync(
      termsLegalPath,
      `<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'title'    => 'Terms of Use',
    'lead'     => 'Terms governing access to this website and its content.',
    'sections' => [
        [
            'id'    => 'usage',
            'title' => '1. Acceptable Use',
            'body'  => '<p>By accessing this website, you agree to use its pages and contact forms responsibly and in compliance with applicable laws.</p>',
        ],
    ],
];
`,
      "utf8"
    );
  }

  const iconPath = path.join(siteRoot, "public", "brand", "icon.svg");
  if (!existsSync(iconPath)) {
    writeFileSync(
      iconPath,
      `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64"><rect width="64" height="64" rx="12" fill="#0a0a0a"/><path d="M20 16h8v24h16v8H20V16z" fill="#ffffff"/></svg>\n`,
      "utf8"
    );
  }

  const templates: Record<string, string> = {
    "templates/layout/head.php": `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<!DOCTYPE html>
<html lang="<?= e($config['brand']['locale'] ?? 'en') ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <?= lumora_head_meta($meta ?? [], $config ?? []) ?>
    <script nonce="<?= e(lumora_csp_nonce()) ?>">try{const s=localStorage.getItem('color-scheme');if(s==='light'||s==='dark'){const m=document.querySelector('meta[name="color-scheme"]');if(m)m.content=s;document.documentElement.style.colorScheme=s;}}catch(_){}</script>
</head>
<body>
<a href="#main-content" class="lumora-skip-link">Skip to main content</a>
`,
    "templates/layout/header.php": `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\\Core\\AdminDb;
use Lumora\\Core\\LegalRegistry;
use Lumora\\Core\\Security;

$brandName = (string)($config['brand']['name'] ?? 'Lumora');
$rawPath = (string)($meta['path'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));
$cleanCurrent = '/' . trim((string)parse_url($rawPath, PHP_URL_PATH), '/') . '/';
if ($cleanCurrent === '//') {
    $cleanCurrent = '/';
}
$showAdmin = Security::isLocalhost() && !empty($config['features']['admin_panel']);
$adminHref = $showAdmin ? u(AdminDb::normalizeAdminPath($config ?? [])) : '';
$legalSlugs = LegalRegistry::activeSlugs($config ?? [], $GLOBALS['lumoraRootDir'] ?? null);
?>
<header class="lumora-header">
    <div class="lumora-container flex items-center justify-between gap-4 py-3.5">
        <a href="<?= e(u('/')) ?>" class="inline-flex items-center gap-2.5 font-bold text-base no-underline">
            <img src="<?= e(asset_url('/public/brand/icon.svg')) ?>" alt="<?= e($brandName) ?>" width="32" height="32" class="h-8 w-8 rounded-lg shrink-0" />
            <span><?= e($brandName) ?></span>
        </a>
        <nav class="hidden md:flex items-center gap-1" aria-label="Primary Navigation">
            <a href="<?= e(u('/')) ?>" class="lumora-nav-link" <?= $cleanCurrent === '/' ? 'aria-current="page"' : '' ?>>Overview</a>
            <a href="<?= e(u('/contact/')) ?>" class="lumora-nav-link" <?= str_starts_with($cleanCurrent, '/contact/') ? 'aria-current="page"' : '' ?>>Contact</a>
            <?php if (!empty($legalSlugs)): ?>
                <a href="<?= e(u('/legal/')) ?>" class="lumora-nav-link" <?= str_starts_with($cleanCurrent, '/legal/') ? 'aria-current="page"' : '' ?>>Legal</a>
            <?php endif; ?>
        </nav>
        <div class="flex items-center gap-2 shrink-0">
            <?php if ($showAdmin): ?>
                <a href="<?= e($adminHref) ?>" class="lumora-badge lumora-badge-accent hidden sm:inline-flex">Admin Console</a>
            <?php endif; ?>
            <button type="button" class="lumora-btn lumora-btn-ghost" data-lumora-theme-toggle aria-label="Toggle color theme" title="Toggle light/dark theme">
                <span data-theme-icon="sun" aria-hidden="true">Light</span>
                <span data-theme-icon="moon" aria-hidden="true">Dark</span>
            </button>
            <a href="<?= e(u('/contact/')) ?>" class="lumora-btn lumora-btn-primary hidden sm:inline-flex" data-lumora-cta="header-contact">Contact</a>
            <button type="button" class="lumora-btn lumora-btn-secondary md:hidden" data-lumora-nav-toggle aria-expanded="false" aria-controls="lumora-mobile-drawer" aria-label="Open navigation menu">Menu</button>
        </div>
    </div>
</header>
<dialog id="lumora-mobile-drawer" data-lumora-nav-drawer aria-label="Mobile Navigation">
    <div data-lumora-nav-sheet class="flex flex-col gap-4">
        <div class="flex items-center justify-between pb-3 border-b border-[var(--lumora-border)]">
            <span class="font-bold text-sm"><?= e($brandName) ?></span>
            <button type="button" class="lumora-btn lumora-btn-ghost" data-lumora-nav-close aria-label="Close navigation menu">Close</button>
        </div>
        <nav class="flex flex-col gap-1" aria-label="Mobile Primary Navigation">
            <a href="<?= e(u('/')) ?>" class="lumora-nav-link" <?= $cleanCurrent === '/' ? 'aria-current="page"' : '' ?>>Overview</a>
            <a href="<?= e(u('/contact/')) ?>" class="lumora-nav-link" <?= str_starts_with($cleanCurrent, '/contact/') ? 'aria-current="page"' : '' ?>>Contact</a>
            <?php if (!empty($legalSlugs)): ?>
                <a href="<?= e(u('/legal/')) ?>" class="lumora-nav-link" <?= str_starts_with($cleanCurrent, '/legal/') ? 'aria-current="page"' : '' ?>>Legal</a>
            <?php endif; ?>
            <?php if ($showAdmin): ?>
                <a href="<?= e($adminHref) ?>" class="lumora-nav-link">Admin Console</a>
            <?php endif; ?>
        </nav>
    </div>
</dialog>
`,
    "templates/layout/footer.php": `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\\Core\\AdminDb;
use Lumora\\Core\\LegalRegistry;
use Lumora\\Core\\Security;

$legalSlugs = LegalRegistry::activeSlugs($config ?? [], $GLOBALS['lumoraRootDir'] ?? null);
$showAdmin = Security::isLocalhost() && !empty($config['features']['admin_panel']);
?>
<footer class="mt-auto border-t border-[var(--lumora-border)] bg-[var(--lumora-surface)]">
    <div class="lumora-container py-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 text-sm text-[var(--lumora-fg-muted)]">
        <p>&copy; <?= gmdate('Y') ?> <?= e($config['brand']['legal_entity'] ?? ($config['brand']['name'] ?? 'Lumora')) ?>.</p>
        <div class="flex flex-wrap items-center gap-4">
            <?php foreach ($legalSlugs as $legalSlug): ?>
                <a href="<?= e(u('/legal/' . $legalSlug . '/')) ?>" class="hover:underline"><?= e(ucwords(str_replace('-', ' ', $legalSlug))) ?></a>
            <?php endforeach; ?>
            <?php if ($showAdmin): ?>
                <a href="<?= e(u(AdminDb::normalizeAdminPath($config ?? []))) ?>" class="lumora-badge lumora-badge-accent">Admin Console</a>
            <?php endif; ?>
        </div>
    </div>
</footer>
<?= lumora_core_script() ?>
</body>
</html>
`,
    "templates/home.php": `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}
component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-14 flex-1 space-y-8">
    <section class="lumora-card p-8 sm:p-12 space-y-5">
        <span class="lumora-badge lumora-badge-accent font-mono">v<?= e($config['brand']['version'] ?? '${pkgVersion}') ?></span>
        <h1 class="text-3xl sm:text-5xl font-bold tracking-tight"><?= e($config['brand']['name'] ?? 'Lumora') ?></h1>
        <p class="text-base sm:text-lg text-[var(--lumora-fg-muted)] max-w-2xl"><?= e($config['brand']['slogan'] ?? '') ?></p>
        <div class="flex flex-wrap gap-3 pt-2">
            <a href="<?= e(u('/contact/')) ?>" class="lumora-btn lumora-btn-primary" data-lumora-cta="hero-contact">Contact Us</a>
            <a href="<?= e(u('/legal/')) ?>" class="lumora-btn lumora-btn-secondary">Legal &amp; Policies</a>
        </div>
    </section>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
`,
    "templates/contact.php": `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}
$inquiry = lumora_handle_inquiry();
$old = is_array($inquiry['data'] ?? null) ? $inquiry['data'] : [];
$fieldErrors = is_array($inquiry['field_errors'] ?? null) ? $inquiry['field_errors'] : [];
component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-12 flex-1 max-w-2xl space-y-6">
    <h1 class="text-2xl sm:text-3xl font-bold tracking-tight"><?= e($inquiry['channel_meta']['heading'] ?? 'Contact Us') ?></h1>
    <?php if (!empty($inquiry['success'])): ?>
        <div class="lumora-alert-success" role="status" aria-live="polite">
            Receipt: <strong><?= e($inquiry['receipt'] ?? '') ?></strong>
        </div>
    <?php else: ?>
        <?php if (!empty($inquiry['errors'])): ?>
            <div class="lumora-alert-danger" role="alert">
                <ul class="list-disc pl-5 text-sm space-y-1">
                    <?php foreach ($inquiry['errors'] as $err): ?>
                        <li><?= e((string)$err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="POST" action="<?= e(u('/contact/')) ?>" class="lumora-card p-6 space-y-4" data-lumora-contact-form novalidate>
            <input type="hidden" name="_csrf" value="<?= e(lumora_csrf_token()) ?>" />
            <input type="hidden" name="_ts" value="<?= (string)time() ?>" />
            <input type="hidden" name="channel" value="<?= e($inquiry['channel'] ?? 'general') ?>" />
            <div class="lumora-honeypot" aria-hidden="true">
                <label>Website <input type="text" name="website_url" tabindex="-1" autocomplete="off" /></label>
            </div>
            <div>
                <label for="name" class="block text-sm font-medium mb-1">Full Name *</label>
                <input id="name" type="text" name="name" value="<?= e((string)($old['name'] ?? '')) ?>" required class="lumora-input" data-error="err-name" aria-invalid="<?= isset($fieldErrors['name']) ? 'true' : 'false' ?>" />
                <p id="err-name" class="lumora-field-error"><?= e((string)($fieldErrors['name'] ?? '')) ?></p>
            </div>
            <div>
                <label for="email" class="block text-sm font-medium mb-1">Email *</label>
                <input id="email" type="email" name="email" value="<?= e((string)($old['email'] ?? '')) ?>" required class="lumora-input" data-error="err-email" aria-invalid="<?= isset($fieldErrors['email']) ? 'true' : 'false' ?>" />
                <p id="err-email" class="lumora-field-error"><?= e((string)($fieldErrors['email'] ?? '')) ?></p>
            </div>
            <div>
                <label for="subject" class="block text-sm font-medium mb-1">Subject *</label>
                <input id="subject" type="text" name="subject" value="<?= e((string)($old['subject'] ?? '')) ?>" required class="lumora-input" data-error="err-subject" aria-invalid="<?= isset($fieldErrors['subject']) ? 'true' : 'false' ?>" />
                <p id="err-subject" class="lumora-field-error"><?= e((string)($fieldErrors['subject'] ?? '')) ?></p>
            </div>
            <div>
                <label for="message" class="block text-sm font-medium mb-1">Message *</label>
                <textarea id="message" name="message" rows="4" minlength="15" maxlength="4000" required class="lumora-textarea" data-error="err-message" aria-invalid="<?= isset($fieldErrors['message']) ? 'true' : 'false' ?>"><?= e((string)($old['message'] ?? '')) ?></textarea>
                <p id="err-message" class="lumora-field-error"><?= e((string)($fieldErrors['message'] ?? '')) ?></p>
            </div>
            <div>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input id="consent" type="checkbox" name="consent" value="1" required data-error="err-consent" aria-invalid="<?= isset($fieldErrors['consent']) ? 'true' : 'false' ?>" />
                    <span>I acknowledge the Privacy Notice. *</span>
                </label>
                <p id="err-consent" class="lumora-field-error"><?= e((string)($fieldErrors['consent'] ?? '')) ?></p>
            </div>
            <button type="submit" class="lumora-btn lumora-btn-primary"><?= e($inquiry['channel_meta']['button_text'] ?? 'Send') ?></button>
        </form>
    <?php endif; ?>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
`,
    "templates/legal-index.php": `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}
use Lumora\\Core\\LegalRegistry;
$slugs = LegalRegistry::activeSlugs($config ?? [], $GLOBALS['lumoraRootDir'] ?? null);
component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-12 flex-1 space-y-6">
    <h1 class="text-2xl sm:text-3xl font-bold tracking-tight">Legal &amp; Governance</h1>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <?php foreach ($slugs as $s): ?>
            <?php $doc = LegalRegistry::resolveDocument($s, $config ?? [], $GLOBALS['lumoraRootDir'] ?? null); ?>
            <?php if ($doc !== null): ?>
                <article class="lumora-card p-6 space-y-2">
                    <h2 class="text-lg font-bold"><a href="<?= e(u('/legal/' . $s . '/')) ?>" class="hover:underline"><?= e((string)($doc['title'] ?? ucfirst($s))) ?></a></h2>
                    <p class="text-sm text-[var(--lumora-fg-muted)]"><?= e((string)($doc['lead'] ?? '')) ?></p>
                </article>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
`,
    "templates/legal.php": `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}
component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-12 flex-1 max-w-3xl">
    <article class="lumora-card p-6 sm:p-10 space-y-6">
        <h1 class="text-2xl sm:text-3xl font-bold tracking-tight"><?= e($legal_data['title'] ?? 'Legal Policy') ?></h1>
        <p class="text-[var(--lumora-fg-muted)]"><?= e($legal_data['lead'] ?? '') ?></p>
        <?php foreach ($legal_data['sections'] ?? [] as $section): ?>
            <section id="<?= e($section['id'] ?? '') ?>" class="space-y-2">
                <h2 class="text-lg font-bold"><?= e($section['title'] ?? '') ?></h2>
                <div class="text-sm text-[var(--lumora-fg-muted)] space-y-2"><?= $section['body'] ?? '' ?></div>
            </section>
        <?php endforeach; ?>
    </article>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
`,
    "templates/404.php": `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}
component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-16 flex-1">
    <div class="lumora-card p-8 max-w-md mx-auto text-center space-y-4">
        <h1 class="text-2xl font-bold">404 — Page Not Found</h1>
        <a href="<?= e(u('/')) ?>" class="lumora-btn lumora-btn-primary">Return Home</a>
    </div>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
`,
  };

  for (const [relPath, content] of Object.entries(templates)) {
    const abs = path.join(siteRoot, relPath);
    if (!existsSync(abs)) {
      writeFileSync(abs, content, "utf8");
    }
  }

  // Non-destructively create or merge consumer package.json scripts
  const pkgJsonPath = path.join(siteRoot, "package.json");
  let pkgObj: Record<string, unknown> = {
    name: `${brandId}-website`,
    version: "0.1.0",
    private: true,
    type: "module",
  };
  if (existsSync(pkgJsonPath)) {
    try {
      pkgObj = JSON.parse(readFileSync(pkgJsonPath, "utf8")) as Record<string, unknown>;
    } catch {
      // Keep default object if existing package.json is unreadable
    }
  }
  pkgObj.private ??= true;
  pkgObj.type ??= "module";
  const existingScripts = (pkgObj.scripts as Record<string, string> | undefined) ?? {};
  pkgObj.scripts = {
    sync: "lumora-web sync",
    css: "lumora-web css",
    dev: "lumora-web dev",
    build: "lumora-web build",
    verify: "lumora-web verify",
    flush: "lumora-web flush",
    admin: "lumora-web admin",
    ...existingScripts,
  };
  const existingDeps = (pkgObj.dependencies as Record<string, string> | undefined) ?? {};
  const existingDevDeps = (pkgObj.devDependencies as Record<string, string> | undefined) ?? {};
  if (!existingDeps["@astrake/lumora-webserver"] && !existingDevDeps["@astrake/lumora-webserver"]) {
    pkgObj.dependencies = {
      ...existingDeps,
      "@astrake/lumora-webserver": "latest",
    };
  }
  writeFileSync(pkgJsonPath, JSON.stringify(pkgObj, null, 2) + "\n", "utf8");

  // Non-destructively create or merge consumer .gitignore
  const gitignorePath = path.join(siteRoot, ".gitignore");
  const requiredIgnores = [
    "node_modules/",
    "dist/",
    "storage/",
    "*.sqlite",
    "*.sqlite-shm",
    "*.sqlite-wal",
    "*.db",
    "*.db-shm",
    "*.db-wal",
    "*.jsonl",
    "*.log",
    ".env",
    ".env.*",
    "!.env.example",
  ];
  if (!existsSync(gitignorePath)) {
    writeFileSync(gitignorePath, requiredIgnores.join("\n") + "\n", "utf8");
  } else {
    const currentIgnore = readFileSync(gitignorePath, "utf8");
    const missing = requiredIgnores.filter((entry) => !currentIgnore.includes(entry));
    if (missing.length > 0) {
      const prefix = currentIgnore.endsWith("\n") ? "" : "\n";
      writeFileSync(gitignorePath, `${currentIgnore}${prefix}${missing.join("\n")}\n`, "utf8");
    }
  }

  // Scaffold agentic guidance files (AGENTS.md, .cursorrules, LUMORA_SETUP.md)
  const agentsTarget = path.join(siteRoot, "AGENTS.md");
  if (options.withAgents || !existsSync(agentsTarget)) {
    const agentsSrc = resolveFrameworkDoc("AGENTS.md");
    if (agentsSrc) {
      copyFileSync(agentsSrc, agentsTarget);
    }
  }
  const cursorTarget = path.join(siteRoot, ".cursorrules");
  if (options.withAgents || !existsSync(cursorTarget)) {
    const cursorSrc = resolveFrameworkDoc(".cursorrules");
    if (cursorSrc) {
      copyFileSync(cursorSrc, cursorTarget);
    }
  }
  const setupPath = path.join(siteRoot, "LUMORA_SETUP.md");
  if (!existsSync(setupPath)) {
    writeFileSync(
      setupPath,
      `# Lumora Website Setup (${brandName})

- **Config file**: \`lumora.config.php\`
- **Route registry**: \`content/routes.php\`
- **Inquiry channels**: \`content/channels.php\`
- **Brand CSS**: \`css/brand-theme.css\` & \`css/brand.css\`
- **Templates**: \`templates/\`
- **Core lockfile**: \`lumora-lock.json\` (managed by \`bun run sync\`)

## Commands

- \`bun run sync\`: Materialize \`core/*.php\` and \`public/assets/js/lumora-core.js\` and update \`lumora-lock.json\`
- \`bun run css\`: Compile Tailwind CSS v4 into \`public/assets/css/lumora.min.css\`
- \`bun run dev\`: Start local PHP 8.2+ dev server, CSS/template watcher, and localhost Admin Console setup helper
- \`bun run build\`: Build zero-dependency \`dist/\` deployment bundle
- \`bun run verify\`: Run the 12-Gate Automated Compliance Verifier
- \`bun run flush\`: Replay pending SQLite WAL outbox inquiries to Hub
- \`bun run admin\`: Manage Admin Console users (\`create-user\`, \`list-users\`, \`reset-password\`)

## AI Agent Context

Read \`AGENTS.md\` (or \`node_modules/@astrake/lumora-webserver/llms-full.txt\`) for the complete PHP helper API and 12-Gate rules.
`,
      "utf8"
    );
  }

  syncWebsite({ cwd: siteRoot, withAgents: options.withAgents, silent: options.silent });
  await compileBrandCss({ cwd: siteRoot, minify: true, silent: options.silent });

  if (!options.silent) {
    console.log(`[lumora-web init] Scaffolded Lumora website in ${siteRoot}`);
  }
}
