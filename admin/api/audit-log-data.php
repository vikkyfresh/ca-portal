<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
require_once '../../includes/config.php';

$start      = trim($_GET['start'] ?? '');
$end        = trim($_GET['end'] ?? '');
$eventType  = trim($_GET['event_type'] ?? '');
$actorType  = trim($_GET['actor_type'] ?? '');
$search     = trim($_GET['search'] ?? '');
$page       = max(1, intval($_GET['page'] ?? 1));
$perPage    = min(200, max(10, intval($_GET['per_page'] ?? 25)));

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
    // Total count for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare("SELECT id, event_type, actor_type, actor_id, actor_name, description, ip_address, created_at
                            FROM audit_logs $whereSql
                            ORDER BY created_at DESC
                            LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Quick counts per event_type within the current filter (excluding the event_type
    // filter itself) so the dropdown / summary chips can show live counts if needed.
    echo json_encode([
        'success'  => true,
        'rows'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ]);
} catch (Exception $e) {
    // Most likely cause: audit_logs table not yet migrated (patch-4-audit-logs.sql)
    echo json_encode(['success' => false, 'message' => 'Could not load audit logs. Has patch-4-audit-logs.sql been run? (' . $e->getMessage() . ')']);
}
