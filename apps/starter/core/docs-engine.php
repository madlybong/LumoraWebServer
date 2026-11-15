<?php
declare(strict_types=1);

namespace Lumora\Core;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Headless Documentation Manifest & Markdown-to-HTML5 Resolver.
 * Reads strictly from `<website>/content/docs/` using a manifest allowlist and `realpath` containment.
 */
final class DocsEngine
{
    private string $docsRoot;
    private string $manifestPath;
    private string $siteRoot;

    public function __construct(?string $docsRoot = null)
    {
        $root = (string)($GLOBALS['lumoraRootDir'] ?? getcwd());
        $this->docsRoot = $docsRoot ?? ($root . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'docs');
        $this->manifestPath = $this->docsRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->siteRoot = preg_match('#[/\\\\]content[/\\\\]docs$#', $this->docsRoot) === 1
            ? dirname($this->docsRoot, 2)
            : $root;
    }

    public function getManifest(?bool $includeDrafts = null): array
    {
        $manifest = ['version' => '0.1.0', 'tracks' => []];
        if (is_file($this->manifestPath)) {
            $raw = (string)@file_get_contents($this->manifestPath);
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['tracks']) && is_array($decoded['tracks'])) {
                $manifest = $decoded;
            }
        }

        $allowDrafts = $includeDrafts ?? Security::isLocalhost();
        $realDocsRoot = realpath($this->docsRoot);

        foreach ($manifest['tracks'] as $trackKey => $track) {
            if (!is_array($track)) {
                continue;
            }
            $filteredArticles = [];
            foreach (($track['articles'] ?? []) as $art) {
                if (!is_array($art)) {
                    continue;
                }
                $status = strtolower((string)($art['status'] ?? 'published'));
                if ($status === 'draft' && !$allowDrafts) {
                    continue;
                }
                $markdown = isset($art['markdown']) && is_string($art['markdown']) ? $art['markdown'] : '';
                if ($markdown === '' && !empty($art['file']) && is_string($art['file']) && $realDocsRoot !== false) {
                    $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $art['file']);
                    $realCandidate = realpath($this->docsRoot . DIRECTORY_SEPARATOR . $rel);
                    if (
                        $realCandidate !== false
                        && str_starts_with($realCandidate, $realDocsRoot . DIRECTORY_SEPARATOR)
                        && is_file($realCandidate)
                    ) {
                        $markdown = (string)@file_get_contents($realCandidate);
                    }
                }
                $art['markdown'] = $markdown;
                $art['status'] = $status;
                $art['tags'] = is_array($art['tags'] ?? null) ? $art['tags'] : [];
                $art['source_origin'] = (string)($art['source_origin'] ?? 'file');
                $art['has_file_origin'] = $art['source_origin'] === 'file' || !empty($art['has_file_origin']);
                if (empty($art['reading_time'])) {
                    $art['reading_time'] = Render::readingTime($markdown) . ' min read';
                }
                $filteredArticles[] = $art;
            }
            $manifest['tracks'][$trackKey]['source_origin'] = 'file';
            $manifest['tracks'][$trackKey]['has_file_origin'] = true;
            $manifest['tracks'][$trackKey]['articles'] = $filteredArticles;
        }

        $pdo = class_exists(AdminDb::class, false) ? AdminDb::connectExisting($this->siteRoot) : null;
        if (!$pdo instanceof \PDO) {
            return $manifest;
        }

        try {
            $tracks = $manifest['tracks'];
            $trackRows = $pdo->query('SELECT * FROM cms_docs_tracks ORDER BY sort_order ASC, id ASC')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($trackRows as $tr) {
                $tid = (string)($tr['track_id'] ?? '');
                if ($tid === '') {
                    continue;
                }
                if (!isset($tracks[$tid]) || !is_array($tracks[$tid])) {
                    $tracks[$tid] = [
                        'title'           => (string)($tr['title'] ?? $tid),
                        'description'     => (string)($tr['description'] ?? ''),
                        'sort_order'      => (int)($tr['sort_order'] ?? 100),
                        'source_origin'   => 'sqlite',
                        'has_file_origin' => false,
                        'articles'        => [],
                    ];
                } else {
                    if (!empty($tr['title'])) {
                        $tracks[$tid]['title'] = (string)$tr['title'];
                    }
                    if (isset($tr['description']) && $tr['description'] !== '') {
                        $tracks[$tid]['description'] = (string)$tr['description'];
                    }
                    $tracks[$tid]['sort_order'] = (int)($tr['sort_order'] ?? 100);
                    $tracks[$tid]['source_origin'] = 'sqlite';
                    $tracks[$tid]['has_file_origin'] = true;
                    if (!isset($tracks[$tid]['articles']) || !is_array($tracks[$tid]['articles'])) {
                        $tracks[$tid]['articles'] = [];
                    }
                }
            }

            $sql = $allowDrafts
                ? 'SELECT * FROM cms_docs_articles ORDER BY sort_order ASC, id ASC'
                : 'SELECT * FROM cms_docs_articles WHERE status = "published" ORDER BY sort_order ASC, id ASC';
            $artRows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($artRows as $ar) {
                $tid = (string)($ar['track_id'] ?? 'operator');
                $slug = (string)($ar['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                if (!isset($tracks[$tid]) || !is_array($tracks[$tid])) {
                    $tracks[$tid] = [
                        'title'           => ucfirst(str_replace('-', ' ', $tid)),
                        'description'     => '',
                        'source_origin'   => 'sqlite',
                        'has_file_origin' => false,
                        'articles'        => [],
                    ];
                }
                $tags = json_decode((string)($ar['tags_json'] ?? '[]'), true);
                $markdown = (string)($ar['markdown'] ?? '');

                $replacedIdx = null;
                foreach ($tracks[$tid]['articles'] as $idx => $existing) {
                    if (($existing['slug'] ?? '') === $slug) {
                        $replacedIdx = $idx;
                        break;
                    }
                }

                $entry = [
                    'slug'            => $slug,
                    'title'           => (string)($ar['title'] ?? $slug),
                    'summary'         => (string)($ar['summary'] ?? ''),
                    'markdown'        => $markdown,
                    'reading_time'    => Render::readingTime($markdown) . ' min read',
                    'tags'            => is_array($tags) ? $tags : [],
                    'status'          => strtolower((string)($ar['status'] ?? 'published')),
                    'sort_order'      => (int)($ar['sort_order'] ?? 100),
                    'source_origin'   => 'sqlite',
                    'has_file_origin' => $replacedIdx !== null && !empty($tracks[$tid]['articles'][$replacedIdx]['has_file_origin']),
                ];

                if ($replacedIdx !== null) {
                    $tracks[$tid]['articles'][$replacedIdx] = $entry;
                } else {
                    $tracks[$tid]['articles'][] = $entry;
                }
            }

            $manifest['tracks'] = $tracks;
        } catch (\Throwable) {
            // Ignore when cms_docs_* tables are unavailable
        }

        return $manifest;
    }

    public function renderArticle(string $trackId, string $slug): array
    {
        if (
            str_contains($trackId, '..')
            || str_contains($slug, '..')
            || str_contains($slug, '/')
            || str_contains($slug, '\\')
            || stripos($slug, 'internal') !== false
        ) {
            return $this->notFoundResult($trackId, $slug);
        }

        $manifest = $this->getManifest();
        $tracks = $manifest['tracks'];
        if (!isset($tracks[$trackId])) {
            $firstTrack = array_key_first($tracks);
            $trackId = is_string($firstTrack) ? $firstTrack : 'operator';
        }

        $matchedArticle = null;
        $matchedTrackId = $trackId;

        foreach ($tracks as $tKey => $track) {
            foreach ($track['articles'] ?? [] as $article) {
                if (($article['slug'] ?? '') === $slug) {
                    $matchedArticle = $article;
                    $matchedTrackId = (string)$tKey;
                    break 2;
                }
            }
        }

        if ($matchedArticle === null) {
            return $this->notFoundResult($trackId, $slug);
        }

        $markdown = '';
        if (isset($matchedArticle['markdown']) && is_string($matchedArticle['markdown']) && $matchedArticle['markdown'] !== '') {
            $markdown = $matchedArticle['markdown'];
        } else {
            $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)($matchedArticle['file'] ?? ''));
            $candidatePath = $this->docsRoot . DIRECTORY_SEPARATOR . $relativePath;
            $realDocsRoot = realpath($this->docsRoot);
            $realCandidate = realpath($candidatePath);

            if (
                $realDocsRoot === false
                || $realCandidate === false
                || !str_starts_with($realCandidate, $realDocsRoot . DIRECTORY_SEPARATOR)
                || !is_file($realCandidate)
            ) {
                return $this->notFoundResult($matchedTrackId, $slug);
            }

            $markdown = (string)@file_get_contents($realCandidate);
        }
        $parsed = $this->parseMarkdown($markdown);

        return [
            'found'         => true,
            'track_id'      => $matchedTrackId,
            'slug'          => (string)$matchedArticle['slug'],
            'title'         => (string)$matchedArticle['title'],
            'summary'       => (string)($matchedArticle['summary'] ?? ''),
            'reading_time'  => (string)($matchedArticle['reading_time'] ?? (Render::readingTime($markdown) . ' min read')),
            'tags'          => is_array($matchedArticle['tags'] ?? null) ? $matchedArticle['tags'] : [],
            'status'        => strtolower((string)($matchedArticle['status'] ?? 'published')),
            'source_origin' => (string)($matchedArticle['source_origin'] ?? 'file'),
            'html'          => $parsed['html'],
            'toc'           => $parsed['toc'],
        ];
    }

    public function searchArticles(string $query): array
    {
        $q = trim($query);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $manifest = $this->getManifest();
        $results = [];
        $realDocsRoot = realpath($this->docsRoot);

        foreach ($manifest['tracks'] as $trackId => $track) {
            foreach ($track['articles'] ?? [] as $article) {
                if (isset($article['markdown']) && is_string($article['markdown'])) {
                    $body = $article['markdown'];
                } else {
                    $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)($article['file'] ?? ''));
                    $realFile = $realDocsRoot !== false ? realpath($this->docsRoot . DIRECTORY_SEPARATOR . $rel) : false;
                    $body = ($realDocsRoot !== false && $realFile !== false && str_starts_with($realFile, $realDocsRoot . DIRECTORY_SEPARATOR))
                        ? (string)@file_get_contents($realFile)
                        : '';
                }

                $haystack = ($article['title'] ?? '') . ' ' . ($article['summary'] ?? '') . ' ' . implode(' ', $article['tags'] ?? []) . ' ' . $body;
                $pos = mb_stripos($haystack, $q);
                if ($pos !== false) {
                    $start = max(0, $pos - 60);
                    $snippet = trim(preg_replace('/[#*`>|_~-]+/', ' ', mb_substr($haystack, $start, 180)) ?? '');
                    $results[] = [
                        'track_id'    => (string)$trackId,
                        'track_title' => (string)($track['title'] ?? ''),
                        'slug'        => (string)($article['slug'] ?? ''),
                        'title'       => (string)($article['title'] ?? ''),
                        'summary'     => (string)($article['summary'] ?? ''),
                        'snippet'     => '...' . Render::escape($snippet) . '...',
                    ];
                }
            }
        }

        return $results;
    }

    public function parseMarkdown(string $markdown): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $htmlParts = [];
        $toc = [];

        $inCodeBlock = false;
        $codeLang = '';
        $codeBuffer = [];

        $inTable = false;
        $tableRows = [];

        $inList = false;
        $listType = 'ul';
        $listItems = [];

        $inBlockquote = false;
        $quoteLines = [];

        $flushList = function () use (&$inList, &$listType, &$listItems, &$htmlParts): void {
            if (!$inList) {
                return;
            }
            $itemsHtml = '';
            foreach ($listItems as $item) {
                $itemsHtml .= '<li>' . $this->formatInline($item) . '</li>';
            }
            $htmlParts[] = "<{$listType} class=\"doc-list\">{$itemsHtml}</{$listType}>";
            $inList = false;
            $listItems = [];
        };

        $flushTable = function () use (&$inTable, &$tableRows, &$htmlParts): void {
            if (!$inTable || count($tableRows) === 0) {
                $inTable = false;
                $tableRows = [];
                return;
            }
            $thead = '';
            $tbody = '';
            foreach ($tableRows as $idx => $row) {
                if ($idx === 1 && preg_match('/^[\s|:-]+$/', $row)) {
                    continue;
                }
                $cells = array_map('trim', explode('|', trim($row, '| ')));
                if ($idx === 0) {
                    $ths = '';
                    foreach ($cells as $c) {
                        $ths .= '<th>' . $this->formatInline($c) . '</th>';
                    }
                    $thead = "<thead><tr>{$ths}</tr></thead>";
                } else {
                    $tds = '';
                    foreach ($cells as $c) {
                        $tds .= '<td>' . $this->formatInline($c) . '</td>';
                    }
                    $tbody .= "<tr>{$tds}</tr>";
                }
            }
            $htmlParts[] = "<div class=\"doc-table-wrap\"><table class=\"doc-table\">{$thead}<tbody>{$tbody}</tbody></table></div>";
            $inTable = false;
            $tableRows = [];
        };

        $flushBlockquote = function () use (&$inBlockquote, &$quoteLines, &$htmlParts): void {
            if (!$inBlockquote || count($quoteLines) === 0) {
                $inBlockquote = false;
                $quoteLines = [];
                return;
            }
            $firstLine = trim($quoteLines[0] ?? '');
            $alertType = 'note';
            $alertTitle = 'Note';
            $isAlert = false;

            if (preg_match('/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*(.*)$/i', $firstLine, $m)) {
                $isAlert = true;
                $alertType = strtolower($m[1]);
                $alertTitle = ucfirst($alertType);
                if (trim($m[2]) !== '') {
                    $quoteLines[0] = trim($m[2]);
                } else {
                    array_shift($quoteLines);
                }
            }

            $bodyHtml = $this->formatInline(implode(' ', array_map('trim', $quoteLines)));
            if ($isAlert) {
                $htmlParts[] = "<aside class=\"doc-callout doc-callout-{$alertType}\" role=\"note\"><div class=\"doc-callout-title\">{$alertTitle}</div><div class=\"doc-callout-body\">{$bodyHtml}</div></aside>";
            } else {
                $htmlParts[] = "<blockquote class=\"doc-quote\">{$bodyHtml}</blockquote>";
            }

            $inBlockquote = false;
            $quoteLines = [];
        };

        foreach ($lines as $rawLine) {
            $line = rtrim($rawLine);

            if (preg_match('/^```(\w*)/', $line, $m)) {
                $flushList();
                $flushTable();
                $flushBlockquote();

                if (!$inCodeBlock) {
                    $inCodeBlock = true;
                    $codeLang = $m[1] !== '' ? strtolower($m[1]) : 'text';
                    $codeBuffer = [];
                } else {
                    $escapedCode = Render::escape(implode("\n", $codeBuffer));
                    $langLabel = strtoupper($codeLang);
                    $htmlParts[] = "<div class=\"doc-code-block\"><div class=\"doc-code-header\"><span class=\"doc-code-lang\">{$langLabel}</span></div><pre><code class=\"language-{$codeLang}\">{$escapedCode}</code></pre></div>";
                    $inCodeBlock = false;
                    $codeLang = '';
                    $codeBuffer = [];
                }
                continue;
            }

            if ($inCodeBlock) {
                $codeBuffer[] = $rawLine;
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                $flushList();
                $flushTable();
                $flushBlockquote();
                continue;
            }

            if (preg_match('/^---+\s*$/', $trimmed)) {
                $flushList();
                $flushTable();
                $flushBlockquote();
                $htmlParts[] = '<hr class="doc-divider" />';
                continue;
            }

            if (str_starts_with($trimmed, '>')) {
                $flushList();
                $flushTable();
                $inBlockquote = true;
                $quoteLines[] = ltrim(substr($trimmed, 1));
                continue;
            }
            $flushBlockquote();

            if (str_starts_with($trimmed, '|') && str_ends_with($trimmed, '|')) {
                $flushList();
                $inTable = true;
                $tableRows[] = $trimmed;
                continue;
            }
            $flushTable();

            if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $m)) {
                $flushList();
                $level = strlen($m[1]);
                $text = trim($m[2]);
                $plainText = strip_tags($text);
                $slugId = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $plainText) ?? 'section', '-'));
                if ($level >= 2 && $level <= 3) {
                    $toc[] = [
                        'id'    => $slugId,
                        'text'  => $plainText,
                        'level' => $level,
                    ];
                }
                $formattedHeading = $this->formatInline($text);
                $htmlParts[] = "<h{$level} id=\"{$slugId}\" class=\"doc-heading doc-h{$level}\">{$formattedHeading}</h{$level}>";
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m)) {
                if ($inList && $listType !== 'ul') {
                    $flushList();
                }
                $inList = true;
                $listType = 'ul';
                $listItems[] = $m[1];
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
                if ($inList && $listType !== 'ol') {
                    $flushList();
                }
                $inList = true;
                $listType = 'ol';
                $listItems[] = $m[1];
                continue;
            }

            $flushList();
            $htmlParts[] = '<p class="doc-paragraph">' . $this->formatInline($trimmed) . '</p>';
        }

        $flushList();
        $flushTable();
        $flushBlockquote();

        return [
            'html' => implode("\n", $htmlParts),
            'toc'  => $toc,
        ];
    }

    public static function renderMarkdownText(string $markdown, ?string $siteRoot = null): array
    {
        $docsRoot = $siteRoot !== null
            ? rtrim(str_replace('\\', '/', $siteRoot), '/') . '/content/docs'
            : null;
        return (new self($docsRoot))->parseMarkdown($markdown);
    }

    private function notFoundResult(string $trackId, string $slug): array
    {
        return [
            'found'        => false,
            'track_id'     => Render::escape($trackId),
            'slug'         => Render::escape($slug),
            'title'        => 'Documentation Article Not Found',
            'summary'      => 'The requested documentation resource does not exist.',
            'reading_time' => '1 min',
            'tags'         => [],
            'html'         => '<p class="doc-not-found">The requested documentation article was not found in the manifest.</p>',
            'toc'          => [],
        ];
    }

    private function formatInline(string $text): string
    {
        $escaped = Render::escape($text);
        $escaped = preg_replace('/`([^`]+)`/', '<code class="doc-inline-code">$1</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped) ?? $escaped;

        $siteRoot = $this->siteRoot;
        $escaped = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)]+)\)/',
            static function (array $matches) use ($siteRoot): string {
                $alt = $matches[1];
                $src = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!str_starts_with($src, '/') && !str_starts_with($src, 'https://')) {
                    return $alt;
                }
                if (str_starts_with($src, '//') || preg_match('/[\x00-\x20]/', $src) === 1) {
                    return $alt;
                }
                $dims = '';
                if (str_starts_with($src, '/public/') && $siteRoot !== '') {
                    $localFile = rtrim(str_replace('\\', '/', $siteRoot), '/') . $src;
                    if (is_file($localFile)) {
                        $info = @getimagesize($localFile);
                        if (is_array($info) && (int)($info[0] ?? 0) > 0 && (int)($info[1] ?? 0) > 0) {
                            $dims = ' width="' . (int)$info[0] . '" height="' . (int)$info[1] . '"';
                        }
                    }
                }
                return '<img src="' . Render::escape($src) . '" alt="' . $alt . '"' . $dims . ' loading="lazy" decoding="async" />';
            },
            $escaped
        ) ?? $escaped;

        $escaped = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            static function (array $matches): string {
                $label = $matches[1];
                $url = $matches[2];
                if (
                    str_starts_with($url, '?')
                    || str_starts_with($url, '/')
                    || str_starts_with($url, '#')
                    || str_starts_with($url, 'https://')
                ) {
                    return '<a href="' . $url . '" class="doc-link">' . $label . '</a>';
                }
                return $label;
            },
            $escaped
        ) ?? $escaped;

        return $escaped;
    }
}

if (
    class_exists(\Lumora\Core\DocsEngine::class, false)
    && !class_exists(\Astrake\Lumora\Core\DocsEngine::class, false)
) {
    class_alias(\Lumora\Core\DocsEngine::class, \Astrake\Lumora\Core\DocsEngine::class);
}
