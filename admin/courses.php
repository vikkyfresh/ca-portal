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


// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action'] ?? '';
    $lecturerId  = intval($_POST['lecturer_id'] ?? 0);
    $courseCode  = strtoupper(trim($_POST['course_code'] ?? ''));
    $courseTitle = trim($_POST['course_title'] ?? '');
    $level       = in_array(intval($_POST['level'] ?? 0), [100,200,300,400,500]) ? intval($_POST['level']) : 0;
    $id          = intval($_POST['id'] ?? 0);

    if ($action === 'add' && $lecturerId && $courseCode && $courseTitle && $level) {
        $pdo->prepare("INSERT INTO lecturer_courses (lecturer_id, course_code, course_title, level, assigned_by) VALUES (?, ?, ?, ?, ?)")
           ->execute([$lecturerId, $courseCode, $courseTitle, $level, $_SESSION['admin_id']]);
    }
    elseif ($action === 'edit' && $id && $lecturerId && $courseCode && $courseTitle && $level) {
        $pdo->prepare("UPDATE lecturer_courses SET lecturer_id = ?, course_code = ?, course_title = ?, level = ? WHERE id = ?")
           ->execute([$lecturerId, $courseCode, $courseTitle, $level, $id]);
    }
    elseif ($action === 'delete' && $id) {
        $pdo->prepare("DELETE FROM lecturer_courses WHERE id = ?")->execute([$id]);
    }
}

$courses = $pdo->query("SELECT lc.*, a.full_name as lecturer_name FROM lecturer_courses lc JOIN admins a ON lc.lecturer_id = a.id ORDER BY lc.level, lc.course_code")->fetchAll();
$lecturers = $pdo->query("SELECT id, full_name FROM admins WHERE role = 'lecturer' ORDER BY full_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; }
        .layout{display:flex;min-height:100vh}
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* .nav defined in includes/sidebar.php */
        /* .nav a defined in includes/sidebar.php */
        
        /* .nav a i defined in includes/sidebar.php */
        .main { flex: 1; margin-left: 260px; }
        .topbar { background: white; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; position: sticky; top: 0; z-index: 50; }
        .topbar h1 { font-size: 1.5rem; color: #0f172a; }
        .back-btn { padding: 8px 16px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 0.9rem; transition: all 0.2s; }
        .back-btn:hover { background: #e2e8f0; }
        .content { padding: 24px; }
        .btn { padding: 8px 16px; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-size: 0.9rem; transition: all 0.2s; }
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { background: #1e3a8a; }
        .btn-secondary { background: #3b82f6; color: white; }
        .btn-secondary:hover { background: #2563eb; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 6px 12px; font-size: 0.8rem; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .table-scroll table { box-shadow: none; min-width: 640px; }
        th, td { padding: 14px 16px; border-bottom: 1px solid #e2e8f0; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; color: #475569; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover { background: #f8fafc; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; background: #ede9fe; color: #5b21b6; }
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); z-index: 200; align-items: center; justify-content: center; padding: 20px; }
        .modal.active { display: flex; }
        .modal-card { background: white; border-radius: 16px; padding: 28px; max-width: 520px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.3); }
        .modal-card h3 { margin-bottom: 20px; color: #0f172a; display: flex; align-items: center; gap: 8px; font-size: 1.2rem; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; color: #475569; font-weight: 500; font-size: 0.9rem; }
        .form-group input, .form-group select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem; font-family: inherit; transition: border-color 0.2s; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #1e3a8a; }
        .form-actions { display: flex; gap: 10px; margin-top: 24px; justify-content: flex-end; }
        .btn-cancel { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .menu-toggle { display: none; background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #475569; }
        @media (max-width: 768px) { 
            /* sidebar CSS → includes/sidebar.php */ 
            /* sidebar CSS → includes/sidebar.php */ 
            .main { margin-left: 0; } 
            .menu-toggle { display: block; } 
        }
    </style>
</head>
<body>
    <div class="layout">
                <?php $activePage='courses'; require_once __DIR__.'/includes/sidebar.php'; ?>
        
        <main class="main">
            <div class="topbar">
                <div style="display:flex; align-items:center; gap:16px;">
                    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
                    <h1>Manage Courses</h1>
                </div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                    <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Assign Course</button>
                </div>
            </div>
            <div class="content">
                <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Course Code</th>
                            <th>Title</th>
                            <th>Level</th>
                            <th>Lecturer</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $c): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($c['course_code']) ?></strong></td>
                            <td><?= htmlspecialchars($c['course_title']) ?></td>
                            <td><span class="badge">Level <?= $c['level'] ?></span></td>
                            <td><?= htmlspecialchars($c['lecturer_name']) ?></td>
                            <td style="display:flex; gap:6px;">
                                <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($c) ?>)'><i class="fas fa-edit"></i> Edit</button>
                                <form method="post" style="display:inline" onsubmit="return confirm('Delete this course assignment?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($courses)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:60px; color:#64748b;"><i class="fas fa-book-open" style="font-size:3rem;opacity:0.3;display:block;margin-bottom:16px;"></i>No courses assigned yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Add Modal -->
    <div class="modal" id="addModal">
        <div class="modal-card">
            <h3><i class="fas fa-plus-circle"></i> Assign Course to Lecturer</h3>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="form-group"><label>Lecturer *</label>
                    <select name="lecturer_id" required>
                        <option value="">Select lecturer</option>
                        <?php foreach ($lecturers as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
                        <?php endforeach; ?>
                        <?php if (empty($lecturers)): ?>
                        <option value="" disabled>No lecturers available</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group"><label>Course Code *</label><input name="course_code" required placeholder="e.g., CSC301"></div>
                <div class="form-group"><label>Course Title *</label><input name="course_title" required placeholder="e.g., Data Structures and Algorithms"></div>
                <div class="form-group"><label>Level *</label>
                    <select name="level" required>
                        <option value="100">100 Level</option>
                        <option value="200">200 Level</option>
                        <option value="300" selected>300 Level</option>
                        <option value="400">400 Level</option>
                        <option value="500">500 Level</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Assign Course</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Modal -->
    <div class="modal" id="editModal">
        <div class="modal-card">
            <h3><i class="fas fa-edit"></i> Edit Course Assignment</h3>
            <form method="post">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <div class="form-group"><label>Lecturer</label>
                    <select name="lecturer_id" id="editLecturer" required>
                        <?php foreach ($lecturers as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Course Code</label><input name="course_code" id="editCode" required></div>
                <div class="form-group"><label>Course Title</label><input name="course_title" id="editTitle" required></div>
                <div class="form-group"><label>Level</label>
                    <select name="level" id="editLevel" required>
                        <option value="100">100</option><option value="200">200</option><option value="300">300</option><option value="400">400</option><option value="500">500</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Assignment</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openAddModal() { document.getElementById('addModal').classList.add('active'); }
        function closeAddModal() { document.getElementById('addModal').classList.remove('active'); }
        
        function openEditModal(data) {
            document.getElementById('editId').value = data.id;
            document.getElementById('editLecturer').value = data.lecturer_id;
            document.getElementById('editCode').value = data.course_code;
            document.getElementById('editTitle').value = data.course_title;
            document.getElementById('editLevel').value = data.level;
            document.getElementById('editModal').classList.add('active');
        }
        function closeEditModal() { document.getElementById('editModal').classList.remove('active'); }
        
        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
        });
    </script>
</body>
</html>