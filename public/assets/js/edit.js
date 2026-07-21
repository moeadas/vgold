// VGo — Inline edit component
function editableText(opts) {
  const { value, onSave, multiline, placeholder, className, style } = opts;
  const id = 'edit-' + Math.random().toString(36).slice(2, 9);
  return `<span class="editable-text ${className || ''}" id="${id}" data-value="${esc(value || '')}" data-placeholder="${esc(placeholder || '')}" data-multiline="${multiline ? 1 : 0}" style="${style || ''}" onclick="startEdit('${id}', ${multiline ? 'true' : 'false'})">${esc(value || placeholder || '')}</span>`;
}

function startEdit(id, multiline) {
  const el = document.getElementById(id);
  if (!el || el.querySelector('input,textarea')) return;
  const value = el.dataset.value || '';
  const placeholder = el.dataset.placeholder || '';
  
  const inputEl = document.createElement(multiline ? 'textarea' : 'input');
  if (multiline) {
    inputEl.className = 'inline-edit-area';
    inputEl.value = value;
  } else {
    inputEl.type = 'text';
    inputEl.className = 'inline-edit-input';
    inputEl.value = value;
  }
  inputEl.placeholder = placeholder;
  
  el.innerHTML = '';
  el.appendChild(inputEl);
  inputEl.focus();
  if (inputEl.select) inputEl.select();
  
  const save = () => {
    const newVal = inputEl.value.trim();
    if (newVal !== value) {
      el.dataset.value = newVal;
      el.innerHTML = esc(newVal || placeholder || '');
      if (el.dataset.onsave) {
        try { window[el.dataset.onsave](newVal); } catch(e) {}
      }
    } else {
      el.innerHTML = esc(value || placeholder || '');
    }
  };
  
  inputEl.addEventListener('blur', save);
  if (!multiline) {
    inputEl.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); inputEl.blur(); }
      if (e.key === 'Escape') { el.innerHTML = esc(value || placeholder || ''); }
    });
  } else {
    inputEl.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { el.innerHTML = esc(value || placeholder || ''); }
    });
  }
}

// Save project field
async function saveProjectField(projectId, field, value) {
  try {
    await API.updateProject(projectId, { [field]: value });
    toast('Saved', 'success');
    State.activeProject = null;
    State.projects = null;
  } catch(e) { toast(e.message, 'error'); }
}

// Save task field
async function saveTaskField(taskId, field, value) {
  try {
    await API.updateTask(taskId, { [field]: value });
    toast('Saved', 'success');
    State.activeProject = null;
    State.myTasksData = null;
    State.meetingData = null;
  } catch(e) { toast(e.message, 'error'); }
}