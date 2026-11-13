# Project Overview — `@astrake/lumora-webserver`

**Package Name:** `@astrake/lumora-webserver`  
**Current Version:** `0.1.2`  
**Runtime Requirements:** PHP `8.2+` (`pdo_sqlite`, `curl`, `mbstring`), Bun `1.3+` (build & verification CLI)  
**License:** MIT  

---

## 1. Mission & Problem Statement

Whether building personal portfolios, studio sites, SaaS marketing sites, or regulated multi-brand portals, maintaining standalone PHP websites independently inevitably introduces architectural and security drift:
- **Bootstrap Guard Drift:** Partial files accidentally defining guard constants instead of rejecting direct HTTP probes with `403 Forbidden`.
- **CGNAT CSRF Failures:** Legacy IP-bound CSRF tokens failing when mobile visitors on carrier-grade NAT (CGNAT) networks rotate public IPv4 addresses between page load and form submission.
- **Rate-Limit Ordering Bugs:** Executing rate-limit checks *after* CSRF or field validation, allowing automated bots to flood validation endpoints.
- **Subresource Integrity (SRI) & Cache Drift:** Updating CSS or JS assets without updating SHA-384 `integrity` attributes or cache-busting query strings (`?v=<hash8>`).
- **Rigid Compliance Assumptions:** Forcing every website to carry jurisdiction-specific enterprise templates instead of letting each site opt in to the exact territories (`IN`, `EU`, `UK`, `US`, or neutral `GLOBAL`), legal documents, discovery endpoints, and retention windows it needs.

`@astrake/lumora-webserver` eliminates this drift by packaging **100% of the headless PHP 8.2+ backend logic, Tailwind CSS v4 base primitives, client ES module runtime, zero-dependency `dist/` bundler, and 12-Gate Automated Compliance Verifier** into a single cryptographically locked NPM package.

---

## 2. Core Design Principles

1. **100% Headless Backend, 100% Brand-Owned Visual DOM:**
   - `packages/core/runtime/php/core/` provides all security headers, routing, discovery generation, Schema.org `@graph` synthesis, multi-channel inquiry validation, SQLite3 WAL persistence, statutory legal generation, docs/blog engines, and binary release serving.
   - Each consumer brand website owns 100% of its HTML templates (`templates/*.php`), design tokens (`css/brand-theme.css`, `css/brand.css`), and declarative content (`lumora.config.php`, `content/routes.php`, `content/channels.php`).
2. **Cryptographic Lockfile (`lumora-lock.json`):**
   - `lumora-web sync` materializes the PHP core into `<website>/core/` and records SHA-256 hashes of every core file plus SHA-384 Subresource Integrity (SRI) hashes of compiled CSS, client JS, and `.woff2` fonts in `lumora-lock.json`.
   - **Gate 01** and **Gate 04** fail the build immediately if any file in `core/` is modified locally or if any asset's SRI hash drifts.
3. **Zero-Dependency Production `dist/` Output:**
   - `lumora-web build` produces a self-contained `dist/` folder with physical `<route>/index.php` stubs, `.htaccess`, and `nginx.lumora.conf`.
   - Production servers (cPanel, LiteSpeed, aaPanel, Apache, Nginx) require **zero** `node_modules/`, **zero** Composer `vendor/`, and **zero** custom URL rewrite configuration to serve every canonical route and legacy `301` redirect.
4. **Dual-Mode First-Party Inquiry Persistence & Dispatch:**
   - Every form submission is validated in strict Gate 09 order and persisted in `<1ms` to an isolated local SQLite3 Write-Ahead Logging (WAL) database (`storage/vault-<hmac>/lumora.sqlite`) plus an atomic `flock` JSONL failover ledger (`contacts-backup.jsonl`).
   - Websites can operate in **Standalone Edge Mode** (native TLS 1.2/1.3 SMTP socket + self-hosted WAHA/Evolution/Webhook WhatsApp) or **Central Hub Mode** (HMAC-SHA256 signed webhook sync to a compatible receiver endpoint with automatic outbox replay via `lumora-web flush`).

---

## 3. Compliance & Standards Coverage

| Standard / Mandate | Enforcement Mechanism in `@astrake/lumora-webserver` |
| :--- | :--- |
| **OWASP ASVS v4.0 Level 2** | 8 mandatory HTTP security headers, per-request 128-bit CSPRNG CSP nonce, `LUMORA_BOOTSTRAPPED` `403` isolation, `realpath`-jailed file reads, and context-aware output escaping (`e()`). |
| **India DPDP Act 2023 (Opt-in `IN`)** | Zero third-party trackers/CDNs (**Gate 05**), configurable consent verification, configurable PII redaction (`[REDACTED]` at `retention_days_pii`, or `0` to disable), Eighth Schedule language notice when `'IN'` territory is configured, and custom/privacy inquiry channels. |
| **CERT-In Directions (2022) (Opt-in `IN`)** | Keyed HMAC-SHA256 `ip_hash` security event logging (`contact_rate_limits` & `security-events.log`) with configurable retention (`retention_days_logs`) and optional `/.well-known/security.txt`. |
| **EU / UK GDPR & ePrivacy (Opt-in `EU`/`UK`)** | Zero-cookie stateless session architecture (`/legal/cookies/`), zero external font/script leaks, and parameterized Controller/Data Subject rights disclosures. |
| **W3C WCAG 2.2 Level AA** | `:focus-visible` keyboard focus rings, `.lumora-skip-link` navigation, `@media (prefers-reduced-motion: reduce)` reset, hardware/network motion tier governor (`data-motion="lite\|full"`), and **Gate 06** `<img>` attribute enforcement. |
