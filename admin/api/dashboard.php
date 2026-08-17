<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../includes/config.php';

$action = $_GET['action'] ?? 'stats';

// ============================================
// GET DASHBOARD STATISTICS
// ============================================
if ($action === 'stats') {
    try {
        $totalStudents = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $totalLecturers = $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'lecturer'")->fetchColumn();
        $totalTests = $pdo->query("SELECT COUNT(*) FROM tests WHERE is_active = 1 AND is_draft = 0")->fetchColumn();
        $totalSubmissions = $pdo->query("SELECT COUNT(*) FROM attempts WHERE status = 'completed'")->fetchColumn();
        $avgScore = round($pdo->query("SELECT AVG(percentage) FROM attempts WHERE status = 'completed'")->fetchColumn() ?? 0, 1);
        $pendingFaces = $pdo->query("SELECT COUNT(*) FROM students WHERE face_descriptor IS NULL")->fetchColumn();
        $testsThisMonth = $pdo->query("SELECT COUNT(*) FROM tests WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())")->fetchColumn();
        $submissionsToday = $pdo->query("SELECT COUNT(*) FROM attempts WHERE DATE(end_time) = CURDATE() AND status = 'completed'")->fetchColumn();
        $totalCourses = $pdo->query("SELECT COUNT(DISTINCT course_code) FROM lecturer_courses")->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'total_students' => (int)$totalStudents,
                'total_lecturers' => (int)$totalLecturers,
                'total_tests' => (int)$totalTests,
                'total_submissions' => (int)$totalSubmissions,
                'avg_score' => (float)$avgScore,
                'pending_faces' => (int)$pendingFaces,
                'tests_this_month' => (int)$testsThisMonth,
                'submissions_today' => (int)$submissionsToday,
                'total_courses' => (int)$totalCourses
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET RECENT ACTIVITY
// ============================================
if ($action === 'activity') {
    try {
        $limit = intval($_GET['limit'] ?? 10);
        
        $activity = $pdo->query("
            (SELECT 'test_created' as type, test_title as title, created_at, 
                    (SELECT full_name FROM admins WHERE id = created_by) as user_name
             FROM tests ORDER BY created_at DESC LIMIT $limit)
            UNION ALL
            (SELECT 'submission' as type, 
                    CONCAT(s.full_name, ' scored ', a.percentage, '% on ', t.test_title) as title, 
                    a.end_time as created_at, s.full_name as user_name
             FROM attempts a
             JOIN students s ON a.student_matric = s.matric
             JOIN tests t ON a.test_id = t.id
             WHERE a.status = 'completed'
             ORDER BY a.end_time DESC LIMIT $limit)
            ORDER BY created_at DESC LIMIT $limit
        ")->fetchAll();
        
        echo json_encode(['success' => true, 'data' => $activity]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET LEVEL DISTRIBUTION (for charts)
// ============================================
if ($action === 'level_distribution') {
    try {
        $data = $pdo->query("
            SELECT s.level, COUNT(a.id) as count
            FROM students s
            LEFT JOIN attempts a ON s.matric = a.student_matric AND a.status = 'completed'
            GROUP BY s.level ORDER BY s.level
        ")->fetchAll();
        
        $labels = [];
        $values = [];
        foreach ($data as $row) {
            $labels[] = 'Level ' . $row['level'];
            $values[] = (int)$row['count'];
        }
        
        echo json_encode(['success' => true, 'labels' => $labels, 'values' => $values]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET SYSTEM HEALTH
// ============================================
if ($action === 'health') {
    try {
        $dbSize = $pdo->query("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) 
            FROM information_schema.tables WHERE table_schema = 'ca_portal'
        ")->fetchColumn() ?? 0;
        
        $tableCount = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'ca_portal'")->fetchColumn() ?? 0;
        $maintenanceMode = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn() ?? '0';
        
        echo json_encode([
            'success' => true,
            'data' => [
                'db_size_mb' => (float)$dbSize,
                'table_count' => (int)$tableCount,
                'maintenance_mode' => $maintenanceMode === '1',
                'server_time' => date('Y-m-d H:i:s')
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action. Use: stats, activity, level_distribution, health']);
?>