<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

$page_title = "Nursery Stock Management";
require_once 'includes/header.php';

// Include inventory CSS with nursery theme (green)
echo '<link rel="stylesheet" href="css/inventory.css">';
echo '<style>
.page-header {
    background: linear-gradient(135deg, #689f38 0%, #7cb342 100%) !important;
}
.card-header:not(.bg-warning):not(.bg-danger) {
    background: linear-gradient(135deg, #689f38 0%, #7cb342 100%) !important;
}
.stat-card {
    border-left-color: #689f38 !important;
}
.btn-primary {
    background-color: #689f38 !important;
    border-color: #689f38 !important;
}
.btn-primary:hover {
    background-color: #558b2f !important;
    border-color: #558b2f !important;
}
</style>';

// Get filter parameters
$search = get('search', '');
$business_unit_filter = get('business_unit_id', '');
$status_filter = get('status', '');
$variety_filter = get('variety_id', '');

// Build query
$where_clauses = [];
$params = [];

if ($search) {
    $where_clauses[] = "(ns.batch_number ILIKE ? OR ns.seed_source ILIKE ? OR pv.variety_name ILIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($business_unit_filter) {
    $where_clauses[] = "ns.business_unit_id = ?";
    $params[] = $business_unit_filter;
}

if ($status_filter) {
    $where_clauses[] = "ns.status = ?";
    $params[] = $status_filter;
}

if ($variety_filter) {
    $where_clauses[] = "ns.plant_variety_id = ?";
    $params[] = $variety_filter;
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_stocks,
        COUNT(CASE WHEN status IN ('Germination', 'Sprouting', 'Polybag', 'Ready') THEN 1 END) as active_stocks,
        COUNT(CASE WHEN status = 'Ready' THEN 1 END) as ready_stocks,
        SUM(quantity_ready) as total_ready_quantity,
        SUM(quantity_seeds + quantity_sprouts + quantity_polybag + quantity_ready) as total_all_quantity
    FROM nursery_stocks ns
    $where_sql
";
$stmt = $db->prepare($stats_query);
$stmt->execute($params);
$stats = $stmt->fetch();

// Get all stocks
$stocks_query = "
    SELECT 
        ns.*,
        bu.unit_name as nursery_name,
        bu.unit_code as nursery_code,
        pv.variety_name,
        pv.variety_code,
        pv.plant_type,
        CURRENT_DATE - ns.germination_date as age_days,
        (ns.quantity_seeds + ns.quantity_sprouts + ns.quantity_polybag + ns.quantity_ready) as total_quantity
    FROM nursery_stocks ns
    INNER JOIN business_units bu ON ns.business_unit_id = bu.business_unit_id
    INNER JOIN plant_varieties pv ON ns.plant_variety_id = pv.variety_id
    $where_sql
    ORDER BY ns.germination_date DESC
";
$stmt = $db->prepare($stocks_query);
$stmt->execute($params);
$stocks = $stmt->fetchAll();

// Get nurseries for filter
$nurseries = $db->query("
    SELECT business_unit_id, unit_code, unit_name 
    FROM business_units 
    WHERE unit_type = 'nursery'
    ORDER BY unit_name
")->fetchAll();

// Get varieties for filter
$varieties = $db->query("
    SELECT variety_id, variety_code, variety_name, plant_type 
    FROM plant_varieties 
    WHERE status = 'active'
    ORDER BY plant_type, variety_name
")->fetchAll();

// Helper function for status badge color
function getStatusBadgeColor($status) {
    return match($status) {
        'Germination' => 'info',
        'Sprouting' => 'primary',
        'Polybag' => 'warning',
        'Ready' => 'success',
        'Distributed' => 'secondary',
        default => 'secondary'
    };
}
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-box-seam"></i> Nursery Stock Management</h1>
            <p class="text-muted">Manage seedling inventory and production stages</p>
        </div>
        <div class="col-auto">
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="bi bi-printer"></i> Print Report
            </button>
            <a href="nursery_stock_create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Add Stock
            </a>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4><?php echo format_number($stats['total_stocks'] ?? 0, 0); ?></h4>
                <small>Total Batches</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4 class="text-success"><?php echo format_number($stats['active_stocks'] ?? 0, 0); ?></h4>
                <small>Active Batches</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4 class="text-warning"><?php echo format_number($stats['ready_stocks'] ?? 0, 0); ?></h4>
                <small>Ready for Distribution</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4 class="text-info"><?php echo format_number($stats['total_ready_quantity'] ?? 0, 0); ?></h4>
                <small>Total Ready Seedlings</small>
            </div>
        </div>
    </div>
</div>

<!-- Filter Section -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Batch, Source, Variety">
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Nursery</label>
                <select name="business_unit_id" class="form-select">
                    <option value="">All Nurseries</option>
                    <?php foreach ($nurseries as $nursery): ?>
                        <option value="<?php echo $nursery['business_unit_id']; ?>" 
                                <?php echo $business_unit_filter == $nursery['business_unit_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($nursery['unit_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="Germination" <?php echo $status_filter == 'Germination' ? 'selected' : ''; ?>>Germination</option>
                    <option value="Sprouting" <?php echo $status_filter == 'Sprouting' ? 'selected' : ''; ?>>Sprouting</option>
                    <option value="Polybag" <?php echo $status_filter == 'Polybag' ? 'selected' : ''; ?>>Polybag</option>
                    <option value="Ready" <?php echo $status_filter == 'Ready' ? 'selected' : ''; ?>>Ready</option>
                    <option value="Distributed" <?php echo $status_filter == 'Distributed' ? 'selected' : ''; ?>>Distributed</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Variety</label>
                <select name="variety_id" class="form-select">
                    <option value="">All Varieties</option>
                    <?php foreach ($varieties as $variety): ?>
                        <option value="<?php echo $variety['variety_id']; ?>" 
                                <?php echo $variety_filter == $variety['variety_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($variety['variety_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-funnel"></i> Filter
                </button>
                <a href="nursery_stocks.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Clear
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Stocks List -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Stock Batches (<?php echo count($stocks); ?> batches)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Batch Number</th>
                        <th>Nursery</th>
                        <th>Variety</th>
                        <th>Plant Type</th>
                        <th>Germination Date</th>
                        <th>Age (days)</th>
                        <th class="text-end">Seeds</th>
                        <th class="text-end">Sprouts</th>
                        <th class="text-end">Polybag</th>
                        <th class="text-end">Ready</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stocks)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted">No stock batches found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stocks as $stock): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($stock['batch_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($stock['nursery_name']); ?></td>
                                <td><?php echo htmlspecialchars($stock['variety_name']); ?></td>
                                <td><?php echo htmlspecialchars($stock['plant_type']); ?></td>
                                <td><?php echo format_date($stock['germination_date']); ?></td>
                                <td><?php echo $stock['age_days']; ?></td>
                                <td class="text-end"><?php echo format_number($stock['quantity_seeds'], 0); ?></td>
                                <td class="text-end"><?php echo format_number($stock['quantity_sprouts'], 0); ?></td>
                                <td class="text-end"><?php echo format_number($stock['quantity_polybag'], 0); ?></td>
                                <td class="text-end"><strong><?php echo format_number($stock['quantity_ready'], 0); ?></strong></td>
                                <td>
                                    <span class="badge bg-<?php echo getStatusBadgeColor($stock['status']); ?>">
                                        <?php echo $stock['status']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="nursery_stock_view.php?id=<?php echo $stock['stock_id']; ?>" 
                                       class="btn btn-sm btn-info" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="nursery_stock_edit.php?id=<?php echo $stock['stock_id']; ?>" 
                                       class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button onclick="deleteStock(<?php echo $stock['stock_id']; ?>)" 
                                            class="btn btn-sm btn-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function deleteStock(stockId) {
    if (confirm('Are you sure you want to delete this stock batch?')) {
        window.location.href = 'nursery_stock_delete.php?id=' + stockId;
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob