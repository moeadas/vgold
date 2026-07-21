// assignee-picker.js
// Renders a searchable multi-select for task assignees with avatar chips.
// Usage: AssigneePicker.render({ members, selectedIds, onChange })

const AssigneePicker = {
  render({ members, selectedIds = [], onChange }) {
    const selected = new Set(selectedIds.map(String));

    const avatar = (m) => `<span class="ap-avatar" style="background:${esc(m.avatar_color || m.bg || '#888')}">${esc(m.initials || initialsOf(m.name))}</span>`;

    const chipsHtml = () => [...selected].map(uid => {
      const m = members.find(x => String(x.id) === uid);
      if (!m) return '';
      return `<span class="ap-chip" data-uid="${esc(m.id)}">${avatar(m)}<span>${esc(m.name)}</span><button type="button" class="ap-chip-x" data-remove="${esc(m.id)}">&times;</button></span>`;
    }).join('');

    const optionsHtml = (filter = '') => {
      const filtered = members.filter(m => m.name.toLowerCase().includes(filter.toLowerCase()));
      if (!filtered.length) return '<div class="ap-empty">No members found</div>';
      return filtered.map(m => `<div class="ap-option ${selected.has(String(m.id)) ? 'ap-selected' : ''}" data-uid="${esc(m.id)}">${avatar(m)}<span class="ap-name">${esc(m.name)}</span><span class="ap-check">${selected.has(String(m.id)) ? '✓' : ''}</span></div>`).join('');
    };

    const wrapper = document.createElement('div');
    wrapper.className = 'ap-wrap';
    wrapper.innerHTML = `
      <div class="ap-control" tabindex="0">
        <div class="ap-chips">${chipsHtml() || '<span class="ap-placeholder">Assign to…</span>'}</div>
        <span class="ap-caret">▾</span>
      </div>
      <div class="ap-dropdown" hidden>
        <input type="text" class="ap-search" placeholder="Search members…" />
        <div class="ap-list">${optionsHtml()}</div>
      </div>`;

    const control = wrapper.querySelector('.ap-control');
    const dropdown = wrapper.querySelector('.ap-dropdown');
    const search = wrapper.querySelector('.ap-search');
    const list = wrapper.querySelector('.ap-list');
    const chips = wrapper.querySelector('.ap-chips');

    const refresh = () => {
      chips.innerHTML = chipsHtml() || '<span class="ap-placeholder">Assign to…</span>';
      list.innerHTML = optionsHtml(search.value);
      if (onChange) onChange([...selected]);
    };

    const open = () => { dropdown.hidden = false; search.value = ''; list.innerHTML = optionsHtml(); search.focus(); };
    const close = () => { dropdown.hidden = true; };

    control.addEventListener('click', () => dropdown.hidden ? open() : close());
    search.addEventListener('input', () => list.innerHTML = optionsHtml(search.value));

    list.addEventListener('click', (e) => {
      const opt = e.target.closest('.ap-option');
      if (!opt) return;
      const uid = opt.dataset.uid;
      selected.has(uid) ? selected.delete(uid) : selected.add(uid);
      refresh();
    });

    chips.addEventListener('click', (e) => {
      const x = e.target.closest('[data-remove]');
      if (!x) return;
      e.stopPropagation();
      selected.delete(x.dataset.remove);
      refresh();
    });

    document.addEventListener('click', (e) => { if (!wrapper.contains(e.target)) close(); });
    wrapper.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

    return { el: wrapper, getSelected: () => [...selected] };
  }
};

function initialsOf(name = '') {
  return name.trim().split(/\s+/).map(p => p[0]).slice(0, 2).join('').toUpperCase();
}