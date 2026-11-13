<?php
declare(strict_types=1);

namespace Lumora\Core;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Discovery
{
    public static function isEndpointEnabled(string $endpoint, array $config, string $rootDir = ''): bool
    {
        $disc = is_array($config['discovery'] ?? null) ? $config['discovery'] : [];
        $brand = is_array($config['brand'] ?? null) ? $config['brand'] : [];

        return match ($endpoint) {
            'health'       => ($disc['health'] ?? true) !== false,
            'robots_txt'   => ($disc['robots_txt'] ?? true) !== false,
            'sitemap_xml'  => ($disc['sitemap_xml'] ?? true) !== false,
            'llms_txt'     => ($disc['llms_txt'] ?? true) !== false,
            'webmanifest'  => ($disc['webmanifest'] ?? true) !== false,
            'security_txt' => array_key_exists('security_txt', $disc) && $disc['security_txt'] !== null
                ? (bool)$disc['security_txt']
                : (!empty($brand['security_email']) || ($rootDir !== '' && is_file(rtrim($rootDir, '/') . '/.well-known/security.txt'))),
            default => true,
        };
    }

    public static function tryServe(string $path, array $config, string $rootDir, string $basePath = ''): bool
    {
        $normalized = $path !== '/' ? rtrim($path, '/') : '/';

        if ($normalized === '/health') {
            if (!self::isEndpointEnabled('health', $config, $rootDir)) {
                return false;
            }
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-store, max-age=0');
            }
            $frameworkLabel = (string)($config['brand']['framework_label'] ?? 'lumora-webserver');
            echo json_encode([
                'ok'         => true,
                'status'     => 'healthy',
                'brand'      => (string)($config['brand']['id'] ?? 'lumora'),
                'version'    => (string)($config['brand']['version'] ?? '0.1.2'),
                'framework'  => $frameworkLabel,
                'php'        => PHP_VERSION,
                'ts_utc'     => gmdate('Y-m-d\TH:i:s\Z'),
            ], JSON_UNESCAPED_SLASHES);
            return true;
        }

        if ($path === '/robots.txt') {
            if (!self::isEndpointEnabled('robots_txt', $config, $rootDir)) {
                return false;
            }
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
                header('Cache-Control: public, max-age=3600');
            }
            echo self::renderRobotsTxt($config, $rootDir);
            return true;
        }

        if ($path === '/sitemap.xml') {
            if (!self::isEndpointEnabled('sitemap_xml', $config, $rootDir)) {
                return false;
            }
            if (!headers_sent()) {
                header('Content-Type: application/xml; charset=UTF-8');
                header('Cache-Control: public, max-age=3600');
            }
            echo self::renderSitemapXml($config, $rootDir);
            return true;
        }

        if ($path === '/llms.txt') {
            if (!self::isEndpointEnabled('llms_txt', $config, $rootDir)) {
                return false;
            }
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
                header('Cache-Control: public, max-age=3600');
            }
            $override = self::loadLlmsOverride($config, $rootDir, false);
            if ($override !== null) {
                echo $override;
                return true;
            }
            echo self::renderLlmsTxt($config, $rootDir, false);
            return true;
        }

        if ($path === '/llms-full.txt') {
            if (!self::isEndpointEnabled('llms_txt', $config, $rootDir)) {
                return false;
            }
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
                header('Cache-Control: public, max-age=3600');
            }
            $override = self::loadLlmsOverride($config, $rootDir, true);
            if ($override !== null) {
                echo $override;
                return true;
            }
            echo self::renderLlmsTxt($config, $rootDir, true);
            return true;
        }

        if ($path === '/.well-known/security.txt' || $path === '/security.txt') {
            if (!self::isEndpointEnabled('security_txt', $config, $rootDir)) {
                return false;
            }
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
                header('Cache-Control: public, max-age=86400');
            }
            $customFile = $rootDir . '/.well-known/security.txt';
            if (is_file($customFile) && is_readable($customFile)) {
                readfile($customFile);
                return true;
            }
            echo self::renderSecurityTxt($config, $rootDir);
            return true;
        }

        if ($path === '/site.webmanifest') {
            if (!self::isEndpointEnabled('webmanifest', $config, $rootDir)) {
                return false;
            }
            if (!headers_sent()) {
                header('Content-Type: application/manifest+json; charset=UTF-8');
                header('Cache-Control: public, max-age=86400');
            }
            echo self::renderWebManifest($config, $basePath);
            return true;
        }

        // Hub-triggered signed outbox flush endpoint
        if ($normalized === '/_lumora/outbox/flush' && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            self::handleOutboxFlush($config, $rootDir);
            return true;
        }

        return false;
    }

    private static function loadLlmsOverride(array $config, string $rootDir, bool $full): ?string
    {
        $phpOverride = $rootDir . '/content/llms.php';
        if (is_file($phpOverride) && is_readable($phpOverride)) {
            $result = (static function (string $file, array $config, string $rootDir, bool $full) {
                return require $file;
            })($phpOverride, $config, $rootDir, $full);

            if (is_string($result)) {
                return $result;
            }
            if (is_array($result)) {
                $candidate = $full
                    ? ($result['full'] ?? $result['summary'] ?? null)
                    : ($result['summary'] ?? $result['full'] ?? null);
                if (is_string($candidate)) {
                    return $candidate;
                }
            }
        }

        $txtFile = $rootDir . ($full ? '/content/llms-full.txt' : '/content/llms.txt');
        if (is_file($txtFile) && is_readable($txtFile)) {
            $content = file_get_contents($txtFile);
            return is_string($content) ? $content : null;
        }

        return null;
    }

    public static function renderRobotsTxt(array $config, string $rootDir = ''): string
    {
        $origin = rtrim((string)($config['brand']['origin'] ?? 'https://localhost'), '/');
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /core/',
            'Disallow: /storage/',
            'Disallow: /templates/',
            'Disallow: /content/',
            'Disallow: /css/',
            'Disallow: /_lumora/',
            '',
            '# AI & LLM Search Discovery Crawlers',
            'User-agent: GPTBot',
            'Allow: /',
            '',
            'User-agent: OAI-SearchBot',
            'Allow: /',
            '',
            'User-agent: ChatGPT-User',
            'Allow: /',
            '',
            'User-agent: PerplexityBot',
            'Allow: /',
            '',
            'User-agent: ClaudeBot',
            'Allow: /',
            '',
            'User-agent: anthropic-ai',
            'Allow: /',
            '',
            'User-agent: Google-Extended',
            'Allow: /',
            '',
            'User-agent: Applebot-Extended',
            'Allow: /',
        ];

        if (self::isEndpointEnabled('sitemap_xml', $config, $rootDir)) {
            $lines[] = '';
            $lines[] = "Sitemap: {$origin}/sitemap.xml";
        }

        return implode("\n", $lines);
    }

    public static function renderSitemapXml(array $config, string $rootDir): string
    {
        $origin = rtrim((string)($config['brand']['origin'] ?? 'https://localhost'), '/');
        $today = gmdate('Y-m-d');
        $routeConfig = Router::loadRouteConfig($rootDir);
        $routes = $routeConfig['routes'];

        $urls = [];
        $seen = [];

        foreach ($routes as $routePath => $routeDef) {
            if (!empty($routeDef['localhost_only_draft'])) {
                continue;
            }
            $robots = (string)($routeDef['meta']['robots'] ?? '');
            if (str_contains($robots, 'noindex')) {
                continue;
            }
            $loc = $origin . ($routePath === '/' ? '/' : '/' . ltrim((string)$routePath, '/'));
            if (isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $urls[] = [
                'loc'        => $loc,
                'priority'   => $routePath === '/' ? '1.0' : (string)($routeDef['priority'] ?? '0.8'),
                'changefreq' => (string)($routeDef['changefreq'] ?? ($routePath === '/' ? 'weekly' : 'monthly')),
                'images'     => is_array($routeDef['images'] ?? null) ? $routeDef['images'] : [],
            ];
        }

        // Include app-configured active legal routes
        foreach (LegalRegistry::activeSlugs($config, $rootDir) as $slug) {
            $loc = $origin . '/legal/' . $slug . '/';
            if (!isset($seen[$loc])) {
                $seen[$loc] = true;
                $urls[] = [
                    'loc'        => $loc,
                    'priority'   => '0.6',
                    'changefreq' => 'yearly',
                    'images'     => [],
                ];
            }
        }

        // Include docs articles if enabled
        if (!empty($config['features']['docs_engine'])) {
            $docs = new DocsEngine($rootDir . '/content/docs');
            $manifest = $docs->getManifest();
            foreach ($manifest['tracks'] as $track) {
                foreach ($track['articles'] ?? [] as $art) {
                    $slug = (string)($art['slug'] ?? '');
                    if ($slug !== '') {
                        $loc = $origin . '/docs/' . rawurlencode($slug) . '/';
                        if (!isset($seen[$loc])) {
                            $seen[$loc] = true;
                            $urls[] = [
                                'loc'        => $loc,
                                'priority'   => '0.8',
                                'changefreq' => 'monthly',
                                'images'     => [],
                            ];
                        }
                    }
                }
            }
        }

        // Include blog posts if enabled
        if (!empty($config['features']['blog_engine'])) {
            $posts = BlogEngine::listPosts($rootDir, false);
            foreach ($posts as $post) {
                $slug = (string)($post['slug'] ?? '');
                if ($slug !== '') {
                    $loc = $origin . '/blog/' . rawurlencode($slug) . '/';
                    if (!isset($seen[$loc])) {
                        $seen[$loc] = true;
                        $urls[] = [
                            'loc'        => $loc,
                            'priority'   => '0.7',
                            'changefreq' => 'monthly',
                            'images'     => [],
                        ];
                    }
                }
            }
        }

        $xmlItems = '';
        foreach ($urls as $u) {
            $loc = Render::escape($u['loc']);
            $imgXml = '';
            foreach ($u['images'] as $img) {
                if (empty($img['loc'])) {
                    continue;
                }
                $imgLoc = str_starts_with((string)$img['loc'], 'http')
                    ? (string)$img['loc']
                    : $origin . '/' . ltrim((string)$img['loc'], '/');
                $eImgLoc = Render::escape($imgLoc);
                $eImgTitle = Render::escape((string)($img['title'] ?? ''));
                $imgXml .= "    <image:image>\n      <image:loc>{$eImgLoc}</image:loc>\n      <image:title>{$eImgTitle}</image:title>\n    </image:image>\n";
            }
            $xmlItems .= "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$today}</lastmod>\n    <changefreq>{$u['changefreq']}</changefreq>\n    <priority>{$u['priority']}</priority>\n{$imgXml}  </url>\n";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n{$xmlItems}</urlset>\n";
    }

    public static function renderLlmsTxt(array $config, string $rootDir, bool $full = false): string
    {
        $brand = $config['brand'] ?? [];
        $brandName = (string)($brand['name'] ?? 'Lumora');
        $legalEntity = trim((string)($brand['legal_entity'] ?? ''));
        $slogan = (string)($brand['slogan'] ?? ($brand['description'] ?? ''));
        $origin = rtrim((string)($brand['origin'] ?? 'https://localhost'), '/');

        $routeConfig = Router::loadRouteConfig($rootDir);
        $routes = $routeConfig['routes'];
        $activeLegalSlugs = LegalRegistry::activeSlugs($config, $rootDir);

        $lines = [
            "# {$brandName}",
            '',
            "> {$slogan}",
            '',
            '## Overview',
            "- **Brand:** {$brandName}",
        ];

        if ($legalEntity !== '' && !empty($brand['expose_legal_entity_in_llms'])) {
            $lines[] = "- **Legal Entity:** {$legalEntity}";
        }

        $lines[] = "- **Canonical Origin:** {$origin}/";

        if (self::isEndpointEnabled('security_txt', $config, $rootDir)) {
            $lines[] = "- **Security Disclosure:** {$origin}/.well-known/security.txt";
        }
        if (in_array('privacy', $activeLegalSlugs, true)) {
            $lines[] = "- **Privacy Policy:** {$origin}/legal/privacy/";
        }

        $lines[] = '';
        $lines[] = '## Public Pages';

        foreach ($routes as $path => $def) {
            if (!empty($def['localhost_only_draft'])) {
                continue;
            }
            $title = (string)($def['meta']['title'] ?? $path);
            $desc = (string)($def['meta']['description'] ?? '');
            $url = $origin . ($path === '/' ? '/' : '/' . ltrim((string)$path, '/'));
            $lines[] = "- [{$title}]({$url}): {$desc}";
        }
        $lines[] = '';

        if (!empty($config['features']['docs_engine'])) {
            $lines[] = '## Documentation';
            $docs = new DocsEngine($rootDir . '/content/docs');
            $manifest = $docs->getManifest();
            foreach ($manifest['tracks'] as $trackKey => $track) {
                $trackTitle = (string)($track['title'] ?? ucfirst((string)$trackKey));
                $lines[] = "### {$trackTitle}";
                foreach ($track['articles'] ?? [] as $art) {
                    $slug = (string)($art['slug'] ?? '');
                    $title = (string)($art['title'] ?? $slug);
                    $summary = (string)($art['summary'] ?? '');
                    $docUrl = $origin . '/docs/' . rawurlencode($slug) . '/';
                    $lines[] = $summary !== ''
                        ? "- [{$title}]({$docUrl}): {$summary}"
                        : "- [{$title}]({$docUrl})";
                }
            }
            $lines[] = '';
        }

        if (!empty($config['features']['blog_engine'])) {
            $posts = BlogEngine::listPosts($rootDir, false);
            if (!empty($posts)) {
                $lines[] = '## Articles & Updates';
                foreach ($posts as $post) {
                    $slug = (string)($post['slug'] ?? '');
                    $title = (string)($post['title'] ?? $slug);
                    $summary = (string)($post['summary'] ?? '');
                    if ($slug !== '') {
                        $postUrl = $origin . '/blog/' . rawurlencode($slug) . '/';
                        $lines[] = $summary !== ''
                            ? "- [{$title}]({$postUrl}): {$summary}"
                            : "- [{$title}]({$postUrl})";
                    }
                }
                $lines[] = '';
            }
        }

        if ($full && !empty($activeLegalSlugs)) {
            $lines[] = '## Legal & Policies';
            foreach ($activeLegalSlugs as $slug) {
                $doc = LegalRegistry::resolveDocument($slug, $config, $rootDir);
                if (is_array($doc)) {
                    $lines[] = "- [{$doc['title']}]({$origin}/legal/{$slug}/): {$doc['lead']}";
                }
            }
        }

        return implode("\n", $lines);
    }

    public static function renderSecurityTxt(array $config, string $rootDir = ''): string
    {
        $brand = $config['brand'] ?? [];
        $disc = $config['discovery'] ?? [];
        $origin = rtrim((string)($brand['origin'] ?? 'https://localhost'), '/');
        $domain = (string)($brand['domain'] ?? 'localhost');
        $secEmail = (string)($brand['security_email'] ?? $brand['contact_email'] ?? ('security@' . $domain));
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 86400 * 180);

        $lines = [
            "Contact: mailto:{$secEmail}",
        ];

        $customContact = trim((string)($disc['security_txt_contact'] ?? ''));
        if ($customContact !== '') {
            $contactUrl = str_starts_with($customContact, 'http')
                ? $customContact
                : $origin . '/' . ltrim($customContact, '/');
            $lines[] = "Contact: {$contactUrl}";
        }

        $lines[] = "Expires: {$expires}";
        $lines[] = 'Preferred-Languages: en';
        $lines[] = "Canonical: {$origin}/.well-known/security.txt";

        $customPolicy = trim((string)($disc['security_txt_policy'] ?? ''));
        if ($customPolicy !== '') {
            $policyUrl = str_starts_with($customPolicy, 'http')
                ? $customPolicy
                : $origin . '/' . ltrim($customPolicy, '/');
            $lines[] = "Policy: {$policyUrl}";
        } else {
            $activeSlugs = LegalRegistry::activeSlugs($config, $rootDir);
            if (in_array('security', $activeSlugs, true)) {
                $lines[] = "Policy: {$origin}/legal/security/";
            } elseif (in_array('privacy', $activeSlugs, true)) {
                $lines[] = "Policy: {$origin}/legal/privacy/";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public static function renderWebManifest(array $config, string $basePath = ''): string
    {
        $brand = $config['brand'] ?? [];
        $name = (string)($brand['name'] ?? 'Lumora');
        $shortName = (string)($brand['short_name'] ?? $name);
        $themeColor = (string)($brand['theme_color'] ?? '#0a0a0a');
        $bgColor = (string)($brand['background_color'] ?? '#ffffff');
        $startUrl = Router::url('/', $basePath);

        return (string)json_encode([
            'name'             => $name,
            'short_name'       => $shortName,
            'description'      => (string)($brand['slogan'] ?? ''),
            'start_url'        => $startUrl,
            'display'          => 'standalone',
            'background_color' => $bgColor,
            'theme_color'      => $themeColor,
            'icons'            => [
                [
                    'src'   => Router::url('/public/brand/icon.svg', $basePath),
                    'sizes' => 'any',
                    'type'  => 'image/svg+xml',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private static function handleOutboxFlush(array $config, string $rootDir): void
    {
        $secret = HubClient::resolveHubSecret($config, $rootDir);
        $sigHeader = (string)($_SERVER['HTTP_X_LUMORA_SIGNATURE'] ?? '');
        $tsHeader = (string)($_SERVER['HTTP_X_LUMORA_TIMESTAMP'] ?? '');
        $rawBody = (string)file_get_contents('php://input');

        if ($secret === '' || !HubClient::verifySignature($rawBody, $tsHeader, $sigHeader, $secret)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'Unauthorized outbox flush request'], JSON_UNESCAPED_SLASHES);
            return;
        }

        $result = HubClient::flushPendingOutbox($config, $rootDir, 25);
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge(['ok' => true], $result), JSON_UNESCAPED_SLASHES);
    }
}

if (
    class_exists(\Lumora\Core\Discovery::class, false)
    && !class_exists(\Astrake\Lumora\Core\Discovery::class, false)
) {
    class_alias(\Lumora\Core\Discovery::class, \Astrake\Lumora\Core\Discovery::class);
}
