<?php
/**
 * CS Dept CA Portal - Secure Face Descriptor Retrieval
 * Updated for high-security verification
 */

session_start();
header('Content-Type: application/json');

// Using your existing config file
require_once '../includes/config.php';

// 1. Get and Sanitize Input
$matric = strtoupper(trim($_GET['matric'] ?? ''));

// 2. Initial Validation
if (empty($matric)) {
    echo json_encode(['success' => false, 'message' => 'Matric number required']);
    exit;
}

// Ensure the format strictly matches: e.g., 21CS1001
if (!preg_match('/^\d{2}CS\d{4}$/', $matric)) {
    echo json_encode(['success' => false, 'message' => 'Invalid matric format']);
    exit;
}

// SECURITY: this session must have legitimately looked up this exact matric
// first (via api/check-student.php or the custom-link matric check). Without
// this, matric numbers are guessable (\d{2}CS\d{4}) and this endpoint would
// hand out any enrolled student's raw biometric descriptor to anyone.
if (($_SESSION['pending_verify_matric'] ?? '') !== $matric) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorised request.']);
    exit;
}

// Reuse the same failed-attempt lockout face-verify.php already tracks, so
// repeated descriptor fetches for one matric are capped too.
if (!isset($_SESSION['face_attempts'])) $_SESSION['face_attempts'] = [];
if (($_SESSION['face_attempts'][$matric] ?? 0) >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please contact admin.']);
    exit;
}

try {
    // 3. Fetch Student Data
    // Note: Ensure your table column is 'matric' (matching your query)
    $stmt = $pdo->prepare("SELECT matric, full_name, face_descriptor FROM students WHERE matric = ? LIMIT 1");
    $stmt->execute([$matric]);
    $student = $stmt->fetch();

    // 4. Check existence
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student record not found.']);
        exit;
    }

    // 5. Check if Face is Enrolled
    if (empty($student['face_descriptor'])) {
        echo json_encode([
            'success'      => false,
            'message'      => 'No face enrolled for ' . htmlspecialchars($student['full_name']) . '.',
            'student_name' => $student['full_name']
        ]);
        exit;
    }

    // 6. JSON Decoding and Integrity Check
    $descriptor = json_decode($student['face_descriptor'], true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($descriptor)) {
        echo json_encode(['success' => false, 'message' => 'Face template data is corrupted.']);
        exit;
    }

    // SECURITY CHECK: 
    // face-api.js descriptors are ALWAYS 128 floating point numbers.
    // If it's not 128, the comparison logic in verification.php will FAIL.
    if (count($descriptor) !== 128) {
        error_log("Security Warning: Matric $matric has an invalid descriptor length: " . count($descriptor));
        echo json_encode(['success' => false, 'message' => 'Biometric signature mismatch. Please re-enroll.']);
        exit;
    }

    // 7. Successful Response
    echo json_encode([
        'success'      => true,
        'descriptor'   => $descriptor,
        'student_name' => $student['full_name'],
        'message'      => 'Face signature retrieved'
    ]);

} catch (PDOException $e) {
    // Log actual error for admin, show generic error to user
    error_log('Face API SQL Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal database error.']);
} catch (Exception $e) {
    error_log('Face API General Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error. Try again.']);
}