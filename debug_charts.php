<?php
require_once 'config/database.php';
$db = getDB();

echo "=== Chart Data Debug ===\n\n";

// Blocks by status
try {
    $r = $db->query("SELECT b.status, COUNT(*) as count, SUM(b.area) as total_area FROM blocks b INNER JOIN divisions _d ON b.division_id = _d.division_id INNER JOIN business_units _bu ON _d.business_unit_id = _bu.business_unit_id WHERE 1=1 GROUP BY b.status")->fetchAll();
    echo "blocks_by_status rows: " . count($r) . "\n";
    foreach ($r as $row) echo "  status={$row['status']} count={$row['count']} area={$row['total_area']}\n";
} catch (Exception $e) { echo "blocks_by_status ERROR: " . $e->getMessage() . "\n"; }

echo "\n";

// BU by type
try {
    $r = $db->query("SELECT unit_type, COUNT(*) as count, SUM(total_area) as total_area FROM business_units WHERE status = 'Active' GROUP BY unit_type")->fetchAll();
    echo "bu_by_type rows: " . count($r) . "\n";
    foreach ($r as $row) echo "  type={$row['unit_type']} count={$row['count']}\n";
} catch (Exception $e) { echo "bu_by_type ERROR: " . $e->getMessage() . "\n"; }

echo "\n";

// Planting years
try {
    $r = $db->query("SELECT py.year, COUNT(b.block_id) as block_count, SUM(b.area) as total_area FROM planting_years py LEFT JOIN blocks b ON py.planting_year_id = b.planting_year_id WHERE 1=1 GROUP BY py.year ORDER BY py.year DESC LIMIT 10")->fetchAll();
    echo "planting_years rows: " . count($r) . "\n";
    foreach ($r as $row) echo "  year={$row['year']} blocks={$row['block_count']}\n";
} catch (Exception $e) { echo "planting_years ERROR: " . $e->getMessage() . "\n"; }

echo "\n";

// Check planting_years columns
try {
    $r = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name='planting_years' ORDER BY ordinal_position")->fetchAll();
    echo "planting_years columns: ";
    echo implode(', ', array_column($r, 'column_name')) . "\n";
} catch (Exception $e) { echo "column check ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== Done ===\n";
