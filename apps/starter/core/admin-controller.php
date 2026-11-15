<?php

declare(strict_types=1);

namespace Lumora\Core;

use PDO;
use Throwable;

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AdminController
{
    public static function tryServe(string $relativePath, array $config, string $rootDir, string $basePath): bool
    {
        if (empty($config['features']['admin_panel'])) {
            return false;
        }

        $adminPath = AdminDb::normalizeAdminPath($config);
        $trimmedAdmin = rtrim($adminPath, '/');

        if ($relativePath === $trimmedAdmin) {
            self::emitAdminHeaders();
            if (!headers_sent()) {
                header('Location: ' . Router::url($adminPath, $basePath), true, 302);
            }
            return true;
        }

        if ($relativePath === $adminPath) {
            self::serveShell($config, $rootDir, $adminPath, $basePath);
            return true;
        }

        if ($relativePath === $adminPath . 'api/' || $relativePath === $adminPath . 'api') {
            self::serveApi($config, $rootDir, $adminPath, $basePath);
            return true;
        }

        return false;
    }

    private static function emitAdminHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    public static function serveShell(array $config, string $rootDir, string $adminPath, string $basePath): void
    {
        self::emitAdminHeaders();
        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }

        $brandingCfg = is_array($config['admin']['branding'] ?? null) ? $config['admin']['branding'] : [];
        $brand = is_array($config['brand'] ?? null) ? $config['brand'] : [];
        $defaultTheme = in_array((string)($brandingCfg['default_theme'] ?? 'system'), ['light', 'dark', 'system'], true)
            ? (string)($brandingCfg['default_theme'] ?? 'system')
            : 'system';

        $cssPath = '/public/assets/admin/lumora-admin.css';
        $jsPath = '/public/assets/admin/lumora-admin.js';
        $cssUrl = Render::assetUrl($cssPath, $rootDir);
        $jsUrl = Render::assetUrl($jsPath, $rootDir);
        $cssSri = Render::assetSri($cssPath, $rootDir);
        $jsSri = Render::assetSri($jsPath, $rootDir);
        $nonce = Security::cspNonce();

        $bootPayload = [
            'apiUrl'           => Router::url($adminPath . 'api/', $basePath),
            'siteUrl'          => Router::url('/', $basePath),
            'adminPath'        => $adminPath,
            'bootstrapCsrf'    => Security::csrfToken($rootDir),
            'branding'         => [
                'title'        => (string)($brandingCfg['title'] ?? (($brand['name'] ?? 'Lumora') . ' Console')),
                'brandName'    => (string)($brand['name'] ?? 'Lumora'),
                'brandId'      => (string)($brand['id'] ?? 'lumora'),
                'domain'       => (string)($brand['domain'] ?? 'localhost'),
                'logo'         => !empty($brandingCfg['logo']) ? Router::url((string)$brandingCfg['logo'], $basePath) : '',
                'logoDark'     => !empty($brandingCfg['logo_dark']) ? Router::url((string)$brandingCfg['logo_dark'], $basePath) : '',
                'defaultTheme' => $defaultTheme,
            ],
            'features'         => is_array($config['features'] ?? null) ? $config['features'] : [],
            'legalTerritories' => is_array($config['legal']['territories'] ?? null) ? $config['legal']['territories'] : ['GLOBAL'],
            'require2fa'       => !empty($config['admin']['require_2fa']),
        ];

        $bootJson = json_encode($bootPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($bootJson)) {
            $bootJson = '{}';
        }
        $defaultThemeJson = json_encode($defaultTheme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '"system"';

        $title = Render::escape($bootPayload['branding']['title']);
        $cssLink = '<link rel="stylesheet" href="' . Render::escape($cssUrl) . '"' . ($cssSri !== '' ? ' integrity="' . Render::escape($cssSri) . '" crossorigin="anonymous"' : '') . ' />';
        $jsTag = '<script type="module" src="' . Render::escape($jsUrl) . '" nonce="' . Render::escape($nonce) . '"' . ($jsSri !== '' ? ' integrity="' . Render::escape($jsSri) . '" crossorigin="anonymous"' : '') . '></script>';
        $faviconTag = !empty($brand['icon'])
            ? '<link rel="icon" href="' . Render::escape(Router::url((string)$brand['icon'], $basePath)) . '" />'
            : '';

        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="light dark" />
<meta name="robots" content="noindex, nofollow, noarchive" />
<title>{$title}</title>
{$faviconTag}
<script nonce="{$nonce}">
(function(){try{var d={$defaultThemeJson};var s=localStorage.getItem('lumora_admin_theme');var m=s||d;var dark=m==='dark'||(m==='system'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.classList.toggle('dark',!!dark);document.documentElement.dataset.theme=dark?'dark':'light';}catch(_){}})();
</script>
{$cssLink}
</head>
<body class="bg-background text-foreground antialiased selection:bg-primary selection:text-primary-foreground">
<div id="lumora-admin-app"></div>
<script id="lumora-admin-boot" type="application/json" nonce="{$nonce}">{$bootJson}</script>
{$jsTag}
</body>
</html>
HTML;
    }

    public static function serveApi(array $config, string $rootDir, string $adminPath, string $basePath): void
    {
        self::emitAdminHeaders();

        $action = trim((string)($_GET['action'] ?? ''));
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($action === '') {
            self::jsonResponse(['ok' => false, 'error' => 'Missing action parameter.'], 400);
            return;
        }

        $pdo = AdminDb::connect($rootDir);
        if (!$pdo instanceof PDO) {
            self::jsonResponse(['ok' => false, 'error' => 'SQLite admin database is unavailable on this host.'], 503);
            return;
        }

        AdminAuth::syncInitialOwnerFromConfig($pdo, $config);

        try {
            // 1. Auth Status (public bootstrap check inside admin path)
            if ($action === 'auth.status' && $method === 'GET') {
                self::handleAuthStatus($pdo, $config, $rootDir);
                return;
            }

            // 2. Unauthenticated bootstrap / login / logout actions (stateless v2 CSRF)
            if ($action === 'auth.setup' && $method === 'POST') {
                if (!self::verifyRequestBootstrapCsrf($rootDir)) {
                    self::jsonResponse(['ok' => false, 'error' => 'Invalid or expired security token. Reload and retry.'], 403);
                    return;
                }
                $body = self::readJsonBody();
                $res = AdminAuth::completeSetupToken(
                    $pdo,
                    $config,
                    $rootDir,
                    (string)($body['setup_token'] ?? ''),
                    (string)($body['email'] ?? ''),
                    (string)($body['name'] ?? ''),
                    (string)($body['password'] ?? '')
                );
                self::jsonResponse($res, (int)($res['status'] ?? ($res['ok'] ? 200 : 400)));
                return;
            }

            if ($action === 'auth.login' && $method === 'POST') {
                if (!self::verifyRequestBootstrapCsrf($rootDir)) {
                    self::jsonResponse(['ok' => false, 'error' => 'Invalid or expired security token. Reload and retry.'], 403);
                    return;
                }
                $body = self::readJsonBody();
                $res = AdminAuth::login(
                    $pdo,
                    $config,
                    $rootDir,
                    (string)($body['email'] ?? ''),
                    (string)($body['password'] ?? ''),
                    (string)($body['code'] ?? '')
                );
                self::jsonResponse($res, (int)($res['status'] ?? ($res['ok'] ? 200 : 401)));
                return;
            }

            if ($action === 'auth.logout' && $method === 'POST') {
                $sess = AdminAuth::currentSession($pdo, $config, $rootDir);
                $csrfHeader = self::readCsrfHeader();
                $csrfValid = self::verifyRequestBootstrapCsrf($rootDir)
                    || ($sess !== null && AdminAuth::verifyCsrfForSession($sess['session'], $csrfHeader));
                if (!$csrfValid) {
                    self::jsonResponse(['ok' => false, 'error' => 'CSRF token mismatch.'], 403);
                    return;
                }
                if ($sess !== null) {
                    AdminDb::logAudit((int)$sess['user']['id'], (string)$sess['user']['email'], 'auth.logout', 'admin_session', (string)$sess['session']['id'], [], $rootDir);
                    AdminAuth::revokeSessionById($pdo, (int)$sess['session']['id']);
                }
                AdminAuth::clearSessionCookie($config, $rootDir);
                self::jsonResponse([
                    'ok'             => true,
                    'authenticated'  => false,
                    'bootstrap_csrf' => Security::csrfToken($rootDir),
                ], 200);
                return;
            }

            // 3. Require valid cookie session for all subsequent actions
            $auth = AdminAuth::currentSession($pdo, $config, $rootDir);
            if ($auth === null) {
                self::jsonResponse(['ok' => false, 'error' => 'Authentication required.'], 401);
                return;
            }
            $session = $auth['session'];
            $user = $auth['user'];

            // Verify session-bound CSRF on all mutating HTTP methods
            if ($method !== 'GET' && $method !== 'HEAD') {
                $ttl = max(300, (int)($config['admin']['session_ttl_sec'] ?? 28800));
                if (!AdminAuth::verifyCsrfForSession($session, self::readCsrfHeader(), $ttl)) {
                    self::jsonResponse(['ok' => false, 'error' => 'Invalid or expired session CSRF token.'], 403);
                    return;
                }
            }

            // Handle Step-2 2FA challenge completion
            if ($action === 'auth.verify-2fa' && $method === 'POST') {
                $body = self::readJsonBody();
                $res = AdminAuth::verifyPendingSecondFactor($pdo, $config, $rootDir, $session, $user, (string)($body['code'] ?? ''));
                self::jsonResponse($res, (int)($res['status'] ?? ($res['ok'] ? 200 : 401)));
                return;
            }

            if ((int)($session['totp_verified'] ?? 0) !== 1) {
                self::jsonResponse(['ok' => false, 'requires_2fa' => true, 'error' => 'Two-factor verification required.'], 403);
                return;
            }

            // Enforce mandatory 2FA enrollment policy when configured
            $requires2faSetup = !empty($config['admin']['require_2fa']) && (int)($user['totp_enabled'] ?? 0) !== 1;
            $setupAllowed = in_array($action, ['profile.get', 'profile.update', 'profile.password', 'profile.totp-begin', 'profile.totp-confirm'], true);
            if ($requires2faSetup && !$setupAllowed) {
                self::jsonResponse(['ok' => false, 'requires_2fa_setup' => true, 'error' => 'Enroll two-factor authentication before accessing console modules.'], 403);
                return;
            }

            self::dispatchAuthenticatedAction($action, $method, $pdo, $config, $rootDir, $adminPath, $basePath, $session, $user);
        } catch (Throwable $e) {
            self::jsonResponse(['ok' => false, 'error' => 'Unexpected server error while processing admin action.'], 500);
        }
    }

    private static function computeBadges(PDO $pdo): array
    {
        return [
            'new_inquiries'   => (int)$pdo->query('SELECT COUNT(*) FROM contact_submissions WHERE triage_status = "new"')->fetchColumn(),
            'pending_outbox'  => (int)$pdo->query('SELECT COUNT(*) FROM contact_submissions WHERE sync_status IN ("pending", "failed")')->fetchColumn(),
            'unresolved_404s' => (int)$pdo->query('SELECT COUNT(*) FROM cms_404_log')->fetchColumn(),
        ];
    }

    private static function handleAuthStatus(PDO $pdo, array $config, string $rootDir): void
    {
        $hasOwner = AdminAuth::hasAnyOwner($pdo);
        $setupAvailable = AdminAuth::isSetupTokenAvailable($pdo);
        $auth = AdminAuth::currentSession($pdo, $config, $rootDir);

        if ($auth === null) {
            self::jsonResponse([
                'ok'                 => true,
                'authenticated'      => false,
                'requires_2fa'       => false,
                'requires_2fa_setup' => false,
                'has_owner'          => $hasOwner,
                'setup_available'    => $setupAvailable,
                'bootstrap_csrf'     => Security::csrfToken($rootDir),
                'user'               => null,
            ]);
            return;
        }

        $sess = $auth['session'];
        $user = $auth['user'];
        $totpVerified = (int)($sess['totp_verified'] ?? 0) === 1;
        $requiresSetup = !empty($config['admin']['require_2fa']) && (int)($user['totp_enabled'] ?? 0) !== 1;

        self::jsonResponse([
            'ok'                 => true,
            'authenticated'      => $totpVerified,
            'requires_2fa'       => !$totpVerified,
            'requires_2fa_setup' => $totpVerified && $requiresSetup,
            'has_owner'          => $hasOwner,
            'setup_available'    => $setupAvailable,
            'csrf_token'         => AdminAuth::csrfTokenForSession($sess),
            'bootstrap_csrf'     => Security::csrfToken($rootDir),
            'user'               => AdminAuth::publicUser($user),
            'badges'             => $totpVerified ? self::computeBadges($pdo) : null,
        ]);
    }

    private static function dispatchAuthenticatedAction(
        string $action,
        string $method,
        PDO $pdo,
        array $config,
        string $rootDir,
        string $adminPath,
        string $basePath,
        array $session,
        array $user
    ): void {
        $require = static function (string $role) use ($user, $config): bool {
            if (!AdminAuth::hasRole($user, $role, $config)) {
                self::jsonResponse(['ok' => false, 'error' => "Insufficient permissions ({$role} role required)."], 403);
                return false;
            }
            return true;
        };

        $body = $method === 'POST' ? self::readJsonBody() : [];

        // ---------------------------------------------------------------------
        // Profile & Security (All Authenticated Roles)
        // ---------------------------------------------------------------------
        if ($action === 'profile.get' && $method === 'GET') {
            $sessStmt = $pdo->prepare('SELECT id, ip_hash, totp_verified, last_seen_at, expires_at, created_at FROM admin_sessions WHERE user_id = :u ORDER BY last_seen_at DESC');
            $sessStmt->execute([':u' => (int)$user['id']]);
            self::jsonResponse([
                'ok'       => true,
                'user'     => AdminAuth::publicUser($user),
                'sessions' => array_map(static fn (array $r): array => [
                    'id'           => (int)$r['id'],
                    'is_current'   => (int)$r['id'] === (int)$session['id'],
                    'ip_hash_short'=> substr((string)$r['ip_hash'], 0, 12),
                    'last_seen_at' => (string)$r['last_seen_at'],
                    'expires_at'   => (string)$r['expires_at'],
                ], $sessStmt->fetchAll(PDO::FETCH_ASSOC)),
            ]);
            return;
        }

        if ($action === 'profile.update' && $method === 'POST') {
            $nextName = AdminSanitizer::sanitizePlainText((string)($body['name'] ?? ''), 120);
            if ($nextName === '') {
                self::jsonResponse(['ok' => false, 'error' => 'Display name cannot be empty.'], 422);
                return;
            }
            $pdo->prepare('UPDATE admin_users SET name = :n, updated_at = :u WHERE id = :id')
                ->execute([':n' => $nextName, ':u' => gmdate('Y-m-d\TH:i:s\Z'), ':id' => (int)$user['id']]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'profile.update', 'admin_user', (string)$user['id'], ['name' => $nextName], $rootDir);
            $fresh = AdminAuth::getUserById($pdo, (int)$user['id']) ?? $user;
            self::jsonResponse(['ok' => true, 'user' => AdminAuth::publicUser($fresh)]);
            return;
        }

        if ($action === 'profile.revoke-session' && $method === 'POST') {
            $targetSessId = (int)($body['session_id'] ?? 0);
            if ($targetSessId <= 0 || $targetSessId === (int)$session['id']) {
                self::jsonResponse(['ok' => false, 'error' => 'Select a non-current active session to revoke.'], 422);
                return;
            }
            $stmt = $pdo->prepare('DELETE FROM admin_sessions WHERE id = :sid AND user_id = :uid');
            $stmt->execute([':sid' => $targetSessId, ':uid' => (int)$user['id']]);
            if ($stmt->rowCount() > 0) {
                AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'profile.revoke_session', 'admin_session', (string)$targetSessId, [], $rootDir);
            }
            self::jsonResponse(['ok' => true, 'deleted' => $stmt->rowCount()]);
            return;
        }

        if ($action === 'profile.password' && $method === 'POST') {
            $cur = (string)($body['current_password'] ?? '');
            $next = (string)($body['new_password'] ?? '');
            if (!AdminAuth::verifyPassword($cur, (string)$user['password_hash'])) {
                self::jsonResponse(['ok' => false, 'error' => 'Current password is incorrect.'], 401);
                return;
            }
            if (strlen($next) < 10) {
                self::jsonResponse(['ok' => false, 'error' => 'New password must be at least 10 characters.'], 422);
                return;
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $pdo->prepare('UPDATE admin_users SET password_hash = :h, updated_at = :u WHERE id = :id')
                ->execute([':h' => AdminAuth::hashPassword($next), ':u' => $now, ':id' => (int)$user['id']]);
            AdminAuth::revokeUserSessions($pdo, (int)$user['id'], (int)$session['id']);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'profile.password_changed', 'admin_user', (string)$user['id'], [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'profile.totp-begin' && $method === 'POST') {
            $secret = AdminAuth::generateTotpSecret(20);
            $enc = AdminDb::encryptValue($secret, $rootDir);
            if ($enc === '') {
                self::jsonResponse(['ok' => false, 'error' => 'OpenSSL encryption is required on PHP host to store 2FA secrets.'], 500);
                return;
            }
            $pdo->prepare('UPDATE admin_users SET totp_pending_enc = :e, updated_at = :u WHERE id = :id')
                ->execute([':e' => $enc, ':u' => gmdate('Y-m-d\TH:i:s\Z'), ':id' => (int)$user['id']]);
            $issuer = (string)($config['admin']['branding']['title'] ?? $config['brand']['name'] ?? 'Lumora Console');
            self::jsonResponse([
                'ok'          => true,
                'secret'      => $secret,
                'otpauth_uri' => AdminAuth::otpAuthUri($secret, (string)$user['email'], $issuer),
            ]);
            return;
        }

        if ($action === 'profile.totp-confirm' && $method === 'POST') {
            $pendingEnc = (string)($user['totp_pending_enc'] ?? '');
            $secret = $pendingEnc !== '' ? AdminDb::decryptValue($pendingEnc, $rootDir) : null;
            if (!is_string($secret) || $secret === '') {
                self::jsonResponse(['ok' => false, 'error' => 'No pending 2FA enrollment found. Start enrollment again.'], 400);
                return;
            }
            $check = AdminAuth::verifyTotpCode($secret, (string)($body['code'] ?? ''), 0);
            if (!$check['ok']) {
                self::jsonResponse(['ok' => false, 'error' => 'Invalid 6-digit verification code. Confirm your clock and retry.'], 422);
                return;
            }
            $rec = AdminAuth::generateRecoveryCodes(8);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $pdo->prepare(
                'UPDATE admin_users
                 SET totp_secret_enc = :se, totp_pending_enc = "", totp_enabled = 1, totp_last_step = :st, recovery_codes_json = :rc, updated_at = :u
                 WHERE id = :id'
            )->execute([
                ':se' => AdminDb::encryptValue($secret, $rootDir),
                ':st' => $check['step'],
                ':rc' => json_encode($rec['hashes']) ?: '[]',
                ':u'  => $now,
                ':id' => (int)$user['id'],
            ]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'profile.2fa_enabled', 'admin_user', (string)$user['id'], [], $rootDir);
            self::jsonResponse([
                'ok'             => true,
                'recovery_codes' => $rec['plain'],
            ]);
            return;
        }

        if ($action === 'profile.totp-disable' && $method === 'POST') {
            if (!empty($config['admin']['require_2fa'])) {
                self::jsonResponse(['ok' => false, 'error' => 'Policy requires 2FA on all accounts for this website.'], 403);
                return;
            }
            if (!AdminAuth::verifyPassword((string)($body['password'] ?? ''), (string)$user['password_hash'])) {
                self::jsonResponse(['ok' => false, 'error' => 'Incorrect password.'], 401);
                return;
            }
            $check = AdminAuth::verifyUserSecondFactor($pdo, $user, (string)($body['code'] ?? ''));
            if (empty($check['ok'])) {
                self::jsonResponse(['ok' => false, 'error' => $check['error'] ?? 'Invalid authenticator or recovery code.'], 401);
                return;
            }
            $pdo->prepare(
                'UPDATE admin_users SET totp_secret_enc = "", totp_pending_enc = "", totp_enabled = 0, totp_last_step = 0, recovery_codes_json = "[]", updated_at = :u WHERE id = :id'
            )->execute([':u' => gmdate('Y-m-d\TH:i:s\Z'), ':id' => (int)$user['id']]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'profile.2fa_disabled', 'admin_user', (string)$user['id'], [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        // ---------------------------------------------------------------------
        // Overview & Discovery (Viewer+)
        // ---------------------------------------------------------------------
        if ($action === 'overview.get' && $method === 'GET') {
            $since24h = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
            $badges = self::computeBadges($pdo);
            $stats = [
                'total_inquiries'     => (int)$pdo->query('SELECT COUNT(*) FROM contact_submissions')->fetchColumn(),
                'new_inquiries'       => $badges['new_inquiries'],
                'pending_outbox'      => $badges['pending_outbox'],
                'unresolved_404s'     => $badges['unresolved_404s'],
                'blog_posts'          => count(BlogEngine::listPosts($rootDir, true)),
                'docs_articles'       => self::countDocsArticles($rootDir),
                'media_count'         => (int)$pdo->query('SELECT COUNT(*) FROM cms_media')->fetchColumn(),
                'active_redirects'    => (int)$pdo->query('SELECT COUNT(*) FROM cms_redirects WHERE is_active = 1')->fetchColumn(),
                'failed_auth_24h'     => (int)$pdo->query("SELECT COUNT(*) FROM contact_rate_limits WHERE event_type = 'admin_login_failed' AND created_at >= " . $pdo->quote($since24h))->fetchColumn(),
            ];
            $auditRows = $pdo->query('SELECT id, actor_email, action, target_type, target_id, created_at FROM admin_audit_log ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
            self::jsonResponse([
                'ok'           => true,
                'stats'        => $stats,
                'badges'       => $badges,
                'audit'        => $auditRows,
                'environment'  => [
                    'php_version' => PHP_VERSION,
                    'is_localhost'=> Security::isLocalhost(),
                    'sqlite_wal'  => extension_loaded('pdo_sqlite'),
                    'openssl'     => extension_loaded('openssl'),
                    'argon2id'    => defined('PASSWORD_ARGON2ID'),
                    'admin_path'  => $adminPath,
                ],
                'features'     => is_array($config['features'] ?? null) ? $config['features'] : [],
            ]);
            return;
        }

        if ($action === 'discovery.preview' && $method === 'GET') {
            $lockData = Render::lockData($rootDir);
            $releasesManifest = [];
            if (!empty($config['features']['release_server'])) {
                ob_start();
                ReleaseServer::tryServe('/releases/release-manifest.json', $config, $rootDir);
                $releasesManifest = json_decode((string)ob_get_clean(), true) ?: [];
            }
            self::jsonResponse([
                'ok'             => true,
                'robots_txt'     => Discovery::renderRobotsTxt($config, $rootDir),
                'sitemap_xml'    => Discovery::renderSitemapXml($config, $rootDir),
                'llms_txt'       => Discovery::resolveLlmsContent($config, $rootDir, false),
                'llms_full_txt'  => Discovery::resolveLlmsContent($config, $rootDir, true),
                'security_txt'   => Discovery::renderSecurityTxt($config, $rootDir),
                'manifest_json'  => Discovery::renderWebManifest($config, $basePath),
                'lock_version'   => (string)($lockData['version'] ?? ''),
                'releases_count' => count($releasesManifest['artifacts'] ?? []),
            ]);
            return;
        }

        if ($action === 'discovery.settings-get' && $method === 'GET') {
            $routeCfg = Router::loadRouteConfig($rootDir);
            $routeSeo = $pdo->query('SELECT * FROM cms_route_seo ORDER BY route_path ASC')->fetchAll(PDO::FETCH_ASSOC);
            self::jsonResponse([
                'ok'       => true,
                'settings' => [
                    'llms_txt'      => AdminDb::getSetting('discovery.llms_txt', $rootDir, false) ?? '',
                    'llms_full_txt' => AdminDb::getSetting('discovery.llms_full_txt', $rootDir, false) ?? '',
                    'security_txt'  => AdminDb::getSetting('discovery.security_txt', $rootDir, false) ?? '',
                    'robots_extra'  => AdminDb::getSetting('discovery.robots_extra', $rootDir, false) ?? '',
                ],
                'route_seo'       => $routeSeo,
                'declared_routes' => array_keys($routeCfg['routes'] ?? []),
            ]);
            return;
        }

        if ($action === 'discovery.settings-save' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $uid = (int)$user['id'];
            $keys = [
                'llms_txt'      => 'discovery.llms_txt',
                'llms_full_txt' => 'discovery.llms_full_txt',
                'security_txt'  => 'discovery.security_txt',
                'robots_extra'  => 'discovery.robots_extra',
            ];
            foreach ($keys as $field => $settingKey) {
                $val = trim((string)($body[$field] ?? ''));
                if ($val === '') {
                    AdminDb::deleteSetting($settingKey, $rootDir);
                } else {
                    $clean = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $val);
                    AdminDb::setSetting($settingKey, substr($clean, 0, 64000), false, $uid, $rootDir);
                }
            }
            AdminDb::logAudit($uid, (string)$user['email'], 'discovery.settings_save', 'admin_settings', 'discovery', [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'discovery.route-seo-save' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $routePath = trim((string)($body['route_path'] ?? ''));
            if ($routePath === '' || !str_starts_with($routePath, '/') || str_contains($routePath, '..') || str_starts_with($routePath, rtrim($adminPath, '/'))) {
                self::jsonResponse(['ok' => false, 'error' => 'Route path must start with "/" and cannot target protected admin routes.'], 422);
                return;
            }
            $metaTitle = AdminSanitizer::sanitizePlainText((string)($body['meta_title'] ?? ''), 180);
            $metaDesc = AdminSanitizer::sanitizePlainText((string)($body['meta_description'] ?? ''), 320);
            $ogImage = trim((string)($body['og_image'] ?? ''));
            if ($ogImage !== '' && !AdminSanitizer::isSafeUrl($ogImage, false)) {
                $ogImage = '';
            }
            $robots = AdminSanitizer::sanitizePlainText((string)($body['robots'] ?? 'index, follow'), 80) ?: 'index, follow';
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $pdo->prepare(
                'INSERT INTO cms_route_seo (route_path, meta_title, meta_description, og_image, robots, updated_at)
                 VALUES (:rp, :mt, :md, :oi, :ro, :up)
                 ON CONFLICT(route_path) DO UPDATE SET
                    meta_title = excluded.meta_title, meta_description = excluded.meta_description,
                    og_image = excluded.og_image, robots = excluded.robots, updated_at = excluded.updated_at'
            )->execute([
                ':rp' => $routePath,
                ':mt' => $metaTitle,
                ':md' => $metaDesc,
                ':oi' => $ogImage,
                ':ro' => $robots,
                ':up' => $now,
            ]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'discovery.route_seo_save', 'route_seo', $routePath, [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'discovery.route-seo-delete' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $routePath = trim((string)($body['route_path'] ?? ''));
            $pdo->prepare('DELETE FROM cms_route_seo WHERE route_path = :rp')->execute([':rp' => $routePath]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'discovery.route_seo_delete', 'route_seo', $routePath, [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        // ---------------------------------------------------------------------
        // Inquiries Inbox & Dispatch Outbox
        // ---------------------------------------------------------------------
        if ($action === 'inquiries.list' && $method === 'GET') {
            $filters = self::buildInquiryQueryFilters($_GET);
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));

            $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM contact_submissions' . $filters['where']);
            $cntStmt->execute($filters['params']);
            $total = (int)$cntStmt->fetchColumn();

            $sql = 'SELECT id, receipt_id, channel, channel_label, sla_window, full_name, organization, email, phone, subject, consultation_mode, facility_location, email_status, whatsapp_status, sync_status, hub_receipt, triage_status, created_at, updated_at FROM contact_submissions' . $filters['where'] . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($filters['params']);

            self::jsonResponse([
                'ok'       => true,
                'total'    => $total,
                'items'    => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'channels' => array_keys(InquiryEngine::loadChannelConfig($rootDir)['channels'] ?? []),
            ]);
            return;
        }

        if ($action === 'inquiries.get' && $method === 'GET') {
            $receiptId = trim((string)($_GET['receipt_id'] ?? ''));
            $stmt = $pdo->prepare('SELECT * FROM contact_submissions WHERE receipt_id = :r LIMIT 1');
            $stmt->execute([':r' => $receiptId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                self::jsonResponse(['ok' => false, 'error' => 'Inquiry not found.'], 404);
                return;
            }
            $row['details'] = json_decode((string)($row['details_json'] ?? '{}'), true) ?: [];
            self::jsonResponse(['ok' => true, 'item' => $row]);
            return;
        }

        if ($action === 'inquiries.export' && $method === 'GET') {
            if (!$require('editor')) {
                return;
            }
            $filters = self::buildInquiryQueryFilters($_GET);
            $format = strtolower((string)($_GET['format'] ?? 'json'));
            $sql = 'SELECT receipt_id, channel, channel_label, triage_status, full_name, organization, email, phone, subject, message, consultation_mode, facility_location, email_status, whatsapp_status, sync_status, created_at FROM contact_submissions' . $filters['where'] . ' ORDER BY created_at DESC LIMIT 2000';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($filters['params']);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'inquiries.export', 'inquiry', $format, ['count' => count($rows)], $rootDir);

            if ($format === 'csv') {
                if (!headers_sent()) {
                    header('Content-Type: text/csv; charset=UTF-8');
                    header('Content-Disposition: attachment; filename="lumora-inquiries-' . gmdate('Ymd-His') . '.csv"');
                }
                $out = fopen('php://output', 'wb');
                if ($out !== false) {
                    $headers = ['receipt_id', 'channel', 'channel_label', 'triage_status', 'full_name', 'organization', 'email', 'phone', 'subject', 'message', 'consultation_mode', 'facility_location', 'email_status', 'whatsapp_status', 'sync_status', 'created_at'];
                    fputcsv($out, $headers);
                    foreach ($rows as $r) {
                        $line = [];
                        foreach ($headers as $h) {
                            $line[] = self::escapeCsvCell((string)($r[$h] ?? ''));
                        }
                        fputcsv($out, $line);
                    }
                    fclose($out);
                }
                return;
            }

            if (!headers_sent()) {
                header('Content-Disposition: attachment; filename="lumora-inquiries-' . gmdate('Ymd-His') . '.json"');
            }
            self::jsonResponse(['ok' => true, 'exported_at' => gmdate('Y-m-d\TH:i:s\Z'), 'items' => $rows]);
            return;
        }

        if ($action === 'inquiries.update' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $receiptId = trim((string)($body['receipt_id'] ?? ''));
            $status = strtolower(trim((string)($body['triage_status'] ?? 'new')));
            if (!in_array($status, ['new', 'in_review', 'responded', 'archived', 'spam'], true)) {
                self::jsonResponse(['ok' => false, 'error' => 'Invalid triage status.'], 422);
                return;
            }
            $notes = AdminSanitizer::sanitizePlainText((string)($body['internal_notes'] ?? ''), 4000);
            $upd = $pdo->prepare('UPDATE contact_submissions SET triage_status = :ts, internal_notes = :n, updated_at = :u WHERE receipt_id = :r');
            $upd->execute([':ts' => $status, ':n' => $notes, ':u' => gmdate('Y-m-d\TH:i:s\Z'), ':r' => $receiptId]);
            if ($upd->rowCount() === 0) {
                self::jsonResponse(['ok' => false, 'error' => 'Inquiry receipt not found.'], 404);
                return;
            }
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'inquiries.update', 'inquiry', $receiptId, ['triage_status' => $status], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'inquiries.redact' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $receiptId = trim((string)($body['receipt_id'] ?? ''));
            $upd = $pdo->prepare(
                'UPDATE contact_submissions
                 SET full_name = "[REDACTED]", organization = "", email = "redacted@redacted.invalid",
                     phone = "", subject = "[REDACTED]", message = "[REDACTED BY OPERATOR]",
                     facility_location = "", details_json = "{}", triage_status = "archived", updated_at = :u
                 WHERE receipt_id = :r'
            );
            $upd->execute([':u' => gmdate('Y-m-d\TH:i:s\Z'), ':r' => $receiptId]);
            if ($upd->rowCount() === 0) {
                self::jsonResponse(['ok' => false, 'error' => 'Inquiry receipt not found.'], 404);
                return;
            }
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'inquiries.redact_pii', 'inquiry', $receiptId, [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'inquiries.retry-dispatch' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $receiptId = trim((string)($body['receipt_id'] ?? ''));
            $includeEdge = !empty($body['include_edge']);
            $res = HubClient::retryInquiryByReceipt($config, $receiptId, $rootDir, $includeEdge);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'inquiries.retry_dispatch', 'inquiry', $receiptId, ['synced' => $res['synced'] ?? false], $rootDir);
            self::jsonResponse($res, !empty($res['error']) && $res['error'] === 'Inquiry receipt not found' ? 404 : 200);
            return;
        }

        if ($action === 'inquiries.bulk' && $method === 'POST') {
            $op = strtolower(trim((string)($body['operation'] ?? '')));
            if (!in_array($op, ['status', 'retry_dispatch', 'redact'], true)) {
                self::jsonResponse(['ok' => false, 'error' => 'Unsupported bulk inquiry operation.'], 422);
                return;
            }
            if (!$require($op === 'redact' ? 'owner' : 'editor')) {
                return;
            }
            $rawIds = is_array($body['receipt_ids'] ?? null) ? $body['receipt_ids'] : [];
            $receiptIds = array_values(array_unique(array_filter(array_map(
                static fn ($v): string => trim((string)$v),
                $rawIds
            ), static fn (string $v): bool => $v !== '')));
            $receiptIds = array_slice($receiptIds, 0, 100);
            if (empty($receiptIds)) {
                self::jsonResponse(['ok' => false, 'error' => 'Select at least one inquiry receipt.'], 422);
                return;
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $affected = 0;
            $syncedCount = 0;

            if ($op === 'status') {
                $status = strtolower(trim((string)($body['triage_status'] ?? 'new')));
                if (!in_array($status, ['new', 'in_review', 'responded', 'archived', 'spam'], true)) {
                    self::jsonResponse(['ok' => false, 'error' => 'Invalid triage status.'], 422);
                    return;
                }
                $upd = $pdo->prepare('UPDATE contact_submissions SET triage_status = :ts, updated_at = :u WHERE receipt_id = :r');
                foreach ($receiptIds as $rid) {
                    $upd->execute([':ts' => $status, ':u' => $now, ':r' => $rid]);
                    $affected += $upd->rowCount();
                }
            } elseif ($op === 'redact') {
                $upd = $pdo->prepare(
                    'UPDATE contact_submissions
                     SET full_name = "[REDACTED]", organization = "", email = "redacted@redacted.invalid",
                         phone = "", subject = "[REDACTED]", message = "[REDACTED BY OPERATOR]",
                         facility_location = "", details_json = "{}", triage_status = "archived", updated_at = :u
                     WHERE receipt_id = :r'
                );
                foreach ($receiptIds as $rid) {
                    $upd->execute([':u' => $now, ':r' => $rid]);
                    $affected += $upd->rowCount();
                }
            } else {
                $includeEdge = !empty($body['include_edge']);
                foreach (array_slice($receiptIds, 0, 25) as $rid) {
                    $res = HubClient::retryInquiryByReceipt($config, $rid, $rootDir, $includeEdge);
                    if (empty($res['error'])) {
                        $affected++;
                    }
                    if (!empty($res['synced'])) {
                        $syncedCount++;
                    }
                }
            }

            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'inquiries.bulk', 'inquiry', $op, [
                'requested' => count($receiptIds),
                'affected'  => $affected,
            ], $rootDir);
            self::jsonResponse([
                'ok'        => true,
                'operation' => $op,
                'affected'  => $affected,
                'synced'    => $syncedCount,
            ]);
            return;
        }

        // ---------------------------------------------------------------------
        // Blog Manager (Hybrid File + SQLite)
        // ---------------------------------------------------------------------
        if ($action === 'blog.list' && $method === 'GET') {
            self::jsonResponse([
                'ok'    => true,
                'posts' => BlogEngine::listPosts($rootDir, true),
            ]);
            return;
        }

        if ($action === 'blog.preview' && $method === 'POST') {
            $rawMd = (string)($body['content_markdown'] ?? '');
            $rawHtml = (string)($body['content_html'] ?? '');
            if (trim($rawMd) !== '' && strtolower(trim((string)($body['editor_mode'] ?? ''))) === 'markdown') {
                $rendered = DocsEngine::renderMarkdownText($rawMd, $rootDir);
                $html = AdminSanitizer::sanitizeHtml((string)($rendered['html'] ?? ''), $rootDir);
            } else {
                $html = AdminSanitizer::sanitizeHtml($rawHtml !== '' ? $rawHtml : (trim($rawMd) !== '' ? (string)(DocsEngine::renderMarkdownText($rawMd, $rootDir)['html'] ?? '') : ''), $rootDir);
            }
            self::jsonResponse(['ok' => true, 'html' => $html]);
            return;
        }

        if ($action === 'blog.save' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $slug = strtolower(trim((string)($body['slug'] ?? '')));
            if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1 || $slug === 'index') {
                self::jsonResponse(['ok' => false, 'error' => 'Blog slug must use lowercase letters, numbers, and hyphens only.'], 422);
                return;
            }
            $title = AdminSanitizer::sanitizePlainText((string)($body['title'] ?? ''), 200);
            if ($title === '') {
                self::jsonResponse(['ok' => false, 'error' => 'Post title is required.'], 422);
                return;
            }
            $summary = AdminSanitizer::sanitizePlainText((string)($body['summary'] ?? ''), 500);
            $rawMd = (string)($body['content_markdown'] ?? '');
            $rawHtml = (string)($body['content_html'] ?? '');
            if (trim($rawMd) !== '' && (strtolower(trim((string)($body['editor_mode'] ?? ''))) === 'markdown' || trim($rawHtml) === '')) {
                $rawHtml = (string)(DocsEngine::renderMarkdownText($rawMd, $rootDir)['html'] ?? '');
            }
            $contentHtml = AdminSanitizer::sanitizeHtml($rawHtml, $rootDir);
            $author = AdminSanitizer::sanitizePlainText((string)($body['author'] ?? $user['name']), 120);
            $category = AdminSanitizer::sanitizePlainText((string)($body['category'] ?? 'General'), 80) ?: 'General';
            $rawStatus = strtolower(trim((string)($body['status'] ?? 'published')));
            $status = in_array($rawStatus, ['published', 'draft', 'scheduled'], true) ? $rawStatus : 'published';
            $date = trim((string)($body['date'] ?? gmdate('Y-m-d')));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                $date = gmdate('Y-m-d');
            }
            $image = trim((string)($body['image'] ?? ''));
            if ($image !== '' && !AdminSanitizer::isSafeUrl($image, false)) {
                $image = '';
            }
            $metaTitle = AdminSanitizer::sanitizePlainText((string)($body['meta_title'] ?? ''), 180);
            $metaDescription = AdminSanitizer::sanitizePlainText((string)($body['meta_description'] ?? ''), 320);
            $ogImage = trim((string)($body['og_image'] ?? ''));
            if ($ogImage !== '' && !AdminSanitizer::isSafeUrl($ogImage, false)) {
                $ogImage = '';
            }
            $canonicalUrl = trim((string)($body['canonical_url'] ?? ''));
            if ($canonicalUrl !== '' && !AdminSanitizer::isSafeUrl($canonicalUrl, false)) {
                $canonicalUrl = '';
            }
            $rawTags = is_array($body['tags'] ?? null) ? $body['tags'] : explode(',', (string)($body['tags'] ?? ''));
            $tags = array_values(array_filter(array_map(static fn ($t): string => AdminSanitizer::sanitizePlainText((string)$t, 48), $rawTags), static fn (string $t): bool => $t !== ''));
            $now = gmdate('Y-m-d\TH:i:s\Z');

            $stmt = $pdo->prepare(
                'INSERT INTO cms_blog_posts (slug, title, summary, content_html, author, category, tags_json, date, image, status, meta_title, meta_description, og_image, canonical_url, source_origin, created_at, updated_at)
                 VALUES (:sl, :ti, :su, :ch, :au, :ca, :tj, :da, :im, :st, :mt, :md, :og, :cu, "sqlite", :cr, :up)
                 ON CONFLICT(slug) DO UPDATE SET
                    title = excluded.title, summary = excluded.summary, content_html = excluded.content_html,
                    author = excluded.author, category = excluded.category, tags_json = excluded.tags_json,
                    date = excluded.date, image = excluded.image, status = excluded.status,
                    meta_title = excluded.meta_title, meta_description = excluded.meta_description,
                    og_image = excluded.og_image, canonical_url = excluded.canonical_url, updated_at = excluded.updated_at'
            );
            $stmt->execute([
                ':sl' => $slug,
                ':ti' => $title,
                ':su' => $summary,
                ':ch' => $contentHtml,
                ':au' => $author,
                ':ca' => $category,
                ':tj' => json_encode($tags) ?: '[]',
                ':da' => $date,
                ':im' => $image,
                ':st' => $status,
                ':mt' => $metaTitle,
                ':md' => $metaDescription,
                ':og' => $ogImage,
                ':cu' => $canonicalUrl,
                ':cr' => $now,
                ':up' => $now,
            ]);
            AdminDb::recordRevision($pdo, 'blog', $slug, $title, [
                'slug'             => $slug,
                'title'            => $title,
                'summary'          => $summary,
                'content_html'     => $contentHtml,
                'author'           => $author,
                'category'         => $category,
                'tags'             => $tags,
                'date'             => $date,
                'image'            => $image,
                'status'           => $status,
                'meta_title'       => $metaTitle,
                'meta_description' => $metaDescription,
                'og_image'         => $ogImage,
                'canonical_url'    => $canonicalUrl,
            ], (string)$user['email']);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'blog.save', 'blog_post', $slug, ['status' => $status], $rootDir);
            self::jsonResponse(['ok' => true, 'post' => BlogEngine::getPost($slug, $rootDir, true)]);
            return;
        }

        if ($action === 'blog.delete' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $slug = strtolower(trim((string)($body['slug'] ?? '')));
            $pdo->prepare('DELETE FROM cms_blog_posts WHERE slug = :s')->execute([':s' => $slug]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'blog.delete', 'blog_post', $slug, [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        // ---------------------------------------------------------------------
        // Docs Manager (Hybrid File + SQLite)
        // ---------------------------------------------------------------------
        if ($action === 'docs.list' && $method === 'GET') {
            $docs = new DocsEngine($rootDir . '/content/docs');
            $manifest = $docs->getManifest(true);
            $tracks = [];
            foreach (($manifest['tracks'] ?? []) as $tid => $tr) {
                $tracks[] = [
                    'track_id'    => (string)$tid,
                    'title'       => (string)($tr['title'] ?? $tid),
                    'description' => (string)($tr['description'] ?? ''),
                    'articles'    => array_values(is_array($tr['articles'] ?? null) ? $tr['articles'] : []),
                ];
            }
            self::jsonResponse(['ok' => true, 'tracks' => $tracks]);
            return;
        }

        if ($action === 'docs.track-save' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $tid = strtolower(trim((string)($body['track_id'] ?? '')));
            if (preg_match('/^[a-z0-9-]+$/', $tid) !== 1) {
                self::jsonResponse(['ok' => false, 'error' => 'Track ID must use lowercase letters, numbers, and hyphens only.'], 422);
                return;
            }
            $title = AdminSanitizer::sanitizePlainText((string)($body['title'] ?? ''), 140);
            if ($title === '') {
                self::jsonResponse(['ok' => false, 'error' => 'Track title is required.'], 422);
                return;
            }
            $desc = AdminSanitizer::sanitizePlainText((string)($body['description'] ?? ''), 300);
            $order = (int)($body['sort_order'] ?? 100);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $pdo->prepare(
                'INSERT INTO cms_docs_tracks (track_id, title, description, sort_order, created_at, updated_at)
                 VALUES (:id, :ti, :de, :so, :cr, :up)
                 ON CONFLICT(track_id) DO UPDATE SET title = excluded.title, description = excluded.description, sort_order = excluded.sort_order, updated_at = excluded.updated_at'
            )->execute([':id' => $tid, ':ti' => $title, ':de' => $desc, ':so' => $order, ':cr' => $now, ':up' => $now]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'docs.track_save', 'docs_track', $tid, [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'docs.track-delete' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $tid = strtolower(trim((string)($body['track_id'] ?? '')));
            $pdo->prepare('DELETE FROM cms_docs_articles WHERE track_id = :t')->execute([':t' => $tid]);
            $pdo->prepare('DELETE FROM cms_docs_tracks WHERE track_id = :t')->execute([':t' => $tid]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'docs.track_delete', 'docs_track', $tid, [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'docs.preview' && $method === 'POST') {
            $md = (string)($body['markdown'] ?? '');
            self::jsonResponse(array_merge(['ok' => true], DocsEngine::renderMarkdownText($md, $rootDir)));
            return;
        }

        if ($action === 'docs.save' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $tid = strtolower(trim((string)($body['track_id'] ?? 'operator')));
            $slug = strtolower(trim((string)($body['slug'] ?? '')));
            if (preg_match('/^[a-z0-9-]+$/', $tid) !== 1 || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
                self::jsonResponse(['ok' => false, 'error' => 'Track ID and article slug must use lowercase alphanumeric characters and hyphens.'], 422);
                return;
            }
            if (stripos($slug, 'internal') !== false) {
                self::jsonResponse(['ok' => false, 'error' => 'Slug cannot contain reserved keyword "internal".'], 422);
                return;
            }
            $title = AdminSanitizer::sanitizePlainText((string)($body['title'] ?? ''), 200);
            if ($title === '') {
                self::jsonResponse(['ok' => false, 'error' => 'Article title is required.'], 422);
                return;
            }
            $summary = AdminSanitizer::sanitizePlainText((string)($body['summary'] ?? ''), 400);
            $markdown = (string)($body['markdown'] ?? '');
            $status = strtolower(trim((string)($body['status'] ?? 'published'))) === 'draft' ? 'draft' : 'published';
            $order = (int)($body['sort_order'] ?? 100);
            $rawTags = is_array($body['tags'] ?? null) ? $body['tags'] : explode(',', (string)($body['tags'] ?? ''));
            $tags = array_values(array_filter(array_map(static fn ($t): string => AdminSanitizer::sanitizePlainText((string)$t, 48), $rawTags), static fn (string $t): bool => $t !== ''));
            $now = gmdate('Y-m-d\TH:i:s\Z');

            $pdo->prepare(
                'INSERT INTO cms_docs_articles (track_id, slug, title, summary, markdown, tags_json, status, sort_order, created_at, updated_at)
                 VALUES (:ti, :sl, :tl, :su, :md, :tj, :st, :so, :cr, :up)
                 ON CONFLICT(track_id, slug) DO UPDATE SET
                    title = excluded.title, summary = excluded.summary, markdown = excluded.markdown,
                    tags_json = excluded.tags_json, status = excluded.status, sort_order = excluded.sort_order, updated_at = excluded.updated_at'
            )->execute([
                ':ti' => $tid,
                ':sl' => $slug,
                ':tl' => $title,
                ':su' => $summary,
                ':md' => $markdown,
                ':tj' => json_encode($tags) ?: '[]',
                ':st' => $status,
                ':so' => $order,
                ':cr' => $now,
                ':up' => $now,
            ]);
            AdminDb::recordRevision($pdo, 'docs', "{$tid}/{$slug}", $title, [
                'track_id'   => $tid,
                'slug'       => $slug,
                'title'      => $title,
                'summary'    => $summary,
                'markdown'   => $markdown,
                'tags'       => $tags,
                'status'     => $status,
                'sort_order' => $order,
            ], (string)$user['email']);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'docs.save', 'docs_article', "{$tid}/{$slug}", ['status' => $status], $rootDir);
            self::jsonResponse(['ok' => true, 'preview' => DocsEngine::renderMarkdownText($markdown, $rootDir)]);
            return;
        }

        if ($action === 'docs.delete' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $tid = strtolower(trim((string)($body['track_id'] ?? '')));
            $slug = strtolower(trim((string)($body['slug'] ?? '')));
            $pdo->prepare('DELETE FROM cms_docs_articles WHERE track_id = :t AND slug = :s')->execute([':t' => $tid, ':s' => $slug]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'docs.delete', 'docs_article', "{$tid}/{$slug}", [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        // ---------------------------------------------------------------------
        // Routing & Redirects
        // ---------------------------------------------------------------------
        if ($action === 'routing.list' && $method === 'GET') {
            $routeCfg = Router::loadRouteConfig($rootDir);
            $sqliteRedirects = $pdo->query('SELECT * FROM cms_redirects ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
            $notFoundLog = $pdo->query('SELECT * FROM cms_404_log ORDER BY hits DESC, last_seen_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
            self::jsonResponse([
                'ok'               => true,
                'static_routes'    => $routeCfg['routes'],
                'file_redirects'   => $routeCfg['redirects'],
                'sqlite_redirects' => $sqliteRedirects,
                'not_found_log'    => $notFoundLog,
            ]);
            return;
        }

        if ($action === 'routing.save' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $src = trim((string)($body['source_path'] ?? ''));
            if ($src === '' || !str_starts_with($src, '/') || str_contains($src, '..') || str_starts_with($src, rtrim($adminPath, '/'))) {
                self::jsonResponse(['ok' => false, 'error' => 'Source path must start with "/" and cannot target protected admin routes.'], 422);
                return;
            }
            $target = trim((string)($body['target_url'] ?? ''));
            if (!AdminSanitizer::isSafeUrl($target, false)) {
                self::jsonResponse(['ok' => false, 'error' => 'Target URL must be a valid root-relative path or http(s) URL.'], 422);
                return;
            }
            $code = (int)($body['status_code'] ?? 301) === 302 ? 302 : 301;
            $active = isset($body['is_active']) ? (!empty($body['is_active']) ? 1 : 0) : 1;
            $now = gmdate('Y-m-d\TH:i:s\Z');

            $pdo->prepare(
                'INSERT INTO cms_redirects (source_path, target_url, status_code, is_active, created_at, updated_at)
                 VALUES (:sp, :tu, :sc, :ia, :cr, :up)
                 ON CONFLICT(source_path) DO UPDATE SET target_url = excluded.target_url, status_code = excluded.status_code, is_active = excluded.is_active, updated_at = excluded.updated_at'
            )->execute([':sp' => $src, ':tu' => $target, ':sc' => $code, ':ia' => $active, ':cr' => $now, ':up' => $now]);

            $trimmed = rtrim($src, '/') ?: '/';
            $pdo->prepare('DELETE FROM cms_404_log WHERE path IN (:p1, :p2, :p3)')->execute([
                ':p1' => $src,
                ':p2' => $trimmed,
                ':p3' => ($trimmed === '/' ? '/' : $trimmed . '/'),
            ]);

            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'routing.save', 'redirect', $src, ['target' => $target, 'status' => $code], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'routing.toggle' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $id = (int)($body['id'] ?? 0);
            $active = !empty($body['is_active']) ? 1 : 0;
            $pdo->prepare('UPDATE cms_redirects SET is_active = :ia, updated_at = :up WHERE id = :id')
                ->execute([':ia' => $active, ':up' => gmdate('Y-m-d\TH:i:s\Z'), ':id' => $id]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'routing.toggle', 'redirect', (string)$id, ['is_active' => $active], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'routing.404-clear' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $path = trim((string)($body['path'] ?? ''));
            if ($path !== '') {
                $pdo->prepare('DELETE FROM cms_404_log WHERE path = :p')->execute([':p' => $path]);
            } else {
                $pdo->exec('DELETE FROM cms_404_log');
            }
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'routing.404_clear', '404_log', $path !== '' ? $path : 'all', [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'routing.delete' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $id = (int)($body['id'] ?? 0);
            $pdo->prepare('DELETE FROM cms_redirects WHERE id = :id')->execute([':id' => $id]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'routing.delete', 'redirect', (string)$id, [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        // ---------------------------------------------------------------------
        // Raster Media Library
        // ---------------------------------------------------------------------
        if ($action === 'media.list' && $method === 'GET') {
            $rows = $pdo->query('SELECT * FROM cms_media ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
            $knownPaths = [];
            foreach ($rows as &$r) {
                $pp = (string)($r['public_path'] ?? '');
                $knownPaths[$pp] = true;
                $r['url'] = Router::url($pp, $basePath);
                $r['source_origin'] = 'upload';
            }
            unset($r);
            $staticItems = self::scanStaticMedia($rootDir, $basePath, $knownPaths);
            self::jsonResponse(['ok' => true, 'items' => array_merge($rows, $staticItems)]);
            return;
        }

        if ($action === 'media.upload' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                self::jsonResponse(['ok' => false, 'error' => 'Missing uploaded image file payload.'], 400);
                return;
            }
            $alt = (string)($_POST['alt_text'] ?? '');
            $fw = (int)($_POST['width'] ?? 0);
            $fh = (int)($_POST['height'] ?? 0);
            $stored = AdminSanitizer::processImageUpload($_FILES['file'], $rootDir, $config, $alt, $fw, $fh);
            if (empty($stored['ok'])) {
                self::jsonResponse($stored, 422);
                return;
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $stmt = $pdo->prepare(
                'INSERT INTO cms_media (filename, public_path, mime_type, size_bytes, width, height, alt_text, uploaded_by, created_at)
                 VALUES (:fn, :pp, :mt, :sb, :w, :h, :at, :ub, :ca)'
            );
            $stmt->execute([
                ':fn' => $stored['filename'],
                ':pp' => $stored['public_path'],
                ':mt' => $stored['mime_type'],
                ':sb' => $stored['size_bytes'],
                ':w'  => $stored['width'],
                ':h'  => $stored['height'],
                ':at' => $stored['alt_text'],
                ':ub' => (int)$user['id'],
                ':ca' => $now,
            ]);
            $id = (int)$pdo->lastInsertId();
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'media.upload', 'media', (string)$id, ['path' => $stored['public_path']], $rootDir);
            self::jsonResponse([
                'ok'   => true,
                'item' => array_merge($stored, [
                    'id'         => $id,
                    'url'        => Router::url((string)$stored['public_path'], $basePath),
                    'created_at' => $now,
                ]),
            ]);
            return;
        }

        if ($action === 'media.update' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $id = (int)($body['id'] ?? 0);
            $alt = AdminSanitizer::sanitizePlainText((string)($body['alt_text'] ?? ''), 240);
            $pdo->prepare('UPDATE cms_media SET alt_text = :a WHERE id = :id')->execute([':a' => $alt, ':id' => $id]);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'media.delete' && $method === 'POST') {
            if (!$require('editor')) {
                return;
            }
            $id = (int)($body['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT public_path FROM cms_media WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $relPath = (string)($row['public_path'] ?? '');
                $uploadsRoot = realpath($rootDir . '/public/uploads');
                $targetFile = realpath($rootDir . $relPath);
                if ($uploadsRoot !== false && $targetFile !== false && str_starts_with($targetFile, $uploadsRoot . DIRECTORY_SEPARATOR) && is_file($targetFile)) {
                    @unlink($targetFile);
                }
                $pdo->prepare('DELETE FROM cms_media WHERE id = :id')->execute([':id' => $id]);
                AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'media.delete', 'media', (string)$id, ['path' => $relPath], $rootDir);
            }
            self::jsonResponse(['ok' => true]);
            return;
        }

        // ---------------------------------------------------------------------
        // Legal Overrides (Viewer Read / Owner Write)
        // ---------------------------------------------------------------------
        if ($action === 'legal.list' && $method === 'GET') {
            $slugs = array_values(array_unique(array_merge(LegalRegistry::builtInTemplateSlugs(), LegalRegistry::activeSlugs($config, $rootDir))));
            $docs = [];
            foreach ($slugs as $slug) {
                $doc = LegalRegistry::resolveDocument($slug, $config, $rootDir);
                if ($doc !== null) {
                    $docs[] = array_merge(['slug' => $slug], $doc);
                }
            }
            self::jsonResponse(['ok' => true, 'documents' => $docs]);
            return;
        }

        if ($action === 'legal.save' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $slug = strtolower(trim((string)($body['slug'] ?? '')));
            if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1 || $slug === 'index') {
                self::jsonResponse(['ok' => false, 'error' => 'Invalid legal slug.'], 422);
                return;
            }
            $title = AdminSanitizer::sanitizePlainText((string)($body['title'] ?? ''), 180);
            if ($title === '') {
                self::jsonResponse(['ok' => false, 'error' => 'Legal document title is required.'], 422);
                return;
            }
            $lead = AdminSanitizer::sanitizePlainText((string)($body['lead'] ?? ''), 400);
            $docNo = AdminSanitizer::sanitizePlainText((string)($body['doc_no'] ?? ''), 40);
            $version = AdminSanitizer::sanitizePlainText((string)($body['version'] ?? '1.0.0'), 32);
            $date = AdminSanitizer::sanitizePlainText((string)($body['date'] ?? gmdate('Y-m-d')), 32);
            $status = AdminSanitizer::sanitizePlainText((string)($body['status'] ?? 'ACTIVE POLICY'), 64);
            $active = isset($body['is_active']) ? (!empty($body['is_active']) ? 1 : 0) : 1;

            $cleanSections = [];
            foreach ((is_array($body['sections'] ?? null) ? $body['sections'] : []) as $idx => $sec) {
                if (!is_array($sec)) {
                    continue;
                }
                $secId = strtolower((string)preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($sec['id'] ?? ('sec-' . ($idx + 1)))));
                $secTitle = AdminSanitizer::sanitizePlainText((string)($sec['title'] ?? ''), 160);
                $secBody = AdminSanitizer::sanitizeHtml((string)($sec['body'] ?? ''), $rootDir);
                if ($secTitle !== '' || $secBody !== '') {
                    $cleanSections[] = [
                        'id'    => trim($secId, '-') ?: ('sec-' . ($idx + 1)),
                        'title' => $secTitle !== '' ? $secTitle : ('Section ' . ($idx + 1)),
                        'body'  => $secBody,
                    ];
                }
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $pdo->prepare(
                'INSERT INTO cms_legal_overrides (slug, title, lead, doc_no, version, date, status, sections_json, is_active, updated_at)
                 VALUES (:sl, :ti, :le, :dn, :ve, :da, :st, :sj, :ia, :up)
                 ON CONFLICT(slug) DO UPDATE SET
                    title = excluded.title, lead = excluded.lead, doc_no = excluded.doc_no,
                    version = excluded.version, date = excluded.date, status = excluded.status,
                    sections_json = excluded.sections_json, is_active = excluded.is_active, updated_at = excluded.updated_at'
            )->execute([
                ':sl' => $slug,
                ':ti' => $title,
                ':le' => $lead,
                ':dn' => $docNo,
                ':ve' => $version,
                ':da' => $date,
                ':st' => $status,
                ':sj' => json_encode($cleanSections) ?: '[]',
                ':ia' => $active,
                ':up' => $now,
            ]);
            AdminDb::recordRevision($pdo, 'legal', $slug, $title, [
                'slug'      => $slug,
                'title'     => $title,
                'lead'      => $lead,
                'doc_no'    => $docNo,
                'version'   => $version,
                'date'      => $date,
                'status'    => $status,
                'sections'  => $cleanSections,
                'is_active' => $active,
            ], (string)$user['email']);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'legal.save', 'legal_doc', $slug, [], $rootDir);
            self::jsonResponse(['ok' => true, 'document' => LegalRegistry::resolveDocument($slug, $config, $rootDir)]);
            return;
        }

        if ($action === 'legal.delete' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $slug = strtolower(trim((string)($body['slug'] ?? '')));
            $pdo->prepare('DELETE FROM cms_legal_overrides WHERE slug = :s')->execute([':s' => $slug]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'legal.delete', 'legal_doc', $slug, [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        // ---------------------------------------------------------------------
        // Revision History & Rollback (Blog, Docs, Legal)
        // ---------------------------------------------------------------------
        if ($action === 'revisions.list' && $method === 'GET') {
            $entityType = strtolower(trim((string)($_GET['entity_type'] ?? '')));
            $entityKey = trim((string)($_GET['entity_key'] ?? ''));
            if (!in_array($entityType, ['blog', 'docs', 'legal'], true) || $entityKey === '') {
                self::jsonResponse(['ok' => false, 'error' => 'Valid entity_type and entity_key are required.'], 422);
                return;
            }
            $stmt = $pdo->prepare('SELECT id, entity_type, entity_key, title, payload_json, actor_email, created_at FROM cms_revisions WHERE entity_type = :et AND entity_key = :ek ORDER BY id DESC LIMIT 15');
            $stmt->execute([':et' => $entityType, ':ek' => $entityKey]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['snapshot'] = json_decode((string)($r['payload_json'] ?? '{}'), true) ?: [];
                unset($r['payload_json']);
            }
            unset($r);
            self::jsonResponse(['ok' => true, 'revisions' => $rows]);
            return;
        }

        if ($action === 'revisions.restore' && $method === 'POST') {
            $revId = (int)($body['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM cms_revisions WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $revId]);
            $rev = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($rev)) {
                self::jsonResponse(['ok' => false, 'error' => 'Revision snapshot not found.'], 404);
                return;
            }
            $entityType = (string)($rev['entity_type'] ?? '');
            if (!$require($entityType === 'legal' ? 'owner' : 'editor')) {
                return;
            }
            $restored = self::restoreRevisionRow($pdo, $rev, (string)$user['email'], $rootDir);
            if (!$restored['ok']) {
                self::jsonResponse($restored, 422);
                return;
            }
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'revisions.restore', $entityType, (string)$rev['entity_key'], ['revision_id' => $revId], $rootDir);
            self::jsonResponse($restored);
            return;
        }

        // ---------------------------------------------------------------------
        // Channels Manager (Viewer Read / Owner Write)
        // ---------------------------------------------------------------------
        if ($action === 'channels.list' && $method === 'GET') {
            $merged = InquiryEngine::loadChannelConfig($rootDir);
            $overrides = $pdo->query('SELECT * FROM cms_channels ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
            self::jsonResponse([
                'ok'              => true,
                'default_channel' => $merged['default_channel'] ?? 'general',
                'channels'        => $merged['channels'] ?? [],
                'sqlite_overrides'=> $overrides,
            ]);
            return;
        }

        if ($action === 'channels.save' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $key = strtolower(trim((string)($body['channel_key'] ?? '')));
            if (preg_match('/^[a-z0-9_-]+$/', $key) !== 1) {
                self::jsonResponse(['ok' => false, 'error' => 'Channel key must contain lowercase letters, numbers, underscores, or hyphens.'], 422);
                return;
            }
            $label = AdminSanitizer::sanitizePlainText((string)($body['label'] ?? ''), 120);
            if ($label === '') {
                self::jsonResponse(['ok' => false, 'error' => 'Channel label is required.'], 422);
                return;
            }
            $desc = AdminSanitizer::sanitizePlainText((string)($body['description'] ?? ''), 240);
            $sla = AdminSanitizer::sanitizePlainText((string)($body['sla_window'] ?? '1-2 Business Days'), 80) ?: '1-2 Business Days';
            $configJson = self::sanitizeChannelConfig($body, $key);
            $active = isset($body['is_active']) ? (!empty($body['is_active']) ? 1 : 0) : 1;
            $order = (int)($body['sort_order'] ?? 100);
            $now = gmdate('Y-m-d\TH:i:s\Z');

            $pdo->prepare(
                'INSERT INTO cms_channels (channel_key, label, description, sla_window, config_json, is_active, sort_order, updated_at)
                 VALUES (:ck, :la, :de, :sl, :cj, :ia, :so, :up)
                 ON CONFLICT(channel_key) DO UPDATE SET
                    label = excluded.label, description = excluded.description, sla_window = excluded.sla_window,
                    config_json = excluded.config_json, is_active = excluded.is_active, sort_order = excluded.sort_order, updated_at = excluded.updated_at'
            )->execute([':ck' => $key, ':la' => $label, ':de' => $desc, ':sl' => $sla, ':cj' => $configJson, ':ia' => $active, ':so' => $order, ':up' => $now]);

            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'channels.save', 'channel', $key, ['is_active' => $active], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'channels.delete' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $key = strtolower(trim((string)($body['channel_key'] ?? '')));
            $pdo->prepare('DELETE FROM cms_channels WHERE channel_key = :k')->execute([':k' => $key]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'channels.delete', 'channel', $key, [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        // ---------------------------------------------------------------------
        // Dispatch, Hub & Edge Notifications (Viewer Read / Owner Write)
        // ---------------------------------------------------------------------
        if ($action === 'dispatch.status' && $method === 'GET') {
            $hub = HubClient::resolveHubConfig($config, $rootDir);
            $notify = HubClient::resolveNotifyConfig($config, $rootDir);
            $hasHubSecret = HubClient::resolveHubSecret($config, $rootDir) !== '';
            $outboxRows = InquiryDb::getPendingOutbox(25, $rootDir);

            self::jsonResponse([
                'ok'  => true,
                'hub' => [
                    'enabled'         => !empty($hub['enabled']),
                    'endpoint'        => (string)($hub['endpoint'] ?? ''),
                    'timeout_ms'      => (int)($hub['timeout_ms'] ?? 1500),
                    'fallback_notify' => !empty($hub['fallback_notify']),
                    'has_secret'      => $hasHubSecret,
                ],
                'email' => [
                    'enabled'       => !empty($notify['email']['enabled']),
                    'transport'     => (string)($notify['email']['transport'] ?? 'smtp'),
                    'to'            => (string)($notify['email']['to'] ?? ''),
                    'from_email'    => (string)($notify['email']['from_email'] ?? ''),
                    'from_name'     => (string)($notify['email']['from_name'] ?? ''),
                    'smtp_host'     => (string)($notify['email']['smtp_host'] ?? ''),
                    'smtp_port'     => (int)($notify['email']['smtp_port'] ?? 587),
                    'smtp_user'     => (string)($notify['email']['smtp_user'] ?? ''),
                    'smtp_tls'      => !empty($notify['email']['smtp_tls']),
                    'has_smtp_pass' => trim((string)($notify['email']['smtp_pass'] ?? '')) !== '',
                ],
                'whatsapp' => [
                    'enabled'     => !empty($notify['whatsapp']['enabled']),
                    'provider'    => (string)($notify['whatsapp']['provider'] ?? 'waha'),
                    'phone'       => (string)($notify['whatsapp']['phone'] ?? ''),
                    'webhook_url' => (string)($notify['whatsapp']['webhook_url'] ?? ''),
                    'instance'    => (string)($notify['whatsapp']['instance'] ?? 'default'),
                    'has_api_key' => trim((string)($notify['whatsapp']['api_key'] ?? '')) !== '',
                ],
                'pending_outbox' => array_map(static fn (array $r): array => [
                    'receipt_id'      => (string)($r['receipt_id'] ?? ''),
                    'channel_label'   => (string)($r['channel_label'] ?? ''),
                    'subject'         => (string)($r['subject'] ?? ''),
                    'sync_status'     => (string)($r['sync_status'] ?? 'pending'),
                    'email_status'    => (string)($r['email_status'] ?? 'skipped'),
                    'whatsapp_status' => (string)($r['whatsapp_status'] ?? 'skipped'),
                    'created_at'      => (string)($r['created_at'] ?? ''),
                ], $outboxRows),
            ]);
            return;
        }

        if ($action === 'dispatch.save' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $uid = (int)$user['id'];

            if (isset($body['hub']) && is_array($body['hub'])) {
                $h = $body['hub'];
                $ep = trim((string)($h['endpoint'] ?? ''));
                if ($ep !== '' && !self::isValidWebhookUrl($ep)) {
                    self::jsonResponse(['ok' => false, 'error' => 'Hub endpoint must use HTTPS (or http://localhost in development).'], 422);
                    return;
                }
                AdminDb::setSetting('hub.enabled', !empty($h['enabled']) ? '1' : '0', false, $uid, $rootDir);
                AdminDb::setSetting('hub.endpoint', $ep, false, $uid, $rootDir);
                AdminDb::setSetting('hub.timeout_ms', (string)max(500, min(3000, (int)($h['timeout_ms'] ?? 1500))), false, $uid, $rootDir);
                AdminDb::setSetting('hub.fallback_notify', !empty($h['fallback_notify']) ? '1' : '0', false, $uid, $rootDir);
                if (!empty($h['clear_secret'])) {
                    AdminDb::deleteSetting('hub.shared_secret', $rootDir);
                } elseif (isset($h['shared_secret']) && trim((string)$h['shared_secret']) !== '') {
                    AdminDb::setSetting('hub.shared_secret', trim((string)$h['shared_secret']), true, $uid, $rootDir);
                }
            }

            if (isset($body['email']) && is_array($body['email'])) {
                $em = $body['email'];
                AdminDb::setSetting('notify.email.enabled', !empty($em['enabled']) ? '1' : '0', false, $uid, $rootDir);
                AdminDb::setSetting('notify.email.transport', ((string)($em['transport'] ?? 'smtp')) === 'mail' ? 'mail' : 'smtp', false, $uid, $rootDir);
                AdminDb::setSetting('notify.email.to', AdminSanitizer::sanitizePlainText((string)($em['to'] ?? ''), 180), false, $uid, $rootDir);
                AdminDb::setSetting('notify.email.from_email', AdminSanitizer::sanitizePlainText((string)($em['from_email'] ?? ''), 180), false, $uid, $rootDir);
                AdminDb::setSetting('notify.email.from_name', AdminSanitizer::sanitizePlainText((string)($em['from_name'] ?? ''), 120), false, $uid, $rootDir);
                AdminDb::setSetting('notify.email.smtp_host', AdminSanitizer::sanitizePlainText((string)($em['smtp_host'] ?? ''), 180), false, $uid, $rootDir);
                AdminDb::setSetting('notify.email.smtp_port', (string)max(1, min(65535, (int)($em['smtp_port'] ?? 587))), false, $uid, $rootDir);
                AdminDb::setSetting('notify.email.smtp_user', AdminSanitizer::sanitizePlainText((string)($em['smtp_user'] ?? ''), 180), false, $uid, $rootDir);
                AdminDb::setSetting('notify.email.smtp_tls', !empty($em['smtp_tls']) ? '1' : '0', false, $uid, $rootDir);
                if (!empty($em['clear_smtp_pass'])) {
                    AdminDb::deleteSetting('notify.email.smtp_pass', $rootDir);
                } elseif (isset($em['smtp_pass']) && trim((string)$em['smtp_pass']) !== '') {
                    AdminDb::setSetting('notify.email.smtp_pass', trim((string)$em['smtp_pass']), true, $uid, $rootDir);
                }
            }

            if (isset($body['whatsapp']) && is_array($body['whatsapp'])) {
                $wa = $body['whatsapp'];
                $prov = strtolower(trim((string)($wa['provider'] ?? 'waha')));
                if (!in_array($prov, ['waha', 'evolution', 'webhook'], true)) {
                    $prov = 'waha';
                }
                $waUrl = trim((string)($wa['webhook_url'] ?? ''));
                if ($waUrl !== '' && !self::isValidWebhookUrl($waUrl)) {
                    self::jsonResponse(['ok' => false, 'error' => 'WhatsApp webhook must use HTTPS (or http://localhost in development).'], 422);
                    return;
                }
                AdminDb::setSetting('notify.whatsapp.enabled', !empty($wa['enabled']) ? '1' : '0', false, $uid, $rootDir);
                AdminDb::setSetting('notify.whatsapp.provider', $prov, false, $uid, $rootDir);
                AdminDb::setSetting('notify.whatsapp.phone', preg_replace('/[^0-9+]/', '', (string)($wa['phone'] ?? '')) ?? '', false, $uid, $rootDir);
                AdminDb::setSetting('notify.whatsapp.webhook_url', $waUrl, false, $uid, $rootDir);
                AdminDb::setSetting('notify.whatsapp.instance', AdminSanitizer::sanitizePlainText((string)($wa['instance'] ?? 'default'), 64) ?: 'default', false, $uid, $rootDir);
                if (!empty($wa['clear_api_key'])) {
                    AdminDb::deleteSetting('notify.whatsapp.api_key', $rootDir);
                } elseif (isset($wa['api_key']) && trim((string)$wa['api_key']) !== '') {
                    AdminDb::setSetting('notify.whatsapp.api_key', trim((string)$wa['api_key']), true, $uid, $rootDir);
                }
            }

            AdminDb::logAudit($uid, (string)$user['email'], 'dispatch.save_settings', 'admin_settings', 'dispatch', [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'dispatch.flush' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $res = HubClient::flushPendingOutbox($config, $rootDir, 25);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'dispatch.flush_outbox', 'hub_outbox', 'batch', $res, $rootDir);
            self::jsonResponse(array_merge(['ok' => true], $res));
            return;
        }

        if ($action === 'dispatch.test' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $rawType = strtolower(trim((string)($body['type'] ?? 'email')));
            $type = in_array($rawType, ['email', 'whatsapp', 'hub'], true) ? $rawType : 'email';
            $recipient = trim((string)($body['recipient'] ?? ''));
            $res = HubClient::sendTestNotification($config, $type, $recipient, $rootDir);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'dispatch.test', 'notification', $type, ['status' => $res['status']], $rootDir);
            self::jsonResponse($res);
            return;
        }

        // ---------------------------------------------------------------------
        // Releases Manager (Viewer Read / Owner Write)
        // ---------------------------------------------------------------------
        if ($action === 'releases.list' && $method === 'GET') {
            ob_start();
            ReleaseServer::tryServe('/releases/release-manifest.json', $config, $rootDir);
            $manifest = json_decode((string)ob_get_clean(), true) ?: [];
            $rows = $pdo->query('SELECT * FROM cms_releases ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
            self::jsonResponse([
                'ok'              => true,
                'manifest'        => $manifest,
                'sqlite_releases' => $rows,
            ]);
            return;
        }

        if ($action === 'releases.save' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $version = AdminSanitizer::sanitizePlainText((string)($body['version'] ?? ''), 32);
            $platform = strtolower(trim((string)($body['platform'] ?? 'archive')));
            if (!in_array($platform, ['windows', 'linux', 'macos', 'android', 'archive'], true)) {
                $platform = 'archive';
            }
            $filename = basename(trim((string)($body['filename'] ?? '')));
            if ($filename === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $filename) !== 1 || str_contains($filename, '..')) {
                self::jsonResponse(['ok' => false, 'error' => 'Invalid release filename.'], 422);
                return;
            }
            $sha256 = strtolower(trim((string)($body['sha256'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                self::jsonResponse(['ok' => false, 'error' => 'SHA-256 checksum must be exactly 64 hex characters.'], 422);
                return;
            }
            $size = max(0, (int)($body['size_bytes'] ?? 0));
            $channel = strtolower(trim((string)($body['channel'] ?? 'stable')));
            if (!in_array($channel, ['stable', 'beta', 'rc'], true)) {
                $channel = 'stable';
            }
            $notes = AdminSanitizer::sanitizePlainText((string)($body['release_notes'] ?? ''), 1500);
            $active = isset($body['is_active']) ? (!empty($body['is_active']) ? 1 : 0) : 1;
            $now = gmdate('Y-m-d\TH:i:s\Z');

            $pdo->prepare(
                'INSERT INTO cms_releases (version, platform, filename, sha256, size_bytes, channel, release_notes, is_active, created_at, updated_at)
                 VALUES (:ve, :pl, :fn, :sh, :sb, :ch, :rn, :ia, :cr, :up)
                 ON CONFLICT(filename) DO UPDATE SET
                    version = excluded.version, platform = excluded.platform, sha256 = excluded.sha256,
                    size_bytes = excluded.size_bytes, channel = excluded.channel, release_notes = excluded.release_notes,
                    is_active = excluded.is_active, updated_at = excluded.updated_at'
            )->execute([
                ':ve' => $version !== '' ? $version : '0.1.0',
                ':pl' => $platform,
                ':fn' => $filename,
                ':sh' => $sha256,
                ':sb' => $size,
                ':ch' => $channel,
                ':rn' => $notes,
                ':ia' => $active,
                ':cr' => $now,
                ':up' => $now,
            ]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'releases.save', 'release', $filename, ['version' => $version], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        if ($action === 'releases.delete' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $id = (int)($body['id'] ?? 0);
            $pdo->prepare('DELETE FROM cms_releases WHERE id = :id')->execute([':id' => $id]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'releases.delete', 'release', (string)$id, [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        // ---------------------------------------------------------------------
        // Security Logs, Rate Limits & Sessions
        // ---------------------------------------------------------------------
        if ($action === 'security.events' && $method === 'GET') {
            $since = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
            $rateStmt = $pdo->prepare(
                'SELECT ip_hash, event_type, COUNT(*) AS hits, MAX(created_at) AS last_hit
                 FROM contact_rate_limits WHERE created_at >= :s GROUP BY ip_hash, event_type ORDER BY last_hit DESC LIMIT 50'
            );
            $rateStmt->execute([':s' => $since]);
            $rateLimits = $rateStmt->fetchAll(PDO::FETCH_ASSOC);

            $adminAudit = $pdo->query('SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);

            $secFile = Security::vaultDir($rootDir) . '/security-audit.jsonl';
            $securityJsonl = [];
            if (is_file($secFile)) {
                $lines = @file($secFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach (array_slice(array_reverse($lines), 0, 50) as $line) {
                    $decoded = json_decode($line, true);
                    if (is_array($decoded)) {
                        $securityJsonl[] = $decoded;
                    }
                }
            }

            $activeSessions = (int)$pdo->query('SELECT COUNT(*) FROM admin_sessions WHERE expires_at > ' . $pdo->quote(gmdate('Y-m-d\TH:i:s\Z')))->fetchColumn();

            self::jsonResponse([
                'ok'              => true,
                'rate_limits'     => $rateLimits,
                'admin_audit'     => $adminAudit,
                'security_events' => $securityJsonl,
                'active_sessions' => $activeSessions,
            ]);
            return;
        }

        if ($action === 'security.unblock-ip' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $ipHash = trim((string)($body['ip_hash'] ?? ''));
            $stmt = $pdo->prepare('DELETE FROM contact_rate_limits WHERE ip_hash = :ip');
            $stmt->execute([':ip' => $ipHash]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'security.unblock_ip', 'rate_limit', substr($ipHash, 0, 16), ['deleted' => $stmt->rowCount()], $rootDir);
            self::jsonResponse(['ok' => true, 'deleted' => $stmt->rowCount()]);
            return;
        }

        if ($action === 'security.revoke-all-sessions' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $targetUid = isset($body['user_id']) ? (int)$body['user_id'] : 0;
            if ($targetUid > 0) {
                AdminAuth::revokeUserSessions($pdo, $targetUid, $targetUid === (int)$user['id'] ? (int)$session['id'] : null);
            } else {
                $pdo->prepare('DELETE FROM admin_sessions WHERE id != :sid')->execute([':sid' => (int)$session['id']]);
            }
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'security.revoke_sessions', 'admin_sessions', (string)$targetUid, [], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        // ---------------------------------------------------------------------
        // RBAC User Management (Owner Only)
        // ---------------------------------------------------------------------
        if ($action === 'users.list' && $method === 'GET') {
            if (!$require('owner')) {
                return;
            }
            $rows = $pdo->query('SELECT id, email, name, role, totp_enabled, is_active, last_login_at, created_at FROM admin_users ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
            self::jsonResponse([
                'ok'    => true,
                'users' => array_map([AdminAuth::class, 'publicUser'], $rows),
            ]);
            return;
        }

        if ($action === 'users.create' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $created = AdminAuth::createUser(
                $pdo,
                (string)($body['email'] ?? ''),
                (string)($body['name'] ?? ''),
                (string)($body['role'] ?? 'editor'),
                (string)($body['password'] ?? ''),
                $rootDir
            );
            if (empty($created['ok'])) {
                self::jsonResponse($created, 422);
                return;
            }
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'users.create', 'admin_user', (string)$created['user']['id'], ['role' => $created['user']['role']], $rootDir);
            self::jsonResponse($created, 200);
            return;
        }

        if ($action === 'users.update' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $targetId = (int)($body['id'] ?? 0);
            $target = AdminAuth::getUserById($pdo, $targetId);
            if ($target === null) {
                self::jsonResponse(['ok' => false, 'error' => 'Target user not found.'], 404);
                return;
            }

            $nextRole = isset($body['role']) ? AdminAuth::normalizeRole((string)$body['role']) : (string)$target['role'];
            $nextActive = isset($body['is_active']) ? (!empty($body['is_active']) ? 1 : 0) : (int)$target['is_active'];

            // Guard against removing or deactivating the last active owner
            if ((string)$target['role'] === 'owner' && ($nextRole !== 'owner' || $nextActive !== 1)) {
                $otherOwners = (int)$pdo->query('SELECT COUNT(*) FROM admin_users WHERE role = "owner" AND is_active = 1 AND id != ' . $targetId)->fetchColumn();
                if ($otherOwners < 1) {
                    self::jsonResponse(['ok' => false, 'error' => 'Cannot demote or deactivate the last active owner account.'], 422);
                    return;
                }
            }

            $nextName = isset($body['name']) ? (AdminSanitizer::sanitizePlainText((string)$body['name'], 120) ?: (string)$target['name']) : (string)$target['name'];
            $nextHash = (string)$target['password_hash'];
            $revokeSess = ($nextActive !== 1) || ($nextRole !== (string)$target['role']);

            if (isset($body['new_password']) && (string)$body['new_password'] !== '') {
                if (strlen((string)$body['new_password']) < 10) {
                    self::jsonResponse(['ok' => false, 'error' => 'Password must be at least 10 characters.'], 422);
                    return;
                }
                $nextHash = AdminAuth::hashPassword((string)$body['new_password']);
                $revokeSess = true;
            }

            $totpEnabled = !empty($body['reset_2fa']) ? 0 : (int)$target['totp_enabled'];
            $totpSecret = !empty($body['reset_2fa']) ? '' : (string)$target['totp_secret_enc'];
            $totpPending = !empty($body['reset_2fa']) ? '' : (string)$target['totp_pending_enc'];
            $totpRec = !empty($body['reset_2fa']) ? '[]' : (string)$target['recovery_codes_json'];
            if (!empty($body['reset_2fa'])) {
                $revokeSess = true;
            }

            $pdo->prepare(
                'UPDATE admin_users
                 SET name = :n, role = :r, is_active = :ia, password_hash = :ph,
                     totp_enabled = :te, totp_secret_enc = :ts, totp_pending_enc = :tp, recovery_codes_json = :rc, updated_at = :up
                 WHERE id = :id'
            )->execute([
                ':n'  => $nextName,
                ':r'  => $nextRole,
                ':ia' => $nextActive,
                ':ph' => $nextHash,
                ':te' => $totpEnabled,
                ':ts' => $totpSecret,
                ':tp' => $totpPending,
                ':rc' => $totpRec,
                ':up' => gmdate('Y-m-d\TH:i:s\Z'),
                ':id' => $targetId,
            ]);

            if ($revokeSess) {
                AdminAuth::revokeUserSessions($pdo, $targetId, $targetId === (int)$user['id'] ? (int)$session['id'] : null);
            }

            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'users.update', 'admin_user', (string)$targetId, ['role' => $nextRole, 'is_active' => $nextActive], $rootDir);
            self::jsonResponse(['ok' => true, 'user' => AdminAuth::publicUser(AdminAuth::getUserById($pdo, $targetId) ?? $target)]);
            return;
        }

        if ($action === 'users.delete' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            $targetId = (int)($body['id'] ?? 0);
            if ($targetId === (int)$user['id']) {
                self::jsonResponse(['ok' => false, 'error' => 'Cannot delete your own active account while signed in.'], 422);
                return;
            }
            $target = AdminAuth::getUserById($pdo, $targetId);
            if ($target === null) {
                self::jsonResponse(['ok' => false, 'error' => 'Target user not found.'], 404);
                return;
            }
            if ((string)$target['role'] === 'owner' && (int)$target['is_active'] === 1) {
                $otherOwners = (int)$pdo->query('SELECT COUNT(*) FROM admin_users WHERE role = "owner" AND is_active = 1 AND id != ' . $targetId)->fetchColumn();
                if ($otherOwners < 1) {
                    self::jsonResponse(['ok' => false, 'error' => 'Cannot delete the last active owner account.'], 422);
                    return;
                }
            }
            $pdo->prepare('DELETE FROM admin_users WHERE id = :id')->execute([':id' => $targetId]);
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'users.delete', 'admin_user', (string)$targetId, ['email' => $target['email']], $rootDir);
            self::jsonResponse(['ok' => true]);
            return;
        }

        // ---------------------------------------------------------------------
        // Portable CMS Snapshot Export / Import (Owner Only, Non-Secret)
        // ---------------------------------------------------------------------
        if ($action === 'system.export-cms' && $method === 'GET') {
            if (!$require('owner')) {
                return;
            }
            $discoverySettings = [];
            $stStmt = $pdo->query("SELECT key, value_enc FROM admin_settings WHERE is_secret = 0 AND key LIKE 'discovery.%'");
            if ($stStmt !== false) {
                foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $k = (string)($row['key'] ?? '');
                    if ($k !== '') {
                        $discoverySettings[$k] = AdminDb::getSetting($k, $rootDir, false) ?? '';
                    }
                }
            }
            $snapshot = [
                'ok'          => true,
                'format'      => 'lumora-cms-snapshot-v1',
                'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'tables'      => [
                    'admin_settings'      => $discoverySettings,
                    'cms_blog_posts'      => $pdo->query('SELECT slug, title, summary, content_html, author, category, tags_json, date, image, status, meta_title, meta_description, og_image, canonical_url, source_origin, created_at, updated_at FROM cms_blog_posts ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC),
                    'cms_docs_tracks'     => $pdo->query('SELECT track_id, title, description, sort_order, created_at, updated_at FROM cms_docs_tracks ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC),
                    'cms_docs_articles'   => $pdo->query('SELECT track_id, slug, title, summary, markdown, tags_json, status, sort_order, created_at, updated_at FROM cms_docs_articles ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC),
                    'cms_legal_overrides' => $pdo->query('SELECT slug, title, lead, doc_no, version, date, status, sections_json, is_active, updated_at FROM cms_legal_overrides ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC),
                    'cms_channels'        => $pdo->query('SELECT channel_key, label, description, sla_window, config_json, is_active, sort_order, updated_at FROM cms_channels ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC),
                    'cms_redirects'       => $pdo->query('SELECT source_path, target_url, status_code, is_active, created_at, updated_at FROM cms_redirects ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC),
                    'cms_route_seo'       => $pdo->query('SELECT route_path, meta_title, meta_description, og_image, robots, updated_at FROM cms_route_seo ORDER BY route_path ASC')->fetchAll(PDO::FETCH_ASSOC),
                    'cms_releases'        => $pdo->query('SELECT version, platform, filename, sha256, size_bytes, channel, release_notes, is_active, created_at, updated_at FROM cms_releases ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC),
                ],
            ];
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'system.export_cms', 'cms_snapshot', 'v1', [
                'posts'     => count($snapshot['tables']['cms_blog_posts']),
                'articles'  => count($snapshot['tables']['cms_docs_articles']),
                'redirects' => count($snapshot['tables']['cms_redirects']),
            ], $rootDir);
            if (!headers_sent()) {
                header('Content-Disposition: attachment; filename="lumora-cms-snapshot-' . gmdate('Ymd-His') . '.json"');
            }
            self::jsonResponse($snapshot);
            return;
        }

        if ($action === 'system.import-cms' && $method === 'POST') {
            if (!$require('owner')) {
                return;
            }
            if (($body['format'] ?? '') !== 'lumora-cms-snapshot-v1' || !is_array($body['tables'] ?? null)) {
                self::jsonResponse(['ok' => false, 'error' => 'Invalid CMS snapshot payload (expected lumora-cms-snapshot-v1).'], 422);
                return;
            }
            try {
                $counts = self::importCmsSnapshot($pdo, $body['tables'], (int)$user['id'], $rootDir);
            } catch (\Throwable $e) {
                self::jsonResponse(['ok' => false, 'error' => 'Snapshot import failed: ' . $e->getMessage()], 422);
                return;
            }
            AdminDb::logAudit((int)$user['id'], (string)$user['email'], 'system.import_cms', 'cms_snapshot', 'v1', $counts, $rootDir);
            self::jsonResponse(['ok' => true, 'counts' => $counts]);
            return;
        }

        self::jsonResponse(['ok' => false, 'error' => 'Unknown action or unsupported HTTP method.'], 404);
    }

    private static function buildInquiryQueryFilters(array $query): array
    {
        $clauses = [];
        $params = [];

        $status = strtolower(trim((string)($query['status'] ?? '')));
        if ($status !== '' && in_array($status, ['new', 'in_review', 'responded', 'archived', 'spam'], true)) {
            $clauses[] = 'triage_status = :ts';
            $params[':ts'] = $status;
        }

        $channel = strtolower(trim((string)($query['channel'] ?? '')));
        if ($channel !== '') {
            $clauses[] = 'channel = :ch';
            $params[':ch'] = $channel;
        }

        $sync = strtolower(trim((string)($query['sync_status'] ?? '')));
        if ($sync !== '') {
            $clauses[] = 'sync_status = :sy';
            $params[':sy'] = $sync;
        }

        $q = trim((string)($query['q'] ?? ''));
        if ($q !== '') {
            $clauses[] = '(receipt_id LIKE :q OR full_name LIKE :q OR email LIKE :q OR organization LIKE :q OR subject LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $dateFrom = trim((string)($query['date_from'] ?? ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
            $clauses[] = 'created_at >= :df';
            $params[':df'] = $dateFrom . 'T00:00:00Z';
        }

        $dateTo = trim((string)($query['date_to'] ?? ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
            $clauses[] = 'created_at <= :dt';
            $params[':dt'] = $dateTo . 'T23:59:59Z';
        }

        return [
            'where'  => empty($clauses) ? '' : (' WHERE ' . implode(' AND ', $clauses)),
            'params' => $params,
        ];
    }

    private static function scanStaticMedia(string $rootDir, string $basePath, array $knownPaths): array
    {
        $publicRoot = rtrim($rootDir, '/\\') . '/public';
        if (!is_dir($publicRoot)) {
            return [];
        }
        $items = [];
        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($publicRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'avif', 'gif'], true)) {
                    continue;
                }
                $full = $file->getPathname();
                $rel = '/public/' . ltrim(str_replace('\\', '/', substr($full, strlen($publicRoot))), '/');
                if (isset($knownPaths[$rel])) {
                    continue;
                }
                $info = @getimagesize($full);
                $items[] = [
                    'id'            => 0,
                    'filename'      => $file->getFilename(),
                    'public_path'   => $rel,
                    'url'           => Router::url($rel, $basePath),
                    'mime_type'     => is_array($info) && !empty($info['mime']) ? (string)$info['mime'] : ('image/' . ($ext === 'jpg' ? 'jpeg' : $ext)),
                    'size_bytes'    => (int)$file->getSize(),
                    'width'         => is_array($info) ? (int)($info[0] ?? 0) : 0,
                    'height'        => is_array($info) ? (int)($info[1] ?? 0) : 0,
                    'alt_text'      => '',
                    'source_origin' => 'static',
                    'created_at'    => gmdate('Y-m-d\TH:i:s\Z', (int)($file->getMTime() ?: time())),
                ];
            }
        } catch (\Throwable) {
            return [];
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string)$a['public_path'], (string)$b['public_path']));
        return $items;
    }

    private static function sanitizeChannelConfig(array $body, string $key): string
    {
        $src = is_array($body['config'] ?? null) ? $body['config'] : $body;
        $rawCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($src['code'] ?? $key)) ?? '');
        $code = substr($rawCode !== '' ? $rawCode : 'INQ', 0, 6);

        $fields = [];
        $rawFields = is_array($src['fields'] ?? null) ? $src['fields'] : [];
        foreach (array_slice($rawFields, 0, 20) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $name = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9_]/', '_', (string)($f['name'] ?? ''))));
            $name = trim($name, '_');
            if ($name === '') {
                continue;
            }
            $type = strtolower(trim((string)($f['type'] ?? 'string'))) === 'enum' ? 'enum' : 'string';
            $entry = [
                'name'        => substr($name, 0, 48),
                'label'       => AdminSanitizer::sanitizePlainText((string)($f['label'] ?? $name), 100) ?: $name,
                'type'        => $type,
                'required'    => !empty($f['required']),
                'placeholder' => AdminSanitizer::sanitizePlainText((string)($f['placeholder'] ?? ''), 140),
            ];
            if ($type === 'enum') {
                $optsRaw = is_array($f['options'] ?? null) ? $f['options'] : explode(',', (string)($f['options'] ?? ''));
                $opts = array_values(array_filter(array_map(
                    static fn ($o): string => AdminSanitizer::sanitizePlainText((string)$o, 80),
                    $optsRaw
                ), static fn (string $o): bool => $o !== ''));
                $entry['options'] = array_slice($opts, 0, 30);
            }
            $fields[] = $entry;
        }

        $clean = [
            'code'                => $code,
            'subject_placeholder' => AdminSanitizer::sanitizePlainText((string)($src['subject_placeholder'] ?? ''), 160),
            'message_label'       => AdminSanitizer::sanitizePlainText((string)($src['message_label'] ?? ''), 100),
            'message_placeholder' => AdminSanitizer::sanitizePlainText((string)($src['message_placeholder'] ?? ''), 200),
            'button_text'         => AdminSanitizer::sanitizePlainText((string)($src['button_text'] ?? ''), 80),
            'fields'              => $fields,
        ];
        return json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private static function restoreRevisionRow(PDO $pdo, array $rev, string $actorEmail, string $rootDir): array
    {
        $entityType = (string)($rev['entity_type'] ?? '');
        $snap = json_decode((string)($rev['payload_json'] ?? '{}'), true);
        if (!is_array($snap)) {
            return ['ok' => false, 'error' => 'Corrupt revision snapshot JSON.'];
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');

        if ($entityType === 'blog') {
            $slug = strtolower(trim((string)($snap['slug'] ?? $rev['entity_key'] ?? '')));
            if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1 || $slug === 'index') {
                return ['ok' => false, 'error' => 'Invalid blog slug in revision snapshot.'];
            }
            $title = AdminSanitizer::sanitizePlainText((string)($snap['title'] ?? ''), 200);
            $summary = AdminSanitizer::sanitizePlainText((string)($snap['summary'] ?? ''), 500);
            $contentHtml = AdminSanitizer::sanitizeHtml((string)($snap['content_html'] ?? ''), $rootDir);
            $author = AdminSanitizer::sanitizePlainText((string)($snap['author'] ?? ''), 120);
            $category = AdminSanitizer::sanitizePlainText((string)($snap['category'] ?? 'General'), 80) ?: 'General';
            $status = in_array((string)($snap['status'] ?? 'published'), ['published', 'draft', 'scheduled'], true) ? (string)$snap['status'] : 'published';
            $date = trim((string)($snap['date'] ?? gmdate('Y-m-d')));
            $image = trim((string)($snap['image'] ?? ''));
            if ($image !== '' && !AdminSanitizer::isSafeUrl($image, false)) {
                $image = '';
            }
            $metaTitle = AdminSanitizer::sanitizePlainText((string)($snap['meta_title'] ?? ''), 180);
            $metaDesc = AdminSanitizer::sanitizePlainText((string)($snap['meta_description'] ?? ''), 320);
            $ogImage = trim((string)($snap['og_image'] ?? ''));
            if ($ogImage !== '' && !AdminSanitizer::isSafeUrl($ogImage, false)) {
                $ogImage = '';
            }
            $canonical = trim((string)($snap['canonical_url'] ?? ''));
            if ($canonical !== '' && !AdminSanitizer::isSafeUrl($canonical, false)) {
                $canonical = '';
            }
            $tags = is_array($snap['tags'] ?? null) ? $snap['tags'] : [];

            $pdo->prepare(
                'INSERT INTO cms_blog_posts (slug, title, summary, content_html, author, category, tags_json, date, image, status, meta_title, meta_description, og_image, canonical_url, source_origin, created_at, updated_at)
                 VALUES (:sl, :ti, :su, :ch, :au, :ca, :tj, :da, :im, :st, :mt, :md, :og, :cu, "sqlite", :cr, :up)
                 ON CONFLICT(slug) DO UPDATE SET
                    title = excluded.title, summary = excluded.summary, content_html = excluded.content_html,
                    author = excluded.author, category = excluded.category, tags_json = excluded.tags_json,
                    date = excluded.date, image = excluded.image, status = excluded.status,
                    meta_title = excluded.meta_title, meta_description = excluded.meta_description,
                    og_image = excluded.og_image, canonical_url = excluded.canonical_url, updated_at = excluded.updated_at'
            )->execute([
                ':sl' => $slug, ':ti' => $title, ':su' => $summary, ':ch' => $contentHtml,
                ':au' => $author, ':ca' => $category, ':tj' => json_encode($tags) ?: '[]',
                ':da' => $date, ':im' => $image, ':st' => $status,
                ':mt' => $metaTitle, ':md' => $metaDesc, ':og' => $ogImage, ':cu' => $canonical,
                ':cr' => $now, ':up' => $now,
            ]);
            AdminDb::recordRevision($pdo, 'blog', $slug, $title, $snap, $actorEmail);
            return ['ok' => true, 'entity_type' => 'blog', 'entity_key' => $slug];
        }

        if ($entityType === 'docs') {
            $tid = strtolower(trim((string)($snap['track_id'] ?? '')));
            $slug = strtolower(trim((string)($snap['slug'] ?? '')));
            if (preg_match('/^[a-z0-9-]+$/', $tid) !== 1 || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
                return ['ok' => false, 'error' => 'Invalid docs identifiers in revision snapshot.'];
            }
            $title = AdminSanitizer::sanitizePlainText((string)($snap['title'] ?? ''), 200);
            $summary = AdminSanitizer::sanitizePlainText((string)($snap['summary'] ?? ''), 400);
            $markdown = (string)($snap['markdown'] ?? '');
            $status = strtolower(trim((string)($snap['status'] ?? 'published'))) === 'draft' ? 'draft' : 'published';
            $order = (int)($snap['sort_order'] ?? 100);
            $tags = is_array($snap['tags'] ?? null) ? $snap['tags'] : [];

            $pdo->prepare(
                'INSERT INTO cms_docs_articles (track_id, slug, title, summary, markdown, tags_json, status, sort_order, created_at, updated_at)
                 VALUES (:ti, :sl, :tl, :su, :md, :tj, :st, :so, :cr, :up)
                 ON CONFLICT(track_id, slug) DO UPDATE SET
                    title = excluded.title, summary = excluded.summary, markdown = excluded.markdown,
                    tags_json = excluded.tags_json, status = excluded.status, sort_order = excluded.sort_order, updated_at = excluded.updated_at'
            )->execute([
                ':ti' => $tid, ':sl' => $slug, ':tl' => $title, ':su' => $summary, ':md' => $markdown,
                ':tj' => json_encode($tags) ?: '[]', ':st' => $status, ':so' => $order, ':cr' => $now, ':up' => $now,
            ]);
            AdminDb::recordRevision($pdo, 'docs', "{$tid}/{$slug}", $title, $snap, $actorEmail);
            return ['ok' => true, 'entity_type' => 'docs', 'entity_key' => "{$tid}/{$slug}"];
        }

        if ($entityType === 'legal') {
            $slug = strtolower(trim((string)($snap['slug'] ?? $rev['entity_key'] ?? '')));
            if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1 || $slug === 'index') {
                return ['ok' => false, 'error' => 'Invalid legal slug in revision snapshot.'];
            }
            $title = AdminSanitizer::sanitizePlainText((string)($snap['title'] ?? ''), 180);
            $lead = AdminSanitizer::sanitizePlainText((string)($snap['lead'] ?? ''), 400);
            $docNo = AdminSanitizer::sanitizePlainText((string)($snap['doc_no'] ?? ''), 40);
            $version = AdminSanitizer::sanitizePlainText((string)($snap['version'] ?? '1.0.0'), 32);
            $date = AdminSanitizer::sanitizePlainText((string)($snap['date'] ?? gmdate('Y-m-d')), 32);
            $status = AdminSanitizer::sanitizePlainText((string)($snap['status'] ?? 'ACTIVE POLICY'), 64);
            $active = isset($snap['is_active']) ? (!empty($snap['is_active']) ? 1 : 0) : 1;
            $cleanSections = [];
            foreach ((is_array($snap['sections'] ?? null) ? $snap['sections'] : []) as $idx => $sec) {
                if (!is_array($sec)) {
                    continue;
                }
                $cleanSections[] = [
                    'id'    => strtolower((string)preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($sec['id'] ?? ('sec-' . ($idx + 1))))),
                    'title' => AdminSanitizer::sanitizePlainText((string)($sec['title'] ?? ''), 160),
                    'body'  => AdminSanitizer::sanitizeHtml((string)($sec['body'] ?? ''), $rootDir),
                ];
            }
            $pdo->prepare(
                'INSERT INTO cms_legal_overrides (slug, title, lead, doc_no, version, date, status, sections_json, is_active, updated_at)
                 VALUES (:sl, :ti, :le, :dn, :ve, :da, :st, :sj, :ia, :up)
                 ON CONFLICT(slug) DO UPDATE SET
                    title = excluded.title, lead = excluded.lead, doc_no = excluded.doc_no,
                    version = excluded.version, date = excluded.date, status = excluded.status,
                    sections_json = excluded.sections_json, is_active = excluded.is_active, updated_at = excluded.updated_at'
            )->execute([
                ':sl' => $slug, ':ti' => $title, ':le' => $lead, ':dn' => $docNo,
                ':ve' => $version, ':da' => $date, ':st' => $status,
                ':sj' => json_encode($cleanSections) ?: '[]', ':ia' => $active, ':up' => $now,
            ]);
            AdminDb::recordRevision($pdo, 'legal', $slug, $title, $snap, $actorEmail);
            return ['ok' => true, 'entity_type' => 'legal', 'entity_key' => $slug];
        }

        return ['ok' => false, 'error' => 'Unsupported revision entity type.'];
    }

    private static function importCmsSnapshot(PDO $pdo, array $tables, int $uid, string $rootDir): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $counts = [
            'admin_settings'      => 0,
            'cms_blog_posts'      => 0,
            'cms_docs_tracks'     => 0,
            'cms_docs_articles'   => 0,
            'cms_legal_overrides' => 0,
            'cms_channels'        => 0,
            'cms_redirects'       => 0,
            'cms_route_seo'       => 0,
            'cms_releases'        => 0,
        ];

        $pdo->beginTransaction();
        try {
            $allowedSettings = ['discovery.llms_txt', 'discovery.llms_full_txt', 'discovery.robots_extra', 'discovery.security_txt'];
            if (is_array($tables['admin_settings'] ?? null)) {
                foreach ($tables['admin_settings'] as $k => $v) {
                    if (in_array((string)$k, $allowedSettings, true) && is_scalar($v)) {
                        $clean = str_replace("\0", '', (string)$v);
                        AdminDb::setSetting((string)$k, substr($clean, 0, 64000), false, $uid, $rootDir);
                        $counts['admin_settings']++;
                    }
                }
            }

            if (is_array($tables['cms_blog_posts'] ?? null)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cms_blog_posts (slug, title, summary, content_html, author, category, tags_json, date, image, status, meta_title, meta_description, og_image, canonical_url, source_origin, created_at, updated_at)
                     VALUES (:sl, :ti, :su, :ch, :au, :ca, :tj, :da, :im, :st, :mt, :md, :og, :cu, "sqlite", :cr, :up)
                     ON CONFLICT(slug) DO UPDATE SET
                        title = excluded.title, summary = excluded.summary, content_html = excluded.content_html,
                        author = excluded.author, category = excluded.category, tags_json = excluded.tags_json,
                        date = excluded.date, image = excluded.image, status = excluded.status,
                        meta_title = excluded.meta_title, meta_description = excluded.meta_description,
                        og_image = excluded.og_image, canonical_url = excluded.canonical_url, updated_at = excluded.updated_at'
                );
                foreach ($tables['cms_blog_posts'] as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $slug = strtolower(trim((string)($r['slug'] ?? '')));
                    if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1 || $slug === 'index') {
                        continue;
                    }
                    $img = trim((string)($r['image'] ?? ''));
                    if ($img !== '' && !AdminSanitizer::isSafeUrl($img, false)) {
                        $img = '';
                    }
                    $og = trim((string)($r['og_image'] ?? ''));
                    if ($og !== '' && !AdminSanitizer::isSafeUrl($og, false)) {
                        $og = '';
                    }
                    $cu = trim((string)($r['canonical_url'] ?? ''));
                    if ($cu !== '' && !AdminSanitizer::isSafeUrl($cu, false)) {
                        $cu = '';
                    }
                    $st = strtolower(trim((string)($r['status'] ?? 'published')));
                    if (!in_array($st, ['published', 'draft', 'scheduled'], true)) {
                        $st = 'published';
                    }
                    $stmt->execute([
                        ':sl' => $slug,
                        ':ti' => AdminSanitizer::sanitizePlainText((string)($r['title'] ?? $slug), 200) ?: $slug,
                        ':su' => AdminSanitizer::sanitizePlainText((string)($r['summary'] ?? ''), 500),
                        ':ch' => AdminSanitizer::sanitizeHtml((string)($r['content_html'] ?? ''), $rootDir),
                        ':au' => AdminSanitizer::sanitizePlainText((string)($r['author'] ?? ''), 120),
                        ':ca' => AdminSanitizer::sanitizePlainText((string)($r['category'] ?? 'General'), 80) ?: 'General',
                        ':tj' => is_string($r['tags_json'] ?? null) ? $r['tags_json'] : (json_encode($r['tags'] ?? []) ?: '[]'),
                        ':da' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($r['date'] ?? '')) === 1 ? (string)$r['date'] : gmdate('Y-m-d'),
                        ':im' => $img,
                        ':st' => $st,
                        ':mt' => AdminSanitizer::sanitizePlainText((string)($r['meta_title'] ?? ''), 180),
                        ':md' => AdminSanitizer::sanitizePlainText((string)($r['meta_description'] ?? ''), 320),
                        ':og' => $og,
                        ':cu' => $cu,
                        ':cr' => (string)($r['created_at'] ?? $now),
                        ':up' => $now,
                    ]);
                    $counts['cms_blog_posts']++;
                }
            }

            if (is_array($tables['cms_docs_tracks'] ?? null)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cms_docs_tracks (track_id, title, description, sort_order, created_at, updated_at)
                     VALUES (:id, :ti, :de, :so, :cr, :up)
                     ON CONFLICT(track_id) DO UPDATE SET title = excluded.title, description = excluded.description, sort_order = excluded.sort_order, updated_at = excluded.updated_at'
                );
                foreach ($tables['cms_docs_tracks'] as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $tid = strtolower(trim((string)($r['track_id'] ?? '')));
                    if (preg_match('/^[a-z0-9-]+$/', $tid) !== 1) {
                        continue;
                    }
                    $stmt->execute([
                        ':id' => $tid,
                        ':ti' => AdminSanitizer::sanitizePlainText((string)($r['title'] ?? $tid), 140) ?: $tid,
                        ':de' => AdminSanitizer::sanitizePlainText((string)($r['description'] ?? ''), 300),
                        ':so' => (int)($r['sort_order'] ?? 100),
                        ':cr' => (string)($r['created_at'] ?? $now),
                        ':up' => $now,
                    ]);
                    $counts['cms_docs_tracks']++;
                }
            }

            if (is_array($tables['cms_docs_articles'] ?? null)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cms_docs_articles (track_id, slug, title, summary, markdown, tags_json, status, sort_order, created_at, updated_at)
                     VALUES (:ti, :sl, :tl, :su, :md, :tj, :st, :so, :cr, :up)
                     ON CONFLICT(track_id, slug) DO UPDATE SET
                        title = excluded.title, summary = excluded.summary, markdown = excluded.markdown,
                        tags_json = excluded.tags_json, status = excluded.status, sort_order = excluded.sort_order, updated_at = excluded.updated_at'
                );
                foreach ($tables['cms_docs_articles'] as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $tid = strtolower(trim((string)($r['track_id'] ?? '')));
                    $slug = strtolower(trim((string)($r['slug'] ?? '')));
                    if (preg_match('/^[a-z0-9-]+$/', $tid) !== 1 || preg_match('/^[a-z0-9-]+$/', $slug) !== 1 || stripos($slug, 'internal') !== false) {
                        continue;
                    }
                    $stmt->execute([
                        ':ti' => $tid,
                        ':sl' => $slug,
                        ':tl' => AdminSanitizer::sanitizePlainText((string)($r['title'] ?? $slug), 200) ?: $slug,
                        ':su' => AdminSanitizer::sanitizePlainText((string)($r['summary'] ?? ''), 400),
                        ':md' => (string)($r['markdown'] ?? ''),
                        ':tj' => is_string($r['tags_json'] ?? null) ? $r['tags_json'] : '[]',
                        ':st' => strtolower(trim((string)($r['status'] ?? 'published'))) === 'draft' ? 'draft' : 'published',
                        ':so' => (int)($r['sort_order'] ?? 100),
                        ':cr' => (string)($r['created_at'] ?? $now),
                        ':up' => $now,
                    ]);
                    $counts['cms_docs_articles']++;
                }
            }

            if (is_array($tables['cms_legal_overrides'] ?? null)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cms_legal_overrides (slug, title, lead, doc_no, version, date, status, sections_json, is_active, updated_at)
                     VALUES (:sl, :ti, :le, :dn, :ve, :da, :st, :sj, :ia, :up)
                     ON CONFLICT(slug) DO UPDATE SET
                        title = excluded.title, lead = excluded.lead, doc_no = excluded.doc_no,
                        version = excluded.version, date = excluded.date, status = excluded.status,
                        sections_json = excluded.sections_json, is_active = excluded.is_active, updated_at = excluded.updated_at'
                );
                foreach ($tables['cms_legal_overrides'] as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $slug = strtolower(trim((string)($r['slug'] ?? '')));
                    if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1 || $slug === 'index') {
                        continue;
                    }
                    $rawSecs = is_array($r['sections'] ?? null) ? $r['sections'] : (json_decode((string)($r['sections_json'] ?? '[]'), true) ?: []);
                    $cleanSecs = [];
                    foreach ($rawSecs as $idx => $sec) {
                        if (!is_array($sec)) {
                            continue;
                        }
                        $cleanSecs[] = [
                            'id'    => strtolower((string)preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($sec['id'] ?? ('sec-' . ($idx + 1))))),
                            'title' => AdminSanitizer::sanitizePlainText((string)($sec['title'] ?? ''), 160),
                            'body'  => AdminSanitizer::sanitizeHtml((string)($sec['body'] ?? ''), $rootDir),
                        ];
                    }
                    $stmt->execute([
                        ':sl' => $slug,
                        ':ti' => AdminSanitizer::sanitizePlainText((string)($r['title'] ?? $slug), 180) ?: $slug,
                        ':le' => AdminSanitizer::sanitizePlainText((string)($r['lead'] ?? ''), 400),
                        ':dn' => AdminSanitizer::sanitizePlainText((string)($r['doc_no'] ?? ''), 40),
                        ':ve' => AdminSanitizer::sanitizePlainText((string)($r['version'] ?? '1.0.0'), 32),
                        ':da' => AdminSanitizer::sanitizePlainText((string)($r['date'] ?? gmdate('Y-m-d')), 32),
                        ':st' => AdminSanitizer::sanitizePlainText((string)($r['status'] ?? 'ACTIVE POLICY'), 64),
                        ':sj' => json_encode($cleanSecs) ?: '[]',
                        ':ia' => !empty($r['is_active']) ? 1 : 0,
                        ':up' => $now,
                    ]);
                    $counts['cms_legal_overrides']++;
                }
            }

            if (is_array($tables['cms_channels'] ?? null)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cms_channels (channel_key, label, description, sla_window, config_json, is_active, sort_order, updated_at)
                     VALUES (:ck, :la, :de, :sl, :cj, :ia, :so, :up)
                     ON CONFLICT(channel_key) DO UPDATE SET
                        label = excluded.label, description = excluded.description, sla_window = excluded.sla_window,
                        config_json = excluded.config_json, is_active = excluded.is_active, sort_order = excluded.sort_order, updated_at = excluded.updated_at'
                );
                foreach ($tables['cms_channels'] as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $ck = strtolower(trim((string)($r['channel_key'] ?? '')));
                    if (preg_match('/^[a-z0-9_-]+$/', $ck) !== 1) {
                        continue;
                    }
                    $decodedCfg = json_decode((string)($r['config_json'] ?? '{}'), true);
                    $cfgJson = self::sanitizeChannelConfig(['config' => is_array($decodedCfg) ? $decodedCfg : []], $ck);
                    $stmt->execute([
                        ':ck' => $ck,
                        ':la' => AdminSanitizer::sanitizePlainText((string)($r['label'] ?? $ck), 120) ?: $ck,
                        ':de' => AdminSanitizer::sanitizePlainText((string)($r['description'] ?? ''), 240),
                        ':sl' => AdminSanitizer::sanitizePlainText((string)($r['sla_window'] ?? '1-2 Business Days'), 80) ?: '1-2 Business Days',
                        ':cj' => $cfgJson,
                        ':ia' => !empty($r['is_active']) ? 1 : 0,
                        ':so' => (int)($r['sort_order'] ?? 100),
                        ':up' => $now,
                    ]);
                    $counts['cms_channels']++;
                }
            }

            if (is_array($tables['cms_redirects'] ?? null)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cms_redirects (source_path, target_url, status_code, is_active, created_at, updated_at)
                     VALUES (:sp, :tu, :sc, :ia, :cr, :up)
                     ON CONFLICT(source_path) DO UPDATE SET target_url = excluded.target_url, status_code = excluded.status_code, is_active = excluded.is_active, updated_at = excluded.updated_at'
                );
                foreach ($tables['cms_redirects'] as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $sp = trim((string)($r['source_path'] ?? ''));
                    $tu = trim((string)($r['target_url'] ?? ''));
                    if ($sp === '' || !str_starts_with($sp, '/') || str_contains($sp, '..') || !AdminSanitizer::isSafeUrl($tu, false)) {
                        continue;
                    }
                    $stmt->execute([
                        ':sp' => $sp,
                        ':tu' => $tu,
                        ':sc' => (int)($r['status_code'] ?? 301) === 302 ? 302 : 301,
                        ':ia' => !empty($r['is_active']) ? 1 : 0,
                        ':cr' => (string)($r['created_at'] ?? $now),
                        ':up' => $now,
                    ]);
                    $counts['cms_redirects']++;
                }
            }

            if (is_array($tables['cms_route_seo'] ?? null)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cms_route_seo (route_path, meta_title, meta_description, og_image, robots, updated_at)
                     VALUES (:rp, :mt, :md, :oi, :ro, :up)
                     ON CONFLICT(route_path) DO UPDATE SET
                        meta_title = excluded.meta_title, meta_description = excluded.meta_description,
                        og_image = excluded.og_image, robots = excluded.robots, updated_at = excluded.updated_at'
                );
                foreach ($tables['cms_route_seo'] as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $rp = trim((string)($r['route_path'] ?? ''));
                    if ($rp === '' || !str_starts_with($rp, '/') || str_contains($rp, '..')) {
                        continue;
                    }
                    $oi = trim((string)($r['og_image'] ?? ''));
                    if ($oi !== '' && !AdminSanitizer::isSafeUrl($oi, false)) {
                        $oi = '';
                    }
                    $stmt->execute([
                        ':rp' => $rp,
                        ':mt' => AdminSanitizer::sanitizePlainText((string)($r['meta_title'] ?? ''), 180),
                        ':md' => AdminSanitizer::sanitizePlainText((string)($r['meta_description'] ?? ''), 320),
                        ':oi' => $oi,
                        ':ro' => AdminSanitizer::sanitizePlainText((string)($r['robots'] ?? 'index, follow'), 80) ?: 'index, follow',
                        ':up' => $now,
                    ]);
                    $counts['cms_route_seo']++;
                }
            }

            if (is_array($tables['cms_releases'] ?? null)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cms_releases (version, platform, filename, sha256, size_bytes, channel, release_notes, is_active, created_at, updated_at)
                     VALUES (:ve, :pl, :fn, :sh, :sb, :ch, :rn, :ia, :cr, :up)
                     ON CONFLICT(filename) DO UPDATE SET
                        version = excluded.version, platform = excluded.platform, sha256 = excluded.sha256,
                        size_bytes = excluded.size_bytes, channel = excluded.channel, release_notes = excluded.release_notes,
                        is_active = excluded.is_active, updated_at = excluded.updated_at'
                );
                foreach ($tables['cms_releases'] as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $fn = basename(trim((string)($r['filename'] ?? '')));
                    $sh = strtolower(trim((string)($r['sha256'] ?? '')));
                    if ($fn === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $fn) !== 1 || preg_match('/^[a-f0-9]{64}$/', $sh) !== 1) {
                        continue;
                    }
                    $pl = strtolower(trim((string)($r['platform'] ?? 'archive')));
                    if (!in_array($pl, ['windows', 'linux', 'macos', 'android', 'archive'], true)) {
                        $pl = 'archive';
                    }
                    $ch = strtolower(trim((string)($r['channel'] ?? 'stable')));
                    if (!in_array($ch, ['stable', 'beta', 'rc'], true)) {
                        $ch = 'stable';
                    }
                    $stmt->execute([
                        ':ve' => AdminSanitizer::sanitizePlainText((string)($r['version'] ?? '0.1.0'), 32) ?: '0.1.0',
                        ':pl' => $pl,
                        ':fn' => $fn,
                        ':sh' => $sh,
                        ':sb' => max(0, (int)($r['size_bytes'] ?? 0)),
                        ':ch' => $ch,
                        ':rn' => AdminSanitizer::sanitizePlainText((string)($r['release_notes'] ?? ''), 1500),
                        ':ia' => !empty($r['is_active']) ? 1 : 0,
                        ':cr' => (string)($r['created_at'] ?? $now),
                        ':up' => $now,
                    ]);
                    $counts['cms_releases']++;
                }
            }

            $pdo->commit();
            return $counts;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function escapeCsvCell(string $cell): string
    {
        if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $cell;
        }
        return $cell;
    }

    private static function isValidWebhookUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return false;
        }
        $scheme = strtolower((string)($parsed['scheme'] ?? ''));
        $host = strtolower((string)($parsed['host'] ?? ''));
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        return ($scheme === 'https') || ($scheme === 'http' && $isLocal);
    }

    private static function countDocsArticles(string $rootDir): int
    {
        $docs = new DocsEngine($rootDir . '/content/docs');
        $manifest = $docs->getManifest(true);
        $total = 0;
        foreach (($manifest['tracks'] ?? []) as $track) {
            $total += count(is_array($track['articles'] ?? null) ? $track['articles'] : []);
        }
        return $total;
    }

    private static function readCsrfHeader(): string
    {
        return trim((string)($_SERVER['HTTP_X_LUMORA_CSRF'] ?? ''));
    }

    private static function verifyRequestBootstrapCsrf(string $rootDir): bool
    {
        $token = self::readCsrfHeader();
        if ($token === '') {
            return false;
        }
        return Security::verifyCsrf($rootDir, $token);
    }

    private static function readJsonBody(): array
    {
        if (isset($GLOBALS['lumoraAdminTestBody']) && is_array($GLOBALS['lumoraAdminTestBody'])) {
            return $GLOBALS['lumoraAdminTestBody'];
        }
        $raw = (string)@file_get_contents('php://input', false, null, 0, 2097152);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

\class_alias(AdminController::class, \Astrake\Lumora\Core\AdminController::class);
