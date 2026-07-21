    </main>

    <script>
    // Mobile sidebar toggle with backdrop
    function toggleMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        sidebar.classList.toggle('active');
        backdrop.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
    }
    // Close sidebar on navigation (mobile)
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) toggleMobileSidebar();
        });
    });
    </script>
    <script>
    // Admin Switch User functionality
    function handleSwitchUser(userId) {
        if (!userId) return;
        var csrfToken = document.querySelector('input[name="csrf_token"]');
        var token = csrfToken ? csrfToken.value : '<?php echo generateCSRFToken(); ?>';
        var fd = new FormData();
        fd.append('csrf_token', token);
        fd.append('action', 'switch');
        fd.append('user_id', userId);
        fetch('/api/switch-user.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.message || 'Failed to switch user');
                var sel = document.getElementById('switchUserSelect');
                if (sel) sel.value = '';
            }
        })
        .catch(function(){ alert('Error switching user'); });
    }
    function handleSwitchBack() {
        var csrfToken = document.querySelector('input[name="csrf_token"]');
        var token = csrfToken ? csrfToken.value : '<?php echo generateCSRFToken(); ?>';
        var fd = new FormData();
        fd.append('csrf_token', token);
        fd.append('action', 'switch_back');
        fetch('/api/switch-user.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data.success) {
                window.location.href = '/dashboard.php';
            } else {
                alert(data.message || 'Failed to switch back');
            }
        })
        .catch(function(){ alert('Error switching back'); });
    }
    </script>
    <!-- Notification System -->
    <script>
    (function(){
        var POLL_INTERVAL = 30000; // 30 seconds
        var panelOpen = false;
        var notifData = [];

        function getNotifIcon(type) {
            switch(type) {
                case 'wa_inbound':   return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';
                case 'wa_unmatched': return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
                case 'lead_assigned':return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>';
                default:             return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
            }
        }

        function timeAgo(dateStr) {
            var now = new Date();
            var d = new Date(dateStr);
            var diff = Math.floor((now - d) / 1000);
            if (diff < 60) return 'Just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return Math.floor(diff / 86400) + 'd ago';
        }

        function escapeHtmlNotif(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Poll unread count
        function pollUnreadCount() {
            fetch('/api/notifications.php?action=unread_count')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        var badge = document.getElementById('notifBadge');
                        if (badge) {
                            var count = data.count || 0;
                            badge.textContent = count > 99 ? '99+' : count;
                            badge.style.display = count > 0 ? 'inline-block' : 'none';
                        }
                    }
                })
                .catch(function() { /* silent */ });
        }

        // Load notifications into panel
        function loadNotifications() {
            fetch('/api/notifications.php?action=list')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) return;
                    notifData = data.data || [];
                    renderNotifications();
                })
                .catch(function() { /* silent */ });
        }

        function renderNotifications() {
            var body = document.getElementById('notifPanelBody');
            if (!body) return;
            if (!notifData.length) {
                body.innerHTML = '<div class="notif-empty">No notifications yet</div>';
                return;
            }
            var html = '';
            for (var i = 0; i < notifData.length; i++) {
                var n = notifData[i];
                var cls = n.is_read == 0 ? 'notif-item unread' : 'notif-item';
                html += '<div class="' + cls + '" data-id="' + n.notification_id + '" onclick="handleNotifClick(' + n.notification_id + ',\'' + escapeHtmlNotif(n.link || '') + '\')">';
                html += '<div class="notif-icon ' + escapeHtmlNotif(n.type) + '">' + getNotifIcon(n.type) + '</div>';
                html += '<div class="notif-content">';
                html += '<div class="notif-title">' + escapeHtmlNotif(n.title) + '</div>';
                if (n.body) html += '<div class="notif-body">' + escapeHtmlNotif(n.body) + '</div>';
                html += '<div class="notif-time">' + timeAgo(n.created_at) + '</div>';
                html += '</div>';
                if (n.is_read == 0) html += '<div class="notif-dot"></div>';
                html += '</div>';
            }
            body.innerHTML = html;
        }

        // Toggle panel
        window.toggleNotifPanel = function() {
            var panel = document.getElementById('notifPanel');
            if (!panel) return;
            panelOpen = !panelOpen;
            panel.style.display = panelOpen ? 'flex' : 'none';
            if (panelOpen) loadNotifications();
        };

        // Click a notification — mark read + navigate
        window.handleNotifClick = function(id, link) {
            fetch('/api/notifications.php?action=mark_read', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_id: id })
            }).then(function() {
                pollUnreadCount();
            }).catch(function(){});

            if (link) {
                window.location.href = link;
            } else {
                // Just mark read visually
                var el = document.querySelector('.notif-item[data-id="' + id + '"]');
                if (el) {
                    el.classList.remove('unread');
                    var dot = el.querySelector('.notif-dot');
                    if (dot) dot.remove();
                }
            }
        };

        // Mark all read
        window.markAllNotifRead = function() {
            fetch('/api/notifications.php?action=mark_all_read', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}'
            }).then(function() {
                pollUnreadCount();
                // Update UI
                var items = document.querySelectorAll('.notif-item.unread');
                for (var i = 0; i < items.length; i++) {
                    items[i].classList.remove('unread');
                    var dot = items[i].querySelector('.notif-dot');
                    if (dot) dot.remove();
                }
            }).catch(function(){});
        };

        // Close panel on outside click
        document.addEventListener('click', function(e) {
            var wrap = document.getElementById('notifBellWrap');
            if (panelOpen && wrap && !wrap.contains(e.target)) {
                var panel = document.getElementById('notifPanel');
                if (panel) panel.style.display = 'none';
                panelOpen = false;
            }
        });

        // Start polling
        pollUnreadCount();
        setInterval(pollUnreadCount, POLL_INTERVAL);
    })();
    </script>

    <script src="/assets/js/main.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@twilio/voice-sdk@2.13.0/dist/twilio.min.js"></script>
    <script src="/assets/js/voip.js?v=20260505e"></script>
    <script src="/assets/js/whatsapp.js"></script>
</body>
</html>
