<?php
session_start();
header('Content-Type: application/json');
require_once '../../includes/config.php';

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM admins WHERE (username = ? OR email = ?) AND role = 'admin'");
$stmt->execute([$username, $username]);
$admin = $stmt->fetch();

if ($admin && password_verify($password, $admin['password_hash'])) {
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_name'] = $admin['full_name'];
    $_SESSION['admin_role'] = $admin['role'];
    $pdo->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);
    logAudit('admin_login', 'admin', $admin['id'], $admin['full_name'],
        $admin['full_name'] . ' (' . $admin['username'] . ') logged in to the admin portal.');
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}
?>