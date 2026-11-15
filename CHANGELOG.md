# Changelog

## Unreleased

---

## [0.2.0] — 2026-11-16

### Added

- **Opt-In Vue 3 + CodeMirror 6 Admin Console (`packages/admin` & `packages/core/runtime/admin/`):**
  - Enterprise full-viewport admin shell (`h-dvh overflow-hidden`) featuring a sticky top app bar with route breadcrumbs, `⌘K` Command Palette, quick external website link, collapsible 4-group sidebar, and independent scroll container.
  - Full-page split-pane Studios powered by CodeMirror 6 (`@codemirror/lang-html`, `@codemirror/lang-markdown`, `@codemirror/lang-json`, line numbers, code folding, bracket matching, search, and adaptive dark/light themes) for Blog Articles, Documentation, Legal Policies, Form Channels, and Discovery/SEO overrides.
  - Standardized 3-zone (`Header` / `Scroll Body` / `Footer`) modal dialogs across media picking, revision history diffs, and confirmation flows.
- **4 New Headless PHP 8.2+ Core Modules (`13` → `17` modules in `Lumora\Core`):**
  - `AdminDb` (`core/admin-db.php`): Zero-write public GET fast-path (`connectExisting()`), Hybrid CMS SQLite schema (`admin_users`, `admin_sessions`, `admin_settings`, `admin_audit_logs`, `cms_blog_posts`, `cms_doc_tracks`, `cms_doc_articles`, `cms_legal_docs`, `cms_channels`, `cms_redirects`, `cms_404_logs`, `cms_media`, `cms_releases`, `cms_revisions`), revision snapshots, and OpenSSL `aes-256-gcm` + `hash_hkdf` encrypted secrets vault.
  - `AdminAuth` (`core/admin-auth.php`): `PASSWORD_ARGON2ID` password hashing, IP brute-force lockout (`429`), User-Agent-bound `HttpOnly` `SameSite=Strict` sessions, per-session HMAC CSRF (`X-Lumora-Csrf`), hierarchical RBAC (`owner` > `editor` > `viewer`) with `admin.allowed_roles` and last-owner guard, RFC 6238 TOTP 2FA with replay protection (`last_totp_step`) & single-use recovery codes, offline QR SVG matrix generator, and dual-path Owner bootstrap (`admin.initial_owner` / `LUMORA_ADMIN_SETUP_TOKEN`).
  - `AdminSanitizer` (`core/admin-sanitizer.php`): Allowlist `DOMDocument` HTML sanitizer stripping `<script>`, `<iframe>`, event handlers, and `javascript:` URIs while enforcing Gate-06 `width`/`height`/`alt` constraints, plus polyglot-safe raster upload validation (`public/uploads/` with `.htaccess` & `index.html` script guards).
  - `AdminController` (`core/admin-controller.php`): Stealth `admin.path` SPA host (`X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`) and JSON API across Inquiries CRM (triage status, internal notes, bulk actions, CSV export), Hub & Edge Dispatch settings & test delivery, Form Channels Studio, Blog Studio, Documentation Studio, Media Library, Legal Policies Studio, Routes & 404 Monitor (with 1-click 404-to-301 redirect promotion), Discovery & SEO Studio, Releases Manager, Security & Audit Logs, Users & RBAC, and Profile & 2FA.
- **Admin CLI & Agentic Foreign-Project Upgrade Suite:**
  - New `lumora-web admin` subcommands (`create-user`, `list-users`, `reset-password`, `disable-2fa`, `revoke-sessions`, `hash-password`).
  - Added `--with-admin` flag to both `lumora-web init` and `lumora-web sync` for non-destructive upgrades of existing `0.1.x` websites (`lumora.config.php`, `package.json`, `router.php`, `.htaccess`, `core/*.php`, `public/assets/admin/`, `public/uploads/`, `lumora-lock.json`).
  - Added [`docs/user/UPGRADING.md`](docs/user/UPGRADING.md) (also packed at `UPGRADING.md` inside `@astrake/lumora-webserver`) with deterministic Path A/Path B upgrade commands and copy-pasteable AI coding agent prompts.

### Changed

- **Hybrid Flat-File + SQLite CMS Resolution:** Updated `BlogEngine`, `DocsEngine`, `LegalRegistry`, `InquiryEngine`, `Router`, `Discovery`, and `ReleaseServer` to merge SQLite CMS records and overrides seamlessly on top of repository flat files (`content/*`, `releases/*`) while preserving zero disk writes on uninitialized sites.
- **Reference Starter Application (`apps/starter`):** Overhauled with adaptive light/dark Zinc + Electric Blue design tokens (`css/brand-theme.css`, `css/brand.css`), CSP-nonced first-paint theme persistence, `/releases/` showcase template, localhost Admin Console onboarding links, and `v0.2.0` reference artifacts.
- **12-Gate Automated Compliance Verifier (`lumora-web verify`):** Expanded Gates 01, 04, 06, 09, 10, 11, and 12 to validate Admin Console SRI assets, zero-write public GET fast-path, `AdminSanitizer` XSS/polyglot guards, `AdminDb` AES-256-GCM tamper rejection, stealth `admin.path` HTTP headers, and physical `dist/<admin.path>/` zero-rewrite stubs.
- **Changelog Automation (`tools/changelog.ts`):** Preserves manually authored/curated release sections when `CHANGELOG.md` already contains an entry for the target version unless `--force` is passed.

### Fixed

- Resolved local `@tailwindcss/cli` binary resolution across nested workspace `node_modules/.bin` paths in `compileBrandCss()`.
- Replaced HTML5 `<search>` tag with `<div role="search">` in `InquiriesView.vue` to eliminate Vue compiler component-resolution runtime errors, wrapped admin views in single-root `<div>` containers for `<Transition mode="out-in">` stability, and guarded hash-route studio watchers per active view.
- Replaced hardcoded `'0.1.2'` template version fallbacks in `packages/core/src/cli/init.ts` with dynamic `resolvePackageVersion()`.

---

## [0.1.2] — 2026-11-14

### Changed
- **Standardized PHP Core Namespace (`Lumora\Core`):** Migrated all 13 PHP core classes from `Astrake\Lumora\Core` to `Lumora\Core` while registering backward-compatible `class_alias(\Lumora\Core\<Class>::class, \Astrake\Lumora\Core\<Class>::class)` declarations across every module.
- **De-Branded Security Layer:** Removed hardcoded legacy bootstrap constants and globals (`ASTRAKE_*`, `AURUVI_*`, `DRISVA_*`, `ORINA_WEB_BOOTSTRAPPED`) from `Security::boot()` in favor of opt-in `security.compat_constants` validated against `/^[A-Z][A-Z0-9_]{2,63}$/`.
- **App-Driven `LegalRegistry` & Globally Neutral Defaults:** Switched default legal configuration to `territories: ['GLOBAL']`, `slugs: ['privacy', 'terms']`, optional `brand.legal_entity`, empty default `address`, and neutral `[REDACTED]` pruning tokens. Added support for `legal.slugs`, `legal.include_default_slugs`, custom `content/legal/{slug}.php` documents without forced statutory boilerplate, `features.legal_registry = false` toggle, and `LegalRegistry::activeSlugs()`.
- **Universal `SchemaGraph` & Configurable `Discovery`:** Added `schema_org_type` (`Person` / `Organization` / `LocalBusiness`), opt-in `legalName`, `sameAs`, custom `contact_points`, optional `PostalAddress`, opt-in `SoftwareApplication` (`primary_offering_schema`) & `Brand` (`emit_brand_node`), per-route `schema_nodes`, `framework_label`, `discovery.*` endpoint toggles (with conditional `<link>` rendering in `Render::headMeta()`), `content/llms.php` programmatic override, and automatic `BlogEngine` indexing in `/llms.txt`.
- **App-Owned Channels, First-Party Attribution & Retention Opt-Out:** Removed hardcoded alias/referer merging in `InquiryEngine` when an app defines `channels`, added first-party `_attr_*` / UTM / CTA attribution extraction (plus `initFirstPartyAttribution()` in `lumora-core.js` and wire `schema_version` `1.1`), and updated `InquiryDb::prune()` so `retention_days_pii = 0` preserves submissions intact.
- **Dynamic CLI, Scaffolder & 12-Gate Verifier:** Updated `lumora-web init` defaults (`acme` / RFC 2606 examples), `build-dist` legal stub generation (`loadPhpLegalSlugs()`), and Gates 05/08/09/10/12 to respect site feature and discovery toggles. Updated tests to cover Personal, Commercial, and Regulated Enterprise profiles.

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
