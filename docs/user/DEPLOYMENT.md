# Production Deployment Guide — `@astrake/lumora-webserver`

`@astrake/lumora-webserver` packages every website into a zero-dependency `dist/` bundle that runs on any PHP 8.2+ hosting environment (cPanel, LiteSpeed, aaPanel, Apache, or Nginx VPS) without requiring Node.js, Bun, or Composer in production.

---

## 1. Building & Verifying the Production Bundle

Before deploying, run `build` and `verify` locally or in CI:

```bash
bunx lumora-web build
bunx lumora-web verify
```

The generated `dist/` directory contains:
- `index.php`, `lumora.config.php`, `lumora-lock.json`
- `.htaccess` (Apache / LiteSpeed / cPanel rules)
- `nginx.lumora.conf` (Nginx / aaPanel snippet)
- `core/*.php` (All 13 headless PHP 8.2+ modules)
- `templates/`, `content/`, `public/`, `storage/` (with `403` `index.php` and `.htaccess` guards)
- Physical `<route>/index.php` stubs for every canonical route, statutory `/legal/{slug}/` route, `/docs/` article, `/blog/` article, and `301` redirect

---

## 2. Hosting-Specific Deployment Instructions

### 2.1. Apache / LiteSpeed / cPanel Shared Hosting
1. Upload the **contents** of `dist/` directly into your domain's webroot (e.g., `public_html/`).
2. `.htaccess` is active immediately:
   - Blocks direct HTTP access to `core/`, `storage/`, `templates/`, `content/`, `lumora.config.php`, and `lumora-lock.json` with `403 Forbidden`.
   - Serves `.woff2`, `.svg`, `.png`, `.webp`, `.css`, and `.js` with `Cache-Control: public, max-age=31536000, immutable`.
   - Routes dynamic URLs through `index.php` (and even if `mod_rewrite` is disabled, physical `<route>/index.php` stubs serve all canonical routes and `301` redirects).
3. Ensure `public_html/storage/` is writable by the PHP user (`chmod 0750 storage`).

### 2.2. Nginx / aaPanel / VPS Hosting
1. Point your Nginx `root` directive to the deployed `dist/` directory.
2. Include the generated `nginx.lumora.conf` inside your `server { ... }` block:
   ```nginx
   server {
       listen 443 ssl http2;
       server_name auruvi.com;
       root /var/www/auruvi/dist;
       index index.php;

       include /var/www/auruvi/dist/nginx.lumora.conf;

       location ~ \.php$ {
           include fastcgi_params;
           fastcgi_pass unix:/run/php/php8.2-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
       }
   }
   ```

### 2.3. Behind Cloudflare or Reverse Proxies
If your website sits behind Cloudflare or a local Nginx reverse proxy where `$_SERVER['REMOTE_ADDR']` is a proxy IP, enable `'trust_proxy_headers' => true` under `'security'` in `lumora.config.php` so pre-CSRF rate limiting (`5 / 900s`) hashes the visitor's real IP (`HTTP_CF_CONNECTING_IP` / `HTTP_X_REAL_IP` / `HTTP_X_FORWARDED_FOR`).

---

## 3. Production Secrets & Outbound Notifications

On production servers where you prefer not to set environment variables in web-server configs, place PHP secret files inside `storage/` (which is blocked from HTTP access and excluded from `lumora-web build` overwrites):

### 3.1. Standalone Edge SMTP & Self-Hosted WhatsApp (`storage/notify-config.php`)
```php
<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'email' => [
        'enabled'    => true,
        'transport'  => 'smtp',
        'to'         => 'desk@auruvi.com',
        'from_email' => 'noreply@auruvi.com',
        'smtp_host'  => 'smtp.auruvi.com',
        'smtp_port'  => 587,
        'smtp_user'  => 'noreply@auruvi.com',
        'smtp_pass'  => 'your-smtp-password',
        'smtp_tls'   => true,
    ],
    'whatsapp' => [
        'enabled'     => true,
        'provider'    => 'waha', // 'waha' | 'evolution' | 'webhook'
        'phone'       => '919830000000',
        'api_key'     => 'your-waha-api-key',
        'webhook_url' => 'https://wa.internal.astrake.com',
        'instance'    => 'default',
    ],
];
```

### 3.2. Central Communication Hub Secret (`storage/hub-secret.php`)
```php
<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return 'your-32-byte-minimum-hmac-sha256-shared-secret';
```

### 3.3. Replaying Pending Outbox Inquiries
If the Central Hub was temporarily unreachable during a visitor submission, the inquiry remains safely stored in `storage/vault-<hmac>/lumora.sqlite` with `sync_status = 'pending'`. Replay pending rows either via CLI cron:
```bash
bunx lumora-web flush --cwd /var/www/auruvi/dist
```
or remotely from your Central Hub by sending an HMAC-signed `POST /_lumora/outbox/flush` request with headers `X-Lumora-Timestamp` and `X-Lumora-Signature`.
