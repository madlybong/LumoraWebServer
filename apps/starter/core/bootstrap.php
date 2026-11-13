<?php
declare(strict_types=1);

namespace Lumora\Core;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/schema-graph.php';
require_once __DIR__ . '/router.php';
require_once __DIR__ . '/discovery.php';
require_once __DIR__ . '/inquiry-db.php';
require_once __DIR__ . '/hub-client.php';
require_once __DIR__ . '/inquiry-engine.php';
require_once __DIR__ . '/legal-registry.php';
require_once __DIR__ . '/docs-engine.php';
require_once __DIR__ . '/blog-engine.php';
require_once __DIR__ . '/release-server.php';

final class Bootstrap
{
    private static array $config = [];
    private static string $rootDir = '';

    public static function init(string $rootDir): array
    {
        self::$rootDir = rtrim(str_replace('\\', '/', $rootDir), '/');
        $configFile = self::$rootDir . '/lumora.config.php';
        if (!is_file($configFile)) {
            http_response_code(500);
            exit('Missing lumora.config.php');
        }

        $loaded = require $configFile;
        self::$config = is_array($loaded) ? $loaded : [];
        $GLOBALS['lumoraConfig'] = self::$config;
        $GLOBALS['lumoraRootDir'] = self::$rootDir;

        Security::boot(self::$config, self::$rootDir);
        Render::boot(self::$config, self::$rootDir);

        return self::$config;
    }

    public static function config(): array
    {
        return self::$config;
    }

    public static function rootDir(): string
    {
        return self::$rootDir;
    }

    /**
     * Full front-controller request lifecycle:
     * 1. Boots config, security headers, CSP nonce, and view helpers
     * 2. Normalizes base path and resolves route
     * 3. Dispatches discovery endpoints (/health, /robots.txt, /sitemap.xml, /llms.txt, etc.)
     * 4. Handles 403 blocks & 301 redirects
     * 5. Renders the matched brand template with injected route context
     */
    public static function handleRequest(string $rootDir): void
    {
        $config = self::init($rootDir);
        $basePath = Router::basePath($rootDir);

        $rawUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $parsedPath = (string)(parse_url($rawUri, PHP_URL_PATH) ?: '/');
        $rawQuery = (string)(parse_url($rawUri, PHP_URL_QUERY) ?? '');
        if ($rawQuery !== '' && empty($_GET)) {
            parse_str($rawQuery, $_GET);
        }

        if ($basePath !== '' && str_starts_with($parsedPath, $basePath)) {
            $relativePath = substr($parsedPath, strlen($basePath));
        } else {
            $relativePath = $parsedPath;
        }
        if ($relativePath === '' || $relativePath === false) {
            $relativePath = '/';
        }

        // Allow physical stub entrypoints (dist/<route>/index.php) to force their canonical route
        if (isset($GLOBALS['lumoraForcedRoute']) && is_string($GLOBALS['lumoraForcedRoute'])) {
            $relativePath = $GLOBALS['lumoraForcedRoute'];
            unset($GLOBALS['lumoraForcedRoute']);
        }

        // Check discovery & system endpoints first (/health, /robots.txt, /sitemap.xml, /llms.txt, etc.)
        if (Discovery::tryServe($relativePath, $config, self::$rootDir, $basePath)) {
            return;
        }

        // Check binary release server endpoints if enabled (/releases/*)
        if (!empty($config['features']['release_server']) && ReleaseServer::tryServe($relativePath, $config, self::$rootDir)) {
            return;
        }

        $route = Router::resolve($relativePath, $config, self::$rootDir);

        // Handle 403 Forbidden internal probes
        if (($route['status'] ?? 200) === 403 || ($route['template'] ?? '') === 'forbidden') {
            http_response_code(403);
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo '403 Forbidden';
            return;
        }

        // Handle 301 / 302 redirects
        if (!empty($route['redirect'])) {
            $status = (int)($route['status'] ?? 301);
            http_response_code($status);
            if (!headers_sent()) {
                $target = (string)$route['redirect'];
                header('Location: ' . Router::url($target, $basePath), true, $status);
            }
            return;
        }

        // Handle Atom feed if routed via blog engine
        if (($route['endpoint'] ?? '') === 'blog-feed') {
            BlogEngine::serveAtomFeed($config, self::$rootDir, $basePath);
            return;
        }

        $httpStatus = (int)($route['status'] ?? 200);
        http_response_code($httpStatus);

        $templateName = (string)($route['template'] ?? '404');
        Render::renderTemplate($templateName, array_merge($route['params'] ?? [], [
            'route'       => $route,
            'meta'        => $route['meta'] ?? [],
            'currentPath' => $route['canonical_path'] ?? $relativePath,
            'config'      => $config,
        ]));
    }
}

if (
    class_exists(\Lumora\Core\Bootstrap::class, false)
    && !class_exists(\Astrake\Lumora\Core\Bootstrap::class, false)
) {
    class_alias(\Lumora\Core\Bootstrap::class, \Astrake\Lumora\Core\Bootstrap::class);
}
