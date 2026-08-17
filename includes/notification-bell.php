<?php
/**
 * Shared Notification Bell Component
 * Include this after setting:
 *   $notifApiPath     — relative path to api/notifications.php from the current page
 *   $notifViewAllPath — relative path to the "view all notifications" page
 */
$notifApiPath     = $notifApiPath     ?? 'api/notifications.php';
$notifViewAllPath = $notifViewAllPath ?? 'notifications.php';
?>
<style>
.notif-bell-wrap { position: relative; display: inline-block; }
.notif-bell-btn { position:relative; width:38px; height:38px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all .2s; color:#475569; }
.notif-bell-btn:hover { background:#eff6ff; border-color:#bfdbfe; color:#1e3a8a; }
.notif-bell-badge { position:absolute; top:-4px; right:-4px; min-width:18px; height:18px; background:#ef4444; color:#fff; border-radius:9px; font-size:10px; font-weight:700; display:none; align-items:center; justify-content:center; padding:0 4px; border:2px solid #fff; }
.notif-bell-badge.show { display:flex; }
.notif-bell-dropdown { position:absolute; top:48px; right:0; width:320px; max-width:90vw; background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,.15); z-index:3000; display:none; overflow:hidden; }
.notif-bell-dropdown.open { display:block; }
.notif-bell-header { padding:12px 16px; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center; }
.notif-bell-header h4 { font-size:13px; font-weight:800; color:#0f172a; }
.notif-bell-header a { font-size:11px; color:#1e3a8a; text-decoration:none; font-weight:700; }
.notif-bell-list { max-height:340px; overflow-y:auto; }
.notif-bell-item { padding:11px 16px; display:flex; gap:10px; border-bottom:1px solid #f8fafc; cursor:pointer; transition:background .15s; }
.notif-bell-item:hover { background:#f8fafc; }
.notif-bell-item.unread { background:#eff6ff; }
.notif-bell-dot { width:8px; height:8px; border-radius:50%; background:#3b82f6; margin-top:5px; flex-shrink:0; }
.notif-bell-item.read .notif-bell-dot { background:transparent; }
.notif-bell-item-title { font-size:12.5px; font-weight:700; color:#0f172a; margin-bottom:2px; }
.notif-bell-item-msg { font-size:11.5px; color:#64748b; line-height:1.4; }
.notif-bell-item-time { font-size:10px; color:#94a3b8; margin-top:4px; }
.notif-bell-empty { padding:30px 16px; text-align:center; color:#94a3b8; font-size:12.5px; }
.notif-bell-footer { padding:10px 16px; border-top:1px solid #f1f5f9; text-align:center; }
.notif-bell-footer a { font-size:12px; font-weight:700; color:#1e3a8a; text-decoration:none; }
</style>

<div class="notif-bell-wrap" id="notifBellWrap">
    <button class="notif-bell-btn" id="notifBellBtn" type="button" aria-label="Notifications">
        <i class="fas fa-bell"></i>
        <span class="notif-bell-badge" id="notifBellBadge">0</span>
    </button>
    <div class="notif-bell-dropdown" id="notifBellDropdown">
        <div class="notif-bell-header">
            <h4>Notifications</h4>
            <a href="javascript:void(0)" id="notifMarkAllRead">Mark all read</a>
        </div>
        <div class="notif-bell-list" id="notifBellList">
            <div class="notif-bell-empty">Loading…</div>
        </div>
        <div class="notif-bell-footer">
            <a href="<?= htmlspecialchars($notifViewAllPath) ?>">Manage &amp; view all notifications</a>
        </div>
    </div>
</div>

<script>
(function() {
    var apiPath = <?= json_encode($notifApiPath) ?>;
    var btn = document.getElementById('notifBellBtn');
    var dropdown = document.getElementById('notifBellDropdown');
    var badge = document.getElementById('notifBellBadge');
    var list = document.getElementById('notifBellList');
    var markAllBtn = document.getElementById('notifMarkAllRead');

    function timeAgo(dateStr) {
        var d = new Date(dateStr.replace(' ', 'T'));
        var diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff/60) + 'm ago';
        if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff/86400) + 'd ago';
        return d.toLocaleDateString();
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function render(data) {
        if (!data.notifications || !data.notifications.length) {
            list.innerHTML = '<div class="notif-bell-empty">No notifications yet</div>';
        } else {
            list.innerHTML = data.notifications.map(function(n) {
                var isRead = n.is_read == 1;
                return '<div class="notif-bell-item ' + (isRead ? 'read' : 'unread') + '" data-id="' + n.id + '">' +
                    '<div class="notif-bell-dot"></div>' +
                    '<div>' +
                        '<div class="notif-bell-item-title">' + escapeHtml(n.title) + '</div>' +
                        '<div class="notif-bell-item-msg">' + escapeHtml(n.message) + '</div>' +
                        '<div class="notif-bell-item-time">' + timeAgo(n.created_at) + '</div>' +
                    '</div>' +
                '</div>';
            }).join('');
        }
        var unread = data.unread || 0;
        badge.textContent = unread > 9 ? '9+' : unread;
        badge.classList.toggle('show', unread > 0);
    }

    function load() {
        fetch(apiPath + '?action=list&limit=8')
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.success) render(d); })
            .catch(function() { list.innerHTML = '<div class="notif-bell-empty">Could not load notifications</div>'; });
    }

    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdown.classList.toggle('open');
        if (dropdown.classList.contains('open')) load();
    });

    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && !btn.contains(e.target)) dropdown.classList.remove('open');
    });

    list.addEventListener('click', function(e) {
        var item = e.target.closest('.notif-bell-item');
        if (!item) return;
        var id = item.getAttribute('data-id');
        if (item.classList.contains('unread')) {
            item.classList.remove('unread');
            item.classList.add('read');
            var fd = new FormData();
            fd.append('action', 'mark_read');
            fd.append('id', id);
            fetch(apiPath, { method: 'POST', body: fd }).then(load);
        }
    });

    markAllBtn.addEventListener('click', function() {
        var fd = new FormData();
        fd.append('action', 'mark_all_read');
        fetch(apiPath, { method: 'POST', body: fd }).then(load);
    });

    // Initial badge load (without opening dropdown) + periodic refresh
    load();
    setInterval(load, 30000);
})();
</script>
