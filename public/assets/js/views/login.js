// VGo — Login view (Microsoft primary, password for external collaborators)
function renderLogin() {
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
            <img src="/assets/img/vgo-login-logo.png?v=20260626c" srcset="/assets/img/vgo-login-logo@2x.png?v=20260626c 2x" alt="VGold" class="login-logo-img" />
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
        </div>
      </div>
    </div>
  `;
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
