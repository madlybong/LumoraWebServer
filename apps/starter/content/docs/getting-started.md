# Getting Started with `@astrake/lumora-webserver`

Welcome to the reference starter application powered by **Lumora WebServer (`0.2.0`)**. Every page in this project renders directly from a headless PHP 8.2+ micro-kernel paired with locally compiled Tailwind CSS v4 styles and cryptographic asset verification.

## 1. Project Anatomy

- `lumora.config.php` — Central brand identity, CSP / CSRF security policy, feature flags (`docs_engine`, `blog_engine`, `release_server`, `legal_registry`, `admin_panel`), and discovery metadata.
- `content/` — Flat-file source of truth (`routes.php`, `channels.php`, `docs/`, `blog/`, `legal/`) layered transparently with SQLite CMS tables managed from `/admin/`.
- `templates/` — Strictly escaped HTML5 templates using native helpers (`component()`, `u()`, `e()`, `asset_url()`, `asset_sri()`, `lumora_csp_nonce()`).
- `css/` — `brand-theme.css` (design tokens and adaptive light/dark palette) + `brand.css` compiled via Tailwind CSS v4 into `public/assets/css/lumora.min.css`.
- `releases/` — SHA-256 verified binary/archive distribution catalog served with HTTP Range requests.

## 2. Local Development Workflow

Run the development server from your site root (or from the monorepo root with `bun run dev`):

```bash
bunx lumora-web dev
```

During startup, `lumora-web dev`:

1. Synchronizes the framework runtime into `core/` (`lumora-web sync`).
2. Compiles Tailwind CSS v4 (`brand-theme.css` + `brand.css` + `lumora-base.css`) into `public/assets/css/lumora.min.css` and computes its SHA-384 Subresource Integrity digest.
3. Watches `css/`, `templates/`, and `content/` for incremental stylesheet rebuilds.
4. Checks whether an active Owner account exists in SQLite. If none exists and `LUMORA_ADMIN_SETUP_TOKEN` is unset, `lumora-web dev` provisions an ephemeral 128-bit hex setup token for that session and prints both the Website URL and Admin Console URL.

## 3. Admin Console Onboarding

On localhost (`127.0.0.1` or `::1`), an **Admin Console** badge appears in the navigation bar and footer whenever `features.admin_panel` is enabled. On public hostnames, the header stays completely clean so admin routes remain stealth.

To create an administrator explicitly via CLI:

```bash
bunx lumora-web admin create-user --email admin@example.com --role owner
```

## 4. Production Build & 12-Gate Verification

Generate the zero-rewrite `dist/` bundle and verify all 12 security, SRI, accessibility, and live HTTP smoke gates:

```bash
bunx lumora-web build
bunx lumora-web verify
```

All generated HTML routes inside `dist/` ship self-contained `index.php` stubs compatible with Apache, Nginx, LiteSpeed, cPanel, and PHP built-in servers without complex rewrite dependencies.
