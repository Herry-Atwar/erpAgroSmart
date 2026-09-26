<?php
// Dashboard Overview Report
// Get summary statistics

// Total costs by period
$sql_total = "
    SELECT
        SUM(jel.debit_amount) as total_cost,
        COUNT(DISTINCT je.id) as total_entries,
        COUNT(DISTINCT jel.block_id) as total_blocks,
        COUNT(DISTINCT jel.activity_id) as total_activities
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    WHERE $where_clause
    AND jel.debit_amount > 0
";
$stmt = $pdo->prepare($sql_total);
$stmt->execute($params);
$totals = $stmt->fetch();

// Cost by category
$sql_category = "
    SELECT
        jel.cost_type,
        SUM(jel.debit_amount) as total_cost,
        COUNT(DISTINCT je.id) as entry_count
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    WHERE $where_clause
    AND jel.debit_amount > 0
    AND jel.cost_type IS NOT NULL
    GROUP BY jel.cost_type
    ORDER BY total_cost DESC
";
$stmt = $pdo->prepare($sql_category);
$stmt->execute($params);
$cost_by_category = $stmt->fetchAll();

// Cost by block status
$sql_status = "
    SELECT
        b.status,
        SUM(jel.debit_amount) as total_cost,
        COUNT(DISTINCT jel.block_id) as block_count
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    LEFT JOIN blocks b ON jel.block_id = b.block_id
    WHERE $where_clause
    AND jel.debit_amount > 0
    GROUP BY b.status
    ORDER BY total_cost DESC
";
$stmt = $pdo->prepare($sql_status);
$stmt->execute($params);
$cost_by_status = $stmt->fetchAll();

// Top 10 blocks by cost
$sql_top_blocks = "
    SELECT
        b.block_code,
        b.block_name,
        b.status,
        b.area,
        SUM(jel.debit_amount) as total_cost,
        SUM(jel.debit_amount) / NULLIF(b.area, 0) as cost_per_ha
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    LEFT JOIN blocks b ON jel.block_id = b.block_id
    WHERE $where_clause
    AND jel.debit_amount > 0
    AND b.block_id IS NOT NULL
    GROUP BY b.block_id, b.block_code, b.block_name, b.status, b.area
    ORDER BY total_cost DESC
    LIMIT 10
";
$stmt = $pdo->prepare($sql_top_blocks);
$stmt->execute($params);
$top_blocks = $stmt->fetchAll();

// Top 10 activities by cost
$sql_top_activities = "
    SELECT
        a.activity_code,
        a.activity_name,
        SUM(jel.debit_amount) as total_cost,
        COUNT(DISTINCT je.id) as entry_count
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    LEFT JOIN activities a ON jel.activity_id = a.id
    WHERE $where_clause
    AND jel.debit_amount > 0
    AND a.id IS NOT NULL
    GROUP BY a.id, a.activity_code, a.activity_name
    ORDER BY total_cost DESC
    LIMIT 10
";
$stmt = $pdo->prepare($sql_top_activities);
$stmt->execute($params);
$top_activities = $stmt->fetchAll();

// Monthly trend (last 12 months)
$sql_monthly = "
    SELECT
        TO_CHAR(je.entry_date, 'YYYY-MM') as month,
        SUM(jel.debit_amount) as total_cost,
        COUNT(DISTINCT je.id) as entry_count
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    WHERE $where_clause
    AND jel.debit_amount > 0
    AND je.entry_date >= CURRENT_DATE - INTERVAL '12 months'
    GROUP BY TO_CHAR(je.entry_date, 'YYYY-MM')
    ORDER BY month
";
$stmt = $pdo->prepare($sql_monthly);
$stmt->execute($params);
$monthly_trend = $stmt->fetchAll();
?>

<div class="row mb-4">
    <!-- Summary Cards -->
    <div class="col-md-3">
        <div class="card text-white" style="background-color: #3065b0;">
            <div class="card-body">
                <h6 class="card-title">Total Cost</h6>
                <h3 class="mb-0">Rp <?= number_format($totals['total_cost'] ?? 0, 0, ',', '.') ?></h3>
                <small><?= $totals['total_entries'] ?? 0 ?> entries</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6 class="card-title">Active Blocks</h6>
                <h3 class="mb-0"><?= $totals['total_blocks'] ?? 0 ?></h3>
                <small>blocks with costs</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white" style="background-color: #3aafc7;">
            <div class="card-body">
                <h6 class="card-title">Activities</h6>
                <h3 class="mb-0"><?= $totals['total_activities'] ?? 0 ?></h3>
                <small>different activities</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h6 class="card-title">Avg Cost/Entry</h6>
                <h3 class="mb-0">Rp <?= number_format(($totals['total_cost'] ?? 0) / max(1, $totals['total_entries'] ?? 1), 0, ',', '.') ?></h3>
                <small>per journal entry</small>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <!-- Cost by Category -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Cost by Category</h5>
            </div>
            <div class="card-body">
                <div style="height: 200px;">
                    <canvas id="categoryChart"></canvas>
                </div>
                <div class="table-responsive mt-3">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cost_by_category as $cat): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-<?= getCategoryColor($cat['cost_type']) ?>">
                                            <?= ucfirst(str_replace('_', ' ', $cat['cost_type'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">Rp <?= number_format($cat['total_cost'], 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format(($cat['total_cost'] / max(1, $totals['total_cost'])) * 100, 1) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Cost by Block Status -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Cost by Block Status</h5>
            </div>
            <div class="card-body">
                <div style="height: 200px;">
                    <canvas id="statusChart"></canvas>
                </div>
                <div class="table-responsive mt-3">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Blocks</th>
                                <th class="text-end">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cost_by_status as $status): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-<?= getStatusColor($status['status']) ?>">
                                            <?= htmlspecialchars($status['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">Rp <?= number_format($status['total_cost'], 0, ',', '.') ?></td>
                                    <td class="text-end"><?= $status['block_count'] ?></td>
                                    <td class="text-end"><?= number_format(($status['total_cost'] / max(1, $totals['total_cost'])) * 100, 1) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <!-- Monthly Trend -->
    <div class="col-md-12">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-graph-up"></i> Monthly Cost Trend (Last 12 Months)</h5>
            </div>
            <div class="card-body">
                <div style="height: 150px;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <!-- Top 10 Blocks -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-trophy"></i> Top 10 Blocks by Cost</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Block</th>
                                <th>Status</th>
                                <th class="text-end">Area (Ha)</th>
                                <th class="text-end">Total Cost</th>
                                <th class="text-end">Cost/Ha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; foreach ($top_blocks as $block): ?>
                                <tr>
                                    <td><?= $rank++ ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($block['block_code']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($block['block_name']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= getStatusColor($block['status']) ?>">
                                            <?= htmlspecialchars($block['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end"><?= number_format($block['area'] ?? 0, 2) ?></td>
                                    <td class="text-end">Rp <?= number_format($block['total_cost'], 0, ',', '.') ?></td>
                                    <td class="text-end">Rp <?= number_format($block['cost_per_ha'], 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Top 10 Activities -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <h5 class="mb-0"><i class="bi bi-list-task"></i> Top 10 Activities by Cost</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Activity</th>
                                <th class="text-end">Total Cost</th>
                                <th class="text-end">Entries</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; foreach ($top_activities as $activity): ?>
                                <tr>
                                    <td><?= $rank++ ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($activity['activity_code']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($activity['activity_name']) ?></small>
                                    </td>
                                    <td class="text-end">Rp <?= number_format($activity['total_cost'], 0, ',', '.') ?></td>
                                    <td class="text-end"><?= $activity['entry_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Category Chart
const categoryData = {
    labels: [<?php foreach ($cost_by_category as $cat) echo "'" . ucfirst(str_replace('_', ' ', $cat['cost_type'])) . "',"; ?>],
    datasets: [{
        data: [<?php foreach ($cost_by_category as $cat) echo $cat['total_cost'] . ','; ?>],
        backgroundColor: [
            'rgba(255, 99, 132, 0.8)',
            'rgba(54, 162, 235, 0.8)',
            'rgba(255, 206, 86, 0.8)',
            'rgba(75, 192, 192, 0.8)',
            'rgba(153, 102, 255, 0.8)'
        ]
    }]
};

new Chart(document.getElementById('categoryChart'), {
    type: 'doughnut',
    data: categoryData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// Status Chart
const statusData = {
    labels: [<?php foreach ($cost_by_status as $status) echo "'" . htmlspecialchars($status['status']) . "',"; ?>],
    datasets: [{
        label: 'Cost by Status',
        data: [<?php foreach ($cost_by_status as $status) echo $status['total_cost'] . ','; ?>],
        backgroundColor: 'rgba(54, 162, 235, 0.8)',
        borderColor: 'rgba(54, 162, 235, 1)',
        borderWidth: 1
    }]
};

new Chart(document.getElementById('statusChart'), {
    type: 'bar',
    data: statusData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// Trend Chart
const trendData = {
    labels: [<?php foreach ($monthly_trend as $month) echo "'" . $month['month'] . "',"; ?>],
    datasets: [{
        label: 'Monthly Cost',
        data: [<?php foreach ($monthly_trend as $month) echo $month['total_cost'] . ','; ?>],
        borderColor: 'rgba(75, 192, 192, 1)',
        backgroundColor: 'rgba(75, 192, 192, 0.2)',
        tension: 0.4,
        fill: true
    }]
};

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: trendData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: { beginAtZero: true }
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
