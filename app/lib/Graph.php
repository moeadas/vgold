<?php
// app/lib/Graph.php — Microsoft Graph app-only token service (certificate auth)
require_once __DIR__ . '/../../vendor/autoload.php';
use Firebase\JWT\JWT;

class Graph {
    private static $cfg = null, $token = null, $exp = 0;

    private static function cfg() {
        if (self::$cfg === null) self::$cfg = require __DIR__ . '/../../config/graph.php';
        return self::$cfg;
    }

    /** Cached app-only access token. Supports certificate OR client-secret auth. */
    public static function token() {
        if (self::$token && time() < self::$exp - 120) return self::$token;
        $cfg = self::cfg();
        $now = time();
        $tokenUrl = $cfg['login_authority'] . '/oauth2/v2.0/token';

        // Choose auth method. Default to certificate (as VGO) unless explicitly 'secret'.
        $authMethod = $cfg['app_auth'] ?? 'certificate';
        if ($authMethod === 'secret' && !empty($cfg['client_secret'])) {
            $params = [
                'client_id'     => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'scope'         => 'https://graph.microsoft.com/.default',
                'grant_type'    => 'client_credentials',
            ];
        } else {
            // Certificate (JWT client-assertion) auth.
            $x5t = rtrim(strtr(base64_encode(hex2bin($cfg['cert_thumbprint'])), '+/', '-_'), '=');
            $assertion = JWT::encode([
                'aud' => $tokenUrl,
                'iss' => $cfg['client_id'],
                'sub' => $cfg['client_id'],
                'jti' => bin2hex(random_bytes(16)),
                'nbf' => $now,
                'exp' => $now + 300,
            ], file_get_contents($cfg['cert_key_path']), 'RS256', null, ['x5t' => $x5t]);
            $params = [
                'client_id'             => $cfg['client_id'],
                'scope'                 => 'https://graph.microsoft.com/.default',
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion'      => $assertion,
                'grant_type'            => 'client_credentials',
            ];
        }

        $resp = self::raw('POST', $tokenUrl, http_build_query($params),
            ['Content-Type: application/x-www-form-urlencoded']);

        $d = json_decode($resp['body'], true);
        if (empty($d['access_token'])) throw new Exception('Graph token failed: ' . ($d['error_description'] ?? $resp['body']));
        self::$token = $d['access_token'];
        self::$exp = $now + (int)($d['expires_in'] ?? 3600);
        return self::$token;
    }

    /** Authenticated Graph call. $path relative to graph_base unless $absolute. */
    public static function request($method, $path, $body = null, $headers = [], $absolute = false) {
        $cfg = self::cfg();
        $url = $absolute ? $path : $cfg['graph_base'] . $path;
        $headers[] = 'Authorization: Bearer ' . self::token();
        return self::raw($method, $url, $body, $headers);
    }

    /** Unauthenticated raw HTTP call (no app-only Bearer token added).
     *  Used by the OIDC login code-exchange, which must NOT carry an app token. */
    public static function rawCall($method, $url, $body = null, $headers = []) {
        return self::raw($method, $url, $body, $headers);
    }

    private static function raw($method, $url, $body, $headers) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch);
        if ($out === false) { $e = curl_error($ch); curl_close($ch); throw new Exception('HTTP error: ' . $e); }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $out];
    }
}