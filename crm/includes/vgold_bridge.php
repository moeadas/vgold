<?php
/**
 * VGold ⇆ CRM session bridge.
 *
 * When the CRM screens run inside the unified VGold shell (mounted at /crm/*),
 * there is ONE login and ONE session. This bridge boots just enough of the
 * VGold app to (a) share the same DB connection and (b) translate the unified
 * VGold session into the $_SESSION variables the legacy CRM pages expect.
 *
 * It is intentionally defensive: if VGold's libraries can't be loaded (e.g. the
 * CRM is being run entirely standalone, outside the shell), it does nothing and
 * the CRM falls back to its own auth.
 *
 * Define VGOLD_SHELL before including a CRM page to force bridge mode:
 *     define('VGOLD_SHELL', true);
 *     require __DIR__.'/includes/vgold_bridge.php';
 */

if (defined('VGOLD_BRIDGE_LOADED')) return;
define('VGOLD_BRIDGE_LOADED', true);

// Resolve the VGold app root (…/vgold). This file lives at crm/includes/.
$vgoldRoot = dirname(__DIR__, 2);

$appConfig = $vgoldRoot . '/config/app.php';
$dbLib     = $vgoldRoot . '/app/lib/DB.php';
$authLib   = $vgoldRoot . '/app/lib/Auth.php';

// Only engage the bridge if the VGold app is actually present.
if (!is_file($appConfig) || !is_file($dbLib) || !is_file($authLib)) {
    return;
}

require_once $appConfig;   // defines DB_*, APP_*, SESSION_LIFETIME, etc.
require_once $dbLib;
require_once $authLib;

// Start / attach to the unified VGold session (uses VGold's cookie params).
Auth::init();

/**
 * Returns true when a VGold user is signed in AND is linked to a CRM account.
 * Populates the CRM-expected session vars as a side effect.
 */
function vgold_bridge_active() {
    if (!class_exists('Auth') || !Auth::check()) return false;
    return Auth::bridgeToCrm();   // sets $_SESSION user_id/username/email/full_name/role
}

// Engage immediately so any subsequently-included CRM auth helpers see the
// bridged session state.
$GLOBALS['__VGOLD_BRIDGE_ACTIVE'] = vgold_bridge_active();
