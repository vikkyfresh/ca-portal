<?php
session_start();
if (!isset($_SESSION['lecturer_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

$lecturerId = (int)$_SESSION['lecturer_id'];
$msg        = null;

$stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$lecturerId]);
$user = $stmt->fetch();
if (!$user) { session_destroy(); header('Location: index.php'); exit; }

$academicSession = getAcademicSetting('academic_session', '2025/2026');
$currentSemester = getAcademicSetting('current_semester', '2nd Semester');

$totalTestsStmt = $pdo->prepare("SELECT COUNT(*) FROM tests WHERE created_by = ?");
$totalTestsStmt->execute([$lecturerId]);
$totalTests = (int)$totalTestsStmt->fetchColumn();

$totalStudentsStmt = $pdo->prepare("SELECT COUNT(DISTINCT a.student_matric) FROM attempts a JOIN tests t ON a.test_id=t.id WHERE t.created_by=? AND a.status='completed'");
$totalStudentsStmt->execute([$lecturerId]);
$totalStudents = (int)$totalStudentsStmt->fetchColumn();

$avgScoreStmt = $pdo->prepare("SELECT AVG(a.percentage) FROM attempts a JOIN tests t ON a.test_id=t.id WHERE t.created_by=? AND a.status='completed'");
$avgScoreStmt->execute([$lecturerId]);
$avgScore = round((float)($avgScoreStmt->fetchColumn() ?? 0), 1);

$uploadDir = '../uploads/passports/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (isLecturerRestricted($lecturerId)) {
        $msg = ['type' => 'error', 'text' => '🔒 ' . LECTURER_RESTRICTED_MESSAGE];
    } elseif ($action === 'update_profile') {
        $email     = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $phone     = trim($_POST['phone'] ?? '');
        $photoPath = $user['photo'] ?? null;

        if (!empty($_FILES['photo']['name'])) {
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                $msg = ['type'=>'error','text'=>'Photo upload failed. Please try again.'];
            } elseif (in_array($ext, ['jpg','jpeg','png','webp'], true)) {
                if ($_FILES['photo']['size'] <= 2 * 1024 * 1024) {
                    $newFile = $uploadDir . 'lecturer_' . $lecturerId . '.' . $ext;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $newFile)) {
                        $photoPath = 'uploads/passports/lecturer_' . $lecturerId . '.' . $ext;
                        $user['photo'] = $photoPath;
                    } else {
                        $msg = ['type'=>'error','text'=>'Photo could not be saved. Check folder permissions.'];
                    }
                } else {
                    $msg = ['type'=>'error','text'=>'Photo must be under 2MB.'];
                }
            } else {
                $msg = ['type'=>'error','text'=>'Only JPG, PNG or WEBP images allowed.'];
            }
        }

        if (!$msg) {
            if ($email) {
                $pdo->prepare("UPDATE admins SET email=?,phone=?,photo=? WHERE id=?")
                    ->execute([$email,$phone,$photoPath,$lecturerId]);
                $user['email'] = $email;
                $user['phone'] = $phone;
                $msg = ['type'=>'success','text'=>'Profile updated successfully.'];
            } else {
                $msg = ['type'=>'error','text'=>'Please enter a valid email address.'];
            }
        }
    } elseif ($action === 'change_password') {
        $curr = $_POST['current_password'] ?? '';
        $new  = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        if (!password_verify($curr, $user['password_hash'])) {
            $msg = ['type'=>'error','text'=>'Current password is incorrect.'];
        } elseif (strlen($new) < 8) {
            $msg = ['type'=>'error','text'=>'New password must be at least 8 characters.'];
        } elseif ($new !== $conf) {
            $msg = ['type'=>'error','text'=>'New passwords do not match.'];
        } else {
            $pdo->prepare("UPDATE admins SET password_hash=? WHERE id=?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $lecturerId]);
            $msg = ['type'=>'success','text'=>'Password changed successfully.'];
        }
    }
}

$photoSrc = 'https://ui-avatars.com/api/?name='.urlencode($user['full_name']).'&background=1e3a8a&color=fff&size=200&bold=true';
if (!empty($user['photo'])) {
    $sp = dirname(__DIR__).'/'.ltrim($user['photo'],'/');
    if (file_exists($sp)) $photoSrc = '../'.ltrim($user['photo'],'/');
}

$lecturerName      = $user['full_name'];
$lecturerAvatarUrl = $photoSrc;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — Lecturer Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;color:#0f172a}
.layout{display:flex;min-height:100vh}
.main{flex:1;margin-left:260px;min-width:0;display:flex;flex-direction:column}
.topbar{background:white;padding:0 24px;border-bottom:1px solid #e2e8f0;height:62px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:50;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.topbar-left{flex:1}
.topbar-left h1{font-size:1.1rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:7px}
.topbar-left p{font-size:11.5px;color:#64748b;margin-top:1px}
.back-btn{padding:8px 16px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;white-space:nowrap}
.back-btn:hover{background:#e2e8f0}
.content{padding:22px 24px 48px;max-width:860px}

/* Hero — identical to admin */
.profile-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 55%,#1e40af 100%);border-radius:18px;padding:28px 30px;margin-bottom:22px;display:flex;align-items:center;gap:22px;flex-wrap:wrap;box-shadow:0 6px 24px rgba(15,23,42,.28)}
.avatar-wrap{position:relative;flex-shrink:0;cursor:pointer}
.avatar{width:86px;height:86px;border-radius:50%;border:3px solid rgba(255,255,255,.28);object-fit:cover;display:block;transition:opacity .2s}
.avatar-wrap:hover .avatar{opacity:.75}
.avatar-overlay{position:absolute;inset:0;border-radius:50%;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s}
.avatar-wrap:hover .avatar-overlay{opacity:1}
.avatar-overlay i{color:white;font-size:20px}
.avatar-badge{position:absolute;bottom:2px;right:2px;width:22px;height:22px;background:#10b981;border-radius:50%;border:2px solid white;display:flex;align-items:center;justify-content:center}
.avatar-badge i{font-size:9px;color:white}
.hero-info{flex:1}
.hero-name{font-size:1.4rem;font-weight:800;color:white;margin-bottom:3px}
.hero-role{font-size:12.5px;color:rgba(255,255,255,.65);margin-bottom:11px}
.hero-tags{display:flex;gap:7px;flex-wrap:wrap}
.hero-tag{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);color:rgba(255,255,255,.88);font-size:10.5px;font-weight:600;padding:3px 11px;border-radius:20px}
.hero-tag.blue{background:rgba(59,130,246,.18);border-color:rgba(59,130,246,.35);color:#93c5fd}
.hero-tag.green{background:rgba(16,185,129,.18);border-color:rgba(16,185,129,.35);color:#86efac}
.hero-stats{display:flex;gap:0}
.hero-stat{text-align:center;padding:0 20px;border-right:1px solid rgba(255,255,255,.13)}
.hero-stat:last-child{border-right:none;padding-right:0}
.hero-stat-val{font-size:1.6rem;font-weight:800;color:white;line-height:1}
.hero-stat-lbl{font-size:10.5px;color:rgba(255,255,255,.5);margin-top:3px}

/* Alert */
.alert{padding:11px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:9px}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}

/* Cards — identical to admin */
.card{background:white;border-radius:15px;padding:22px 24px;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-bottom:18px}
.card-header{display:flex;align-items:center;gap:10px;margin-bottom:18px;padding-bottom:13px;border-bottom:1px solid #f1f5f9}
.card-header i{color:#1e3a8a;font-size:1rem;width:20px;text-align:center}
.card-header h3{font-size:.97rem;font-weight:700;color:#0f172a}
.card-header .sub{margin-left:auto;font-size:11.5px;color:#94a3b8}
.card.danger-zone{border-left:4px solid #ef4444}
.card.danger-zone .card-header i{color:#ef4444}

/* Info grid — identical to admin */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}
.info-item{background:#f8fafc;border-radius:10px;padding:11px 15px;border:1px solid #e2e8f0}
.info-item .i-label{font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px}
.info-item .i-value{font-size:13.5px;font-weight:600;color:#0f172a}

/* Form — identical to admin */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}
.form-group label{display:block;font-size:11.5px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.form-group input{width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13.5px;color:#0f172a;transition:border .2s;background:white}
.form-group input:focus{outline:none;border-color:#1e3a8a;box-shadow:0 0 0 3px rgba(30,58,138,.08)}
.field-note{font-size:11px;color:#94a3b8;margin-top:4px}

/* Photo upload — identical to admin */
.photo-upload-zone{border:2px dashed #e2e8f0;border-radius:12px;padding:18px;text-align:center;cursor:pointer;transition:all .2s;background:#f8fafc;margin-bottom:18px}
.photo-upload-zone:hover{border-color:#1e3a8a;background:#eff6ff}
.photo-upload-zone i.upload-icon{font-size:26px;color:#94a3b8;display:block;margin-bottom:7px}
.photo-upload-zone p{font-size:13px;color:#64748b;margin-bottom:3px}
.photo-upload-zone small{font-size:11px;color:#94a3b8}
#photoPreview{width:72px;height:72px;border-radius:50%;object-fit:cover;margin:0 auto 8px;display:none;border:2px solid #1e3a8a}
#photoInput{display:none}

/* Password strength — identical to admin */
.pw-track{height:4px;border-radius:4px;background:#e2e8f0;margin-top:6px;overflow:hidden}
.pw-fill{height:100%;border-radius:4px;transition:all .3s;width:0}

/* Buttons — identical to admin */
.btn-row{display:flex;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid #f1f5f9;flex-wrap:wrap}
.btn{padding:10px 22px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:7px;transition:all .2s}
.btn-primary{background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white}
.btn-primary:hover{opacity:.88;transform:translateY(-1px)}
.btn-danger{background:#ef4444;color:white}
.btn-danger:hover{background:#dc2626}
.btn-secondary{background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0}
.btn-secondary:hover{background:#e2e8f0}

@media(max-width:900px){.hero-stats{display:none}.info-grid,.form-grid{grid-template-columns:1fr}}
@media(max-width:768px){.main{margin-left:0}.content{padding:16px}.profile-hero{padding:20px}.topbar{padding:0 14px}}
</style>
</head>
<body>
<div class="layout">
<?php $activePage='profile'; require_once __DIR__.'/includes/sidebar.php'; ?>
<main class="main">

<div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
    <div class="topbar-left">
        <h1><i class="fas fa-user-circle" style="color:#1e3a8a"></i> My Profile</h1>
        <p><?= htmlspecialchars($academicSession) ?> · <?= htmlspecialchars($currentSemester) ?></p>
    </div>
    <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
</div>

<div class="content">

<?php if($msg): ?>
<div class="alert alert-<?= $msg['type'] ?>">
    <i class="fas fa-<?= $msg['type']==='success'?'check-circle':'exclamation-circle' ?>"></i>
    <?= htmlspecialchars($msg['text']) ?>
</div>
<?php endif; ?>

<!-- Hero -->
<div class="profile-hero">
    <div class="avatar-wrap" onclick="document.getElementById('photoInput').click()" title="Click to change photo">
        <img src="<?= $photoSrc ?>" alt="Avatar" class="avatar" id="heroAvatar">
        <div class="avatar-overlay"><i class="fas fa-camera"></i></div>
        <div class="avatar-badge"><i class="fas fa-check"></i></div>
    </div>
    <div class="hero-info">
        <div class="hero-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="hero-role">Course Lecturer · Dept. of Computer Science · PAAU, Anyigba</div>
        <div class="hero-tags">
            <span class="hero-tag blue"><i class="fas fa-id-badge"></i> <?= htmlspecialchars($user['staff_id'] ?? 'Lecturer') ?></span>
            <span class="hero-tag"><i class="fas fa-calendar"></i> <?= htmlspecialchars($academicSession) ?></span>
            <span class="hero-tag green"><i class="fas fa-circle"></i> Active</span>
        </div>
    </div>
    <div class="hero-stats">
        <div class="hero-stat"><div class="hero-stat-val"><?= $totalTests ?></div><div class="hero-stat-lbl">Tests Created</div></div>
        <div class="hero-stat"><div class="hero-stat-val"><?= $totalStudents ?></div><div class="hero-stat-lbl">Students Tested</div></div>
        <div class="hero-stat"><div class="hero-stat-val"><?= $avgScore ?>%</div><div class="hero-stat-lbl">Avg Score</div></div>
    </div>
</div>

<!-- Identity (read-only for lecturer) -->
<div class="card">
    <div class="card-header"><i class="fas fa-fingerprint"></i><h3>Identity Information</h3><span class="sub">Contact admin to update these</span></div>
    <div class="info-grid">
        <div class="info-item"><div class="i-label">Full Name</div><div class="i-value"><?= htmlspecialchars($user['full_name']) ?></div></div>
        <div class="info-item"><div class="i-label">Staff ID</div><div class="i-value"><?= htmlspecialchars($user['staff_id'] ?? 'N/A') ?></div></div>
        <div class="info-item"><div class="i-label">Department</div><div class="i-value"><?= htmlspecialchars($user['department'] ?? 'Computer Science') ?></div></div>
        <div class="info-item"><div class="i-label">Institution</div><div class="i-value">Prince Abubakar Audu University, Anyigba</div></div>
        <div class="info-item"><div class="i-label">Session</div><div class="i-value"><?= htmlspecialchars($academicSession) ?></div></div>
        <div class="info-item"><div class="i-label">Semester</div><div class="i-value"><?= htmlspecialchars($currentSemester) ?></div></div>
    </div>
</div>

<!-- Edit Profile -->
<div class="card">
    <div class="card-header"><i class="fas fa-pen-to-square"></i><h3>Edit Profile</h3><span class="sub">Update your contact info and photo</span></div>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_profile">
        <input type="file" id="photoInput" name="photo" accept="image/jpeg,image/png,image/webp" onchange="previewPhoto(this)">
        <div class="photo-upload-zone" onclick="document.getElementById('photoInput').click()">
            <img id="photoPreview" src="#" alt="Preview">
            <i class="fas fa-camera upload-icon"></i>
            <p>Click to upload profile photo</p>
            <small>JPG, PNG or WEBP · Max 2MB</small>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required placeholder="your@email.com">
                <div class="field-note">Used for system notifications</div>
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="e.g. 08012345678">
            </div>
        </div>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
        </div>
    </form>
</div>

<!-- Change Password -->
<div class="card danger-zone">
    <div class="card-header"><i class="fas fa-lock"></i><h3>Change Password</h3><span class="sub">Minimum 8 characters</span></div>
    <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="form-grid">
            <div class="form-group" style="grid-column:1/-1">
                <label>Current Password</label>
                <input type="password" name="current_password" required placeholder="Enter current password">
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" required placeholder="Min. 8 characters" minlength="8" oninput="checkPw(this.value)">
                <div class="pw-track"><div class="pw-fill" id="pwFill"></div></div>
                <div class="field-note" id="pwHint">Use uppercase, numbers &amp; symbols</div>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required placeholder="Repeat new password">
            </div>
        </div>
        <div class="btn-row">
            <button type="submit" class="btn btn-danger"><i class="fas fa-key"></i> Update Password</button>
            <button type="reset" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</button>
        </div>
    </form>
</div>

</div><!-- /content -->
</main>
</div><!-- /layout -->

<script>
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const preview = document.getElementById('photoPreview');
            const icon    = document.querySelector('.upload-icon');
            const hero    = document.getElementById('heroAvatar');
            const sidebar = document.getElementById('sidebar-avatar');
            preview.src = e.target.result;
            preview.style.display = 'block';
            if (icon) icon.style.display = 'none';
            if (hero) hero.src = e.target.result;
            if (sidebar) sidebar.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
function checkPw(v) {
    const fill = document.getElementById('pwFill');
    const hint = document.getElementById('pwHint');
    let s = 0;
    if (v.length >= 8) s++;
    if (/[A-Z]/.test(v)) s++;
    if (/[0-9]/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    const colors = ['#ef4444','#f97316','#f59e0b','#10b981'];
    const labels = ['Too short','Fair','Good','Strong'];
    if (s > 0) {
        fill.style.width = (s*25)+'%';
        fill.style.background = colors[s-1];
        hint.textContent = labels[s-1];
        hint.style.color = colors[s-1];
    } else {
        fill.style.width = '0';
        hint.textContent = 'Use uppercase, numbers & symbols';
        hint.style.color = '#94a3b8';
    }
}
</script>
</body>
</html>
