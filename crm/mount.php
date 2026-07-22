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

// Serve CRM static assets (css/js/images/fonts) and uploaded media directly,
// no auth needed. Media URLs under /crm/uploads/* are shared with external
// services (e.g. WhatsApp) so they must be publicly fetchable.
if ($target && is_file($target) && preg_match('/\.(css|js|svg|png|jpe?g|gif|ico|webp|woff2?|ttf|map|pdf|mp4|mp3|ogg|wav|webm|doc|docx|xls|xlsx|ppt|pptx)$/i', $path)) {
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $types = [
        'css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml',
        'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
        'webp'=>'image/webp','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2',
        'ttf'=>'font/ttf','map'=>'application/json','pdf'=>'application/pdf',
        'mp4'=>'video/mp4','mp3'=>'audio/mpeg','ogg'=>'audio/ogg','wav'=>'audio/wav',
        'webm'=>'video/webm',
    ];
    if (isset($types[$ext])) header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: public, max-age=3600');
    readfile($target);
    exit;
}

// ── Public integration endpoints ───────────────────────────────────────────
// These are called by third parties (lead-capture forms, Twilio VoIP, WhatsApp
// Cloud API, the sheets cron) WITHOUT a VGold session. They authenticate
// themselves via their own secret/key/signature, so they must bypass the
// unified-session gate. They still run through the bridge (already loaded above)
// so the CRM table-rewrite + unified DB apply. Fine-grained action gating is
// handled inside each endpoint (e.g. whatsapp.php/voip.php public-action branch).
$publicEndpoints = [
    'api/leads-webhook.php',      // inbound leads (?key=)
    'api/cron-sync.php',          // sheets cron (?secret=)
    'api/microsoft-callback.php', // per-user email OAuth redirect
    'api/whatsapp.php',           // WA verify + inbound webhook (public actions)
    'api/voip.php',               // Twilio status/voice callbacks (public actions)
];
$isPublicEndpoint = in_array($path, $publicEndpoints, true);

// Everything else requires an authenticated, CRM-linked session.
if (!$bridgeOk && !$isPublicEndpoint) {
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

// 3. Run the CRM page from within ITS OWN directory so both styles of relative
//    require resolve: pages in crm/pages/ do `require '../includes/…'`, while
//    endpoints in crm/api/ do `require __DIR__.'/../…'`. chdir'ing to the
//    target file's directory (not always crmRoot) makes cwd-relative requires
//    resolve exactly as they would when the file is hit directly on the server.
//
//    We also rewrite root-absolute CRM URLs (href="/…", src="/…", action="/…",
//    fetch('/api/…'), location='/…') to live under the /crm mount, so the
//    legacy pages keep working unchanged inside the VGold shell. We only touch
//    URLs that resolve to real CRM paths (assets, pages, api, dashboard,
//    logout, includes) to avoid clobbering links into the VGold SPA.
chdir(dirname($target));

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

