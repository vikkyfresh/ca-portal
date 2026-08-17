<?php
session_start();
header('Content-Type: application/json');
require_once '../../includes/config.php';

if (!isset($_SESSION['lecturer_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$lecturerId = $_SESSION['lecturer_id'];
$action     = $_POST['action'] ?? $_GET['action'] ?? '';

// Auto-detect base URL (works with ngrok, localhost, IP)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl  = $protocol . '://' . $_SERVER['HTTP_HOST'];

// ============================================================
// CREATE TEST
// ============================================================
if ($action === 'create') {
    guardLecturerWriteJson();
    $courseCode    = trim($_POST['course_code']  ?? '');
    $courseTitle   = trim($_POST['course_title'] ?? '');
    $testTitle     = trim($_POST['test_title']   ?? '');
    $level         = intval($_POST['level']             ?? 0);
    $duration      = intval($_POST['duration_minutes']  ?? 20);
    $totalQ        = intval($_POST['total_questions']   ?? 20);
    $timePerQ      = intval($_POST['time_per_question'] ?? 60);
    $startDate     = $_POST['start_date']  ?? null;
    $expiryDate    = $_POST['expiry_date'] ?? null;
    $maxAttempts   = 1;
    $accessType    = in_array($_POST['access_type'] ?? '', ['general','custom'])
                     ? $_POST['access_type'] : 'general';
    $allowedMatics = json_decode($_POST['allowed_matrics'] ?? '[]', true) ?: [];

    $requireFaceVerify      = isset($_POST['require_face_verify'])      ? 1 : 0;
    $shuffleQuestions       = isset($_POST['shuffle_questions'])        ? 1 : 0;
    $shuffleOptions         = isset($_POST['shuffle_options'])          ? 1 : 0;
    $showResultsImmediately = isset($_POST['show_results_immediately']) ? 1 : 0;

    if (empty($courseCode) || empty($testTitle) || empty($level)) {
        echo json_encode(['success' => false, 'message' => 'Required fields missing']); exit;
    }
    if ($accessType === 'custom' && empty($allowedMatics)) {
        echo json_encode(['success' => false, 'message' => 'Select at least one student for a custom test.']); exit;
    }

    $accessCode = strtoupper(substr(md5(uniqid()), 0, 8));
    $accessLink = $baseUrl . '/ca-portal/join.php?code=' . $accessCode;

    // Link expiry follows the test's own configured window — a join link
    // shouldn't outlive the test it points to. Falls back to +10 hours only
    // when the lecturer left the test with no expiry date at all.
    $linkExpiresAt = $expiryDate ?: date('Y-m-d H:i:s', strtotime('+10 hours'));

    try {
        $stmt = $pdo->prepare("
            INSERT INTO tests
                (course_code, course_title, test_title, level, duration_minutes, total_questions,
                 time_per_question, start_date, expiry_date, max_attempts, passing_score, require_face_verify,
                 shuffle_questions, shuffle_options, show_results_immediately,
                 access_code, access_link, link_expires_at, access_type, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $courseCode, $courseTitle, $testTitle, $level, $duration, $totalQ,
            $timePerQ, $startDate ?: null, $expiryDate ?: null, $maxAttempts, 50,
            $requireFaceVerify, $shuffleQuestions, $shuffleOptions, $showResultsImmediately,
            $accessCode, $accessLink, $linkExpiresAt, $accessType, $lecturerId
        ]);
        $testId = $pdo->lastInsertId();

        $customLink = null;

        if ($accessType === 'custom') {
            $token   = bin2hex(random_bytes(24));
            $expires = $expiryDate ?: date('Y-m-d H:i:s', strtotime('+7 days'));

            $insLink = $pdo->prepare("INSERT INTO custom_test_links (token, test_id, created_by, expires_at) VALUES (?,?,?,?)");
            $insLink->execute([$token, $testId, $lecturerId, $expires]);
            $linkId = $pdo->lastInsertId();

            $insM = $pdo->prepare("INSERT IGNORE INTO custom_test_link_students (link_id, matric) VALUES (?,?)");
            foreach ($allowedMatics as $m) {
                $m = strtoupper(trim($m));
                if ($m) $insM->execute([$linkId, $m]);
            }

            $customLink = $baseUrl . '/ca-portal/take-test-link.php?token=' . $token;
        }

        logAudit('test_created', 'lecturer', $lecturerId, $_SESSION['lecturer_name'] ?? null,
            ($_SESSION['lecturer_name'] ?? 'A lecturer') . " created test \"$testTitle\" ($courseCode, Level $level).",
            ['test_id' => $testId, 'access_type' => $accessType]);

        echo json_encode([
            'success'         => true,
            'test_id'         => $testId,
            'access_code'     => $accessCode,
            'test_link'       => $accessLink,
            'link_expires_at' => $linkExpiresAt,
            'access_type'     => $accessType,
            'custom_link'     => $customLink,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// REGENERATE LINK
// ============================================================
if ($action === 'regenerate_link') {
    guardLecturerWriteJson();
    $testId = intval($_POST['test_id'] ?? 0);
    if (!$testId) {
        echo json_encode(['success' => false, 'message' => 'Test ID required']); exit;
    }

    $stmt = $pdo->prepare("SELECT id, expiry_date FROM tests WHERE id = ? AND created_by = ?");
    $stmt->execute([$testId, $lecturerId]);
    $testRow = $stmt->fetch();
    if (!$testRow) {
        echo json_encode(['success' => false, 'message' => 'Test not found']); exit;
    }

    $accessCode = strtoupper(substr(md5(uniqid()), 0, 8));
    $accessLink = $baseUrl . '/ca-portal/join.php?code=' . $accessCode;
    $linkExpiresAt = $testRow['expiry_date'] ?: date('Y-m-d H:i:s', strtotime('+10 hours'));

    $stmt = $pdo->prepare("UPDATE tests SET access_code = ?, access_link = ?, link_expires_at = ?, link_used = 0 WHERE id = ? AND created_by = ?");
    $stmt->execute([$accessCode, $accessLink, $linkExpiresAt, $testId, $lecturerId]);

    echo json_encode([
        'success'     => true,
        'access_code' => $accessCode,
        'test_link'   => $accessLink,
        'message'     => 'Link regenerated! Old link is now invalid.'
    ]);
    exit;
}

// ============================================================
// DELETE TEST
// ============================================================
if ($action === 'delete') {
    guardLecturerWriteJson();
    $testId = intval($_POST['test_id'] ?? 0);
    if (!$testId) {
        echo json_encode(['success' => false, 'message' => 'Test ID required']); exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM tests WHERE id = ? AND created_by = ?");
    $stmt->execute([$testId, $lecturerId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Test not found']); exit;
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM attempts WHERE test_id = ?");
    $count->execute([$testId]);
    if ($count->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete test with student submissions']); exit;
    }

    $pdo->prepare("DELETE FROM questions WHERE test_id = ?")->execute([$testId]);
    $pdo->prepare("DELETE FROM tests WHERE id = ? AND created_by = ?")->execute([$testId, $lecturerId]);

    echo json_encode(['success' => true, 'message' => 'Test deleted successfully']);
    exit;
}

// ============================================================
// LIST TESTS
// ============================================================
$stmt = $pdo->prepare("SELECT * FROM tests WHERE created_by = ? ORDER BY created_at DESC");
$stmt->execute([$lecturerId]);
echo json_encode(['success' => true, 'tests' => $stmt->fetchAll()]);
?>
