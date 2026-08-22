<?php
// VGo Entry Point — routes /api/* to API router, everything else to SPA
require_once __DIR__ . '/../config/app.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// API routes
if (strpos($uri, '/api/') === 0 || $uri === '/api') {
    require __DIR__ . '/../app/router.php';
    exit;
}

// Plaid OAuth return.
//
// Registered with Plaid as /plaid/oauth, which is a VIRTUAL path: the root
// .htaccess only rewrites a URI into public/ when a matching file physically
// exists there, so anything else lands on the SPA shell. Bank of America sends
// the browser back here mid-handshake and it must get the real page, so it is
// dispatched by the front controller rather than by a rewrite rule.
if ($uri === '/plaid/oauth' || $uri === '/plaid/oauth/') {
    require __DIR__ . '/plaid-oauth.php';
    exit;
}

// Full CRM functionality mounted inside the unified VGo identity/database.
if ($uri === '/crm' || strpos($uri, '/crm/') === 0) {
    require __DIR__ . '/../crm/mount.php';
    exit;
}

// Static assets
if (preg_match('/\.(css|js|ico|png|jpg|svg|woff2?)$/', $uri)) {
    // Prevent path traversal
    $safe = str_replace(['..', "\0"], '', $uri);
    $file = realpath(__DIR__ . $safe);
    if ($file !== false && strpos($file, realpath(__DIR__)) === 0 && is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $types = ['css' => 'text/css', 'js' => 'application/javascript', 'ico' => 'image/x-icon', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];
        if (isset($types[$ext])) header('Content-Type: ' . $types[$ext]);
        // Versioned assets can be cached for a long time (M4).
        if (isset($_GET['v'])) {
            header('Cache-Control: public, max-age=31536000, immutable');
        } else {
            header('Cache-Control: public, max-age=3600');
        }
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

// SPA fallback — output HTML directly with cache-busting
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VGo — Victory Genomics ERP</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css?v=' . ASSET_VERSION . '">
<link rel="stylesheet" href="/assets/css/crm-native.css?v=' . ASSET_VERSION . '">
<link rel="stylesheet" href="/assets/css/overrides.css?v=' . ASSET_VERSION . '">
<link rel="stylesheet" href="/assets/css/accounting.css?v=' . ASSET_VERSION . '">
<link rel="stylesheet" href="/assets/css/sales.css?v=' . ASSET_VERSION . '">
<link rel="icon" href="/assets/img/vgo-logo.png">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/img/icon-180.png">
<meta name="theme-color" content="#C99520">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="VGo">
<script>window.VAPID_PUBLIC = "BFPCZ2bBYxkoGSaaaMMRlGmDQhDPvanQIQj-Y01VCB5jIeOhIigQ7GnixutbHEuNo_f089DSZAMg5PciVAGJSJw";
window.ASSET_V = "' . ASSET_VERSION . '";</script>
</head>
<body>
<div id="app"></div>
<div id="toast-container" class="toast-container"></div>
<div id="modal-root"></div>

<script src="/assets/js/icons.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/countries.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/api.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/toast.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/modal.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/edit.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/login.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/sidebar.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/projects.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/project.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/messages.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/mytasks.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/taskoverview.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/priorities.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/settings.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/crm.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/crm-modules.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/customers.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/sales.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/assignee-picker.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/modals.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/taskpage.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/accounting.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/accounting2.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/acc-billscan.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/acc-bankfeed.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/acc-plaid.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/contractor-invoices.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/views/backups.js?v=' . ASSET_VERSION . '"></script>
<script src="/assets/js/app.js?v=' . ASSET_VERSION . '"></script>
</body>
</html>';
