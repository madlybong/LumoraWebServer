<?php

declare(strict_types=1);

namespace Lumora\Core;

use PDO;
use Throwable;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AdminAuth
{
    private const ROLE_LEVELS = [
        'viewer' => 1,
        'editor' => 2,
        'owner'  => 3,
    ];

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function normalizeRole(string $role): string
    {
        $clean = strtolower(trim($role));
        return isset(self::ROLE_LEVELS[$clean]) ? $clean : 'editor';
    }

    public static function hasRole(array $user, string $requiredRole, array $config = []): bool
    {
        $userRole = self::normalizeRole((string)($user['role'] ?? 'viewer'));
        $reqRole = self::normalizeRole($requiredRole);

        if (!empty($config['admin']['allowed_roles']) && is_array($config['admin']['allowed_roles'])) {
            $allowed = array_map(static fn ($r): string => self::normalizeRole((string)$r), $config['admin']['allowed_roles']);
            if (!in_array($userRole, $allowed, true)) {
                return false;
            }
        }

        return (self::ROLE_LEVELS[$userRole] ?? 0) >= (self::ROLE_LEVELS[$reqRole] ?? 99);
    }

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 3,
                'threads'     => 1,
            ]);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        if ($password === '' || $hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }

    public static function cookieName(): string
    {
        return Security::isLocalhost() ? 'lumora_admin' : '__Secure-lumora_admin';
    }

    public static function cookiePath(array $config, string $rootDir): string
    {
        $base = Router::basePath($rootDir);
        $adminPath = AdminDb::normalizeAdminPath($config);
        $combined = ($base !== '' ? rtrim($base, '/') : '') . $adminPath;
        return str_starts_with($combined, '/') ? $combined : '/' . $combined;
    }

    public static function setSessionCookie(string $rawToken, int $expiresTs, array $config, string $rootDir): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::cookieName(), $rawToken, [
            'expires'  => $expiresTs,
            'path'     => self::cookiePath($config, $rootDir),
            'secure'   => !Security::isLocalhost(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function clearSessionCookie(array $config, string $rootDir): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::cookieName(), '', [
            'expires'  => time() - 3600,
            'path'     => self::cookiePath($config, $rootDir),
            'secure'   => !Security::isLocalhost(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function csrfTokenForSession(array $session, int $now = 0): string
    {
        $tsHex = dechex($now > 0 ? $now : time());
        $sid = (string)($session['id'] ?? 0);
        $secret = (string)($session['csrf_secret'] ?? '');
        $mac = hash_hmac('sha256', "admin_csrf_v1|{$sid}|{$tsHex}", $secret);
        return "{$tsHex}.{$mac}";
    }

    public static function verifyCsrfForSession(array $session, ?string $token, int $maxTtlSec = 28800, int $now = 0): bool
    {
        if ($token === null || substr_count($token, '.') !== 1) {
            return false;
        }
        [$tsHex, $providedMac] = explode('.', trim($token), 2);
        if (!ctype_xdigit($tsHex) || !ctype_xdigit($providedMac) || strlen($providedMac) !== 64) {
            return false;
        }
        $issuedAt = (int)hexdec($tsHex);
        $current = $now > 0 ? $now : time();
        if ($issuedAt > $current + 60 || ($current - $issuedAt) > max(300, $maxTtlSec)) {
            return false;
        }
        $sid = (string)($session['id'] ?? 0);
        $secret = (string)($session['csrf_secret'] ?? '');
        if ($secret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', "admin_csrf_v1|{$sid}|{$tsHex}", $secret);
        return hash_equals($expected, $providedMac);
    }

    public static function hasAnyOwner(PDO $pdo): bool
    {
        try {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM admin_users WHERE role = "owner" AND is_active = 1')->fetchColumn();
            return $count > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function syncInitialOwnerFromConfig(PDO $pdo, array $config): void
    {
        if (self::hasAnyOwner($pdo)) {
            return;
        }
        $init = $config['admin']['initial_owner'] ?? [];
        if (!is_array($init)) {
            return;
        }
        $email = strtolower(trim((string)($init['email'] ?? '')));
        $hash = trim((string)($init['password_hash'] ?? ''));
        $name = trim((string)($init['name'] ?? 'Site Owner'));
        if (
            $email === ''
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || (!str_starts_with($hash, '$argon2') && !str_starts_with($hash, '$2y$') && !str_starts_with($hash, '$2b$'))
        ) {
            return;
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO admin_users (email, name, role, password_hash, is_active, created_at, updated_at)
             VALUES (:e, :n, "owner", :h, 1, :c, :u)'
        );
        $stmt->execute([
            ':e' => $email,
            ':n' => $name !== '' ? $name : 'Site Owner',
            ':h' => $hash,
            ':c' => $now,
            ':u' => $now,
        ]);
    }

    public static function isSetupTokenAvailable(PDO $pdo): bool
    {
        if (self::hasAnyOwner($pdo)) {
            return false;
        }
        $envToken = getenv('LUMORA_ADMIN_SETUP_TOKEN');
        return is_string($envToken) && strlen(trim($envToken)) >= 16;
    }

    /**
     * @return array<string, mixed>
     */
    public static function createUser(PDO $pdo, string $email, string $name, string $role, string $password, ?string $rootDir = null): array
    {
        $cleanEmail = strtolower(trim($email));
        if ($cleanEmail === '' || !filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'A valid email address is required.'];
        }
        if (strlen($password) < 10) {
            return ['ok' => false, 'error' => 'Password must be at least 10 characters.'];
        }
        $cleanRole = self::normalizeRole($role);
        $cleanName = AdminSanitizer::sanitizePlainText($name, 120);
        if ($cleanName === '') {
            $cleanName = explode('@', $cleanEmail)[0];
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $hash = self::hashPassword($password);

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO admin_users (email, name, role, password_hash, is_active, created_at, updated_at)
                 VALUES (:e, :n, :r, :h, 1, :c, :u)'
            );
            $stmt->execute([
                ':e' => $cleanEmail,
                ':n' => $cleanName,
                ':r' => $cleanRole,
                ':h' => $hash,
                ':c' => $now,
                ':u' => $now,
            ]);
            $id = (int)$pdo->lastInsertId();
            return [
                'ok' => true,
                'user' => [
                    'id'    => $id,
                    'email' => $cleanEmail,
                    'name'  => $cleanName,
                    'role'  => $cleanRole,
                ],
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Email already exists or cannot insert user.'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function completeSetupToken(
        PDO $pdo,
        array $config,
        string $rootDir,
        string $setupToken,
        string $email,
        string $name,
        string $password
    ): array {
        self::syncInitialOwnerFromConfig($pdo, $config);
        if (self::hasAnyOwner($pdo)) {
            return ['ok' => false, 'status' => 403, 'error' => 'Initial owner already exists; setup token is disabled.'];
        }

        $expected = trim((string)(getenv('LUMORA_ADMIN_SETUP_TOKEN') ?: ''));
        if (strlen($expected) < 16 || !hash_equals($expected, trim($setupToken))) {
            $ipHash = Security::clientIpHash($rootDir);
            self::recordFailedAuthAttempt($pdo, $ipHash, 'setup_token_invalid', $rootDir);
            return ['ok' => false, 'status' => 403, 'error' => 'Invalid setup token.'];
        }

        $created = self::createUser($pdo, $email, $name, 'owner', $password, $rootDir);
        if (empty($created['ok'])) {
            return ['ok' => false, 'status' => 422, 'error' => $created['error'] ?? 'Could not create owner.'];
        }

        $userId = (int)$created['user']['id'];
        AdminDb::logAudit($userId, $created['user']['email'], 'admin.setup_owner', 'admin_user', (string)$userId, [], $rootDir);
        $userRow = self::getUserById($pdo, $userId);
        if ($userRow === null) {
            return ['ok' => false, 'status' => 500, 'error' => 'Owner created but could not load session.'];
        }

        return self::issueSessionResponse($pdo, $config, $rootDir, $userRow, true);
    }

    public static function isLoginRateLimited(PDO $pdo, string $ipHash, array $config): bool
    {
        $maxAttempts = max(1, (int)($config['admin']['max_login_attempts'] ?? 5));
        $windowSec = max(60, (int)($config['admin']['lockout_window_sec'] ?? 900));
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $windowSec);
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM contact_rate_limits WHERE ip_hash = :ip AND event_type = "admin_login_failed" AND created_at >= :c'
            );
            $stmt->execute([':ip' => $ipHash, ':c' => $cutoff]);
            return ((int)$stmt->fetchColumn()) >= $maxAttempts;
        } catch (Throwable) {
            return false;
        }
    }

    public static function recordFailedAuthAttempt(PDO $pdo, string $ipHash, string $detail, string $rootDir): void
    {
        try {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $stmt = $pdo->prepare('INSERT INTO contact_rate_limits (ip_hash, event_type, created_at) VALUES (:ip, "admin_login_failed", :t)');
            $stmt->execute([':ip' => $ipHash, ':t' => $now]);
            InquiryDb::recordSecurityEvent($ipHash, 'admin_login_failed', $detail, $rootDir);
        } catch (Throwable) {
            // Ignore logging error
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function login(PDO $pdo, array $config, string $rootDir, string $email, string $password, string $totpCode = ''): array
    {
        self::syncInitialOwnerFromConfig($pdo, $config);
        $ipHash = Security::clientIpHash($rootDir);

        if (self::isLoginRateLimited($pdo, $ipHash, $config)) {
            return ['ok' => false, 'status' => 429, 'error' => 'Too many failed authentication attempts. Wait before retrying.'];
        }

        $cleanEmail = strtolower(trim($email));
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $cleanEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Constant-time mitigation dummy check if email absent
        $dummyHash = '$2y$10$usesomesillystringforsalt$abcdefghijklmnopqrstuuW4x5.s.8jK6s5J1p1H1p2F5b9iP9p';
        $validPassword = is_array($user)
            ? self::verifyPassword($password, (string)($user['password_hash'] ?? ''))
            : (password_verify($password, $dummyHash) && false);

        if (!is_array($user) || !$validPassword || (int)($user['is_active'] ?? 0) !== 1) {
            self::recordFailedAuthAttempt($pdo, $ipHash, 'email=' . $cleanEmail, $rootDir);
            return ['ok' => false, 'status' => 401, 'error' => 'Invalid email or password.'];
        }

        if (!self::hasRole($user, 'viewer', $config)) {
            self::recordFailedAuthAttempt($pdo, $ipHash, 'role_disallowed=' . ($user['role'] ?? ''), $rootDir);
            return ['ok' => false, 'status' => 403, 'error' => 'User role is not permitted on this deployment.'];
        }

        $totpEnabled = (int)($user['totp_enabled'] ?? 0) === 1;
        $totpVerified = true;

        if ($totpEnabled) {
            $totpVerified = false;
            $cleanCode = trim($totpCode);
            if ($cleanCode !== '') {
                $check = self::verifyUserSecondFactor($pdo, $user, $cleanCode);
                if (!empty($check['ok'])) {
                    $totpVerified = true;
                } else {
                    self::recordFailedAuthAttempt($pdo, $ipHash, 'invalid_2fa=' . $cleanEmail, $rootDir);
                    return ['ok' => false, 'status' => 401, 'error' => $check['error'] ?? 'Invalid authenticator or recovery code.'];
                }
            }
        }

        if ($totpVerified) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $upd = $pdo->prepare('UPDATE admin_users SET last_login_at = :t, updated_at = :u WHERE id = :id');
            $upd->execute([':t' => $now, ':u' => $now, ':id' => (int)$user['id']]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'auth.login', 'admin_user', (string)$user['id'], [], $rootDir);
        }

        return self::issueSessionResponse($pdo, $config, $rootDir, $user, $totpVerified);
    }

    /**
     * @return array<string, mixed>
     */
    public static function verifyPendingSecondFactor(PDO $pdo, array $config, string $rootDir, array $session, array $user, string $code): array
    {
        $ipHash = Security::clientIpHash($rootDir);
        if (self::isLoginRateLimited($pdo, $ipHash, $config)) {
            return ['ok' => false, 'status' => 429, 'error' => 'Too many failed verification attempts.'];
        }

        $res = self::verifyUserSecondFactor($pdo, $user, $code);
        if (empty($res['ok'])) {
            self::recordFailedAuthAttempt($pdo, $ipHash, 'invalid_2fa=' . ($user['email'] ?? ''), $rootDir);
            return ['ok' => false, 'status' => 401, 'error' => $res['error'] ?? 'Invalid authenticator or recovery code.'];
        }

        $ttl = max(300, (int)($config['admin']['session_ttl_sec'] ?? 28800));
        $expiresTs = time() + $ttl;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $updSess = $pdo->prepare('UPDATE admin_sessions SET totp_verified = 1, expires_at = :e, last_seen_at = :l WHERE id = :id');
        $updSess->execute([
            ':e'  => gmdate('Y-m-d\TH:i:s\Z', $expiresTs),
            ':l'  => $now,
            ':id' => (int)$session['id'],
        ]);

        $updUser = $pdo->prepare('UPDATE admin_users SET last_login_at = :t, updated_at = :u WHERE id = :id');
        $updUser->execute([':t' => $now, ':u' => $now, ':id' => (int)$user['id']]);
        AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'auth.verify_2fa', 'admin_user', (string)$user['id'], [], $rootDir);

        $rawCookie = trim((string)($_COOKIE[self::cookieName()] ?? ''));
        if ($rawCookie !== '') {
            self::setSessionCookie($rawCookie, $expiresTs, $config, $rootDir);
        }

        $freshUser = self::getUserById($pdo, (int)$user['id']) ?? $user;
        $freshSession = $session;
        $freshSession['totp_verified'] = 1;
        return [
            'ok'                 => true,
            'status'             => 200,
            'authenticated'      => true,
            'requires_2fa'       => false,
            'requires_2fa_setup' => !empty($config['admin']['require_2fa']) && (int)($freshUser['totp_enabled'] ?? 0) !== 1,
            'csrf_token'         => self::csrfTokenForSession($freshSession),
            'user'               => self::publicUser($freshUser),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function issueSessionResponse(PDO $pdo, array $config, string $rootDir, array $user, bool $totpVerified): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $sessionHash = hash_hmac('sha256', 'lumora_admin_sess|' . $rawToken, Security::secretKey($rootDir));
        $csrfSecret = bin2hex(random_bytes(32));
        $uaHash = self::currentUaHash();
        $ipHash = Security::clientIpHash($rootDir);

        $ttl = $totpVerified ? max(300, (int)($config['admin']['session_ttl_sec'] ?? 28800)) : 600;
        $expiresTs = time() + $ttl;
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $pdo->prepare(
            'INSERT INTO admin_sessions (session_hash, user_id, csrf_secret, ua_hash, ip_hash, totp_verified, expires_at, last_seen_at, created_at)
             VALUES (:sh, :uid, :cs, :ua, :ip, :tv, :ex, :ls, :ca)'
        );
        $stmt->execute([
            ':sh'  => $sessionHash,
            ':uid' => (int)$user['id'],
            ':cs'  => $csrfSecret,
            ':ua'  => $uaHash,
            ':ip'  => $ipHash,
            ':tv'  => $totpVerified ? 1 : 0,
            ':ex'  => gmdate('Y-m-d\TH:i:s\Z', $expiresTs),
            ':ls'  => $now,
            ':ca'  => $now,
        ]);
        $sessId = (int)$pdo->lastInsertId();

        self::setSessionCookie($rawToken, $expiresTs, $config, $rootDir);

        $sessionRow = ['id' => $sessId, 'csrf_secret' => $csrfSecret, 'totp_verified' => $totpVerified ? 1 : 0];
        $requiresSetup = !empty($config['admin']['require_2fa']) && (int)($user['totp_enabled'] ?? 0) !== 1;

        return [
            'ok'                 => true,
            'status'             => 200,
            'authenticated'      => $totpVerified,
            'requires_2fa'       => !$totpVerified,
            'requires_2fa_setup' => $totpVerified && $requiresSetup,
            'csrf_token'         => self::csrfTokenForSession($sessionRow),
            'user'               => $totpVerified ? self::publicUser($user) : ['id' => (int)$user['id'], 'email' => (string)$user['email'], 'name' => (string)$user['name'], 'role' => (string)$user['role']],
        ];
    }

    /**
     * @return array{session: array<string, mixed>, user: array<string, mixed>}|null
     */
    public static function currentSession(PDO $pdo, array $config, string $rootDir): ?array
    {
        $rawToken = trim((string)($_COOKIE[self::cookieName()] ?? ''));
        if ($rawToken === '' || !ctype_xdigit($rawToken) || strlen($rawToken) !== 64) {
            return null;
        }

        $sessionHash = hash_hmac('sha256', 'lumora_admin_sess|' . $rawToken, Security::secretKey($rootDir));
        $stmt = $pdo->prepare(
            'SELECT s.id AS sess_id, s.session_hash, s.user_id, s.csrf_secret, s.ua_hash, s.ip_hash,
                    s.totp_verified, s.expires_at, s.last_seen_at,
                    u.email, u.name, u.role, u.password_hash, u.totp_secret_enc, u.totp_pending_enc,
                    u.totp_enabled, u.totp_last_step, u.recovery_codes_json, u.is_active, u.last_login_at
             FROM admin_sessions s
             INNER JOIN admin_users u ON u.id = s.user_id
             WHERE s.session_hash = :sh
             LIMIT 1'
        );
        $stmt->execute([':sh' => $sessionHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $expiresTs = strtotime((string)($row['expires_at'] ?? '')) ?: 0;
        if ($expiresTs <= time() || (int)($row['is_active'] ?? 0) !== 1) {
            self::revokeSessionById($pdo, (int)$row['sess_id']);
            return null;
        }

        if (!hash_equals((string)($row['ua_hash'] ?? ''), self::currentUaHash())) {
            return null;
        }

        // Prune stale sessions opportunistically and refresh last_seen_at if >60s old
        $lastSeenTs = strtotime((string)($row['last_seen_at'] ?? '')) ?: 0;
        if ((time() - $lastSeenTs) > 60) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $pdo->prepare('UPDATE admin_sessions SET last_seen_at = :t WHERE id = :id')->execute([':t' => $now, ':id' => (int)$row['sess_id']]);
            $pdo->prepare('DELETE FROM admin_sessions WHERE expires_at < :now')->execute([':now' => $now]);
        }

        return [
            'session' => [
                'id'            => (int)$row['sess_id'],
                'user_id'       => (int)$row['user_id'],
                'csrf_secret'   => (string)$row['csrf_secret'],
                'totp_verified' => (int)$row['totp_verified'],
                'expires_at'    => (string)$row['expires_at'],
            ],
            'user' => [
                'id'                  => (int)$row['user_id'],
                'email'               => (string)$row['email'],
                'name'                => (string)$row['name'],
                'role'                => (string)$row['role'],
                'password_hash'       => (string)$row['password_hash'],
                'totp_secret_enc'     => (string)$row['totp_secret_enc'],
                'totp_pending_enc'    => (string)$row['totp_pending_enc'],
                'totp_enabled'        => (int)$row['totp_enabled'],
                'totp_last_step'      => (int)$row['totp_last_step'],
                'recovery_codes_json' => (string)$row['recovery_codes_json'],
                'is_active'           => (int)$row['is_active'],
                'last_login_at'       => (string)$row['last_login_at'],
            ],
        ];
    }

    public static function revokeSessionById(PDO $pdo, int $sessionId): void
    {
        $pdo->prepare('DELETE FROM admin_sessions WHERE id = :id')->execute([':id' => $sessionId]);
    }

    public static function revokeUserSessions(PDO $pdo, int $userId, ?int $exceptSessionId = null): void
    {
        if ($exceptSessionId !== null) {
            $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = :uid AND id != :sid')->execute([':uid' => $userId, ':sid' => $exceptSessionId]);
            return;
        }
        $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = :uid')->execute([':uid' => $userId]);
    }

    public static function publicUser(array $user): array
    {
        return [
            'id'            => (int)($user['id'] ?? 0),
            'email'         => (string)($user['email'] ?? ''),
            'name'          => (string)($user['name'] ?? ''),
            'role'          => self::normalizeRole((string)($user['role'] ?? 'viewer')),
            'totp_enabled'  => (int)($user['totp_enabled'] ?? 0) === 1,
            'is_active'     => (int)($user['is_active'] ?? 1) === 1,
            'last_login_at' => (string)($user['last_login_at'] ?? ''),
        ];
    }

    public static function getUserById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    // -------------------------------------------------------------------------
    // Pure-PHP RFC 6238 TOTP (HMAC-SHA1, 30s period, 6 digits) & Recovery Codes
    // -------------------------------------------------------------------------

    public static function generateTotpSecret(int $bytes = 20): string
    {
        $raw = random_bytes(max(16, $bytes));
        $binaryStr = '';
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $binaryStr .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        $alphabet = self::BASE32_ALPHABET;
        for ($j = 0, $bLen = strlen($binaryStr); $j < $bLen; $j += 5) {
            $chunk = str_pad(substr($binaryStr, $j, 5), 5, '0', STR_PAD_RIGHT);
            $encoded .= $alphabet[bindec($chunk)];
        }
        return $encoded;
    }

    public static function base32Decode(string $secret): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', strtoupper($secret)) ?? '');
        if ($clean === '') {
            return '';
        }
        $bits = '';
        $alphabet = self::BASE32_ALPHABET;
        for ($i = 0, $len = strlen($clean); $i < $len; $i++) {
            $idx = strpos($alphabet, $clean[$i]);
            if ($idx === false) {
                continue;
            }
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($j = 0, $bLen = strlen($bits); $j + 8 <= $bLen; $j += 8) {
            $out .= chr((int)bindec(substr($bits, $j, 8)));
        }
        return $out;
    }

    public static function hotp(string $binarySecret, int $counter, int $digits = 6): string
    {
        $hi = ($counter >> 32) & 0xFFFFFFFF;
        $lo = $counter & 0xFFFFFFFF;
        $binCounter = pack('N2', $hi, $lo);
        $hash = hash_hmac('sha1', $binCounter, $binarySecret, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );
        $mod = 10 ** $digits;
        return str_pad((string)($truncated % $mod), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{ok: bool, replayed: bool, step: int}
     */
    public static function verifyTotpCode(string $base32Secret, string $code, int $lastAcceptedStep = 0, int $now = 0, int $window = 1): array
    {
        $clean = preg_replace('/\s+/', '', trim($code)) ?? '';
        if (preg_match('/^\d{6}$/', $clean) !== 1) {
            return ['ok' => false, 'replayed' => false, 'step' => 0];
        }
        $binSecret = self::base32Decode($base32Secret);
        if ($binSecret === '') {
            return ['ok' => false, 'replayed' => false, 'step' => 0];
        }

        $ts = $now > 0 ? $now : time();
        $currentStep = intdiv($ts, 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $currentStep + $offset;
            if ($step < 0) {
                continue;
            }
            $expected = self::hotp($binSecret, $step, 6);
            if (hash_equals($expected, $clean)) {
                if ($lastAcceptedStep > 0 && $step <= $lastAcceptedStep) {
                    return ['ok' => false, 'replayed' => true, 'step' => $step];
                }
                return ['ok' => true, 'replayed' => false, 'step' => $step];
            }
        }

        return ['ok' => false, 'replayed' => false, 'step' => 0];
    }

    public static function otpAuthUri(string $base32Secret, string $email, string $issuer): string
    {
        $cleanIssuer = trim($issuer) !== '' ? trim($issuer) : 'Lumora Admin';
        $label = rawurlencode($cleanIssuer . ':' . $email);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            rawurlencode($base32Secret),
            rawurlencode($cleanIssuer)
        );
    }

    /**
     * @return array{plain: array<int, string>, hashes: array<int, string>}
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $plain[] = $code;
            $hashes[] = password_hash($code, PASSWORD_DEFAULT);
        }
        return ['plain' => $plain, 'hashes' => $hashes];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public static function verifyUserSecondFactor(PDO $pdo, array $user, string $inputCode): array
    {
        $clean = trim($inputCode);
        if ($clean === '') {
            return ['ok' => false, 'error' => 'Authenticator code or recovery code is required.'];
        }

        $encSecret = (string)($user['totp_secret_enc'] ?? '');
        $secret = $encSecret !== '' ? AdminDb::decryptValue($encSecret) : null;
        $lastStep = (int)($user['totp_last_step'] ?? 0);

        if (is_string($secret) && $secret !== '' && preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $clean) ?? '') === 1) {
            $totpRes = self::verifyTotpCode($secret, $clean, $lastStep);
            if ($totpRes['ok']) {
                $pdo->prepare('UPDATE admin_users SET totp_last_step = :s WHERE id = :id')
                    ->execute([':s' => $totpRes['step'], ':id' => (int)$user['id']]);
                return ['ok' => true];
            }
            if ($totpRes['replayed']) {
                return ['ok' => false, 'error' => 'This 6-digit code was already used. Wait for the next 30-second code.'];
            }
            return ['ok' => false, 'error' => 'Invalid authenticator code.'];
        }

        // Attempt single-use recovery code fallback
        $normalizedRecovery = strtoupper(preg_replace('/\s+/', '', $clean) ?? '');
        $hashes = json_decode((string)($user['recovery_codes_json'] ?? '[]'), true);
        if (!is_array($hashes) || empty($hashes)) {
            return ['ok' => false, 'error' => 'Invalid authenticator code.'];
        }

        foreach ($hashes as $idx => $hash) {
            if (is_string($hash) && password_verify($normalizedRecovery, $hash)) {
                unset($hashes[$idx]);
                $remainingJson = json_encode(array_values($hashes));
                $pdo->prepare('UPDATE admin_users SET recovery_codes_json = :j, updated_at = :u WHERE id = :id')
                    ->execute([
                        ':j'  => is_string($remainingJson) ? $remainingJson : '[]',
                        ':u'  => gmdate('Y-m-d\TH:i:s\Z'),
                        ':id' => (int)$user['id'],
                    ]);
                return ['ok' => true];
            }
        }

        return ['ok' => false, 'error' => 'Invalid authenticator or recovery code.'];
    }

    private static function currentUaHash(): string
    {
        return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? 'lumora-admin-ua'));
    }
}

\class_alias(AdminAuth::class, \Astrake\Lumora\Core\AdminAuth::class);
