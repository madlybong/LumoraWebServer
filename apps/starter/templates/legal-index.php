<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Astrake\Lumora\Core\LegalRegistry;

$slugs = LegalRegistry::allowedSlugs();

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['config' => $config ?? []]);
?>
<main id="main-content" class="site-shell" style="padding:3rem 0;">
    <h1>Statutory Legal &amp; Compliance Registry</h1>
    <p style="color:var(--color-muted);margin-bottom:2rem;">
        Parameterized multi-territory statutory policies generated for <?= e($config['brand']['legal_entity'] ?? 'Astrake India') ?>.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.5rem;">
        <?php foreach ($slugs as $s): ?>
            <?php $doc = LegalRegistry::resolveDocument($s, $config ?? [], $GLOBALS['lumoraRootDir'] ?? null); ?>
            <?php if ($doc !== null): ?>
                <article class="card-surface">
                    <p style="font-family:var(--font-mono);font-size:0.75rem;color:var(--color-muted);margin:0 0 0.25rem;">
                        <?= e($doc['doc_no'] ?? '') ?> · <?= e($doc['status'] ?? '') ?>
                    </p>
                    <h2 style="font-size:1.25rem;margin:0 0 0.5rem;">
                        <a href="<?= e(u('/legal/' . $s . '/')) ?>" style="color:inherit;text-decoration:none;">
                            <?= e($doc['title'] ?? '') ?>
                        </a>
                    </h2>
                    <p style="margin:0;color:var(--color-muted);font-size:0.9375rem;"><?= e($doc['lead'] ?? '') ?></p>
                </article>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
