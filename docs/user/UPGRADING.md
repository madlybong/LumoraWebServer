# Upgrading Existing Websites to `@astrake/lumora-webserver` v0.2.0

This guide is the authoritative, zero-hallucination upgrade playbook for upgrading any consumer brand website from `0.1.0`, `0.1.1`, or `0.1.2` to **`v0.2.0`** — both for manual developers and for AI coding agents (Antigravity, Cursor, Claude Code, Windsurf).

---

## 1. Compatibility Matrix & What Changed (`0.1.x` → `0.2.0`)

`v0.2.0` is **100% backward-compatible** with `0.1.0`, `0.1.1`, and `0.1.2` consumer websites:

- Your existing templates (`templates/*.php`), CSS tokens (`css/brand-theme.css`, `css/brand.css`), config (`lumora.config.php`), routes (`content/routes.php`), inquiry channels (`content/channels.php`), legal addenda (`content/legal/*.php`), documentation (`content/docs/*`), blog posts (`content/blog/*.php`), and release manifests (`releases/*`) continue working unchanged.
- The locked PHP 8.2+ micro-kernel in `core/*.php` expands from **13** to **17** modules by adding 4 opt-in Admin & Hybrid CMS modules:
  - `core/admin-db.php` (`Lumora\Core\AdminDb`) — zero-write public GET fast-path (`connectExisting()`), Hybrid CMS tables, revision snapshots, and OpenSSL `aes-256-gcm` encrypted settings vault.
  - `core/admin-auth.php` (`Lumora\Core\AdminAuth`) — Argon2id auth, RBAC (`owner` > `editor` > `viewer`), User-Agent-bound sessions, RFC 6238 TOTP 2FA with replay protection & recovery codes, and brute-force lockout (`429`).
  - `core/admin-sanitizer.php` (`Lumora\Core\AdminSanitizer`) — DOMDocument XSS allowlist scrubber, Gate 06 `<img>` attribute enforcement, and polyglot-safe image uploads (`public/uploads/`).
  - `core/admin-controller.php` (`Lumora\Core\AdminController`) — stealth `admin.path` SPA host and JSON API powering the AOT Vue 3 + CodeMirror 6 Admin Console.
- Root stubs (`router.php`, `.htaccess`, `index.php`) were updated in `0.2.0` to enforce `public/uploads/` script isolation and route release/admin endpoints cleanly — pass `--force-stubs` during `lumora-web sync` to refresh them.

---

## 2. Choose Your Upgrade Path

### Path A — Core-Only Upgrade (Keep Admin Console Disabled)

Use this path if you want all security, router, CSS/CLI, and verifier upgrades in `0.2.0` while keeping `features.admin_panel => false` (the default):

```bash
bun add -D @astrake/lumora-webserver@^0.2.0
bunx lumora-web sync --force-stubs --with-agents
bunx lumora-web css
bunx lumora-web build
bunx lumora-web verify
```

### Path B — Full Upgrade + Enable the Admin Console & Hybrid SQLite CMS (`--with-admin`)

Use this path to upgrade the core runtime AND automatically enable the opt-in Admin Console (`/admin/`, or custom `admin.path`):

```bash
bun add -D @astrake/lumora-webserver@^0.2.0
bunx lumora-web sync --with-admin --force-stubs --with-agents
bunx lumora-web css
bunx lumora-web build
bunx lumora-web verify
```

Running `lumora-web sync --with-admin --force-stubs --with-agents` non-destructively performs every required wiring step in one command:
1. Materializes all **17** locked PHP 8.2+ modules into `core/*.php` and updates `public/assets/js/lumora-core.js`.
2. Refreshes root `index.php`, `router.php`, and `.htaccess` (`--force-stubs`) so `/admin/`, `/admin/api/`, and `public/uploads/` security rules are active.
3. Refreshes `AGENTS.md`, `.cursorrules`, and `UPGRADING.md` (`--with-agents`).
4. Patches your existing `lumora.config.php` non-destructively:
   - Sets `'features' => [ ..., 'admin_panel' => true ]`.
   - Adds a default `'admin' => [ 'path' => '/admin/', 'branding' => [...] ]` block if missing.
5. Adds `"admin": "lumora-web admin"` to your `package.json` `"scripts"` without overwriting existing scripts.
6. Copies AOT-compiled `public/assets/admin/lumora-admin.js` and `public/assets/admin/lumora-admin.css` and locks their SHA-256 + SHA-384 SRI hashes in `lumora-lock.json`.
7. Hardens `public/uploads/.htaccess` and `public/uploads/index.html` to block script/PHP/SVG execution inside uploads.

> [!NOTE]
> `lumora-web sync` updates **only** framework-managed files, `lumora.config.php` admin flags (when `--with-admin` is passed), `package.json` `"admin"` script, and agent instruction docs. It never overwrites your `templates/`, `css/`, `content/`, or `storage/`.

---

## 3. Provisioning Your First Admin Operator (When Admin Console Is Enabled)

After running Path B, choose any of the 3 sanctioned methods to provision your first `owner` account:

### Option 1 — Local Development (`lumora-web dev`)
Run the local development server:
```bash
bunx lumora-web dev --port 8080
```
If no `owner` exists yet in SQLite and `LUMORA_ADMIN_SETUP_TOKEN` is unset, `lumora-web dev` generates an ephemeral session setup token automatically and prints your local Admin Console URL in the terminal. Open `/admin/` and complete initial Owner Setup in the UI.

### Option 2 — Direct CLI Provisioning (`lumora-web admin create-user`)
Create an Owner account directly into `storage/vault-<hmac>/lumora.sqlite`:
```bash
bunx lumora-web admin create-user \
  --email "admin@example.com" \
  --name "Site Owner" \
  --role owner \
  --password "ReplaceWithStrongPassword123!"
```
> Omit `--password` to have Lumora generate a cryptographically random password and print it once in stdout.

Other operator maintenance subcommands:
- `bunx lumora-web admin list-users`
- `bunx lumora-web admin reset-password --email "admin@example.com"`
- `bunx lumora-web admin disable-2fa --email "admin@example.com"`
- `bunx lumora-web admin revoke-sessions`
- `bunx lumora-web admin hash-password --password "..."` (prints `$argon2id$...` for `admin.initial_owner` in `lumora.config.php`)

### Option 3 — Production Environment Secret (`LUMORA_ADMIN_SETUP_TOKEN`)
On remote shared hosting or VPS before opening `/admin/` for the first time, set `LUMORA_ADMIN_SETUP_TOKEN` (minimum 24 characters) in your server environment, visit `https://your-domain.com/admin/`, complete Owner Setup once, and remove `LUMORA_ADMIN_SETUP_TOKEN` afterward.

---

## 4. Optional Template Enhancements for Existing Websites

Your existing `templates/` pass all 12 Compliance Gates immediately after upgrade. If you also want convenient UI shortcuts in your website templates:

### 4.1. Localhost-Only Header/Footer Admin Console Shortcut
Expose an Admin Console link strictly on localhost (`Security::isLocalhost()`) so production visitors never see hidden admin paths:
```php
<?php
use Lumora\Core\AdminDb;
use Lumora\Core\Security;

$showAdminLink = Security::isLocalhost() && !empty($config['features']['admin_panel']);
$adminHref = $showAdminLink ? u(AdminDb::normalizeAdminPath($config ?? [])) : '';
?>
<?php if ($showAdminLink): ?>
    <a href="<?= e($adminHref) ?>" class="lumora-badge lumora-badge-accent">Admin Console</a>
<?php endif; ?>
```

### 4.2. Customizing Admin Mount Path & Branding
In `lumora.config.php`, customize `admin.path` and branding without changing code:
```php
'admin' => [
    'path'               => '/_console/', // Normalized automatically; excluded from sitemap.xml & robots.txt
    'session_ttl_sec'    => 28800,
    'require_2fa'        => true,         // Require RFC 6238 TOTP 2FA on all operators
    'allowed_roles'      => ['owner', 'editor', 'viewer'],
    'branding'           => [
        'title'         => 'Acme Admin',
        'logo'          => '/public/brand/icon.svg',
        'logo_dark'     => '/public/brand/icon-dark.svg',
        'default_theme' => 'system',      // 'light' | 'dark' | 'system'
    ],
],
```
Re-run `bunx lumora-web build && bunx lumora-web verify` after changing `admin.path` so `dist/<admin.path>/index.php` stubs regenerate cleanly.

---

## 5. How the Hybrid SQLite CMS Interacts with Existing Flat Files

- **Zero-Write Public Fast-Path:** Until you create an admin account or save settings inside the Admin Console, `AdminDb::connectExisting()` checks whether `storage/csrf-secret.php` and an initialized vault SQLite DB exist. Public `GET` requests perform **zero** SQLite writes on uninitialized sites.
- **SQLite Layering Over Flat Files:**
  - **Blog (`BlogEngine`):** Merges SQLite `cms_blog_posts` on top of `content/blog/*.php` by `slug`, respecting draft guards and `/blog/feed.xml`.
  - **Docs (`DocsEngine`):** Merges `cms_doc_tracks` and `cms_doc_articles` on top of `content/docs/manifest.json` + `.md` files (with GitHub alerts, tables, and TOC).
  - **Legal (`LegalRegistry`):** Merges `cms_legal_docs` over `content/legal/{slug}.php` and statutory baselines.
  - **Channels (`InquiryEngine`):** Merges `cms_channels` over `content/channels.php` field rules and custom receipt labels.
  - **Routing & Discovery (`Router`, `Discovery`):** Combines `content/routes.php` + `cms_redirects`, logs unresolved 404 hits into `cms_404_logs`, and applies optional Discovery/Route SEO overrides stored in SQLite.
  - **Revisions & Revert:** Every saved edit in Blog, Docs, Legal, and Channels records a snapshot in `cms_revisions`; any flat-file-backed entry edited in SQLite can be reverted back to the repository file at any time.

---

## 6. Copy-Pasteable AI Agent Upgrade Prompt (Zero-Hallucination)

If you use an AI coding agent (Antigravity, Cursor, Claude Code, Windsurf) inside your consumer website repository, paste the prompt below verbatim:

```text
Upgrade this Lumora consumer website repository to @astrake/lumora-webserver@^0.2.0 and enable the new Admin Console + Hybrid SQLite CMS cleanly without breaking existing templates, routes, or content.

Follow these exact rules from node_modules/@astrake/lumora-webserver/UPGRADING.md and AGENTS.md:
1. Run `bun add -D @astrake/lumora-webserver@^0.2.0` (or `bun add` if already in `dependencies`).
2. Read `node_modules/@astrake/lumora-webserver/UPGRADING.md`, `AGENTS.md`, and `lumora.config.php` before making manual edits.
3. Run `bunx lumora-web sync --with-admin --force-stubs --with-agents` so `core/*.php` (17 modules), `lumora.config.php` (`features.admin_panel` + `admin`), `router.php`, `.htaccess`, `package.json`, `public/assets/admin/`, `public/uploads/`, and `lumora-lock.json` are synced deterministically.
4. Run `bunx lumora-web css`, `bunx lumora-web build`, and `bunx lumora-web verify`.
5. Never hand-edit files inside `core/`, `public/assets/js/lumora-core.js`, or `public/assets/admin/*` — let `lumora-web sync` manage them.
6. Ensure every PHP file modified or created keeps `declare(strict_types=1);` and the `LUMORA_BOOTSTRAPPED` 403 guard (Gates 02 & 03), zero third-party CDNs/trackers (Gate 05), and `width`/`height`/`alt` on every `<img>` in `templates/` (Gate 06).
7. Report the exact output of `bunx lumora-web verify` (must be 12/12 passing gates) and summarize what files changed.
```

### Verification Checklist for Upgraded Foreign Projects
- [ ] `bunx lumora-web verify` exits `0` with **12/12** compliance gates passing.
- [ ] `lumora-lock.json` shows `"version": "0.2.0"` and 17 `/core/*.php` hashes + `/public/assets/admin/lumora-admin.{js,css}` SRI hashes (when `admin_panel` is `true`).
- [ ] `dist/` contains physical `<admin.path>/index.php` and `<admin.path>/api/index.php` stubs (when `admin_panel` is `true`).
- [ ] All custom routes in `content/routes.php`, `templates/`, and `content/` render identically (`GET /` `200 OK`).
