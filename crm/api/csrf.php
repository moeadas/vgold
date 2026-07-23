<?php
/**
 * Victory Genomics CRM — CSRF token bridge for the native SPA.
 *
 * The native VGold SPA views call the legacy /crm/api/*.php endpoints directly
 * (through the unified session established by mount.php). Those endpoints verify
 * a legacy CSRF token in the JSON body. This endpoint hands the current token to
 * the SPA so native module writes can authorize without an embedded page.
 *
 * GET  /crm/api/csrf.php  →  { success: true, token: "<csrf>" }
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('X-LiteSpeed-Cache-Control: no-cache');

startSecureSession();

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

echo json_encode(['success' => true, 'token' => generateCSRFToken()]);
