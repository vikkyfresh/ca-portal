<?php
/**
 * take-test-link.php  (project root)
 * Custom restricted test link.
 * Flow: token check → matric entry → face verify → test
 */
session_start();
require_once 'includes/config.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$linkRow = null;

// ── 1. Validate token ──────────────────────────────────────────────────────
if (empty($token)) {
    $error = 'no_token';
} else {
    $stmt = $pdo->prepare("
        SELECT cl.*, t.test_title, t.course_code, t.level, t.expiry_date,
               t.duration_minutes, t.total_questions, t.require_face_verify
        FROM custom_test_links cl
        JOIN tests t ON t.id = cl.test_id
        WHERE cl.token = ? LIMIT 1
    ");
    $stmt->execute([$token]);
    $linkRow = $stmt->fetch();

    if (!$linkRow)                                       { $error = 'invalid'; }
    elseif ($linkRow['revoked'])                         { $error = 'revoked'; }
    elseif (strtotime($linkRow['expires_at']) < time())  { $error = 'expired'; }
}

// ── 2. Handle matric verification POST ────────────────────────────────────
$checkMsg = '';
$verified = false;
$student  = null;

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_matric') {
    $postMatric = strtoupper(trim($_POST['matric'] ?? ''));

    if (!$postMatric) {
        $checkMsg = 'Please enter your matric number.';
    } else {
        // Check on allowed list
        $allowedStmt = $pdo->prepare("
            SELECT 1 FROM custom_test_link_students
            WHERE link_id = ? AND matric = ?
        ");
        $allowedStmt->execute([$linkRow['id'], $postMatric]);
        if (!$allowedStmt->fetch()) {
            $checkMsg = 'Your matric number is not on the access list for this test. Contact your lecturer.';
        } else {
            // Check student exists and face enrolled
            $stuStmt = $pdo->prepare("SELECT matric, full_name, level, face_descriptor FROM students WHERE matric = ?");
            $stuStmt->execute([$postMatric]);
            $student = $stuStmt->fetch();

            if (!$student) {
                $checkMsg = 'Student record not found. Contact your lecturer.';
            } elseif ($linkRow['require_face_verify'] && empty($student['face_descriptor'])) {
                // Only block for missing face if this test actually requires face verify
                $checkMsg = 'Your face is not enrolled yet. Please visit the admin office to enroll your face first.';
            } else {
                $_SESSION['custom_link_token']   = $token;
                $_SESSION['custom_link_matric']  = $postMatric;
                $_SESSION['custom_link_test_id'] = $linkRow['test_id'];
                $_SESSION['custom_link_name']    = $student['full_name'];
                $_SESSION['pending_verify_matric'] = $postMatric;

                if (!$linkRow['require_face_verify']) {
                    // Face verify is OFF — set session directly and go straight to test
                    $_SESSION['verified']             = true;
                    $_SESSION['face_verified']        = true;
                    $_SESSION['authenticated_matric'] = $postMatric;
                    $_SESSION['student_matric']       = $postMatric;
                    $_SESSION['student_name']         = $student['full_name'];
                    $_SESSION['student_level']        = $student['level'];
                    $_SESSION['verified_at']          = time();
                    unset($_SESSION['custom_link_token'], $_SESSION['custom_link_matric'],
                          $_SESSION['custom_link_test_id'], $_SESSION['custom_link_name']);
                    header("Location: take-test.php?test_id={$linkRow['test_id']}&matric=" . urlencode($postMatric));
                    exit;
                }

                // Face verify is ON — proceed to face verify step
                $verified = true;
            }
        }
    }
}

// ── 3. If already face-verified via session, go straight to test ──────────
if (!$error) {
    $sessionMatric = $_SESSION['custom_link_matric'] ?? '';
    $sessionToken  = $_SESSION['custom_link_token']  ?? '';
    $faceVerified  = $_SESSION['face_verified']       ?? false;
    $authMatric    = strtoupper(trim($_SESSION['authenticated_matric'] ?? ''));
    $faceRequired  = (bool)($linkRow['require_face_verify'] ?? 1);

    // Pass through if session matches AND (face verified OR face not required)
    if ($sessionToken === $token && $sessionMatric && $authMatric === $sessionMatric
        && ($faceVerified || !$faceRequired)) {
        unset($_SESSION['custom_link_token'], $_SESSION['custom_link_matric'],
              $_SESSION['custom_link_test_id'], $_SESSION['custom_link_name']);
        header("Location: take-test.php?test_id={$linkRow['test_id']}&matric=" . urlencode($sessionMatric));
        exit;
    }
}

$errorMessages = [
    'no_token' => 'No test link provided. Use the exact link sent to you.',
    'invalid'  => 'This test link is invalid. Check the link and try again.',
    'revoked'  => 'This link has been revoked by your lecturer.',
    'expired'  => 'This link has expired. Contact your lecturer for a new one.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Test</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .wrap{background:#fff;border-radius:20px;padding:36px 32px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
        .logo{text-align:center;margin-bottom:24px}
        .logo-icon{width:64px;height:64px;background:linear-gradient(135deg,#0f172a,#1e3a8a);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:1.7rem;color:#fff;margin-bottom:12px}
        .logo h2{font-size:1.2rem;font-weight:800;color:#0f172a}
        .logo p{font-size:.82rem;color:#64748b;margin-top:4px}

        /* Steps */
        .steps{display:flex;justify-content:center;margin-bottom:26px}
        .step{display:flex;flex-direction:column;align-items:center;flex:1;position:relative}
        .step:not(:last-child)::after{content:'';position:absolute;top:14px;left:calc(50% + 14px);right:calc(-50% + 14px);height:2px;background:#e2e8f0;z-index:0}
        .step-dot{width:28px;height:28px;border-radius:50%;background:#e2e8f0;color:#94a3b8;font-size:.78rem;font-weight:700;display:flex;align-items:center;justify-content:center;position:relative;z-index:1;transition:all .3s}
        .step.active .step-dot{background:#1e3a8a;color:#fff}
        .step.done .step-dot{background:#10b981;color:#fff}
        .step-label{font-size:.68rem;color:#94a3b8;margin-top:5px;font-weight:600;text-align:center}
        .step.active .step-label{color:#1e3a8a}
        .step.done .step-label{color:#10b981}

        .test-info{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px 18px;margin-bottom:20px}
        .test-info p{font-size:.76rem;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px}
        .test-info h3{font-size:.98rem;font-weight:700;color:#1e3a8a}
        .test-info span{font-size:.78rem;color:#64748b}

        .alert{padding:13px 16px;border-radius:10px;font-size:.86rem;font-weight:500;display:flex;align-items:flex-start;gap:9px;margin-bottom:18px;line-height:1.5}
        .alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        .alert-warn{background:#fefce8;color:#854d0e;border:1px solid #fde68a}
        .alert-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
        .alert i{flex-shrink:0;margin-top:1px}

        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:.83rem;font-weight:600;color:#475569;margin-bottom:6px}
        .form-group input{width:100%;padding:12px 16px;border:2px solid #e2e8f0;border-radius:10px;font-size:1rem;font-family:inherit;outline:none;transition:border .2s;text-transform:uppercase;letter-spacing:.05em}
        .form-group input:focus{border-color:#1e3a8a}
        .hint{font-size:.76rem;color:#94a3b8;margin-top:4px}

        .btn{width:100%;padding:13px;border:none;border-radius:12px;font-size:.94rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;margin-bottom:10px;text-decoration:none}
        .btn:last-child{margin-bottom:0}
        .btn-primary{background:linear-gradient(to right,#0f172a,#1e3a8a);color:#fff}
        .btn-primary:hover{opacity:.9;transform:translateY(-1px)}
        .btn-secondary{background:#f1f5f9;color:#475569}
        .btn-secondary:hover{background:#e2e8f0}

        .success-screen{text-align:center;padding:8px 0}
        .success-icon{width:72px;height:72px;background:#dcfce7;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:2.2rem;margin-bottom:16px}
        .success-screen h3{font-size:1.15rem;font-weight:800;color:#0f172a;margin-bottom:8px}
        .success-screen p{color:#64748b;font-size:.88rem;line-height:1.6}

        .error-icon{width:68px;height:68px;background:#fee2e2;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:1.9rem;margin:0 auto 16px;display:block;text-align:center;line-height:68px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="logo">
        <div class="logo-icon"><i class="fas fa-file-alt"></i></div>
        <h2>Restricted Test Access</h2>
        <p>Verify your identity to access this test</p>
    </div>

    <?php if ($error): ?>
        <!-- ── Error ── -->
        <div style="text-align:center;margin-bottom:18px">
            <div class="error-icon">🚫</div>
        </div>
        <div class="alert alert-error">
            <i class="fas fa-times-circle"></i>
            <div><?= htmlspecialchars($errorMessages[$error] ?? 'An error occurred.') ?></div>
        </div>
        <a href="student-login.php" class="btn btn-secondary">
            <i class="fas fa-home"></i> Back to Login
        </a>

    <?php elseif ($verified): ?>
        <!-- ── Step 2: Redirect to face verify ── -->
        <?php
            // Store data needed after face verify
            $_SESSION['custom_link_token']   = $token;
            $_SESSION['custom_link_test_id'] = $linkRow['test_id'];
        ?>
        <div class="steps">
            <div class="step done"><div class="step-dot"><i class="fas fa-check" style="font-size:.6rem"></i></div><div class="step-label">Verify</div></div>
            <div class="step active"><div class="step-dot">2</div><div class="step-label">Face ID</div></div>
            <div class="step"><div class="step-dot">3</div><div class="step-label">Test</div></div>
        </div>
        <div class="alert alert-info">
            <i class="fas fa-camera"></i>
            <div>Matric verified! You will now complete face verification before your test starts.</div>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
            <div style="width:40px;height:40px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0">👤</div>
            <div>
                <div style="font-weight:700;color:#166534;font-size:.92rem"><?= htmlspecialchars($student['full_name']) ?></div>
                <div style="font-size:.76rem;color:#4ade80"><?= htmlspecialchars($student['matric']) ?></div>
            </div>
        </div>
        <!-- Auto-redirect to face-verify with return URL pointing back here after success -->
        <script>
            window.location.href = 'face-verify.php?matric=<?= urlencode($student['matric']) ?>&redirect=custom_test&token=<?= urlencode($token) ?>';
        </script>
        <noscript>
            <a href="face-verify.php?matric=<?= urlencode($student['matric']) ?>&redirect=custom_test&token=<?= urlencode($token) ?>" class="btn btn-primary">
                <i class="fas fa-camera"></i> Continue to Face Verification
            </a>
        </noscript>

    <?php else: ?>
        <!-- ── Step 1: Matric entry ── -->
        <div class="steps">
            <div class="step active"><div class="step-dot">1</div><div class="step-label">Verify</div></div>
            <div class="step"><div class="step-dot">2</div><div class="step-label">Face ID</div></div>
            <div class="step"><div class="step-dot">3</div><div class="step-label">Test</div></div>
        </div>

        <div class="test-info">
            <p>You are accessing</p>
            <h3><?= htmlspecialchars($linkRow['test_title']) ?></h3>
            <span><?= htmlspecialchars($linkRow['course_code']) ?> &nbsp;·&nbsp; <?= $linkRow['level'] ?>L
                &nbsp;·&nbsp; <?= $linkRow['total_questions'] ?> questions &nbsp;·&nbsp; <?= $linkRow['duration_minutes'] ?> mins
            </span>
        </div>

        <?php if ($checkMsg): ?>
        <div class="alert alert-error">
            <i class="fas fa-times-circle"></i>
            <div><?= htmlspecialchars($checkMsg) ?></div>
        </div>
        <?php else: ?>
        <div class="alert alert-warn">
            <i class="fas fa-info-circle"></i>
            <div>This is a restricted test. Enter your matric number to verify you are on the access list.</div>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="verify_matric">
            <div class="form-group">
                <label>Your Matric Number</label>
                <input type="text" name="matric" placeholder="e.g. 22CS0001" maxlength="10"
                       oninput="this.value=this.value.toUpperCase()"
                       value="<?= htmlspecialchars($_POST['matric'] ?? '') ?>"
                       autofocus required>
                <div class="hint">Format: YYCSXXXX (e.g. 22CS0001)</div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-arrow-right"></i> Verify & Continue
            </button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
