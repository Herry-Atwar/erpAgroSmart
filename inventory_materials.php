<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

$page_title = "Materials Inventory";
require_once 'includes/header.php';

// Get filters
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-d'));
$category_filter = get('category', '');
$warehouse_filter = get('warehouse_id', '');
$report_type = get('report_type', 'summary');

// Fetch material categories
$categories = ['fertilizer', 'pesticide', 'herbicide', 'equipment', 'fuel', 'spare_parts', 'other'];

// Fetch warehouses
$warehouses_stmt = $db->query("
    SELECT * FROM material_warehouses
    ORDER BY warehouse_code
");
$warehouses = $warehouses_stmt->fetchAll();

// Fetch materials with current stock
$materials_sql = "
    SELECT m.*, 
           COALESCE(s.current_stock, 0) as current_stock,
           COALESCE(s.stock_value, 0) as stock_value,
           w.warehouse_name, w.warehouse_code
    FROM materials m
    LEFT JOIN vw_material_stock_summary s ON m.material_id = s.material_id
    LEFT JOIN material_warehouses w ON m.default_warehouse_id = w.warehouse_id
    WHERE 1=1
";
$params = [];

if ($category_filter) {
    $materials_sql .= " AND m.category = ?";
    $params[] = $category_filter;
}

$materials_sql .= " ORDER BY m.material_code";

$stmt = $db->prepare($materials_sql);
$stmt->execute($params);
$materials = $stmt->fetchAll();

// Get inventory summary
$inventory_summary = $db->query("
    SELECT 
        COUNT(DISTINCT material_id) as total_materials,
        SUM(current_stock) as total_stock_qty,
        SUM(stock_value) as total_stock_value,
        COUNT(DISTINCT CASE WHEN current_stock <= reorder_level THEN material_id END) as low_stock_items,
        COUNT(DISTINCT CASE WHEN current_stock = 0 THEN material_id END) as out_of_stock_items
    FROM vw_material_stock_summary
")->fetch();

// Get stock movements for the period
$movements_sql = "
    SELECT 
        DATE(transaction_date) as date,
        SUM(CASE WHEN transaction_type = 'in' THEN quantity ELSE 0 END) as stock_in,
        SUM(CASE WHEN transaction_type = 'out' THEN quantity ELSE 0 END) as stock_out,
        SUM(CASE WHEN transaction_type = 'adjustment' THEN quantity ELSE 0 END) as adjustments,
        SUM(CASE WHEN transaction_type = 'in' THEN quantity * unit_price ELSE 0 END) as value_in,
        SUM(CASE WHEN transaction_type = 'out' THEN quantity * unit_price ELSE 0 END) as value_out
    FROM material_transactions
    WHERE transaction_date BETWEEN ? AND ?
";
$mov_params = [$date_from, $date_to];

if ($category_filter) {
    $movements_sql .= " AND material_id IN (SELECT material_id FROM materials WHERE category = ?)";
    $mov_params[] = $category_filter;
}

$movements_sql .= " GROUP BY DATE(transaction_date) ORDER BY date";

$stmt = $db->prepare($movements_sql);
$stmt->execute($mov_params);
$movements = $stmt->fetchAll();

// Calculate period totals
$period_in = array_sum(array_column($movements, 'stock_in'));
$period_out = array_sum(array_column($movements, 'stock_out'));
$period_value_in = array_sum(array_column($movements, 'value_in'));
$period_value_out = array_sum(array_column($movements, 'value_out'));

// Get low stock alerts
$all_alerts = $db->query("
    SELECT * FROM vw_material_stock_alerts
    ORDER BY days_until_stockout
")->fetchAll();

$alerts = array_filter($all_alerts, function($alert) {
    return $alert['alert_level'] != 'NORMAL';
});

// Get stock by category
$stock_by_category = $db->query("
    SELECT 
        m.category,
        COUNT(DISTINCT m.material_id) as material_count,
        SUM(s.current_stock) as total_stock,
        SUM(s.stock_value) as total_value
    FROM materials m
    LEFT JOIN vw_material_stock_summary s ON m.material_id = s.material_id
    GROUP BY m.category
    ORDER BY total_value DESC
")->fetchAll();

// Get top consumers
$stmt = $db->prepare("
    SELECT
        m.material_code,
        m.material_name,
        m.unit,
        SUM(t.quantity) as total_consumed,
        SUM(t.quantity * t.unit_price) as total_value
    FROM material_transactions t
    INNER JOIN materials m ON t.material_id = m.material_id
    WHERE t.transaction_type = 'out'
      AND t.transaction_date BETWEEN ? AND ?
    GROUP BY m.material_id, m.material_code, m.material_name, m.unit
    ORDER BY total_consumed DESC
    LIMIT 10
");
$stmt->execute([$date_from, $date_to]);
$top_consumers = $stmt->fetchAll();
?>

<style>
    /* Custom orange theme for Materials inventory page */
    .card-header:not(.bg-warning):not(.bg-danger) {
        background-color: #bc5420 !important;
        color: white !important;
    }
    
    .page-header h1 {
        color: #bc5420 !important;
    }
    
    .page-header {
        border-bottom-color: #bc5420 !important;
    }
    
    .stat-card {
        border-left-color: #bc5420 !important;
    }
    
    .stat-card h3 {
        color: #bc5420 !important;
    }
    
    .btn-primary {
        background-color: #bc5420 !important;
        border-color: #bc5420 !important;
    }
    
    .btn-primary:hover {
        background-color: #9a4419 !important;
        border-color: #9a4419 !important;
    }
    
    .text-primary {
        color: #bc5420 !important;
    }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-box"></i> Materials Inventory</h1>
            <p class="text-muted">Fertilizers, chemicals, equipment, and supplies management</p>
        </div>
        <div class="col-auto">
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="bi bi-printer"></i> Print Report
            </button>
            <a href="materials_stock.php" class="btn btn-primary">
                <i class="bi bi-arrow-left-right"></i> Stock Transactions
            </a>
        </div>
    </div>
</div>

<!-- Filter Section -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Report Type</label>
                <select class="form-select" name="report_type">
                    <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Summary</option>
                    <option value="detailed" <?php echo $report_type == 'detailed' ? 'selected' : ''; ?>>Detailed</option>
                    <option value="valuation" <?php echo $report_type == 'valuation' ? 'selected' : ''; ?>>Stock Valuation</option>
                    <option value="movements" <?php echo $report_type == 'movements' ? 'selected' : ''; ?>>Stock Movements</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat; ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>>
                            <?php echo ucfirst(str_replace('_', ' ', $cat)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Warehouse</label>
                <select class="form-select" name="warehouse_id">
                    <option value="">All Warehouses</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?php echo $wh['warehouse_id']; ?>" <?php echo $warehouse_filter == $wh['warehouse_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($wh['warehouse_code']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Search
                    </button>
                    <a href="inventory_materials.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-clockwise"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4><?php echo $inventory_summary['total_materials'] ?? 0; ?></h4>
                <small>Total Materials</small>
                <div class="text-muted mt-1">
                    <?php echo format_number($inventory_summary['total_stock_qty'] ?? 0, 0); ?> units
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4>Rp <?php echo format_number($inventory_summary['total_stock_value'] ?? 0, 0); ?></h4>
                <small>Total Stock Value</small>
                <div class="text-muted mt-1">Inventory Valuation</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4 class="text-warning"><?php echo $inventory_summary['low_stock_items'] ?? 0; ?></h4>
                <small>Low Stock Items</small>
                <div class="text-muted mt-1">
                    <span class="badge bg-danger"><?php echo $inventory_summary['out_of_stock_items'] ?? 0; ?></span> Out of Stock
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4><?php echo format_number($period_out, 0); ?></h4>
                <small>Period Consumption</small>
                <div class="text-muted mt-1">
                    Rp <?php echo format_number($period_value_out, 0); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Alerts Section -->
<?php if (!empty($alerts)): ?>
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <i class="bi bi-exclamation-triangle"></i> <strong>Stock Alerts</strong>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach (array_slice($alerts, 0, 6) as $alert): ?>
                        <div class="col-md-4 mb-2">
                            <div class="alert alert-<?php echo $alert['alert_color']; ?> mb-0">
                                <strong><?php echo htmlspecialchars($alert['material_code']); ?></strong>: 
                                <?php echo $alert['alert_level']; ?>
                                <br>
                                <small>
                                    Stock: <?php echo format_number($alert['current_stock'], 0); ?> <?php echo $alert['unit']; ?>
                                    | Reorder: <?php echo format_number($alert['reorder_level'], 0); ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($alerts) > 6): ?>
                    <div class="text-center mt-2">
                        <small class="text-muted">+ <?php echo count($alerts) - 6; ?> more alerts</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Report Content -->
<?php if ($report_type == 'summary'): ?>
    
    <!-- Stock by Category -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-pie-chart"></i> Stock by Category
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th class="text-center">Items</th>
                                    <th class="text-end">Stock Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stock_by_category as $cat): ?>
                                    <tr>
                                        <td><strong><?php echo ucfirst(str_replace('_', ' ', $cat['category'])); ?></strong></td>
                                        <td class="text-center"><?php echo $cat['material_count']; ?></td>
                                        <td class="text-end">Rp <?php echo format_number($cat['total_value'], 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>TOTAL</th>
                                    <th class="text-center"><?php echo array_sum(array_column($stock_by_category, 'material_count')); ?></th>
                                    <th class="text-end">Rp <?php echo format_number(array_sum(array_column($stock_by_category, 'total_value')), 0); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-graph-down"></i> Top 10 Consumed Materials
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Material</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_consumers as $consumer): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($consumer['material_code']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($consumer['material_name']); ?></small>
                                        </td>
                                        <td class="text-end"><?php echo format_number($consumer['total_consumed'], 0); ?> <?php echo $consumer['unit']; ?></td>
                                        <td class="text-end">Rp <?php echo format_number($consumer['total_value'], 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Materials List -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-list-ul"></i> Materials Inventory (<?php echo count($materials); ?>)
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Material Name</th>
                            <th>Category</th>
                            <th class="text-end">Current Stock</th>
                            <th class="text-end">Reorder Level</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Stock Value</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($materials as $mat): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($mat['material_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($mat['material_name']); ?></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo ucfirst(str_replace('_', ' ', $mat['category'])); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <strong><?php echo format_number($mat['current_stock'], 2); ?></strong> <?php echo $mat['unit']; ?>
                                </td>
                                <td class="text-end"><?php echo format_number($mat['reorder_level'], 0); ?> <?php echo $mat['unit']; ?></td>
                                <td class="text-end">Rp <?php echo format_number($mat['unit_price'], 0); ?></td>
                                <td class="text-end">Rp <?php echo format_number($mat['stock_value'], 0); ?></td>
                                <td>
                                    <?php if ($mat['current_stock'] == 0): ?>
                                        <span class="badge bg-danger">Out of Stock</span>
                                    <?php elseif ($mat['current_stock'] <= $mat['reorder_level']): ?>
                                        <span class="badge bg-warning">Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">In Stock</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($report_type == 'valuation'): ?>
    
    <!-- Stock Valuation Report -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-currency-dollar"></i> Stock Valuation Report
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Material Code</th>
                            <th>Material Name</th>
                            <th>Category</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total Value</th>
                            <th class="text-end">% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_value = array_sum(array_column($materials, 'stock_value'));
                        foreach ($materials as $mat): 
                            $pct = $total_value > 0 ? ($mat['stock_value'] / $total_value * 100) : 0;
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($mat['material_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($mat['material_name']); ?></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $mat['category'])); ?></td>
                                <td class="text-end"><?php echo format_number($mat['current_stock'], 2); ?> <?php echo $mat['unit']; ?></td>
                                <td class="text-end">Rp <?php echo format_number($mat['unit_price'], 0); ?></td>
                                <td class="text-end"><strong>Rp <?php echo format_number($mat['stock_value'], 0); ?></strong></td>
                                <td class="text-end"><?php echo format_number($pct, 2); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5">TOTAL INVENTORY VALUE</th>
                            <th class="text-end">Rp <?php echo format_number($total_value, 0); ?></th>
                            <th class="text-end">100.00%</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($report_type == 'movements'): ?>
    
    <!-- Stock Movements -->
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
                            <th class="text-end">Stock In (Qty)</th>
                            <th class="text-end">Stock Out (Qty)</th>
                            <th class="text-end">Adjustments</th>
                            <th class="text-end">Value In</th>
                            <th class="text-end">Value Out</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($movements)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">No movements in selected period</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($movements as $mov): ?>
                                <tr>
                                    <td><?php echo format_date($mov['date']); ?></td>
                                    <td class="text-end text-success"><?php echo format_number($mov['stock_in'], 0); ?></td>
                                    <td class="text-end text-danger"><?php echo format_number($mov['stock_out'], 0); ?></td>
                                    <td class="text-end text-warning"><?php echo format_number($mov['adjustments'], 0); ?></td>
                                    <td class="text-end">Rp <?php echo format_number($mov['value_in'], 0); ?></td>
                                    <td class="text-end">Rp <?php echo format_number($mov['value_out'], 0); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th>TOTAL</th>
                            <th class="text-end text-success"><?php echo format_number($period_in, 0); ?></th>
                            <th class="text-end text-danger"><?php echo format_number($period_out, 0); ?></th>
                            <th class="text-end">-</th>
                            <th class="text-end">Rp <?php echo format_number($period_value_in, 0); ?></th>
                            <th class="text-end">Rp <?php echo format_number($period_value_out, 0); ?></th>
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

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
<?php if ($report_type == 'movements' && !empty($movements)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('movementChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(function($m) { return format_date($m['date']); }, $movements)); ?>,
                datasets: [
                    {
                        label: 'Stock In',
                        data: <?php echo json_encode(array_column($movements, 'stock_in')); ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.5)',
                        borderColor: 'rgb(75, 192, 192)',
                        borderWidth: 1
                    },
                    {
                        label: 'Stock Out',
                        data: <?php echo json_encode(array_column($movements, 'stock_out')); ?>,
                        backgroundColor: 'rgba(255, 99, 132, 0.5)',
                        borderColor: 'rgb(255, 99, 132)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Materials Stock Movement Trend'
                    },
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
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
