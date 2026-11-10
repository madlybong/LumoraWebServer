# Changelog

## Unreleased

---

## [0.1.1] — 2026-11-11

### Maintenance

- Enable npm Trusted Publishing (OIDC) and SLSA provenance attestation in `.github/workflows/release.yml`.
- Upgrade CI/Release setup-bun to `1.4.4` for `lockfileVersion: 2` compatibility.

All notable changes to `@astrake/lumora-webserver` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/) · Commits: [Conventional Commits](https://www.conventionalcommits.org/)


---

## [0.1.0] — 2026-11-11

### Added

- **Headless PHP 8.2+ Enterprise Micro-Kernel (`packages/core/runtime/php/core/`):**
  - 16 zero-dependency core PHP modules (`bootstrap.php`, `router.php`, `render.php`, `security.php`, `meta.php`, `discovery.php`, `inquiry-controller.php`, `inquiry-db.php`, `inquiry-mailer.php`, `hub-client.php`, `legal-engine.php`, `docs-engine.php`, `blog-engine.php`, `release-server.php`, `outbox-cli.php`).
  - Dynamic discovery & statutory endpoints (`/robots.txt`, `/sitemap.xml`, `/llms.txt`, `/llms-full.txt`, `/.well-known/security.txt`, `/site.webmanifest`).
  - Defense-in-depth security: per-request 128-bit CSP nonces, SHA-384 Subresource Integrity (`lumora-lock.json`), stateless v2 HMAC-SHA256 CSRF tokens, pre-CSRF IP rate limiting, honeypot & `<2s` timing traps, SQLite WAL + JSONL fallback vault, and HMAC-SHA256 Hub webhook dispatch (`X-Lumora-Signature`).
- **Bun CLI & Build Toolchain (`lumora-web`):**
  - `lumora-web init`: Non-destructively scaffolds or upgrades a consumer website (`lumora.config.php`, `content/routes.php`, `content/channels.php`, `css/`, `templates/`, `package.json` scripts, `.gitignore`, `.cursorrules`, `AGENTS.md`, and `LUMORA_SETUP.md`).
  - `lumora-web sync`: Materializes locked PHP core (`core/*.php`) and client runtime (`public/assets/js/lumora-core.js`) and computes SHA-256 + SHA-384 SRI hashes in `lumora-lock.json`.
  - `lumora-web css`: Compiles `@astrake/lumora-webserver/runtime/css/lumora-base.css`, `css/brand-theme.css`, and `css/brand.css` into `public/assets/css/lumora.min.css`.
  - `lumora-web build`: Packages a zero-Node/zero-Bun `dist/` deployment bundle with physical route & redirect `index.php` stubs for Apache/cPanel/aaPanel, Nginx, and FrankenPHP.
  - `lumora-web verify`: Automated 12-Gate Compliance & Build Verifier.
  - `lumora-web flush`: Replays pending SQLite WAL outbox inquiries to the configured Hub webhook.
- **Reference Application (`apps/starter`) & Documentation Suite:**
  - Complete reference website passing 12/12 compliance gates plus full user, developer, and AI context documentation (`AGENTS.md`, `llms.txt`, `llms-full.txt`, `docs/`).
