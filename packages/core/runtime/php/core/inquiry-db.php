<?php
declare(strict_types=1);

namespace Astrake\Lumora\Core;

use PDO;
use Throwable;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class InquiryDb
{
    private static ?PDO $pdo = null;
    private static string $currentDbPath = '';

    public static function connect(?string $rootDir = null): ?PDO
    {
        $vaultDir = Security::vaultDir($rootDir);
        $dbPath = $vaultDir . '/lumora.sqlite';

        if (self::$pdo instanceof PDO && self::$currentDbPath === $dbPath) {
            return self::$pdo;
        }

        if (!extension_loaded('pdo_sqlite')) {
            return null;
        }

        try {
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ]);

            @chmod($dbPath, 0600);

            $pdo->exec('PRAGMA journal_mode = WAL;');
            $pdo->exec('PRAGMA synchronous = NORMAL;');
            $pdo->exec('PRAGMA busy_timeout = 5000;');
            $pdo->exec('PRAGMA foreign_keys = ON;');

            $pdo->exec('
                CREATE TABLE IF NOT EXISTS contact_submissions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    receipt_id TEXT NOT NULL UNIQUE,
                    brand_id TEXT NOT NULL DEFAULT "lumora",
                    channel TEXT NOT NULL,
                    channel_label TEXT NOT NULL,
                    sla_window TEXT NOT NULL DEFAULT "1-2 Business Days",
                    full_name TEXT NOT NULL,
                    organization TEXT NOT NULL DEFAULT "",
                    email TEXT NOT NULL,
                    phone TEXT NOT NULL DEFAULT "",
                    subject TEXT NOT NULL,
                    message TEXT NOT NULL,
                    consultation_mode TEXT NOT NULL DEFAULT "online",
                    facility_location TEXT NOT NULL DEFAULT "",
                    details_json TEXT NOT NULL DEFAULT "{}",
                    ip_hash TEXT NOT NULL DEFAULT "",
                    user_agent TEXT NOT NULL DEFAULT "",
                    email_status TEXT NOT NULL DEFAULT "pending",
                    whatsapp_status TEXT NOT NULL DEFAULT "pending",
                    sync_status TEXT NOT NULL DEFAULT "pending",
                    hub_receipt TEXT NOT NULL DEFAULT "",
                    synced_at TEXT NOT NULL DEFAULT "",
                    created_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_lumora_contact_channel ON contact_submissions(channel);
                CREATE INDEX IF NOT EXISTS idx_lumora_contact_email ON contact_submissions(email);
                CREATE INDEX IF NOT EXISTS idx_lumora_contact_created ON contact_submissions(created_at);
                CREATE INDEX IF NOT EXISTS idx_lumora_contact_sync ON contact_submissions(sync_status, created_at);

                CREATE TABLE IF NOT EXISTS contact_rate_limits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_hash TEXT NOT NULL,
                    event_type TEXT NOT NULL,
                    created_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_lumora_rate_limit_ip_created ON contact_rate_limits(ip_hash, created_at);
            ');

            self::$pdo = $pdo;
            self::$currentDbPath = $dbPath;
            return $pdo;
        } catch (Throwable) {
            return null;
        }
    }

    public static function resetConnection(): void
    {
        self::$pdo = null;
        self::$currentDbPath = '';
    }

    /**
     * Enforces statutory data retention schedules on write operations:
     * 1. DPDP Act 2023 Sec. 8(7) (default 90 days): Redacts PII & message body while preserving receipt_id & ip_hash.
     * 2. CERT-In Directions (default 180 days): Permanently purges records and rate-limit logs older than 180 days.
     */
    public static function prune(PDO $pdo, array $config = []): void
    {
        try {
            $piiDays = max(1, (int)($config['legal']['retention_days_pii'] ?? 90));
            $logDays = max($piiDays, (int)($config['legal']['retention_days_logs'] ?? 180));

            $piiCutoff = gmdate('Y-m-d\TH:i:s\Z', time() - ($piiDays * 86400));
            $logCutoff = gmdate('Y-m-d\TH:i:s\Z', time() - ($logDays * 86400));

            $stmtRedact = $pdo->prepare('
                UPDATE contact_submissions
                SET full_name = "[REDACTED-90D]",
                    organization = "",
                    email = "redacted@lumora.invalid",
                    phone = "",
                    subject = "[REDACTED-90D]",
                    message = "[REDACTED AFTER STATUTORY RETENTION WINDOW]",
                    facility_location = "",
                    details_json = "{}",
                    user_agent = ""
                WHERE created_at < :pii_cutoff
                  AND full_name != "[REDACTED-90D]"
            ');
            $stmtRedact->execute([':pii_cutoff' => $piiCutoff]);

            $stmtPurge = $pdo->prepare('DELETE FROM contact_submissions WHERE created_at < :log_cutoff');
            $stmtPurge->execute([':log_cutoff' => $logCutoff]);

            $stmtRatePurge = $pdo->prepare('DELETE FROM contact_rate_limits WHERE created_at < :log_cutoff');
            $stmtRatePurge->execute([':log_cutoff' => $logCutoff]);
        } catch (Throwable) {
            // Non-blocking
        }
    }

    public static function recordSecurityEvent(
        string $ipHash,
        string $eventType,
        string $detail = '',
        ?string $rootDir = null
    ): void {
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        $pdo = self::connect($rootDir);

        if ($pdo instanceof PDO && $ipHash !== '') {
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO contact_rate_limits (ip_hash, event_type, created_at)
                    VALUES (:ip_hash, :event_type, :created_at)
                ');
                $stmt->execute([
                    ':ip_hash'    => $ipHash,
                    ':event_type' => $eventType,
                    ':created_at' => $nowIso,
                ]);
            } catch (Throwable) {
                // Fall through to file log
            }
        }

        $vaultDir = Security::vaultDir($rootDir);
        $logFile = $vaultDir . '/security-events.log';
        $entry = json_encode([
            'timestamp' => $nowIso,
            'event'     => $eventType,
            'ip_hash'   => $ipHash,
            'method'    => (string)($_SERVER['REQUEST_METHOD'] ?? 'POST'),
            'uri'       => (string)($_SERVER['REQUEST_URI'] ?? '/contact/'),
            'detail'    => $detail,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (is_string($entry)) {
            $fp = @fopen($logFile, 'ab');
            if (is_resource($fp)) {
                if (@flock($fp, LOCK_EX)) {
                    @fwrite($fp, $entry . "\n");
                    @fflush($fp);
                    @flock($fp, LOCK_UN);
                }
                @fclose($fp);
                @chmod($logFile, 0600);
            }
        }
    }

    /**
     * Checks whether the given IP hash has exceeded the pre-CSRF POST rate limit.
     * Default: Maximum 5 attempts per 900s (15 minutes).
     */
    public static function isRateLimited(
        string $ipHash,
        int $maxAttempts = 5,
        int $windowSeconds = 900,
        ?string $rootDir = null
    ): bool {
        if ($ipHash === '') {
            return false;
        }

        $cutoffIso = gmdate('Y-m-d\TH:i:s\Z', time() - $windowSeconds);
        $pdo = self::connect($rootDir);

        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare('
                    SELECT COUNT(*)
                    FROM contact_rate_limits
                    WHERE ip_hash = :ip_hash
                      AND event_type = :event_type
                      AND created_at >= :cutoff
                ');
                $stmt->execute([
                    ':ip_hash'    => $ipHash,
                    ':event_type' => 'post_attempt',
                    ':cutoff'     => $cutoffIso,
                ]);
                $count = (int)$stmt->fetchColumn();
                return $count >= $maxAttempts;
            } catch (Throwable) {
                // Fall back to security-events.log
            }
        }

        $logFile = Security::vaultDir($rootDir) . '/security-events.log';
        if (!is_readable($logFile)) {
            return false;
        }
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return false;
        }
        $hits = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $row = json_decode($lines[$i], true);
            if (!is_array($row) || empty($row['timestamp'])) {
                continue;
            }
            if ((string)$row['timestamp'] < $cutoffIso) {
                break;
            }
            if (($row['ip_hash'] ?? '') === $ipHash && ($row['event'] ?? '') === 'post_attempt') {
                $hits++;
                if ($hits >= $maxAttempts) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function saveBackupJsonl(array $record, ?string $rootDir = null): bool
    {
        $vaultDir = Security::vaultDir($rootDir);
        $backupFile = $vaultDir . '/contacts-backup.jsonl';
        $payload = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            return false;
        }

        $fp = @fopen($backupFile, 'ab');
        if (!is_resource($fp)) {
            return false;
        }

        $written = false;
        if (@flock($fp, LOCK_EX)) {
            $bytes = @fwrite($fp, $payload . "\n");
            @fflush($fp);
            @flock($fp, LOCK_UN);
            $written = ($bytes !== false && $bytes > 0);
        }
        @fclose($fp);
        @chmod($backupFile, 0600);

        return $written;
    }

    /**
     * Persists a validated inquiry into both SQLite3 WAL and atomic JSONL backup.
     * Returns positive SQLite row ID, 0 if persisted via JSONL failover only, or null on total storage failure.
     */
    public static function saveSubmission(array $record, array $config = [], ?string $rootDir = null): ?int
    {
        $jsonlSaved = self::saveBackupJsonl($record, $rootDir);
        $pdo = self::connect($rootDir);

        if ($pdo instanceof PDO) {
            try {
                self::prune($pdo, $config);

                $stmt = $pdo->prepare('
                    INSERT INTO contact_submissions (
                        receipt_id,
                        brand_id,
                        channel,
                        channel_label,
                        sla_window,
                        full_name,
                        organization,
                        email,
                        phone,
                        subject,
                        message,
                        consultation_mode,
                        facility_location,
                        details_json,
                        ip_hash,
                        user_agent,
                        email_status,
                        whatsapp_status,
                        sync_status,
                        hub_receipt,
                        synced_at,
                        created_at
                    ) VALUES (
                        :receipt_id,
                        :brand_id,
                        :channel,
                        :channel_label,
                        :sla_window,
                        :full_name,
                        :organization,
                        :email,
                        :phone,
                        :subject,
                        :message,
                        :consultation_mode,
                        :facility_location,
                        :details_json,
                        :ip_hash,
                        :user_agent,
                        :email_status,
                        :whatsapp_status,
                        :sync_status,
                        :hub_receipt,
                        :synced_at,
                        :created_at
                    )
                ');

                $stmt->execute([
                    ':receipt_id'        => (string)($record['receipt_id'] ?? ''),
                    ':brand_id'          => (string)($record['brand_id'] ?? ($config['brand']['id'] ?? 'lumora')),
                    ':channel'           => (string)($record['channel'] ?? 'general'),
                    ':channel_label'     => (string)($record['channel_label'] ?? 'General Inquiry'),
                    ':sla_window'        => (string)($record['sla_window'] ?? '1-2 Business Days'),
                    ':full_name'         => (string)($record['full_name'] ?? ''),
                    ':organization'      => (string)($record['organization'] ?? ''),
                    ':email'             => (string)($record['email'] ?? ''),
                    ':phone'             => (string)($record['phone'] ?? ''),
                    ':subject'           => (string)($record['subject'] ?? ''),
                    ':message'           => (string)($record['message'] ?? ''),
                    ':consultation_mode' => (string)($record['consultation_mode'] ?? 'online'),
                    ':facility_location' => (string)($record['facility_location'] ?? ''),
                    ':details_json'      => json_encode($record['details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                    ':ip_hash'           => (string)($record['ip_hash'] ?? ''),
                    ':user_agent'        => mb_substr((string)($record['user_agent'] ?? ''), 0, 250),
                    ':email_status'      => (string)($record['email_status'] ?? 'pending'),
                    ':whatsapp_status'   => (string)($record['whatsapp_status'] ?? 'pending'),
                    ':sync_status'       => (string)($record['sync_status'] ?? 'pending'),
                    ':hub_receipt'       => (string)($record['hub_receipt'] ?? ''),
                    ':synced_at'         => (string)($record['synced_at'] ?? ''),
                    ':created_at'        => (string)($record['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
                ]);

                return (int)$pdo->lastInsertId();
            } catch (Throwable) {
                // Fall through to JSONL result
            }
        }

        return $jsonlSaved ? 0 : null;
    }

    public static function updateDeliveryAndSyncStatus(
        string $receiptId,
        string $emailStatus,
        string $whatsappStatus,
        string $syncStatus = 'pending',
        string $hubReceipt = '',
        ?string $rootDir = null
    ): void {
        $pdo = self::connect($rootDir);
        if (!$pdo instanceof PDO || $receiptId === '') {
            return;
        }

        try {
            $syncedAt = ($syncStatus === 'synced') ? gmdate('Y-m-d\TH:i:s\Z') : '';
            $stmt = $pdo->prepare('
                UPDATE contact_submissions
                SET email_status = :email_status,
                    whatsapp_status = :whatsapp_status,
                    sync_status = :sync_status,
                    hub_receipt = :hub_receipt,
                    synced_at = :synced_at
                WHERE receipt_id = :receipt_id
            ');
            $stmt->execute([
                ':email_status'    => $emailStatus,
                ':whatsapp_status' => $whatsappStatus,
                ':sync_status'     => $syncStatus,
                ':hub_receipt'     => $hubReceipt,
                ':synced_at'       => $syncedAt,
                ':receipt_id'      => $receiptId,
            ]);
        } catch (Throwable) {
            // Non-blocking
        }
    }

    /**
     * Fetches pending outbox inquiries for webhook/hub replay.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getPendingOutbox(int $limit = 25, ?string $rootDir = null): array
    {
        $pdo = self::connect($rootDir);
        if (!$pdo instanceof PDO) {
            return [];
        }
        try {
            $stmt = $pdo->prepare('
                SELECT *
                FROM contact_submissions
                WHERE sync_status IN ("pending", "failed")
                  AND full_name != "[REDACTED-90D]"
                ORDER BY id ASC
                LIMIT :lim
            ');
            $stmt->bindValue(':lim', max(1, min(100, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }
}
