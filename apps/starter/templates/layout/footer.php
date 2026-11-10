<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<footer style="border-top:1px solid var(--color-border);margin-top:4rem;padding:2.5rem 0;">
    <div class="site-shell" style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:1rem;">
        <div>
            <strong><?= e($config['brand']['name'] ?? 'Lumora WebStarter') ?></strong>
            <p style="margin:0.25rem 0 0;color:var(--color-muted);font-size:0.875rem;">
                &copy; <?= gmdate('Y') ?> <?= e($config['brand']['legal_entity'] ?? 'Astrake India') ?>. Zero-Surveillance Enterprise Architecture.
            </p>
        </div>
        <div style="display:flex;gap:1rem;font-size:0.875rem;">
            <a href="<?= e(u('/legal/privacy/')) ?>">Privacy (DPDP 2023)</a>
            <a href="<?= e(u('/legal/terms/')) ?>">Terms</a>
            <a href="<?= e(u('/legal/cookies/')) ?>">Zero-Cookie</a>
            <a href="<?= e(u('/legal/accessibility/')) ?>">Accessibility</a>
            <a href="<?= e(u('/legal/security/')) ?>">Security</a>
            <a href="<?= e(u('/legal/company/')) ?>">Corporate</a>
        </div>
    </div>
</footer>
<?= lumora_core_script() ?>
</body>
</html>
