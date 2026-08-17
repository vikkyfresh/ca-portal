<?php
session_start();
if (!isset($_SESSION['lecturer_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

// ── SIDEBAR SETUP ────────────────────────────────────────────────
$lecturerId   = (int)$_SESSION['lecturer_id'];
$lecturerName = $_SESSION['lecturer_name'] ?? 'Lecturer';
$lecturerDept = $_SESSION['lecturer_department'] ?? 'Computer Science';
$photoSrc     = 'https://ui-avatars.com/api/?name=' . urlencode($lecturerName) . '&background=1e3a8a&color=fff&size=80&bold=true';
$stmtPhoto    = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
$stmtPhoto->execute([$lecturerId]);
$photoRow     = $stmtPhoto->fetch();
if (!empty($photoRow['photo'])) {
    $sp = dirname(__DIR__) . '/' . ltrim($photoRow['photo'], '/');
    if (file_exists($sp)) $photoSrc = '../' . ltrim($photoRow['photo'], '/');
}
$lecturerAvatarUrl = $photoSrc;
$avatarUrl         = $photoSrc;

// ── PARAMS ───────────────────────────────────────────────────────
$testId = intval($_GET['test_id'] ?? 0);
$matric = strtoupper(trim($_GET['matric'] ?? ''));

if (!$testId || !$matric) { header('Location: view_results.php'); exit; }

// Verify this test belongs to this lecturer
$testStmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND created_by = ? LIMIT 1");
$testStmt->execute([$testId, $lecturerId]);
$test = $testStmt->fetch();
if (!$test) { header('Location: view_results.php'); exit; }

// Get student info
$stuStmt = $pdo->prepare("SELECT full_name, matric, level FROM students WHERE matric = ? LIMIT 1");
$stuStmt->execute([$matric]);
$student = $stuStmt->fetch();

// Get the completed attempt's proctoring integrity verdict (see api/submit-test.php)
$attStmt = $pdo->prepare("SELECT proctoring_flag, proctoring_note FROM attempts WHERE test_id = ? AND student_matric = ? AND status = 'completed' ORDER BY end_time DESC LIMIT 1");
$attStmt->execute([$testId, $matric]);
$attemptRow = $attStmt->fetch();

$integrityInfo = [
    'clean'         => ['label' => 'Monitoring ran cleanly',        'color' => '#16a34a', 'icon' => '✅'],
    'gaps'          => ['label' => 'Monitoring stalled/tampered',   'color' => '#dc2626', 'icon' => '🚫'],
    'degraded'      => ['label' => 'Camera monitoring unavailable', 'color' => '#d97706', 'icon' => '⚠️'],
    'no_monitoring' => ['label' => 'No monitoring detected at all', 'color' => '#dc2626', 'icon' => '🚫'],
];

// Get all violations for this student + test
$logStmt = $pdo->prepare("
    SELECT * FROM proctoring_logs
    WHERE test_id = ? AND student_matric = ?
    ORDER BY created_at ASC
");
$logStmt->execute([$testId, $matric]);
$logs = $logStmt->fetchAll();

$violationLabels = [
    'face_out'        => ['label' => 'Face Out of Frame',  'color' => '#dc2626', 'icon' => '👤'],
    'eyes_closed'     => ['label' => 'Eyes Closed',        'color' => '#d97706', 'icon' => '👁️'],
    'eyes_away'       => ['label' => 'Eyes Looking Away',  'color' => '#7c3aed', 'icon' => '👀'],
    'tab_switch'      => ['label' => 'Tab Switch',         'color' => '#0369a1', 'icon' => '🔀'],
    'fullscreen_exit' => ['label' => 'Fullscreen Exit',    'color' => '#b45309', 'icon' => '⛶'],
    'multiple_faces'  => ['label' => 'Multiple Faces',     'color' => '#991b1b', 'icon' => '🧑‍🤝‍🧑'],
    'no_camera'       => ['label' => 'Camera Unavailable', 'color' => '#92400e', 'icon' => '📵'],
];

// Counts per type
$counts = array_fill_keys(array_keys($violationLabels), 0);
foreach ($logs as $log) {
    if (isset($counts[$log['event_type']])) $counts[$log['event_type']]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proctoring Detail</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;display:flex;min-height:100vh}
        .main{flex:1;padding:24px;overflow-x:hidden}
        .page-header{background:white;border-radius:16px;padding:20px 24px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.06);display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .back-btn{display:inline-flex;align-items:center;gap:8px;background:#0f172a;color:white;padding:8px 16px;border-radius:10px;text-decoration:none;font-size:.85rem;font-weight:600}
        .page-title{font-size:1.2rem;font-weight:700;color:#0f172a}
        .page-sub{font-size:.85rem;color:#64748b;margin-top:2px}
        .summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
        .summary-card{background:white;border-radius:14px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,.06);border-left:4px solid}
        .summary-card .count{font-size:2rem;font-weight:800;line-height:1}
        .summary-card .label{font-size:.8rem;color:#64748b;margin-top:4px;font-weight:500}
        .timeline{background:white;border-radius:16px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
        .timeline-title{font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:20px;display:flex;align-items:center;gap:8px}
        .timeline-empty{text-align:center;padding:40px;color:#94a3b8}
        .tl-item{display:flex;gap:16px;margin-bottom:20px;position:relative}
        .tl-item:not(:last-child)::before{content:'';position:absolute;left:19px;top:40px;bottom:-20px;width:2px;background:#e2e8f0}
        .tl-dot{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.12)}
        .tl-body{flex:1;padding-top:6px}
        .tl-type{font-weight:700;font-size:.9rem}
        .tl-time{font-size:.78rem;color:#94a3b8;margin-top:2px}
        .tl-warn{display:inline-block;background:#fef2f2;color:#dc2626;font-size:.74rem;font-weight:700;padding:2px 8px;border-radius:20px;margin-top:4px}
        .snapshot-wrap{margin-top:10px}
        .snapshot-wrap img{max-width:220px;border-radius:10px;border:2px solid #e2e8f0;cursor:pointer;transition:transform .2s}
        .snapshot-wrap img:hover{transform:scale(1.04)}
        .snapshot-label{font-size:.73rem;color:#94a3b8;margin-top:4px}
        /* Lightbox */
        #lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center}
        #lightbox.open{display:flex}
        #lightbox img{max-width:90vw;max-height:85vh;border-radius:12px;box-shadow:0 0 60px rgba(0,0,0,.6)}
        #lightbox-close{position:absolute;top:20px;right:24px;color:white;font-size:2rem;cursor:pointer;font-weight:700;line-height:1}
    </style>
</head>
<body>
<?php $activePage = 'view_results'; require_once __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">

    <div class="page-header">
        <a href="view_results.php?test_id=<?= $testId ?>" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Results</a>
        <div>
            <div class="page-title">🔍 Proctoring Detail — <?= htmlspecialchars($student['full_name'] ?? $matric) ?></div>
            <div class="page-sub"><?= htmlspecialchars($matric) ?> &nbsp;·&nbsp; <?= htmlspecialchars($test['course_code']) ?> — <?= htmlspecialchars($test['test_title'] ?? $test['course_title'] ?? '') ?></div>
        </div>
    </div>

    <?php if ($attemptRow && !empty($attemptRow['proctoring_flag']) && isset($integrityInfo[$attemptRow['proctoring_flag']])):
        $iv = $integrityInfo[$attemptRow['proctoring_flag']];
    ?>
    <div style="background:white;border-radius:16px;padding:18px 22px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.06);border-left:4px solid <?= $iv['color'] ?>;display:flex;align-items:center;gap:14px;">
        <div style="font-size:1.6rem"><?= $iv['icon'] ?></div>
        <div>
            <div style="font-weight:700;color:<?= $iv['color'] ?>;font-size:.95rem"><?= $iv['label'] ?></div>
            <?php if (!empty($attemptRow['proctoring_note'])): ?>
            <div style="font-size:.82rem;color:#64748b;margin-top:2px"><?= htmlspecialchars($attemptRow['proctoring_note']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="summary-grid">
        <?php foreach ($violationLabels as $type => $info): ?>
        <div class="summary-card" style="border-color:<?= $info['color'] ?>">
            <div class="count" style="color:<?= $info['color'] ?>"><?= $counts[$type] ?></div>
            <div class="label"><?= $info['icon'] ?> <?= $info['label'] ?></div>
        </div>
        <?php endforeach; ?>
        <div class="summary-card" style="border-color:#0f172a">
            <div class="count" style="color:#0f172a"><?= count($logs) ?></div>
            <div class="label">📊 Total Violations</div>
        </div>
    </div>

    <!-- Timeline -->
    <div class="timeline">
        <div class="timeline-title"><i class="fas fa-stream"></i> Violation Timeline</div>
        <?php if (empty($logs)): ?>
        <div class="timeline-empty">
            <div style="font-size:3rem;margin-bottom:12px">✅</div>
            <div style="font-weight:600;color:#0f172a">No violations recorded</div>
            <div style="font-size:.85rem;margin-top:6px">This student completed the test cleanly.</div>
        </div>
        <?php else: ?>
        <?php foreach ($logs as $log):
            $info = $violationLabels[$log['event_type']] ?? ['label'=>$log['event_type'],'color'=>'#64748b','icon'=>'⚠️'];
        ?>
        <div class="tl-item">
            <div class="tl-dot" style="background:<?= $info['color'] ?>20;color:<?= $info['color'] ?>"><?= $info['icon'] ?></div>
            <div class="tl-body">
                <div class="tl-type" style="color:<?= $info['color'] ?>"><?= $info['label'] ?></div>
                <div class="tl-time"><i class="fas fa-clock"></i> <?= date('h:i:s A', strtotime($log['created_at'])) ?> &nbsp;·&nbsp; <?= date('d M Y', strtotime($log['created_at'])) ?></div>
                <?php $evData = json_decode($log['event_data'] ?? '{}', true); $warnCount = $evData['warning_count'] ?? 0; if ($warnCount > 0): ?>
                <span class="tl-warn">⚠️ Warning <?= $warnCount ?></span>
                <?php endif; ?>
                <?php if (!empty($log['screenshot_path'])): ?>
                <div class="snapshot-wrap">
                    <img src="../api/get-snapshot.php?file=<?= urlencode($log['screenshot_path'] ?? '') ?>"
                         alt="Snapshot"
                         onclick="openLightbox(this.src)"
                         title="Click to enlarge">
                    <div class="snapshot-label"><i class="fas fa-camera"></i> Webcam capture at time of violation</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- Lightbox -->
<div id="lightbox">
    <span id="lightbox-close" onclick="document.getElementById('lightbox').classList.remove('open')">&times;</span>
    <img id="lightbox-img" src="" alt="Snapshot">
</div>

<script>
function openLightbox(src) {
    document.getElementById('lightbox-img').src = src;
    document.getElementById('lightbox').classList.add('open');
}
document.getElementById('lightbox').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
</script>
</body>
</html>
