# Configuration Reference — `@astrake/lumora-webserver`

`@astrake/lumora-webserver` is 100% declarative. All brand identity, security controls, assets, inquiry channels, and statutory legal clauses are configured through PHP arrays in your website repository.

---

## 1. Root Configuration (`lumora.config.php`)

### 1.1. `brand` Section
| Key | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `id` | `string` | `'lumora'` | Lowercase machine identifier (e.g., `'acme'`, `'studio'`). Bound into v2 CSRF HMACs and Hub webhooks. |
| `prefix` | `string` | `'LUM'` | Uppercase prefix for inquiry receipts (`{PREFIX}-YYYYMMDD-{4HEX}`). |
| `name` | `string` | `'Lumora'` | Display name of the brand, person, or product. |
| `short_name` | `string` | `brand.name` | Short name emitted in `/site.webmanifest`. |
| `legal_entity` | `string` | `''` (optional) | Registered legal entity operating the website (e.g., `'Acme Studio LLC'`). |
| `expose_legal_entity_in_schema` | `bool` | `false` | Emits `legalName` on Schema.org `Organization` only when `true`. |
| `expose_legal_entity_in_llms` | `bool` | `false` | Mentions `legal_entity` in `/llms.txt` only when `true`. |
| `schema_org_type` | `'Organization'\|'Person'\|string` | `'Organization'` | Primary entity `@type` in Schema.org JSON-LD (`Organization`, `Person`, `LocalBusiness`, etc.). |
| `framework_label` | `string` | `'lumora-webserver'` | Framework label emitted in `/health`. |
| `domain` | `string` | `'localhost'` | Bare canonical domain (used for non-www redirects and SMTP `EHLO`). |
| `origin` | `string` | `'https://localhost'` | Full canonical origin URL without trailing slash (e.g., `'https://acme.example.com'`). |
| `version` | `string` | `'0.1.2'` | Product/website version string emitted in `/health` and legal headers. |
| `locale` | `string` | `'en'` | BCP-47 locale tag emitted in `<html lang>`, `hreflang`, and Schema.org `WebSite`. |
| `theme_color` | `string` | `'#0a0a0a'` | Browser theme color meta and webmanifest token. |
| `color_scheme` | `'light'\|'dark'` | `'light'` | `<meta name="color-scheme">` value. |
| `primary_offering_schema` | `bool` | `false` | When `true` and `schema_org_type !== 'Person'`, emits a `SoftwareApplication` node in Schema.org `@graph`. |
| `emit_brand_node` | `bool` | `false` | Emits a separate Schema.org `Brand` node when `true`. |
| `category` | `string` | `'BusinessApplication'` | Schema.org `SoftwareApplication.applicationCategory` when `primary_offering_schema` is `true`. |
| `slogan` | `string` | `''` | Tagline used in default page titles and Schema.org descriptions. |
| `description` | `string` | `brand.slogan` | Default meta description and `/llms.txt` summary. |
| `contact_email` | `string` | `'contact@' . domain` | Primary public contact and fallback notification address. |
| `security_email` | `string` | `''` | Contact email emitted in `/.well-known/security.txt` (enables `/security.txt` by default when set). |
| `same_as` | `string[]` | `[]` | Profile / organization URLs emitted in Schema.org `sameAs`. |
| `contact_points` | `array[]` | `[]` | Custom Schema.org `ContactPoint` objects overriding the default contact point. |
| `address` | `array` | `[]` | Optional `street`, `locality`, `region`, `postal_code`, `country` (empty fields omitted). |

### 1.2. `security` Section
| Key | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `canonical_https_redirect` | `bool` | `true` | Redirects plain HTTP requests to `https://{domain}{uri}` (`301`) on non-localhost. |
| `strip_www_redirect` | `bool` | `true` | Redirects `www.{domain}` to `{domain}` (`301`) on non-localhost. |
| `trust_proxy_headers` | `bool` | `false` | When `true`, `Security::clientIpHash()` reads `HTTP_CF_CONNECTING_IP`, `HTTP_X_REAL_IP`, or `HTTP_X_FORWARDED_FOR` before `REMOTE_ADDR`. Enable behind Cloudflare or local Nginx reverse proxies. |
| `csp_style_unsafe_inline` | `bool` | `true` | Includes `'unsafe-inline'` in `style-src` for dynamic inline style transforms. |
| `csp_media_self` | `bool` | `false` | Sets `media-src 'self'` when `true`, or `media-src 'none'` when `false`. |
| `rate_limit_max_attempts` | `int` | `5` | Maximum POST attempts per `ip_hash` within `rate_limit_window_sec` before pre-CSRF rejection. |
| `rate_limit_window_sec` | `int` | `900` | Rolling rate-limit window in seconds (default 15 minutes). |
| `csrf_ttl_sec` | `int` | `7200` | Stateless v2 CSRF token validity window in seconds (default 2 hours). |
| `compat_constants` | `string[]` | `[]` | Opt-in list of legacy constant names matching `/^[A-Z][A-Z0-9_]{2,63}$/` defined to `true` during `Security::boot()`. |

### 1.3. `assets`, `discovery`, `legal`, & `features` Sections
| Key | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `assets.compiled_css` | `string` | `'/public/assets/css/lumora.min.css'` | Compiled stylesheet path injected by `lumora_head_meta()`. |
| `assets.core_js` | `string` | `'/public/assets/js/lumora-core.js'` | Core client ES module injected by `lumora_core_script()`. |
| `assets.fonts` | `string[]` | `[]` | Array of `/public/fonts/*.woff2` paths to preload with SHA-384 `integrity`. |
| `assets.vendor_js` | `string[]` | `[]` | Optional vendored JS files to include in `lumora-lock.json` SRI calculation. |
| `discovery.robots_txt` | `bool` | `true` | Enables `/robots.txt`. |
| `discovery.sitemap_xml` | `bool` | `true` | Enables `/sitemap.xml`. |
| `discovery.llms_txt` | `bool` | `true` | Enables `/llms.txt` and `<link rel="alternate">` in `lumora_head_meta()`. |
| `discovery.llms_full_txt` | `bool` | `true` | Enables `/llms-full.txt` and `<link rel="alternate">` in `lumora_head_meta()`. |
| `discovery.security_txt` | `bool` | auto | Defaults `true` only if `brand.security_email` is set or `.well-known/security.txt` exists; set explicit `bool` to override. |
| `discovery.webmanifest` | `bool` | `true` | Enables `/site.webmanifest` and `<link rel="manifest">`. |
| `legal.territories` | `string[]` | `['GLOBAL']` | Opt-in statutory clause profiles (`'IN'`, `'EU'`, `'UK'`, `'US'`, `'GLOBAL'`). |
| `legal.slugs` | `string[]` | auto (`['privacy', 'terms']` + `content/legal/*.php`) | Explicit list of active `/legal/{slug}/` routes. |
| `legal.include_default_slugs` | `bool` | `true` | Includes `'privacy'` and `'terms'` when `legal.slugs` is omitted. |
| `legal.retention_days_pii` | `int\|null` | `90` | Days before `InquiryDb::prune()` redacts PII (`0` or `null` disables redaction). |
| `legal.retention_days_logs` | `int\|null` | `180` | Days before `InquiryDb::prune()` deletes old rate-limit/log entries (`0` disables purge). |
| `features.legal_registry` | `bool` | `true` | Disables all `/legal/*` routes when `false`. |
| `features.inquiry_engine` | `bool` | `true` | Controls inquiry engine checks during `lumora-web verify`. |

### 1.4. `hub` & `notifications` Sections
| Key | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `hub.enabled` | `bool` | `false` | Enables HMAC-SHA256 server-to-server webhook dispatch on form submission. |
| `hub.endpoint` | `string` | `''` | Target ingest URL (e.g., `'https://hub.example.com/api/v1/lumora/inquiries/ingest'`). |
| `hub.shared_secret` | `string` | `''` | HMAC signing secret (can also be supplied via `LUMORA_HUB_SECRET` or `storage/hub-secret.php`). |
| `hub.timeout_ms` | `int` | `1500` | HTTP timeout in milliseconds (`500`–`3000`). |
| `hub.fallback_notify` | `bool` | `true` | Runs standalone Edge SMTP/WhatsApp notifications if the Hub is disabled or unreachable. |

---

## 2. Declarative Inquiry Channels (`content/channels.php`)

`InquiryEngine` validates every submission against `content/channels.php`:
- **`default_channel`**: Fallback channel key when no `channel` or `topic` parameter is provided (default `'general'`).
- **`honeypot_mode`**:
  - `'reject'`: Returns an error when hidden `website_url` is filled.
  - `'fake_receipt'`: Returns a synthetic `200 OK` receipt (`{PREFIX}-YYYYMMDD-{4HEX}`) without touching SQLite or dispatching notifications.
- **`aliases`**: Maps query/post aliases (e.g., `'dpdp' => 'privacy'`, `'gdpr' => 'privacy'`) to canonical channel keys.
- **`referer_map`**: Hydrates the default channel from `HTTP_REFERER` substrings (e.g., `'/legal/security' => 'security'`).
- **`channels.<key>.fields.<fieldName>`**:
  - `type`: `'string'` | `'enum'` | `'url'`
  - `required`: `bool`
  - `required_if`: `array<string, string|string[]>` (e.g., `['consultation_mode' => 'onsite']`)
  - `options`: `string[]` (required when `type === 'enum'`)
  - `min_length` / `max_length`: `int`
  - `receipt_label`: Uppercase label displayed on the user's confirmation receipt and in dispatch summaries.
  - `error_message`: Custom validation message returned in `$inquiry['field_errors'][$fieldName]`.

---

## 3. Custom Legal Documents & Territory Clauses (`content/legal/{slug}.php`)

By default, `LegalRegistry` serves neutral global documents at `/legal/privacy/` and `/legal/terms/` (`legal.territories = ['GLOBAL']`). Websites can customize slugs via `legal.slugs`, add arbitrary custom slugs under `content/legal/{slug}.php`, disable legal routes altogether with `'features' => ['legal_registry' => false]`, or opt in to regional statutory templates (`'IN'`, `'EU'`, `'UK'`, `'US'`) with built-in layouts for `privacy`, `terms`, `cookies`, `accessibility`, `security`, and `company`.

To create a standalone custom page (such as `content/legal/refund-policy.php`), omit `'merge_territory'` and return `'sections' => [...]`. To enrich a built-in template with regional statutory clauses, create `content/legal/{slug}.php` with `'merge_territory' => true`:
- `prepend_sections`: Array of `['id' => '...', 'title' => '...', 'body' => '<p>...</p>']` inserted before baseline sections.
- `replace_sections`: Associative map of `'section_id' => ['title' => '...', 'body' => '...']` replacing specific baseline sections.
- `append_sections`: Array of sections appended after baseline sections.
- Set `'merge_territory' => false` (or omit it while providing `'sections' => [...]`) to completely replace the generated baseline.

---

## 4. Environment Variables Reference

| Variable | Purpose |
| :--- | :--- |
| `LUMORA_CSRF_SECRET` | 32+ character secret key for stateless v2 CSRF tokens, `ip_hash`, and vault directory derivation. |
| `LUMORA_HUB_ENDPOINT` | Central Communication Hub ingest URL. |
| `LUMORA_HUB_SECRET` | Shared secret for `X-Lumora-Signature` HMAC-SHA256 webhook signing and `/_lumora/outbox/flush` verification. |
| `LUMORA_FORCE_PRODUCTION` | Set to `'1'` to enforce production behavior (error suppression, `upgrade-insecure-requests`, draft hiding) on localhost. |
| `LUMORA_RELEASES_DIR` | Custom directory path for binary artifacts served by `ReleaseServer` (`/releases/*`). |
| `LUMORA_NOTIFY_EMAIL_ENABLED` | `'true'` / `'false'` to enable standalone Edge SMTP/mail notifications. |
| `LUMORA_SMTP_HOST` / `PORT` / `USER` / `PASS` | Standalone Edge SMTP socket credentials (`port` `587` STARTTLS or `465` implicit TLS). |
| `LUMORA_WA_ENABLED` / `PROVIDER` / `PHONE` / `APIKEY` / `WEBHOOK_URL` | Standalone Edge self-hosted WhatsApp notification settings (`waha`, `evolution`, or `webhook`). |
