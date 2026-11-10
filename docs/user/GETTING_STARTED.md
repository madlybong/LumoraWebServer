# Getting Started with `@astrake/lumora-webserver`

This guide walks you through scaffolding, customizing, verifying, and building a brand website with `@astrake/lumora-webserver`.

---

## 1. Prerequisites

- **PHP 8.2+** with `pdo_sqlite`, `curl`, and `mbstring` enabled (`php -v`).
- **Bun 1.3+** (`bun -v`) for running the `lumora-web` build, CSS compilation, and 12-Gate verification CLI.

---

## 2. Scaffolding a New Brand Website

Create a new directory, initialize `package.json`, install `@astrake/lumora-webserver`, and run `lumora-web init`:

```bash
mkdir my-brand-website
cd my-brand-website
bun init -y
bun add -D @astrake/lumora-webserver
bunx lumora-web init \
  --brand-id "auruvi" \
  --brand-name "Auruvi HMS" \
  --prefix "AUR" \
  --domain "auruvi.com" \
  --entity "Astrake India"
```

### What `lumora-web init` Creates
- `lumora.config.php`: Pre-populated brand identity, security, asset, Hub, legal, and feature flags.
- `index.php`, `router.php`, `.htaccess`: Canonical front controller, local `php -S` dev router, and Apache/cPanel security rules.
- `core/*.php`: All 16 headless PHP 8.2+ runtime modules.
- `lumora-lock.json`: Cryptographic SHA-256 core checksums and SHA-384 asset SRI hashes.
- `css/brand-theme.css` & `css/brand.css`: Brand design tokens and component styles compiled into `public/assets/css/lumora.min.css`.
- `content/routes.php` & `content/channels.php`: Initial route table and inquiry channel definitions.
- `templates/`: Starter templates (`layout/head.php`, `layout/header.php`, `layout/footer.php`, `home.php`, `contact.php`, `legal.php`, `404.php`).
- `package.json`, `.gitignore`, `AGENTS.md`, `.cursorrules`, and `LUMORA_SETUP.md`: Non-destructively merges standard `lumora-web` scripts (`sync`, `css`, `dev`, `build`, `verify`, `flush`), ignore rules, and AI coding rules.

### Refactoring an Existing Website Project
To refactor an existing website repository in-place without clobbering existing brand templates or routes:

```bash
cd /path/to/existing-website
bun add -D @astrake/lumora-webserver
bunx lumora-web init --with-agents --brand-id "astrake" --brand-name "Astrake" --prefix "AST" --domain "astrake.com"
bunx lumora-web sync --force-stubs --with-agents
bunx lumora-web css
bunx lumora-web build
bunx lumora-web verify
```

---

## 3. Running the Development Server

Start the local PHP 8.2+ development server with live Tailwind CSS v4 + SRI watching:

```bash
bunx lumora-web dev --port 8080
```

Open `http://127.0.0.1:8080/` in your browser:
- `/` renders `templates/home.php`
- `/contact/` renders `templates/contact.php` (with live CSRF, honeypot, and SQLite WAL persistence)
- `/legal/privacy/`, `/legal/terms/`, `/legal/cookies/`, `/legal/accessibility/`, `/legal/security/`, `/legal/company/` render statutory legal disclosures via `templates/legal.php`
- `/health`, `/robots.txt`, `/sitemap.xml`, `/llms.txt`, `/llms-full.txt`, `/.well-known/security.txt`, and `/site.webmanifest` are served automatically

---

## 4. Authoring Templates & Components

Every PHP template in `templates/` must start with the strict-types declaration and `LUMORA_BOOTSTRAPPED` guard:

```php
<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['config' => $config ?? [], 'currentPath' => $currentPath ?? '/']);
?>
<main id="main-content" class="mx-auto max-w-6xl px-6 py-16">
    <h1 class="text-4xl font-bold"><?= e($config['brand']['name'] ?? '') ?></h1>
    <p class="mt-4 text-lg"><?= e($config['brand']['slogan'] ?? '') ?></p>
    <img
        src="<?= e(asset_url('/public/brand/icon.svg')) ?>"
        alt="<?= e($config['brand']['name'] ?? 'Brand Icon') ?>"
        width="64"
        height="64"
    />
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
```

> [!IMPORTANT]
> Every `<img>` element in `templates/**/*.php` must include explicit `width`, `height`, and `alt` attributes to pass **Gate 06**.

---

## 5. Adding Documentation & Blog Content

### 5.1. Enabling the Documentation Engine (`/docs/`)
1. Set `'docs_engine' => true` under `'features'` in `lumora.config.php`.
2. Add a `'/docs/'` route in `content/routes.php` pointing to `'template' => 'docs'`.
3. Create `content/docs/manifest.json` and place your `.md` files in `content/docs/`:
   ```json
   {
     "version": "1.0.0",
     "tracks": {
       "operator": {
         "id": "operator",
         "title": "Operator Guide",
         "articles": [
           {
             "slug": "installation",
             "title": "On-Premise Installation",
             "summary": "Deploying the appliance on facility LAN.",
             "file": "installation.md",
             "tags": ["LAN", "Setup"]
           }
         ]
       }
     }
   }
   ```
4. Both `/docs/installation/` and `/docs/operator/installation/` automatically resolve and inject `$doc_article` (`html`, `toc`, `title`, `summary`, `reading_time`) and `$doc_manifest` into your template.

### 5.2. Enabling the Blog Engine (`/blog/` & `/blog/feed.xml`)
1. Set `'blog_engine' => true` under `'features'` in `lumora.config.php`.
2. Add a `'/blog/'` route in `content/routes.php` pointing to `'template' => 'blog-index'`.
3. Create PHP post files in `content/blog/{slug}.php`:
   ```php
   <?php
   declare(strict_types=1);

   if (!defined('LUMORA_BOOTSTRAPPED')) {
       http_response_code(403);
       exit('Forbidden');
   }

   return [
       'title'   => 'Zero-Surveillance Web Architecture',
       'date'    => '2026-11-10',
       'status'  => 'published', // Use 'draft' for localhost-only preview
       'author'  => 'Engineering Team',
       'summary' => 'How stateless HMAC CSRF eliminates session cookies.',
       'tags'    => ['Security', 'Architecture'],
       'body'    => '<p>Article HTML content...</p>',
   ];
   ```

---

## 6. Building & Verifying for Production

Run the production build and 12-Gate compliance verifier before committing or deploying:

```bash
bunx lumora-web build
bunx lumora-web verify
```
