<?php
require_once __DIR__ . '/Crypto.php';

/**
 * Which rows in the settings table are credentials, and how they are stored.
 *
 * The asymmetry here is the whole point. Crypto::decrypt passes a value that
 * is not encrypted straight back, so calling fromStorage() on a row that has
 * not been migrated yet still yields the right answer — which means a read
 * site can be converted before the data is, and in any order. A write site
 * that is missed leaves a value in plaintext: bad, but readable and fixable.
 * A read site that is missed hands the caller ciphertext and breaks sending
 * mail or placing calls. So: decrypt everywhere, encrypt where it matters.
 *
 * The list is deliberately the three values that are genuinely secret and that
 * the admin UI already refuses to echo back. Account SIDs and API key IDs are
 * identifiers, are rendered into the settings form, and encrypting them would
 * put ciphertext on screen.
 */
class Secrets
{
    const SETTING_KEYS = ['smtp_password', 'twilio_auth_token', 'twilio_api_secret'];

    public static function isSecret($key)
    {
        return in_array((string)$key, self::SETTING_KEYS, true);
    }

    /** Value as it should be written to the settings table. */
    public static function forStorage($key, $value)
    {
        if (!self::isSecret($key)) return $value;
        if (!is_string($value) || $value === '') return $value;
        return Crypto::encrypt($value);
    }

    /** Value as the application should use it. Safe on plaintext. */
    public static function fromStorage($key, $value)
    {
        if (!self::isSecret($key)) return $value;
        return Crypto::decrypt($value);
    }

    /** Decrypt the secrets in a key => value map, leaving everything else alone. */
    public static function fromStorageMap(array $rows)
    {
        foreach (self::SETTING_KEYS as $k) {
            if (array_key_exists($k, $rows)) $rows[$k] = Crypto::decrypt($rows[$k]);
        }
        return $rows;
    }

    /**
     * Encrypt any secret still sitting in the table as plaintext.
     *
     * Idempotent: Crypto::encrypt refuses to double-encrypt, and rows already
     * carrying the prefix are skipped outright. Returns the number rewritten.
     */
    public static function encryptStoredSettings($table = 'crm_settings')
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return 0;
        $done = 0;
        foreach (self::SETTING_KEYS as $key) {
            try {
                $row = DB::fetch("SELECT setting_value FROM `$table` WHERE setting_key = ?", [$key]);
            } catch (\Throwable $e) {
                return $done; // table absent on this install; nothing to do
            }
            if (!$row) continue;
            $value = (string)$row['setting_value'];
            if ($value === '' || strpos($value, Crypto::PREFIX) === 0) continue;

            $cipher = Crypto::encrypt($value);
            // Never write something that will not read back. If the key is
            // wrong or openssl is unavailable, encrypt() returns the input
            // unchanged, and rewriting it would be a pointless no-op at best.
            if ($cipher === $value || Crypto::decrypt($cipher) !== $value) {
                error_log('Secrets: refusing to encrypt ' . $key . ' — it would not decrypt back');
                continue;
            }
            DB::query("UPDATE `$table` SET setting_value = ? WHERE setting_key = ?", [$cipher, $key]);
            $done++;
        }
        return $done;
    }
}
