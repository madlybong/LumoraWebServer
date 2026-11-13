<?php
declare(strict_types=1);

namespace Lumora\Core;

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
        $features = $config['features'] ?? [];
        $origin = rtrim((string)($brand['origin'] ?? 'https://localhost'), '/');
        $brandName = (string)($brand['name'] ?? 'Lumora');
        $legalEntity = trim((string)($brand['legal_entity'] ?? ''));
        $locale = (string)($brand['locale'] ?? 'en');
        $category = (string)($brand['category'] ?? 'BusinessApplication');
        $slogan = (string)($brand['slogan'] ?? '');
        $addr = is_array($brand['address'] ?? null) ? $brand['address'] : [];

        $allowedEntityTypes = ['Organization', 'Person', 'LocalBusiness', 'Corporation', 'ProfessionalService'];
        $requestedType = (string)($brand['schema_org_type'] ?? 'Organization');
        $entityType = in_array($requestedType, $allowedEntityTypes, true) ? $requestedType : 'Organization';
        $isPerson = ($entityType === 'Person');
        $primaryId = $origin . ($isPerson ? '/#person' : '/#organization');
        $primaryRef = ['@id' => $primaryId];

        $title = (string)($meta['title'] ?? $brandName);
        $description = (string)($meta['description'] ?? ($brand['description'] ?? $slogan));
        $canonicalUrl = (string)($meta['canonical'] ?? ($origin . '/'));
        $imageUrl = (string)($meta['image'] ?? ($origin . '/public/brand/og-default.png'));
        $schemaType = (string)($meta['schema_type'] ?? 'home');
        $routePath = (string)($meta['path'] ?? '/');

        $logoSetting = (string)($brand['logo'] ?? '/public/brand/icon.svg');
        $logoUrl = str_starts_with($logoSetting, 'http')
            ? $logoSetting
            : $origin . '/' . ltrim($logoSetting, '/');

        $primaryNode = [
            '@type'       => $entityType,
            '@id'         => $primaryId,
            'name'        => $brandName,
            'url'         => $origin . '/',
            'description' => $description,
        ];

        if ($legalEntity !== '' && !empty($brand['expose_legal_entity_in_schema'])) {
            $primaryNode['legalName'] = $legalEntity;
        }

        if ($isPerson) {
            $primaryNode['image'] = !empty($brand['logo']) ? $logoUrl : $imageUrl;
        } else {
            $primaryNode['logo'] = [
                '@type' => 'ImageObject',
                '@id'   => $origin . '/#logo',
                'url'   => $logoUrl,
            ];
        }

        if (!empty($brand['same_as']) && is_array($brand['same_as'])) {
            $sameAs = array_values(array_filter(array_map('strval', $brand['same_as']), static fn(string $u): bool => $u !== ''));
            if (!empty($sameAs)) {
                $primaryNode['sameAs'] = $sameAs;
            }
        }

        if (!empty($brand['contact_points']) && is_array($brand['contact_points'])) {
            $contactPoints = [];
            foreach ($brand['contact_points'] as $cpItem) {
                if (!is_array($cpItem)) {
                    continue;
                }
                $cp = [
                    '@type'             => 'ContactPoint',
                    'contactType'       => (string)($cpItem['contact_type'] ?? 'customer support'),
                    'availableLanguage' => !empty($cpItem['available_language']) && is_array($cpItem['available_language'])
                        ? array_values($cpItem['available_language'])
                        : ['English'],
                ];
                if (!empty($cpItem['url'])) {
                    $cpUrl = (string)$cpItem['url'];
                    $cp['url'] = str_starts_with($cpUrl, 'http')
                        ? $cpUrl
                        : $origin . '/' . ltrim($cpUrl, '/');
                }
                if (!empty($cpItem['email'])) {
                    $cp['email'] = (string)$cpItem['email'];
                }
                $contactPoints[] = $cp;
            }
            if (!empty($contactPoints)) {
                $primaryNode['contactPoint'] = $contactPoints;
            }
        } elseif (!$isPerson && ((!isset($features['inquiry_engine']) || $features['inquiry_engine'] !== false) || !empty($brand['contact_email']))) {
            $defaultCp = [
                '@type'             => 'ContactPoint',
                'contactType'       => 'customer support',
                'availableLanguage' => ['English'],
            ];
            if (!isset($features['inquiry_engine']) || $features['inquiry_engine'] !== false) {
                $defaultCp['url'] = $origin . '/contact/';
            }
            if (!empty($brand['contact_email'])) {
                $defaultCp['email'] = (string)$brand['contact_email'];
            }
            $primaryNode['contactPoint'] = [$defaultCp];
        }

        if (!empty($addr)) {
            $addressProps = array_filter([
                'streetAddress'   => trim((string)($addr['street'] ?? '')),
                'addressLocality' => trim((string)($addr['locality'] ?? '')),
                'addressRegion'   => trim((string)($addr['region'] ?? '')),
                'postalCode'      => trim((string)($addr['postal_code'] ?? '')),
                'addressCountry'  => trim((string)($addr['country'] ?? '')),
            ], static fn(string $v): bool => $v !== '');

            if (!empty($addressProps)) {
                $primaryNode['address'] = ['@type' => 'PostalAddress'] + $addressProps;
            }
        }

        $graph = [$primaryNode];

        $emitBrandNode = array_key_exists('emit_brand_node', $brand)
            ? !empty($brand['emit_brand_node'])
            : !$isPerson;

        if ($emitBrandNode) {
            $brandNode = [
                '@type'       => 'Brand',
                '@id'         => $origin . '/#brand',
                'name'        => $brandName,
                'url'         => $origin . '/',
                'description' => $description,
            ];
            if ($slogan !== '') {
                $brandNode['slogan'] = $slogan;
            }
            $graph[] = $brandNode;
        }

        $graph[] = [
            '@type'      => 'WebSite',
            '@id'        => $origin . '/#website',
            'url'        => $origin . '/',
            'name'       => $brandName,
            'publisher'  => $primaryRef,
            'inLanguage' => $locale,
        ];

        $shouldEmitSoftware = !empty($brand['primary_offering_schema'])
            || $schemaType === 'software'
            || !empty($meta['software_schema']);

        if ($shouldEmitSoftware) {
            $softwareNode = [
                '@type'               => 'SoftwareApplication',
                '@id'                 => $origin . '/#software',
                'name'                => $brandName,
                'applicationCategory' => $category,
                'description'         => $description,
                'image'               => $imageUrl,
                'publisher'           => $primaryRef,
            ];
            if (!empty($brand['operating_system'])) {
                $softwareNode['operatingSystem'] = (string)$brand['operating_system'];
            }
            if ($emitBrandNode) {
                $softwareNode['brand'] = ['@id' => $origin . '/#brand'];
            }
            $graph[] = $softwareNode;
        }

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
                'publisher'        => $primaryRef,
            ];
        } elseif ($schemaType === 'blog') {
            $graph[] = [
                '@type'       => 'BlogPosting',
                '@id'         => $canonicalUrl . '#post',
                'headline'    => $title,
                'description' => $description,
                'publisher'   => $primaryRef,
            ];
        } elseif ($schemaType === 'legal') {
            $graph[] = [
                '@type'       => 'WebPage',
                '@id'         => $canonicalUrl . '#legal',
                'url'         => $canonicalUrl,
                'name'        => $title,
                'description' => $description,
                'isPartOf'    => ['@id' => $origin . '/#website'],
                'publisher'   => $primaryRef,
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

        if (!empty($meta['schema_nodes']) && is_array($meta['schema_nodes'])) {
            foreach ($meta['schema_nodes'] as $extraNode) {
                if (is_array($extraNode) && !empty($extraNode)) {
                    $graph[] = $extraNode;
                }
            }
        }

        return [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];
    }
}

if (
    class_exists(\Lumora\Core\SchemaGraph::class, false)
    && !class_exists(\Astrake\Lumora\Core\SchemaGraph::class, false)
) {
    class_alias(\Lumora\Core\SchemaGraph::class, \Astrake\Lumora\Core\SchemaGraph::class);
}
