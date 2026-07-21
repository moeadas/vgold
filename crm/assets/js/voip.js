/**
 * Victory Genomics CRM - VoIP Softphone (Twilio Voice SDK 2.x)
 * 
 * CALL FLOW (simple, single path):
 *   1. Log call in CRM → get call_id
 *   2. device.connect() → Twilio fetches TwiML App URL → <Dial><Number>lead</Number></Dial>
 *   3. Browser ↔ Twilio ↔ Lead phone  (two-way audio via WebRTC)
 *   4. On hangup: device fires 'disconnect', we clean up
 *
 * There is NO server-side fallback. If device.connect() fails, the call fails.
 * This prevents the double-call bug entirely.
 */
const VoIPPhone = {
    device: null,
    activeCall: null,
    callTimer: null,
    callSeconds: 0,
    currentCallId: null,
    currentCallSid: null,
    currentLeadId: null,
    currentNumber: null,
    initAttempted: false,
    initSuccess: false,
    initError: null,
    isMuted: false,
    callStartedAt: null,

    // ─── Sound helpers ───
    _audioCtx: null,
    _getAudioCtx() {
        if (!this._audioCtx) {
            try { this._audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) {}
        }
        return this._audioCtx;
    },

    playTone(freq, duration, type) {
        const ctx = this._getAudioCtx();
        if (!ctx) return;
        try {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = type || 'sine';
            osc.frequency.value = freq;
            gain.gain.value = 0.08;
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + (duration || 0.2));
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + (duration || 0.2));
        } catch(e) {}
    },

    playDialTone()    { this.playTone(440, 0.15); setTimeout(() => this.playTone(480, 0.15), 180); },
    playRingTone()    { this.playTone(440, 0.4);  setTimeout(() => this.playTone(480, 0.4), 500); },
    playConnectTone() { this.playTone(600, 0.1);  setTimeout(() => this.playTone(800, 0.15), 120); },
    playEndTone()     { this.playTone(400, 0.15); setTimeout(() => this.playTone(300, 0.2), 180); },
    playErrorTone()   { this.playTone(200, 0.3, 'sawtooth'); },

    // ─── Initialize ───
    async init() {
        this.initAttempted = true;
        console.log('VoIP: init() starting...');

        if (typeof Twilio === 'undefined' || !Twilio.Device) {
            console.error('VoIP INIT FAIL: Twilio Voice SDK not loaded. Check if https://cdn.jsdelivr.net/npm/@twilio/voice-sdk@2.13.0/dist/twilio.min.js loaded.');
            this.initError = 'Twilio SDK not loaded';
            this._updateReadyBadge();
            return;
        }
        try {
            console.log('VoIP: fetching token...');
            const resp = await fetch('/api/voip.php?action=token');
            console.log('VoIP: token response status:', resp.status);
            const data = await resp.json();
            if (!data.success) {
                console.error('VoIP INIT FAIL: token error:', data.message);
                this.initError = data.message;
                this._updateReadyBadge();
                return;
            }

            console.log('VoIP: token received, identity:', data.identity, 'length:', data.token?.length);

            // Codec enum lives on Twilio.Call in Voice SDK 2.x (not on Twilio.Device)
            const codecPrefs = (Twilio.Call && Twilio.Call.Codec)
                ? [Twilio.Call.Codec.Opus, Twilio.Call.Codec.PCMU]
                : ['opus', 'pcmu'];

            this.device = new Twilio.Device(data.token, {
                codecPreferences: codecPrefs,
                logLevel: 'warn',
                closeProtection: true,
                edge: 'ashburn',
            });

            this.device.on('registered', () => {
                console.log('VoIP: device registered');
                this.initSuccess = true;
                this.initError = null;
                this._updateReadyBadge();
            });

            this.device.on('unregistered', () => {
                console.warn('VoIP: device unregistered');
                this.initSuccess = false;
                this._updateReadyBadge();
            });

            this.device.on('error', (err) => {
                console.error('VoIP device error:', err.code, err.message);
                this.initError = err.message;
                this._updateReadyBadge();
            });

            this.device.on('incoming', (call) => this.handleIncoming(call));

            this.device.on('tokenWillExpire', async () => {
                try {
                    const r = await fetch('/api/voip.php?action=token');
                    const d = await r.json();
                    if (d.success) this.device.updateToken(d.token);
                } catch (e) { console.warn('Token refresh failed:', e); }
            });

            // Register (needed for incoming calls; outbound works without it)
            try {
                await this.device.register();
            } catch (regErr) {
                console.warn('VoIP register() failed (outbound calls still work):', regErr.message || regErr);
            }
            this._updateReadyBadge();
        } catch (err) {
            console.warn('VoIP init failed:', err.message || err);
            this.initError = err.message || 'Init failed';
            this._updateReadyBadge();
        }
    },

    _updateReadyBadge() {
        const badge = document.getElementById('voip-ready-badge');
        if (!badge) return;
        if (this.initSuccess) {
            badge.textContent = 'VoIP Ready';
            badge.style.background = '#34c759';
        } else if (this.device) {
            badge.textContent = 'VoIP Ready';
            badge.style.background = '#34c759';
        } else {
            badge.textContent = 'VoIP Unavailable';
            badge.style.background = '#ff3b30';
        }
    },

    // ─── Make an outbound call ───
    async call(toNumber, leadId) {
        if (!toNumber) {
            if (typeof showNotification === 'function') showNotification('No phone number provided', 'error');
            return;
        }

        // Strict duplicate guard
        if (this.activeCall || this.currentCallId) {
            if (typeof showNotification === 'function') showNotification('A call is already in progress', 'warning');
            return;
        }

        // Auto-retry init if device isn't ready
        if (!this.device) {
            console.log('VoIP device not ready, retrying init...');
            if (typeof showNotification === 'function') showNotification('Initializing VoIP...', 'info');
            await this.init();
            if (!this.device) {
                const errMsg = this.initError || 'Unknown error';
                console.error('VoIP init failed after retry:', errMsg);
                if (typeof showNotification === 'function') showNotification('VoIP failed: ' + errMsg + '. Check browser console.', 'error');
                return;
            }
        }

        this.currentNumber = toNumber;
        this.currentLeadId = leadId || 0;

        // Show UI
        this.playDialTone();
        this.showCallPanel(toNumber, 'Dialing...');
        this.updateStatus('connecting');

        // Step 1: Log call in CRM
        try {
            const resp = await fetch('/api/voip.php?action=call', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ to_number: toNumber, lead_id: leadId || 0 })
            });
            const data = await resp.json();
            if (data.success) {
                this.currentCallId = data.call_id;
            } else {
                this.playErrorTone();
                if (typeof showNotification === 'function') showNotification('Call error: ' + (data.message || 'Unknown'), 'error');
                this._resetState();
                return;
            }
        } catch (e) {
            console.error('Failed to log call:', e);
            this.playErrorTone();
            if (typeof showNotification === 'function') showNotification('Failed to initiate call', 'error');
            this._resetState();
            return;
        }

        // Step 2: Connect via WebRTC (the ONLY call path)
        // device.connect() → Twilio hits TwiML App → returns <Dial><Number>lead</Number></Dial>
        // The browser is one leg, the lead's phone is the other. Two-way audio.
        try {
            console.log('Calling', toNumber, 'via WebRTC');
            const call = await this.device.connect({
                params: {
                    To: toNumber,
                    call_id: this.currentCallId || ''
                }
            });

            this.activeCall = call;

            call.on('accept', () => {
                console.log('Call accepted - audio connected');
                this.onCallConnected();
            });

            call.on('disconnect', (c) => {
                console.log('Call disconnected');
                this.onCallEnded('completed');
            });

            call.on('cancel', () => {
                console.log('Call canceled');
                this.onCallEnded('canceled');
            });

            call.on('reject', () => {
                console.log('Call rejected');
                this.onCallEnded('rejected');
            });

            call.on('error', (err) => {
                console.error('Call error:', err.code, err.message);
                this.playErrorTone();
                if (typeof showNotification === 'function') showNotification('Call error: ' + (err.message || 'Unknown'), 'error');
                this.onCallEnded('failed');
            });

            call.on('ringing', () => {
                console.log('Ringing...');
                this.playRingTone();
                this.updateStatus('ringing');
            });

            call.on('reconnecting', () => this.updateStatus('reconnecting'));
            call.on('reconnected', () => this.updateStatus('in-call'));

        } catch (err) {
            console.error('device.connect() failed:', err);
            this.playErrorTone();
            if (typeof showNotification === 'function') {
                showNotification('Could not place call: ' + (err.message || 'Unknown error') + '. Please refresh and try again.', 'error');
            }
            // End the call in DB
            if (this.currentCallId) {
                fetch('/api/voip.php?action=end_call', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ call_id: this.currentCallId, duration: 0, reason: 'failed' })
                }).catch(() => {});
            }
            this._resetState();
        }
    },

    // ─── Handle incoming call ───
    handleIncoming(call) {
        if (this.activeCall) {
            console.warn('Already on a call, rejecting incoming');
            call.reject();
            return;
        }
        this.activeCall = call;
        const from = call.parameters.From || 'Unknown';
        this.currentNumber = from;
        this.playRingTone();
        this._ringInterval = setInterval(() => this.playRingTone(), 3000);

        this.showCallPanel(from, 'Incoming call...');
        this.updateStatus('ringing');

        const panel = document.getElementById('voip-panel');
        if (panel) {
            panel.querySelector('.voip-actions').innerHTML = `
                <button class="voip-btn voip-btn-accept" onclick="VoIPPhone.acceptCall()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    Accept
                </button>
                <button class="voip-btn voip-btn-end" onclick="VoIPPhone.rejectCall()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Reject
                </button>`;
        }
    },

    acceptCall() {
        if (this._ringInterval) { clearInterval(this._ringInterval); this._ringInterval = null; }
        if (this.activeCall) {
            this.activeCall.accept();
            this.onCallConnected();
        }
    },

    rejectCall() {
        if (this._ringInterval) { clearInterval(this._ringInterval); this._ringInterval = null; }
        if (this.activeCall) {
            this.activeCall.reject();
            this.onCallEnded('rejected');
        }
    },

    // ─── Call connected ───
    onCallConnected() {
        if (this._ringInterval) { clearInterval(this._ringInterval); this._ringInterval = null; }
        this.callSeconds = 0;
        this.callStartedAt = new Date();
        this.isMuted = false;
        this.playConnectTone();
        this.updateStatus('in-call');
        this.startTimer();
        this.showActiveCallUI();
    },

    // ─── Call ended ───
    onCallEnded(reason) {
        if (this._ringInterval) { clearInterval(this._ringInterval); this._ringInterval = null; }
        this.stopTimer();
        this.playEndTone();

        // Save to DB
        if (this.currentCallId) {
            const sid = this.currentCallSid || (this.activeCall && this.activeCall.parameters ? this.activeCall.parameters.CallSid : '') || '';
            fetch('/api/voip.php?action=end_call', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    call_id: this.currentCallId,
                    call_sid: sid,
                    duration: this.callSeconds,
                    reason: reason || 'completed'
                })
            }).catch(() => {});
        }

        this.showPostCallUI();
        this.activeCall = null;
    },

    // ─── Hang up ───
    hangup() {
        console.log('Hangup called. activeCall:', !!this.activeCall, 'currentCallId:', this.currentCallId);
        if (this.activeCall) {
            try {
                this.activeCall.disconnect();
            } catch (e) {
                console.warn('disconnect() error:', e);
                this.onCallEnded('completed');
            }
        } else if (this.currentCallId) {
            // Call was logged but WebRTC might have ended already
            this.onCallEnded('completed');
        } else {
            this._resetState();
        }
    },

    // ─── Toggle mute ───
    toggleMute() {
        if (!this.activeCall) return;
        this.isMuted = !this.isMuted;
        this.activeCall.mute(this.isMuted);
        const btn = document.getElementById('voip-mute-btn');
        if (btn) {
            btn.classList.toggle('active', this.isMuted);
            btn.title = this.isMuted ? 'Unmute' : 'Mute';
        }
        if (typeof showNotification === 'function') {
            showNotification(this.isMuted ? 'Muted' : 'Unmuted', 'info');
        }
    },

    // ─── DTMF ───
    sendDigit(digit) {
        if (this.activeCall && typeof this.activeCall.sendDigits === 'function') {
            this.activeCall.sendDigits(digit);
            this.playTone(697, 0.1);
        }
    },

    // ─── Timer ───
    startTimer() {
        this.stopTimer();
        this.callTimer = setInterval(() => {
            this.callSeconds++;
            const el = document.getElementById('voip-timer');
            if (el) el.textContent = this.formatDuration(this.callSeconds);
        }, 1000);
    },

    stopTimer() {
        if (this.callTimer) { clearInterval(this.callTimer); this.callTimer = null; }
    },

    formatDuration(s) {
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        if (h > 0) return h + ':' + String(m).padStart(2,'0') + ':' + String(sec).padStart(2,'0');
        return String(m).padStart(2,'0') + ':' + String(sec).padStart(2,'0');
    },

    // ─── Status UI ───
    updateStatus(status) {
        const labels = { ready:'Ready', connecting:'Dialing...', 'in-call':'Connected', ringing:'Ringing...', reconnecting:'Reconnecting...', error:'Error' };
        const colors = { ready:'#34c759', connecting:'#ff9f0a', 'in-call':'#34c759', ringing:'#ff9f0a', reconnecting:'#ff9f0a', error:'#ff3b30' };

        const el = document.getElementById('voip-status');
        if (el) { el.textContent = labels[status] || status; el.style.color = '#fff'; }

        const badge = document.getElementById('voip-status-badge');
        if (badge) { badge.style.background = colors[status] || '#86868b'; badge.textContent = labels[status] || status; }

        const pulseIcon = document.querySelector('.voip-pulse-icon');
        if (pulseIcon) {
            pulseIcon.classList.toggle('pulsing', status === 'ringing' || status === 'connecting');
        }
    },

    // ─── UI Panels ───
    showCallPanel(number, statusText) {
        let panel = document.getElementById('voip-panel');
        if (!panel) {
            panel = document.createElement('div');
            panel.id = 'voip-panel';
            panel.className = 'voip-panel';
            document.body.appendChild(panel);
        }
        panel.style.display = 'flex';
        const safe = (s) => typeof escapeHtml === 'function' ? escapeHtml(s) : s;
        panel.innerHTML = `
            <div class="voip-panel-header">
                <div class="voip-caller-info">
                    <div class="voip-pulse-icon pulsing">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </div>
                    <div>
                        <div class="voip-number">${safe(number)}</div>
                        <div class="voip-call-status" id="voip-status">${safe(statusText)}</div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <span id="voip-status-badge" class="voip-status-badge">${safe(statusText)}</span>
                    <div id="voip-timer" class="voip-timer">00:00</div>
                </div>
            </div>
            <div class="voip-actions">
                <button class="voip-btn voip-btn-mute" id="voip-mute-btn" onclick="VoIPPhone.toggleMute()" title="Mute">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                </button>
                <button class="voip-btn voip-btn-end" onclick="VoIPPhone.hangup()" title="End Call">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91"/><line x1="23" y1="1" x2="1" y2="23"/></svg>
                    End Call
                </button>
            </div>`;
    },

    showActiveCallUI() {
        const panel = document.getElementById('voip-panel');
        if (!panel) return;
        const actionsEl = panel.querySelector('.voip-actions');
        if (actionsEl) {
            actionsEl.innerHTML = `
                <button class="voip-btn voip-btn-mute" id="voip-mute-btn" onclick="VoIPPhone.toggleMute()" title="Mute">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                    Mute
                </button>
                <button class="voip-btn voip-btn-keypad" id="voip-keypad-btn" onclick="VoIPPhone.toggleKeypad()" title="Keypad">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="4" height="4" rx="1"/><rect x="10" y="4" width="4" height="4" rx="1"/><rect x="16" y="4" width="4" height="4" rx="1"/><rect x="4" y="10" width="4" height="4" rx="1"/><rect x="10" y="10" width="4" height="4" rx="1"/><rect x="16" y="10" width="4" height="4" rx="1"/><rect x="4" y="16" width="4" height="4" rx="1"/><rect x="10" y="16" width="4" height="4" rx="1"/><rect x="16" y="16" width="4" height="4" rx="1"/></svg>
                </button>
                <button class="voip-btn voip-btn-end" onclick="VoIPPhone.hangup()" title="End Call">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91"/><line x1="23" y1="1" x2="1" y2="23"/></svg>
                    End Call
                </button>`;
        }
        const existingKeypad = document.getElementById('voip-keypad');
        if (existingKeypad) existingKeypad.remove();
    },

    toggleKeypad() {
        let keypad = document.getElementById('voip-keypad');
        if (keypad) { keypad.remove(); return; }
        const panel = document.getElementById('voip-panel');
        if (!panel) return;
        keypad = document.createElement('div');
        keypad.id = 'voip-keypad';
        keypad.className = 'voip-keypad';
        const keys = ['1','2','3','4','5','6','7','8','9','*','0','#'];
        keypad.innerHTML = '<div class="voip-keypad-grid">' +
            keys.map(k => `<button class="voip-keypad-key" onclick="VoIPPhone.sendDigit('${k}')">${k}</button>`).join('') +
            '</div>';
        panel.appendChild(keypad);
    },

    showPostCallUI() {
        const panel = document.getElementById('voip-panel');
        if (!panel) return;
        const duration = this.formatDuration(this.callSeconds);
        panel.innerHTML = `
            <div class="voip-panel-header" style="background:linear-gradient(135deg,#34c759,#28a745);">
                <div class="voip-caller-info">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    <div>
                        <div class="voip-number">Call Ended</div>
                        <div class="voip-call-status">Duration: ${duration}</div>
                    </div>
                </div>
            </div>
            <div class="voip-post-call">
                <label style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:2px;">Call Outcome</label>
                <select id="voip-outcome" class="form-control voip-select">
                    <option value="">Select outcome...</option>
                    <option value="Positive">Positive - Interested / Good response</option>
                    <option value="Neutral">Neutral - Need follow-up</option>
                    <option value="Negative">Negative - Not interested</option>
                    <option value="No Response">No Response - Did not answer</option>
                    <option value="Voicemail">Voicemail - Left message</option>
                </select>
                <label style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:2px;">Notes</label>
                <textarea id="voip-notes" class="form-control voip-textarea" placeholder="Call notes..." rows="3"></textarea>
                <div class="voip-actions">
                    <button class="voip-btn voip-btn-save" onclick="VoIPPhone.saveCallLog()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        Save &amp; Close
                    </button>
                    <button class="voip-btn voip-btn-dismiss" onclick="VoIPPhone.dismissPanel()">Dismiss</button>
                </div>
            </div>`;
    },

    async saveCallLog() {
        const outcome = document.getElementById('voip-outcome')?.value || '';
        const notes = document.getElementById('voip-notes')?.value || '';
        if (this.currentCallId) {
            try {
                const resp = await fetch('/api/voip.php?action=log_call', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ call_id: this.currentCallId, outcome, notes, duration: this.callSeconds })
                });
                const data = await resp.json();
                if (data.success) {
                    if (typeof showNotification === 'function') showNotification('Call logged', 'success');
                }
            } catch (e) {
                if (typeof showNotification === 'function') showNotification('Failed to save log', 'error');
            }
        }
        this.dismissPanel();
        if (window.location.pathname.includes('voip-dashboard')) {
            setTimeout(() => window.location.reload(), 500);
        }
    },

    dismissPanel() {
        const panel = document.getElementById('voip-panel');
        if (panel) {
            panel.style.opacity = '0';
            panel.style.transform = 'translateY(20px)';
            setTimeout(() => { panel.style.display = 'none'; panel.style.opacity = ''; panel.style.transform = ''; }, 200);
        }
        this._resetState();
    },

    _resetState() {
        this.currentCallId = null;
        this.currentCallSid = null;
        this.currentLeadId = null;
        this.currentNumber = null;
        this.callSeconds = 0;
        this.isMuted = false;
        this.callStartedAt = null;
        this.activeCall = null;
        this.stopTimer();
        // Hide panel if still showing
        const panel = document.getElementById('voip-panel');
        if (panel) panel.style.display = 'none';
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => VoIPPhone.init());
