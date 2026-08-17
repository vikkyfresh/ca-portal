<?php
/**
 * api/log-proctoring.php
 * Receives anti-cheat violation events from the test page.
 * Handles: face_out, eyes_closed, eyes_away, tab_switch, fullscreen_exit
 */
session_start();
require_once '../includes/config.php';
header('Content-Type: application/json');

// Must be a logged-in student
$sessionMatric = strtoupper(trim(
    $_SESSION['authenticated_matric'] ?? $_SESSION['student_matric'] ?? ''
));
if (!$sessionMatric) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

$body          = json_decode(file_get_contents('php://input'), true);
$testId        = isset($body['test_id'])        ? intval($body['test_id'])          : 0;
$matric        = isset($body['matric'])         ? strtoupper(trim($body['matric'])) : '';
$violationType = isset($body['violation_type']) ? trim($body['violation_type'])     : '';
$warningCount  = isset($body['warning_count'])  ? intval($body['warning_count'])    : 0;
$snapshotB64   = $body['snapshot'] ?? null;

// Validate violation type
$allowed = ['face_out', 'eyes_closed', 'eyes_away', 'tab_switch', 'fullscreen_exit', 'multiple_faces', 'no_camera'];
if (!$testId || !$matric || !in_array($violationType, $allowed)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

if ($matric !== $sessionMatric) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// ── SAVE SNAPSHOT ────────────────────────────────────────────
$snapshotPath = null;
if (!empty($snapshotB64)) {
    $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $snapshotB64);
    $decoded   = base64_decode($imageData, true);
    if ($decoded !== false && strlen($decoded) > 1000) {
        $snapshotDir = dirname(__DIR__) . '/uploads/snapshots/';
        if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0755, true);
        $filename = strtolower($matric) . '_t' . $testId . '_' . $violationType . '_' . time() . '.jpg';
        $fullPath = $snapshotDir . $filename;
        if (file_put_contents($fullPath, $decoded) !== false) {
            $snapshotPath = 'uploads/snapshots/' . $filename;
        }
    }
}
// ─────────────────────────────────────────────────────────────

try {
    // Use actual DB columns: event_type, event_data, screenshot_path, created_at
    $stmt = $pdo->prepare("
        INSERT INTO proctoring_logs
            (test_id, student_matric, event_type, event_data, screenshot_path, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $eventData = json_encode(['warning_count' => $warningCount]);
    $stmt->execute([$testId, $matric, $violationType, $eventData, $snapshotPath]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'snapshot_saved' => !is_null($snapshotPath)]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
}
