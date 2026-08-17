<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

$academicSession = getAcademicSetting('academic_session', '2025/2026');
$currentSemester = getAcademicSetting('current_semester', '2nd Semester');

// Load all settings fresh
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$portalOpen       = ($settings['portal_open']           ?? '1') === '1';
$studentsBlocked  = ($settings['students_blocked']      ?? '0') === '1';
$lecturersBlocked = ($settings['lecturers_blocked']     ?? '0') === '1';
$testingOpen      = ($settings['testing_open']          ?? '1') === '1';
$portalMsg        = $settings['portal_closed_message']  ?? 'The portal is currently closed. Please check back later.';
$testingMsg       = $settings['testing_closed_message'] ?? 'Tests are not currently available.';
$announcementOn   = ($settings['announcement_active']   ?? '0') === '1';
$announcementText = $settings['announcement_text']      ?? '';

// Partial exam mode: which lecturers (by admins.id) are currently restricted to view-only
$restrictedLecturerIds = json_decode($settings['restricted_lecturers'] ?? '[]', true);
if (!is_array($restrictedLecturerIds)) $restrictedLecturerIds = [];
$allLecturers = $pdo->query("SELECT id, full_name, staff_id FROM admins WHERE role = 'lecturer' ORDER BY full_name")->fetchAll();

$photoSrc = 'https://ui-avatars.com/api/?name='.urlencode($_SESSION['admin_name'] ?? 'Admin').'&background=1e3a8a&color=fff&size=80&bold=true';
$stmtPhoto = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
$stmtPhoto->execute([$_SESSION['admin_id'] ?? 0]);
$photoRow = $stmtPhoto->fetch();
if (!empty($photoRow['photo'])) {
    $sp = dirname(__DIR__) . '/' . ltrim($photoRow['photo'], '/');
    if (file_exists($sp)) $photoSrc = '../' . ltrim($photoRow['photo'], '/');
}

// Count active students and lecturers for impact info
$totalStudents  = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$totalLecturers = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role='lecturer'")->fetchColumn();
$activeTests    = (int)$pdo->query("SELECT COUNT(*) FROM tests WHERE is_active=1")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Portal Control — Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
::-webkit-scrollbar { width:6px; } ::-webkit-scrollbar-track { background:#f1f5f9; } ::-webkit-scrollbar-thumb { background:#cbd5e0; border-radius:10px; }

body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#f1f5f9; color:#0f172a; }
.layout{display:flex;min-height:100vh}

/* Sidebar */
/* → includes/sidebar.php */
/* → includes/sidebar.php */ /* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */ /* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
.nav a i { width:18px; text-align:center; }
/* → includes/sidebar.php */
/* → includes/sidebar.php */

.main { flex:1; margin-left:260px; overflow-y:auto; }
.topbar { background:white; padding:0 28px; border-bottom:1px solid #e2e8f0; height:64px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:50; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.topbar-left h1 { font-size:1.2rem; font-weight:700; color:#0f172a; }
.topbar-left p { font-size:12px; color:#64748b; margin-top:1px; }
.back-btn { padding:8px 16px; background:#f1f5f9; color:#475569; border:1.5px solid #e2e8f0; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600; display:flex; align-items:center; gap:6px; transition:all .2s; }
.back-btn:hover { background:#e2e8f0; }
.content { padding:24px 28px 48px; max-width:1400px; }

/* Section label */
.section-label { font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#94a3b8; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
.section-label::after { content:''; flex:1; height:1px; background:#e2e8f0; }

/* Live status hero */
.status-hero { background:linear-gradient(135deg,#0f172a,#1e3a8a); border-radius:20px; padding:24px 28px; margin-bottom:24px; box-shadow:0 8px 24px rgba(15,23,42,.3); }
.status-hero-top { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
.status-hero-title { font-size:1rem; font-weight:700; color:white; display:flex; align-items:center; gap:8px; }
.status-hero-sub { font-size:12px; color:rgba(255,255,255,.55); margin-top:3px; }
.last-updated { font-size:11px; color:rgba(255,255,255,.4); }
.status-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.status-box { background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.12); border-radius:14px; padding:16px; text-align:center; transition:background .2s; }
.status-box:hover { background:rgba(255,255,255,.12); }
.status-indicator { display:flex; align-items:center; justify-content:center; gap:6px; margin-bottom:8px; }
.s-dot { width:10px; height:10px; border-radius:50%; display:inline-block; flex-shrink:0; }
.s-dot.on   { background:#10b981; box-shadow:0 0 8px #10b981; animation:pulse 2s infinite; }
.s-dot.off  { background:#ef4444; box-shadow:0 0 8px #ef4444; }
.s-dot.warn { background:#f59e0b; box-shadow:0 0 8px #f59e0b; animation:pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.6} }
.s-label { font-size:11px; color:rgba(255,255,255,.55); margin-bottom:4px; }
.s-val { font-size:14px; font-weight:800; color:white; }
.s-impact { font-size:10px; color:rgba(255,255,255,.4); margin-top:4px; }

/* Cards */
.card { background:white; border-radius:16px; padding:22px 24px; box-shadow:0 1px 4px rgba(0,0,0,.07); margin-bottom:20px; }
.card-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid #f1f5f9; }
.card-title { font-size:1rem; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px; }
.card-title i { color:#1e3a8a; }
.card-sub { font-size:12px; color:#64748b; margin-top:3px; }
.card-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; }
.cb-on  { background:#dcfce7; color:#15803d; }
.cb-off { background:#fee2e2; color:#991b1b; }
.cb-warn{ background:#fef9c3; color:#92400e; }

/* Toggle rows */
.toggle-row { display:flex; justify-content:space-between; align-items:center; padding:14px 0; border-bottom:1px solid #f8fafc; }
.toggle-row:last-of-type { border-bottom:none; }
.toggle-info h4 { font-size:14px; font-weight:600; color:#0f172a; margin-bottom:3px; }
.toggle-info p { font-size:12px; color:#64748b; max-width:400px; }
.toggle-wrap { display:flex; align-items:center; gap:10px; }
.toggle-status { font-size:12px; font-weight:700; min-width:44px; text-align:right; }
.toggle { position:relative; width:52px; height:28px; flex-shrink:0; }
.toggle input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; inset:0; background:#e2e8f0; border-radius:14px; cursor:pointer; transition:.3s; }
.toggle-slider::before { content:''; position:absolute; width:22px; height:22px; left:3px; bottom:3px; background:white; border-radius:50%; transition:.3s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.toggle input:checked + .toggle-slider { background:#10b981; }
.toggle input:checked + .toggle-slider.red-on { background:#ef4444; }
.toggle input:checked + .toggle-slider::before { transform:translateX(24px); }

/* Form elements */
.form-group { margin-top:16px; }
.form-group label { display:block; font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px; }
.form-group textarea, .form-group input[type=text] { width:100%; padding:11px 14px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:14px; color:#0f172a; font-family:inherit; transition:border .2s; resize:vertical; }
.form-group textarea:focus, .form-group input:focus { outline:none; border-color:#1e3a8a; box-shadow:0 0 0 3px rgba(30,58,138,.08); }
.form-group .note { font-size:11px; color:#94a3b8; margin-top:4px; }

/* Preview box */
.preview-box { background:#0f172a; border-radius:14px; padding:28px; text-align:center; margin-top:16px; border:1px solid #1e3a8a; }
.preview-box .p-icon { font-size:36px; margin-bottom:10px; }
.preview-box h3 { font-size:1rem; font-weight:700; color:white; margin-bottom:6px; }
.preview-box p { font-size:13px; color:rgba(255,255,255,.6); }
.preview-box small { font-size:11px; color:rgba(255,255,255,.3); margin-top:8px; display:block; }

/* Announcement preview */
.ann-preview { background:#eff6ff; border:1px solid #bfdbfe; border-left:4px solid #3b82f6; border-radius:10px; padding:12px 16px; margin-top:12px; font-size:13px; color:#1d4ed8; }

/* Buttons */
.btn { padding:9px 18px; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; border:none; display:inline-flex; align-items:center; gap:7px; transition:all .2s; text-decoration:none; }
.btn-primary { background:linear-gradient(135deg,#0f172a,#1e3a8a); color:white; }
.btn-primary:hover { opacity:.88; transform:translateY(-1px); }
.btn-success { background:#10b981; color:white; }
.btn-success:hover { background:#059669; transform:translateY(-1px); }
.btn-danger  { background:#ef4444; color:white; }
.btn-danger:hover  { background:#dc2626; transform:translateY(-1px); }
.btn-outline { background:white; color:#1e3a8a; border:1.5px solid #1e3a8a; }
.btn-outline:hover { background:#1e3a8a; color:white; }
.btn-row { display:flex; gap:10px; margin-top:16px; flex-wrap:wrap; padding-top:14px; border-top:1px solid #f1f5f9; }

/* Quick actions */
.quick-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.quick-card { border-radius:14px; padding:18px 16px; text-align:center; cursor:pointer; border:2px solid transparent; transition:all .2s; }
.quick-card:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.1); }
.quick-card i { font-size:28px; display:block; margin-bottom:10px; }
.quick-card h4 { font-size:14px; font-weight:700; margin-bottom:4px; }
.quick-card p { font-size:11px; opacity:.75; }
.qc-open   { background:#f0fdf4; border-color:#86efac; color:#15803d; }
.qc-open:hover  { background:#dcfce7; }
.qc-close  { background:#fff1f2; border-color:#fca5a5; color:#991b1b; }
.qc-close:hover { background:#fee2e2; }
.qc-exam   { background:#eff6ff; border-color:#93c5fd; color:#1d4ed8; }
.qc-exam:hover  { background:#dbeafe; }

/* Toast */
.toast { position:fixed; bottom:28px; right:28px; background:#0f172a; color:white; padding:14px 20px; border-radius:12px; font-size:13px; z-index:9999; display:flex; align-items:center; gap:10px; box-shadow:0 8px 24px rgba(0,0,0,.3); transform:translateY(100px); opacity:0; transition:all .35s cubic-bezier(.4,0,.2,1); min-width:220px; }
.toast.show { transform:translateY(0); opacity:1; }
.t-dot { width:9px; height:9px; border-radius:50%; background:#10b981; flex-shrink:0; }
.t-dot.red { background:#ef4444; }
.t-dot.amber { background:#f59e0b; }

/* Confirm modal */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:200; display:none; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal { background:white; border-radius:20px; padding:28px; max-width:420px; width:90%; box-shadow:0 25px 50px rgba(0,0,0,.4); }
.modal h3 { font-size:1.1rem; font-weight:700; color:#0f172a; margin-bottom:8px; }
.modal p { font-size:14px; color:#64748b; margin-bottom:24px; line-height:1.6; }
.modal-btns { display:flex; gap:10px; justify-content:flex-end; }


/* ── Responsive ── */
@media(max-width:900px) {
    .status-grid { grid-template-columns: repeat(2,1fr); }
    .quick-grid  { grid-template-columns: repeat(2,1fr); }
}
@media(max-width:768px) {
    /* → includes/sidebar.php */
    .main    { margin-left:0; }
    .content { padding:16px; }
    .topbar  { padding:0 16px; height:auto; min-height:64px; flex-wrap:wrap; gap:8px; padding-top:10px; padding-bottom:10px; }
    .topbar-left h1 { font-size:1.1rem; }
    .back-btn { padding:7px 12px; font-size:12px; }
    .status-grid { grid-template-columns: repeat(2,1fr); gap:10px; }
    .status-hero { padding:18px; }
    .status-hero-top { flex-direction:column; gap:6px; align-items:flex-start; }
    .quick-grid  { grid-template-columns:1fr; }
    .quick-card  { padding:14px; }
    .toggle-row  { flex-wrap:wrap; gap:10px; }
    .toggle-info p { max-width:100%; }
    .toggle-wrap { margin-left:auto; }
    .card { padding:16px; }
    .card-header { flex-wrap:wrap; gap:8px; }
    .btn-row { flex-wrap:wrap; }
    .modal { padding:20px; }
    .modal-btns { flex-wrap:wrap; }
}
@media(max-width:480px) {
    .status-grid { grid-template-columns:1fr 1fr; gap:8px; }
    .status-box  { padding:12px 8px; }
    .s-val { font-size:12px; }
    .s-label { font-size:10px; }
    .s-impact { font-size:9px; }
    .quick-grid { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="layout">

<?php $activePage='portal-control'; require_once __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
<div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
    <div class="topbar-left">
        <h1><i class="fas fa-toggle-on" style="color:#1e3a8a;margin-right:8px"></i>Portal Control</h1>
        <p><?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?></p>
    </div>
    <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
</div>

<div class="content">

<!-- Live status hero -->
<div class="status-hero">
    <div class="status-hero-top">
        <div>
            <div class="status-hero-title"><i class="fas fa-satellite-dish"></i> Live System Status</div>
            <div class="status-hero-sub">Changes take effect immediately for all users</div>
        </div>
        <div class="last-updated" id="lastUpdated">Updated: <?= date('g:i A') ?></div>
    </div>
    <div class="status-grid">
        <div class="status-box">
            <div class="status-indicator">
                <span class="s-dot <?= $portalOpen?'on':'off' ?>" id="dot-portal"></span>
                <span class="s-val" id="val-portal"><?= $portalOpen?'OPEN':'CLOSED' ?></span>
            </div>
            <div class="s-label">Student Portal</div>
            <div class="s-impact"><?= $totalStudents ?> students affected</div>
        </div>
        <div class="status-box">
            <div class="status-indicator">
                <span class="s-dot <?= !$studentsBlocked?'on':'off' ?>" id="dot-students"></span>
                <span class="s-val" id="val-students"><?= $studentsBlocked?'BLOCKED':'ALLOWED' ?></span>
            </div>
            <div class="s-label">Student Access</div>
            <div class="s-impact"><?= $totalStudents ?> students</div>
        </div>
        <div class="status-box">
            <div class="status-indicator">
                <span class="s-dot <?= !$lecturersBlocked?'on':'off' ?>" id="dot-lecturers"></span>
                <span class="s-val" id="val-lecturers"><?= $lecturersBlocked?'BLOCKED':'ALLOWED' ?></span>
            </div>
            <div class="s-label">Lecturer Access</div>
            <div class="s-impact"><?= $totalLecturers ?> lecturers</div>
        </div>
        <div class="status-box">
            <div class="status-indicator">
                <span class="s-dot <?= $testingOpen?'on':'off' ?>" id="dot-testing"></span>
                <span class="s-val" id="val-testing"><?= $testingOpen?'OPEN':'CLOSED' ?></span>
            </div>
            <div class="s-label">Test Taking</div>
            <div class="s-impact"><?= $activeTests ?> active tests</div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="section-label"><i class="fas fa-bolt" style="color:#1e3a8a"></i> Quick Actions</div>
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title"><i class="fas fa-bolt"></i> One-Click Presets</div>
            <div class="card-sub">Apply common configurations instantly — affects all users immediately</div>
        </div>
    </div>
    <div class="quick-grid">
        <div class="quick-card qc-open" onclick="confirmAction('open_all','Open Everything','This will open the student portal, allow all access, and enable test taking for <?= $totalStudents ?> students and <?= $totalLecturers ?> lecturers.')">
            <i class="fas fa-door-open"></i>
            <h4>Open Everything</h4>
            <p>Portal open · All access · Tests open</p>
        </div>
        <div class="quick-card qc-close" onclick="confirmAction('close_all','Close Everything','This will close the student portal, block all access, and disable all test taking. No students or lecturers will be able to log in.')">
            <i class="fas fa-door-closed"></i>
            <h4>Close Everything</h4>
            <p>Portal closed · All blocked · Tests closed</p>
        </div>
        <div class="quick-card qc-exam" onclick="confirmAction('exam_mode','Activate Exam Mode','Portal stays open for students and tests will be available. Lecturers will be blocked from making any changes during the exam period.')">
            <i class="fas fa-graduation-cap"></i>
            <h4>Exam Mode</h4>
            <p>Students open · Tests open · Lecturers blocked</p>
        </div>
    </div>
</div>

<!-- Portal Access -->
<div class="section-label"><i class="fas fa-door-open" style="color:#1e3a8a"></i> Access Control</div>
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title"><i class="fas fa-users-slash"></i> Portal Access</div>
            <div class="card-sub">Control who can log in and use the portal</div>
        </div>
        <span class="card-badge <?= $portalOpen?'cb-on':'cb-off' ?>" id="badge-portal">
            Portal <?= $portalOpen?'Open':'Closed' ?>
        </span>
    </div>

    <div class="toggle-row">
        <div class="toggle-info">
            <h4><i class="fas fa-door-open" style="color:#1e3a8a;margin-right:6px"></i>Student Portal</h4>
            <p>When OFF — students see a "portal closed" message and cannot log in at all</p>
        </div>
        <div class="toggle-wrap">
            <span class="toggle-status" id="ts-portal" style="color:<?= $portalOpen?'#15803d':'#991b1b' ?>"><?= $portalOpen?'ON':'OFF' ?></span>
            <label class="toggle">
                <input type="checkbox" id="toggle-portal-open" <?= $portalOpen?'checked':'' ?>
                    onchange="save('portal_open',this.checked?'1':'0','Student portal ' + (this.checked?'opened':'closed'),'portal',this)">
                <span class="toggle-slider"></span>
            </label>
        </div>
    </div>

    <div class="toggle-row">
        <div class="toggle-info">
            <h4><i class="fas fa-user-slash" style="color:#ef4444;margin-right:6px"></i>Block Students</h4>
            <p>Portal stays open but students are shown a restricted access message on login</p>
        </div>
        <div class="toggle-wrap">
            <span class="toggle-status" id="ts-students" style="color:<?= $studentsBlocked?'#991b1b':'#15803d' ?>"><?= $studentsBlocked?'ON':'OFF' ?></span>
            <label class="toggle">
                <input type="checkbox" id="toggle-students-blocked" <?= $studentsBlocked?'checked':'' ?>
                    onchange="save('students_blocked',this.checked?'1':'0','Students ' + (this.checked?'blocked':'unblocked'),'students',this)">
                <span class="toggle-slider red-on"></span>
            </label>
        </div>
    </div>

    <div class="toggle-row">
        <div class="toggle-info">
            <h4><i class="fas fa-chalkboard-teacher" style="color:#ef4444;margin-right:6px"></i>Block Lecturers</h4>
            <p>Lecturers cannot log in — useful during exam periods to prevent test edits</p>
        </div>
        <div class="toggle-wrap">
            <span class="toggle-status" id="ts-lecturers" style="color:<?= $lecturersBlocked?'#991b1b':'#15803d' ?>"><?= $lecturersBlocked?'ON':'OFF' ?></span>
            <label class="toggle">
                <input type="checkbox" id="toggle-lecturers-blocked" <?= $lecturersBlocked?'checked':'' ?>
                    onchange="save('lecturers_blocked',this.checked?'1':'0','Lecturers ' + (this.checked?'blocked':'unblocked'),'lecturers',this)">
                <span class="toggle-slider red-on"></span>
            </label>
        </div>
    </div>

    <div class="form-group">
        <label>Portal Closed Message</label>
        <textarea id="portalMsg" rows="2" placeholder="Message shown to students when portal is closed..."><?= htmlspecialchars($portalMsg) ?></textarea>
        <div class="note">Shown when the portal toggle above is turned OFF</div>
    </div>

    <!-- Preview of closed portal -->
    <div class="preview-box" id="portalPreview" style="<?= $portalOpen?'display:none':'' ?>">
        <div class="p-icon">🔒</div>
        <h3>Portal Closed</h3>
        <p id="previewMsgText"><?= htmlspecialchars($portalMsg) ?></p>
        <small>This is exactly what students see when they try to log in</small>
    </div>

    <div class="btn-row">
        <button class="btn btn-primary" onclick="saveMsg('portal_closed_message','portalMsg','Portal message saved')">
            <i class="fas fa-save"></i> Save Message
        </button>
    </div>
</div>

<!-- Partial Exam Mode: restrict specific lecturers to view-only -->
<div class="section-label"><i class="fas fa-user-lock" style="color:#4f46e5"></i> Partial Exam Mode</div>
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title"><i class="fas fa-user-lock"></i> Restrict Specific Lecturers</div>
            <div class="card-sub">Pick individual lecturers to switch to view-only during an exam — everyone else keeps full access. Restricted lecturers can still log in and browse, but every write action (tests, questions, links, profile, password) is blocked.</div>
        </div>
        <span class="card-badge <?= !empty($restrictedLecturerIds) ? 'cb-off' : 'cb-on' ?>" id="badge-restricted">
            <?= count($restrictedLecturerIds) ?> Restricted
        </span>
    </div>

    <?php if (empty($allLecturers)): ?>
    <p class="note">No lecturer accounts found yet.</p>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:2px;max-height:320px;overflow-y:auto;border:1px solid #f1f5f9;border-radius:10px;padding:6px;">
        <?php foreach ($allLecturers as $lec): ?>
        <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;cursor:pointer;font-size:13.5px;color:#0f172a;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
            <input type="checkbox" class="restrict-lecturer-cb" value="<?= (int)$lec['id'] ?>"
                <?= in_array((int)$lec['id'], $restrictedLecturerIds, true) ? 'checked' : '' ?>
                style="width:17px;height:17px;accent-color:#4f46e5;">
            <span style="font-weight:600;"><?= htmlspecialchars($lec['full_name']) ?></span>
            <span style="color:#94a3b8;font-size:12px;">(<?= htmlspecialchars($lec['staff_id']) ?>)</span>
        </label>
        <?php endforeach; ?>
    </div>
    <div class="btn-row" style="margin-top:14px;">
        <button class="btn btn-primary" onclick="saveRestrictedLecturers()">
            <i class="fas fa-save"></i> Save Restricted List
        </button>
        <button class="btn" style="background:#f1f5f9;color:#475569;" onclick="document.querySelectorAll('.restrict-lecturer-cb').forEach(cb=>cb.checked=false); saveRestrictedLecturers();">
            <i class="fas fa-times"></i> Clear All (Full Access)
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Test Control -->
<div class="section-label"><i class="fas fa-file-alt" style="color:#1e3a8a"></i> Test Access</div>
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title"><i class="fas fa-file-alt"></i> Test Taking Window</div>
            <div class="card-sub">Controls whether students can start tests — independent of portal access</div>
        </div>
        <span class="card-badge <?= $testingOpen?'cb-on':'cb-off' ?>" id="badge-testing">
            Tests <?= $testingOpen?'Open':'Closed' ?>
        </span>
    </div>

    <div class="toggle-row">
        <div class="toggle-info">
            <h4><i class="fas fa-play-circle" style="color:#10b981;margin-right:6px"></i>Allow Test Taking</h4>
            <p>When OFF — logged-in students cannot start any test, even if tests are marked active</p>
        </div>
        <div class="toggle-wrap">
            <span class="toggle-status" id="ts-testing" style="color:<?= $testingOpen?'#15803d':'#991b1b' ?>"><?= $testingOpen?'ON':'OFF' ?></span>
            <label class="toggle">
                <input type="checkbox" id="toggle-testing-open" <?= $testingOpen?'checked':'' ?>
                    onchange="save('testing_open',this.checked?'1':'0','Tests ' + (this.checked?'opened':'closed'),'testing',this)">
                <span class="toggle-slider"></span>
            </label>
        </div>
    </div>

    <div class="form-group">
        <label>Tests Unavailable Message</label>
        <textarea id="testMsg" rows="2" placeholder="Message shown when student tries to start a test..."><?= htmlspecialchars($testingMsg) ?></textarea>
        <div class="note">Shown when a student clicks "Start Test" while testing is closed</div>
    </div>
    <div class="btn-row">
        <button class="btn btn-primary" onclick="saveMsg('testing_closed_message','testMsg','Test message saved')">
            <i class="fas fa-save"></i> Save Message
        </button>
    </div>
</div>

<!-- Announcement -->
<div class="section-label"><i class="fas fa-bullhorn" style="color:#1e3a8a"></i> Announcement</div>
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title"><i class="fas fa-bullhorn"></i> System Announcement Banner</div>
            <div class="card-sub">Displays a banner at the top of student and lecturer dashboards</div>
        </div>
        <span class="card-badge <?= $announcementOn?'cb-warn':'cb-off' ?>" id="badge-ann">
            <?= $announcementOn?'Active':'Inactive' ?>
        </span>
    </div>

    <div class="toggle-row">
        <div class="toggle-info">
            <h4><i class="fas fa-bell" style="color:#f59e0b;margin-right:6px"></i>Show Announcement</h4>
            <p>Displays your message as a prominent banner on all dashboards</p>
        </div>
        <div class="toggle-wrap">
            <span class="toggle-status" id="ts-ann" style="color:<?= $announcementOn?'#92400e':'#64748b' ?>"><?= $announcementOn?'ON':'OFF' ?></span>
            <label class="toggle">
                <input type="checkbox" id="toggle-ann" <?= $announcementOn?'checked':'' ?>
                    onchange="save('announcement_active',this.checked?'1':'0','Announcement ' + (this.checked?'activated':'deactivated'),'ann',this)">
                <span class="toggle-slider"></span>
            </label>
        </div>
    </div>

    <div class="form-group">
        <label>Announcement Text</label>
        <textarea id="annText" rows="3" placeholder="e.g. CSC 404 CA Test opens Monday 9:00 AM. Ensure your Face ID is enrolled before the test date."><?= htmlspecialchars($announcementText) ?></textarea>
    </div>

    <!-- Live preview -->
    <div class="ann-preview" id="annPreview" style="<?= (!$announcementOn||!$announcementText)?'display:none':'' ?>">
        <i class="fas fa-bullhorn" style="margin-right:8px"></i>
        <strong>Announcement:</strong> &nbsp;<span id="annPreviewText"><?= htmlspecialchars($announcementText) ?></span>
    </div>

    <div class="btn-row">
        <button class="btn btn-primary" onclick="saveAnnouncement()">
            <i class="fas fa-save"></i> Save &amp; Preview
        </button>
        <button class="btn btn-danger" onclick="save('announcement_active','0','Announcement hidden','ann');document.getElementById('toggle-ann').checked=false">
            <i class="fas fa-eye-slash"></i> Hide Now
        </button>
    </div>
</div>

</div><!-- /content -->
</main>
</div>

<!-- Confirm Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <h3 id="modalTitle">Confirm Action</h3>
        <p id="modalMsg">Are you sure?</p>
        <div class="modal-btns">
            <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="modalConfirmBtn" onclick="executeAction()">Confirm</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
    <span class="t-dot" id="tDot"></span>
    <span id="tMsg">Saved</span>
</div>

<script>
let pendingAction = null;

// ── Core save function ────────────────────────────────────────
async function save(key, value, msg, dotKey, toggleEl) {
    // Show saving state
    if (toggleEl) toggleEl.disabled = true;
    showToast('Saving...', 'amber');

    const fd = new FormData();
    fd.append('action', 'set_setting');
    fd.append('key', key);
    fd.append('value', value);

    try {
        const r = await fetch('../api/portal-control.php', { method:'POST', body:fd });

        // Check for non-JSON response (PHP error page)
        const text = await r.text();
        let d;
        try {
            d = JSON.parse(text);
        } catch(parseErr) {
            showToast('Server error — check PHP logs', 'error');
            if (toggleEl) {
                toggleEl.checked = value !== '1'; // revert
                toggleEl.disabled = false;
            }
            console.error('Non-JSON response:', text);
            return;
        }

        if (d.success) {
            showToast(msg, 'success');
            updateStatusDot(dotKey, key, value);
        } else {
            // Revert the toggle to previous state
            if (toggleEl) toggleEl.checked = value !== '1';
            showToast('Failed: ' + (d.message || 'Unknown error'), 'error');
            console.error('Save failed:', d);
        }
    } catch(e) {
        if (toggleEl) toggleEl.checked = value !== '1';
        showToast('Network error — check connection', 'error');
        console.error('Fetch error:', e);
    } finally {
        if (toggleEl) toggleEl.disabled = false;
    }
}

async function saveMsg(key, textareaId, msg) {
    const value = document.getElementById(textareaId).value.trim();
    if (!value) { showToast('Message cannot be empty', 'error'); return; }
    await save(key, value, msg, null);
    if (key === 'portal_closed_message') {
        document.getElementById('previewMsgText').textContent = value;
    }
}

async function saveRestrictedLecturers() {
    const ids = Array.from(document.querySelectorAll('.restrict-lecturer-cb:checked')).map(cb => parseInt(cb.value));
    await save('restricted_lecturers', JSON.stringify(ids),
        ids.length ? (ids.length + ' lecturer(s) set to view-only') : 'All lecturers now have full access',
        null);
    const badge = document.getElementById('badge-restricted');
    if (badge) {
        badge.textContent = ids.length + ' Restricted';
        badge.className = 'card-badge ' + (ids.length ? 'cb-off' : 'cb-on');
    }
}

async function saveAnnouncement() {
    const text = document.getElementById('annText').value.trim();
    await save('announcement_text', text, 'Announcement text saved', null);
    const preview = document.getElementById('annPreview');
    document.getElementById('annPreviewText').textContent = text;
    if (text && document.getElementById('toggle-ann').checked) {
        preview.style.display = 'block';
    }
}

// ── Status dot updater ────────────────────────────────────────
function updateStatusDot(dotKey, settingKey, value) {
    const map = {
        'portal':    { dot:'dot-portal',    val:'val-portal',    ts:'ts-portal',    badge:'badge-portal' },
        'students':  { dot:'dot-students',  val:'val-students',  ts:'ts-students' },
        'lecturers': { dot:'dot-lecturers', val:'val-lecturers', ts:'ts-lecturers' },
        'testing':   { dot:'dot-testing',   val:'val-testing',   ts:'ts-testing',   badge:'badge-testing' },
        'ann':       { badge:'badge-ann',   ts:'ts-ann' },
    };
    if (!dotKey || !map[dotKey]) return;
    const el = map[dotKey];
    const isOn = value === '1';
    const isBlockToggle = settingKey === 'students_blocked' || settingKey === 'lecturers_blocked';

    // For block toggles: ON = bad (red), OFF = good (green)
    const isGood = isBlockToggle ? !isOn : isOn;

    if (el.dot) {
        const dot = document.getElementById(el.dot);
        dot.className = 's-dot ' + (isGood ? 'on' : 'off');
    }
    if (el.val) {
        const valEl = document.getElementById(el.val);
        if (settingKey === 'portal_open')        valEl.textContent = isOn ? 'OPEN' : 'CLOSED';
        else if (settingKey === 'testing_open')  valEl.textContent = isOn ? 'OPEN' : 'CLOSED';
        else if (isBlockToggle)                   valEl.textContent = isOn ? 'BLOCKED' : 'ALLOWED';
    }
    if (el.ts) {
        const tsEl = document.getElementById(el.ts);
        tsEl.textContent = isOn ? 'ON' : 'OFF';
        tsEl.style.color = isGood ? '#15803d' : '#991b1b';
    }
    if (el.badge) {
        const badgeEl = document.getElementById(el.badge);
        if (settingKey === 'portal_open') {
            badgeEl.textContent = isOn ? 'Portal Open' : 'Portal Closed';
            badgeEl.className = 'card-badge ' + (isOn ? 'cb-on' : 'cb-off');
        } else if (settingKey === 'testing_open') {
            badgeEl.textContent = isOn ? 'Tests Open' : 'Tests Closed';
            badgeEl.className = 'card-badge ' + (isOn ? 'cb-on' : 'cb-off');
        } else if (settingKey === 'announcement_active') {
            badgeEl.textContent = isOn ? 'Active' : 'Inactive';
            badgeEl.className = 'card-badge ' + (isOn ? 'cb-warn' : 'cb-off');
        }
    }
    // Portal preview box
    if (settingKey === 'portal_open') {
        document.getElementById('portalPreview').style.display = isOn ? 'none' : 'block';
    }
    // Update last updated time
    document.getElementById('lastUpdated').textContent = 'Updated: ' + new Date().toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
}

// ── Quick action presets ──────────────────────────────────────
function confirmAction(action, title, msg) {
    pendingAction = action;
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalMsg').textContent = msg;
    document.getElementById('confirmModal').classList.add('show');
}
function closeModal() {
    document.getElementById('confirmModal').classList.remove('show');
    pendingAction = null;
}
async function executeAction() {
    const action = pendingAction;
    closeModal();
    if (!action) return;

    const presets = {
        open_all:  [['portal_open','1'],['students_blocked','0'],['lecturers_blocked','0'],['testing_open','1']],
        close_all: [['portal_open','0'],['students_blocked','1'],['lecturers_blocked','1'],['testing_open','0']],
        exam_mode: [['portal_open','1'],['students_blocked','0'],['lecturers_blocked','1'],['testing_open','1']],
    };
    const msgs = {
        open_all:  '✅ Everything is now OPEN',
        close_all: '🔒 Everything is now CLOSED',
        exam_mode: '🎓 Exam Mode activated',
    };

    const pairs = presets[action];
    if (!pairs) return;
    showToast('Applying settings...', 'amber');

    for (const [k, v] of pairs) {
        const fd = new FormData();
        fd.append('action', 'set_setting');
        fd.append('key', k);
        fd.append('value', v);
        const response = await fetch('../api/portal-control.php', { method:'POST', body:fd });
        const data = await response.json();
        if (!data.success) {
            showToast('Failed: ' + (data.message || 'Could not save settings'), 'error');
            return;
        }
    }

    showToast(msgs[action], 'success');

    // Update all UI without page reload
    const dotMap = {
        portal_open: 'portal', students_blocked: 'students',
        lecturers_blocked: 'lecturers', testing_open: 'testing'
    };
    for (const [k, v] of pairs) {
        const chk = document.getElementById({
            portal_open:'toggle-portal-open',
            students_blocked:'toggle-students-blocked',
            lecturers_blocked:'toggle-lecturers-blocked',
            testing_open:'toggle-testing-open'
        }[k]);
        if (chk) chk.checked = v === '1';
        updateStatusDot(dotMap[k], k, v);
    }
}

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg, type='success') {
    const t   = document.getElementById('toast');
    const dot = document.getElementById('tDot');
    document.getElementById('tMsg').textContent = msg;
    dot.className = 't-dot' + (type==='error'?' red':type==='amber'?' amber':'');
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

// Close modal on overlay click
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>