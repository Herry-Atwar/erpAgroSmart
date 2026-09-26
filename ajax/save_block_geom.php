<?php
/**
 * AJAX endpoint: save a block's GeoJSON geometry from Leaflet.Draw.
 * POST: block_id (int), geojson (string — Feature or FeatureCollection JSON)
 * Returns JSON { success, area_ha, message }
 */
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$block_id = isset($_POST['block_id']) && ctype_digit($_POST['block_id']) ? (int)$_POST['block_id'] : 0;
$geojson  = trim($_POST['geojson'] ?? '');

if (!$block_id) {
    echo json_encode(['success' => false, 'message' => 'block_id is required']);
    exit;
}
if (!$geojson) {
    echo json_encode(['success' => false, 'message' => 'geojson is required']);
    exit;
}

// Validate JSON structure
$decoded = json_decode($geojson, true);
if (!$decoded || !isset($decoded['type'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid GeoJSON']);
    exit;
}

// Accept FeatureCollection — extract first feature geometry
if ($decoded['type'] === 'FeatureCollection') {
    if (empty($decoded['features'])) {
        echo json_encode(['success' => false, 'message' => 'FeatureCollection is empty']);
        exit;
    }
    $geojson = json_encode($decoded['features'][0]['geometry'] ?? $decoded['features'][0]);
}
// Accept Feature — extract geometry
elseif ($decoded['type'] === 'Feature') {
    $geojson = json_encode($decoded['geometry']);
}
// Otherwise assume it IS a geometry object — pass through

try {
    $db = getDB();

    // Verify block exists
    $check = $db->prepare("SELECT block_id FROM blocks WHERE block_id = ?");
    $check->execute([$block_id]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'message' => "Block ID {$block_id} not found"]);
        exit;
    }

    // Upsert geometry using PostGIS ST_GeomFromGeoJSON
    // Also compute area_ha using ST_Area(geography)
    $stmt = $db->prepare("
        UPDATE blocks
        SET
            geojson  = :raw_geojson,
            geom     = ST_SetSRID(ST_GeomFromGeoJSON(:geom_geojson), 4326),
            area_ha  = ROUND(
                           ST_Area(ST_SetSRID(ST_GeomFromGeoJSON(:geom_geojson2), 4326)::geography) / 10000.0,
                           4
                       ),
            updated_at = NOW()
        WHERE block_id = :block_id
        RETURNING area_ha
    ");
    $stmt->execute([
        ':raw_geojson'  => $geojson,
        ':geom_geojson' => $geojson,
        ':geom_geojson2'=> $geojson,
        ':block_id'     => $block_id,
    ]);
    $row = $stmt->fetch();
    $area_ha = $row ? (float)$row['area_ha'] : null;

    echo json_encode([
        'success' => true,
        'area_ha' => $area_ha,
        'message' => 'Block geometry saved' . ($area_ha !== null ? " — area: {$area_ha} ha" : ''),
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
