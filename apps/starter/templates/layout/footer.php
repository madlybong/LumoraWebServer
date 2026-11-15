<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\Core\AdminDb;
use Lumora\Core\LegalRegistry;
use Lumora\Core\Security;

$brandName = (string)($config['brand']['name'] ?? 'Lumora WebStarter');
$legalEntity = (string)($config['brand']['legal_entity'] ?? ($config['brand']['name'] ?? 'Lumora Maintainers'));
$brandVersion = (string)($config['brand']['version'] ?? '0.2.0');
$features = is_array($config['features'] ?? null) ? $config['features'] : [];
$activeLegalSlugs = LegalRegistry::activeSlugs($config ?? [], $GLOBALS['lumoraRootDir'] ?? null);
$showAdmin = Security::isLocalhost() && !empty($features['admin_panel']);
$adminHref = $showAdmin ? u(AdminDb::normalizeAdminPath($config ?? [])) : '';
?>
<footer class="mt-auto border-t border-[var(--lumora-border)] bg-[var(--lumora-surface)]">
    <div class="lumora-container py-12">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
            <div class="space-y-3">
                <div class="flex items-center gap-2.5 font-bold text-sm">
                    <span><?= e($brandName) ?></span>
                    <span class="lumora-badge font-mono">v<?= e($brandVersion) ?></span>
                </div>
                <p class="text-sm text-[var(--lumora-fg-muted)] max-w-xs">
                    Zero-dependency PHP 8.2+ micro-kernel &amp; Tailwind CSS v4 architecture verified by 12 automated security gates.
                </p>
                <?php if ($showAdmin): ?>
                    <div class="pt-1">
                        <a href="<?= e($adminHref) ?>" class="lumora-badge lumora-badge-accent">
                            Open Admin Console &rarr;
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="space-y-2.5">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[var(--lumora-fg)]">Platform</h2>
                <ul class="space-y-1.5 text-sm text-[var(--lumora-fg-muted)]">
                    <li><a href="<?= e(u('/')) ?>" class="hover:underline">Overview</a></li>
                    <li><a href="<?= e(u('/docs/')) ?>" class="hover:underline">Documentation</a></li>
                    <li><a href="<?= e(u('/blog/')) ?>" class="hover:underline">Engineering Blog</a></li>
                    <li><a href="<?= e(u('/releases/')) ?>" class="hover:underline">Verified Releases</a></li>
                    <li><a href="<?= e(u('/contact/')) ?>" class="hover:underline">Contact &amp; Inquiries</a></li>
                </ul>
            </div>

            <div class="space-y-2.5">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[var(--lumora-fg)]">Governance</h2>
                <ul class="space-y-1.5 text-sm text-[var(--lumora-fg-muted)]">
                    <li><a href="<?= e(u('/legal/')) ?>" class="hover:underline">Legal Directory</a></li>
                    <?php foreach ($activeLegalSlugs as $legalSlug): ?>
                        <li>
                            <a href="<?= e(u('/legal/' . $legalSlug . '/')) ?>" class="hover:underline">
                                <?= e(ucwords(str_replace('-', ' ', $legalSlug))) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="space-y-2.5">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[var(--lumora-fg)]">Discovery &amp; Feeds</h2>
                <ul class="space-y-1.5 text-sm font-mono text-[var(--lumora-fg-muted)]">
                    <li><a href="<?= e(u('/llms.txt')) ?>" class="hover:underline">/llms.txt</a></li>
                    <li><a href="<?= e(u('/llms-full.txt')) ?>" class="hover:underline">/llms-full.txt</a></li>
                    <li><a href="<?= e(u('/sitemap.xml')) ?>" class="hover:underline">/sitemap.xml</a></li>
                    <li><a href="<?= e(u('/blog/feed.xml')) ?>" class="hover:underline">/blog/feed.xml</a></li>
                    <li><a href="<?= e(u('/releases/release-manifest.json')) ?>" class="hover:underline">/releases/release-manifest.json</a></li>
                    <li><a href="<?= e(u('/health')) ?>" class="hover:underline">/health</a></li>
                </ul>
            </div>
        </div>

        <div class="mt-10 pt-6 border-t border-[var(--lumora-border)] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs text-[var(--lumora-fg-muted)]">
            <p>&copy; <?= gmdate('Y') ?> <?= e($legalEntity) ?>. Powered by @astrake/lumora-webserver.</p>
            <p class="font-mono">CSP Nonce · SHA-384 SRI · SQLite WAL · CGNAT v2 CSRF</p>
        </div>
    </div>
</footer>
<?= lumora_core_script() ?>
</body>
</html>
