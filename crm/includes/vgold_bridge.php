<?php
if (defined('VGOLD_BRIDGE_LOADED')) return;
define('VGOLD_BRIDGE_LOADED', true);

$vgoldRoot = dirname(__DIR__, 2);
$appConfig = $vgoldRoot . '/config/app.php';
$dbLib = $vgoldRoot . '/app/lib/DB.php';
$authLib = $vgoldRoot . '/app/lib/Auth.php';
if (!is_file($appConfig) || !is_file($dbLib) || !is_file($authLib)) return;

require_once $appConfig;
require_once $dbLib;
require_once $authLib;
if (!defined('CRM_BASE')) define('CRM_BASE', '/crm');
Auth::init();
$GLOBALS['__VGOLD_BRIDGE_ACTIVE'] = Auth::check() && Auth::bridgeToCrm();
