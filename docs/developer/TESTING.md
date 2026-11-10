# Testing & 12-Gate Verification Guide — `LumoraWebServer`

`@astrake/lumora-webserver` combines unit tests, cross-language TypeScript/PHP integration tests, and the **12-Gate Automated Compliance Verifier** (`lumora-web verify`).

---

## 1. Running the Test & Validation Suite

| Command | Scope |
| :--- | :--- |
| `bun test` | Runs all Bun + PHP 8.2 integration test suites in `packages/core/test/*.test.ts`. |
| `bun run check` | Runs strict TypeScript typechecking across `tsconfig.base.json`, `packages/core`, and `apps/starter`. |
| `bun run verify:starter` | Runs the 12-Gate Compliance Verifier against `apps/starter`. |
| `bun run validate` | Runs `check` → `test` → `build` → `verify:starter` → `version:sync`. |

---

## 2. Test Suite Architecture (`packages/core/test/`)

1. **`packages/core/test/types-and-wire.test.ts`**:
   - Validates `LumoraWebConfigSchema` and `LumoraInquiryWirePayloadSchema`.
   - Verifies cross-language cryptographic parity: asserts that TypeScript's `signLumoraWebhookPayload()` and PHP's `HubClient::signPayload()` / `HubClient::verifySignature()` produce identical `sha256=<hex>` HMAC-SHA256 signatures for the same JSON payload and timestamp.
2. **`packages/core/test/php-runtime.test.ts`**:
   - **Stateless v2 CGNAT-Safe CSRF:** Verifies that `Security::csrfToken()` and `Security::verifyCsrf()` succeed when a mobile client's `REMOTE_ADDR` rotates mid-session under CGNAT (`103.21.244.10` → `49.36.128.99`), while rejecting User-Agent mismatches and tokens older than `csrf_ttl_sec`.
   - **Territory Legal Registry & Project Overrides:** Verifies that `LegalRegistry::resolveDocument()` generates territory-parameterized clauses (`IN` DPDP Act 2023, `EU` GDPR) and cleanly merges project-level `content/legal/{slug}.php` overrides (`merge_territory => true`, `append_sections`, `governing_law`).
   - **Statutory SQLite WAL Retention Pruning:** Verifies that `InquiryDb::prune()` leaves recent inquiries intact, redacts PII (`[REDACTED-90D]`) on records older than 90 days (DPDP Act 2023 Sec. 8(7)), and permanently deletes records older than 180 days (CERT-In Directions 2022).
   - **SRI Cache-Busting, Multi-Track Docs & Vault Secrets:** Verifies that `Render::assetUrl()` appends `?v=<8-char-hash>` to `.css`/`.js` assets while preserving bare `.woff2` font paths, `Router::resolve()` resolves `/docs/{track}/{slug}/`, and `HubClient::resolveHubSecret()` loads secrets from `storage/hub-secret.php`.
3. **`packages/core/test/cli-and-12-gates.test.ts`**:
   - Executes the complete CLI lifecycle (`syncWebsite` → `compileBrandCss` → `buildWebsiteDist` → `verifyWebsite`) against `apps/starter` and asserts that all 12 compliance gates pass.

---

## 3. Deep Breakdown of the 12 Compliance Gates (`packages/core/src/cli/verify.ts`)

| Gate | Name | What It Verifies & How to Fix Failures |
| :---: | :--- | :--- |
| **01** | **Core Lock Integrity (SHA-256)** | Compares SHA-256 hashes of `core/*.php` and `public/assets/js/lumora-core.js` against `lumora-lock.json` and checks for untracked files in `core/`. **Fix:** Run `lumora-web sync`. |
| **02** | **PHP 8.2+ Syntax & Strict Types** | Parses all `.php` files via `token_get_all(..., TOKEN_PARSE)` + `php -l index.php` and asserts `declare(strict_types=1);`. **Fix:** Add `declare(strict_types=1);` to the top of any new template or content file. |
| **03** | **`LUMORA_BOOTSTRAPPED` Direct-Access Isolation** | Scans all `.php` files outside `index.php` and `router.php` for `if (!defined('LUMORA_BOOTSTRAPPED'))` + `403` exit. **Fix:** Add the 4-line bootstrap guard to the top of the file. |
| **04** | **Font & Vendor Asset SRI SHA-384 Synchronization** | Recomputes SHA-384 hashes of `lumora.min.css`, `lumora-core.js`, and `public/fonts/*.woff2` and compares against `lumora-lock.json`. **Fix:** Run `lumora-web css` or `lumora-web sync`. |
| **05** | **Zero Third-Party Leaks (GDPR & DPDP Act 2023)** | Scans all `.php`, `.css`, and `.js` files for external CDNs (`fonts.googleapis.com`, `cdnjs`, `unpkg`, `jsdelivr`), trackers (`googletagmanager`, `google-analytics`, `hotjar`), `eval()`, and `new Function()`. **Fix:** Self-host all fonts and scripts under `public/`. |
| **06** | **Design Token & Accessibility Audit** | Checks `lumora.min.css` for `prefers-reduced-motion` and `:focus-visible`, and parses `templates/**/*.php` (stripping `<?...?>` first) to verify every `<img>` tag has explicit `width`, `height`, and `alt` attributes. |
| **07** | **Canonical Route & Trailing-Slash Audit** | Verifies every non-file route in `content/routes.php` starts and ends with `/` and points to an existing `templates/{name}.php` file. |
| **08** | **Statutory Legal & DPDP Portal Completeness** | Evaluates `LegalRegistry::resolveDocument()` across all 6 statutory slugs (`privacy`, `terms`, `cookies`, `accessibility`, `security`, `company`) and verifies `/contact/` resolves to `200`. |
| **09** | **Edge SQLite WAL & Pre-CSRF Rate-Limit Smoke Test** | Runs an isolated 6-attempt CLI test against `InquiryEngine::handle()` in a temporary vault verifying: (1) honeypot catch, (2) `<2s` timing trap block, (3) invalid CSRF block, (4) valid submission persisted to SQLite WAL + JSONL with `sha256=...` HMAC signature, and (6) 6th POST blocked by the `5 / 900s` pre-CSRF rate limiter even with a valid CSRF token. |
| **10** | **Ephemeral Live HTTP Security Header Audit** | Spawns `php -S 127.0.0.1:<port> router.php` and verifies: all 8 security headers on `GET /`, `403 Forbidden` on `/core/security.php`, `/lumora.config.php`, and `/storage/`, and `200 OK` across all 7 discovery endpoints (`/health`, `/robots.txt`, `/sitemap.xml`, `/llms.txt`, `/llms-full.txt`, `/.well-known/security.txt`, `/site.webmanifest`). |
| **11** | **Zero-Dependency `dist/` Packaging Verification** | Scans `dist/` to ensure zero `.md`, `.lock`, `.sh`, `.ts`, `node_modules/`, or root `router.php` files exist, and verifies physical `dist/<route>/index.php` stubs exist. |
| **12** | **Ephemeral `dist/` Production Server Smoke Test** | Spawns `php -S 127.0.0.1:<port> -t dist` (without `router.php`) and verifies `200 OK` + `Content-Security-Policy` header + zero PHP fatal errors on every canonical route and `/legal/privacy/`, plus `301` across all configured legacy redirects. |
