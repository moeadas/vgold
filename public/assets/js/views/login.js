// VGo — Login view (Microsoft primary, password for external collaborators)

/**
 * The signed-out screens share one shell. A reset link lands on #reset/<token>,
 * so this has to be decided before anything else renders — the router only runs
 * once someone is signed in, which by definition they are not here.
 */
function renderLogin() {
  const hash = (location.hash || '').replace(/^#/, '');
  if (hash === 'forgot') return renderForgotPassword();
  if (hash.indexOf('reset/') === 0) return renderResetPassword(hash.slice(6));
  if (hash === 'reset') return renderResetPassword('');
  return renderSignIn();
}

// The token from an emailed link, held here rather than interpolated into an
// inline handler.
let _resetToken = '';

function renderSignIn() {
  const app = document.getElementById('app');
  app.innerHTML = `
    <div class="login-root">
      <div class="login-left">
        <div class="login-left-content">
          <h1 class="login-tagline">Your team's workspace,<br>organized.</h1>
          <p class="login-desc">Manage projects, tasks, and conversations in one place.</p>
          <div class="login-features">
            <div class="login-feature">
              <div class="login-feature-icon">${I.check}</div>
              <span>Smart project management</span>
            </div>
            <div class="login-feature">
              <div class="login-feature-icon">${I.check}</div>
              <span>Real-time team messaging</span>
            </div>
            <div class="login-feature">
              <div class="login-feature-icon">${I.check}</div>
              <span>AI-powered daily planning</span>
            </div>
          </div>
        </div>
        <div class="login-left-footer">
          <span>© 2026 Victory Genomics</span>
        </div>
      </div>
      <div class="login-right">
        <div class="login-card">
          <div class="login-logo-wrap">
            <img src="/assets/img/vgo-login-logo.png?v=20260626c" srcset="/assets/img/vgo-login-logo@2x.png?v=20260626c 2x" alt="VGo" class="login-logo-img" />
          </div>
          <h1 class="login-title">Welcome back</h1>
          <p class="login-subtitle">Sign in to your workspace</p>
          <div class="login-error" id="login-error"></div>
          <a href="/api/auth/microsoft" class="btn-microsoft" style="margin-bottom:20px">
            <svg width="20" height="20" viewBox="0 0 23 23" fill="none" style="flex:none">
              <rect x="0.5" y="0.5" width="10" height="10" rx="1" fill="#F25022"/>
              <rect x="12" y="0.5" width="10" height="10" rx="1" fill="#7FBA00"/>
              <rect x="0.5" y="12" width="10" height="10" rx="1" fill="#00A4EF"/>
              <rect x="12" y="12" width="10" height="10" rx="1" fill="#FFB900"/>
            </svg>
            <span>Sign in with Microsoft</span>
          </a>
          <div class="login-divider"><span>or sign in with password (external collaborators)</span></div>
          <form id="login-form" onsubmit="return handleLogin(event)" style="margin-top:14px">
            <div class="input-group">
              <label class="input-label">Email or username</label>
              <input class="input-field" type="text" id="login-email" placeholder="you@company.com or username" required autocomplete="username">
            </div>
            <div class="input-group">
              <label class="input-label">Password</label>
              <input class="input-field" type="password" id="login-password" placeholder="••••••••" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-login" id="login-submit">
              <span id="login-btn-text">Sign in</span>
              <span id="login-spinner" style="display:none">${I.spinner}</span>
            </button>
          </form>
          <div class="login-alt-actions">
            <a href="#forgot" class="login-link">Forgot your password?</a>
          </div>
        </div>
      </div>
    </div>
  `;
}

/** Shell shared by the two password screens, so they match the sign-in card. */
function loginShell(inner) {
  document.getElementById('app').innerHTML = `
    <div class="login-root">
      <div class="login-left">
        <div class="login-left-content">
          <h1 class="login-tagline">Your team's workspace,<br>organized.</h1>
          <p class="login-desc">Manage projects, tasks, and conversations in one place.</p>
        </div>
        <div class="login-left-footer"><span>© 2026 Victory Genomics</span></div>
      </div>
      <div class="login-right">
        <div class="login-card">
          <div class="login-logo-wrap">
            <img src="/assets/img/vgo-login-logo.png?v=20260626c" srcset="/assets/img/vgo-login-logo@2x.png?v=20260626c 2x" alt="VGo" class="login-logo-img" />
          </div>
          ${inner}
        </div>
      </div>
    </div>`;
}

// ===== Forgot password — request a link =====
function renderForgotPassword() {
  loginShell(`
    <h1 class="login-title">Reset your password</h1>
    <p class="login-subtitle">Enter the email address you sign in with and we'll send you a link.</p>
    <div class="login-error" id="login-error"></div>
    <div class="login-note" id="forgot-done" style="display:none"></div>
    <form id="forgot-form" onsubmit="return handleForgot(event)">
      <div class="input-group">
        <label class="input-label">Email address</label>
        <input class="input-field" type="email" id="forgot-email" placeholder="you@company.com" required autocomplete="username">
      </div>
      <button type="submit" class="btn-login" id="forgot-submit">
        <span id="forgot-btn-text">Send reset link</span>
        <span id="forgot-spinner" style="display:none">${I.spinner}</span>
      </button>
    </form>
    <div class="login-alt-actions"><a href="#" class="login-link" onclick="event.preventDefault();goSignIn()">← Back to sign in</a></div>
    <p class="login-fineprint">Signing in with Microsoft? Your password is managed by Microsoft, not VGo — use the Microsoft button on the sign-in screen.</p>
  `);
  setTimeout(() => document.getElementById('forgot-email')?.focus(), 30);
}

async function handleForgot(e) {
  e.preventDefault();
  const err = document.getElementById('login-error');
  err.classList.remove('show');
  const btn = document.getElementById('forgot-submit');
  const txt = document.getElementById('forgot-btn-text');
  const spin = document.getElementById('forgot-spinner');
  btn.disabled = true; txt.style.display = 'none'; spin.style.display = 'inline';
  try {
    const res = await API.forgotPassword(document.getElementById('forgot-email').value.trim());
    document.getElementById('forgot-form').style.display = 'none';
    const done = document.getElementById('forgot-done');
    done.textContent = res.message || 'If that address belongs to an account that signs in with a password, a reset link is on its way.';
    done.style.display = 'block';
  } catch (ex) {
    showLoginError(ex.message);
  } finally {
    btn.disabled = false; txt.style.display = 'inline'; spin.style.display = 'none';
  }
  return false;
}

// ===== Reset password — set a new one from an emailed link =====
async function renderResetPassword(token) {
  // Tokens are 64 hex characters; anything else is not worth a round trip.
  _resetToken = /^[a-f0-9]{64}$/i.test(String(token || '')) ? String(token) : '';
  loginShell(`
    <h1 class="login-title">Choose a new password</h1>
    <p class="login-subtitle" id="reset-sub">Checking your link…</p>
    <div class="login-error" id="login-error"></div>
    <div id="reset-body"></div>
  `);

  let info = { valid: false };
  if (_resetToken) {
    try { info = await API.resetCheck(_resetToken); } catch (ex) { info = { valid: false }; }
  }

  const sub = document.getElementById('reset-sub');
  const body = document.getElementById('reset-body');
  if (!info || !info.valid) {
    sub.textContent = 'That link is no longer valid.';
    body.innerHTML = `
      <div class="login-note">Reset links work once and expire after an hour. Request a fresh one and it will arrive in a moment.</div>
      <a href="#forgot" class="btn-login" style="text-decoration:none;margin-top:16px">Request a new link</a>
      <div class="login-alt-actions"><a href="#" class="login-link" onclick="event.preventDefault();goSignIn()">← Back to sign in</a></div>`;
    return;
  }

  sub.textContent = 'Setting a new password for ' + info.email + '.';
  body.innerHTML = `
    <form id="reset-form" onsubmit="return handleReset(event)">
      <div class="input-group">
        <label class="input-label">New password</label>
        <input class="input-field" type="password" id="reset-pw" placeholder="At least 8 characters" required autocomplete="new-password" minlength="8">
      </div>
      <div class="input-group">
        <label class="input-label">Confirm new password</label>
        <input class="input-field" type="password" id="reset-pw2" placeholder="••••••••" required autocomplete="new-password">
      </div>
      <button type="submit" class="btn-login" id="reset-submit">
        <span id="reset-btn-text">Set password and sign in</span>
        <span id="reset-spinner" style="display:none">${I.spinner}</span>
      </button>
    </form>`;
  setTimeout(() => document.getElementById('reset-pw')?.focus(), 30);
}

/** Clear the hash without firing hashchange, then show the sign-in card. */
function goSignIn() {
  history.replaceState(null, '', location.pathname + location.search);
  renderLogin();
}

async function handleReset(e) {
  e.preventDefault();
  const err = document.getElementById('login-error');
  err.classList.remove('show');
  const pw = document.getElementById('reset-pw').value;
  const pw2 = document.getElementById('reset-pw2').value;
  if (pw.length < 8) { showLoginError('Password must be at least 8 characters.'); return false; }
  if (pw !== pw2) { showLoginError('Those passwords do not match.'); return false; }

  const btn = document.getElementById('reset-submit');
  const txt = document.getElementById('reset-btn-text');
  const spin = document.getElementById('reset-spinner');
  btn.disabled = true; txt.style.display = 'none'; spin.style.display = 'inline';
  try {
    const res = await API.resetPassword(_resetToken, pw);
    _resetToken = '';
    // The token is spent — take it out of the URL so a shared or bookmarked
    // link cannot be replayed, and so a reload does not land back here.
    history.replaceState(null, '', location.pathname + location.search);
    if (res.signed_in) { location.reload(); return false; }
    renderLogin();
    showLoginError('Password updated. Sign in with your new password.');
  } catch (ex) {
    showLoginError(ex.message);
    btn.disabled = false; txt.style.display = 'inline'; spin.style.display = 'none';
  }
  return false;
}

function showLoginError(msg) {
  const el = document.getElementById('login-error');
  el.textContent = msg;
  el.classList.add('show');
}

async function handleLogin(e) {
  e.preventDefault();
  const err = document.getElementById('login-error');
  err.classList.remove('show');
  const btn = document.getElementById('login-submit');
  const btnText = document.getElementById('login-btn-text');
  const spinner = document.getElementById('login-spinner');
  btn.disabled = true;
  btnText.style.display = 'none';
  spinner.style.display = 'inline';
  const email = document.getElementById('login-email').value;
  const password = document.getElementById('login-password').value;
  try {
    await API.login(email, password);
    location.reload();
  } catch (ex) {
    showLoginError(ex.message);
    btn.disabled = false;
    btnText.style.display = 'inline';
    spinner.style.display = 'none';
  }
  return false;
}
