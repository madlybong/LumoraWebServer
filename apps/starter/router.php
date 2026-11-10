<?php
declare(strict_types=1);
/**
 * @astrake/lumora-webserver — Local Development Server Router (php -S)
 */

$uriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

// Block direct access to protected directories & config files with 403 Forbidden
$protectedPrefixes = ['/core/', '/storage/', '/templates/', '/content/', '/css/', '/node_modules/'];
foreach ($protectedPrefixes as $prefix) {
    if (str_starts_with($uriPath . '/', $prefix) || str_starts_with($uriPath, $prefix)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '403 Forbidden';
        return true;
    }
}

$protectedFiles = ['/lumora.config.php', '/lumora-lock.json', '/package.json', '/router.php', '/.htaccess'];
if (in_array($uriPath, $protectedFiles, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '403 Forbidden';
    return true;
}

// Serve static files under /public/ directly via PHP SAPI
if (str_starts_with($uriPath, '/public/')) {
    $candidate = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $uriPath);
    if (is_file($candidate)) {
        return false;
    }
}

require __DIR__ . '/index.php';
return true;
