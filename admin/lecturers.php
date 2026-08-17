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


$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $staffId = strtoupper(trim($_POST['staff_id'] ?? ''));
        $name    = trim($_POST['full_name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $dept    = trim($_POST['department'] ?? 'Computer Science');
        
        if ($staffId && $name && $email) {
            // Generate username from name
            $firstName = strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $name)[0]));
            $username  = $firstName . rand(10, 99);
            
            // DEFAULT PASSWORD IS ALWAYS "password"
            $defaultPassword = 'password';
            $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
            
            try {
                $pdo->prepare("INSERT INTO admins (staff_id, username, password_hash, full_name, email, department, role, force_password_change, default_password_changed) VALUES (?, ?, ?, ?, ?, ?, 'lecturer', 1, 0)")
                   ->execute([$staffId, $username, $hash, $name, $email, $dept]);
                
                $message = "Lecturer '$name' added successfully!<br>
                            <strong>Staff ID:</strong> $staffId<br>
                            <strong>Username:</strong> $username<br>
                            <strong>Default Password:</strong> password";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Duplicate entry. Staff ID or email already exists.";
                } else {
                    $error = "Error: " . $e->getMessage();
                }
            }
        } else {
            $error = "All fields are required.";
        }
    }
    
    elseif ($action === 'edit') {
        $id      = intval($_POST['id'] ?? 0);
        $staffId = strtoupper(trim($_POST['staff_id'] ?? ''));
        $name    = trim($_POST['full_name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $dept    = trim($_POST['department'] ?? 'Computer Science');
        
        if ($id && $staffId && $name && $email) {
            $pdo->prepare("UPDATE admins SET staff_id = ?, full_name = ?, email = ?, department = ? WHERE id = ? AND role = 'lecturer'")
               ->execute([$staffId, $name, $email, $dept, $id]);
            $message = "Lecturer updated successfully!";
        }
    }
    
    elseif ($action === 'reset_password') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $defaultPassword = 'password';
            $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE admins SET password_hash = ?, force_password_change = 1, default_password_changed = 0 WHERE id = ? AND role = 'lecturer'")
               ->execute([$hash, $id]);
            $message = "Password reset to: <strong>password</strong>";
        }
    }
    
    elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            // Check if lecturer has courses or tests
            $courseCountStmt = $pdo->prepare("SELECT COUNT(*) FROM lecturer_courses WHERE lecturer_id = ?");
            $courseCountStmt->execute([$id]);
            $courseCount = $courseCountStmt->fetchColumn();

            $testCountStmt = $pdo->prepare("SELECT COUNT(*) FROM tests WHERE created_by = ?");
            $testCountStmt->execute([$id]);
            $testCount = $testCountStmt->fetchColumn();
            
            if ($courseCount > 0 || $testCount > 0) {
                $error = "Cannot delete. Lecturer has $courseCount course(s) and $testCount test(s).";
            } else {
                $pdo->prepare("DELETE FROM admins WHERE id = ? AND role = 'lecturer'")->execute([$id]);
                $message = "Lecturer deleted successfully!";
            }
        }
    }
}

$lecturers = $pdo->query("SELECT a.*, (SELECT COUNT(*) FROM lecturer_courses WHERE lecturer_id = a.id) as course_count, (SELECT COUNT(*) FROM tests WHERE created_by = a.id) as test_count FROM admins a WHERE a.role = 'lecturer' ORDER BY a.full_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Lecturers - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; }
        .layout{display:flex;min-height:100vh}
        /* ── Sidebar ── */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */ /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */ /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */ /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        .nav a i { width:18px; text-align:center; font-size:.88rem; }
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        .nav a i { width: 20px; }
        .main { flex: 1; margin-left: 260px; }
        .topbar { background: white; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; position: sticky; top: 0; z-index: 50; }
        .topbar h1 { font-size: 1.5rem; color: #0f172a; }
        .back-btn { padding: 8px 16px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 0.9rem; }
        .back-btn:hover { background: #e2e8f0; }
        .content { padding: 24px; }
        
        .alert { padding: 14px 20px; border-radius: 10px; margin-bottom: 24px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        
        .btn { padding: 8px 16px; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-size: 0.9rem; transition: all 0.2s; }
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { background: #1e3a8a; }
        .btn-secondary { background: #3b82f6; color: white; }
        .btn-secondary:hover { background: #2563eb; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 6px 12px; font-size: 0.8rem; }
        
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th, td { padding: 14px 16px; border-bottom: 1px solid #e2e8f0; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; color: #475569; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover { background: #f8fafc; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); z-index: 200; align-items: center; justify-content: center; padding: 20px; }
        .modal.active { display: flex; }
        .modal-card { background: white; border-radius: 16px; padding: 28px; max-width: 520px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.3); }
        .modal-card h3 { margin-bottom: 20px; color: #0f172a; display: flex; align-items: center; gap: 8px; font-size: 1.2rem; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; color: #475569; font-weight: 500; font-size: 0.9rem; }
        .form-group input { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem; font-family: inherit; }
        .form-group input:focus { outline: none; border-color: #1e3a8a; }
        .form-actions { display: flex; gap: 10px; margin-top: 24px; justify-content: flex-end; }
        .btn-cancel { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .password-note { background: #fef3c7; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem; color: #92400e; border-left: 4px solid #f59e0b; }
        
        .menu-toggle { display: none; background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #475569; }
        .empty-state { text-align: center; padding: 60px 20px; color: #64748b; }
        .empty-state i { font-size: 3rem; margin-bottom: 16px; opacity: 0.5; }
        
        @media (max-width: 768px) { 
            /* → includes/sidebar.php */ 
            /* → includes/sidebar.php */ 
            .main { margin-left: 0; } 
            .menu-toggle { display: block; } 
        }
    
/* ── Responsive ── */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:#f1f5f9}
::-webkit-scrollbar-thumb{background:#cbd5e0;border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:#94a3b8}

@media(max-width:1100px){
    .kpi-grid{grid-template-columns:repeat(2,1fr)}
    .kpi-row{grid-template-columns:repeat(3,1fr)}
    .charts-row{grid-template-columns:1fr}
    .level-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:900px){
    .grid-2,.grid-3,.two-col{grid-template-columns:1fr}
    .portal-status-row{grid-template-columns:repeat(2,1fr)}
    .level-grid{grid-template-columns:repeat(2,1fr)}
    .stats-row-grid{grid-template-columns:repeat(2,1fr)}
    .form-grid{grid-template-columns:1fr}
    .info-grid{grid-template-columns:1fr}
    .perms-list{grid-template-columns:1fr}
    .hero-kpis{display:none}
    .hero-stats{display:none}
}
@media(max-width:768px){
    /* → includes/sidebar.php */
    .main{margin-left:0}
    .topbar{padding:0 16px;height:auto;min-height:64px;flex-wrap:wrap;gap:8px;padding-top:10px;padding-bottom:10px}
    .topbar-left h1{font-size:1.1rem}
    .content{padding:16px}
    .kpi-grid,.kpi-row{grid-template-columns:repeat(2,1fr)}
    .level-grid{grid-template-columns:repeat(2,1fr)}
    .quick-grid{grid-template-columns:repeat(2,1fr)}
    .profile-hero{flex-direction:column;text-align:center;padding:24px 16px}
    .hero-tags{justify-content:center}
    .admin-hero{flex-direction:column;text-align:center;padding:24px 16px}
    .card{padding:16px 14px}
    .tbl-wrap{overflow-x:auto}
    table{font-size:12px}
    thead th{padding:8px 10px;font-size:11px}
    tbody td{padding:8px 10px}
    .btn-row{flex-wrap:wrap}
    .back-btn{padding:7px 12px;font-size:12px}
    .g-box{min-width:50px;padding:10px 6px}
}
@media(max-width:480px){
    .kpi-grid,.kpi-row{grid-template-columns:1fr}
    .level-grid{grid-template-columns:1fr 1fr}
    .portal-status-row{grid-template-columns:1fr 1fr}
    .quick-grid{grid-template-columns:1fr}
    .hero-tags{flex-wrap:wrap}
}
</style>
</head>
<body>
    <div class="layout">
                <?php $activePage='lecturers'; require_once __DIR__.'/includes/sidebar.php'; ?>
        
        <main class="main">
            <div class="topbar">
                <div style="display:flex; align-items:center; gap:16px;">
                    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
                    <h1>Manage Lecturers</h1>
                </div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                    <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Lecturer</button>
                </div>
            </div>
            <div class="content">
                
                <?php if ($message): ?>
                <div class="alert alert-success"><?= $message ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
                <?php endif; ?>
                
                <?php if (empty($lecturers)): ?>
                <div class="empty-state">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <h3>No Lecturers Found</h3>
                    <p>Click "Add Lecturer" to add one.</p>
                </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Staff ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Department</th>
                            <th>Courses</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lecturers as $l): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($l['staff_id']) ?></strong></td>
                            <td><?= htmlspecialchars($l['full_name']) ?></td>
                            <td><?= htmlspecialchars($l['email']) ?></td>
                            <td><?= htmlspecialchars($l['department']) ?></td>
                            <td><span class="badge badge-info"><?= $l['course_count'] ?> courses</span></td>
                            <td style="display:flex; gap:6px; flex-wrap:wrap;">
                                <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($l) ?>)'><i class="fas fa-edit"></i> Edit</button>
                                <form method="post" style="display:inline" onsubmit="return confirm('Reset password to default?')">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-key"></i> Reset PW</button>
                                </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('Delete this lecturer?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Add Modal -->
    <div class="modal" id="addModal">
        <div class="modal-card">
            <h3><i class="fas fa-user-plus"></i> Add New Lecturer</h3>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="form-group"><label>Staff ID *</label><input name="staff_id" required placeholder="e.g., LEC/CS/2024/001"></div>
                <div class="form-group"><label>Full Name *</label><input name="full_name" required placeholder="Dr. John Smith"></div>
                <div class="form-group"><label>Email *</label><input type="email" name="email" required placeholder="lecturer@university.edu"></div>
                <div class="form-group"><label>Department</label><input name="department" value="Computer Science"></div>
                <div class="password-note">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Default Password:</strong> <code>password</code><br>
                    <small>Lecturer will be forced to change on first login.</small>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Lecturer</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Modal -->
    <div class="modal" id="editModal">
        <div class="modal-card">
            <h3><i class="fas fa-edit"></i> Edit Lecturer</h3>
            <form method="post">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <div class="form-group"><label>Staff ID</label><input name="staff_id" id="editStaffId" required></div>
                <div class="form-group"><label>Full Name</label><input name="full_name" id="editName" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail" required></div>
                <div class="form-group"><label>Department</label><input name="department" id="editDept"></div>
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Lecturer</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openAddModal() { document.getElementById('addModal').classList.add('active'); }
        function closeAddModal() { document.getElementById('addModal').classList.remove('active'); }
        
        function openEditModal(data) {
            document.getElementById('editId').value = data.id;
            document.getElementById('editStaffId').value = data.staff_id;
            document.getElementById('editName').value = data.full_name;
            document.getElementById('editEmail').value = data.email || '';
            document.getElementById('editDept').value = data.department || '';
            document.getElementById('editModal').classList.add('active');
        }
        function closeEditModal() { document.getElementById('editModal').classList.remove('active'); }
        
        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
        });
        
        document.querySelector('.menu-toggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
        });
    </script>
</body>
</html>