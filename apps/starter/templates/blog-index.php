<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\Core\BlogEngine;

$pageNum = (int)($page_num ?? 1);
$paginated = BlogEngine::paginate($pageNum, 10);

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content" class="site-shell" style="padding:3rem 0;">
    <h1>Engineering Dispatches</h1>
    <p style="color:var(--color-muted);margin-bottom:2rem;">
        Architectural notes and release engineering updates. <a href="<?= e(u('/blog/feed.xml')) ?>">Atom 1.0 Feed</a>
    </p>
    <?php foreach ($paginated['items'] as $post): ?>
        <article class="card-surface">
            <p style="font-family:var(--font-mono);font-size:0.75rem;color:var(--color-muted);margin:0 0 0.25rem;">
                <?= e($post['date'] ?? '') ?> · <?= e($post['author'] ?? ($config['brand']['name'] ?? 'Maintainers')) ?>
            </p>
            <h2 style="margin:0 0 0.5rem;">
                <a href="<?= e(u('/blog/' . ($post['slug'] ?? '') . '/')) ?>" style="color:inherit;text-decoration:none;">
                    <?= e($post['title'] ?? '') ?>
                </a>
            </h2>
            <p style="margin:0;color:var(--color-muted);"><?= e($post['summary'] ?? '') ?></p>
        </article>
    <?php endforeach; ?>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
