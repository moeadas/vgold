<?php
/**
 * Victory Genomics CRM - Microsoft OAuth2 Callback
 * Handles the redirect from Microsoft after user authorizes the app.
 * Exchanges auth code for access/refresh tokens and stores them.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
requireLogin();

$userId = getCurrentUserId();

// Check for errors from Microsoft
if (isset($_GET['error'])) {
    $errorDesc = $_GET['error_description'] ?? $_GET['error'];
    $_SESSION['error'] = "Microsoft authorization failed: " . htmlspecialchars($errorDesc);
    header('Location: /pages/profile.php');
    exit;
}

// Verify state parameter (CSRF protection)
$state = $_GET['state'] ?? '';
$expectedState = $_SESSION['ms_oauth_state'] ?? '';
if (empty($state) || !hash_equals($expectedState, $state)) {
    $_SESSION['error'] = "Invalid OAuth state. Please try connecting again.";
    header('Location: /pages/profile.php');
    exit;
}
unset($_SESSION['ms_oauth_state']);

// Get the authorization code
$code = $_GET['code'] ?? '';
if (empty($code)) {
    $_SESSION['error'] = "No authorization code received from Microsoft.";
    header('Location: /pages/profile.php');
    exit;
}

// Exchange code for tokens
$tokenUrl = 'https://login.microsoftonline.com/' . MS_TENANT_ID . '/oauth2/v2.0/token';

$postData = [
    'client_id'     => MS_CLIENT_ID,
    'client_secret' => MS_CLIENT_SECRET,
    'code'          => $code,
    'redirect_uri'  => MS_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
    'scope'         => 'https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read offline_access',
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $tokenUrl,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    $_SESSION['error'] = "Network error connecting to Microsoft: " . $curlError;
    header('Location: /pages/profile.php');
    exit;
}

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    $errorMsg = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Unknown error';
    error_log("Microsoft OAuth token error: " . $response);
    $_SESSION['error'] = "Failed to get access token: " . htmlspecialchars($errorMsg);
    header('Location: /pages/profile.php');
    exit;
}

$accessToken  = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? '';
$expiresIn    = intval($tokenData['expires_in'] ?? 3600);
$expiresAt    = date('Y-m-d H:i:s', time() + $expiresIn);

// Get user's email from Microsoft Graph
$userEmail = '';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://graph.microsoft.com/v1.0/me',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
]);
$meResponse = curl_exec($ch);
curl_close($ch);

$meData = json_decode($meResponse, true);
$userEmail = $meData['mail'] ?? $meData['userPrincipalName'] ?? '';

// Store tokens in database
try {
    $db = Database::getInstance();
    $db->update('users', [
        'ms_access_token'    => $accessToken,
        'ms_refresh_token'   => $refreshToken,
        'ms_token_expires'   => $expiresAt,
        'ms_connected_email' => $userEmail,
        'smtp_email'         => $userEmail ?: ($db->findOne('users', ['user_id' => $userId])['smtp_email'] ?? ''),
    ], ['user_id' => $userId]);

    logActivity($userId, 'Connected Microsoft Email', 'User', $userId, "Connected Office 365 email: $userEmail");
    $_SESSION['success'] = "Microsoft Office 365 connected successfully! Email: $userEmail";
} catch (Exception $e) {
    error_log("OAuth token storage error: " . $e->getMessage());
    $_SESSION['error'] = "Connected to Microsoft but failed to save tokens: " . $e->getMessage();
}

header('Location: /pages/profile.php');
exit;
