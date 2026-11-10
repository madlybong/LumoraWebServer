<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Astrake\Lumora\Core\DocsEngine;

$docsEngine = new DocsEngine();
$manifest = $doc_manifest ?? $docsEngine->getManifest();
$requestedSlug = (string)($_GET['doc'] ?? ($doc_article['slug'] ?? 'getting-started'));
$article = $doc_article ?? $docsEngine->renderArticle('operator', $requestedSlug);

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content" class="site-shell" style="padding:3rem 0;">
    <div style="display:grid;grid-template-columns:240px 1fr;gap:2.5rem;">
        <aside aria-label="Documentation Sidebar">
            <?php foreach ($manifest['tracks'] ?? [] as $track): ?>
                <h2 style="font-size:0.875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-muted);">
                    <?= e($track['title'] ?? 'Guides') ?>
                </h2>
                <ul style="list-style:none;padding:0;margin:0 0 1.5rem;">
                    <?php foreach ($track['articles'] ?? [] as $item): ?>
                        <li style="margin-bottom:0.5rem;">
                            <a href="<?= e(u('/docs/' . ($item['slug'] ?? '') . '/')) ?>">
                                <?= e($item['title'] ?? '') ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </aside>
        <article class="card-surface">
            <p style="font-family:var(--font-mono);font-size:0.75rem;color:var(--color-muted);">
                <?= e($article['reading_time'] ?? '3 min read') ?>
            </p>
            <div><?= $article['html'] ?? '' ?></div>
        </article>
    </div>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
