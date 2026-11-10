<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'brand' => [
        'id'            => 'lumora-starter',
        'prefix'        => 'LUM',
        'name'          => 'Lumora WebStarter',
        'legal_entity'  => 'Astrake India',
        'domain'        => 'web.lumora.astrake.com',
        'origin'        => 'https://web.lumora.astrake.com',
        'version'       => '0.1.0',
        'locale'        => 'en-IN',
        'theme_color'   => '#0a0a0a',
        'color_scheme'  => 'light',
        'category'      => 'DeveloperApplication',
        'slogan'        => 'Headless PHP 8.2+ Enterprise Website Micro-Kernel & Tailwind CSS v4 Framework',
        'description'   => 'Reference enterprise website powered by @astrake/lumora-webserver featuring OWASP ASVS L2 headers, CGNAT-safe v2 CSRF, SQLite WAL persistence, and zero-dependency cPanel dist/ builds.',
        'contact_email' => 'office@astrake.com',
        'security_email'=> 'security@astrake.com',
        'address'       => [
            'street'      => 'Shop No 3, Ground Floor, 13B Bireswar Dhole Lane',
            'locality'    => 'Kolkata',
            'region'      => 'West Bengal',
            'postal_code' => '700035',
            'country'     => 'IN',
        ],
    ],

    'security' => [
        'canonical_https_redirect' => true,
        'strip_www_redirect'       => true,
        'csp_style_unsafe_inline'  => true,
        'csp_media_self'           => false,
        'rate_limit_max_attempts'  => 5,
        'rate_limit_window_sec'    => 900,
        'csrf_ttl_sec'             => 7200,
    ],

    'assets' => [
        'compiled_css' => '/public/assets/css/lumora.min.css',
        'core_js'      => '/public/assets/js/lumora-core.js',
        'fonts'        => [],
        'vendor_js'    => [],
    ],

    'hub' => [
        'enabled'         => false,
        'endpoint'        => getenv('LUMORA_HUB_ENDPOINT') ?: 'https://dispatch.astrake.com/api/v1/lumora/inquiries/ingest',
        'shared_secret'   => getenv('LUMORA_HUB_SECRET') ?: '',
        'timeout_ms'      => 1500,
        'fallback_notify' => true,
    ],

    'legal' => [
        'territories'         => ['IN', 'EU', 'GLOBAL'],
        'entity_type'         => 'Proprietorship',
        'grievance_officer'   => 'Grievance Officer & Principal — Data Protection Compliance',
        'grievance_email'     => 'office@astrake.com',
        'effective_date'      => '2026-11-10',
        'retention_days_pii'  => 90,
        'retention_days_logs' => 180,
        'governing_law'       => 'Kolkata, West Bengal, India',
    ],

    'features' => [
        'docs_engine'    => true,
        'blog_engine'    => true,
        'release_server' => true,
    ],
];
