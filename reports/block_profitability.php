<?php
// Block Profitability Report
// This report shows revenue vs cost for mature (TM) blocks

// Get costs by block
$sql_costs = "
    SELECT
        b.block_id,
        b.block_code,
        b.block_name,
        b.area,
        bu.unit_name,
        d.division_name,
        SUM(jel.debit_amount) as total_cost,
        SUM(jel.debit_amount) / NULLIF(b.area, 0) as cost_per_ha
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    LEFT JOIN blocks b ON jel.block_id = b.block_id
    LEFT JOIN business_units bu ON je.business_unit_id = bu.business_unit_id
    LEFT JOIN divisions d ON je.division_id = d.division_id
    WHERE $where_clause
    AND jel.debit_amount > 0
    AND b.block_id IS NOT NULL
    AND b.status = 'TM'
    GROUP BY b.block_id, b.block_code, b.block_name, b.area, bu.unit_name, d.division_name
";

$stmt = $pdo->prepare($sql_costs);
$stmt->execute($params);
$block_costs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get revenue by block (from harvest realizations)
// Note: Using average FFB price of Rp 2,500/kg (adjust as needed)
$ffb_price_per_kg = 2500; // Default FFB price in IDR
$sql_revenue = "
    SELECT
        hr.block_id,
        SUM(hr.actual_quantity_kg) * $ffb_price_per_kg as total_revenue,
        SUM(hr.actual_quantity_kg) as total_quantity_kg
    FROM harvest_realizations hr
    WHERE hr.harvest_date BETWEEN :date_from AND :date_to
    " . ($block_id ? "AND hr.block_id = :block_id" : "") . "
    GROUP BY hr.block_id
";

$revenue_params = [':date_from' => $date_from, ':date_to' => $date_to];
if ($block_id) {
    $revenue_params[':block_id'] = $block_id;
}

$stmt = $pdo->prepare($sql_revenue);
$stmt->execute($revenue_params);
$block_revenues = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create revenue lookup
$revenue_lookup = [];
foreach ($block_revenues as $rev) {
    $revenue_lookup[$rev['block_id']] = [
        'revenue' => $rev['total_revenue'],
        'quantity' => $rev['total_quantity_kg']
    ];
}

// Combine costs and revenues
$profitability = [];
$total_revenue = 0;
$total_cost = 0;
$total_profit = 0;
$total_area = 0;

foreach ($block_costs as $cost) {
    $block_id_val = $cost['block_id'];
    $revenue = $revenue_lookup[$block_id_val]['revenue'] ?? 0;
    $quantity = $revenue_lookup[$block_id_val]['quantity'] ?? 0;
    $profit = $revenue - $cost['total_cost'];
    $profit_margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
    $revenue_per_ha = $cost['area'] ?? 0 > 0 ? $revenue / $cost['area'] ?? 0 : 0;
    $profit_per_ha = $cost['area'] ?? 0 > 0 ? $profit / $cost['area'] ?? 0 : 0;
    
    $profitability[] = [
        'block_code' => $cost['block_code'],
        'block_name' => $cost['block_name'],
        'estate_name' => $cost['unit_name'] ?? 'N/A',
        'division_name' => $cost['division_name'],
        'area_ha' => $cost['area'] ?? 0,
        'revenue' => $revenue,
        'cost' => $cost['total_cost'],
        'profit' => $profit,
        'profit_margin' => $profit_margin,
        'quantity_kg' => $quantity,
        'revenue_per_ha' => $revenue_per_ha,
        'cost_per_ha' => $cost['cost_per_ha'],
        'profit_per_ha' => $profit_per_ha
    ];
    
    $total_revenue += $revenue;
    $total_cost += $cost['total_cost'];
    $total_profit += $profit;
    $total_area += $cost['area'] ?? 0;
}

// Sort by profit descending
usort($profitability, function($a, $b) {
    return $b['profit'] <=> $a['profit'];
});

$avg_profit_margin = $total_revenue > 0 ? ($total_profit / $total_revenue) * 100 : 0;
?>

<div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4><i class="bi bi-currency-dollar"></i> Block Profitability Report (TM Blocks Only)</h4>
            <div>
                <button onclick="exportToExcel('block_profitability')" class="btn btn-success btn-sm">
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
        <div class="card bg-success text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Total Revenue</h6>
                <h5 class="mb-0">Rp <?= number_format($total_revenue, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-danger text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Total Cost</h6>
                <h5 class="mb-0">Rp <?= number_format($total_cost, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-<?= $total_profit >= 0 ? 'primary' : 'warning' ?> text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Net Profit</h6>
                <h5 class="mb-0">Rp <?= number_format($total_profit, 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-info text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Profit Margin</h6>
                <h5 class="mb-0"><?= number_format($avg_profit_margin, 1) ?>%</h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-secondary text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Avg Profit/Ha</h6>
                <h5 class="mb-0">Rp <?= number_format($total_profit / max(1, $total_area), 0, ',', '.') ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-dark text-white">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Total Area</h6>
                <h5 class="mb-0"><?= number_format($total_area, 2) ?> Ha</h5>
            </div>
        </div>
    </div>
</div>

<!-- Data Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Estate</th>
                        <th>Division</th>
                        <th>Block Code</th>
                        <th>Block Name</th>
                        <th class="text-end">Area (Ha)</th>
                        <th class="text-end">FFB (Kg)</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Profit</th>
                        <th class="text-end">Margin %</th>
                        <th class="text-end">Revenue/Ha</th>
                        <th class="text-end">Cost/Ha</th>
                        <th class="text-end">Profit/Ha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($profitability)): ?>
                        <tr>
                            <td colspan="13" class="text-center text-muted">No data available for the selected filters</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($profitability as $row): ?>
                            <tr class="<?= $row['profit'] < 0 ? 'table-danger' : '' ?>">
                                <td><?= htmlspecialchars($row['unit_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['division_name']) ?></td>
                                <td><strong><?= htmlspecialchars($row['block_code']) ?></strong></td>
                                <td><?= htmlspecialchars($row['block_name']) ?></td>
                                <td class="text-end"><?= number_format($row['area'] ?? 0, 2) ?></td>
                                <td class="text-end"><?= number_format($row['quantity_kg'], 0, ',', '.') ?></td>
                                <td class="text-end text-success">Rp <?= number_format($row['revenue'], 0, ',', '.') ?></td>
                                <td class="text-end text-danger">Rp <?= number_format($row['cost'], 0, ',', '.') ?></td>
                                <td class="text-end <?= $row['profit'] >= 0 ? 'text-primary fw-bold' : 'text-danger fw-bold' ?>">
                                    Rp <?= number_format($row['profit'], 0, ',', '.') ?>
                                </td>
                                <td class="text-end <?= $row['profit_margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= number_format($row['profit_margin'], 1) ?>%
                                </td>
                                <td class="text-end">Rp <?= number_format($row['revenue_per_ha'], 0, ',', '.') ?></td>
                                <td class="text-end">Rp <?= number_format($row['cost_per_ha'], 0, ',', '.') ?></td>
                                <td class="text-end <?= $row['profit_per_ha'] >= 0 ? 'text-primary' : 'text-danger' ?>">
                                    Rp <?= number_format($row['profit_per_ha'], 0, ',', '.') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Grand Total Row -->
                        <tr class="table-primary fw-bold">
                            <td colspan="4">GRAND TOTAL</td>
                            <td class="text-end"><?= number_format($total_area, 2) ?></td>
                            <td class="text-end"><?= number_format(array_sum(array_column($profitability, 'quantity_kg')), 0, ',', '.') ?></td>
                            <td class="text-end text-success">Rp <?= number_format($total_revenue, 0, ',', '.') ?></td>
                            <td class="text-end text-danger">Rp <?= number_format($total_cost, 0, ',', '.') ?></td>
                            <td class="text-end <?= $total_profit >= 0 ? 'text-primary' : 'text-danger' ?>">
                                Rp <?= number_format($total_profit, 0, ',', '.') ?>
                            </td>
                            <td class="text-end"><?= number_format($avg_profit_margin, 1) ?>%</td>
                            <td class="text-end">Rp <?= number_format($total_revenue / max(1, $total_area), 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($total_cost / max(1, $total_area), 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($total_profit / max(1, $total_area), 0, ',', '.') ?></td>
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
                <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Top 10 Most Profitable Blocks</h5>
            </div>
            <div class="card-body">
                <canvas id="profitChart" height="300"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header" style="background-color: #166c82; color: white;">
                <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Revenue vs Cost</h5>
            </div>
            <div class="card-body">
                <canvas id="revenueVsCostChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Top 10 Profitable Blocks
const top10 = <?= json_encode(array_slice($profitability, 0, 10)) ?>;
const profitData = {
    labels: top10.map(b => b.block_code),
    datasets: [{
        label: 'Profit',
        data: top10.map(b => b.profit),
        backgroundColor: top10.map(b => b.profit >= 0 ? 'rgba(75, 192, 192, 0.8)' : 'rgba(255, 99, 132, 0.8)'),
        borderColor: top10.map(b => b.profit >= 0 ? 'rgba(75, 192, 192, 1)' : 'rgba(255, 99, 132, 1)'),
        borderWidth: 1
    }]
};

new Chart(document.getElementById('profitChart'), {
    type: 'bar',
    data: profitData,
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Profit: Rp ' + context.parsed.x.toLocaleString('id-ID');
                    }
                }
            }
        }
    }
});

// Revenue vs Cost Pie Chart
const revCostData = {
    labels: ['Revenue', 'Cost', 'Profit'],
    datasets: [{
        data: [<?= $total_revenue ?>, <?= $total_cost ?>, <?= max(0, $total_profit) ?>],
        backgroundColor: [
            'rgba(75, 192, 192, 0.8)',
            'rgba(255, 99, 132, 0.8)',
            'rgba(54, 162, 235, 0.8)'
        ]
    }]
};

new Chart(document.getElementById('revenueVsCostChart'), {
    type: 'doughnut',
    data: revCostData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.label + ': Rp ' + context.parsed.toLocaleString('id-ID');
                    }
                }
            }
        }
    }
});
</script>

// Made with Bob
