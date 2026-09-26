<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_GET['block_id'])) {
    echo json_encode(['success' => false, 'message' => 'Block ID required']);
    exit;
}

$block_id = $_GET['block_id'];

try {
    $db = getDBConnection();
    
    // Get block basic info
    $block_stmt = $db->prepare("
        SELECT block_code, block_name, area 
        FROM blocks 
        WHERE block_id = ?
    ");
    $block_stmt->execute([$block_id]);
    $block = $block_stmt->fetch();
    
    if (!$block) {
        echo json_encode(['success' => false, 'message' => 'Block not found']);
        exit;
    }
    
    // Get block area components
    $components_stmt = $db->prepare("
        SELECT 
            mt.measurement_name,
            mt.unit_symbol,
            c.category_name,
            bacv.measurement_value,
            bacv.notes
        FROM block_area_component_values bacv
        INNER JOIN measurement_types mt ON bacv.measurement_type_id = mt.measurement_type_id
        INNER JOIN categories c ON mt.category_id = c.category_id
        WHERE bacv.block_id = ?
        ORDER BY c.display_order, mt.display_order
    ");
    $components_stmt->execute([$block_id]);
    $components = $components_stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'block' => $block,
        'components' => $components
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// Made with Bob
