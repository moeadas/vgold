<?php
require_once __DIR__ . '/../lib/Graph.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../../vendor/autoload.php';
use Firebase\JWT\JWT;

class AuthController {
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
    
    public static function login() {
        $data = input();
        requireFields(['email', 'password'], $data);
        // Accept EITHER an email or a legacy CRM username as the identifier, so
        // external (non-Microsoft) users migrated from the CRM can sign in with
        // whichever they remember. Matching is case-insensitive.
        $identifier = trim($data['email']);
        $identifierLc = strtolower($identifier);
        $password = $data['password'];
        
        // Rate limiting: max 5 attempts per 15 min per IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
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
        
        // Resolve by email first, then by carried-over CRM username.
        $user = DB::fetch("SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1", [$identifierLc]);
        if (!$user) {
            $user = DB::fetch("SELECT * FROM users WHERE LOWER(crm_username) = ? AND is_active = 1", [$identifierLc]);
        }
        if (!$user || !password_verify($password, $user['password'])) {
            $_SESSION[$key] = $attempts + 1;
            if (!isset($_SESSION[$key . '_time'])) $_SESSION[$key . '_time'] = time();
            jsonError('Invalid credentials', 401);
        }
        
        $wm = DB::fetch("SELECT workspace_id, role FROM workspace_members WHERE user_id = ? ORDER BY joined_at ASC LIMIT 1", [$user['id']]);
        if (!$wm) jsonError('No workspace found', 403);
        
        // Reset rate limit on success
        unset($_SESSION[$key]);
        unset($_SESSION[$key . '_time']);
        
        // Set auth_provider + CRM linkage in session from the user's DB record.
        $_SESSION['auth_provider'] = $user['auth_provider'] ?? 'password';
        $_SESSION['crm_user_id']   = $user['crm_user_id'] ?? null;
        $_SESSION['crm_role']      = $user['crm_role'] ?? null;
        
        Auth::login($user['id'], $wm['workspace_id']);
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
        $settings = DB::fetch("SELECT default_screen FROM user_settings WHERE user_id = ?", [$user['id']]);
        $defaultScreen = $settings['default_screen'] ?? 'mytasks';
        jsonResponse(['user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'auth_provider' => $user['auth_provider'] ?? 'password',
            'avatar_color' => $user['avatar_color'],
            'initials' => initials($user['name']),
            'default_screen' => $defaultScreen,
            'crm_user_id' => $user['crm_user_id'] ?? null,
            'crm_role' => $user['crm_role'] ?? null,
        ], 'csrf_token' => Csrf::token()]);
    }
    
    // ===== SIGN IN WITH MICROSOFT (OIDC authorization-code flow) =====
    public static function microsoftLogin() {
        $cfg = require __DIR__ . '/../../config/graph.php';
        Auth::init();
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        header('Location: ' . $cfg['login_authority'] . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id' => $cfg['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $cfg['redirect_uri'],
            'response_mode' => 'query',
            'scope' => 'openid profile email User.Read',
            'state' => $_SESSION['oauth_state'],
        ]));
        exit;
    }
    
    public static function microsoftCallback() {
        $cfg = require __DIR__ . '/../../config/graph.php';
        Auth::init();
        if (($_GET['state'] ?? '') !== ($_SESSION['oauth_state'] ?? '_')) jsonError('Invalid state', 400);
        $code = $_GET['code'] ?? '';
        if (!$code) jsonError('Missing code', 400);
        
        // Exchange the auth code for tokens. Confidential-client auth uses EITHER a
        // certificate (client-assertion) OR a client secret, matching config 'app_auth'.
        $tokenUrl = $cfg['login_authority'] . '/oauth2/v2.0/token';
        $now = time();
        $tokenParams = [
            'client_id'    => $cfg['client_id'],
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $cfg['redirect_uri'],
            'scope'        => 'openid profile email User.Read',
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

        $resp = Graph::request('POST', $tokenUrl, http_build_query($tokenParams),
            ['Content-Type: application/x-www-form-urlencoded'], true);
        
        $d = json_decode($resp['body'], true);
        if (empty($d['id_token'])) jsonError('Login failed', 401);
        
        // Decode id_token payload
        $parts = explode('.', $d['id_token']);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
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
        if (!$user) jsonError('No VGold account for ' . $email . '. Ask an admin to add you.', 403);
        
        $wm = DB::fetch("SELECT workspace_id FROM workspace_members WHERE user_id = ? ORDER BY joined_at ASC LIMIT 1", [$user['id']]);
        if (!$wm) jsonError('No workspace assigned. Ask an admin.', 403);
        
        $_SESSION['auth_provider'] = 'microsoft'; // drives edit-button visibility
        $_SESSION['crm_user_id']   = $user['crm_user_id'] ?? null;
        $_SESSION['crm_role']      = $user['crm_role'] ?? null;
        Auth::login($user['id'], $wm['workspace_id']);
        header('Location: /');
        exit;
    }
}