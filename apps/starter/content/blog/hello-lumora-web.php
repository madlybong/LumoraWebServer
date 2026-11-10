<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'slug'    => 'hello-lumora-web',
    'title'   => 'Introducing @astrake/lumora-webserver: Zero-Dependency Enterprise PHP + Tailwind v4',
    'date'    => '2026-11-10',
    'status'  => 'published',
    'author'  => 'Astrake Engineering',
    'summary' => 'How we unified five brand websites onto a single headless PHP 8.2+ micro-kernel with CGNAT-safe v2 CSRF, SQLite WAL outboxes, and 12 automated compliance gates.',
    'body'    => '<p>Enterprise marketing and documentation portals demand sub-millisecond response times, strict OWASP ASVS Level 2 transport hardening, and zero runtime supply-chain risk on production servers.</p><p>With <code>@astrake/lumora-webserver</code>, each brand retains 100% creative freedom in <code>templates/*.php</code> and <code>css/brand.css</code> while inheriting a cryptographically locked security and persistence kernel.</p>',
];
