<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

$adminName = $_SESSION['admin_name'] ?? 'Administrator';
$photoSrc = 'https://ui-avatars.com/api/?name=' . urlencode($adminName) . '&background=1e3a8a&color=fff&size=80&bold=true';
if (!empty($_SESSION['admin_id'])) {
    $_spStmt = $pdo->prepare('SELECT photo FROM admins WHERE id = ? LIMIT 1');
    $_spStmt->execute([$_SESSION['admin_id']]);
    $_spRow = $_spStmt->fetch();
    if (!empty($_spRow['photo'])) {
        $_sp = dirname(__DIR__) . '/' . ltrim($_spRow['photo'], '/');
        if (file_exists($_sp)) $photoSrc = '../' . ltrim($_spRow['photo'], '/');
    }
}

// Toggle actions must be POST — a plain GET link/image/prefetch should never
// be able to flip maintenance mode for a logged-in admin (CSRF hardening).
$action = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['action'] ?? '') : '';
$message_saved = false;

if ($action === 'enable') {
    $pdo->query("UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'maintenance_mode'");
    header('Location: maintenance.php'); exit;
} elseif ($action === 'disable') {
    $pdo->query("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'maintenance_mode'");
    header('Location: maintenance.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['maintenance_message'])) {
    $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'maintenance_message'");
    $stmt->execute([$_POST['maintenance_message']]);
    $message_saved = true;
}

$mode = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn();
$message = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_message'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; }
        .layout{display:flex;min-height:100vh}
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        .nav a i { width: 20px; }
        .main { flex: 1; margin-left: 260px; }
        .topbar { background: white; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { font-size: 1.5rem; color: #0f172a; }
        .back-btn { padding: 8px 16px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: .9rem; display: flex; align-items: center; gap: 6px; }
        .back-btn:hover { background: #e2e8f0; }
        .content { padding: 24px; max-width: 700px; }
        .card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h3 { margin-bottom: 16px; color: #0f172a; display: flex; align-items: center; gap: 8px; }
        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; font-size: .9rem; }
        .btn-green { background: #10b981; color: white; }
        .btn-red { background: #ef4444; color: white; }
        .btn-primary { background: #0f172a; color: white; }
        .status { padding: 16px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
        .status.on { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .status.off { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .status i { font-size: 1.5rem; }
        .alert-success { background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; color: #475569; font-weight: 500; }
        .form-group textarea { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-family: inherit; font-size: .95rem; resize: vertical; min-height: 80px; }
        .form-group textarea:focus { outline: none; border-color: #1e3a8a; }
        .info-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 16px; border-radius: 10px; margin-top: 16px; font-size: .9rem; color: #475569; line-height: 1.8; }
        .info-box i { color: #1e3a8a; margin-right: 8px; }
        .menu-toggle { display: none; }
        @media (max-width: 768px) { /* → includes/sidebar.php */ .main { margin-left: 0; } .menu-toggle { display: block; } }
    </style>
</head>
<body>
    <div class="layout">
        <?php $activePage='maintenance'; require_once __DIR__.'/includes/sidebar.php'; ?>
        <main class="main">
            <div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
    <button class="menu-toggle" onclick="document.getElementById(\'sidebar\').classList.toggle(\'open\')"><i class="fas fa-bars"></i></button>
    <h1>Maintenance Mode</h1>
                <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
            <div class="content">
                
                <?php if ($message_saved): ?>
                <div class="alert-success"><i class="fas fa-check-circle"></i> Maintenance message updated successfully!</div>
                <?php endif; ?>

                <div class="card">
                    <h3><i class="fas fa-power-off"></i> System Status</h3>
                    <div class="status <?= $mode == '1' ? 'on' : 'off' ?>">
                        <i class="fas <?= $mode == '1' ? 'fa-tools' : 'fa-check-circle' ?>"></i>
                        <div>
                            <strong>Maintenance Mode: <?= $mode == '1' ? 'ACTIVE' : 'OFF' ?></strong>
                            <p style="font-size:0.85rem; margin-top:4px;">
                                <?= $mode == '1' ? 'Students cannot access the system.' : 'System is running normally.' ?>
                            </p>
                        </div>
                    </div>
                    <?php if ($mode == '1'): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Disable maintenance mode?')">
                        <input type="hidden" name="action" value="disable">
                        <button type="submit" class="btn btn-red"><i class="fas fa-power-off"></i> Disable Maintenance</button>
                    </form>
                    <?php else: ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Enable maintenance mode?')">
                        <input type="hidden" name="action" value="enable">
                        <button type="submit" class="btn btn-green"><i class="fas fa-tools"></i> Enable Maintenance</button>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3><i class="fas fa-pen"></i> Maintenance Message</h3>
                    <form method="post">
                        <div class="form-group">
                            <label>Message to display to users:</label>
                            <textarea name="maintenance_message" rows="4"><?= htmlspecialchars($message) ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Message</button>
                    </form>
                </div>

                <div class="info-box">
                    <p><i class="fas fa-info-circle"></i> <strong>How it works:</strong></p>
                    <ul style="margin-top:8px; padding-left:20px;">
                        <li>When enabled, <strong>students</strong> see the maintenance page.</li>
                        <li><strong>Admins</strong> can still access everything.</li>
                        <li><strong>Lecturers</strong> see a warning banner on their dashboard.</li>
                    </ul>
                </div>
            </div>
        </main>
    </div>
</body>
</html>