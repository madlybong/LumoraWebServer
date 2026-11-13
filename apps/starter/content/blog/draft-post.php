<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'slug'    => 'draft-post',
    'title'   => 'Internal Draft Architecture Note (Localhost Only)',
    'date'    => '2026-11-10',
    'status'  => 'draft',
    'author'  => 'Lumora Maintainers',
    'summary' => 'This draft article is gated to localhost and never appears in production feeds or sitemaps.',
    'body'    => '<p>Localhost-only draft content.</p>',
];
