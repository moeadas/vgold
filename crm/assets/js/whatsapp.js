/**
 * Victory Genomics CRM — WhatsApp Chat Panel
 * Slide-out chat UI for messaging leads
 * Supports Twilio Content Templates for business-initiated messages
 * Supports file attachments (images, documents, audio, video)
 */
const WhatsAppChat = {
    currentLeadId: null,
    currentToNumber: null,
    currentLeadName: '',
    templates: [],           // Legacy DB templates
    contentTemplates: [],    // Twilio Content Templates (Meta-approved)
    pollTimer: null,
    insideWindow: null,      // null = unknown, true/false
    pendingMedia: null,      // { url, filename, category, mime_type } — staged attachment

    /** Open chat for a specific lead */
    async open(leadId, toNumber, leadName) {
        try {
            this.currentLeadId = leadId;
            this.currentToNumber = toNumber;
            this.currentLeadName = leadName || 'Lead';
            this.insideWindow = null;
            this.pendingMedia = null;

            this.createPanel();
            this.showPanel();

            // Load everything in parallel
            Promise.all([
                this.loadContentTemplates().catch(e => console.warn('Content templates load error:', e)),
                this.loadHistory().catch(e => console.warn('History load error:', e)),
                this.checkWindow().catch(e => console.warn('Window check error:', e))
            ]);
            this.startPolling();
        } catch (err) {
            console.error('WhatsApp panel open error:', err);
            if (typeof showNotification === 'function') {
                showNotification('Failed to open WhatsApp chat: ' + err.message, 'error');
            }
        }
    },

    /** Check the 24h service window */
    async checkWindow() {
        if (!this.currentToNumber) return;
        try {
            const resp = await fetch('/crm/api/whatsapp.php?action=check_window&phone=' + encodeURIComponent(this.currentToNumber));
            const data = await resp.json();
            if (data.success) {
                this.insideWindow = data.inside_window;
                this.updateWindowBanner();
            }
        } catch (e) {
            console.warn('Window check failed:', e);
        }
    },

    /** Show/hide a banner indicating 24h window status */
    updateWindowBanner() {
        let banner = document.getElementById('wa-window-banner');
        if (!banner) return;

        if (this.insideWindow === true) {
            banner.innerHTML = `
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#34c759" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="16 8 10 16 8 12"/></svg>
                <span>Free-form messages allowed (contact replied within 24h)</span>`;
            banner.className = 'wa-window-banner wa-window-open';
        } else if (this.insideWindow === false) {
            banner.innerHTML = `
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ff9f0a" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span>Outside 24h window — use a template to initiate conversation</span>`;
            banner.className = 'wa-window-banner wa-window-closed';
        } else {
            banner.innerHTML = '';
            banner.className = 'wa-window-banner';
        }
    },

    /** Create the chat panel DOM */
    createPanel() {
        let panel = document.getElementById('wa-panel');
        if (panel) {
            const nameEl = panel.querySelector('.wa-contact-name');
            const numEl = panel.querySelector('.wa-contact-number');
            if (nameEl) nameEl.textContent = this.currentLeadName;
            if (numEl) numEl.textContent = this.currentToNumber;
            // Clear any pending attachment from previous session
            this.clearAttachment();
            return;
        }

        panel = document.createElement('div');
        panel.id = 'wa-panel';
        panel.className = 'wa-panel';
        panel.innerHTML = `
            <div class="wa-panel-header">
                <div class="wa-header-left">
                    <div class="wa-avatar">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    </div>
                    <div>
                        <div class="wa-contact-name">${escapeHtml(this.currentLeadName)}</div>
                        <div class="wa-contact-number">${escapeHtml(this.currentToNumber)}</div>
                    </div>
                </div>
                <div class="wa-header-right">
                    <button class="wa-header-btn" onclick="WhatsAppChat.showTemplates()" title="Send Template">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                    </button>
                    <button class="wa-header-btn" onclick="WhatsAppChat.close()" title="Close">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            </div>
            <div id="wa-window-banner" class="wa-window-banner"></div>
            <div class="wa-messages" id="wa-messages">
                <div class="wa-loading">Loading messages...</div>
            </div>
            <div id="wa-templates-panel" class="wa-templates-panel" style="display:none;"></div>
            <div id="wa-attach-preview" class="wa-attach-preview" style="display:none;"></div>
            <div class="wa-composer">
                <input type="file" id="wa-file-input" style="display:none;"
                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.mp3,.ogg,.mp4,.3gp"
                    onchange="WhatsAppChat.handleFileSelect(this)">
                <button class="wa-attach-btn" onclick="document.getElementById('wa-file-input').click()" title="Attach file">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                </button>
                <textarea id="wa-input" class="wa-input" placeholder="Type a message..." rows="1"
                    onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();WhatsAppChat.send();}"
                    oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,80)+'px'"></textarea>
                <button class="wa-send-btn" onclick="WhatsAppChat.send()" title="Send">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </div>`;
        document.body.appendChild(panel);
    },

    showPanel() {
        const panel = document.getElementById('wa-panel');
        if (panel) {
            panel.style.display = 'flex';
            setTimeout(() => panel.classList.add('active'), 10);
        }
    },

    close() {
        this.stopPolling();
        this.clearAttachment();
        const panel = document.getElementById('wa-panel');
        if (panel) {
            panel.classList.remove('active');
            setTimeout(() => { panel.style.display = 'none'; }, 300);
        }
        // Hide templates panel
        const tplPanel = document.getElementById('wa-templates-panel');
        if (tplPanel) tplPanel.style.display = 'none';
        this.currentLeadId = null;
        this.currentToNumber = null;
    },

    // ─── ATTACHMENT HANDLING ───

    /** Handle file selection from the file input */
    handleFileSelect(input) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        const maxSize = 16 * 1024 * 1024; // 16MB

        if (file.size > maxSize) {
            if (typeof showNotification === 'function') showNotification('File too large. Maximum 16MB for WhatsApp.', 'error');
            input.value = '';
            return;
        }

        this.uploadAndStageFile(file);
        input.value = ''; // Reset so same file can be re-selected
    },

    /** Upload file to server, then stage it as pending attachment */
    async uploadAndStageFile(file) {
        const preview = document.getElementById('wa-attach-preview');
        if (!preview) return;

        // Show uploading state
        const isImage = file.type.startsWith('image/');
        const iconSvg = this.getFileIcon(file.type);
        const sizeStr = this.formatFileSize(file.size);

        preview.innerHTML = `
            <div class="wa-attach-item wa-attach-uploading">
                <div class="wa-attach-info">
                    ${isImage ? '' : '<span class="wa-attach-icon">' + iconSvg + '</span>'}
                    <span class="wa-attach-name">${escapeHtml(file.name)}</span>
                    <span class="wa-attach-size">${sizeStr}</span>
                </div>
                <span class="wa-attach-status">Uploading...</span>
            </div>`;
        preview.style.display = 'block';

        // If it's an image, show a thumbnail preview
        if (isImage) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const infoEl = preview.querySelector('.wa-attach-info');
                if (infoEl) {
                    infoEl.insertAdjacentHTML('afterbegin',
                        '<img src="' + e.target.result + '" class="wa-attach-thumb" style="width:40px;height:40px;max-width:40px;max-height:40px;object-fit:cover;border-radius:6px;flex-shrink:0;display:block;" alt="Preview">');
                }
            };
            reader.readAsDataURL(file);
        }

        try {
            const csrfEl = document.getElementById('globalCsrfToken');
            const csrfToken = csrfEl ? csrfEl.value : '';

            const fd = new FormData();
            fd.append('file', file);
            fd.append('csrf_token', csrfToken);

            const resp = await fetch('/crm/api/upload-media.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });

            const data = await resp.json();
            if (!data.success) {
                throw new Error(data.message || 'Upload failed');
            }

            // Stage the uploaded file
            this.pendingMedia = {
                url: data.url,
                filename: data.filename,
                category: data.category,
                mime_type: data.mime_type,
                size: data.size
            };

            // Update preview to "ready" state
            preview.innerHTML = `
                <div class="wa-attach-item wa-attach-ready">
                    ${isImage ? '<img src="' + escapeHtml(data.url) + '" class="wa-attach-thumb" style="width:40px;height:40px;max-width:40px;max-height:40px;object-fit:cover;border-radius:6px;flex-shrink:0;display:block;" alt="Preview">' : '<span class="wa-attach-icon">' + iconSvg + '</span>'}
                    <div class="wa-attach-info">
                        <span class="wa-attach-name">${escapeHtml(data.filename)}</span>
                        <span class="wa-attach-size">${sizeStr}</span>
                    </div>
                    <button class="wa-attach-remove" onclick="WhatsAppChat.clearAttachment()" title="Remove attachment">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>`;
            preview.style.display = 'block';

        } catch (err) {
            console.error('File upload error:', err);
            if (typeof showNotification === 'function') showNotification('Upload failed: ' + err.message, 'error');
            this.clearAttachment();
        }
    },

    /** Clear the pending attachment */
    clearAttachment() {
        this.pendingMedia = null;
        const preview = document.getElementById('wa-attach-preview');
        if (preview) {
            preview.innerHTML = '';
            preview.style.display = 'none';
        }
        const fileInput = document.getElementById('wa-file-input');
        if (fileInput) fileInput.value = '';
    },

    /** Get an SVG icon for a file type */
    getFileIcon(mimeType) {
        if (mimeType.startsWith('video/')) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>';
        if (mimeType.startsWith('audio/')) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
        if (mimeType === 'application/pdf') return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ff3b30" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
    },

    /** Format file size for display */
    formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    },

    /** Load chat history */
    async loadHistory() {
        const container = document.getElementById('wa-messages');
        if (!container) return;

        try {
            const params = new URLSearchParams();
            if (this.currentLeadId) params.set('lead_id', this.currentLeadId);
            else if (this.currentToNumber) params.set('to_number', this.currentToNumber);

            const resp = await fetch('/crm/api/whatsapp.php?action=chat_history&' + params.toString());
            if (!resp.ok) throw new Error('Server returned ' + resp.status);
            const data = await resp.json();

            if (data.success && data.data && data.data.length > 0) {
                container.innerHTML = data.data.map(msg => this.renderMessage(msg)).join('');
                container.scrollTop = container.scrollHeight;
            } else {
                container.innerHTML = `
                    <div class="wa-empty">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="1.5"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                        <p>No messages yet</p>
                        <p class="text-muted">Send a WhatsApp message to start the conversation</p>
                    </div>`;
            }
        } catch (err) {
            container.innerHTML = '<div class="wa-empty"><p>Failed to load messages</p></div>';
        }
    },

    /** Render a single message bubble */
    renderMessage(msg) {
        const isOutbound = msg.direction === 'Outbound';
        const time = new Date(msg.created_at || msg.sent_at).toLocaleTimeString('en-US', {
            hour: '2-digit', minute: '2-digit'
        });
        const statusIcon = this.getStatusIcon(msg.status);

        // Build media HTML
        let mediaHtml = '';
        if (msg.media_url) {
            const mtype = (msg.media_type || '').toLowerCase();
            const url = escapeHtml(msg.media_url);
            if (mtype.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp)$/i.test(msg.media_url)) {
                mediaHtml = `<a href="${url}" target="_blank" rel="noopener"><img src="${url}" class="wa-media-img" alt="Image" loading="lazy"></a>`;
            } else if (mtype.startsWith('video/') || /\.(mp4|3gp)$/i.test(msg.media_url)) {
                mediaHtml = `<video src="${url}" class="wa-media-video" controls preload="metadata"></video>`;
            } else if (mtype.startsWith('audio/') || /\.(mp3|ogg|amr|aac)$/i.test(msg.media_url)) {
                mediaHtml = `<audio src="${url}" class="wa-media-audio" controls preload="metadata"></audio>`;
            } else {
                // Document — show download link
                const fname = msg.media_url.split('/').pop() || 'Document';
                mediaHtml = `<a href="${url}" target="_blank" rel="noopener" class="wa-media-doc">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>${escapeHtml(decodeURIComponent(fname))}</span>
                </a>`;
            }
        }

        const bodyText = (msg.message_body || '').trim();

        return `<div class="wa-message ${isOutbound ? 'wa-message-out' : 'wa-message-in'}">
            <div class="wa-bubble">
                ${mediaHtml}
                ${bodyText ? '<div class="wa-text">' + escapeHtml(bodyText) + '</div>' : ''}
                <div class="wa-meta">
                    <span class="wa-time">${time}</span>
                    ${isOutbound ? '<span class="wa-status">' + statusIcon + '</span>' : ''}
                </div>
            </div>
        </div>`;
    },

    getStatusIcon(status) {
        switch (status) {
            case 'Sent':      return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#86868b" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
            case 'Delivered':  return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0071e3" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
            case 'Read':       return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#34c759" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
            case 'Failed':     return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ff3b30" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
            default:           return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#86868b" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
        }
    },

    /** Send a free-form message (with optional attachment) */
    async send() {
        const input = document.getElementById('wa-input');
        const body = (input ? input.value : '').trim();
        const hasMedia = !!this.pendingMedia;

        // Need either text or attachment
        if (!body && !hasMedia) return;

        if (!this.currentToNumber) {
            if (typeof showNotification === 'function') showNotification('No recipient phone number', 'error');
            return;
        }

        // Capture media info before clearing
        const mediaUrl = hasMedia ? this.pendingMedia.url : null;
        const mediaFilename = hasMedia ? this.pendingMedia.filename : null;
        const mediaCategory = hasMedia ? this.pendingMedia.category : null;

        // Clear input and attachment
        if (input) { input.value = ''; input.style.height = 'auto'; }
        this.clearAttachment();

        // Optimistic UI
        const container = document.getElementById('wa-messages');
        if (container) {
            const emptyEl = container.querySelector('.wa-empty');
            if (emptyEl) emptyEl.remove();
        }

        const tempMsg = this.renderMessage({
            direction: 'Outbound',
            message_body: body || (mediaFilename ? 'Sent: ' + mediaFilename : ''),
            media_url: mediaUrl,
            media_type: mediaCategory === 'image' ? 'image/jpeg' : (mediaCategory === 'video' ? 'video/mp4' : ''),
            created_at: new Date().toISOString(),
            status: 'Queued'
        });
        if (container) {
            container.insertAdjacentHTML('beforeend', tempMsg);
            container.scrollTop = container.scrollHeight;
        }

        try {
            const payload = {
                to_number: this.currentToNumber,
                body: body || (mediaFilename || 'Attachment'),
                lead_id: this.currentLeadId || 0
            };
            if (mediaUrl) {
                payload.media_url = mediaUrl;
            }

            const resp = await fetch('/crm/api/whatsapp.php?action=send', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });

            if (!resp.ok) throw new Error('Server returned ' + resp.status);
            const data = await resp.json();
            if (!data.success) {
                if (typeof showNotification === 'function') showNotification('WhatsApp: ' + (data.message || 'Send failed'), 'error');
            } else {
                if (typeof showNotification === 'function') showNotification('WhatsApp message sent', 'success');
                this.checkWindow();
            }
        } catch (err) {
            console.error('WhatsApp send error:', err);
            if (typeof showNotification === 'function') showNotification('Failed to send WhatsApp message: ' + err.message, 'error');
        }
    },

    // ─── TWILIO CONTENT TEMPLATES ───

    /** Load Twilio Content Templates (Meta-approved) */
    async loadContentTemplates() {
        try {
            const resp = await fetch('/crm/api/whatsapp.php?action=content_templates');
            if (!resp.ok) throw new Error('Server returned ' + resp.status);
            const data = await resp.json();
            if (data.success) {
                this.contentTemplates = data.data || [];
            }
        } catch (e) {
            console.warn('Failed to load content templates:', e);
        }
    },

    /** Show templates panel with Twilio Content Templates */
    showTemplates() {
        const panel = document.getElementById('wa-templates-panel');
        if (!panel) return;

        if (panel.style.display !== 'none') {
            panel.style.display = 'none';
            return;
        }

        // Filter to only show approved templates (or all with status indicators)
        const approved = this.contentTemplates.filter(t => t.approval_status === 'approved');
        const pending = this.contentTemplates.filter(t => t.approval_status === 'pending' || t.approval_status === 'received');
        const allUsable = approved;

        let html = `
            <div class="wa-templates-header">
                <strong>WhatsApp Templates</strong>
                <button class="wa-header-btn" onclick="document.getElementById('wa-templates-panel').style.display='none'" style="background:rgba(0,0,0,0.06);">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>`;

        if (allUsable.length === 0 && pending.length === 0) {
            html += `<div class="wa-template-empty">
                <p>No approved templates available yet.</p>
                <p style="font-size:11px;margin-top:6px;color:#86868b;">Templates are pending Meta approval. This usually takes a few minutes to a few hours.</p>
            </div>`;
        } else {
            html += '<div class="wa-templates-list">';

            // Approved templates — clickable
            allUsable.forEach(t => {
                const varCount = Object.keys(t.variables || {}).length;
                html += `
                    <div class="wa-template-item wa-tpl-approved" onclick="WhatsAppChat.openTemplateFill('${escapeHtml(t.content_sid)}')">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <div class="wa-template-name">${escapeHtml(t.friendly_name)}</div>
                            <span class="wa-tpl-status wa-tpl-status-approved">Approved</span>
                        </div>
                        <div class="wa-template-cat">${escapeHtml(t.category || 'UTILITY')}${varCount ? ' &middot; ' + varCount + ' variable' + (varCount > 1 ? 's' : '') : ''}</div>
                        <div class="wa-template-preview">${escapeHtml(t.body || '').substring(0, 120)}${(t.body || '').length > 120 ? '...' : ''}</div>
                    </div>`;
            });

            // Pending templates — not clickable
            pending.forEach(t => {
                html += `
                    <div class="wa-template-item wa-tpl-pending" style="opacity:0.6;cursor:default;">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <div class="wa-template-name">${escapeHtml(t.friendly_name)}</div>
                            <span class="wa-tpl-status wa-tpl-status-pending">Pending</span>
                        </div>
                        <div class="wa-template-cat">${escapeHtml(t.category || '')}</div>
                        <div class="wa-template-preview">${escapeHtml(t.body || '').substring(0, 120)}</div>
                    </div>`;
            });

            html += '</div>';
        }

        panel.innerHTML = html;
        panel.style.display = 'block';
    },

    /** Open the variable-fill modal for a specific template */
    openTemplateFill(contentSid) {
        const tpl = this.contentTemplates.find(t => t.content_sid === contentSid);
        if (!tpl) return;

        // Hide the templates list
        const tplPanel = document.getElementById('wa-templates-panel');
        if (tplPanel) tplPanel.style.display = 'none';

        // Build the fill form
        const vars = tpl.variables || {};
        const varKeys = Object.keys(vars);
        let body = tpl.body || '';

        let formHtml = `
            <div class="wa-tpl-fill-overlay" id="wa-tpl-fill-overlay">
                <div class="wa-tpl-fill-modal">
                    <div class="wa-tpl-fill-header">
                        <strong>Send Template: ${escapeHtml(tpl.friendly_name)}</strong>
                        <button class="wa-header-btn" onclick="WhatsAppChat.closeTemplateFill()" style="background:rgba(0,0,0,0.06);">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="wa-tpl-fill-body">
                        <div class="wa-tpl-preview-box">
                            <div class="wa-tpl-preview-label">Template Preview</div>
                            <div class="wa-tpl-preview-text" id="wa-tpl-preview">${escapeHtml(body)}</div>
                        </div>`;

        if (varKeys.length > 0) {
            formHtml += '<div class="wa-tpl-vars-section"><div class="wa-tpl-vars-label">Fill in variables:</div>';
            varKeys.forEach(key => {
                const desc = vars[key] || 'Variable ' + key;
                formHtml += `
                    <div class="wa-tpl-var-row">
                        <label class="wa-tpl-var-label">{{${key}}} — ${escapeHtml(desc)}</label>
                        <input type="text" class="wa-tpl-var-input" data-var-key="${key}"
                               placeholder="${escapeHtml(desc)}"
                               oninput="WhatsAppChat.updateTemplatePreview('${escapeHtml(contentSid)}')">
                    </div>`;
            });
            formHtml += '</div>';
        }

        formHtml += `
                    </div>
                    <div class="wa-tpl-fill-footer">
                        <button class="btn btn-outline btn-sm" onclick="WhatsAppChat.closeTemplateFill()">Cancel</button>
                        <button class="btn btn-sm" style="background:#25D366;color:#fff;border:none;" onclick="WhatsAppChat.sendContentTemplate('${escapeHtml(contentSid)}')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><path d="M22 2L11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            Send Template
                        </button>
                    </div>
                </div>
            </div>`;

        // Inject into the panel
        const panel = document.getElementById('wa-panel');
        if (panel) {
            panel.insertAdjacentHTML('beforeend', formHtml);
            // Auto-fill variables from lead data if available
            this.autoFillTemplateVars(contentSid);
        }
    },

    /** Auto-fill known variables from the current lead context */
    autoFillTemplateVars(contentSid) {
        const tpl = this.contentTemplates.find(t => t.content_sid === contentSid);
        if (!tpl) return;

        const vars = tpl.variables || {};
        const inputs = document.querySelectorAll('.wa-tpl-var-input');

        // Map common variable descriptions to auto-fill values
        const autoMap = {
            'contact_name': this.currentLeadName || '',
            'contact name': this.currentLeadName || '',
            'first name': (this.currentLeadName || '').split(' ')[0] || '',
            'name': this.currentLeadName || '',
        };

        inputs.forEach(input => {
            const key = input.getAttribute('data-var-key');
            const desc = (vars[key] || '').toLowerCase();
            // Try to match description to auto-fill
            for (const [pattern, value] of Object.entries(autoMap)) {
                if (desc.includes(pattern) && value) {
                    input.value = value;
                    break;
                }
            }
        });

        // Update preview after auto-fill
        this.updateTemplatePreview(contentSid);
    },

    /** Update the live preview as variables are filled */
    updateTemplatePreview(contentSid) {
        const tpl = this.contentTemplates.find(t => t.content_sid === contentSid);
        if (!tpl) return;

        let body = tpl.body || '';
        const inputs = document.querySelectorAll('.wa-tpl-var-input');
        inputs.forEach(input => {
            const key = input.getAttribute('data-var-key');
            const val = input.value.trim();
            if (val) {
                body = body.replace(new RegExp('\\{\\{' + key + '\\}\\}', 'g'), '<strong style="color:#25D366;">' + escapeHtml(val) + '</strong>');
            }
        });

        const previewEl = document.getElementById('wa-tpl-preview');
        if (previewEl) previewEl.innerHTML = body;
    },

    /** Close the template fill modal */
    closeTemplateFill() {
        const overlay = document.getElementById('wa-tpl-fill-overlay');
        if (overlay) overlay.remove();
    },

    /** Send a Twilio Content Template with filled variables */
    async sendContentTemplate(contentSid) {
        const tpl = this.contentTemplates.find(t => t.content_sid === contentSid);
        if (!tpl) return;

        // Collect variable values
        const variables = {};
        const inputs = document.querySelectorAll('.wa-tpl-var-input');
        let allFilled = true;
        inputs.forEach(input => {
            const key = input.getAttribute('data-var-key');
            const val = input.value.trim();
            if (!val) {
                allFilled = false;
                input.style.borderColor = '#ff3b30';
            } else {
                input.style.borderColor = '';
                variables[key] = val;
            }
        });

        if (!allFilled) {
            if (typeof showNotification === 'function') showNotification('Please fill in all template variables', 'error');
            return;
        }

        // Close the fill modal
        this.closeTemplateFill();

        // Optimistic UI
        let bodyPreview = tpl.body || '(Template message)';
        for (const [k, v] of Object.entries(variables)) {
            bodyPreview = bodyPreview.replace(new RegExp('\\{\\{' + k + '\\}\\}', 'g'), v);
        }

        const container = document.getElementById('wa-messages');
        if (container) {
            const emptyEl = container.querySelector('.wa-empty');
            if (emptyEl) emptyEl.remove();
            const tempMsg = this.renderMessage({
                direction: 'Outbound',
                message_body: bodyPreview,
                created_at: new Date().toISOString(),
                status: 'Queued'
            });
            container.insertAdjacentHTML('beforeend', tempMsg);
            container.scrollTop = container.scrollHeight;
        }

        try {
            const resp = await fetch('/crm/api/whatsapp.php?action=send_content_template', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    content_sid: contentSid,
                    to_number: this.currentToNumber,
                    lead_id: this.currentLeadId || 0,
                    variables: variables
                })
            });

            if (!resp.ok) throw new Error('Server returned ' + resp.status);
            const data = await resp.json();
            if (data.success) {
                if (typeof showNotification === 'function') showNotification('Template message sent!', 'success');
            } else {
                if (typeof showNotification === 'function') showNotification('WhatsApp: ' + (data.message || 'Failed'), 'error');
            }
        } catch (err) {
            console.error('WhatsApp content template send error:', err);
            if (typeof showNotification === 'function') showNotification('Failed to send template: ' + err.message, 'error');
        }
    },

    /** Poll for new messages */
    startPolling() {
        this.stopPolling();
        this.pollTimer = setInterval(() => this.loadHistory(), 15000);
    },

    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }
};
