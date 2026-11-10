<?php
declare(strict_types=1);

namespace Astrake\Lumora\Core {

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class InquiryEngine
{
    /**
     * Loads declarative channel & field definitions from content/channels.php.
     */
    public static function loadChannelConfig(?string $rootDir = null): array
    {
        $resolvedRoot = $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd());
        $file = rtrim($resolvedRoot, '/') . '/content/channels.php';
        $defaults = [
            'default_channel' => 'general',
            'honeypot_mode'   => 'reject', // 'reject' | 'fake_receipt'
            'aliases'         => [
                'data-rights' => 'privacy',
                'dpdp'        => 'privacy',
                'gdpr'        => 'privacy',
            ],
            'referer_map'     => [
                '/legal/security' => 'security',
                '/legal/privacy'  => 'privacy',
            ],
            'channels'        => [
                'general'  => [
                    'code'                => 'CHN-01',
                    'label'               => 'Business & Project Inquiry',
                    'heading'             => 'Send Us a Message',
                    'sla'                 => '1-2 Business Days',
                    'subject_placeholder' => 'e.g. Architecture Consultation or Project Scoping',
                    'message_label'       => 'How Can We Help? *',
                    'message_placeholder' => 'Describe your requirements, timeline, or questions...',
                    'button_text'         => 'Send Inquiry',
                    'fields'              => [],
                ],
                'security' => [
                    'code'                => 'CHN-02',
                    'label'               => 'Security Vulnerability Report',
                    'heading'             => 'Report a Security Issue',
                    'sla'                 => '24 Hours (CERT-In Mandate)',
                    'subject_placeholder' => 'Brief summary of the security finding',
                    'message_label'       => 'Vulnerability Details & Steps to Reproduce *',
                    'message_placeholder' => 'Describe the affected URL or component and steps to reproduce...',
                    'button_text'         => 'Submit Security Report',
                    'fields'              => [],
                ],
                'privacy'  => [
                    'code'                => 'CHN-03',
                    'label'               => 'Data Privacy & Statutory Rights',
                    'heading'             => 'Data Principal Rights & Grievance Redressal',
                    'sla'                 => '72 Hours',
                    'subject_placeholder' => 'e.g. Data Access, Correction, Erasure, or Grievance',
                    'message_label'       => 'Request Description & Reference *',
                    'message_placeholder' => 'Describe your statutory data rights request and any prior receipt ID...',
                    'button_text'         => 'Submit Privacy Request',
                    'fields'              => [],
                ],
            ],
        ];

        if (!is_file($file)) {
            return $defaults;
        }

        $loaded = require $file;
        if (!is_array($loaded)) {
            return $defaults;
        }

        if (isset($loaded['channels']) && is_array($loaded['channels'])) {
            return [
                'default_channel' => (string)($loaded['default_channel'] ?? $defaults['default_channel']),
                'honeypot_mode'   => (string)($loaded['honeypot_mode'] ?? $defaults['honeypot_mode']),
                'aliases'         => array_merge($defaults['aliases'], is_array($loaded['aliases'] ?? null) ? $loaded['aliases'] : []),
                'referer_map'     => array_merge($defaults['referer_map'], is_array($loaded['referer_map'] ?? null) ? $loaded['referer_map'] : []),
                'channels'        => $loaded['channels'],
            ];
        }

        return array_merge($defaults, ['channels' => $loaded]);
    }

    public static function resolveChannel(?array $postInput = null, ?array $getInput = null, ?string $rootDir = null): string
    {
        $cfg = self::loadChannelConfig($rootDir);
        $channels = $cfg['channels'];
        $defaultKey = (string)$cfg['default_channel'];
        if (!isset($channels[$defaultKey])) {
            $firstKey = array_key_first($channels);
            $defaultKey = is_string($firstKey) ? $firstKey : 'general';
        }

        $post = $postInput ?? $_POST;
        $get = $getInput ?? $_GET;
        $raw = strtolower(trim((string)($post['channel'] ?? $post['topic'] ?? $get['topic'] ?? $get['channel'] ?? '')));

        if ($raw !== '') {
            $mapped = (string)($cfg['aliases'][$raw] ?? $raw);
            if (isset($channels[$mapped])) {
                return $mapped;
            }
        }

        $referer = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            foreach ($cfg['referer_map'] as $needle => $targetChannel) {
                if (str_contains($referer, strtolower((string)$needle)) && isset($channels[$targetChannel])) {
                    return (string)$targetChannel;
                }
            }
        }

        return $defaultKey;
    }

    /**
     * Handles a first-party inquiry submission with strict Gate 09 execution order:
     * 1. Pre-CSRF Rate-Limit Check (5 / 900s default)
     * 2. Honeypot trap check ('website_url')
     * 3. Render-to-Submit timing guard ('_ts' >= 2s)
     * 4. Stateless v2 CGNAT-safe CSRF verification ('_csrf' or 'csrf_token')
     * 5. Declarative channel & conditional field validation
     * 6. SQLite WAL + JSONL backup + Standalone Edge Notifier + HMAC Hub Sync
     *
     * @param array<string, mixed>|null $postInput Optional POST override for CLI smoke tests
     */
    public static function handle(
        ?array $config = null,
        ?string $rootDir = null,
        ?array $postInput = null,
        int $nowOverride = 0
    ): array {
        $cfg = $config ?? (array)($GLOBALS['lumoraConfig'] ?? []);
        $resolvedRoot = $rootDir ?? (string)($GLOBALS['lumoraRootDir'] ?? getcwd());
        $channelCfg = self::loadChannelConfig($resolvedRoot);
        $channels = $channelCfg['channels'];

        $activeChannel = self::resolveChannel($postInput, $_GET, $resolvedRoot);
        $channelMeta = $channels[$activeChannel] ?? reset($channels) ?: ['label' => 'General Inquiry', 'sla' => '1-2 Business Days'];

        $isPost = ($postInput !== null) || (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
        if (!$isPost) {
            return [
                'submitted'      => false,
                'success'        => false,
                'receipt'        => '',
                'reference_id'   => '',
                'channel'        => $activeChannel,
                'channel_meta'   => $channelMeta,
                'errors'         => [],
                'field_errors'   => [],
                'data'           => ['channel' => $activeChannel],
                'values'         => ['channel' => $activeChannel, 'topic' => $activeChannel],
                'receipt_detail' => null,
            ];
        }

        $post = $postInput ?? $_POST;
        $now = $nowOverride > 0 ? $nowOverride : time();
        $ipHash = Security::clientIpHash($resolvedRoot);

        $secCfg = $cfg['security'] ?? [];
        $maxAttempts = max(1, (int)($secCfg['rate_limit_max_attempts'] ?? 5));
        $windowSec = max(60, (int)($secCfg['rate_limit_window_sec'] ?? 900));

        // 1. Pre-CSRF Rate-Limit Check across ALL POST attempts
        if (InquiryDb::isRateLimited($ipHash, $maxAttempts, $windowSec, $resolvedRoot)) {
            InquiryDb::recordSecurityEvent($ipHash, 'rate_limit_exceeded', "Blocked POST exceeding {$maxAttempts} / {$windowSec}s", $resolvedRoot);
            $msg = "Submission rate limit exceeded (maximum {$maxAttempts} attempts per " . (int)round($windowSec / 60) . ' minutes). Please wait before trying again.';
            return [
                'submitted'    => true,
                'success'      => false,
                'receipt'      => '',
                'reference_id' => '',
                'channel'      => $activeChannel,
                'channel_meta' => $channelMeta,
                'errors'       => [$msg],
                'field_errors' => ['form' => $msg],
                'data'         => $post,
                'values'       => $post,
            ];
        }

        // Record this attempt immediately for pre-CSRF rate-limit enforcement
        InquiryDb::recordSecurityEvent($ipHash, 'post_attempt', 'channel=' . $activeChannel, $resolvedRoot);

        // 2. Honeypot Trap Check ('website_url' must be empty)
        $honeypot = trim((string)($post['website_url'] ?? ''));
        if ($honeypot !== '') {
            InquiryDb::recordSecurityEvent($ipHash, 'honeypot_triggered', 'Hidden website_url populated', $resolvedRoot);
            if (($channelCfg['honeypot_mode'] ?? 'reject') === 'fake_receipt') {
                $prefix = strtoupper((string)($cfg['brand']['prefix'] ?? 'REF'));
                $fakeRef = $prefix . '-' . gmdate('Ymd', $now) . '-' . strtoupper(bin2hex(random_bytes(2)));
                return [
                    'submitted'    => true,
                    'success'      => true,
                    'receipt'      => $fakeRef,
                    'reference_id' => $fakeRef,
                    'channel'      => $activeChannel,
                    'channel_meta' => $channelMeta,
                    'errors'       => [],
                    'field_errors' => [],
                    'data'         => [],
                    'values'       => [],
                ];
            }
            $msg = 'Automated submission detected.';
            return [
                'submitted'    => true,
                'success'      => false,
                'receipt'      => '',
                'reference_id' => '',
                'channel'      => $activeChannel,
                'channel_meta' => $channelMeta,
                'errors'       => [$msg],
                'field_errors' => ['form' => $msg],
                'data'         => $post,
                'values'       => $post,
            ];
        }

        $errors = [];
        $fieldErrors = [];

        // 3. Render-to-Submit Timing Guard ('_ts' must be >= 2 seconds in the past)
        $submitTs = (int)($post['_ts'] ?? 0);
        if ($submitTs <= 0 || ($now - $submitTs) < 2) {
            InquiryDb::recordSecurityEvent($ipHash, 'timing_trap', 'Submitted under 2s threshold', $resolvedRoot);
            $msg = 'Please wait a moment before submitting the form.';
            $errors[] = $msg;
            $fieldErrors['form'] = $msg;
        }

        // 4. Stateless v2 CGNAT-Safe CSRF Verification ('_csrf' or 'csrf_token')
        $csrfToken = (string)($post['_csrf'] ?? $post['csrf_token'] ?? '');
        if (!Security::verifyCsrf($resolvedRoot, $csrfToken, $now)) {
            InquiryDb::recordSecurityEvent($ipHash, 'csrf_failure', 'Invalid or expired v2 CSRF token', $resolvedRoot);
            $msg = 'Your form security token expired or was invalid. Please submit your message again.';
            $errors[] = $msg;
            $fieldErrors['form'] = $msg;
        }

        // 5. Core Field Extraction (with cross-brand alias normalization)
        $fullName = trim((string)($post['full_name'] ?? $post['name'] ?? ''));
        $organization = trim((string)($post['organization'] ?? ''));
        $email = trim((string)($post['email'] ?? $post['work_email'] ?? ''));
        $phone = trim((string)($post['phone'] ?? ''));
        $subject = trim((string)($post['subject'] ?? ''));
        $message = trim((string)($post['message'] ?? ''));

        $rawMode = strtolower(trim((string)($post['consultation_mode'] ?? $post['delivery_mode'] ?? $post['demo_format'] ?? 'online')));
        $isOnsite = in_array($rawMode, ['onsite', 'in_person', 'on-site assessment at our facility'], true);
        $consultationMode = $isOnsite ? 'onsite' : 'online';
        $facilityLocation = trim((string)($post['facility_location'] ?? $post['city'] ?? ''));

        if ($fullName === '' || mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120 || !preg_match('/\p{L}.*\p{L}/u', $fullName)) {
            $msg = 'Please enter your full name (at least 2 letters, up to 120 characters).';
            $errors[] = $msg;
            $fieldErrors['name'] = $msg;
            $fieldErrors['full_name'] = $msg;
        }

        if ($organization !== '' && mb_strlen($organization) > 160) {
            $msg = 'Organization name must be 160 characters or fewer.';
            $errors[] = $msg;
            $fieldErrors['organization'] = $msg;
        }

        if ($email === '' || mb_strlen($email) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/', $email)) {
            $msg = 'Please enter a valid email address (e.g. name@organization.com).';
            $errors[] = $msg;
            $fieldErrors['email'] = $msg;
            $fieldErrors['work_email'] = $msg;
        }

        if ($phone !== '') {
            $digitCount = preg_match_all('/[0-9]/', $phone);
            if (!preg_match('/^\+?[0-9\s\-().]{7,25}$/', $phone) || $digitCount < 7 || $digitCount > 15) {
                $msg = 'Please enter a valid phone number (7 to 15 digits).';
                $errors[] = $msg;
                $fieldErrors['phone'] = $msg;
            }
        }

        // Conditional On-Site Assessment Validation
        if ($isOnsite) {
            if (mb_strlen($facilityLocation) < 2 || mb_strlen($facilityLocation) > 200) {
                $msg = 'Please enter your facility city and location for an on-site session.';
                $errors[] = $msg;
                $fieldErrors['facility_location'] = $msg;
                $fieldErrors['city'] = $msg;
            }
            if ($phone === '') {
                $msg = 'A contact phone number is required to coordinate on-site assessments.';
                $errors[] = $msg;
                $fieldErrors['phone'] = $msg;
            }
        }

        // 6. Declarative Custom Channel Fields (from content/channels.php)
        $receiptDetails = [];
        $customFields = is_array($channelMeta['fields'] ?? null) ? $channelMeta['fields'] : [];

        foreach ($customFields as $fieldName => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $rawVal = $post[$fieldName] ?? ($rule['default'] ?? '');
            $val = is_array($rawVal)
                ? implode(', ', array_map(static fn($v) => trim((string)$v), $rawVal))
                : trim((string)$rawVal);

            $isRequired = !empty($rule['required']);
            if (!$isRequired && !empty($rule['required_if']) && is_array($rule['required_if'])) {
                foreach ($rule['required_if'] as $depField => $depExpected) {
                    $actualDep = trim((string)($post[$depField] ?? ''));
                    if (is_array($depExpected) ? in_array($actualDep, $depExpected, true) : ($actualDep === (string)$depExpected)) {
                        $isRequired = true;
                    }
                }
            }

            if ($isRequired && $val === '') {
                $msg = (string)($rule['error_message'] ?? ("Please provide {$fieldName}."));
                $errors[] = $msg;
                $fieldErrors[$fieldName] = $msg;
                continue;
            }

            if ($val !== '') {
                $type = (string)($rule['type'] ?? 'string');
                if ($type === 'enum' && !empty($rule['options']) && is_array($rule['options'])) {
                    if (!in_array($val, $rule['options'], true)) {
                        $msg = (string)($rule['error_message'] ?? ("Please select a valid option for {$fieldName}."));
                        $errors[] = $msg;
                        $fieldErrors[$fieldName] = $msg;
                        continue;
                    }
                } elseif ($type === 'url') {
                    if (!preg_match('#^https?://#i', $val)) {
                        $val = 'https://' . $val;
                    }
                    $host = (string)parse_url($val, PHP_URL_HOST);
                    if (!filter_var($val, FILTER_VALIDATE_URL) || !str_contains($host, '.')) {
                        $msg = (string)($rule['error_message'] ?? 'Please enter a valid URL.');
                        $errors[] = $msg;
                        $fieldErrors[$fieldName] = $msg;
                        continue;
                    }
                }

                $minLen = (int)($rule['min_length'] ?? 0);
                $maxLen = (int)($rule['max_length'] ?? 500);
                if (($minLen > 0 && mb_strlen($val) < $minLen) || ($maxLen > 0 && mb_strlen($val) > $maxLen)) {
                    $msg = (string)($rule['error_message'] ?? ("Field {$fieldName} must be between {$minLen} and {$maxLen} characters."));
                    $errors[] = $msg;
                    $fieldErrors[$fieldName] = $msg;
                    continue;
                }

                $label = (string)($rule['receipt_label'] ?? strtoupper(str_replace('_', ' ', (string)$fieldName)));
                $receiptDetails[$label] = $val;
            }
        }

        // Synthesize default subject if the form does not render an explicit subject input
        if ($subject === '' && !empty($channelMeta['auto_subject'])) {
            $subject = (string)$channelMeta['label'] . ' — ' . ($organization !== '' ? $organization : $fullName);
        }
        if (mb_strlen($subject) < 2 || mb_strlen($subject) > 240) {
            $msg = 'Please enter a brief subject (2 to 240 characters).';
            $errors[] = $msg;
            $fieldErrors['subject'] = $msg;
        }

        $minMessage = max(5, (int)($channelMeta['min_message_length'] ?? 10));
        $maxMessage = max(500, (int)($channelMeta['max_message_length'] ?? 4000));
        if (mb_strlen($message) < $minMessage || mb_strlen($message) > $maxMessage) {
            $msg = "Please provide a message between {$minMessage} and {$maxMessage} characters.";
            $errors[] = $msg;
            $fieldErrors['message'] = $msg;
        }

        // Consent verification ('consent' or 'dpdp_consent', unless channel disables explicit checkbox)
        $requireConsent = (bool)($channelMeta['require_consent'] ?? true);
        $consentGiven = !empty($post['consent']) || !empty($post['dpdp_consent']);
        if ($requireConsent && !$consentGiven) {
            $msg = 'Please confirm the privacy policy acknowledgment before submitting.';
            $errors[] = $msg;
            $fieldErrors['consent'] = $msg;
            $fieldErrors['dpdp_consent'] = $msg;
        }

        $submittedData = array_merge($post, [
            'channel'           => $activeChannel,
            'name'              => $fullName,
            'full_name'         => $fullName,
            'organization'      => $organization,
            'email'             => $email,
            'work_email'        => $email,
            'phone'             => $phone,
            'subject'           => $subject,
            'message'           => $message,
            'consultation_mode' => $consultationMode,
            'facility_location' => $facilityLocation,
        ]);

        if (!empty($errors)) {
            if (!empty($fieldErrors)) {
                InquiryDb::recordSecurityEvent($ipHash, 'validation_failed', 'count=' . count($fieldErrors), $resolvedRoot);
            }
            return [
                'submitted'    => true,
                'success'      => false,
                'receipt'      => '',
                'reference_id' => '',
                'channel'      => $activeChannel,
                'channel_meta' => $channelMeta,
                'errors'       => $errors,
                'field_errors' => $fieldErrors,
                'data'         => $submittedData,
                'values'       => $submittedData,
            ];
        }

        // 7. Generate Receipt ID, Persist to SQLite WAL + JSONL, Dispatch Notifications & Optional Hub Sync
        $prefix = strtoupper((string)($cfg['brand']['prefix'] ?? 'LUM'));
        $receiptId = $prefix . '-' . gmdate('Ymd', $now) . '-' . strtoupper(bin2hex(random_bytes(2)));
        $isoCreated = gmdate('Y-m-d\TH:i:s\Z', $now);
        $displayTs = gmdate('Y-m-d H:i \U\T\C', $now);

        $record = [
            'receipt_id'        => $receiptId,
            'brand_id'          => (string)($cfg['brand']['id'] ?? 'lumora'),
            'channel'           => $activeChannel,
            'channel_label'     => (string)($channelMeta['label'] ?? 'Inquiry'),
            'sla_window'        => (string)($channelMeta['sla'] ?? '1-2 Business Days'),
            'full_name'         => $fullName,
            'organization'      => $organization,
            'email'             => $email,
            'phone'             => $phone,
            'subject'           => $subject,
            'message'           => $message,
            'consultation_mode' => $consultationMode,
            'facility_location' => $facilityLocation,
            'details'           => $receiptDetails,
            'ip_hash'           => $ipHash,
            'user_agent'        => (string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI'),
            'email_status'      => 'pending',
            'whatsapp_status'   => 'pending',
            'sync_status'       => 'pending',
            'created_at'        => $isoCreated,
        ];

        $dbId = InquiryDb::saveSubmission($record, $cfg, $resolvedRoot);

        // Hub sync if enabled
        $hubResult = HubClient::syncInquiryToHub($cfg, $record, $resolvedRoot);
        $syncStatus = $hubResult['synced'] ? 'synced' : (empty($cfg['hub']['enabled']) ? 'skipped' : 'pending');

        // Standalone Edge notifications (runs when Hub is disabled, or when fallback_notify is enabled and Hub failed)
        $shouldRunEdgeNotify = empty($cfg['hub']['enabled'])
            || (!$hubResult['synced'] && !empty($cfg['hub']['fallback_notify']))
            || !empty($cfg['notifications']['email']['enabled'])
            || !empty($cfg['notifications']['whatsapp']['enabled']);

        $emailStatus = 'skipped';
        $waStatus = 'skipped';
        if ($shouldRunEdgeNotify) {
            $edgeDispatch = HubClient::dispatchEdgeNotifications($cfg, $record, $resolvedRoot);
            $emailStatus = $edgeDispatch['email_status'];
            $waStatus = $edgeDispatch['whatsapp_status'];
        }

        if ($dbId === null && !$hubResult['synced'] && $emailStatus !== 'sent' && $waStatus !== 'sent') {
            InquiryDb::recordSecurityEvent($ipHash, 'storage_failure', 'SQLite and JSONL failed for ' . $receiptId, $resolvedRoot);
            if (!headers_sent()) {
                http_response_code(503);
            }
            $contactEmail = (string)($cfg['brand']['contact_email'] ?? ('office@' . ($cfg['brand']['domain'] ?? 'localhost')));
            $failMsg = "We could not save your message right now due to a temporary storage error. Please try again or email {$contactEmail}.";
            return [
                'submitted'    => true,
                'success'      => false,
                'receipt'      => '',
                'reference_id' => '',
                'channel'      => $activeChannel,
                'channel_meta' => $channelMeta,
                'errors'       => [$failMsg],
                'field_errors' => ['form' => $failMsg],
                'data'         => $submittedData,
                'values'       => $submittedData,
            ];
        }

        InquiryDb::recordSecurityEvent($ipHash, 'submission_accepted', "receipt={$receiptId} channel={$activeChannel}", $resolvedRoot);
        InquiryDb::updateDeliveryAndSyncStatus(
            $receiptId,
            $emailStatus,
            $waStatus,
            $syncStatus,
            $hubResult['hub_receipt'],
            $resolvedRoot
        );

        return [
            'submitted'       => true,
            'success'         => true,
            'receipt'         => $receiptId,
            'reference_id'    => $receiptId,
            'db_id'           => $dbId,
            'channel'         => $activeChannel,
            'channel_label'   => (string)($channelMeta['label'] ?? 'Inquiry'),
            'channel_meta'    => $channelMeta,
            'sla_window'      => (string)($channelMeta['sla'] ?? '1-2 Business Days'),
            'timestamp'       => $displayTs,
            'created_at'      => $isoCreated,
            'name'            => $fullName,
            'email'           => $email,
            'return_contact'  => $email . ($phone !== '' ? ' / ' . $phone : ''),
            'details'         => $receiptDetails,
            'email_status'    => $emailStatus,
            'whatsapp_status' => $waStatus,
            'sync_status'     => $syncStatus,
            'hub_signature'   => $hubResult['signature'],
            'errors'          => [],
            'field_errors'    => [],
            'data'            => [],
            'values'          => ['channel' => $activeChannel, 'topic' => $activeChannel],
        ];
    }
}
}

namespace {
    use Astrake\Lumora\Core\InquiryEngine;

    if (!function_exists('lumora_handle_inquiry')) {
        function lumora_handle_inquiry(?array $postInput = null): array
        {
            return InquiryEngine::handle(null, null, $postInput);
        }
    }
}
