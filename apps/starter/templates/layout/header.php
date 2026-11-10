<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<header class="site-header">
    <div class="site-shell" style="display:flex;align-items:center;justify-content:space-between;width:100%;">
        <a href="<?= e(u('/')) ?>" style="display:inline-flex;align-items:center;gap:0.75rem;text-decoration:none;color:inherit;font-weight:700;">
            <img src="<?= e(asset_url('/public/brand/icon.svg')) ?>" alt="Lumora Icon" width="32" height="32" />
            <span><?= e($config['brand']['name'] ?? 'Lumora WebStarter') ?></span>
        </a>
        <nav class="site-nav" aria-label="Primary Navigation">
            <a href="<?= e(u('/')) ?>">Overview</a>
            <a href="<?= e(u('/docs/')) ?>">Docs</a>
            <a href="<?= e(u('/blog/')) ?>">Blog</a>
            <a href="<?= e(u('/legal/')) ?>">Legal</a>
            <a href="<?= e(u('/contact/')) ?>" class="btn-primary">Contact</a>
        </nav>
    </div>
</header>
