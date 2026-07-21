<?php
/**
 * VGold CRM mount controller (Phase 3).
 *
 * Public entry (public/index.php) forwards every /crm/* request here. This
 * script:
 *   1. Boots the VGold↔CRM bridge (unified config, DB, session).
 *   2. Requires a signed-in VGold user linked to a CRM account; otherwise it
 *      redirects to the VGold SPA login (there is only ONE login now).
 *   3. Maps /crm/<path> onto the physical file inside crm/ and includes it,
 *      running it from the crm/ working directory so its relative
 *      `require 'includes/…'` paths and asset URLs keep working.
 *
 * Legacy CRM pages call startSecureSession()/requireLogin() from
 * crm/includes/auth.php; those are bridge-aware (see that file) and become
 * no-ops that trust the already-established unified session.
 */

// 1. Establish the unified bridge (defines VGOLD_BRIDGE_LOADED, boots Auth).
require_once __DIR__ . '/includes/vgold_bridge.php';

// 2. Gatekeeping — must be a signed-in, CRM-linked VGold user.
$bridgeOk = !empty($GLOBALS['__VGOLD_BRIDGE_ACTIVE']);

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/crm/?#', '', $uri);           // strip the /crm prefix
$path = ltrim($path, '/');
if ($path === '') $path = 'dashboard.php';             // default landing page

// Prevent path traversal; resolve against the crm/ directory.
$path = str_replace(['..', "\0"], '', $path);
$crmRoot = __DIR__;
$target  = realpath($crmRoot . '/' . $path);

// Serve CRM static assets (css/js/images/fonts) directly, no auth needed.
if ($target && is_file($target) && preg_match('/\.(css|js|svg|png|jpe?g|gif|ico|woff2?|ttf|map)$/i', $path)) {
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $types = [
        'css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml',
        'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
        'ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf',
        'map'=>'application/json',
    ];
    if (isset($types[$ext])) header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: public, max-age=3600');
    readfile($target);
    exit;
}

// Everything else requires an authenticated, CRM-linked session.
if (!$bridgeOk) {
    // Not signed in (or not CRM-linked): send to the unified SPA login.
    header('Location: /?next=' . rawurlencode($uri));
    exit;
}

// Only allow .php application routes past this point.
if (!$target || !is_file($target) || strpos($target, $crmRoot) !== 0
        || !preg_match('/\.php$/', $path)) {
    http_response_code(404);
    echo 'CRM: Not Found';
    exit;
}

// Block direct execution of migration / bootstrap scripts via the web mount.
$base = basename($target);
if (preg_match('/^(migrate_|mount\.php|router\.php)/', $base)) {
    http_response_code(403);
    echo 'CRM: Forbidden';
    exit;
}

// 3. Run the CRM page from within crm/ so its relative requires resolve.
//    Rewrite root-absolute CRM URLs (href="/…", src="/…", action="/…",
//    fetch('/api/…'), location='/…') to live under the /crm mount, so the
//    legacy pages keep working unchanged inside the VGold shell. We only touch
//    URLs that resolve to real CRM paths (assets, pages, api, dashboard,
//    logout, includes) to avoid clobbering links into the VGold SPA.
chdir($crmRoot);

$crmTopSegments = 'assets|pages|api|dashboard\.php|logout\.php|login\.php|includes|profile\.php|index\.php';

ob_start(function ($html) use ($crmTopSegments) {
    if ($html === '' || stripos($html, '<') === false) return $html;
    // Attribute URLs: href/src/action="/pages/…" -> "/crm/pages/…"
    $html = preg_replace(
        '#(\b(?:href|src|action)\s*=\s*["\'])/(' . $crmTopSegments . ')#i',
        '$1/crm/$2',
        $html
    );
    // JS string URLs: '/api/…', "/pages/…", `/dashboard.php` in fetch()/location
    $html = preg_replace(
        '#(["\'`])/(' . $crmTopSegments . ')#i',
        '$1/crm/$2',
        $html
    );
    return $html;
});

require $target;

