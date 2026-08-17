<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

// ── Matric parser (YYcs[1|2]NNN format)
// 1=UTME → level=(currentYear-enrollYear+1)*100
// 2=DE   → level=(currentYear-enrollYear+2)*100  (DE enters at 200L)
// Program is 4 years → level is capped at 400 (no 500L).
// Spillover allowance: matric years older than MIN_ENROLL_YEAR are rejected as invalid.
const MIN_ENROLL_YEAR = 2021; // update this each session as the spillover window moves

function parseMatric(string $matric): ?array {
    if (!preg_match('/^(\d{2})[Cc][Ss]([12])(\d{3,})$/', trim($matric), $m)) return null;
    $enrollYear  = (int)$m[1] + 2000;
    if ($enrollYear < MIN_ENROLL_YEAR) return null; // exceeds spillover allowance
    $type        = $m[2] === '1' ? 'UTME' : 'DE';
    $yearsPassed = (int)date('Y') - $enrollYear;
    if ($yearsPassed < 0) $yearsPassed += 100;
    $level = ($yearsPassed + ($type === 'DE' ? 2 : 1)) * 100;
    return ['type' => $type, 'enroll_year' => $enrollYear, 'level' => max(100, min(400, $level))];
}


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
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $matricIn = trim($_POST['matric'] ?? '');
        $parsed   = parseMatric($matricIn);
        if (!$parsed) {
            header('Location: students.php?error=invalid_matric');
            exit;
        }
        // Trust the server-computed level, not whatever the client select happened to hold
        $pdo->prepare("INSERT INTO students (matric, full_name, email, phone, level) VALUES (?, ?, ?, ?, ?)")
           ->execute([strtoupper($matricIn), $_POST['full_name'], $_POST['email'], $_POST['phone'] ?? '', $parsed['level']]);
    } 
    elseif ($action === 'edit') {
        $pdo->prepare("UPDATE students SET full_name = ?, email = ?, phone = ?, level = ? WHERE matric = ?")
           ->execute([$_POST['full_name'], $_POST['email'], $_POST['phone'] ?? '', $_POST['level'], $_POST['matric']]);
    } 
    elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM students WHERE matric = ?")->execute([$_POST['matric']]);
    }
    elseif ($action === 'clear_session') {
        // Admin manually clears a stuck active session for a student
        $pdo->prepare("UPDATE students SET session_token = NULL, session_token_created_at = NULL WHERE matric = ?")
            ->execute([$_POST['matric']]);
    }
}

$students = $pdo->query("SELECT matric, full_name, email, phone, level, session_token, (face_descriptor IS NOT NULL AND face_descriptor <> '') AS face_enrolled FROM students ORDER BY level, full_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - Admin Portal</title>
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
        th, td { padding: 14px 16px; border-bottom: 1px solid #e2e8f0; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; color: #475569; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover { background: #f8fafc; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
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
                <?php $activePage='students'; require_once __DIR__.'/includes/sidebar.php'; ?>
        
        <main class="main">
            <?php if (($_GET['error'] ?? '') === 'invalid_matric'): ?>
            <div style="margin:16px 28px 0;padding:14px 18px;background:#fef2f2;border:2px solid #fecaca;border-radius:10px;color:#991b1b;font-weight:600;font-size:.9rem;">
                ⚠ Invalid matric number. Format must be YYcs[1|2]NNN (e.g. 23CS1039 or 24CS2001), and the enrollment year must be <?= (int)MIN_ENROLL_YEAR ?> or newer (spillover limit exceeded for older years). Student was not saved — please check with the admin office if this matric predates the spillover window.
            </div>
            <?php endif; ?>
            <div class="topbar">
                <div style="display:flex; align-items:center; gap:16px;">
                    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
                    <h1>Manage Students</h1>
                </div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                    <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Student</button>
                </div>
            </div>
            <div class="content">
                <table>
                    <thead>
                        <tr>
                            <th>Matric</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Level</th>
                            <th>Face Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($s['matric']) ?></strong></td>
                            <td><?= htmlspecialchars($s['full_name']) ?></td>
                            <td><?= htmlspecialchars($s['email'] ?? '-') ?></td>
                            <td><?= $s['level'] ?></td>
                            <td>
                                <?php if ($s['face_enrolled']): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> Enrolled</span>
                                <?php else: ?>
                                    <span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>
                                <?php endif; ?>
                            </td>
                            <td style="display:flex; gap:6px;">
                                <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($s) ?>)'><i class="fas fa-edit"></i> Edit</button>
                                <form method="post" style="display:inline" onsubmit="return confirm('Delete this student?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="matric" value="<?= $s['matric'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php if (!empty($s['session_token'])): ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Clear active session for <?= htmlspecialchars($s['matric']) ?>? The student will need to log in again.')">
                                    <input type="hidden" name="action" value="clear_session">
                                    <input type="hidden" name="matric" value="<?= $s['matric'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background:#f59e0b;color:white;border:none;padding:6px 10px;border-radius:6px;cursor:pointer;" title="Active session — click to clear">
                                        <i class="fas fa-lock"></i> Active
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($students)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:60px; color:#64748b;"><i class="fas fa-users" style="font-size:3rem;opacity:0.3;display:block;margin-bottom:16px;"></i>No students found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    
    <!-- Add Modal -->
    <div class="modal" id="addModal">
        <div class="modal-card">
            <h3><i class="fas fa-user-graduate"></i> Add New Student</h3>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="form-group"><label>Matric Number *</label><input name="matric" required placeholder="e.g., 23CS1039 or 24CS2001" pattern="[0-9]{2}[Cc][Ss][12][0-9]{3,}" id="addMatric"></div>
                <div id="matricBadge" style="display:none;margin-top:-8px;margin-bottom:10px;padding:8px 12px;border-radius:8px;font-size:.82rem;font-weight:600;"></div>
                <div class="form-group"><label>Full Name *</label><input name="full_name" required placeholder="John Doe"></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="student@university.edu"></div>
                <div class="form-group"><label>Phone</label><input name="phone" placeholder="+234 XXX XXX XXXX"></div>
                <div class="form-group"><label>Level *</label>
                    <select name="level" id="addLevel" required>
                        <option value="100">100 Level</option>
                        <option value="200">200 Level</option>
                        <option value="300" selected>300 Level</option>
                        <option value="400">400 Level</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Student</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Modal -->
    <div class="modal" id="editModal">
        <div class="modal-card">
            <h3><i class="fas fa-edit"></i> Edit Student</h3>
            <form method="post">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="matric" id="editMatric">
                <div id="editTypeBanner" style="display:none;"></div>
                <div class="form-group"><label>Full Name</label><input name="full_name" id="editName" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" id="editEmail"></div>
                <div class="form-group"><label>Phone</label><input name="phone" id="editPhone"></div>
                <div class="form-group"><label>Level</label>
                    <select name="level" id="editLevel" required>
                        <option value="100">100</option><option value="200">200</option><option value="300">300</option><option value="400">400</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Student</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Matric parser: YYcs[1|2]NNN  (1=UTME, 2=DE enters at 200L)
        // 4-year program → level capped at 400. Spillover limit: enrollment year must be
        // MIN_ENROLL_YEAR or newer (kept in sync with the PHP const of the same name).
        const MIN_ENROLL_YEAR = 2021;
        function parseMatric(matric) {
            const m = matric.toUpperCase().match(/^(\d{2})CS([12])(\d{3,})$/);
            if (!m) return null;
            const enrollYear = 2000 + parseInt(m[1]);
            if (enrollYear < MIN_ENROLL_YEAR) return null; // exceeds spillover allowance
            const type        = m[2] === '1' ? 'UTME' : 'DE';
            const currentYear = new Date().getFullYear();
            let   yearsPassed = currentYear - enrollYear;
            if (yearsPassed < 0) yearsPassed += 100;
            const levelOffset = type === 'DE' ? 2 : 1;
            let   level = (yearsPassed + levelOffset) * 100;
            level = Math.max(100, Math.min(400, level));
            return { type, enrollYear, level };
        }

        // Auto-detect when admin types matric in Add modal
        document.getElementById('addMatric').addEventListener('input', function () {
            const badge    = document.getElementById('matricBadge');
            const levelSel = document.getElementById('addLevel');
            const val      = this.value.trim();
            if (val.length < 8) { badge.style.display = 'none'; return; }
            const m = val.toUpperCase().match(/^(\d{2})CS([12])(\d{3,})$/);
            const info = parseMatric(val);
            if (!info) {
                badge.style.cssText = 'display:block;padding:8px 12px;border-radius:8px;font-size:.82rem;font-weight:600;background:#fef2f2;border:1px solid #fecaca;color:#dc2626;';
                if (m && (2000 + parseInt(m[1])) < MIN_ENROLL_YEAR) {
                    badge.textContent = '⚠ Matric year too old — exceeds the ' + MIN_ENROLL_YEAR + '+ spillover allowance. Escalate to admin.';
                } else {
                    badge.textContent = '⚠ Invalid format. Expected: YYcs[1|2]NNN  e.g. 23CS1039 (UTME) or 24CS2001 (DE)';
                }
                return;
            }
            levelSel.value = String(info.level);
            const isDE = info.type === 'DE';
            badge.style.cssText = 'display:block;padding:8px 12px;border-radius:8px;font-size:.82rem;font-weight:600;'
                + (isDE ? 'background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;'
                        : 'background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;');
            badge.textContent = (isDE ? '🎓 Direct Entry (DE)' : '📋 UTME')
                + '  ·  Enrolled ' + info.enrollYear
                + '  ·  Auto-detected Level: ' + info.level;
        });

        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
            document.getElementById('matricBadge').style.display = 'none';
        }
        function closeAddModal() { document.getElementById('addModal').classList.remove('active'); }

        function openEditModal(data) {
            document.getElementById('editMatric').value = data.matric;
            document.getElementById('editName').value   = data.full_name;
            document.getElementById('editEmail').value  = data.email || '';
            document.getElementById('editPhone').value  = data.phone || '';
            document.getElementById('editLevel').value  = data.level;
            // Show UTME/DE type info in the edit modal
            const info   = parseMatric(data.matric);
            const banner = document.getElementById('editTypeBanner');
            if (info && banner) {
                const isDE = info.type === 'DE';
                const mismatch = parseInt(data.level) !== info.level;
                banner.style.cssText = 'display:block;margin-bottom:10px;padding:8px 12px;border-radius:8px;font-size:.82rem;font-weight:600;'
                    + (mismatch ? 'background:#fffbeb;border:1px solid #fde68a;color:#92400e;'
                                : isDE ? 'background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;'
                                       : 'background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;');
                banner.textContent = (isDE ? '🎓 Direct Entry (DE)' : '📋 UTME')
                    + '  ·  Enrolled ' + info.enrollYear
                    + '  ·  Expected Level: ' + info.level
                    + (mismatch ? '  ⚠ Stored level (' + data.level + ') differs — verify before saving' : '');
            }
            document.getElementById('editModal').classList.add('active');
        }
        function closeEditModal() { document.getElementById('editModal').classList.remove('active'); }

        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
        });
    </script>
</body>
</html>