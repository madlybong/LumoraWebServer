<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Project-level Terms of Use addendum layered on top of the parameterized territory generator.
 */
return [
    'merge_territory' => true,
    'append_sections' => [
        [
            'id'    => 'reference-architecture-terms',
            'title' => '6. Acceptable Use & Deterministic Build Terms',
            'body'  => '<p>This reference starter application demonstrates strict Content Security Policy nonces, zero-rewrite static/dynamic artifact generation, and rate-limited inquiry endpoints. Automated abuse, unauthorized scanning of administrative endpoints, or automated scraping bypassing documented discovery feeds (<code>/llms.txt</code> and <code>/sitemap.xml</code>) is prohibited.</p>',
        ],
    ],
];
