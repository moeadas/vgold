<?php
// Mounts the complete original CRM under /crm/* while sharing VGold identity,
// database, module permissions, and the surrounding VGold application shell.
require_once __DIR__ . '/includes/vgold_bridge.php';
require_once __DIR__ . '/../app/lib/Authz.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = ltrim(preg_replace('#^/crm/?#', '', $uri), '/');
if ($path === '') $path = 'dashboard.php';
$path = str_replace(['..', "\0"], '', $path);
$crmRoot = __DIR__;
$target = realpath($crmRoot . '/' . $path);
$crmTopSegments = 'assets|pages|api|dashboard\.php|logout\.php|login\.php|includes|profile\.php|index\.php';

// CRM-owned static files and uploaded media remain addressable below /crm.
if ($target && is_file($target) && preg_match('/\.(css|js|svg|png|jpe?g|gif|ico|webp|woff2?|ttf|map|pdf|mp4|mp3|ogg|wav|webm|doc|docx|xls|xlsx|ppt|pptx)$/i', $path)) {
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $types = [
        'css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml',
        'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
        'webp'=>'image/webp','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2',
        'ttf'=>'font/ttf','map'=>'application/json','pdf'=>'application/pdf',
        'mp4'=>'video/mp4','mp3'=>'audio/mpeg','ogg'=>'audio/ogg','wav'=>'audio/wav','webm'=>'video/webm',
    ];
    if (isset($types[$ext])) header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: public, max-age=3600');
    if ($ext === 'js') {
        $script = file_get_contents($target);
        $script = preg_replace('#(["\'`])/(' . $crmTopSegments . ')#i', '$1/crm/$2', $script);
        echo $script;
    } else {
        readfile($target);
    }
    exit;
}

$publicEndpoints = [
    'api/leads-webhook.php', 'api/cron-sync.php', 'api/microsoft-callback.php',
];
$publicEmailActions = ['track_open','track_click','unsubscribe','unsubscribe_confirm'];
$publicVoipActions = ['twiml','twiml_outbound','twiml_conference','call_status','dial_status','recording_status'];
$publicWhatsAppActions = ['webhook','status'];
$isPublicEndpoint = in_array($path, $publicEndpoints, true)
    || ($path === 'api/email.php' && in_array($_GET['action'] ?? '', $publicEmailActions, true))
    || ($path === 'api/voip.php' && in_array($_GET['action'] ?? '', $publicVoipActions, true))
    || ($path === 'api/whatsapp.php' && in_array($_GET['action'] ?? '', $publicWhatsAppActions, true));
$bridgeOk = !empty($GLOBALS['__VGOLD_BRIDGE_ACTIVE']);
if (!$bridgeOk && !$isPublicEndpoint) {
    header('Location: /?next=' . rawurlencode($uri));
    exit;
}

if (!$target || !is_file($target) || strpos($target, $crmRoot) !== 0 || !preg_match('/\.php$/', $path)) {
    http_response_code(404);
    echo 'CRM: Not Found';
    exit;
}
if (preg_match('/^(migrate_|mount\.php|router\.php)/', basename($target))) {
    http_response_code(403);
    echo 'CRM: Forbidden';
    exit;
}

// Enforce the same per-user module matrix on legacy pages and their APIs.
if (!$isPublicEndpoint) {
    $module = 'crm.dashboard';
    if (preg_match('#(?:^|/)(?:leads|lead-form|lead-detail|import-leads|export-leads)\.php$#', $path) || $path === 'api/leads.php') $module = 'crm.leads';
    elseif (preg_match('#(?:^|/)interactions\.php$#', $path)) $module = 'crm.interactions';
    elseif (strpos($path, 'proposal') !== false) $module = 'crm.proposals';
    elseif (strpos($path, 'email') !== false || $path === 'api/send-email.php') $module = 'crm.email';
    elseif (strpos($path, 'voip') !== false || strpos($path, 'whatsapp') !== false || $path === 'api/upload-media.php') $module = 'crm.communications';
    elseif (strpos($path, 'automation') !== false) $module = 'crm.automation';
    elseif (strpos($path, 'report') !== false || strpos($path, 'export') !== false || strpos($path, 'sheets') !== false) $module = 'crm.reports';
    elseif (strpos($path, 'knowledge') !== false || strpos($path, 'quick-guides') !== false) $module = 'crm.knowledge';
    elseif (preg_match('#(?:^|/)(?:users|user-form|settings)\.php$#', $path)) {
        Auth::requireAdmin();
        $module = null;
    }
    if ($module && !Authz::hasModuleAccess($module)) {
        http_response_code(403);
        if (strpos($path, 'api/') === 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'This CRM module is not enabled for your account.']);
        } else {
            echo '<!doctype html><meta charset="utf-8"><style>body{font:15px system-ui;padding:32px;color:#3d2e22}</style><h2>Module access required</h2><p>Ask a VGold administrator to enable this CRM module.</p>';
        }
        exit;
    }
    $vgoldUser = Auth::user();
    if ($vgoldUser && $vgoldUser['role'] === 'admin') {
        $_SESSION['role'] = 'Admin';
    } elseif (in_array($module, ['crm.proposals','crm.email','crm.automation','crm.reports','crm.knowledge'], true)) {
        // The original CRM hard-coded these screens to manager roles. In VGold,
        // the explicit module grant is authoritative, so grant the minimum
        // legacy role needed to execute the already-authorized screen/API.
        $_SESSION['role'] = 'Sales Manager';
    }
}

// Any CRM write that can create/update a customer interaction is reconciled
// with Workflow after the legacy handler finishes (including handlers that exit
// after issuing a redirect).
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && preg_match('#(?:interactions|lead-detail|voip|whatsapp|automation)#i', $path)) {
    require_once __DIR__ . '/../app/lib/CRMTaskBridge.php';
    register_shutdown_function(function () {
        try { CRMTaskBridge::syncAll(); }
        catch (Throwable $e) { error_log('CRM/Workflow shutdown sync: ' . $e->getMessage()); }
    });
}

if (!empty($_GET['embedded'])) $_SESSION['vgold_crm_embedded'] = true;
$embedded = !empty($_SESSION['vgold_crm_embedded']);
chdir(dirname($target));

register_shutdown_function(function () use ($crmTopSegments) {
    foreach (headers_list() as $headerLine) {
        if (stripos($headerLine, 'Location:') !== 0) continue;
        $location = trim(substr($headerLine, strlen('Location:')));
        if (preg_match('#^/(' . $crmTopSegments . ')(.*)$#i', $location, $match) && strpos($location, '/crm/') !== 0) {
            header_remove('Location');
            header('Location: /crm/' . $match[1] . $match[2]);
        }
    }
});

ob_start(function ($html) use ($crmTopSegments, $embedded) {
    if ($html === '' || stripos($html, '<') === false) return $html;
    $html = preg_replace('#(\b(?:href|src|action)\s*=\s*["\'])/(' . $crmTopSegments . ')#i', '$1/crm/$2', $html);
    $html = preg_replace('#(["\'`])/(' . $crmTopSegments . ')#i', '$1/crm/$2', $html);
    $html = preg_replace('#(/crm/assets/[^"\']+?\.(?:css|js))(?:\?[^"\']*)?#i', '$1?v=20260721f', $html);
    if ($embedded && stripos($html, '</head>') !== false) {
        $style = '<style id="vgold-embedded-crm">'
            . '.sidebar,.sidebar-backdrop,.mobile-menu-toggle,.notif-bell-wrap{display:none!important}'
            . 'html,body{background:#fbfaf7!important;min-height:100%!important}'
            . '.main-content{margin-left:0!important;width:auto!important;max-width:none!important;min-height:100vh!important;padding:20px 22px 42px!important}'
            . '@media(max-width:768px){.main-content{padding:14px!important}}'
            . '</style>';
        $html = str_ireplace('</head>', $style . '</head>', $html);
    }
    return $html;
});

require $target;
