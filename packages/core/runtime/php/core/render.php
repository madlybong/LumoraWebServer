<?php
declare(strict_types=1);

namespace Lumora\Core {

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Render
{
    private static array $config = [];
    private static string $rootDir = '';
    private static ?array $lockData = null;

    public static function boot(array $config, string $rootDir): void
    {
        self::$config = $config;
        self::$rootDir = $rootDir;
        self::$lockData = null;
    }

    public static function lockData(?string $rootDir = null): array
    {
        if (self::$lockData !== null && $rootDir === null) {
            return self::$lockData;
        }
        $resolvedRoot = $rootDir ?? (self::$rootDir !== '' ? self::$rootDir : (string)($GLOBALS['lumoraRootDir'] ?? getcwd()));
        $lockFile = rtrim($resolvedRoot, '/') . '/lumora-lock.json';
        if (!is_file($lockFile)) {
            return self::$lockData = ['sri' => []];
        }
        $decoded = json_decode((string)@file_get_contents($lockFile), true);
        return self::$lockData = is_array($decoded) ? $decoded : ['sri' => []];
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    public static function assetSri(string $assetPath, ?string $rootDir = null): string
    {
        $clean = '/' . ltrim(str_replace('\\', '/', $assetPath), '/');
        $lock = self::lockData($rootDir);
        return (string)($lock['sri'][$clean] ?? '');
    }

    public static function assetUrl(string $assetPath, ?string $rootDir = null): string
    {
        $url = Router::url($assetPath);
        if (!str_contains($url, '?') && (str_ends_with($assetPath, '.css') || str_ends_with($assetPath, '.js'))) {
            $sri = self::assetSri($assetPath, $rootDir);
            if ($sri !== '') {
                $ver = substr(hash('sha256', $sri), 0, 8);
                return $url . '?v=' . $ver;
            }
        }
        return $url;
    }

    public static function readingTime(string $textOrHtml, int $wpm = 230): int
    {
        $cleanText = strip_tags($textOrHtml);
        $words = preg_split('/\s+/u', trim($cleanText), -1, PREG_SPLIT_NO_EMPTY);
        $count = is_array($words) ? count($words) : 0;
        return max(1, (int)ceil($count / max(100, $wpm)));
    }

    public static function component(string $name, array $props = [], ?string $rootDir = null): void
    {
        $resolvedRoot = $rootDir ?? (self::$rootDir !== '' ? self::$rootDir : (string)($GLOBALS['lumoraRootDir'] ?? getcwd()));
        $cleanName = preg_replace('/[^a-z0-9_\/-]/', '', strtolower($name)) ?? '';
        $cleanName = str_replace('..', '', $cleanName);

        $candidates = [
            $resolvedRoot . '/templates/components/' . $cleanName . '.php',
            $resolvedRoot . '/templates/layout/' . $cleanName . '.php',
            $resolvedRoot . '/includes/components/' . $cleanName . '.php',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                extract($props, EXTR_SKIP);
                include $file;
                return;
            }
        }
    }

    public static function renderTemplate(string $templateName, array $context = [], ?string $rootDir = null): void
    {
        $resolvedRoot = $rootDir ?? (self::$rootDir !== '' ? self::$rootDir : (string)($GLOBALS['lumoraRootDir'] ?? getcwd()));
        $clean = preg_replace('/[^a-z0-9_\/-]/', '', strtolower($templateName)) ?? '404';
        $clean = str_replace('..', '', $clean);

        $templateFile = $resolvedRoot . '/templates/' . $clean . '.php';
        if (!is_file($templateFile)) {
            http_response_code(404);
            $templateFile = $resolvedRoot . '/templates/404.php';
        }
        if (is_file($templateFile)) {
            extract($context, EXTR_SKIP);
            require $templateFile;
        } else {
            echo '404 Not Found';
        }
    }

    /**
     * Emits SEO, OpenGraph, Twitter Card, hreflang, AI discovery links, font preloads (with SRI),
     * compiled stylesheet link (with SRI), and Schema.org JSON-LD @graph script (with CSP nonce).
     */
    public static function headMeta(array $meta = [], ?array $config = null, ?string $rootDir = null): string
    {
        $cfg = $config ?? self::$config;
        $resolvedRoot = $rootDir ?? (self::$rootDir !== '' ? self::$rootDir : (string)($GLOBALS['lumoraRootDir'] ?? getcwd()));
        $brand = $cfg['brand'] ?? [];
        $assets = $cfg['assets'] ?? [];

        $brandName = (string)($brand['name'] ?? 'Lumora Website');
        $origin = rtrim((string)($brand['origin'] ?? 'https://localhost'), '/');
        $locale = (string)($brand['locale'] ?? 'en');
        $ogLocale = str_replace('-', '_', $locale);
        $themeColor = (string)($brand['theme_color'] ?? '#0a0a0a');
        $colorScheme = (string)($brand['color_scheme'] ?? 'light');

        $title = (string)($meta['title'] ?? ($brandName . ' — ' . ($brand['slogan'] ?? 'Enterprise Platform')));
        $description = (string)($meta['description'] ?? ($brand['description'] ?? $brand['slogan'] ?? ''));
        $routePath = (string)($meta['path'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));
        $routePath = (string)(parse_url($routePath, PHP_URL_PATH) ?: '/');
        $canonicalUrl = (string)($meta['canonical'] ?? ($origin . ($routePath === '/' ? '/' : '/' . ltrim($routePath, '/'))));
        $robots = (string)($meta['robots'] ?? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1');
        $ogType = (string)($meta['type'] ?? 'website');
        $ogImagePath = (string)($meta['image'] ?? ($brand['og_image'] ?? '/public/brand/og-default.png'));
        $ogImageUrl = str_starts_with($ogImagePath, 'http') ? $ogImagePath : ($origin . '/' . ltrim($ogImagePath, '/'));

        $eTitle = self::escape($title);
        $eDesc = self::escape($description);
        $eCanonical = self::escape($canonicalUrl);
        $eRobots = self::escape($robots);
        $eBrand = self::escape($brandName);
        $eOgLocale = self::escape($ogLocale);
        $eHreflang = self::escape(strtolower($locale));
        $eOgType = self::escape($ogType);
        $eOgImage = self::escape($ogImageUrl);
        $eThemeColor = self::escape($themeColor);
        $eColorScheme = self::escape($colorScheme);

        $lines = [
            "<title>{$eTitle}</title>",
            "<meta name=\"description\" content=\"{$eDesc}\" />",
            "<meta name=\"robots\" content=\"{$eRobots}\" />",
            "<meta name=\"theme-color\" content=\"{$eThemeColor}\" />",
            "<meta name=\"color-scheme\" content=\"{$eColorScheme}\" />",
            "<link rel=\"canonical\" href=\"{$eCanonical}\" />",
            "<link rel=\"alternate\" hreflang=\"{$eHreflang}\" href=\"{$eCanonical}\" />",
            "<link rel=\"alternate\" hreflang=\"x-default\" href=\"{$eCanonical}\" />",
        ];

        $rootForDisc = self::$rootDir !== '' ? self::$rootDir : (string)($GLOBALS['lumoraRootDir'] ?? '');
        if (Discovery::isEndpointEnabled('llms_txt', $config, $rootForDisc)) {
            $lines[] = "<link rel=\"alternate\" type=\"text/plain\" title=\"AI Context Manifest\" href=\"{$origin}/llms.txt\" />";
            $lines[] = "<link rel=\"alternate\" type=\"text/plain\" title=\"AI Full Knowledge Specification\" href=\"{$origin}/llms-full.txt\" />";
        }
        if (Discovery::isEndpointEnabled('webmanifest', $config, $rootForDisc)) {
            $lines[] = "<link rel=\"manifest\" href=\"" . self::escape(self::assetUrl('/site.webmanifest')) . "\" />";
        }

        array_push(
            $lines,
            "<meta property=\"og:site_name\" content=\"{$eBrand}\" />",
            "<meta property=\"og:locale\" content=\"{$eOgLocale}\" />",
            "<meta property=\"og:type\" content=\"{$eOgType}\" />",
            "<meta property=\"og:title\" content=\"{$eTitle}\" />",
            "<meta property=\"og:description\" content=\"{$eDesc}\" />",
            "<meta property=\"og:url\" content=\"{$eCanonical}\" />",
            "<meta property=\"og:image\" content=\"{$eOgImage}\" />",
            "<meta name=\"twitter:card\" content=\"summary_large_image\" />",
            "<meta name=\"twitter:title\" content=\"{$eTitle}\" />",
            "<meta name=\"twitter:description\" content=\"{$eDesc}\" />",
            "<meta name=\"twitter:image\" content=\"{$eOgImage}\" />"
        );

        // Preload self-hosted WOFF2 fonts with SHA-384 SRI
        $fonts = is_array($assets['fonts'] ?? null) ? $assets['fonts'] : [];
        foreach ($fonts as $fontPath) {
            if (!is_string($fontPath) || $fontPath === '') {
                continue;
            }
            $sri = self::assetSri($fontPath, $resolvedRoot);
            $sriAttr = $sri !== '' ? ' integrity="' . self::escape($sri) . '"' : '';
            $href = self::escape(self::assetUrl($fontPath));
            $lines[] = "<link rel=\"preload\" href=\"{$href}\" as=\"font\" type=\"font/woff2\" crossorigin=\"anonymous\"{$sriAttr} />";
        }

        // Compiled CSS with SHA-384 SRI
        $cssPath = (string)($assets['compiled_css'] ?? '/public/assets/css/lumora.min.css');
        if ($cssPath !== '') {
            $cssSri = self::assetSri($cssPath, $resolvedRoot);
            $cssSriAttr = $cssSri !== '' ? ' integrity="' . self::escape($cssSri) . '" crossorigin="anonymous"' : '';
            $cssHref = self::escape(self::assetUrl($cssPath, $resolvedRoot));
            $lines[] = "<link rel=\"stylesheet\" href=\"{$cssHref}\"{$cssSriAttr} />";
        }

        // Deterministic Schema.org JSON-LD @graph with CSP Nonce
        $nonce = self::escape(Security::cspNonce());
        $schemaGraph = SchemaGraph::build($cfg, array_merge($meta, [
            'title'       => $title,
            'description' => $description,
            'canonical'   => $canonicalUrl,
            'image'       => $ogImageUrl,
            'path'        => $routePath,
        ]));
        $jsonLd = json_encode($schemaGraph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($jsonLd)) {
            $lines[] = "<script type=\"application/ld+json\" nonce=\"{$nonce}\">{$jsonLd}</script>";
        }

        return implode("\n    ", $lines);
    }

    public static function coreScriptTag(?array $config = null, ?string $rootDir = null): string
    {
        $cfg = $config ?? self::$config;
        $resolvedRoot = $rootDir ?? (self::$rootDir !== '' ? self::$rootDir : (string)($GLOBALS['lumoraRootDir'] ?? getcwd()));
        $jsPath = (string)($cfg['assets']['core_js'] ?? '/public/assets/js/lumora-core.js');
        if ($jsPath === '') {
            return '';
        }
        $sri = self::assetSri($jsPath, $resolvedRoot);
        $sriAttr = $sri !== '' ? ' integrity="' . self::escape($sri) . '" crossorigin="anonymous"' : '';
        $nonce = self::escape(Security::cspNonce());
        $src = self::escape(self::assetUrl($jsPath, $resolvedRoot));
        return "<script type=\"module\" src=\"{$src}\" nonce=\"{$nonce}\"{$sriAttr}></script>";
    }
}
}

namespace {
    if (
        class_exists(\Lumora\Core\Render::class, false)
        && !class_exists(\Astrake\Lumora\Core\Render::class, false)
    ) {
        class_alias(\Lumora\Core\Render::class, \Astrake\Lumora\Core\Render::class);
    }

    use Lumora\Core\Render;

    if (!function_exists('e')) {
        function e(?string $value): string
        {
            return Render::escape($value);
        }
    }
    if (!function_exists('component')) {
        function component(string $name, array $props = []): void
        {
            Render::component($name, $props);
        }
    }
    if (!function_exists('asset_url')) {
        function asset_url(string $path): string
        {
            return Render::assetUrl($path);
        }
    }
    if (!function_exists('asset_sri')) {
        function asset_sri(string $path): string
        {
            return Render::assetSri($path);
        }
    }
    if (!function_exists('reading_time')) {
        function reading_time(string $textOrHtml): int
        {
            return Render::readingTime($textOrHtml);
        }
    }
    if (!function_exists('lumora_head_meta')) {
        function lumora_head_meta(array $meta = []): string
        {
            return Render::headMeta($meta);
        }
    }
    if (!function_exists('lumora_core_script')) {
        function lumora_core_script(): string
        {
            return Render::coreScriptTag();
        }
    }
    if (!function_exists('render_template')) {
        function render_template(string $templateName, array $context = []): void
        {
            Render::renderTemplate($templateName, $context);
        }
    }
}
