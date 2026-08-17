<?php
session_start();
require_once __DIR__ . '/includes/config.php';

$authMatric = strtoupper(trim($_SESSION['authenticated_matric'] ?? $_SESSION['student_matric'] ?? ''));
$testId     = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;

if (!$authMatric || !$testId) {
    header('Location: dashboard.php');
    exit;
}

// SECURITY: score/percentage/passed/etc used to be trusted straight off the
// URL (score=, total=, passed=, pass_mark=, student_name=), which meant
// anyone could hand-craft a "100% passed" result page — including the PDF
// export — without ever taking the test. Pull the real, most recent
// completed attempt for this student+test from the database instead, and
// never trust caller-supplied score/grade values again.
$attemptStmt = $pdo->prepare("
    SELECT a.score, a.total, a.percentage, a.time_spent_seconds, a.end_time,
           t.course_code, t.course_title, t.test_title, t.passing_score
    FROM attempts a
    JOIN tests t ON a.test_id = t.id
    WHERE a.student_matric = ? AND a.test_id = ? AND a.status = 'completed'
    ORDER BY a.end_time DESC
    LIMIT 1
");
$attemptStmt->execute([$authMatric, $testId]);
$attempt = $attemptStmt->fetch();

if (!$attempt) {
    // No completed attempt for this student+test — nothing legitimate to show.
    header('Location: dashboard.php');
    exit;
}

$score       = (int)$attempt['score'];
$total       = (int)$attempt['total'];
$percentage  = (float)$attempt['percentage'];
$course      = $attempt['course_code'] ?? ($_SESSION['current_course'] ?? '');
$title       = $attempt['course_title'] ?? $attempt['test_title'] ?? ($_SESSION['current_test_title'] ?? 'Test');
$timeTaken   = (int)($attempt['time_spent_seconds'] ?? 0);
$studentName = $_SESSION['student_name'] ?? 'Student';
$studentId   = $authMatric;

$autoSubmitted = isset($_GET['auto_submitted']) && $_GET['auto_submitted'] === '1';

$minutes    = floor($timeTaken / 60);
$seconds    = $timeTaken % 60;
$dateTaken  = !empty($attempt['end_time']) ? date('F j, Y \a\t g:i A', strtotime($attempt['end_time'])) : date('F j, Y \a\t g:i A');

// Portal-wide passing mark (fixed at 50% for every course — see tests.passing_score).
$passMark = (float)($attempt['passing_score'] ?? 50);
$passed   = $percentage >= $passMark;

// Performance tier — drives feedback text, accent colour, and emoji.
// No letter grades (A/B/C/D/E/F) anywhere in the system anymore — just
// percentage + pass/fail + a short remark.
if ($percentage >= 70)      { $remark = 'Excellent';   $tier = 'excellent'; }
elseif ($percentage >= 60)  { $remark = 'Very Good';   $tier = 'good'; }
elseif ($percentage >= 50)  { $remark = 'Good';        $tier = 'average'; }
elseif ($percentage >= 45)  { $remark = 'Fair';        $tier = 'below'; }
elseif ($percentage >= 40)  { $remark = 'Weak';        $tier = 'weak'; }
else                         { $remark = 'Needs Work';  $tier = 'fail'; }

$feedbackMap = [
    'excellent' => 'Exceptional performance! You clearly have a strong command of this material.',
    'good'      => 'Great effort — you\'re above the curve. A focused review of weak areas will take you higher.',
    'average'   => 'Solid foundation. Revisit the topics you missed and you\'ll improve next time.',
    'below'     => "Just under the {$passMark}% pass mark this time — a focused review of the topics you missed should get you over the line next attempt.",
    'weak'      => "Below the {$passMark}% pass mark — go back over the material thoroughly before your next attempt.",
    'fail'      => 'Don\'t give up! Go over the material thoroughly to strengthen your understanding.',
];
$feedback = $feedbackMap[$tier];

// Tier accent colours — portal stays navy/white
$tierColors = [
    'excellent' => '#10b981',
    'good'      => '#3b82f6',
    'average'   => '#f59e0b',
    'below'     => '#f97316',
    'weak'      => '#f43f5e',
    'fail'      => '#ef4444',
];
$accentColor = $tierColors[$tier];

// Emoji per tier — single source of truth (previously a 3rd, separately
// hardcoded threshold set lower down, which is what let it drift out of
// sync with the tier scale above).
$emojiMap = [
    'excellent' => '🎉',
    'good'      => '👍',
    'average'   => '📚',
    'below'     => '📖',
    'weak'      => '⚠️',
    'fail'      => '💪',
];

$correct   = $score;
$incorrect = $total - $score;

// ── Test history: all of this student's completed attempts, most recent first ──
$testHistory = [];
if ($authMatric) {
    $stmt = $pdo->prepare("
        SELECT a.id, a.score, a.total, a.percentage, a.end_time, a.status,
               t.course_code, t.course_title, t.test_title, t.passing_score
        FROM attempts a
        JOIN tests t ON a.test_id = t.id
        WHERE a.student_matric = ? AND a.status = 'completed'
        ORDER BY a.end_time DESC
    ");
    $stmt->execute([$authMatric]);
    $testHistory = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Test Result — <?= htmlspecialchars($course ?: 'Result') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #0f172a 100%);
    min-height: 100vh;
    padding: 40px 20px;
}

.container { max-width: 860px; margin: 0 auto; }

/* Top action bar */
.actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-bottom: 20px;
}
.action-btn {
    padding: 9px 18px;
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 10px;
    color: white;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.2s;
    text-decoration: none;
}
.action-btn:hover { background: rgba(255,255,255,0.2); transform: translateY(-1px); }

/* Main card */
.result-card {
    background: white;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 25px 50px rgba(0,0,0,0.4);
}

/* Header — portal navy gradient */
.result-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
    padding: 36px 40px;
    color: white;
    text-align: center;
}
.result-header .emoji { font-size: 56px; margin-bottom: 12px; display: block; }
.result-header h1 { font-size: 26px; font-weight: 700; margin-bottom: 6px; }
.result-header .course-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 20px;
    padding: 4px 14px;
    font-size: 13px;
    margin-top: 8px;
}

/* Student info strip */
.student-strip {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
}
.strip-item {
    padding: 14px 24px;
    border-right: 1px solid #e2e8f0;
}
.strip-item:last-child { border-right: none; }
.strip-label {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 3px;
}
.strip-value { font-size: 14px; font-weight: 600; color: #0f172a; }

/* Score section */
.score-section { padding: 36px 40px; background: white; }

/* Score ring */
.score-ring-wrap {
    width: 180px;
    height: 180px;
    margin: 0 auto 28px;
    position: relative;
}
.score-ring-wrap svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.ring-bg   { fill: none; stroke: #e2e8f0; stroke-width: 12; }
.ring-prog {
    fill: none;
    stroke: <?= $accentColor ?>;
    stroke-width: 12;
    stroke-linecap: round;
    stroke-dasharray: 502;
    stroke-dashoffset: <?= 502 - (502 * $percentage / 100) ?>;
}
.ring-inner {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
}
.ring-pct {
    font-size: 44px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
}
.ring-pct span { font-size: 20px; color: #64748b; font-weight: 500; }
.ring-sub { font-size: 13px; color: #64748b; margin-top: 4px; }

/* Performance remark badge */
.remark-section { text-align: center; margin: 20px 0; }
.remark-badge {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: <?= $accentColor ?>15;
    border: 1px solid <?= $accentColor ?>40;
    border-radius: 50px;
    padding: 10px 24px;
}
.remark-text  { font-size: 20px; font-weight: 700; color: <?= $accentColor ?>; }
.pass-pill {
    display: inline-flex; align-items: center; gap: 8px;
    margin-top: 10px; padding: 6px 18px; border-radius: 50px;
    font-size: 14px; font-weight: 700;
}
.pass-pill.is-pass { background: #10b98115; border: 1px solid #10b98140; color: #10b981; }
.pass-pill.is-fail { background: #ef444415; border: 1px solid #ef444440; color: #ef4444; }

/* Stats grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin: 24px 0;
}
.stat-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 20px;
    text-align: center;
    transition: transform 0.2s, box-shadow 0.2s;
}
.stat-box:hover { transform: translateY(-3px); box-shadow: 0 6px 16px rgba(0,0,0,0.08); }
.stat-icon { font-size: 28px; margin-bottom: 10px; }
.stat-value { font-size: 28px; font-weight: 800; color: #0f172a; }
.stat-value.green { color: #10b981; }
.stat-value.red   { color: #ef4444; }
.stat-label { font-size: 12px; color: #64748b; margin-top: 4px; }

/* Progress bar */
.progress-section { margin: 20px 0; }
.progress-header {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #64748b;
    margin-bottom: 8px;
}
.progress-track {
    background: #e2e8f0;
    border-radius: 100px;
    height: 10px;
    overflow: hidden;
}
.progress-fill {
    height: 100%;
    border-radius: 100px;
    background: linear-gradient(90deg, #1e3a8a, <?= $accentColor ?>);
    width: <?= $percentage ?>%;
    transition: width 1s ease;
}

/* Feedback */
.feedback-box {
    background: #f1f5f9;
    border-left: 4px solid #1e3a8a;
    border-radius: 12px;
    padding: 20px 24px;
    margin: 20px 0;
    display: flex;
    gap: 14px;
    align-items: flex-start;
}
.feedback-box i { color: #1e3a8a; font-size: 22px; margin-top: 2px; flex-shrink: 0; }
.feedback-box p { color: #475569; font-size: 14px; line-height: 1.6; }

/* Bottom buttons */
.bottom-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
    margin-top: 8px;
}
.btn {
    padding: 12px 24px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.2s;
}
.btn-primary {
    background: linear-gradient(135deg, #0f172a, #1e3a8a);
    color: white;
}
.btn-primary:hover { opacity: 0.9; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(30,58,138,0.35); }
.btn-secondary { background: #f1f5f9; color: #475569; }
.btn-secondary:hover { background: #e2e8f0; transform: translateY(-1px); }
.btn-logout { background: linear-gradient(135deg,#7f1d1d,#dc2626); color:#fff; }
.btn-logout:hover { opacity:.9; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(220,38,38,0.35); }

/* Test history */
.history-box { margin-top: 28px; }
.history-box h3 { font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 14px; display:flex; align-items:center; gap:8px; }
.history-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 12px; }
.history-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.history-table th { text-align: left; padding: 10px 14px; background: #f8fafc; color: #64748b; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #e2e8f0; }
.history-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; color: #1e293b; }
.history-table tr:last-child td { border-bottom: none; }
.history-table tr.current-row { background: #eff6ff; }
.hist-pill { display:inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.hist-pass { background: #d1fae5; color: #065f46; }
.hist-fail { background: #fee2e2; color: #991b1b; }

/* Warning */
.warn {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 10px;
    padding: 10px 16px;
    margin-bottom: 16px;
    font-size: 13px;
    color: #c2410c;
    display: <?= empty($course) ? 'flex' : 'none' ?>;
    align-items: center;
    gap: 8px;
}

/* Toast */
.toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #0f172a;
    color: white;
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 13px;
    z-index: 9999;
    animation: toastIn 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}
@keyframes toastIn {
    from { opacity:0; transform: translateY(12px); }
    to   { opacity:1; transform: translateY(0); }
}

/* Confetti */
#confetti-canvas { position: fixed; inset: 0; pointer-events: none; z-index: 9998; }

@media print {
    body { background: white; padding: 0; }
    .actions, .bottom-actions, #confetti-canvas { display: none; }
    .result-card { box-shadow: none; border-radius: 0; }
}
@media (max-width: 600px) {
    .student-strip { grid-template-columns: 1fr; }
    .strip-item { border-right: none; border-bottom: 1px solid #e2e8f0; }
    .strip-item:last-child { border-bottom: none; }
    .stats-grid { grid-template-columns: 1fr; }
    .bottom-actions { flex-direction: column; }
    .score-section { padding: 28px 20px; }
    .result-header { padding: 28px 20px; }
}
</style>
</head>
<body>

<canvas id="confetti-canvas"></canvas>

<div class="container">

    <!-- Action bar (top - subtle) -->
    <div class="actions">
        <button class="action-btn" onclick="exportPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="action-btn" onclick="exportCSV()"><i class="fas fa-file-csv"></i> CSV</button>
        <button class="action-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>

    <?php if (empty($course)): ?>
    <div class="warn"><i class="fas fa-exclamation-triangle"></i> Course information for this result couldn't be loaded — your score below is still accurate.</div>
    <?php endif; ?>

    <!-- Main card -->
    <div class="result-card" id="resultCard">

        <?php if ($autoSubmitted): ?>
        <div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:14px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:12px;">
            <span style="font-size:24px;">🚨</span>
            <div>
                <div style="font-weight:700;color:#dc2626;font-size:.95rem;">Test Auto-Submitted</div>
                <div style="color:#7f1d1d;font-size:.83rem;margin-top:2px;">Your test was automatically submitted by the proctoring system due to repeated monitoring violations.</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="result-header">
            <span class="emoji"><?= $emojiMap[$tier] ?></span>
            <h1>Test Completed!</h1>
            <div class="course-tag">
                <i class="fas fa-book"></i>
                <?= htmlspecialchars($course ?: 'Unknown Course') ?> &nbsp;·&nbsp; <?= htmlspecialchars($title) ?>
            </div>
        </div>

        <!-- Student strip -->
        <div class="student-strip">
            <div class="strip-item">
                <div class="strip-label">Student</div>
                <div class="strip-value"><?= htmlspecialchars($studentName) ?></div>
            </div>
            <div class="strip-item">
                <div class="strip-label">Matric No</div>
                <div class="strip-value"><?= htmlspecialchars($studentId) ?></div>
            </div>
            <div class="strip-item">
                <div class="strip-label">Date</div>
                <div class="strip-value"><?= $dateTaken ?></div>
            </div>
        </div>

        <!-- Score body -->
        <div class="score-section">

            <!-- Ring -->
            <div class="score-ring-wrap">
                <svg viewBox="0 0 180 180">
                    <circle cx="90" cy="90" r="80" class="ring-bg"/>
                    <circle cx="90" cy="90" r="80" class="ring-prog"/>
                </svg>
                <div class="ring-inner">
                    <div class="ring-pct"><?= $percentage ?><span>%</span></div>
                    <div class="ring-sub"><?= $score ?> / <?= $total ?> pts</div>
                </div>
            </div>

            <!-- Performance remark -->
            <div class="remark-section">
                <div class="remark-badge">
                    <span class="remark-text"><?= htmlspecialchars($remark) ?></span>
                </div>
                <div class="pass-pill <?= $passed ? 'is-pass' : 'is-fail' ?>">
                    <?= $passed ? '✅ Passed' : '❌ Not Passed' ?> · Pass Mark: <?= $passMark ?>%
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-icon">⏱️</div>
                    <div class="stat-value"><?= $minutes ?>:<?= str_pad($seconds, 2, '0', STR_PAD_LEFT) ?></div>
                    <div class="stat-label">Time Taken</div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon">✅</div>
                    <div class="stat-value green"><?= $correct ?></div>
                    <div class="stat-label">Correct</div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon">❌</div>
                    <div class="stat-value red"><?= $incorrect ?></div>
                    <div class="stat-label">Incorrect</div>
                </div>
            </div>

            <!-- Progress bar -->
            <div class="progress-section">
                <div class="progress-header">
                    <span>Score Accuracy</span>
                    <span><?= $percentage ?>%</span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill"></div>
                </div>
            </div>

            <!-- Feedback -->
            <div class="feedback-box">
                <i class="fas fa-lightbulb"></i>
                <p><?= $feedback ?></p>
            </div>

            <!-- Save Result Section -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:20px 24px;margin:20px 0;text-align:center;">
                <p style="font-size:13px;color:#64748b;margin-bottom:14px;font-weight:600;">
                    <i class="fas fa-download" style="color:#1e3a8a;margin-right:6px;"></i>
                    Save or share your result
                </p>
                <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                    <button onclick="exportPDF()" style="padding:12px 22px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white;border:none;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
                        <i class="fas fa-file-pdf"></i> Download as PDF
                    </button>
                    <button onclick="exportCSV()" style="padding:12px 22px;background:white;color:#1e3a8a;border:1.5px solid #1e3a8a;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
                        <i class="fas fa-file-csv"></i> Save as CSV
                    </button>
                    <button onclick="window.print()" style="padding:12px 22px;background:white;color:#475569;border:1.5px solid #e2e8f0;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <!-- Test History -->
            <div class="history-box">
                <h3><i class="fas fa-history" style="color:#1e3a8a"></i> Your Test History</h3>
                <?php if (empty($testHistory)): ?>
                <p style="color:#64748b;font-size:13px;">No previous attempts found.</p>
                <?php else: ?>
                <div class="history-table-wrap">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Test</th>
                                <th>Score</th>
                                <th>%</th>
                                <th>Result</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($testHistory as $h):
                                $hPass = (float)$h['percentage'] >= (float)$h['passing_score'];
                                $isCurrentAttempt = ((int)$h['score'] === $score) && ((int)$h['total'] === $total)
                                    && strcasecmp($h['course_code'], $course) === 0;
                            ?>
                            <tr class="<?= $isCurrentAttempt ? 'current-row' : '' ?>">
                                <td><strong><?= htmlspecialchars($h['course_code']) ?></strong><br><span style="color:#94a3b8;font-size:11px;"><?= htmlspecialchars($h['course_title']) ?></span></td>
                                <td><?= htmlspecialchars($h['test_title']) ?></td>
                                <td><?= (int)$h['score'] ?>/<?= (int)$h['total'] ?></td>
                                <td><?= number_format((float)$h['percentage'], 1) ?>%</td>
                                <td><span class="hist-pill <?= $hPass ? 'hist-pass' : 'hist-fail' ?>"><?= $hPass ? 'PASS' : 'FAIL' ?></span></td>
                                <td><?= $h['end_time'] ? date('M j, Y · g:i A', strtotime($h['end_time'])) : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Navigation Buttons -->
            <div class="bottom-actions">
                <a href="logout.php" class="btn btn-logout" id="finishLogoutBtn">
                    <i class="fas fa-power-off"></i> Finish &amp; Logout
                </a>
            </div>

        </div>
    </div>
</div>

<script src="assets/js/ui-notify.js"></script>
<script>
document.getElementById('finishLogoutBtn').addEventListener('click', async function(e) {
    e.preventDefault();
    const ok = await confirmDialog('You will be signed out of the portal.', {
        title: 'Finish & Log Out?',
        confirmText: 'Yes, Log Out',
        cancelText: 'Stay Here'
    });
    if (ok) window.location.href = 'logout.php';
});
const RESULT = {
    student:    <?= json_encode($studentName) ?>,
    studentId:  <?= json_encode($studentId) ?>,
    course:     <?= json_encode($course) ?>,
    title:      <?= json_encode($title) ?>,
    score:      <?= $score ?>,
    total:      <?= $total ?>,
    percentage: <?= $percentage ?>,
    remark:     <?= json_encode($remark) ?>,
    passed:     <?= json_encode($passed) ?>,
    passMark:   <?= json_encode($passMark) ?>,
    timeMins:   <?= $minutes ?>,
    timeSecs:   <?= $seconds ?>,
    correct:    <?= $correct ?>,
    incorrect:  <?= $incorrect ?>,
    date:       <?= json_encode($dateTaken) ?>,
};

function exportPDF() {
    html2pdf().set({
        margin: [0.5, 0.5],
        filename: `result_${RESULT.course}_${RESULT.studentId}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    }).from(document.getElementById('resultCard')).save();
    showToast('PDF downloaded');
}

function exportCSV() {
    const rows = [
        ['Field', 'Value'],
        ['Student Name', RESULT.student],
        ['Matric Number', RESULT.studentId],
        ['Course', RESULT.course],
        ['Test Title', RESULT.title],
        ['Score', `${RESULT.score}/${RESULT.total}`],
        ['Percentage', RESULT.percentage + '%'],
        ['Result', RESULT.passed ? `Passed (Pass Mark: ${RESULT.passMark}%)` : `Not Passed (Pass Mark: ${RESULT.passMark}%)`],
        ['Correct', RESULT.correct],
        ['Incorrect', RESULT.incorrect],
        ['Time Taken', `${RESULT.timeMins}m ${String(RESULT.timeSecs).padStart(2,'0')}s`],
        ['Date', RESULT.date],
    ];
    const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `result_${RESULT.course}_${RESULT.studentId}.csv`;
    a.click();
    showToast('CSV downloaded');
}

function showToast(msg) {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const t = document.createElement('div');
    t.className = 'toast';
    t.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981"></i> ' + msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}

<?php if ($tier === 'excellent'): ?>
(function() {
    const canvas = document.getElementById('confetti-canvas');
    const ctx = canvas.getContext('2d');
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    const colors = ['#1e3a8a','#10b981','#f59e0b','#3b82f6','#ffffff'];
    const particles = Array.from({length: 130}, () => ({
        x: Math.random() * canvas.width,
        y: -20 - Math.random() * canvas.height * 0.5,
        w: Math.random() * 7 + 3,
        h: Math.random() * 14 + 5,
        r: Math.random() * Math.PI * 2,
        vy: Math.random() * 4 + 2,
        vx: (Math.random() - 0.5) * 2,
        vr: (Math.random() - 0.5) * 0.12,
        color: colors[Math.floor(Math.random() * colors.length)],
    }));
    let frame = 0;
    function draw() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        let alive = false;
        for (const p of particles) {
            p.y += p.vy; p.x += p.vx; p.r += p.vr;
            if (p.y < canvas.height + 20) {
                alive = true;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.r);
                ctx.fillStyle = p.color;
                ctx.globalAlpha = 0.85;
                ctx.fillRect(-p.w/2, -p.h/2, p.w, p.h);
                ctx.restore();
            }
        }
        if (++frame < 220 && alive) requestAnimationFrame(draw);
        else ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
    draw();
})();
<?php endif; ?>
</script>
</body>
</html>
