<?php
/**
 * Victory Genomics CRM — Knowledge Hub
 * Dynamic link-based resource cards. All users can view; Sales Manager+ can manage.
 * Uses inline form panel instead of modal popups.
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();

$pageTitle = 'Knowledge Hub';
$csrf_token = generateCSRFToken();
$canManage = hasRole('Sales Manager');
include '../includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="page-title">Knowledge Hub</h1>
        <p style="font-size:13px;color:var(--color-text-secondary);margin-top:4px;">Shared resources, training materials, guides, and useful links for the team.</p>
    </div>
    <?php if ($canManage): ?>
    <button class="btn btn-primary" id="addResourceBtn" onclick="showForm()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="vertical-align:middle;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Resource
    </button>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<!-- ── Inline Add/Edit Form Panel ── -->
<div id="formPanel" class="kh-form-panel" style="display:none;">
    <div class="kh-form-inner">
        <div class="kh-form-header">
            <h3 id="formTitle" style="margin:0;font-size:16px;font-weight:700;">Add New Resource</h3>
            <button type="button" class="kh-form-close" onclick="hideForm()" title="Cancel">&times;</button>
        </div>
        <form id="cardForm" onsubmit="saveCard(event)">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="card_id" id="card_id">
            <div class="kh-form-body">
                <div class="kh-form-row">
                    <div class="kh-form-col">
                        <label class="form-label">Title <span style="color:#ff3b30;">*</span></label>
                        <input type="text" name="title" id="kh_title" class="form-control" required placeholder="e.g. Sales Playbook 2026">
                    </div>
                    <div class="kh-form-col">
                        <label class="form-label">Category</label>
                        <input type="text" name="category" id="kh_category" class="form-control" placeholder="e.g. Sales, Training, Product" list="catSuggestions">
                        <datalist id="catSuggestions"></datalist>
                    </div>
                </div>
                <div class="kh-form-row">
                    <div class="kh-form-col" style="flex:2;">
                        <label class="form-label">Link / URL <span style="color:#ff3b30;">*</span></label>
                        <input type="text" name="url" id="kh_url" class="form-control" required placeholder="https://drive.google.com/... or /pages/...">
                        <small style="color:var(--color-text-muted,#86868b);font-size:11px;margin-top:2px;display:block;">Google Drive, Dropbox, Notion, or any external URL</small>
                    </div>
                    <div class="kh-form-col" style="flex:1;">
                        <label class="form-label">Card Color</label>
                        <div class="color-picker-row">
                            <span class="color-dot selected" data-color="#0071e3" style="background:#0071e3;" onclick="pickColor(this)"></span>
                            <span class="color-dot" data-color="#00B8D9" style="background:#00B8D9;" onclick="pickColor(this)"></span>
                            <span class="color-dot" data-color="#34c759" style="background:#34c759;" onclick="pickColor(this)"></span>
                            <span class="color-dot" data-color="#ff9500" style="background:#ff9500;" onclick="pickColor(this)"></span>
                            <span class="color-dot" data-color="#ff3b30" style="background:#ff3b30;" onclick="pickColor(this)"></span>
                            <span class="color-dot" data-color="#af52de" style="background:#af52de;" onclick="pickColor(this)"></span>
                            <span class="color-dot" data-color="#1d1d1f" style="background:#1d1d1f;" onclick="pickColor(this)"></span>
                        </div>
                        <input type="hidden" name="icon_color" id="kh_icon_color" value="#0071e3">
                    </div>
                </div>
                <div class="kh-form-row">
                    <div class="kh-form-col">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="kh_description" class="form-control" rows="2" placeholder="Brief description of this resource..."></textarea>
                    </div>
                </div>
            </div>
            <div class="kh-form-footer">
                <button type="button" class="btn btn-outline" onclick="hideForm()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="cardSaveBtn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="vertical-align:middle;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>
                    Save Resource
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Category Filter Chips -->
<div id="categoryFilters" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
    <button class="guide-filter-chip active" data-cat="all" onclick="filterCategory('all', this)">All</button>
</div>

<!-- Cards Grid -->
<div class="guides-grid" id="cardsGrid">
    <div class="guide-card guide-card-empty">
        <div class="guide-card-body" style="text-align:center;padding:40px 20px;">
            <p style="color:var(--color-text-muted);font-size:13px;">Loading resources...</p>
        </div>
    </div>
</div>

<style>
/* ── Inline Form Panel ── */
.kh-form-panel {
    background: var(--color-bg-card, #fff);
    border: 1px solid var(--color-border-light, #e5e5e7);
    border-radius: 12px;
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.kh-form-inner { padding: 0; }
.kh-form-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 24px 0;
}
.kh-form-close {
    background: none;
    border: none;
    font-size: 22px;
    color: var(--color-text-muted, #86868b);
    cursor: pointer;
    padding: 0 4px;
    line-height: 1;
    transition: color 0.15s;
}
.kh-form-close:hover { color: var(--color-text-primary, #1d1d1f); }
.kh-form-body { padding: 16px 24px 8px; }
.kh-form-row {
    display: flex;
    gap: 16px;
    margin-bottom: 14px;
}
.kh-form-col { flex: 1; min-width: 0; }
.kh-form-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 12px 24px 18px;
}
@media (max-width: 640px) {
    .kh-form-row { flex-direction: column; gap: 10px; }
}

/* ── Guide Cards Grid ── */
.guides-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 16px;
    margin-top: 8px;
}

.guide-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px 24px;
    background: var(--color-bg-card, #fff);
    border: 1px solid var(--color-border-light, #e5e5e7);
    border-radius: 12px;
    transition: all 0.2s ease;
    cursor: pointer;
    position: relative;
    text-decoration: none;
    color: inherit;
}

.guide-card:not(.guide-card-empty):hover {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    transform: translateY(-2px);
}

.guide-card-empty {
    border-style: dashed;
    opacity: 0.6;
    cursor: default;
}

.guide-card-icon {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.guide-card-body {
    flex: 1;
    min-width: 0;
}

.guide-card-title {
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 4px;
    color: var(--color-text-primary, #1d1d1f);
}

.guide-card-desc {
    font-size: 12px;
    color: var(--color-text-secondary, #6e6e73);
    line-height: 1.5;
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.guide-card-meta {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}

.guide-tag {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.guide-card-arrow {
    flex-shrink: 0;
    color: var(--color-text-muted, #86868b);
    transition: transform 0.2s;
}

.guide-card:not(.guide-card-empty):hover .guide-card-arrow {
    transform: translateX(4px);
}

/* ── Card hover actions ── */
.guide-card-actions {
    position: absolute;
    top: 8px;
    right: 8px;
    display: flex;
    gap: 4px;
    opacity: 0;
    transition: opacity 0.15s;
}
.guide-card:hover .guide-card-actions { opacity: 1; }

.guide-card-actions button {
    background: #fff;
    border: 1px solid #e5e5e7;
    border-radius: 6px;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--color-text-secondary, #6e6e73);
    transition: all 0.1s;
}
.guide-card-actions button:hover { background: #f5f5f7; color: var(--color-text-primary, #1d1d1f); }
.guide-card-actions button.danger:hover { background: #fff0f0; color: #ff3b30; border-color: #fecaca; }

/* ── Filter Chips ── */
.guide-filter-chip {
    font-size: 12px;
    font-weight: 500;
    padding: 5px 14px;
    border-radius: 20px;
    border: 1px solid var(--color-border-light, #e5e5e7);
    background: #fff;
    cursor: pointer;
    transition: all 0.15s;
    color: var(--color-text-secondary, #6e6e73);
}
.guide-filter-chip:hover { border-color: #86868b; }
.guide-filter-chip.active { background: #1d1d1f; color: #fff; border-color: #1d1d1f; }

/* ── Color Picker ── */
.color-picker-row { display: flex; gap: 8px; margin-top: 6px; flex-wrap: wrap; }
.color-dot {
    width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
    border: 3px solid transparent; transition: border-color 0.15s, transform 0.1s;
}
.color-dot:hover { transform: scale(1.15); }
.color-dot.selected { border-color: var(--color-text-primary, #1d1d1f); }

/* ── Toast notification ── */
.kh-toast {
    position: fixed; top: 20px; right: 20px; z-index: 9999;
    padding: 12px 20px; border-radius: 8px; font-size: 13px;
    font-weight: 500; color: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,.15);
    transition: opacity 0.3s;
}

/* ── Responsive ── */
@media (max-width: 480px) {
    .guides-grid { grid-template-columns: 1fr; }
    .guide-card { padding: 16px; }
    .guide-card-icon { width: 44px; height: 44px; }
}
</style>

<script>
var canManage = <?php echo $canManage ? 'true' : 'false'; ?>;
var csrfToken = '<?php echo $csrf_token; ?>';
var allCards = [];
var activeCategory = 'all';

document.addEventListener('DOMContentLoaded', loadCards);

/* ── Load Cards ───────────────────────────────────────── */
function loadCards() {
    fetch('/api/knowledge-hub.php?action=list')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                allCards = data.data || [];
                buildCategoryChips();
                renderCards();
            } else {
                document.getElementById('cardsGrid').innerHTML =
                    '<div class="guide-card guide-card-empty"><div class="guide-card-body" style="text-align:center;padding:40px;"><p style="color:#ff3b30;">Failed to load resources.</p></div></div>';
            }
        })
        .catch(function() {
            document.getElementById('cardsGrid').innerHTML =
                '<div class="guide-card guide-card-empty"><div class="guide-card-body" style="text-align:center;padding:40px;"><p style="color:#ff3b30;">Failed to load resources.</p></div></div>';
        });
}

/* ── Category Chips ───────────────────────────────────── */
function buildCategoryChips() {
    var cats = {};
    allCards.forEach(function(c) {
        if (c.category) {
            c.category.split(',').forEach(function(p) {
                var cat = p.trim();
                if (cat) cats[cat] = (cats[cat] || 0) + 1;
            });
        }
    });
    var bar = document.getElementById('categoryFilters');
    var html = '<button class="guide-filter-chip active" data-cat="all" onclick="filterCategory(\'all\', this)">All (' + allCards.length + ')</button>';
    Object.keys(cats).sort().forEach(function(cat) {
        html += '<button class="guide-filter-chip" data-cat="' + escHtml(cat) + '" onclick="filterCategory(\'' + escHtml(cat) + '\', this)">' + escHtml(cat) + ' (' + cats[cat] + ')</button>';
    });
    bar.innerHTML = html;

    // Update datalist suggestions for category input
    var dl = document.getElementById('catSuggestions');
    if (dl) {
        dl.innerHTML = '';
        Object.keys(cats).sort().forEach(function(cat) {
            var opt = document.createElement('option');
            opt.value = cat;
            dl.appendChild(opt);
        });
    }
}

function filterCategory(cat, btn) {
    activeCategory = cat;
    document.querySelectorAll('.guide-filter-chip').forEach(function(c) { c.classList.remove('active'); });
    if (btn) btn.classList.add('active');
    renderCards();
}

/* ── Render Card Grid ─────────────────────────────────── */
function renderCards() {
    var grid = document.getElementById('cardsGrid');
    var filtered = allCards;
    if (activeCategory !== 'all') {
        filtered = allCards.filter(function(c) {
            return (c.category || '').toLowerCase().indexOf(activeCategory.toLowerCase()) !== -1;
        });
    }

    if (filtered.length === 0) {
        grid.innerHTML = '<div class="guide-card guide-card-empty"><div class="guide-card-body" style="text-align:center;padding:40px 20px;">'
            + '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--color-text-muted,#86868b)" stroke-width="1.5"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>'
            + '<p style="color:var(--color-text-muted,#86868b);margin-top:12px;font-size:13px;">No resources found.</p></div></div>';
        return;
    }

    var html = '';
    filtered.forEach(function(card) {
        var color = card.icon_color || '#0071e3';
        var cats = (card.category || '').split(',').map(function(s) { return s.trim(); }).filter(Boolean);
        var catHtml = '';
        cats.forEach(function(c) {
            catHtml += '<span class="guide-tag" style="background:' + hexToLightBg(color) + ';color:' + color + ';">' + escHtml(c) + '</span>';
        });

        html += '<div class="guide-card" style="border-left:3px solid ' + color + ';" onclick="openResource(\'' + escAttr(card.url) + '\')">';

        // Edit/Delete actions (managers only)
        if (canManage) {
            html += '<div class="guide-card-actions">';
            html += '<button onclick="event.stopPropagation(); editCard(' + card.card_id + ')" title="Edit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>';
            html += '<button class="danger" onclick="event.stopPropagation(); deleteCard(' + card.card_id + ', \'' + escAttr(card.title) + '\')" title="Delete"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>';
            html += '</div>';
        }

        // Icon
        html += '<div class="guide-card-icon" style="background:' + hexToLightBg(color) + ';">';
        html += '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="' + color + '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
        html += '</div>';

        // Body
        html += '<div class="guide-card-body">';
        html += '<div class="guide-card-title">' + escHtml(card.title) + '</div>';
        if (card.description) {
            html += '<div class="guide-card-desc">' + escHtml(card.description) + '</div>';
        }
        if (catHtml) {
            html += '<div class="guide-card-meta">' + catHtml + '</div>';
        }
        html += '</div>';

        // Arrow
        html += '<div class="guide-card-arrow"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></div>';
        html += '</div>';
    });

    grid.innerHTML = html;
}

function openResource(url) {
    if (url) window.open(url, '_blank', 'noopener,noreferrer');
}

/* ── Inline Form (no popup) ───────────────────────────── */
function showForm(card) {
    if (!canManage) return;
    var panel = document.getElementById('formPanel');
    var form  = document.getElementById('cardForm');
    form.reset();
    document.getElementById('card_id').value = '';
    document.getElementById('kh_icon_color').value = '#0071e3';
    resetColorPicker('#0071e3');
    document.getElementById('formTitle').textContent = 'Add New Resource';
    document.getElementById('cardSaveBtn').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="vertical-align:middle;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg> Save Resource';

    if (card) {
        document.getElementById('formTitle').textContent = 'Edit Resource';
        document.getElementById('card_id').value = card.card_id;
        document.getElementById('kh_title').value = card.title || '';
        document.getElementById('kh_description').value = card.description || '';
        document.getElementById('kh_category').value = card.category || '';
        document.getElementById('kh_url').value = card.url || '';
        document.getElementById('kh_icon_color').value = card.icon_color || '#0071e3';
        resetColorPicker(card.icon_color || '#0071e3');
        document.getElementById('cardSaveBtn').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="vertical-align:middle;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg> Update Resource';
    }

    panel.style.display = 'block';
    document.getElementById('kh_title').focus();
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function hideForm() {
    document.getElementById('formPanel').style.display = 'none';
}

function editCard(id) {
    var card = allCards.find(function(c) { return c.card_id == id; });
    if (card) showForm(card);
}

function deleteCard(id, title) {
    if (!confirm('Remove "' + title + '" from the Knowledge Hub?')) return;
    fetch('/api/knowledge-hub.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: csrfToken, card_id: id })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            khToast('Resource removed', 'success');
            loadCards();
        } else {
            khToast(data.message || 'Failed to remove', 'error');
        }
    })
    .catch(function() { khToast('An error occurred', 'error'); });
}

function saveCard(e) {
    e.preventDefault();
    var btn = document.getElementById('cardSaveBtn');
    btn.disabled = true;
    btn.style.opacity = '0.6';

    var fd = new FormData(e.target);
    var data = {};
    fd.forEach(function(v, k) { data[k] = v; });

    var isEdit = !!data.card_id;
    var url = '/api/knowledge-hub.php?action=' + (isEdit ? 'update' : 'create');

    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(function(r) { return r.json(); })
    .then(function(resp) {
        btn.disabled = false;
        btn.style.opacity = '1';
        if (resp.success) {
            hideForm();
            khToast(resp.message || 'Saved!', 'success');
            loadCards();
        } else {
            khToast(resp.message || 'Failed to save', 'error');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.style.opacity = '1';
        khToast('An error occurred', 'error');
    });
}

/* ── Helpers ───────────────────────────────────────────── */
function pickColor(el) {
    document.querySelectorAll('.color-dot').forEach(function(d) { d.classList.remove('selected'); });
    el.classList.add('selected');
    document.getElementById('kh_icon_color').value = el.getAttribute('data-color');
}

function resetColorPicker(color) {
    document.querySelectorAll('.color-dot').forEach(function(d) {
        d.classList.toggle('selected', d.getAttribute('data-color') === color);
    });
}

function hexToLightBg(hex) {
    var r = parseInt(hex.slice(1,3), 16);
    var g = parseInt(hex.slice(3,5), 16);
    var b = parseInt(hex.slice(5,7), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',0.1)';
}

function escHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function escAttr(str) {
    if (!str) return '';
    return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

function khToast(msg, type) {
    var toast = document.createElement('div');
    toast.className = 'kh-toast';
    toast.style.background = type === 'error' ? '#ff3b30' : '#34c759';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function() { toast.style.opacity = '0'; setTimeout(function() { toast.remove(); }, 300); }, 3000);
}
</script>

<?php include '../includes/footer.php'; ?>
