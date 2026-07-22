<?php
/**
 * Shared guard for one-off migration scripts (crm/migrate_*.php).
 *
 * These scripts run schema DDL and seed data. They must NEVER be executable by
 * an anonymous web visitor. This guard allows execution only from the CLI or
 * by an authenticated Admin session; everything else gets a 403.
 *
 * Include it as the first thing after the opening docblock:
 *     require_once __DIR__ . '/includes/migration-guard.php';
 */

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/auth.php';
    if (function_exists('startSecureSession')) startSecureSession();
    $allowed = function_exists('isLoggedIn') && isLoggedIn()
            && function_exists('hasRole') && hasRole('Admin');
    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden — this migration requires an Admin login or CLI execution.\n";
        exit;
    }
}
