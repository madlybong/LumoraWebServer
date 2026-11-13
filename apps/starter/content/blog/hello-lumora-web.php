<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'slug'    => 'hello-lumora-web',
    'title'   => 'Introducing @astrake/lumora-webserver: Zero-Dependency PHP 8.2+ & Tailwind v4',
    'date'    => '2026-11-10',
    'status'  => 'published',
    'author'  => 'Lumora Maintainers',
    'summary' => 'How to build fast, portable websites on a headless PHP 8.2+ micro-kernel with CGNAT-safe v2 CSRF, SQLite WAL storage, and 12 automated quality gates.',
    'body'    => '<p>Modern marketing sites, blogs, and documentation portals benefit from sub-millisecond response times, strict transport hardening, and zero runtime supply-chain risk on production servers.</p><p>With <code>@astrake/lumora-webserver</code>, every project keeps 100% creative freedom in <code>templates/*.php</code> and <code>css/brand.css</code> while inheriting a cryptographically locked security and persistence kernel.</p>',
];
