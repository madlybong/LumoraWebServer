<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\Core\LegalRegistry;

$activeLegalSlugs = LegalRegistry::activeSlugs($config ?? [], $GLOBALS['lumoraRootDir'] ?? null);
?>
<footer style="border-top:1px solid var(--color-border);margin-top:4rem;padding:2.5rem 0;">
    <div class="site-shell" style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:1rem;">
        <div>
            <strong><?= e($config['brand']['name'] ?? 'Lumora WebStarter') ?></strong>
            <p style="margin:0.25rem 0 0;color:var(--color-muted);font-size:0.875rem;">
                &copy; <?= gmdate('Y') ?> <?= e($config['brand']['legal_entity'] ?? ($config['brand']['name'] ?? 'Lumora Maintainers')) ?>. Stateless Web Architecture.
            </p>
        </div>
        <?php if (!empty($activeLegalSlugs)): ?>
            <div style="display:flex;gap:1rem;font-size:0.875rem;">
                <?php foreach ($activeLegalSlugs as $legalSlug): ?>
                    <a href="<?= e(u('/legal/' . $legalSlug . '/')) ?>"><?= e(ucwords(str_replace('-', ' ', $legalSlug))) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</footer>
<?= lumora_core_script() ?>
</body>
</html>
