<?php
declare(strict_types=1);

namespace Astrake\Lumora\Core;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Discovery
{
    /**
     * Intercepts and serves deterministic machine-readable discovery endpoints:
     * - /health
     * - /robots.txt
     * - /sitemap.xml
     * - /llms.txt
     * - /llms-full.txt
     * - /.well-known/security.txt & /security.txt
     * - /site.webmanifest
     * - /_lumora/outbox/flush (POST HMAC-authenticated outbox replay)
     */
    public static function tryServe(string $path, array $config, string $rootDir, string $basePath = ''): bool
    {
        $normalized = $path !== '/' ? rtrim($path, '/') : '/';

        if ($normalized === '/health') {
            http_response_code(200);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-store');
            }
            $brand = $config['brand'] ?? [];
            echo json_encode([
                'status'     => 'ok',
                'framework'  => '@astrake/lumora-webserver',
                'brand_id'   => (string)($brand['id'] ?? 'lumora'),
                'brand_name' => (string)($brand['name'] ?? 'Lumora'),
                'version'    => (string)($brand['version'] ?? '0.1.0'),
                'timestamp'  => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES);
            return true;
        }

        if ($path === '/robots.txt') {
            http_response_code(200);
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo self::renderRobotsTxt($config, $basePath);
            return true;
        }

        if ($path === '/sitemap.xml') {
            http_response_code(200);
            if (!headers_sent()) {
                header('Content-Type: application/xml; charset=UTF-8');
            }
            echo self::renderSitemapXml($config, $rootDir);
            return true;
        }

        if ($path === '/llms.txt') {
            http_response_code(200);
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
            }
            $customFile = $rootDir . '/content/llms.txt';
            if (is_file($customFile)) {
                echo (string)@file_get_contents($customFile);
            } else {
                echo self::renderLlmsTxt($config, $rootDir, false);
            }
            return true;
        }

        if ($path === '/llms-full.txt') {
            http_response_code(200);
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
            }
            $customFile = $rootDir . '/content/llms-full.txt';
            if (is_file($customFile)) {
                echo (string)@file_get_contents($customFile);
            } else {
                echo self::renderLlmsTxt($config, $rootDir, true);
            }
            return true;
        }

        if ($path === '/.well-known/security.txt' || $path === '/security.txt') {
            http_response_code(200);
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
            }
            $customWellKnown = $rootDir . '/.well-known/security.txt';
            $customRoot = $rootDir . '/security.txt';
            if (is_file($customWellKnown)) {
                echo (string)@file_get_contents($customWellKnown);
            } elseif (is_file($customRoot)) {
                echo (string)@file_get_contents($customRoot);
            } else {
                echo self::renderSecurityTxt($config);
            }
            return true;
        }

        if ($path === '/site.webmanifest') {
            http_response_code(200);
            if (!headers_sent()) {
                header('Content-Type: application/manifest+json; charset=UTF-8');
            }
            $customManifest = $rootDir . '/site.webmanifest';
            if (is_file($customManifest)) {
                echo (string)@file_get_contents($customManifest);
            } else {
                echo self::renderWebManifest($config, $basePath);
            }
            return true;
        }

        if ($normalized === '/_lumora/outbox/flush' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            self::handleOutboxFlush($config, $rootDir);
            return true;
        }

        return false;
    }

    public static function renderRobotsTxt(array $config, string $basePath = ''): string
    {
        $origin = rtrim((string)($config['brand']['origin'] ?? 'https://localhost'), '/');
        return <<<TXT
User-agent: *
Allow: /
Disallow: /core/
Disallow: /storage/
Disallow: /templates/
Disallow: /content/
Disallow: /css/
Disallow: /_lumora/

# AI & LLM Search Discovery Crawlers (GEO / AIO)
User-agent: GPTBot
Allow: /

User-agent: OAI-SearchBot
Allow: /

User-agent: ChatGPT-User
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: anthropic-ai
Allow: /

User-agent: Google-Extended
Allow: /

User-agent: Applebot-Extended
Allow: /

Sitemap: {$origin}/sitemap.xml
TXT;
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

        // Include statutory legal routes
        foreach (LegalRegistry::allowedSlugs() as $slug) {
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
        $legalEntity = (string)($brand['legal_entity'] ?? $brandName);
        $origin = rtrim((string)($brand['origin'] ?? 'https://localhost'), '/');
        $slogan = (string)($brand['slogan'] ?? 'Enterprise Web Platform');
        $description = (string)($brand['description'] ?? $slogan);

        $routeConfig = Router::loadRouteConfig($rootDir);
        $routes = $routeConfig['routes'];

        $pageLines = [];
        foreach ($routes as $routePath => $routeDef) {
            if (!empty($routeDef['localhost_only_draft'])) {
                continue;
            }
            $title = (string)($routeDef['meta']['title'] ?? $routePath);
            $desc = (string)($routeDef['meta']['description'] ?? $slogan);
            $url = $origin . ($routePath === '/' ? '/' : '/' . ltrim((string)$routePath, '/'));
            $pageLines[] = "- [{$title}]({$url}): {$desc}";
        }
        $pagesBlock = implode("\n", $pageLines);

        $out = <<<MD
# {$brandName} — {$slogan}

> {$description}

## Organization & Governance
- **Brand:** {$brandName}
- **Legal Entity:** {$legalEntity}
- **Canonical Origin:** {$origin}/
- **Security & Vulnerability Disclosure:** {$origin}/.well-known/security.txt
- **Data Privacy & DPDP Portal:** {$origin}/legal/privacy/

## Canonical Pages & Resources
{$pagesBlock}
MD;

        if ($full) {
            $out .= "\n\n## Statutory Legal & Compliance Endpoints\n";
            foreach (LegalRegistry::allowedSlugs() as $slug) {
                $doc = LegalRegistry::resolveDocument($slug, $config, $rootDir);
                if ($doc !== null) {
                    $out .= "- [{$doc['title']}]({$origin}/legal/{$slug}/): {$doc['lead']}\n";
                }
            }

            if (!empty($config['features']['docs_engine'])) {
                $docs = new DocsEngine($rootDir . '/content/docs');
                $manifest = $docs->getManifest();
                $out .= "\n## Documentation Chapters\n";
                foreach ($manifest['tracks'] as $track) {
                    foreach ($track['articles'] ?? [] as $art) {
                        $slug = (string)($art['slug'] ?? '');
                        $title = (string)($art['title'] ?? '');
                        $summary = (string)($art['summary'] ?? '');
                        $out .= "- [{$title}]({$origin}/docs/{$slug}/): {$summary}\n";
                    }
                }
            }
        }

        return $out . "\n";
    }

    public static function renderSecurityTxt(array $config): string
    {
        $brand = $config['brand'] ?? [];
        $origin = rtrim((string)($brand['origin'] ?? 'https://localhost'), '/');
        $email = (string)($brand['security_email'] ?? $brand['contact_email'] ?? ('security@' . ($brand['domain'] ?? 'localhost')));
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 365 * 86400);

        return <<<TXT
Contact: mailto:{$email}
Contact: {$origin}/contact/?topic=security
Expires: {$expires}
Preferred-Languages: en
Canonical: {$origin}/.well-known/security.txt
Policy: {$origin}/legal/security/
TXT;
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
