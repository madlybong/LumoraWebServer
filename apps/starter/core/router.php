<?php
declare(strict_types=1);

namespace Lumora\Core {

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Router
{
    public static function basePath(?string $rootDir = null): string
    {
        if (isset($GLOBALS['lumoraBasePath']) && is_string($GLOBALS['lumoraBasePath'])) {
            return $GLOBALS['lumoraBasePath'];
        }
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = rtrim(dirname($scriptName), '/.');
        if ($dir === '' || $dir === '/') {
            return $GLOBALS['lumoraBasePath'] = '';
        }
        // If executing from a generated physical route stub (e.g. /contact/index.php),
        // compute base path relative to the website root directory.
        if ($rootDir !== null && isset($_SERVER['SCRIPT_FILENAME'])) {
            $realRoot = realpath($rootDir);
            $realScriptDir = realpath(dirname((string)$_SERVER['SCRIPT_FILENAME']));
            if ($realRoot !== false && $realScriptDir !== false && str_starts_with($realScriptDir, $realRoot)) {
                $subRoute = trim(str_replace('\\', '/', substr($realScriptDir, strlen($realRoot))), '/');
                if ($subRoute !== '' && str_ends_with($dir, '/' . $subRoute)) {
                    $dir = substr($dir, 0, -strlen('/' . $subRoute));
                }
            }
        }
        return $GLOBALS['lumoraBasePath'] = rtrim($dir, '/');
    }

    public static function url(string $path, ?string $basePath = null): string
    {
        if (
            str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')
            || str_starts_with($path, 'mailto:')
            || str_starts_with($path, 'tel:')
            || str_starts_with($path, '#')
        ) {
            return $path;
        }
        $base = $basePath ?? self::basePath();
        $normalized = '/' . ltrim($path, '/');
        return $base !== '' ? rtrim($base, '/') . $normalized : $normalized;
    }

    public static function loadRouteConfig(string $rootDir): array
    {
        $routesFile = rtrim($rootDir, '/') . '/content/routes.php';
        if (!is_file($routesFile)) {
            return ['routes' => [], 'redirects' => []];
        }
        $loaded = require $routesFile;
        if (!is_array($loaded)) {
            return ['routes' => [], 'redirects' => []];
        }
        // Support both ['routes' => [...], 'redirects' => [...]] and flat route maps
        if (isset($loaded['routes']) && is_array($loaded['routes'])) {
            return [
                'routes'    => $loaded['routes'],
                'redirects' => is_array($loaded['redirects'] ?? null) ? $loaded['redirects'] : [],
            ];
        }
        return ['routes' => $loaded, 'redirects' => []];
    }

    /**
     * Resolves an incoming path into a route descriptor:
     * - Blocks internal directories (/core/, /storage/, /templates/, /content/, /css/) with 403
     * - Evaluates 301 redirect rules
     * - Enforces canonical trailing-slash policy (301 redirect for non-file routes)
     * - Matches static routes, legal routes, docs routes, and blog routes
     */
    public static function resolve(string $rawPath, array $config, string $rootDir): array
    {
        $path = rawurldecode($rawPath);
        if ($path === '' || $path === '/index.php') {
            $path = '/';
        } elseif (str_ends_with($path, '/index.php')) {
            $path = substr($path, 0, -strlen('/index.php')) . '/';
        }

        // 1. Block direct HTTP probing of internal directories or dotfiles
        $blockedPrefixes = ['/core/', '/storage/', '/templates/', '/content/', '/css/', '/node_modules/', '/.'];
        foreach ($blockedPrefixes as $prefix) {
            if (str_starts_with($path . '/', $prefix) || str_starts_with($path, $prefix)) {
                return ['template' => 'forbidden', 'status' => 403];
            }
        }
        if ($path === '/lumora.config.php' || $path === '/lumora-lock.json' || $path === '/package.json') {
            return ['template' => 'forbidden', 'status' => 403];
        }

        $routeConfig = self::loadRouteConfig($rootDir);
        $staticRoutes = $routeConfig['routes'];
        $redirects = $routeConfig['redirects'];

        // 2. Check explicit redirects (exact match with or without trailing slash)
        $trimmedPath = $path !== '/' ? rtrim($path, '/') : '/';
        $slashedPath = $path !== '/' ? $trimmedPath . '/' : '/';
        if (isset($redirects[$path])) {
            return self::buildRedirect($redirects[$path]);
        }
        if (isset($redirects[$trimmedPath])) {
            return self::buildRedirect($redirects[$trimmedPath]);
        }
        if (isset($redirects[$slashedPath])) {
            return self::buildRedirect($redirects[$slashedPath]);
        }

        // 3. Special file endpoint: /blog/feed.xml
        if ($path === '/blog/feed.xml' && !empty($config['features']['blog_engine'])) {
            return ['endpoint' => 'blog-feed', 'status' => 200, 'canonical_path' => '/blog/feed.xml'];
        }

        // 4. Enforce canonical trailing-slash normalization on non-file URLs
        if ($path !== '/' && !str_ends_with($path, '/') && !preg_match('/\.[a-zA-Z0-9]{1,6}$/', $path)) {
            return self::buildRedirect($path . '/');
        }

        // 5. Match static routes defined in content/routes.php
        if (isset($staticRoutes[$path])) {
            $matched = $staticRoutes[$path];
            if (!empty($matched['localhost_only_draft']) && !Security::isLocalhost()) {
                return ['template' => '404', 'status' => 404, 'canonical_path' => $path];
            }
            return array_merge([
                'status'         => 200,
                'canonical_path' => $path,
                'params'         => [],
                'meta'           => [],
            ], $matched);
        }

        // 6. Match statutory legal routes: /legal/{slug}/
        if (preg_match('#^/legal/([a-z0-9-]+)/$#', $path, $m)) {
            $slug = $m[1];
            $doc = LegalRegistry::resolveDocument($slug, $config, $rootDir);
            if ($doc !== null) {
                return [
                    'template'       => 'legal',
                    'status'         => 200,
                    'canonical_path' => $path,
                    'meta'           => [
                        'title'       => $doc['title'] . ' — ' . ($config['brand']['name'] ?? 'Lumora'),
                        'description' => $doc['lead'] ?? '',
                        'path'        => $path,
                        'schema_type' => 'legal',
                    ],
                    'params'         => [
                        'slug'       => $slug,
                        'legal_data' => $doc,
                    ],
                ];
            }
        }

        // 7. Match Documentation Engine routes: /docs/{slug}/ or /docs/{track}/{slug}/
        if (!empty($config['features']['docs_engine'])) {
            if (preg_match('#^/docs/(?:([a-z0-9-]+)/)?([a-z0-9-]+)/$#', $path, $m)) {
                $track = ($m[1] ?? '') !== '' ? $m[1] : 'operator';
                $slug = $m[2];
                $docs = new DocsEngine($rootDir . '/content/docs');
                $article = $docs->renderArticle($track, $slug);
                if ($article['found']) {
                    return [
                        'template'       => is_file($rootDir . '/templates/docs-article.php') ? 'docs-article' : 'docs',
                        'status'         => 200,
                        'canonical_path' => $path,
                        'meta'           => [
                            'title'       => $article['title'] . ' — ' . ($config['brand']['name'] ?? 'Docs'),
                            'description' => $article['summary'],
                            'path'        => $path,
                            'schema_type' => 'docs',
                        ],
                        'params'         => [
                            'doc_article'  => $article,
                            'doc_manifest' => $docs->getManifest(),
                        ],
                    ];
                }
            }
        }

        // 8. Match Blog Engine routes: /blog/page/{n}/ and /blog/{slug}/
        if (!empty($config['features']['blog_engine'])) {
            if (preg_match('#^/blog/page/(\d+)/$#', $path, $m)) {
                $pageNum = max(1, (int)$m[1]);
                return [
                    'template'       => 'blog-index',
                    'status'         => 200,
                    'canonical_path' => $path,
                    'meta'           => [
                        'title'       => 'Engineering & Insights (Page ' . $pageNum . ') — ' . ($config['brand']['name'] ?? ''),
                        'description' => 'Articles and technical architecture dispatches.',
                        'path'        => $path,
                        'schema_type' => 'blog',
                    ],
                    'params'         => [
                        'page_num' => $pageNum,
                    ],
                ];
            }

            if (preg_match('#^/blog/([a-z0-9-]+)/$#', $path, $m)) {
                $slug = $m[1];
                $post = BlogEngine::getPost($slug, $rootDir);
                if ($post !== null) {
                    return [
                        'template'       => 'blog-article',
                        'status'         => 200,
                        'canonical_path' => $path,
                        'meta'           => [
                            'title'       => ($post['title'] ?? $slug) . ' — ' . ($config['brand']['name'] ?? ''),
                            'description' => (string)($post['summary'] ?? $post['excerpt'] ?? ''),
                            'path'        => $path,
                            'schema_type' => 'blog',
                        ],
                        'params'         => [
                            'slug'      => $slug,
                            'post_data' => $post,
                        ],
                    ];
                }
            }
        }

        // 9. Unmatched route -> 404
        return [
            'template'       => '404',
            'status'         => 404,
            'canonical_path' => $path,
            'meta'           => [
                'title'       => 'Page Not Found — ' . ($config['brand']['name'] ?? 'Lumora'),
                'description' => 'The requested resource could not be found.',
                'path'        => $path,
                'robots'      => 'noindex, nofollow',
            ],
            'params'         => [],
        ];
    }

    private static function buildRedirect(string $target): array
    {
        $queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($queryString !== '' && !str_contains($target, '?')) {
            $target .= '?' . $queryString;
        }
        return [
            'redirect' => $target,
            'status'   => 301,
        ];
    }
}
}

namespace {
    if (
        class_exists(\Lumora\Core\Router::class, false)
        && !class_exists(\Astrake\Lumora\Core\Router::class, false)
    ) {
        class_alias(\Lumora\Core\Router::class, \Astrake\Lumora\Core\Router::class);
    }

    use Lumora\Core\Router;

    if (!function_exists('lumora_base_path')) {
        function lumora_base_path(): string
        {
            return Router::basePath();
        }
    }
    if (!function_exists('u')) {
        function u(string $path): string
        {
            return Router::url($path);
        }
    }
}
