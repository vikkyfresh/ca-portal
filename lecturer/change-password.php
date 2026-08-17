<?php
session_start();
if (!isset($_SESSION['lecturer_id'])) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Lecturer Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: white; border-radius: 16px; padding: 32px; max-width: 420px; width: 100%; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h2 { margin-bottom: 20px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; margin-bottom: 4px; color: #475569; font-weight: 500; }
        .form-group input { width: 100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 8px; }
        .btn { width: 100%; padding: 12px; background: #0f172a; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .btn:disabled { opacity: 0.6; }
        .req { font-size: 0.85rem; margin-bottom: 12px; }
        .req li { color: #ef4444; }
        .req li.met { color: #10b981; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Change Password</h2>
        <form id="pwForm">
            <div class="form-group"><label>Current Password</label><input type="password" id="current" required></div>
            <div class="form-group"><label>New Password</label><input type="password" id="newpw" required></div>
            <div class="form-group"><label>Confirm Password</label><input type="password" id="confirm" required></div>
            <ul class="req">
                <li id="r1">8+ characters</li>
                <li id="r2">Uppercase letter</li>
                <li id="r3">Lowercase letter</li>
                <li id="r4">Number</li>
                <li id="r5">Special character</li>
            </ul>
            <button type="submit" class="btn" id="submitBtn" disabled>Change Password</button>
        </form>
    </div>
    <script>
        const np = document.getElementById('newpw');
        const cp = document.getElementById('confirm');
        const btn = document.getElementById('submitBtn');
        
        function check() {
            const p = np.value;
            document.getElementById('r1').className = p.length >= 8 ? 'met' : '';
            document.getElementById('r2').className = /[A-Z]/.test(p) ? 'met' : '';
            document.getElementById('r3').className = /[a-z]/.test(p) ? 'met' : '';
            document.getElementById('r4').className = /[0-9]/.test(p) ? 'met' : '';
            document.getElementById('r5').className = /[!@#$%^&*(),.?":{}|<>]/.test(p) ? 'met' : '';
            btn.disabled = !(p.length >= 8 && /[A-Z]/.test(p) && /[a-z]/.test(p) && /[0-9]/.test(p) && /[!@#$%^&*(),.?":{}|<>]/.test(p) && p === cp.value);
        }
        
        np.addEventListener('input', check);
        cp.addEventListener('input', check);
        
        document.getElementById('pwForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const fd = new FormData();
            fd.append('action', 'change_password');
            fd.append('current_password', document.getElementById('current').value);
            fd.append('new_password', np.value);
            const resp = await fetch('api/password.php', { method: 'POST', body: fd });
            const data = await resp.json();
            alert(data.message || (data.success ? 'Changed!' : 'Failed'));
            if (data.success) window.location.href = 'dashboard.php';
        });
    </script>
</body>
</html>