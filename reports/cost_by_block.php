<?php
// Cost by Block Report

$sql = "
    SELECT
        c.company_name,
        bu.unit_name,
        d.division_name,
        b.block_code,
        b.block_name,
        b.status,
        b.area,
        py.year,
        COUNT(DISTINCT je.id) as entry_count,
        SUM(CASE WHEN jel.cost_type = 'labor' THEN jel.debit_amount ELSE 0 END) as labor_cost,
        SUM(CASE WHEN jel.cost_type = 'material' THEN jel.debit_amount ELSE 0 END) as material_cost,
        SUM(CASE WHEN jel.cost_type = 'vehicle_equipment' THEN jel.debit_amount ELSE 0 END) as equipment_cost,
        SUM(CASE WHEN jel.cost_type = 'overhead' THEN jel.debit_amount ELSE 0 END) as overhead_cost,
        SUM(CASE WHEN jel.cost_type = 'other' THEN jel.debit_amount ELSE 0 END) as other_cost,
        SUM(jel.debit_amount) as total_cost,
        SUM(jel.debit_amount) / NULLIF(b.area, 0) as cost_per_ha
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    LEFT JOIN companies c ON je.company_id = c.company_id
    LEFT JOIN business_units bu ON je.business_unit_id = bu.business_unit_id
    LEFT JOIN divisions d ON je.division_id = d.division_id
    LEFT JOIN blocks b ON jel.block_id = b.block_id
    LEFT JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    WHERE $where_clause
    AND jel.debit_amount > 0
    AND b.block_id IS NOT NULL
    GROUP BY
        c.company_name, bu.unit_name, d.division_name,
        b.block_id, b.block_code, b.block_name, b.status, b.area, py.year
    ORDER BY total_cost DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$blocks = $stmt->fetchAll();

// Calculate totals
$grand_total = 0;
$total_labor = 0;
$total_material = 0;
$total_equipment = 0;
$total_overhead = 0;
$total_other = 0;
$total_area = 0;

foreach ($blocks as $block) {
    $grand_total += $block['total_cost'];
    $total_labor += $block['labor_cost'];
    $total_material += $block['material_cost'];
    $total_equipment += $block['equipment_cost'];
    $total_overhead += $block['overhead_cost'];
    $total_other += $block['other_cost'];
    $total_area += $block['area'];
}
?>

<div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4><i class="bi bi-grid-3x3"></i> Cost by Block Report</h4>
            <div>
                <button onclick="exportToExcel('cost_by_block')" class="btn btn-success btn-sm">
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
        <div class="card text-white" style="background-color: #3065b0;">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Total Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($grand_total, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-white" style="background-color: #3aafc7;">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Labor</h6>
                <h5 class="mb-0">Rp <?= number_format($total_labor, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-success text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Material</h6>
                <h5 class="mb-0">Rp <?= number_format($total_material, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-warning text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Equipment</h6>
                <h5 class="mb-0">Rp <?= number_format($total_equipment, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-secondary text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Overhead</h6>
                <h5 class="mb-0">Rp <?= number_format($total_overhead, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-dark text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Avg Cost/Ha</h6>
                <h5 class="mb-0">Rp <?= number_format($grand_total / max(1, $total_area), 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- Data Table -->
<div class="card">
    <div class="card-header text-white" style="background-color: #166c82;">
        <h5 class="mb-0"><i class="bi bi-table"></i> Cost by Block Details</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover" id="blockCostTable">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2">Company</th>
                        <th rowspan="2">Estate</th>
                        <th rowspan="2">Division</th>
                        <th rowspan="2">Block Code</th>
                        <th rowspan="2">Block Name</th>
                        <th rowspan="2">Status</th>
                        <th rowspan="2" class="text-end">Area (Ha)</th>
                        <th rowspan="2">Year</th>
                        <th colspan="5" class="text-center">Cost by Category</th>
                        <th rowspan="2" class="text-end">Total Cost</th>
                        <th rowspan="2" class="text-end">Cost/Ha</th>
                        <th rowspan="2" class="text-center">Entries</th>
                    </tr>
                    <tr>
                        <th class="text-end">Labor</th>
                        <th class="text-end">Material</th>
                        <th class="text-end">Equipment</th>
                        <th class="text-end">Overhead</th>
                        <th class="text-end">Other</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($blocks)): ?>
                        <tr>
                            <td colspan="16" class="text-center text-muted">No data available for the selected filters</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($blocks as $block): ?>
                            <tr>
                                <td><?= htmlspecialchars($block['company_name']) ?></td>
                                <td><?= htmlspecialchars($block['unit_name']) ?></td>
                                <td><?= htmlspecialchars($block['division_name']) ?></td>
                                <td><strong><?= htmlspecialchars($block['block_code']) ?></strong></td>
                                <td><?= htmlspecialchars($block['block_name']) ?></td>
                                <td>
                                    <span class="badge bg-<?= getStatusColor($block['status']) ?>">
                                        <?= htmlspecialchars($block['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end"><?= number_format($block['area'], 2) ?></td>
                                <td><?= htmlspecialchars($block['year']) ?></td>
                                <td class="text-end"><?= number_format($block['labor_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($block['material_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($block['equipment_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($block['overhead_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($block['other_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><strong>Rp <?= number_format($block['total_cost'], 0, ',', '.') ?></strong></td>
                                <td class="text-end">Rp <?= number_format($block['cost_per_ha'], 0, ',', '.') ?></td>
                                <td class="text-center"><?= $block['entry_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Grand Total Row -->
                        <tr class="table-primary fw-bold">
                            <td colspan="6">GRAND TOTAL</td>
                            <td class="text-end"><?= number_format($total_area, 2) ?></td>
                            <td></td>
                            <td class="text-end"><?= number_format($total_labor, 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($total_material, 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($total_equipment, 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($total_overhead, 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($total_other, 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($grand_total, 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($grand_total / max(1, $total_area), 0, ',', '.') ?></td>
                            <td class="text-center"><?= count($blocks) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Cost Distribution Chart -->
<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Top 20 Blocks by Total Cost</h5>
            </div>
            <div class="card-body">
                <canvas id="blockCostChart" height="80"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Block Cost Chart (Top 20)
const blockData = {
    labels: [
        <?php 
        $top20 = array_slice($blocks, 0, 20);
        foreach ($top20 as $block) {
            echo "'" . htmlspecialchars($block['block_code']) . "',";
        }
        ?>
    ],
    datasets: [
        {
            label: 'Labor',
            data: [<?php foreach ($top20 as $block) echo $block['labor_cost'] . ','; ?>],
            backgroundColor: 'rgba(54, 162, 235, 0.8)'
        },
        {
            label: 'Material',
            data: [<?php foreach ($top20 as $block) echo $block['material_cost'] . ','; ?>],
            backgroundColor: 'rgba(75, 192, 192, 0.8)'
        },
        {
            label: 'Equipment',
            data: [<?php foreach ($top20 as $block) echo $block['equipment_cost'] . ','; ?>],
            backgroundColor: 'rgba(255, 206, 86, 0.8)'
        },
        {
            label: 'Overhead',
            data: [<?php foreach ($top20 as $block) echo $block['overhead_cost'] . ','; ?>],
            backgroundColor: 'rgba(153, 102, 255, 0.8)'
        },
        {
            label: 'Other',
            data: [<?php foreach ($top20 as $block) echo $block['other_cost'] . ','; ?>],
            backgroundColor: 'rgba(201, 203, 207, 0.8)'
        }
    ]
};

new Chart(document.getElementById('blockCostChart'), {
    type: 'bar',
    data: blockData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { stacked: true },
            y: { 
                stacked: true,
                beginAtZero: true
            }
        },
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': Rp ' + context.parsed.y.toLocaleString('id-ID');
                    }
                }
            }
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
