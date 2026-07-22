<?php
// HTML escape helper
if (!function_exists('esc')) {
    function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

// VGold Application Configuration
// Unified ERP: VGo workflow management + Victory Genomics CRM in one app.
if (defined('APP_NAME')) { return; }
define('APP_NAME', 'VGold');

// Auto-detect environment
$isSiteGround = file_exists(__DIR__ . '/database.sg.php') && isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'victorygenomics.com') !== false;

if ($isSiteGround) {
    define('APP_URL', 'https://vgold.victorygenomics.com');
    define('APP_ENV', 'production');
    define('APP_DEBUG', false);
    $dbConfig = require __DIR__ . '/database.sg.php';
} else {
    define('APP_URL', 'http://localhost:8080');
    define('APP_ENV', 'development');
    define('APP_DEBUG', true);
    $dbConfig = require __DIR__ . '/database.php';
}

// Bump this on each deploy to bust browser caches for CSS/JS (M4).
define('ASSET_VERSION', '2026.07.21.3');

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
