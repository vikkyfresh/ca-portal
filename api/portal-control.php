<?php
require_once '../includes/config.php'; // config.php now has session_start()

header('Content-Type: application/json');

// Check admin authentication - use your session variable
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Please login first']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!$action) {
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit;
}

switch($action) {
    case 'get_settings':
        $settings = [];
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        echo json_encode(['success' => true, 'settings' => $settings]);
        break;
        
    case 'set_block_mode':
        $mode = $_POST['mode'] ?? 'full_block';
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'block_mode'");
        $stmt->execute([$mode]);
        echo json_encode(['success' => true, 'mode' => $mode]);
        break;
        
    case 'toggle_maintenance':
        $mode = $_POST['mode'] ?? '0';
        $message = $_POST['message'] ?? '';
        $start = $_POST['start_time'] ?: null;
        $end = $_POST['end_time'] ?: null;
        $target_roles = $_POST['target_roles'] ?? '[]';
        
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'maintenance_mode'")->execute([$mode]);
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'maintenance_message'")->execute([$message]);
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'maintenance_start'")->execute([$start]);
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'maintenance_end'")->execute([$end]);
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'maintenance_target_roles'")->execute([$target_roles]);

        logAudit('portal_setting_changed', 'admin', $_SESSION['admin_id'] ?? null, $_SESSION['admin_name'] ?? null,
            ($_SESSION['admin_name'] ?? 'An admin') . ' ' . ($mode === '1' ? 'enabled' : 'disabled') . ' maintenance mode.');

        echo json_encode(['success' => true]);
        break;
        
    case 'open_edit_window':
        $target = $_POST['target'] ?? 'all';
        $start = $_POST['start_date'] ?? date('Y-m-d H:i:s');
        $end = $_POST['end_date'] ?? date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $pdo->prepare("UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'profile_edit_window_open'")->execute();
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'profile_edit_window_start'")->execute([$start]);
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'profile_edit_window_end'")->execute([$end]);
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'profile_edit_target'")->execute([$target]);
        
        echo json_encode(['success' => true]);
        break;
        
    case 'close_edit_window':
        $pdo->prepare("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'profile_edit_window_open'")->execute();
        echo json_encode(['success' => true]);
        break;
        
    case 'set_setting':
        $key   = trim($_POST['key'] ?? '');
        $value = $_POST['value'] ?? '';
        $allowed = [
            'portal_open','students_blocked','lecturers_blocked',
            'testing_open','testing_closed_message',
            'portal_closed_message','announcement_active','announcement_text',
            'block_mode','maintenance_mode','maintenance_message',
            'profile_edit_window_open','profile_edit_window_end',
            'announcement_text','maintenance_start','maintenance_end',
            'maintenance_target_roles','profile_edit_window_start',
            'profile_edit_target','block_mode',
            'restricted_lecturers',
        ];
        if (!in_array($key, $allowed, true)) {
            echo json_encode(['success'=>false,'message'=>'Invalid setting key: '.$key]);
            exit;
        }
        try {
            // INSERT if not exists, UPDATE if exists — bulletproof upsert
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$key, $value]);
            
            // Verify it actually saved
            $verify = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $verify->execute([$key]);
            $saved = $verify->fetchColumn();
            
            if ($saved === $value) {
                logAudit('portal_setting_changed', 'admin', $_SESSION['admin_id'] ?? null, $_SESSION['admin_name'] ?? null,
                    ($_SESSION['admin_name'] ?? 'An admin') . " changed portal setting \"$key\" to \"$value\".",
                    ['key' => $key, 'value' => $value]);
                echo json_encode(['success'=>true,'key'=>$key,'value'=>$value,'saved'=>$saved]);
            } else {
                echo json_encode(['success'=>false,'message'=>'Save verification failed. Expected: '.$value.' Got: '.$saved]);
            }
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
}
?>