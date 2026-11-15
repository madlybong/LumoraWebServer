# Security & Micro-Kernel Architecture

`@astrake/lumora-webserver` enforces defense-in-depth defaults across HTTP headers, filesystem boundaries, CSRF verification, SQLite persistence, and asset integrity.

## 1. Per-Request CSP Nonces & Zero-Tracker Surface

Every HTML response generates a fresh 128-bit CSPRNG Content Security Policy nonce via `lumora_csp_nonce()`:

- `script-src 'self' 'nonce-...'` — Only first-party scripts stamped with the active request nonce execute. No runtime expression evaluators, inline event handlers, or third-party script hosts are permitted.
- `object-src 'none'`, `frame-ancestors 'none'`, `base-uri 'self'` — Clickjacking and base-tag hijacking are neutralized at the browser policy layer.
- `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`, and restrictive `Permissions-Policy` ship on every response.

## 2. Subresource Integrity (`lumora-lock.json`)

Compiled assets (`lumora.min.css`, `lumora-core.js`, `lumora-admin.css`, `lumora-admin.js`) are hashed with SHA-384 during `lumora-web sync`, `lumora-web css`, and `lumora-web build`. Templates emit `integrity="sha384-..." crossorigin="anonymous"` via `asset_sri()`, guaranteeing tamper detection across CDNs and reverse proxies.

## 3. CGNAT-Safe v2 CSRF & SQLite WAL Vault

- **Stateless v2 CSRF Tokens:** Signed with an isolated storage HMAC secret (`storage/csrf-secret.php` or `LUMORA_CSRF_SECRET`) bound to a 15-minute sliding validity window so mobile CGNAT IP rotations never break legitimate submissions.
- **HMAC-Derived Storage Vault:** Sensitive databases and inquiry failover queues live inside `storage/vault-<hmac32>/lumora.sqlite` guarded by `0750`/`0640` permissions, HTTP `403` stub guards, and SQLite Write-Ahead Logging (`WAL`).
- **Pre-CSRF Rate Limiting:** Excessive POST bursts fail early (HTTP `429`) before expensive downstream processing occurs.

## 4. Hybrid Flat-File + SQLite CMS

Both `DocsEngine` and `BlogEngine` merge version-controlled flat files under `content/` with dynamic entries authored in the `/admin/` Console:

- Flat files remain cleanly deployable in Git without database dumps.
- SQLite entries in `cms_docs_tracks`, `cms_docs_articles`, `cms_blog_posts`, `cms_channels`, and `cms_releases` layer on top deterministically without schema drift.
- Draft articles remain restricted exclusively to `Security::isLocalhost()` requests and never leak into production sitemaps or Atom XML feeds.
