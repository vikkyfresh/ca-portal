<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

$adminId = (int)$_SESSION['admin_id'];
$photoSrc = 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['admin_name'] ?? 'Admin') . '&background=1e3a8a&color=fff&size=80&bold=true';
$stmtPhoto = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
$stmtPhoto->execute([$adminId]);
$photoRow = $stmtPhoto->fetch();
if (!empty($photoRow['photo'])) {
    $sp = dirname(__DIR__) . '/' . ltrim($photoRow['photo'], '/');
    if (file_exists($sp)) $photoSrc = '../' . ltrim($photoRow['photo'], '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — Admin Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:-apple-system,'Segoe UI',sans-serif; }
body { background:#f4f6fb; }
.layout { display:flex; min-height:100vh; }
.main { flex:1; margin-left:260px; padding:32px; }
@media(max-width:900px){ .main{margin-left:0;} }
.top-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; flex-wrap:wrap; gap:10px; }
.top-row h1 { font-size:1.4rem; color:#0f172a; display:flex; align-items:center; gap:10px; }
.grid { display:grid; grid-template-columns:340px 1fr; gap:22px; align-items:start; }
@media(max-width:900px){ .grid{grid-template-columns:1fr;} }
.card { background:#fff; border-radius:16px; box-shadow:0 4px 18px rgba(15,23,42,.06); overflow:hidden; }
.card-pad { padding:20px; }
.card-pad h3 { font-size:14px; font-weight:800; color:#0f172a; margin-bottom:16px; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:6px; }
.form-group input, .form-group select, .form-group textarea {
    width:100%; padding:10px 12px; border:1.5px solid #e2e8f0; border-radius:9px; font-size:13.5px; font-family:inherit;
}
.form-group textarea { resize:vertical; min-height:90px; }
.btn-primary { width:100%; background:linear-gradient(135deg,#0f172a,#1e3a8a); color:#fff; border:none; padding:12px; border-radius:10px; font-weight:700; font-size:13.5px; cursor:pointer; }
.n-item { padding:16px 20px; border-bottom:1px solid #f1f5f9; display:flex; gap:12px; align-items:flex-start; }
.n-item:last-child { border-bottom:none; }
.n-item.inactive { opacity:.5; }
.n-title { font-weight:800; color:#0f172a; font-size:14px; margin-bottom:3px; }
.n-msg { color:#475569; font-size:13px; line-height:1.55; margin-bottom:6px; }
.n-meta { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.pill { font-size:10.5px; font-weight:700; padding:2px 9px; border-radius:999px; }
.pill-all { background:#e0e7ff; color:#3730a3; }
.pill-students { background:#dbeafe; color:#1e40af; }
.pill-lecturers { background:#fef3c7; color:#92400e; }
.n-time { color:#94a3b8; font-size:11px; }
.n-actions { margin-left:auto; display:flex; gap:8px; flex-shrink:0; }
.icon-btn { width:30px; height:30px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:12px; }
.icon-btn.toggle { background:#f1f5f9; color:#475569; }
.icon-btn.del { background:#fee2e2; color:#991b1b; }
.empty { padding:50px 20px; text-align:center; color:#94a3b8; }
</style>
</head>
<body>
<div class="layout">
<?php $activePage='notifications'; require_once __DIR__.'/includes/sidebar.php'; ?>
<main class="main">
    <div class="top-row">
        <h1><i class="fas fa-bell" style="color:#1e3a8a"></i> Notifications</h1>
    </div>

    <div class="grid">
        <div class="card card-pad">
            <h3><i class="fas fa-paper-plane" style="color:#1e3a8a;margin-right:6px;"></i>Send New Notification</h3>
            <div class="form-group">
                <label>Title</label>
                <input type="text" id="nTitle" placeholder="e.g. Maintenance tonight" maxlength="150">
            </div>
            <div class="form-group">
                <label>Message</label>
                <textarea id="nMessage" placeholder="Write the notification..."></textarea>
            </div>
            <div class="form-group">
                <label>Audience</label>
                <select id="nAudience">
                    <option value="all">Everyone</option>
                    <option value="students">Students only</option>
                    <option value="lecturers">Lecturers only</option>
                </select>
            </div>
            <div class="form-group" id="levelWrap" style="display:none;">
                <label>Student Level (optional — leave blank for all levels)</label>
                <select id="nLevel">
                    <option value="">All Levels</option>
                    <option value="100">100 Level</option>
                    <option value="200">200 Level</option>
                    <option value="300">300 Level</option>
                    <option value="400">400 Level</option>
                </select>
            </div>
            <button class="btn-primary" id="sendBtn"><i class="fas fa-paper-plane"></i> Send Notification</button>
        </div>

        <div class="card" id="listWrap">
            <div class="empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
        </div>
    </div>
</main>
</div>

<script>
var audienceSel = document.getElementById('nAudience');
var levelWrap = document.getElementById('levelWrap');
audienceSel.addEventListener('change', function() {
    levelWrap.style.display = this.value === 'students' ? 'block' : 'none';
});

function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
var pillClass = { all: 'pill-all', students: 'pill-students', lecturers: 'pill-lecturers' };
var pillLabel = { all: 'Everyone', students: 'Students', lecturers: 'Lecturers' };

function render(items) {
    var listWrap = document.getElementById('listWrap');
    if (!items.length) {
        listWrap.innerHTML = '<div class="empty"><i class="fas fa-bell-slash" style="font-size:32px;display:block;margin-bottom:10px;"></i>No notifications sent yet</div>';
        return;
    }
    listWrap.innerHTML = items.map(function(n) {
        return '<div class="n-item ' + (n.is_active == 0 ? 'inactive' : '') + '" data-id="' + n.id + '">' +
            '<div style="flex:1;">' +
                '<div class="n-title">' + escapeHtml(n.title) + '</div>' +
                '<div class="n-msg">' + escapeHtml(n.message) + '</div>' +
                '<div class="n-meta">' +
                    '<span class="pill ' + (pillClass[n.audience]||'pill-all') + '">' + (pillLabel[n.audience]||n.audience) + (n.level ? ' · ' + n.level + 'L' : '') + '</span>' +
                    '<span class="n-time">' + new Date(n.created_at.replace(' ','T')).toLocaleString() + '</span>' +
                '</div>' +
            '</div>' +
            '<div class="n-actions">' +
                '<button class="icon-btn toggle" title="' + (n.is_active == 0 ? 'Activate' : 'Deactivate') + '"><i class="fas fa-' + (n.is_active == 0 ? 'eye-slash' : 'eye') + '"></i></button>' +
                '<button class="icon-btn del" title="Delete"><i class="fas fa-trash"></i></button>' +
            '</div>' +
        '</div>';
    }).join('');
}

function load() {
    fetch('../api/notifications.php?action=list&limit=100')
        .then(function(r){ return r.json(); })
        .then(function(d){ if (d.success) render(d.notifications); });
}

document.getElementById('sendBtn').addEventListener('click', function() {
    var title = document.getElementById('nTitle').value.trim();
    var message = document.getElementById('nMessage').value.trim();
    var audience = audienceSel.value;
    var level = document.getElementById('nLevel').value;
    if (!title || !message) { alert('Title and message are required.'); return; }

    var fd = new FormData();
    fd.append('action', 'create');
    fd.append('title', title);
    fd.append('message', message);
    fd.append('audience', audience);
    if (audience === 'students' && level) fd.append('level', level);

    fetch('../api/notifications.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.success) {
                document.getElementById('nTitle').value = '';
                document.getElementById('nMessage').value = '';
                load();
            } else {
                alert(d.message || 'Failed to send notification');
            }
        });
});

document.getElementById('listWrap').addEventListener('click', function(e) {
    var item = e.target.closest('.n-item');
    if (!item) return;
    var id = item.getAttribute('data-id');

    if (e.target.closest('.toggle')) {
        var fd = new FormData(); fd.append('action', 'toggle'); fd.append('id', id);
        fetch('../api/notifications.php', { method:'POST', body:fd }).then(load);
    } else if (e.target.closest('.del')) {
        if (!confirm('Delete this notification permanently?')) return;
        var fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
        fetch('../api/notifications.php', { method:'POST', body:fd }).then(load);
    }
});

load();
</script>
</body>
</html>
