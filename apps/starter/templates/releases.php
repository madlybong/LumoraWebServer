<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\Core\ReleaseServer;

$catalog = ReleaseServer::getCatalog($config ?? [], $GLOBALS['lumoraRootDir'] ?? '');
$artifacts = is_array($catalog['artifacts'] ?? null) ? $catalog['artifacts'] : [];

$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
};

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="lumora-container py-10 sm:py-12 flex-1 space-y-8">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 border-b border-[var(--lumora-border)] pb-6">
        <div class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <span class="lumora-badge lumora-badge-accent font-mono">v<?= e((string)($catalog['version'] ?? '0.2.0')) ?></span>
                <span class="lumora-badge font-mono"><?= e((string)($catalog['installerMode'] ?? 'offline-only')) ?></span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight">Verified Releases &amp; Checksums</h1>
            <p class="text-sm sm:text-base text-[var(--lumora-fg-muted)] max-w-2xl">
                Immutable release artifacts served with SHA-256 verification headers (<code>X-Checksum-Sha256</code>) and byte-range resume support.
            </p>
        </div>

        <div class="flex flex-wrap gap-2 shrink-0">
            <a href="<?= e(u('/releases/release-manifest.json')) ?>" class="lumora-btn lumora-btn-secondary font-mono text-xs">
                release-manifest.json
            </a>
            <a href="<?= e(u('/releases/SHA256SUMS')) ?>" class="lumora-btn lumora-btn-secondary font-mono text-xs">
                SHA256SUMS
            </a>
        </div>
    </div>

    <?php if (empty($artifacts)): ?>
        <div class="lumora-card p-8 text-center space-y-2">
            <h2 class="text-lg font-bold">No Release Artifacts Published Yet</h2>
            <p class="text-sm text-[var(--lumora-fg-muted)]">
                Add artifacts to <code>releases/release-manifest.json</code> or upload releases via the Admin Console.
            </p>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($artifacts as $art): ?>
                <?php
                $fn = (string)($art['filename'] ?? '');
                $available = !empty($art['available']);
                $size = (int)($art['size_bytes'] ?? 0);
                ?>
                <article class="lumora-card p-6 space-y-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-bold font-mono"><?= e($fn) ?></h2>
                                <span class="lumora-badge lumora-badge-accent font-mono">v<?= e((string)($art['version'] ?? '')) ?></span>
                                <span class="lumora-badge font-mono"><?= e((string)($art['platform'] ?? 'archive')) ?></span>
                                <span class="lumora-badge <?= ($art['channel'] ?? 'stable') === 'stable' ? 'lumora-badge-success' : 'lumora-badge-warning' ?> font-mono">
                                    <?= e((string)($art['channel'] ?? 'stable')) ?>
                                </span>
                                <span class="lumora-badge font-mono"><?= e($formatBytes($size)) ?></span>
                                <span class="lumora-badge font-mono">source: <?= e((string)($art['source_origin'] ?? 'file')) ?></span>
                            </div>
                            <?php if (!empty($art['release_notes'])): ?>
                                <p class="text-sm text-[var(--lumora-fg-muted)]"><?= e((string)$art['release_notes']) ?></p>
                            <?php endif; ?>
                        </div>

                        <?php if ($available && $fn !== ''): ?>
                            <a
                                href="<?= e(u('/releases/' . $fn)) ?>"
                                class="lumora-btn lumora-btn-primary shrink-0"
                                data-lumora-cta="download-release"
                            >
                                Download Artifact
                            </a>
                        <?php else: ?>
                            <span class="lumora-badge lumora-badge-warning shrink-0">Artifact Missing on Disk</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($art['sha256'])): ?>
                        <div class="rounded-md border border-[var(--lumora-border)] bg-[var(--lumora-bg-subtle)] px-3.5 py-2.5 text-xs font-mono break-all">
                            <span class="text-[var(--lumora-fg-muted)]">SHA-256:</span>
                            <span class="text-[var(--lumora-fg)] ml-1"><?= e((string)$art['sha256']) ?></span>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
