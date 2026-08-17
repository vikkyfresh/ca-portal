<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

$photoSrc = 'https://ui-avatars.com/api/?name='.urlencode($_SESSION['admin_name'] ?? 'Admin').'&background=1e3a8a&color=fff&size=80&bold=true';
$stmtPhoto = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
$stmtPhoto->execute([$_SESSION['admin_id'] ?? 0]);
$photoRow = $stmtPhoto->fetch();
if (!empty($photoRow['photo'])) {
    $sp = dirname(__DIR__) . '/' . ltrim($photoRow['photo'], '/');
    if (file_exists($sp)) $photoSrc = '../' . ltrim($photoRow['photo'], '/');
}

$academicSession = getAcademicSetting('academic_session', '2025/2026');
$currentSemester = getAcademicSetting('current_semester', '2nd Semester');

$totalStudents  = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$totalTests     = (int)$pdo->query("SELECT COUNT(*) FROM tests")->fetchColumn();
$totalAttempts  = (int)$pdo->query("SELECT COUNT(*) FROM attempts WHERE status='completed'")->fetchColumn();
$avgScore       = round((float)($pdo->query("SELECT AVG(percentage) FROM attempts WHERE status='completed'")->fetchColumn() ?? 0), 1);
$enrolledFaces  = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE face_descriptor IS NOT NULL")->fetchColumn();
$totalPass      = (int)$pdo->query("SELECT COUNT(*) FROM attempts a JOIN tests t ON a.test_id=t.id WHERE a.status='completed' AND a.percentage>=t.passing_score")->fetchColumn();
$passRate       = $totalAttempts > 0 ? round($totalPass/$totalAttempts*100,1) : 0;

// Per-level summary
$byLevel = $pdo->query("
    SELECT s.level,
           COUNT(DISTINCT s.matric) AS students,
           COUNT(a.id) AS submissions,
           ROUND(AVG(a.percentage),1) AS avg_score,
           SUM(CASE WHEN a.percentage >= t.passing_score THEN 1 ELSE 0 END) AS passed
    FROM students s
    LEFT JOIN attempts a ON s.matric=a.student_matric AND a.status='completed'
    LEFT JOIN tests t ON a.test_id=t.id
    GROUP BY s.level ORDER BY s.level ASC
")->fetchAll();

// Recent results
$recentResults = $pdo->query("
    SELECT a.student_matric, s.full_name, s.level, t.course_code, t.passing_score,
           a.percentage, a.score, a.total, a.end_time
    FROM attempts a
    JOIN students s ON a.student_matric=s.matric
    JOIN tests t ON a.test_id=t.id
    WHERE a.status='completed'
    ORDER BY a.end_time DESC LIMIT 20
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — Admin Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9}
.layout{display:flex;min-height:100vh}
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
.nav a i{width:18px;text-align:center;font-size:.9rem}
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
.main{flex:1;margin-left:260px}
.topbar{background:white;padding:0 28px;border-bottom:1px solid #e2e8f0;height:64px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.topbar h1{font-size:1.25rem;font-weight:700;color:#0f172a}
.topbar p{font-size:12px;color:#64748b;margin-top:1px}
.print-btn{padding:8px 16px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px}
.print-btn:hover{opacity:.88}
.content{padding:24px 28px 48px}
.section-label{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;margin-bottom:12px;margin-top:4px;display:flex;align-items:center;gap:8px}
.section-label::after{content:'';flex:1;height:1px;background:#e2e8f0}

/* KPIs */
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.kpi{background:white;border-radius:14px;padding:18px 16px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-top:3px solid #1e3a8a}
.kpi.green{border-color:#10b981} .kpi.amber{border-color:#f59e0b} .kpi.purple{border-color:#8b5cf6} .kpi.teal{border-color:#14b8a6} .kpi.red{border-color:#ef4444}
.kpi-val{font-size:1.9rem;font-weight:800;color:#0f172a;line-height:1}
.kpi-lbl{font-size:11px;color:#64748b;margin-top:5px}

/* Level table */
.card{background:white;border-radius:16px;padding:22px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:20px}
.card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #f1f5f9}
.card-title{font-size:.975rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px}
.card-title i{color:#1e3a8a}
.card-sub{font-size:12px;color:#64748b}
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
thead th{padding:10px 14px;background:#0f172a;color:white;text-align:left;font-weight:600;font-size:11.5px;letter-spacing:.02em;white-space:nowrap}
tbody td{padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle}
tbody tr:hover td{background:#f8fafc}
tbody tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700}
.badge-pass{background:#dcfce7;color:#15803d} .badge-fail{background:#fee2e2;color:#991b1b}
.pbar{background:#e2e8f0;border-radius:4px;height:7px;overflow:hidden;margin-top:4px}
.pbar-fill{height:100%;border-radius:4px;background:linear-gradient(to right,#1e3a8a,#10b981)}

.report-footer{background:white;border-radius:16px;padding:20px 28px;box-shadow:0 1px 4px rgba(0,0,0,.07);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-top:8px;border-top:3px solid #1e3a8a}
.report-footer-left{font-size:13px;color:#475569;line-height:1.7}
.report-footer-right{font-size:12px;color:#94a3b8;text-align:right}

@media print{.sidebar,.topbar,.print-btn{display:none !important}.main{margin-left:0}.content{padding:0}body{background:white}.card{box-shadow:none;border:1px solid #e2e8f0}thead th{background:#1e3a8a !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}

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
    /* → includes/sidebar.php */
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
<?php $activePage='reports'; require_once __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
<div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
    <button class="menu-toggle" onclick="document.getElementById(\'sidebar\').classList.toggle(\'open\')"><i class="fas fa-bars"></i></button>
    <div>
        <h1><i class="fas fa-file-lines" style="color:#1e3a8a;margin-right:8px"></i>Reports</h1>
        <p><?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?> · Generated: <?= date('d M Y, g:i A') ?></p>
    </div>
    <a href="dashboard.php" style="padding:8px 16px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-arrow-left"></i> Dashboard</a>
    <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
</div>

<div class="content">

<!-- KPIs -->
<div class="section-label"><i class="fas fa-circle-info" style="color:#1e3a8a"></i> System Summary</div>
<div class="kpi-row">
    <div class="kpi"><div class="kpi-val"><?= $totalStudents ?></div><div class="kpi-lbl">Total Students</div></div>
    <div class="kpi green"><div class="kpi-val"><?= $totalTests ?></div><div class="kpi-lbl">Total Tests</div></div>
    <div class="kpi amber"><div class="kpi-val"><?= $totalAttempts ?></div><div class="kpi-lbl">Completed Attempts</div></div>
    <div class="kpi purple"><div class="kpi-val"><?= $avgScore ?>%</div><div class="kpi-lbl">Overall Avg Score</div></div>
    <div class="kpi teal"><div class="kpi-val"><?= $passRate ?>%</div><div class="kpi-lbl">Overall Pass Rate</div></div>
    <div class="kpi red"><div class="kpi-val"><?= $enrolledFaces ?>/<?= $totalStudents ?></div><div class="kpi-lbl">Face ID Enrolled</div></div>
</div>

<!-- Level Summary -->
<div class="section-label"><i class="fas fa-layer-group" style="color:#1e3a8a"></i> Summary by Level</div>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-layer-group"></i> Performance by Level (100L – 400L)</div>
        <div class="card-sub"><?= htmlspecialchars($academicSession) ?></div>
    </div>
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>Level</th><th>Students</th><th>Submissions</th><th>Avg Score</th><th>Passed</th><th>Pass Rate</th></tr></thead>
            <tbody>
            <?php foreach($byLevel as $lv):
                $pr = $lv['submissions']>0 ? round($lv['passed']/$lv['submissions']*100,1) : 0;
                $col = '#1e3a8a';
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($lv['level']) ?>L</strong></td>
                <td><?= $lv['students'] ?></td>
                <td><?= $lv['submissions'] ?></td>
                <td>
                    <strong style="color:<?= $col ?>"><?= $lv['avg_score'] ?? 0 ?>%</strong>
                    <div class="pbar"><div class="pbar-fill" style="width:<?= min(100,$lv['avg_score']??0) ?>%"></div></div>
                </td>
                <td><?= $lv['passed'] ?? 0 ?></td>
                <td><span class="badge <?= $pr>=50?'badge-pass':'badge-fail' ?>"><?= $pr ?>%</span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Results -->
<div class="section-label"><i class="fas fa-clock" style="color:#1e3a8a"></i> Recent Submissions</div>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-clock"></i> Latest 20 Results</div>
        <div class="card-sub">Most recent first</div>
    </div>
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>Date</th><th>Student</th><th>Matric</th><th>Level</th><th>Course</th><th>Score</th><th>CA (/30)</th><th>%</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach($recentResults as $r):
                $pct = round((float)$r['percentage'],1);
                $pass = $pct >= $r['passing_score'];
                $ca   = $r['total']>0 ? round(($r['score']/$r['total'])*30,1) : 0;
            ?>
            <tr>
                <td style="color:#64748b;font-size:12px"><?= $r['end_time']?date('d M Y, H:i',strtotime($r['end_time'])):'N/A' ?></td>
                <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                <td><?= htmlspecialchars($r['student_matric']) ?></td>
                <td><?= htmlspecialchars($r['level']) ?>L</td>
                <td><strong><?= htmlspecialchars($r['course_code']) ?></strong></td>
                <td><?= (int)$r['score'] ?>/<?= (int)$r['total'] ?></td>
                <td><strong style="color:#1e3a8a"><?= $ca ?></strong>/30</td>
                <td><?= $pct ?>%</td>
                <td><span class="badge <?= $pass?'badge-pass':'badge-fail' ?>"><?= $pass?'PASS':'FAIL' ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Footer -->
<div class="report-footer">
    <div class="report-footer-left">
        <strong>Prince Abubakar Audu University, Anyigba</strong><br>
        Faculty of Computing — Department of Computer Science<br>
        CA Portal Reports · <?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?>
    </div>
    <div class="report-footer-right">
        Generated: <?= date('d F Y, g:i A') ?><br>
        System-generated · No signature required
    </div>
</div>

</div>
</main>
</div>
</body>
</html>
