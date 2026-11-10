<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Project-level legal customization layered on top of the parameterized territory generator.
 */
return [
    'merge_territory' => true,
    'append_sections' => [
        [
            'id'    => 'project-addendum',
            'title' => '6. Project-Specific Subresource Integrity & Local Vault Guarantee',
            'body'  => '<p>This property verifies all static assets via cryptographic SHA-384 Subresource Integrity (SRI) lockfiles (<code>lumora-lock.json</code>) and isolates prospective inquiry records inside an HMAC-derived local SQLite WAL vault.</p>',
        ],
    ],
];
