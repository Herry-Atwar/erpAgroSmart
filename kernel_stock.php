<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add_transaction') {
        try {
            $transaction_type = post('transaction_type');
            
            // Handle transfer transactions differently
            if ($transaction_type == 'transfer') {
                $from_storage = post('storage_id');
                $to_storage = post('destination_storage_id');
                $quantity = post('quantity_kg');
                $ref_no = post('reference_no') ?: 'TRF-' . date('YmdHis');
                
                // Start transaction
                $db->beginTransaction();
                
                // Transfer out from source storage
                $stmt = $db->prepare("
                    INSERT INTO kernel_stock_transactions
                    (transaction_date, transaction_time, transaction_type, storage_id,
                     destination_storage_id, quantity_kg, reference_no, remarks, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    post('transaction_date'),
                    post('transaction_time'),
                    'transfer',
                    $from_storage,
                    $to_storage,
                    -abs($quantity),
                    $ref_no,
                    'Transfer out to storage ' . $to_storage . ': ' . post('remarks'),
                    'admin'
                ]);
                
                // Transfer in to destination storage
                $stmt->execute([
                    post('transaction_date'),
                    post('transaction_time'),
                    'transfer',
                    $to_storage,
                    $from_storage,
                    abs($quantity),
                    $ref_no,
                    'Transfer in from storage ' . $from_storage . ': ' . post('remarks'),
                    'admin'
                ]);
                
                $db->commit();
                set_message('success', 'Transfer transaction recorded successfully!');
            } else {
                // Regular transaction (in, out, adjustment)
                $stmt = $db->prepare("
                    INSERT INTO kernel_stock_transactions
                    (transaction_date, transaction_time, transaction_type, storage_id,
                     production_id, destination_storage_id, quantity_kg, reference_no, remarks, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    post('transaction_date'),
                    post('transaction_time'),
                    $transaction_type,
                    post('storage_id'),
                    post('production_id') ?: null,
                    null,
                    post('quantity_kg'),
                    post('reference_no'),
                    post('remarks'),
                    'admin'
                ]);
                
                set_message('success', 'Stock transaction recorded successfully!');
            }
            
            redirect('kernel_stock.php');
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            set_message('error', 'Error recording transaction: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit_transaction') {
        try {
            $stmt = $db->prepare("
                UPDATE kernel_stock_transactions
                SET transaction_date = ?, transaction_time = ?, transaction_type = ?,
                    storage_id = ?, production_id = ?, quantity_kg = ?,
                    reference_no = ?, remarks = ?, updated_by = ?
                WHERE transaction_id = ?
            ");
            
            $stmt->execute([
                post('transaction_date'),
                post('transaction_time'),
                post('transaction_type'),
                post('storage_id'),
                post('production_id') ?: null,
                post('quantity_kg'),
                post('reference_no'),
                post('remarks'),
                'admin',
                post('transaction_id')
            ]);
            
            set_message('success', 'Transaction updated successfully!');
            redirect('kernel_stock.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating transaction: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete_transaction') {
        try {
            $stmt = $db->prepare("DELETE FROM kernel_stock_transactions WHERE transaction_id = ?");
            $stmt->execute([post('transaction_id')]);
            
            set_message('success', 'Transaction deleted successfully!');
            redirect('kernel_stock.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting transaction: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'add_storage') {
        try {
            $stmt = $db->prepare("
                INSERT INTO kernel_storage
                (storage_code, storage_name, storage_type, capacity_kg, location,
                 status, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('storage_code'),
                post('storage_name'),
                post('storage_type'),
                post('capacity_kg'),
                post('location'),
                post('status'),
                post('remarks'),
                'admin'
            ]);
            
            set_message('success', 'Storage location added successfully!');
            redirect('kernel_stock.php?tab=storages');
        } catch (PDOException $e) {
            set_message('error', 'Error adding storage: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit_storage') {
        try {
            $stmt = $db->prepare("
                UPDATE kernel_storage
                SET storage_code = ?, storage_name = ?, storage_type = ?, capacity_kg = ?,
                    location = ?, status = ?, remarks = ?, updated_by = ?
                WHERE storage_id = ?
            ");
            
            $stmt->execute([
                post('storage_code'),
                post('storage_name'),
                post('storage_type'),
                post('capacity_kg'),
                post('location'),
                post('status'),
                post('remarks'),
                'admin',
                post('storage_id')
            ]);
            
            set_message('success', 'Storage updated successfully!');
            redirect('kernel_stock.php?tab=storages');
        } catch (PDOException $e) {
            set_message('error', 'Error updating storage: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_transaction = null;
$edit_storage = null;
if (get('action') == 'edit_transaction' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM kernel_stock_transactions WHERE transaction_id = ?");
    $stmt->execute([get('id')]);
    $edit_transaction = $stmt->fetch();
}
if (get('action') == 'edit_storage' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM kernel_storage WHERE storage_id = ?");
    $stmt->execute([get('id')]);
    $edit_storage = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Kernel Stock Management";
require_once 'includes/header.php';

// Include inventory CSS with kernel theme
echo '<link rel="stylesheet" href="css/inventory.css">';
echo '<div class="kernel-theme">';

$active_tab = get('tab', 'stock');

// Fetch storage locations for dropdown
$storages_stmt = $db->query("
    SELECT s.*, 
           COALESCE(st.current_stock_kg, 0) as current_stock_kg,
           COALESCE(st.utilization_percentage, 0) as utilization_pct
    FROM kernel_storage s
    LEFT JOIN vw_kernel_storage_summary st ON s.storage_id = st.storage_id
    ORDER BY s.storage_code
");
$storages = $storages_stmt->fetchAll();

// Fetch production records for dropdown
$productions_stmt = $db->query("
    SELECT p.production_id, p.production_date, p.kernel_quantity_kg as kernel_produced_kg,
           b.batch_no, m.mill_name
    FROM mill_production p
    INNER JOIN mill_processing_batch b ON p.batch_id = b.batch_id
    INNER JOIN mill_master m ON b.mill_id = m.mill_id
    ORDER BY p.production_date DESC
    LIMIT 100
");
$productions = $productions_stmt->fetchAll();

// Fetch stock transactions with filters
$search = get('search', '');
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-d'));
$type_filter = get('transaction_type', '');
$storage_filter = get('storage_id', '');

$sql = "SELECT t.*, 
        s.storage_code, s.storage_name,
        dest.storage_code as dest_storage_code,
        p.production_date, b.batch_no
        FROM kernel_stock_transactions t
        INNER JOIN kernel_storage s ON t.storage_id = s.storage_id
        LEFT JOIN kernel_storage dest ON t.destination_storage_id = dest.storage_id
        LEFT JOIN mill_production p ON t.production_id = p.production_id
        LEFT JOIN mill_processing_batch b ON p.batch_id = b.batch_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (t.reference_no LIKE ? OR s.storage_code LIKE ? OR b.batch_no LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($date_from) {
    $sql .= " AND t.transaction_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND t.transaction_date <= ?";
    $params[] = $date_to;
}
if ($type_filter) {
    $sql .= " AND t.transaction_type = ?";
    $params[] = $type_filter;
}
if ($storage_filter) {
    $sql .= " AND t.storage_id = ?";
    $params[] = $storage_filter;
}

$sql .= " ORDER BY t.transaction_date DESC, t.transaction_time DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Calculate summary statistics
$total_in = array_sum(array_map(function($t) {
    return $t['transaction_type'] == 'in' ? $t['quantity_kg'] : 0;
}, $transactions));

$total_out = array_sum(array_map(function($t) {
    return $t['transaction_type'] == 'out' ? $t['quantity_kg'] : 0;
}, $transactions));

$total_adjustment = array_sum(array_map(function($t) {
    return $t['transaction_type'] == 'adjustment' ? $t['quantity_kg'] : 0;
}, $transactions));

// Get overall stock summary
$stock_summary = $db->query("
    SELECT 
        SUM(current_stock_kg) as total_stock,
        SUM(capacity_kg) as total_capacity,
        COUNT(*) as total_storages,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_storages
    FROM vw_kernel_storage_summary
")->fetch();

$transaction_types = ['in', 'out', 'adjustment', 'transfer'];
$storage_statuses = ['active', 'maintenance', 'inactive'];
$storage_types = ['silo', 'warehouse', 'bulk', 'container'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-box-seam"></i> Kernel Stock Management</h1>
            <p class="text-muted">Inventory tracking and storage management</p>
        </div>
        <div class="col-auto">
            <a href="inventory_kernel.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Kernel Inventory
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                <i class="bi bi-plus-circle"></i> Add Transaction
            </button>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addStorageModal">
                <i class="bi bi-database-add"></i> Add Storage
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($stock_summary['total_stock'] ?? 0, 0); ?> kg</h3>
                <p><i class="bi bi-box-seam text-primary"></i> Total Stock</p>
                <small class="text-muted"><?php echo format_number(($stock_summary['total_stock'] ?? 0) / 1000, 2); ?> MT</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($stock_summary['total_capacity'] ?? 0, 0); ?> kg</h3>
                <p><i class="bi bi-database text-info"></i> Total Capacity</p>
                <small class="text-muted">
                    <?php 
                    $utilization = ($stock_summary['total_capacity'] ?? 0) > 0 
                        ? ($stock_summary['total_stock'] ?? 0) / $stock_summary['total_capacity'] * 100 
                        : 0;
                    echo format_number($utilization, 1); 
                    ?>% Utilized
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $stock_summary['active_storages'] ?? 0; ?> / <?php echo $stock_summary['total_storages'] ?? 0; ?></h3>
                <p><i class="bi bi-check-circle text-success"></i> Active Storages</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo count($transactions); ?></h3>
                <p><i class="bi bi-arrow-left-right"></i> Transactions</p>
                <small class="text-muted">Selected Period</small>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo $active_tab == 'stock' ? 'active' : ''; ?>" href="?tab=stock">
            <i class="bi bi-list-ul"></i> Stock Transactions
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $active_tab == 'storages' ? 'active' : ''; ?>" href="?tab=storages">
            <i class="bi bi-database"></i> Storage Locations
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $active_tab == 'summary' ? 'active' : ''; ?>" href="?tab=summary">
            <i class="bi bi-bar-chart"></i> Stock Summary
        </a>
    </li>
</ul>

<?php if ($active_tab == 'stock'): ?>
    <!-- Stock Transactions Tab -->
    
    <!-- Transaction Summary -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-graph-up"></i> Transaction Summary (Selected Period)
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h4 class="text-success"><?php echo format_number($total_in, 0); ?> kg</h4>
                            <small>Stock In</small>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-danger"><?php echo format_number($total_out, 0); ?> kg</h4>
                            <small>Stock Out</small>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-warning"><?php echo format_number($total_adjustment, 0); ?> kg</h4>
                            <small>Adjustments</small>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-primary"><?php echo format_number($total_in - $total_out + $total_adjustment, 0); ?> kg</h4>
                            <small>Net Change</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Search and Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="tab" value="stock">
                <div class="col-md-2">
                    <input type="text" class="form-control" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="transaction_type">
                        <option value="">All Types</option>
                        <?php foreach ($transaction_types as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $type_filter == $type ? 'selected' : ''; ?>>
                                <?php echo ucfirst($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="storage_id">
                        <option value="">All Storages</option>
                        <?php foreach ($storages as $storage): ?>
                            <option value="<?php echo $storage['storage_id']; ?>" <?php echo $storage_filter == $storage['storage_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($storage['storage_code']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                    <a href="?tab=stock" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Transactions Table -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-list-ul"></i> Stock Transactions (<?php echo count($transactions); ?>)
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Type</th>
                            <th>From Storage</th>
                            <th>To Storage</th>
                            <th>Quantity (kg)</th>
                            <th>Reference</th>
                            <th>Batch</th>
                            <th>Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">No transactions found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $trans): ?>
                                <tr>
                                    <td>
                                        <?php echo format_date($trans['transaction_date']); ?><br>
                                        <small class="text-muted"><?php echo date('H:i', strtotime($trans['transaction_time'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php
                                            echo $trans['transaction_type'] == 'in' ? 'success' :
                                                ($trans['transaction_type'] == 'out' ? 'danger' :
                                                ($trans['transaction_type'] == 'transfer' ? 'info' : 'warning'));
                                        ?>">
                                            <?php echo strtoupper($trans['transaction_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($trans['storage_code']); ?></strong>
                                        <?php if ($trans['transaction_type'] == 'transfer' && $trans['quantity_kg'] < 0): ?>
                                            <br><small class="text-muted">(Source)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trans['transaction_type'] == 'transfer' && isset($trans['dest_storage_code']) && $trans['dest_storage_code']): ?>
                                            <strong><?php echo htmlspecialchars($trans['dest_storage_code']); ?></strong>
                                            <br><small class="text-muted">(Destination)</small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <strong><?php echo format_number(abs($trans['quantity_kg']), 0); ?></strong> kg
                                        <br><small class="text-muted"><?php echo format_number(abs($trans['quantity_kg'])/1000, 2); ?> MT</small>
                                    </td>
                                    <td><?php echo htmlspecialchars($trans['reference_no']); ?></td>
                                    <td><?php echo $trans['batch_no'] ? htmlspecialchars($trans['batch_no']) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars(substr($trans['remarks'] ?? '', 0, 50)); ?></td>
                                    <td>
                                        <a href="?action=edit_transaction&id=<?php echo $trans['transaction_id']; ?>&tab=stock" class="btn btn-sm btn-warning" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this transaction?');">
                                            <input type="hidden" name="action" value="delete_transaction">
                                            <input type="hidden" name="transaction_id" value="<?php echo $trans['transaction_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($active_tab == 'storages'): ?>
    <!-- Storage Locations Tab -->
    
    <div class="card">
        <div class="card-header">
            <i class="bi bi-database"></i> Storage Locations (<?php echo count($storages); ?>)
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Storage Code</th>
                            <th>Storage Name</th>
                            <th>Type</th>
                            <th>Capacity</th>
                            <th>Current Stock</th>
                            <th>Utilization</th>
                            <th>Status</th>
                            <th>Location</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($storages as $storage): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($storage['storage_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($storage['storage_name']); ?></td>
                                <td><?php echo ucfirst($storage['storage_type']); ?></td>
                                <td class="text-end">
                                    <?php echo format_number($storage['capacity_kg'], 0); ?> kg
                                    <br><small class="text-muted"><?php echo format_number($storage['capacity_kg']/1000, 2); ?> MT</small>
                                </td>
                                <td class="text-end">
                                    <?php echo format_number($storage['current_stock_kg'], 0); ?> kg
                                    <br><small class="text-muted"><?php echo format_number($storage['current_stock_kg']/1000, 2); ?> MT</small>
                                </td>
                                <td>
                                    <div class="progress" style="height: 25px;">
                                        <div class="progress-bar bg-<?php 
                                            echo $storage['utilization_pct'] >= 90 ? 'danger' : 
                                                ($storage['utilization_pct'] >= 70 ? 'warning' : 'success'); 
                                        ?>" 
                                             role="progressbar" 
                                             style="width: <?php echo $storage['utilization_pct']; ?>%">
                                            <?php echo format_number($storage['utilization_pct'], 1); ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $storage['status'] == 'active' ? 'success' : 
                                            ($storage['status'] == 'maintenance' ? 'warning' : 'secondary'); 
                                    ?>">
                                        <?php echo ucfirst($storage['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($storage['location']); ?></td>
                                <td>
                                    <a href="?action=edit_storage&id=<?php echo $storage['storage_id']; ?>&tab=storages" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- Stock Summary Tab -->
    
    <?php
    // Get stock by storage
    $storage_stock = $db->query("
        SELECT * FROM vw_kernel_storage_summary
        ORDER BY current_stock_kg DESC
    ")->fetchAll();
    
    // Get recent movements
    $recent_movements = $db->query("
        SELECT 
            DATE(transaction_date) as date,
            SUM(CASE WHEN transaction_type = 'in' THEN quantity_kg ELSE 0 END) as stock_in,
            SUM(CASE WHEN transaction_type = 'out' THEN quantity_kg ELSE 0 END) as stock_out,
            SUM(CASE WHEN transaction_type = 'adjustment' THEN quantity_kg ELSE 0 END) as adjustments
        FROM kernel_stock_transactions
        WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(transaction_date)
        ORDER BY date DESC
        LIMIT 10
    ")->fetchAll();
    ?>
    
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-pie-chart"></i> Stock by Storage
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Storage</th>
                                <th class="text-end">Stock (kg)</th>
                                <th class="text-end">Capacity %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($storage_stock as $ss): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($ss['storage_code']); ?></strong></td>
                                    <td class="text-end"><?php echo format_number($ss['current_stock_kg'], 0); ?></td>
                                    <td class="text-end">
                                        <span class="badge bg-<?php 
                                            $util = ($ss['current_stock_kg'] / $ss['capacity_kg'] * 100);
                                            echo $util >= 90 ? 'danger' : ($util >= 70 ? 'warning' : 'success'); 
                                        ?>">
                                            <?php echo format_number($util, 1); ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-graph-up"></i> Recent Stock Movements (Last 30 Days)
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-end">In (kg)</th>
                                <th class="text-end">Out (kg)</th>
                                <th class="text-end">Net (kg)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_movements as $mov): ?>
                                <tr>
                                    <td><?php echo format_date($mov['date']); ?></td>
                                    <td class="text-end text-success"><?php echo format_number($mov['stock_in'], 0); ?></td>
                                    <td class="text-end text-danger"><?php echo format_number($mov['stock_out'], 0); ?></td>
                                    <td class="text-end">
                                        <strong><?php echo format_number($mov['stock_in'] - $mov['stock_out'] + $mov['adjustments'], 0); ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTransactionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="kernel_stock.php">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <?php echo $edit_transaction ? 'Edit Transaction' : 'Add Stock Transaction'; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_transaction ? 'edit_transaction' : 'add_transaction'; ?>">
                    <?php if ($edit_transaction): ?>
                        <input type="hidden" name="transaction_id" value="<?php echo $edit_transaction['transaction_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Transaction Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="transaction_date" required
                                   value="<?php echo $edit_transaction ? $edit_transaction['transaction_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Transaction Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="transaction_time" required
                                   value="<?php echo $edit_transaction ? date('H:i', strtotime($edit_transaction['transaction_time'])) : date('H:i'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Transaction Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="transaction_type" id="transaction_type" required onchange="toggleDestinationStorage()">
                                <?php foreach ($transaction_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($edit_transaction && $edit_transaction['transaction_type'] == $type) ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Source Storage <span class="text-danger">*</span></label>
                            <select class="form-select" name="storage_id" id="storage_id" required>
                                <option value="">Select Storage</option>
                                <?php foreach ($storages as $storage): ?>
                                    <option value="<?php echo $storage['storage_id']; ?>"
                                        <?php echo ($edit_transaction && $edit_transaction['storage_id'] == $storage['storage_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($storage['storage_code'] . ' - ' . $storage['storage_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="destination_storage_field" style="display:none;">
                            <label class="form-label">Destination Storage <span class="text-danger">*</span></label>
                            <select class="form-select" name="destination_storage_id" id="destination_storage_id">
                                <option value="">Select Destination Storage</option>
                                <?php foreach ($storages as $storage): ?>
                                    <option value="<?php echo $storage['storage_id']; ?>"
                                        <?php echo ($edit_transaction && $edit_transaction['destination_storage_id'] == $storage['storage_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($storage['storage_code'] . ' - ' . $storage['storage_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="production_field">
                            <label class="form-label">Production Batch (Optional)</label>
                            <select class="form-select" name="production_id" id="production_id">
                                <option value="">Select Production</option>
                                <?php foreach ($productions as $prod): ?>
                                    <option value="<?php echo $prod['production_id']; ?>"
                                        <?php echo ($edit_transaction && $edit_transaction['production_id'] == $prod['production_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($prod['batch_no'] . ' - ' . format_date($prod['production_date'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity (kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="quantity_kg" required
                                   value="<?php echo $edit_transaction ? $edit_transaction['quantity_kg'] : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reference No</label>
                            <input type="text" class="form-control" name="reference_no"
                                   value="<?php echo $edit_transaction ? htmlspecialchars($edit_transaction['reference_no']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="3"><?php echo $edit_transaction ? htmlspecialchars($edit_transaction['remarks']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_transaction ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Storage Modal -->
<div class="modal fade" id="addStorageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="kernel_stock.php">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <?php echo $edit_storage ? 'Edit Storage Location' : 'Add Storage Location'; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_storage ? 'edit_storage' : 'add_storage'; ?>">
                    <?php if ($edit_storage): ?>
                        <input type="hidden" name="storage_id" value="<?php echo $edit_storage['storage_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Storage Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="storage_code" required
                                   value="<?php echo $edit_storage ? htmlspecialchars($edit_storage['storage_code']) : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Storage Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="storage_name" required
                                   value="<?php echo $edit_storage ? htmlspecialchars($edit_storage['storage_name']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Storage Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="storage_type" required>
                                <?php foreach ($storage_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($edit_storage && $edit_storage['storage_type'] == $type) ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Capacity (kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="capacity_kg" required
                                   value="<?php echo $edit_storage ? $edit_storage['capacity_kg'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" name="status" required>
                                <?php foreach ($storage_statuses as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo ($edit_storage && $edit_storage['status'] == $status) ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($status); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <input type="text" class="form-control" name="location"
                               value="<?php echo $edit_storage ? htmlspecialchars($edit_storage['location']) : ''; ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="3"><?php echo $edit_storage ? htmlspecialchars($edit_storage['remarks']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save"></i> <?php echo $edit_storage ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleDestinationStorage() {
    const transactionType = document.getElementById('transaction_type').value;
    const destinationField = document.getElementById('destination_storage_field');
    const destinationSelect = document.getElementById('destination_storage_id');
    const productionField = document.getElementById('production_field');
    
    if (transactionType === 'transfer') {
        destinationField.style.display = 'block';
        destinationSelect.required = true;
        productionField.style.display = 'none';
        document.getElementById('production_id').required = false;
    } else {
        destinationField.style.display = 'none';
        destinationSelect.required = false;
        productionField.style.display = 'block';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleDestinationStorage();
    
    <?php if ($edit_transaction): ?>
    var editModal = new bootstrap.Modal(document.getElementById('addTransactionModal'));
    editModal.show();
    <?php endif; ?>
});
</script>

<?php if ($edit_storage): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addStorageModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

</div> <!-- End kernel-theme -->

<?php require_once 'includes/footer.php'; ?>

// Made with Bob