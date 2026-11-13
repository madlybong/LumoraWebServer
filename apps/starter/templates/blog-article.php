<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content" class="site-shell" style="padding:3rem 0;">
    <article class="card-surface">
        <p style="font-family:var(--font-mono);font-size:0.75rem;color:var(--color-muted);">
            <?= e($post_data['date'] ?? '') ?> · <?= e($post_data['author'] ?? ($config['brand']['name'] ?? 'Maintainers')) ?> · <?= reading_time((string)($post_data['body'] ?? '')) ?> min read
        </p>
        <h1><?= e($post_data['title'] ?? '') ?></h1>
        <div><?= $post_data['body'] ?? '' ?></div>
    </article>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
