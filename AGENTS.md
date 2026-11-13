# Lumora Agent Guide — `@astrake/lumora-webserver`

This is the authoritative operating guide for AI coding agents working in:
1. **The `LumoraWebServer` framework monorepo** (`packages/core`, `apps/starter`, `tools/`), OR
2. **Any consumer website** powered by `@astrake/lumora-webserver` (personal portfolios, commercial sites, SaaS products, or regulated enterprise portals).

---

## 1. Mission & Core Architectural Invariants

`@astrake/lumora-webserver` is a headless PHP 8.2+ website micro-kernel, Tailwind CSS v4 brand layer compiler, zero-dependency `dist/` bundler, and 12-Gate automated verification suite.

### Non-Negotiable Rules

1. **Zero Brand Hardcoding in `core/`:**
   - Never hardcode brand names, domains, routes, inquiry channels, or legal entity particulars inside `packages/core/runtime/php/core/`.
   - Parameterize 100% of brand data through `lumora.config.php`, `content/routes.php`, `content/channels.php`, and `content/legal/{slug}.php`.
2. **Read-Only `core/` in Consumer Websites:**
   - In any brand website consuming `@astrake/lumora-webserver`, files inside `<website>/core/` and `<website>/public/assets/js/lumora-core.js` are managed exclusively by `lumora-web sync` and verified by SHA-256 checksums in `lumora-lock.json` (**Gate 01**). Never hand-edit `<website>/core/*.php` in a consumer repo.
3. **Strict PHP 8.2+ & Direct-Access Guard (`Gate 02` & `Gate 03`):**
   - Every PHP file in the repository (`index.php`, `router.php`, `lumora.config.php`, `core/*.php`, `content/**/*.php`, `templates/**/*.php`) **must** begin with `<?php` and `declare(strict_types=1);`.
   - Every PHP file **except** `index.php` and `router.php` **must** enforce the bootstrap isolation guard at the top of the file:
     ```php
     if (!defined('LUMORA_BOOTSTRAPPED')) {
         http_response_code(403);
         exit('Forbidden');
     }
     ```
   - When a core PHP file declares both `Lumora\Core` classes and global helper functions, use bracketed namespaces (`namespace Lumora\Core { ... }` followed by `namespace { ... }`) and register backward-compatible `class_alias(\Lumora\Core\<Class>::class, \Astrake\Lumora\Core\<Class>::class)` declarations.
4. **Zero Third-Party Leaks (`Gate 05`):**
   - Never reference external font CDNs (`fonts.googleapis.com`, `fonts.gstatic.com`), script CDNs (`cdnjs`, `unpkg`, `jsdelivr`), or third-party trackers (`googletagmanager`, `google-analytics`, `hotjar`, `facebook`) in any template, CSS, or JS file.
   - Never use `eval(` or `new Function(` in any JS or PHP file.
5. **Accessibility & Image Dimensions (`Gate 06`):**
   - Every `<img>` tag in `templates/**/*.php` **must** declare explicit `width="..."`, `height="..."`, and `alt="..."` attributes.
   - Compiled CSS (`public/assets/css/lumora.min.css`) must retain `:focus-visible` and `@media (prefers-reduced-motion: reduce)` rules from `lumora-base.css`.
6. **Trailing-Slash Canonical URLs (`Gate 07`):**
   - Every non-file route key in `content/routes.php` (except `/`) **must** start and end with `/` (e.g., `'/contact/'`, `'/docs/'`, `'/legal/'`) and reference an existing `templates/{template}.php` file.
7. **Full 12-Gate Verification:**
   - Any change to `packages/core` or `apps/starter` in this monorepo must pass `bun run validate`.
   - Any change to a consumer brand website must pass `bunx lumora-web verify`.

---

## 2. Headless PHP Global Helpers & Template API

When authoring brand templates in `templates/**/*.php`, use these global helper functions provided by `core/`:

| Helper Function | Signature & Behavior |
| :--- | :--- |
| `e(?string $value): string` | HTML-escapes text using `ENT_QUOTES \| ENT_SUBSTITUTE` (`UTF-8`). Use for all dynamic text and attribute values. |
| `u(string $path): string` | Resolves a root-relative path (e.g., `u('/contact/')`) against the active subdirectory base path. Leaves `http://`, `https://`, `mailto:`, `tel:`, and `#` URLs untouched. |
| `asset_url(string $path): string` | Resolves a public asset URL (e.g., `asset_url('/public/brand/icon.svg')`). Automatically appends `?v=<8-char-hash>` cache-busting query string to `.css` and `.js` files that have an SRI entry in `lumora-lock.json`. |
| `asset_sri(string $path): string` | Returns the `sha384-...` Subresource Integrity hash for `$path` from `lumora-lock.json` (or `''` if not locked). |
| `component(string $name, array $props = []): void` | Includes `templates/components/{$name}.php` or `templates/layout/{$name}.php` with `$props` extracted (`EXTR_SKIP`). |
| `render_template(string $name, array $ctx = []): void` | Renders `templates/{$name}.php` (falling back to `templates/404.php` with HTTP 404 if missing). |
| `lumora_head_meta(array $meta = []): string` | Emits `<title>`, SEO `<meta>`, canonical & `hreflang` links, conditional `/llms.txt`, `/llms-full.txt`, and `/site.webmanifest` links (controlled by `discovery.*`), OpenGraph & Twitter tags, WOFF2 font `<link rel="preload">` tags with `integrity`, compiled stylesheet `<link rel="stylesheet">` with `integrity`, and CSP-nonced `<script type="application/ld+json">` Schema.org `@graph`. |
| `lumora_core_script(): string` | Emits `<script type="module" src=".../lumora-core.js?v=..." nonce="..." integrity="sha384-..." crossorigin="anonymous"></script>`. |
| `lumora_csp_nonce(): string` | Returns the per-request 128-bit base64 CSP nonce (`$GLOBALS['lumoraCspNonce']`). Required on any custom `<script nonce="<?= e(lumora_csp_nonce()) ?>">`. |
| `lumora_csrf_token(): string` | Generates a CGNAT-resilient stateless v2 HMAC-SHA256 CSRF token (`{tsHex}.{nonceHex}.{mac}`). |
| `lumora_verify_csrf(?string $token): bool` | Verifies a stateless v2 CSRF token against UA hash, brand ID, and configured `csrf_ttl_sec` (default `7200s`). |
| `lumora_handle_inquiry(?array $postInput = null): array` | Processes `GET` hydration or `POST` submission on `/contact/` (or `/demo/`), enforcing rate limits, honeypot, `<2s` timing trap, v2 CSRF, declarative channel validation, SQLite WAL + JSONL storage, Edge SMTP/WhatsApp dispatch, and optional HMAC Hub sync. |
| `reading_time(string $textOrHtml): int` | Estimates reading time in minutes (at 230 WPM, minimum `1`). |
| `lumora_is_localhost(): bool` | Returns `true` on `127.0.0.1`, `::1`, `localhost`, or PHP CLI unless `LUMORA_FORCE_PRODUCTION=1`. |

---

## 3. Template Context Variables

`Bootstrap::handleRequest()` injects the following variables into every rendered template (`templates/{name}.php`):
- `$config` (`array`): The loaded `lumora.config.php` array.
- `$route` (`array`): The resolved route descriptor (`template`, `status`, `canonical_path`, `meta`, `params`).
- `$meta` (`array`): Route SEO/Schema metadata (`title`, `description`, `path`, `schema_type`, optional `faq`, `image`, `robots`). Pass to `component('layout/head', ['meta' => $meta, 'config' => $config])`.
- `$currentPath` (`string`): Normalized canonical path (e.g., `'/'`, `'/contact/'`, `'/legal/privacy/'`).
- **Route-Specific Params:**
  - On `/legal/{slug}/` (`templates/legal.php`, when `features.legal_registry` is enabled and `$slug` is active via `LegalRegistry::activeSlugs()`): `$slug` (`string`) and `$legal_data` (`array` with `doc_no`, `title`, `lead`, `version`, `date`, `status`, `entity_name`, `entity_type`, `address`, `email`, `territories`, `sections`).
  - On `/docs/{slug}/` or `/docs/{track}/{slug}/` (`templates/docs.php` or `templates/docs-article.php`): `$doc_article` (`array` with `found`, `track_id`, `slug`, `title`, `summary`, `reading_time`, `tags`, `html`, `toc`) and `$doc_manifest` (`array`).
  - On `/blog/{slug}/` (`templates/blog-article.php`): `$slug` (`string`) and `$post_data` (`array`).
  - On `/blog/page/{n}/` (`templates/blog-index.php`): `$page_num` (`int`).

---

## 4. Client JS (`lumora-core.js`) DOM Data Attributes

`lumora-core.js` automatically binds to the following `data-*` attributes on `DOMContentLoaded`:
- **Motion Governor:** Sets `document.documentElement.dataset.motion = "lite" | "full"` based on `prefers-reduced-motion`, `navigator.connection.saveData`, `hardwareConcurrency <= 4`, and `deviceMemory <= 4`.
- **Navigation Drawer:**
  - `[data-lumora-nav-toggle]`: Button toggling `aria-expanded="true|false"` and `document.body.classList.toggle('is-nav-open')` (closes on `Escape`).
  - `[data-lumora-nav-drawer]`: Drawer container whose `hidden` attribute is toggled.
- **Contact / Inquiry Form (`form[data-lumora-contact-form]`):**
  - `[data-lumora-delivery-mode]`: `<select>` element switching between `'online'` and `'onsite'`.
  - `[data-lumora-mode-btn="online|onsite"]`: Button-based mode switcher updating hidden `input[name="consultation_mode"]` and `aria-pressed`.
  - `[data-lumora-onsite-fields]`: Container revealed (`hidden = false`) when delivery mode is `'onsite'`, setting `required = true` on `[name="facility_location"]` and `[name="phone"]`.
  - `[data-lumora-phone-required]`: Badge revealed when phone is required for on-site assessments.
  - `[data-lumora-char-counter]`: Live character counter bound to `textarea[name="message"]` (`minlength` / `maxlength`).
- **First-Party Attribution (`initFirstPartyAttribution()`):**
  - `[data-lumora-cta="<id>"]`: Records the clicked CTA identifier into `window.sessionStorage` (`lumora_attr_v1`) alongside landing page, referrer, and UTM query parameters, and injects hidden `_attr_*` fields into `form[data-lumora-contact-form]`.

---

## 5. CLI & Workspace Commands

### Inside a Brand Website
- `bunx lumora-web init [--brand-id <id>] [--brand-name <name>] [--prefix <PFX>] [--domain <domain>] [--entity <entity>] [--with-agents]`
- `bunx lumora-web sync [--force-stubs] [--with-agents]`
- `bunx lumora-web css`
- `bunx lumora-web dev [--port 8080]`
- `bunx lumora-web build`
- `bunx lumora-web verify`
- `bunx lumora-web flush`

### Inside the `LumoraWebServer` Monorepo
- `bun run check`: Typecheck all workspace packages (`packages/core`, `apps/starter`).
- `bun test`: Run unit and PHP/CLI integration tests.
- `bun run build`: Build `packages/core/dist` and `apps/starter/dist`.
- `bun run verify:starter`: Run the 12-Gate Compliance Verifier against `apps/starter`.
- `bun run validate`: Execute the complete release validation pipeline (`check` → `test` → `build` → `verify:starter` → `version:sync`).
