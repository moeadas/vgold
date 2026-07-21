<?php
/**
 * Victory Genomics CRM — Email Builder v2 (page shell)
 *
 * Loads campaign or template JSON from the DB and hands it to
 * the front-end EmailBuilder module (assets/js/email-builder.js).
 *
 * URL shapes supported:
 *   /pages/email-builder.php?campaign_id=123
 *   /pages/email-builder.php?template_id=45
 *   /pages/email-builder.php?mode=campaign&id=123
 *   /pages/email-builder.php?mode=template&id=45
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
requireLogin();
requireRole('Sales Manager');
$pageTitle   = 'Email Builder';
$currentPage = 'email-campaigns';
$csrf_token = generateCSRFToken();
$db         = Database::getInstance()->getConnection();

/* --------- Resolve target (campaign vs template) ---------- */
$mode = $_GET['mode'] ?? '';
$id   = isset($_GET['id']) ? intval($_GET['id']) : 0;
$campaignId = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : ($mode === 'campaign' ? $id : 0);
$templateId = isset($_GET['template_id']) ? intval($_GET['template_id']) : ($mode === 'template' ? $id : 0);
$isTemplate = ($templateId > 0 || $mode === 'template');

$existingJson = '{}';
$recordName   = '';
$recordSubject = '';

if ($campaignId > 0) {
    $stmt = $db->prepare("SELECT content_json, name, subject FROM email_campaigns WHERE campaign_id = ?");
    $stmt->execute([$campaignId]);
    $row = $stmt->fetch();
    if ($row) {
        $existingJson  = $row['content_json'] ?: '{}';
        $recordName    = $row['name'] ?? '';
        $recordSubject = $row['subject'] ?? '';
    }
} elseif ($templateId > 0) {
    $stmt = $db->prepare("SELECT content_json, name, subject FROM email_templates WHERE template_id = ?");
    $stmt->execute([$templateId]);
    $row = $stmt->fetch();
    if ($row) {
        $existingJson  = $row['content_json'] ?: '{}';
        $recordName    = $row['name'] ?? '';
        $recordSubject = $row['subject'] ?? '';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<!-- Email Builder v2 -->
<link rel="stylesheet" href="/assets/css/email-builder.css?v=2">
<div class="eb-app" id="eb-app">
  <!-- ============ Topbar ============ -->
  <div class="eb-topbar">
    <h2>
      <?php if ($campaignId): ?>
        Campaign #<?php echo $campaignId; ?>
      <?php elseif ($templateId): ?>
        Template #<?php echo $templateId; ?>
      <?php else: ?>
        Untitled email
      <?php endif; ?>
      <?php if ($recordName): ?>
        <span style="color:#6b7280;font-weight:400;font-size:14px;">— <?php echo htmlspecialchars($recordName); ?></span>
      <?php endif; ?>
    </h2>
    <div id="eb-toolbar-extra"></div>
    <div class="eb-spacer"></div>
    <button class="eb-btn" id="eb-tab-design" title="Design view">🎨</button>
    <button class="eb-btn" id="eb-tab-preview" title="Preview">👁</button>
    <button class="eb-save-btn" id="eb-save-btn">Save</button>
  </div>
  <!-- ============ Left palette ============ -->
  <aside class="eb-palette">
    <h4>Pre-built sections</h4>
    <div class="eb-pal-grid" id="eb-palette-sections">
      <div class="eb-pal-item is-wide" data-section-key="hero">
        <span class="eb-pal-ico">🎯</span><span>Hero</span>
      </div>
      <div class="eb-pal-item is-wide" data-section-key="twoCol">
        <span class="eb-pal-ico">▌▌</span><span>Image + Text</span>
      </div>
      <div class="eb-pal-item is-wide" data-section-key="features">
        <span class="eb-pal-ico">✨</span><span>3-Feature Grid</span>
      </div>
      <div class="eb-pal-item is-wide" data-section-key="cta">
        <span class="eb-pal-ico">📣</span><span>Call to Action</span>
      </div>
      <div class="eb-pal-item is-wide" data-section-key="footer">
        <span class="eb-pal-ico">⬇</span><span>Footer + Social</span>
      </div>
    </div>
    <h4>Full templates</h4>
    <div class="eb-pal-grid" id="eb-palette-templates">
      <div class="eb-pal-item is-wide" data-section-key="welcome">
        <span class="eb-pal-ico">👋</span><span>Welcome Email</span>
      </div>
      <div class="eb-pal-item is-wide" data-section-key="newsletter">
        <span class="eb-pal-ico">📰</span><span>Newsletter</span>
      </div>
      <div class="eb-pal-item is-wide" data-section-key="productLaunch">
        <span class="eb-pal-ico">🚀</span><span>Product Launch</span>
      </div>
      <div class="eb-pal-item is-wide" data-section-key="eventInvite">
        <span class="eb-pal-ico">🎟</span><span>Event Invite</span>
      </div>
      <div class="eb-pal-item is-wide" data-section-key="promoSale">
        <span class="eb-pal-ico">⚡</span><span>Promo / Sale</span>
      </div>
    </div>
    <h4>Empty layouts</h4>
    <div class="eb-pal-grid" id="eb-palette-layouts">
      <div class="eb-pal-item" data-cols="1" draggable="false">
        <span class="eb-pal-ico">▌</span><span>1 col</span>
      </div>
      <div class="eb-pal-item" data-cols="2">
        <span class="eb-pal-ico">▌▌</span><span>2 col</span>
      </div>
      <div class="eb-pal-item" data-cols="3">
        <span class="eb-pal-ico">▌▌▌</span><span>3 col</span>
      </div>
      <div class="eb-pal-item" data-cols="4">
        <span class="eb-pal-ico">▌▌▌▌</span><span>4 col</span>
      </div>
    </div>
    <h4>Blocks</h4>
    <div class="eb-pal-grid" id="eb-palette-blocks">
      <div class="eb-pal-item" data-block-type="heading"><span class="eb-pal-ico">H</span><span>Heading</span></div>
      <div class="eb-pal-item" data-block-type="text"><span class="eb-pal-ico">T</span><span>Text</span></div>
      <div class="eb-pal-item" data-block-type="image"><span class="eb-pal-ico">🖼</span><span>Image</span></div>
      <div class="eb-pal-item" data-block-type="button"><span class="eb-pal-ico">🔘</span><span>Button</span></div>
      <div class="eb-pal-item" data-block-type="divider"><span class="eb-pal-ico">—</span><span>Divider</span></div>
      <div class="eb-pal-item" data-block-type="spacer"><span class="eb-pal-ico">⇳</span><span>Spacer</span></div>
      <div class="eb-pal-item" data-block-type="social"><span class="eb-pal-ico">@</span><span>Social</span></div>
      <div class="eb-pal-item" data-block-type="video"><span class="eb-pal-ico">▶</span><span>Video</span></div>
      <div class="eb-pal-item" data-block-type="html"><span class="eb-pal-ico">&lt;/&gt;</span><span>HTML</span></div>
      <div class="eb-pal-item" data-block-type="footer"><span class="eb-pal-ico">⬇</span><span>Footer</span></div>
    </div>
  </aside>
  <!-- ============ Center canvas ============ -->
  <main class="eb-canvas-area">
    <div id="email-canvas"></div>
  </main>
  <!-- ============ Right properties panel ============ -->
  <aside class="eb-props" id="eb-properties"></aside>
  <!-- Preview overlay (toggled by eye button) -->
  <div class="eb-preview-wrap" id="eb-preview-wrap">
    <button class="eb-preview-close" id="eb-preview-close" title="Close preview">✕</button>
    <iframe id="eb-preview" title="Email preview"></iframe>
  </div>
</div>
<!-- SortableJS for drag-and-drop -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<!-- Email Builder engine -->
<script src="/assets/js/email-builder.js?v=2"></script>
<script>
(function () {
  var CSRF_TOKEN    = <?php echo json_encode($csrf_token); ?>;
  var EXISTING_JSON = <?php echo json_encode($existingJson); ?>;
  var CAMPAIGN_ID   = <?php echo json_encode($campaignId); ?>;
  var TEMPLATE_ID   = <?php echo json_encode($templateId); ?>;
  var IS_TEMPLATE   = <?php echo json_encode($isTemplate); ?>;
  var RECORD_NAME   = <?php echo json_encode($recordName); ?>;
  var RECORD_SUBJECT= <?php echo json_encode($recordSubject); ?>;

  // Build saveTarget for autosave
  var saveTarget = null;
  if (CAMPAIGN_ID) saveTarget = { kind: 'campaign', id: CAMPAIGN_ID };
  else if (TEMPLATE_ID) saveTarget = { kind: 'template', id: TEMPLATE_ID };

  // Seed editor meta with DB values so the body panel shows them
  var seedJson = EXISTING_JSON;
  try {
    var parsed = JSON.parse(EXISTING_JSON);
    if (!parsed.meta) parsed.meta = {};
    if (!parsed.meta.name)    parsed.meta.name    = RECORD_NAME || '';
    if (!parsed.meta.subject) parsed.meta.subject = RECORD_SUBJECT || '';
    seedJson = JSON.stringify(parsed);
  } catch (_) { /* ignore */ }

  document.addEventListener('DOMContentLoaded', function () {
    EmailBuilder.init({
      csrfToken: CSRF_TOKEN,
      existingJson: (seedJson && seedJson !== '{}') ? seedJson : null,
      saveTarget: saveTarget
    });

    // Manual save button (autosave already runs, this just forces it now)
    document.getElementById('eb-save-btn').addEventListener('click', function () {
      saveNow(true);
    });

    // Tab toggles
    document.getElementById('eb-tab-design').addEventListener('click', function () {
      document.getElementById('eb-preview-wrap').classList.remove('is-open');
    });
    document.getElementById('eb-tab-preview').addEventListener('click', function () {
      document.getElementById('eb-preview-wrap').classList.add('is-open');
    });
    // Close preview button
    document.getElementById('eb-preview-close').addEventListener('click', function () {
      document.getElementById('eb-preview-wrap').classList.remove('is-open');
    });
    // Also close on Escape key when preview is open
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        document.getElementById('eb-preview-wrap').classList.remove('is-open');
      }
    });
    // Warn before leaving with unsaved changes
    window.addEventListener('beforeunload', function (e) {
      if (document.title.indexOf('•') === 0) {
        e.preventDefault();
        e.returnValue = '';
      }
    });
  });

  showNotification = typeof showNotification === "function" ? showNotification : function(msg, type) {
    var div = document.createElement("div");
    div.className = "eb-toast eb-toast-" + (type || "info");
    div.textContent = msg;
    document.body.appendChild(div);
    setTimeout(function(){ div.style.opacity = "0"; setTimeout(function(){ div.remove(); }, 300); }, 3000);
  };

  function saveNow(showAlert) {
    if (!saveTarget) {
      if (typeof showNotification === 'function') {
        showNotification('Open this builder from a campaign or template to enable saving.', 'warning');
      } else {
        var $s = document.getElementById('eb-status');
        if ($s) { $s.textContent = 'Open from campaign/template to save'; $s.style.color = '#d97706'; setTimeout(function(){ $s.textContent = ''; }, 3000); }
      }
      return;
    }
    var json = EmailBuilder.getJSON();
    var html = EmailBuilder.getHTML();
    var url, body;
    if (saveTarget.kind === 'campaign') {
      url = '/api/email.php?action=campaign_save&_cb=' + Date.now();
      body = {
        csrf_token: CSRF_TOKEN,
        campaign_id: saveTarget.id,
        name: RECORD_NAME || 'Untitled Campaign',
        subject: RECORD_SUBJECT || 'No Subject',
        content_json: json,
        content_html: html
      };
    } else {
      url = '/api/email.php?action=template_save&_cb=' + Date.now();
      body = {
        csrf_token: CSRF_TOKEN,
        template_id: saveTarget.id,
        name: RECORD_NAME || 'Untitled Template',
        subject: RECORD_SUBJECT || '',
        content_json: json,
        content_html: html
      };
    }
    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); })
      .then(function (resp) {
        if (resp && resp.success) {
          if (showAlert) {
            var $s = document.getElementById('eb-status');
            if ($s) {
              $s.textContent = '✓ Saved';
              $s.style.color = '#16a34a';
              setTimeout(function () { $s.textContent = ''; }, 1800);
            }
          }
        } else {
          var errMsg = resp && resp.message ? resp.message : 'Unknown error';
          if (typeof showNotification === 'function') {
            showNotification('Save failed: ' + errMsg, 'error');
          } else {
            var $s = document.getElementById('eb-status');
            if ($s) { $s.textContent = 'Save failed'; $s.style.color = '#dc2626'; setTimeout(function(){ $s.textContent = ''; }, 3000); }
          }
        }
      })
      .catch(function () {
        if (typeof showNotification === 'function') {
          showNotification('Network error — could not save.', 'error');
        } else {
          var $s = document.getElementById('eb-status');
          if ($s) { $s.textContent = 'Network error'; $s.style.color = '#dc2626'; setTimeout(function(){ $s.textContent = ''; }, 3000); }
        }
      });
  }
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
