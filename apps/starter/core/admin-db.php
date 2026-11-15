<?php

declare(strict_types=1);

namespace Lumora\Core;

use PDO;
use Throwable;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AdminDb
{
    private static ?PDO $migratedPdo = null;

    public static function normalizeAdminPath(array $config): string
    {
        $raw = (string)($config['admin']['path'] ?? '/admin/');
        $clean = trim(str_replace('\\', '/', $raw));
        $clean = (string)preg_replace('#[^a-zA-Z0-9_\-/]#', '', $clean);
        $clean = (string)preg_replace('#/+#', '/', $clean);
        $trimmed = trim($clean, '/');
        if (
            $trimmed === ''
            || $trimmed === '.'
            || $trimmed === '..'
            || str_contains($trimmed, '..')
            || in_array($trimmed, ['public', 'core', 'content', 'storage', 'templates', 'releases', 'assets', 'docs', 'blog', 'legal', 'contact'], true)
        ) {
            return '/admin/';
        }
        return '/' . $trimmed . '/';
    }

    /**
     * Zero-write fast-path connection for public GET requests.
     * Returns null without creating any directories, secret files, or SQLite databases
     * unless storage/csrf-secret.php (or LUMORA_CSRF_SECRET), vault lumora.sqlite, and
     * initialized admin tables already exist on disk.
     */
    public static function connectExisting(?string $rootDir = null): ?PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            return null;
        }

        $resolvedRoot = self::resolveRoot($rootDir);
        $secret = self::readExistingSecret($resolvedRoot);
        if ($secret === null) {
            return null;
        }

        $vaultId = substr(hash_hmac('sha256', 'lumora-storage-vault-v1', $secret), 0, 32);
        $dbPath = $resolvedRoot . '/storage/vault-' . $vaultId . '/lumora.sqlite';
        if (!is_file($dbPath) || !is_readable($dbPath)) {
            return null;
        }

        $pdo = InquiryDb::connect($resolvedRoot);
        if (!$pdo instanceof PDO) {
            return null;
        }

        if (self::$migratedPdo === $pdo) {
            return $pdo;
        }

        try {
            $hasSchema = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='admin_settings' LIMIT 1")->fetchColumn();
            if (!$hasSchema) {
                return null;
            }
            self::migrate($pdo);
            return $pdo;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Full read/write SQLite connection with idempotent admin & CMS schema migration.
     */
    public static function connect(?string $rootDir = null): ?PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            return null;
        }

        $resolvedRoot = self::resolveRoot($rootDir);
        $pdo = InquiryDb::connect($resolvedRoot);
        if (!$pdo instanceof PDO) {
            return null;
        }

        if (self::$migratedPdo !== $pdo) {
            self::migrate($pdo);
        }

        return $pdo;
    }

    public static function resetMigrationCache(): void
    {
        self::$migratedPdo = null;
    }

    public static function encryptValue(string $plaintext, ?string $rootDir = null): string
    {
        if ($plaintext === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt') || !function_exists('hash_hkdf')) {
            return '';
        }
        $secret = Security::secretKey($rootDir);
        $key = hash_hkdf('sha256', $secret, 32, 'lumora-admin-aes-gcm-v1');
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            return '';
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decryptValue(string $encoded, ?string $rootDir = null): ?string
    {
        if ($encoded === '') {
            return '';
        }
        if (!str_starts_with($encoded, 'v1:') || !function_exists('openssl_decrypt') || !function_exists('hash_hkdf')) {
            return null;
        }
        $raw = base64_decode(substr($encoded, 3), true);
        if (!is_string($raw) || strlen($raw) < 28) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $secret = self::readExistingSecret(self::resolveRoot($rootDir)) ?? Security::secretKey($rootDir);
        $key = hash_hkdf('sha256', $secret, 32, 'lumora-admin-aes-gcm-v1');
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($plain) ? $plain : null;
    }

    public static function getSetting(string $key, ?string $rootDir = null, bool $readOnly = true): ?string
    {
        $pdo = $readOnly ? self::connectExisting($rootDir) : self::connect($rootDir);
        if (!$pdo instanceof PDO) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT value_enc, is_secret FROM admin_settings WHERE key = :k LIMIT 1');
            $stmt->execute([':k' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            $raw = (string)($row['value_enc'] ?? '');
            if ($raw === '') {
                return '';
            }
            if (!empty($row['is_secret']) || str_starts_with($raw, 'v1:')) {
                return self::decryptValue($raw, $rootDir);
            }
            return $raw;
        } catch (Throwable) {
            return null;
        }
    }

    public static function setSetting(string $key, string $value, bool $isSecret = true, ?int $userId = null, ?string $rootDir = null): bool
    {
        $pdo = self::connect($rootDir);
        if (!$pdo instanceof PDO) {
            return false;
        }
        $stored = $isSecret && $value !== '' ? self::encryptValue($value, $rootDir) : $value;
        if ($isSecret && $value !== '' && $stored === '') {
            return false;
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $pdo->prepare(
            'INSERT INTO admin_settings (key, value_enc, is_secret, updated_by, updated_at)
             VALUES (:k, :v, :s, :u, :t)
             ON CONFLICT(key) DO UPDATE SET value_enc = excluded.value_enc, is_secret = excluded.is_secret, updated_by = excluded.updated_by, updated_at = excluded.updated_at'
        );
        return $stmt->execute([
            ':k' => $key,
            ':v' => $stored,
            ':s' => $isSecret ? 1 : 0,
            ':u' => $userId,
            ':t' => $now,
        ]);
    }

    public static function deleteSetting(string $key, ?string $rootDir = null): bool
    {
        $pdo = self::connect($rootDir);
        if (!$pdo instanceof PDO) {
            return false;
        }
        $stmt = $pdo->prepare('DELETE FROM admin_settings WHERE key = :k');
        return $stmt->execute([':k' => $key]);
    }

    public static function logAudit(
        ?int $userId,
        string $actorEmail,
        string $action,
        string $targetType = '',
        string $targetId = '',
        array $detail = [],
        ?string $rootDir = null
    ): void {
        try {
            $pdo = self::connect($rootDir);
            if (!$pdo instanceof PDO) {
                return;
            }
            $json = json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                $json = '{}';
            }
            $stmt = $pdo->prepare(
                'INSERT INTO admin_audit_log (user_id, actor_email, action, target_type, target_id, detail_json, ip_hash, created_at)
                 VALUES (:uid, :email, :act, :tt, :tid, :det, :ip, :at)'
            );
            $stmt->execute([
                ':uid'   => $userId,
                ':email' => $actorEmail,
                ':act'   => $action,
                ':tt'    => $targetType,
                ':tid'   => $targetId,
                ':det'   => $json,
                ':ip'    => Security::clientIpHash($rootDir),
                ':at'    => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        } catch (Throwable) {
            // Audit log failures must not abort core workflow state
        }
    }

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "editor",
                password_hash TEXT NOT NULL,
                totp_secret_enc TEXT NOT NULL DEFAULT "",
                totp_pending_enc TEXT NOT NULL DEFAULT "",
                totp_enabled INTEGER NOT NULL DEFAULT 0,
                totp_last_step INTEGER NOT NULL DEFAULT 0,
                recovery_codes_json TEXT NOT NULL DEFAULT "[]",
                is_active INTEGER NOT NULL DEFAULT 1,
                last_login_at TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS admin_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_hash TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL,
                csrf_secret TEXT NOT NULL,
                ua_hash TEXT NOT NULL,
                ip_hash TEXT NOT NULL,
                totp_verified INTEGER NOT NULL DEFAULT 0,
                expires_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_lumora_admin_sess_user ON admin_sessions(user_id);
            CREATE INDEX IF NOT EXISTS idx_lumora_admin_sess_exp ON admin_sessions(expires_at);

            CREATE TABLE IF NOT EXISTS admin_settings (
                key TEXT PRIMARY KEY,
                value_enc TEXT NOT NULL DEFAULT "",
                is_secret INTEGER NOT NULL DEFAULT 0,
                updated_by INTEGER,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS admin_audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                actor_email TEXT NOT NULL DEFAULT "",
                action TEXT NOT NULL,
                target_type TEXT NOT NULL DEFAULT "",
                target_id TEXT NOT NULL DEFAULT "",
                detail_json TEXT NOT NULL DEFAULT "{}",
                ip_hash TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_lumora_admin_audit_created ON admin_audit_log(created_at DESC);

            CREATE TABLE IF NOT EXISTS cms_blog_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                summary TEXT NOT NULL DEFAULT "",
                content_html TEXT NOT NULL DEFAULT "",
                author TEXT NOT NULL DEFAULT "",
                category TEXT NOT NULL DEFAULT "General",
                tags_json TEXT NOT NULL DEFAULT "[]",
                date TEXT NOT NULL,
                image TEXT NOT NULL DEFAULT "",
                status TEXT NOT NULL DEFAULT "published",
                source_origin TEXT NOT NULL DEFAULT "sqlite",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_lumora_cms_blog_status_date ON cms_blog_posts(status, date DESC);

            CREATE TABLE IF NOT EXISTS cms_docs_tracks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                track_id TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                sort_order INTEGER NOT NULL DEFAULT 100,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS cms_docs_articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                track_id TEXT NOT NULL,
                slug TEXT NOT NULL,
                title TEXT NOT NULL,
                summary TEXT NOT NULL DEFAULT "",
                markdown TEXT NOT NULL DEFAULT "",
                tags_json TEXT NOT NULL DEFAULT "[]",
                status TEXT NOT NULL DEFAULT "published",
                sort_order INTEGER NOT NULL DEFAULT 100,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(track_id, slug)
            );
            CREATE INDEX IF NOT EXISTS idx_lumora_cms_docs_track_status ON cms_docs_articles(track_id, status, sort_order);

            CREATE TABLE IF NOT EXISTS cms_redirects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_path TEXT NOT NULL UNIQUE,
                target_url TEXT NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 301,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS cms_legal_overrides (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                lead TEXT NOT NULL DEFAULT "",
                doc_no TEXT NOT NULL DEFAULT "",
                version TEXT NOT NULL DEFAULT "1.0.0",
                date TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "Published",
                sections_json TEXT NOT NULL DEFAULT "[]",
                is_active INTEGER NOT NULL DEFAULT 1,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS cms_channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_key TEXT NOT NULL UNIQUE,
                label TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                sla_window TEXT NOT NULL DEFAULT "1-2 Business Days",
                is_active INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 100,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS cms_releases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                version TEXT NOT NULL,
                platform TEXT NOT NULL,
                filename TEXT NOT NULL UNIQUE,
                sha256 TEXT NOT NULL,
                size_bytes INTEGER NOT NULL DEFAULT 0,
                channel TEXT NOT NULL DEFAULT "stable",
                release_notes TEXT NOT NULL DEFAULT "",
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS cms_media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL UNIQUE,
                public_path TEXT NOT NULL UNIQUE,
                mime_type TEXT NOT NULL,
                size_bytes INTEGER NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                alt_text TEXT NOT NULL DEFAULT "",
                uploaded_by INTEGER,
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS cms_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT NOT NULL,
                entity_key TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT "",
                payload_json TEXT NOT NULL DEFAULT "{}",
                actor_email TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_lumora_cms_revisions_lookup ON cms_revisions(entity_type, entity_key, id DESC);

            CREATE TABLE IF NOT EXISTS cms_404_log (
                path TEXT PRIMARY KEY,
                hits INTEGER NOT NULL DEFAULT 1,
                last_referer TEXT NOT NULL DEFAULT "",
                last_seen_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_lumora_cms_404_hits ON cms_404_log(hits DESC, last_seen_at DESC);

            CREATE TABLE IF NOT EXISTS cms_route_seo (
                route_path TEXT PRIMARY KEY,
                meta_title TEXT NOT NULL DEFAULT "",
                meta_description TEXT NOT NULL DEFAULT "",
                og_image TEXT NOT NULL DEFAULT "",
                robots TEXT NOT NULL DEFAULT "index, follow",
                updated_at TEXT NOT NULL
            );
        ');

        self::ensureColumns($pdo, 'contact_submissions', [
            'triage_status'  => 'TEXT NOT NULL DEFAULT "new"',
            'internal_notes' => 'TEXT NOT NULL DEFAULT ""',
            'updated_at'     => 'TEXT NOT NULL DEFAULT ""',
        ]);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lumora_contact_triage ON contact_submissions(triage_status, created_at DESC)');

        self::ensureColumns($pdo, 'cms_blog_posts', [
            'meta_title'       => 'TEXT NOT NULL DEFAULT ""',
            'meta_description' => 'TEXT NOT NULL DEFAULT ""',
            'og_image'         => 'TEXT NOT NULL DEFAULT ""',
            'canonical_url'    => 'TEXT NOT NULL DEFAULT ""',
        ]);

        self::ensureColumns($pdo, 'cms_channels', [
            'config_json' => 'TEXT NOT NULL DEFAULT "{}"',
        ]);

        self::ensureColumns($pdo, 'cms_redirects', [
            'hits'        => 'INTEGER NOT NULL DEFAULT 0',
            'last_hit_at' => 'TEXT NOT NULL DEFAULT ""',
        ]);

        self::$migratedPdo = $pdo;
    }

    public static function recordRevision(
        PDO $pdo,
        string $entityType,
        string $entityKey,
        string $title,
        array $payload,
        string $actorEmail = ''
    ): void {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                $json = '{}';
            }
            $pdo->prepare(
                'INSERT INTO cms_revisions (entity_type, entity_key, title, payload_json, actor_email, created_at)
                 VALUES (:et, :ek, :ti, :pj, :ae, :cr)'
            )->execute([
                ':et' => $entityType,
                ':ek' => $entityKey,
                ':ti' => $title,
                ':pj' => $json,
                ':ae' => $actorEmail,
                ':cr' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);

            // Retain only latest 15 revisions per entity
            $pdo->prepare(
                'DELETE FROM cms_revisions
                 WHERE entity_type = :et AND entity_key = :ek
                   AND id NOT IN (
                       SELECT id FROM cms_revisions
                       WHERE entity_type = :et2 AND entity_key = :ek2
                       ORDER BY id DESC LIMIT 15
                   )'
            )->execute([
                ':et'  => $entityType,
                ':ek'  => $entityKey,
                ':et2' => $entityType,
                ':ek2' => $entityKey,
            ]);
        } catch (Throwable) {
            // Revision snapshots must never break primary saves
        }
    }

    public static function record404(string $path, string $referer = '', ?string $rootDir = null): void
    {
        $cleanPath = trim($path);
        if ($cleanPath === '' || strlen($cleanPath) > 300 || str_starts_with($cleanPath, '/_lumora')) {
            return;
        }
        try {
            $pdo = self::connectExisting($rootDir);
            if (!$pdo instanceof PDO) {
                return;
            }
            $cleanReferer = trim(substr($referer, 0, 300));
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $pdo->prepare(
                'INSERT INTO cms_404_log (path, hits, last_referer, last_seen_at)
                 VALUES (:p, 1, :r, :t)
                 ON CONFLICT(path) DO UPDATE SET
                    hits = hits + 1,
                    last_referer = CASE WHEN excluded.last_referer != "" THEN excluded.last_referer ELSE cms_404_log.last_referer END,
                    last_seen_at = excluded.last_seen_at'
            )->execute([
                ':p' => $cleanPath,
                ':r' => $cleanReferer,
                ':t' => $now,
            ]);

            // Keep top 200 entries bounded
            $count = (int)$pdo->query('SELECT COUNT(*) FROM cms_404_log')->fetchColumn();
            if ($count > 200) {
                $pdo->exec(
                    'DELETE FROM cms_404_log
                     WHERE path NOT IN (
                         SELECT path FROM cms_404_log ORDER BY hits DESC, last_seen_at DESC LIMIT 200
                     )'
                );
            }
        } catch (Throwable) {
            // 404 telemetry must fail closed
        }
    }

    /**
     * @param array<string, string> $columns
     */
    private static function ensureColumns(PDO $pdo, string $table, array $columns): void
    {
        $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
        $existingCols = [];
        foreach ($cols as $col) {
            if (is_array($col) && isset($col['name'])) {
                $existingCols[(string)$col['name']] = true;
            }
        }
        foreach ($columns as $name => $definition) {
            if (!isset($existingCols[$name])) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
            }
        }
    }

    private static function resolveRoot(?string $rootDir = null): string
    {
        return rtrim(str_replace('\\', '/', $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd())), '/');
    }

    private static function readExistingSecret(string $resolvedRoot): ?string
    {
        $envSecret = getenv('LUMORA_CSRF_SECRET');
        if (is_string($envSecret) && strlen(trim($envSecret)) >= 32) {
            return trim($envSecret);
        }

        $secretFile = $resolvedRoot . '/storage/csrf-secret.php';
        if (!is_file($secretFile) || !is_readable($secretFile)) {
            return null;
        }
        $loaded = include $secretFile;
        if (is_string($loaded) && strlen(trim($loaded)) >= 64) {
            return trim($loaded);
        }
        return null;
    }
}

\class_alias(AdminDb::class, \Astrake\Lumora\Core\AdminDb::class);
