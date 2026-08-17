<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Timezone: set to Nigeria (WAT = UTC+1) ────────────────────────────────
date_default_timezone_set('Africa/Lagos');

// ── Concurrent-login lock timeout ──────────────────────────────────────────
// A student's session_token is written on successful face verification and is
// meant to be cleared on logout / test submission. If a session is abandoned
// (browser closed, crash, network drop) before either of those happens, the
// lock would otherwise persist forever and permanently block the student's
// next login. This is a safety-net expiry: any lock older than this is
// treated as stale and auto-released. Generous on purpose — comfortably
// longer than the longest allowed test duration (180 min, see create-test.php)
// plus buffer for face-verification time and slow connections.
define('SESSION_LOCK_TIMEOUT_MINUTES', 240);

$host = 'localhost';
$dbname = 'ca_portal';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Sync MySQL session timezone to match PHP
    $pdo->exec("SET time_zone = '+01:00'");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ---------------------------------------------------------------
// AUDIT LOG — system-wide activity trail for admin/audit-logs.php
// ---------------------------------------------------------------
// Call this from anywhere a meaningful event happens (logins, logouts, test
// creation/completion, face enrollment, retake approvals, portal-control
// changes, etc). Never throws — a logging failure must never break the
// actual feature that triggered it.
//
//   logAudit('student_login', 'student', $matric, $fullName, "…description…");
//
function logAudit($eventType, $actorType, $actorId, $actorName, $description, $metadata = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (event_type, actor_type, actor_id, actor_name, description, ip_address, metadata) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            $eventType,
            $actorType,
            $actorId !== null ? (string)$actorId : null,
            $actorName,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $metadata !== null ? json_encode($metadata) : null
        ]);
    } catch (Exception $e) {
        // audit_logs table missing (not yet migrated) or any other DB hiccup —
        // silently ignore so the calling feature still completes normally.
    }
}

// Fetch current academic settings
function getAcademicSetting($key, $default = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        return $result ?: $default;
    } catch (Exception $e) {
        return $default;
    }
}

// Define current session variables for use across the site
$CURRENT_SESSION = getAcademicSetting('academic_session', '2025/2026');
$CURRENT_SEMESTER = getAcademicSetting('current_semester', '1st Semester');

// ---------------------------------------------------------------
// PORTAL ACCESS CONTROL (maintenance / role-block / exam mode)
// ---------------------------------------------------------------
// Returns null if access is allowed, or an array describing the block:
//   ['variant' => 'maintenance'|'exam_mode'|'blocked', 'title' => ..., 'message' => ...]
function getAccessBlock($role) {
    global $pdo;
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('portal_open','students_blocked','lecturers_blocked','testing_open','portal_closed_message')");
    $s = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $portalOpen   = (trim($s['portal_open'] ?? '1')) === '1';
    $studentsBlk  = (trim($s['students_blocked'] ?? '0')) === '1';
    $lecturersBlk = (trim($s['lecturers_blocked'] ?? '0')) === '1';
    $testingOpen  = (trim($s['testing_open'] ?? '1')) === '1';
    $portalMsg    = $s['portal_closed_message'] ?? 'The site is currently under maintenance. Please check back shortly.';

    if (!$portalOpen) {
        return ['variant' => 'maintenance', 'title' => 'Site Under Maintenance', 'message' => $portalMsg];
    }
    if ($role === 'student' && $studentsBlk) {
        return ['variant' => 'blocked', 'title' => 'Student Access Restricted', 'message' => 'Student access is currently restricted by the administrator. Please check back later.'];
    }
    if ($role === 'lecturer' && $lecturersBlk) {
        // The exam_mode preset blocks lecturers while keeping students + testing open
        if ($testingOpen && !$studentsBlk) {
            return ['variant' => 'exam_mode', 'title' => 'Exam Mode Active', 'message' => 'Lecturer access is paused while a live testing window is in progress. You will be able to log in again once the exam session ends.'];
        }
        return ['variant' => 'blocked', 'title' => 'Lecturer Access Restricted', 'message' => 'Lecturer access is currently restricted by the administrator. Please check back later.'];
    }
    return null;
}

// Renders a bold, full-screen access-block page and stops execution.
// $backLink lets each entry point point "Back" to the right place (default: index.php)
function renderAccessBlockPage(array $block, string $role, string $backLink = 'index.php', string $statusApiPath = 'api/portal-status.php') {
    $variant = $block['variant'];
    $title   = htmlspecialchars($block['title'], ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars($block['message'], ENT_QUOTES, 'UTF-8');

    $themes = [
        'maintenance' => ['grad' => 'linear-gradient(135deg,#7f1d1d,#dc2626)', 'icon' => 'fa-tools',         'chip' => '#fecaca', 'chipText' => '#7f1d1d', 'label' => 'MAINTENANCE'],
        'blocked'     => ['grad' => 'linear-gradient(135deg,#7c2d12,#ea580c)', 'icon' => 'fa-ban',            'chip' => '#fed7aa', 'chipText' => '#7c2d12', 'label' => 'ACCESS RESTRICTED'],
        'exam_mode'   => ['grad' => 'linear-gradient(135deg,#312e81,#4f46e5)', 'icon' => 'fa-file-signature', 'chip' => '#c7d2fe', 'chipText' => '#312e81', 'label' => 'EXAM MODE'],
    ];
    $theme = $themes[$variant] ?? ['grad' => 'linear-gradient(135deg,#0f172a,#1e3a8a)', 'icon' => 'fa-lock', 'chip' => '#e2e8f0', 'chipText' => '#0f172a', 'label' => 'NOTICE'];

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . $title . ' — CS Dept CA Portal</title>'
       . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">'
       . '<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:' . $theme['grad'] . ';min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{background:white;border-radius:24px;padding:48px 40px;max-width:480px;width:100%;text-align:center;box-shadow:0 25px 60px rgba(0,0,0,.45);animation:pop .25s ease}
@keyframes pop{from{transform:scale(.96);opacity:0}to{transform:scale(1);opacity:1}}
.chip{display:inline-block;padding:6px 14px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.08em;background:' . $theme['chip'] . ';color:' . $theme['chipText'] . ';margin-bottom:18px}
.icon{width:76px;height:76px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:34px;color:white;background:' . $theme['grad'] . '}
h1{font-size:1.5rem;font-weight:800;color:#0f172a;margin-bottom:12px}
p{color:#475569;font-size:15px;line-height:1.7;margin-bottom:26px}
a.back{display:inline-block;padding:12px 26px;background:' . $theme['grad'] . ';color:white;border-radius:12px;text-decoration:none;font-weight:700;font-size:14px}
.pulse{display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;margin-right:6px;animation:blink 1.4s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.25}}
.live{font-size:11px;color:#94a3b8;margin-top:18px}
</style></head><body>'
       . '<div class="box">'
       . '<div class="chip">' . $theme['label'] . '</div>'
       . '<div class="icon"><i class="fas ' . $theme['icon'] . '"></i></div>'
       . '<h1>' . $title . '</h1>'
       . '<p>' . $message . '</p>'
       . '<a class="back" href="' . htmlspecialchars($backLink, ENT_QUOTES, "UTF-8") . '"><i class="fas fa-arrow-left"></i>&nbsp; Back to Home</a>'
       . '<div class="live"><span class="pulse"></span>This page checks live — it will unlock automatically once access is restored.</div>'
       . '</div>'
       . '<script>
(function(){
  setInterval(function(){
    fetch("' . $statusApiPath . '?role=' . $role . '").then(function(r){return r.json();}).then(function(d){
      if (!d.blocked) window.location.reload();
    }).catch(function(){});
  }, 4000);
})();
</script>'
       . '</body></html>';
    exit;
}

// ---------------------------------------------------------------
// PARTIAL EXAM MODE: per-lecturer view-only restriction
// ---------------------------------------------------------------
// Admin can select specific lecturers (by admins.id) to restrict to view-only
// during an exam window, without blocking every lecturer via lecturers_blocked.
// Stored as a JSON array of admin.id values under system_settings.restricted_lecturers.
const LECTURER_RESTRICTED_MESSAGE = 'Your account is in view-only mode during the current exam session. Editing is temporarily disabled by the administrator until the exam window ends.';

function isLecturerRestricted($lecturerId) {
    global $pdo;
    $lecturerId = (int)$lecturerId;
    if (!$lecturerId) return false;
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'restricted_lecturers'");
    $stmt->execute();
    $raw = $stmt->fetchColumn();
    $ids = $raw ? json_decode($raw, true) : [];
    if (!is_array($ids)) return false;
    return in_array($lecturerId, array_map('intval', $ids), true);
}

// For JSON/AJAX write endpoints: stop and respond 403 if this lecturer is restricted.
function guardLecturerWriteJson() {
    $lecturerId = (int)($_SESSION['lecturer_id'] ?? 0);
    if (isLecturerRestricted($lecturerId)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'restricted', 'message' => LECTURER_RESTRICTED_MESSAGE]);
        exit;
    }
}
