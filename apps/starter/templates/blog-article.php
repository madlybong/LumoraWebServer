<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

$post = is_array($post_data ?? null) ? $post_data : [];
$postTitle = (string)($post['title'] ?? 'Article');
$bodyHtml = (string)($post['content'] ?? $post['body'] ?? '');
$coverImg = trim((string)($post['image'] ?? ''));
$postTags = is_array($post['tags'] ?? null) ? $post['tags'] : [];
$readTimeText = (string)($post['reading_time'] ?? (reading_time($bodyHtml) . ' min read'));

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-10 sm:py-12 flex-1 max-w-4xl">
    <nav aria-label="Breadcrumb" class="mb-6">
        <a href="<?= e(u('/blog/')) ?>" class="text-sm font-medium text-[var(--lumora-accent-fg)] hover:underline">
            &larr; All Engineering Dispatches
        </a>
    </nav>

    <article class="lumora-card overflow-hidden">
        <?php if ($coverImg !== ''): ?>
            <img
                src="<?= e(asset_url($coverImg)) ?>"
                alt="<?= e($postTitle) ?>"
                width="1200"
                height="630"
                class="w-full h-auto aspect-[1200/630] object-cover border-b border-[var(--lumora-border)]"
            />
        <?php endif; ?>

        <div class="p-6 sm:p-10 space-y-6">
            <div class="space-y-4 border-b border-[var(--lumora-border)] pb-6">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="lumora-badge lumora-badge-accent"><?= e((string)($post['category'] ?? 'Engineering')) ?></span>
                    <?php if (($post['status'] ?? 'published') === 'draft'): ?>
                        <span class="lumora-badge lumora-badge-warning font-mono">LOCALHOST DRAFT</span>
                    <?php endif; ?>
                    <span class="lumora-badge font-mono">source: <?= e((string)($post['source_origin'] ?? 'file')) ?></span>
                </div>

                <h1 class="text-2xl sm:text-4xl font-bold tracking-tight text-[var(--lumora-fg)]">
                    <?= e($postTitle) ?>
                </h1>

                <p class="text-xs sm:text-sm font-mono text-[var(--lumora-fg-muted)]">
                    <?= e((string)($post['date'] ?? '')) ?> · <?= e((string)($post['author'] ?? ($config['brand']['name'] ?? 'Maintainers'))) ?> · <?= e($readTimeText) ?>
                </p>
            </div>

            <div class="prose-lumora">
                <?= $bodyHtml ?>
            </div>

            <?php if (!empty($postTags)): ?>
                <div class="pt-6 border-t border-[var(--lumora-border)] flex flex-wrap items-center gap-2">
                    <span class="text-xs font-mono text-[var(--lumora-fg-muted)]">Tags:</span>
                    <?php foreach ($postTags as $tag): ?>
                        <span class="lumora-badge font-mono">#<?= e((string)$tag) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
