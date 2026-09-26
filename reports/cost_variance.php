<?php
// Cost Variance Report - Actual vs Budget/Norm

// Get actual costs by block and activity
$sql_actual = "
    SELECT
        b.block_id,
        b.block_code,
        b.block_name,
        b.area,
        a.id,
        a.activity_code,
        a.activity_name,
        SUM(jel.debit_amount) as actual_cost,
        COUNT(DISTINCT je.id) as entry_count
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    LEFT JOIN blocks b ON jel.block_id = b.block_id
    LEFT JOIN activities a ON jel.activity_id = a.id
    WHERE $where_clause
    AND jel.debit_amount > 0
    AND b.block_id IS NOT NULL
    AND a.id IS NOT NULL
    GROUP BY b.block_id, b.block_code, b.block_name, b.area, a.id, a.activity_code, a.activity_name
";

$stmt = $pdo->prepare($sql_actual);
$stmt->execute($params);
$actual_costs = $stmt->fetchAll();

// Get norms/standards (if table exists)
$norms_lookup = [];
try {
    $norms = $pdo->query("
        SELECT
            an.activity_id,
            an.terrain_type,
            an.man_days_per_unit,
            an.unit_of_measure,
            (an.man_days_per_unit * 150000) as cost_per_unit,
            an.is_default
        FROM activity_norms an
        WHERE an.is_active = TRUE
    ")->fetchAll();

    foreach ($norms as $norm) {
        $key = $norm['activity_id'] . '_' . $norm['terrain_type'];
        $norms_lookup[$key] = $norm;
    }
} catch (PDOException $e) {
    // Query failed, continue without norms
    $norms_lookup = [];
}

// Get block terrain types (default to 'flat' if not available)
$block_terrains = [];
foreach ($actual_costs as $cost) {
    if (!isset($block_terrains[$cost['block_id']])) {
        $stmt = $pdo->prepare("SELECT terrain_type FROM blocks WHERE block_id = ?");
        $stmt->execute([$cost['block_id']]);
        $terrain = $stmt->fetchColumn();
        $block_terrains[$cost['block_id']] = $terrain ?: 'flat';
    }
}

// Calculate variances
$variances = [];
$total_actual = 0;
$total_standard = 0;
$total_variance = 0;

foreach ($actual_costs as $cost) {
    $terrain_type = $block_terrains[$cost['block_id']] ?? 'flat';
    $norm_key = $cost['id'] . '_' . $terrain_type;
    $norm = $norms_lookup[$norm_key] ?? null;
    
    // If no exact match, try to get default norm for this activity
    if (!$norm) {
        foreach ($norms_lookup as $key => $n) {
            if (strpos($key, $cost['id'] . '_') === 0 && $n['is_default'] == 1) {
                $norm = $n;
                break;
            }
        }
    }
    
    $standard_cost = 0;
    if ($norm && $cost['area'] > 0) {
        // Calculate standard cost based on norm: area * man_days_per_unit * daily_wage
        $standard_cost = $cost['area'] * $norm['cost_per_unit'];
    }
    
    $variance = $cost['actual_cost'] - $standard_cost;
    $variance_pct = $standard_cost > 0 ? ($variance / $standard_cost) * 100 : 0;
    
    $variances[] = [
        'block_code' => $cost['block_code'],
        'block_name' => $cost['block_name'],
        'terrain_type' => $terrain_type,
        'area_ha' => $cost['area'],
        'activity_code' => $cost['activity_code'],
        'activity_name' => $cost['activity_name'],
        'actual_cost' => $cost['actual_cost'],
        'standard_cost' => $standard_cost,
        'variance' => $variance,
        'variance_pct' => $variance_pct,
        'entry_count' => $cost['entry_count'],
        'has_norm' => $norm !== null
    ];
    
    $total_actual += $cost['actual_cost'];
    $total_standard += $standard_cost;
    $total_variance += $variance;
}

// Sort by absolute variance descending
usort($variances, function($a, $b) {
    return abs($b['variance']) <=> abs($a['variance']);
});

$total_variance_pct = $total_standard > 0 ? ($total_variance / $total_standard) * 100 : 0;

// Count favorable and unfavorable variances
$favorable_count = count(array_filter($variances, fn($v) => $v['variance'] < 0));
$unfavorable_count = count(array_filter($variances, fn($v) => $v['variance'] > 0));
$no_norm_count = count(array_filter($variances, fn($v) => !$v['has_norm']));
?>

<div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4><i class="bi bi-bar-chart"></i> Cost Variance Analysis (Actual vs Standard)</h4>
            <div>
                <button onclick="exportToExcel('cost_variance')" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </button>
                <button onclick="printReport()" class="btn btn-secondary btn-sm">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card bg-primary text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Actual Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($total_actual, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-info text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Standard Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($total_standard, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-<?= $total_variance > 0 ? 'danger' : 'success' ?> text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Total Variance</h6>
                <h5 class="mb-0">Rp <?= number_format(abs($total_variance), 0, ',', '.') ?></h5>
                <small><?= $total_variance > 0 ? 'Unfavorable' : 'Favorable' ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-warning text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Variance %</h6>
                <h5 class="mb-0"><?= number_format(abs($total_variance_pct), 1) ?>%</h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-success text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Favorable</h6>
                <h5 class="mb-0"><?= $favorable_count ?></h5>
                <small>items</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-danger text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Unfavorable</h6>
                <h5 class="mb-0"><?= $unfavorable_count ?></h5>
                <small>items</small>
            </div>
        </div>
    </div>
</div>

<?php if ($no_norm_count > 0): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    <strong>Note:</strong> <?= $no_norm_count ?> items have no standard/norm defined. Please set up activity norms for accurate variance analysis.
</div>
<?php endif; ?>

<!-- Data Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Block Code</th>
                        <th>Block Name</th>
                        <th>Status</th>
                        <th class="text-end">Area (Ha)</th>
                        <th>Activity</th>
                        <th class="text-end">Actual Cost</th>
                        <th class="text-end">Standard Cost</th>
                        <th class="text-end">Variance</th>
                        <th class="text-end">Variance %</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Entries</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($variances)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">No data available for the selected filters</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($variances as $var): ?>
                            <tr class="<?= !$var['has_norm'] ? 'table-warning' : '' ?>">
                                <td><strong><?= htmlspecialchars($var['block_code']) ?></strong></td>
                                <td><?= htmlspecialchars($var['block_name']) ?></td>
                                <td>
                                    <span class="badge bg-<?= getStatusColor($var['block_status']) ?>">
                                        <?= htmlspecialchars($var['block_status']) ?>
                                    </span>
                                </td>
                                <td class="text-end"><?= number_format($var['area'], 2) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($var['activity_code']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($var['activity_name']) ?></small>
                                </td>
                                <td class="text-end">Rp <?= number_format($var['actual_cost'], 0, ',', '.') ?></td>
                                <td class="text-end">
                                    <?php if ($var['has_norm']): ?>
                                        Rp <?= number_format($var['standard_cost'], 0, ',', '.') ?>
                                    <?php else: ?>
                                        <span class="text-muted">No norm</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end <?= $var['variance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?php if ($var['has_norm']): ?>
                                        <strong><?= $var['variance'] > 0 ? '+' : '' ?>Rp <?= number_format($var['variance'], 0, ',', '.') ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end <?= $var['variance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?php if ($var['has_norm'] && $var['standard_cost'] > 0): ?>
                                        <?= $var['variance'] > 0 ? '+' : '' ?><?= number_format($var['variance_pct'], 1) ?>%
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!$var['has_norm']): ?>
                                        <span class="badge bg-warning">No Norm</span>
                                    <?php elseif ($var['variance'] > 0): ?>
                                        <span class="badge bg-danger">Unfavorable</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Favorable</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= $var['entry_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Grand Total Row -->
                        <tr class="table-primary fw-bold">
                            <td colspan="5">GRAND TOTAL</td>
                            <td class="text-end">Rp <?= number_format($total_actual, 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($total_standard, 0, ',', '.') ?></td>
                            <td class="text-end <?= $total_variance > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $total_variance > 0 ? '+' : '' ?>Rp <?= number_format($total_variance, 0, ',', '.') ?>
                            </td>
                            <td class="text-end <?= $total_variance > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $total_variance > 0 ? '+' : '' ?><?= number_format($total_variance_pct, 1) ?>%
                            </td>
                            <td colspan="2" class="text-center"><?= count($variances) ?> items</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row mt-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header" style="background-color: #166c82; color: white;">
                <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Top 10 Unfavorable Variances</h5>
            </div>
            <div class="card-body">
                <canvas id="unfavorableChart" height="300"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header" style="background-color: #166c82; color: white;">
                <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Top 10 Favorable Variances</h5>
            </div>
            <div class="card-body">
                <canvas id="favorableChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header" style="background-color: #166c82; color: white;">
                <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Variance Distribution</h5>
            </div>
            <div class="card-body">
                <canvas id="distributionChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header" style="background-color: #166c82; color: white;">
                <h5 class="mb-0"><i class="bi bi-graph-up"></i> Actual vs Standard Comparison</h5>
            </div>
            <div class="card-body">
                <canvas id="comparisonChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
const variances = <?= json_encode($variances) ?>;

// Top 10 Unfavorable
const unfavorable = variances.filter(v => v.variance > 0 && v.has_norm).slice(0, 10);
new Chart(document.getElementById('unfavorableChart'), {
    type: 'bar',
    data: {
        labels: unfavorable.map(v => v.block_code + ' - ' + v.activity_code),
        datasets: [{
            label: 'Unfavorable Variance',
            data: unfavorable.map(v => v.variance),
            backgroundColor: 'rgba(255, 99, 132, 0.8)',
            borderColor: 'rgba(255, 99, 132, 1)',
            borderWidth: 1
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        }
    }
});

// Top 10 Favorable
const favorable = variances.filter(v => v.variance < 0 && v.has_norm).slice(0, 10);
new Chart(document.getElementById('favorableChart'), {
    type: 'bar',
    data: {
        labels: favorable.map(v => v.block_code + ' - ' + v.activity_code),
        datasets: [{
            label: 'Favorable Variance',
            data: favorable.map(v => Math.abs(v.variance)),
            backgroundColor: 'rgba(75, 192, 192, 0.8)',
            borderColor: 'rgba(75, 192, 192, 1)',
            borderWidth: 1
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        }
    }
});

// Distribution
const favorableSum = variances.filter(v => v.variance < 0).reduce((sum, v) => sum + Math.abs(v.variance), 0);
const unfavorableSum = variances.filter(v => v.variance > 0).reduce((sum, v) => sum + v.variance, 0);

new Chart(document.getElementById('distributionChart'), {
    type: 'doughnut',
    data: {
        labels: ['Favorable', 'Unfavorable'],
        datasets: [{
            data: [favorableSum, unfavorableSum],
            backgroundColor: [
                'rgba(75, 192, 192, 0.8)',
                'rgba(255, 99, 132, 0.8)'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// Comparison
new Chart(document.getElementById('comparisonChart'), {
    type: 'bar',
    data: {
        labels: ['Total'],
        datasets: [
            {
                label: 'Actual Cost',
                data: [<?= $total_actual ?>],
                backgroundColor: 'rgba(54, 162, 235, 0.8)'
            },
            {
                label: 'Standard Cost',
                data: [<?= $total_standard ?>],
                backgroundColor: 'rgba(75, 192, 192, 0.8)'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});
</script>

<?php
function getStatusColor($status) {
    $colors = [
        'LC' => 'warning',
        'TBM' => 'info',
        'TM' => 'success'
    ];
    return $colors[$status] ?? 'secondary';
}
?>

// Made with Bob
