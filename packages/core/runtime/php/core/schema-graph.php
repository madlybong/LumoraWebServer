<?php
declare(strict_types=1);

namespace Astrake\Lumora\Core;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class SchemaGraph
{
    /**
     * Deterministically generates a Schema.org JSON-LD @graph array from lumora.config.php and route metadata.
     */
    public static function build(array $config, array $meta = []): array
    {
        $brand = $config['brand'] ?? [];
        $origin = rtrim((string)($brand['origin'] ?? 'https://localhost'), '/');
        $brandName = (string)($brand['name'] ?? 'Lumora');
        $legalEntity = (string)($brand['legal_entity'] ?? $brandName);
        $locale = (string)($brand['locale'] ?? 'en-IN');
        $category = (string)($brand['category'] ?? 'BusinessApplication');
        $slogan = (string)($brand['slogan'] ?? '');
        $addr = is_array($brand['address'] ?? null) ? $brand['address'] : [];

        $title = (string)($meta['title'] ?? $brandName);
        $description = (string)($meta['description'] ?? ($brand['description'] ?? $slogan));
        $canonicalUrl = (string)($meta['canonical'] ?? ($origin . '/'));
        $imageUrl = (string)($meta['image'] ?? ($origin . '/public/brand/og-default.png'));
        $schemaType = (string)($meta['schema_type'] ?? 'home');
        $routePath = (string)($meta['path'] ?? '/');

        $orgNode = [
            '@type'        => 'Organization',
            '@id'          => $origin . '/#organization',
            'name'         => $brandName,
            'legalName'    => $legalEntity,
            'url'          => $origin . '/',
            'description'  => $description,
            'logo'         => [
                '@type' => 'ImageObject',
                '@id'   => $origin . '/#logo',
                'url'   => $origin . (string)($brand['logo'] ?? '/public/brand/icon.svg'),
            ],
            'contactPoint' => [
                [
                    '@type'             => 'ContactPoint',
                    'url'               => $origin . '/contact/',
                    'contactType'       => 'customer service',
                    'availableLanguage' => ['English'],
                ],
                [
                    '@type'             => 'ContactPoint',
                    'url'               => $origin . '/contact/?topic=security',
                    'contactType'       => 'security reporting',
                    'availableLanguage' => ['English'],
                ],
                [
                    '@type'             => 'ContactPoint',
                    'url'               => $origin . '/contact/?topic=privacy',
                    'contactType'       => 'data protection officer',
                    'availableLanguage' => ['English'],
                ],
            ],
        ];

        if (!empty($addr)) {
            $orgNode['address'] = [
                '@type'           => 'PostalAddress',
                'streetAddress'   => (string)($addr['street'] ?? ''),
                'addressLocality' => (string)($addr['locality'] ?? ''),
                'addressRegion'   => (string)($addr['region'] ?? ''),
                'postalCode'      => (string)($addr['postal_code'] ?? ''),
                'addressCountry'  => (string)($addr['country'] ?? 'IN'),
            ];
        }

        $graph = [
            $orgNode,
            [
                '@type'       => 'Brand',
                '@id'         => $origin . '/#brand',
                'name'        => $brandName,
                'url'         => $origin . '/',
                'slogan'      => $slogan,
                'description' => $description,
            ],
            [
                '@type'      => 'WebSite',
                '@id'        => $origin . '/#website',
                'url'        => $origin . '/',
                'name'       => $brandName,
                'publisher'  => ['@id' => $origin . '/#organization'],
                'inLanguage' => $locale,
            ],
            [
                '@type'               => 'SoftwareApplication',
                '@id'                 => $origin . '/#software',
                'name'                => $brandName,
                'applicationCategory' => $category,
                'operatingSystem'     => (string)($brand['operating_system'] ?? 'Web, Linux, Windows, macOS'),
                'description'         => $description,
                'image'               => $imageUrl,
                'brand'               => ['@id' => $origin . '/#brand'],
                'publisher'           => ['@id' => $origin . '/#organization'],
            ],
        ];

        // Add BreadcrumbList for non-root pages
        if ($routePath !== '/' && $routePath !== '') {
            $segments = array_values(array_filter(explode('/', trim($routePath, '/'))));
            $items = [
                [
                    '@type'    => 'ListItem',
                    'position' => 1,
                    'name'     => 'Home',
                    'item'     => $origin . '/',
                ],
            ];
            $accum = '';
            foreach ($segments as $idx => $seg) {
                $accum .= '/' . $seg;
                $isLast = ($idx === count($segments) - 1);
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $idx + 2,
                    'name'     => $isLast ? $title : ucwords(str_replace('-', ' ', $seg)),
                    'item'     => $origin . $accum . '/',
                ];
            }
            $graph[] = [
                '@type'           => 'BreadcrumbList',
                '@id'             => $canonicalUrl . '#breadcrumb',
                'itemListElement' => $items,
            ];
        }

        if ($schemaType === 'contact') {
            $graph[] = [
                '@type'       => 'ContactPage',
                '@id'         => $canonicalUrl . '#webpage',
                'url'         => $canonicalUrl,
                'name'        => $title,
                'description' => $description,
                'isPartOf'    => ['@id' => $origin . '/#website'],
            ];
        } elseif ($schemaType === 'docs') {
            $graph[] = [
                '@type'            => 'TechArticle',
                '@id'              => $canonicalUrl . '#article',
                'headline'         => $title,
                'description'      => $description,
                'proficiencyLevel' => 'Beginner',
                'publisher'        => ['@id' => $origin . '/#organization'],
            ];
        } elseif ($schemaType === 'blog') {
            $graph[] = [
                '@type'       => 'BlogPosting',
                '@id'         => $canonicalUrl . '#post',
                'headline'    => $title,
                'description' => $description,
                'publisher'   => ['@id' => $origin . '/#organization'],
            ];
        } elseif ($schemaType === 'legal') {
            $graph[] = [
                '@type'       => 'WebPage',
                '@id'         => $canonicalUrl . '#legal',
                'url'         => $canonicalUrl,
                'name'        => $title,
                'description' => $description,
                'isPartOf'    => ['@id' => $origin . '/#website'],
                'publisher'   => ['@id' => $origin . '/#organization'],
            ];
        }

        if (!empty($meta['faq']) && is_array($meta['faq'])) {
            $faqEntities = [];
            foreach ($meta['faq'] as $qa) {
                if (!empty($qa['q']) && !empty($qa['a'])) {
                    $faqEntities[] = [
                        '@type'          => 'Question',
                        'name'           => (string)$qa['q'],
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text'  => (string)$qa['a'],
                        ],
                    ];
                }
            }
            if (!empty($faqEntities)) {
                $graph[] = [
                    '@type'      => 'FAQPage',
                    '@id'        => $canonicalUrl . '#faq',
                    'mainEntity' => $faqEntities,
                ];
            }
        }

        return [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];
    }
}
