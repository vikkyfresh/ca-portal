<?php
/**
 * api/heartbeat.php
 * Called every ~15s by liveness-monitor.js while a test is open.
 * Purpose: client-side proctoring can never reliably self-report its own
 * script being disabled/blocked/killed — the absence of these pings is
 * what tells the server that happened. See active_attempts table.
 */
session_start();
require_once '../includes/config.php';
header('Content-Type: application/json');

$sessionMatric = strtoupper(trim(
    $_SESSION['authenticated_matric'] ?? $_SESSION['student_matric'] ?? ''
));
if (!$sessionMatric) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true);
$testId = isset($body['test_id']) ? intval($body['test_id']) : 0;
$matric = isset($body['matric']) ? strtoupper(trim($body['matric'])) : '';
$monitoringActive = !empty($body['monitoring_active']) ? 1 : 0;

if (!$testId || !$matric || $matric !== $sessionMatric) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// A heartbeat gap over this many seconds counts as a stall — the client is
// expected to ping every ~15s, so this gives generous slack for network
// hiccups/background-tab throttling before flagging it as suspicious.
$GAP_THRESHOLD_SECONDS = 40;
// A gap over this long means "not a stall, this is a new attempt" (e.g. a
// retake reusing the same test_id+matric after the previous row was never
// cleaned up) — reset rather than accumulate.
$STALE_RESET_SECONDS = 3600;

try {
    $stmt = $pdo->prepare("
        INSERT INTO active_attempts (test_id, student_matric, started_at, last_heartbeat, heartbeat_gaps, monitoring_active)
        VALUES (?, ?, NOW(), NOW(), 0, ?)
        ON DUPLICATE KEY UPDATE
            heartbeat_gaps = IF(TIMESTAMPDIFF(SECOND, last_heartbeat, NOW()) > ?, 0,
                                 heartbeat_gaps + IF(TIMESTAMPDIFF(SECOND, last_heartbeat, NOW()) > ?, 1, 0)),
            started_at     = IF(TIMESTAMPDIFF(SECOND, last_heartbeat, NOW()) > ?, NOW(), started_at),
            last_heartbeat = NOW(),
            monitoring_active = VALUES(monitoring_active)
    ");
    $stmt->execute([
        $testId, $matric, $monitoringActive,
        $STALE_RESET_SECONDS, $GAP_THRESHOLD_SECONDS, $STALE_RESET_SECONDS
    ]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
}
