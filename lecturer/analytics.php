<?php
session_start();
if (!isset($_SESSION['lecturer_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

// Lecturer photo
$photoSrc = 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['lecturer_name'] ?? 'Lecturer') . '&background=1e3a8a&color=fff&size=80&bold=true';
if (!empty($_SESSION['lecturer_id'])) {
    $stmtPhoto = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
    $stmtPhoto->execute([$_SESSION['lecturer_id']]);
    $photoRow = $stmtPhoto->fetch();
    if (!empty($photoRow['photo'])) {
        $sp = dirname(__DIR__) . '/' . ltrim($photoRow['photo'], '/');
        if (file_exists($sp)) $photoSrc = '../' . ltrim($photoRow['photo'], '/');
    }
}
$lecturerAvatarUrl = $photoSrc;
$avatarUrl = $photoSrc;


$lecturerId   = (int)$_SESSION['lecturer_id'];
$lecturerName = $_SESSION['lecturer_name'] ?? 'Lecturer';
$selectedTest = intval($_GET['test'] ?? 0);

$academicSession = getAcademicSetting('academic_session', '2025/2026');
$currentSemester = getAcademicSetting('current_semester', '2nd Semester');

// All tests by this lecturer
$stmt = $pdo->prepare("SELECT id, test_title, course_code, passing_score, total_questions, level FROM tests WHERE created_by = ? ORDER BY created_at DESC");
$stmt->execute([$lecturerId]);
$tests = $stmt->fetchAll();

// Overall stats for this lecturer
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT a.id) as total_attempts,
           COUNT(DISTINCT a.student_matric) as unique_students,
           ROUND(AVG(a.percentage),1) as overall_avg,
           SUM(CASE WHEN a.percentage >= t.passing_score THEN 1 ELSE 0 END) as total_pass
    FROM attempts a
    JOIN tests t ON a.test_id = t.id
    WHERE t.created_by = ? AND a.status = 'completed'
");
$stmt->execute([$lecturerId]);
$overallStats = $stmt->fetch();

// Per-test breakdown for bar chart
$stmt = $pdo->prepare("
    SELECT t.course_code, t.test_title, t.level, t.passing_score,
           COUNT(a.id) as submissions,
           ROUND(AVG(a.percentage),1) as avg_score,
           ROUND(MAX(a.percentage),1) as highest,
           ROUND(MIN(a.percentage),1) as lowest,
           SUM(CASE WHEN a.percentage >= t.passing_score THEN 1 ELSE 0 END) as passed
    FROM tests t
    LEFT JOIN attempts a ON t.id = a.test_id AND a.status = 'completed'
    WHERE t.created_by = ?
    GROUP BY t.id
    ORDER BY t.created_at DESC
");
$stmt->execute([$lecturerId]);
$testBreakdown = $stmt->fetchAll();

// Selected test detail
$testData = null;
if ($selectedTest) {
    $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND created_by = ?");
    $stmt->execute([$selectedTest, $lecturerId]);
    $testInfo = $stmt->fetch();

    if ($testInfo) {
        $stmt = $pdo->prepare("
            SELECT a.percentage, a.score, a.total, a.time_spent_seconds,
                   s.full_name, s.matric, s.level AS student_level, a.end_time
            FROM attempts a
            JOIN students s ON a.student_matric = s.matric
            WHERE a.test_id = ? AND a.status = 'completed'
            ORDER BY a.percentage DESC
        ");
        $stmt->execute([$selectedTest]);
        $attempts = $stmt->fetchAll();

        $scores = array_column($attempts, 'percentage');
        $dist   = ['90-100'=>0,'70-89'=>0,'50-69'=>0,'<50'=>0];
        foreach ($scores as $s) {
            if ($s >= 90)      $dist['90-100']++;
            elseif ($s >= 70)  $dist['70-89']++;
            elseif ($s >= 50)  $dist['50-69']++;
            else               $dist['<50']++;
        }

        // Pass count against this test's actual passing_score (not a fixed
        // 50% cutoff — different tests can have different pass marks).
        $passMarkForTest = (float)($testInfo['passing_score'] ?? 50);
        $passCount = 0;
        foreach ($scores as $s) { if ($s >= $passMarkForTest) $passCount++; }

        $testData = [
            'info'      => $testInfo,
            'attempts'  => $attempts,
            'total'     => count($scores),
            'avg'       => count($scores) ? round(array_sum($scores)/count($scores),1) : 0,
            'highest'   => count($scores) ? round(max($scores),1) : 0,
            'lowest'    => count($scores) ? round(min($scores),1) : 0,
            'passCount' => $passCount,
            'passRate'  => count($scores) ? round($passCount/count($scores)*100,1) : 0,
            'dist'      => $dist,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics — Lecturer Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9}
.layout{display:flex;min-height:100vh}
/* sidebar CSS → includes/sidebar.php */
/* sidebar CSS → includes/sidebar.php */
/* .nav defined in includes/sidebar.php */
/* .nav a defined in includes/sidebar.php */

.main{flex:1;margin-left:260px}
.topbar{background:white;padding:16px 28px;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;display:flex;justify-content:space-between;align-items:center}
.topbar h1{font-size:1.3rem;color:#0f172a;font-weight:700}
.topbar-sub{font-size:12px;color:#64748b}
.content{padding:24px 28px}

/* Summary stats */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.kpi{background:white;border-radius:14px;padding:18px 20px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid #1e3a8a}
.kpi.green{border-color:#10b981} .kpi.amber{border-color:#f59e0b} .kpi.red{border-color:#ef4444}
.kpi-val{font-size:2rem;font-weight:800;color:#0f172a;line-height:1}
.kpi-lbl{font-size:12px;color:#64748b;margin-top:5px}

/* Cards */
.card{background:white;border-radius:16px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:20px}
.card-title{font-size:1rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid #f1f5f9}
.card-title i{color:#1e3a8a}

/* Test selector */
.selector-card{background:linear-gradient(135deg,#0f172a,#1e3a8a);border-radius:16px;padding:20px 24px;margin-bottom:20px;box-shadow:0 4px 16px rgba(15,23,42,.25)}
.selector-card label{color:rgba(255,255,255,.85);font-size:13px;font-weight:500;display:block;margin-bottom:8px}
.selector-card select{width:100%;max-width:500px;padding:11px 16px;border:none;border-radius:10px;font-size:.95rem;background:white;color:#0f172a;font-weight:500;cursor:pointer}

/* Two-col grid */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}

/* Table */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
thead th{padding:10px 14px;background:#0f172a;color:white;text-align:left;font-weight:600;font-size:12px;white-space:nowrap}
tbody td{padding:9px 14px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle}
tbody tr:hover td{background:#f8fafc}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700}
.badge-pass{background:#dcfce7;color:#15803d} .badge-fail{background:#fee2e2;color:#991b1b}
.gp{display:inline-block;width:26px;height:26px;border-radius:50%;text-align:center;line-height:26px;font-weight:800;font-size:12px}

/* Progress bar */
.pbar{background:#e2e8f0;border-radius:4px;height:8px;overflow:hidden;margin-top:4px}
.pbar-fill{height:100%;border-radius:4px;background:linear-gradient(to right,#1e3a8a,#10b981)}

/* Empty */
.empty{text-align:center;padding:56px 20px;color:#64748b}
.empty i{font-size:40px;display:block;margin-bottom:12px;color:#cbd5e0}

.main{margin-left:0}.kpi-grid{grid-template-columns:1fr 1fr}.grid-2,.grid-3{grid-template-columns:1fr}.content{padding:16px}}

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
<?php $activePage='analytics'; require_once __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
<div class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
        <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
        <div>
            <h1><i class="fas fa-chart-pie" style="color:#1e3a8a;margin-right:8px"></i>Analytics</h1>
            <div class="topbar-sub"><?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?></div>
        </div>
    </div>
    <a href="dashboard.php" style="padding:8px 16px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
</div>

<div class="content">

<!-- Overall KPIs -->
<div class="kpi-grid">
    <div class="kpi">
        <div class="kpi-val"><?= count($tests) ?></div>
        <div class="kpi-lbl">Tests Created</div>
    </div>
    <div class="kpi green">
        <div class="kpi-val"><?= $overallStats['unique_students'] ?? 0 ?></div>
        <div class="kpi-lbl">Unique Students</div>
    </div>
    <div class="kpi amber">
        <div class="kpi-val"><?= $overallStats['overall_avg'] ?? 0 ?>%</div>
        <div class="kpi-lbl">Overall Avg Score</div>
    </div>
    <div class="kpi red">
        <div class="kpi-val"><?= $overallStats['total_attempts'] ?? 0 ?></div>
        <div class="kpi-lbl">Total Submissions</div>
    </div>
</div>

<!-- All-tests performance chart -->
<?php if (!empty($testBreakdown)): ?>
<div class="card">
    <div class="card-title"><i class="fas fa-chart-bar"></i> Performance Across All My Tests</div>
    <div style="position:relative;height:260px">
        <canvas id="allTestsChart"></canvas>
    </div>
</div>

<!-- Per-test breakdown table -->
<div class="card">
    <div class="card-title"><i class="fas fa-table"></i> Test-by-Test Summary</div>
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>Course</th><th>Test Title</th><th>Level</th>
                    <th>Submissions</th><th>Avg %</th><th>Highest</th><th>Lowest</th>
                    <th>Passed</th><th>Pass Rate</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($testBreakdown as $tb):
                $pr = $tb['submissions'] > 0 ? round($tb['passed']/$tb['submissions']*100,1) : 0;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($tb['course_code']) ?></strong></td>
                <td><?= htmlspecialchars($tb['test_title']) ?></td>
                <td><?= htmlspecialchars($tb['level'] ?? 'N/A') ?></td>
                <td><?= $tb['submissions'] ?></td>
                <td>
                    <strong style="color:#1e3a8a"><?= $tb['avg_score'] ?>%</strong>
                    <div class="pbar"><div class="pbar-fill" style="width:<?= min(100,$tb['avg_score']) ?>%"></div></div>
                </td>
                <td style="color:#15803d;font-weight:700"><?= $tb['highest'] ?>%</td>
                <td style="color:#ef4444;font-weight:700"><?= $tb['lowest'] ?>%</td>
                <td><?= $tb['passed'] ?></td>
                <td><span class="badge <?= $pr>=50?'badge-pass':'badge-fail' ?>"><?= $pr ?>%</span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Test selector for deep dive -->
<div class="selector-card">
    <label><i class="fas fa-search"></i> &nbsp;Deep dive into a specific test:</label>
    <form method="get" action="">
        <select name="test" onchange="this.form.submit()">
            <option value="">— Select a test —</option>
            <?php foreach($tests as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $selectedTest==$t['id']?'selected':'' ?>>
                <?= htmlspecialchars($t['course_code']) ?> · <?= htmlspecialchars($t['test_title']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($testData): ?>

<!-- Deep dive stats -->
<div class="kpi-grid">
    <div class="kpi"><div class="kpi-val"><?= $testData['total'] ?></div><div class="kpi-lbl">Submissions</div></div>
    <div class="kpi green"><div class="kpi-val"><?= $testData['avg'] ?>%</div><div class="kpi-lbl">Average Score</div></div>
    <div class="kpi amber"><div class="kpi-val"><?= $testData['highest'] ?>%</div><div class="kpi-lbl">Highest Score</div></div>
    <div class="kpi red"><div class="kpi-val"><?= $testData['lowest'] ?>%</div><div class="kpi-lbl">Lowest Score</div></div>
</div>

<!-- Score band distribution -->
<div class="card">
    <div class="card-title"><i class="fas fa-chart-pie"></i> Score Bands & Pass Rate</div>
    <div style="position:relative;height:240px">
        <canvas id="distChart"></canvas>
    </div>
    <div style="margin-top:16px;text-align:center">
        <span style="background:#dcfce7;color:#15803d;font-weight:700;padding:6px 16px;border-radius:20px;font-size:14px;display:inline-block">
            Pass Rate: <?= $testData['passRate'] ?>% (<?= $testData['passCount'] ?>/<?= $testData['total'] ?>)
        </span>
    </div>
</div>

<!-- Student detail table -->
<div class="card">
    <div class="card-title"><i class="fas fa-users"></i> Individual Student Results — <?= htmlspecialchars($testData['info']['course_code']) ?></div>
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>Matric</th><th>Student</th><th>Level</th><th>Score</th><th>CA (/30)</th><th>%</th><th>Time</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php $sn=1; foreach($testData['attempts'] as $a):
                $pct   = round((float)$a['percentage'],1);
                $ca    = $a['total']>0 ? round(($a['score']/$a['total'])*30,1) : 0;
                $mins  = $a['time_spent_seconds']>0 ? round($a['time_spent_seconds']/60,1).'m' : 'N/A';
                $pass  = $pct >= $testData['info']['passing_score'];
            ?>
            <tr>
                <td><?= $sn++ ?></td>
                <td><strong><?= htmlspecialchars($a['matric']) ?></strong></td>
                <td><?= htmlspecialchars($a['full_name']) ?></td>
                <td><?= htmlspecialchars($a['student_level']) ?>L</td>
                <td><?= (int)$a['score'] ?>/<?= (int)$a['total'] ?></td>
                <td><strong style="color:#1e3a8a"><?= $ca ?></strong></td>
                <td><?= $pct ?>%</td>
                <td><?= $mins ?></td>
                <td><span class="badge <?= $pass?'badge-pass':'badge-fail' ?>"><?= $pass?'PASS':'FAIL' ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif($selectedTest): ?>
<div class="card"><div class="empty"><i class="fas fa-inbox"></i><p>No submissions yet for this test.</p></div></div>
<?php endif; ?>

</div><!-- /content -->
</main>
</div>

<script>
<?php if (!empty($testBreakdown)): ?>
// All-tests bar chart
new Chart(document.getElementById('allTestsChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($t)=>$t['course_code'], $testBreakdown)) ?>,
        datasets: [
            {
                label: 'Avg Score (%)',
                data: <?= json_encode(array_map(fn($t)=>$t['avg_score'], $testBreakdown)) ?>,
                backgroundColor: <?= json_encode(array_map(fn($t)=> $t['avg_score']>=70?'rgba(16,185,129,0.75)':($t['avg_score']>=50?'rgba(59,130,246,0.75)':'rgba(239,68,68,0.75)'), $testBreakdown)) ?>,
                borderColor: <?= json_encode(array_map(fn($t)=> $t['avg_score']>=70?'#10b981':($t['avg_score']>=50?'#3b82f6':'#ef4444'), $testBreakdown)) ?>,
                borderWidth: 2,
                borderRadius: 8,
                yAxisID: 'y',
            },
            {
                label: 'Submissions',
                data: <?= json_encode(array_map(fn($t)=>$t['submissions'], $testBreakdown)) ?>,
                type: 'line',
                borderColor: '#1e3a8a',
                backgroundColor: 'rgba(30,58,138,0.07)',
                borderWidth: 2.5,
                pointBackgroundColor: '#1e3a8a',
                pointRadius: 5,
                tension: 0.35,
                fill: true,
                yAxisID: 'y2',
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: { size: 12 } } } },
        scales: {
            y:  { position: 'left',  min: 0, max: 100, ticks: { callback: v=>v+'%', font:{size:11} }, grid: { color:'rgba(0,0,0,0.05)' } },
            y2: { position: 'right', min: 0, ticks: { font:{size:11} }, grid: { drawOnChartArea: false } },
            x:  { ticks: { font:{size:11} }, grid: { display: false } }
        }
    }
});
<?php endif; ?>

<?php if ($testData): ?>
// Score band doughnut
new Chart(document.getElementById('distChart'), {
    type: 'doughnut',
    data: {
        labels: ['90-100%','70-89%','50-69%','Below 50%'],
        datasets: [{
            data: [<?= $testData['dist']['90-100'] ?>, <?= $testData['dist']['70-89'] ?>, <?= $testData['dist']['50-69'] ?>, <?= $testData['dist']['<50'] ?>],
            backgroundColor: ['#10b981','#3b82f6','#f59e0b','#ef4444'],
            borderWidth: 3, borderColor: '#fff',
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { font:{size:12}, usePointStyle: true } } },
        cutout: '65%'
    }
});
<?php endif; ?>
</script>
</body>
</html>
