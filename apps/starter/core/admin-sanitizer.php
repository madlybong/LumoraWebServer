<?php

declare(strict_types=1);

namespace Lumora\Core;

use DOMDocument;
use DOMElement;
use DOMNode;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AdminSanitizer
{
    private const DROP_ELEMENTS = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet',
        'svg', 'math', 'form', 'input', 'button', 'textarea',
        'select', 'option', 'link', 'meta', 'base', 'template',
        'noscript', 'frame', 'frameset', 'audio', 'video', 'source',
    ];

    private const ALLOWED_ELEMENTS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'del',
        'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'a', 'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'span', 'div', 'sup', 'sub', 'mark', 'kbd',
    ];

    private const ALLOWED_MIME_EXT = [
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
    ];

    public static function sanitizeHtml(string $html, ?string $rootDir = null): string
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return '';
        }

        if (!class_exists(DOMDocument::class)) {
            return '<p>' . htmlspecialchars(strip_tags($trimmed), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $prevUseErrors = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="__lumora_frag__">' . $trimmed . '</div></body></html>';
        $loaded = @$doc->loadHTML($wrapped, LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);

        if (!$loaded) {
            return '<p>' . htmlspecialchars(strip_tags($trimmed), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $container = $doc->getElementById('__lumora_frag__');
        if (!$container instanceof DOMElement) {
            return '';
        }

        self::sanitizeChildren($container, $doc, $rootDir);

        $out = '';
        foreach ($container->childNodes as $child) {
            $chunk = $doc->saveHTML($child);
            if (is_string($chunk)) {
                $out .= $chunk;
            }
        }
        return trim($out);
    }

    public static function sanitizePlainText(string $value, int $maxLength = 500): string
    {
        $clean = trim(strip_tags($value));
        $clean = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);
        if (function_exists('mb_substr')) {
            return mb_substr($clean, 0, $maxLength, 'UTF-8');
        }
        return substr($clean, 0, $maxLength);
    }

    public static function isSafeUrl(string $url, bool $allowMailAndTel = false): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return false;
        }
        // Reject protocol-relative //evil.com and control chars
        if (str_starts_with($trimmed, '//') || preg_match('/[\x00-\x20]/', $trimmed) === 1) {
            return false;
        }
        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, '#')) {
            return true;
        }
        $lower = strtolower($trimmed);
        if (str_starts_with($lower, 'https://') || str_starts_with($lower, 'http://')) {
            return filter_var($trimmed, FILTER_VALIDATE_URL) !== false;
        }
        if ($allowMailAndTel && (str_starts_with($lower, 'mailto:') || str_starts_with($lower, 'tel:'))) {
            return true;
        }
        return false;
    }

    /**
     * Strictly validates and stores raster image uploads (.webp, .avif, .png, .jpg) into public/uploads/YYYY-MM/.
     * Explicitly rejects SVGs, PHP scripts, polyglots, and un-dimensioned files.
     *
     * @param array<string, mixed> $file $_FILES entry
     * @param array<string, mixed> $config Lumora config
     * @return array<string, mixed>
     */
    public static function processImageUpload(array $file, string $rootDir, array $config, string $altText = '', int $fallbackWidth = 0, int $fallbackHeight = 0): array
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed with error code ' . $err . '.'];
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        $origName = (string)($file['name'] ?? 'upload');
        $size = (int)($file['size'] ?? 0);
        $maxBytes = max(1024, (int)($config['admin']['max_upload_bytes'] ?? 5242880));

        if ($tmpPath === '' || !is_file($tmpPath) || !is_readable($tmpPath)) {
            return ['ok' => false, 'error' => 'Uploaded temporary file is unreadable.'];
        }
        if ($size <= 0 || $size > $maxBytes) {
            return ['ok' => false, 'error' => 'Image size must be between 1 byte and ' . $maxBytes . ' bytes.'];
        }

        // Reject polyglots or active markup embedded in file header/body
        $headBytes = (string)@file_get_contents($tmpPath, false, null, 0, 2048);
        if (preg_match('/<\?php|<\?=|<script\b|<svg\b/i', $headBytes) === 1) {
            return ['ok' => false, 'error' => 'Disallowed active markup detected in upload payload.'];
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = @finfo_file($finfo, $tmpPath);
                @finfo_close($finfo);
                if (is_string($detected)) {
                    $mime = strtolower(trim($detected));
                }
            }
        }
        if (!isset(self::ALLOWED_MIME_EXT[$mime])) {
            return ['ok' => false, 'error' => 'Unsupported image type "' . ($mime !== '' ? $mime : 'unknown') . '". Allowed: WebP, AVIF, PNG, JPEG.'];
        }

        $width = 0;
        $height = 0;
        $info = @getimagesize($tmpPath);
        if (is_array($info) && isset($info[0], $info[1]) && (int)$info[0] > 0 && (int)$info[1] > 0) {
            $width = (int)$info[0];
            $height = (int)$info[1];
        } elseif ($mime === 'image/avif' && function_exists('imagecreatefromavif')) {
            $im = @imagecreatefromavif($tmpPath);
            if ($im !== false) {
                $width = imagesx($im);
                $height = imagesy($im);
                imagedestroy($im);
            }
        } elseif ($mime === 'image/avif' && $fallbackWidth > 0 && $fallbackHeight > 0) {
            $width = $fallbackWidth;
            $height = $fallbackHeight;
        }

        if ($width <= 0 || $height <= 0 || $width > 16000 || $height > 16000) {
            return ['ok' => false, 'error' => 'Unable to verify valid raster dimensions for uploaded image.'];
        }

        $ext = self::ALLOWED_MIME_EXT[$mime];
        $base = pathinfo($origName, PATHINFO_FILENAME);
        $slug = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $base));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'asset';
        }
        $slug = substr($slug, 0, 48);
        $ym = gmdate('Y-m');
        $safeFilename = $slug . '-' . bin2hex(random_bytes(4)) . '.' . $ext;

        $normalizedRoot = rtrim(str_replace('\\', '/', $rootDir), '/');
        $uploadsRoot = $normalizedRoot . '/public/uploads';
        $monthDir = $uploadsRoot . '/' . $ym;
        if (!is_dir($monthDir) && !@mkdir($monthDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Unable to create public/uploads directory.'];
        }

        self::ensureUploadsSecurityFiles($uploadsRoot);

        $destPath = $monthDir . '/' . $safeFilename;
        $moved = is_uploaded_file($tmpPath) ? @move_uploaded_file($tmpPath, $destPath) : @copy($tmpPath, $destPath);
        if (!$moved || !is_file($destPath)) {
            return ['ok' => false, 'error' => 'Failed to store uploaded image on disk.'];
        }
        @chmod($destPath, 0644);

        $publicPath = '/public/uploads/' . $ym . '/' . $safeFilename;
        return [
            'ok'          => true,
            'filename'    => $safeFilename,
            'public_path' => $publicPath,
            'mime_type'   => $mime,
            'size_bytes'  => filesize($destPath) ?: $size,
            'width'       => $width,
            'height'      => $height,
            'alt_text'    => self::sanitizePlainText($altText, 240),
        ];
    }

    public static function ensureUploadsSecurityFiles(string $uploadsRoot): void
    {
        if (!is_dir($uploadsRoot)) {
            @mkdir($uploadsRoot, 0755, true);
        }
        $htaccess = $uploadsRoot . '/.htaccess';
        if (!is_file($htaccess)) {
            $rules = implode("\n", [
                '<IfModule mod_php.c>',
                '    php_flag engine off',
                '</IfModule>',
                '<FilesMatch "\.(?i:php[0-9]?|phtml|phar|pl|py|cgi|sh|svg|svgz|html?|xhtml)$">',
                '    <IfModule mod_authz_core.c>',
                '        Require all denied',
                '    </IfModule>',
                '    <IfModule !mod_authz_core.c>',
                '        Order deny,allow',
                '        Deny from all',
                '    </IfModule>',
                '</FilesMatch>',
                'Options -Indexes -ExecCGI',
                '',
            ]);
            @file_put_contents($htaccess, $rules, LOCK_EX);
        }
    }

    private static function sanitizeChildren(DOMNode $parent, DOMDocument $doc, ?string $rootDir): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $node) {
            if ($node->nodeType === XML_COMMENT_NODE) {
                $parent->removeChild($node);
                continue;
            }
            if ($node->nodeType !== XML_ELEMENT_NODE || !$node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, self::DROP_ELEMENTS, true)) {
                $parent->removeChild($node);
                continue;
            }

            self::sanitizeChildren($node, $doc, $rootDir);

            if (!in_array($tag, self::ALLOWED_ELEMENTS, true)) {
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            if ($tag === 'img') {
                if (!self::sanitizeImgElement($node, $rootDir)) {
                    $parent->removeChild($node);
                    continue;
                }
                continue;
            }

            self::sanitizeElementAttributes($node, $tag);
        }
    }

    private static function sanitizeImgElement(DOMElement $el, ?string $rootDir): bool
    {
        $src = trim((string)$el->getAttribute('src'));
        if (!self::isSafeUrl($src, false)) {
            return false;
        }

        $alt = $el->hasAttribute('alt') ? self::sanitizePlainText((string)$el->getAttribute('alt'), 240) : '';
        $w = (int)preg_replace('/[^0-9]/', '', (string)$el->getAttribute('width'));
        $h = (int)preg_replace('/[^0-9]/', '', (string)$el->getAttribute('height'));

        // Enforce Gate 06: try resolving natural raster dimensions from local public file if missing
        if (($w <= 0 || $h <= 0) && str_starts_with($src, '/public/') && $rootDir !== null) {
            $localFile = rtrim(str_replace('\\', '/', $rootDir), '/') . $src;
            if (is_file($localFile)) {
                $info = @getimagesize($localFile);
                if (is_array($info) && (int)($info[0] ?? 0) > 0 && (int)($info[1] ?? 0) > 0) {
                    $w = (int)$info[0];
                    $h = (int)$info[1];
                }
            }
        }

        if ($w <= 0 || $h <= 0) {
            return false;
        }

        $class = self::sanitizeClassAttr((string)$el->getAttribute('class'));
        $title = $el->hasAttribute('title') ? self::sanitizePlainText((string)$el->getAttribute('title'), 160) : '';
        $loading = strtolower((string)$el->getAttribute('loading')) === 'eager' ? 'eager' : 'lazy';

        while ($el->attributes->length > 0) {
            $attr = $el->attributes->item(0);
            if ($attr !== null) {
                $el->removeAttributeNode($attr);
            }
        }

        $el->setAttribute('src', $src);
        $el->setAttribute('alt', $alt);
        $el->setAttribute('width', (string)$w);
        $el->setAttribute('height', (string)$h);
        $el->setAttribute('loading', $loading);
        $el->setAttribute('decoding', 'async');
        if ($class !== '') {
            $el->setAttribute('class', $class);
        }
        if ($title !== '') {
            $el->setAttribute('title', $title);
        }
        return true;
    }

    private static function sanitizeElementAttributes(DOMElement $el, string $tag): void
    {
        $kept = [];

        if ($el->hasAttribute('id')) {
            $id = (string)preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$el->getAttribute('id'));
            if ($id !== '') {
                $kept['id'] = substr($id, 0, 80);
            }
        }

        if ($el->hasAttribute('class')) {
            $class = self::sanitizeClassAttr((string)$el->getAttribute('class'));
            if ($class !== '') {
                $kept['class'] = $class;
            }
        }

        if ($tag === 'a') {
            $href = trim((string)$el->getAttribute('href'));
            if (self::isSafeUrl($href, true)) {
                $kept['href'] = $href;
                $target = strtolower(trim((string)$el->getAttribute('target')));
                if ($target === '_blank') {
                    $kept['target'] = '_blank';
                    $kept['rel'] = 'noopener noreferrer';
                }
                if ($el->hasAttribute('title')) {
                    $title = self::sanitizePlainText((string)$el->getAttribute('title'), 160);
                    if ($title !== '') {
                        $kept['title'] = $title;
                    }
                }
            }
        } elseif ($tag === 'th' || $tag === 'td') {
            foreach (['colspan', 'rowspan'] as $spanAttr) {
                if ($el->hasAttribute($spanAttr)) {
                    $val = (int)$el->getAttribute($spanAttr);
                    if ($val >= 1 && $val <= 20) {
                        $kept[$spanAttr] = (string)$val;
                    }
                }
            }
            if ($tag === 'th' && $el->hasAttribute('scope')) {
                $scope = strtolower(trim((string)$el->getAttribute('scope')));
                if (in_array($scope, ['col', 'row', 'colgroup', 'rowgroup'], true)) {
                    $kept['scope'] = $scope;
                }
            }
        }

        while ($el->attributes->length > 0) {
            $attr = $el->attributes->item(0);
            if ($attr !== null) {
                $el->removeAttributeNode($attr);
            }
        }

        foreach ($kept as $k => $v) {
            $el->setAttribute($k, $v);
        }
    }

    private static function sanitizeClassAttr(string $raw): string
    {
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        $safe = [];
        foreach ($parts as $part) {
            $cleaned = (string)preg_replace('/[^a-zA-Z0-9_\-:]/', '', $part);
            if ($cleaned !== '') {
                $safe[] = substr($cleaned, 0, 64);
            }
        }
        return implode(' ', array_slice($safe, 0, 12));
    }
}

\class_alias(AdminSanitizer::class, \Astrake\Lumora\Core\AdminSanitizer::class);
