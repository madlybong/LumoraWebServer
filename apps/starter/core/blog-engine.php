<?php
declare(strict_types=1);

namespace Lumora\Core;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Headless Blog Engine (File-based PHP posts, Pagination, Localhost Draft Guard, and Atom 1.0 Feed).
 */
final class BlogEngine
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listPosts(?string $rootDir = null, ?bool $includeDrafts = null): array
    {
        $resolvedRoot = $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd());
        $blogDir = rtrim($resolvedRoot, '/') . '/content/blog';
        if (!is_dir($blogDir)) {
            return [];
        }

        $allowDrafts = $includeDrafts ?? Security::isLocalhost();
        $files = glob($blogDir . '/*.php') ?: [];
        $posts = [];

        foreach ($files as $file) {
            $slug = basename($file, '.php');
            if ($slug === 'index') {
                continue;
            }
            $data = require $file;
            if (!is_array($data)) {
                continue;
            }
            $status = strtolower((string)($data['status'] ?? 'published'));
            if ($status === 'draft' && !$allowDrafts) {
                continue;
            }
            $data['slug'] = (string)($data['slug'] ?? $slug);
            $posts[] = $data;
        }

        usort($posts, static function (array $a, array $b): int {
            return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
        });

        return $posts;
    }

    public static function getPost(string $slug, ?string $rootDir = null): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return null;
        }
        $resolvedRoot = $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd());
        $file = rtrim($resolvedRoot, '/') . '/content/blog/' . $slug . '.php';
        if (!is_file($file)) {
            return null;
        }
        $post = require $file;
        if (!is_array($post)) {
            return null;
        }
        $isDraft = strtolower((string)($post['status'] ?? 'published')) === 'draft';
        if ($isDraft && !Security::isLocalhost()) {
            return null;
        }
        $post['slug'] = (string)($post['slug'] ?? $slug);
        return $post;
    }

    public static function paginate(int $page = 1, int $perPage = 10, ?string $rootDir = null): array
    {
        $all = self::listPosts($rootDir);
        $total = count($all);
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $currentPage = max(1, min($totalPages, $page));
        $offset = ($currentPage - 1) * $perPage;

        return [
            'items'        => array_slice($all, $offset, $perPage),
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $currentPage,
            'total_pages'  => $totalPages,
        ];
    }

    public static function serveAtomFeed(array $config, string $rootDir, string $basePath = ''): void
    {
        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: application/atom+xml; charset=UTF-8');
        }

        $brand = $config['brand'] ?? [];
        $brandName = Render::escape((string)($brand['name'] ?? 'Lumora'));
        $origin = rtrim((string)($brand['origin'] ?? 'https://localhost'), '/');
        $posts = self::listPosts($rootDir, false);
        $updated = !empty($posts[0]['date'])
            ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$posts[0]['date']) ?: time())
            : gmdate('Y-m-d\TH:i:s\Z');

        $entriesXml = '';
        foreach (array_slice($posts, 0, 20) as $post) {
            $slug = rawurlencode((string)($post['slug'] ?? ''));
            $url = Render::escape($origin . '/blog/' . $slug . '/');
            $title = Render::escape((string)($post['title'] ?? $slug));
            $summary = Render::escape((string)($post['summary'] ?? $post['excerpt'] ?? ''));
            $postDate = gmdate('Y-m-d\TH:i:s\Z', strtotime((string)($post['date'] ?? '')) ?: time());

            $entriesXml .= <<<XML
  <entry>
    <title>{$title}</title>
    <link href="{$url}" />
    <id>{$url}</id>
    <updated>{$postDate}</updated>
    <summary>{$summary}</summary>
  </entry>

XML;
        }

        echo <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>{$brandName} — Engineering &amp; Dispatches</title>
  <link href="{$origin}/blog/feed.xml" rel="self" />
  <link href="{$origin}/blog/" />
  <updated>{$updated}</updated>
  <id>{$origin}/blog/</id>
{$entriesXml}</feed>
XML;
    }
}

if (
    class_exists(\Lumora\Core\BlogEngine::class, false)
    && !class_exists(\Astrake\Lumora\Core\BlogEngine::class, false)
) {
    class_alias(\Lumora\Core\BlogEngine::class, \Astrake\Lumora\Core\BlogEngine::class);
}
