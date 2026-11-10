<?php
declare(strict_types=1);

namespace Astrake\Lumora\Core;

use Throwable;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class HubClient
{
    /**
     * Computes HMAC-SHA256 signature header value: "sha256=" . hmac(timestamp . "." . rawJsonBody, secret)
     */
    public static function signPayload(string $rawJsonBody, string $timestamp, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawJsonBody, $secret);
    }

    public static function verifySignature(string $rawJsonBody, string $timestamp, string $signatureHeader, string $secret): bool
    {
        if ($secret === '' || $timestamp === '' || $signatureHeader === '' || !ctype_digit($timestamp)) {
            return false;
        }
        $ts = (int)$timestamp;
        if (abs(time() - $ts) > 300) {
            return false;
        }
        $expected = self::signPayload($rawJsonBody, $timestamp, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    public static function resolveHubSecret(array $config, ?string $rootDir = null): string
    {
        $hubCfg = $config['hub'] ?? [];
        $secret = trim((string)($hubCfg['shared_secret'] ?? ''));
        if ($secret !== '') {
            return $secret;
        }
        $envSecret = getenv('LUMORA_HUB_SECRET');
        if (is_string($envSecret) && trim($envSecret) !== '') {
            return trim($envSecret);
        }

        $resolvedRoot = rtrim(str_replace('\\', '/', $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd())), '/');
        $candidates = [
            Security::vaultDir($resolvedRoot) . '/hub-secret.php',
            $resolvedRoot . '/storage/hub-secret.php',
        ];
        foreach ($candidates as $file) {
            if (is_file($file) && is_readable($file)) {
                $loaded = include $file;
                if (is_string($loaded) && trim($loaded) !== '') {
                    return trim($loaded);
                }
            }
        }
        return '';
    }

    /**
     * Dispatches a newly recorded inquiry to an external HMAC-SHA256 webhook / Central Communication Hub.
     *
     * @return array{synced: bool, http_code: int, hub_receipt: string, error: string, signature: string}
     */
    public static function syncInquiryToHub(array $config, array $record, ?string $rootDir = null): array
    {
        $hubCfg = $config['hub'] ?? [];
        $secret = self::resolveHubSecret($config, $rootDir);
        $timestamp = (string)time();
        $brandId = (string)($config['brand']['id'] ?? 'lumora');

        $payload = json_encode([
            'schema_version' => '1.0',
            'brand_id'       => $brandId,
            'brand_name'     => (string)($config['brand']['name'] ?? 'Lumora'),
            'domain'         => (string)($config['brand']['domain'] ?? 'localhost'),
            'inquiry'        => $record,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($payload)) {
            return ['synced' => false, 'http_code' => 0, 'hub_receipt' => '', 'error' => 'json_encode_failed', 'signature' => ''];
        }

        $signature = $secret !== '' ? self::signPayload($payload, $timestamp, $secret) : '';

        if (empty($hubCfg['enabled']) || empty($hubCfg['endpoint']) || !function_exists('curl_init')) {
            return ['synced' => false, 'http_code' => 0, 'hub_receipt' => '', 'error' => 'hub_disabled_or_no_curl', 'signature' => $signature];
        }

        if ($secret === '') {
            return ['synced' => false, 'http_code' => 0, 'hub_receipt' => '', 'error' => 'missing_hub_secret', 'signature' => ''];
        }

        $endpoint = trim((string)$hubCfg['endpoint']);
        $timeoutMs = max(500, min(3000, (int)($hubCfg['timeout_ms'] ?? 1500)));

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['synced' => false, 'http_code' => 0, 'hub_receipt' => '', 'error' => 'curl_init_failed', 'signature' => $signature];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $payload,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => min(800, $timeoutMs),
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_HTTPHEADER        => [
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json',
                'X-Lumora-Brand: ' . $brandId,
                'X-Lumora-Timestamp: ' . $timestamp,
                'X-Lumora-Idempotency-Key: ' . (string)($record['receipt_id'] ?? ''),
                'X-Lumora-Signature: ' . $signature,
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody !== false && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode((string)$responseBody, true);
            return [
                'synced'      => true,
                'http_code'   => $httpCode,
                'hub_receipt' => (string)($decoded['hub_id'] ?? $record['receipt_id'] ?? ''),
                'error'       => '',
                'signature'   => $signature,
            ];
        }

        return [
            'synced'      => false,
            'http_code'   => $httpCode,
            'hub_receipt' => '',
            'error'       => $curlError !== '' ? $curlError : "HTTP {$httpCode}",
            'signature'   => $signature,
        ];
    }

    /**
     * Replays pending outbox inquiries in SQLite WAL to the configured webhook/hub endpoint.
     *
     * @return array{attempted: int, synced: int, failed: int}
     */
    public static function flushPendingOutbox(array $config, ?string $rootDir = null, int $limit = 25): array
    {
        $rows = InquiryDb::getPendingOutbox($limit, $rootDir);
        $attempted = 0;
        $synced = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $attempted++;
            $details = json_decode((string)($row['details_json'] ?? '{}'), true);
            $record = [
                'receipt_id'        => (string)($row['receipt_id'] ?? ''),
                'channel'           => (string)($row['channel'] ?? 'general'),
                'channel_label'     => (string)($row['channel_label'] ?? ''),
                'sla_window'        => (string)($row['sla_window'] ?? '1-2 Business Days'),
                'full_name'         => (string)($row['full_name'] ?? ''),
                'organization'      => (string)($row['organization'] ?? ''),
                'email'             => (string)($row['email'] ?? ''),
                'phone'             => (string)($row['phone'] ?? ''),
                'subject'           => (string)($row['subject'] ?? ''),
                'message'           => (string)($row['message'] ?? ''),
                'consultation_mode' => (string)($row['consultation_mode'] ?? 'online'),
                'facility_location' => (string)($row['facility_location'] ?? ''),
                'details'           => is_array($details) ? $details : [],
                'ip_hash'           => (string)($row['ip_hash'] ?? ''),
                'user_agent'        => (string)($row['user_agent'] ?? ''),
                'created_at'        => (string)($row['created_at'] ?? ''),
            ];

            $res = self::syncInquiryToHub($config, $record, $rootDir);
            if ($res['synced']) {
                $synced++;
                InquiryDb::updateDeliveryAndSyncStatus(
                    $record['receipt_id'],
                    (string)($row['email_status'] ?? 'skipped'),
                    (string)($row['whatsapp_status'] ?? 'skipped'),
                    'synced',
                    $res['hub_receipt'],
                    $rootDir
                );
            } else {
                $failed++;
                InquiryDb::updateDeliveryAndSyncStatus(
                    $record['receipt_id'],
                    (string)($row['email_status'] ?? 'skipped'),
                    (string)($row['whatsapp_status'] ?? 'skipped'),
                    'failed',
                    '',
                    $rootDir
                );
            }
        }

        return [
            'attempted' => $attempted,
            'synced'    => $synced,
            'failed'    => $failed,
        ];
    }

    /**
     * Standalone Edge Notification Dispatcher (First-Party Native SMTP Socket + Self-Hosted WhatsApp API).
     * Runs when standalone edge notifications are enabled or when Hub fallback_notify is active.
     *
     * @return array{email_status: string, whatsapp_status: string}
     */
    public static function dispatchEdgeNotifications(array $config, array $record, ?string $rootDir = null): array
    {
        $notifyCfg = self::resolveNotifyConfig($config, $rootDir);
        $emailStatus = self::sendEdgeEmail($config, $record, $notifyCfg['email'] ?? [], $rootDir);
        $waStatus = self::sendEdgeWhatsApp($config, $record, $notifyCfg['whatsapp'] ?? [], $rootDir);

        return [
            'email_status'    => $emailStatus,
            'whatsapp_status' => $waStatus,
        ];
    }

    public static function resolveNotifyConfig(array $config, ?string $rootDir = null): array
    {
        $brandDomain = (string)($config['brand']['domain'] ?? 'localhost');
        $brandName = (string)($config['brand']['name'] ?? 'Lumora');
        $defaults = [
            'email' => [
                'enabled'    => filter_var(getenv('LUMORA_NOTIFY_EMAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
                'transport'  => getenv('LUMORA_NOTIFY_EMAIL_TRANSPORT') ?: 'smtp',
                'to'         => getenv('LUMORA_NOTIFY_EMAIL_TO') ?: ('contact@' . $brandDomain),
                'from_email' => getenv('LUMORA_NOTIFY_EMAIL_FROM') ?: ('noreply@' . $brandDomain),
                'from_name'  => $brandName . ' Dispatch',
                'smtp_host'  => getenv('LUMORA_SMTP_HOST') ?: '',
                'smtp_port'  => (int)(getenv('LUMORA_SMTP_PORT') ?: 587),
                'smtp_user'  => getenv('LUMORA_SMTP_USER') ?: '',
                'smtp_pass'  => getenv('LUMORA_SMTP_PASS') ?: '',
                'smtp_tls'   => true,
                'timeout_ms' => 2500,
            ],
            'whatsapp' => [
                'enabled'     => filter_var(getenv('LUMORA_WA_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
                'provider'    => strtolower(trim((string)(getenv('LUMORA_WA_PROVIDER') ?: 'waha'))),
                'phone'       => preg_replace('/[^0-9+]/', '', (string)(getenv('LUMORA_WA_PHONE') ?: '')) ?? '',
                'api_key'     => trim((string)(getenv('LUMORA_WA_APIKEY') ?: '')),
                'webhook_url' => trim((string)(getenv('LUMORA_WA_WEBHOOK_URL') ?: '')),
                'instance'    => trim((string)(getenv('LUMORA_WA_INSTANCE') ?: 'default')),
                'timeout_ms'  => 2500,
            ],
        ];

        if (isset($config['notifications']) && is_array($config['notifications'])) {
            if (isset($config['notifications']['email']) && is_array($config['notifications']['email'])) {
                $defaults['email'] = array_merge($defaults['email'], $config['notifications']['email']);
            }
            if (isset($config['notifications']['whatsapp']) && is_array($config['notifications']['whatsapp'])) {
                $defaults['whatsapp'] = array_merge($defaults['whatsapp'], $config['notifications']['whatsapp']);
            }
        }

        $resolvedRoot = $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd());
        $customFile = rtrim($resolvedRoot, '/') . '/storage/notify-config.php';
        if (is_file($customFile)) {
            $custom = include $customFile;
            if (is_array($custom)) {
                if (isset($custom['email']) && is_array($custom['email'])) {
                    $defaults['email'] = array_merge($defaults['email'], $custom['email']);
                }
                if (isset($custom['whatsapp']) && is_array($custom['whatsapp'])) {
                    $defaults['whatsapp'] = array_merge($defaults['whatsapp'], $custom['whatsapp']);
                }
            }
        }

        return $defaults;
    }

    public static function formatSummary(array $config, array $record): string
    {
        $brandName = strtoupper((string)($config['brand']['name'] ?? 'LUMORA'));
        $lines = [
            "{$brandName} INQUIRY [" . ($record['receipt_id'] ?? '') . ']',
            'Channel: ' . ($record['channel_label'] ?? $record['channel'] ?? ''),
            'Name: ' . ($record['full_name'] ?? ''),
        ];
        if (!empty($record['organization'])) {
            $lines[] = 'Organization: ' . $record['organization'];
        }
        $lines[] = 'Email: ' . ($record['email'] ?? '');
        if (!empty($record['phone'])) {
            $lines[] = 'Phone: ' . $record['phone'];
        }
        if (!empty($record['consultation_mode'])) {
            $lines[] = 'Mode: ' . $record['consultation_mode'];
        }
        if (!empty($record['facility_location'])) {
            $lines[] = 'Facility Location: ' . $record['facility_location'];
        }
        if (!empty($record['details']) && is_array($record['details'])) {
            foreach ($record['details'] as $k => $v) {
                $lines[] = $k . ': ' . (is_scalar($v) ? (string)$v : json_encode($v));
            }
        }
        $lines[] = 'Subject: ' . ($record['subject'] ?? '');
        $lines[] = 'Message: ' . mb_substr((string)($record['message'] ?? ''), 0, 800);
        $lines[] = 'Time: ' . ($record['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));

        return implode("\n", $lines);
    }

    private static function sendEdgeEmail(array $config, array $record, array $emailCfg, ?string $rootDir = null): string
    {
        if (empty($emailCfg['enabled'])) {
            return 'skipped';
        }

        $brandName = (string)($config['brand']['name'] ?? 'Lumora');
        $domain = (string)($config['brand']['domain'] ?? 'localhost');
        $receiptId = (string)($record['receipt_id'] ?? '');
        $to = trim(preg_replace('/[\r\n<>]/', '', (string)($emailCfg['to'] ?? '')) ?? '');
        $fromEmail = trim(preg_replace('/[\r\n<>]/', '', (string)($emailCfg['from_email'] ?? ('noreply@' . $domain))) ?? '');
        $replyTo = trim(preg_replace('/[\r\n<>]/', '', (string)($record['email'] ?? $fromEmail)) ?? '');

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'skipped';
        }

        $subject = str_replace(["\r", "\n"], ' ', sprintf('[%s %s] %s', $brandName, $receiptId, $record['subject'] ?? 'Inquiry'));
        $body = self::formatSummary($config, $record);
        $transport = strtolower((string)($emailCfg['transport'] ?? 'smtp'));

        if ($transport === 'mail') {
            $headers = [
                "From: {$brandName} Dispatch <{$fromEmail}>",
                "Reply-To: {$replyTo}",
                'Content-Type: text/plain; charset=UTF-8',
                'X-Mailer: Lumora-WebServer/0.1.0',
            ];
            return @mail($to, $subject, $body, implode("\r\n", $headers)) ? 'sent' : 'failed';
        }

        $host = trim((string)($emailCfg['smtp_host'] ?? ''));
        $port = (int)($emailCfg['smtp_port'] ?? 587);
        if ($host === '') {
            return 'skipped';
        }

        $timeoutSec = max(1.0, ((int)($emailCfg['timeout_ms'] ?? 2500)) / 1000.0);
        try {
            $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
            $errno = 0;
            $errstr = '';
            $streamCtx = stream_context_create([
                'ssl' => [
                    'verify_peer'       => true,
                    'verify_peer_name'  => true,
                    'allow_self_signed' => false,
                ],
            ]);
            $fp = @stream_socket_client($remote, $errno, $errstr, $timeoutSec, STREAM_CLIENT_CONNECT, $streamCtx);
            if (!is_resource($fp)) {
                return 'failed';
            }
            stream_set_timeout($fp, (int)ceil($timeoutSec));

            $readReply = static function ($socket): string {
                $data = '';
                while ($str = @fgets($socket, 515)) {
                    $data .= $str;
                    if (isset($str[3]) && $str[3] === ' ') {
                        break;
                    }
                }
                return $data;
            };
            $sendCmd = static function ($socket, string $cmd) use ($readReply): string {
                @fwrite($socket, $cmd . "\r\n");
                return $readReply($socket);
            };

            if (!str_starts_with($readReply($fp), '220')) {
                @fclose($fp);
                return 'failed';
            }

            $sendCmd($fp, 'EHLO ' . $domain);
            if ($port === 587 && !empty($emailCfg['smtp_tls'])) {
                if (str_starts_with($sendCmd($fp, 'STARTTLS'), '220')) {
                    if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                        @fclose($fp);
                        return 'failed';
                    }
                    $sendCmd($fp, 'EHLO ' . $domain);
                }
            }

            if (!empty($emailCfg['smtp_user']) && !empty($emailCfg['smtp_pass'])) {
                $sendCmd($fp, 'AUTH LOGIN');
                $sendCmd($fp, base64_encode((string)$emailCfg['smtp_user']));
                if (!str_starts_with($sendCmd($fp, base64_encode((string)$emailCfg['smtp_pass'])), '235')) {
                    @fclose($fp);
                    return 'failed';
                }
            }

            $sendCmd($fp, "MAIL FROM:<{$fromEmail}>");
            $rcptResp = $sendCmd($fp, "RCPT TO:<{$to}>");
            if (!str_starts_with($rcptResp, '250') && !str_starts_with($rcptResp, '251')) {
                @fclose($fp);
                return 'failed';
            }

            $sendCmd($fp, 'DATA');
            $mime = "From: {$brandName} Dispatch <{$fromEmail}>\r\n"
                . "To: <{$to}>\r\n"
                . "Reply-To: <{$replyTo}>\r\n"
                . "Subject: {$subject}\r\n"
                . 'Date: ' . gmdate('r') . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . str_replace("\n.", "\n..", $body) . "\r\n.";
            $dataResp = $sendCmd($fp, $mime);
            $sendCmd($fp, 'QUIT');
            @fclose($fp);

            return str_starts_with($dataResp, '250') ? 'sent' : 'failed';
        } catch (Throwable) {
            return 'failed';
        }
    }

    private static function sendEdgeWhatsApp(array $config, array $record, array $waCfg, ?string $rootDir = null): string
    {
        if (empty($waCfg['enabled']) || !function_exists('curl_init')) {
            return 'skipped';
        }

        $provider = strtolower(trim((string)($waCfg['provider'] ?? 'waha')));
        if ($provider === 'callmebot') {
            return 'skipped'; // Prohibited under zero-third-party data sovereignty
        }

        $webhookUrl = trim((string)($waCfg['webhook_url'] ?? ''));
        if ($webhookUrl === '') {
            return 'skipped';
        }

        $parsed = parse_url($webhookUrl);
        $scheme = strtolower((string)($parsed['scheme'] ?? ''));
        $host = strtolower((string)($parsed['host'] ?? ''));
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !$isLocal) {
            return 'skipped';
        }

        $phone = preg_replace('/[^0-9]/', '', (string)($waCfg['phone'] ?? '')) ?? '';
        $apiKey = trim((string)($waCfg['api_key'] ?? ''));
        $timeoutMs = max(1000, (int)($waCfg['timeout_ms'] ?? 2500));
        $text = self::formatSummary($config, $record);

        try {
            $ch = curl_init();
            if ($ch === false) {
                return 'failed';
            }
            $headers = ['Accept: application/json', 'Content-Type: application/json'];

            if ($provider === 'waha') {
                if ($phone === '') {
                    return 'skipped';
                }
                if ($apiKey !== '') {
                    $headers[] = 'X-Api-Key: ' . $apiKey;
                }
                $payload = json_encode([
                    'session' => $waCfg['instance'] ?? 'default',
                    'chatId'  => $phone . '@c.us',
                    'text'    => $text,
                ]);
                curl_setopt($ch, CURLOPT_URL, rtrim($webhookUrl, '/') . '/api/sendText');
            } elseif ($provider === 'evolution') {
                if ($phone === '') {
                    return 'skipped';
                }
                if ($apiKey !== '') {
                    $headers[] = 'apikey: ' . $apiKey;
                }
                $instance = rawurlencode((string)($waCfg['instance'] ?? 'default'));
                $payload = json_encode([
                    'number' => $phone,
                    'text'   => $text,
                ]);
                curl_setopt($ch, CURLOPT_URL, rtrim($webhookUrl, '/') . '/message/sendText/' . $instance);
            } else {
                if ($apiKey !== '') {
                    $headers[] = 'Authorization: Bearer ' . $apiKey;
                }
                $payload = json_encode([
                    'receipt_id' => $record['receipt_id'] ?? '',
                    'phone'      => $phone,
                    'text'       => $text,
                    'submission' => $record,
                ]);
                curl_setopt($ch, CURLOPT_URL, $webhookUrl);
            }

            curl_setopt_array($ch, [
                CURLOPT_POST              => true,
                CURLOPT_POSTFIELDS        => $payload,
                CURLOPT_HTTPHEADER        => $headers,
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_CONNECTTIMEOUT_MS => min(1500, $timeoutMs),
                CURLOPT_TIMEOUT_MS        => $timeoutMs,
            ]);

            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ($resp !== false && $code >= 200 && $code < 300) ? 'sent' : 'failed';
        } catch (Throwable) {
            return 'failed';
        }
    }
}
