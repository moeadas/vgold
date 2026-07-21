<?php
// CSRF protection (H5). Double-submit style token stored in the session and
// validated against the X-CSRF-Token header (or _csrf field) on unsafe methods.
class Csrf {
    const HEADER = 'HTTP_X_CSRF_TOKEN';

    public static function token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    // Validate the request token for state-changing methods.
    public static function validate() {
        $sent = $_SERVER[self::HEADER] ?? null;
        if (!$sent) {
            // Fall back to form field / JSON body
            $sent = $_POST['_csrf'] ?? null;
            if (!$sent) {
                $raw = file_get_contents('php://input');
                if ($raw) {
                    $json = json_decode($raw, true);
                    if (is_array($json) && isset($json['_csrf'])) $sent = $json['_csrf'];
                }
            }
        }
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!$expected || !is_string($sent) || !hash_equals($expected, $sent)) {
            http_response_code(419);
            echo json_encode(['error' => 'CSRF token mismatch. Please refresh and try again.']);
            exit;
        }
    }
}
