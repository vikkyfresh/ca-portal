<?php
session_start();
if (isset($_SESSION['admin_id'])) { header('Location: dashboard.php'); exit; }
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal - CS Dept CA Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(to bottom right, #0f172a, #1e3a8a, #0f172a); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .card { background: white; border-radius: 24px; padding: 36px 32px; max-width: 420px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .icon { width: 64px; height: 64px; background: linear-gradient(to right, #0f172a, #1e3a8a); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; color: white; font-size: 1.8rem; }
        h1 { text-align: center; color: #0f172a; margin-bottom: 4px; font-size: 1.5rem; }
        p { text-align: center; color: #64748b; margin-bottom: 24px; font-size: 0.9rem; }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 6px; color: #475569; font-weight: 500; }
        .form-group input { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 1rem; }
        .form-group input:focus { outline: none; border-color: #1e3a8a; }
        .btn { width: 100%; padding: 14px; background: linear-gradient(to right, #0f172a, #1e3a8a); color: white; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(30,58,138,0.4); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .links { text-align: center; margin-top: 20px; font-size: 0.85rem; color: #94a3b8; }
        .links a { color: #1e3a8a; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🛡️</div>
        <h1>Admin Portal</h1>
        <p>CS Department - System Administration</p>
        
        <?php if ($error === 'invalid'): ?>
        <div class="alert alert-error">Invalid username or password</div>
        <?php endif; ?>
        
        <form id="loginForm">
            <div class="form-group"><label>Username</label><input type="text" name="username" placeholder="Enter username" required autofocus></div>
            <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="Enter password" required></div>
            <button type="submit" class="btn">Access Admin Portal</button>
        </form>
        
        <div class="links"><a href="../lecturer/">Lecturer</a> | <a href="../index.php">Student</a></div>
    </div>
    <script>
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = this.querySelector('.btn');
            btn.textContent = 'Authenticating...'; btn.disabled = true;
            const fd = new FormData(this);
            try {
                const resp = await fetch('api/auth.php', { method: 'POST', body: fd });
                const data = await resp.json();
                if (data.success) window.location.href = 'dashboard.php';
                else window.location.href = 'index.php?error=invalid';
            } catch(err) { window.location.href = 'index.php?error=invalid'; }
        });
    </script>
</body>
</html>