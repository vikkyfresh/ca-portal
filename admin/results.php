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
$adminId = (int)$_SESSION['admin_id'];

// Selected filters
$selectedLevel  = $_GET['level']  ?? '';
$selectedTest   = (int)($_GET['test_id'] ?? 0);

// All tests (for dropdown)
$tests = $pdo->query("SELECT id, course_code, test_title, level FROM tests ORDER BY level ASC, course_code ASC")->fetchAll();

// Stats per level
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

// Selected test results
$testInfo = null;
$results  = [];
$stats    = [];

if ($selectedTest) {
    $testInfo = $pdo->prepare("SELECT * FROM tests WHERE id = ?")->execute([$selectedTest]) ? null : null;
    $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?");
    $stmt->execute([$selectedTest]);
    $testInfo = $stmt->fetch();

    if ($testInfo) {
        $q = "SELECT a.*, s.full_name, s.matric, s.level AS student_level, s.email
              FROM attempts a
              JOIN students s ON a.student_matric = s.matric
              WHERE a.test_id = ? AND a.status = 'completed'
              ORDER BY a.percentage DESC";
        $stmt = $pdo->prepare($q);
        $stmt->execute([$selectedTest]);
        $results = $stmt->fetchAll();

        // Proctoring flags map for this test
        $procMapAdmin = [];
        if (!empty($results)) {
            $pMatricsAdmin = array_column($results, 'matric');
            $inA = implode(',', array_fill(0, count($pMatricsAdmin), '?'));
            $pStmtA = $pdo->prepare("
                SELECT student_matric,
                       COUNT(*) AS total_flags,
                       SUM(event_type='face_out')        AS face_out,
                       SUM(event_type='eyes_closed')     AS eyes_closed,
                       SUM(event_type='eyes_away')       AS eyes_away,
                       SUM(event_type='tab_switch')      AS tab_switch,
                       SUM(event_type='fullscreen_exit') AS fullscreen_exit
                FROM proctoring_logs
                WHERE test_id = ? AND student_matric IN ($inA)
                GROUP BY student_matric
            ");
            $pStmtA->execute(array_merge([$selectedTest], $pMatricsAdmin));
            foreach ($pStmtA->fetchAll() as $pr) {
                $procMapAdmin[$pr['student_matric']] = $pr;
            }
        }

        $scores = array_column($results, 'percentage');
        $passCount = 0;
        foreach ($scores as $s) {
            $s >= $testInfo['passing_score'] && $passCount++;
        }
        $stats = [
            'total'     => count($scores),
            'pass'      => $passCount,
            'fail'      => count($scores) - $passCount,
            'avg'       => count($scores) ? round(array_sum($scores)/count($scores),1) : 0,
            'highest'   => count($scores) ? round(max($scores),1) : 0,
            'lowest'    => count($scores) ? round(min($scores),1) : 0,
            'pass_rate' => count($scores) ? round($passCount/count($scores)*100,1) : 0,
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Results — Admin Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9}
.layout{display:flex;min-height:100vh}
/* ── Sidebar ── */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php *//* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php *//* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        .nav a i{width:17px;text-align:center;font-size:.85rem}
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* ── Main ── */
        .main{flex:1;margin-left:260px;min-width:0}
        .content{padding:22px 24px 48px}
.topbar{background:white;padding:0 28px;border-bottom:1px solid #e2e8f0;height:64px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.topbar-left h1{font-size:1.2rem;font-weight:700;color:#0f172a}
.topbar-left p{font-size:12px;color:#64748b}
.print-btn{padding:8px 16px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px}
.content{padding:22px 24px 48px}
.section-label{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.section-label::after{content:'';flex:1;height:1px;background:#e2e8f0}

/* Level cards */
.level-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.level-card{background:white;border-radius:14px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.07);text-align:center;border-top:4px solid #1e3a8a;transition:transform .2s}
.level-card:hover{transform:translateY(-2px)}
.level-badge{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white;border-radius:12px;font-size:1rem;font-weight:800;margin-bottom:10px}
.level-avg{font-size:1.8rem;font-weight:800;color:#0f172a;line-height:1}
.level-lbl{font-size:11px;color:#64748b;margin-bottom:10px}
.level-stats{display:flex;justify-content:space-around;font-size:11px;color:#475569;padding-top:10px;border-top:1px solid #f1f5f9}
.level-stats span{display:flex;flex-direction:column;align-items:center;gap:2px}
.level-stats strong{font-size:14px;font-weight:700;color:#0f172a}
.pbar{background:#e2e8f0;border-radius:4px;height:6px;overflow:hidden;margin-top:6px}
.pbar-fill{height:100%;border-radius:4px;background:linear-gradient(to right,#1e3a8a,#10b981)}

/* Selector */
.selector-card{background:linear-gradient(135deg,#0f172a,#1e3a8a);border-radius:16px;padding:20px 24px;margin-bottom:20px}
.selector-card label{color:rgba(255,255,255,.85);font-size:13px;font-weight:500;display:block;margin-bottom:8px}
.selector-card select{width:100%;max-width:500px;padding:11px 16px;border:none;border-radius:10px;font-size:.95rem;background:white;color:#0f172a;font-weight:500;cursor:pointer}

/* Card */
.card{background:white;border-radius:16px;padding:22px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:20px}
.card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f1f5f9}
.card-title{font-size:.975rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px}
.card-title i{color:#1e3a8a}
.card-sub{font-size:12px;color:#64748b}

/* KPI mini row */
.kpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px}
.kpi{background:white;border-radius:12px;padding:16px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid #1e3a8a;text-align:center}
.kpi.green{border-color:#10b981}.kpi.amber{border-color:#f59e0b}.kpi.red{border-color:#ef4444}.kpi.purple{border-color:#8b5cf6}
.kpi-val{font-size:1.7rem;font-weight:800;color:#0f172a;line-height:1}
.kpi-lbl{font-size:11px;color:#64748b;margin-top:4px}

/* Export row */
.export-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding-top:14px;border-top:1px solid #f1f5f9}
.exp-lbl{font-size:12px;font-weight:600;color:#64748b}
.exp-btn{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;border:none;transition:all .2s}
.exp-primary{background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white}
.exp-primary:hover{opacity:.88;transform:translateY(-1px)}
.exp-outline{background:white;color:#1e3a8a;border:1.5px solid #1e3a8a}
.exp-outline:hover{background:#1e3a8a;color:white}

/* Table */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
thead th{padding:10px 14px;background:#0f172a;color:white;text-align:left;font-weight:600;font-size:11.5px;white-space:nowrap}
tbody td{padding:9px 14px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle}
tbody tr:hover td{background:#f8fafc}
tbody tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700}
.badge-pass{background:#dcfce7;color:#15803d}.badge-fail{background:#fee2e2;color:#991b1b}

.report-footer{background:white;border-radius:16px;padding:20px 28px;box-shadow:0 1px 4px rgba(0,0,0,.07);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;border-top:3px solid #1e3a8a}
.report-footer-left{font-size:13px;color:#475569;line-height:1.7}
.report-footer-right{font-size:12px;color:#94a3b8;text-align:right}
.empty{text-align:center;padding:56px 20px;color:#64748b}
.empty i{font-size:40px;display:block;margin-bottom:12px;color:#cbd5e0}

@media print{.sidebar,.topbar,.print-btn,.selector-card,.export-row{display:none!important}.main{margin-left:0}.content{padding:0}body{background:white}.card,.level-card{box-shadow:none;border:1px solid #e2e8f0}thead th{background:#1e3a8a!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}

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
<?php $activePage='results'; require_once __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
<div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
    <button class="menu-toggle" onclick="document.getElementById(\'sidebar\').classList.toggle(\'open\')"><i class="fas fa-bars"></i></button>
    <div class="topbar-left">
        <h1><i class="fas fa-chart-bar" style="color:#1e3a8a;margin-right:8px"></i>All Results</h1>
        <p><?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?></p>
    </div>
    <a href="dashboard.php" style="padding:8px 16px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;"><i class="fas fa-arrow-left"></i> Dashboard</a>
    <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
</div>

<div class="content">

<!-- Level overview cards -->
<div class="section-label"><i class="fas fa-layer-group" style="color:#1e3a8a"></i> Performance by Level</div>
<div class="level-grid">
<?php foreach($byLevel as $lv):
    $pr  = $lv['submissions']>0 ? round($lv['passed']/$lv['submissions']*100,1) : 0;
    $avg = $lv['avg_score'] ?? 0;
    $col = '#1e3a8a';
?>
<div class="level-card" style="border-top-color:<?= $col ?>">
    <div class="level-badge"><?= htmlspecialchars($lv['level']) ?>L</div>
    <div class="level-avg" style="color:#1e3a8a"><?= $avg ?>%</div>
    <div class="level-lbl">Average Score</div>
    <div class="pbar"><div class="pbar-fill" style="width:<?= min(100,$avg) ?>%;background:#1e3a8a"></div></div>
    <div class="level-stats" style="margin-top:12px">
        <span><strong><?= $lv['students'] ?></strong>Students</span>
        <span><strong><?= $lv['submissions'] ?></strong>Submitted</span>
        <span><strong><?= $pr ?>%</strong>Pass</span>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Test selector -->
<div class="selector-card">
    <label><i class="fas fa-filter"></i> &nbsp;View results for a specific test:</label>
    <form method="get">
        <select name="test_id" onchange="this.form.submit()">
            <option value="">— Select a test —</option>
            <?php foreach($tests as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $selectedTest==$t['id']?'selected':'' ?>>
                <?= htmlspecialchars($t['level']) ?>L · <?= htmlspecialchars($t['course_code']) ?> — <?= htmlspecialchars($t['test_title']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($testInfo && !empty($stats)): ?>

<!-- KPI row -->
<div class="kpi-row">
    <div class="kpi"><div class="kpi-val"><?= $stats['total'] ?></div><div class="kpi-lbl">Submissions</div></div>
    <div class="kpi green"><div class="kpi-val"><?= $stats['pass'] ?></div><div class="kpi-lbl">Passed</div></div>
    <div class="kpi red"><div class="kpi-val"><?= $stats['fail'] ?></div><div class="kpi-lbl">Failed</div></div>
    <div class="kpi amber"><div class="kpi-val"><?= $stats['avg'] ?>%</div><div class="kpi-lbl">Average</div></div>
    <div class="kpi purple"><div class="kpi-val"><?= $stats['pass_rate'] ?>%</div><div class="kpi-lbl">Pass Rate</div></div>
</div>

<!-- Test info + pass/fail dist -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-info-circle"></i> <?= htmlspecialchars($testInfo['course_code']) ?> — <?= htmlspecialchars($testInfo['test_title']) ?></div>
        <div class="card-sub">Level <?= $testInfo['level'] ?>L · Pass Mark: <?= $testInfo['passing_score'] ?>% · <?= $testInfo['total_questions'] ?> Questions</div>
    </div>
    <div style="position:relative;height:180px">
        <canvas id="passFailChart"></canvas>
    </div>
    <div class="export-row">
        <span class="exp-lbl"><i class="fas fa-download"></i> Export:</span>
        <a href="../lecturer/api/export-results.php?test_id=<?= $selectedTest ?>&format=excel" class="exp-btn exp-primary"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="../lecturer/api/export-results.php?test_id=<?= $selectedTest ?>&format=csv" class="exp-btn exp-outline"><i class="fas fa-file-csv"></i> CSV</a>
        <a href="../lecturer/api/export-results.php?test_id=<?= $selectedTest ?>&format=pdf" class="exp-btn exp-outline" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        <button onclick="window.print()" class="exp-btn exp-outline"><i class="fas fa-print"></i> Print</button>
    </div>
</div>

<!-- Student table -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-users"></i> Student Performance — <?= htmlspecialchars($testInfo['course_code']) ?></div>
        <div class="card-sub"><?= $stats['total'] ?> student<?= $stats['total']!==1?'s':'' ?></div>
    </div>
    <?php if (!empty($results)): ?>
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>Matric No</th><th>Student Name</th><th>Level</th><th>Raw Score</th><th>CA (/30)</th><th>%</th><th>🔍 Flags</th><th>Status</th><th>Date</th></tr>
            </thead>
            <tbody>
            <?php $sn=1; foreach($results as $r):
                $pct  = round((float)$r['percentage'],1);
                $pass = $pct >= $testInfo['passing_score'];
                $ca   = $r['total']>0 ? round(($r['score']/$r['total'])*30,1) : 0;
                $date = !empty($r['end_time']) ? date('d M Y',strtotime($r['end_time'])) : 'N/A';
            ?>
            <tr>
                <td><?= $sn++ ?></td>
                <td><strong><?= htmlspecialchars($r['matric']) ?></strong></td>
                <td><?= htmlspecialchars($r['full_name']) ?></td>
                <td><?= htmlspecialchars($r['student_level']) ?>L</td>
                <td><?= (int)$r['score'] ?>/<?= (int)$r['total'] ?></td>
                <td><strong style="color:#1e3a8a"><?= $ca ?></strong>/30</td>
                <td><?= $pct ?>%</td>
                <td>
                    <?php $procA = $procMapAdmin[$r['matric']] ?? null; ?>
                    <?php if ($procA && $procA['total_flags'] > 0): ?>
                    <a href="../lecturer/proctoring-detail.php?test_id=<?= $selectedTest ?>&matric=<?= urlencode($r['matric']) ?>"
                       title="Face out: <?= $procA['face_out'] ?> | Eyes closed: <?= $procA['eyes_closed'] ?> | Tab switch: <?= $procA['tab_switch'] ?> | Fullscreen exit: <?= $procA['fullscreen_exit'] ?>"
                       style="display:inline-flex;align-items:center;gap:4px;background:<?= $procA['total_flags']>=3?'#dc2626':'#d97706' ?>;color:white;padding:3px 9px;border-radius:20px;font-size:.74rem;font-weight:700;text-decoration:none;">
                        ⚠️ <?= $procA['total_flags'] ?> <i class="fas fa-external-link-alt" style="font-size:.6rem"></i>
                    </a>
                    <?php else: ?>
                    <span style="color:#10b981;font-size:.77rem;font-weight:600;">✅ Clean</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= $pass?'badge-pass':'badge-fail' ?>"><?= $pass?'PASS':'FAIL' ?></span></td>
                <td style="color:#64748b;font-size:12px"><?= $date ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty"><i class="fas fa-inbox"></i><p>No submissions yet for this test.</p></div>
    <?php endif; ?>
</div>

<?php elseif($selectedTest): ?>
<div class="card"><div class="empty"><i class="fas fa-exclamation-circle"></i><p>Test not found.</p></div></div>
<?php endif; ?>

<div class="report-footer">
    <div class="report-footer-left"><strong>Prince Abubakar Audu University, Anyigba</strong><br>Faculty of Computing — Department of Computer Science<br>CA Portal · <?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?></div>
    <div class="report-footer-right">Generated: <?= date('d F Y, g:i A') ?><br>System-generated · No signature required</div>
</div>

</div>
</main>
</div>

<script>
<?php if (!empty($stats)): ?>
new Chart(document.getElementById('passFailChart'),{
    type:'doughnut',
    data:{
        labels:['Pass','Fail'],
        datasets:[{
            data:[<?= (int)$stats['pass'] ?>,<?= (int)$stats['fail'] ?>],
            backgroundColor:['#10b981','#ef4444'],
            borderWidth:3,borderColor:'#fff',
        }]
    },
    options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'bottom',labels:{font:{size:11},usePointStyle:true,padding:10}}}}
});
<?php endif; ?>
</script>
</body>
</html>
