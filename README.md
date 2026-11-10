# `@astrake/lumora-webserver`

**Headless PHP 8.2+ Enterprise Website Micro-Kernel, Tailwind CSS v4 Brand Layer Compiler, Zero-Dependency `dist/` Bundler, and 12-Gate Automated Compliance Verifier.**

Part of the **Lumora Ecosystem** (`@astrake/lumora-server`, `@astrake/lumora-webserver`, `@astrake/lumora-ui`).

---

## 1. Quick Start (Consumer Websites)

```bash
mkdir my-brand-website && cd my-brand-website
bun init -y
bun add -D @astrake/lumora-webserver

# 1. Scaffold a new brand website
bunx lumora-web init \
  --brand-id "auruvi" \
  --brand-name "Auruvi HMS" \
  --prefix "AUR" \
  --domain "auruvi.com" \
  --entity "Astrake India"

# 2. Start local PHP 8.2+ dev server with live Tailwind v4 + SRI watcher
bunx lumora-web dev --port 8080

# 3. Build zero-dependency dist/ bundle and run all 12 compliance gates
bunx lumora-web build
bunx lumora-web verify
```

---

## 2. Documentation Index

### User & Site Operator Guides
- [**Getting Started (`docs/user/GETTING_STARTED.md`)**](./docs/user/GETTING_STARTED.md): Scaffolding a project, authoring templates and components, adding docs/blog content, and running the CLI.
- [**Configuration Reference (`docs/user/CONFIGURATION.md`)**](./docs/user/CONFIGURATION.md): Complete schema reference for `lumora.config.php`, `content/routes.php`, `content/channels.php`, `content/legal/{slug}.php`, and environment variables.
- [**Production Deployment (`docs/user/DEPLOYMENT.md`)**](./docs/user/DEPLOYMENT.md): Deploying `dist/` to Apache/cPanel/LiteSpeed, Nginx/aaPanel, Cloudflare/reverse proxies, and configuring Edge SMTP/WhatsApp or Central Hub sync.

### Architecture & Framework Developer Guides
- [**Project Overview (`docs/PROJECT.md`)**](./docs/PROJECT.md): Ecosystem positioning, architectural principles, and statutory compliance matrix (OWASP ASVS v4.0 L2, DPDP Act 2023, GDPR, CERT-In 2022, WCAG 2.2 AA).
- [**System Architecture (`docs/ARCHITECTURE.md`)**](./docs/ARCHITECTURE.md): Request lifecycle, physical zero-rewrite route stubs, CGNAT-safe stateless v2 CSRF, SQLite3 WAL + JSONL persistence, and CSS/SRI compilation.
- [**Contributing Guide (`docs/developer/CONTRIBUTING.md`)**](./docs/developer/CONTRIBUTING.md): Monorepo structure, PHP 8.2+ & TypeScript coding conventions, and release workflow.
- [**Testing & 12-Gate Verifier (`docs/developer/TESTING.md`)**](./docs/developer/TESTING.md): Test suite breakdown and troubleshooting guide for all 12 compliance gates.

### AI & LLM Context Specifications
- [**AI Agent Operating Guide (`AGENTS.md`)**](./AGENTS.md): Mandatory invariants, PHP global helper signatures, template context variables, and `lumora-core.js` DOM data attributes.
- [**LLM Index (`llms.txt`)**](./llms.txt): Concise machine-readable index of modules, documentation, and CLI commands.
- [**Full LLM Specification (`llms-full.txt`)**](./llms-full.txt): Single-file complete API, schema, wire-protocol, and 12-Gate specification for zero-hallucination agentic coding.

---

## 3. Core Capabilities

1. **Zero-Dependency PHP 8.2+ Headless Micro-Kernel (`runtime/php/core/`):**
   - **Security (`security.php`):** Emits 8 mandatory OWASP ASVS v4.0 Level 2 HTTP headers, per-request 128-bit CSPRNG CSP nonces, HMAC-derived local storage vaults (`storage/vault-<hmac>/`), and CGNAT-resilient stateless v2 HMAC-SHA256 CSRF tokens (`{tsHex}.{nonceHex}.{mac}`).
   - **Routing & Discovery (`router.php`, `discovery.php`):** Enforces canonical trailing slashes (`301`), subfolder base-path resolution, and auto-serves `/health`, `/robots.txt`, `/sitemap.xml`, `/llms.txt`, `/llms-full.txt`, `/.well-known/security.txt`, `/site.webmanifest`, and `POST /_lumora/outbox/flush`.
   - **SEO & Schema.org (`render.php`, `schema-graph.php`):** Deterministic Schema.org JSON-LD `@graph` builder (`Organization`, `Brand`, `WebSite`, `SoftwareApplication`, `BreadcrumbList`, `ContactPage`, `TechArticle`, `BlogPosting`, `WebPage`, `FAQPage`) and automatic SHA-384 Subresource Integrity (SRI) + `?v=<hash8>` cache-busting from `lumora-lock.json`.
   - **Declarative Inquiry Engine & SQLite WAL Vault (`inquiry-engine.php`, `inquiry-db.php`, `hub-client.php`):** Pre-CSRF rate limiting (`5 / 900s`), honeypot (`reject` or `fake_receipt`), `<2s` timing guard, declarative custom channel fields (`content/channels.php`), `<1ms` SQLite3 WAL + atomic JSONL persistence, 90-day DPDP Act 2023 PII redaction, 180-day CERT-In log purge, standalone Edge notifications (native SMTP + self-hosted WAHA/Evolution/Webhook WhatsApp), and optional HMAC-SHA256 webhook outbox sync.
   - **Parameterized Territory Legal Registry (`legal-registry.php`):** Generates statutory compliance documents (`privacy`, `terms`, `cookies`, `accessibility`, `security`, `company`) parameterized by territory (`IN`, `EU`, `UK`, `US`, `GLOBAL`) with full project-level customization in `content/legal/{slug}.php`.
   - **Content & Release Engines (`docs-engine.php`, `blog-engine.php`, `release-server.php`):** Manifest-jailed Markdown documentation engine (with `dist/` manifest inlining), file-based blog engine with localhost draft gating and Atom 1.0 feed, and RFC 9110 `Range` (`206 Partial Content`) binary artifact server with `X-Checksum-Sha256`.

2. **Tailwind CSS v4 + Brand Design Layer (`runtime/css/lumora-base.css`):**
   - Compiles `lumora-base.css` + `css/brand-theme.css` + `css/brand.css` into an SRI-locked stylesheet (`public/assets/css/lumora.min.css`).

3. **Zero-Dependency `dist/` Packaging & 12-Gate Verifier (`lumora-web` CLI):**
   - `lumora-web init`: Scaffolds a new website project.
   - `lumora-web sync`: Syncs the locked PHP/JS runtime and computes SHA-256 + SHA-384 SRI hashes in `lumora-lock.json`.
   - `lumora-web css`: Compiles Tailwind v4 + brand CSS layers.
   - `lumora-web dev`: Runs `php -S` dev server with live CSS/SRI watcher.
   - `lumora-web build`: Produces a 100% standalone `dist/` bundle with physical `<route>/index.php` stubs, `.htaccess`, and `nginx.lumora.conf`.
   - `lumora-web verify`: Runs all 12 compliance gates (source, SQLite WAL smoke, live HTTP headers, and zero-rewrite `dist/` server).
   - `lumora-web flush`: Replays pending SQLite WAL outbox inquiries to a configured webhook/hub endpoint.

---

## 4. Monorepo Commands

```bash
bun install
bun run validate      # Runs typecheck, tests, build, 12-Gate verifier on apps/starter, and version sync
bun test              # Runs Bun + PHP integration tests
bun run dev           # Starts local dev server for apps/starter
```
