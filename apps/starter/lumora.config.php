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
        'legal_entity'  => 'Lumora Maintainers',
        'domain'        => 'lumora-starter.example.com',
        'origin'        => 'https://lumora-starter.example.com',
        'version'       => '0.1.2',
        'locale'        => 'en',
        'theme_color'   => '#0a0a0a',
        'color_scheme'  => 'light',
        'category'      => 'DeveloperApplication',
        'slogan'        => 'Headless PHP 8.2+ Website Micro-Kernel & Tailwind CSS v4 Framework',
        'description'   => 'Reference website powered by @astrake/lumora-webserver featuring hardened HTTP security headers, CGNAT-safe v2 CSRF, SQLite WAL persistence, and zero-dependency dist/ builds.',
        'contact_email' => 'hello@lumora-starter.example.com',
        'security_email'=> 'security@lumora-starter.example.com',
        'address'       => [],
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
        'endpoint'        => getenv('LUMORA_HUB_ENDPOINT') ?: '',
        'shared_secret'   => getenv('LUMORA_HUB_SECRET') ?: '',
        'timeout_ms'      => 1500,
        'fallback_notify' => true,
    ],

    'legal' => [
        'territories'         => ['GLOBAL'],
        'grievance_email'     => 'privacy@lumora-starter.example.com',
        'effective_date'      => '2026-11-10',
        'slugs'               => ['privacy', 'terms'],
        'retention_days_pii'  => 90,
        'retention_days_logs' => 180,
    ],

    'discovery' => [
        'security_txt' => true,
    ],

    'features' => [
        'docs_engine'    => true,
        'blog_engine'    => true,
        'release_server' => true,
        'legal_registry' => true,
        'inquiry_engine' => true,
    ],
];
