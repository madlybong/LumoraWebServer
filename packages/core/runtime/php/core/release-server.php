<?php
declare(strict_types=1);

namespace Lumora\Core;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Headless Binary Artifact, Release Manifest, and SHA256SUMS Server (/releases/*).
 */
final class ReleaseServer
{
    public static function tryServe(string $path, array $config, string $rootDir): bool
    {
        if (!str_starts_with($path, '/releases/')) {
            return false;
        }

        $releasesDir = getenv('LUMORA_RELEASES_DIR') ?: ($rootDir . '/releases');
        $brand = $config['brand'] ?? [];

        if ($path === '/releases/release-manifest.json') {
            $manifestFile = $releasesDir . '/release-manifest.json';
            if (is_file($manifestFile)) {
                $json = (string)@file_get_contents($manifestFile);
            } else {
                $json = (string)json_encode([
                    'product'       => (string)($brand['name'] ?? 'Lumora'),
                    'legalOwner'    => (string)($brand['legal_entity'] ?? $brand['name'] ?? 'Lumora'),
                    'domain'        => (string)($brand['domain'] ?? 'localhost'),
                    'version'       => (string)($brand['version'] ?? '0.1.0'),
                    'installerMode' => 'offline-only',
                    'artifacts'     => [],
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }
            http_response_code(200);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-cache');
            }
            echo $json;
            return true;
        }

        if ($path === '/releases/SHA256SUMS') {
            $sumsFile = $releasesDir . '/SHA256SUMS';
            $body = is_file($sumsFile) ? (string)@file_get_contents($sumsFile) : '';
            http_response_code(200);
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
                header('Cache-Control: no-cache');
            }
            echo $body;
            return true;
        }

        $subPath = substr($path, strlen('/releases/'));
        $artifact = basename($subPath);
        if ($artifact === '' || $artifact !== $subPath || str_contains($artifact, '..')) {
            http_response_code(400);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode(['ok' => false, 'error' => 'Invalid artifact name'], JSON_UNESCAPED_SLASHES);
            return true;
        }

        $foundFile = $releasesDir . '/' . $artifact;
        if (!is_file($foundFile)) {
            http_response_code(404);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode(['ok' => false, 'error' => 'Artifact not found: ' . $artifact], JSON_UNESCAPED_SLASHES);
            return true;
        }

        $mime = str_ends_with($artifact, '.apk')
            ? 'application/vnd.android.package-archive'
            : 'application/octet-stream';

        $fileSize = (int)filesize($foundFile);
        $sha256 = (string)hash_file('sha256', $foundFile);
        $start = 0;
        $end = max(0, $fileSize - 1);
        $statusCode = 200;

        $rangeHeader = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
        if ($rangeHeader !== '' && $fileSize > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $m)) {
            $reqStart = $m[1] !== '' ? (int)$m[1] : null;
            $reqEnd = $m[2] !== '' ? (int)$m[2] : null;

            if ($reqStart === null && $reqEnd !== null) {
                $suffixLen = $reqEnd;
                $start = max(0, $fileSize - $suffixLen);
                $end = $fileSize - 1;
            } elseif ($reqStart !== null) {
                $start = $reqStart;
                $end = $reqEnd !== null ? min($fileSize - 1, $reqEnd) : ($fileSize - 1);
            }

            if ($start > $end || $start >= $fileSize) {
                http_response_code(416);
                if (!headers_sent()) {
                    header("Content-Range: bytes */{$fileSize}");
                }
                return true;
            }
            $statusCode = 206;
        }

        $length = ($fileSize === 0) ? 0 : ($end - $start + 1);
        http_response_code($statusCode);
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . (string)$length);
            if ($statusCode === 206) {
                header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
            }
            header('Content-Disposition: attachment; filename="' . $artifact . '"');
            header('X-Checksum-Sha256: ' . $sha256);
        }

        if ($length > 0) {
            $fp = @fopen($foundFile, 'rb');
            if (is_resource($fp)) {
                if ($start > 0) {
                    @fseek($fp, $start);
                }
                $remaining = $length;
                while ($remaining > 0 && !feof($fp)) {
                    $chunk = @fread($fp, min(65536, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
                @fclose($fp);
            }
        }
        return true;
    }
}

if (
    class_exists(\Lumora\Core\ReleaseServer::class, false)
    && !class_exists(\Astrake\Lumora\Core\ReleaseServer::class, false)
) {
    class_alias(\Lumora\Core\ReleaseServer::class, \Astrake\Lumora\Core\ReleaseServer::class);
}
