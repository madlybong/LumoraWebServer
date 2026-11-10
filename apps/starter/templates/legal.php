<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content" class="site-shell" style="padding:3rem 0;max-width:860px;">
    <article class="card-surface">
        <p style="font-family:var(--font-mono);font-size:0.75rem;color:var(--color-muted);">
            <?= e($legal_data['doc_no'] ?? 'LGL') ?> · Effective <?= e($legal_data['date'] ?? '') ?> · Entity: <?= e($legal_data['entity_name'] ?? '') ?>
        </p>
        <h1><?= e($legal_data['title'] ?? 'Legal Policy') ?></h1>
        <p style="color:var(--color-muted);font-size:1.0625rem;margin-bottom:2rem;">
            <?= e($legal_data['lead'] ?? '') ?>
        </p>
        <?php foreach ($legal_data['sections'] ?? [] as $section): ?>
            <section id="<?= e($section['id'] ?? '') ?>" style="margin-bottom:2rem;">
                <h2><?= e($section['title'] ?? '') ?></h2>
                <div><?= $section['body'] ?? '' ?></div>
            </section>
        <?php endforeach; ?>
    </article>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
