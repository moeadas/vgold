<?php
/**
 * Victory Genomics CRM V2 — Public Unsubscribe Page
 * Included by api/email.php (no direct access)
 * Variables available: $log, $token
 */
if (!isset($log) && !isset($token)) { header('Location: /login.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe — Victory Genomics</title>
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="main-container">
        <div class="login-card unsub-card">
            <div class="login-header">
                <img src="/assets/images/VG%20logo.svg" alt="Victory Genomics" class="login-logo">
                <h1 class="login-title">Unsubscribe</h1>
            </div>

            <?php if ($log): ?>
                <div id="unsubContent">
                    <p class="unsub-text">
                        Are you sure you want to unsubscribe <strong><?php echo htmlspecialchars($log['email']); ?></strong> from our mailing list?
                    </p>
                    <button onclick="confirmUnsub()" class="btn btn-primary btn-block" id="unsubBtn">Yes, Unsubscribe Me</button>
                    <p class="unsub-footer">
                        You can also contact us at <a href="mailto:info@victorygenomics.com">info@victorygenomics.com</a>
                    </p>
                </div>
                <div id="unsubDone" class="unsub-done" style="display:none;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--color-success)" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <h2>You've been unsubscribed</h2>
                    <p>You will no longer receive marketing emails from us.</p>
                </div>
            <?php else: ?>
                <p class="unsub-text">Invalid or expired unsubscribe link.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function confirmUnsub() {
        document.getElementById('unsubBtn').disabled = true;
        document.getElementById('unsubBtn').textContent = 'Processing...';
        fetch('/api/email.php?action=unsubscribe_confirm', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'token=<?php echo urlencode($token); ?>'
        }).then(r => r.json()).then(d => {
            document.getElementById('unsubContent').style.display = 'none';
            document.getElementById('unsubDone').style.display = '';
        }).catch(() => {
            document.getElementById('unsubContent').style.display = 'none';
            document.getElementById('unsubDone').style.display = '';
        });
    }
    </script>
</body>
</html>
