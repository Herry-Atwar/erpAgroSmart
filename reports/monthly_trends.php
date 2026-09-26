<?php
// Monthly Trends Report

// Build parameters for this specific query
$trend_params = [];
$trend_conditions = ["je.status = 'posted'", "jel.debit_amount > 0", "je.entry_date >= CURRENT_DATE - INTERVAL '12 months'"];

if ($company_id) {
    $trend_conditions[] = "je.company_id = :company_id";
    $trend_params[':company_id'] = $company_id;
}
if ($estate_id) {
    $trend_conditions[] = "je.business_unit_id = :estate_id";
    $trend_params[':estate_id'] = $estate_id;
}
if ($division_id) {
    $trend_conditions[] = "je.division_id = :division_id";
    $trend_params[':division_id'] = $division_id;
}
if ($block_id) {
    $trend_conditions[] = "jel.block_id = :block_id";
    $trend_params[':block_id'] = $block_id;
}
if ($activity_id) {
    $trend_conditions[] = "jel.activity_id = :activity_id";
    $trend_params[':activity_id'] = $activity_id;
}
if ($cost_category) {
    $trend_conditions[] = "jel.cost_type = :cost_category";
    $trend_params[':cost_category'] = $cost_category;
}
if ($status_filter) {
    $trend_conditions[] = "b.status = :status";
    $trend_params[':status'] = $status_filter;
}

// Get monthly data for the last 12 months
$sql = "
    SELECT
        TO_CHAR(je.entry_date, 'YYYY-MM') as month,
        TO_CHAR(je.entry_date, 'Mon YYYY') as month_label,
        COUNT(DISTINCT je.id) as entry_count,
        COUNT(DISTINCT jel.block_id) as block_count,
        SUM(CASE WHEN jel.cost_type = 'labor' THEN jel.debit_amount ELSE 0 END) as labor_cost,
        SUM(CASE WHEN jel.cost_type = 'material' THEN jel.debit_amount ELSE 0 END) as material_cost,
        SUM(CASE WHEN jel.cost_type = 'vehicle_equipment' THEN jel.debit_amount ELSE 0 END) as equipment_cost,
        SUM(CASE WHEN jel.cost_type = 'overhead' THEN jel.debit_amount ELSE 0 END) as overhead_cost,
        SUM(CASE WHEN jel.cost_type = 'other' THEN jel.debit_amount ELSE 0 END) as other_cost,
        SUM(jel.debit_amount) as total_cost
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    LEFT JOIN blocks b ON jel.block_id = b.block_id
    WHERE " . implode(' AND ', $trend_conditions) . "
    GROUP BY TO_CHAR(je.entry_date, 'YYYY-MM'), TO_CHAR(je.entry_date, 'Mon YYYY')
    ORDER BY month
";

$stmt = $pdo->prepare($sql);
$stmt->execute($trend_params);
$monthly_data = $stmt->fetchAll();

// Calculate statistics
$total_cost = array_sum(array_column($monthly_data, 'total_cost'));
$avg_monthly_cost = count($monthly_data) > 0 ? $total_cost / count($monthly_data) : 0;
$max_month = !empty($monthly_data) ? max(array_column($monthly_data, 'total_cost')) : 0;
$min_month = !empty($monthly_data) ? min(array_column($monthly_data, 'total_cost')) : 0;

// Calculate month-over-month growth
$mom_growth = [];
for ($i = 1; $i < count($monthly_data); $i++) {
    $prev = $monthly_data[$i-1]['total_cost'];
    $curr = $monthly_data[$i]['total_cost'];
    $growth = $prev > 0 ? (($curr - $prev) / $prev) * 100 : 0;
    $mom_growth[] = [
        'month' => $monthly_data[$i]['month_label'],
        'growth' => $growth
    ];
}

// Get activity trends
$sql_activity = "
    SELECT
        TO_CHAR(je.entry_date, 'YYYY-MM') as month,
        a.activity_code,
        a.activity_name,
        SUM(jel.debit_amount) as total_cost
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    LEFT JOIN activities a ON jel.activity_id = a.id
    LEFT JOIN blocks b ON jel.block_id = b.block_id
    WHERE je.status = 'posted'
    AND jel.debit_amount > 0
    AND je.entry_date >= CURRENT_DATE - INTERVAL '12 months'
    AND a.id IS NOT NULL
    " . ($company_id ? "AND je.company_id = :company_id" : "") . "
    " . ($estate_id ? "AND je.business_unit_id = :estate_id" : "") . "
    " . ($division_id ? "AND je.division_id = :division_id" : "") . "
    " . ($block_id ? "AND jel.block_id = :block_id" : "") . "
    " . ($activity_id ? "AND jel.activity_id = :activity_id" : "") . "
    " . ($cost_category ? "AND jel.cost_type = :cost_category" : "") . "
    " . ($status_filter ? "AND b.status = :status" : "") . "
    GROUP BY TO_CHAR(je.entry_date, 'YYYY-MM'), a.id, a.activity_code, a.activity_name
    ORDER BY month, total_cost DESC
";

$stmt = $pdo->prepare($sql_activity);
$stmt->execute($trend_params);
$activity_trends = $stmt->fetchAll();

// Organize by month
$activity_by_month = [];
foreach ($activity_trends as $row) {
    if (!isset($activity_by_month[$row['month']])) {
        $activity_by_month[$row['month']] = [];
    }
    $activity_by_month[$row['month']][] = $row;
}
?>

<div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4><i class="bi bi-graph-up"></i> Monthly Cost Trends (Last 12 Months)</h4>
            <div>
                <button onclick="exportToExcel('monthly_trends')" class="btn btn-success btn-sm">
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
                <h6 class="card-title mb-1">Total Cost (12M)</h6>
                <h5 class="mb-0">Rp <?= number_format($total_cost, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Avg Monthly Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($avg_monthly_cost, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Highest Month</h6>
                <h5 class="mb-0">Rp <?= number_format($max_month, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Lowest Month</h6>
                <h5 class="mb-0">Rp <?= number_format($min_month, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Data Table -->
<div class="card mb-4">
    <div class="card-header text-white" style="background-color: #166c82;">
        <h5 class="mb-0"><i class="bi bi-table"></i> Monthly Cost Breakdown</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Month</th>
                        <th class="text-end">Labor</th>
                        <th class="text-end">Material</th>
                        <th class="text-end">Equipment</th>
                        <th class="text-end">Overhead</th>
                        <th class="text-end">Other</th>
                        <th class="text-end">Total Cost</th>
                        <th class="text-end">MoM Growth</th>
                        <th class="text-center">Entries</th>
                        <th class="text-center">Blocks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthly_data)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No data available</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $prev_cost = 0;
                        foreach ($monthly_data as $row): 
                            $growth = $prev_cost > 0 ? (($row['total_cost'] - $prev_cost) / $prev_cost) * 100 : 0;
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['month_label']) ?></strong></td>
                                <td class="text-end"><?= number_format($row['labor_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($row['material_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($row['equipment_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($row['overhead_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($row['other_cost'], 0, ',', '.') ?></td>
                                <td class="text-end"><strong>Rp <?= number_format($row['total_cost'], 0, ',', '.') ?></strong></td>
                                <td class="text-end">
                                    <?php if ($prev_cost > 0): ?>
                                        <span class="badge bg-<?= $growth >= 0 ? 'danger' : 'success' ?>">
                                            <?= $growth >= 0 ? '+' : '' ?><?= number_format($growth, 1) ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= $row['entry_count'] ?></td>
                                <td class="text-center"><?= $row['block_count'] ?></td>
                            </tr>
                        <?php 
                            $prev_cost = $row['total_cost'];
                        endforeach; 
                        ?>
                        <!-- Average Row -->
                        <tr class="table-primary fw-bold">
                            <td>AVERAGE</td>
                            <td class="text-end"><?= number_format(array_sum(array_column($monthly_data, 'labor_cost')) / count($monthly_data), 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format(array_sum(array_column($monthly_data, 'material_cost')) / count($monthly_data), 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format(array_sum(array_column($monthly_data, 'equipment_cost')) / count($monthly_data), 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format(array_sum(array_column($monthly_data, 'overhead_cost')) / count($monthly_data), 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format(array_sum(array_column($monthly_data, 'other_cost')) / count($monthly_data), 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($avg_monthly_cost, 0, ',', '.') ?></td>
                            <td colspan="3"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-graph-up"></i> Total Cost Trend</h5>
            </div>
            <div class="card-body">
                <canvas id="totalTrendChart" height="80"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-layers"></i> Cost by Category Trend</h5>
            </div>
            <div class="card-body">
                <canvas id="categoryTrendChart" height="80"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-arrow-up-right"></i> Month-over-Month Growth</h5>
            </div>
            <div class="card-body">
                <canvas id="growthChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Average Cost by Category</h5>
            </div>
            <div class="card-body">
                <canvas id="avgCategoryChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
const monthlyData = <?= json_encode($monthly_data) ?>;

// Total Trend Chart
const totalTrendData = {
    labels: monthlyData.map(m => m.month_label),
    datasets: [{
        label: 'Total Cost',
        data: monthlyData.map(m => m.total_cost),
        borderColor: 'rgba(54, 162, 235, 1)',
        backgroundColor: 'rgba(54, 162, 235, 0.2)',
        tension: 0.4,
        fill: true
    }]
};

new Chart(document.getElementById('totalTrendChart'), {
    type: 'line',
    data: totalTrendData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Cost: Rp ' + context.parsed.y.toLocaleString('id-ID');
                    }
                }
            }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// Category Trend Chart
const categoryTrendData = {
    labels: monthlyData.map(m => m.month_label),
    datasets: [
        {
            label: 'Labor',
            data: monthlyData.map(m => m.labor_cost),
            borderColor: 'rgba(54, 162, 235, 1)',
            backgroundColor: 'rgba(54, 162, 235, 0.2)',
            tension: 0.4
        },
        {
            label: 'Material',
            data: monthlyData.map(m => m.material_cost),
            borderColor: 'rgba(75, 192, 192, 1)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.4
        },
        {
            label: 'Equipment',
            data: monthlyData.map(m => m.equipment_cost),
            borderColor: 'rgba(255, 206, 86, 1)',
            backgroundColor: 'rgba(255, 206, 86, 0.2)',
            tension: 0.4
        },
        {
            label: 'Overhead',
            data: monthlyData.map(m => m.overhead_cost),
            borderColor: 'rgba(153, 102, 255, 1)',
            backgroundColor: 'rgba(153, 102, 255, 0.2)',
            tension: 0.4
        },
        {
            label: 'Other',
            data: monthlyData.map(m => m.other_cost),
            borderColor: 'rgba(201, 203, 207, 1)',
            backgroundColor: 'rgba(201, 203, 207, 0.2)',
            tension: 0.4
        }
    ]
};

new Chart(document.getElementById('categoryTrendChart'), {
    type: 'line',
    data: categoryTrendData,
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

// Growth Chart
const growthData = monthlyData.slice(1).map((m, i) => {
    const prev = monthlyData[i].total_cost;
    return prev > 0 ? ((m.total_cost - prev) / prev) * 100 : 0;
});

new Chart(document.getElementById('growthChart'), {
    type: 'bar',
    data: {
        labels: monthlyData.slice(1).map(m => m.month_label),
        datasets: [{
            label: 'MoM Growth %',
            data: growthData,
            backgroundColor: growthData.map(g => g >= 0 ? 'rgba(255, 99, 132, 0.8)' : 'rgba(75, 192, 192, 0.8)'),
            borderColor: growthData.map(g => g >= 0 ? 'rgba(255, 99, 132, 1)' : 'rgba(75, 192, 192, 1)'),
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        }
    }
});

// Average Category Chart
const avgData = {
    labels: ['Labor', 'Material', 'Equipment', 'Overhead', 'Other'],
    datasets: [{
        data: [
            monthlyData.reduce((sum, m) => sum + parseFloat(m.labor_cost), 0) / monthlyData.length,
            monthlyData.reduce((sum, m) => sum + parseFloat(m.material_cost), 0) / monthlyData.length,
            monthlyData.reduce((sum, m) => sum + parseFloat(m.equipment_cost), 0) / monthlyData.length,
            monthlyData.reduce((sum, m) => sum + parseFloat(m.overhead_cost), 0) / monthlyData.length,
            monthlyData.reduce((sum, m) => sum + parseFloat(m.other_cost), 0) / monthlyData.length
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

new Chart(document.getElementById('avgCategoryChart'), {
    type: 'doughnut',
    data: avgData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});
</script>

// Made with Bob
