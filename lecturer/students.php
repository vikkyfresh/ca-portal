<?php
session_start();
if (!isset($_SESSION['lecturer_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

// Lecturer photo
$photoSrc = 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['lecturer_name'] ?? 'Lecturer') . '&background=1e3a8a&color=fff&size=80&bold=true';
if (!empty($_SESSION['lecturer_id'])) {
    $stmtPhoto = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
    $stmtPhoto->execute([$_SESSION['lecturer_id']]);
    $photoRow = $stmtPhoto->fetch();
    if (!empty($photoRow['photo'])) {
        $sp = dirname(__DIR__) . '/' . ltrim($photoRow['photo'], '/');
        if (file_exists($sp)) $photoSrc = '../' . ltrim($photoRow['photo'], '/');
    }
}
$lecturerAvatarUrl = $photoSrc;
$avatarUrl = $photoSrc;


$lecturerId = $_SESSION['lecturer_id'];
$lecturerName = $_SESSION['lecturer_name'];

// Get lecturer's assigned levels
$stmt = $pdo->prepare("SELECT DISTINCT level FROM lecturer_courses WHERE lecturer_id = ? ORDER BY level");
$stmt->execute([$lecturerId]);
$courses = $stmt->fetchAll(PDO::FETCH_COLUMN);
$levels = array_unique($courses);

// Get selected level
$selectedLevel = intval($_GET['level'] ?? ($levels[0] ?? 0));

// Get students for the selected level
$students = [];
if ($selectedLevel) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE level = ? ORDER BY full_name");
    $stmt->execute([$selectedLevel]);
    $students = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students - Lecturer Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; }
        .layout{display:flex;min-height:100vh}
        /* ── Sidebar ── */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */ /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */ /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */ /* sidebar CSS → includes/sidebar.php */
        /* .nav defined in includes/sidebar.php */
        
        /* .nav a defined in includes/sidebar.php */
        .nav a i { width:18px; text-align:center; font-size:.88rem; }
        
        /* sidebar CSS → includes/sidebar.php */
        .nav a i { width: 20px; }
        .main { flex: 1; margin-left: 260px; }
        .topbar { background: white; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { font-size: 1.5rem; color: #0f172a; }
        .back-btn { padding: 8px 16px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 0.9rem; }
        .content { padding: 24px; }
        .tabs { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
        .tab { padding: 8px 16px; border-radius: 20px; text-decoration: none; color: #475569; background: white; border: 1px solid #e2e8f0; font-weight: 500; font-size: 0.9rem; }
        .tab.active { background: #0f172a; color: white; border-color: #0f172a; }
        .tab:hover:not(.active) { background: #f8fafc; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th, td { padding: 12px 20px; border-bottom: 1px solid #e2e8f0; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; color: #475569; font-weight: 600; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .empty-state { text-align: center; padding: 48px; color: #64748b; }
        @media (max-width: 768px) { /* sidebar CSS → includes/sidebar.php */ .main { margin-left: 0; } }
    
/* ── Responsive ── */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:#f1f5f9}
::-webkit-scrollbar-thumb{background:#cbd5e0;border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:#94a3b8}

@media(max-width:900px){
    .grid-2,.stats-grid,.kpi-grid{grid-template-columns:repeat(2,1fr)}
    .level-grid{grid-template-columns:repeat(2,1fr)}
    .form-grid{grid-template-columns:1fr}
    .info-grid{grid-template-columns:1fr}
    .hero-stats{display:none}
}
@media(max-width:768px){
    /* sidebar CSS → includes/sidebar.php */
    .main{margin-left:0}
    .topbar{padding:0 16px;height:auto;min-height:64px;flex-wrap:wrap;gap:8px;padding-top:10px;padding-bottom:10px}
    .content{padding:16px}
    .kpi-grid,.stats-grid{grid-template-columns:repeat(2,1fr)}
    .level-grid{flex-wrap:wrap}
    .card{padding:16px 14px}
    .tbl-wrap{overflow-x:auto}
    table{font-size:12px}
    thead th{padding:8px 10px;font-size:11px}
    tbody td{padding:8px 10px}
    .btn-row{flex-wrap:wrap}
    .back-btn{padding:7px 12px;font-size:12px}
    .profile-hero{flex-direction:column;text-align:center;padding:24px 16px}
    .hero-tags{justify-content:center}
}
@media(max-width:480px){
    .kpi-grid,.stats-grid{grid-template-columns:1fr}
    .grid-2{grid-template-columns:1fr}
}
</style>
</head>
<body>
    <div class="layout">
        <?php $activePage='students'; require_once __DIR__.'/includes/sidebar.php'; ?>
        <main class="main">
            <div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
    <button class="menu-toggle" onclick="document.getElementById(\'sidebar\').classList.toggle(\'open\')"><i class="fas fa-bars"></i></button>
    <h1>My Students</h1>
                <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
            <div class="content">
                <div class="tabs">
                    <?php foreach ([100, 200, 300, 400, 500] as $l): ?>
                    <a href="?level=<?= $l ?>" class="tab <?= $selectedLevel == $l ? 'active' : '' ?>">
                        Level <?= $l ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <table>
                    <thead><tr><th>Matric</th><th>Name</th><th>Email</th><th>Level</th><th>Face</th></tr></thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($s['matric']) ?></strong></td>
                            <td><?= htmlspecialchars($s['full_name']) ?></td>
                            <td><?= htmlspecialchars($s['email'] ?? '-') ?></td>
                            <td><?= $s['level'] ?></td>
                            <td>
                                <span class="badge <?= $s['face_descriptor'] ? 'badge-success' : 'badge-warning' ?>">
                                    <?= $s['face_descriptor'] ? '✅' : '❌' ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($students)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:40px; color:#64748b;">No students at <?= $selectedLevel ?> Level.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>