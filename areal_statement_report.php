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
$blocks_query = "SELECT block_id, block_code, block_name FROM blocks WHERE 1=1";
$blocks_params = [];
if ($division_id) {
    $blocks_query .= " AND block_id IN (
        SELECT b.block_id FROM blocks b
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        WHERE py.division_id = ?
    )";
    $blocks_params[] = $division_id;
}
$blocks_query .= " ORDER BY block_code";
$blocks_stmt = $db->prepare($blocks_query);
$blocks_stmt->execute($blocks_params);
$blocks = $blocks_stmt->fetchAll();

// Fetch areal statement data
$sql = "SELECT * FROM v_block_areal_statement WHERE 1=1";
$params = [];

if ($division_id) {
    $sql .= " AND block_id IN (
        SELECT b.block_id FROM blocks b
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        WHERE py.division_id = ?
    )";
    $params[] = $division_id;
}

if ($block_id) {
    $sql .= " AND block_id = ?";
    $params[] = $block_id;
}

$sql .= " ORDER BY block_code";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$areal_data = $stmt->fetchAll();

// Calculate totals
$totals = [
    'total_area' => 0,
    'planted_area' => 0,
    'tree_count' => 0,
    'road_area' => 0,
    'building_area' => 0,
    'bridge_area' => 0,
    'water_area' => 0,
    'swamp_area' => 0,
    'conservation_area' => 0,
    'other_area' => 0,
    'total_non_planted_area' => 0
];

foreach ($areal_data as $row) {
    foreach ($totals as $key => $value) {
        $totals[$key] += $row[$key];
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-file-earmark-text"></i> Areal Statement Report</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-secondary">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <a href="block_area_components.php" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Manage Components
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Division</label>
                    <select name="division_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Divisions</option>
                        <?php foreach ($divisions as $division): ?>
                        <option value="<?= $division['division_id'] ?>" <?= $division['division_id'] == $division_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($division['division_code']) ?> - <?= htmlspecialchars($division['division_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Block</label>
                    <select name="block_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Blocks</option>
                        <?php foreach ($blocks as $block): ?>
                        <option value="<?= $block['block_id'] ?>" <?= $block['block_id'] == $block_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($block['block_code']) ?> - <?= htmlspecialchars($block['block_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <a href="areal_statement_report.php" class="btn btn-secondary w-100">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6>Total Area</h6>
                    <h3><?= number_format($totals['total_area'], 2) ?> ha</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h6>Planted Area</h6>
                    <h3><?= number_format($totals['planted_area'], 2) ?> ha</h3>
                    <small><?= $totals['total_area'] > 0 ? number_format($totals['planted_area']/$totals['total_area']*100, 1) : 0 ?>%</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h6>Non-Planted Area</h6>
                    <h3><?= number_format($totals['total_non_planted_area'], 2) ?> ha</h3>
                    <small><?= $totals['total_area'] > 0 ? number_format($totals['total_non_planted_area']/$totals['total_area']*100, 1) : 0 ?>%</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h6>Total Trees</h6>
                    <h3><?= number_format($totals['tree_count'], 0) ?></h3>
                    <small><?= $totals['planted_area'] > 0 ? number_format($totals['tree_count']/$totals['planted_area'], 0) : 0 ?> trees/ha</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Areal Statement Table -->
    <div class="card">
        <div class="card-header bg-light">
            <h5 class="mb-0">Areal Statement by Block</h5>
        </div>
        <div class="card-body">
            <?php if (empty($areal_data)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No data available. Please configure block area components first.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th rowspan="2" class="align-middle">Block Code</th>
                            <th rowspan="2" class="align-middle">Block Name</th>
                            <th rowspan="2" class="align-middle text-end">Total Area<br>(ha)</th>
                            <th colspan="3" class="text-center bg-success text-white">Planted Area</th>
                            <th colspan="7" class="text-center bg-warning">Non-Planted Area</th>
                        </tr>
                        <tr>
                            <!-- Planted -->
                            <th class="text-end bg-success text-white">Area (ha)</th>
                            <th class="text-end bg-success text-white">Trees</th>
                            <th class="text-end bg-success text-white">%</th>
                            <!-- Non-Planted -->
                            <th class="text-end bg-warning">Roads (ha)</th>
                            <th class="text-end bg-warning">Buildings (ha)</th>
                            <th class="text-end bg-warning">Bridges (ha)</th>
                            <th class="text-end bg-warning">Water (ha)</th>
                            <th class="text-end bg-warning">Swamp (ha)</th>
                            <th class="text-end bg-warning">Conservation (ha)</th>
                            <th class="text-end bg-warning">Other (ha)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($areal_data as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['block_code']) ?></strong></td>
                            <td><?= htmlspecialchars($row['block_name']) ?></td>
                            <td class="text-end"><strong><?= number_format($row['total_area'], 2) ?></strong></td>
                            <!-- Planted -->
                            <td class="text-end bg-success bg-opacity-10"><?= number_format($row['planted_area'], 2) ?></td>
                            <td class="text-end bg-success bg-opacity-10"><?= number_format($row['tree_count'], 0) ?></td>
                            <td class="text-end bg-success bg-opacity-10"><?= number_format($row['planted_percentage'], 1) ?>%</td>
                            <!-- Non-Planted -->
                            <td class="text-end bg-warning bg-opacity-10"><?= number_format($row['road_area'], 2) ?></td>
                            <td class="text-end bg-warning bg-opacity-10"><?= number_format($row['building_area'], 2) ?></td>
                            <td class="text-end bg-warning bg-opacity-10"><?= number_format($row['bridge_area'], 2) ?></td>
                            <td class="text-end bg-warning bg-opacity-10"><?= number_format($row['water_area'], 2) ?></td>
                            <td class="text-end bg-warning bg-opacity-10"><?= number_format($row['swamp_area'], 2) ?></td>
                            <td class="text-end bg-warning bg-opacity-10"><?= number_format($row['conservation_area'], 2) ?></td>
                            <td class="text-end bg-warning bg-opacity-10"><?= number_format($row['other_area'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-secondary">
                        <tr>
                            <th colspan="2" class="text-end">TOTAL:</th>
                            <th class="text-end"><?= number_format($totals['total_area'], 2) ?></th>
                            <!-- Planted -->
                            <th class="text-end"><?= number_format($totals['planted_area'], 2) ?></th>
                            <th class="text-end"><?= number_format($totals['tree_count'], 0) ?></th>
                            <th class="text-end"><?= $totals['total_area'] > 0 ? number_format($totals['planted_area']/$totals['total_area']*100, 1) : 0 ?>%</th>
                            <!-- Non-Planted -->
                            <th class="text-end"><?= number_format($totals['road_area'], 2) ?></th>
                            <th class="text-end"><?= number_format($totals['building_area'], 2) ?></th>
                            <th class="text-end"><?= number_format($totals['bridge_area'], 2) ?></th>
                            <th class="text-end"><?= number_format($totals['water_area'], 2) ?></th>
                            <th class="text-end"><?= number_format($totals['swamp_area'], 2) ?></th>
                            <th class="text-end"><?= number_format($totals['conservation_area'], 2) ?></th>
                            <th class="text-end"><?= number_format($totals['other_area'], 2) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Non-Planted Breakdown Chart -->
    <?php if (!empty($areal_data)): ?>
    <div class="card mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Non-Planted Area Breakdown</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr>
                            <td><i class="bi bi-circle-fill text-primary"></i> Roads & Paths</td>
                            <td class="text-end"><strong><?= number_format($totals['road_area'], 2) ?> ha</strong></td>
                            <td class="text-end"><?= $totals['total_non_planted_area'] > 0 ? number_format($totals['road_area']/$totals['total_non_planted_area']*100, 1) : 0 ?>%</td>
                        </tr>
                        <tr>
                            <td><i class="bi bi-circle-fill text-success"></i> Buildings</td>
                            <td class="text-end"><strong><?= number_format($totals['building_area'], 2) ?> ha</strong></td>
                            <td class="text-end"><?= $totals['total_non_planted_area'] > 0 ? number_format($totals['building_area']/$totals['total_non_planted_area']*100, 1) : 0 ?>%</td>
                        </tr>
                        <tr>
                            <td><i class="bi bi-circle-fill text-info"></i> Bridges</td>
                            <td class="text-end"><strong><?= number_format($totals['bridge_area'], 2) ?> ha</strong></td>
                            <td class="text-end"><?= $totals['total_non_planted_area'] > 0 ? number_format($totals['bridge_area']/$totals['total_non_planted_area']*100, 1) : 0 ?>%</td>
                        </tr>
                        <tr>
                            <td><i class="bi bi-circle-fill text-warning"></i> Water Bodies</td>
                            <td class="text-end"><strong><?= number_format($totals['water_area'], 2) ?> ha</strong></td>
                            <td class="text-end"><?= $totals['total_non_planted_area'] > 0 ? number_format($totals['water_area']/$totals['total_non_planted_area']*100, 1) : 0 ?>%</td>
                        </tr>
                        <tr>
                            <td><i class="bi bi-circle-fill text-secondary"></i> Swamp</td>
                            <td class="text-end"><strong><?= number_format($totals['swamp_area'], 2) ?> ha</strong></td>
                            <td class="text-end"><?= $totals['total_non_planted_area'] > 0 ? number_format($totals['swamp_area']/$totals['total_non_planted_area']*100, 1) : 0 ?>%</td>
                        </tr>
                        <tr>
                            <td><i class="bi bi-circle-fill text-danger"></i> Conservation</td>
                            <td class="text-end"><strong><?= number_format($totals['conservation_area'], 2) ?> ha</strong></td>
                            <td class="text-end"><?= $totals['total_non_planted_area'] > 0 ? number_format($totals['conservation_area']/$totals['total_non_planted_area']*100, 1) : 0 ?>%</td>
                        </tr>
                        <tr>
                            <td><i class="bi bi-circle-fill text-dark"></i> Other</td>
                            <td class="text-end"><strong><?= number_format($totals['other_area'], 2) ?> ha</strong></td>
                            <td class="text-end"><?= $totals['total_non_planted_area'] > 0 ? number_format($totals['other_area']/$totals['total_non_planted_area']*100, 1) : 0 ?>%</td>
                        </tr>
                        <tr class="table-secondary">
                            <td><strong>Total Non-Planted</strong></td>
                            <td class="text-end"><strong><?= number_format($totals['total_non_planted_area'], 2) ?> ha</strong></td>
                            <td class="text-end"><strong>100%</strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
