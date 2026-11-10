<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'routes' => [
        '/' => [
            'template' => 'home',
            'meta'     => [
                'title'       => 'Lumora WebStarter — Enterprise Website Framework',
                'description' => 'Headless PHP 8.2+ Micro-Kernel, Tailwind CSS v4 Brand Layer, and 12-Gate Automated Compliance Verifier.',
                'path'        => '/',
                'schema_type' => 'home',
                'faq'         => [
                    [
                        'q' => 'How does @astrake/lumora-webserver handle production deployment?',
                        'a' => 'Running lumora-web build compiles Tailwind CSS v4, locks SHA-384 SRI hashes, and outputs a 100% standalone zero-dependency dist/ bundle with physical index.php route stubs.',
                    ],
                ],
            ],
        ],
        '/docs/' => [
            'template' => 'docs',
            'meta'     => [
                'title'       => 'Documentation — Lumora WebStarter',
                'description' => 'Operator and developer documentation for @astrake/lumora-webserver.',
                'path'        => '/docs/',
                'schema_type' => 'docs',
            ],
        ],
        '/blog/' => [
            'template' => 'blog-index',
            'meta'     => [
                'title'       => 'Engineering Dispatches — Lumora WebStarter',
                'description' => 'Architectural notes and security engineering dispatches.',
                'path'        => '/blog/',
                'schema_type' => 'blog',
            ],
        ],
        '/contact/' => [
            'template' => 'contact',
            'meta'     => [
                'title'       => 'Contact & Statutory Desk — Lumora WebStarter',
                'description' => 'First-party encrypted inquiry, security vulnerability disclosure, and DPDP Act 2023 Data Principal portal.',
                'path'        => '/contact/',
                'schema_type' => 'contact',
            ],
        ],
        '/legal/' => [
            'template' => 'legal-index',
            'meta'     => [
                'title'       => 'Statutory Legal & Compliance Registry — Lumora WebStarter',
                'description' => 'DPDP Act 2023, CERT-In 2022, EU GDPR, Terms of Use, Zero-Cookie Audit, and WCAG 2.2 AA disclosures.',
                'path'        => '/legal/',
                'schema_type' => 'legal',
            ],
        ],
    ],
    'redirects' => [
        '/privacy'  => '/legal/privacy/',
        '/privacy/' => '/legal/privacy/',
        '/terms'    => '/legal/terms/',
        '/terms/'   => '/legal/terms/',
        '/guide'    => '/docs/',
        '/guide/'   => '/docs/',
    ],
];
