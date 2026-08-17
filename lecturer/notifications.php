<?php
session_start();
if (!isset($_SESSION['lecturer_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

$lecturerId = (int)$_SESSION['lecturer_id'];
$photoSrc = 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['lecturer_name'] ?? 'Lecturer') . '&background=1e3a8a&color=fff&size=80&bold=true';
$stmtPhoto = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
$stmtPhoto->execute([$lecturerId]);
$photoRow = $stmtPhoto->fetch();
if (!empty($photoRow['photo'])) {
    $sp = dirname(__DIR__) . '/' . ltrim($photoRow['photo'], '/');
    if (file_exists($sp)) $photoSrc = '../' . ltrim($photoRow['photo'], '/');
}
$lecturerAvatarUrl = $photoSrc;
$lecturerName = $_SESSION['lecturer_name'] ?? 'Lecturer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — Lecturer Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:-apple-system,'Segoe UI',sans-serif; }
body { background:#f4f6fb; }
.layout { display:flex; min-height:100vh; }
.main { flex:1; margin-left:260px; padding:32px; }
@media(max-width:900px){ .main{margin-left:0;} }
.top-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; flex-wrap:wrap; gap:10px; }
.top-row h1 { font-size:1.4rem; color:#0f172a; display:flex; align-items:center; gap:10px; }
.card { background:#fff; border-radius:16px; box-shadow:0 4px 18px rgba(15,23,42,.06); overflow:hidden; }
.n-item { padding:18px 22px; border-bottom:1px solid #f1f5f9; display:flex; gap:14px; }
.n-item:last-child { border-bottom:none; }
.n-item.unread { background:#eff6ff; }
.n-dot { width:9px; height:9px; border-radius:50%; background:#3b82f6; margin-top:7px; flex-shrink:0; }
.n-item.read .n-dot { background:transparent; }
.n-title { font-weight:800; color:#0f172a; font-size:14.5px; margin-bottom:4px; }
.n-msg { color:#475569; font-size:13.5px; line-height:1.6; }
.n-time { color:#94a3b8; font-size:11.5px; margin-top:8px; }
.empty { padding:60px 20px; text-align:center; color:#94a3b8; }
.empty i { font-size:36px; margin-bottom:12px; display:block; }
.mark-all { background:#1e3a8a; color:#fff; border:none; padding:9px 16px; border-radius:9px; font-weight:700; font-size:12.5px; cursor:pointer; }
</style>
</head>
<body>
<div class="layout">
<?php $activePage='notifications'; require_once __DIR__.'/includes/sidebar.php'; ?>
<main class="main">
    <div class="top-row">
        <h1><i class="fas fa-bell" style="color:#1e3a8a"></i> Notifications</h1>
        <button class="mark-all" id="markAllBtn"><i class="fas fa-check-double"></i> Mark all read</button>
    </div>
    <div class="card" id="listWrap">
        <div class="empty"><i class="fas fa-spinner fa-spin"></i>Loading…</div>
    </div>
</main>
</div>

<script>
var listWrap = document.getElementById('listWrap');
function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function render(items) {
    if (!items.length) {
        listWrap.innerHTML = '<div class="empty"><i class="fas fa-bell-slash"></i>No notifications yet</div>';
        return;
    }
    listWrap.innerHTML = items.map(function(n) {
        var isRead = n.is_read == 1;
        return '<div class="n-item ' + (isRead ? 'read' : 'unread') + '" data-id="' + n.id + '">' +
            '<div class="n-dot"></div><div>' +
            '<div class="n-title">' + escapeHtml(n.title) + '</div>' +
            '<div class="n-msg">' + escapeHtml(n.message) + '</div>' +
            '<div class="n-time">' + new Date(n.created_at.replace(' ','T')).toLocaleString() + '</div>' +
            '</div></div>';
    }).join('');
}

function load() {
    fetch('../api/notifications.php?action=list&limit=50')
        .then(function(r){ return r.json(); })
        .then(function(d){ if (d.success) render(d.notifications); });
}

listWrap.addEventListener('click', function(e) {
    var item = e.target.closest('.n-item');
    if (!item || !item.classList.contains('unread')) return;
    item.classList.remove('unread'); item.classList.add('read');
    var fd = new FormData(); fd.append('action','mark_read'); fd.append('id', item.getAttribute('data-id'));
    fetch('../api/notifications.php', { method:'POST', body:fd });
});

document.getElementById('markAllBtn').addEventListener('click', function() {
    var fd = new FormData(); fd.append('action','mark_all_read');
    fetch('../api/notifications.php', { method:'POST', body:fd }).then(load);
});

load();
</script>
</body>
</html>
