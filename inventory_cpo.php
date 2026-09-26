<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

$page_title = "CPO Inventory Report";
require_once 'includes/header.php';

// Include inventory CSS
echo '<link rel="stylesheet" href="css/inventory.css">';

// Get date range from filters
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-d'));
$tank_filter = get('tank_id', '');
$report_type = get('report_type', 'summary');

// Fetch all tanks
$tanks_stmt = $db->query("
    SELECT t.*, 
           COALESCE(s.current_stock_kg, 0) as current_stock_kg,
           COALESCE(s.utilization_percentage, 0) as utilization_percentage
    FROM storage_tanks t
    LEFT JOIN vw_tank_stock_summary s ON t.tank_id = s.tank_id
    ORDER BY t.tank_code
");
$tanks = $tanks_stmt->fetchAll();

// Get overall inventory summary
$inventory_summary = $db->query("
    SELECT 
        SUM(current_stock_kg) as total_stock_kg,
        SUM(capacity_kg) as total_capacity_kg,
        COUNT(*) as total_tanks,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_tanks,
        SUM(CASE WHEN utilization_percentage >= 90 THEN 1 ELSE 0 END) as critical_tanks,
        SUM(CASE WHEN utilization_percentage <= 20 THEN 1 ELSE 0 END) as low_tanks
    FROM vw_tank_stock_summary
")->fetch();

// Get stock movements for the period
$movements_sql = "
    SELECT 
        DATE(transaction_date) as date,
        SUM(CASE WHEN transaction_type = 'in' THEN quantity_kg ELSE 0 END) as stock_in,
        SUM(CASE WHEN transaction_type = 'out' THEN quantity_kg ELSE 0 END) as stock_out,
        SUM(CASE WHEN transaction_type = 'adjustment' THEN quantity_kg ELSE 0 END) as adjustments
    FROM cpo_stock_transactions
    WHERE transaction_date BETWEEN ? AND ?
";
$params = [$date_from, $date_to];

if ($tank_filter) {
    $movements_sql .= " AND storage_tank_id = ?";
    $params[] = $tank_filter;
}

$movements_sql .= " GROUP BY DATE(transaction_date) ORDER BY date";

$stmt = $db->prepare($movements_sql);
$stmt->execute($params);
$movements = $stmt->fetchAll();

// Calculate period totals
$period_in = array_sum(array_column($movements, 'stock_in'));
$period_out = array_sum(array_column($movements, 'stock_out'));
$period_adjustments = array_sum(array_column($movements, 'adjustments'));
$period_net = $period_in - $period_out + $period_adjustments;

// Get stock aging data
$aging_data = $db->query("
    SELECT * FROM vw_stock_aging
    ORDER BY days_in_storage DESC
")->fetchAll();

// Get utilization alerts
$all_alerts = $db->query("
    SELECT * FROM vw_tank_utilization_alerts
    ORDER BY utilization_percentage DESC
")->fetchAll();

// Filter out NORMAL alerts in PHP to avoid collation issues
$alerts = array_filter($all_alerts, function($alert) {
    return $alert['alert_level'] != 'NORMAL';
});

// Get top transactions
$top_transactions_sql = "
    SELECT t.*, s.tank_code, s.tank_name
    FROM cpo_stock_transactions t
    INNER JOIN storage_tanks s ON t.storage_tank_id = s.tank_id
    WHERE t.transaction_date BETWEEN ? AND ?
";
$top_params = [$date_from, $date_to];

if ($tank_filter) {
    $top_transactions_sql .= " AND t.storage_tank_id = ?";
    $top_params[] = $tank_filter;
}

$top_transactions_sql .= " ORDER BY t.quantity_kg DESC LIMIT 10";

$stmt = $db->prepare($top_transactions_sql);
$stmt->execute($top_params);
$top_transactions = $stmt->fetchAll();

// Calculate inventory turnover
$avg_stock = ($inventory_summary['total_stock_kg'] ?? 0);
$turnover_ratio = $avg_stock > 0 ? ($period_out / $avg_stock) : 0;
$days_in_period = (strtotime($date_to) - strtotime($date_from)) / 86400 + 1;
$turnover_days = $turnover_ratio > 0 ? ($days_in_period / $turnover_ratio) : 0;
?>


<style>
.inv-header { display:flex; align-items:center; justify-content:space-between; padding:10px 0 8px; margin-bottom:10px; }
.inv-header h5 { margin:0; font-weight:600; font-size:1rem; }
.inv-header p  { margin:0; font-size:0.78rem; color:#6c757d; }
.inv-filter { background:#f8f9fa; border:1px solid #e9ecef; border-radius:6px; padding:8px 12px; margin-bottom:10px; }
.inv-filter .form-select, .inv-filter .form-control { font-size:0.8rem; padding:3px 8px; height:30px; }
.inv-filter label { font-size:0.75rem; margin-bottom:2px; color:#495057; }
.stat-card-sm .card-body { padding:8px 10px !important; }
.stat-card-sm h5 { font-size:1rem; font-weight:700; margin:0 0 1px; line-height:1.2; }
.stat-card-sm .lbl { font-size:0.7rem; color:#6c757d; }
.stat-card-sm .sub { font-size:0.68rem; color:#adb5bd; margin-top:1px; }
.card-header-sm { padding:6px 12px; font-size:0.82rem; font-weight:600; }
.card-body-sm   { padding:10px 12px !important; }
.table-xs th, .table-xs td { padding:4px 8px; font-size:0.78rem; vertical-align:middle; }
.prog-sm { height:16px !important; }
</style>

<div class="inv-header">
    <div>
        <h5><i class="bi bi-clipboard-data text-warning me-1"></i> CPO Inventory Report</h5>
        <p>Comprehensive inventory analysis and reporting</p>
    </div>
    <div class="d-flex gap-1">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm py-1 px-2" style="font-size:0.78rem">
            <i class="bi bi-printer"></i> Print
        </button>
        <a href="cpo_stock.php" class="btn btn-warning btn-sm py-1 px-2" style="font-size:0.78rem">
            <i class="bi bi-arrow-left-right"></i> Transactions
        </a>
    </div>
</div>

<!-- Filter Section -->
<div class="inv-filter">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3 col-sm-6">
            <label>Report Type</label>
            <select class="form-select form-select-sm" name="report_type">
                <option value="summary"   <?php echo $report_type=='summary'   ?'selected':''; ?>>Summary</option>
                <option value="detailed"  <?php echo $report_type=='detailed'  ?'selected':''; ?>>Detailed</option>
                <option value="aging"     <?php echo $report_type=='aging'     ?'selected':''; ?>>Aging Analysis</option>
                <option value="movements" <?php echo $report_type=='movements' ?'selected':''; ?>>Stock Movements</option>
            </select>
        </div>
        <div class="col-md-2 col-sm-6">
            <label>Tank</label>
            <select class="form-select form-select-sm" name="tank_id">
                <option value="">All Tanks</option>
                <?php foreach ($tanks as $tank): ?>
                    <option value="<?php echo $tank['tank_id']; ?>" <?php echo $tank_filter==$tank['tank_id']?'selected':''; ?>>
                        <?php echo htmlspecialchars($tank['tank_code']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 col-sm-4">
            <label>From</label>
            <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>
        <div class="col-md-2 col-sm-4">
            <label>To</label>
            <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>
        <div class="col-md-3 col-sm-4 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i> Filter</button>
            <a href="inventory_cpo.php" class="btn btn-outline-secondary btn-sm flex-fill"><i class="bi bi-x"></i> Reset</a>
        </div>
    </form>
</div>

<!-- Inventory Summary Cards -->
<div class="row g-2 mb-2">
    <?php
    $util = ($inventory_summary['total_capacity_kg'] ?? 0) > 0
        ? ($inventory_summary['total_stock_kg'] ?? 0) / $inventory_summary['total_capacity_kg'] * 100 : 0;
    $stats = [
        ['val' => format_number($inventory_summary['total_stock_kg'] ?? 0, 0),
         'sub' => format_number(($inventory_summary['total_stock_kg'] ?? 0)/1000,2).' MT',
         'lbl' => 'Total Stock (kg)', 'color' => 'dark'],
        ['val' => format_number($inventory_summary['total_capacity_kg'] ?? 0, 0),
         'sub' => format_number($util,1).'% used',
         'lbl' => 'Capacity (kg)', 'color' => 'secondary'],
        ['val' => ($inventory_summary['active_tanks'] ?? 0).'/'.($inventory_summary['total_tanks'] ?? 0),
         'sub' => ($inventory_summary['critical_tanks'] ?? 0).' critical',
         'lbl' => 'Active Tanks', 'color' => 'info'],
        ['val' => format_number($period_in, 0),
         'sub' => 'Period IN',
         'lbl' => 'Stock In (kg)', 'color' => 'success'],
        ['val' => format_number($period_out, 0),
         'sub' => 'Period OUT',
         'lbl' => 'Stock Out (kg)', 'color' => 'danger'],
        ['val' => format_number($period_net, 0),
         'sub' => ($period_net>=0?'+':'').format_number($period_net/1000,2).' MT',
         'lbl' => 'Net Change (kg)', 'color' => $period_net>=0?'primary':'warning'],
    ];
    foreach ($stats as $s): ?>
    <div class="col-md-2 col-sm-4">
        <div class="card border-0 shadow-sm stat-card-sm">
            <div class="card-body text-center stat-card-sm">
                <h5 class="text-<?php echo $s['color']; ?>"><?php echo $s['val']; ?></h5>
                <div class="lbl"><?php echo $s['lbl']; ?></div>
                <div class="sub"><?php echo $s['sub']; ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Alerts Section -->
<?php if (!empty($alerts)): ?>
<div class="d-flex flex-wrap gap-2 mb-2">
    <?php foreach ($alerts as $alert): ?>
    <div class="alert alert-<?php echo $alert['alert_color']; ?> py-1 px-2 mb-0" style="font-size:0.78rem">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong><?php echo htmlspecialchars($alert['tank_code']); ?></strong>:
        <?php echo $alert['alert_level']; ?> —
        <?php echo format_number($alert['current_stock_kg'],0); ?> kg (<?php echo format_number($alert['utilization_percentage'],1); ?>%)
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Report Content Based on Type -->
<?php if ($report_type == 'summary'): ?>
    
    <!-- Tank Visual + Summary (side by side) -->
    <div class="row g-2 mb-2">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header card-header-sm"><i class="bi bi-diagram-3"></i> Storage Tanks</div>
                <div class="card-body card-body-sm">
                    <div class="row g-2">
                        <?php foreach ($tanks as $tank): ?>
                        <div class="col-md-3 col-sm-6">
                            <div class="d-flex align-items-center gap-2 border rounded p-2" style="font-size:0.78rem">
                                <div style="width:32px;flex-shrink:0">
                                    <div class="tank-body" style="height:48px;position:relative">
                                        <div class="tank-top"></div>
                                        <div class="tank-fill <?php echo $tank['utilization_percentage']>=90?'danger':($tank['utilization_percentage']>=70?'warning':''); ?>" style="height:<?php echo $tank['utilization_percentage']; ?>%"></div>
                                        <div class="tank-percentage" style="font-size:0.6rem"><?php echo format_number($tank['utilization_percentage'],1); ?>%</div>
                                    </div>
                                </div>
                                <div class="flex-fill overflow-hidden">
                                    <div class="fw-bold text-truncate"><?php echo htmlspecialchars($tank['tank_code']); ?></div>
                                    <div class="text-muted" style="font-size:0.7rem"><?php echo format_number($tank['current_stock_kg']/1000,2); ?> / <?php echo format_number($tank['capacity_kg']/1000,2); ?> MT</div>
                                    <span class="badge bg-<?php echo $tank['status']=='active'?'success':($tank['status']=='maintenance'?'warning':'secondary'); ?>" style="font-size:0.65rem"><?php echo ucfirst($tank['status']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tank Inventory Summary -->
    <div class="card mb-2">
        <div class="card-header card-header-sm"><i class="bi bi-database"></i> Tank Inventory Summary</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-xs mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tank</th><th>Name</th><th>Type</th>
                            <th class="text-end">Capacity (MT)</th>
                            <th class="text-end">Stock (MT)</th>
                            <th class="text-end">Available (MT)</th>
                            <th style="min-width:100px">Utilization</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tanks as $tank):
                            $available = $tank['capacity_kg'] - $tank['current_stock_kg']; ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($tank['tank_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($tank['tank_name']); ?></td>
                            <td><?php echo ucfirst($tank['tank_type']); ?></td>
                            <td class="text-end"><?php echo format_number($tank['capacity_kg']/1000,2); ?></td>
                            <td class="text-end"><strong><?php echo format_number($tank['current_stock_kg']/1000,2); ?></strong></td>
                            <td class="text-end"><?php echo format_number($available/1000,2); ?></td>
                            <td>
                                <div class="progress prog-sm">
                                    <div class="progress-bar bg-<?php echo $tank['utilization_percentage']>=90?'danger':($tank['utilization_percentage']>=70?'warning':'success'); ?>"
                                         style="width:<?php echo $tank['utilization_percentage']; ?>%">
                                        <?php echo format_number($tank['utilization_percentage'],1); ?>%
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge bg-<?php echo $tank['status']=='active'?'success':($tank['status']=='maintenance'?'warning':'secondary'); ?>"><?php echo ucfirst($tank['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="3">TOTAL</td>
                            <td class="text-end"><?php echo format_number(($inventory_summary['total_capacity_kg']??0)/1000,2); ?></td>
                            <td class="text-end"><?php echo format_number(($inventory_summary['total_stock_kg']??0)/1000,2); ?></td>
                            <td class="text-end"><?php echo format_number((($inventory_summary['total_capacity_kg']??0)-($inventory_summary['total_stock_kg']??0))/1000,2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Turnover + Top Transactions -->
    <div class="row g-2 mb-2">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header card-header-sm"><i class="bi bi-arrow-repeat"></i> Inventory Turnover</div>
                <div class="card-body card-body-sm">
                    <table class="table table-xs mb-0">
                        <tr><th style="width:55%">Period</th><td><?php echo format_date($date_from); ?> – <?php echo format_date($date_to); ?></td></tr>
                        <tr><th>Days in Period</th><td><?php echo number_format($days_in_period,0); ?> days</td></tr>
                        <tr><th>Avg Stock</th><td><?php echo format_number($avg_stock/1000,2); ?> MT</td></tr>
                        <tr><th>Total Out</th><td><?php echo format_number($period_out/1000,2); ?> MT</td></tr>
                        <tr><th>Turnover Ratio</th><td><strong><?php echo format_number($turnover_ratio,2); ?>x</strong></td></tr>
                        <tr><th>Days Inv. Outstanding</th><td><strong><?php echo format_number($turnover_days,1); ?> days</strong></td></tr>
                        <tr><th>Daily Avg Dispatch</th><td><?php echo format_number($period_out/$days_in_period,0); ?> kg/day</td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header card-header-sm"><i class="bi bi-trophy"></i> Top 10 Largest Transactions</div>
                <div class="card-body p-0">
                    <table class="table table-xs mb-0">
                        <thead class="table-light"><tr><th>Date</th><th>Type</th><th>Tank</th><th class="text-end">Qty (kg)</th></tr></thead>
                        <tbody>
                            <?php foreach ($top_transactions as $trans): ?>
                            <tr>
                                <td><?php echo format_date($trans['transaction_date']); ?></td>
                                <td><span class="badge bg-<?php echo $trans['transaction_type']=='in'?'success':($trans['transaction_type']=='out'?'danger':'warning'); ?>"><?php echo strtoupper($trans['transaction_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($trans['tank_code']); ?></td>
                                <td class="text-end"><strong><?php echo format_number($trans['quantity_kg'],0); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($report_type == 'aging'): ?>
    
    <!-- Stock Aging Analysis -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-clock-history"></i> Stock Aging Analysis
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Tank Code</th>
                            <th>Tank Name</th>
                            <th class="text-end">Current Stock (kg)</th>
                            <th>Oldest Stock Date</th>
                            <th class="text-center">Days in Storage</th>
                            <th>Aging Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($aging_data)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">No stock aging data available</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($aging_data as $aging): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($aging['tank_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($aging['tank_name']); ?></td>
                                    <td class="text-end">
                                        <?php echo format_number($aging['current_stock_kg'], 0); ?>
                                        <br><small class="text-muted"><?php echo format_number($aging['current_stock_kg']/1000, 2); ?> MT</small>
                                    </td>
                                    <td><?php echo format_date($aging['oldest_stock_date']); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php 
                                            echo $aging['days_in_storage'] > 60 ? 'danger' : 
                                                ($aging['days_in_storage'] > 30 ? 'warning' : 
                                                ($aging['days_in_storage'] > 14 ? 'info' : 'success')); 
                                        ?>">
                                            <?php echo $aging['days_in_storage']; ?> days
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo strpos($aging['aging_status'], 'OLD') !== false ? 'danger' : 
                                                (strpos($aging['aging_status'], 'AGING') !== false ? 'warning' : 
                                                (strpos($aging['aging_status'], 'MODERATE') !== false ? 'info' : 'success')); 
                                        ?>">
                                            <?php echo $aging['aging_status']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($report_type == 'movements'): ?>
    
    <!-- Daily Stock Movements -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-graph-up"></i> Daily Stock Movements
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-end">Stock In (kg)</th>
                            <th class="text-end">Stock Out (kg)</th>
                            <th class="text-end">Adjustments (kg)</th>
                            <th class="text-end">Net Change (kg)</th>
                            <th class="text-end">Net Change (MT)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($movements)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">No movements in selected period</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($movements as $mov): ?>
                                <?php $net = $mov['stock_in'] - $mov['stock_out'] + $mov['adjustments']; ?>
                                <tr>
                                    <td><?php echo format_date($mov['date']); ?></td>
                                    <td class="text-end text-success"><?php echo format_number($mov['stock_in'], 0); ?></td>
                                    <td class="text-end text-danger"><?php echo format_number($mov['stock_out'], 0); ?></td>
                                    <td class="text-end text-warning"><?php echo format_number($mov['adjustments'], 0); ?></td>
                                    <td class="text-end">
                                        <strong class="text-<?php echo $net >= 0 ? 'success' : 'danger'; ?>">
                                            <?php echo $net >= 0 ? '+' : ''; ?><?php echo format_number($net, 0); ?>
                                        </strong>
                                    </td>
                                    <td class="text-end">
                                        <strong class="text-<?php echo $net >= 0 ? 'success' : 'danger'; ?>">
                                            <?php echo $net >= 0 ? '+' : ''; ?><?php echo format_number($net/1000, 2); ?>
                                        </strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th>TOTAL</th>
                            <th class="text-end text-success"><?php echo format_number($period_in, 0); ?></th>
                            <th class="text-end text-danger"><?php echo format_number($period_out, 0); ?></th>
                            <th class="text-end text-warning"><?php echo format_number($period_adjustments, 0); ?></th>
                            <th class="text-end">
                                <strong class="text-<?php echo $period_net >= 0 ? 'success' : 'danger'; ?>">
                                    <?php echo $period_net >= 0 ? '+' : ''; ?><?php echo format_number($period_net, 0); ?>
                                </strong>
                            </th>
                            <th class="text-end">
                                <strong class="text-<?php echo $period_net >= 0 ? 'success' : 'danger'; ?>">
                                    <?php echo $period_net >= 0 ? '+' : ''; ?><?php echo format_number($period_net/1000, 2); ?>
                                </strong>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- Movement Chart -->
            <div class="mt-4">
                <canvas id="movementChart" height="80"></canvas>
            </div>
        </div>
    </div>

<?php endif; ?>

<!-- Print Styles -->
<style>
@media print {
    .page-header .btn,
    .card-body form,
    .no-print {
        display: none !important;
    }
    .card {
        page-break-inside: avoid;
    }
}
</style>

<!-- Chart.js for Movement Chart -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
<?php if ($report_type == 'movements' && !empty($movements)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('movementChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(function($m) { return format_date($m['date']); }, $movements)); ?>,
                datasets: [
                    {
                        label: 'Stock In',
                        data: <?php echo json_encode(array_column($movements, 'stock_in')); ?>,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        tension: 0.1
                    },
                    {
                        label: 'Stock Out',
                        data: <?php echo json_encode(array_column($movements, 'stock_out')); ?>,
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        tension: 0.1
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Stock Movement Trend'
                    },
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString() + ' kg';
                            }
                        }
                    }
                }
            }
        });
    }
});
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
