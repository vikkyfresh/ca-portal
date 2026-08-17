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

// Get all tests by this lecturer
$stmt = $pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM attempts WHERE test_id = t.id) as submissions FROM tests t WHERE t.created_by = ? ORDER BY t.created_at DESC");
$stmt->execute([$lecturerId]);
$tests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tests - Lecturer Portal</title>
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
        .nav-section { font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:rgba(255,255,255,.35); padding:12px 12px 5px; }
        /* .nav a defined in includes/sidebar.php */
        .nav a i { width:18px; text-align:center; font-size:.88rem; }
        
        /* sidebar CSS → includes/sidebar.php */
        .nav a i { width: 20px; }
        .main { flex: 1; margin-left: 260px; }
        .topbar { background: white; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; position: sticky; top: 0; z-index: 50; }
        .topbar h1 { font-size: 1.5rem; color: #0f172a; }
        .back-btn { padding: 8px 16px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 0.9rem; display: flex; align-items: center; gap: 6px; }
        .back-btn:hover { background: #e2e8f0; }
        .content { padding: 24px; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th, td { text-align: left; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; font-size: 0.9rem; }
        th { background: #f8fafc; color: #475569; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover { background: #f8fafc; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .btn { padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 0.85rem; color: white; margin-right: 4px; border: none; cursor: pointer; display: inline-block; font-weight: 500; }
        .btn-blue { background: #3b82f6; }
        .btn-green { background: #10b981; }
        .btn-red { background: #ef4444; }
        .btn-copy { background: #8b5cf6; }
        .btn-regenerate { background: #f59e0b; color: white; }
        .btn-edit { background: #f59e0b; color: white; }
        .btn-preview { background: #6366f1; color: white; }
        .empty-state { text-align: center; padding: 60px 20px; color: #64748b; }
        .empty-state i { font-size: 3rem; margin-bottom: 16px; opacity: 0.5; }
        .menu-toggle { display: none; background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #475569; }
        @media (max-width: 768px) { /* sidebar CSS → includes/sidebar.php */ /* sidebar CSS → includes/sidebar.php */ .main { margin-left: 0; } .menu-toggle { display: block; } }
    
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
                <?php $activePage='tests'; require_once __DIR__.'/includes/sidebar.php'; ?>
        
        <main class="main">
            <div class="topbar">
                <div style="display:flex; align-items:center; gap:16px;">
                    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
                    <h1>My Tests</h1>
                </div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
                    <a href="create-test.php" class="btn btn-green"><i class="fas fa-plus"></i> Create Test</a>
                </div>
            </div>
            <div class="content">
                <?php if (empty($tests)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h3>No Tests Yet</h3>
                    <p>Create your first test to get started.</p>
                    <a href="create-test.php" class="btn btn-green" style="margin-top:16px;"><i class="fas fa-plus"></i> Create Test</a>
                </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Test Title</th>
                            <th>Course</th>
                            <th>Level</th>
                            <th>Questions</th>
                            <th>Submissions</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tests as $t): 
                            $now = new DateTime();
                            $expiry = new DateTime($t['expiry_date'] ?? 'now');
                            $isExpired = $now > $expiry;
                            
                            if (!$t['is_active']) {
                                $status = 'Inactive';
                                $badge = 'badge-danger';
                            } elseif ($isExpired) {
                                $status = 'Expired';
                                $badge = 'badge-warning';
                            } elseif ($t['link_used']) {
                                $status = 'Active';
                                $badge = 'badge-success';
                            } else {
                                $linkExpiry = new DateTime($t['link_expires_at'] ?? 'now');
                                if ($now > $linkExpiry) {
                                    $status = 'Link Expired';
                                    $badge = 'badge-warning';
                                } else {
                                    $status = 'Ready';
                                    $badge = 'badge-info';
                                }
                            }
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($t['test_title']) ?></strong></td>
                            <td><?= htmlspecialchars($t['course_code']) ?></td>
                            <td><?= $t['level'] ?></td>
                            <td><?= $t['total_questions'] ?></td>
                            <td><?= $t['submissions'] ?></td>
                            <td><span class="badge <?= $badge ?>"><?= $status ?></span></td>
                            <td style="display:flex; gap:4px; flex-wrap:wrap;">
                                <?php if ($t['submissions'] == 0): ?>
                                <a href="edit-test.php?id=<?= $t['id'] ?>" class="btn btn-edit" title="Edit Test">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <?php endif; ?>
                                <a href="questions.php?test=<?= $t['id'] ?>" class="btn btn-blue" title="Manage Questions">
                                    <i class="fas fa-question-circle"></i> Questions
                                </a>
                                <a href="view_results.php?test_id=<?= $t['id'] ?>" class="btn btn-green" title="View Results">
                                    <i class="fas fa-chart-bar"></i> Results
                                </a>
                                <?php if ($t['access_link']): ?>
                                <button class="btn btn-copy" onclick="copyTestLink('<?= htmlspecialchars($t['access_link'], ENT_QUOTES) ?>', this)" title="Copy Test Link">
                                    <i class="fas fa-link"></i> Copy
                                </button>
                                <button class="btn btn-regenerate" onclick="regenerateLink(<?= $t['id'] ?>, this)" title="Generate New Link (Old link becomes invalid)">
                                    <i class="fas fa-sync-alt"></i> New
                                </button>
                                <?php endif; ?>
                                <a href="preview-test.php?id=<?= $t['id'] ?>" class="btn btn-preview" title="Preview Test">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($t['submissions'] == 0): ?>
                                <button class="btn btn-red" onclick="deleteTest(<?= $t['id'] ?>, this)" title="Delete Test (Only if no submissions)">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script>
        function copyTestLink(link, btn) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(() => {
                    const originalHTML = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    btn.style.background = '#10b981';
                    setTimeout(() => { btn.innerHTML = originalHTML; btn.style.background = '#8b5cf6'; }, 2000);
                }).catch(() => { prompt('Copy this link:', link); });
            } else {
                prompt('Copy this link:', link);
            }
        }
        
        async function regenerateLink(testId, btn) {
            if (!confirm('⚠️ Generate a new link? The OLD link will stop working immediately.')) return;
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;
            const formData = new FormData();
            formData.append('action', 'regenerate_link');
            formData.append('test_id', testId);
            try {
                const resp = await fetch('api/tests.php', { method: 'POST', body: formData });
                const data = await resp.json();
                if (data.success) {
                    alert('✅ New link generated! Old link is INVALID.');
                    navigator.clipboard?.writeText(data.test_link);
                    location.reload();
                } else {
                    alert('❌ Failed: ' + (data.message || 'Error'));
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            } catch(e) { alert('Error regenerating link'); btn.innerHTML = originalHTML; btn.disabled = false; }
        }
        
        async function deleteTest(testId, btn) {
            if (!confirm('⚠️ Delete this test PERMANENTLY? This CANNOT be undone.')) return;
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('test_id', testId);
            try {
                const resp = await fetch('api/tests.php', { method: 'POST', body: formData });
                const data = await resp.json();
                if (data.success) { alert('✅ Test deleted successfully!'); location.reload(); }
                else { alert('❌ ' + (data.message || 'Cannot delete')); btn.innerHTML = originalHTML; btn.disabled = false; }
            } catch(e) { alert('Error deleting test'); btn.innerHTML = originalHTML; btn.disabled = false; }
        }
        
        document.querySelector('.menu-toggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
        });
    </script>
</body>
</html>