<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-16 flex-1 flex items-center justify-center">
    <div class="lumora-card p-8 sm:p-12 max-w-lg w-full text-center space-y-5">
        <span class="lumora-badge lumora-badge-warning font-mono">HTTP 404</span>
        <h1 class="text-3xl font-bold tracking-tight">Page Not Found</h1>
        <p class="text-sm sm:text-base text-[var(--lumora-fg-muted)]">
            The requested resource could not be located on this server.
        </p>
        <div class="flex flex-wrap items-center justify-center gap-3 pt-2">
            <a href="<?= e(u('/')) ?>" class="lumora-btn lumora-btn-primary">Return to Overview</a>
            <a href="<?= e(u('/docs/')) ?>" class="lumora-btn lumora-btn-secondary">Browse Documentation</a>
        </div>
    </div>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
