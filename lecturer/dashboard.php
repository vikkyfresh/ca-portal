<?php
session_start();
if (!isset($_SESSION['lecturer_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

// ── PORTAL CONTROL ───────────────────────────────────────────
$accessBlock = getAccessBlock('lecturer');
if ($accessBlock) {
    session_destroy();
    renderAccessBlockPage($accessBlock, 'lecturer', '../index.php', '../api/portal-status.php');
}
// ─────────────────────────────────────────────────────────────



$lecturerId = $_SESSION['lecturer_id'];
$lecturerName = $_SESSION['lecturer_name'];
$lecturerDept = $_SESSION['lecturer_department'] ?? 'Computer Science';

// Build photo source — also expose as $lecturerAvatarUrl for sidebar
$photoSrc = 'https://ui-avatars.com/api/?name=' . urlencode($lecturerName) . '&background=1e3a8a&color=fff&size=120&bold=true';
$stmt = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
$stmt->execute([$lecturerId]);
$photoRow = $stmt->fetch();
if (!empty($photoRow['photo'])) {
    $relPhoto   = ltrim($photoRow['photo'], '/');
    $serverPath = dirname(__DIR__) . '/' . $relPhoto;   // absolute disk path
    if (file_exists($serverPath)) {
        $photoSrc = '../' . $relPhoto;                  // correct URL from lecturer/
    }
}
$lecturerAvatarUrl = $photoSrc;   // sidebar uses this variable name
$avatarUrl         = $photoSrc;   // fallback alias

// Stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tests WHERE created_by = ? AND is_active = 1");
$stmt->execute([$lecturerId]);
$totalTests = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT course_code) FROM lecturer_courses WHERE lecturer_id = ?");
$stmt->execute([$lecturerId]);
$totalCourses = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM attempts a JOIN tests t ON a.test_id = t.id WHERE t.created_by = ?");
$stmt->execute([$lecturerId]);
$totalSubmissions = $stmt->fetchColumn();

// Get courses
$courses = $pdo->prepare("SELECT * FROM lecturer_courses WHERE lecturer_id = ? ORDER BY level");
$courses->execute([$lecturerId]);
$courses = $courses->fetchAll();

// Get recent tests
$stmt = $pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM attempts WHERE test_id = t.id) as submissions FROM tests t WHERE t.created_by = ? ORDER BY t.created_at DESC LIMIT 5");
$stmt->execute([$lecturerId]);
$recentTests = $stmt->fetchAll();

// Check maintenance mode
$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'");
$maintenanceMode = $stmt->fetchColumn();

// Build notifications for lecturer (blocked/testing-closed/announcement states —
// maintenance mode gets its own dedicated banner below, so it's not duplicated here)
$lecNotifs = [];
$stmtN = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('students_blocked','testing_open','announcement_active','announcement_text','academic_session','current_semester')");
$sysVals = [];
while ($r = $stmtN->fetch()) $sysVals[$r['setting_key']] = $r['setting_value'];
if (!empty($sysVals['students_blocked']) && $sysVals['students_blocked']=='1') $lecNotifs[] = ['type'=>'amber','icon'=>'user-slash','msg'=>'Students are currently blocked'];
if (isset($sysVals['testing_open']) && $sysVals['testing_open']=='0') $lecNotifs[] = ['type'=>'amber','icon'=>'stop-circle','msg'=>'Test taking is closed'];
if (!empty($sysVals['announcement_active']) && $sysVals['announcement_active']=='1') $lecNotifs[] = ['type'=>'blue','icon'=>'bullhorn','msg'=>'Announcement active: '.mb_substr($sysVals['announcement_text']??'',0,50)];
$lecNotifCount = count($lecNotifs);
$academicSession = $sysVals['academic_session'] ?? '2025/2026';
$currentSemester = $sysVals['current_semester'] ?? '1st Semester';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Lecturer Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; }
        .layout{display:flex;min-height:100vh}
        /* ── Sidebar ── */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */ /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */ /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */ /* sidebar CSS → includes/sidebar.php */
        /* .nav defined in includes/sidebar.php */
        
        /* .nav a defined in includes/sidebar.php */
        .nav a i { width:18px; text-align:center; font-size:.88rem; }
        
        /* sidebar CSS → includes/sidebar.php */
        .nav a i { width: 20px; }
        .main { flex: 1; margin-left: 260px; }
        .topbar{background:white;padding:0 24px;border-bottom:1px solid #e2e8f0;height:62px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 1px 3px rgba(0,0,0,.05);flex-shrink:0}
        .topbar-left{display:flex;flex-direction:column;gap:1px}
        .topbar-left h1{font-size:1.15rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px}
        .topbar-left p{font-size:11.5px;color:#64748b;margin-top:1px}
        .topbar-right{display:flex;align-items:center;gap:10px}
        .lec-pill-wrap{position:relative}
        .lec-pill{display:flex;align-items:center;gap:9px;padding:5px 13px 5px 5px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:40px;cursor:pointer;transition:all .2s;font:inherit}
        .lec-pill:hover{background:#eff6ff;border-color:#bfdbfe}
        .lec-pill img{width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;object-position:center 20%}
        .lec-pill-name{font-size:12.5px;font-weight:600;color:#0f172a;text-align:left}
        .lec-pill-role{font-size:10.5px;color:#64748b;text-align:left}
        .lec-pill-menu{position:absolute;top:52px;right:0;width:210px;background:white;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.12);z-index:200;display:none;overflow:hidden;padding:6px}
        .lec-pill-menu.open{display:block}
        .lec-pill-menu a{display:flex;align-items:center;gap:10px;padding:10px 12px;font-size:13px;font-weight:600;color:#334155;text-decoration:none;border-radius:9px;transition:background .15s}
        .lec-pill-menu a:hover{background:#f1f5f9}
        .lec-pill-menu a i{width:16px;color:#64748b}
        .lec-pill-menu a.danger{color:#dc2626}
        .lec-pill-menu a.danger i{color:#dc2626}
        .lec-pill-menu-divider{height:1px;background:#f1f5f9;margin:6px 4px}
        .content { padding: 24px; }
        
        .maintenance-banner { background: #fff3cd; border: 1px solid #f59e0b; border-radius: 10px; padding: 14px 20px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
        .maintenance-banner i { font-size: 1.5rem; color: #f59e0b; }
        .status-banner { border-radius: 10px; padding: 12px 18px; margin-bottom: 14px; display: flex; align-items: center; gap: 12px; font-size: 13px; font-weight: 600; }
        .status-banner.red   { background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; }
        .status-banner.amber { background:#fef3c7; border:1px solid #fcd34d; color:#92400e; }
        .status-banner.blue  { background:#dbeafe; border:1px solid #93c5fd; color:#1e3a8a; }
        
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .stat-icon.blue { background: #dbeafe; color: #1e40af; }
        .stat-icon.green { background: #d1fae5; color: #065f46; }
        .stat-icon.purple { background: #ede9fe; color: #5b21b6; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: #0f172a; }
        .stat-label { color: #64748b; font-size: 0.85rem; }
        
        .section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .section h2 { font-size: 1.1rem; color: #0f172a; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        
        .courses-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .course-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; }
        .course-card h4 { color: #0f172a; margin-bottom: 4px; }
        .course-card p { color: #64748b; font-size: 0.85rem; }
        .btn-sm { padding: 6px 12px; background: #0f172a; color: white; border-radius: 6px; text-decoration: none; font-size: 0.8rem; display: inline-block; margin-top: 8px; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 16px; border-bottom: 1px solid #e2e8f0; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; color: #475569; font-weight: 600; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        
        @media (max-width: 768px) { /* sidebar CSS → includes/sidebar.php */ .main { margin-left: 0; } .stats { grid-template-columns: 1fr; } .courses-grid { grid-template-columns: 1fr; } }
    
/* ── Responsive ── */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:#f1f5f9}
::-webkit-scrollbar-thumb{background:#cbd5e0;border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:#94a3b8}

@media(max-width:900px){
    .grid-2,.stats-grid,.kpi-grid{grid-template-columns:repeat(2,1fr)}
    .level-grid{grid-template-columns:repeat(2,1fr)}
    .form-grid{grid-template-columns:1fr}
    .info-grid{grid-template-columns:1fr}
    .hero-stats{display:none}
}
@media(max-width:768px){
    /* sidebar CSS → includes/sidebar.php */
    .main{margin-left:0}
    .topbar{padding:0 16px;height:auto;min-height:64px;flex-wrap:wrap;gap:8px;padding-top:10px;padding-bottom:10px}
    .content{padding:16px}
    .kpi-grid,.stats-grid{grid-template-columns:repeat(2,1fr)}
    .level-grid{flex-wrap:wrap}
    .card{padding:16px 14px}
    .tbl-wrap{overflow-x:auto}
    table{font-size:12px}
    thead th{padding:8px 10px;font-size:11px}
    tbody td{padding:8px 10px}
    .btn-row{flex-wrap:wrap}
    .back-btn{padding:7px 12px;font-size:12px}
    .profile-hero{flex-direction:column;text-align:center;padding:24px 16px}
    .hero-tags{justify-content:center}
}
@media(max-width:480px){
    .kpi-grid,.stats-grid{grid-template-columns:1fr}
    .grid-2{grid-template-columns:1fr}
}
</style>
</head>
<body>
    <div class="layout">
        <?php $activePage='dashboard'; require_once __DIR__.'/includes/sidebar.php'; ?>
        <main class="main">
            <div class="topbar">
                <div style="display:flex;align-items:center;gap:12px">
                    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
                    <div class="topbar-left">
                        <h1>
                            <i class="fas fa-chalkboard-teacher" style="color:#1a4fd8;font-size:1rem"></i>
                            Dashboard
                            <span style="display:inline-flex;align-items:center;gap:5px;background:#d1fae5;color:#065f46;font-size:10.5px;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:.4px;border:1px solid #a7f3d0;white-space:nowrap;">
                                <i class="fas fa-chalkboard-teacher"></i> LECTURER
                            </span>
                        </h1>
                        <p>Faculty of Computing &nbsp;·&nbsp; Computer Science &nbsp;·&nbsp; <?= htmlspecialchars($academicSession ?? '2025/2026') ?> &nbsp;·&nbsp; <span id="liveClock"><?= date('D, d M Y \a\t g:i:s A') ?></span></p>
                    </div>
                </div>
                <div class="topbar-right">
                    <!-- Lecturer pill (dropdown: profile / change password / logout) -->
                    <div class="lec-pill-wrap" id="lecPillWrap">
                        <button type="button" class="lec-pill" onclick="toggleLecPillMenu()">
                            <img src="<?= htmlspecialchars($photoSrc) ?>" alt="avatar"
                                 onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($lecturerName) ?>&background=1e3a8a&color=fff&size=80&bold=true'">
                            <div>
                                <div class="lec-pill-name"><?= htmlspecialchars(explode(' ',$lecturerName)[0] . ' ' . (explode(' ',$lecturerName)[1] ?? '')) ?></div>
                                <div class="lec-pill-role">Lecturer</div>
                            </div>
                            <i class="fas fa-chevron-down" style="font-size:10px;color:#94a3b8;margin-left:2px"></i>
                        </button>
                        <div class="lec-pill-menu" id="lecPillMenu">
                            <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                            <a href="change-password.php"><i class="fas fa-key"></i> Change Password</a>
                            <div class="lec-pill-menu-divider"></div>
                            <a href="logout.php" class="danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>
            <script>
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
            function toggleLecPillMenu() {
                document.getElementById('lecPillMenu').classList.toggle('open');
            }
            document.addEventListener('click', function(e) {
                var wrap = document.getElementById('lecPillWrap');
                if (wrap && !wrap.contains(e.target)) {
                    document.getElementById('lecPillMenu').classList.remove('open');
                }
            });
            </script>
            <div class="content">
                <?php if ($maintenanceMode == '1'): ?>
                <div class="maintenance-banner">
                    <i class="fas fa-tools"></i>
                    <div><strong>System Maintenance in Progress</strong><br><small>Students cannot access tests until maintenance is complete.</small></div>
                </div>
                <?php endif; ?>
                <?php foreach ($lecNotifs as $n): ?>
                <div class="status-banner <?= htmlspecialchars($n['type']) ?>"><i class="fas fa-<?= htmlspecialchars($n['icon']) ?>"></i> <?= htmlspecialchars($n['msg']) ?></div>
                <?php endforeach; ?>
                
                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-file-alt"></i></div>
                        <div><div class="stat-value"><?= $totalTests ?></div><div class="stat-label">Active Tests</div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-book"></i></div>
                        <div><div class="stat-value"><?= $totalCourses ?></div><div class="stat-label">Courses</div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-pencil-alt"></i></div>
                        <div><div class="stat-value"><?= $totalSubmissions ?></div><div class="stat-label">Submissions</div></div>
                    </div>
                </div>
                
                <div class="section">
                    <h2><i class="fas fa-book"></i> My Courses</h2>
                    <div class="courses-grid">
                        <?php foreach ($courses as $c): ?>
                        <div class="course-card">
                            <h4><?= htmlspecialchars($c['course_code']) ?></h4>
                            <p><?= htmlspecialchars($c['course_title']) ?></p>
                            <p>Level: <?= $c['level'] ?></p>
                            <a href="create-test.php?course=<?= $c['course_code'] ?>" class="btn-sm">+ New Test</a>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($courses)): ?>
                        <p style="color:#64748b;">No courses assigned yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="section">
                    <h2><i class="fas fa-history"></i> Recent Tests</h2>
                    <table>
                        <thead><tr><th>Test</th><th>Course</th><th>Level</th><th>Submissions</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentTests as $t): ?>
                            <tr>
                                <td><?= htmlspecialchars($t['test_title']) ?></td>
                                <td><?= htmlspecialchars($t['course_code']) ?></td>
                                <td><?= $t['level'] ?></td>
                                <td><?= $t['submissions'] ?></td>
                                <td><span class="badge badge-success"><?= $t['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recentTests)): ?>
                            <tr><td colspan="5" style="color:#64748b;">No tests yet. <a href="create-test.php">Create one</a></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
<script>
// Mobile sidebar close on outside click
document.addEventListener('click', function(e){
    var sb = document.getElementById('sidebar');
    if (sb && sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.menu-toggle'))
        sb.classList.remove('open');
});

// Live portal-control check — if an admin blocks lecturers (incl. exam mode) or
// closes the portal while this dashboard is open, log the lecturer out immediately.
setInterval(function() {
    fetch('../api/portal-status.php?role=lecturer')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.blocked) {
                window.location.href = 'logout.php';
            }
        })
        .catch(function() {});
}, 5000);
</script>
</body>
</html>