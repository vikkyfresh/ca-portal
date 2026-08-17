<?php
session_start();
header('Content-Type: application/json');
require_once '../../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'invalid_request']);
    exit;
}

$staffId  = trim($_POST['staff_id'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($staffId) || empty($password)) {
    echo json_encode(['success' => false, 'error' => 'invalid']);
    exit;
}

// Step 1: Find the lecturer/admin account
$stmt = $pdo->prepare("SELECT * FROM admins WHERE (staff_id = ? OR username = ? OR email = ?) AND role IN ('lecturer', 'admin') LIMIT 1");
$stmt->execute([$staffId, $staffId, $staffId]);
$lecturer = $stmt->fetch();

// Step 2: Verify password
if (!$lecturer || !password_verify($password, $lecturer['password_hash'])) {
    echo json_encode(['success' => false, 'error' => 'invalid']);
    exit;
}

// Step 3: Check portal control — lecturers_blocked
$lbStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='lecturers_blocked' LIMIT 1");
$lbRow   = $lbStmt ? $lbStmt->fetch() : null;
if ($lbRow && $lbRow['setting_value'] === '1') {
    echo json_encode(['success' => false, 'error' => 'blocked', 'message' => 'Lecturer access is currently restricted by the administrator. Please try again later.']);
    exit;
}

// Step 4: Set session and log in
$_SESSION['lecturer_id']         = $lecturer['id'];
$_SESSION['lecturer_staff_id']   = $lecturer['staff_id'];
$_SESSION['lecturer_name']       = $lecturer['full_name'];
$_SESSION['lecturer_email']      = $lecturer['email'];
$_SESSION['lecturer_department'] = $lecturer['department'] ?? 'Computer Science';
$_SESSION['lecturer_role']       = $lecturer['role'];

$pdo->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$lecturer['id']]);

logAudit(
    $lecturer['role'] === 'admin' ? 'admin_login' : 'lecturer_login',
    $lecturer['role'] === 'admin' ? 'admin' : 'lecturer',
    $lecturer['id'], $lecturer['full_name'],
    $lecturer['full_name'] . ' (' . ($lecturer['staff_id'] ?: $lecturer['username']) . ') logged in.'
);

echo json_encode([
    'success'               => true,
    'force_password_change' => (bool)($lecturer['force_password_change'] ?? false)
]);
exit;
?>