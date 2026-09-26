<?php
// Cost by Category Report

$sql = "
    SELECT
        jel.cost_type,
        COUNT(DISTINCT je.id) as entry_count,
        COUNT(DISTINCT jel.block_id) as block_count,
        COUNT(DISTINCT jel.activity_id) as activity_count,
        SUM(CASE WHEN b.status = 'LC' THEN jel.debit_amount ELSE 0 END) as lc_cost,
        SUM(CASE WHEN b.status = 'TBM' THEN jel.debit_amount ELSE 0 END) as tbm_cost,
        SUM(CASE WHEN b.status = 'TM' THEN jel.debit_amount ELSE 0 END) as tm_cost,
        SUM(jel.debit_amount) as total_cost
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    LEFT JOIN blocks b ON jel.block_id = b.block_id
    WHERE $where_clause
    AND jel.debit_amount > 0
    AND jel.cost_type IS NOT NULL
    GROUP BY jel.cost_type
    ORDER BY total_cost DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll();

// Calculate totals
$grand_total = 0;
$total_lc = 0;
$total_tbm = 0;
$total_tm = 0;

foreach ($categories as $cat) {
    $grand_total += $cat['total_cost'];
    $total_lc += $cat['lc_cost'];
    $total_tbm += $cat['tbm_cost'];
    $total_tm += $cat['tm_cost'];
}

// Get monthly trend by category
$sql_trend = "
    SELECT
        TO_CHAR(je.entry_date, 'YYYY-MM') as month,
        jel.cost_type,
        SUM(jel.debit_amount) as total_cost
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    WHERE $where_clause
    AND jel.debit_amount > 0
    AND jel.cost_type IS NOT NULL
    AND je.entry_date >= CURRENT_DATE - INTERVAL '12 months'
    GROUP BY TO_CHAR(je.entry_date, 'YYYY-MM'), jel.cost_type
    ORDER BY month, jel.cost_type
";

$stmt = $pdo->prepare($sql_trend);
$stmt->execute($params);
$trend_data = $stmt->fetchAll();

// Organize trend data by month and category
$months = [];
$trend_by_month = [];
foreach ($trend_data as $row) {
    if (!in_array($row['month'], $months)) {
        $months[] = $row['month'];
    }
    if (!isset($trend_by_month[$row['month']])) {
        $trend_by_month[$row['month']] = [];
    }
    $trend_by_month[$row['month']][$row['cost_type']] = $row['total_cost'];
}
?>

<div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4><i class="bi bi-pie-chart"></i> Cost by Category Report</h4>
            <div>
                <button onclick="exportToExcel('cost_by_category')" class="btn btn-success btn-sm">
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
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Total Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($grand_total, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">LC Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($total_lc, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">TBM Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($total_tbm, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">TM Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($total_tm, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- Data Table -->
<div class="card mb-4">
    <div class="card-header text-white" style="background-color: #166c82;">
        <h5 class="mb-0"><i class="bi bi-table"></i> Cost by Category Details</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Cost Category</th>
                        <th class="text-end">LC Cost</th>
                        <th class="text-end">TBM Cost</th>
                        <th class="text-end">TM Cost</th>
                        <th class="text-end">Total Cost</th>
                        <th class="text-end">% of Total</th>
                        <th class="text-center">Entries</th>
                        <th class="text-center">Blocks</th>
                        <th class="text-center">Activities</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">No data available for the selected filters</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-<?= getCategoryColor($cat['cost_type']) ?> fs-6">
                                        <i class="bi bi-<?= getCategoryIcon($cat['cost_type']) ?>"></i>
                                        <?= ucfirst(str_replace('_', ' ', $cat['cost_type'])) ?>
                                    </span>
                                </td>
                                <td class="text-end">Rp <?= number_format($cat['lc_cost'], 0, ',', '.') ?></td>
                                <td class="text-end">Rp <?= number_format($cat['tbm_cost'], 0, ',', '.') ?></td>
                                <td class="text-end">Rp <?= number_format($cat['tm_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><strong>Rp <?= number_format($cat['total_cost'], 0, ',', '.') ?></strong></td>
                                <td class="text-end">
                                    <strong><?= number_format(($cat['total_cost'] / max(1, $grand_total)) * 100, 1) ?>%</strong>
                                </td>
                                <td class="text-center"><?= $cat['entry_count'] ?></td>
                                <td class="text-center"><?= $cat['block_count'] ?></td>
                                <td class="text-center"><?= $cat['activity_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Grand Total Row -->
                        <tr class="table-primary fw-bold">
                            <td>GRAND TOTAL</td>
                            <td class="text-end">Rp <?= number_format($total_lc, 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($total_tbm, 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($total_tm, 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($grand_total, 0, ',', '.') ?></td>
                            <td class="text-end">100.0%</td>
                            <td colspan="3" class="text-center"><?= count($categories) ?> categories</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Cost Distribution</h5>
            </div>
            <div class="card-body">
                <canvas id="categoryPieChart" height="300"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Cost by Block Status</h5>
            </div>
            <div class="card-body">
                <canvas id="statusBarChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-graph-up"></i> Monthly Trend by Category (Last 12 Months)</h5>
            </div>
            <div class="card-body">
                <canvas id="trendChart" height="80"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Pie Chart
const pieData = {
    labels: [<?php foreach ($categories as $cat) echo "'" . ucfirst(str_replace('_', ' ', $cat['cost_type'])) . "',"; ?>],
    datasets: [{
        data: [<?php foreach ($categories as $cat) echo $cat['total_cost'] . ','; ?>],
        backgroundColor: [
            'rgba(54, 162, 235, 0.8)',
            'rgba(75, 192, 192, 0.8)',
            'rgba(255, 206, 86, 0.8)',
            'rgba(153, 102, 255, 0.8)',
            'rgba(201, 203, 207, 0.8)'
        ]
    }]
};

new Chart(document.getElementById('categoryPieChart'), {
    type: 'pie',
    data: pieData,
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

// Bar Chart by Status
const barData = {
    labels: [<?php foreach ($categories as $cat) echo "'" . ucfirst(str_replace('_', ' ', $cat['cost_type'])) . "',"; ?>],
    datasets: [
        {
            label: 'LC',
            data: [<?php foreach ($categories as $cat) echo $cat['lc_cost'] . ','; ?>],
            backgroundColor: 'rgba(255, 206, 86, 0.8)'
        },
        {
            label: 'TBM',
            data: [<?php foreach ($categories as $cat) echo $cat['tbm_cost'] . ','; ?>],
            backgroundColor: 'rgba(54, 162, 235, 0.8)'
        },
        {
            label: 'TM',
            data: [<?php foreach ($categories as $cat) echo $cat['tm_cost'] . ','; ?>],
            backgroundColor: 'rgba(75, 192, 192, 0.8)'
        }
    ]
};

new Chart(document.getElementById('statusBarChart'), {
    type: 'bar',
    data: barData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true }
        },
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// Trend Chart
const trendData = {
    labels: <?= json_encode($months) ?>,
    datasets: [
        {
            label: 'Labor',
            data: <?= json_encode(array_map(function($m) use ($trend_by_month) { return $trend_by_month[$m]['labor'] ?? 0; }, $months)) ?>,
            borderColor: 'rgba(54, 162, 235, 1)',
            backgroundColor: 'rgba(54, 162, 235, 0.2)',
            tension: 0.4
        },
        {
            label: 'Material',
            data: <?= json_encode(array_map(function($m) use ($trend_by_month) { return $trend_by_month[$m]['material'] ?? 0; }, $months)) ?>,
            borderColor: 'rgba(75, 192, 192, 1)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.4
        },
        {
            label: 'Equipment',
            data: <?= json_encode(array_map(function($m) use ($trend_by_month) { return $trend_by_month[$m]['vehicle_equipment'] ?? 0; }, $months)) ?>,
            borderColor: 'rgba(255, 206, 86, 1)',
            backgroundColor: 'rgba(255, 206, 86, 0.2)',
            tension: 0.4
        },
        {
            label: 'Overhead',
            data: <?= json_encode(array_map(function($m) use ($trend_by_month) { return $trend_by_month[$m]['overhead'] ?? 0; }, $months)) ?>,
            borderColor: 'rgba(153, 102, 255, 1)',
            backgroundColor: 'rgba(153, 102, 255, 0.2)',
            tension: 0.4
        },
        {
            label: 'Other',
            data: <?= json_encode(array_map(function($m) use ($trend_by_month) { return $trend_by_month[$m]['other'] ?? 0; }, $months)) ?>,
            borderColor: 'rgba(201, 203, 207, 1)',
            backgroundColor: 'rgba(201, 203, 207, 0.2)',
            tension: 0.4
        }
    ]
};

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: trendData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { beginAtZero: true }
        },
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});
</script>

<?php
function getCategoryColor($category) {
    $colors = [
        'labor' => 'primary',
        'material' => 'success',
        'vehicle_equipment' => 'warning',
        'overhead' => 'info',
        'other' => 'secondary'
    ];
    return $colors[$category] ?? 'secondary';
}

function getCategoryIcon($category) {
    $icons = [
        'labor' => 'people',
        'material' => 'box-seam',
        'vehicle_equipment' => 'truck',
        'overhead' => 'building',
        'other' => 'three-dots'
    ];
    return $icons[$category] ?? 'circle';
}
?>

// Made with Bob
