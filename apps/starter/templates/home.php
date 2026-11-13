<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content" class="site-shell">
    <section class="hero-section">
        <p style="font-family:var(--font-mono);font-size:0.8125rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-muted);">
            @astrake/lumora-webserver · v<?= e($config['brand']['version'] ?? '0.1.2') ?>
        </p>
        <h1 style="font-size:clamp(2.25rem,4vw,3.5rem);line-height:1.1;margin:0.5rem 0 1rem;">
            <?= e($config['brand']['slogan'] ?? 'Enterprise Website Micro-Kernel') ?>
        </h1>
        <p style="max-width:68ch;color:var(--color-muted);font-size:1.125rem;margin-bottom:2rem;">
            <?= e($config['brand']['description'] ?? '') ?>
        </p>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;">
            <a href="<?= e(u('/contact/')) ?>" class="btn-primary">Send Enterprise Inquiry</a>
            <a href="<?= e(u('/docs/')) ?>" class="card-surface" style="margin:0;padding:0.75rem 1.5rem;text-decoration:none;color:inherit;font-weight:600;">Read Documentation</a>
        </div>
    </section>

    <section aria-labelledby="pillars-heading" style="padding-bottom:4rem;">
        <h2 id="pillars-heading">Architectural Pillars</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.5rem;">
            <article class="card-surface">
                <h3>OWASP ASVS v4.0 L2 Security</h3>
                <p>128-bit CSPRNG CSP nonces, SHA-384 Subresource Integrity (SRI) lockfiles, and CGNAT-resilient stateless v2 HMAC-SHA256 CSRF protection.</p>
            </article>
            <article class="card-surface">
                <h3>Sub-Millisecond SQLite WAL Vault</h3>
                <p>Local-first ACID SQLite3 WAL persistence with atomic JSONL failover, pre-CSRF 5/900s rate limiting, and optional HMAC-signed Hub sync.</p>
            </article>
            <article class="card-surface">
                <h3>Zero-Dependency dist/ Builds</h3>
                <p>Compiles Tailwind CSS v4 + brand themes and emits physical index.php route stubs for Apache, Nginx, cPanel, and aaPanel.</p>
            </article>
        </div>
    </section>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
