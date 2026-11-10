<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content" class="site-shell" style="padding:5rem 0;text-align:center;">
    <p style="font-family:var(--font-mono);color:var(--color-muted);">HTTP 404</p>
    <h1>Page Not Found</h1>
    <p style="color:var(--color-muted);margin-bottom:2rem;">The requested resource could not be found on this server.</p>
    <a href="<?= e(u('/')) ?>" class="btn-primary">Return to Overview</a>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
