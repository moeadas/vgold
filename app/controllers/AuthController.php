<?php
require_once __DIR__ . '/../lib/Graph.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../../vendor/autoload.php';
use Firebase\JWT\JWT;

class AuthController {

    /**
     * Salt+digest of a throwaway bcrypt hash, used when the identifier does not
     * match any account so that the failure still pays the bcrypt cost. Without
     * it the not-found path returned in a few milliseconds while a real account
     * took ~180ms, which told an unauthenticated caller exactly which addresses
     * are real.
     *
     * The cost is spliced in at call time rather than baked in: password_verify()
     * reads the round count from the hash prefix, and the body is independent of
     * it, so this always runs the same number of rounds as a freshly-created
     * account on whatever PHP this server happens to be on. The digest will never
     * match — that is the point.
     */
    private const DUMMY_HASH_BODY = 'E7Ghl.85jRV.woF.Ku.fy.QDrlNLqs5rWDg/0u1pv2ZEIGWgZGd4u';

    private static function dummyHash() {
        $cost = defined('PASSWORD_BCRYPT_DEFAULT_COST') ? (int)PASSWORD_BCRYPT_DEFAULT_COST : 10;
        if ($cost < 4 || $cost > 31) $cost = 10;
        return '$2y$' . str_pad((string)$cost, 2, '0', STR_PAD_LEFT) . '$' . self::DUMMY_HASH_BODY;
    }

    public static function registerDisabled() {
        jsonError('Registration is disabled. Ask an admin to add you.', 403);
    }
    
    public static function register() {
        $data = input();
        requireFields(['name', 'email', 'password'], $data);
        
        $existing = DB::fetch("SELECT id FROM users WHERE email = ?", [$data['email']]);
        if ($existing) jsonError('Email already registered');
        
        $password = $data['password'];
        if (strlen($password) < 8) jsonError('Password must be at least 8 characters');
        
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $color = sprintf('#%06X', mt_rand(0x4F5635, 0xC99520));
        
        DB::conn()->beginTransaction();
        try {
            $wsId = DB::insert('workspaces', [
                'name' => $data['name'] . "'s workspace",
                'created_by' => 0,
            ]);
            $userId = DB::insert('users', [
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $hashed,
                'avatar_color' => $color,
                'role' => 'admin',
            ]);
            DB::update('workspaces', ['created_by' => $userId], 'id = ?', [$wsId]);
            DB::insert('workspace_members', [
                'workspace_id' => $wsId,
                'user_id' => $userId,
                'role' => 'admin',
            ]);
            DB::insert('user_settings', ['user_id' => $userId]);
            
            // Create default channels
            foreach (['general', 'random'] as $ch) {
                $chId = DB::insert('channels', ['workspace_id' => $wsId, 'name' => $ch, 'type' => 'channel']);
                DB::insert('channel_members', ['channel_id' => $chId, 'user_id' => $userId]);
            }
            
            DB::conn()->commit();
            Auth::login($userId, $wsId);
            jsonResponse(['ok' => true, 'user' => ['id' => $userId, 'name' => $data['name'], 'email' => $data['email']]]);
        } catch (Exception $e) {
            DB::conn()->rollBack();
            $msg = APP_DEBUG ? $e->getMessage() : 'Registration failed';
            jsonError($msg, 500);
        }
    }
    
    /**
     * Start a reset from the login screen.
     *
     * Answers identically whether or not the address exists — this endpoint is
     * public, so a distinguishable response would turn it into a tool for
     * discovering who has an account.
     */
    public static function forgotPassword() {
        $data  = input();
        $email = strtolower(trim($data['email'] ?? ''));
        $generic = ['ok' => true, 'message' => 'If that address belongs to an account that signs in with a password, a reset link is on its way.'];

        // Per-IP ceiling, independent of which address is being probed. Stored
        // server-side for the same reason as the login counter: a session-backed
        // ceiling is reset by dropping the cookie.
        if (RateLimit::ensure()) {
            $ip = RateLimit::ip();
            if (RateLimit::exceeded(RateLimit::RESET_IP, $ip, RateLimit::RESET_IP_MAX, RateLimit::RESET_WINDOW)) {
                jsonResponse($generic);
            }
            RateLimit::hit(RateLimit::RESET_IP, $ip);
        } else {
            $ipKey = 'pwreset_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $win   = $_SESSION[$ipKey . '_time'] ?? 0;
            if ($win && (time() - $win) > 3600) { unset($_SESSION[$ipKey], $_SESSION[$ipKey . '_time']); }
            if ((int)($_SESSION[$ipKey] ?? 0) >= 10) jsonResponse($generic);
            $_SESSION[$ipKey] = (int)($_SESSION[$ipKey] ?? 0) + 1;
            if (!isset($_SESSION[$ipKey . '_time'])) $_SESSION[$ipKey . '_time'] = time();
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse($generic);

        $user = DB::fetch("SELECT id, name, email, auth_provider FROM users WHERE LOWER(email) = ? AND is_active = 1", [$email]);
        // Microsoft accounts have no local password, so there is nothing to reset.
        if ($user && PasswordReset::isPasswordAccount($user) && !PasswordReset::isThrottled($user['id'])) {
            $token = PasswordReset::issue($user['id']);
            PasswordReset::sendEmail($user, $token);
        }
        jsonResponse($generic);
    }

    /** Is this reset link still good? Lets the UI say so before asking for a password. */
    public static function resetCheck() {
        $row = PasswordReset::resolve($_GET['token'] ?? '');
        if (!$row) jsonResponse(['valid' => false]);
        jsonResponse(['valid' => true, 'name' => $row['name'], 'email' => $row['email']]);
    }

    /** Finish a reset: spend the token, set the password, sign the person in. */
    public static function resetPassword() {
        $data     = input();
        $token    = (string)($data['token'] ?? '');
        $password = (string)($data['password'] ?? '');

        $err = PasswordReset::validate($password);
        if ($err) jsonError($err);

        $row = PasswordReset::consume($token, $password);
        if (!$row) jsonError('This reset link has expired or has already been used. Request a new one.', 400);

        $wm = DB::fetch("SELECT workspace_id FROM workspace_members WHERE user_id = ? ORDER BY joined_at ASC LIMIT 1", [$row['user_id']]);
        if (!$wm) jsonResponse(['ok' => true, 'signed_in' => false]);

        $_SESSION['auth_provider'] = 'password';
        Auth::login((int)$row['user_id'], (int)$wm['workspace_id']);
        jsonResponse(['ok' => true, 'signed_in' => true, 'csrf_token' => Csrf::token()]);
    }

    public static function login() {
        $data = input();
        requireFields(['email', 'password'], $data);
        $identifier = trim($data['email']);
        $identifierLc = strtolower($identifier);
        $password = $data['password'];
        
        // Rate limiting. The counters live in the database, keyed by IP and by the
        // identifier being tried — a counter kept in $_SESSION is reset by simply
        // dropping the session cookie, so it stopped nothing. The per-account
        // ceiling is the tight one; the per-IP ceiling is loose because the whole
        // office shares one NAT address.
        $ip = RateLimit::ip();
        $limiterLive = RateLimit::ensure();

        if ($limiterLive) {
            if (RateLimit::exceeded(RateLimit::LOGIN_USER, $identifierLc, RateLimit::LOGIN_USER_MAX, RateLimit::LOGIN_WINDOW)
             || RateLimit::exceeded(RateLimit::LOGIN_IP, $ip, RateLimit::LOGIN_IP_MAX, RateLimit::LOGIN_WINDOW)) {
                jsonError('Too many login attempts. Please try again in 15 minutes.', 429);
            }
        } else {
            // Degraded: the attempts table is unavailable. Keep the old
            // session-backed behaviour rather than letting nobody sign in.
            $key = 'login_' . md5($ip);
            $attempts = (int)($_SESSION[$key] ?? 0);
            $firstAttempt = $_SESSION[$key . '_time'] ?? 0;
            if ($firstAttempt && (time() - $firstAttempt) > 900) {
                $_SESSION[$key] = 0;
                $_SESSION[$key . '_time'] = time();
            }
            if ($attempts >= 5) {
                jsonError('Too many login attempts. Please try again in 15 minutes.', 429);
            }
        }

        $user = DB::fetch("SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1", [$identifierLc]);
        if (!$user) {
            $user = DB::fetch("SELECT * FROM users WHERE LOWER(crm_username) = ? AND is_active = 1", [$identifierLc]);
        }

        // Always pay the bcrypt cost, even for an address that does not exist.
        // Skipping it returned in ~5ms instead of ~180ms, which told an
        // unauthenticated caller exactly which addresses are real accounts.
        $hash = ($user && !empty($user['password'])) ? $user['password'] : self::dummyHash();
        $passwordOk = password_verify($password, $hash);

        if (!$user || !$passwordOk) {
            if ($limiterLive) {
                RateLimit::hit(RateLimit::LOGIN_USER, $identifierLc);
                RateLimit::hit(RateLimit::LOGIN_IP, $ip);
            } else {
                $key = 'login_' . md5($ip);
                $_SESSION[$key] = (int)($_SESSION[$key] ?? 0) + 1;
                if (!isset($_SESSION[$key . '_time'])) $_SESSION[$key . '_time'] = time();
            }
            jsonError('Invalid credentials', 401);
        }
        
        $wm = DB::fetch("SELECT workspace_id, role FROM workspace_members WHERE user_id = ? ORDER BY joined_at ASC LIMIT 1", [$user['id']]);
        if (!$wm) jsonError('No workspace found', 403);
        
        // Reset rate limit on success
        if ($limiterLive) {
            RateLimit::clear(RateLimit::LOGIN_USER, $identifierLc);
            RateLimit::clear(RateLimit::LOGIN_IP, $ip);
        } else {
            $key = 'login_' . md5($ip);
            unset($_SESSION[$key]);
            unset($_SESSION[$key . '_time']);
        }
        
        // Set auth_provider + CRM linkage in session from the user's DB record.
        $_SESSION['auth_provider'] = $user['auth_provider'] ?? 'password';
        $_SESSION['crm_user_id'] = $user['crm_user_id'] ?? null;
        $_SESSION['crm_role'] = $user['crm_role'] ?? null;
        
        Auth::login($user['id'], $wm['workspace_id']);
        // Default on: this is an internal tool and the phone PWA is the main
        // casualty of a short session. Send remember:false to opt out.
        if (!array_key_exists('remember', $data) || !empty($data['remember'])) {
            Auth::rememberIssue($user['id'], $wm['workspace_id']);
        }
        jsonResponse(['ok' => true, 'csrf_token' => Csrf::token(), 'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $wm['role'],
            'avatar_color' => $user['avatar_color'],
        ]]);
    }
    
    public static function logout() {
        Auth::logout();
        jsonResponse(['ok' => true]);
    }
    
    public static function me() {
        $user = Auth::user();
        if (!$user) jsonError('Not logged in', 401);
        // Per-user default landing screen (B1). Falls back to My Tasks.
        $settings = DB::fetch("SELECT default_screen, nav_order FROM user_settings WHERE user_id = ?", [$user['id']]);
        $defaultScreen = $settings['default_screen'] ?? 'mytasks';
        // Per-user sidebar ordering, decoded here so the shell can render it on
        // first paint without a second round trip.
        $navOrder = json_decode((string)($settings['nav_order'] ?? ''), true);
        if (!is_array($navOrder)) $navOrder = new stdClass();
        jsonResponse(['user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'auth_provider' => $user['auth_provider'] ?? 'password',
            'avatar_color' => $user['avatar_color'],
            'initials' => initials($user['name']),
            'default_screen' => $defaultScreen,
            'is_contractor' => (int)($user['is_contractor'] ?? 0) === 1,
            'crm_user_id' => $user['crm_user_id'] ?? null,
            'crm_role' => $user['crm_role'] ?? null,
            'modules' => Authz::grantedModules((int)$user['id'], Auth::workspaceId()),
            'nav_order' => $navOrder,
        ], 'csrf_token' => Csrf::token(),
        'app_version' => defined('APP_VERSION') ? APP_VERSION : null,
        'app_build' => defined('APP_BUILD') ? APP_BUILD : (defined('ASSET_VERSION') ? ASSET_VERSION : null),
        ]);
    }
    
    // ===== SIGN IN WITH MICROSOFT (OIDC authorization-code flow) =====
    public static function microsoftLogin() {
        $cfg = require __DIR__ . '/../../config/graph.php';
        Auth::init();
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        $_SESSION['oauth_nonce'] = bin2hex(random_bytes(16));
        // Mail.Send + offline_access ride along with sign-in so a CRM user can
        // email a lead from their own mailbox without a separate Connect step.
        // See app/lib/MsMail.php.
        require_once __DIR__ . '/../lib/MsMail.php';
        header('Location: ' . $cfg['login_authority'] . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id' => $cfg['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $cfg['redirect_uri'],
            'response_mode' => 'query',
            'scope' => MsMail::SCOPES,
            'state' => $_SESSION['oauth_state'],
            'nonce' => $_SESSION['oauth_nonce'],
        ]));
        exit;
    }
    
    public static function microsoftCallback() {
        $cfg = require __DIR__ . '/../../config/graph.php';
        Auth::init();
        // Constant-time state comparison; require a state was actually issued.
        $expectedState = $_SESSION['oauth_state'] ?? '';
        if ($expectedState === '' || !hash_equals($expectedState, (string)($_GET['state'] ?? ''))) {
            jsonError('Invalid state', 400);
        }
        unset($_SESSION['oauth_state']); // single-use: prevent replay
        $code = $_GET['code'] ?? '';
        if (!$code) jsonError('Missing code', 400);
        
        // Exchange the auth code for tokens. Confidential-client auth uses EITHER a
        // certificate (client-assertion) OR a client secret, matching config 'app_auth'.
        $tokenUrl = $cfg['login_authority'] . '/oauth2/v2.0/token';
        $now = time();
        require_once __DIR__ . '/../lib/MsMail.php';
        $tokenParams = [
            'client_id'    => $cfg['client_id'],
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $cfg['redirect_uri'],
            'scope'        => MsMail::SCOPES,
        ];
        $authMethod = $cfg['app_auth'] ?? 'certificate';
        if ($authMethod === 'secret' && !empty($cfg['client_secret'])) {
            $tokenParams['client_secret'] = $cfg['client_secret'];
        } else {
            $x5t = rtrim(strtr(base64_encode(hex2bin($cfg['cert_thumbprint'])), '+/', '-_'), '=');
            $assertion = JWT::encode([
                'aud' => $tokenUrl,
                'iss' => $cfg['client_id'],
                'sub' => $cfg['client_id'],
                'jti' => bin2hex(random_bytes(16)),
                'nbf' => $now,
                'exp' => $now + 300,
            ], file_get_contents($cfg['cert_key_path']), 'RS256', null, ['x5t' => $x5t]);
            $tokenParams['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
            $tokenParams['client_assertion'] = $assertion;
        }

        $resp = Graph::rawCall('POST', $tokenUrl, http_build_query($tokenParams),
            ['Content-Type: application/x-www-form-urlencoded']);
        
        $d = json_decode($resp['body'], true);
        if (empty($d['id_token'])) jsonError('Login failed', 401);
        
        // Decode id_token payload. The id_token is received here directly from
        // Microsoft's token endpoint over a TLS-verified back-channel (auth-code
        // flow), so it did not pass through the user's browser. We still validate
        // the standard claims as defense in depth against token confusion/replay.
        $parts = explode('.', $d['id_token']);
        if (count($parts) !== 3) jsonError('Login failed: malformed token', 401);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($claims)) jsonError('Login failed: unreadable token', 401);
        // Audience must be this application.
        if (($claims['aud'] ?? null) !== $cfg['client_id']) jsonError('Login failed: wrong audience', 401);
        // Not expired (60s leeway for clock skew).
        if (!isset($claims['exp']) || (int)$claims['exp'] < (time() - 60)) jsonError('Login failed: token expired', 401);
        // Issuer must be a Microsoft identity host.
        $issHost = strtolower(parse_url($claims['iss'] ?? '', PHP_URL_HOST) ?? '');
        if (!in_array($issHost, ['login.microsoftonline.com', 'sts.windows.net'], true)) {
            jsonError('Login failed: untrusted issuer', 401);
        }
        // Nonce binds the token to this login request (blocks replay/injection).
        if (!empty($_SESSION['oauth_nonce'])) {
            if (!isset($claims['nonce']) || !hash_equals($_SESSION['oauth_nonce'], (string)$claims['nonce'])) {
                jsonError('Login failed: nonce mismatch', 401);
            }
        }
        unset($_SESSION['oauth_nonce']);
        $email = strtolower($claims['email'] ?? $claims['preferred_username'] ?? '');
        $oid = $claims['oid'] ?? null;
        if (!$email || !$oid) jsonError('Login failed: missing identity claims', 401);
        
        // Prefer matching by stored oid; fall back to email for first sign-in.
        // Email match is case-insensitive because CRM-migrated users may have
        // mixed-case emails (e.g. Zeina@, Omar@, Asif@, Marina@victorygenomics.com).
        $user = DB::fetch("SELECT * FROM users WHERE ms_oid = ? AND is_active = 1", [$oid]);
        if (!$user) {
            $user = DB::fetch("SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1", [$email]);
            if ($user && empty($user['ms_oid'])) {
                DB::update('users', ['ms_oid' => $oid, 'auth_provider' => 'microsoft'], 'id = ?', [$user['id']]);
            }
        }
        if (!$user) jsonError('No VGo account for ' . $email . '. Ask an admin to add you.', 403);
        
        $wm = DB::fetch("SELECT workspace_id FROM workspace_members WHERE user_id = ? ORDER BY joined_at ASC LIMIT 1", [$user['id']]);
        if (!$wm) jsonError('No workspace assigned. Ask an admin.', 403);
        
        // Keep the delegated mail tokens. Absent consent Microsoft simply returns
        // no refresh_token; store() no-ops and CRM email falls back, so a login
        // must never fail because of this.
        try {
            if (!empty($d['access_token'])) {
                MsMail::store($user['id'], $d, $claims['email'] ?? $claims['preferred_username'] ?? $email);
            }
        } catch (\Throwable $e) {
            error_log('microsoftCallback: storing mail token: ' . $e->getMessage());
        }

        $_SESSION['auth_provider'] = 'microsoft'; // drives edit-button visibility
        $_SESSION['crm_user_id'] = $user['crm_user_id'] ?? null;
        $_SESSION['crm_role'] = $user['crm_role'] ?? null;
        Auth::login($user['id'], $wm['workspace_id']);
        Auth::rememberIssue($user['id'], $wm['workspace_id']);
        header('Location: /');
        exit;
    }
}
