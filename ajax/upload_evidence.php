<?php
/**
 * AJAX endpoint: upload evidence file for ISPO assessment score or corrective action.
 * POST params:
 *   - file      (multipart upload, field name = "evidence_file")
 *   - score_id  (integer, optional)
 *   - action_id (integer, optional)
 *   - description (string, optional)
 *
 * Returns JSON { success, evidence_id, file_name, file_path, message }
 */
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$score_id   = isset($_POST['score_id'])   && ctype_digit($_POST['score_id'])   ? (int)$_POST['score_id']   : null;
$action_id  = isset($_POST['action_id'])  && ctype_digit($_POST['action_id'])  ? (int)$_POST['action_id']  : null;
$description = trim($_POST['description'] ?? '');

if ($score_id === null && $action_id === null) {
    echo json_encode(['success' => false, 'message' => 'score_id or action_id is required']);
    exit;
}

if (!isset($_FILES['evidence_file']) || $_FILES['evidence_file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['evidence_file']['error'] ?? 'no file';
    echo json_encode(['success' => false, 'message' => "Upload error: {$err}"]);
    exit;
}

$file      = $_FILES['evidence_file'];
$orig_name = basename($file['name']);
$size_kb   = (int)round($file['size'] / 1024);
$ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

// Allowed types
$allowed_ext  = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','zip'];
$allowed_mime = ['application/pdf','application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg','image/png','image/gif','application/zip'];

if (!in_array($ext, $allowed_ext)) {
    echo json_encode(['success' => false, 'message' => "File type .{$ext} not allowed"]);
    exit;
}

// 10 MB limit
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File exceeds 10 MB limit']);
    exit;
}

// Build safe storage filename
$prefix    = $score_id  ? "sc{$score_id}"  : "ac{$action_id}";
$safe_name = $prefix . '_' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig_name);
$upload_dir = __DIR__ . '/../uploads/ispo/';
$dest_path  = $upload_dir . $safe_name;
$rel_path   = 'uploads/ispo/' . $safe_name;

if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file']);
    exit;
}

// Detect MIME from actual file
$finfo     = new finfo(FILEINFO_MIME_TYPE);
$mime_type = $finfo->file($dest_path);

try {
    $db  = getDB();
    $stmt = $db->prepare("
        INSERT INTO ispo_evidence (score_id, action_id, file_name, file_path, file_type, file_size_kb, description, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$score_id, $action_id, $orig_name, $rel_path, $mime_type, $size_kb, $description ?: null, 'admin']);
    $evidence_id = $db->lastInsertId();

    // If action_id provided, update evidence_path on action
    if ($action_id) {
        $db->prepare("UPDATE ispo_corrective_actions SET evidence_path = ?, updated_at = NOW() WHERE action_id = ?")
           ->execute([$rel_path, $action_id]);
    }

    echo json_encode([
        'success'     => true,
        'evidence_id' => $evidence_id,
        'file_name'   => $orig_name,
        'file_path'   => $rel_path,
        'message'     => 'Evidence uploaded successfully',
    ]);
} catch (PDOException $e) {
    // Clean up the file if DB insert fails
    @unlink($dest_path);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
