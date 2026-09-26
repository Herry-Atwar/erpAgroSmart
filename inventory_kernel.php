<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

$page_title = "Kernel Inventory Report";
require_once 'includes/header.php';

// Include inventory CSS with kernel theme
echo '<link rel="stylesheet" href="css/inventory.css">';
echo '<div class="kernel-theme">';

// Get date range from filters
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-d'));
$storage_filter = get('storage_id', '');
$report_type = get('report_type', 'summary');

// Fetch all kernel storage locations
$storages_stmt = $db->query("
    SELECT s.*,
           COALESCE(st.current_stock_kg, 0) as current_stock_kg,
           COALESCE(st.utilization_percentage, 0) as utilization_percentage
    FROM kernel_storage s
    LEFT JOIN vw_kernel_storage_summary st ON s.storage_id = st.storage_id
    ORDER BY s.storage_code
");
$storages = $storages_stmt->fetchAll();

// Get overall inventory summary
$inventory_summary = $db->query("
    SELECT
        SUM(current_stock_kg) as total_stock_kg,
        SUM(capacity_kg) as total_capacity_kg,
        COUNT(*) as total_storages,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_storages,
        SUM(CASE WHEN utilization_percentage >= 90 THEN 1 ELSE 0 END) as critical_storages,
        SUM(CASE WHEN utilization_percentage <= 20 THEN 1 ELSE 0 END) as low_storages
    FROM vw_kernel_storage_summary
")->fetch();

// Get stock movements for the period
$movements_sql = "
    SELECT 
        DATE(transaction_date) as date,
        SUM(CASE WHEN transaction_type = 'in' THEN quantity_kg ELSE 0 END) as stock_in,
        SUM(CASE WHEN transaction_type = 'out' THEN quantity_kg ELSE 0 END) as stock_out,
        SUM(CASE WHEN transaction_type = 'adjustment' THEN quantity_kg ELSE 0 END) as adjustments
    FROM kernel_stock_transactions
    WHERE transaction_date BETWEEN ? AND ?
";
$params = [$date_from, $date_to];

if ($storage_filter) {
    $movements_sql .= " AND storage_id = ?";
    $params[] = $storage_filter;
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
    SELECT * FROM vw_kernel_stock_aging
    ORDER BY days_in_storage DESC
")->fetchAll();

// Get utilization alerts - filter in PHP to avoid collation issues
$all_alerts = $db->query("
    SELECT * FROM vw_kernel_utilization_alerts
    ORDER BY utilization_percentage DESC
")->fetchAll();

$alerts = array_filter($all_alerts, function($alert) {
    return $alert['alert_level'] != 'NORMAL';
});

// Get top transactions
$top_transactions_sql = "
    SELECT t.*, s.storage_code, s.storage_name
    FROM kernel_stock_transactions t
    INNER JOIN kernel_storage s ON t.storage_id = s.storage_id
    WHERE t.transaction_date BETWEEN ? AND ?
";
$top_params = [$date_from, $date_to];

if ($storage_filter) {
    $top_transactions_sql .= " AND t.storage_id = ?";
    $top_params[] = $storage_filter;
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
        <h5><i class="bi bi-box-seam text-success me-1"></i> Kernel Inventory Report</h5>
        <p>Palm kernel inventory analysis and reporting</p>
    </div>
    <div class="d-flex gap-1">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm py-1 px-2" style="font-size:0.78rem">
            <i class="bi bi-printer"></i> Print
        </button>
        <a href="kernel_stock.php" class="btn btn-success btn-sm py-1 px-2" style="font-size:0.78rem">
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
            <label>Storage</label>
            <select class="form-select form-select-sm" name="storage_id">
                <option value="">All Storages</option>
                <?php foreach ($storages as $storage): ?>
                    <option value="<?php echo $storage['storage_id']; ?>" <?php echo $storage_filter==$storage['storage_id']?'selected':''; ?>>
                        <?php echo htmlspecialchars($storage['storage_code']); ?>
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
            <a href="inventory_kernel.php" class="btn btn-outline-secondary btn-sm flex-fill"><i class="bi bi-x"></i> Reset</a>
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
        ['val' => ($inventory_summary['active_storages'] ?? 0).'/'.($inventory_summary['total_storages'] ?? 0),
         'sub' => ($inventory_summary['critical_storages'] ?? 0).' critical',
         'lbl' => 'Active Storages', 'color' => 'info'],
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
        <strong><?php echo htmlspecialchars($alert['storage_code']); ?></strong>:
        <?php echo $alert['alert_level']; ?> —
        <?php echo format_number($alert['current_stock_kg'],0); ?> kg (<?php echo format_number($alert['utilization_percentage'],1); ?>%)
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Report Content Based on Type -->
<?php if ($report_type == 'summary'): ?>
    
    <!-- Kernel Storage Visual Overview -->
    <div class="row g-2 mb-2">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header card-header-sm"><i class="bi bi-diagram-3"></i> Kernel Storage Locations</div>
                <div class="card-body card-body-sm">
                    <div class="row g-2">
                        <?php foreach ($storages as $storage): ?>
                        <div class="col-md-3 col-sm-6">
                            <div class="d-flex align-items-center gap-2 border rounded p-2" style="font-size:0.78rem">
                                <div style="width:32px;flex-shrink:0">
                                    <div class="silo-body" style="height:48px;position:relative">
                                        <div class="silo-top"></div>
                                        <div class="silo-fill <?php echo $storage['utilization_percentage']>=90?'danger':($storage['utilization_percentage']>=70?'warning':''); ?>" style="height:<?php echo $storage['utilization_percentage']; ?>%"></div>
                                        <div class="tank-percentage" style="font-size:0.6rem"><?php echo format_number($storage['utilization_percentage'],1); ?>%</div>
                                    </div>
                                </div>
                                <div class="flex-fill overflow-hidden">
                                    <div class="fw-bold text-truncate"><?php echo htmlspecialchars($storage['storage_code']); ?></div>
                                    <div class="text-muted" style="font-size:0.7rem"><?php echo ucfirst($storage['storage_type']); ?> · <?php echo format_number($storage['current_stock_kg']/1000,2); ?> / <?php echo format_number($storage['capacity_kg']/1000,2); ?> MT</div>
                                    <span class="badge bg-<?php echo $storage['status']=='active'?'success':($storage['status']=='maintenance'?'warning':'secondary'); ?>" style="font-size:0.65rem"><?php echo ucfirst($storage['status']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Storage Inventory Summary -->
    <div class="card mb-2">
        <div class="card-header card-header-sm"><i class="bi bi-box-seam"></i> Kernel Storage Inventory Summary</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-xs mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Storage</th><th>Name</th><th>Type</th>
                            <th class="text-end">Capacity (MT)</th>
                            <th class="text-end">Stock (MT)</th>
                            <th class="text-end">Available (MT)</th>
                            <th style="min-width:100px">Utilization</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($storages as $storage):
                            $available = $storage['capacity_kg'] - $storage['current_stock_kg']; ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($storage['storage_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($storage['storage_name']); ?></td>
                            <td><?php echo ucfirst($storage['storage_type']); ?></td>
                            <td class="text-end"><?php echo format_number($storage['capacity_kg']/1000,2); ?></td>
                            <td class="text-end"><strong><?php echo format_number($storage['current_stock_kg']/1000,2); ?></strong></td>
                            <td class="text-end"><?php echo format_number($available/1000,2); ?></td>
                            <td>
                                <div class="progress prog-sm">
                                    <div class="progress-bar bg-<?php echo $storage['utilization_percentage']>=90?'danger':($storage['utilization_percentage']>=70?'warning':'success'); ?>"
                                         style="width:<?php echo $storage['utilization_percentage']; ?>%">
                                        <?php echo format_number($storage['utilization_percentage'],1); ?>%
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge bg-<?php echo $storage['status']=='active'?'success':($storage['status']=='maintenance'?'warning':'secondary'); ?>"><?php echo ucfirst($storage['status']); ?></span></td>
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
                        <thead class="table-light"><tr><th>Date</th><th>Type</th><th>Storage</th><th class="text-end">Qty (kg)</th></tr></thead>
                        <tbody>
                            <?php foreach ($top_transactions as $trans): ?>
                            <tr>
                                <td><?php echo format_date($trans['transaction_date']); ?></td>
                                <td><span class="badge bg-<?php echo $trans['transaction_type']=='in'?'success':($trans['transaction_type']=='out'?'danger':'warning'); ?>"><?php echo strtoupper($trans['transaction_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($trans['storage_code']); ?></td>
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
            <i class="bi bi-clock-history"></i> Kernel Stock Aging Analysis
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Storage Code</th>
                            <th>Storage Name</th>
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
                                    <td><strong><?php echo htmlspecialchars($aging['storage_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($aging['storage_name']); ?></td>
                                    <td class="text-end">
                                        <?php echo format_number($aging['current_stock_kg'], 0); ?>
                                        <br><small class="text-muted"><?php echo format_number($aging['current_stock_kg']/1000, 2); ?> MT</small>
                                    </td>
                                    <td><?php echo format_date($aging['oldest_stock_date']); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php 
                                            echo $aging['days_in_storage'] > 90 ? 'danger' : 
                                                ($aging['days_in_storage'] > 60 ? 'warning' : 
                                                ($aging['days_in_storage'] > 30 ? 'info' : 'success')); 
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
            <i class="bi bi-graph-up"></i> Daily Kernel Stock Movements
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
                        text: 'Kernel Stock Movement Trend'
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

</div> <!-- End kernel-theme -->

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
