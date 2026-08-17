<?php
/**
 * Shared Admin Sidebar Include
 * Usage: require_once __DIR__ . '/includes/sidebar.php';
 * Requires: $photoSrc, $adminName to be set before including, and $activePage string
 * e.g. $activePage = 'dashboard';
 */
$activePage = $activePage ?? '';
?>
<!-- ===== ADMIN SIDEBAR CSS ===== -->
<style>
/* Sidebar */
.sidebar{width:260px;background:linear-gradient(180deg,#0f172a 0%,#1e3a8a 100%);color:white;position:fixed;top:0;left:0;bottom:0;z-index:100;display:flex;flex-direction:column;overflow-y:auto;transition:transform .3s}
.sidebar::-webkit-scrollbar{width:6px}
.sidebar::-webkit-scrollbar-track{background:rgba(255,255,255,.05);border-radius:10px}
.sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.35);border-radius:10px;border:1px solid rgba(255,255,255,.1)}
.sidebar::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.55)}
.sidebar-logo{padding:18px 20px;border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:12px;flex-shrink:0}
.sidebar-logo-icon{width:42px;height:42px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sidebar-logo-icon img{width:100%;height:100%;object-fit:contain}
.sidebar-logo-text{font-size:13px;font-weight:800;letter-spacing:.01em;line-height:1.2}
.sidebar-logo-sub{font-size:10px;opacity:.55;margin-top:1px}
.sidebar-user{margin:10px 12px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:11px 13px;display:flex;align-items:center;gap:10px;flex-shrink:0}
.sidebar-user-avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.25);flex-shrink:0}
.sidebar-user-name{font-size:12.5px;font-weight:600;color:white;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px}
.sidebar-user-role{font-size:10.5px;color:rgba(255,255,255,.5)}
.nav{padding:6px 10px;flex:1}
.nav-section{font-size:9.5px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.3);padding:12px 10px 4px}
.nav a{display:flex;align-items:center;gap:10px;padding:9px 12px;color:rgba(255,255,255,.72);text-decoration:none;border-radius:9px;margin-bottom:1px;font-size:.84rem;transition:all .18s}
.nav a i{width:17px;text-align:center;font-size:.85rem;opacity:.85}
.nav a:hover,.nav a.active{background:rgba(255,255,255,.15);color:white}
.nav a.active{font-weight:600;background:rgba(255,255,255,.18)}
.sidebar-footer{padding:12px 18px;border-top:1px solid rgba(255,255,255,.08);font-size:10px;color:rgba(255,255,255,.28);flex-shrink:0}

/* Main layout */
.layout{display:flex;min-height:100vh}
.main{flex:1;margin-left:260px;min-width:0;display:flex;flex-direction:column}

/* Mobile toggle */
.menu-toggle{display:none;background:none;border:none;font-size:1.2rem;cursor:pointer;color:#475569;padding:6px}

@media(max-width:768px){
    .sidebar{transform:translateX(-100%)}
    .sidebar.open{transform:translateX(0)}
    .main{margin-left:0}
    .menu-toggle{display:block}
}
</style>

<div style="position:fixed;top:14px;right:20px;z-index:1500;">
    <?php $notifApiPath = '../api/notifications.php'; $notifViewAllPath = 'notifications.php'; require __DIR__ . '/../../includes/notification-bell.php'; ?>
</div>

<!-- ===== ADMIN SIDEBAR HTML ===== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <div class="sidebar-logo-icon">
            <img src="../assets/images/faculty-logo.png" alt="Faculty of Computing">
        </div>
        <div>
            <div class="sidebar-logo-text">PAAU CA Portal</div>
            <div class="sidebar-logo-sub">Admin · CS Department</div>
        </div>
    </div>
    <div class="sidebar-user">
        <img src="<?= $photoSrc ?? 'https://ui-avatars.com/api/?name=Admin&background=1e3a8a&color=fff&size=80&bold=true' ?>" alt="avatar" class="sidebar-user-avatar" id="sidebar-avatar"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($adminName ?? 'Admin') ?>&background=1e3a8a&color=fff&size=80&bold=true'">
        <div>
            <div class="sidebar-user-name"><?= htmlspecialchars($adminName ?? $_SESSION['admin_name'] ?? 'Administrator') ?></div>
            <div class="sidebar-user-role">System Administrator</div>
        </div>
    </div>
    <nav class="nav">
        <div class="nav-section">Main</div>
        <a href="dashboard.php" <?= $activePage==='dashboard'?'class="active"':'' ?>><i class="fas fa-gauge-high"></i> Dashboard</a>
        <a href="students.php" <?= $activePage==='students'?'class="active"':'' ?>><i class="fas fa-users"></i> Students</a>
        <a href="lecturers.php" <?= $activePage==='lecturers'?'class="active"':'' ?>><i class="fas fa-chalkboard-teacher"></i> Lecturers</a>
        <a href="results.php" <?= $activePage==='results'?'class="active"':'' ?>><i class="fas fa-chart-bar"></i> Results</a>
        <div class="nav-section">Reports</div>
        <a href="reports.php" <?= $activePage==='reports'?'class="active"':'' ?>><i class="fas fa-file-lines"></i> Reports</a>
        <a href="analytics.php" <?= $activePage==='analytics'?'class="active"':'' ?>><i class="fas fa-chart-pie"></i> Analytics</a>
        <div class="nav-section">System</div>
        <a href="courses.php" <?= $activePage==='courses'?'class="active"':'' ?>><i class="fas fa-book"></i> Courses</a>
        <a href="tests.php" <?= $activePage==='tests'?'class="active"':'' ?>><i class="fas fa-file-alt"></i> All Tests</a>
        <a href="face-enrollment.php" <?= $activePage==='face-enrollment'?'class="active"':'' ?>><i class="fas fa-camera"></i> Face Enrolment</a>
        <a href="portal-control.php" <?= $activePage==='portal-control'?'class="active"':'' ?>><i class="fas fa-toggle-on"></i> Portal Control</a>
        <a href="audit-logs.php" <?= $activePage==='audit-logs'?'class="active"':'' ?>><i class="fas fa-clipboard-list"></i> Audit Logs</a>
        <a href="settings.php" <?= $activePage==='settings'?'class="active"':'' ?>><i class="fas fa-gear"></i> Settings</a>
        <div class="nav-section">Account</div>
        <a href="profile.php" <?= $activePage==='profile'?'class="active"':'' ?>><i class="fas fa-user-circle"></i> My Profile</a>
        <a href="notifications.php" <?= $activePage==='notifications'?'class="active"':'' ?>><i class="fas fa-bell"></i> Notifications</a>
        <a href="logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
    </nav>
    <div class="sidebar-footer">CS Dept · PAAU Anyigba · <?= date('Y') ?></div>
</aside>
