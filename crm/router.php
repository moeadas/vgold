<?php
/**
 * Router for PHP built-in development server.
 * Usage: php -S 0.0.0.0:8080 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rawurldecode($uri);
$file = __DIR__ . $uri;

// Serve static files directly
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    // Set correct MIME types
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $mimeTypes = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'json' => 'application/json',
        'csv'  => 'text/csv',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    return false; // Let PHP's built-in server handle it
}

// Route to index / default page
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/login.php';
    return;
}

// Route .php files
if (preg_match('/\.php$/', $uri) && file_exists($file)) {
    require $file;
    return;
}

// If no matching file, return 404
http_response_code(404);
echo '404 Not Found';
