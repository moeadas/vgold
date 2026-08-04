<?php
// HTML escape helper
if (!function_exists('esc')) {
    function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

// VGo Application Configuration
// Unified ERP: VGo workflow management + Victory Genomics CRM in one app.
if (defined('APP_NAME')) { return; }
define('APP_NAME', 'VGo');

// Auto-detect environment
// A request on the live host is obviously production; so is a CLI run on the
// server, where there is no HTTP_HOST at all. The old test required HTTP_HOST
// and so fell through to the dev branch on CLI, fataling on a database.php
// that does not exist in production.
$isSiteGround = file_exists(__DIR__ . '/database.sg.php')
    && (!isset($_SERVER['HTTP_HOST']) || strpos($_SERVER['HTTP_HOST'], 'victorygenomics.com') !== false);

if ($isSiteGround) {
    // Canonical host. Deliberately explicit rather than read from HTTP_HOST:
    // links in outgoing email must point here even when the request arrived
    // on the old vgold host, which now 301s to this one.
    define('APP_URL', 'https://vgo.victorygenomics.com');
    define('APP_ENV', 'production');
    define('APP_DEBUG', false);
    $dbConfig = require __DIR__ . '/database.sg.php';
} else {
    define('APP_URL', 'http://localhost:8080');
    define('APP_ENV', 'development');
    define('APP_DEBUG', true);
    $dbConfig = require __DIR__ . '/database.php';
}

// Bare hostname, for the places that need a host rather than a URL (SMTP EHLO,
// Message-ID domains). Derived, so it can never drift out of step with APP_URL.
define('APP_HOST', parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost');

// Bump this on each deploy to bust browser caches for CSS/JS (M4).
define('ASSET_VERSION', '2026.08.04.2');

// Human-readable build number, shown at the top of Settings so you can confirm
// which build is actually live. Bump alongside ASSET_VERSION on every deploy.
define('APP_VERSION', '1.17.1');
define('APP_BUILD', '2026.08.04.2');

define('SESSION_LIFETIME', 604800); // 7 days
define('UPLOAD_PATH', __DIR__ . '/../storage/uploads');
define('MAX_FILE_SIZE', 10485760); // 10MB

// Database
define('DB_HOST', $dbConfig['host']);
define('DB_PORT', $dbConfig['port']);
define('DB_NAME', $dbConfig['name']);
define('DB_USER', $dbConfig['user']);
define('DB_PASS', $dbConfig['pass']);
define('DB_CHARSET', $dbConfig['charset']);
