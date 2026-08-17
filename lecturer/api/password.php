<?php
session_start();
header('Content-Type: application/json');
require_once '../../includes/config.php';

$action = $_POST['action'] ?? '';

// Change password (logged in)
if ($action === 'change_password') {
    if (!isset($_SESSION['lecturer_id'])) { echo json_encode(['success' => false]); exit; }
    guardLecturerWriteJson();
    
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['lecturer_id']]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($current, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Current password incorrect']);
        exit;
    }
    
    $pdo->prepare("UPDATE admins SET password_hash = ?, force_password_change = 0, last_password_change = NOW() WHERE id = ?")
       ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['lecturer_id']]);
    
    echo json_encode(['success' => true, 'message' => 'Password changed']);
    exit;
}

// Request OTP
if ($action === 'request_otp') {
    $email = trim($_POST['email'] ?? '');
    $stmt = $pdo->prepare("SELECT id, full_name FROM admins WHERE email = ? AND role = 'lecturer'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) { echo json_encode(['success' => false, 'message' => 'Email not found']); exit; }
    
    $otp = sprintf("%06d", mt_rand(0, 999999));
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    
    $pdo->prepare("DELETE FROM password_reset_otp WHERE email = ?")->execute([$email]);
    $pdo->prepare("INSERT INTO password_reset_otp (email, otp, expires_at) VALUES (?, ?, ?)")->execute([$email, $otp, $expires]);
    
    echo json_encode(['success' => true, 'message' => 'OTP sent', 'otp' => $otp]); // In production, email the OTP
    exit;
}

// Verify OTP
if ($action === 'verify_otp') {
    $email = trim($_POST['email'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    
    $stmt = $pdo->prepare("SELECT id FROM password_reset_otp WHERE email = ? AND otp = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([$email, $otp]);
    
    echo json_encode(['success' => $stmt->fetch() ? true : false]);
    exit;
}

// Reset password
if ($action === 'reset_password') {
    $email = trim($_POST['email'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    $new = $_POST['new_password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT id FROM password_reset_otp WHERE email = ? AND otp = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([$email, $otp]);
    if (!$stmt->fetch()) { echo json_encode(['success' => false, 'message' => 'Invalid OTP']); exit; }
    
    $pdo->prepare("UPDATE admins SET password_hash = ?, force_password_change = 0 WHERE email = ?")
       ->execute([password_hash($new, PASSWORD_DEFAULT), $email]);
    $pdo->prepare("UPDATE password_reset_otp SET used = 1 WHERE email = ?")->execute([$email]);
    
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false]);
?>