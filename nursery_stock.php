<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO nursery_stocks (business_unit_id, plant_variety_id, batch_number, seed_source,
                                         germination_date, quantity_seeds, quantity_sprouts, quantity_polybag,
                                         quantity_ready, status, notes, created_at, updated_at)
                VALUES (:business_unit_id, :variety_id, :batch_number, :seed_source,
                        :germination_date, :quantity_seeds, :quantity_sprouts, :quantity_polybag,
                        :quantity_ready, :status, :notes, NOW(), NOW())
            ");
            
            $stmt->execute([
                ':business_unit_id' => post('business_unit_id'),
                ':variety_id' => post('variety_id'),
                ':batch_number' => post('batch_number'),
                ':seed_source' => post('seed_source'),
                ':germination_date' => post('germination_date'),
                ':quantity_seeds' => post('quantity_seeds', 0),
                ':quantity_sprouts' => post('quantity_sprouts', 0),
                ':quantity_polybag' => post('quantity_polybag', 0),
                ':quantity_ready' => post('quantity_ready', 0),
                ':status' => post('status', 'Germination'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Nursery stock added successfully!');
            redirect('nursery_stock.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding nursery stock: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE nursery_stocks
                SET business_unit_id = :business_unit_id, plant_variety_id = :variety_id,
                    batch_number = :batch_number, seed_source = :seed_source,
                    germination_date = :germination_date, quantity_seeds = :quantity_seeds,
                    quantity_sprouts = :quantity_sprouts, quantity_polybag = :quantity_polybag,
                    quantity_ready = :quantity_ready, status = :status, notes = :notes, updated_at = NOW()
                WHERE stock_id = :id
            ");
            
            $stmt->execute([
                ':id' => post('stock_id'),
                ':business_unit_id' => post('business_unit_id'),
                ':variety_id' => post('variety_id'),
                ':batch_number' => post('batch_number'),
                ':seed_source' => post('seed_source'),
                ':germination_date' => post('germination_date'),
                ':quantity_seeds' => post('quantity_seeds'),
                ':quantity_sprouts' => post('quantity_sprouts'),
                ':quantity_polybag' => post('quantity_polybag'),
                ':quantity_ready' => post('quantity_ready'),
                ':status' => post('status'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Nursery stock updated successfully!');
            redirect('nursery_stock.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating nursery stock: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM nursery_stocks WHERE stock_id = :id");
            $stmt->execute([':id' => post('stock_id')]);
            
            set_message('success', 'Nursery stock deleted successfully!');
            redirect('nursery_stock.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting nursery stock: ' . $e->getMessage());
        }
    }
}

// Get stock for editing (before header)
$edit_stock = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM nursery_stocks WHERE stock_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_stock = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Nursery Stock Management";
require_once 'includes/header.php';

// Fetch business units (nurseries)
$nurseries_stmt = $db->query("
    SELECT bu.business_unit_id, bu.unit_code, bu.unit_name, c.company_name 
    FROM business_units bu
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE bu.unit_type = 'Nursery' AND bu.status = 'Active'
    ORDER BY c.company_name, bu.unit_name
");
$nurseries = $nurseries_stmt->fetchAll();

// Fetch plant varieties
$varieties_stmt = $db->query("SELECT variety_id, variety_code, variety_name FROM plant_varieties WHERE status = 'Active' ORDER BY variety_name");
$varieties = $varieties_stmt->fetchAll();

// Fetch nursery stock with statistics
$search = get('search', '');
$nursery_filter = get('business_unit_id', '');
$status_filter = get('status', '');

$sql = "SELECT ns.*,
        ns.stock_id,
        bu.unit_name as nursery_name, bu.unit_code as nursery_code,
        c.company_name,
        pv.variety_name, pv.variety_code
        FROM nursery_stocks ns
        INNER JOIN business_units bu ON ns.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        INNER JOIN plant_varieties pv ON ns.plant_variety_id = pv.variety_id
        WHERE 1=1";

if ($search) {
    $sql .= " AND (ns.batch_number LIKE :search OR pv.variety_name LIKE :search)";
}
if ($nursery_filter) {
    $sql .= " AND ns.business_unit_id = :business_unit_id";
}
if ($status_filter) {
    $sql .= " AND ns.status = :status";
}

$sql .= " ORDER BY ns.germination_date DESC, ns.batch_number";

$stmt = $db->prepare($sql);
if ($search) {
    $stmt->bindValue(':search', "%$search%");
}
if ($nursery_filter) {
    $stmt->bindValue(':business_unit_id', $nursery_filter);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$stocks = $stmt->fetchAll();

// Calculate summary statistics
$total_seeds = array_sum(array_column($stocks, 'quantity_seeds'));
$total_sprouts = array_sum(array_column($stocks, 'quantity_sprouts'));
$total_polybag = array_sum(array_column($stocks, 'quantity_polybag'));
$total_ready = array_sum(array_column($stocks, 'quantity_ready'));
?>

<style>
    /* Custom yellowish green header for nursery stock page */
    .card-header {
        background-color: #689f38 !important;
        color: white !important;
    }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-box-seam"></i> Nursery Stock Management</h1>
            <p class="text-muted">Manage seedling inventory and track growth stages</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New Stock
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_seeds, 0); ?></h3>
                <p><i class="bi bi-circle"></i> Total Seeds</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_sprouts, 0); ?></h3>
                <p><i class="bi bi-flower2"></i> Sprouts</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_polybag, 0); ?></h3>
                <p><i class="bi bi-bag"></i> In Polybag</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_ready, 0); ?></h3>
                <p><i class="bi bi-check-circle"></i> Ready for Planting</p>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="Search by batch or variety..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="business_unit_id">
                    <option value="">All Nurseries</option>
                    <?php foreach ($nurseries as $nursery): ?>
                        <option value="<?php echo $nursery['business_unit_id']; ?>" <?php echo $nursery_filter == $nursery['business_unit_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($nursery['company_name'] . ' - ' . $nursery['unit_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="Germination" <?php echo $status_filter == 'Germination' ? 'selected' : ''; ?>>Germination</option>
                    <option value="Sprout" <?php echo $status_filter == 'Sprout' ? 'selected' : ''; ?>>Sprout</option>
                    <option value="Polybag" <?php echo $status_filter == 'Polybag' ? 'selected' : ''; ?>>Polybag</option>
                    <option value="Ready" <?php echo $status_filter == 'Ready' ? 'selected' : ''; ?>>Ready</option>
                    <option value="Distributed" <?php echo $status_filter == 'Distributed' ? 'selected' : ''; ?>>Distributed</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                <a href="nursery_stock.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Stock Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Nursery Stock List (<?php echo count($stocks); ?> batches)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Batch Number</th>
                        <th>Nursery</th>
                        <th>Variety</th>
                        <th>Germination Date</th>
                        <th>Seeds</th>
                        <th>Sprouts</th>
                        <th>Polybag</th>
                        <th>Ready</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stocks)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No nursery stock found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stocks as $stock): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($stock['batch_number']); ?></strong></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($stock['company_name']); ?></small><br>
                                    <?php echo htmlspecialchars($stock['nursery_name']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($stock['variety_name']); ?></td>
                                <td><?php echo format_date($stock['germination_date']); ?></td>
                                <td><?php echo format_number($stock['quantity_seeds'], 0); ?></td>
                                <td><?php echo format_number($stock['quantity_sprouts'], 0); ?></td>
                                <td><?php echo format_number($stock['quantity_polybag'], 0); ?></td>
                                <td><?php echo format_number($stock['quantity_ready'], 0); ?></td>
                                <td><?php echo get_status_badge($stock['status']); ?></td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $stock['stock_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="nursery_stock.php" style="display:inline;" onsubmit="return confirmDelete('Delete this stock batch?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="stock_id" value="<?php echo $stock['stock_id']; ?>">
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

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="nursery_stock.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_stock ? 'Edit Nursery Stock' : 'Add New Nursery Stock'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_stock ? 'edit' : 'add'; ?>">
                    <?php if ($edit_stock): ?>
                        <input type="hidden" name="stock_id" value="<?php echo $edit_stock['stock_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nursery <span class="text-danger">*</span></label>
                            <select class="form-select" name="business_unit_id" required>
                                <option value="">Select Nursery</option>
                                <?php foreach ($nurseries as $nursery): ?>
                                    <option value="<?php echo $nursery['business_unit_id']; ?>" 
                                        <?php echo ($edit_stock && $edit_stock['business_unit_id'] == $nursery['business_unit_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($nursery['company_name'] . ' - ' . $nursery['unit_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Variety <span class="text-danger">*</span></label>
                            <select class="form-select" name="variety_id" required>
                                <option value="">Select Variety</option>
                                <?php foreach ($varieties as $variety): ?>
                                    <option value="<?php echo $variety['variety_id']; ?>" 
                                        <?php echo ($edit_stock && $edit_stock['variety_id'] == $variety['variety_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($variety['variety_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Batch Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="batch_number" required 
                                   value="<?php echo $edit_stock ? htmlspecialchars($edit_stock['batch_number']) : ''; ?>"
                                   placeholder="e.g., BATCH-2024-001">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Germination Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="germination_date" required
                                   value="<?php echo $edit_stock ? $edit_stock['germination_date'] : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Seed Source</label>
                        <input type="text" class="form-control" name="seed_source"
                               value="<?php echo $edit_stock ? htmlspecialchars($edit_stock['seed_source']) : ''; ?>"
                               placeholder="e.g., PPKS, Local">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity Seeds</label>
                            <input type="number" class="form-control" name="quantity_seeds"
                                   value="<?php echo $edit_stock ? $edit_stock['quantity_seeds'] : '0'; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity Sprouts</label>
                            <input type="number" class="form-control" name="quantity_sprouts"
                                   value="<?php echo $edit_stock ? $edit_stock['quantity_sprouts'] : '0'; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity in Polybag</label>
                            <input type="number" class="form-control" name="quantity_polybag"
                                   value="<?php echo $edit_stock ? $edit_stock['quantity_polybag'] : '0'; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity Ready</label>
                            <input type="number" class="form-control" name="quantity_ready"
                                   value="<?php echo $edit_stock ? $edit_stock['quantity_ready'] : '0'; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="Germination" <?php echo ($edit_stock && $edit_stock['status'] == 'Germination') ? 'selected' : ''; ?>>Germination</option>
                            <option value="Sprout" <?php echo ($edit_stock && $edit_stock['status'] == 'Sprout') ? 'selected' : ''; ?>>Sprout</option>
                            <option value="Polybag" <?php echo ($edit_stock && $edit_stock['status'] == 'Polybag') ? 'selected' : ''; ?>>Polybag</option>
                            <option value="Ready" <?php echo ($edit_stock && $edit_stock['status'] == 'Ready') ? 'selected' : ''; ?>>Ready</option>
                            <option value="Distributed" <?php echo ($edit_stock && $edit_stock['status'] == 'Distributed') ? 'selected' : ''; ?>>Distributed</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo $edit_stock ? htmlspecialchars($edit_stock['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_stock ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_stock): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

<script>
function confirmDelete(message) {
    return confirm(message);
}
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob