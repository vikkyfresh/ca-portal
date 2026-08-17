<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

$adminId   = (int)$_SESSION['admin_id'];
$adminName = $_SESSION['admin_name'] ?? 'Administrator';

$stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$adminId]);
$admin = $stmt->fetch();

$academicSession = getAcademicSetting('academic_session', '2025/2026');
$currentSemester = getAcademicSetting('current_semester', '2nd Semester');

// Portal settings (for notification bell only)
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('portal_open','students_blocked','lecturers_blocked','testing_open','announcement_active','announcement_text')");
$pc = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$portalOpen      = ($pc['portal_open']         ?? '1') === '1';
$studBlocked     = ($pc['students_blocked']    ?? '0') === '1';
$lecBlocked      = ($pc['lecturers_blocked']   ?? '0') === '1';
$testingOpen     = ($pc['testing_open']        ?? '1') === '1';
$annActive       = ($pc['announcement_active'] ?? '0') === '1';
$annText         = $pc['announcement_text']    ?? '';

// Build notification count (announcement-active already gets its own banner below, so it's not duplicated here)
$notifications = [];
if (!$portalOpen)   $notifications[] = ['type'=>'red',  'icon'=>'lock',        'msg'=>'Student portal is CLOSED'];
if ($studBlocked)   $notifications[] = ['type'=>'red',  'icon'=>'user-slash',  'msg'=>'Students are BLOCKED'];
if ($lecBlocked)    $notifications[] = ['type'=>'amber','icon'=>'ban',         'msg'=>'Lecturers are BLOCKED'];
if (!$testingOpen)  $notifications[] = ['type'=>'amber','icon'=>'stop-circle', 'msg'=>'Test taking is CLOSED'];
$notifCount = count($notifications);

// KPIs
$totalStudents    = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$totalLecturers   = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role='lecturer'")->fetchColumn();
$totalTests       = (int)$pdo->query("SELECT COUNT(*) FROM tests WHERE is_active=1")->fetchColumn();
$totalSubmissions = (int)$pdo->query("SELECT COUNT(*) FROM attempts WHERE status='completed'")->fetchColumn();
$facesEnrolled    = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE face_descriptor IS NOT NULL")->fetchColumn();
$avgScore         = round((float)($pdo->query("SELECT AVG(percentage) FROM attempts WHERE status='completed'")->fetchColumn() ?? 0), 1);
$totalPass        = (int)$pdo->query("SELECT COUNT(*) FROM attempts a JOIN tests t ON a.test_id=t.id WHERE a.status='completed' AND a.percentage>=t.passing_score")->fetchColumn();
$passRate         = $totalSubmissions > 0 ? round($totalPass/$totalSubmissions*100,1) : 0;

// Recent submissions
$recentAttempts = $pdo->query("
    SELECT a.student_matric, s.full_name, s.level, t.course_code,
           a.percentage, a.score, a.total, a.end_time, t.passing_score
    FROM attempts a
    JOIN students s ON a.student_matric=s.matric
    JOIN tests t ON a.test_id=t.id
    WHERE a.status='completed'
    ORDER BY a.end_time DESC LIMIT 8
")->fetchAll();

// Recent students
$recentStudents = $pdo->query("SELECT * FROM students ORDER BY created_at DESC LIMIT 6")->fetchAll();

// Pass/Fail distribution for doughnut (compares each attempt against its own test's pass mark)
$pfRows = $pdo->query("SELECT a.percentage, t.passing_score FROM attempts a JOIN tests t ON a.test_id = t.id WHERE a.status = 'completed'")->fetchAll();
$pf = ['Pass' => 0, 'Fail' => 0];
foreach ($pfRows as $row) {
    if ((float)$row['percentage'] >= (float)$row['passing_score']) $pf['Pass']++;
    else $pf['Fail']++;
}

// Photo — build a URL that works from inside /admin/
$photoSrc = 'https://ui-avatars.com/api/?name='.urlencode($adminName).'&background=1e3a8a&color=fff&size=120&bold=true';
if (!empty($admin['photo'])) {
    // photo stored as e.g. "uploads/passports/admin_1.jpg" (relative to portal root)
    $relPhoto  = ltrim($admin['photo'], '/');
    $serverPath = dirname(__DIR__) . '/' . $relPhoto;   // absolute disk path
    if (file_exists($serverPath)) {
        $photoSrc = '../' . $relPhoto;                  // correct URL from admin/
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Admin Portal | PAAU CA System</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:#f1f5f9}
::-webkit-scrollbar-thumb{background:#cbd5e0;border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:#94a3b8}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;color:#0f172a;overflow-x:hidden}
.layout{display:flex;min-height:100vh}

/* Sidebar */
/* sidebar CSS in includes/sidebar.php */










/* .nav defined in includes/sidebar.php */


.nav a i{width:17px;text-align:center;font-size:.85rem;opacity:.85}




/* Main */
.main{flex:1;margin-left:260px;min-width:0;display:flex;flex-direction:column}

/* Topbar */
.topbar{background:white;padding:0 24px;border-bottom:1px solid #e2e8f0;height:62px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 1px 3px rgba(0,0,0,.05);flex-shrink:0}
.topbar-left h1{font-size:1.15rem;font-weight:700;color:#0f172a}
.topbar-left p{font-size:11.5px;color:#64748b;margin-top:1px}
.topbar-right{display:flex;align-items:center;gap:10px}

/* Notification bell */
.status-banner{border-radius:10px;padding:12px 18px;margin-bottom:14px;display:flex;align-items:center;gap:12px;font-size:13px;font-weight:600}
.status-banner.red{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b}
.status-banner.amber{background:#fef3c7;border:1px solid #fcd34d;color:#92400e}
.status-banner.blue{background:#dbeafe;border:1px solid #93c5fd;color:#1e3a8a}

/* Admin pill */
.admin-pill-wrap{position:relative}
.admin-pill{display:flex;align-items:center;gap:9px;padding:5px 13px 5px 5px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:40px;cursor:pointer;transition:all .2s;font:inherit}
.admin-pill:hover{background:#eff6ff;border-color:#bfdbfe}
.admin-pill img{width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0}
.admin-pill-name{font-size:12.5px;font-weight:600;color:#0f172a;text-align:left}
.admin-pill-role{font-size:10.5px;color:#64748b;text-align:left}
.admin-pill-menu{position:absolute;top:52px;right:0;width:210px;background:white;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.12);z-index:200;display:none;overflow:hidden;padding:6px}
.admin-pill-menu.open{display:block}
.admin-pill-menu a{display:flex;align-items:center;gap:10px;padding:10px 12px;font-size:13px;font-weight:600;color:#334155;text-decoration:none;border-radius:9px;transition:background .15s}
.admin-pill-menu a:hover{background:#f1f5f9}
.admin-pill-menu a i{width:16px;color:#64748b}
.admin-pill-menu a.danger{color:#dc2626}
.admin-pill-menu a.danger i{color:#dc2626}
.admin-pill-menu-divider{height:1px;background:#f1f5f9;margin:6px 4px}
.menu-toggle{display:none;background:none;border:none;font-size:1.2rem;cursor:pointer;color:#475569;padding:6px}

/* Content */
.content{padding:22px 24px 48px;flex:1}
.section-label{font-size:10.5px;font-weight:700;letter-spacing:.11em;text-transform:uppercase;color:#94a3b8;margin-bottom:11px;display:flex;align-items:center;gap:8px}
.section-label::after{content:'';flex:1;height:1px;background:#e2e8f0}

/* Hero */
.admin-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 55%,#1e40af 100%);border-radius:18px;padding:26px 28px;margin-bottom:22px;display:flex;align-items:center;gap:22px;flex-wrap:wrap;box-shadow:0 6px 24px rgba(15,23,42,.28);position:relative;overflow:hidden}
.admin-hero::before{content:'';position:absolute;top:-50px;right:-50px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.03)}
.admin-hero::after{content:'';position:absolute;bottom:-60px;right:80px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.025)}
.hero-avatar{width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.28);flex-shrink:0;z-index:1}
.hero-text{flex:1;z-index:1}
.hero-greeting{font-size:12px;color:rgba(255,255,255,.6);margin-bottom:2px}
.hero-name{font-size:1.4rem;font-weight:800;color:white;margin-bottom:4px}
.hero-meta{font-size:12px;color:rgba(255,255,255,.55);margin-bottom:10px}
.hero-tags{display:flex;gap:7px;flex-wrap:wrap}
.hero-tag{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);color:rgba(255,255,255,.88);font-size:10.5px;font-weight:600;padding:3px 11px;border-radius:20px}
.hero-tag.gold{background:rgba(245,158,11,.18);border-color:rgba(245,158,11,.35);color:#fbbf24}
.hero-kpis{display:flex;gap:0;z-index:1}
.hero-kpi{text-align:center;padding:0 20px;border-right:1px solid rgba(255,255,255,.13)}
.hero-kpi:last-child{border-right:none;padding-right:0}
.hero-kpi-val{font-size:1.7rem;font-weight:800;color:white;line-height:1}
.hero-kpi-lbl{font-size:10.5px;color:rgba(255,255,255,.5);margin-top:3px}

/* KPI grid */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:22px}
.kpi{background:white;border-radius:13px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.07);border-top:3px solid #1e3a8a;display:flex;align-items:center;gap:13px;transition:transform .2s,box-shadow .2s}
.kpi:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(0,0,0,.1)}
.kpi.green{border-color:#10b981} .kpi.amber{border-color:#f59e0b} .kpi.purple{border-color:#8b5cf6} .kpi.teal{border-color:#14b8a6} .kpi.red{border-color:#ef4444}
.kpi-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.ki-blue{background:#dbeafe;color:#1d4ed8} .ki-green{background:#dcfce7;color:#15803d} .ki-amber{background:#fef9c3;color:#92400e} .ki-purple{background:#ede9fe;color:#5b21b6} .ki-teal{background:#ccfbf1;color:#0d9488} .ki-red{background:#fee2e2;color:#b91c1c}
.kpi-val{font-size:1.6rem;font-weight:800;color:#0f172a;line-height:1}
.kpi-lbl{font-size:11.5px;color:#64748b;margin-top:3px}

/* Charts */
.charts-row{display:grid;grid-template-columns:1.5fr 1fr;gap:18px;margin-bottom:20px}

/* Cards */
.card{background:white;border-radius:15px;padding:20px 22px;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:18px}
.card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;padding-bottom:11px;border-bottom:1px solid #f1f5f9}
.card-title{font-size:.93rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:7px}
.card-title i{color:#1e3a8a}
.card-sub{font-size:11.5px;color:#64748b}
.view-all{font-size:11.5px;color:#1e3a8a;text-decoration:none;font-weight:600}
.view-all:hover{text-decoration:underline}

/* Quick actions */
.quick-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.quick-btn{padding:13px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:11px;text-align:center;text-decoration:none;color:#0f172a;transition:all .2s;display:flex;flex-direction:column;align-items:center;gap:7px}
.quick-btn i{font-size:1.2rem;color:#1e3a8a}
.quick-btn span{font-size:11.5px;font-weight:600}
.quick-btn:hover{background:#0f172a;color:white;border-color:#0f172a;transform:translateY(-1px)}
.quick-btn:hover i{color:white}

/* Two-col */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:18px}

/* Table */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:12.5px}
thead th{padding:9px 13px;background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#0f172a;font-weight:700;text-align:left;font-size:11.5px;white-space:nowrap}
tbody td{padding:9px 13px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle}
tbody tr:hover td{background:#f8fafc}
tbody tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:700}
.badge-pass{background:#dcfce7;color:#15803d} .badge-fail{background:#fee2e2;color:#991b1b}

/* Footer */
.dash-footer{background:white;border-radius:15px;padding:16px 22px;box-shadow:0 1px 3px rgba(0,0,0,.07);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;border-top:3px solid #1e3a8a;margin-top:4px}
.dash-footer-left{font-size:12.5px;color:#475569;line-height:1.7}
.dash-footer-right{font-size:11.5px;color:#94a3b8;text-align:right}

/* Responsive */
@media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,1fr)}.charts-row{grid-template-columns:1fr}}
@media(max-width:900px){.two-col{grid-template-columns:1fr}.hero-kpis{display:none}}
@media(max-width:768px){
    .sidebar{transform:translateX(-100%)} 
    .main{margin-left:0} .menu-toggle{display:block}
    .kpi-grid{grid-template-columns:repeat(2,1fr)}
    .quick-grid{grid-template-columns:repeat(2,1fr)}
    .content{padding:14px} .admin-hero{padding:20px}
    .admin-pill-menu{width:260px;right:-10px}
}
@media(max-width:480px){.kpi-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">

<!-- Sidebar -->
<?php $activePage='dashboard'; require_once __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
<!-- Topbar -->
<div class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
        <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-left">
            <h1><i class="fas fa-gauge-high" style="color:#1a4fd8;margin-right:7px;font-size:1rem"></i>Dashboard
                <span style="display:inline-flex;align-items:center;gap:5px;background:#ede9fe;color:#5b21b6;font-size:10.5px;font-weight:700;padding:3px 10px;border-radius:20px;vertical-align:middle;margin-left:8px;letter-spacing:.4px;border:1px solid #ddd6fe;white-space:nowrap;">
                    <i class="fas fa-shield-halved"></i> ADMIN
                </span>
            </h1>
            <p><?= htmlspecialchars($academicSession) ?> &nbsp;·&nbsp; <?= htmlspecialchars($currentSemester) ?> &nbsp;·&nbsp; <span id="liveClock"><?= date('D, d M Y \a\t g:i:s A') ?></span> &nbsp;·&nbsp; <span style="color:#1a4fd8;font-weight:600;">Faculty of Computing</span></p>
        </div>
    </div>
    <div class="topbar-right">
        <!-- Admin pill (dropdown: profile / settings / logout) -->
        <div class="admin-pill-wrap" id="adminPillWrap">
            <button type="button" class="admin-pill" onclick="toggleAdminPillMenu()">
                <img src="<?= $photoSrc ?>" alt="avatar">
                <div>
                    <div class="admin-pill-name"><?= htmlspecialchars($adminName) ?></div>
                    <div class="admin-pill-role">Administrator</div>
                </div>
                <i class="fas fa-chevron-down" style="font-size:10px;color:#94a3b8;margin-left:2px"></i>
            </button>
            <div class="admin-pill-menu" id="adminPillMenu">
                <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                <div class="admin-pill-menu-divider"></div>
                <a href="logout.php" class="danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>
</div>
<script>
function toggleAdminPillMenu() {
    document.getElementById('adminPillMenu').classList.toggle('open');
}
document.addEventListener('click', function(e) {
    var wrap = document.getElementById('adminPillWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('adminPillMenu').classList.remove('open');
    }
});
</script>

<div class="content">

<!-- Announcement banner (if active) -->
<?php if($annActive && $annText): ?>
<div style="background:#dbeafe;border:1px solid #93c5fd;border-left:4px solid #3b82f6;border-radius:11px;padding:11px 16px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;font-size:13px;color:#1d4ed8">
    <div style="display:flex;align-items:center;gap:8px"><i class="fas fa-bullhorn"></i> <strong>Announcement:</strong> &nbsp;<?= htmlspecialchars($annText) ?></div>
    <a href="portal-control.php" style="font-size:11.5px;color:#1d4ed8;font-weight:700;text-decoration:none;background:rgba(59,130,246,.15);padding:4px 12px;border-radius:6px">Edit</a>
</div>
<?php endif; ?>

<?php foreach ($notifications as $n): ?>
<div class="status-banner <?= htmlspecialchars($n['type']) ?>"><i class="fas fa-<?= htmlspecialchars($n['icon']) ?>"></i> <?= htmlspecialchars($n['msg']) ?></div>
<?php endforeach; ?>

<!-- Hero -->
<div class="admin-hero">
    <img src="<?= $photoSrc ?>" alt="Avatar" class="hero-avatar">
    <div class="hero-text">
        <div class="hero-greeting">Welcome back,</div>
        <div class="hero-name"><?= htmlspecialchars($adminName) ?></div>
        <div class="hero-meta">System Administrator &nbsp;·&nbsp; Faculty of Computing &nbsp;·&nbsp; Dept. of Computer Science &nbsp;·&nbsp; PAAU, Anyigba</div>
        <div class="hero-tags">
            <span class="hero-tag gold"><i class="fas fa-shield-halved"></i> Administrator</span>
            <span class="hero-tag"><i class="fas fa-calendar"></i> <?= htmlspecialchars($academicSession) ?></span>
            <span class="hero-tag" style="background:rgba(16,185,129,.18);border-color:rgba(16,185,129,.35);color:#86efac"><i class="fas fa-circle"></i> Active</span>
        </div>
    </div>
    <div class="hero-kpis">
        <div class="hero-kpi"><div class="hero-kpi-val"><?= $totalStudents ?></div><div class="hero-kpi-lbl">Students</div></div>
        <div class="hero-kpi"><div class="hero-kpi-val"><?= $totalTests ?></div><div class="hero-kpi-lbl">Active Tests</div></div>
        <div class="hero-kpi"><div class="hero-kpi-val"><?= $passRate ?>%</div><div class="hero-kpi-lbl">Pass Rate</div></div>
    </div>
</div>

<!-- KPI cards -->
<div class="section-label"><i class="fas fa-chart-bar" style="color:#1e3a8a"></i> System Overview</div>
<div class="kpi-grid">
    <div class="kpi"><div class="kpi-icon ki-blue"><i class="fas fa-users"></i></div><div><div class="kpi-val"><?= $totalStudents ?></div><div class="kpi-lbl">Total Students</div></div></div>
    <div class="kpi green"><div class="kpi-icon ki-green"><i class="fas fa-chalkboard-teacher"></i></div><div><div class="kpi-val"><?= $totalLecturers ?></div><div class="kpi-lbl">Lecturers</div></div></div>
    <div class="kpi purple"><div class="kpi-icon ki-purple"><i class="fas fa-file-alt"></i></div><div><div class="kpi-val"><?= $totalTests ?></div><div class="kpi-lbl">Active Tests</div></div></div>
    <div class="kpi amber"><div class="kpi-icon ki-amber"><i class="fas fa-pencil-alt"></i></div><div><div class="kpi-val"><?= $totalSubmissions ?></div><div class="kpi-lbl">Submissions</div></div></div>
    <div class="kpi teal"><div class="kpi-icon ki-teal"><i class="fas fa-chart-line"></i></div><div><div class="kpi-val"><?= $avgScore ?>%</div><div class="kpi-lbl">Avg Score</div></div></div>
    <div class="kpi teal"><div class="kpi-icon ki-teal"><i class="fas fa-trophy"></i></div><div><div class="kpi-val"><?= $passRate ?>%</div><div class="kpi-lbl">Pass Rate</div></div></div>
    <div class="kpi red"><div class="kpi-icon ki-red"><i class="fas fa-camera"></i></div><div><div class="kpi-val"><?= $facesEnrolled ?>/<?= $totalStudents ?></div><div class="kpi-lbl">Face ID Enrolled</div></div></div>
    <div class="kpi"><div class="kpi-icon ki-blue"><i class="fas fa-check-circle"></i></div><div><div class="kpi-val"><?= $totalPass ?></div><div class="kpi-lbl">Total Passes</div></div></div>
</div>

<!-- Charts -->
<div class="charts-row">
    <div class="card" style="margin-bottom:0">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-chart-bar"></i> Submissions Over Time</div>
            <a href="analytics.php" class="view-all">Full Analytics →</a>
        </div>
        <div style="position:relative;height:200px"><canvas id="submissionsChart"></canvas></div>
    </div>
    <div class="card" style="margin-bottom:0">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-chart-pie"></i> Pass / Fail Distribution</div>
        </div>
        <div style="position:relative;height:200px"><canvas id="passFailChart"></canvas></div>
    </div>
</div>

<!-- Quick actions -->
<div class="section-label" style="margin-top:18px"><i class="fas fa-bolt" style="color:#1e3a8a"></i> Quick Actions</div>
<div class="card">
    <div class="quick-grid">
        <a href="students.php" class="quick-btn"><i class="fas fa-user-graduate"></i><span>Manage Students</span></a>
        <a href="lecturers.php" class="quick-btn"><i class="fas fa-chalkboard-teacher"></i><span>Manage Lecturers</span></a>
        <a href="results.php" class="quick-btn"><i class="fas fa-chart-bar"></i><span>View Results</span></a>
        <a href="face-enrollment.php" class="quick-btn"><i class="fas fa-camera"></i><span>Face Enrolment</span></a>
        <a href="analytics.php" class="quick-btn"><i class="fas fa-chart-pie"></i><span>Analytics</span></a>
        <a href="portal-control.php" class="quick-btn"><i class="fas fa-toggle-on"></i><span>Portal Control</span></a>
    </div>
</div>

<!-- Recent data -->
<div class="two-col">
    <div class="card" style="margin-bottom:0">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-clock"></i> Recent Submissions</div>
            <a href="results.php" class="view-all">View All →</a>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead><tr><th>Student</th><th>Course</th><th>CA/30</th><th>Status</th></tr></thead>
                <tbody>
                <?php if(empty($recentAttempts)): ?>
                <tr><td colspan="4" style="text-align:center;color:#64748b;padding:20px">No submissions yet</td></tr>
                <?php endif; ?>
                <?php foreach($recentAttempts as $ra):
                    $pct = round((float)$ra['percentage'],1);
                    $pass = $pct>=$ra['passing_score'];
                    $ca   = $ra['total']>0?round(($ra['score']/$ra['total'])*30,1):0;
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($ra['full_name']) ?></strong><br><small style="color:#94a3b8"><?= $ra['level'] ?>L</small></td>
                    <td><strong><?= htmlspecialchars($ra['course_code']) ?></strong></td>
                    <td><strong style="color:#1e3a8a"><?= $ca ?></strong>/30</td>
                    <td><span class="badge <?= $pass?'badge-pass':'badge-fail' ?>"><?= $pass?'PASS':'FAIL' ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card" style="margin-bottom:0">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-user-plus"></i> Recently Enrolled</div>
            <a href="students.php" class="view-all">View All →</a>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead><tr><th>Name</th><th>Matric</th><th>Level</th><th>Face ID</th></tr></thead>
                <tbody>
                <?php if(empty($recentStudents)): ?>
                <tr><td colspan="4" style="text-align:center;color:#64748b;padding:20px">No students yet</td></tr>
                <?php endif; ?>
                <?php foreach($recentStudents as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['full_name']) ?></strong></td>
                    <td style="font-size:11.5px;color:#64748b"><?= htmlspecialchars($s['matric']) ?></td>
                    <td><?= $s['level'] ?>L</td>
                    <td><?php if(!empty($s['face_descriptor'])): ?><span style="color:#15803d;font-size:11.5px;font-weight:600"><i class="fas fa-check-circle"></i> Yes</span><?php else: ?><span style="color:#ef4444;font-size:11.5px;font-weight:600"><i class="fas fa-times-circle"></i> No</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Footer -->
<div class="dash-footer" style="margin-top:18px">
    <div class="dash-footer-left"><strong>Prince Abubakar Audu University, Anyigba</strong><br>Faculty of Computing — Department of Computer Science<br>CA Portal · <?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?></div>
    <div class="dash-footer-right"><?= date('d F Y, g:i A') ?><br>Logged in as <strong><?= htmlspecialchars($adminName) ?></strong></div>
</div>

</div>
</main>
</div>

<script>
// Live clock — ticks every second so admins can see the current date/time
// on-screen without needing to check their OS clock.
(function() {
    var el = document.getElementById('liveClock');
    if (!el) return;
    var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function pad(n) { return n < 10 ? '0' + n : n; }
    function tick() {
        var d = new Date();
        var h = d.getHours();
        var ampm = h >= 12 ? 'PM' : 'AM';
        var h12 = h % 12 || 12;
        el.textContent = days[d.getDay()] + ', ' + pad(d.getDate()) + ' ' + months[d.getMonth()] + ' ' + d.getFullYear()
            + ' at ' + h12 + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) + ' ' + ampm;
    }
    tick();
    setInterval(tick, 1000);
})();

if (typeof Chart === 'undefined') {
    document.querySelectorAll('#submissionsChart, #passFailChart').forEach(function(c) {
        var msg = document.createElement('div');
        msg.style.cssText = 'display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;font-size:.82rem;text-align:center;padding:0 16px';
        msg.textContent = 'Chart library failed to load — check your internet connection and refresh.';
        c.replaceWith(msg);
    });
} else {

Chart.defaults.font.family="-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif";
Chart.defaults.font.size=11;

// Submissions bar (monthly from attempts)
new Chart(document.getElementById('submissionsChart'),{
    type:'bar',
    data:{
        labels:['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
        datasets:[{
            label:'Submissions',
            data:[<?php
                $monthly = array_fill(0,12,0);
                // Get real monthly data
                $mData = $pdo->query("SELECT MONTH(end_time) as m, COUNT(*) as c FROM attempts WHERE status='completed' AND YEAR(end_time)=YEAR(NOW()) GROUP BY MONTH(end_time)")->fetchAll();
                foreach($mData as $md) $monthly[$md['m']-1]=$md['c'];
                echo implode(',',$monthly);
            ?>],
            backgroundColor:'rgba(30,58,138,0.7)',
            borderColor:'#1e3a8a',
            borderWidth:2,
            borderRadius:6,
        }]
    },
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1,font:{size:10}},grid:{color:'rgba(0,0,0,0.05)'}},x:{grid:{display:false},ticks:{font:{size:10}}}}}
});

// Pass/Fail doughnut
new Chart(document.getElementById('passFailChart'),{
    type:'doughnut',
    data:{
        labels:['Pass','Fail'],
        datasets:[{
            data:[<?= (int)$pf['Pass'] ?>,<?= (int)$pf['Fail'] ?>],
            backgroundColor:['#10b981','#ef4444'],
            borderWidth:3,borderColor:'#fff',
        }]
    },
    options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'bottom',labels:{font:{size:11},usePointStyle:true,padding:10}}}}
});

}

// Mobile sidebar
document.addEventListener('click',function(e){
    const sb=document.getElementById('sidebar');
    if(sb.classList.contains('open')&&!sb.contains(e.target)&&!e.target.closest('.menu-toggle')) sb.classList.remove('open');
});
</script>
</body>
</html>
