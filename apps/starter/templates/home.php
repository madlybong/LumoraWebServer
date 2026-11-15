<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

use Lumora\Core\AdminDb;
use Lumora\Core\Security;

$brandVersion = (string)($config['brand']['version'] ?? '0.2.0');
$slogan = (string)($config['brand']['slogan'] ?? 'Headless PHP 8.2+ Website Micro-Kernel & Tailwind CSS v4 Framework');
$description = (string)($config['brand']['description'] ?? 'Reference website powered by @astrake/lumora-webserver featuring hardened HTTP security headers, CGNAT-safe v2 CSRF, SQLite WAL persistence, and zero-dependency dist/ builds.');
$showAdmin = Security::isLocalhost() && !empty($config['features']['admin_panel']);
$adminHref = $showAdmin ? u(AdminDb::normalizeAdminPath($config ?? [])) : '';

component('layout/head', ['meta' => $meta ?? [], 'config' => $config ?? []]);
component('layout/header', ['meta' => $meta ?? [], 'config' => $config ?? []]);
?>
<main id="main-content" class="flex-1">
    <section class="border-b border-[var(--lumora-border)] bg-[var(--lumora-surface)]">
        <div class="lumora-container py-14 sm:py-20">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">
                <div class="lg:col-span-7 space-y-6">
                    <div class="inline-flex flex-wrap items-center gap-2">
                        <span class="lumora-badge lumora-badge-accent font-mono">@astrake/lumora-webserver</span>
                        <span class="lumora-badge font-mono">v<?= e($brandVersion) ?></span>
                        <span class="lumora-badge lumora-badge-success">12-Gate Verified</span>
                    </div>

                    <h1 class="text-3xl sm:text-5xl font-bold tracking-tight leading-tight text-[var(--lumora-fg)]">
                        <?= e($slogan) ?>
                    </h1>

                    <p class="text-base sm:text-lg text-[var(--lumora-fg-muted)] max-w-2xl leading-relaxed">
                        <?= e($description) ?>
                    </p>

                    <div class="flex flex-wrap items-center gap-3 pt-1">
                        <a href="<?= e(u('/contact/')) ?>" class="lumora-btn lumora-btn-primary" data-lumora-cta="hero-contact">
                            Send Enterprise Inquiry
                        </a>
                        <a href="<?= e(u('/docs/')) ?>" class="lumora-btn lumora-btn-secondary" data-lumora-cta="hero-docs">
                            Read Documentation
                        </a>
                        <?php if ($showAdmin): ?>
                            <a href="<?= e($adminHref) ?>" class="lumora-btn lumora-btn-secondary" data-lumora-cta="hero-admin">
                                Open Admin Console
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="lg:col-span-5">
                    <div class="lumora-card p-6 space-y-4">
                        <div class="flex items-center justify-between gap-2 border-b border-[var(--lumora-border)] pb-3">
                            <span class="text-xs font-mono uppercase tracking-wider text-[var(--lumora-fg-muted)]">Quickstart Runtime</span>
                            <span class="lumora-badge font-mono">PHP 8.2+ · TW v4</span>
                        </div>
                        <pre class="overflow-x-auto rounded-md border border-[var(--lumora-border)] bg-zinc-950 text-zinc-100 p-4 text-xs sm:text-sm font-mono leading-relaxed"><code>bunx lumora-web dev
bunx lumora-web build
bunx lumora-web verify</code></pre>
                        <dl class="grid grid-cols-2 gap-3 text-xs">
                            <div class="rounded-md border border-[var(--lumora-border)] bg-[var(--lumora-bg-subtle)] p-3">
                                <dt class="font-mono text-[var(--lumora-fg-muted)]">Integrity</dt>
                                <dd class="mt-1 font-semibold text-[var(--lumora-fg)]">CSP Nonce + SHA-384</dd>
                            </div>
                            <div class="rounded-md border border-[var(--lumora-border)] bg-[var(--lumora-bg-subtle)] p-3">
                                <dt class="font-mono text-[var(--lumora-fg-muted)]">Persistence</dt>
                                <dd class="mt-1 font-semibold text-[var(--lumora-fg)]">SQLite WAL + JSONL</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section aria-labelledby="pillars-heading" class="lumora-container py-14 sm:py-16 space-y-8">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
            <div>
                <p class="text-xs font-mono uppercase tracking-wider text-[var(--lumora-accent-fg)]">Core Guarantees</p>
                <h2 id="pillars-heading" class="text-2xl sm:text-3xl font-bold tracking-tight mt-1">Architectural Pillars</h2>
            </div>
            <a href="<?= e(u('/docs/architecture/security-architecture/')) ?>" class="text-sm font-semibold text-[var(--lumora-accent-fg)] hover:underline">
                Explore Security Architecture &rarr;
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <article class="lumora-card p-6 space-y-2.5">
                <span class="lumora-badge font-mono">01 / SECURITY</span>
                <h3 class="text-lg font-bold">OWASP ASVS v4.0 L2 Hardened</h3>
                <p class="text-sm text-[var(--lumora-fg-muted)]">
                    128-bit CSPRNG per-request CSP nonces, SHA-384 Subresource Integrity lockfiles, CGNAT-resilient stateless v2 HMAC-SHA256 CSRF protection, and strict response headers on every route.
                </p>
            </article>

            <article class="lumora-card p-6 space-y-2.5">
                <span class="lumora-badge font-mono">02 / STORAGE VAULT</span>
                <h3 class="text-lg font-bold">Sub-Millisecond SQLite WAL Vault</h3>
                <p class="text-sm text-[var(--lumora-fg-muted)]">
                    Local-first ACID SQLite3 Write-Ahead Logging inside HMAC-isolated storage vaults with atomic JSONL failover, pre-CSRF 5/900s rate limiting, and optional Hub webhook delivery.
                </p>
            </article>

            <article class="lumora-card p-6 space-y-2.5">
                <span class="lumora-badge font-mono">03 / HYBRID CMS</span>
                <h3 class="text-lg font-bold">Flat-File &amp; Admin Console Parity</h3>
                <p class="text-sm text-[var(--lumora-fg-muted)]">
                    Author documentation tracks, blog dispatches, inquiry channels, and SHA-256 releases in version-controlled files or manage them live through the embedded Vue Admin Console.
                </p>
            </article>

            <article class="lumora-card p-6 space-y-2.5">
                <span class="lumora-badge font-mono">04 / DEPLOYMENT</span>
                <h3 class="text-lg font-bold">Zero-Dependency dist/ Builds</h3>
                <p class="text-sm text-[var(--lumora-fg-muted)]">
                    Compiles Tailwind CSS v4 + adaptive light/dark tokens locally and emits self-contained <code>index.php</code> route stubs compatible with Apache, Nginx, cPanel, and native PHP servers.
                </p>
            </article>
        </div>
    </section>

    <section aria-labelledby="modules-heading" class="lumora-container pb-16 space-y-6">
        <div>
            <p class="text-xs font-mono uppercase tracking-wider text-[var(--lumora-accent-fg)]">Reference Showcase</p>
            <h2 id="modules-heading" class="text-2xl font-bold tracking-tight mt-1">Explore Starter Modules</h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <a href="<?= e(u('/docs/')) ?>" class="lumora-card lumora-card-interactive p-5 block no-underline space-y-2">
                <div class="flex items-center justify-between">
                    <span class="font-bold">Documentation Portal</span>
                    <span class="lumora-badge font-mono">/docs/</span>
                </div>
                <p class="text-sm text-[var(--lumora-fg-muted)]">Multi-track Markdown guides, live full-text search, reading-time estimates, and automatic section TOC.</p>
            </a>

            <a href="<?= e(u('/blog/')) ?>" class="lumora-card lumora-card-interactive p-5 block no-underline space-y-2">
                <div class="flex items-center justify-between">
                    <span class="font-bold">Engineering Blog &amp; Atom</span>
                    <span class="lumora-badge font-mono">/blog/</span>
                </div>
                <p class="text-sm text-[var(--lumora-fg-muted)]">Paginated dispatches, localhost-only draft previews, cover artwork, tags, and valid Atom 1.0 XML syndication.</p>
            </a>

            <a href="<?= e(u('/releases/')) ?>" class="lumora-card lumora-card-interactive p-5 block no-underline space-y-2">
                <div class="flex items-center justify-between">
                    <span class="font-bold">Verified Releases</span>
                    <span class="lumora-badge font-mono">/releases/</span>
                </div>
                <p class="text-sm text-[var(--lumora-fg-muted)]">Downloadable archive catalog backed by <code>release-manifest.json</code>, <code>SHA256SUMS</code>, and HTTP Range headers.</p>
            </a>

            <a href="<?= e(u('/contact/')) ?>" class="lumora-card lumora-card-interactive p-5 block no-underline space-y-2">
                <div class="flex items-center justify-between">
                    <span class="font-bold">Channel Inquiry Engine</span>
                    <span class="lumora-badge font-mono">/contact/</span>
                </div>
                <p class="text-sm text-[var(--lumora-fg-muted)]">Channel-specific SLAs, dynamic custom schema fields, honeypot trap, CSRF v2 tokens, and live aria validation.</p>
            </a>

            <a href="<?= e(u('/legal/')) ?>" class="lumora-card lumora-card-interactive p-5 block no-underline space-y-2">
                <div class="flex items-center justify-between">
                    <span class="font-bold">Legal &amp; Governance</span>
                    <span class="lumora-badge font-mono">/legal/</span>
                </div>
                <p class="text-sm text-[var(--lumora-fg-muted)]">Parameterized Privacy Policy and Terms of Use merged with project-level compliance addenda.</p>
            </a>

            <?php if ($showAdmin): ?>
                <a href="<?= e($adminHref) ?>" class="lumora-card lumora-card-interactive p-5 block no-underline space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-bold">Admin Console</span>
                        <span class="lumora-badge lumora-badge-accent font-mono"><?= e($adminHref) ?></span>
                    </div>
                    <p class="text-sm text-[var(--lumora-fg-muted)]">Manage blog posts, docs tracks, inquiry triage, releases, and RBAC accounts on localhost.</p>
                </a>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php component('layout/footer', ['config' => $config ?? []]); ?>
