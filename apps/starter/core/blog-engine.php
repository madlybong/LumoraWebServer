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
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function normalizePostRecord(array $raw, string $fallbackSlug, string $origin): array
    {
        $slug = (string)($raw['slug'] ?? $fallbackSlug);
        $bodyHtml = (string)($raw['content'] ?? $raw['body'] ?? $raw['content_html'] ?? '');
        $tags = $raw['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }
        $category = trim((string)($raw['category'] ?? ''));
        $readingTime = trim((string)($raw['reading_time'] ?? ''));
        if ($readingTime === '') {
            $readingTime = Render::readingTime($bodyHtml) . ' min read';
        } elseif (is_numeric($readingTime)) {
            $readingTime .= ' min read';
        }

        return array_merge($raw, [
            'slug'             => $slug,
            'title'            => (string)($raw['title'] ?? $slug),
            'summary'          => (string)($raw['summary'] ?? $raw['excerpt'] ?? ''),
            'body'             => $bodyHtml,
            'content'          => $bodyHtml,
            'author'           => (string)($raw['author'] ?? ''),
            'category'         => $category !== '' ? $category : 'Engineering',
            'tags'             => array_values($tags),
            'date'             => (string)($raw['date'] ?? ''),
            'image'            => (string)($raw['image'] ?? ''),
            'status'           => strtolower((string)($raw['status'] ?? 'published')),
            'meta_title'       => (string)($raw['meta_title'] ?? ''),
            'meta_description' => (string)($raw['meta_description'] ?? ''),
            'og_image'         => (string)($raw['og_image'] ?? ''),
            'canonical_url'    => (string)($raw['canonical_url'] ?? ''),
            'reading_time'     => $readingTime,
            'source_origin'    => $origin,
            'has_file_origin'  => !empty($raw['has_file_origin']),
        ]);
    }

    private static function isPostVisible(string $status, string $date, bool $allowDrafts): bool
    {
        if ($allowDrafts) {
            return true;
        }
        $norm = strtolower(trim($status));
        if ($norm === 'draft') {
            return false;
        }
        if ($norm === 'scheduled') {
            return $date !== '' && strcmp($date, gmdate('Y-m-d')) <= 0;
        }
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listPosts(?string $rootDir = null, ?bool $includeDrafts = null): array
    {
        $resolvedRoot = $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd());
        $blogDir = rtrim($resolvedRoot, '/') . '/content/blog';
        $allowDrafts = $includeDrafts ?? Security::isLocalhost();
        $bySlug = [];
        $fileSlugs = [];

        if (is_dir($blogDir)) {
            $files = glob($blogDir . '/*.php') ?: [];
            foreach ($files as $file) {
                $slug = basename($file, '.php');
                if ($slug === 'index') {
                    continue;
                }
                $data = require $file;
                if (!is_array($data)) {
                    continue;
                }
                $data['has_file_origin'] = true;
                $normalized = self::normalizePostRecord($data, $slug, 'file');
                $fileSlugs[$normalized['slug']] = true;
                if (!self::isPostVisible((string)$normalized['status'], (string)$normalized['date'], $allowDrafts)) {
                    continue;
                }
                $bySlug[$normalized['slug']] = $normalized;
            }
        }

        $pdo = class_exists(AdminDb::class, false) ? AdminDb::connectExisting($resolvedRoot) : null;
        if ($pdo instanceof \PDO) {
            try {
                $sql = $allowDrafts
                    ? 'SELECT * FROM cms_blog_posts'
                    : 'SELECT * FROM cms_blog_posts WHERE status IN ("published", "scheduled")';
                $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $slug = (string)($row['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $status = strtolower((string)($row['status'] ?? 'published'));
                    $date = (string)($row['date'] ?? '');
                    if (!self::isPostVisible($status, $date, $allowDrafts)) {
                        unset($bySlug[$slug]);
                        continue;
                    }
                    $tags = json_decode((string)($row['tags_json'] ?? '[]'), true);
                    $content = (string)($row['content_html'] ?? '');
                    $bySlug[$slug] = self::normalizePostRecord([
                        'slug'             => $slug,
                        'title'            => (string)($row['title'] ?? $slug),
                        'summary'          => (string)($row['summary'] ?? ''),
                        'content'          => $content,
                        'author'           => (string)($row['author'] ?? ''),
                        'category'         => (string)($row['category'] ?? 'General'),
                        'tags'             => is_array($tags) ? $tags : [],
                        'date'             => $date,
                        'image'            => (string)($row['image'] ?? ''),
                        'status'           => $status,
                        'meta_title'       => (string)($row['meta_title'] ?? ''),
                        'meta_description' => (string)($row['meta_description'] ?? ''),
                        'og_image'         => (string)($row['og_image'] ?? ''),
                        'canonical_url'    => (string)($row['canonical_url'] ?? ''),
                        'has_file_origin'  => isset($fileSlugs[$slug]),
                    ], $slug, 'sqlite');
                }
            } catch (\Throwable) {
                // Ignore if cms_blog_posts table is absent
            }
        }

        $posts = array_values($bySlug);
        usort($posts, static function (array $a, array $b): int {
            return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
        });

        return $posts;
    }

    public static function getPost(string $slug, ?string $rootDir = null, ?bool $includeDrafts = null): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return null;
        }
        $resolvedRoot = $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd());
        $file = rtrim($resolvedRoot, '/') . '/content/blog/' . $slug . '.php';
        $hasFileOrigin = is_file($file);
        $allowDrafts = $includeDrafts ?? Security::isLocalhost();

        $pdo = class_exists(AdminDb::class, false) ? AdminDb::connectExisting($resolvedRoot) : null;
        if ($pdo instanceof \PDO) {
            try {
                $stmt = $pdo->prepare('SELECT * FROM cms_blog_posts WHERE slug = :s LIMIT 1');
                $stmt->execute([':s' => $slug]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $status = strtolower((string)($row['status'] ?? 'published'));
                    $date = (string)($row['date'] ?? '');
                    if (!self::isPostVisible($status, $date, $allowDrafts)) {
                        return null;
                    }
                    $tags = json_decode((string)($row['tags_json'] ?? '[]'), true);
                    $content = (string)($row['content_html'] ?? '');
                    return self::normalizePostRecord([
                        'slug'             => $slug,
                        'title'            => (string)($row['title'] ?? $slug),
                        'summary'          => (string)($row['summary'] ?? ''),
                        'content'          => $content,
                        'author'           => (string)($row['author'] ?? ''),
                        'category'         => (string)($row['category'] ?? 'General'),
                        'tags'             => is_array($tags) ? $tags : [],
                        'date'             => $date,
                        'image'            => (string)($row['image'] ?? ''),
                        'status'           => $status,
                        'meta_title'       => (string)($row['meta_title'] ?? ''),
                        'meta_description' => (string)($row['meta_description'] ?? ''),
                        'og_image'         => (string)($row['og_image'] ?? ''),
                        'canonical_url'    => (string)($row['canonical_url'] ?? ''),
                        'has_file_origin'  => $hasFileOrigin,
                    ], $slug, 'sqlite');
                }
            } catch (\Throwable) {
                // Fallback to file post below
            }
        }

        if (!$hasFileOrigin) {
            return null;
        }
        $post = require $file;
        if (!is_array($post)) {
            return null;
        }
        $post['has_file_origin'] = true;
        $normalized = self::normalizePostRecord($post, $slug, 'file');
        if (!self::isPostVisible((string)$normalized['status'], (string)$normalized['date'], $allowDrafts)) {
            return null;
        }
        return $normalized;
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
