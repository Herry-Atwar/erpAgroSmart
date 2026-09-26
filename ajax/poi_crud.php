<?php
/**
 * POI CRUD AJAX endpoint
 * Actions: list | add | update | delete | types
 * All responses: JSON
 */
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? 'list';
$db     = getDB();

try {

// ── LIST ─────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $company_id = isset($_GET['company_id']) && ctype_digit($_GET['company_id'])
                  ? (int)$_GET['company_id'] : null;
    $type_code  = trim($_GET['type_code'] ?? '');

    $sql    = "SELECT p.*, t.type_code, t.type_name, t.category, t.map_color, t.icon_name
               FROM map_poi p
               JOIN poi_types t ON p.type_id = t.type_id
               WHERE p.is_active = TRUE";
    $params = [];

    if ($company_id) { $sql .= " AND p.company_id = ?"; $params[] = $company_id; }
    if ($type_code)  { $sql .= " AND t.type_code = ?";  $params[] = $type_code; }
    $sql .= " ORDER BY t.sort_order, p.poi_name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

// ── TYPES ────────────────────────────────────────────────────────────────────
elseif ($action === 'types') {
    $rows = $db->query("SELECT * FROM poi_types WHERE is_active = TRUE ORDER BY sort_order")->fetchAll();
    echo json_encode(['success' => true, 'data' => $rows]);
}

// ── ADD ──────────────────────────────────────────────────────────────────────
elseif ($action === 'add') {
    $required = ['type_id','poi_name','latitude','longitude'];
    foreach ($required as $f) {
        if (empty($_POST[$f])) {
            echo json_encode(['success' => false, 'message' => "Field '{$f}' is required"]);
            exit;
        }
    }
    $lat = (float)$_POST['latitude'];
    $lng = (float)$_POST['longitude'];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        echo json_encode(['success' => false, 'message' => 'Invalid coordinates']);
        exit;
    }
    $stmt = $db->prepare("
        INSERT INTO map_poi
          (company_id, type_id, poi_name, poi_code, description,
           latitude, longitude, address, capacity_info,
           contact_name, contact_phone, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING poi_id
    ");
    $stmt->execute([
        !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null,
        (int)$_POST['type_id'],
        trim($_POST['poi_name']),
        trim($_POST['poi_code']         ?? '') ?: null,
        trim($_POST['description']      ?? '') ?: null,
        $lat, $lng,
        trim($_POST['address']          ?? '') ?: null,
        trim($_POST['capacity_info']    ?? '') ?: null,
        trim($_POST['contact_name']     ?? '') ?: null,
        trim($_POST['contact_phone']    ?? '') ?: null,
        'admin',
    ]);
    $row = $stmt->fetch();
    echo json_encode(['success' => true, 'poi_id' => $row['poi_id'], 'message' => 'POI added successfully']);
}

// ── UPDATE ────────────────────────────────────────────────────────────────────
elseif ($action === 'update') {
    $poi_id = (int)($_POST['poi_id'] ?? 0);
    if (!$poi_id) { echo json_encode(['success'=>false,'message'=>'poi_id required']); exit; }

    $lat = (float)($_POST['latitude']  ?? 0);
    $lng = (float)($_POST['longitude'] ?? 0);

    $db->prepare("
        UPDATE map_poi SET
            type_id       = ?,
            poi_name      = ?,
            poi_code      = ?,
            description   = ?,
            latitude      = ?,
            longitude     = ?,
            address       = ?,
            capacity_info = ?,
            contact_name  = ?,
            contact_phone = ?,
            updated_at    = NOW()
        WHERE poi_id = ?
    ")->execute([
        (int)$_POST['type_id'],
        trim($_POST['poi_name']),
        trim($_POST['poi_code']      ?? '') ?: null,
        trim($_POST['description']   ?? '') ?: null,
        $lat, $lng,
        trim($_POST['address']       ?? '') ?: null,
        trim($_POST['capacity_info'] ?? '') ?: null,
        trim($_POST['contact_name']  ?? '') ?: null,
        trim($_POST['contact_phone'] ?? '') ?: null,
        $poi_id,
    ]);
    echo json_encode(['success' => true, 'message' => 'POI updated']);
}

// ── DELETE ────────────────────────────────────────────────────────────────────
elseif ($action === 'delete') {
    $poi_id = (int)($_POST['poi_id'] ?? $_GET['poi_id'] ?? 0);
    if (!$poi_id) { echo json_encode(['success'=>false,'message'=>'poi_id required']); exit; }
    $db->prepare("DELETE FROM map_poi WHERE poi_id = ?")->execute([$poi_id]);
    echo json_encode(['success' => true, 'message' => 'POI deleted']);
}

else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Unknown action: {$action}"]);
}

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
