<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

$doc = is_array($legal_data ?? null) ? $legal_data : [];
$sections = is_array($doc['sections'] ?? null) ? $doc['sections'] : [];

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-10 sm:py-12 flex-1 max-w-4xl space-y-6">
    <nav aria-label="Breadcrumb">
        <a href="<?= e(u('/legal/')) ?>" class="text-sm font-medium text-[var(--lumora-accent-fg)] hover:underline">
            &larr; All Legal &amp; Governance Policies
        </a>
    </nav>

    <article class="lumora-card p-6 sm:p-10 space-y-8">
        <header class="space-y-4 border-b border-[var(--lumora-border)] pb-6">
            <div class="flex flex-wrap items-center gap-2 text-xs font-mono">
                <span class="lumora-badge lumora-badge-accent"><?= e((string)($doc['doc_no'] ?? 'LGL')) ?></span>
                <?php if (!empty($doc['date'])): ?>
                    <span class="lumora-badge">Effective <?= e((string)$doc['date']) ?></span>
                <?php endif; ?>
                <?php if (!empty($doc['entity_name'])): ?>
                    <span class="lumora-badge">Entity: <?= e((string)$doc['entity_name']) ?></span>
                <?php endif; ?>
            </div>
            <h1 class="text-2xl sm:text-4xl font-bold tracking-tight text-[var(--lumora-fg)]">
                <?= e((string)($doc['title'] ?? 'Legal Policy')) ?>
            </h1>
            <?php if (!empty($doc['lead'])): ?>
                <p class="text-base sm:text-lg text-[var(--lumora-fg-muted)] leading-relaxed">
                    <?= e((string)$doc['lead']) ?>
                </p>
            <?php endif; ?>
        </header>

        <?php if (!empty($sections)): ?>
            <nav aria-label="Document Sections" class="rounded-lg border border-[var(--lumora-border)] bg-[var(--lumora-bg-subtle)] p-4">
                <h2 class="text-xs font-mono uppercase tracking-wider text-[var(--lumora-fg-muted)] mb-2">Sections</h2>
                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-sm">
                    <?php foreach ($sections as $sec): ?>
                        <?php if (empty($sec['id']) || empty($sec['title'])) continue; ?>
                        <li>
                            <a href="#<?= e((string)$sec['id']) ?>" class="text-[var(--lumora-accent-fg)] hover:underline">
                                <?= e((string)$sec['title']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>

        <div class="prose-lumora space-y-8">
            <?php foreach ($sections as $section): ?>
                <section id="<?= e((string)($section['id'] ?? '')) ?>">
                    <h2><?= e((string)($section['title'] ?? '')) ?></h2>
                    <div><?= $section['body'] ?? '' ?></div>
                </section>
            <?php endforeach; ?>
        </div>
    </article>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
