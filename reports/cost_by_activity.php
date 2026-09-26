<?php
// Cost by Activity Report

$sql = "
    SELECT
        a.activity_code,
        a.activity_name,
        COUNT(DISTINCT je.id) as entry_count,
        COUNT(DISTINCT jel.block_id) as block_count,
        SUM(CASE WHEN jel.cost_type = 'labor' THEN jel.debit_amount ELSE 0 END) as labor_cost,
        SUM(CASE WHEN jel.cost_type = 'material' THEN jel.debit_amount ELSE 0 END) as material_cost,
        SUM(CASE WHEN jel.cost_type = 'vehicle_equipment' THEN jel.debit_amount ELSE 0 END) as equipment_cost,
        SUM(CASE WHEN jel.cost_type = 'overhead' THEN jel.debit_amount ELSE 0 END) as overhead_cost,
        SUM(CASE WHEN jel.cost_type = 'other' THEN jel.debit_amount ELSE 0 END) as other_cost,
        SUM(jel.debit_amount) as total_cost,
        SUM(CASE WHEN b.status = 'LC' THEN jel.debit_amount ELSE 0 END) as lc_cost,
        SUM(CASE WHEN b.status = 'TBM' THEN jel.debit_amount ELSE 0 END) as tbm_cost,
        SUM(CASE WHEN b.status = 'TM' THEN jel.debit_amount ELSE 0 END) as tm_cost
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    LEFT JOIN activities a ON jel.activity_id = a.id
    LEFT JOIN blocks b ON jel.block_id = b.block_id
    WHERE $where_clause
    AND jel.debit_amount > 0
    AND a.id IS NOT NULL
    GROUP BY a.id, a.activity_code, a.activity_name
    ORDER BY total_cost DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

// Calculate totals
$grand_total = 0;
$total_labor = 0;
$total_material = 0;
$total_equipment = 0;
$total_overhead = 0;
$total_other = 0;
$total_lc = 0;
$total_tbm = 0;
$total_tm = 0;

foreach ($activities as $activity) {
    $grand_total += $activity['total_cost'];
    $total_labor += $activity['labor_cost'];
    $total_material += $activity['material_cost'];
    $total_equipment += $activity['equipment_cost'];
    $total_overhead += $activity['overhead_cost'];
    $total_other += $activity['other_cost'];
    $total_lc += $activity['lc_cost'];
    $total_tbm += $activity['tbm_cost'];
    $total_tm += $activity['tm_cost'];
}
?>

<div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4><i class="bi bi-list-task"></i> Cost by Activity Report</h4>
            <div>
                <button onclick="exportToExcel('cost_by_activity')" class="btn btn-success btn-sm">
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
                <h6 class="card-title mb-1">Total Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($grand_total, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-info text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Activities</h6>
                <h5 class="mb-0"><?= count($activities) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-warning text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">LC Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($total_lc, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-info text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">TBM Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($total_tbm, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-success text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">TM Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($total_tm, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-dark text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Avg/Activity</h6>
                <h5 class="mb-0">Rp <?= number_format($grand_total / max(1, count($activities)), 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- Data Table -->
<div class="card">
    <div class="card-header text-white" style="background-color: #166c82;">
        <h5 class="mb-0"><i class="bi bi-table"></i> Cost by Activity Details</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover" id="activityCostTable">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2">Activity Code</th>
                        <th rowspan="2">Activity Name</th>
                        <th colspan="5" class="text-center">Cost by Category</th>
                        <th rowspan="2" class="text-end">Total Cost</th>
                        <th colspan="3" class="text-center">Cost by Block Status</th>
                        <th rowspan="2" class="text-center">Entries</th>
                        <th rowspan="2" class="text-center">Blocks</th>
                    </tr>
                    <tr>
                        <th class="text-end">Labor</th>
                        <th class="text-end">Material</th>
                        <th class="text-end">Equipment</th>
                        <th class="text-end">Overhead</th>
                        <th class="text-end">Other</th>
                        <th class="text-end">LC</th>
                        <th class="text-end">TBM</th>
                        <th class="text-end">TM</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activities)): ?>
                        <tr>
                            <td colspan="13" class="text-center text-muted">No data available for the selected filters</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($activity['activity_code']) ?></strong></td>
                                <td><?= htmlspecialchars($activity['activity_name']) ?></td>
                                <td class="text-end"><?= number_format($activity['labor_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($activity['material_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($activity['equipment_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($activity['overhead_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($activity['other_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><strong>Rp <?= number_format($activity['total_cost'], 0, ',', '.') ?></strong></td>
                                <td class="text-end"><?= number_format($activity['lc_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($activity['tbm_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($activity['tm_cost'], 0, ',', '.') ?></td>
                                <td class="text-center"><?= $activity['entry_count'] ?></td>
                                <td class="text-center"><?= $activity['block_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Grand Total Row -->
                        <tr class="table-primary fw-bold">
                            <td colspan="2">GRAND TOTAL</td>
                            <td class="text-end"><?= number_format($total_labor, 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($total_material, 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($total_equipment, 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($total_overhead, 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($total_other, 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($grand_total, 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($total_lc, 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($total_tbm, 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($total_tm, 0, ',', '.') ?></td>
                            <td colspan="2" class="text-center"><?= count($activities) ?> activities</td>
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
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Top 10 Activities by Cost</h5>
            </div>
            <div class="card-body">
                <canvas id="topActivitiesChart" height="300"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Cost Distribution by Category</h5>
            </div>
            <div class="card-body">
                <canvas id="categoryDistChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Top 10 Activities Chart
const top10 = <?= json_encode(array_slice($activities, 0, 10)) ?>;
const topActivitiesData = {
    labels: top10.map(a => a.activity_code),
    datasets: [{
        label: 'Total Cost',
        data: top10.map(a => a.total_cost),
        backgroundColor: 'rgba(54, 162, 235, 0.8)',
        borderColor: 'rgba(54, 162, 235, 1)',
        borderWidth: 1
    }]
};

new Chart(document.getElementById('topActivitiesChart'), {
    type: 'bar',
    data: topActivitiesData,
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Rp ' + context.parsed.x.toLocaleString('id-ID');
                    }
                }
            }
        },
        scales: {
            x: { beginAtZero: true }
        }
    }
});

// Category Distribution Chart
const categoryData = {
    labels: ['Labor', 'Material', 'Equipment', 'Overhead', 'Other'],
    datasets: [{
        data: [
            <?= $total_labor ?>,
            <?= $total_material ?>,
            <?= $total_equipment ?>,
            <?= $total_overhead ?>,
            <?= $total_other ?>
        ],
        backgroundColor: [
            'rgba(54, 162, 235, 0.8)',
            'rgba(75, 192, 192, 0.8)',
            'rgba(255, 206, 86, 0.8)',
            'rgba(153, 102, 255, 0.8)',
            'rgba(201, 203, 207, 0.8)'
        ]
    }]
};

new Chart(document.getElementById('categoryDistChart'), {
    type: 'doughnut',
    data: categoryData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((context.parsed / total) * 100).toFixed(1);
                        return context.label + ': Rp ' + context.parsed.toLocaleString('id-ID') + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});
</script>

// Made with Bob
