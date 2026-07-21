/**
 * Victory Genomics CRM — Main JavaScript (Vanilla, no jQuery)
 */

document.addEventListener('DOMContentLoaded', function () {
    // ─── Mobile sidebar ───
    const sidebar = document.getElementById('sidebar');
    document.addEventListener('click', function (e) {
        if (sidebar && sidebar.classList.contains('active')) {
            if (!e.target.closest('.sidebar') && !e.target.closest('.mobile-menu-toggle')) {
                sidebar.classList.remove('active');
            }
        }
    });

    // ─── Auto-dismiss alerts after 5s (skip hidden ones inside modals) ───
    document.querySelectorAll('.alert').forEach(function (el) {
        // Don't auto-dismiss alerts that are hidden (e.g. inside modals)
        if (el.style.display === 'none' || el.closest('.modal')) return;
        setTimeout(function () {
            el.style.transition = 'opacity 0.3s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 300);
        }, 5000);
    });

    // ─── Confirm actions ───
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.dataset.confirm)) e.preventDefault();
        });
    });

    // ─── Form validation (data-validate) ───
    document.querySelectorAll('form[data-validate]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            let valid = true;
            form.querySelectorAll('[required]').forEach(function (field) {
                if (!field.value.trim()) {
                    valid = false;
                    field.style.borderColor = 'var(--color-danger)';
                } else {
                    field.style.borderColor = '';
                }
            });
            if (!valid) {
                e.preventDefault();
                showNotification('Please fill in all required fields', 'error');
            }
        });
    });
});

/**
 * Show notification toast
 */
function showNotification(message, type) {
    type = type || 'info';
    var icons = {
        success: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
        error:   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info:    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };

    var div = document.createElement('div');
    div.className = 'alert alert-' + type;
    div.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9999;max-width:380px;animation:modalIn 0.25s ease-out;box-shadow:0 8px 30px rgba(0,0,0,0.12);';
    div.innerHTML = (icons[type] || '') + '<span>' + escapeHtml(message) + '</span>';
    document.body.appendChild(div);
    setTimeout(function () {
        div.style.transition = 'opacity 0.3s';
        div.style.opacity = '0';
        setTimeout(function () { div.remove(); }, 300);
    }, 4000);
}

/**
 * Show alert (alias for backward compat)
 */
function showAlert(message, type) {
    showNotification(message, type);
}

/**
 * Escape HTML
 */
function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

/**
 * Format date
 */
function formatDate(dateStr) {
    if (!dateStr) return '-';
    var d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

/**
 * Delete lead
 */
function deleteLead(leadId, csrfToken) {
    if (!confirm('Are you sure you want to delete this lead?')) return;

    fetch('/api/leads.php?action=delete&id=' + leadId, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: csrfToken })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            showNotification('Lead deleted successfully', 'success');
            var row = document.querySelector('tr[data-lead-id="' + leadId + '"]');
            if (row) row.remove();
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(function () { showNotification('Failed to delete lead', 'error'); });
}

/**
 * Update lead status
 */
function updateLeadStatus(leadId, status, csrfToken) {
    fetch('/api/leads.php?action=status', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lead_id: leadId, status: status, csrf_token: csrfToken })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            showNotification('Status updated', 'success');
            location.reload();
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(function () { showNotification('Failed to update status', 'error'); });
}

/**
 * Export data
 */
function exportToCSV(type) {
    window.location.href = '/api/export.php?type=' + type;
}
