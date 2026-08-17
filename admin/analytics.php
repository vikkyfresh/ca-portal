<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

// Admin photo
$photoSrc = 'https://ui-avatars.com/api/?name='.urlencode($_SESSION['admin_name'] ?? 'Admin').'&background=1e3a8a&color=fff&size=80&bold=true';
$stmtPhoto = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
$stmtPhoto->execute([$_SESSION['admin_id'] ?? 0]);
$photoRowX = $stmtPhoto->fetch();
if (!empty($photoRowX['photo'])) {
    $sp = dirname(__DIR__) . '/' . ltrim($photoRowX['photo'], '/');
    if (file_exists($sp)) $photoSrc = '../' . ltrim($photoRowX['photo'], '/');
}


$academicSession = getAcademicSetting('academic_session', '2025/2026');
$currentSemester = getAcademicSetting('current_semester', '2nd Semester');

// ── Global KPIs ──────────────────────────────────────────────────────────────
$totalStudents   = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$totalTests      = (int)$pdo->query("SELECT COUNT(*) FROM tests")->fetchColumn();
$totalAttempts   = (int)$pdo->query("SELECT COUNT(*) FROM attempts WHERE status='completed'")->fetchColumn();
$overallAvg      = round((float)($pdo->query("SELECT AVG(percentage) FROM attempts WHERE status='completed'")->fetchColumn() ?? 0), 1);
$totalPass       = (int)$pdo->query("SELECT COUNT(*) FROM attempts a JOIN tests t ON a.test_id=t.id WHERE a.status='completed' AND a.percentage>=t.passing_score")->fetchColumn();
$passRate        = $totalAttempts > 0 ? round($totalPass / $totalAttempts * 100, 1) : 0;
$facesEnrolled   = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE face_descriptor IS NOT NULL")->fetchColumn();

// ── Performance by Level (100L–400L) ────────────────────────────────────────
$byLevel = $pdo->query("
    SELECT s.level,
           COUNT(DISTINCT s.matric) AS student_count,
           COUNT(a.id)              AS submissions,
           ROUND(AVG(a.percentage),1) AS avg_score,
           ROUND(MAX(a.percentage),1) AS highest,
           ROUND(MIN(a.percentage),1) AS lowest,
           SUM(CASE WHEN a.percentage >= t.passing_score THEN 1 ELSE 0 END) AS passed
    FROM students s
    LEFT JOIN attempts a ON s.matric = a.student_matric AND a.status = 'completed'
    LEFT JOIN tests t ON a.test_id = t.id
    GROUP BY s.level
    ORDER BY s.level ASC
")->fetchAll();

// ── Performance by test ──────────────────────────────────────────────────────
$byTest = $pdo->query("
    SELECT t.course_code, t.test_title, t.level, t.passing_score,
           COUNT(a.id) AS submissions,
           ROUND(AVG(a.percentage),1) AS avg_score,
           ROUND(MAX(a.percentage),1) AS highest,
           ROUND(MIN(a.percentage),1) AS lowest,
           SUM(CASE WHEN a.percentage >= t.passing_score THEN 1 ELSE 0 END) AS passed
    FROM tests t
    LEFT JOIN attempts a ON t.id=a.test_id AND a.status='completed'
    GROUP BY t.id
    ORDER BY t.level ASC, t.course_code ASC
")->fetchAll();

// ── Per-level avg over time (last 6 months) for trend line ─────────────────
$trend = $pdo->query("
    SELECT DATE_FORMAT(a.end_time,'%b %Y') AS month_label,
           ROUND(AVG(a.percentage),1) AS avg_score,
           COUNT(a.id) AS total
    FROM attempts a
    WHERE a.status='completed' AND a.end_time >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(a.end_time,'%Y-%m')
    ORDER BY DATE_FORMAT(a.end_time,'%Y-%m') ASC
")->fetchAll();

// ── Top 10 students ──────────────────────────────────────────────────────────
$topStudents = $pdo->query("
    SELECT s.full_name, s.matric, s.level,
           COUNT(a.id) AS tests_taken,
           ROUND(AVG(a.percentage),1) AS avg_score,
           ROUND(MAX(a.percentage),1) AS best_score
    FROM students s
    JOIN attempts a ON s.matric=a.student_matric AND a.status='completed'
    GROUP BY s.matric
    ORDER BY avg_score DESC
    LIMIT 10
")->fetchAll();

// ── Recent activity ──────────────────────────────────────────────────────────
$recentAttempts = $pdo->query("
    SELECT a.student_matric, s.full_name, s.level, t.course_code,
           a.percentage, a.score, a.total, a.end_time, t.passing_score
    FROM attempts a
    JOIN students s ON a.student_matric=s.matric
    JOIN tests t ON a.test_id=t.id
    WHERE a.status='completed'
    ORDER BY a.end_time DESC
    LIMIT 15
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics — Admin Portal | CS Dept CA System</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

body { min-height: 100vh;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #f1f5f9;
    color: #0f172a;
}

/* ── Layout ── */
.layout{display:flex;min-height:100vh}

/* sidebar CSS in includes/sidebar.php */





/* .nav defined in includes/sidebar.php */

/* .nav a defined in includes/sidebar.php */
.nav a i { width: 18px; text-align: center; font-size: .9rem; }





.main { flex:1; margin-left: 260px; min-width: 0; }

/* ── Topbar ── */
.topbar {
    background: white;
    padding: 0 28px;
    border-bottom: 1px solid #e2e8f0;
    position: sticky; top:0; z-index:50;
    height: 64px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.topbar-left h1 { font-size: 1.25rem; font-weight: 700; color: #0f172a; }
.topbar-left p  { font-size: 12px; color: #64748b; margin-top: 1px; }
.topbar-right   { display: flex; align-items: center; gap: 12px; }
.print-btn {
    padding: 8px 16px;
    background: linear-gradient(135deg, #0f172a, #1e3a8a);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex; align-items: center; gap: 6px;
    transition: opacity .2s;
}
.print-btn:hover { opacity: .88; }

/* ── Content ── */
.content { padding: 24px 28px 48px; }

/* ── Section header ── */
.section-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: #94a3b8;
    margin-bottom: 12px;
    display: flex; align-items: center; gap: 8px;
}
.section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e2e8f0;
}

/* ── KPI row ── */
.kpi-row {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 12px;
    margin-bottom: 28px;
}
.kpi {
    background: white;
    border-radius: 14px;
    padding: 18px 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.07);
    border-top: 3px solid #1e3a8a;
    position: relative;
    overflow: hidden;
}
.kpi::after {
    content: '';
    position: absolute;
    bottom: -10px; right: -10px;
    width: 50px; height: 50px;
    border-radius: 50%;
    background: #1e3a8a;
    opacity: .05;
}
.kpi.green  { border-color: #10b981; } .kpi.green::after  { background: #10b981; }
.kpi.amber  { border-color: #f59e0b; } .kpi.amber::after  { background: #f59e0b; }
.kpi.red    { border-color: #ef4444; } .kpi.red::after    { background: #ef4444; }
.kpi.purple { border-color: #8b5cf6; } .kpi.purple::after { background: #8b5cf6; }
.kpi.teal   { border-color: #14b8a6; } .kpi.teal::after   { background: #14b8a6; }

.kpi-val { font-size: 1.9rem; font-weight: 800; color: #0f172a; line-height: 1; }
.kpi-lbl { font-size: 11px; color: #64748b; margin-top: 6px; }

/* ── Cards ── */
.card {
    background: white;
    border-radius: 16px;
    padding: 22px 24px;
    box-shadow: 0 1px 4px rgba(0,0,0,.07);
    margin-bottom: 20px;
}
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid #f1f5f9;
}
.card-title {
    font-size: .975rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-title i { color: #1e3a8a; }
.card-sub { font-size: 12px; color: #64748b; }

/* ── Grids ── */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
.grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }

/* ── Level performance cards ── */
.level-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
.level-card {
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,.07);
    text-align: center;
    border-top: 4px solid #1e3a8a;
    transition: transform .2s, box-shadow .2s;
}
.level-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,.1); }
.level-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 56px; height: 56px;
    background: linear-gradient(135deg, #0f172a, #1e3a8a);
    color: white;
    border-radius: 14px;
    font-size: 1.1rem;
    font-weight: 800;
    margin-bottom: 12px;
}
.level-avg { font-size: 2rem; font-weight: 800; color: #0f172a; line-height: 1; }
.level-label { font-size: 11px; color: #64748b; margin-bottom: 12px; }
.level-stats { display: flex; justify-content: space-around; font-size: 12px; color: #475569; padding-top: 10px; border-top: 1px solid #f1f5f9; }
.level-stats span { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.level-stats strong { font-size: 15px; font-weight: 700; color: #0f172a; }

/* Progress bar */
.pbar { background: #e2e8f0; border-radius: 4px; height: 7px; overflow: hidden; margin-top: 6px; }
.pbar-fill { height: 100%; border-radius: 4px; background: linear-gradient(to right, #1e3a8a, #10b981); transition: width .8s ease; }

/* ── Tables ── */
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th {
    padding: 10px 14px;
    background: #0f172a;
    color: white;
    text-align: left;
    font-weight: 600;
    font-size: 11.5px;
    letter-spacing: .02em;
    white-space: nowrap;
}
tbody td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
tbody tr:hover td { background: #f8fafc; }
tbody tr:last-child td { border-bottom: none; }

.badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.badge-pass { background:#dcfce7; color:#15803d; }
.badge-fail { background:#fee2e2; color:#991b1b; }

/* ── Rank badge ── */
.rank-1 { color: #f59e0b; font-weight:800; }
.rank-2 { color: #94a3b8; font-weight:800; }
.rank-3 { color: #b45309; font-weight:800; }

/* ── Official footer ── */
.report-footer {
    background: white;
    border-radius: 16px;
    padding: 20px 28px;
    box-shadow: 0 1px 4px rgba(0,0,0,.07);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 8px;
    border-top: 3px solid #1e3a8a;
}
.report-footer-left { font-size: 13px; color: #475569; line-height: 1.7; }
.report-footer-right { font-size: 12px; color: #94a3b8; text-align: right; }

/* ── Print ── */
@media print {
    .sidebar, .topbar, .print-btn { display: none !important; }
    .main { margin-left: 0; }
    .content { padding: 0; }
    body { background: #f1f5f9; }
    .card, .level-card, .kpi { box-shadow: none; border: 1px solid #e2e8f0; }
    thead th { background: #1e3a8a !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .level-badge { background: #1e3a8a !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .kpi-row { grid-template-columns: repeat(3, 1fr); }
    .level-grid { grid-template-columns: repeat(4, 1fr); }
}

@media (max-width: 1100px) { .kpi-row { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 900px)  { .grid-2, .grid-3 { grid-template-columns: 1fr; } .level-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 768px)  { .sidebar { display:none; } .main { margin-left:0; } .kpi-row { grid-template-columns: repeat(2,1fr); } .content { padding: 16px; } }

/* ── Responsive ── */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:#f1f5f9}
::-webkit-scrollbar-thumb{background:#cbd5e0;border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:#94a3b8}

@media(max-width:1100px){
    .kpi-grid{grid-template-columns:repeat(2,1fr)}
    .kpi-row{grid-template-columns:repeat(3,1fr)}
    .charts-row{grid-template-columns:1fr}
    .level-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:900px){
    .grid-2,.grid-3,.two-col{grid-template-columns:1fr}
    .portal-status-row{grid-template-columns:repeat(2,1fr)}
    .level-grid{grid-template-columns:repeat(2,1fr)}
    .stats-row-grid{grid-template-columns:repeat(2,1fr)}
    .form-grid{grid-template-columns:1fr}
    .info-grid{grid-template-columns:1fr}
    .perms-list{grid-template-columns:1fr}
    .hero-kpis{display:none}
    .hero-stats{display:none}
}
@media(max-width:768px){
    .sidebar{display:none}
    .main{margin-left:0}
    .topbar{padding:0 16px;height:auto;min-height:64px;flex-wrap:wrap;gap:8px;padding-top:10px;padding-bottom:10px}
    .topbar-left h1{font-size:1.1rem}
    .content{padding:16px}
    .kpi-grid,.kpi-row{grid-template-columns:repeat(2,1fr)}
    .level-grid{grid-template-columns:repeat(2,1fr)}
    .quick-grid{grid-template-columns:repeat(2,1fr)}
    .profile-hero{flex-direction:column;text-align:center;padding:24px 16px}
    .hero-tags{justify-content:center}
    .admin-hero{flex-direction:column;text-align:center;padding:24px 16px}
    .card{padding:16px 14px}
    .tbl-wrap{overflow-x:auto}
    table{font-size:12px}
    thead th{padding:8px 10px;font-size:11px}
    tbody td{padding:8px 10px}
    .btn-row{flex-wrap:wrap}
    .back-btn{padding:7px 12px;font-size:12px}
}
@media(max-width:480px){
    .kpi-grid,.kpi-row{grid-template-columns:1fr}
    .level-grid{grid-template-columns:1fr 1fr}
    .portal-status-row{grid-template-columns:1fr 1fr}
    .quick-grid{grid-template-columns:1fr}
    .hero-tags{flex-wrap:wrap}
}
</style>
</head>
<body>
<div class="layout">

<?php $activePage='analytics'; require_once __DIR__.'/includes/sidebar.php'; ?>

<!-- ── Main ── -->
<main class="main">

<!-- Topbar -->
<div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
    <button class="menu-toggle" onclick="document.getElementById(\'sidebar\').classList.toggle(\'open\')"><i class="fas fa-bars"></i></button>
    <div class="topbar-left">
        <h1><i class="fas fa-chart-pie" style="color:#1e3a8a;margin-right:8px"></i>Analytics &amp; Performance</h1>
        <p><?= htmlspecialchars($academicSession) ?> &nbsp;·&nbsp; <?= htmlspecialchars($currentSemester) ?> &nbsp;·&nbsp; Generated: <?= date('d M Y, g:i A') ?></p>
    </div>
    <div class="topbar-right">
        <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
    
    <a href="dashboard.php" style="padding:8px 16px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;transition:all .2s;"><i class="fas fa-arrow-left"></i> Dashboard</a>
</div>
</div>

<div class="content">

<!-- ══ SECTION 1: Global KPIs ══ -->
<div class="section-label"><i class="fas fa-circle-info" style="color:#1e3a8a"></i> System Overview</div>
<div class="kpi-row">
    <div class="kpi">
        <div class="kpi-val"><?= $totalStudents ?></div>
        <div class="kpi-lbl">Registered Students</div>
    </div>
    <div class="kpi green">
        <div class="kpi-val"><?= $totalTests ?></div>
        <div class="kpi-lbl">Tests Created</div>
    </div>
    <div class="kpi amber">
        <div class="kpi-val"><?= $totalAttempts ?></div>
        <div class="kpi-lbl">Total Submissions</div>
    </div>
    <div class="kpi purple">
        <div class="kpi-val"><?= $overallAvg ?>%</div>
        <div class="kpi-lbl">Overall Avg Score</div>
    </div>
    <div class="kpi teal">
        <div class="kpi-val"><?= $passRate ?>%</div>
        <div class="kpi-lbl">Overall Pass Rate</div>
    </div>
    <div class="kpi red">
        <div class="kpi-val"><?= $facesEnrolled ?>/<?= $totalStudents ?></div>
        <div class="kpi-lbl">Face ID Enrolled</div>
    </div>
</div>

<!-- ══ SECTION 2: Performance by Level ══ -->
<div class="section-label"><i class="fas fa-layer-group" style="color:#1e3a8a"></i> Performance by Level (100L – 400L)</div>
<div class="level-grid">
<?php foreach($byLevel as $lv):
    $lvAvg  = $lv['avg_score'] ?? 0;
    $lvPass = $lv['submissions'] > 0 ? round($lv['passed']/$lv['submissions']*100,1) : 0;
    $color = '#1e3a8a';
?>
<div class="level-card" style="border-top-color:<?= $color ?>">
    <div class="level-badge" style="background:linear-gradient(135deg,#0f172a,#1e3a8a)">
        <?= htmlspecialchars($lv['level']) ?>L
    </div>
    <div class="level-avg" style="color:#1e3a8a"><?= $lvAvg ?>%</div>
    <div class="level-label">Average Score</div>
    <div class="pbar"><div class="pbar-fill" style="width:<?= min(100,$lvAvg) ?>%;background:#1e3a8a"></div></div>
    <div class="level-stats" style="margin-top:12px">
        <span><strong><?= $lv['student_count'] ?></strong>Students</span>
        <span><strong><?= $lv['submissions'] ?></strong>Submitted</span>
        <span><strong><?= $lvPass ?>%</strong>Pass Rate</span>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Level bar chart -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-chart-bar"></i> Average Score by Level</div>
        <div class="card-sub">Bars = avg %, Line = pass rate %</div>
    </div>
    <div style="position:relative;height:240px">
        <canvas id="levelChart"></canvas>
    </div>
</div>

<!-- ══ SECTION 4: Activity Trend ══ -->
<?php if (!empty($trend)): ?>
<div class="section-label"><i class="fas fa-chart-line" style="color:#1e3a8a"></i> Score Trend (Last 6 Months)</div>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-chart-line"></i> Monthly Average Score &amp; Submission Volume</div>
        <div class="card-sub">Based on <?= $totalAttempts ?> completed attempts</div>
    </div>
    <div style="position:relative;height:220px">
        <canvas id="trendChart"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- ══ SECTION 5: Test Performance Table ══ -->
<div class="section-label"><i class="fas fa-file-alt" style="color:#1e3a8a"></i> Performance by Test / Course</div>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-table"></i> All Tests — Detailed Breakdown</div>
        <div class="card-sub"><?= count($byTest) ?> tests total</div>
    </div>
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>Level</th>
                    <th>Course Code</th>
                    <th>Test Title</th>
                    <th>Submissions</th>
                    <th>Avg Score</th>
                    <th>Highest</th>
                    <th>Lowest</th>
                    <th>Passed</th>
                    <th>Pass Rate</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($byTest as $bt):
                $pr = $bt['submissions'] > 0 ? round($bt['passed']/$bt['submissions']*100,1) : 0;
                $avgColor = '#1e3a8a';
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($bt['level']) ?>L</strong></td>
                <td><strong><?= htmlspecialchars($bt['course_code']) ?></strong></td>
                <td><?= htmlspecialchars($bt['test_title']) ?></td>
                <td><?= $bt['submissions'] ?></td>
                <td>
                    <strong style="color:<?= $avgColor ?>"><?= $bt['avg_score'] ?>%</strong>
                    <div class="pbar"><div class="pbar-fill" style="width:<?= min(100,$bt['avg_score']) ?>%"></div></div>
                </td>
                <td style="color:#15803d;font-weight:700"><?= $bt['highest'] ?>%</td>
                <td style="color:#ef4444;font-weight:700"><?= $bt['lowest'] ?>%</td>
                <td><?= $bt['passed'] ?></td>
                <td><span class="badge <?= $pr>=50?'badge-pass':'badge-fail' ?>"><?= $pr ?>%</span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ SECTION 6: Top Students ══ -->
<div class="section-label"><i class="fas fa-trophy" style="color:#1e3a8a"></i> Top Performing Students</div>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-trophy"></i> Top 10 Students by Average Score</div>
        <div class="card-sub">Across all tests &amp; levels</div>
    </div>
    <?php if (!empty($topStudents)): ?>
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr><th>Rank</th><th>Student Name</th><th>Matric No</th><th>Level</th><th>Tests Taken</th><th>Avg Score</th><th>Best Score</th></tr>
            </thead>
            <tbody>
            <?php foreach($topStudents as $i => $ts):
                $rankClass = $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':''));
                $rankIcon  = $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':($i+1)));
            ?>
            <tr>
                <td><span class="<?= $rankClass ?>"><?= $rankIcon ?></span></td>
                <td><strong><?= htmlspecialchars($ts['full_name']) ?></strong></td>
                <td><?= htmlspecialchars($ts['matric']) ?></td>
                <td><?= htmlspecialchars($ts['level']) ?>L</td>
                <td><?= $ts['tests_taken'] ?></td>
                <td>
                    <strong style="color:#1e3a8a"><?= $ts['avg_score'] ?>%</strong>
                    <div class="pbar"><div class="pbar-fill" style="width:<?= min(100,$ts['avg_score']) ?>%"></div></div>
                </td>
                <td style="color:#15803d;font-weight:700"><?= $ts['best_score'] ?>%</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:40px;color:#64748b"><i class="fas fa-users" style="font-size:32px;display:block;margin-bottom:10px;color:#cbd5e0"></i>No submissions yet.</div>
    <?php endif; ?>
</div>

<!-- ══ SECTION 7: Recent Activity ══ -->
<div class="section-label"><i class="fas fa-clock" style="color:#1e3a8a"></i> Recent Submissions</div>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-clock"></i> Latest 15 Submissions</div>
    </div>
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr><th>Date / Time</th><th>Student</th><th>Matric</th><th>Level</th><th>Course</th><th>Score</th><th>%</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach($recentAttempts as $ra):
                $pct = round((float)$ra['percentage'],1);
                $pass = $pct >= $ra['passing_score'];
                $ca   = $ra['total'] > 0 ? round(($ra['score']/$ra['total'])*30,1) : 0;
            ?>
            <tr>
                <td style="color:#64748b;font-size:12px"><?= $ra['end_time'] ? date('d M Y, g:i A', strtotime($ra['end_time'])) : 'N/A' ?></td>
                <td><strong><?= htmlspecialchars($ra['full_name']) ?></strong></td>
                <td><?= htmlspecialchars($ra['student_matric']) ?></td>
                <td><?= htmlspecialchars($ra['level']) ?>L</td>
                <td><strong><?= htmlspecialchars($ra['course_code']) ?></strong></td>
                <td><?= (int)$ra['score'] ?>/<?= (int)$ra['total'] ?> <small style="color:#94a3b8">(<?= $ca ?>/30)</small></td>
                <td><?= $pct ?>%</td>
                <td><span class="badge <?= $pass?'badge-pass':'badge-fail' ?>"><?= $pass?'PASS':'FAIL' ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ Official footer ══ -->
<div class="report-footer">
    <div class="report-footer-left">
        <strong>Prince Abubakar Audu University, Anyigba</strong><br>
        Faculty of Computing — Department of Computer Science<br>
        CA Portal Analytics Report · <?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?>
    </div>
    <div class="report-footer-right">
        Generated: <?= date('d F Y, g:i A') ?><br>
        System-generated · No signature required
    </div>
</div>

</div><!-- /content -->
</main>
</div>

<script>
Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";

// ── Level bar + line chart ──
new Chart(document.getElementById('levelChart'), {
    data: {
        labels: <?= json_encode(array_map(fn($l)=>$l['level'].'L', $byLevel)) ?>,
        datasets: [
            {
                type: 'bar',
                label: 'Avg Score (%)',
                data: <?= json_encode(array_map(fn($l)=>$l['avg_score']??0, $byLevel)) ?>,
                backgroundColor: <?= json_encode(array_map(fn($l)=>
                    ($l['avg_score']??0)>=70?'rgba(16,185,129,0.75)':
                    (($l['avg_score']??0)>=50?'rgba(59,130,246,0.75)':'rgba(239,68,68,0.75)'),
                    $byLevel)) ?>,
                borderColor: <?= json_encode(array_map(fn($l)=>
                    ($l['avg_score']??0)>=70?'#10b981':
                    (($l['avg_score']??0)>=50?'#3b82f6':'#ef4444'),
                    $byLevel)) ?>,
                borderWidth: 2,
                borderRadius: 8,
                yAxisID: 'y',
            },
            {
                type: 'line',
                label: 'Pass Rate (%)',
                data: <?= json_encode(array_map(fn($l)=>
                    $l['submissions']>0?round($l['passed']/$l['submissions']*100,1):0,
                    $byLevel)) ?>,
                borderColor: '#1e3a8a',
                backgroundColor: 'rgba(30,58,138,0.07)',
                borderWidth: 2.5,
                pointBackgroundColor: '#1e3a8a',
                pointRadius: 6,
                tension: 0.35,
                fill: true,
                yAxisID: 'y',
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: { size: 12 } } } },
        scales: {
            y:  { min:0, max:100, ticks:{ callback:v=>v+'%', font:{size:11} }, grid:{color:'rgba(0,0,0,0.05)'} },
            x:  { grid:{display:false}, ticks:{font:{size:12}} }
        }
    }
});

<?php if (!empty($trend)): ?>
// ── Trend line ──
new Chart(document.getElementById('trendChart'), {
    data: {
        labels: <?= json_encode(array_column($trend,'month_label')) ?>,
        datasets: [
            {
                type: 'line',
                label: 'Avg Score (%)',
                data: <?= json_encode(array_column($trend,'avg_score')) ?>,
                borderColor: '#1e3a8a',
                backgroundColor: 'rgba(30,58,138,0.08)',
                borderWidth: 2.5,
                pointBackgroundColor: '#1e3a8a',
                pointRadius: 5,
                tension: 0.4,
                fill: true,
                yAxisID: 'y',
            },
            {
                type: 'bar',
                label: 'Submissions',
                data: <?= json_encode(array_column($trend,'total')) ?>,
                backgroundColor: 'rgba(16,185,129,0.5)',
                borderColor: '#10b981',
                borderWidth: 2,
                borderRadius: 6,
                yAxisID: 'y2',
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode:'index', intersect:false },
        plugins: { legend:{ position:'top', labels:{ usePointStyle:true, font:{size:12} } } },
        scales: {
            y:  { position:'left',  min:0, max:100, ticks:{ callback:v=>v+'%', font:{size:11} }, grid:{color:'rgba(0,0,0,0.05)'} },
            y2: { position:'right', min:0, ticks:{ font:{size:11} }, grid:{drawOnChartArea:false} },
            x:  { grid:{display:false}, ticks:{font:{size:11}} }
        }
    }
});
<?php endif; ?>
</script>
</body>
</html>
