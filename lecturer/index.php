<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
if (isset($_SESSION['lecturer_id'])) {
    header('Location: dashboard.php');
    exit;
}
$error = $_GET['error'] ?? '';

$accessBlock = getAccessBlock('lecturer');
if ($accessBlock) {
    renderAccessBlockPage($accessBlock, 'lecturer', '../index.php', '../api/portal-status.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Portal - CS Dept CA Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(to bottom right, #0f172a, #1e3a8a, #0f172a);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .login-card {
            background: white;
            border-radius: 24px;
            padding: 36px 32px;
            max-width: 420px;
            width: 100%;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }
        .login-icon {
            width: 64px; height: 64px;
            background: linear-gradient(to right, #0f172a, #1e3a8a);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            color: white;
            font-size: 1.8rem;
        }
        h1 { text-align: center; color: #0f172a; margin-bottom: 4px; font-size: 1.5rem; }
        p { text-align: center; color: #64748b; margin-bottom: 24px; font-size: 0.9rem; }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 6px; color: #475569; font-weight: 500; font-size: 0.9rem; }
        .form-group input { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 1rem; }
        .form-group input:focus { outline: none; border-color: #1e3a8a; }
        .password-wrapper { position: relative; }
        .password-wrapper input { padding-right: 45px; }
        .toggle-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #64748b; cursor: pointer; }
        .login-btn { width: 100%; padding: 14px; background: linear-gradient(to right, #0f172a, #1e3a8a); color: white; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        .login-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(30,58,138,0.4); }
        .forgot-link { text-align: center; margin-top: 16px; }
        .forgot-link a { color: #1e3a8a; text-decoration: none; font-size: 0.9rem; }
        .footer-links { text-align: center; margin-top: 20px; font-size: 0.85rem; color: #94a3b8; }
        .footer-links a { color: #1e3a8a; text-decoration: none; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-icon">👨‍🏫</div>
        <h1>Lecturer Portal</h1>
        <p>CS Department - Continuous Assessment</p>
        
        <?php if ($error === 'portal_closed'): ?>
        <div class="alert alert-error"><i class="fas fa-lock" style="margin-right:6px"></i><strong>Portal Closed</strong> — The portal is currently closed. Please check back later.</div>
        <?php endif; ?>
        <?php if ($error === 'blocked'): ?>
        <div class="alert alert-error"><i class="fas fa-ban" style="margin-right:6px"></i><strong>Access Restricted</strong> — Lecturer access is currently restricted by the administrator. Please try again later.</div>
        <?php endif; ?>
        <?php if ($error === 'invalid'): ?>
        <div class="alert alert-error">Invalid staff ID or password</div>
        <?php endif; ?>
        
        <?php if ($error === 'session_expired'): ?>
        <div class="alert alert-error">Session expired. Please login again.</div>
        <?php endif; ?>
        
        <?php if (isset($_GET['message']) && $_GET['message'] === 'password_changed'): ?>
        <div class="alert alert-success">Password changed successfully! Please login.</div>
        <?php endif; ?>
        
        <form id="loginForm">
            <div class="form-group">
                <label>Staff ID</label>
                <input type="text" name="staff_id" placeholder="Enter your staff ID" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="password" placeholder="Enter your password" required>
                    <button type="button" class="toggle-btn" onclick="togglePassword()">👁️</button>
                </div>
            </div>
            <button type="submit" class="login-btn">Access Portal</button>
        </form>
        
        <div class="forgot-link">
            <a href="forgot-password.php">Forgot Password?</a>
        </div>
        
        <div class="footer-links">
            <a href="../admin/">Admin</a> | <a href="../index.php">Student</a>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const p = document.getElementById('password');
            p.type = p.type === 'password' ? 'text' : 'password';
        }
        
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = e.target.querySelector('.login-btn');
            btn.textContent = 'Authenticating...';
            btn.disabled = true;
            
            const formData = new FormData(e.target);
            
            try {
                const resp = await fetch('api/auth.php', { method: 'POST', body: formData });
                const data = await resp.json();
                
                if (data.success) {
                    if (data.force_password_change) {
                        window.location.href = 'change-password.php?force=true';
                    } else {
                        window.location.href = 'dashboard.php';
                    }
                } else {
                    if (data.error === 'blocked') {
                        window.location.href = 'index.php?error=blocked';
                    } else {
                        window.location.href = 'index.php?error=invalid';
                    }
                }
            } catch (err) {
                window.location.href = 'index.php?error=invalid';
            }
        });

        // Live portal-control check — if lecturer access closes while this page is
        // open, reload so the maintenance / exam-mode screen shows immediately.
        setInterval(function() {
            fetch('../api/portal-status.php?role=lecturer')
                .then(function(r) { return r.json(); })
                .then(function(d) { if (d.blocked) window.location.reload(); })
                .catch(function() {});
        }, 5000);
    </script>
</body>
</html>