<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$testId = intval($input['test_id'] ?? 0);
$matric = strtoupper(trim($input['matric'] ?? ''));
$answers = $input['answers'] ?? [];
$timeSpent = max(0, intval($input['time_spent'] ?? 0));
$sessionMatric = strtoupper(trim($_SESSION['authenticated_matric'] ?? $_SESSION['student_matric'] ?? ''));

if (!$testId || !$matric) {
    echo json_encode(['success' => false, 'message' => 'Missing test_id or matric']);
    exit;
}

if (!$sessionMatric || $sessionMatric !== $matric || empty($_SESSION['face_verified'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please verify your face again.']);
    exit;
}

if (empty($answers) || !is_array($answers)) {
    echo json_encode(['success' => false, 'message' => 'No answers submitted']);
    exit;
}

$expectedQuestionIds = array_map('intval', $_SESSION['active_test_questions'][$testId] ?? []);
if (!$expectedQuestionIds) {
    echo json_encode(['success' => false, 'message' => 'Test session expired. Please restart the test from your dashboard.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT matric, level FROM students WHERE matric = ? LIMIT 1");
    $stmt->execute([$matric]);
    $student = $stmt->fetch();
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$testId]);
    $test = $stmt->fetch();
    if (!$test || (int)$test['level'] !== (int)$student['level']) {
        echo json_encode(['success' => false, 'message' => 'Test not available for this student']);
        exit;
    }

    if (!empty($test['start_date']) && strtotime($test['start_date']) > time()) {
        echo json_encode(['success' => false, 'message' => 'This test has not started yet']);
        exit;
    }
    if (!empty($test['expiry_date']) && strtotime($test['expiry_date']) < time()) {
        echo json_encode(['success' => false, 'message' => 'This test has expired']);
        exit;
    }

    // ── Block double submission once max_attempts is used — a lecturer-approved,
    //    unused retake overrides the limit ──────────────────────────────────
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attempts WHERE student_matric = ? AND test_id = ? AND status = 'completed'");
    $stmt->execute([$matric, $testId]);
    $attemptsUsed = (int)$stmt->fetchColumn();
    $maxAttempts  = (int)($test['max_attempts'] ?? 1);

    $retakeStmt = $pdo->prepare("SELECT id FROM retake_approvals WHERE student_matric = ? AND test_id = ? AND used = 0 LIMIT 1");
    $retakeStmt->execute([$matric, $testId]);
    $approvedRetake = $retakeStmt->fetch();

    if (!$approvedRetake && $maxAttempts > 0 && $attemptsUsed >= $maxAttempts) {
        echo json_encode(['success' => false, 'message' => 'You have already submitted this test. Contact your lecturer for a retake.']);
        exit;
    }
    // ──────────────────────────────────────────────────────────────────────

    $cleanAnswers = [];
    foreach ($answers as $qId => $studentAnswer) {
        $qId = (int)$qId;
        $studentAnswer = (int)$studentAnswer;
        if ($qId > 0 && $studentAnswer >= 0 && $studentAnswer <= 3) {
            $cleanAnswers[$qId] = $studentAnswer;
        }
    }

    if (!$cleanAnswers) {
        echo json_encode(['success' => false, 'message' => 'No valid answers submitted']);
        exit;
    }

    $submittedQuestionIds = array_keys($cleanAnswers);
    if (array_diff($submittedQuestionIds, $expectedQuestionIds)) {
        echo json_encode(['success' => false, 'message' => 'Submitted answers do not match this test session']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($expectedQuestionIds), '?'));
    $params = array_merge([$test['course_code'], (int)$test['level']], $expectedQuestionIds);

    $stmt = $pdo->prepare("
        SELECT id, correct_option
        FROM course_questions
        WHERE course_code = ?
          AND level = ?
          AND id IN ($placeholders)
    ");
    $stmt->execute($params);
    $questions = $stmt->fetchAll();

    if (count($questions) !== count($expectedQuestionIds)) {
        echo json_encode(['success' => false, 'message' => 'One or more test questions no longer belong to this test']);
        exit;
    }

    $correctAnswers = [];
    foreach ($questions as $q) {
        $correctAnswers[(int)$q['id']] = ord(strtoupper($q['correct_option'])) - 65;
    }

    $score = 0;
    foreach ($expectedQuestionIds as $qId) {
        if (isset($cleanAnswers[$qId], $correctAnswers[$qId]) && $cleanAnswers[$qId] === $correctAnswers[$qId]) {
            $score++;
        }
    }

    $total = count($expectedQuestionIds);
    $percentage = $total > 0 ? round(($score / $total) * 100, 2) : 0;

    // Portal-wide passing mark — same for every course (see tests.passing_score,
    // fixed at 50 for all tests; lecturer/api/tests.php no longer lets this vary).
    $passingMark = (float) ($test['passing_score'] ?? 50);
    $passed      = $percentage >= $passingMark;

    // ── PROCTORING INTEGRITY VERDICT ────────────────────────────
    // Client-side proctoring can never reliably report its own script being
    // blocked or killed — the heartbeat row (or lack of one) is what tells us
    // that after the fact. See assets/js/liveness-monitor.js and
    // api/heartbeat.php.
    $procFlag = null;
    $procNote = null;
    try {
        $hbStmt = $pdo->prepare("SELECT heartbeat_gaps, monitoring_active FROM active_attempts WHERE test_id = ? AND student_matric = ?");
        $hbStmt->execute([$testId, $matric]);
        $hb = $hbStmt->fetch();

        if (!$hb) {
            $procFlag = 'no_monitoring';
            $procNote = 'No proctoring heartbeat was ever received for this attempt — the anti-cheat script likely never ran.';
        } elseif (!$hb['monitoring_active']) {
            $procFlag = 'degraded';
            $procNote = 'Camera-based monitoring was unavailable during this attempt (tab/fullscreen checks may still have run).';
        } elseif ((int)$hb['heartbeat_gaps'] > 0) {
            $procFlag = 'gaps';
            $procNote = (int)$hb['heartbeat_gaps'] . ' monitoring gap(s) detected — the anti-cheat script may have stalled or been tampered with.';
        } else {
            $procFlag = 'clean';
        }

        $pdo->prepare("DELETE FROM active_attempts WHERE test_id = ? AND student_matric = ?")
            ->execute([$testId, $matric]);
    } catch (Exception $e) { /* proctoring table missing/not migrated yet — don't block submission */ }
    // ────────────────────────────────────────────────────────────

    $stmt = $pdo->prepare("
        INSERT INTO attempts
            (student_matric, test_id, start_time, end_time, time_spent_seconds, score, total, percentage, status, answers, face_verified, proctoring_flag, proctoring_note)
        VALUES
            (?, ?, DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), ?, ?, ?, ?, 'completed', ?, 1, ?, ?)
    ");
    $stmt->execute([$matric, $testId, $timeSpent, $timeSpent, $score, $total, $percentage, json_encode($cleanAnswers), $procFlag, $procNote]);
    unset($_SESSION['active_test_questions'][$testId]);

    // ── If this submission used an approved retake, mark it consumed ───
    if (!empty($approvedRetake)) {
        $pdo->prepare("UPDATE retake_approvals SET used = 1, used_at = NOW() WHERE id = ?")
            ->execute([$approvedRetake['id']]);
    }
    // ────────────────────────────────────────────────────────────────────

    // ── CLEAR SESSION TOKEN on test submit ──────────────────────
    try {
        $stmtClear = $pdo->prepare("UPDATE students SET session_token = NULL, session_token_created_at = NULL WHERE matric = ? AND session_token = ?");
        $stmtClear->execute([$sessionMatric, $_SESSION['session_token'] ?? '']);
    } catch (Exception $e) { /* silent fail */ }
    // ────────────────────────────────────────────────────────────

    $studentDisplayName = $_SESSION['student_name'] ?? $matric;
    logAudit('test_completed', 'student', $matric, $studentDisplayName,
        $studentDisplayName . " completed \"" . ($test['test_title'] ?? $test['course_code']) . "\" — scored $score/$total (" . round($percentage) . "%).",
        ['test_id' => $testId, 'score' => $score, 'total' => $total, 'percentage' => round($percentage,1), 'proctoring_flag' => $procFlag]);

    echo json_encode([
        'success'    => true,
        'score'      => $score,
        'total'      => $total,
        'percentage' => round($percentage),
        'passed'     => $passed,
        'pass_mark'  => $passingMark,
    ]);
} catch (Exception $e) {
    error_log('Submit error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>
