<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/config.php';

$matric = strtoupper(trim($_POST['matric'] ?? ''));
$action = $_POST['action'] ?? 'success';

if (!preg_match('/^\d{2}CS\d{4}$/', $matric)) {
    echo json_encode(['success' => false, 'message' => 'Invalid matric format']);
    exit;
}

// ── FAILED ATTEMPT ──────────────────────────────────────────────────────
// This branch previously didn't exist, so a failed attempt fell straight
// through into the success logic below: it wrote a real session_token to
// the DB (concurrent-login lock), marked the session as fully verified,
// and cleared pending_verify_matric — silently granting session access on
// a failed face match, AND breaking every subsequent "Try Again" click
// because get-face-descriptor.php requires pending_verify_matric to still
// match. A failed attempt should only record itself, nothing else.
if ($action === 'fail') {
    if (!isset($_SESSION['face_attempts'])) $_SESSION['face_attempts'] = [];
    $_SESSION['face_attempts'][$matric] = ($_SESSION['face_attempts'][$matric] ?? 0) + 1;
    echo json_encode([
        'success'  => true,
        'attempts' => $_SESSION['face_attempts'][$matric]
    ]);
    exit;
}
// ──────────────────────────────────────────────────────────────────────

try {
    $stmt = $pdo->prepare("SELECT matric, full_name, email, level FROM students WHERE matric = ?");
    $stmt->execute([$matric]);
    $student = $stmt->fetch();

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    // Only run the "active test exists" check for general flow (not custom-link flow)
    $isCustomFlow = !empty($_SESSION['custom_link_token']);

    if (!$isCustomFlow) {
        $stmt = $pdo->prepare(
            "SELECT id, max_attempts FROM tests
             WHERE level = ? AND is_active = 1 AND access_type = 'general'
             AND (start_date IS NULL OR start_date <= NOW())
             AND (expiry_date IS NULL OR expiry_date >= NOW())
             LIMIT 1"
        );
        $stmt->execute([$student['level']]);
        $activeTest = $stmt->fetch();

        if (!$activeTest) {
            echo json_encode([
                'success' => false,
                'message' => 'No active test available for Level ' . $student['level'] . '. Please contact your lecturer.'
            ]);
            exit;
        }

        // Check attempts — but a lecturer-approved, unused retake overrides the limit
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attempts WHERE student_matric = ? AND test_id = ? AND status = 'completed'");
        $stmt->execute([$matric, $activeTest['id']]);
        $attemptsUsed = $stmt->fetchColumn();
        $maxAttempts  = $activeTest['max_attempts'];

        $stmt = $pdo->prepare("SELECT id FROM retake_approvals WHERE student_matric = ? AND test_id = ? AND used = 0 LIMIT 1");
        $stmt->execute([$matric, $activeTest['id']]);
        $hasApprovedRetake = (bool)$stmt->fetch();

        if (!$hasApprovedRetake && $maxAttempts > 0 && $attemptsUsed >= $maxAttempts) {
            echo json_encode([
                'success' => false,
                'message' => 'You have used all ' . $maxAttempts . ' attempt(s) for this test.'
            ]);
            exit;
        }
    }

    // ── WRITE SESSION TOKEN TO DB (concurrent login lock) ──────
    $sessionToken = bin2hex(random_bytes(32));
    $stmtToken = $pdo->prepare("UPDATE students SET session_token = ?, session_token_created_at = NOW() WHERE matric = ?");
    $stmtToken->execute([$sessionToken, $matric]);
    // ────────────────────────────────────────────────────────────

    // Set session
    $_SESSION['session_token']         = $sessionToken;
    $_SESSION['verified']             = true;
    $_SESSION['authenticated_matric'] = $student['matric'];
    $_SESSION['student_matric']       = $student['matric'];
    $_SESSION['student_name']         = $student['full_name'];
    $_SESSION['student_level']        = $student['level'];
    $_SESSION['student_email']        = $student['email'];
    $_SESSION['face_verified']        = true;
    $_SESSION['verified_at']          = time();
    $_SESSION['verified_matric']      = $matric;
    unset($_SESSION['pending_verify_matric']);

    logAudit('student_login', 'student', $student['matric'], $student['full_name'],
        $student['full_name'] . ' (' . $student['matric'] . ') logged in and passed face verification.');

    echo json_encode([
        'success'       => true,
        'message'       => 'Session created',
        'student_name'  => $student['full_name'],
        'student_level' => $student['level'],
        'redirect'      => 'dashboard.php'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
