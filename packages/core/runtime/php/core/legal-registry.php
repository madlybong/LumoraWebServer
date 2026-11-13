<?php
declare(strict_types=1);

namespace Lumora\Core;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * App-driven legal document registry with opt-in regional compliance templates.
 * Defaults to a neutral global posture ('GLOBAL', ['privacy', 'terms']) and allows any
 * consumer project to define active slugs via `legal.slugs`, `content/legal/{slug}.php`,
 * or disable legal routes completely with `features.legal_registry = false`.
 */
final class LegalRegistry
{
    /**
     * Built-in template slugs available when requested by the app or default minimal policy.
     *
     * @return array<int, string>
     */
    public static function builtInTemplateSlugs(): array
    {
        return ['privacy', 'terms', 'cookies', 'accessibility', 'security', 'company'];
    }

    /**
     * Dynamically determines active legal slugs for the current website.
     *
     * @return array<int, string>
     */
    public static function activeSlugs(?array $config = null, ?string $rootDir = null): array
    {
        $cfg = $config ?? (is_array($GLOBALS['lumoraConfig'] ?? null) ? $GLOBALS['lumoraConfig'] : []);
        $resolvedRoot = rtrim(
            str_replace('\\', '/', $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd())),
            '/'
        );

        if (isset($cfg['features']['legal_registry']) && $cfg['features']['legal_registry'] === false) {
            return [];
        }

        if (isset($cfg['legal']['slugs']) && is_array($cfg['legal']['slugs'])) {
            $slugs = [];
            foreach ($cfg['legal']['slugs'] as $rawSlug) {
                $slug = strtolower(trim((string)$rawSlug));
                if ($slug !== '' && preg_match('/^[a-z0-9-]+$/', $slug)) {
                    $slugs[] = $slug;
                }
            }
            return array_values(array_unique($slugs));
        }

        $includeDefaults = !isset($cfg['legal']['include_default_slugs']) || $cfg['legal']['include_default_slugs'] !== false;
        $discovered = $includeDefaults ? ['privacy', 'terms'] : [];
        $legalDir = $resolvedRoot . '/content/legal';
        if (is_dir($legalDir)) {
            $files = glob($legalDir . '/*.php') ?: [];
            foreach ($files as $file) {
                $slug = basename($file, '.php');
                if ($slug !== 'index' && preg_match('/^[a-z0-9-]+$/', $slug)) {
                    $discovered[] = $slug;
                }
            }
        }

        if (!empty($discovered)) {
            $priority = ['privacy' => 0, 'terms' => 1];
            usort($discovered, static function (string $a, string $b) use ($priority): int {
                $pa = $priority[$a] ?? 100;
                $pb = $priority[$b] ?? 100;
                return $pa !== $pb ? $pa <=> $pb : strcmp($a, $b);
            });
            return array_values(array_unique($discovered));
        }

        return [];
    }

    /**
     * Backward-compatible alias for `activeSlugs()`. Reads globals when called without arguments.
     *
     * @return array<int, string>
     */
    public static function allowedSlugs(?array $config = null, ?string $rootDir = null): array
    {
        return self::activeSlugs($config, $rootDir);
    }

    private static function formatAddress(array $config): string
    {
        $brand = $config['brand'] ?? [];
        $legalCfg = $config['legal'] ?? [];
        $addr = is_array($brand['address'] ?? null) ? $brand['address'] : [];

        $formattedAddr = trim(implode(', ', array_filter([
            trim((string)($addr['street'] ?? '')),
            trim((string)($addr['locality'] ?? '')),
            trim((string)($addr['region'] ?? '')),
            trim((string)($addr['postal_code'] ?? '')),
            trim((string)($addr['country'] ?? '')),
        ])));

        if ($formattedAddr === '' && !empty($legalCfg['registered_address'])) {
            $formattedAddr = trim((string)$legalCfg['registered_address']);
        }

        return $formattedAddr;
    }

    private static function buildNeutralMeta(string $slug, array $config): array
    {
        $brand = $config['brand'] ?? [];
        $legalCfg = $config['legal'] ?? [];
        $brandName = (string)($brand['name'] ?? 'Lumora Website');
        $legalEntity = trim((string)($brand['legal_entity'] ?? ''));
        $domain = (string)($brand['domain'] ?? 'localhost');
        $email = (string)($legalCfg['grievance_email'] ?? $brand['contact_email'] ?? ('contact@' . $domain));

        return [
            'doc_no'      => '',
            'title'       => ucwords(str_replace('-', ' ', $slug)),
            'lead'        => '',
            'version'     => (string)($brand['version'] ?? '0.1.2'),
            'date'        => (string)($legalCfg['effective_date'] ?? '2026-11-10'),
            'status'      => 'ACTIVE POLICY',
            'entity_name' => $legalEntity !== '' ? $legalEntity : $brandName,
            'entity_type' => trim((string)($legalCfg['entity_type'] ?? '')),
            'address'     => self::formatAddress($config),
            'email'       => $email,
            'territories' => is_array($legalCfg['territories'] ?? null)
                ? array_map('strtoupper', $legalCfg['territories'])
                : ['GLOBAL'],
        ];
    }

    public static function resolveDocument(string $slug, array $config, ?string $rootDir = null): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return null;
        }
        if (isset($config['features']['legal_registry']) && $config['features']['legal_registry'] === false) {
            return null;
        }

        $resolvedRoot = rtrim(
            str_replace('\\', '/', $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd())),
            '/'
        );
        $overrideFile = $resolvedRoot . '/content/legal/' . $slug . '.php';
        $active = self::activeSlugs($config, $resolvedRoot);

        if (!in_array($slug, $active, true) && !is_file($overrideFile)) {
            return null;
        }

        if (is_file($overrideFile)) {
            $custom = require $overrideFile;
            if (is_array($custom)) {
                $neutralMeta = self::buildNeutralMeta($slug, $config);

                // Full replacement override: preserves app sections verbatim with neutral metadata.
                if (isset($custom['sections']) && is_array($custom['sections']) && empty($custom['merge_territory'])) {
                    return array_merge($neutralMeta, $custom);
                }

                $baseDoc = in_array($slug, self::builtInTemplateSlugs(), true)
                    ? self::generateTerritoryDocument($slug, $config)
                    : array_merge($neutralMeta, ['sections' => []]);

                $merged = $baseDoc;
                foreach (['doc_no', 'title', 'lead', 'version', 'date', 'status', 'entity_name', 'entity_type', 'address', 'email'] as $key) {
                    if (isset($custom[$key]) && is_string($custom[$key])) {
                        $merged[$key] = $custom[$key];
                    }
                }

                $sections = $baseDoc['sections'];
                if (!empty($custom['replace_sections']) && is_array($custom['replace_sections'])) {
                    foreach ($sections as $idx => $sec) {
                        $secId = (string)($sec['id'] ?? '');
                        if ($secId !== '' && isset($custom['replace_sections'][$secId]) && is_array($custom['replace_sections'][$secId])) {
                            $sections[$idx] = array_merge($sec, $custom['replace_sections'][$secId]);
                        }
                    }
                }
                if (!empty($custom['prepend_sections']) && is_array($custom['prepend_sections'])) {
                    $sections = array_merge($custom['prepend_sections'], $sections);
                }
                if (!empty($custom['append_sections']) && is_array($custom['append_sections'])) {
                    $sections = array_merge($sections, $custom['append_sections']);
                }

                $merged['sections'] = $sections;
                return $merged;
            }
        }

        if (!in_array($slug, self::builtInTemplateSlugs(), true)) {
            return null;
        }

        return self::generateTerritoryDocument($slug, $config);
    }

    public static function generateTerritoryDocument(string $slug, array $config): array
    {
        $brand = $config['brand'] ?? [];
        $legalCfg = $config['legal'] ?? [];

        $brandName = (string)($brand['name'] ?? 'Lumora Website');
        $legalEntity = trim((string)($brand['legal_entity'] ?? ''));
        $displayEntity = $legalEntity !== '' ? $legalEntity : $brandName;
        $entityType = trim((string)($legalCfg['entity_type'] ?? ''));
        $domain = (string)($brand['domain'] ?? 'localhost');
        $version = (string)($brand['version'] ?? '0.1.2');
        $effectiveDate = (string)($legalCfg['effective_date'] ?? '2026-11-10');
        $email = (string)($legalCfg['grievance_email'] ?? $brand['contact_email'] ?? ('contact@' . $domain));
        $grievanceOfficer = trim((string)($legalCfg['grievance_officer'] ?? ''));
        $piiDays = max(0, (int)($legalCfg['retention_days_pii'] ?? 90));
        $logDays = max(0, (int)($legalCfg['retention_days_logs'] ?? 180));
        $formattedAddr = self::formatAddress($config);
        $addr = is_array($brand['address'] ?? null) ? $brand['address'] : [];

        $territories = is_array($legalCfg['territories'] ?? null)
            ? array_map('strtoupper', $legalCfg['territories'])
            : ['GLOBAL'];

        $hasIndia = in_array('IN', $territories, true);
        $hasEu = in_array('EU', $territories, true) || in_array('UK', $territories, true);
        $hasUs = in_array('US', $territories, true);
        $hasStatutory = $hasIndia || $hasEu || $hasUs;

        $commonMeta = [
            'version'     => $version,
            'date'        => $effectiveDate,
            'status'      => $hasStatutory ? 'ACTIVE STATUTORY POLICY' : 'ACTIVE POLICY',
            'entity_name' => $displayEntity,
            'entity_type' => $entityType,
            'address'     => $formattedAddr,
            'email'       => $email,
            'territories' => $territories,
        ];

        $eBrand = Render::escape($brandName);
        $eEntity = Render::escape($displayEntity);
        $eAddr = Render::escape($formattedAddr);
        $eEmail = Render::escape($email);
        $eOfficer = Render::escape($grievanceOfficer);

        if ($slug === 'privacy') {
            $regList = [];
            if ($hasIndia) {
                $regList[] = 'Digital Personal Data Protection (DPDP) Act 2023 (India)';
                $regList[] = 'CERT-In Directions (2022)';
            }
            if ($hasEu) {
                $regList[] = 'Regulation (EU) 2016/679 (GDPR)';
            }
            if ($hasUs) {
                $regList[] = 'CCPA / CPRA';
            }
            $regSummary = implode(', ', $regList);

            $contactLines = ["<strong>{$eEntity}</strong>"];
            if ($eOfficer !== '') {
                $contactLines[] = "Designated Contact: {$eOfficer}";
            }
            if ($eAddr !== '') {
                $contactLines[] = "Address: {$eAddr}";
            }
            $contactLines[] = "Email: <a href=\"mailto:{$eEmail}\">{$eEmail}</a>";
            if (!isset($config['features']['inquiry_engine']) || $config['features']['inquiry_engine'] !== false) {
                $contactLines[] = "Contact Form: <a href=\"/contact/\">/contact/</a>";
            }

            $retentionItems = [];
            if ($piiDays > 0) {
                $retentionItems[] = "<li><strong>Inquiry Personal Details ({$piiDays} Calendar Days):</strong> Automatically redacted (<code>[REDACTED]</code>) after {$piiDays} days" . ($hasIndia ? ' per DPDP Act 2023 Sec. 8(7)' : '') . ".</li>";
            } else {
                $retentionItems[] = "<li><strong>Inquiry Personal Details:</strong> Retained for active correspondence until manually cleared or deletion is requested.</li>";
            }
            if ($logDays > 0) {
                $retentionItems[] = "<li><strong>Security Rate-Limit Logs ({$logDays} Calendar Days):</strong> Keyed <code>ip_hash</code> entries are kept for {$logDays} days" . ($hasIndia ? ' per CERT-In Directions (April 28, 2022)' : '') . " and purged thereafter.</li>";
            } else {
                $retentionItems[] = "<li><strong>Security Rate-Limit Logs:</strong> Retained until cleared by the site operator.</li>";
            }

            $contactHeading = $hasIndia
                ? '2. Data Fiduciary, Controller & Statutory Contact'
                : ($hasEu ? '2. Data Controller & Privacy Contact' : '2. Publisher & Privacy Contact');
            $contactIntro = $regSummary !== ''
                ? "<p>Under {$regSummary}, inquiries concerning personal information are managed by:</p>"
                : "<p>This website is operated by:</p>";
            $indiaNotice = $hasIndia
                ? "<p><strong>Eighth Schedule Language Accessibility (DPDP Act 2023 Sec. 5(3)):</strong> Individuals may request this notice in any language specified in the Eighth Schedule to the Constitution of India via <a href=\"mailto:{$eEmail}\">{$eEmail}</a>.</p>"
                : '';

            $sections = [
                [
                    'id'    => 'architecture',
                    'title' => '1. Stateless & Cookie-Free Architecture',
                    'body'  => "<p><strong>{$eBrand}</strong> avoids third-party tracking cookies, advertising beacons, third-party CDNs, and cross-site analytics. Stylesheets, scripts, and assets are served directly from this site's origin.</p>",
                ],
                [
                    'id'    => 'fiduciary',
                    'title' => $contactHeading,
                    'body'  => $contactIntro . "<p>" . implode('<br />', $contactLines) . "</p>" . $indiaNotice,
                ],
                [
                    'id'    => 'processing',
                    'title' => '3. Information Collected & Purpose',
                    'body'  => "<p>Information is collected only when you voluntarily submit a form on this website:</p>"
                        . "<ul>"
                        . "<li><strong>Contact Details:</strong> Name, organization (if supplied), email address, and optional phone number.</li>"
                        . "<li><strong>Message Context:</strong> Selected inquiry topic, subject, message body, and optional campaign parameters stored in browser session storage.</li>"
                        . "<li><strong>Abuse Prevention Telemetry:</strong> A keyed HMAC-SHA256 hash of the client IP (<code>ip_hash</code>; raw IPs are not persisted) and stateless CSRF tokens to block spam and automated abuse.</li>"
                        . "</ul>",
                ],
                [
                    'id'    => 'retention',
                    'title' => '4. Data Retention & Cleanup',
                    'body'  => "<p>Submissions are stored in a local SQLite database restricted to the site operator:</p><ul>" . implode('', $retentionItems) . "</ul>",
                ],
                [
                    'id'    => 'rights',
                    'title' => '5. Access, Correction & Deletion Requests',
                    'body'  => "<p>You may request access to, correction of, or deletion of personal information you submitted"
                        . ($regSummary !== '' ? " in accordance with {$regSummary}" : '')
                        . " by emailing <a href=\"mailto:{$eEmail}\">{$eEmail}</a>.</p>",
                ],
            ];

            return array_merge($commonMeta, [
                'doc_no'   => 'LGL-01',
                'title'    => 'Privacy Policy',
                'lead'     => $regSummary !== ''
                    ? "How {$brandName} processes and protects personal information aligned with {$regSummary}."
                    : "How {$brandName} collects, uses, protects, and deletes information submitted through this website.",
                'sections' => $sections,
            ]);
        }

        if ($slug === 'terms') {
            $addrParts = array_values(array_filter([
                trim((string)($addr['locality'] ?? '')),
                trim((string)($addr['region'] ?? '')),
                trim((string)($addr['country'] ?? '')),
            ]));
            $jurisdiction = trim((string)($legalCfg['governing_law'] ?? ''));
            if ($jurisdiction === '') {
                $jurisdiction = !empty($addrParts) ? implode(', ', $addrParts) : 'the applicable local jurisdiction';
            }
            $eJur = Render::escape($jurisdiction);
            $entityWithAddr = $eAddr !== '' ? "<strong>{$eEntity}</strong> ({$eAddr})" : "<strong>{$eEntity}</strong>";

            return array_merge($commonMeta, [
                'doc_no'   => 'LGL-02',
                'title'    => 'Terms of Use',
                'lead'     => "Terms governing access to {$brandName} websites, content, and interactive features.",
                'sections' => [
                    [
                        'id'    => 'acceptance',
                        'title' => '1. Scope & Acceptance',
                        'body'  => "<p>By accessing this website operated by {$entityWithAddr}, you agree to abide by these Terms of Use and all applicable laws.</p>",
                    ],
                    [
                        'id'    => 'ip',
                        'title' => '2. Intellectual Property',
                        'body'  => "<p>Unless otherwise stated or explicitly licensed, content, documentation, visual designs, and trademarks published under <strong>{$eBrand}</strong> remain the property of <strong>{$eEntity}</strong>.</p>",
                    ],
                    [
                        'id'    => 'acceptable-use',
                        'title' => '3. Acceptable Use',
                        'body'  => "<p>Automated flooding, denial-of-service attempts, or bypassing rate limits on public forms is prohibited. Please report security issues responsibly via <a href=\"mailto:{$eEmail}\">{$eEmail}</a>.</p>",
                    ],
                    [
                        'id'    => 'governing-law',
                        'title' => '4. Governing Law',
                        'body'  => "<p>These Terms are governed by the laws applicable in <strong>{$eJur}</strong>.</p>",
                    ],
                ],
            ]);
        }

        if ($slug === 'cookies') {
            return array_merge($commonMeta, [
                'doc_no'   => 'LGL-03',
                'title'    => 'Cookie & Storage Policy',
                'lead'     => "Technical overview of stateless session security and absence of tracking cookies on {$brandName}.",
                'sections' => [
                    [
                        'id'    => 'zero-cookie',
                        'title' => '1. Cookie-Free Operation',
                        'body'  => "<p><strong>{$eBrand}</strong> does not set advertising or cross-site tracking cookies. Anti-CSRF form validation uses stateless HMAC-SHA256 tokens rather than persistent browser cookies.</p>",
                    ],
                ],
            ]);
        }

        if ($slug === 'accessibility') {
            $standards = ['W3C WCAG 2.2 Level AA'];
            if ($hasEu) {
                $standards[] = 'EN 301 549';
            }
            if ($hasIndia) {
                $standards[] = 'RPwD Act 2016';
            }
            $stdSummary = implode(', ', $standards);

            return array_merge($commonMeta, [
                'doc_no'   => 'LGL-04',
                'title'    => 'Accessibility Statement',
                'lead'     => "Accessibility approach for {$brandName} following {$stdSummary} guidelines.",
                'sections' => [
                    [
                        'id'    => 'conformance',
                        'title' => '1. Accessible Design Standards',
                        'body'  => "<p><strong>{$eBrand}</strong> supports keyboard navigation, visible focus indicators (<code>:focus-visible</code>), semantic HTML landmarks, <code>prefers-reduced-motion: reduce</code>, and high-contrast typography. To report an accessibility barrier, contact <a href=\"mailto:{$eEmail}\">{$eEmail}</a>.</p>",
                    ],
                ],
            ]);
        }

        if ($slug === 'security') {
            return array_merge($commonMeta, [
                'doc_no'   => 'LGL-05',
                'title'    => 'Security & Vulnerability Disclosure',
                'lead'     => "Transport hardening and coordinated vulnerability disclosure policy for {$brandName}.",
                'sections' => [
                    [
                        'id'    => 'transport-hardening',
                        'title' => '1. Transport & Application Hardening',
                        'body'  => "<p>Responses from <strong>{$eBrand}</strong> apply per-request CSP nonces, Subresource Integrity (SHA-384 SRI), strict framing isolation (<code>X-Frame-Options: DENY</code>), and HSTS headers over HTTPS.</p>",
                    ],
                    [
                        'id'    => 'disclosure',
                        'title' => '2. Coordinated Disclosure',
                        'body'  => "<p>Security researchers can report potential vulnerabilities directly to <a href=\"mailto:{$eEmail}\">{$eEmail}</a>.</p>",
                    ],
                ],
            ]);
        }

        // 'company'
        $details = ["<strong>Brand / Site:</strong> {$eBrand}"];
        if ($legalEntity !== '') {
            $entityLabel = $entityType !== '' ? "{$eEntity} (" . Render::escape($entityType) . ")" : $eEntity;
            $details[] = "<strong>Organization / Operator:</strong> {$entityLabel}";
        }
        if ($eAddr !== '') {
            $details[] = "<strong>Address:</strong> {$eAddr}";
        }
        $details[] = "<strong>Email:</strong> <a href=\"mailto:{$eEmail}\">{$eEmail}</a>";

        return array_merge($commonMeta, [
            'doc_no'   => 'LGL-06',
            'title'    => 'Publisher & Contact Information',
            'lead'     => "Official publisher particulars and direct contact details for {$brandName}.",
            'sections' => [
                [
                    'id'    => 'corporate-particulars',
                    'title' => '1. Publisher Details',
                    'body'  => '<p>' . implode('<br />', $details) . '</p>',
                ],
            ],
        ]);
    }
}

if (
    class_exists(\Lumora\Core\LegalRegistry::class, false)
    && !class_exists(\Astrake\Lumora\Core\LegalRegistry::class, false)
) {
    class_alias(\Lumora\Core\LegalRegistry::class, \Astrake\Lumora\Core\LegalRegistry::class);
}
