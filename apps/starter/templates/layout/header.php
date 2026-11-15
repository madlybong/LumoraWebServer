<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\Core\AdminDb;
use Lumora\Core\Security;

$brandName = (string)($config['brand']['name'] ?? 'Lumora WebStarter');
$brandVersion = (string)($config['brand']['version'] ?? '0.2.0');
$rawPath = (string)($meta['path'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));
$parsedPath = (string)parse_url($rawPath, PHP_URL_PATH);
$cleanCurrent = '/' . trim($parsedPath, '/') . '/';
if ($cleanCurrent === '//') {
    $cleanCurrent = '/';
}

$isSection = static function (string $prefix) use ($cleanCurrent): bool {
    if ($prefix === '/') {
        return $cleanCurrent === '/';
    }
    return str_starts_with($cleanCurrent, $prefix);
};

$features = is_array($config['features'] ?? null) ? $config['features'] : [];
$showAdmin = Security::isLocalhost() && !empty($features['admin_panel']);
$adminHref = $showAdmin ? u(AdminDb::normalizeAdminPath($config ?? [])) : '';

$navItems = [
    ['label' => 'Overview', 'path' => '/', 'prefix' => '/', 'enabled' => true],
    ['label' => 'Docs', 'path' => '/docs/', 'prefix' => '/docs/', 'enabled' => !isset($features['docs_engine']) || !empty($features['docs_engine'])],
    ['label' => 'Blog', 'path' => '/blog/', 'prefix' => '/blog/', 'enabled' => !isset($features['blog_engine']) || !empty($features['blog_engine'])],
    ['label' => 'Releases', 'path' => '/releases/', 'prefix' => '/releases/', 'enabled' => !isset($features['release_server']) || !empty($features['release_server'])],
    ['label' => 'Legal', 'path' => '/legal/', 'prefix' => '/legal/', 'enabled' => !isset($features['legal_registry']) || !empty($features['legal_registry'])],
    ['label' => 'Contact', 'path' => '/contact/', 'prefix' => '/contact/', 'enabled' => !isset($features['inquiry_engine']) || !empty($features['inquiry_engine'])],
];
?>
<header class="lumora-header">
    <div class="lumora-container flex items-center justify-between gap-4 py-3.5">
        <div class="flex items-center gap-3 min-w-0">
            <a href="<?= e(u('/')) ?>" class="inline-flex items-center gap-2.5 font-bold tracking-tight text-base no-underline shrink-0">
                <img src="<?= e(asset_url('/public/brand/icon.svg')) ?>" alt="<?= e($brandName) ?>" width="32" height="32" class="h-8 w-8 rounded-lg shrink-0" />
                <span class="truncate"><?= e($brandName) ?></span>
            </a>
            <span class="lumora-badge font-mono hidden sm:inline-flex">v<?= e($brandVersion) ?></span>
        </div>

        <nav class="hidden md:flex items-center gap-1" aria-label="Primary Navigation">
            <?php foreach ($navItems as $item): ?>
                <?php if (!$item['enabled']) continue; ?>
                <a
                    href="<?= e(u($item['path'])) ?>"
                    class="lumora-nav-link"
                    <?= $isSection($item['prefix']) ? 'aria-current="page"' : '' ?>
                >
                    <?= e($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="flex items-center gap-2 shrink-0">
            <?php if ($showAdmin): ?>
                <a href="<?= e($adminHref) ?>" class="lumora-badge lumora-badge-accent hidden sm:inline-flex" data-lumora-cta="header-admin">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                    Admin Console
                </a>
            <?php endif; ?>

            <button
                type="button"
                class="lumora-btn lumora-btn-ghost"
                data-lumora-theme-toggle
                aria-label="Toggle color theme"
                title="Toggle light/dark theme"
            >
                <span data-theme-icon="sun" aria-hidden="true">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="4" />
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
                    </svg>
                </span>
                <span data-theme-icon="moon" aria-hidden="true">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" />
                    </svg>
                </span>
            </button>

            <a href="<?= e(u('/contact/')) ?>" class="lumora-btn lumora-btn-primary hidden sm:inline-flex" data-lumora-cta="header-contact">
                Send Inquiry
            </a>

            <button
                type="button"
                class="lumora-btn lumora-btn-secondary md:hidden"
                data-lumora-nav-toggle
                aria-expanded="false"
                aria-controls="lumora-mobile-drawer"
                aria-label="Open navigation menu"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                <span class="sr-only">Menu</span>
            </button>
        </div>
    </div>
</header>

<dialog id="lumora-mobile-drawer" data-lumora-nav-drawer aria-label="Mobile Navigation">
    <div data-lumora-nav-sheet class="flex flex-col justify-between h-full gap-6">
        <div>
            <div class="flex items-center justify-between gap-3 pb-4 border-b border-[var(--lumora-border)]">
                <a href="<?= e(u('/')) ?>" class="inline-flex items-center gap-2 font-bold text-sm no-underline">
                    <img src="<?= e(asset_url('/public/brand/icon.svg')) ?>" alt="<?= e($brandName) ?>" width="28" height="28" class="h-7 w-7 rounded-md shrink-0" />
                    <span><?= e($brandName) ?></span>
                </a>
                <button type="button" class="lumora-btn lumora-btn-ghost" data-lumora-nav-close aria-label="Close navigation menu">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="flex flex-col gap-1 mt-4" aria-label="Mobile Primary Navigation">
                <?php foreach ($navItems as $item): ?>
                    <?php if (!$item['enabled']) continue; ?>
                    <a
                        href="<?= e(u($item['path'])) ?>"
                        class="lumora-nav-link justify-between py-2.5"
                        <?= $isSection($item['prefix']) ? 'aria-current="page"' : '' ?>
                    >
                        <span><?= e($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="flex flex-col gap-2 pt-4 border-t border-[var(--lumora-border)]">
            <?php if ($showAdmin): ?>
                <a href="<?= e($adminHref) ?>" class="lumora-btn lumora-btn-secondary w-full" data-lumora-cta="drawer-admin">
                    Admin Console
                </a>
            <?php endif; ?>
            <a href="<?= e(u('/contact/')) ?>" class="lumora-btn lumora-btn-primary w-full" data-lumora-cta="drawer-contact">
                Send Inquiry
            </a>
        </div>
    </div>
</dialog>
