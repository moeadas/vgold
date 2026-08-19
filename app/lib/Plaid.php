<?php
/**
 * Thin Plaid REST client.
 *
 * Deliberately not the official SDK: VGo runs on LiteSpeed shared hosting with
 * no Composer and no build step, and everything Plaid needs is JSON over HTTPS
 * plus one ES256 signature check — both native here. Adding a dependency tree
 * would buy nothing and cost us the ability to deploy by copying files.
 *
 * Credentials live in config/plaid.local.php, which is ABOVE the docroot
 * (docroot is public/) and gitignored. Nothing in this class may ever echo,
 * log, or return a secret or an access_token.
 */
class Plaid
{
    private static $cfg = null;

    // ===== configuration =====

    public static function configPath(): string
    {
        return dirname(__DIR__, 2) . '/config/plaid.local.php';
    }

    public static function config(): array
    {
        if (self::$cfg !== null) return self::$cfg;
        $p = self::configPath();
        $c = is_file($p) ? (require $p) : [];
        if (!is_array($c)) $c = [];
        self::$cfg = $c + [
            'client_id' => '', 'env' => 'sandbox', 'secrets' => [],
            'redirect_uri' => '', 'webhook_uri' => '', 'token_key' => '',
        ];
        return self::$cfg;
    }

    /** Forget the cached config — call after writing new credentials. */
    public static function reloadConfig(): void { self::$cfg = null; }

    public static function env(): string
    {
        $e = (string)(self::config()['env'] ?? 'sandbox');
        return $e === 'production' ? 'production' : 'sandbox';
    }

    private static function secret(): string
    {
        $c = self::config();
        return (string)($c['secrets'][self::env()] ?? '');
    }

    /**
     * Is Plaid usable right now? Returned to the UI so it can explain what is
     * missing instead of failing at the first API call.
     */
    public static function status(): array
    {
        $c = self::config();
        return [
            'configured'   => $c['client_id'] !== '' && self::secret() !== '',
            'env'          => self::env(),
            'has_client'   => $c['client_id'] !== '',
            'has_secret'   => self::secret() !== '',
            'has_prod_key' => (string)($c['secrets']['production'] ?? '') !== '',
            'redirect_uri' => (string)$c['redirect_uri'],
            'webhook_uri'  => (string)$c['webhook_uri'],
            // A fingerprint, never the value — enough to confirm which key is
            // loaded when two people are looking at the same screen.
            'client_hint'  => $c['client_id'] !== ''
                ? substr($c['client_id'], 0, 6) . '…' . substr($c['client_id'], -4) : '',
        ];
    }

    // ===== transport =====

    /**
     * POST to Plaid. Returns [ok, decodedBody, httpCode].
     *
     * Plaid signals failure with a 4xx AND an error_code in the body, so both
     * are folded into one `ok`. Network failures are surfaced as a synthetic
     * error_code so callers never have to distinguish "no reply" from "no".
     */
    public static function call(string $path, array $body, int $timeout = 30): array
    {
        $c = self::config();
        $payload = $body + ['client_id' => $c['client_id'], 'secret' => self::secret()];

        $ch = curl_init('https://' . self::env() . '.plaid.com' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [false, ['error_code' => 'NETWORK_ERROR', 'error_message' => $cerr], 0];
        }
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) {
            return [false, ['error_code' => 'BAD_RESPONSE', 'error_message' => substr((string)$raw, 0, 200)], $code];
        }
        $ok = $code >= 200 && $code < 300 && empty($j['error_code']);
        return [$ok, $j, $code];
    }

    /** Human-usable one-liner for an error body, safe to show a user. */
    public static function errText(array $j): string
    {
        $code = (string)($j['error_code'] ?? 'UNKNOWN');
        $msg  = (string)($j['error_message'] ?? '');
        return $msg !== '' ? "$code: $msg" : $code;
    }

    // ===== access_token encryption at rest =====
    //
    // A Plaid access_token is a bearer credential for a real bank feed. Storing
    // it in plaintext would mean a database dump alone is enough to read the
    // company's bank data, so it is sealed with a key that lives only in the
    // file above the docroot. Losing that key costs a reconnect, nothing worse.

    private static function key(): string
    {
        $k = (string)(self::config()['token_key'] ?? '');
        if ($k === '') return '';
        return hash('sha256', 'vgo|plaid|' . $k, true);   // 32 raw bytes
    }

    public static function sealToken(string $plain): string
    {
        $key = self::key();
        if ($key === '' || $plain === '') return $plain;
        if (function_exists('sodium_crypto_secretbox')) {
            $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 'v1s:' . base64_encode($n . sodium_crypto_secretbox($plain, $n, $key));
        }
        $iv = random_bytes(12); $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1g:' . base64_encode($iv . $tag . $ct);
    }

    public static function openToken(string $stored): string
    {
        $key = self::key();
        if ($stored === '') return '';
        // Tokens written before a key existed stay readable.
        if (strncmp($stored, 'v1s:', 4) !== 0 && strncmp($stored, 'v1g:', 4) !== 0) return $stored;
        if ($key === '') return '';
        $blob = base64_decode(substr($stored, 4), true);
        if ($blob === false) return '';
        try {
            if (strncmp($stored, 'v1s:', 4) === 0) {
                $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                $out = sodium_crypto_secretbox_open(substr($blob, $n), substr($blob, 0, $n), $key);
                return $out === false ? '' : $out;
            }
            $out = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', $key,
                OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16));
            return $out === false ? '' : $out;
        } catch (\Throwable $e) { return ''; }
    }

    // ===== webhook signature verification =====

    /**
     * Verify a Plaid webhook. Returns [ok, reason].
     *
     * Four things must hold, and all four matter:
     *   1. the Plaid-Verification header is an ES256 JWT
     *   2. its signature checks out against the JWK Plaid gives us for that kid
     *   3. it was issued less than 5 minutes ago (replay)
     *   4. its request_body_sha256 matches the RAW body byte-for-byte
     *
     * (4) is why the raw body must be passed in unmodified — re-encoding the
     * JSON changes the hash and every webhook would be rejected.
     */
    public static function verifyWebhook(string $rawBody, string $jwtHeader): array
    {
        if ($jwtHeader === '') return [false, 'missing Plaid-Verification header'];
        $parts = explode('.', $jwtHeader);
        if (count($parts) !== 3) return [false, 'malformed JWT'];
        [$h64, $p64, $s64] = $parts;

        $hdr = json_decode(self::b64url($h64), true);
        if (!is_array($hdr)) return [false, 'unreadable JWT header'];
        if (($hdr['alg'] ?? '') !== 'ES256') return [false, 'unexpected alg ' . (string)($hdr['alg'] ?? '')];
        $kid = (string)($hdr['kid'] ?? '');
        if ($kid === '') return [false, 'no kid'];

        [$ok, $j] = self::call('/webhook_verification_key/get', ['key_id' => $kid], 15);
        if (!$ok) return [false, 'key fetch failed: ' . self::errText($j)];
        $jwk = $j['key'] ?? null;
        if (!is_array($jwk) || ($jwk['kty'] ?? '') !== 'EC') return [false, 'bad JWK'];

        $pem = self::jwkToPem($jwk);
        if ($pem === '') return [false, 'JWK -> PEM failed'];

        // ES256 signatures are raw r||s; OpenSSL wants DER.
        $der = self::rawSigToDer(self::b64url($s64));
        if ($der === '') return [false, 'bad signature encoding'];
        if (openssl_verify($h64 . '.' . $p64, $der, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return [false, 'signature mismatch'];
        }

        $pl = json_decode(self::b64url($p64), true);
        if (!is_array($pl)) return [false, 'unreadable JWT payload'];
        $iat = (int)($pl['iat'] ?? 0);
        if ($iat <= 0 || (time() - $iat) > 300) return [false, 'stale webhook (iat)'];

        $claimed = (string)($pl['request_body_sha256'] ?? '');
        if ($claimed === '') return [false, 'no body hash claim'];
        if (!hash_equals($claimed, hash('sha256', $rawBody))) return [false, 'body hash mismatch'];

        return [true, 'ok'];
    }

    private static function b64url(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** Build a PEM public key from a P-256 JWK (x/y are base64url, 32 bytes each). */
    private static function jwkToPem(array $jwk): string
    {
        $x = self::b64url((string)($jwk['x'] ?? ''));
        $y = self::b64url((string)($jwk['y'] ?? ''));
        if (strlen($x) !== 32 || strlen($y) !== 32) return '';
        // SPKI prefix for id-ecPublicKey + prime256v1, then the uncompressed point.
        $der = base64_decode('MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE') . $x . $y;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** JOSE raw r||s (64 bytes) -> ASN.1 DER SEQUENCE of two INTEGERs. */
    private static function rawSigToDer(string $raw): string
    {
        if (strlen($raw) !== 64) return '';
        $int = function (string $v): string {
            $v = ltrim($v, "\x00");
            if ($v === '') $v = "\x00";
            if (ord($v[0]) > 0x7f) $v = "\x00" . $v;      // keep it positive
            return "\x02" . chr(strlen($v)) . $v;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));
        return "\x30" . chr(strlen($seq)) . $seq;
    }
}
