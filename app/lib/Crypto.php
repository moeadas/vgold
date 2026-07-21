<?php
// Symmetric encryption for secrets at rest (H6): SMTP passwords, provider API keys.
// Uses AES-256-GCM with a key from config/app_key.php (gitignored). Encrypted values
// are stored with an "enc:v1:" prefix so decrypt() can transparently pass through any
// legacy plaintext values that were saved before encryption was introduced.
class Crypto {
    const PREFIX = 'enc:v1:';
    private static $key = null;

    private static function key() {
        if (self::$key !== null) return self::$key;

        $keyFile = __DIR__ . '/../../config/app_key.php';
        $raw = null;
        if (file_exists($keyFile)) {
            $raw = require $keyFile; // should return a long random string
        }
        // Fallback: derive a stable key from DB credentials so encryption still
        // works if the operator hasn't created app_key.php yet. Creating a
        // dedicated config/app_key.php is strongly recommended.
        if (!is_string($raw) || strlen($raw) < 16) {
            $raw = 'vgo|' . (defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('DB_NAME') ? DB_NAME : '');
        }
        self::$key = hash('sha256', $raw, true); // 32-byte key
        return self::$key;
    }

    public static function encrypt($plaintext) {
        if ($plaintext === null || $plaintext === '') return $plaintext;
        // Avoid double-encrypting
        if (is_string($plaintext) && strpos($plaintext, self::PREFIX) === 0) return $plaintext;

        $iv = random_bytes(12); // GCM nonce
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) return $plaintext; // fail-safe: never lose the value
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt($stored) {
        if (!is_string($stored) || $stored === '') return $stored;
        // Legacy plaintext (pre-encryption) — return unchanged
        if (strpos($stored, self::PREFIX) !== 0) return $stored;

        $blob = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($blob === false || strlen($blob) < 28) return '';
        $iv = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $cipher = substr($blob, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
}
