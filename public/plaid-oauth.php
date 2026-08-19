<?php
/**
 * Plaid OAuth return page.
 *
 * Bank of America takes the user to its own site to sign in, then sends the
 * browser back here. Plaid's rule for this leg is specific: re-initialise Link
 * with the SAME link_token that started the flow, plus `receivedRedirectUri`
 * set to this full URL. That is why the token is parked in sessionStorage
 * before Link opens — this page is a fresh document and has no other way to
 * know which handshake it is completing.
 *
 * Served at exactly the URI registered in the Plaid dashboard
 * (/plaid/oauth) via a rewrite in public/.htaccess.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../app/lib/DB.php';
require_once __DIR__ . '/../app/lib/Auth.php';
require_once __DIR__ . '/../app/lib/Csrf.php';

Auth::init();
$authed = Auth::check();
$csrf = $authed ? Csrf::token() : '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Connecting your bank · VGo</title>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= ASSET_VERSION ?>">
<style>
  body { margin:0; min-height:100dvh; display:flex; align-items:center; justify-content:center;
         background:var(--bg,#F6F1E7); font-family:var(--sans,system-ui,sans-serif); color:var(--text,#3D2E22); }
  .po-card { max-width:460px; width:calc(100% - 32px); background:var(--surface,#fff);
             border:1px solid var(--border,rgba(61,46,34,.12)); border-radius:18px;
             padding:32px 28px; text-align:center; box-shadow:0 18px 44px -20px rgba(61,46,34,.28); }
  .po-title { font-size:19px; font-weight:700; margin:0 0 8px; }
  .po-msg { font-size:14px; line-height:1.55; color:var(--muted,#9A8A78); margin:0 0 20px; }
  .po-spin { width:34px; height:34px; margin:0 auto 18px; border-radius:50%;
             border:3px solid var(--sand,#EAE0CE); border-top-color:var(--gold,#C99520);
             animation:po-rot .9s linear infinite; }
  @keyframes po-rot { to { transform:rotate(360deg); } }
  .po-btn { display:inline-block; padding:11px 20px; border-radius:11px; border:none; cursor:pointer;
            background:var(--gold-dark,#7A5D31); color:#fff; font-size:14px; font-weight:700;
            font-family:inherit; text-decoration:none; }
  .po-err { color:#B0432B; }
  @media (prefers-reduced-motion:reduce) { .po-spin { animation:none; } }
</style>
</head>
<body>
  <div class="po-card">
    <div class="po-spin" id="po-spin"></div>
    <h1 class="po-title" id="po-title">Finishing the connection…</h1>
    <p class="po-msg" id="po-msg">Hold on while we confirm this with your bank.</p>
    <a class="po-btn" id="po-btn" href="/#acc/banking" style="display:none">Back to Banking</a>
  </div>

<script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js"></script>
<script>
(function () {
  var AUTHED = <?= $authed ? 'true' : 'false' ?>;
  var CSRF   = <?= json_encode($csrf) ?>;
  var KEY    = 'vgo_plaid_link_token';

  function done(title, msg, isErr) {
    document.getElementById('po-spin').style.display = 'none';
    var t = document.getElementById('po-title');
    t.textContent = title;
    if (isErr) t.className = 'po-title po-err';
    document.getElementById('po-msg').textContent = msg;
    document.getElementById('po-btn').style.display = 'inline-block';
  }

  if (!AUTHED) { done('Please sign in first', 'Sign in to VGo and start the bank connection again.', true); return; }

  var linkToken = null;
  try { linkToken = sessionStorage.getItem(KEY) || localStorage.getItem(KEY); } catch (e) {}
  if (!linkToken) {
    done('This link has expired', 'Go back to Accounting → Banking and start the connection again.', true);
    return;
  }
  if (typeof Plaid === 'undefined') {
    done('Could not load Plaid', 'Check your connection and try again from Accounting → Banking.', true);
    return;
  }

  var handler = Plaid.create({
    token: linkToken,
    // Completing the OAuth leg rather than starting a new one.
    receivedRedirectUri: window.location.href,
    onSuccess: function (publicToken) {
      fetch('/api/acc/plaid/exchange', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ public_token: publicToken })
      })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        try { sessionStorage.removeItem(KEY); localStorage.removeItem(KEY); } catch (e) {}
        if (!res.ok) { done('Could not finish', (res.d && res.d.error) || 'Plaid rejected the connection.', true); return; }
        // Land on Banking with the new connection already in place.
        window.location.replace('/#acc/banking');
      })
      .catch(function () { done('Could not finish', 'The connection was authorised but saving it failed. Try again from Banking.', true); });
    },
    onExit: function (err) {
      try { sessionStorage.removeItem(KEY); localStorage.removeItem(KEY); } catch (e) {}
      if (err) done('Connection cancelled', err.display_message || err.error_message || 'The bank connection was not completed.', true);
      else window.location.replace('/#acc/banking');
    }
  });
  handler.open();
})();
</script>
</body>
</html>
