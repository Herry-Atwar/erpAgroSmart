<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Areal Statement Report";
require_once 'includes/header.php';

// Get parameters
$division_id = get('division_id', '');
$block_id = get('block_id', '');

// Fetch divisions
$divisions_stmt = $db->query("SELECT division_id, division_code, division_name FROM divisions ORDER BY division_code");
$divisions = $divisions_stmt->fetchAll();

// Fetch blocks
$blocks_query = "SELECT block_id, block_code, block_name, area FROM blocks WHERE 1=1";
$blocks_params = [];
if ($division_id) {
    $blocks_query .= " AND block_id IN (
        SELECT b.block_id FROM blocks b
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        WHERE py.division_id = ?
    )";
    $blocks_params[] = $division_id;
}
if ($block_id) {
    $blocks_query .= " AND block_id = ?";
    $blocks_params[] = $block_id;
}
$blocks_query .= " ORDER BY block_code";
$blocks_stmt = $db->prepare($blocks_query);
$blocks_stmt->execute($blocks_params);
$blocks = $blocks_stmt->fetchAll();

// Fetch all active categories with their measurements
$categories_stmt = $db->query("
    SELECT 
        bacc.id as category_id,
        bacc.category_code,
        bacc.category_name,
        bacc.category_type,
        bacc.display_order as cat_order,
        acmt.id as measurement_id,
        acmt.measurement_code,
        acmt.measurement_name,
        acmt.unit_of_measure,
        acmt.data_type,
        acmt.decimal_places,
        acmt.display_order as meas_order
    FROM block_area_component_categories bacc
    INNER JOIN area_component_measurement_types acmt ON bacc.id = acmt.category_id
    WHERE bacc.is_active = TRUE
    ORDER BY bacc.display_order, acmt.display_order
");
$all_measurements = $categories_stmt->fetchAll();

// Group measurements by category
$categories = [];
foreach ($all_measurements as $row) {
    $cat_id = $row['category_id'];
    if (!isset($categories[$cat_id])) {
        $categories[$cat_id] = [
            'id' => $row['category_id'],
            'code' => $row['category_code'],
            'name' => $row['category_name'],
            'type' => $row['category_type'],
            'measurements' => []
        ];
    }
    $categories[$cat_id]['measurements'][] = [
        'id' => $row['measurement_id'],
        'code' => $row['measurement_code'],
        'name' => $row['measurement_name'],
        'unit' => $row['unit_of_measure'],
        'data_type' => $row['data_type'],
        'decimals' => $row['decimal_places']
    ];
}

// Fetch values for selected blocks
$block_data = [];
$totals_by_measurement = [];

foreach ($blocks as $block) {
    $block_id_val = $block['block_id'];
    
    // Fetch all values for this block
    $values_stmt = $db->prepare("
        SELECT 
            category_id,
            measurement_type_id,
            value,
            text_value
        FROM block_area_component_values
        WHERE block_id = ?
    ");
    $values_stmt->execute([$block_id_val]);
    $values = $values_stmt->fetchAll();
    
    $block_values = [];
    foreach ($values as $val) {
        $key = $val['category_id'] . '_' . $val['measurement_type_id'];
        $block_values[$key] = $val['value'] ?: $val['text_value'];
        
        // Accumulate totals for numeric values
        if (is_numeric($val['value'])) {
            if (!isset($totals_by_measurement[$key])) {
                $totals_by_measurement[$key] = 0;
            }
            $totals_by_measurement[$key] += $val['value'];
        }
    }
    
    $block_data[$block_id_val] = [
        'block' => $block,
        'values' => $block_values
    ];
}
?>

<style>
@media print {
    .no-print {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
}

.table-areal {
    font-size: 0.9rem;
}

.table-areal th {
    font-weight: 600;
    white-space: nowrap;
}

.table-areal th:nth-child(1),
.table-areal td:nth-child(1) {
    min-width: 180px;
    width: 180px;
}

.table-areal th:nth-child(2),
.table-areal td:nth-child(2) {
    min-width: 200px;
    width: 200px;
}

.table-areal th:nth-child(3),
.table-areal td:nth-child(3) {
    min-width: 80px;
    width: 80px;
}

.table-areal td {
    font-size: 0.85rem;
}

.table-areal th {
    font-size: 0.85rem;
}

.basic-header {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
    color: white;
    font-weight: 600;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
}

.category-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: bold;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
}

.planted-category {
    background: linear-gradient(135deg, #56ab2f 0%, #a8e063 100%);
    color: white;
    font-weight: 600;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
}

.non-planted-category {
    background: linear-gradient(135deg, #8B4513 0%, #D2691E 100%);
    color: white;
    font-weight: 600;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
}

.measurement-header {
    background: linear-gradient(135deg, #adb5bd 0%, #6c757d 100%);
    color: white;
    font-weight: 500;
    border-left: 1px solid #dee2e6;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
}

.total-row {
    background-color: #f8f9fa;
    font-weight: bold;
    border-top: 2px solid #dee2e6;
}

</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                <h2><i class="bi bi-file-earmark-text"></i> Areal Statement Report</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-secondary">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <a href="block_area_components.php" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Manage Components
                    </a>
                    <a href="area_component_config.php" class="btn btn-info">
                        <i class="bi bi-gear"></i> Configure
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Division</label>
                    <select name="division_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Divisions</option>
                        <?php foreach ($divisions as $div): ?>
                        <option value="<?= $div['division_id'] ?>" <?= $div['division_id'] == $division_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($div['division_code']) ?> - <?= htmlspecialchars($div['division_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Block</label>
                    <select name="block_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Blocks</option>
                        <?php foreach ($blocks as $blk): ?>
                        <option value="<?= $blk['block_id'] ?>" <?= $blk['block_id'] == $block_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($blk['block_code']) ?> - <?= htmlspecialchars($blk['block_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-search"></i> Filter
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($blocks)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No blocks found. Please select different filters or add block data.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-areal table-striped">
                    <thead>
                        <tr>
                            <th rowspan="2" class="align-middle basic-header">Block Code</th>
                            <th rowspan="2" class="align-middle basic-header">Block Name</th>
                            <th rowspan="2" class="align-middle basic-header">Total Area (ha)</th>
                            <?php foreach ($categories as $category): ?>
                                <th colspan="<?= count($category['measurements']) ?>" class="text-center <?= $category['type'] == 'planted' ? 'planted-category' : 'non-planted-category' ?>">
                                    <?= htmlspecialchars($category['name']) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <?php foreach ($categories as $category): ?>
                                <?php foreach ($category['measurements'] as $measurement): ?>
                                <th class="text-center measurement-header">
                                    <?= htmlspecialchars($measurement['name']) ?><br>
                                    <small style="opacity: 0.8;">(<?= htmlspecialchars($measurement['unit']) ?>)</small>
                                </th>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($block_data as $data):
                            $block = $data['block'];
                            $values = $data['values'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($block['block_code']) ?></td>
                            <td><?= htmlspecialchars($block['block_name']) ?></td>
                            <td class="text-end"><?= number_format($block['area'], 2) ?></td>
                            <?php foreach ($categories as $category): ?>
                                <?php foreach ($category['measurements'] as $measurement): ?>
                                    <?php 
                                    $key = $category['id'] . '_' . $measurement['id'];
                                    $value = $values[$key] ?? 0;
                                    
                                    if ($measurement['data_type'] == 'text') {
                                        echo '<td>' . htmlspecialchars($value) . '</td>';
                                    } elseif ($measurement['data_type'] == 'integer') {
                                        echo '<td class="text-end">' . number_format($value, 0, ',', ',') . '</td>';
                                    } else {
                                        $decimals = $measurement['decimals'] ?? 2;
                                        echo '<td class="text-end">' . number_format($value, $decimals) . '</td>';
                                    }
                                    ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        
                        <!-- Totals Row -->
                        <tr class="total-row">
                            <td colspan="2" class="text-end">TOTAL</td>
                            <td class="text-end"><?= number_format(array_sum(array_column($blocks, 'area')), 2) ?></td>
                            <?php foreach ($categories as $category): ?>
                                <?php foreach ($category['measurements'] as $measurement): ?>
                                    <?php 
                                    $key = $category['id'] . '_' . $measurement['id'];
                                    $total = $totals_by_measurement[$key] ?? 0;
                                    
                                    if ($measurement['data_type'] == 'text') {
                                        echo '<td>-</td>';
                                    } elseif ($measurement['data_type'] == 'integer') {
                                        echo '<td class="text-end">' . number_format($total, 0, ',', ',') . '</td>';
                                    } else {
                                        $decimals = $measurement['decimals'] ?? 2;
                                        echo '<td class="text-end">' . number_format($total, $decimals) . '</td>';
                                    }
                                    ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob