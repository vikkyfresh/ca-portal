<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

$level  = intval($_GET['level'] ?? 0);
$matric = strtoupper(trim($_GET['matric'] ?? ''));

if (!$level) {
    echo json_encode(['has_test' => false, 'message' => 'Level required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, max_attempts FROM tests WHERE level = ? AND is_active = 1 AND access_type = 'general' AND (start_date IS NULL OR start_date <= NOW()) AND (expiry_date IS NULL OR expiry_date >= NOW())");
    $stmt->execute([$level]);
    $test = $stmt->fetch();
    $count = $test ? 1 : 0;

    // ── Already-taken check (before the student wastes time on face scanning) ──
    $alreadyTaken = false;
    if ($test && $matric) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attempts WHERE student_matric = ? AND test_id = ? AND status = 'completed'");
        $stmt->execute([$matric, $test['id']]);
        $attemptsUsed = (int)$stmt->fetchColumn();

        $maxAttempts = (int)($test['max_attempts'] ?? 0);

        // max_attempts = 0 means unlimited attempts for this test — never flag as already-taken
        if ($maxAttempts > 0 && $attemptsUsed >= $maxAttempts) {
            // Only flag as blocked if there's no lecturer-approved, unused retake waiting
            $stmt = $pdo->prepare("SELECT id FROM retake_approvals WHERE student_matric = ? AND test_id = ? AND used = 0 LIMIT 1");
            $stmt->execute([$matric, $test['id']]);
            if (!$stmt->fetch()) {
                $alreadyTaken = true;
            }
        }
    }

    echo json_encode([
        'has_test'      => $count > 0,
        'count'         => (int)$count,
        'level'         => $level,
        'already_taken' => $alreadyTaken,
    ]);
} catch (Exception $e) {
    echo json_encode(['has_test' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
?>