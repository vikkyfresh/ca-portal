<?php
session_start();
require_once 'includes/config.php';

$matric = strtoupper(trim($_SESSION['authenticated_matric'] ?? $_SESSION['student_matric'] ?? ''));
$requestedMatric = strtoupper(trim($_GET['matric'] ?? ''));
$testId = intval($_GET['test_id'] ?? 0);

if (!$matric || !preg_match('/^\d{2}CS\d{4}$/', $matric)) {
    header('Location: student-login.php'); exit;
}
if ($requestedMatric && $requestedMatric !== $matric) {
    header('Location: dashboard.php'); exit;
}
if (!$testId) { header('Location: dashboard.php'); exit; }

// ── CHECK require_face_verify FOR THIS TEST ───────────────────
$faceReqStmt = $pdo->prepare("SELECT require_face_verify FROM tests WHERE id = ? LIMIT 1");
$faceReqStmt->execute([$testId]);
$faceReqRow = $faceReqStmt->fetch();
$testRequiresFace = ($faceReqRow === false) ? true : (bool)$faceReqRow['require_face_verify'];

// Only enforce face_verified session if this test requires it
if ($testRequiresFace && empty($_SESSION['face_verified'])) {
    header('Location: student-login.php'); exit;
}
// ─────────────────────────────────────────────────────────────

// ── FACE ENROLLMENT GATE ─────────────────────────────────────
// Only redirect to enroll if this test actually requires face verification
if ($testRequiresFace) {
    $faceCheckStmt = $pdo->prepare("SELECT face_descriptor FROM students WHERE matric = ? LIMIT 1");
    $faceCheckStmt->execute([$matric]);
    $faceCheckRow = $faceCheckStmt->fetch();
    if ($faceCheckRow && empty($faceCheckRow['face_descriptor'])) {
        header('Location: face-enroll-required.php?test_id=' . $testId);
        exit;
    }
}
// ─────────────────────────────────────────────────────────────

// ── PORTAL CONTROL: testing_open check ──────────────────────
$stmtPC = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('testing_open','testing_closed_message','portal_open','students_blocked','portal_closed_message')");
$pcfg = $stmtPC->fetchAll(PDO::FETCH_KEY_PAIR);
if (($pcfg['portal_open'] ?? '1') !== '1') {
    $pmsg = htmlspecialchars($pcfg['portal_closed_message'] ?? 'The portal is currently closed.');
    die('<div style="font-family:sans-serif;background:#0f172a;min-height:100vh;display:flex;align-items:center;justify-content:center"><div style="background:white;border-radius:20px;padding:40px;max-width:460px;text-align:center"><div style="font-size:48px;margin-bottom:16px">🔒</div><h2 style="color:#0f172a;margin-bottom:10px">Portal Closed</h2><p style="color:#64748b;margin-bottom:24px">'.$pmsg.'</p><a href="dashboard.php" style="background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:700">← Back to Dashboard</a></div></div>');
}
if (($pcfg['students_blocked'] ?? '0') === '1') {
    die('<div style="font-family:sans-serif;background:#0f172a;min-height:100vh;display:flex;align-items:center;justify-content:center"><div style="background:white;border-radius:20px;padding:40px;max-width:460px;text-align:center"><div style="font-size:48px;margin-bottom:16px">⛔</div><h2 style="color:#0f172a;margin-bottom:10px">Access Restricted</h2><p style="color:#64748b;margin-bottom:24px">Student access is currently restricted. Please check back later.</p><a href="dashboard.php" style="background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:700">← Back</a></div></div>');
}
if (($pcfg['testing_open'] ?? '1') !== '1') {
    $tmsg = htmlspecialchars($pcfg['testing_closed_message'] ?? 'Tests are not currently available.');
    die('<div style="font-family:sans-serif;background:#0f172a;min-height:100vh;display:flex;align-items:center;justify-content:center"><div style="background:white;border-radius:20px;padding:40px;max-width:460px;text-align:center"><div style="font-size:48px;margin-bottom:16px">📋</div><h2 style="color:#0f172a;margin-bottom:10px">Tests Unavailable</h2><p style="color:#64748b;margin-bottom:24px">'.$tmsg.'</p><a href="dashboard.php" style="background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:700">← Back to Dashboard</a></div></div>');
}
// ─────────────────────────────────────────────────────────────



// Fetch test details
// Fetch student name for result page
$stmtStu = $pdo->prepare("SELECT full_name FROM students WHERE matric = ? LIMIT 1");
$stmtStu->execute([$matric]);
$studentRow = $stmtStu->fetch();
$studentFullName = $studentRow['full_name'] ?? $matric;

$stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND is_active = 1");
$stmt->execute([$testId]);
$test = $stmt->fetch();
if (!$test) { die("Test not found or not active."); }

// ✅ CHECK 1: Test expiry date
if (!empty($test['expiry_date']) && strtotime($test['expiry_date']) < time()) {
    die("
    <!DOCTYPE html>
    <html><head><title>Test Expired</title>
    <!-- Liveness Monitor -->
    <script defer src='assets/js/face-api.min.js'></script>
    <script defer src='assets/js/liveness-monitor.js'></script>
    <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f1f5f9;margin:0;}
    .card{background:white;padding:40px;border-radius:16px;text-align:center;box-shadow:0 4px 6px rgba(0,0,0,.1);max-width:500px;}
    h1{color:#ef4444;}p{color:#64748b;}.btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#0f172a;color:white;text-decoration:none;border-radius:8px;}</style></head>
    <body><div class='card'><h1>❌ Test Expired</h1><p>This test window has closed. The deadline was:</p><p><strong>" . date('M d, Y - h:i A', strtotime($test['expiry_date'])) . "</strong></p><a href='dashboard.php' class='btn'>Back to Dashboard</a></div></body></html>");
}

// ✅ CHECK 2: Test hasn't started yet
if (!empty($test['start_date']) && strtotime($test['start_date']) > time()) {
    die("
    <!DOCTYPE html>
    <html><head><title>Test Not Started</title>
    <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f1f5f9;margin:0;}
    .card{background:white;padding:40px;border-radius:16px;text-align:center;box-shadow:0 4px 6px rgba(0,0,0,.1);max-width:500px;}
    h1{color:#3b82f6;}p{color:#64748b;}.btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#0f172a;color:white;text-decoration:none;border-radius:8px;}</style></head>
    <body><div class='card'><h1>⏳ Test Not Started</h1><p>This test is scheduled to start at:</p><p><strong>" . date('M d, Y - h:i A', strtotime($test['start_date'])) . "</strong></p><a href='dashboard.php' class='btn'>Back to Dashboard</a></div></body></html>");
}

// ✅ CHECK 3: Max attempts reached (a lecturer-approved, unused retake overrides this)
if ($test['max_attempts'] > 0) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attempts WHERE student_matric = ? AND test_id = ? AND status = 'completed'");
    $stmt->execute([$matric, $testId]);
    $attemptCount = $stmt->fetchColumn();

    $retakeStmt = $pdo->prepare("SELECT id FROM retake_approvals WHERE student_matric = ? AND test_id = ? AND used = 0 LIMIT 1");
    $retakeStmt->execute([$matric, $testId]);
    $hasApprovedRetake = (bool)$retakeStmt->fetch();

    if (!$hasApprovedRetake && $attemptCount >= $test['max_attempts']) {
        die("
        <!DOCTYPE html>
        <html><head><title>Max Attempts</title>
        <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f1f5f9;margin:0;}
        .card{background:white;padding:40px;border-radius:16px;text-align:center;box-shadow:0 4px 6px rgba(0,0,0,.1);max-width:500px;}
        h1{color:#ef4444;}p{color:#64748b;}.btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#0f172a;color:white;text-decoration:none;border-radius:8px;}</style></head>
        <body><div class='card'><h1>❌ No Attempts Left</h1><p>You have used all <strong>{$test['max_attempts']}</strong> attempt(s) for this test.</p><p>Your attempts: <strong>$attemptCount</strong></p><a href='dashboard.php' class='btn'>Back to Dashboard</a></div></body></html>");
    }
}

// ✅ FETCH QUESTIONS BY COURSE CODE FROM SHARED QUESTION BANK
$courseCode = $test['course_code'];
$limit = (int)$test['total_questions'];
if ($limit <= 0) $limit = 20;

// Count available questions
$totalAvailStmt = $pdo->prepare("SELECT COUNT(*) FROM course_questions WHERE course_code = ?");
$totalAvailStmt->execute([$courseCode]);
$totalAvailable = $totalAvailStmt->fetchColumn();
if ($totalAvailable < $limit) $limit = (int)$totalAvailable;

$stmt = $pdo->prepare("SELECT id, question as question_text, image_url, option_a, option_b, option_c, option_d, correct_option 
    FROM course_questions WHERE course_code = ? ORDER BY RAND() LIMIT {$limit}");
$stmt->execute([$courseCode]);
$dbQuestions = $stmt->fetchAll();

if (empty($dbQuestions)) { 
    die("No questions found for $courseCode. Please add questions to the question bank."); 
}

$_SESSION['active_test_questions'][$testId] = array_map('intval', array_column($dbQuestions, 'id'));

$totalQuestions = count($dbQuestions);
$testDuration = (int)$test['duration_minutes'];
$courseCodeDisp = htmlspecialchars($test['course_code']);
$courseTitle = htmlspecialchars($test['course_title']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test - <?= $courseCodeDisp ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(to bottom right, #0f172a, #1e3a8a, #0f172a); min-height: 100vh; padding: 12px; }
        .test-container { max-width: 850px; margin: 0 auto; background: white; border-radius: 24px; padding: 24px 20px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .test-header { display: flex; flex-direction: column; gap: 12px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0; }
        .test-info h1 { font-size: 1.1rem; color: #0f172a; }
        .test-info p { color: #64748b; font-size: 0.85rem; }
        .timer { display: flex; align-items: center; justify-content: flex-end; gap: 8px; background: #0f172a; color: white; padding: 10px 20px; border-radius: 40px; font-size: 1.3rem; font-weight: 700; font-family: 'Courier New', monospace; }
        .timer.warning { background: #f59e0b; animation: pulse 1s infinite; }
        .timer.danger { background: #ef4444; animation: pulse 0.5s infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.7; } }
        .progress-bar { height: 6px; background: #e2e8f0; border-radius: 3px; margin-bottom: 20px; overflow: hidden; }
        .progress-fill { height: 100%; background: #10b981; border-radius: 3px; width: 0%; transition: width 0.3s; }
        .question-text { font-size: 1.1rem; font-weight: 600; color: #0f172a; margin-bottom: 20px; line-height: 1.5; }
        .options-list { display: flex; flex-direction: column; gap: 10px; }
        .option-item { padding: 14px 16px; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 12px; }
        .option-item:hover { background: #f1f5f9; border-color: #cbd5e1; }
        .option-item.selected { background: #dbeafe; border-color: #3b82f6; }
        .option-item input[type="radio"] { width: 18px; height: 18px; accent-color: #3b82f6; cursor: pointer; }
        .option-item label { flex: 1; cursor: pointer; color: #1e293b; font-size: 1rem; }
        .navigation { display: flex; flex-direction: column; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
        .nav-buttons { display: flex; gap: 8px; justify-content: space-between; }
        .nav-btn { flex: 1; padding: 12px; border: none; border-radius: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; }
        .prev-btn, .next-btn { background: #0f172a; color: white; }
        .prev-btn:disabled, .next-btn:disabled { background: #94a3b8; cursor: not-allowed; }
        .submit-btn { width: 100%; padding: 14px; background: #10b981; color: white; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all .2s; }
        .submit-btn:disabled { background: #94a3b8; cursor: not-allowed; opacity: .75; }
        .answer-counter { text-align:center; font-size:.82rem; color:#64748b; margin-bottom:5px; font-weight:500; }
        .answer-counter strong { color:#0f172a; }
        .submit-btn:hover:not(:disabled) { background: #059669; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 101; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.active { display: flex; }
        .modal-card { background: white; border-radius: 20px; padding: 24px; max-width: 400px; width: 100%; text-align: center; }
        .modal-card h3 { margin-bottom: 12px; color: #0f172a; }
        .modal-card p { color: #64748b; margin-bottom: 20px; }
        .modal-buttons { display: flex; gap: 10px; }
        .modal-buttons button { flex: 1; padding: 12px; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; }
        .cancel-btn { background: #f1f5f9; color: #64748b; }
        .confirm-btn { background: #10b981; color: white; }
        /* Disable text selection during test */
        .question-text, .option-item label, .options-list {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        /* push test container down on mobile so banner has space */
        #liveness-banner ~ .test-container { margin-top: 10px; }
        @media (min-width: 640px) { body { padding: 20px; } .test-container { padding: 30px; } .test-header { flex-direction: row; justify-content: space-between; align-items: center; } .test-info h1 { font-size: 1.3rem; } .navigation { flex-direction: row; align-items: center; } .nav-buttons { flex: 2; } .submit-btn { flex: 1; } }
    </style>
    <script defer src="assets/js/face-api.min.js"></script>
    <script defer src="assets/js/liveness-monitor.js"></script>
    <script src="assets/js/ui-notify.js"></script>
</head>
<body>
    <div class="test-container">
        <div class="test-header">
            <div class="test-info">
                <h1><?= $courseCodeDisp ?> - <?= $courseTitle ?></h1>
                <p>Question <span id="currentQ">1</span> of <?= $totalQuestions ?></p>
            </div>
            <div class="timer" id="timer"><span>⏱</span> <span id="timerDisplay"><?= $testDuration ?>:00</span></div>
        </div>
        <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
        <div class="question-text" id="questionText"></div>
        <div class="options-list" id="optionsList"></div>
        <div class="navigation">
            <div class="nav-buttons">
                <button class="nav-btn prev-btn" id="prevBtn" disabled>◀ Previous</button>
                <button class="nav-btn next-btn" id="nextBtn">Next ▶</button>
            </div>
            <div style="flex:1;display:flex;flex-direction:column;gap:5px;">
                <div class="answer-counter" id="answerCounter">
                    Answered: <strong id="answeredDisplay">0</strong> / <?= $totalQuestions ?>
                    &nbsp;·&nbsp; <span id="thresholdMsg" style="color:#ef4444;font-weight:600;">Need <?= ceil($totalQuestions * 0.5) ?> to submit</span>
                </div>
                <button class="submit-btn" id="submitBtn" disabled>📩 Submit Test</button>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="submitModal">
        <div class="modal-card">
            <h3>Submit Test?</h3>
            <p>You have <strong id="answeredCount">0</strong> of <?= $totalQuestions ?> questions answered.</p>
            <div class="modal-buttons">
                <button class="cancel-btn" id="cancelSubmit">Cancel</button>
                <button class="confirm-btn" id="confirmSubmit">Submit</button>
            </div>
        </div>
    </div>
    
    <script>
        const questions = <?= json_encode($dbQuestions) ?>;
        const totalQ = questions.length;
        const duration = <?= $testDuration * 60 ?>;
        const matric = '<?= $matric ?>';
        const testId = <?= $testId ?>;
        const course = '<?= $test['course_code'] ?>';
        const courseTitle = '<?= $test['course_title'] ?>';
        const studentName = '<?= htmlspecialchars($studentFullName, ENT_QUOTES) ?>';
        const studentMatric = '<?= htmlspecialchars($matric, ENT_QUOTES) ?>';
        
        let currentIndex = 0;
        let answers = {};
        let timeLeft = duration;
        let submitted = false;
        let timerInterval;
        
        function updateTimer() {
            const m = Math.floor(timeLeft / 60);
            const s = timeLeft % 60;
            document.getElementById('timerDisplay').textContent = `${m}:${s.toString().padStart(2, '0')}`;
            const timerEl = document.getElementById('timer');
            if (timeLeft <= 60) timerEl.className = 'timer danger';
            else if (timeLeft <= 300) timerEl.className = 'timer warning';
        }
        
        const THRESHOLD = Math.ceil(totalQ * 0.5); // 50% of questions

        function updateProgress() {
            const answered = Object.keys(answers).length;
            const pct = answered / totalQ * 100;
            document.getElementById('progressFill').style.width = pct + '%';
            // Update counter
            document.getElementById('answeredDisplay').textContent = answered;
            const submitBtn = document.getElementById('submitBtn');
            const threshMsg = document.getElementById('thresholdMsg');
            if (answered >= THRESHOLD) {
                submitBtn.disabled = false;
                threshMsg.textContent = '✅ Ready to submit';
                threshMsg.style.color = '#10b981';
            } else {
                submitBtn.disabled = true;
                const need = THRESHOLD - answered;
                threshMsg.textContent = `Need ${need} more to submit`;
                threshMsg.style.color = '#ef4444';
            }
        }
        
        function renderQuestion(index) {
            const q = questions[index];
            document.getElementById('currentQ').textContent = index + 1;
            // Render question text + optional diagram
            const qImgHtml = q.image_url
                ? `<div style="margin:14px 0;text-align:center">
                     <img src="../${q.image_url}" alt="Question diagram"
                          style="max-width:100%;max-height:320px;border-radius:12px;border:1px solid #e2e8f0;cursor:pointer;object-fit:contain;background:#f8fafc"
                          onclick="this.requestFullscreen ? this.requestFullscreen() : window.open('../'+q.image_url,'_blank')"
                          title="Click to enlarge">
                     <div style="font-size:11px;color:#94a3b8;margin-top:5px"><i class="fas fa-search-plus"></i> Click image to enlarge</div>
                   </div>`
                : '';
            document.getElementById('questionText').innerHTML =
                `<span style="font-size:1.05rem;font-weight:700;color:#0f172a">Q${index + 1}. ${q.question_text.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>` + qImgHtml;
            
            let html = '';
            const opts = [
                { key: 'option_a', label: 'A' },
                { key: 'option_b', label: 'B' },
                { key: 'option_c', label: 'C' },
                { key: 'option_d', label: 'D' }
            ];
            opts.forEach((opt, i) => {
                const sel = answers[q.id] === i;
                html += `<div class="option-item ${sel ? 'selected' : ''}" onclick="selectAnswer(${q.id}, ${i})">
                    <input type="radio" ${sel ? 'checked' : ''}>
                    <label>${opt.label}. ${q[opt.key]}</label>
                </div>`;
            });
            document.getElementById('optionsList').innerHTML = html;
            document.getElementById('prevBtn').disabled = index === 0;
            if (index === totalQ - 1) {
                document.getElementById('nextBtn').textContent = 'Submit ▶';
            } else {
                document.getElementById('nextBtn').textContent = 'Next ▶';
            }
            updateProgress();
        }
        
        function selectAnswer(qId, idx) { answers[qId] = idx; renderQuestion(currentIndex); }
        function goTo(idx) { if (idx >= 0 && idx < totalQ) { currentIndex = idx; renderQuestion(idx); } }
        
        document.getElementById('prevBtn').addEventListener('click', () => goTo(currentIndex - 1));
        document.getElementById('nextBtn').addEventListener('click', () => {
            if (currentIndex === totalQ - 1) {
                // On last question "Submit ▶" — only open modal if threshold met
                if (Object.keys(answers).length >= THRESHOLD) {
                    document.getElementById('answeredCount').textContent = Object.keys(answers).length;
                    document.getElementById('submitModal').classList.add('active');
                } else {
                    const need = THRESHOLD - Object.keys(answers).length;
                    notify(`You need to answer at least ${THRESHOLD} questions (50%) before submitting. You still need ${need} more answer(s).`, 'warning');
                }
            } else goTo(currentIndex + 1);
        });
        document.getElementById('submitBtn').addEventListener('click', () => {
            if (Object.keys(answers).length < THRESHOLD) return; // safety guard
            document.getElementById('answeredCount').textContent = Object.keys(answers).length;
            document.getElementById('submitModal').classList.add('active');
        });
        document.getElementById('cancelSubmit').addEventListener('click', () => document.getElementById('submitModal').classList.remove('active'));
        document.getElementById('confirmSubmit').addEventListener('click', async () => {
            submitted = true; clearInterval(timerInterval);
            try {
                const resp = await fetch('api/submit-test.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ test_id: testId, matric: matric, answers: answers, time_spent: duration - timeLeft })
                });
                const data = await resp.json();
                if (data.success) {
                    window.location.href = `result.php?score=${data.score}&total=${totalQ}&percentage=${data.percentage}&passed=${data.passed ? 1 : 0}&pass_mark=${data.pass_mark}&course=${encodeURIComponent(course)}&title=${encodeURIComponent(courseTitle)}&time=${duration - timeLeft}&student_name=${encodeURIComponent(studentName)}&student_id=${encodeURIComponent(studentMatric)}&test_id=${testId}`;
                }
            } catch(e) { notify('Submission error — please check your connection and try again.', 'error'); }
        });
        
        timerInterval = setInterval(() => {
            if (submitted) return;
            timeLeft--; updateTimer();
            if (timeLeft <= 0) { clearInterval(timerInterval); document.getElementById('confirmSubmit').click(); }
        }, 1000);
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') goTo(currentIndex - 1);
            if (e.key === 'ArrowRight') goTo(currentIndex + 1);
            if (e.key >= '1' && e.key <= '4') selectAnswer(questions[currentIndex].id, parseInt(e.key) - 1);
        });
        // Note: tab switch auto-submits via LivenessMonitor (visibilitychange/blur)
        // beforeunload only catches direct browser close — warn but allow
        window.addEventListener('beforeunload', (e) => {
            if (!submitted && timeLeft > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        updateTimer();
        renderQuestion(0);

        // ── COPY / PASTE / RIGHT-CLICK BLOCK ────────────────────────
        document.addEventListener('contextmenu', (e) => e.preventDefault());
        document.addEventListener('keydown', (e) => {
            // Block Ctrl/Cmd + C, V, X, A, U, S, P
            if ((e.ctrlKey || e.metaKey) && ['c','v','x','a','u','s','p'].includes(e.key.toLowerCase())) {
                e.preventDefault();
            }
            // Block F12, PrintScreen
            if (e.key === 'F12' || e.key === 'PrintScreen') e.preventDefault();
        });
        document.addEventListener('copy',  (e) => e.preventDefault());
        document.addEventListener('paste', (e) => e.preventDefault());
        document.addEventListener('cut',   (e) => e.preventDefault());
        // ─────────────────────────────────────────────────────────

        // ── LIVENESS MONITOR ──────────────────────────────────────
        // Starts after face-api.js + liveness-monitor.js have loaded
        window.addEventListener('load', () => {
            if (typeof LivenessMonitor === 'undefined') return; // safety
            const monitor = new LivenessMonitor({
                testId:      testId,
                matric:      matric,
                // Relative path — works no matter what folder/domain this is deployed under.
                // (Previously hardcoded to window.location.origin + '/ca-portal/...', which
                // 404'd — and therefore disabled ALL proctoring — on any setup where the
                // project folder isn't literally named "ca-portal".)
                modelsPath:  'assets/js/models',
                onAutoSubmit: () => {
                    // Programmatically trigger submit just like the confirm button
                    submitted = true;
                    clearInterval(timerInterval);
                    fetch('api/submit-test.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            test_id:    testId,
                            matric:     matric,
                            answers:    answers,
                            time_spent: duration - timeLeft
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href =
                                `result.php?score=${data.score}&total=${totalQ}` +
                                `&percentage=${data.percentage}&passed=${data.passed ? 1 : 0}&pass_mark=${data.pass_mark}` +
                                `&course=${encodeURIComponent(course)}` +
                                `&title=${encodeURIComponent(courseTitle)}&time=${duration - timeLeft}` +
                                `&student_name=${encodeURIComponent(studentName)}` +
                                `&student_id=${encodeURIComponent(studentMatric)}&test_id=${testId}` +
                                `&auto_submitted=1`;
                        }
                    })
                    .catch(() => window.location.href = 'dashboard.php');
                }
            });
            monitor.init();

            // Stop camera when test is submitted normally too
            document.getElementById('confirmSubmit').addEventListener('click', () => {
                setTimeout(() => monitor.stop(), 3000);
            }, { once: true });
        });
        // ─────────────────────────────────────────────────────────
    </script>
</body>
</html>
