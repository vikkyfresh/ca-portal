<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

$adminId   = (int)$_SESSION['admin_id'];
$adminName = $_SESSION['admin_name'] ?? 'Administrator';
$academicSession = getAcademicSetting('academic_session', '2025/2026');
$currentSemester = getAcademicSetting('current_semester', '2nd Semester');

$photoSrc = 'https://ui-avatars.com/api/?name='.urlencode($adminName).'&background=1e3a8a&color=fff&size=80&bold=true';
$stmtP = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
$stmtP->execute([$adminId]);
$pr = $stmtP->fetch();
if (!empty($pr['photo'])) { $sp=dirname(__DIR__).'/'.ltrim($pr['photo'],'/'); if(file_exists($sp)) $photoSrc='../'.ltrim($pr['photo'],'/'); }

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $keys = [
            'academic_session','current_semester','session_timeout',
            'max_login_attempts','face_verification_threshold',
            'default_test_duration','default_questions_count',
            'system_email','passing_score',
        ];
        foreach ($keys as $k) {
            $v = trim($_POST[$k] ?? '');
            $pdo->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]);
        }
        // Update session academic values
        $_SESSION['academic_session'] = $_POST['academic_session'] ?? $academicSession;
        $msg = 'Settings saved successfully.';
        logAudit('portal_setting_changed', 'admin', $adminId, $adminName, $adminName . ' updated system settings.');
    } catch(Exception $e) {
        $err = 'Error: '.$e->getMessage();
    }
}

$stmt = $pdo->query("SELECT setting_key,setting_value FROM system_settings");
$s = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
function gs($k,$d=''){ global $s; return htmlspecialchars($s[$k]??$d); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — Admin Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
::-webkit-scrollbar{width:6px}::-webkit-scrollbar-track{background:#f1f5f9}::-webkit-scrollbar-thumb{background:#cbd5e0;border-radius:10px}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;color:#0f172a}
.layout{display:flex;min-height:100vh}
/* → includes/sidebar.php */
/* → includes/sidebar.php *//* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php *//* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php *//* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
/* → includes/sidebar.php */
.nav a i{width:17px;text-align:center;font-size:.85rem}
/* → includes/sidebar.php */
/* → includes/sidebar.php */
.main{flex:1;margin-left:260px}
.topbar{background:white;padding:0 24px;border-bottom:1px solid #e2e8f0;height:62px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.topbar h1{font-size:1.15rem;font-weight:700;color:#0f172a}
.topbar p{font-size:11.5px;color:#64748b;margin-top:1px}
.back-btn{padding:8px 16px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;transition:all .2s}
.back-btn:hover{background:#e2e8f0}
.content{padding:22px 24px 48px;max-width:820px}
.section-label{font-size:10.5px;font-weight:700;letter-spacing:.11em;text-transform:uppercase;color:#94a3b8;margin-bottom:11px;display:flex;align-items:center;gap:8px}
.section-label::after{content:'';flex:1;height:1px;background:#e2e8f0}
.card{background:white;border-radius:15px;padding:22px 24px;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:18px}
.card-header{display:flex;align-items:center;gap:10px;margin-bottom:18px;padding-bottom:13px;border-bottom:1px solid #f1f5f9}
.card-header i{color:#1e3a8a;font-size:1.05rem;width:20px;text-align:center}
.card-header h3{font-size:.97rem;font-weight:700;color:#0f172a}
.card-header .sub{margin-left:auto;font-size:11.5px;color:#94a3b8}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{margin-bottom:0}
.form-group label{display:block;font-size:11.5px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.form-group input,.form-group select{width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13.5px;color:#0f172a;transition:border .2s;background:white}
.form-group input:focus,.form-group select:focus{outline:none;border-color:#1e3a8a;box-shadow:0 0 0 3px rgba(30,58,138,.08)}
.field-note{font-size:11px;color:#94a3b8;margin-top:4px}
.alert{padding:11px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:9px}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.btn-row{display:flex;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid #f1f5f9}
.btn{padding:10px 22px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:7px;transition:all .2s}
.btn-primary{background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white}
.btn-primary:hover{opacity:.88;transform:translateY(-1px)}
.btn-secondary{background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0}
.btn-secondary:hover{background:#e2e8f0}
@media(max-width:768px){/* → includes/sidebar.php */.main{margin-left:0}.form-grid{grid-template-columns:1fr}.content{padding:16px}}
</style>
</head>
<body>
<div class="layout">
<?php $activePage='settings'; require_once __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
<div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
    <button class="menu-toggle" onclick="document.getElementById(\'sidebar\').classList.toggle(\'open\')"><i class="fas fa-bars"></i></button>
    <div><h1><i class="fas fa-gear" style="color:#1e3a8a;margin-right:7px;font-size:1rem"></i>System Settings</h1><p><?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?></p></div>
    <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
</div>

<div class="content">

<?php if($msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<form method="POST">

<!-- Academic Settings -->
<div class="section-label"><i class="fas fa-calendar" style="color:#1e3a8a"></i> Academic Settings</div>
<div class="card">
    <div class="card-header"><i class="fas fa-graduation-cap"></i><h3>Academic Session</h3><span class="sub">Controls session labels shown portal-wide</span></div>
    <div class="form-grid">
        <div class="form-group">
            <label>Academic Session</label>
            <input type="text" name="academic_session" value="<?= gs('academic_session','2025/2026') ?>" placeholder="e.g. 2025/2026">
        </div>
        <div class="form-group">
            <label>Current Semester</label>
            <select name="current_semester">
                <?php foreach(['1st Semester','2nd Semester','3rd Semester'] as $sem): ?>
                <option value="<?= $sem ?>" <?= gs('current_semester','2nd Semester')===$sem?'selected':'' ?>><?= $sem ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>System Email</label>
            <input type="email" name="system_email" value="<?= gs('system_email') ?>" placeholder="admin@paau.edu.ng">
            <div class="field-note">Used for system notifications</div>
        </div>
        <div class="form-group">
            <label>Default Passing Score (%)</label>
            <input type="number" name="passing_score" value="<?= gs('passing_score','40') ?>" min="0" max="100">
            <div class="field-note">Applied to all tests without a custom pass mark</div>
        </div>
    </div>
</div>

<!-- Test Settings -->
<div class="section-label"><i class="fas fa-file-alt" style="color:#1e3a8a"></i> Test Defaults</div>
<div class="card">
    <div class="card-header"><i class="fas fa-sliders"></i><h3>Default Test Configuration</h3><span class="sub">Applied when creating new tests</span></div>
    <div class="form-grid">
        <div class="form-group">
            <label>Default Duration (minutes)</label>
            <input type="number" name="default_test_duration" value="<?= gs('default_test_duration','20') ?>" min="5" max="180">
        </div>
        <div class="form-group">
            <label>Default Question Count</label>
            <input type="number" name="default_questions_count" value="<?= gs('default_questions_count','20') ?>" min="1" max="200">
        </div>
    </div>
</div>

<!-- Security Settings -->
<div class="section-label"><i class="fas fa-shield-halved" style="color:#1e3a8a"></i> Security</div>
<div class="card">
    <div class="card-header"><i class="fas fa-lock"></i><h3>Security Configuration</h3><span class="sub">Authentication and session settings</span></div>
    <div class="form-grid">
        <div class="form-group">
            <label>Session Timeout (seconds)</label>
            <input type="number" name="session_timeout" value="<?= gs('session_timeout','3600') ?>" min="300">
            <div class="field-note">3600 = 1 hour. Students auto-logged out after this time</div>
        </div>
        <div class="form-group">
            <label>Max Login Attempts</label>
            <input type="number" name="max_login_attempts" value="<?= gs('max_login_attempts','5') ?>" min="1" max="20">
            <div class="field-note">Account locked after this many failed attempts</div>
        </div>
        <div class="form-group">
            <label>Face Verification Threshold</label>
            <input type="number" name="face_verification_threshold" value="<?= gs('face_verification_threshold','0.6') ?>" min="0.1" max="1.0" step="0.05">
            <div class="field-note">0.6 recommended. Lower = stricter matching</div>
        </div>
    </div>
</div>

<div class="btn-row">
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save All Settings</button>
    <button type="reset" class="btn btn-secondary"><i class="fas fa-times"></i> Reset Changes</button>
</div>
</form>

</div>
</main>
</div>
</body>
</html>
