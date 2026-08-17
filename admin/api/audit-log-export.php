<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
require_once '../../includes/config.php';

$start     = trim($_GET['start'] ?? '');
$end       = trim($_GET['end'] ?? '');
$eventType = trim($_GET['event_type'] ?? '');
$actorType = trim($_GET['actor_type'] ?? '');
$search    = trim($_GET['search'] ?? '');
$format    = $_GET['format'] ?? 'csv';

$where  = [];
$params = [];
if ($start !== '') { $where[] = 'created_at >= ?'; $params[] = $start . ' 00:00:00'; }
if ($end   !== '') { $where[] = 'created_at <= ?'; $params[] = $end   . ' 23:59:59'; }
if ($eventType !== '' && $eventType !== 'all') { $where[] = 'event_type = ?'; $params[] = $eventType; }
if ($actorType !== '' && $actorType !== 'all') { $where[] = 'actor_type = ?'; $params[] = $actorType; }
if ($search !== '') {
    $where[] = '(description LIKE ? OR actor_name LIKE ? OR actor_id LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    // No LIMIT here on purpose — an export must include every matching row in
    // the chosen range, not just the current page of the on-screen table.
    $stmt = $pdo->prepare("SELECT event_type, actor_type, actor_id, actor_name, description, ip_address, created_at
                            FROM audit_logs $whereSql
                            ORDER BY created_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()]);
    exit;
}

$rangeLabel = ($start ?: 'earliest') . '_to_' . ($end ?: 'latest');

if ($format === 'json') {
    // Consumed by the frontend to build the printable table for PDF export
    // (see the html2pdf.js flow already used in result.php).
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'rows' => $rows, 'count' => count($rows), 'range' => $rangeLabel]);
    exit;
}

// ── CSV (opens directly in Excel) ───────────────────────────────────────
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="audit-log-' . $rangeLabel . '.csv"');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders special characters correctly
fputcsv($out, ['Date/Time', 'Event Type', 'Actor Type', 'Actor ID', 'Actor Name', 'Description', 'IP Address']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['created_at'],
        $r['event_type'],
        $r['actor_type'],
        $r['actor_id'],
        $r['actor_name'],
        $r['description'],
        $r['ip_address'],
    ]);
}
fclose($out);
