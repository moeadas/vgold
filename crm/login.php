<?php
/**
 * Victory Genomics CRM - Login Page
 * Apple-style design, CSRF protected, rate limited
 */
require_once 'includes/auth.php';

startSecureSession();

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } elseif (authenticateUser($username, $password)) {
            header('Location: /dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#ffffff">
    <title>Sign In &mdash; <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="main-container">
        <div class="login-card">
            <div class="login-header">
                <img src="/assets/images/VG%20logo.svg" alt="Victory Genomics" class="login-logo">
                <h1 class="login-title"><?php echo APP_NAME; ?></h1>
                <p class="login-subtitle">Sign in to your account</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="on">
                <?php echo csrfField(); ?>

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" placeholder="Enter your username" required autofocus autocomplete="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    Sign In
                </button>
            </form>

            <div class="login-footer">
                <p>&copy; <?php echo date('Y'); ?> Victory Genomics. All rights reserved.</p>
            </div>
        </div>
    </div>
</body>
</html>
