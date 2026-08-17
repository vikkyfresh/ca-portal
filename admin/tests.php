<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

// Admin photo
$photoSrc = 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['admin_name'] ?? 'Admin') . '&background=1e3a8a&color=fff&size=80&bold=true';
$stmtPhoto = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
$stmtPhoto->execute([$_SESSION['admin_id'] ?? 0]);
$photoRow = $stmtPhoto->fetch();
if (!empty($photoRow['photo'])) {
    $sp = dirname(__DIR__) . '/' . ltrim($photoRow['photo'], '/');
    if (file_exists($sp)) $photoSrc = '../' . ltrim($photoRow['photo'], '/');
}

$tests = $pdo->query("SELECT t.*, a.full_name as lecturer_name FROM tests t JOIN admins a ON t.created_by = a.id ORDER BY t.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Tests - Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; }
        .layout{display:flex;min-height:100vh}
.main{flex:1;margin-left:260px;min-width:0;display:flex;flex-direction:column}
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* .nav defined in includes/sidebar.php */
        /* .nav a defined in includes/sidebar.php */
        
        .topbar { background: white; padding: 16px 24px; }
        .content { padding: 24px; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; }
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; }
        .table-scroll table { min-width: 640px; }
        th, td { padding: 12px 20px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { background: #f8fafc; }
        @media (max-width: 768px) { /* sidebar CSS → includes/sidebar.php */ .main { margin-left: 0; } }
    </style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="layout">
                <?php $activePage='tests'; require_once __DIR__.'/includes/sidebar.php'; ?>
        <main class="main">
            <div class="topbar" style="display:flex;align-items:center;gap:12px">
                <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
                <h1>All Tests</h1>
            </div>
            <div class="content">
                <div class="table-scroll">
                <table>
                    <thead><tr><th>Test</th><th>Course</th><th>Level</th><th>Lecturer</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($tests as $t): ?>
                        <tr><td><?= htmlspecialchars($t['test_title']) ?></td><td><?= htmlspecialchars($t['course_code']) ?></td><td><?= $t['level'] ?></td><td><?= htmlspecialchars($t['lecturer_name']) ?></td><td><?= $t['is_active'] ? 'Active' : 'Inactive' ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>