<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\Core\BlogEngine;

$pageNum = max(1, (int)($page_num ?? ($_GET['page'] ?? 1)));
$paginated = BlogEngine::paginate($pageNum, 6);
$items = is_array($paginated['items'] ?? null) ? $paginated['items'] : [];
$totalPages = max(1, (int)($paginated['total_pages'] ?? 1));

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-10 sm:py-12 flex-1 space-y-8">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 border-b border-[var(--lumora-border)] pb-6">
        <div>
            <p class="text-xs font-mono uppercase tracking-wider text-[var(--lumora-accent-fg)]">Editorial &amp; Architecture</p>
            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight mt-1">Engineering Dispatches</h1>
            <p class="text-sm sm:text-base text-[var(--lumora-fg-muted)] mt-1">
                Architecture updates, security hardening notes, and hybrid file/SQLite CMS posts.
            </p>
        </div>
        <a href="<?= e(u('/blog/feed.xml')) ?>" class="lumora-btn lumora-btn-secondary shrink-0">
            Atom 1.0 Feed
        </a>
    </div>

    <?php if (empty($items)): ?>
        <div class="lumora-card p-8 text-center">
            <p class="text-[var(--lumora-fg-muted)]">No published articles found yet.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php foreach ($items as $post): ?>
                <?php
                $postSlug = (string)($post['slug'] ?? '');
                $postTitle = (string)($post['title'] ?? $postSlug);
                $postTags = is_array($post['tags'] ?? null) ? $post['tags'] : [];
                $coverImg = trim((string)($post['image'] ?? ''));
                ?>
                <article class="lumora-card lumora-card-interactive overflow-hidden flex flex-col">
                    <?php if ($coverImg !== ''): ?>
                        <a href="<?= e(u('/blog/' . $postSlug . '/')) ?>" class="block border-b border-[var(--lumora-border)]">
                            <img
                                src="<?= e(asset_url($coverImg)) ?>"
                                alt="<?= e($postTitle) ?>"
                                width="1200"
                                height="630"
                                loading="lazy"
                                class="w-full h-auto aspect-[1200/630] object-cover"
                            />
                        </a>
                    <?php endif; ?>

                    <div class="p-6 flex-1 flex flex-col justify-between gap-4">
                        <div class="space-y-3">
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="lumora-badge lumora-badge-accent"><?= e((string)($post['category'] ?? 'Engineering')) ?></span>
                                <?php if (($post['status'] ?? 'published') === 'draft'): ?>
                                    <span class="lumora-badge lumora-badge-warning font-mono">LOCALHOST DRAFT</span>
                                <?php endif; ?>
                                <span class="lumora-badge font-mono">source: <?= e((string)($post['source_origin'] ?? 'file')) ?></span>
                            </div>

                            <h2 class="text-xl font-bold leading-snug">
                                <a href="<?= e(u('/blog/' . $postSlug . '/')) ?>" class="hover:text-[var(--lumora-accent-fg)] no-underline">
                                    <?= e($postTitle) ?>
                                </a>
                            </h2>

                            <p class="text-sm text-[var(--lumora-fg-muted)] leading-relaxed">
                                <?= e((string)($post['summary'] ?? '')) ?>
                            </p>
                        </div>

                        <div class="space-y-3 pt-4 border-t border-[var(--lumora-border)]">
                            <?php if (!empty($postTags)): ?>
                                <div class="flex flex-wrap gap-1.5">
                                    <?php foreach ($postTags as $tag): ?>
                                        <span class="lumora-badge font-mono">#<?= e((string)$tag) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex items-center justify-between text-xs font-mono text-[var(--lumora-fg-muted)]">
                                <span><?= e((string)($post['date'] ?? '')) ?> · <?= e((string)($post['author'] ?? 'Maintainers')) ?></span>
                                <span><?= e((string)($post['reading_time'] ?? '1 min read')) ?></span>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
        <nav aria-label="Blog pagination" class="flex items-center justify-center gap-2 pt-4">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php $pUrl = $p === 1 ? u('/blog/') : u('/blog/page/' . $p . '/'); ?>
                <a
                    href="<?= e($pUrl) ?>"
                    class="lumora-btn <?= $p === $pageNum ? 'lumora-btn-primary' : 'lumora-btn-secondary' ?>"
                    <?= $p === $pageNum ? 'aria-current="page"' : '' ?>
                >
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
