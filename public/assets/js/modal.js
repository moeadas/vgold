// VGo — In-app modal system (no browser popups/alerts/prompts)
const Modal = {
  current: null,

  open(opts) {
    const root = document.getElementById('modal-root');
    if (!root) return;
    Modal.close();
    const m = document.createElement('div');
    m.className = 'modal-overlay';
    m.onclick = (e) => { if (e.target === m) Modal.close(); };
    m.innerHTML = `
      <div class="modal">
        <div class="modal-header">
          <h2>${esc(opts.title || '')}</h2>
          <button class="drawer-close" onclick="Modal.close()">${I.close}</button>
        </div>
        <div class="modal-body" id="modal-body-content">${opts.body || ''}</div>
        ${opts.footer ? `<div class="modal-footer">${opts.footer}</div>` : ''}
      </div>`;
    root.appendChild(m);
    Modal.current = m;
    if (opts.onMount) opts.onMount();
    return m;
  },

  close() {
    const root = document.getElementById('modal-root');
    if (root) root.innerHTML = '';
    Modal.current = null;
  },

  confirm(opts) {
    return new Promise(resolve => {
      Modal.open({
        title: opts.title || 'Confirm',
        body: `<p style="font-size:15px;color:var(--text);line-height:1.5">${esc(opts.message || 'Are you sure?')}</p>`,
        footer: `
          <button class="btn-secondary" onclick="Modal.close();__confirmRes(false);">Cancel</button>
          <button class="${opts.danger ? 'btn-danger' : 'btn-primary'}" onclick="Modal.close();__confirmRes(true);">${esc(opts.confirmText || 'Confirm')}</button>
        `,
      });
      window.__confirmRes = resolve;
    });
  },
};
window.Modal = Modal;

// App-native confirmation dialog (uses Modal, falls back to window.confirm)
function appConfirm(message, onConfirm) {
  if (typeof Modal !== 'undefined' && Modal.open) {
    Modal.open({
      title: 'Confirm',
      body: `<p style="font-size:15px;color:var(--text);line-height:1.5">${esc(message)}</p>`,
      footer: `
        <button class="btn-secondary" onclick="Modal.close()">Cancel</button>
        <button class="btn-primary" id="app-confirm-yes" style="background:var(--barn)">Delete</button>
      `,
      onMount: () => {
        const yesBtn = document.getElementById('app-confirm-yes');
        if (yesBtn) yesBtn.addEventListener('click', () => {
          Modal.close();
          onConfirm();
        });
      }
    });
  } else {
    if (window.confirm(message)) onConfirm();
  }
}
window.appConfirm = appConfirm;