<?php
/**
 * CRM-side access to the shared secret helper.
 *
 * The CRM runs two ways — mounted inside VGo, and standalone — so it cannot
 * assume VGo's classes have been loaded. Pull Secrets in on demand.
 *
 * If it is somehow unavailable, both functions return the value untouched.
 * That is the right failure for a read (a row saved before encryption existed
 * is plaintext and works as-is) and a visible, fixable one for a write (the
 * value stays plaintext rather than being lost).
 */
if (!function_exists('crmSecretsReady')) {

    function crmSecretsReady() {
        static $ready = null;
        if ($ready !== null) return $ready;
        if (!class_exists('Secrets')) {
            $f = dirname(__DIR__, 2) . '/app/lib/Secrets.php';
            if (is_file($f)) { require_once $f; }
        }
        return $ready = class_exists('Secrets');
    }

    /** Is this settings key a credential? */
    function crmIsSecret($key) {
        return crmSecretsReady() ? Secrets::isSecret($key) : false;
    }

    /** Stored value -> usable value. Safe to call on anything. */
    function crmSecretValue($key, $value) {
        if (!crmSecretsReady()) return $value;
        try { return Secrets::fromStorage($key, $value); }
        catch (\Throwable $e) { error_log('crmSecretValue: ' . $e->getMessage()); return $value; }
    }

    /** Usable value -> stored value. */
    function crmSecretForStorage($key, $value) {
        if (!crmSecretsReady()) return $value;
        try { return Secrets::forStorage($key, $value); }
        catch (\Throwable $e) { error_log('crmSecretForStorage: ' . $e->getMessage()); return $value; }
    }
}
