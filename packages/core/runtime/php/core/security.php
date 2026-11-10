<?php
declare(strict_types=1);

namespace Astrake\Lumora\Core {

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Security
{
    private static ?string $cspNonce = null;
    private static ?string $secretKey = null;
    private static string $secretKeyRoot = '';
    private static array $config = [];
    private static string $rootDir = '';

    public static function boot(array $config, string $rootDir): void
    {
        self::$config = $config;
        self::$rootDir = rtrim(str_replace('\\', '/', $rootDir), '/');
        self::$secretKey = null;
        self::$secretKeyRoot = '';

        // 1. Backward-compatible bootstrap constants for brand partials
        foreach (['ASTRAKE_BOOTSTRAPPED', 'AURUVI_BOOTSTRAPPED', 'DRISVA_BOOTSTRAPPED', 'ORINA_WEB_BOOTSTRAPPED'] as $legacyConst) {
            if (!defined($legacyConst)) {
                define($legacyConst, true);
            }
        }

        // 2. Production error suppression (OWASP ASVS v4.0 Sec 7.4)
        if (!self::isLocalhost()) {
            @ini_set('display_errors', '0');
            @ini_set('display_startup_errors', '0');
            @ini_set('log_errors', '1');
        }

        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }

        // 3. Canonical HTTPS & non-www redirect in production
        $domain = (string)($config['brand']['domain'] ?? '');
        $secCfg = $config['security'] ?? [];
        if (!self::isLocalhost() && PHP_SAPI !== 'cli' && !headers_sent() && $domain !== '') {
            $hostHeader = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
                || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';

            $shouldStripWww = ($secCfg['strip_www_redirect'] ?? true) && $hostHeader === 'www.' . strtolower($domain);
            $shouldForceHttps = ($secCfg['canonical_https_redirect'] ?? true) && !$isHttps;

            if ($shouldStripWww || $shouldForceHttps) {
                $reqUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
                if ($reqUri === '' || $reqUri[0] !== '/' || str_starts_with($reqUri, '//')) {
                    $reqUri = '/';
                }
                header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
                header('Location: https://' . $domain . $reqUri, true, 301);
                exit;
            }
        }

        // 4. Generate 128-bit CSPRNG CSP Nonce
        self::$cspNonce = base64_encode(random_bytes(16));
        $GLOBALS['lumoraCspNonce'] = self::$cspNonce;
        $GLOBALS['astrakeCspNonce'] = self::$cspNonce;
        $GLOBALS['cspNonce'] = self::$cspNonce;
        $GLOBALS['orinaCspNonce'] = self::$cspNonce;

        // 5. Emit Mandatory 8-Header Enterprise Security Suite
        self::emitSecurityHeaders();
    }

    public static function cspNonce(): string
    {
        if (self::$cspNonce === null) {
            self::$cspNonce = base64_encode(random_bytes(16));
        }
        return self::$cspNonce;
    }

    public static function isLocalhost(): bool
    {
        if (getenv('LUMORA_FORCE_PRODUCTION') === '1' || getenv('ASTRAKE_FORCE_PRODUCTION') === '1') {
            return false;
        }
        $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $host = preg_replace('/:\d+$/', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));
        return PHP_SAPI === 'cli'
            || in_array($remoteAddr, ['127.0.0.1', '::1'], true)
            || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public static function emitSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        $secCfg = self::$config['security'] ?? [];
        $nonce = self::cspNonce();
        $styleSrc = !empty($secCfg['csp_style_unsafe_inline']) ? "'self' 'unsafe-inline'" : "'self'";
        $mediaSrc = !empty($secCfg['csp_media_self']) ? "'self'" : "'none'";

        $csp = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src {$styleSrc}",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "media-src {$mediaSrc}",
            "object-src 'none'",
            "child-src 'none'",
            "frame-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];
        if (!self::isLocalhost()) {
            $csp[] = 'upgrade-insecure-requests';
        }

        header('Content-Security-Policy: ' . implode('; ', $csp));
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header(
            'Permissions-Policy: accelerometer=(), autoplay=(), browsing-topics=(), camera=(), '
            . 'display-capture=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), '
            . 'hid=(), idle-detection=(), interest-cohort=(), local-fonts=(), magnetometer=(), '
            . 'microphone=(), midi=(), payment=(), picture-in-picture=(), publickey-credentials-get=(), '
            . 'screen-wake-lock=(), serial=(), sync-xhr=(), usb=(), web-share=(), xr-spatial-tracking=()'
        );
    }

    public static function secretKey(?string $rootDir = null): string
    {
        $resolvedRoot = rtrim(str_replace('\\', '/', $rootDir ?? (self::$rootDir !== '' ? self::$rootDir : (string)($GLOBALS['lumoraRootDir'] ?? getcwd()))), '/');
        if (self::$secretKey !== null && self::$secretKeyRoot === $resolvedRoot) {
            return self::$secretKey;
        }
        self::$secretKeyRoot = $resolvedRoot;

        $envSecret = getenv('LUMORA_CSRF_SECRET') ?: getenv('ASTRAKE_CSRF_SECRET');
        if (is_string($envSecret) && strlen(trim($envSecret)) >= 32) {
            return self::$secretKey = trim($envSecret);
        }

        $storageDir = $resolvedRoot . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0750, true);
        }
        $guardIndex = $storageDir . '/index.php';
        if (!is_file($guardIndex)) {
            @file_put_contents(
                $guardIndex,
                "<?php\ndeclare(strict_types=1);\nif (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }\nhttp_response_code(403);\nexit('403 Forbidden');\n",
                LOCK_EX
            );
        }
        $guardHtaccess = $storageDir . '/.htaccess';
        if (!is_file($guardHtaccess)) {
            @file_put_contents(
                $guardHtaccess,
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
                LOCK_EX
            );
        }

        $secretFile = $storageDir . '/csrf-secret.php';
        if (is_file($secretFile) && is_readable($secretFile)) {
            $loaded = include $secretFile;
            if (is_string($loaded) && strlen(trim($loaded)) >= 64) {
                return self::$secretKey = trim($loaded);
            }
        }

        $generated = bin2hex(random_bytes(32));
        $phpPayload = "<?php\ndeclare(strict_types=1);\nif (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }\nreturn '{$generated}';\n";
        if (@file_put_contents($secretFile, $phpPayload, LOCK_EX) !== false) {
            @chmod($secretFile, 0600);
            return self::$secretKey = $generated;
        }

        return self::$secretKey = hash('sha256', (string)filemtime(__FILE__) . '|lumora_fallback_secret_v2|' . php_uname());
    }

    public static function vaultDir(?string $rootDir = null): string
    {
        $resolvedRoot = rtrim(str_replace('\\', '/', $rootDir ?? (self::$rootDir !== '' ? self::$rootDir : (string)($GLOBALS['lumoraRootDir'] ?? getcwd()))), '/');
        $secret = self::secretKey($resolvedRoot);
        $vaultId = substr(hash_hmac('sha256', 'lumora-storage-vault-v1', $secret), 0, 32);
        $dir = $resolvedRoot . '/storage/vault-' . $vaultId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $guardFile = $dir . '/index.php';
        if (!is_file($guardFile)) {
            @file_put_contents(
                $guardFile,
                "<?php\ndeclare(strict_types=1);\nif (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }\nhttp_response_code(403);\nexit('403 Forbidden');\n",
                LOCK_EX
            );
        }
        return $dir;
    }

    public static function clientIpHash(?string $rootDir = null): string
    {
        $rawIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $trustProxy = !empty(self::$config['security']['trust_proxy_headers']);
        if ($trustProxy) {
            $candidates = [
                (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
                (string)($_SERVER['HTTP_X_REAL_IP'] ?? ''),
                explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '',
            ];
            foreach ($candidates as $cand) {
                $trimmed = trim($cand);
                if ($trimmed !== '' && filter_var($trimmed, FILTER_VALIDATE_IP) !== false) {
                    $rawIp = $trimmed;
                    break;
                }
            }
        }
        return hash_hmac('sha256', 'lumora_ip|' . $rawIp, self::secretKey($rootDir));
    }

    /**
     * Stateless v2 HMAC-SHA256 CSRF Token (CGNAT-safe: bound to timestamp + 128-bit nonce + brand ID + UA hash).
     */
    public static function csrfToken(?string $rootDir = null, int $now = 0): string
    {
        $tsHex = dechex($now > 0 ? $now : time());
        $nonceHex = bin2hex(random_bytes(16));
        $uaHash = substr(hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? 'lumora-ua')), 0, 16);
        $brandId = (string)(self::$config['brand']['id'] ?? 'lumora');
        $mac = hash_hmac('sha256', "csrf_lumora_v2|{$brandId}|{$tsHex}|{$nonceHex}|{$uaHash}", self::secretKey($rootDir));
        return "{$tsHex}.{$nonceHex}.{$mac}";
    }

    public static function verifyCsrf(?string $rootDir, ?string $token, int $now = 0): bool
    {
        if (empty($token) || substr_count($token, '.') !== 2) {
            return false;
        }
        [$tsHex, $nonceHex, $providedMac] = explode('.', trim($token), 3);
        if (
            !ctype_xdigit($tsHex)
            || !ctype_xdigit($nonceHex)
            || strlen($nonceHex) !== 32
            || !ctype_xdigit($providedMac)
            || strlen($providedMac) !== 64
        ) {
            return false;
        }
        $issuedAt = (int)hexdec($tsHex);
        $currentTime = $now > 0 ? $now : time();
        $ttl = (int)(self::$config['security']['csrf_ttl_sec'] ?? 7200);
        $age = $currentTime - $issuedAt;
        if ($age < -60 || $age > $ttl) {
            return false;
        }
        $uaHash = substr(hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? 'lumora-ua')), 0, 16);
        $brandId = (string)(self::$config['brand']['id'] ?? 'lumora');
        $expectedMac = hash_hmac('sha256', "csrf_lumora_v2|{$brandId}|{$tsHex}|{$nonceHex}|{$uaHash}", self::secretKey($rootDir));
        return hash_equals($expectedMac, strtolower($providedMac));
    }
}
}

namespace {
    use Astrake\Lumora\Core\Security;

    if (!function_exists('lumora_csp_nonce')) {
        function lumora_csp_nonce(): string
        {
            return Security::cspNonce();
        }
    }
    if (!function_exists('lumora_csrf_token')) {
        function lumora_csrf_token(): string
        {
            return Security::csrfToken();
        }
    }
    if (!function_exists('lumora_verify_csrf')) {
        function lumora_verify_csrf(?string $token): bool
        {
            return Security::verifyCsrf(null, $token);
        }
    }
    if (!function_exists('lumora_client_ip_hash')) {
        function lumora_client_ip_hash(): string
        {
            return Security::clientIpHash();
        }
    }
    if (!function_exists('lumora_is_localhost')) {
        function lumora_is_localhost(): bool
        {
            return Security::isLocalhost();
        }
    }
}
