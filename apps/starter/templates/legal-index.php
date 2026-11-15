<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\Core\LegalRegistry;

$slugs = LegalRegistry::activeSlugs($config ?? [], $GLOBALS['lumoraRootDir'] ?? null);
$entityName = (string)($config['brand']['legal_entity'] ?? ($config['brand']['name'] ?? 'Lumora WebStarter'));

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-10 sm:py-12 flex-1 space-y-8">
    <div class="space-y-2 border-b border-[var(--lumora-border)] pb-6">
        <p class="text-xs font-mono uppercase tracking-wider text-[var(--lumora-accent-fg)]">Compliance &amp; Governance</p>
        <h1 class="text-2xl sm:text-3xl font-bold tracking-tight">Legal &amp; Governance Policies</h1>
        <p class="text-sm sm:text-base text-[var(--lumora-fg-muted)] max-w-2xl">
            Statutory notices and governance policies published for <?= e($entityName) ?>.
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php foreach ($slugs as $s): ?>
            <?php $doc = LegalRegistry::resolveDocument($s, $config ?? [], $GLOBALS['lumoraRootDir'] ?? null); ?>
            <?php if ($doc !== null): ?>
                <article class="lumora-card lumora-card-interactive p-6 flex flex-col justify-between gap-4">
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center gap-2 text-xs font-mono">
                            <?php if (!empty($doc['doc_no'])): ?>
                                <span class="lumora-badge lumora-badge-accent"><?= e((string)$doc['doc_no']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($doc['status'])): ?>
                                <span class="lumora-badge lumora-badge-success"><?= e((string)$doc['status']) ?></span>
                            <?php endif; ?>
                        </div>
                        <h2 class="text-xl font-bold">
                            <a href="<?= e(u('/legal/' . $s . '/')) ?>" class="hover:text-[var(--lumora-accent-fg)] no-underline">
                                <?= e((string)($doc['title'] ?? ucfirst($s))) ?>
                            </a>
                        </h2>
                        <p class="text-sm text-[var(--lumora-fg-muted)] leading-relaxed">
                            <?= e((string)($doc['lead'] ?? '')) ?>
                        </p>
                    </div>
                    <div class="pt-3 border-t border-[var(--lumora-border)]">
                        <a href="<?= e(u('/legal/' . $s . '/')) ?>" class="text-sm font-semibold text-[var(--lumora-accent-fg)] hover:underline">
                            Read Document &rarr;
                        </a>
                    </div>
                </article>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
