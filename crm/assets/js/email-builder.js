/**
 * Victory Genomics CRM — Email Builder v2
 * Production-grade visual email editor
 * - SortableJS-only DnD (palette clones, canvas/columns receive)
 * - Single source of truth: design JSON → canvas AND email-safe HTML
 * - Table-based HTML exporter (Outlook/Gmail/Apple Mail safe)
 * - Live iframe preview at desktop/tablet/mobile widths
 * - Undo/redo, autosave, RTL (LTR + RTL), merge tags, image library
 * - Floating inline text toolbar (bold/italic/link/color/size/list/align)
 * - Pre-built section templates (hero, features, CTA, footer)
 * - Keyboard shortcuts (Ctrl/Cmd+Z, Y, D, S; Del)
 * - Dark-mode preview, alt-text + contrast warnings
 *
 * Public API (used by pages/email-builder.php):
 *   EmailBuilder.init(opts)         — boot
 *   EmailBuilder.getJSON()          — returns content_json string
 *   EmailBuilder.getHTML()          — returns email-safe content_html string
 *   EmailBuilder.setPreviewSize(s)  — 'desktop' | 'tablet' | 'mobile'
 *   EmailBuilder.setDirection(d)    — 'ltr' | 'rtl'
 *   EmailBuilder.setDarkMode(b)     — boolean
 *   EmailBuilder.undo() / .redo()
 *   EmailBuilder.sendTest(toEmail)  — POSTs current HTML to send-test endpoint
 */
(function (root) {
  'use strict';
  /* ============================================================
   * Constants
   * ============================================================ */
  const BRAND = '#0071e3';
  const STORAGE_PREFIX = 'vg_email_builder_';
  const AUTOSAVE_DEBOUNCE_MS = 4000;
  const HISTORY_LIMIT = 60;
  const MAX_HTML_BYTES = 100 * 1024;          // Gmail clips at 102KB
  const MERGE_TAGS = [
    { tag: '{{first_name}}',     label: 'First name' },
    { tag: '{{last_name}}',      label: 'Last name' },
    { tag: '{{full_name}}',      label: 'Full name' },
    { tag: '{{email}}',          label: 'Email' },
    { tag: '{{company_name}}',   label: 'Company name' },
    { tag: '{{country}}',        label: 'Country' },
    { tag: '{{city}}',           label: 'City' },
    { tag: '{{unsubscribe_url}}',label: 'Unsubscribe URL' },
    { tag: '{{view_in_browser}}',label: 'View in browser URL' },
    { tag: '{{sender_name}}',    label: 'Sender name' },
    { tag: '{{sender_email}}',   label: 'Sender email' }
  ];
  const FONT_STACKS = [
    { v: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif", l: 'System Default' },
    { v: "Arial, Helvetica, sans-serif", l: 'Arial' },
    { v: "Georgia, 'Times New Roman', serif", l: 'Georgia' },
    { v: "'Helvetica Neue', Helvetica, Arial, sans-serif", l: 'Helvetica' },
    { v: "'Trebuchet MS', sans-serif", l: 'Trebuchet MS' },
    { v: "Verdana, Geneva, sans-serif", l: 'Verdana' },
    { v: "'Courier New', Courier, monospace", l: 'Courier' },
    { v: "Tahoma, Geneva, sans-serif", l: 'Tahoma' },
    { v: "'Lucida Sans', 'Lucida Sans Unicode', sans-serif", l: 'Lucida' },
    { v: "'Cairo', 'Tajawal', Arial, sans-serif", l: 'Cairo (Arabic)' },
    { v: "'Tajawal', 'Cairo', Arial, sans-serif", l: 'Tajawal (Arabic)' }
  ];
  /* ============================================================
   * Internal state
   * ============================================================ */
  const state = {
    body: defaultBody(),
    sections: [],
    meta: { dir: 'ltr', darkMode: false, name: '', subject: '' },
    selectedId: null,             // 'body' | sectionId | blockId
    selectedKind: 'body',         // 'body' | 'section' | 'block'
    previewSize: 'desktop',
    history: [],
    historyIdx: -1,
    sortableInstances: [],
    lastFocusedElement: null,
    autosaveTimer: null,
    autosaveUrl: '',
    saveTarget: null,             // {kind:'campaign'|'template', id:number}
    csrfToken: '',
    isDirty: false,
    onSaved: null
  };
  /* ============================================================
   * Defaults
   * ============================================================ */
  function defaultBody() {
    return {
      backgroundColor: '#f4f4f4',
      contentBackground: '#ffffff',
      fontFamily: FONT_STACKS[0].v,
      fontSize: 16,
      textColor: '#333333',
      linkColor: BRAND,
      lineHeight: 1.6,
      contentWidth: 600,
      paddingTop: 24,
      paddingBottom: 24,
      preheader: ''
    };
  }
  function defaultSection() {
    return {
      id: uid('sec'),
      columns: 1,
      content: [[]],
      bgColor: '#ffffff',
      paddingTop: 20, paddingBottom: 20, paddingLeft: 24, paddingRight: 24,
      borderTopWidth: 0, borderTopColor: '#e5e5e5', borderTopStyle: 'solid',
      borderBottomWidth: 0, borderBottomColor: '#e5e5e5', borderBottomStyle: 'solid',
      gap: 24,
      hideOnMobile: false,
      stackOnMobile: true
    };
  }
  const BLOCK_DEFAULTS = {
    text: {
      content: '<p>Start writing your message here. Click to edit. Use the floating toolbar to format.</p>',
      fontFamily: '',          // inherit body if empty
      fontSize: 16,
      fontWeight: '400',
      fontStyle: 'normal',
      textAlign: 'left',
      color: '',               // inherit body
      lineHeight: 1.6,
      letterSpacing: 0,
      paddingTop: 0, paddingBottom: 0, paddingLeft: 0, paddingRight: 0,
      backgroundColor: 'transparent',
      hideOnMobile: false,
      mobileFontSize: 0        // 0 = inherit
    },
    heading: {
      content: '<h2>Your headline here</h2>',
      level: 'h2',
      fontFamily: '',
      fontSize: 28,
      fontWeight: '700',
      fontStyle: 'normal',
      textAlign: 'left',
      color: '',
      lineHeight: 1.25,
      letterSpacing: 0,
      paddingTop: 0, paddingBottom: 0, paddingLeft: 0, paddingRight: 0,
      backgroundColor: 'transparent',
      hideOnMobile: false,
      mobileFontSize: 22
    },
    image: {
      src: 'https://placehold.co/600x300/eeeeee/999999?text=Image',
      alt: '',
      width: 100,             // percent of container
      align: 'center',
      borderRadius: 0,
      borderWidth: 0, borderColor: '#000000', borderStyle: 'solid',
      linkUrl: '',
      paddingTop: 0, paddingBottom: 0, paddingLeft: 0, paddingRight: 0,
      backgroundColor: 'transparent',
      hideOnMobile: false
    },
    button: {
      text: 'Click here',
      url: 'https://',
      bgColor: BRAND,
      textColor: '#ffffff',
      fontFamily: '',
      fontSize: 16,
      fontWeight: '600',
      borderRadius: 6,
      borderWidth: 0, borderColor: '#000000', borderStyle: 'solid',
      paddingV: 14, paddingH: 28,
      align: 'center',
      fullWidth: false,
      letterSpacing: 0,
      textTransform: 'none',
      paddingTop: 8, paddingBottom: 8,
      hideOnMobile: false
    },
    divider: {
      color: '#e0e0e0',
      thickness: 1,
      style: 'solid',
      width: 100,
      align: 'center',
      paddingTop: 16, paddingBottom: 16,
      hideOnMobile: false
    },
    spacer: {
      height: 32,
      backgroundColor: 'transparent',
      hideOnMobile: false
    },
    html: {
      content: '<!-- Custom HTML — be careful, some email clients are strict -->',
      paddingTop: 0, paddingBottom: 0,
      hideOnMobile: false
    },
    social: {
      align: 'center',
      iconSize: 28,
      iconColor: '#ffffff',
      bgColor: '#333333',
      spacing: 10,
      shape: 'circle',         // circle | rounded | square
      paddingTop: 8, paddingBottom: 8,
      platforms: [
        { id: 'facebook',  url: '', enabled: false },
        { id: 'twitter',   url: '', enabled: false },
        { id: 'linkedin',  url: '', enabled: false },
        { id: 'instagram', url: '', enabled: false },
        { id: 'youtube',   url: '', enabled: false }
      ],
      hideOnMobile: false
    },
    video: {
      thumbSrc: 'https://placehold.co/600x340/000000/ffffff?text=%E2%96%B6+Watch+video',
      url: 'https://',
      alt: 'Watch video',
      paddingTop: 0, paddingBottom: 0,
      hideOnMobile: false
    },
    footer: {
      content: '<p style="font-size:12px;color:#999;text-align:center;">You received this email because you subscribed to our list. <a href="{{unsubscribe_url}}" style="color:#999;">Unsubscribe</a> · <a href="{{view_in_browser}}" style="color:#999;">View in browser</a></p>',
      paddingTop: 8, paddingBottom: 8,
      hideOnMobile: false
    }
  };
  /* Pre-built section templates */
  const SECTION_TEMPLATES = {
    hero: function () {
      const s = defaultSection();
      s.bgColor = '#111111';
      s.paddingTop = 60; s.paddingBottom = 60;
      s.content[0] = [
        blockOf('heading', {
          content: '<h1 style="margin:0;">Welcome aboard 🎉</h1>',
          color: '#ffffff', textAlign: 'center', fontSize: 36
        }),
        blockOf('text', {
          content: '<p style="margin:12px 0 0;">Thanks for joining us. Here is what to expect next.</p>',
          color: '#cccccc', textAlign: 'center'
        }),
        blockOf('spacer', { height: 16 }),
        blockOf('button', { text: 'Get started', url: 'https://' })
      ];
      return s;
    },
    twoCol: function () {
      const s = defaultSection();
      s.columns = 2; s.content = [[], []];
      s.content[0] = [
        blockOf('image', { src: 'https://placehold.co/280x180/eeeeee/999999?text=Image' }),
      ];
      s.content[1] = [
        blockOf('heading', { content: '<h3>Feature title</h3>', fontSize: 22 }),
        blockOf('text',    { content: '<p>Briefly describe the feature in one or two sentences.</p>' }),
        blockOf('button',  { text: 'Learn more', align: 'left' })
      ];
      return s;
    },
    features: function () {
      const s = defaultSection();
      s.columns = 3; s.content = [[], [], []];
      for (let i = 0; i < 3; i++) {
        s.content[i] = [
          blockOf('image',   { src: 'https://placehold.co/120x120/eeeeee/999999?text=%E2%9C%A8', width: 60, align: 'center' }),
          blockOf('heading', { content: '<h4 style="margin:8px 0;">Benefit ' + (i+1) + '</h4>', textAlign: 'center', fontSize: 18 }),
          blockOf('text',    { content: '<p style="margin:0;">Short supporting copy.</p>', textAlign: 'center', fontSize: 14, color: '#666' })
        ];
      }
      return s;
    },
    cta: function () {
      const s = defaultSection();
      s.bgColor = '#f0f7ff';
      s.paddingTop = 48; s.paddingBottom = 48;
      s.content[0] = [
        blockOf('heading', { content: '<h2>Ready to get started?</h2>', textAlign: 'center' }),
        blockOf('text',    { content: '<p>Take the next step in under a minute.</p>', textAlign: 'center', color: '#666' }),
        blockOf('button',  { text: 'Start free trial', align: 'center' })
      ];
      return s;
    },
    footer: function () {
      const s = defaultSection();
      s.bgColor = '#f4f4f4';
      s.paddingTop = 24; s.paddingBottom = 24;
      s.content[0] = [
        blockOf('social', { platforms: [
          { id: 'facebook',  url: '#', enabled: true },
          { id: 'twitter',   url: '#', enabled: true },
          { id: 'linkedin',  url: '#', enabled: true },
          { id: 'instagram', url: '#', enabled: true },
          { id: 'youtube',   url: '',  enabled: false }
        ]}),
        blockOf('footer', {})
      ];
      return s;
    },
    // ===== Full Email Templates =====
    welcome: function () {
      const s = defaultSection();
      s.bgColor = '#ffffff';
      s.content[0] = [
        blockOf('heading', { content: '<h1 style="margin:0;color:#111;">Welcome to [Company]</h1>', textAlign: 'center', fontSize: 32, color: '#111111' }),
        blockOf('text', { content: '<p style="color:#444;">Hi {{first_name}}, we\'re thrilled to have you on board! Here is everything you need to get started.</p>', textAlign: 'center', color: '#444444' }),
        blockOf('spacer', { height: 8 }),
        blockOf('button', { text: 'Complete your profile', url: '#', align: 'center', bgColor: '#0071e3', textColor: '#ffffff', fullWidth: true }),
        blockOf('divider', { color: '#e5e5e5' }),
        blockOf('heading', { content: '<h3 style="margin:0;">Quick tips</h3>', fontSize: 20, textAlign: 'left' }),
        blockOf('text', { content: '<ul><li>Set up your preferences</li><li>Invite your team</li><li>Explore the dashboard</li></ul>', fontSize: 14 }),
        blockOf('spacer', { height: 8 }),
        blockOf('text', { content: '<p style="font-size:13px;color:#888;text-align:center;">Need help? Reply to this email or <a href="#" style="color:#0071e3;">contact support</a>.</p>', fontSize: 13, color: '#888888' })
      ];
      return s;
    },
    newsletter: function () {
      const s = defaultSection();
      s.bgColor = '#ffffff';
      s.content[0] = [
        blockOf('heading', { content: '<h1 style="margin:0;">Weekly Roundup</h1>', textAlign: 'center', fontSize: 30, color: '#111111' }),
        blockOf('text', { content: '<p style="color:#666;text-align:center;">Your curated industry insights, delivered every Tuesday.</p>', textAlign: 'center', color: '#666666' }),
        blockOf('divider', { color: '#e5e5e5' }),
        blockOf('image', { src: 'https://placehold.co/600x300/e8e8e8/999999?text=Featured+Story', width: 100, align: 'center' }),
        blockOf('heading', { content: '<h2 style="margin:8px 0 0;">Headline goes here</h2>', fontSize: 22, color: '#111111' }),
        blockOf('text', { content: '<p style="color:#444;">A short teaser that hooks the reader and leads them to the full article. Keep it punchy and under two lines.</p>', fontSize: 15, color: '#444444' }),
        blockOf('button', { text: 'Read the full story', url: '#', align: 'left', bgColor: '#333333', textColor: '#ffffff' }),
        blockOf('spacer', { height: 16 }),
        blockOf('divider', { color: '#e5e5e5' }),
        blockOf('heading', { content: '<h3>More reads</h3>', fontSize: 18 }),
        blockOf('text', { content: '<ul><li><a href="#" style="color:#0071e3;">Article title one</a> — 3 min read</li><li><a href="#" style="color:#0071e3;">Article title two</a> — 5 min read</li><li><a href="#" style="color:#0071e3;">Article title three</a> — 4 min read</li></ul>', fontSize: 14 })
      ];
      return s;
    },
    productLaunch: function () {
      const s = defaultSection();
      s.bgColor = '#ffffff';
      s.content[0] = [
        blockOf('heading', { content: '<h1 style="margin:0;">Introducing [Product Name]</h1>', textAlign: 'center', fontSize: 34, color: '#111111' }),
        blockOf('text', { content: '<p style="color:#666;text-align:center;">The all-new way to [solve problem]. Built for teams that move fast.</p>', textAlign: 'center', color: '#666666' }),
        blockOf('spacer', { height: 16 }),
        blockOf('image', { src: 'https://placehold.co/600x340/eeeeee/999999?text=Product+Screenshot', width: 100, align: 'center' }),
        blockOf('spacer', { height: 16 }),
        blockOf('heading', { content: '<h2 style="margin:0;">What is new</h2>', fontSize: 22, color: '#111111' }),
        blockOf('text', { content: '<ul><li><strong>Feature One</strong> — Brief description here</li><li><strong>Feature Two</strong> — Brief description here</li><li><strong>Feature Three</strong> — Brief description here</li></ul>', fontSize: 15, color: '#444444' }),
        blockOf('spacer', { height: 16 }),
        blockOf('button', { text: 'Get early access', url: '#', align: 'center', bgColor: '#0071e3', textColor: '#ffffff', fontSize: 18, paddingV: 18, paddingH: 32, fullWidth: true }),
        blockOf('spacer', { height: 8 }),
        blockOf('text', { content: '<p style="font-size:12px;color:#999;text-align:center;">Early-bird pricing ends soon. No credit card required.</p>', fontSize: 12, color: '#999999', textAlign: 'center' })
      ];
      return s;
    },
    eventInvite: function () {
      const s = defaultSection();
      s.bgColor = '#ffffff';
      s.content[0] = [
        blockOf('heading', { content: '<h1 style="margin:0;">You are invited 🎉</h1>', textAlign: 'center', fontSize: 36, color: '#111111' }),
        blockOf('text', { content: '<p style="color:#666;text-align:center;">Join us for an exclusive live session.</p>', textAlign: 'center', color: '#666666' }),
        blockOf('spacer', { height: 16 }),
        blockOf('image', { src: 'https://placehold.co/600x280/eeeeee/999999?text=Event+Banner', width: 100, align: 'center' }),
        blockOf('spacer', { height: 24 }),
        blockOf('heading', { content: '<h2 style="margin:0;">📅 Date \u0026 Time</h2>', fontSize: 20, color: '#111111' }),
        blockOf('text', { content: '<p><strong>Thursday, June 15, 2026</strong><br>2:00 PM — 3:30 PM EST</p>', fontSize: 15, color: '#444444' }),
        blockOf('heading', { content: '<h2 style="margin:16px 0 0;">📍 Location</h2>', fontSize: 20, color: '#111111' }),
        blockOf('text', { content: '<p>Online via Zoom<br><a href="#" style="color:#0071e3;">Add to calendar</a></p>', fontSize: 15, color: '#444444' }),
        blockOf('spacer', { height: 24 }),
        blockOf('button', { text: 'RSVP Now', url: '#', align: 'center', bgColor: '#0071e3', textColor: '#ffffff', fontSize: 18, paddingV: 16, paddingH: 48 }),
        blockOf('spacer', { height: 8 }),
        blockOf('text', { content: '<p style="font-size:13px;color:#888;text-align:center;">Can\'t make it? <a href="#" style="color:#0071e3;">Watch the replay</a> later.</p>', fontSize: 13, color: '#888888', textAlign: 'center' })
      ];
      return s;
    },
    promoSale: function () {
      const s = defaultSection();
      s.bgColor = '#0071e3';
      s.paddingTop = 48; s.paddingBottom = 48;
      s.content[0] = [
        blockOf('heading', { content: '<h1 style="margin:0;color:#fff;">FLASH SALE ⚡</h1>', textAlign: 'center', fontSize: 42, color: '#ffffff' }),
        blockOf('text', { content: '<p style="color:#fff;text-align:center;font-size:18px;">Up to <strong>50% off</strong> everything. This weekend only.</p>', textAlign: 'center', fontSize: 18, color: '#ffffff' }),
        blockOf('spacer', { height: 24 }),
        blockOf('button', { text: 'Shop the sale', url: '#', align: 'center', bgColor: '#ffffff', textColor: '#0071e3', fontSize: 18, paddingV: 18, paddingH: 40 }),
        blockOf('spacer', { height: 8 }),
        blockOf('text', { content: '<p style="color:#fff;text-align:center;font-size:13px;opacity:.8;">Use code <strong>FLASH50</strong> at checkout. Expires Sunday midnight.</p>', textAlign: 'center', fontSize: 13, color: '#ffffff' })
      ];
      return s;
    }
  };
  /* ============================================================
   * Utilities
   * ============================================================ */
  function uid(p) { return p + '_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function px(v)    { return (v === null || v === undefined || v === '') ? '' : (parseFloat(v) + 'px'); }
  function esc(s)   { return (s == null ? '' : String(s)).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch])); }
  function blockOf(type, overrides) {
    return { id: uid('blk'), type: type, data: Object.assign(clone(BLOCK_DEFAULTS[type]), overrides || {}) };
  }
  function findBlock(blockId) {
    for (const sec of state.sections) {
      for (let c = 0; c < sec.content.length; c++) {
        for (let b = 0; b < sec.content[c].length; b++) {
          if (sec.content[c][b].id === blockId) return { sec, col: c, idx: b, block: sec.content[c][b] };
        }
      }
    }
    return null;
  }
  function findSection(secId) { return state.sections.find(s => s.id === secId) || null; }
  /* Color helpers (for contrast warning) */
  function hexToRgb(hex) {
    const m = /^#?([a-f0-9]{6}|[a-f0-9]{3})$/i.exec(hex || '');
    if (!m) return null;
    let h = m[1];
    if (h.length === 3) h = h.split('').map(c => c + c).join('');
    return { r: parseInt(h.slice(0,2),16), g: parseInt(h.slice(2,4),16), b: parseInt(h.slice(4,6),16) };
  }
  function luminance(rgb) {
    const a = ['r','g','b'].map(k => {
      let v = rgb[k] / 255;
      return v <= 0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4);
    });
    return 0.2126*a[0] + 0.7152*a[1] + 0.0722*a[2];
  }
  function contrast(c1, c2) {
    const a = hexToRgb(c1), b = hexToRgb(c2);
    if (!a || !b) return null;
    const L1 = luminance(a), L2 = luminance(b);
    return ((Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05)).toFixed(2);
  }
  /* ============================================================
   * History (undo/redo)
   * ============================================================ */
  function snapshot() {
    return { body: clone(state.body), sections: clone(state.sections), meta: clone(state.meta) };
  }
  function pushHistory() {
    state.history = state.history.slice(0, state.historyIdx + 1);
    state.history.push(snapshot());
    if (state.history.length > HISTORY_LIMIT) state.history.shift();
    state.historyIdx = state.history.length - 1;
    state.isDirty = true;
    scheduleAutosave();
    updateToolbarButtons();
  }
  function restore(snap) {
    state.body = clone(snap.body);
    state.sections = clone(snap.sections);
    state.meta = clone(snap.meta);
    renderCanvas();
    renderPreview();
  }
  function undo() {
    if (state.historyIdx <= 0) return;
    state.historyIdx--;
    restore(state.history[state.historyIdx]);
    updateToolbarButtons();
  }
  function redo() {
    if (state.historyIdx >= state.history.length - 1) return;
    state.historyIdx++;
    restore(state.history[state.historyIdx]);
    updateToolbarButtons();
  }
  /* ============================================================
   * Autosave
   * ============================================================ */
  function scheduleAutosave() {
    if (!state.saveTarget) return;
    clearTimeout(state.autosaveTimer);
    state.autosaveTimer = setTimeout(autosave, AUTOSAVE_DEBOUNCE_MS);
    setStatus('Editing…');
  }
  function autosave() {
    if (!state.saveTarget || !state.isDirty) return;
    setStatus('Saving…');
    const payload = buildSavePayload();
    fetch(payload.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload.body),
      credentials: 'same-origin'
    }).then(r => r.json()).then(resp => {
      if (resp && resp.success) {
        state.isDirty = false;
        setStatus('Saved');
        setTimeout(() => setStatus(''), 1800);
        if (typeof state.onSaved === 'function') state.onSaved();
      } else {
        setStatus('Save failed', true);
      }
    }).catch(() => setStatus('Offline — changes kept locally', true));
    // Local backup
    try {
      localStorage.setItem(STORAGE_PREFIX + (state.saveTarget.kind) + '_' + state.saveTarget.id, JSON.stringify(snapshot()));
    } catch (_) {}
  }
  function buildSavePayload() {
    const json = getJSON();
    const html = getHTML();
    if (state.saveTarget.kind === 'campaign') {
      return {
        url: '/api/email.php?action=campaign_save&_cb=' + Date.now(),
        body: {
          csrf_token: state.csrfToken,
          campaign_id: state.saveTarget.id,
          name: state.meta.name || 'Untitled Campaign',
          subject: state.meta.subject || 'No Subject',
          content_json: json,
          content_html: html
        }
      };
    }
    return {
      url: '/api/email.php?action=template_save&_cb=' + Date.now(),
      body: {
        csrf_token: state.csrfToken,
        template_id: state.saveTarget.id,
        name: state.meta.name || 'Untitled Template',
        subject: state.meta.subject || '',
        content_json: json,
        content_html: html
      }
    };
  }
  function setStatus(msg, isError) {
    const $s = document.getElementById('eb-status');
    if (!$s) return;
    $s.textContent = msg || '';
    $s.style.color = isError ? '#c0392b' : '#666';
  }
  /* ============================================================
   * Boot
   * ============================================================ */
  function init(opts) {
    opts = opts || {};
    state.csrfToken = opts.csrfToken || '';
    if (opts.saveTarget) state.saveTarget = opts.saveTarget;
    if (opts.onSaved) state.onSaved = opts.onSaved;
    // Parse existing JSON (supports the legacy "sections+body" shape)
    if (opts.existingJson) {
      try {
        const parsed = typeof opts.existingJson === 'string' ? JSON.parse(opts.existingJson) : opts.existingJson;
        if (parsed && parsed.sections) {
          state.sections = migrateSections(parsed.sections);
          if (parsed.body) Object.assign(state.body, parsed.body);
          if (parsed.meta) Object.assign(state.meta, parsed.meta);
        } else if (Array.isArray(parsed)) {
          state.sections = parsed.map(legacyToSection);
        }
      } catch (e) { console.warn('EmailBuilder: existingJson parse failed', e); }
    }
    // Empty state — seed with a friendly first section
    if (!state.sections.length) {
      state.sections.push(SECTION_TEMPLATES.hero());
    }
    document.documentElement.setAttribute('data-eb-dir', state.meta.dir);
    if (state.meta.darkMode) document.documentElement.classList.add('eb-dark-preview');
    buildChrome();
    renderCanvas();
    renderPreview();
    selectBody();
    pushHistory();           // initial snapshot
    state.isDirty = false;
    bindKeyboard();
  }
  function migrateSections(secs) {
    return secs.map(s => {
      const fresh = Object.assign(defaultSection(), s);
      if (!fresh.id) fresh.id = uid('sec');
      // Old blocks may not have all fields — merge with defaults
      fresh.content = (s.content || []).map(col => col.map(b => {
        const tplDef = BLOCK_DEFAULTS[b.type] || BLOCK_DEFAULTS.text;
        return { id: b.id || uid('blk'), type: b.type, data: Object.assign(clone(tplDef), b.data || {}) };
      }));
      // Ensure column count matches content array length
      if (fresh.content.length !== fresh.columns) {
        if (fresh.content.length < fresh.columns) {
          while (fresh.content.length < fresh.columns) fresh.content.push([]);
        } else {
          // Move overflow into the last legal column
          const overflow = fresh.content.slice(fresh.columns).flat();
          fresh.content = fresh.content.slice(0, fresh.columns);
          fresh.content[fresh.columns - 1] = fresh.content[fresh.columns - 1].concat(overflow);
        }
      }
      return fresh;
    });
  }
  function legacyToSection(b) {
    const s = defaultSection();
    s.content = [[{ id: uid('blk'), type: b.type || 'text', data: Object.assign(clone(BLOCK_DEFAULTS[b.type] || BLOCK_DEFAULTS.text), b.data || {}) }]];
    return s;
  }
  /* ============================================================
   * Chrome (toolbar + status)
   * ============================================================ */
  function buildChrome() {
    const $tb = document.getElementById('eb-toolbar-extra');
    if (!$tb) return;
    $tb.innerHTML = `
      <button class="eb-btn" id="eb-undo"   title="Undo (Ctrl+Z)">↶</button>
      <button class="eb-btn" id="eb-redo"   title="Redo (Ctrl+Y)">↷</button>
      <span class="eb-sep"></span>
      <button class="eb-btn" data-size="desktop" title="Desktop">🖥</button>
      <button class="eb-btn" data-size="tablet"  title="Tablet">📱</button>
      <button class="eb-btn" data-size="mobile"  title="Mobile">📱̇</button>
      <span class="eb-sep"></span>
      <button class="eb-btn" id="eb-dark"   title="Toggle dark-mode preview">🌗</button>
      <button class="eb-btn" id="eb-dir"    title="Toggle text direction (LTR/RTL)">⇆</button>
      <button class="eb-btn" id="eb-code"   title="View exported HTML">{ }</button>
      <span class="eb-sep"></span>
      <input type="email" id="eb-test-email" placeholder="Test email" style="padding:4px 8px;border:1px solid #ccc;border-radius:4px;font-size:12px;width:140px;">
      <button class="eb-btn" id="eb-test"   title="Send test email">✈</button>
      <span class="eb-sep"></span>
      <span id="eb-status" class="eb-status"></span>
    `;
    $tb.querySelector('#eb-undo').onclick = undo;
    $tb.querySelector('#eb-redo').onclick = redo;
    $tb.querySelectorAll('[data-size]').forEach(btn => {
      btn.onclick = () => setPreviewSize(btn.dataset.size);
    });
    $tb.querySelector('#eb-dark').onclick = () => setDarkMode(!state.meta.darkMode);
    $tb.querySelector('#eb-dir').onclick  = () => setDirection(state.meta.dir === 'rtl' ? 'ltr' : 'rtl');
    $tb.querySelector('#eb-code').onclick = () => showHtmlModal();
    $tb.querySelector('#eb-test').onclick = () => promptSendTest();
    // Active preview size button
    updateToolbarButtons();
  }
  function updateToolbarButtons() {
    document.querySelectorAll('#eb-toolbar-extra [data-size]').forEach(b => {
      b.classList.toggle('is-active', b.dataset.size === state.previewSize);
    });
    const u = document.getElementById('eb-undo');
    const r = document.getElementById('eb-redo');
    if (u) u.disabled = state.historyIdx <= 0;
    if (r) r.disabled = state.historyIdx >= state.history.length - 1;
  }
  /* ============================================================
   * Canvas rendering (the editable surface)
   * ============================================================ */
  function renderCanvas() {
    const $c = document.getElementById('email-canvas');
    if (!$c) return;
    destroyAllSortables();
    $c.style.background    = state.body.backgroundColor;
    $c.style.padding       = px(state.body.paddingTop) + ' 0 ' + px(state.body.paddingBottom);
    $c.style.fontFamily    = state.body.fontFamily;
    $c.style.fontSize      = px(state.body.fontSize);
    $c.style.color         = state.body.textColor;
    $c.style.lineHeight    = state.body.lineHeight;
    $c.setAttribute('dir', state.meta.dir);
    // Inner wrapper at content width
    const $wrap = document.createElement('div');
    $wrap.id = 'eb-canvas-wrap';
    $wrap.style.maxWidth = px(state.body.contentWidth);
    $wrap.style.margin = '0 auto';
    $wrap.style.background = state.body.contentBackground;
    $wrap.style.minHeight = '120px';
    if (!state.sections.length) {
      const $empty = document.createElement('div');
      $empty.className = 'eb-empty';
      $empty.textContent = state.meta.dir === 'rtl'
        ? 'اسحب قسماً من اليسار للبدء'
        : 'Drag a section from the palette to start';
      $wrap.appendChild($empty);
    }
    state.sections.forEach(sec => $wrap.appendChild(renderSection(sec)));
    $c.innerHTML = '';
    $c.appendChild($wrap);
    initSortables();
    renderPreview();
    // Delay selection outline to ensure SortableJS has finished DOM manipulation
    requestAnimationFrame(() => {
      updateSelectionOutline();
      if (state.selectedId) renderProperties();
    });
  }
  function renderSection(sec) {
    const $sec = document.createElement('div');
    $sec.className = 'eb-section';
    $sec.dataset.secId = sec.id;
    $sec.style.background    = sec.bgColor;
    $sec.style.paddingTop    = px(sec.paddingTop);
    $sec.style.paddingBottom = px(sec.paddingBottom);
    $sec.style.paddingLeft   = px(sec.paddingLeft);
    $sec.style.paddingRight  = px(sec.paddingRight);
    if (sec.borderTopWidth)    $sec.style.borderTop    = sec.borderTopWidth + 'px ' + sec.borderTopStyle + ' ' + sec.borderTopColor;
    if (sec.borderBottomWidth) $sec.style.borderBottom = sec.borderBottomWidth + 'px ' + sec.borderBottomStyle + ' ' + sec.borderBottomColor;
    // Section hover toolbar
    const $tb = document.createElement('div');
    $tb.className = 'eb-section-tools';
    $tb.innerHTML = `
      <button title="Move up"      data-act="up">⌃</button>
      <button title="Move down"    data-act="down">⌄</button>
      <button title="Duplicate"    data-act="dup">⎘</button>
      <button title="Settings"     data-act="set">⚙</button>
      <button title="Delete"       data-act="del" class="danger">×</button>
    `;
    $tb.addEventListener('click', e => {
      const act = e.target.closest('button')?.dataset.act;
      if (!act) return;
      e.stopPropagation();
      sectionAction(sec.id, act);
    });
    $sec.appendChild($tb);
    // Columns grid (in editor we use CSS grid for UX; exporter uses tables)
    const $row = document.createElement('div');
    $row.className = 'eb-row';
    $row.style.display = 'grid';
    $row.style.gridTemplateColumns = 'repeat(' + sec.columns + ', 1fr)';
    $row.style.gap = px(sec.gap);
    for (let c = 0; c < sec.columns; c++) {
      const $col = document.createElement('div');
      $col.className = 'eb-col';
      $col.dataset.secId = sec.id;
      $col.dataset.colIdx = c;
      $col.style.minHeight = '40px';
      const blocks = sec.content[c] || [];
      if (!blocks.length) {
        const $hint = document.createElement('div');
        $hint.className = 'eb-col-empty';
        $hint.textContent = state.meta.dir === 'rtl' ? 'اسحب عنصراً هنا' : 'Drag a block here';
        $col.appendChild($hint);
      }
      blocks.forEach(b => $col.appendChild(renderBlock(b, sec.id, c)));
      $row.appendChild($col);
    }
    $sec.appendChild($row);
    $sec.addEventListener('click', e => {
      // Select section unless clicking on a block or toolbar
      const target = e.target;
      const isToolbar = target.closest('.eb-section-tools');
      const isBlock = target.closest('.eb-block');
      if (!isToolbar && !isBlock) selectSection(sec.id);
    });
    return $sec;
  }
  function renderBlock(blk, secId, colIdx) {
    const $b = document.createElement('div');
    $b.className = 'eb-block eb-block-' + blk.type;
    $b.dataset.blkId = blk.id;
    $b.dataset.secId = secId;
    $b.dataset.colIdx = colIdx;
    const d = blk.data;
    switch (blk.type) {
      case 'text':
      case 'heading':
      case 'footer':
        $b.contentEditable = 'true';
        $b.innerHTML = d.content;
        applyTextStyle($b, d);
        $b.addEventListener('focus', () => {
          state.lastFocusedElement = $b;
          selectBlock(blk.id);
        });
        $b.addEventListener('blur',  () => {
          d.content = $b.innerHTML;
          pushHistory();
        });
        $b.addEventListener('input', () => { /* keep state in sync silently; snapshot on blur */ d.content = $b.innerHTML; });
        break;
      case 'image': {
        const $imgWrap = document.createElement('div');
        $imgWrap.style.textAlign = d.align;
        $imgWrap.style.background = d.backgroundColor;
        $imgWrap.style.paddingTop = px(d.paddingTop);
        $imgWrap.style.paddingBottom = px(d.paddingBottom);
        const $img = document.createElement('img');
        $img.src = d.src; $img.alt = d.alt || '';
        $img.style.maxWidth = '100%';
        $img.style.width = d.width + '%';
        $img.style.borderRadius = px(d.borderRadius);
        if (d.borderWidth) $img.style.border = d.borderWidth + 'px ' + d.borderStyle + ' ' + d.borderColor;
        $img.style.display = 'inline-block';
        if (d.linkUrl) {
          // Visual indicator only in editor — exporter writes the real <a>
          $imgWrap.classList.add('eb-image-linked');
          $imgWrap.title = d.linkUrl;
        }
        if (!d.alt) {
          $imgWrap.classList.add('eb-warn-alt');
          $imgWrap.dataset.warn = state.meta.dir === 'rtl' ? 'يفتقد نص بديل' : 'Missing alt text';
        }
        $imgWrap.appendChild($img);
        $b.appendChild($imgWrap);
        break;
      }
      case 'button': {
        const $wrap = document.createElement('div');
        $wrap.style.textAlign = d.align;
        $wrap.style.paddingTop = px(d.paddingTop);
        $wrap.style.paddingBottom = px(d.paddingBottom);
        const $btn = document.createElement('a');
        $btn.href = d.url || '#';
        $btn.textContent = d.text;
        $btn.style.display = d.fullWidth ? 'block' : 'inline-block';
        $btn.style.backgroundColor = d.bgColor;
        $btn.style.color = d.textColor;
        $btn.style.fontSize = px(d.fontSize);
        $btn.style.fontWeight = d.fontWeight;
        $btn.style.fontFamily = d.fontFamily || 'inherit';
        $btn.style.borderRadius = px(d.borderRadius);
        if (d.borderWidth) $btn.style.border = d.borderWidth + 'px ' + d.borderStyle + ' ' + d.borderColor;
        $btn.style.padding = px(d.paddingV) + ' ' + px(d.paddingH);
        $btn.style.textDecoration = 'none';
        $btn.style.textTransform = d.textTransform;
        if (d.letterSpacing) $btn.style.letterSpacing = px(d.letterSpacing);
        $wrap.appendChild($btn);
        $b.appendChild($wrap);
        // Contrast check
        const ratio = contrast(d.bgColor, d.textColor);
        if (ratio !== null && ratio < 4.5) {
          $b.classList.add('eb-warn-contrast');
          $b.dataset.warn = (state.meta.dir === 'rtl' ? 'تباين منخفض ' : 'Low contrast ') + ratio + ':1';
        }
        break;
      }
      case 'divider': {
        const $wrap = document.createElement('div');
        $wrap.style.paddingTop = px(d.paddingTop);
        $wrap.style.paddingBottom = px(d.paddingBottom);
        $wrap.style.textAlign = d.align;
        const $hr = document.createElement('hr');
        $hr.style.border = 'none';
        $hr.style.borderTop = d.thickness + 'px ' + d.style + ' ' + d.color;
        $hr.style.width = d.width + '%';
        $hr.style.margin = d.align === 'center' ? '0 auto' : (d.align === 'right' ? '0 0 0 auto' : '0');
        $wrap.appendChild($hr);
        $b.appendChild($wrap);
        break;
      }
      case 'spacer': {
        const $sp = document.createElement('div');
        $sp.style.height = px(d.height);
        $sp.style.background = d.backgroundColor;
        $sp.style.lineHeight = '1px';
        $sp.innerHTML = '&nbsp;';
        $b.appendChild($sp);
        break;
      }
      case 'html': {
        const $code = document.createElement('div');
        $code.innerHTML = d.content;
        $b.appendChild($code);
        const $tag = document.createElement('div');
        $tag.className = 'eb-html-tag';
        $tag.textContent = 'HTML';
        $b.appendChild($tag);
        break;
      }
      case 'social': {
        $b.appendChild(renderSocialEditor(d));
        break;
      }
      case 'video': {
        const $w = document.createElement('a');
        $w.href = d.url || '#';
        $w.target = '_blank';
        $w.rel = 'noopener';
        const $im = document.createElement('img');
        $im.src = d.thumbSrc;
        $im.alt = d.alt || '';
        $im.style.maxWidth = '100%';
        $im.style.display = 'block';
        $w.appendChild($im);
        $b.appendChild($w);
        break;
      }
    }
    // Block hover toolbar
    const $tb = document.createElement('div');
    $tb.className = 'eb-block-tools';
    $tb.innerHTML = `
      <button title="Drag" data-act="drag" class="eb-handle">⋮⋮</button>
      <button title="Duplicate" data-act="dup">⎘</button>
      <button title="Delete" data-act="del" class="danger">×</button>
    `;
    $tb.addEventListener('click', e => {
      const act = e.target.closest('button')?.dataset.act;
      if (!act || act === 'drag') return;
      e.stopPropagation();
      blockAction(blk.id, act);
    });
    $b.appendChild($tb);
    $b.addEventListener('click', e => {
      e.stopPropagation();
      if (blk.type === 'text' || blk.type === 'heading' || blk.type === 'footer') return; // text blocks: click places cursor, focus selects
      selectBlock(blk.id);
    });
    if (d.hideOnMobile) $b.classList.add('eb-hide-mobile');
    return $b;
  }
  function renderSocialEditor(d) {
    const $w = document.createElement('div');
    $w.style.textAlign = d.align;
    $w.style.paddingTop = px(d.paddingTop);
    $w.style.paddingBottom = px(d.paddingBottom);
    const radius = d.shape === 'circle' ? '50%' : d.shape === 'rounded' ? '6px' : '0';
    d.platforms.forEach(p => {
      if (!p.enabled) return;
      const $a = document.createElement('span');
      $a.title = p.id;
      $a.style.display = 'inline-block';
      $a.style.width = px(d.iconSize * 1.6);
      $a.style.height = px(d.iconSize * 1.6);
      $a.style.lineHeight = px(d.iconSize * 1.6);
      $a.style.margin = '0 ' + px(d.spacing / 2);
      $a.style.background = d.bgColor;
      $a.style.color = d.iconColor;
      $a.style.borderRadius = radius;
      $a.style.fontWeight = '700';
      $a.style.textAlign = 'center';
      $a.textContent = p.id[0].toUpperCase();
      $w.appendChild($a);
    });
    if (!d.platforms.some(p => p.enabled)) {
      const $hint = document.createElement('div');
      $hint.className = 'eb-col-empty';
      $hint.textContent = state.meta.dir === 'rtl' ? 'فعّل المنصات في الإعدادات' : 'Enable platforms in the right panel';
      $w.appendChild($hint);
    }
    return $w;
  }
  function applyTextStyle(el, d) {
    if (d.fontFamily) el.style.fontFamily = d.fontFamily;
    el.style.fontSize = px(d.fontSize);
    el.style.fontWeight = d.fontWeight;
    el.style.fontStyle = d.fontStyle;
    el.style.textAlign = d.textAlign;
    if (d.color) el.style.color = d.color;
    el.style.lineHeight = d.lineHeight;
    if (d.letterSpacing) el.style.letterSpacing = px(d.letterSpacing);
    el.style.paddingTop = px(d.paddingTop);
    el.style.paddingBottom = px(d.paddingBottom);
    el.style.paddingLeft = px(d.paddingLeft);
    el.style.paddingRight = px(d.paddingRight);
    if (d.backgroundColor && d.backgroundColor !== 'transparent') el.style.backgroundColor = d.backgroundColor;
  }
  /* ============================================================
   * Drag & Drop (SortableJS only)
   * ============================================================ */
  function destroyAllSortables() {
    state.sortableInstances.forEach(s => { try { s.destroy(); } catch (_) {} });
    state.sortableInstances = [];
    // Reset dataset flags so palette sortables get re-initialized
    ['eb-palette-sections','eb-palette-layouts','eb-palette-blocks','eb-palette-templates'].forEach(id => {
      const el = document.getElementById(id);
      if (el) delete el.dataset.sortInit;
    });
  }
  function initSortables() {
    if (!window.Sortable) return;
    // Palette: section templates (clone-only)
    const $secPalette = document.getElementById('eb-palette-sections');
    if ($secPalette && !$secPalette.dataset.sortInit) {
      state.sortableInstances.push(new Sortable($secPalette, {
        group: { name: 'eb-sections', pull: 'clone', put: false },
        sort: false,
        animation: 150,
        ghostClass: 'eb-ghost'
      }));
      $secPalette.dataset.sortInit = '1';
    }
    // Palette: layouts (clone-only, same group as sections)
    const $layPalette = document.getElementById('eb-palette-layouts');
    if ($layPalette && !$layPalette.dataset.sortInit) {
      state.sortableInstances.push(new Sortable($layPalette, {
        group: { name: 'eb-sections', pull: 'clone', put: false },
        sort: false,
        animation: 150,
        ghostClass: 'eb-ghost'
      }));
      $layPalette.dataset.sortInit = '1';
    }
    // Palette: blocks (clone-only)
    const $blkPalette = document.getElementById('eb-palette-blocks');
    if ($blkPalette && !$blkPalette.dataset.sortInit) {
      state.sortableInstances.push(new Sortable($blkPalette, {
        group: { name: 'eb-blocks', pull: 'clone', put: false },
        sort: false,
        animation: 150,
        ghostClass: 'eb-ghost'
      }));
      $blkPalette.dataset.sortInit = '1';
    }
    // Palette: full templates (clone-only, same group as sections)
    const $tplPalette = document.getElementById('eb-palette-templates');
    if ($tplPalette && !$tplPalette.dataset.sortInit) {
      state.sortableInstances.push(new Sortable($tplPalette, {
        group: { name: 'eb-sections', pull: 'clone', put: false },
        sort: false,
        animation: 150,
        ghostClass: 'eb-ghost'
      }));
      $tplPalette.dataset.sortInit = '1';
    }
    // Canvas wrapper accepts sections
    const $wrap = document.getElementById('eb-canvas-wrap');
    if ($wrap) {
      state.sortableInstances.push(new Sortable($wrap, {
        group: { name: 'eb-sections-canvas', pull: false, put: ['eb-sections'] },
        draggable: '.eb-section',
        animation: 180,
        ghostClass: 'eb-ghost',
        onAdd: function (evt) {
          const tpl = evt.item.dataset.sectionKey;
          evt.item.remove();
          let newSec;
          if (tpl && SECTION_TEMPLATES[tpl]) {
            newSec = SECTION_TEMPLATES[tpl]();
          } else {
            const cols = parseInt(evt.item.dataset.cols || '1', 10) || 1;
            newSec = defaultSection();
            newSec.columns = cols;
            newSec.content = []; for (let i = 0; i < cols; i++) newSec.content.push([]);
          }
          state.sections.splice(evt.newIndex, 0, newSec);
          pushHistory();
          renderCanvas();
          selectSection(newSec.id);
        },
        onUpdate: function (evt) {
          const moved = state.sections.splice(evt.oldIndex, 1)[0];
          state.sections.splice(evt.newIndex, 0, moved);
          pushHistory();
          renderCanvas();
        }
      }));
    }
    // Each column accepts blocks
    document.querySelectorAll('.eb-col').forEach($col => {
      state.sortableInstances.push(new Sortable($col, {
        group: { name: 'eb-blocks-canvas', pull: true, put: ['eb-blocks', 'eb-blocks-canvas'] },
        draggable: '.eb-block',
        handle: '.eb-handle, .eb-block',
        filter: '[contenteditable="true"]',  // don't start drag from inside text edits
        preventOnFilter: false,
        animation: 160,
        ghostClass: 'eb-ghost',
        onAdd: function (evt) {
          const fromPalette = !!evt.item.dataset.blockType;
          if (fromPalette) {
            const type = evt.item.dataset.blockType;
            evt.item.remove();
            const sec = findSection($col.dataset.secId);
            if (!sec) return;
            const c = parseInt($col.dataset.colIdx, 10);
            const blk = blockOf(type, {});
            sec.content[c].splice(evt.newIndex, 0, blk);
            pushHistory();
            renderCanvas();
            selectBlock(blk.id);
            return;
          }
          // Moved between columns/sections
          const fromSec = findSection(evt.from.dataset.secId);
          const toSec   = findSection($col.dataset.secId);
          if (!fromSec || !toSec) return;
          const fromCol = parseInt(evt.from.dataset.colIdx, 10);
          const toCol   = parseInt($col.dataset.colIdx, 10);
          const moved = fromSec.content[fromCol].splice(evt.oldIndex, 1)[0];
          toSec.content[toCol].splice(evt.newIndex, 0, moved);
          pushHistory();
          renderCanvas();
        },
        onUpdate: function (evt) {
          const sec = findSection($col.dataset.secId);
          if (!sec) return;
          const c = parseInt($col.dataset.colIdx, 10);
          const moved = sec.content[c].splice(evt.oldIndex, 1)[0];
          sec.content[c].splice(evt.newIndex, 0, moved);
          pushHistory();
        }
      }));
    });
  }
  /* ============================================================
   * Selection & properties panel
   * ============================================================ */
  function selectBody() {
    state.selectedKind = 'body';
    state.selectedId = 'body';
    updateSelectionOutline();
    renderProperties();
  }
  function selectSection(id) {
    state.selectedKind = 'section';
    state.selectedId = id;
    updateSelectionOutline();
    renderProperties();
  }
  function selectBlock(id) {
    state.selectedKind = 'block';
    state.selectedId = id;
    updateSelectionOutline();
    renderProperties();
  }
  function updateSelectionOutline() {
    // Remove from ALL elements including SortableJS ghosts
    document.querySelectorAll('.eb-section, .eb-block').forEach(el => el.classList.remove('is-selected'));
    if (!state.selectedId || state.selectedId === 'body') return;
    // Use requestAnimationFrame to ensure DOM is ready
    requestAnimationFrame(() => {
      if (state.selectedKind === 'section') {
        const $el = document.querySelector('.eb-section[data-sec-id="' + state.selectedId + '"]');
        if ($el) $el.classList.add('is-selected');
      } else {
        const $el = document.querySelector('.eb-block[data-blk-id="' + state.selectedId + '"]');
        if ($el) $el.classList.add('is-selected');
      }
    });
  }
  function renderProperties() {
    const $p = document.getElementById('eb-properties');
    if (!$p) return;
    // Don't re-render while user is actively editing (typing, dragging slider, etc.)
    if ($p.querySelector('input:focus, select:focus, textarea:focus')) return;
    $p.innerHTML = '';
    if (state.selectedKind === 'body') return renderBodyProperties($p);
    if (state.selectedKind === 'section') {
      const sec = findSection(state.selectedId);
      if (sec) return renderSectionProperties($p, sec);
    }
    if (state.selectedKind === 'block') {
      const found = findBlock(state.selectedId);
      if (found) return renderBlockProperties($p, found.block);
    }
  }
  /* ---- Body ---- */
  function renderBodyProperties($p) {
    const b = state.body;
    panelTitle($p, 'Email body');
    fieldGroup($p, 'Email settings', [
      input('text',  'Preheader text (preview in inbox)', b.preheader, v => { b.preheader = v; deferred(); }),
      input('text',  'Email name (internal)', state.meta.name, v => { state.meta.name = v; deferred(); }),
      input('text',  'Subject line', state.meta.subject, v => { state.meta.subject = v; deferred(); })
    ]);
    fieldGroup($p, 'Layout', [
      input('color', 'Page background', b.backgroundColor, v => { b.backgroundColor = v; renderCanvas(); deferred(); }),
      input('color', 'Content background', b.contentBackground, v => { b.contentBackground = v; renderCanvas(); deferred(); }),
      input('range', 'Content width (px)', b.contentWidth, v => { b.contentWidth = +v; renderCanvas(); deferred(); }, { min: 480, max: 800, step: 10 }),
      input('range', 'Top padding',    b.paddingTop,    v => { b.paddingTop = +v;    renderCanvas(); deferred(); }, { min: 0, max: 80 }),
      input('range', 'Bottom padding', b.paddingBottom, v => { b.paddingBottom = +v; renderCanvas(); deferred(); }, { min: 0, max: 80 })
    ]);
    fieldGroup($p, 'Typography', [
      select('Font family', FONT_STACKS.map(f => ({ v: f.v, l: f.l })), b.fontFamily, v => { b.fontFamily = v; renderCanvas(); deferred(); }),
      input('range', 'Base font size', b.fontSize, v => { b.fontSize = +v; renderCanvas(); deferred(); }, { min: 12, max: 22 }),
      input('color', 'Text color', b.textColor, v => { b.textColor = v; renderCanvas(); deferred(); }),
      input('color', 'Link color', b.linkColor, v => { b.linkColor = v; renderCanvas(); deferred(); }),
      input('range', 'Line height (×10)', Math.round(b.lineHeight * 10), v => { b.lineHeight = (+v)/10; renderCanvas(); deferred(); }, { min: 10, max: 24 })
    ]);
    fieldGroup($p, 'Direction & mode', [
      select('Text direction', [{ v: 'ltr', l: 'Left-to-Right (English)' }, { v: 'rtl', l: 'Right-to-Left (Arabic / Hebrew)' }], state.meta.dir, v => setDirection(v)),
      toggle('Dark-mode preview', state.meta.darkMode, v => setDarkMode(v))
    ]);
  }
  /* ---- Section ---- */
  function renderSectionProperties($p, sec) {
    panelTitle($p, 'Section');
    fieldGroup($p, 'Layout', [
      columnsPicker(sec),
      input('range', 'Column gap', sec.gap, v => { sec.gap = +v; renderCanvas(); deferred(); }, { min: 0, max: 60 })
    ]);
    fieldGroup($p, 'Style', [
      input('color', 'Background', sec.bgColor, v => { sec.bgColor = v; renderCanvas(); deferred(); }),
      input('range', 'Padding top',    sec.paddingTop,    v => { sec.paddingTop = +v;    renderCanvas(); deferred(); }, { min: 0, max: 100 }),
      input('range', 'Padding bottom', sec.paddingBottom, v => { sec.paddingBottom = +v; renderCanvas(); deferred(); }, { min: 0, max: 100 }),
      input('range', 'Padding left',   sec.paddingLeft,   v => { sec.paddingLeft = +v;   renderCanvas(); deferred(); }, { min: 0, max: 100 }),
      input('range', 'Padding right',  sec.paddingRight,  v => { sec.paddingRight = +v;  renderCanvas(); deferred(); }, { min: 0, max: 100 })
    ]);
    fieldGroup($p, 'Borders', [
      input('range', 'Top border (px)',    sec.borderTopWidth, v => { sec.borderTopWidth = +v; renderCanvas(); deferred(); }, { min: 0, max: 10 }),
      input('color', 'Top border color',   sec.borderTopColor, v => { sec.borderTopColor = v; renderCanvas(); deferred(); }),
      input('range', 'Bottom border (px)', sec.borderBottomWidth, v => { sec.borderBottomWidth = +v; renderCanvas(); deferred(); }, { min: 0, max: 10 }),
      input('color', 'Bottom border color',sec.borderBottomColor, v => { sec.borderBottomColor = v; renderCanvas(); deferred(); })
    ]);
    fieldGroup($p, 'Mobile', [
      toggle('Hide this section on mobile', sec.hideOnMobile, v => { sec.hideOnMobile = v; renderCanvas(); deferred(); }),
      toggle('Stack columns on mobile',     sec.stackOnMobile, v => { sec.stackOnMobile = v; deferred(); })
    ]);
  }
  /* ---- Block (delegates per type) ---- */
  function renderBlockProperties($p, blk) {
    const d = blk.data;
    const titleMap = { text: 'Text', heading: 'Heading', image: 'Image', button: 'Button', divider: 'Divider', spacer: 'Spacer', html: 'HTML', social: 'Social', video: 'Video', footer: 'Footer' };
    panelTitle($p, titleMap[blk.type] || 'Block');
    if (blk.type === 'text' || blk.type === 'heading' || blk.type === 'footer') {
      if (blk.type === 'heading') {
        fieldGroup($p, 'Heading', [
          select('Level', ['h1','h2','h3','h4'].map(v=>({v,l:v.toUpperCase()})), d.level, v => { d.level = v; updateHeadingContentLevel(blk); renderCanvas(); deferred(); })
        ]);
      }
      fieldGroup($p, 'Typography', [
        select('Font family', [{ v: '', l: 'Inherit from body' }].concat(FONT_STACKS.map(f=>({v:f.v,l:f.l}))), d.fontFamily, v => { d.fontFamily = v; renderCanvas(); deferred(); }),
        input('range', 'Font size', d.fontSize, v => { d.fontSize = +v; renderCanvas(); deferred(); }, { min: 10, max: 64 }),
        select('Weight', ['300','400','500','600','700','800'].map(v=>({v,l:v})), d.fontWeight, v => { d.fontWeight = v; renderCanvas(); deferred(); }),
        select('Style', [{v:'normal',l:'Normal'},{v:'italic',l:'Italic'}], d.fontStyle, v => { d.fontStyle = v; renderCanvas(); deferred(); }),
        select('Align', alignOpts(), d.textAlign, v => { d.textAlign = v; renderCanvas(); deferred(); }),
        input('color', 'Color', d.color || '#333333', v => { d.color = v; renderCanvas(); deferred(); }),
        input('range', 'Line height (×10)', Math.round(d.lineHeight * 10), v => { d.lineHeight = (+v)/10; renderCanvas(); deferred(); }, { min: 10, max: 24 }),
        input('range', 'Letter spacing', d.letterSpacing, v => { d.letterSpacing = +v; renderCanvas(); deferred(); }, { min: -2, max: 8 })
      ]);
      // Inline formatting buttons (replaces floating toolbar)
      const fmtButtons = document.createElement('div'); fmtButtons.className = 'eb-prop-group';
      const fmtTitle = document.createElement('div'); fmtTitle.className = 'eb-prop-group-title'; fmtTitle.textContent = 'Format text';
      fmtButtons.appendChild(fmtTitle);
      const btnWrap = document.createElement('div'); btnWrap.className = 'eb-btn-row';
      [
        { l: 'B', t: 'Bold', cmd: 'bold' },
        { l: 'I', t: 'Italic', cmd: 'italic' },
        { l: 'U', t: 'Underline', cmd: 'underline' },
        { l: 'S', t: 'Strikethrough', cmd: 'strikeThrough' },
        { l: '•', t: 'Bullet list', cmd: 'insertUnorderedList' },
        { l: '1.', t: 'Numbered list', cmd: 'insertOrderedList' },
        { l: '🔗', t: 'Link', cmd: 'link' },
        { l: '✕', t: 'Clear formatting', cmd: 'clear' }
      ].forEach(b => {
        const $btn = document.createElement('button'); $btn.type = 'button';
        $btn.className = 'eb-format-btn'; $btn.title = b.t; $btn.textContent = b.l;
        $btn.addEventListener('click', () => {
          const el = state.lastFocusedElement || document.querySelector('.eb-block[data-blk-id="'+blk.id+'"]');
          if (!el) return;
          el.focus();
          if (b.cmd === 'link') {
            const $urlInput = document.getElementById('eb-format-url');
            if ($urlInput) {
              const url = $urlInput.value.trim();
              if (url) document.execCommand('createLink', false, url);
              else document.execCommand('unlink', false, null);
              $urlInput.value = '';
            }
          } else if (b.cmd === 'clear') {
            document.execCommand('removeFormat', false, null);
            document.execCommand('unlink', false, null);
          } else {
            document.execCommand(b.cmd, false, null);
          }
          d.content = el.innerHTML;
          deferred();
        });
        btnWrap.appendChild($btn);
      });
      fmtButtons.appendChild(btnWrap);
      $p.appendChild(fmtButtons);
      paddingGroup($p, d);
      mergeTagPicker($p, blk);
      mobileGroup($p, d);
      return;
    }
    if (blk.type === 'image') {
      fieldGroup($p, 'Image', [
        input('text', 'Image URL', d.src, v => { d.src = v; renderCanvas(); deferred(); }),
        imageUploadField(d, () => { renderCanvas(); deferred(); }),
        input('text', 'Alt text (recommended)', d.alt, v => { d.alt = v; renderCanvas(); deferred(); }),
        input('text', 'Link URL (optional)', d.linkUrl, v => { d.linkUrl = v; renderCanvas(); deferred(); }),
        input('range', 'Width (%)', d.width, v => { d.width = +v; renderCanvas(); deferred(); }, { min: 10, max: 100 }),
        select('Align', alignOpts(), d.align, v => { d.align = v; renderCanvas(); deferred(); }),
        input('range', 'Border radius', d.borderRadius, v => { d.borderRadius = +v; renderCanvas(); deferred(); }, { min: 0, max: 60 }),
        input('range', 'Border width', d.borderWidth, v => { d.borderWidth = +v; renderCanvas(); deferred(); }, { min: 0, max: 10 }),
        input('color', 'Border color', d.borderColor, v => { d.borderColor = v; renderCanvas(); deferred(); }),
        input('color', 'Background', d.backgroundColor === 'transparent' ? '#ffffff' : d.backgroundColor, v => { d.backgroundColor = v; renderCanvas(); deferred(); })
      ]);
      paddingGroup($p, d);
      mobileGroup($p, d);
      return;
    }
    if (blk.type === 'button') {
      fieldGroup($p, 'Button', [
        input('text', 'Label', d.text, v => { d.text = v; renderCanvas(); deferred(); }),
        input('text', 'URL', d.url, v => { d.url = v; renderCanvas(); deferred(); }),
        select('Align', alignOpts(), d.align, v => { d.align = v; renderCanvas(); deferred(); }),
        toggle('Full width', d.fullWidth, v => { d.fullWidth = v; renderCanvas(); deferred(); })
      ]);
      fieldGroup($p, 'Style', [
        input('color', 'Background', d.bgColor, v => { d.bgColor = v; renderCanvas(); deferred(); }),
        input('color', 'Text color', d.textColor, v => { d.textColor = v; renderCanvas(); deferred(); }),
        input('range', 'Font size', d.fontSize, v => { d.fontSize = +v; renderCanvas(); deferred(); }, { min: 10, max: 32 }),
        select('Weight', ['400','500','600','700','800'].map(v=>({v,l:v})), d.fontWeight, v => { d.fontWeight = v; renderCanvas(); deferred(); }),
        input('range', 'Padding vertical',   d.paddingV, v => { d.paddingV = +v; renderCanvas(); deferred(); }, { min: 4, max: 40 }),
        input('range', 'Padding horizontal', d.paddingH, v => { d.paddingH = +v; renderCanvas(); deferred(); }, { min: 4, max: 60 }),
        input('range', 'Border radius', d.borderRadius, v => { d.borderRadius = +v; renderCanvas(); deferred(); }, { min: 0, max: 40 }),
        input('range', 'Border width',  d.borderWidth, v => { d.borderWidth = +v; renderCanvas(); deferred(); }, { min: 0, max: 10 }),
        input('color', 'Border color',  d.borderColor, v => { d.borderColor = v; renderCanvas(); deferred(); }),
        select('Text transform', [{v:'none',l:'None'},{v:'uppercase',l:'UPPERCASE'},{v:'lowercase',l:'lowercase'}], d.textTransform, v => { d.textTransform = v; renderCanvas(); deferred(); }),
        input('range', 'Letter spacing', d.letterSpacing, v => { d.letterSpacing = +v; renderCanvas(); deferred(); }, { min: 0, max: 8 })
      ]);
      paddingGroup($p, d, true);
      mergeTagPicker($p, blk, 'url');
      mobileGroup($p, d);
      return;
    }
    if (blk.type === 'divider') {
      fieldGroup($p, 'Divider', [
        input('color', 'Color', d.color, v => { d.color = v; renderCanvas(); deferred(); }),
        input('range', 'Thickness', d.thickness, v => { d.thickness = +v; renderCanvas(); deferred(); }, { min: 1, max: 12 }),
        select('Style', [{v:'solid',l:'Solid'},{v:'dashed',l:'Dashed'},{v:'dotted',l:'Dotted'}], d.style, v => { d.style = v; renderCanvas(); deferred(); }),
        input('range', 'Width (%)', d.width, v => { d.width = +v; renderCanvas(); deferred(); }, { min: 10, max: 100 }),
        select('Align', alignOpts(), d.align, v => { d.align = v; renderCanvas(); deferred(); })
      ]);
      paddingGroup($p, d, true);
      mobileGroup($p, d);
      return;
    }
    if (blk.type === 'spacer') {
      fieldGroup($p, 'Spacer', [
        input('range', 'Height', d.height, v => { d.height = +v; renderCanvas(); deferred(); }, { min: 4, max: 120 }),
        input('color', 'Background', d.backgroundColor === 'transparent' ? '#ffffff' : d.backgroundColor, v => { d.backgroundColor = v; renderCanvas(); deferred(); })
      ]);
      mobileGroup($p, d);
      return;
    }
    if (blk.type === 'html') {
      fieldGroup($p, 'Custom HTML', [
        textarea('HTML', d.content, v => { d.content = v; renderCanvas(); deferred(); }, 10)
      ]);
      paddingGroup($p, d, true);
      mobileGroup($p, d);
      return;
    }
    if (blk.type === 'social') {
      fieldGroup($p, 'Layout', [
        select('Align', alignOpts(), d.align, v => { d.align = v; renderCanvas(); deferred(); }),
        input('range', 'Icon size', d.iconSize, v => { d.iconSize = +v; renderCanvas(); deferred(); }, { min: 16, max: 64 }),
        input('range', 'Spacing',   d.spacing,  v => { d.spacing = +v; renderCanvas(); deferred(); }, { min: 0, max: 40 }),
        select('Shape', [{v:'circle',l:'Circle'},{v:'rounded',l:'Rounded'},{v:'square',l:'Square'}], d.shape, v => { d.shape = v; renderCanvas(); deferred(); }),
        input('color', 'Icon color', d.iconColor, v => { d.iconColor = v; renderCanvas(); deferred(); }),
        input('color', 'Background', d.bgColor,   v => { d.bgColor = v; renderCanvas(); deferred(); })
      ]);
      const items = [];
      d.platforms.forEach((p, i) => {
        items.push(toggle(p.id.charAt(0).toUpperCase()+p.id.slice(1)+' — enabled', p.enabled, v => { p.enabled = v; renderCanvas(); deferred(); }));
        items.push(input('text', p.id+' URL', p.url, v => { p.url = v; deferred(); }));
      });
      fieldGroup($p, 'Platforms', items);
      paddingGroup($p, d, true);
      mobileGroup($p, d);
      return;
    }
    if (blk.type === 'video') {
      fieldGroup($p, 'Video', [
        input('text', 'Video URL', d.url, v => { d.url = v; deferred(); }),
        input('text', 'Thumbnail URL', d.thumbSrc, v => { d.thumbSrc = v; renderCanvas(); deferred(); }),
        imageUploadField(d, () => { renderCanvas(); deferred(); }, 'thumbSrc'),
        input('text', 'Alt text', d.alt, v => { d.alt = v; deferred(); })
      ]);
      paddingGroup($p, d, true);
      mobileGroup($p, d);
    }
  }
  function updateHeadingContentLevel(blk) {
    const lvl = blk.data.level || 'h2';
    // Replace outer tag inside content
    const dom = document.createElement('div');
    dom.innerHTML = blk.data.content;
    const oldH = dom.querySelector('h1,h2,h3,h4');
    if (oldH) {
      const newH = document.createElement(lvl);
      newH.innerHTML = oldH.innerHTML;
      for (const a of oldH.attributes) newH.setAttribute(a.name, a.value);
      oldH.replaceWith(newH);
      blk.data.content = dom.innerHTML;
    }
  }
  /* ============================================================
   * Field helpers
   * ============================================================ */
  function panelTitle($p, text) {
    const $h = document.createElement('h3');
    $h.className = 'eb-prop-title';
    $h.textContent = text;
    $p.appendChild($h);
  }
  function fieldGroup($p, title, fields) {
    const $g = document.createElement('div');
    $g.className = 'eb-prop-group';
    if (title) {
      const $t = document.createElement('div');
      $t.className = 'eb-prop-group-title';
      $t.textContent = title;
      $g.appendChild($t);
    }
    fields.forEach(f => f && $g.appendChild(f));
    $p.appendChild($g);
  }
  function row(label, $control) {
    const $r = document.createElement('label');
    $r.className = 'eb-prop-row';
    const $l = document.createElement('span');
    $l.className = 'eb-prop-label';
    $l.textContent = label;
    $r.appendChild($l);
    $r.appendChild($control);
    return $r;
  }
  function input(type, label, value, onChange, opts) {
    opts = opts || {};
    const $i = document.createElement('input');
    $i.type = type === 'range' ? 'range' : type;
    if (type === 'range') {
      $i.min = opts.min ?? 0; $i.max = opts.max ?? 100; $i.step = opts.step ?? 1;
      const $val = document.createElement('span');
      $val.className = 'eb-range-val';
      $val.textContent = value;
      $i.value = value;
      $i.addEventListener('input', () => { $val.textContent = $i.value; onChange($i.value); });
      const $wrap = document.createElement('div');
      $wrap.className = 'eb-range-wrap';
      $wrap.appendChild($i); $wrap.appendChild($val);
      return row(label, $wrap);
    }
    $i.value = value == null ? '' : value;
    // For text/url/email/color inputs: use 'change' so focus isn't lost on every keystroke
    // For other inputs (range handled above): use 'input' for live preview
    const eventName = (type === 'text' || type === 'url' || type === 'email') ? 'change' : 'input';
    $i.addEventListener(eventName, () => onChange($i.value));
    return row(label, $i);
  }
  function textarea(label, value, onChange, rows) {
    const $t = document.createElement('textarea');
    $t.rows = rows || 4;
    $t.value = value || '';
    $t.addEventListener('input', () => onChange($t.value));
    return row(label, $t);
  }
  function select(label, options, value, onChange) {
    const $s = document.createElement('select');
    options.forEach(o => {
      const $o = document.createElement('option');
      $o.value = o.v; $o.textContent = o.l;
      if (o.v === value) $o.selected = true;
      $s.appendChild($o);
    });
    $s.addEventListener('change', () => onChange($s.value));
    return row(label, $s);
  }
  function toggle(label, value, onChange) {
    const $w = document.createElement('div'); $w.className = 'eb-toggle';
    const $i = document.createElement('input'); $i.type = 'checkbox'; $i.checked = !!value;
    $i.addEventListener('change', () => onChange($i.checked));
    const $span = document.createElement('span'); $span.textContent = label;
    $w.appendChild($i); $w.appendChild($span);
    return $w;
  }
  function alignOpts() { return [{v:'left',l:'Left'},{v:'center',l:'Center'},{v:'right',l:'Right'}]; }
  function columnsPicker(sec) {
    const $w = document.createElement('div'); $w.className = 'eb-cols-picker';
    [1,2,3,4].forEach(n => {
      const $b = document.createElement('button');
      $b.type = 'button';
      $b.textContent = n;
      $b.className = sec.columns === n ? 'is-active' : '';
      $b.addEventListener('click', () => changeColumns(sec, n));
      $w.appendChild($b);
    });
    return row('Columns', $w);
  }
  function changeColumns(sec, n) {
    if (sec.columns === n) return;
    if (n > sec.columns) {
      while (sec.content.length < n) sec.content.push([]);
    } else {
      const overflow = sec.content.slice(n).flat();
      sec.content = sec.content.slice(0, n);
      sec.content[n - 1] = sec.content[n - 1].concat(overflow);
    }
    sec.columns = n;
    pushHistory();
    renderCanvas();
    renderProperties();
  }
  function paddingGroup($p, d, simpleOnly) {
    const items = [];
    items.push(input('range', 'Padding top',    d.paddingTop,    v => { d.paddingTop = +v; renderCanvas(); deferred(); }, { min: 0, max: 80 }));
    items.push(input('range', 'Padding bottom', d.paddingBottom, v => { d.paddingBottom = +v; renderCanvas(); deferred(); }, { min: 0, max: 80 }));
    if (!simpleOnly && 'paddingLeft' in d) {
      items.push(input('range', 'Padding left',  d.paddingLeft,  v => { d.paddingLeft = +v; renderCanvas(); deferred(); }, { min: 0, max: 80 }));
      items.push(input('range', 'Padding right', d.paddingRight, v => { d.paddingRight = +v; renderCanvas(); deferred(); }, { min: 0, max: 80 }));
    }
    fieldGroup($p, 'Spacing', items);
  }
  function mobileGroup($p, d) {
    const items = [];
    items.push(toggle('Hide on mobile', !!d.hideOnMobile, v => { d.hideOnMobile = v; renderCanvas(); deferred(); }));
    if ('mobileFontSize' in d) {
      items.push(input('range', 'Mobile font size (0 = inherit)', d.mobileFontSize, v => { d.mobileFontSize = +v; deferred(); }, { min: 0, max: 32 }));
    }
    fieldGroup($p, 'Mobile', items);
  }
  function mergeTagPicker($p, blk, fieldName) {
    const $g = document.createElement('div'); $g.className = 'eb-prop-group';
    const $t = document.createElement('div'); $t.className = 'eb-prop-group-title';
    $t.textContent = 'Merge tags'; $g.appendChild($t);
    const $hint = document.createElement('div'); $hint.className = 'eb-hint';
    $hint.textContent = 'Click to insert. Replaced with recipient data when the email is sent.';
    $g.appendChild($hint);
    const $w = document.createElement('div'); $w.className = 'eb-tag-grid';
    MERGE_TAGS.forEach(t => {
      const $b = document.createElement('button');
      $b.type = 'button'; $b.className = 'eb-tag-btn';
      $b.textContent = t.label;
      $b.title = t.tag;
      $b.addEventListener('click', () => {
        if (fieldName) {
          blk.data[fieldName] = (blk.data[fieldName] || '') + t.tag;
          renderProperties();
          renderCanvas();
        } else {
          // Insert at caret in the contenteditable
          const el = state.lastFocusedElement || document.querySelector('.eb-block[data-blk-id="'+blk.id+'"]');
          if (el && el.isContentEditable) {
            el.focus();
            insertAtCaret(el, t.tag);
            blk.data.content = el.innerHTML;
            renderCanvas();
          } else {
            blk.data.content = (blk.data.content || '') + t.tag;
            renderCanvas();
          }
        }
        deferred();
      });
      $w.appendChild($b);
    });
    $g.appendChild($w);
    $p.appendChild($g);
  }
  function insertAtCaret(el, text) {
    const sel = window.getSelection();
    if (!sel || !sel.rangeCount) { el.innerHTML += text; return; }
    const range = sel.getRangeAt(0);
    range.deleteContents();
    range.insertNode(document.createTextNode(text));
    range.collapse(false);
    sel.removeAllRanges(); sel.addRange(range);
  }
  function imageUploadField(d, after, fieldName) {
    fieldName = fieldName || 'src';
    const $w = document.createElement('div'); $w.className = 'eb-upload';
    const $btn = document.createElement('button'); $btn.type = 'button';
    $btn.className = 'eb-upload-btn';
    $btn.textContent = '⬆ Upload from computer';
    const $file = document.createElement('input'); $file.type = 'file'; $file.accept = 'image/*'; $file.style.display = 'none';
    $btn.addEventListener('click', () => $file.click());
    $file.addEventListener('change', () => {
      const f = $file.files[0]; if (!f) return;
      const fd = new FormData(); fd.append('image', f); fd.append('csrf_token', state.csrfToken);
      setStatus('Uploading…');
      fetch('/api/email-builder-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(resp => {
          if (resp && resp.success && resp.url) {
            d[fieldName] = resp.url;
            setStatus('Uploaded');
            setTimeout(() => setStatus(''), 1500);
            after && after();
            renderProperties();
          } else {
            setStatus(resp?.message || 'Upload failed', true);
          }
        }).catch(() => setStatus('Upload failed', true));
    });
    $w.appendChild($btn); $w.appendChild($file);
    return row('Or upload', $w);
  }
  /* ============================================================
   * Inline floating text toolbar
   * ============================================================ */
  let $tipBar = null;
  // Inline toolbar removed — formatting is in properties panel
  function positionInlineToolbar(el) {
    if (!$tipBar) return;
    const r = el.getBoundingClientRect();
    $tipBar.style.top  = (window.scrollY + r.top - 44) + 'px';
    $tipBar.style.left = (window.scrollX + r.left) + 'px';
  }
  // Inline toolbar removed
  function getSelectionLink() {
    const s = window.getSelection(); if (!s.anchorNode) return '';
    let n = s.anchorNode;
    while (n && n.nodeName !== 'A' && n.parentNode) n = n.parentNode;
    return n && n.nodeName === 'A' ? n.getAttribute('href') : '';
  }
  function wrapSelection(styleStr) {
    const sel = window.getSelection(); if (!sel || !sel.rangeCount) return;
    const range = sel.getRangeAt(0);
    const span = document.createElement('span');
    span.setAttribute('style', styleStr);
    span.appendChild(range.extractContents());
    range.insertNode(span);
    sel.removeAllRanges();
  }
  /* ============================================================
   * Actions (section / block buttons)
   * ============================================================ */
  function sectionAction(id, act) {
    const idx = state.sections.findIndex(s => s.id === id);
    if (idx < 0) return;
    if (act === 'del') {
      // Delete without confirm — user can undo with Ctrl+Z
      state.sections.splice(idx, 1);
      pushHistory(); renderCanvas(); selectBody(); return;
    }
    if (act === 'dup') {
      const dup = clone(state.sections[idx]);
      dup.id = uid('sec');
      dup.content.forEach(col => col.forEach(b => b.id = uid('blk')));
      state.sections.splice(idx + 1, 0, dup);
      pushHistory(); renderCanvas(); selectSection(dup.id); return;
    }
    if (act === 'up' && idx > 0) {
      [state.sections[idx-1], state.sections[idx]] = [state.sections[idx], state.sections[idx-1]];
      pushHistory(); renderCanvas(); return;
    }
    if (act === 'down' && idx < state.sections.length - 1) {
      [state.sections[idx+1], state.sections[idx]] = [state.sections[idx], state.sections[idx+1]];
      pushHistory(); renderCanvas(); return;
    }
    if (act === 'set') { selectSection(id); }
  }
  function blockAction(id, act) {
    const found = findBlock(id); if (!found) return;
    if (act === 'del') {
      found.sec.content[found.col].splice(found.idx, 1);
      pushHistory(); renderCanvas(); selectBody(); return;
    }
    if (act === 'dup') {
      const dup = clone(found.block); dup.id = uid('blk');
      found.sec.content[found.col].splice(found.idx + 1, 0, dup);
      pushHistory(); renderCanvas(); selectBlock(dup.id); return;
    }
  }
  /* ============================================================
   * Preview iframe
   * ============================================================ */
  function renderPreview() {
    const $frame = document.getElementById('eb-preview');
    if (!$frame) return;
    const html = getHTML();
    $frame.srcdoc = html;
    // Size
    const widths = { desktop: 640, tablet: 480, mobile: 360 };
    $frame.style.width = (widths[state.previewSize] || 640) + 'px';
  }
  /* ============================================================
   * Direction & dark-mode
   * ============================================================ */
  function setDirection(dir) {
    state.meta.dir = dir === 'rtl' ? 'rtl' : 'ltr';
    document.documentElement.setAttribute('data-eb-dir', state.meta.dir);
    renderCanvas();
    renderProperties();
    deferred();
  }
  function setDarkMode(on) {
    state.meta.darkMode = !!on;
    document.documentElement.classList.toggle('eb-dark-preview', !!on);
    renderPreview();
    deferred();
  }
  function setPreviewSize(s) {
    if (!['desktop','tablet','mobile'].includes(s)) s = 'desktop';
    state.previewSize = s;
    updateToolbarButtons();
    // Apply responsive width to edit canvas too
    const $wrap = document.getElementById('eb-canvas-wrap');
    if ($wrap) {
      const widths = { desktop: 640, tablet: 480, mobile: 360 };
      $wrap.style.transition = 'max-width .3s ease';
      $wrap.style.maxWidth = (widths[s] || 640) + 'px';
    }
    renderPreview();
  }
  /* ============================================================
   * Keyboard shortcuts
   * ============================================================ */
  function bindKeyboard() {
    document.addEventListener('keydown', e => {
      const mod = e.ctrlKey || e.metaKey;
      if (mod && (e.key === 'z' || e.key === 'Z') && !e.shiftKey) { e.preventDefault(); undo(); }
      else if (mod && ((e.key === 'z' && e.shiftKey) || e.key === 'y' || e.key === 'Y')) { e.preventDefault(); redo(); }
      else if (mod && (e.key === 'd' || e.key === 'D')) {
        if (state.selectedKind === 'block') { e.preventDefault(); blockAction(state.selectedId, 'dup'); }
        else if (state.selectedKind === 'section') { e.preventDefault(); sectionAction(state.selectedId, 'dup'); }
      }
      else if (mod && (e.key === 's' || e.key === 'S')) { e.preventDefault(); autosave(); }
      else if (e.key === 'Delete' && !isTextEditing()) {
        if (state.selectedKind === 'block') { e.preventDefault(); blockAction(state.selectedId, 'del'); }
        else if (state.selectedKind === 'section') { e.preventDefault(); sectionAction(state.selectedId, 'del'); }
      }
    });
  }
  function isTextEditing() {
    const a = document.activeElement;
    return a && (a.isContentEditable || a.tagName === 'INPUT' || a.tagName === 'TEXTAREA');
  }
  /* ============================================================
   * JSON in/out
   * ============================================================ */
  function getJSON() {
    return JSON.stringify({
      version: 2,
      body: state.body,
      meta: state.meta,
      sections: state.sections
    });
  }
  /* ============================================================
   * Email-safe HTML exporter (table-based)
   * ============================================================ */
  function getHTML() {
    const b = state.body;
    const dir = state.meta.dir;
    const sectionsHtml = state.sections.map(renderSectionForEmail).join('');
    const mobileCss = `
      @media only screen and (max-width:480px) {
        .eb-content { width:100% !important; }
        .eb-col { display:block !important; width:100% !important; }
        .eb-hide-mobile { display:none !important; }
        .eb-mobile-stack > tbody > tr > td { display:block !important; width:100% !important; }
      }
      @media (prefers-color-scheme: dark) {
        body.eb-supports-dark { background:#111 !important; color:#eee !important; }
        body.eb-supports-dark .eb-content { background:#1a1a1a !important; }
      }
    `;
    const preheader = b.preheader ? `<div style="display:none;font-size:1px;color:${esc(b.backgroundColor)};line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">${esc(b.preheader)}</div>` : '';
    return `<!doctype html>
<html lang="${dir === 'rtl' ? 'ar' : 'en'}" dir="${dir}" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>${esc(state.meta.subject || state.meta.name || 'Email')}</title>
<!--[if mso]>
<style type="text/css">
table { border-collapse:collapse; }
.eb-btn-mso { mso-padding-alt:0; }
</style>
<![endif]-->
<style>
  body { margin:0; padding:0; -webkit-font-smoothing:antialiased; }
  table { border-collapse:collapse; mso-table-lspace:0; mso-table-rspace:0; }
  img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; display:block; }
  a { text-decoration:none; color:${esc(b.linkColor)}; }
  .eb-content { background:${esc(b.contentBackground)}; }
  ${mobileCss}
</style>
</head>
<body class="eb-supports-dark" dir="${dir}" style="margin:0;padding:0;background:${esc(b.backgroundColor)};font-family:${esc(b.fontFamily)};color:${esc(b.textColor)};font-size:${b.fontSize}px;line-height:${b.lineHeight};">
${preheader}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="${esc(b.backgroundColor)}" style="background:${esc(b.backgroundColor)};">
  <tr><td align="center" style="padding:${b.paddingTop}px 0 ${b.paddingBottom}px;">
    <!--[if mso]><table role="presentation" width="${b.contentWidth}" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
    <table role="presentation" class="eb-content" width="${b.contentWidth}" cellpadding="0" cellspacing="0" border="0" style="width:${b.contentWidth}px;max-width:100%;background:${esc(b.contentBackground)};">
      ${sectionsHtml}
    </table>
    <!--[if mso]></td></tr></table><![endif]-->
  </td></tr>
</table>
</body>
</html>`;
  }
  function renderSectionForEmail(sec) {
    const cls = (sec.hideOnMobile ? 'eb-hide-mobile ' : '') + (sec.stackOnMobile ? 'eb-mobile-stack ' : '');
    const pad = `padding:${sec.paddingTop}px ${sec.paddingRight}px ${sec.paddingBottom}px ${sec.paddingLeft}px;`;
    const bt  = sec.borderTopWidth    ? `border-top:${sec.borderTopWidth}px ${sec.borderTopStyle} ${sec.borderTopColor};` : '';
    const bb  = sec.borderBottomWidth ? `border-bottom:${sec.borderBottomWidth}px ${sec.borderBottomStyle} ${sec.borderBottomColor};` : '';
    const inner = renderSectionColumns(sec);
    return `<tr><td class="${cls.trim()}" bgcolor="${esc(sec.bgColor)}" style="${pad}background:${esc(sec.bgColor)};${bt}${bb}">${inner}</td></tr>`;
  }
  function renderSectionColumns(sec) {
    if (sec.columns === 1) {
      return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td>${(sec.content[0]||[]).map(renderBlockForEmail).join('')}</td></tr></table>`;
    }
    const colW = Math.floor(100 / sec.columns);
    const cells = [];
    for (let c = 0; c < sec.columns; c++) {
      const inside = (sec.content[c] || []).map(renderBlockForEmail).join('');
      const gapStyle = c < sec.columns - 1 ? `padding-${state.meta.dir === 'rtl' ? 'left' : 'right'}:${sec.gap}px;` : '';
      cells.push(`<td class="eb-col" width="${colW}%" valign="top" style="width:${colW}%;${gapStyle}">${inside}</td>`);
    }
    return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="${sec.stackOnMobile ? 'eb-mobile-stack' : ''}"><tr>${cells.join('')}</tr></table>`;
  }
  function renderBlockForEmail(blk) {
    const d = blk.data;
    const cls = d.hideOnMobile ? ' class="eb-hide-mobile"' : '';
    switch (blk.type) {
      case 'text':
      case 'footer': {
        const style = textBlockStyle(d);
        return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"${cls}><tr><td style="${style}">${d.content || ''}</td></tr></table>`;
      }
      case 'heading': {
        const style = textBlockStyle(d);
        return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"${cls}><tr><td style="${style}">${d.content || ''}</td></tr></table>`;
      }
      case 'image': {
        const alignAttr = d.align === 'center' ? 'center' : (d.align === 'right' ? 'right' : 'left');
        const pad = `padding:${d.paddingTop}px ${d.paddingRight||0}px ${d.paddingBottom}px ${d.paddingLeft||0}px;`;
        const border = d.borderWidth ? `border:${d.borderWidth}px ${d.borderStyle} ${d.borderColor};` : '';
        const bg = d.backgroundColor && d.backgroundColor !== 'transparent' ? `background:${d.backgroundColor};` : '';
        const imgTag = `<img src="${esc(d.src)}" alt="${esc(d.alt)}" width="${d.width === 100 ? '100%' : Math.round((state.body.contentWidth) * (d.width/100))}" style="display:block;max-width:100%;width:${d.width}%;border-radius:${d.borderRadius}px;${border}" />`;
        const wrappedImg = d.linkUrl ? `<a href="${esc(d.linkUrl)}" target="_blank" rel="noopener">${imgTag}</a>` : imgTag;
        return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"${cls}><tr><td align="${alignAttr}" style="${pad}${bg}">${wrappedImg}</td></tr></table>`;
      }
      case 'button': {
        const alignAttr = d.align === 'center' ? 'center' : (d.align === 'right' ? 'right' : 'left');
        const pad = `padding:${d.paddingTop}px 0 ${d.paddingBottom}px;`;
        const radius = d.borderRadius;
        const ff = d.fontFamily ? `font-family:${d.fontFamily};` : '';
        const btnStyle =
          `background:${d.bgColor};color:${d.textColor};${ff}font-size:${d.fontSize}px;font-weight:${d.fontWeight};` +
          `letter-spacing:${d.letterSpacing}px;text-transform:${d.textTransform};` +
          `border-radius:${radius}px;` + (d.borderWidth ? `border:${d.borderWidth}px ${d.borderStyle} ${d.borderColor};` : 'border:0;') +
          `padding:${d.paddingV}px ${d.paddingH}px;display:${d.fullWidth ? 'block' : 'inline-block'};text-decoration:none;mso-padding-alt:0;`;
        const msoFallback = `
<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="${esc(d.url)}" style="height:${(d.fontSize + d.paddingV*2)}px;v-text-anchor:middle;width:${d.fullWidth ? 480 : 200}px;" arcsize="${Math.round(radius/(d.fontSize + d.paddingV*2)*100)}%" stroke="f" fillcolor="${d.bgColor}">
<w:anchorlock/>
<center style="color:${d.textColor};font-family:${esc(d.fontFamily || state.body.fontFamily)};font-size:${d.fontSize}px;font-weight:${d.fontWeight};">${esc(d.text)}</center>
</v:roundrect>
<![endif]-->`;
        const realBtn = `<a href="${esc(d.url)}" target="_blank" rel="noopener" style="${btnStyle}">${esc(d.text)}</a>`;
        return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"${cls}><tr><td align="${alignAttr}" style="${pad}">${msoFallback}<!--[if !mso]><!-- -->${realBtn}<!--<![endif]--></td></tr></table>`;
      }
      case 'divider': {
        const alignAttr = d.align === 'center' ? 'center' : (d.align === 'right' ? 'right' : 'left');
        const pad = `padding:${d.paddingTop}px 0 ${d.paddingBottom}px;`;
        const widthStyle = d.width === 100 ? 'width:100%;' : `width:${d.width}%;`;
        return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"${cls}><tr><td align="${alignAttr}" style="${pad}"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="${widthStyle}"><tr><td style="border-top:${d.thickness}px ${d.style} ${d.color};font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr></table>`;
      }
      case 'spacer': {
        const bg = d.backgroundColor && d.backgroundColor !== 'transparent' ? `background:${d.backgroundColor};` : '';
        return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"${cls}><tr><td height="${d.height}" style="height:${d.height}px;font-size:0;line-height:0;${bg}">&nbsp;</td></tr></table>`;
      }
      case 'html': {
        return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"${cls}><tr><td style="padding:${d.paddingTop}px 0 ${d.paddingBottom}px;">${d.content || ''}</td></tr></table>`;
      }
      case 'social': {
        const alignAttr = d.align === 'center' ? 'center' : (d.align === 'right' ? 'right' : 'left');
        const radius = d.shape === 'circle' ? '50%' : d.shape === 'rounded' ? '6px' : '0';
        const size = d.iconSize;
        const cellSize = Math.round(size * 1.6);
        const items = d.platforms.filter(p => p.enabled && p.url).map(p => {
          return `<td style="padding:0 ${Math.round(d.spacing/2)}px;"><a href="${esc(p.url)}" target="_blank" rel="noopener" style="display:inline-block;width:${cellSize}px;height:${cellSize}px;line-height:${cellSize}px;background:${d.bgColor};color:${d.iconColor};border-radius:${radius};text-align:center;font-weight:700;font-family:${esc(state.body.fontFamily)};font-size:${Math.round(size*0.6)}px;text-decoration:none;">${esc(p.id[0].toUpperCase())}</a></td>`;
        }).join('');
        return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"${cls}><tr><td align="${alignAttr}" style="padding:${d.paddingTop}px 0 ${d.paddingBottom}px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>${items}</tr></table></td></tr></table>`;
      }
      case 'video': {
        return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"${cls}><tr><td align="center" style="padding:${d.paddingTop}px 0 ${d.paddingBottom}px;"><a href="${esc(d.url)}" target="_blank" rel="noopener"><img src="${esc(d.thumbSrc)}" alt="${esc(d.alt)}" style="display:block;max-width:100%;" /></a></td></tr></table>`;
      }
    }
    return '';
  }
  function textBlockStyle(d) {
    const ff = d.fontFamily ? `font-family:${d.fontFamily};` : '';
    const color = d.color ? `color:${d.color};` : '';
    const bg = d.backgroundColor && d.backgroundColor !== 'transparent' ? `background:${d.backgroundColor};` : '';
    return `${ff}font-size:${d.fontSize}px;font-weight:${d.fontWeight};font-style:${d.fontStyle};text-align:${d.textAlign};${color}line-height:${d.lineHeight};letter-spacing:${d.letterSpacing}px;padding:${d.paddingTop}px ${d.paddingRight||0}px ${d.paddingBottom}px ${d.paddingLeft||0}px;${bg}`;
  }
  /* ============================================================
   * View HTML modal + Send test
   * ============================================================ */
  function showHtmlModal() {
    const html = getHTML();
    const bytes = new Blob([html]).size;
    const modal = openModal('Exported HTML',
      `<p style="margin:0 0 8px;color:#666;font-size:13px;">${bytes} bytes ${bytes > MAX_HTML_BYTES ? '<span style="color:#c0392b;">— Gmail may clip emails over 102 KB</span>' : ''}</p>
       <textarea readonly style="width:100%;height:380px;font-family:monospace;font-size:12px;">${esc(html)}</textarea>
       <div style="display:flex;gap:8px;margin-top:10px;">
         <button id="eb-copy">Copy</button>
         <button id="eb-dl">Download .html</button>
       </div>`);
    modal.querySelector('#eb-copy').onclick = () => { navigator.clipboard.writeText(html); setStatus('Copied'); setTimeout(()=>setStatus(''),1500); };
    modal.querySelector('#eb-dl').onclick = () => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([html], { type: 'text/html' }));
      a.download = (state.meta.name || 'email') + '.html';
      a.click();
    };
  }
  function promptSendTest() {
    const to = document.getElementById('eb-test-email')?.value || '';
    if (!to) { setStatus('Enter test email address', true); return; }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) { setStatus('Invalid email address', true); return; }
    sendTest(to);
  }
  function sendTest(to) {
    setStatus('Sending test…');
    fetch('/api/email.php?action=send_test&_cb=' + Date.now(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        csrf_token: state.csrfToken,
        test_email: to,
        subject: state.meta.subject || 'Test from email builder',
        content_html: getHTML()
      })
    }).then(r => r.json()).then(resp => {
      if (resp && resp.success) {
        setStatus('Test sent ✓');
        if (typeof showNotification === 'function') showNotification('Test email sent successfully', 'success');
        setTimeout(()=>setStatus(''),2500);
      } else {
        setStatus('Test failed', true);
        if (typeof showNotification === 'function') showNotification(resp?.message || 'Test failed', 'error');
      }
    }).catch(() => {
      setStatus('Network error', true);
      if (typeof showNotification === 'function') showNotification('Network error — could not send test', 'error');
    });
  }
  function openModal(title, innerHtml) {
    const $wrap = document.createElement('div'); $wrap.className = 'eb-modal-wrap';
    $wrap.innerHTML = `<div class="eb-modal"><div class="eb-modal-header"><strong>${esc(title)}</strong><button class="eb-modal-close">✕</button></div><div class="eb-modal-body">${innerHtml}</div></div>`;
    $wrap.querySelector('.eb-modal-close').onclick = () => $wrap.remove();
    $wrap.addEventListener('click', e => { if (e.target === $wrap) $wrap.remove(); });
    document.body.appendChild($wrap);
    return $wrap;
  }
  /* ============================================================
   * Deferred (text edits that snapshot on commit)
   * ============================================================ */
  let deferTimer = null;
  function deferred() {
    state.isDirty = true;
    scheduleAutosave();
    clearTimeout(deferTimer);
    deferTimer = setTimeout(() => pushHistory(), 600);
  }
  /* ============================================================
   * Public API
   * ============================================================ */
  root.EmailBuilder = {
    init: init,
    getJSON: getJSON,
    getHTML: getHTML,
    setPreviewSize: setPreviewSize,
    setDirection: setDirection,
    setDarkMode: setDarkMode,
    undo: undo,
    redo: redo,
    sendTest: sendTest
  };
})(window);