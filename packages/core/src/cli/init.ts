import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { compileBrandCss } from "./css-compiler";
import { resolveFrameworkDoc } from "./paths";
import { syncWebsite } from "./sync";

export interface InitWebsiteOptions {
  cwd?: string;
  brandId?: string;
  brandName?: string;
  brandPrefix?: string;
  domain?: string;
  legalEntity?: string;
  withAgents?: boolean;
  silent?: boolean;
}

export async function initWebsite(options: InitWebsiteOptions = {}): Promise<void> {
  const siteRoot = path.resolve(options.cwd ?? process.cwd());
  const brandId = options.brandId ?? "my-site";
  const brandName = options.brandName ?? "My Brand";
  const brandPrefix = options.brandPrefix ?? "SITE";
  const domain = options.domain ?? "example.com";
  const legalEntity = options.legalEntity?.trim() || "";

  mkdirSync(path.join(siteRoot, "css"), { recursive: true });
  mkdirSync(path.join(siteRoot, "content", "legal"), { recursive: true });
  mkdirSync(path.join(siteRoot, "templates", "layout"), { recursive: true });
  mkdirSync(path.join(siteRoot, "public", "brand"), { recursive: true });
  mkdirSync(path.join(siteRoot, "public", "fonts"), { recursive: true });

  const legalEntityLine = legalEntity !== "" ? `\n        'legal_entity'  => '${legalEntity}',` : "";

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
        'version'       => '0.1.2',
        'locale'        => 'en',
        'theme_color'   => '#0a0a0a',
        'color_scheme'  => 'light',
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
    ],
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
  --color-canvas: #fafafa;
  --color-surface: #ffffff;
  --color-ink: #0a0a0a;
  --color-muted: #52525b;
  --color-accent: #0055ff;
  --color-border: #e4e4e7;
}
`,
      "utf8"
    );
  }

  const brandCssPath = path.join(siteRoot, "css", "brand.css");
  if (!existsSync(brandCssPath)) {
    writeFileSync(
      brandCssPath,
      `@import "@astrake/lumora-webserver/runtime/css/lumora-base.css";
@import "./brand-theme.css";

body {
  margin: 0;
  background-color: var(--color-canvas);
  color: var(--color-ink);
  font-family: var(--font-sans);
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
    <?= lumora_head_meta($meta ?? []) ?>
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
?>
<header>
    <nav aria-label="Primary Navigation">
        <a href="<?= e(u('/')) ?>"><?= e($config['brand']['name'] ?? 'Lumora') ?></a>
        <a href="<?= e(u('/contact/')) ?>">Contact</a>
        <?php if (in_array('privacy', \Lumora\Core\LegalRegistry::activeSlugs($config ?? []), true)): ?>
            <a href="<?= e(u('/legal/privacy/')) ?>">Privacy</a>
        <?php endif; ?>
    </nav>
</header>
`,
    "templates/layout/footer.php": `<?php
declare(strict_types=1);
if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<footer>
    <p>&copy; <?= gmdate('Y') ?> <?= e($config['brand']['legal_entity'] ?? ($config['brand']['name'] ?? 'Lumora')) ?>.</p>
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
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content">
    <h1><?= e($config['brand']['name'] ?? 'Lumora') ?></h1>
    <p><?= e($config['brand']['slogan'] ?? '') ?></p>
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
component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content">
    <h1><?= e($inquiry['channel_meta']['heading'] ?? 'Contact Us') ?></h1>
    <?php if (!empty($inquiry['success'])): ?>
        <div role="status">Receipt: <strong><?= e($inquiry['receipt'] ?? '') ?></strong></div>
    <?php else: ?>
        <form method="POST" action="<?= e(u('/contact/')) ?>" data-lumora-contact-form>
            <input type="hidden" name="_csrf" value="<?= e(lumora_csrf_token()) ?>" />
            <input type="hidden" name="_ts" value="<?= (string)time() ?>" />
            <input type="hidden" name="channel" value="<?= e($inquiry['channel'] ?? 'general') ?>" />
            <div class="lumora-honeypot" aria-hidden="true">
                <label>Website <input type="text" name="website_url" tabindex="-1" autocomplete="off" /></label>
            </div>
            <label>Full Name <input type="text" name="name" required /></label>
            <label>Email <input type="email" name="email" required /></label>
            <label>Subject <input type="text" name="subject" required /></label>
            <label>Message <textarea name="message" minlength="15" maxlength="4000" required></textarea></label>
            <label><input type="checkbox" name="consent" value="1" required /> I acknowledge the Privacy Notice.</label>
            <button type="submit"><?= e($inquiry['channel_meta']['button_text'] ?? 'Send') ?></button>
        </form>
    <?php endif; ?>
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
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content">
    <h1><?= e($legal_data['title'] ?? 'Legal Policy') ?></h1>
    <p><?= e($legal_data['lead'] ?? '') ?></p>
    <?php foreach ($legal_data['sections'] ?? [] as $section): ?>
        <section id="<?= e($section['id'] ?? '') ?>">
            <h2><?= e($section['title'] ?? '') ?></h2>
            <div><?= $section['body'] ?? '' ?></div>
        </section>
    <?php endforeach; ?>
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
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content">
    <h1>404 — Page Not Found</h1>
    <p><a href="<?= e(u('/')) ?>">Return Home</a></p>
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
- \`bun run dev\`: Start local PHP 8.2+ dev server and CSS watcher
- \`bun run build\`: Build zero-dependency \`dist/\` deployment bundle
- \`bun run verify\`: Run the 12-Gate Automated Compliance Verifier
- \`bun run flush\`: Replay pending SQLite WAL outbox inquiries to Hub

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
