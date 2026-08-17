<?php
/**
 * Student Dashboard - CS Dept CA Portal
 * Version: 2.0 (High Security)
 * Enforcement: Strict Face-Auth Session
 */
session_start();
require_once 'includes/config.php';

// ── SESSION TOKEN HELPER ─────────────────────────────────────
function clearStudentSessionToken($pdo, $matric, $token) {
    if (!$matric || !$token) return;
    try {
        $stmt = $pdo->prepare("UPDATE students SET session_token = NULL, session_token_created_at = NULL WHERE matric = ? AND session_token = ?");
        $stmt->execute([$matric, $token]);
    } catch (Exception $e) { /* silent fail */ }
}
// ────────────────────────────────────────────────────────────

// ── Helper: render a full portal-closed page and die ─────────
function renderPortalClosed($message) {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal Closed — CS Dept CA Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:linear-gradient(135deg,#0f172a,#1e3a8a);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{background:white;border-radius:24px;padding:48px 40px;max-width:480px;width:100%;text-align:center;box-shadow:0 25px 50px rgba(0,0,0,.4)}
.icon{width:72px;height:72px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:32px;color:#ef4444}
h1{font-size:1.4rem;font-weight:700;color:#0f172a;margin-bottom:10px}
p{color:#64748b;font-size:14px;line-height:1.6;margin-bottom:24px}
a{display:inline-block;padding:12px 24px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px}
</style></head><body>
<div class="box">
  <div class="icon"><i class="fas fa-lock"></i></div>
  <h1>Portal Temporarily Closed</h1>
  <p>' . $message . '</p>
  <a href="index.php"><i class="fas fa-arrow-left"></i> &nbsp;Back to Home</a>
</div></body></html>';
}
// ─────────────────────────────────────────────────────────────


// 1. SECURITY CHECK: Verify student passed the face scan
if (!isset($_SESSION['verified']) || $_SESSION['verified'] !== true) {
    header('Location: index.php?error=unauthorized');
    exit;
}

// 2. IDENTITY LOCK: Use the session matric, NOT the URL parameter
$matric = $_SESSION['authenticated_matric']; 

// ── PORTAL CONTROL: Check if students are blocked ────────────
$accessBlock = getAccessBlock('student');
if ($accessBlock) {
    clearStudentSessionToken($pdo, $_SESSION['student_matric'] ?? null, $_SESSION['session_token'] ?? null);
    session_destroy();
    renderAccessBlockPage($accessBlock, 'student', 'index.php');
}
// ─────────────────────────────────────────────────────────────


try {
    // 3. FETCH STUDENT PROFILE
    $stmt = $pdo->prepare("SELECT matric, full_name, level FROM students WHERE matric = ? LIMIT 1");
    $stmt->execute([$matric]);
    $student = $stmt->fetch();

    if (!$student) {
        clearStudentSessionToken($pdo, $_SESSION['student_matric'] ?? null, $_SESSION['session_token'] ?? null);
        session_destroy();
        header('Location: index.php?error=not_found');
        exit;
    }

    $studentName  = $student['full_name'];
    $studentLevel = (int)$student['level'];
    
    // UI Metadata
    $profilePic      = 'https://ui-avatars.com/api/?name=' . urlencode($studentName) . '&background=1e3a8a&color=fff&size=200';
    $academicSession = '2025/2026';
    $semester        = '1st Semester';

    // 4. FETCH ACTIVE TEST FOR STUDENT'S LEVEL (general only — custom tests are link-access only)
    $stmt = $pdo->prepare(
        "SELECT id, course_code, course_title, test_title, total_questions, duration_minutes, 
                time_per_question, max_attempts, start_date, expiry_date
         FROM tests 
         WHERE level = ? AND is_active = 1
         AND access_type = 'general'
         AND (start_date IS NULL OR start_date <= NOW())
         AND (expiry_date IS NULL OR expiry_date >= NOW())
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$studentLevel]);
    $test = $stmt->fetch();

    $testAvailable = ($test !== false && !empty($test));
    $alreadyTaken  = false;
    $attemptsUsed  = 0;
    $retakeApproved = false;

    // ── ATTEMPT CHECK: 1 attempt only, retake requires lecturer approval ──
    if ($testAvailable) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attempts WHERE student_matric = ? AND test_id = ? AND status = 'completed'");
        $stmt->execute([$matric, $test['id']]);
        $attemptsUsed = (int)$stmt->fetchColumn();

        if ($attemptsUsed >= 1) {
            // Student has already taken this test at least once
            $alreadyTaken  = true;
            $testAvailable = false;

            // Check if lecturer has approved a retake (unused)
            $retakeStmt = $pdo->prepare("SELECT id FROM retake_approvals WHERE student_matric = ? AND test_id = ? AND used = 0 LIMIT 1");
            $retakeStmt->execute([$matric, $test['id']]);
            if ($retakeStmt->fetch()) {
                // ✅ Retake approved — unlock test and mark approval used immediately
                $alreadyTaken   = false;
                $testAvailable  = true;
                $retakeApproved = true;
                $pdo->prepare("UPDATE retake_approvals SET used = 1, used_at = NOW() WHERE student_matric = ? AND test_id = ? AND used = 0")
                    ->execute([$matric, $test['id']]);
                // Delete old attempt so new score replaces it
                $pdo->prepare("DELETE FROM attempts WHERE student_matric = ? AND test_id = ?")
                    ->execute([$matric, $test['id']]);
                $attemptsUsed = 0;
            }
        }
    }
    // ──────────────────────────────────────────────────────────────────────
    
    if (!$testAvailable && !$alreadyTaken) {
        $test = [
            'id' => 0,
            'course_code' => 'N/A',
            'course_title' => 'No active test available for your level',
            'total_questions' => 0,
            'duration_minutes' => 0,
            'time_per_question' => 0
        ];
    } elseif ($alreadyTaken) {
        $test['course_title'] = 'You have already completed this test.';
    }

    // Fetch past test history for performance chart
    $stmtHist = $pdo->prepare("
        SELECT a.percentage, a.score, a.total, t.course_code, t.test_title, t.passing_score, a.end_time
        FROM attempts a
        JOIN tests t ON a.test_id = t.id
        WHERE a.student_matric = ? AND a.status = 'completed'
        ORDER BY a.end_time ASC
    ");
    $stmtHist->execute([$matric]);
    $pastAttempts = $stmtHist->fetchAll();

} catch (PDOException $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    die("A technical error occurred. Please contact the CS Dept Admin.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - CA Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: linear-gradient(to bottom right, #0f172a, #1e3a8a, #0f172a);
            min-height: 100vh;
            color: #1e293b;
            padding: 20px;
        }
        .container { max-width: 800px; margin: 20px auto; }
        .card {
            background: white;
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        .logout-btn {
            background: #f1f5f9;
            color: #ef4444;
            border: 1px solid #fecaca;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.2s;
            text-decoration: none;
        }
        .logout-btn:hover { background: #fee2e2; }
        
        .session-banner {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 12px;
            border-radius: 12px;
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 8px 16px;
            margin-bottom: 30px;
            font-size: 0.85rem;
        }

        .profile-section {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 35px;
        }
        .avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 4px solid #1e3a8a;
        }

        .test-card {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 16px;
            padding: 25px;
        }
        .test-info-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .info-box {
            background: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        .info-label { font-size: 0.75rem; color: #64748b; display: block; margin-bottom: 5px; }
        .info-value { font-size: 1.2rem; font-weight: 800; color: #1e3a8a; }

        .instructions {
            background: white;
            padding: 20px;
            border-radius: 12px;
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 25px;
        }
        .start-btn {
            display: block;
            width: 100%;
            padding: 18px;
            background: linear-gradient(to right, #1e3a8a, #2563eb);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .start-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.3);
        }
        .start-btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }
        .badge {
            background: #10b981;
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            text-transform: uppercase;
        }
        .badge-closed {
            background: #ef4444;
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            text-transform: uppercase;
        }
        .badge-warning {
            background: #f59e0b;
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            text-transform: uppercase;
        }
        .attempt-info {
            background: #fff3cd;
            border: 1px solid #f59e0b;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.85rem;
            color: #92400e;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="header">
            <div style="display:flex; align-items:center; gap:12px;">
                <img src="assets/images/faculty-logo.png" alt="Faculty of Computing" style="width:48px;height:48px;object-fit:contain;flex-shrink:0;">
                <div>
                <h1 style="color:#0f172a">Student Portal
                    <span style="display:inline-flex;align-items:center;gap:5px;background:#dbeafe;color:#1a4fd8;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;vertical-align:middle;margin-left:8px;letter-spacing:.4px;border:1px solid #bfdbfe;">
                        <i class="fas fa-user-graduate"></i> STUDENT
                    </span>
                </h1>
                <p style="font-size:0.8rem; color:#64748b">Faculty of Computing &nbsp;·&nbsp; Computer Science Department</p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                <?php $notifApiPath = 'api/notifications.php'; $notifViewAllPath = 'notifications.php'; require __DIR__ . '/includes/notification-bell.php'; ?>
                <a href="logout.php" class="logout-btn"><i class="fa-solid fa-power-off"></i> Logout</a>
            </div>
        </div>

        <div class="session-banner">
            <span><i class="fa-regular fa-calendar"></i> Session: <b><?= $academicSession ?></b></span>
            <span><i class="fa-solid fa-graduation-cap"></i> Semester: <b><?= $semester ?></b></span>
            <span><i class="fa-regular fa-clock"></i> <b id="liveClock"><?= date('D, d M Y \a\t g:i:s A') ?></b></span>
        </div>

        <div class="profile-section">
            <img src="<?= $profilePic ?>" class="avatar" alt="User" onerror="this.src='https://ui-avatars.com/api/?name=Student&background=1e3a8a&color=fff&size=200'">
            <div>
                <h2 style="margin-bottom:4px;"><?= htmlspecialchars($studentName) ?></h2>
                <p style="color:#64748b; font-size:0.9rem;">
                    Matric: <b><?= htmlspecialchars($matric) ?></b> | 
                    Level: <b><?= $studentLevel ?>L</b>
                </p>
            </div>
        </div>

        <h3 style="margin-bottom:15px; display:flex; align-items:center; gap:10px;">
            <i class="fa-solid fa-file-pen" style="color:#1e3a8a"></i> 
            Active Assessment
        </h3>

        <div class="test-card">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div>
                    <h3 style="color:#1e3a8a"><?= htmlspecialchars($test['course_code']) ?></h3>
                    <p style="color:#475569; font-weight:500;"><?= htmlspecialchars($test['course_title']) ?></p>
                </div>
                <?php if($alreadyTaken): ?>
                    <span class="badge-warning">Taken</span>
                <?php elseif($testAvailable): ?>
                    <span class="badge">Open</span>
                <?php else: ?>
                    <span class="badge-closed">Closed</span>
                <?php endif; ?>
            </div>

            <?php if ($alreadyTaken): ?>
            <div class="attempt-info">
                🔒 You have already completed this test.
            </div>
            <?php elseif ($retakeApproved): ?>
            <div class="attempt-info" style="background:#f0fdf4;border-color:#86efac;color:#166534">
                ✅ This test has been unlocked for you. Good luck!
            </div>
            <?php endif; ?>

            <div class="test-info-row">
                <div class="info-box">
                    <span class="info-label">Questions</span>
                    <span class="info-value"><?= $test['total_questions'] ?></span>
                </div>
                <div class="info-box">
                    <span class="info-label">Minutes</span>
                    <span class="info-value"><?= $test['duration_minutes'] ?></span>
                </div>
                <div class="info-box">
                    <span class="info-label">Avg/Quest</span>
                    <span class="info-value"><?= $test['time_per_question'] ?>s</span>
                </div>
            </div>

            <div class="instructions">
                <b style="color:#0f172a">Examination Instructions:</b>
                <ul style="margin-top:10px; padding-left:18px; color:#475569">
                    <li>Ensure you have a stable internet connection.</li>
                    <li>The timer starts the moment you click "Start Test".</li>
                    <li>The test will auto-submit when the timer reaches zero.</li>
                    <li>Leaving the browser tab may result in automatic disqualification.</li>
                </ul>
            </div>

            <button class="start-btn" 
                    onclick="beginTest()" 
                    <?= (!$testAvailable || $alreadyTaken) ? 'disabled' : '' ?>>
                <?= $alreadyTaken ? '🔒 Test Already Taken' : ($retakeApproved ? 'Start Test Now' : ($testAvailable ? 'Start Test Now' : 'No Assessment Available')) ?>
            </button>

        </div>
    </div>
</div>

<?php if (!empty($pastAttempts)): ?>
<!-- ── PERFORMANCE CHART CARD ── -->
<div style="max-width:800px;margin:24px auto 0;padding:0 20px;">
<div style="background:white;border-radius:24px;padding:32px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;border-bottom:1px solid #e2e8f0;padding-bottom:16px;">
        <div>
            <h3 style="font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:4px;">
                <i class="fas fa-chart-line" style="color:#1e3a8a;margin-right:8px;"></i>My Performance History
            </h3>
            <p style="font-size:12px;color:#64748b;"><?= count($pastAttempts) ?> test<?= count($pastAttempts) !== 1 ? 's' : '' ?> completed</p>
        </div>
        <!-- Summary pills -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php
                $avgScore = round(array_sum(array_column($pastAttempts,'percentage')) / count($pastAttempts),1);
                $best     = round(max(array_column($pastAttempts,'percentage')),1);
            ?>
            <span style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;">
                Avg: <?= $avgScore ?>%
            </span>
            <span style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;">
                Best: <?= $best ?>%
            </span>
        </div>
    </div>

    <!-- Chart canvas -->
    <div style="position:relative;height:220px;">
        <canvas id="perfChart"></canvas>
    </div>

    <!-- Past results table -->
    <div style="margin-top:24px;overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:8px 12px;background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#0f172a;font-weight:700;">#</th>
                    <th style="text-align:left;padding:8px 12px;background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#0f172a;font-weight:700;">Course</th>
                    <th style="text-align:left;padding:8px 12px;background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#0f172a;font-weight:700;">Test</th>
                    <th style="text-align:center;padding:8px 12px;background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#0f172a;font-weight:700;">Score</th>
                    <th style="text-align:center;padding:8px 12px;background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#0f172a;font-weight:700;">%</th>
                    <th style="text-align:center;padding:8px 12px;background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#0f172a;font-weight:700;">Status</th>
                    <th style="text-align:left;padding:8px 12px;background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#0f172a;font-weight:700;">Date</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sn = 1;
            foreach(array_reverse($pastAttempts) as $pa):
                $pct = round((float)$pa['percentage'],1);
                $passed = $pct >= (float)($pa['passing_score'] ?? 50);
                $caScore = $pa['total'] > 0 ? round(($pa['score']/$pa['total'])*30,1) : 0;
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:10px 12px;color:#64748b;"><?= $sn++ ?></td>
                <td style="padding:10px 12px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($pa['course_code']) ?></td>
                <td style="padding:10px 12px;color:#475569;"><?= htmlspecialchars($pa['test_title']) ?></td>
                <td style="padding:10px 12px;text-align:center;color:#0f172a;font-weight:600;"><?= (int)$pa['score'] ?>/<?= (int)$pa['total'] ?> <small style="color:#94a3b8;">(<?= $caScore ?>/30)</small></td>
                <td style="padding:10px 12px;text-align:center;font-weight:700;color:#1e3a8a;"><?= $pct ?>%</td>
                <td style="padding:10px 12px;text-align:center;">
                    <span style="background:<?= $passed ? '#dcfce7' : '#fee2e2' ?>;color:<?= $passed ? '#15803d' : '#991b1b' ?>;font-weight:800;padding:3px 10px;border-radius:20px;font-size:11px;"><?= $passed ? 'PASS' : 'FAIL' ?></span>
                </td>
                <td style="padding:10px 12px;color:#64748b;"><?= $pa['end_time'] ? date('d M Y', strtotime($pa['end_time'])) : 'N/A' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>
</div>

<script src="assets/js/ui-notify.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
(function() {
    const labels  = <?= json_encode(array_map(fn($a) => htmlspecialchars($a['course_code']), $pastAttempts)) ?>;
    const scores  = <?= json_encode(array_map(fn($a) => round((float)$a['percentage'],1), $pastAttempts)) ?>;
    const caScores = <?= json_encode(array_map(fn($a) => $a['total'] > 0 ? round(($a['score']/$a['total'])*30,1) : 0, $pastAttempts)) ?>;

    const ctx = document.getElementById('perfChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Score (%)',
                    data: scores,
                    backgroundColor: scores.map(s =>
                        s >= 70 ? 'rgba(16,185,129,0.7)' :
                        s >= 50 ? 'rgba(59,130,246,0.7)' :
                                  'rgba(239,68,68,0.7)'
                    ),
                    borderColor: scores.map(s =>
                        s >= 70 ? '#10b981' :
                        s >= 50 ? '#3b82f6' :
                                  '#ef4444'
                    ),
                    borderWidth: 2,
                    borderRadius: 8,
                    yAxisID: 'y',
                },
                {
                    label: 'CA Mark (/30)',
                    data: caScores,
                    type: 'line',
                    borderColor: '#1e3a8a',
                    backgroundColor: 'rgba(30,58,138,0.08)',
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
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { font: { size: 12, family: 'Inter, sans-serif' }, usePointStyle: true }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.datasetIndex === 0
                            ? ` Score: ${ctx.parsed.y}%`
                            : ` CA Mark: ${ctx.parsed.y}/30`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    min: 0, max: 100,
                    ticks: { callback: v => v + '%', font: { size: 11 } },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                y2: {
                    type: 'linear',
                    position: 'right',
                    min: 0, max: 30,
                    ticks: { callback: v => v + '/30', font: { size: 11 } },
                    grid: { drawOnChartArea: false }
                },
                x: {
                    ticks: { font: { size: 11 } },
                    grid: { display: false }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

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

async function beginTest() {
    const testId = <?= json_encode($test['id']) ?>;
    const matric = <?= json_encode($matric) ?>;
    const ok = await confirmDialog('The timer will begin immediately once you start.', {
        title: 'Start Test?',
        confirmText: 'Yes, Start Now',
        cancelText: 'Not Yet'
    });
    if (ok) {
        window.location.href = 'take-test.php?test_id=' + testId + '&matric=' + encodeURIComponent(matric);
    }
}

// Live portal-control check — if an admin blocks students or closes the portal
// while this dashboard is open, log the student out immediately so they must
// log back in once access is restored.
setInterval(function() {
    fetch('api/portal-status.php?role=student')
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