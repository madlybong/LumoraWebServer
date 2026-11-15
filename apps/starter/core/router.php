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
        $scriptFile = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if (basename($scriptFile) === 'router.php') {
            return $GLOBALS['lumoraBasePath'] = '';
        }
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $rawDir = str_replace('\\', '/', dirname($scriptName));
        $dir = rtrim($rawDir, '/.');
        if ($dir === '' || $dir === '/') {
            return $GLOBALS['lumoraBasePath'] = '';
        }
        // If executing from a generated physical route stub (e.g. /contact/index.php),
        // compute base path relative to the website root directory.
        if ($rootDir !== null && $scriptFile !== '') {
            $realRoot = realpath($rootDir);
            $realScriptDir = realpath(dirname((string)$_SERVER['SCRIPT_FILENAME']));
            if ($realRoot !== false && $realScriptDir !== false && str_starts_with($realScriptDir, $realRoot)) {
                $subRoute = trim(str_replace('\\', '/', substr($realScriptDir, strlen($realRoot))), '/');
                if ($subRoute !== '' && str_ends_with($dir, '/' . $subRoute)) {
                    $dir = substr($dir, 0, -strlen('/' . $subRoute));
                }
            }
        }
        $normalized = rtrim(str_replace('\\', '/', $dir), '/');
        return $GLOBALS['lumoraBasePath'] = ($normalized === '' || $normalized === '/' ? '' : $normalized);
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

        // 2b. Check runtime SQLite CMS redirects when admin panel is active (zero-write fast-path)
        if (!empty($config['features']['admin_panel'])) {
            $pdo = class_exists(AdminDb::class, false) ? AdminDb::connectExisting($rootDir) : null;
            if ($pdo instanceof \PDO) {
                try {
                    $stmt = $pdo->prepare(
                        'SELECT id, target_url, status_code FROM cms_redirects WHERE is_active = 1 AND source_path IN (:p1, :p2, :p3) LIMIT 1'
                    );
                    $stmt->execute([':p1' => $path, ':p2' => $trimmedPath, ':p3' => $slashedPath]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if (is_array($row) && !empty($row['target_url'])) {
                        if (!empty($row['id'])) {
                            try {
                                $pdo->prepare('UPDATE cms_redirects SET hits = hits + 1, last_hit_at = :now WHERE id = :id')
                                    ->execute([':now' => gmdate('Y-m-d\TH:i:s\Z'), ':id' => (int)$row['id']]);
                            } catch (\Throwable) {
                                // Telemetry update must never block redirect delivery
                            }
                        }
                        $code = (int)($row['status_code'] ?? 301) === 302 ? 302 : 301;
                        return self::buildRedirect((string)$row['target_url'], $code);
                    }
                } catch (\Throwable) {
                    // Fail closed to normal routing if table is unavailable
                }
            }
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
            return self::applyRouteSeoOverride(array_merge([
                'status'         => 200,
                'canonical_path' => $path,
                'params'         => [],
                'meta'           => [],
            ], $matched), $config, $rootDir);
        }

        // 6. Match statutory legal routes: /legal/{slug}/
        if (preg_match('#^/legal/([a-z0-9-]+)/$#', $path, $m)) {
            $slug = $m[1];
            $doc = LegalRegistry::resolveDocument($slug, $config, $rootDir);
            if ($doc !== null) {
                return self::applyRouteSeoOverride([
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
                ], $config, $rootDir);
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
                    return self::applyRouteSeoOverride([
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
                    ], $config, $rootDir);
                }
            }
        }

        // 8. Match Blog Engine routes: /blog/page/{n}/ and /blog/{slug}/
        if (!empty($config['features']['blog_engine'])) {
            if (preg_match('#^/blog/page/(\d+)/$#', $path, $m)) {
                $pageNum = max(1, (int)$m[1]);
                return self::applyRouteSeoOverride([
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
                ], $config, $rootDir);
            }

            if (preg_match('#^/blog/([a-z0-9-]+)/$#', $path, $m)) {
                $slug = $m[1];
                $post = BlogEngine::getPost($slug, $rootDir);
                if ($post !== null) {
                    $postMeta = [
                        'title'       => !empty($post['meta_title'])
                            ? (string)$post['meta_title']
                            : (($post['title'] ?? $slug) . ' — ' . ($config['brand']['name'] ?? '')),
                        'description' => !empty($post['meta_description'])
                            ? (string)$post['meta_description']
                            : (string)($post['summary'] ?? $post['excerpt'] ?? ''),
                        'path'        => $path,
                        'schema_type' => 'blog',
                    ];
                    $postImage = trim((string)($post['og_image'] ?? $post['image'] ?? ''));
                    if ($postImage !== '') {
                        $postMeta['image'] = $postImage;
                    }
                    if (!empty($post['canonical_url'])) {
                        $postMeta['canonical'] = (string)$post['canonical_url'];
                    }
                    return self::applyRouteSeoOverride([
                        'template'       => 'blog-article',
                        'status'         => 200,
                        'canonical_path' => $path,
                        'meta'           => $postMeta,
                        'params'         => [
                            'slug'      => $slug,
                            'post_data' => $post,
                        ],
                    ], $config, $rootDir);
                }
            }
        }

        // 9. Unmatched route -> 404
        if (!empty($config['features']['admin_panel']) && class_exists(AdminDb::class, false)) {
            AdminDb::record404($path, (string)($_SERVER['HTTP_REFERER'] ?? ''), $rootDir);
        }
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

    private static function applyRouteSeoOverride(array $resolved, array $config, string $rootDir): array
    {
        if (empty($config['features']['admin_panel']) || !class_exists(AdminDb::class, false)) {
            return $resolved;
        }
        $pdo = AdminDb::connectExisting($rootDir);
        if (!$pdo instanceof \PDO) {
            return $resolved;
        }
        $canonicalPath = (string)($resolved['canonical_path'] ?? '');
        if ($canonicalPath === '') {
            return $resolved;
        }
        try {
            $stmt = $pdo->prepare('SELECT meta_title, meta_description, og_image, robots FROM cms_route_seo WHERE route_path = :rp LIMIT 1');
            $stmt->execute([':rp' => $canonicalPath]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $meta = is_array($resolved['meta'] ?? null) ? $resolved['meta'] : [];
                if (!empty($row['meta_title'])) {
                    $meta['title'] = (string)$row['meta_title'];
                }
                if (!empty($row['meta_description'])) {
                    $meta['description'] = (string)$row['meta_description'];
                }
                if (!empty($row['og_image'])) {
                    $meta['image'] = (string)$row['og_image'];
                }
                if (!empty($row['robots'])) {
                    $meta['robots'] = (string)$row['robots'];
                }
                $resolved['meta'] = $meta;
            }
        } catch (\Throwable) {
            // Fail closed to declared meta
        }
        return $resolved;
    }

    private static function buildRedirect(string|array $target, int $statusCode = 301): array
    {
        if (is_array($target)) {
            $dest = (string)($target['target'] ?? $target['url'] ?? '/');
            $code = (int)($target['status'] ?? $statusCode);
        } else {
            $dest = $target;
            $code = $statusCode;
        }
        $normalizedCode = $code === 302 ? 302 : 301;
        $queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($queryString !== '' && !str_contains($dest, '?')) {
            $dest .= '?' . $queryString;
        }
        return [
            'redirect' => $dest,
            'status'   => $normalizedCode,
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
