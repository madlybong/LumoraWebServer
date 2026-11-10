<?php
declare(strict_types=1);

namespace Astrake\Lumora\Core;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Parameterized Multi-Territory Statutory Legal Generator with Project-Level Customization.
 * Supports territory presets ('IN' -> DPDP Act 2023 + CERT-In 2022, 'EU'/'UK' -> GDPR, 'US' -> CCPA/CPRA, 'GLOBAL')
 * while allowing any project to override or merge custom clauses via `content/legal/{slug}.php`.
 */
final class LegalRegistry
{
    /**
     * @return array<int, string>
     */
    public static function allowedSlugs(): array
    {
        return ['privacy', 'terms', 'cookies', 'accessibility', 'security', 'company'];
    }

    public static function resolveDocument(string $slug, array $config, ?string $rootDir = null): ?array
    {
        if (!in_array($slug, self::allowedSlugs(), true)) {
            return null;
        }

        $baseDoc = self::generateTerritoryDocument($slug, $config);
        $resolvedRoot = $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd());
        $overrideFile = rtrim($resolvedRoot, '/') . '/content/legal/' . $slug . '.php';

        if (!is_file($overrideFile)) {
            return $baseDoc;
        }

        $custom = require $overrideFile;
        if (!is_array($custom)) {
            return $baseDoc;
        }

        // 1. If the project override provides a full 'sections' array and does not request merging,
        // merge scalar metadata onto baseDoc and replace sections.
        if (isset($custom['sections']) && is_array($custom['sections']) && empty($custom['merge_territory'])) {
            return array_merge($baseDoc, $custom);
        }

        // 2. Otherwise support granular project-level customization on top of the territory baseline:
        //    - scalar overrides (title, lead, version, date, status, entity_name, etc.)
        //    - prepend_sections: array of sections added at the start
        //    - replace_sections: map of section 'id' => replacement section array
        //    - append_sections: array of sections added at the end
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

    public static function generateTerritoryDocument(string $slug, array $config): array
    {
        $brand = $config['brand'] ?? [];
        $legalCfg = $config['legal'] ?? [];

        $brandName = (string)($brand['name'] ?? 'Lumora Platform');
        $legalEntity = (string)($brand['legal_entity'] ?? $brandName);
        $entityType = (string)($legalCfg['entity_type'] ?? 'Enterprise Entity');
        $domain = (string)($brand['domain'] ?? 'localhost');
        $version = (string)($brand['version'] ?? '0.1.0');
        $effectiveDate = (string)($legalCfg['effective_date'] ?? '2026-11-10');
        $email = (string)($legalCfg['grievance_email'] ?? $brand['contact_email'] ?? ('office@' . $domain));
        $grievanceOfficer = (string)($legalCfg['grievance_officer'] ?? 'Grievance & Data Protection Officer');
        $piiDays = max(1, (int)($legalCfg['retention_days_pii'] ?? 90));
        $logDays = max($piiDays, (int)($legalCfg['retention_days_logs'] ?? 180));

        $addr = is_array($brand['address'] ?? null) ? $brand['address'] : [];
        $formattedAddr = trim(implode(', ', array_filter([
            (string)($addr['street'] ?? ''),
            (string)($addr['locality'] ?? ''),
            (string)($addr['region'] ?? ''),
            (string)($addr['postal_code'] ?? ''),
            (string)($addr['country'] ?? 'IN'),
        ])));
        if ($formattedAddr === '') {
            $formattedAddr = (string)($legalCfg['registered_address'] ?? 'Registered Enterprise Office');
        }

        $territories = is_array($legalCfg['territories'] ?? null)
            ? array_map('strtoupper', $legalCfg['territories'])
            : [strtoupper((string)($addr['country'] ?? 'IN')), 'EU', 'GLOBAL'];

        $hasIndia = in_array('IN', $territories, true);
        $hasEu = in_array('EU', $territories, true) || in_array('UK', $territories, true);
        $hasUs = in_array('US', $territories, true);

        $commonMeta = [
            'version'     => $version,
            'date'        => $effectiveDate,
            'status'      => 'ACTIVE STATUTORY POLICY',
            'entity_name' => $legalEntity,
            'entity_type' => $entityType,
            'address'     => $formattedAddr,
            'email'       => $email,
            'territories' => $territories,
        ];

        $eBrand = Render::escape($brandName);
        $eEntity = Render::escape($legalEntity);
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
            if (empty($regList)) {
                $regList[] = 'International Data Protection Standards';
            }
            $regSummary = implode(', ', $regList);

            $sections = [
                [
                    'id'    => 'architecture',
                    'title' => '1. Zero-Surveillance Architectural Invariants',
                    'body'  => "<p><strong>{$eBrand}</strong> (operated by <strong>{$eEntity}</strong>) enforces a stateless, privacy-sovereign web architecture. We deploy zero tracking cookies, zero advertising beacons, zero behavioral fingerprinting scripts, and zero third-party CDNs. All typography, stylesheets, and scripts are self-hosted directly on our primary origin.</p>",
                ],
                [
                    'id'    => 'fiduciary',
                    'title' => '2. Data Fiduciary, Controller & Statutory Contact',
                    'body'  => "<p>Under {$regSummary}, the Data Fiduciary and Controller is:</p>"
                        . "<p><strong>{$eEntity}</strong><br />"
                        . "Designated Officer: {$eOfficer}<br />"
                        . "Registered Office: {$eAddr}<br />"
                        . "Electronic Portal: <a href=\"mailto:{$eEmail}\">{$eEmail}</a> · <a href=\"/contact/?topic=privacy\">/contact/?topic=privacy</a></p>"
                        . ($hasIndia ? "<p><strong>Eighth Schedule Language Accessibility (DPDP Act 2023 Sec. 5(3)):</strong> Data Principals may request an official copy of this Privacy Notice in Hindi, Bengali, or any language specified in the Eighth Schedule to the Constitution of India via <a href=\"mailto:{$eEmail}\">{$eEmail}</a>.</p>" : ''),
                ],
                [
                    'id'    => 'processing',
                    'title' => '3. Itemized Data Inventory & Purpose Limitation',
                    'body'  => "<p>Personal data is processed strictly when you voluntarily submit an inquiry via <a href=\"/contact/\">/contact/</a>:</p>"
                        . "<ul>"
                        . "<li><strong>Identity &amp; Contact Data:</strong> Full Name, Organization, Email Address, and optional Phone Number (required only for on-site assessments).</li>"
                        . "<li><strong>Contextual Inquiry Data:</strong> Selected Channel, Subject, Specification Message, and channel-specific parameters.</li>"
                        . "<li><strong>Anti-Abuse Security Telemetry:</strong> Keyed HMAC-SHA256 hash of client IP (<code>ip_hash</code>; raw IP addresses are never stored), truncated User-Agent, and stateless HMAC CSRF verification tokens used solely to enforce rate limits and prevent automated abuse.</li>"
                        . "</ul>",
                ],
                [
                    'id'    => 'retention',
                    'title' => '4. Statutory Data Retention & Automated Redaction Schedule',
                    'body'  => "<p>Inquiry records are persisted in an encrypted/access-controlled SQLite Write-Ahead Logging (WAL) vault and governed by automated lifecycle pruning:</p>"
                        . "<ul>"
                        . "<li><strong>Prospective Inquiry PII ({$piiDays} Calendar Days):</strong> Automatically and irreversibly redacted (<code>[REDACTED-90D]</code>) after {$piiDays} days" . ($hasIndia ? ' in accordance with DPDP Act 2023 Sec. 8(7)' : '') . ".</li>"
                        . "<li><strong>Anonymized Security Audit Logs ({$logDays} Calendar Days):</strong> Keyed <code>ip_hash</code> and rate-limit events are retained for {$logDays} days" . ($hasIndia ? ' per CERT-In Directions (April 28, 2022)' : '') . " and permanently purged thereafter.</li>"
                        . "</ul>",
                ],
                [
                    'id'    => 'rights',
                    'title' => '5. Data Principal Rights & Grievance Redressal',
                    'body'  => "<p>You hold enforceable rights to request access, correction, completion, erasure, consent withdrawal, and nomination (where applicable under {$regSummary}). Submit requests directly through our <a href=\"/contact/?topic=privacy\">Data Privacy &amp; Statutory Rights Portal</a> or via email at <a href=\"mailto:{$eEmail}\">{$eEmail}</a>.</p>",
                ],
            ];

            return array_merge($commonMeta, [
                'doc_no'   => 'LGL-01',
                'title'    => 'Privacy Notice & Data Protection Policy',
                'lead'     => "Architectural specification of zero-surveillance design, data minimization, and statutory privacy rights at {$brandName} ({$legalEntity}) aligned with {$regSummary}.",
                'sections' => $sections,
            ]);
        }

        if ($slug === 'terms') {
            $jurisdiction = (string)($legalCfg['governing_law'] ?? (($addr['locality'] ?? 'Kolkata') . ', ' . ($addr['country'] ?? 'India')));
            $eJur = Render::escape($jurisdiction);
            return array_merge($commonMeta, [
                'doc_no'   => 'LGL-02',
                'title'    => 'Terms of Use & Commercial Conditions',
                'lead'     => "Legal terms governing access to {$brandName} digital properties, documentation, and enterprise inquiry channels operated by {$legalEntity}.",
                'sections' => [
                    [
                        'id'    => 'acceptance',
                        'title' => '1. Scope & Acceptance',
                        'body'  => "<p>By accessing this website operated by <strong>{$eEntity}</strong> ({$eAddr}), you agree to comply with these Terms of Use and all applicable laws.</p>",
                    ],
                    [
                        'id'    => 'ip',
                        'title' => '2. Intellectual Property & Trademarks',
                        'body'  => "<p>All software architectures, technical schematics, documentation, visual designs, and trademarks associated with <strong>{$eBrand}</strong> are the proprietary intellectual property of <strong>{$eEntity}</strong> unless explicitly licensed otherwise.</p>",
                    ],
                    [
                        'id'    => 'acceptable-use',
                        'title' => '3. Acceptable Use & Security Prohibition',
                        'body'  => "<p>Automated scraping, denial-of-service flooding, unauthorized vulnerability scanning, or bypassing rate limits on <a href=\"/contact/\">/contact/</a> is strictly prohibited. Coordinated security research must follow <a href=\"/legal/security/\">/legal/security/</a>.</p>",
                    ],
                    [
                        'id'    => 'governing-law',
                        'title' => '4. Governing Law & Jurisdiction',
                        'body'  => "<p>These Terms are governed by the laws applicable in <strong>{$eJur}</strong>, with exclusive jurisdiction vested in the competent courts of {$eJur}.</p>",
                    ],
                ],
            ]);
        }

        if ($slug === 'cookies') {
            return array_merge($commonMeta, [
                'doc_no'   => 'LGL-03',
                'title'    => 'Zero-Cookie & Stateless Session Declaration',
                'lead'     => "Technical verification that {$brandName} sets zero HTTP cookies, zero localStorage trackers, and zero third-party beacons.",
                'sections' => [
                    [
                        'id'    => 'zero-cookie',
                        'title' => '1. Zero-Cookie Architecture',
                        'body'  => "<p><strong>{$eBrand}</strong> does not emit <code>Set-Cookie</code> headers for analytics, advertising, or session tracking. CSRF protection is achieved via CGNAT-resilient stateless HMAC-SHA256 tokens bound to timestamp and User-Agent hash.</p>",
                    ],
                ],
            ]);
        }

        if ($slug === 'accessibility') {
            return array_merge($commonMeta, [
                'doc_no'   => 'LGL-04',
                'title'    => 'Accessibility Conformance Statement (WCAG 2.2 AA)',
                'lead'     => "Conformance declaration for {$brandName} under W3C WCAG 2.2 Level AA, EN 301 549, and RPwD Act 2016 standards.",
                'sections' => [
                    [
                        'id'    => 'conformance',
                        'title' => '1. WCAG 2.2 Level AA Engineering Standards',
                        'body'  => "<p><strong>{$eBrand}</strong> is engineered for full keyboard navigation, visible focus rings (<code>:focus-visible</code>), semantic landmarks, first-class <code>prefers-reduced-motion: reduce</code> enforcement, and high-contrast typography. To report an accessibility barrier, contact <a href=\"mailto:{$eEmail}\">{$eEmail}</a>.</p>",
                    ],
                ],
            ]);
        }

        if ($slug === 'security') {
            return array_merge($commonMeta, [
                'doc_no'   => 'LGL-05',
                'title'    => 'Security Architecture & Coordinated Vulnerability Disclosure',
                'lead'     => "OWASP ASVS v4.0 Level 2 transport hardening, CSP nonce isolation, and ISO/IEC 29147 vulnerability disclosure policy for {$brandName}.",
                'sections' => [
                    [
                        'id'    => 'transport-hardening',
                        'title' => '1. Transport & Application Hardening',
                        'body'  => "<p>Every HTTP response from <strong>{$eBrand}</strong> enforces a per-request 128-bit CSPRNG Content-Security-Policy (CSP) nonce, Subresource Integrity (SHA-384 SRI) on self-hosted fonts and assets, HSTS preload, and strict framing isolation (<code>X-Frame-Options: DENY</code>).</p>",
                    ],
                    [
                        'id'    => 'disclosure',
                        'title' => '2. Coordinated Vulnerability Disclosure (RFC 9116)',
                        'body'  => "<p>Security researchers may report potential vulnerabilities via <a href=\"/contact/?topic=security\">/contact/?topic=security</a> or <a href=\"mailto:{$eEmail}\">{$eEmail}</a> (see <a href=\"/.well-known/security.txt\">/.well-known/security.txt</a>). We acknowledge reports within 24 hours.</p>",
                    ],
                ],
            ]);
        }

        // 'company'
        return array_merge($commonMeta, [
            'doc_no'   => 'LGL-06',
            'title'    => 'Corporate Identity & Statutory Disclosures',
            'lead'     => "Official corporate registration, registered office address, and statutory communication channels for {$legalEntity}.",
            'sections' => [
                [
                    'id'    => 'corporate-particulars',
                    'title' => '1. Registered Entity Particulars',
                    'body'  => "<p><strong>Brand / Platform:</strong> {$eBrand}<br />"
                        . "<strong>Legal Entity:</strong> {$eEntity} ({$commonMeta['entity_type']})<br />"
                        . "<strong>Registered Office:</strong> {$eAddr}<br />"
                        . "<strong>Official Electronic Mail:</strong> <a href=\"mailto:{$eEmail}\">{$eEmail}</a><br />"
                        . "<strong>Direct Inquiry &amp; Grievance Desk:</strong> <a href=\"/contact/\">/contact/</a> · <a href=\"/contact/?topic=privacy\">/contact/?topic=privacy</a></p>",
                ],
            ],
        ]);
    }
}
