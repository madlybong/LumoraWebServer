<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\Core\DocsEngine;

$docsEngine = new DocsEngine();
$manifest = $doc_manifest ?? $docsEngine->getManifest();
$searchQuery = trim((string)($_GET['q'] ?? ''));
$searchResults = $searchQuery !== '' ? $docsEngine->searchArticles($searchQuery) : [];
$requestedTrack = (string)($_GET['track'] ?? ($doc_article['track_id'] ?? 'operator'));
$requestedSlug = (string)($_GET['doc'] ?? ($doc_article['slug'] ?? 'getting-started'));
$article = $doc_article ?? $docsEngine->renderArticle($requestedTrack, $requestedSlug);
$tocItems = is_array($article['toc'] ?? null) ? $article['toc'] : [];
$articleTags = is_array($article['tags'] ?? null) ? $article['tags'] : [];

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-10 sm:py-12 flex-1">
    <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-[var(--lumora-border)] pb-6">
        <div>
            <p class="text-xs font-mono uppercase tracking-wider text-[var(--lumora-accent-fg)]">Documentation Portal</p>
            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight mt-1">Guides &amp; Technical Reference</h1>
        </div>
        <form method="GET" action="<?= e(u('/docs/')) ?>" class="flex items-center gap-2 w-full md:w-80" role="search">
            <label for="docs-search-q" class="sr-only">Search documentation</label>
            <input
                type="search"
                id="docs-search-q"
                name="q"
                value="<?= e($searchQuery) ?>"
                placeholder="Search docs (e.g. sqlite, csp)..."
                class="lumora-input"
            />
            <button type="submit" class="lumora-btn lumora-btn-secondary shrink-0">Search</button>
        </form>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        <aside class="lg:col-span-3 space-y-6 lg:sticky lg:top-20" aria-label="Documentation Sidebar">
            <?php foreach ($manifest['tracks'] ?? [] as $trackKey => $track): ?>
                <?php $tid = (string)($track['id'] ?? $trackKey); ?>
                <div class="lumora-card p-4 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-[var(--lumora-fg)]">
                            <?= e((string)($track['title'] ?? 'Guides')) ?>
                        </h2>
                        <?php if (!empty($track['badge'])): ?>
                            <span class="lumora-badge font-mono"><?= e((string)$track['badge']) ?></span>
                        <?php endif; ?>
                    </div>
                    <ul class="space-y-1">
                        <?php foreach ($track['articles'] ?? [] as $item): ?>
                            <?php
                            $itemSlug = (string)($item['slug'] ?? '');
                            $isCurrent = $searchQuery === '' && $itemSlug === (string)($article['slug'] ?? '') && $tid === (string)($article['track_id'] ?? $requestedTrack);
                            ?>
                            <li>
                                <a
                                    href="<?= e(u('/docs/' . $tid . '/' . $itemSlug . '/')) ?>"
                                    class="lumora-nav-link w-full justify-between"
                                    <?= $isCurrent ? 'aria-current="page"' : '' ?>
                                >
                                    <span class="truncate"><?= e((string)($item['title'] ?? $itemSlug)) ?></span>
                                    <?php if (($item['status'] ?? 'published') === 'draft'): ?>
                                        <span class="lumora-badge lumora-badge-warning font-mono">DRAFT</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </aside>

        <div class="<?= empty($tocItems) || $searchQuery !== '' ? 'lg:col-span-9' : 'lg:col-span-7' ?> space-y-6 min-w-0">
            <?php if ($searchQuery !== ''): ?>
                <section class="lumora-card p-6 sm:p-8 space-y-5" aria-label="Search Results">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--lumora-border)] pb-4">
                        <h2 class="text-xl font-bold">
                            Results for &ldquo;<?= e($searchQuery) ?>&rdquo; (<?= count($searchResults) ?>)
                        </h2>
                        <a href="<?= e(u('/docs/')) ?>" class="lumora-btn lumora-btn-secondary">Clear Search</a>
                    </div>

                    <?php if (empty($searchResults)): ?>
                        <p class="text-sm text-[var(--lumora-fg-muted)]">No documentation articles matched your search query.</p>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($searchResults as $hit): ?>
                                <article class="rounded-lg border border-[var(--lumora-border)] bg-[var(--lumora-bg-subtle)] p-4 space-y-1.5">
                                    <div class="flex flex-wrap items-center gap-2 text-xs font-mono text-[var(--lumora-fg-muted)]">
                                        <span class="lumora-badge"><?= e((string)($hit['track_title'] ?? $hit['track_id'] ?? 'docs')) ?></span>
                                    </div>
                                    <h3 class="text-base font-bold">
                                        <a href="<?= e(u('/docs/' . ($hit['track_id'] ?? 'operator') . '/' . ($hit['slug'] ?? '') . '/')) ?>" class="text-[var(--lumora-accent-fg)] hover:underline">
                                            <?= e((string)($hit['title'] ?? '')) ?>
                                        </a>
                                    </h3>
                                    <p class="text-sm text-[var(--lumora-fg-muted)]">
                                        <?= e((string)($hit['snippet'] ?? $hit['summary'] ?? '')) ?>
                                    </p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <article class="lumora-card p-6 sm:p-8 space-y-6">
                    <div class="flex flex-wrap items-center gap-2 border-b border-[var(--lumora-border)] pb-4">
                        <span class="lumora-badge lumora-badge-accent font-mono"><?= e((string)($article['track_id'] ?? $requestedTrack)) ?></span>
                        <span class="lumora-badge font-mono"><?= e((string)($article['reading_time'] ?? '3 min read')) ?></span>
                        <span class="lumora-badge font-mono">source: <?= e((string)($article['source_origin'] ?? 'file')) ?></span>
                        <?php if (($article['status'] ?? 'published') === 'draft'): ?>
                            <span class="lumora-badge lumora-badge-warning font-mono">LOCALHOST DRAFT</span>
                        <?php endif; ?>
                    </div>

                    <div class="prose-lumora">
                        <?= $article['html'] ?? '<p>Select an article from the navigation sidebar.</p>' ?>
                    </div>

                    <?php if (!empty($articleTags)): ?>
                        <div class="pt-5 border-t border-[var(--lumora-border)] flex flex-wrap items-center gap-2">
                            <span class="text-xs font-mono text-[var(--lumora-fg-muted)]">Tags:</span>
                            <?php foreach ($articleTags as $tag): ?>
                                <span class="lumora-badge"><?= e((string)$tag) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endif; ?>
        </div>

        <?php if ($searchQuery === '' && !empty($tocItems)): ?>
            <aside class="lg:col-span-2 hidden lg:block lg:sticky lg:top-20" aria-label="On This Page">
                <div class="lumora-card p-4 space-y-2.5">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-[var(--lumora-fg)]">On This Page</h2>
                    <ul class="space-y-1.5 text-xs text-[var(--lumora-fg-muted)]">
                        <?php foreach ($tocItems as $heading): ?>
                            <?php $lvl = (int)($heading['level'] ?? 2); ?>
                            <li class="<?= $lvl >= 3 ? 'pl-3' : '' ?>">
                                <a href="#<?= e((string)($heading['id'] ?? '')) ?>" class="hover:text-[var(--lumora-fg)] hover:underline block py-0.5">
                                    <?= e((string)($heading['title'] ?? $heading['text'] ?? '')) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </aside>
        <?php endif; ?>
    </div>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
