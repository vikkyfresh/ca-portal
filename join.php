<?php
/**
 * Join Test via Access Link
 * Checks link expiry, test window, and test status
 */
$code = $_GET['code'] ?? '';

if (empty($code)) {
    header('Location: student-login.php');
    exit;
}

require_once 'includes/config.php';

// Find the test by access code
$stmt = $pdo->prepare("SELECT id, course_code, course_title, test_title, level, is_active, 
                       link_expires_at, link_used, start_date, expiry_date,
                       max_attempts,
                       (SELECT COUNT(*) FROM attempts WHERE test_id = tests.id AND status = 'completed') as submission_count
                       FROM tests WHERE access_code = ? LIMIT 1");
$stmt->execute([$code]);
$test = $stmt->fetch();

if (!$test) {
    die("
    <!DOCTYPE html>
    <html><head><title>Invalid Link</title>
    <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f1f5f9;margin:0;}
    .card{background:white;padding:40px;border-radius:16px;text-align:center;box-shadow:0 4px 6px rgba(0,0,0,.1);max-width:500px;}
    h1{color:#ef4444;}p{color:#64748b;}.btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#0f172a;color:white;text-decoration:none;border-radius:8px;}</style></head>
    <body><div class='card'><h1>❌ Invalid Link</h1><p>This test link is not valid. Please check the link and contact your lecturer.</p><a href='index.php' class='btn'>Go to Home</a></div></body></html>");
}

// Check if test is active
if (!$test['is_active']) {
    die("
    <!DOCTYPE html>
    <html><head><title>Test Inactive</title>
    <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f1f5f9;margin:0;}
    .card{background:white;padding:40px;border-radius:16px;text-align:center;box-shadow:0 4px 6px rgba(0,0,0,.1);max-width:500px;}
    h1{color:#ef4444;}p{color:#64748b;}.btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#0f172a;color:white;text-decoration:none;border-radius:8px;}</style></head>
    <body><div class='card'><h1>❌ Test Inactive</h1><p>This test has been deactivated. Please contact your lecturer.</p><a href='index.php' class='btn'>Go to Home</a></div></body></html>");
}

// Check if link has expired (10 hours from creation)
if (!$test['link_used'] && !empty($test['link_expires_at']) && strtotime($test['link_expires_at']) < time()) {
    die("
    <!DOCTYPE html>
    <html><head><title>Link Expired</title>
    <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f1f5f9;margin:0;}
    .card{background:white;padding:40px;border-radius:16px;text-align:center;box-shadow:0 4px 6px rgba(0,0,0,.1);max-width:500px;}
    h1{color:#f59e0b;}p{color:#64748b;}.btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#0f172a;color:white;text-decoration:none;border-radius:8px;}
    .expired{color:#ef4444;font-weight:bold;}</style></head>
    <body><div class='card'><h1>⏰ Link Expired</h1><p>This test link has expired.</p><p>Please contact your lecturer for a new link.</p><a href='index.php' class='btn'>Go to Home</a></div></body></html>");
}

// Check if test window has expired
if (!empty($test['expiry_date']) && strtotime($test['expiry_date']) < time()) {
    die("
    <!DOCTYPE html>
    <html><head><title>Test Window Closed</title>
    <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f1f5f9;margin:0;}
    .card{background:white;padding:40px;border-radius:16px;text-align:center;box-shadow:0 4px 6px rgba(0,0,0,.1);max-width:500px;}
    h1{color:#ef4444;}p{color:#64748b;}.btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#0f172a;color:white;text-decoration:none;border-radius:8px;}</style></head>
    <body><div class='card'><h1>❌ Test Window Closed</h1><p>The scheduled end date for this test has passed.</p><p>Scheduled expiry: <strong>" . date('M d, Y - h:i A', strtotime($test['expiry_date'])) . "</strong></p><a href='index.php' class='btn'>Go to Home</a></div></body></html>");
}

// Check if test hasn't started yet
if (!empty($test['start_date']) && strtotime($test['start_date']) > time()) {
    die("
    <!DOCTYPE html>
    <html><head><title>Test Not Started</title>
    <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f1f5f9;margin:0;}
    .card{background:white;padding:40px;border-radius:16px;text-align:center;box-shadow:0 4px 6px rgba(0,0,0,.1);max-width:500px;}
    h1{color:#3b82f6;}p{color:#64748b;}.btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#0f172a;color:white;text-decoration:none;border-radius:8px;}</style></head>
    <body><div class='card'><h1>⏳ Test Not Yet Started</h1><p>This test is scheduled to start at:</p><p><strong>" . date('M d, Y - h:i A', strtotime($test['start_date'])) . "</strong></p><p>Please come back at the scheduled time.</p><a href='index.php' class='btn'>Go to Home</a></div></body></html>");
}

// Mark link as used
if (!$test['link_used']) {
    $pdo->prepare("UPDATE tests SET link_used = 1 WHERE id = ?")->execute([$test['id']]);
}

// Redirect to the main page with the code
header('Location: student-login.php?code=' . urlencode($code));
exit;
?>