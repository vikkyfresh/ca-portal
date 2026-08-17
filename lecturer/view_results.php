<?php
session_start();
if (!isset($_SESSION['lecturer_id'])) {
    header('Location: index.php');
    exit;
}
require_once '../includes/config.php';

// ── AJAX: Approve retake ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_retake') {
    header('Content-Type: application/json');
    guardLecturerWriteJson();
    $sMatric = strtoupper(trim($_POST['matric'] ?? ''));
    $tId     = (int)($_POST['test_id'] ?? 0);
    $lId     = (int)$_SESSION['lecturer_id'];
    if (!$sMatric || !$tId) { echo json_encode(['success'=>false,'message'=>'Invalid data']); exit; }
    // Verify test belongs to this lecturer
    $chk = $pdo->prepare("SELECT id FROM tests WHERE id=? AND created_by=?");
    $chk->execute([$tId, $lId]);
    if (!$chk->fetch()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
    // Upsert retake approval (reset used=0 so student can retake again)
    $stmt = $pdo->prepare("INSERT INTO retake_approvals (student_matric, test_id, approved_by, used, approved_at)
        VALUES (?,?,?,0,NOW())
        ON DUPLICATE KEY UPDATE approved_by=VALUES(approved_by), used=0, approved_at=NOW()");
    $stmt->execute([$sMatric, $tId, $lId]);
    logAudit('retake_approved', 'lecturer', $lId, $_SESSION['lecturer_name'] ?? null,
        ($_SESSION['lecturer_name'] ?? 'A lecturer') . " approved a retake for $sMatric (test #$tId).");
    echo json_encode(['success'=>true]);
    exit;
}
// ──────────────────────────────────────────────────────────────────────────

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


$lecturerId   = (int) $_SESSION['lecturer_id'];
$lecturerName = $_SESSION['lecturer_name'] ?? 'Lecturer';
$lecturerDept = $_SESSION['lecturer_department'] ?? 'Computer Science';

$selectedTestId = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;

// Academic session info from DB
$academicSession = getAcademicSetting('academic_session', '2025/2026');
$currentSemester = getAcademicSetting('current_semester', '2nd Semester');

// All tests by this lecturer
$stmt = $pdo->prepare("SELECT id, course_code, test_title, level, duration_minutes, passing_score, total_questions, created_at 
    FROM tests WHERE created_by = ? ORDER BY created_at DESC");
$stmt->execute([$lecturerId]);
$tests = $stmt->fetchAll();

$testInfo   = null;
$results    = [];
$statistics = [];

if ($selectedTestId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND created_by = ?");
    $stmt->execute([$selectedTestId, $lecturerId]);
    $testInfo = $stmt->fetch();

    if ($testInfo) {
        $stmt = $pdo->prepare("
            SELECT a.*, s.full_name, s.matric, s.email, s.level AS student_level
            FROM attempts a
            JOIN students s ON a.student_matric = s.matric
            WHERE a.test_id = ? AND a.status = 'completed'
            ORDER BY a.percentage DESC, a.time_spent_seconds ASC
        ");
        $stmt->execute([$selectedTestId]);
        $results = $stmt->fetchAll();

        // Load existing retake approvals for this test
        $retakeStmt = $pdo->prepare("SELECT student_matric, used FROM retake_approvals WHERE test_id = ?");
        $retakeStmt->execute([$selectedTestId]);
        $retakeMap = [];
        foreach ($retakeStmt->fetchAll() as $rr) {
            $retakeMap[$rr['student_matric']] = $rr['used'];
        }

        $totalStudents = count($results);
        $passCount = 0;
        // Build proctoring violation map: matric => [total, face_out, eyes_closed, eyes_away]
        $pMatrics = array_column($results, 'matric');
        $procMap = [];
        if (!empty($pMatrics)) {
            $inClause = implode(',', array_fill(0, count($pMatrics), '?'));
            $pStmt = $pdo->prepare("
                SELECT student_matric,
                       COUNT(*) AS total_flags,
                       SUM(event_type='face_out')        AS face_out,
                       SUM(event_type='eyes_closed')     AS eyes_closed,
                       SUM(event_type='eyes_away')       AS eyes_away,
                       SUM(event_type='tab_switch')      AS tab_switch,
                       SUM(event_type='fullscreen_exit') AS fullscreen_exit,
                       SUM(event_type='multiple_faces')  AS multiple_faces,
                       SUM(event_type='no_camera')       AS no_camera
                FROM proctoring_logs
                WHERE test_id = ? AND student_matric IN ($inClause)
                GROUP BY student_matric
            ");
            $pStmt->execute(array_merge([$selectedTestId], $pMatrics));
            foreach ($pStmt->fetchAll() as $pr) {
                $procMap[$pr['student_matric']] = $pr;
            }
        }

        $scores = [];

        foreach ($results as $r) {
            $pct = (float) $r['percentage'];
            $scores[] = $pct;
            if ($pct >= $testInfo['passing_score']) $passCount++;
        }

        $statistics = [
            'total'         => $totalStudents,
            'pass_count'    => $passCount,
            'fail_count'    => $totalStudents - $passCount,
            'average'       => !empty($scores) ? round(array_sum($scores) / count($scores), 1) : 0,
            'highest'       => !empty($scores) ? round(max($scores), 1) : 0,
            'lowest'        => !empty($scores) ? round(min($scores), 1) : 0,
            'pass_rate'     => $totalStudents > 0 ? round(($passCount / $totalStudents) * 100, 1) : 0,
        ];
    }
}

// Helper: convert raw score (out of N questions) to CA mark out of 30
function toCA(float $score, float $total): float {
    if ($total <= 0) return 0;
    return round(($score / $total) * 30, 1);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Test Reports — Lecturer Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body { min-height: 100vh;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #f1f5f9;
}

/* ── Layout ── */
.layout{display:flex;min-height:100vh}

/* sidebar CSS → includes/sidebar.php */
/* sidebar CSS → includes/sidebar.php */
/* .nav defined in includes/sidebar.php */
/* .nav a defined in includes/sidebar.php */


.main { flex: 1; margin-left: 260px; min-width: 0; }

.topbar {
    background: white;
    padding: 16px 28px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 50;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.topbar h1 { font-size: 1.3rem; color: #0f172a; font-weight: 700; }
.topbar-sub { font-size: 12px; color: #64748b; margin-top: 2px; }

.content { padding: 24px 28px; }

/* ── Cards ── */
.card {
    background: white;
    border-radius: 16px;
    padding: 22px 24px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.07);
    margin-bottom: 20px;
}
.card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f1f5f9;
}
.card-title i { color: #1e3a8a; }

/* ── Test selector ── */
.selector-card {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
    border-radius: 16px;
    padding: 22px 24px;
    margin-bottom: 20px;
    box-shadow: 0 4px 16px rgba(15,23,42,0.25);
}
.selector-card label {
    display: block;
    color: rgba(255,255,255,0.85);
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 10px;
}
.selector-card select {
    width: 100%;
    max-width: 520px;
    padding: 12px 16px;
    border: none;
    border-radius: 10px;
    font-size: 0.95rem;
    background: white;
    color: #0f172a;
    cursor: pointer;
    font-weight: 500;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.selector-card select:focus { outline: 2px solid rgba(255,255,255,0.5); }

/* ── Official result header (mimics university sheet) ── */
.result-header-block {
    background: white;
    border: 2px solid #1e3a8a;
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.07);
}
.uni-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
    color: white;
    padding: 20px 28px;
    text-align: center;
}
.uni-header .uni-name {
    font-size: 1rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    margin-bottom: 2px;
}
.uni-header .uni-sub {
    font-size: 0.8rem;
    opacity: 0.85;
    margin-bottom: 10px;
}
.uni-header .result-title {
    display: inline-block;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.3);
    border-radius: 6px;
    padding: 4px 18px;
    font-size: 0.85rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.session-strip {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 12px 28px;
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
}
.session-item { font-size: 12.5px; color: #475569; }
.session-item strong { color: #0f172a; font-weight: 700; }

/* ── Stats summary row (mirrors the PDF layout) ── */
.stats-summary {
    padding: 20px 28px;
    border-bottom: 1px solid #e2e8f0;
}
.stats-summary h3 {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #94a3b8;
    margin-bottom: 14px;
    font-weight: 600;
}
.stats-row-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    gap: 12px;
}
.stat-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 10px;
    text-align: center;
}
.stat-box .s-num {
    font-size: 26px;
    font-weight: 800;
    color: #1e3a8a;
    line-height: 1;
}
.stat-box .s-num.green { color: #10b981; }
.stat-box .s-num.red   { color: #ef4444; }
.stat-box .s-lbl {
    font-size: 11px;
    color: #64748b;
    margin-top: 5px;
}


/* ── Export buttons ── */
.export-row {
    padding: 16px 28px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.export-label { font-size: 12px; color: #64748b; font-weight: 600; margin-right: 4px; }
.export-btn {
    padding: 9px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: none;
    transition: all 0.2s;
}
.btn-excel {
    background: linear-gradient(135deg, #0f172a, #1e3a8a);
    color: white;
}
.btn-excel:hover { opacity: 0.88; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(30,58,138,0.3); }
.btn-outline {
    background: white;
    color: #1e3a8a;
    border: 1.5px solid #1e3a8a;
}
.btn-outline:hover { background: #1e3a8a; color: white; transform: translateY(-1px); }

/* ── Results table ── */
.table-card { background: white; border-radius: 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); overflow: hidden; margin-bottom: 20px; }
.table-card-header {
    padding: 18px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.table-card-header h3 { font-size: 1rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; }
.table-card-header h3 i { color: #1e3a8a; }
.student-count {
    background: #eff6ff;
    color: #1e3a8a;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
}

.results-table { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th {
    padding: 11px 14px;
    background: #0f172a;
    color: white;
    text-align: left;
    font-weight: 600;
    white-space: nowrap;
    font-size: 12px;
    letter-spacing: 0.02em;
}
thead th:first-child { border-radius: 0; }
tbody td {
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
tbody tr:hover td { background: #f8fafc; }
tbody tr:last-child td { border-bottom: none; }

.ca-score {
    font-size: 15px;
    font-weight: 800;
    color: #1e3a8a;
}
.ca-denom { font-size: 11px; color: #94a3b8; }

.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}
.badge-pass { background: #d1fae5; color: #065f46; }
.badge-fail { background: #fee2e2; color: #991b1b; }

/* ── No data ── */
.no-data {
    text-align: center;
    padding: 60px 20px;
    color: #64748b;
}
.no-data i { font-size: 44px; margin-bottom: 14px; color: #cbd5e0; display: block; }

/* ── Responsive ── */
@media (max-width: 768px) {
    /* sidebar CSS → includes/sidebar.php */
    .main { margin-left: 0; }
    .content { padding: 16px; }
    .stats-row-grid { grid-template-columns: repeat(2, 1fr); }
}

/* ── Print / PDF styles ── */
@media print {
    .sidebar, .topbar, .selector-card, .export-row, .no-print { display: none !important; }
    .main { margin-left: 0; }
    .content { padding: 0; }
    body { background: #f1f5f9; }
    .result-header-block { border: 1.5px solid #1e3a8a; box-shadow: none; }
    .table-card { box-shadow: none; border: 1px solid #e2e8f0; }
    thead th { background: #1e3a8a !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}

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

<!-- Sidebar -->
<?php $activePage='results'; require_once __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div style="display:flex;align-items:center;gap:12px">
            <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
            <div>
                <h1><i class="fas fa-chart-bar" style="color:#1e3a8a;margin-right:8px"></i> Test Reports</h1>
                <div class="topbar-sub"><?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?></div>
            </div>
        </div>
    </div>

    <div class="content">

        <!-- Test selector -->
        <div class="selector-card no-print">
            <label for="test_id"><i class="fas fa-filter"></i> &nbsp;Select a test to view its full report:</label>
            <form method="get" action="">
                <select name="test_id" id="test_id" onchange="this.form.submit()">
                    <option value="">— Choose a test —</option>
                    <?php foreach ($tests as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $selectedTestId == $t['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t['course_code']) ?> · <?= htmlspecialchars($t['test_title']) ?>
                        (<?= date('d M Y', strtotime($t['created_at'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if (empty($tests)): ?>
        <div class="card">
            <div class="no-data">
                <i class="fas fa-folder-open"></i>
                <p>You haven't created any tests yet.</p>
                <a href="create-test.php" style="display:inline-block;margin-top:16px;padding:10px 20px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white;text-decoration:none;border-radius:8px;font-weight:600;">
                    <i class="fas fa-plus"></i> Create Your First Test
                </a>
            </div>
        </div>

        <?php elseif ($selectedTestId > 0 && !$testInfo): ?>
        <div class="card">
            <div class="no-data">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Test not found or you don't have permission to view it.</p>
            </div>
        </div>

        <?php elseif ($selectedTestId > 0 && $testInfo): ?>

        <!-- ══ OFFICIAL RESULT BLOCK ══ -->
        <div class="result-header-block">

            <!-- University header -->
            <div class="uni-header">
                <div class="uni-name">Prince Abubakar Audu University, Anyigba</div>
                <div class="uni-sub">Faculty of Computing — Department of <?= htmlspecialchars($lecturerDept) ?></div>
                <div class="result-title">CA Test Result Report</div>
            </div>

            <!-- Session strip -->
            <div class="session-strip">
                <div class="session-item">Session: <strong><?= htmlspecialchars($academicSession) ?></strong></div>
                <div class="session-item">Semester: <strong><?= htmlspecialchars($currentSemester) ?></strong></div>
                <div class="session-item">Course: <strong><?= htmlspecialchars($testInfo['course_code']) ?> — <?= htmlspecialchars($testInfo['test_title']) ?></strong></div>
                <div class="session-item">Level: <strong><?= htmlspecialchars($testInfo['level']) ?></strong></div>
                <div class="session-item">Questions: <strong><?= $testInfo['total_questions'] ?></strong></div>
                <div class="session-item">CA Mark: <strong>/ 30</strong></div>
                <div class="session-item">Pass Mark: <strong><?= $testInfo['passing_score'] ?>%</strong></div>
            </div>

            <!-- Stats summary -->
            <div class="stats-summary">
                <h3>Submission Statistics</h3>
                <div class="stats-row-grid">
                    <div class="stat-box">
                        <div class="s-num"><?= $statistics['total'] ?></div>
                        <div class="s-lbl">Registered / Sat</div>
                    </div>
                    <div class="stat-box">
                        <div class="s-num green"><?= $statistics['pass_count'] ?></div>
                        <div class="s-lbl">Passes</div>
                    </div>
                    <div class="stat-box">
                        <div class="s-num red"><?= $statistics['fail_count'] ?></div>
                        <div class="s-lbl">Failed</div>
                    </div>
                    <div class="stat-box">
                        <div class="s-num"><?= $statistics['pass_rate'] ?>%</div>
                        <div class="s-lbl">Pass Rate</div>
                    </div>
                    <div class="stat-box">
                        <div class="s-num"><?= $statistics['average'] ?>%</div>
                        <div class="s-lbl">Average Score</div>
                    </div>
                    <div class="stat-box">
                        <div class="s-num"><?= $statistics['highest'] ?>%</div>
                        <div class="s-lbl">Highest Score</div>
                    </div>
                    <div class="stat-box">
                        <div class="s-num"><?= $statistics['lowest'] ?>%</div>
                        <div class="s-lbl">Lowest Score</div>
                    </div>
                </div>
            </div>

            <!-- Export row -->
            <div class="export-row no-print">
                <span class="export-label"><i class="fas fa-download"></i> Download:</span>
                <a href="api/export-results.php?test_id=<?= $selectedTestId ?>&format=excel" class="export-btn btn-excel">
                    <i class="fas fa-file-excel"></i> Excel
                </a>
                <a href="api/export-results.php?test_id=<?= $selectedTestId ?>&format=csv" class="export-btn btn-outline">
                    <i class="fas fa-file-csv"></i> CSV
                </a>
                <a href="api/export-results.php?test_id=<?= $selectedTestId ?>&format=pdf" class="export-btn btn-outline" target="_blank">
                    <i class="fas fa-file-pdf"></i> PDF
                </a>
                <button onclick="window.print()" class="export-btn btn-outline">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>

        </div><!-- /result-header-block -->

        <!-- ══ STUDENT TABLE ══ -->
        <div class="table-card">
            <div class="table-card-header">
                <h3><i class="fas fa-users"></i> Student Performance Details</h3>
                <?php if ($statistics['total'] > 0): ?>
                <span class="student-count"><?= $statistics['total'] ?> student<?= $statistics['total'] !== 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </div>

            <?php if (count($results) > 0): ?>
            <div class="results-table">
                <table>
                    <thead>
                        <tr>
                            <th>S/N</th>
                            <th>Matric No</th>
                            <th>Student Name</th>
                            <th>Raw Score</th>
                            <th>CA Mark (/30)</th>
                            <th>Percentage</th>
                            <th>Time Spent</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>🔍 Flags</th>
                            <th>Retake</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sn = 1; foreach ($results as $r):
                            $pct      = round((float)$r['percentage'], 1);
                            $caScore  = toCA((float)$r['score'], (float)$r['total']);
                            $passed   = $pct >= $testInfo['passing_score'];
                            $timeMins = ($r['time_spent_seconds'] ?? 0) > 0
                                          ? round($r['time_spent_seconds'] / 60, 1) . ' min'
                                          : 'N/A';
                            $dateStr  = !empty($r['end_time']) ? date('d M Y', strtotime($r['end_time'])) : 'N/A';
                        ?>
                        <tr>
                            <td><?= $sn++ ?></td>
                            <td><strong><?= htmlspecialchars($r['matric']) ?></strong></td>
                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                            <td><?= (int)$r['score'] ?>/<?= (int)$r['total'] ?></td>
                            <td>
                                <span class="ca-score"><?= $caScore ?></span>
                                <span class="ca-denom">/ 30</span>
                            </td>
                            <td><?= $pct ?>%</td>
                            <td><?= $timeMins ?></td>
                            <td><span class="badge <?= $passed ? 'badge-pass' : 'badge-fail' ?>"><?= $passed ? 'PASS' : 'FAIL' ?></span></td>
                            <td><?= $dateStr ?></td>
                            <td>
                                <?php
                                $proc = $procMap[$r['matric']] ?? null;
                                $pFlag = $r['proctoring_flag'] ?? null;
                                $integrityBad = in_array($pFlag, ['no_monitoring', 'gaps'], true);
                                $hasViolations = $proc && $proc['total_flags'] > 0;

                                if ($hasViolations || $integrityBad):
                                    $flagColor = ($integrityBad || ($proc && $proc['total_flags'] >= 3)) ? '#dc2626' : '#d97706';
                                    $titleParts = [];
                                    if ($proc) {
                                        $titleParts[] = "Face out: {$proc['face_out']} | Eyes closed: {$proc['eyes_closed']} | Eyes away: {$proc['eyes_away']} | Multi-face: {$proc['multiple_faces']}";
                                    }
                                    if ($pFlag && $pFlag !== 'clean') {
                                        $titleParts[] = 'Integrity: ' . $pFlag;
                                    }
                                ?>
                                <a href="proctoring-detail.php?test_id=<?= $selectedTestId ?>&matric=<?= urlencode($r['matric']) ?>"
                                   title="<?= htmlspecialchars(implode(' — ', $titleParts)) ?> — Click to view detail"
                                   style="display:inline-flex;align-items:center;gap:5px;background:<?= $flagColor ?>;color:white;padding:4px 10px;border-radius:20px;font-size:.76rem;font-weight:700;text-decoration:none;">
                                    ⚠️ <?= $hasViolations ? (int)$proc['total_flags'] : 0 ?><?= $integrityBad ? ' 🚫' : '' ?> <i class="fas fa-external-link-alt" style="font-size:.65rem"></i>
                                </a>
                                <?php elseif ($pFlag === 'degraded'): ?>
                                <span style="color:#d97706;font-size:.78rem;font-weight:600;" title="Camera monitoring was unavailable during this attempt">📵 Degraded</span>
                                <?php else: ?>
                                <span style="color:#10b981;font-size:.78rem;font-weight:600;">✅ Clean</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $retakeStatus = $retakeMap[$r['matric']] ?? null;
                                if ($retakeStatus === null): // no approval yet
                                ?>
                                <button class="btn-retake-approve"
                                    onclick="approveRetake('<?= htmlspecialchars($r['matric']) ?>','<?= htmlspecialchars($r['full_name']) ?>',<?= $selectedTestId ?>,this)">
                                    <i class="fas fa-redo"></i> Approve
                                </button>
                                <?php elseif ($retakeStatus == 0): // approved, not yet used ?>
                                <span style="display:inline-flex;align-items:center;gap:5px;background:#dbeafe;color:#1e40af;padding:4px 10px;border-radius:20px;font-size:.76rem;font-weight:700;">
                                    <i class="fas fa-clock"></i> Approved
                                </span>
                                <?php else: // used ?>
                                <span style="display:inline-flex;align-items:center;gap:5px;background:#dcfce7;color:#166534;padding:4px 10px;border-radius:20px;font-size:.76rem;font-weight:700;">
                                    <i class="fas fa-check"></i> Retaken
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-user-graduate"></i>
                <p>No students have completed this test yet.</p>
            </div>
            <?php endif; ?>

        </div><!-- /table-card -->

        <!-- Signature footer (like the official sheet) -->
        <div class="card" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:20px;">
            <div style="font-size:13px;color:#475569;line-height:1.8;">
                <strong style="color:#0f172a;"><?= htmlspecialchars($lecturerName) ?></strong><br>
                Course Lecturer<br>
                Dept. of <?= htmlspecialchars($lecturerDept) ?>
            </div>
            <div style="font-size:12px;color:#94a3b8;text-align:right;">
                Generated: <?= date('d F Y, g:i A') ?><br>
                <?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?><br>
                CA Portal — System Generated
            </div>
        </div>

        <?php endif; ?>

    </div><!-- /content -->
</main>
</div><!-- /layout -->

<style>
.btn-retake-approve {
    padding: 5px 12px;
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
    border-radius: 8px;
    font-size: .76rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: background .2s;
    white-space: nowrap;
}
.btn-retake-approve:hover { background: #fde68a; }
.btn-retake-approve:disabled { opacity: .5; cursor: not-allowed; }
</style>

<script>
async function approveRetake(matric, name, testId, btn) {
    if (!confirm(`Approve retake for ${name} (${matric})?\n\nThey will see the test again the next time they log in.`)) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    const fd = new FormData();
    fd.append('action', 'approve_retake');
    fd.append('matric', matric);
    fd.append('test_id', testId);
    try {
        const resp = await fetch('view_results.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            btn.outerHTML = '<span style="display:inline-flex;align-items:center;gap:5px;background:#dbeafe;color:#1e40af;padding:4px 10px;border-radius:20px;font-size:.76rem;font-weight:700;"><i class="fas fa-clock"></i> Approved</span>';
        } else {
            alert('Error: ' + (data.message || 'Failed'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-redo"></i> Approve';
        }
    } catch(e) {
        alert('Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-redo"></i> Approve';
    }
}
</script>
</body>
</html>