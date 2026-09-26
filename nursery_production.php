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
                INSERT INTO nursery_maintenances (nursery_stock_id, activity_date, activity_type, description,
                                                 quantity_affected, materials_used, labor_hours, cost,
                                                 performed_by, notes, created_at, updated_at)
                VALUES (:stock_id, :activity_date, :activity_type, :description,
                        :quantity_affected, :materials_used, :labor_hours, :cost,
                        :performed_by, :notes, NOW(), NOW())
            ");
            
            $stmt->execute([
                ':stock_id' => post('stock_id'),
                ':activity_date' => post('activity_date'),
                ':activity_type' => post('activity_type'),
                ':description' => post('description'),
                ':quantity_affected' => post('quantity_affected'),
                ':materials_used' => post('materials_used'),
                ':labor_hours' => post('labor_hours'),
                ':cost' => post('cost'),
                ':performed_by' => post('performed_by'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Production activity recorded successfully!');
            redirect('nursery_production.php');
        } catch (PDOException $e) {
            set_message('error', 'Error recording activity: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE nursery_maintenances
                SET nursery_stock_id = :stock_id, activity_date = :activity_date, activity_type = :activity_type,
                    description = :description, quantity_affected = :quantity_affected,
                    materials_used = :materials_used, labor_hours = :labor_hours, cost = :cost,
                    performed_by = :performed_by, notes = :notes, updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':id' => post('maintenance_id'),
                ':stock_id' => post('stock_id'),
                ':activity_date' => post('activity_date'),
                ':activity_type' => post('activity_type'),
                ':description' => post('description'),
                ':quantity_affected' => post('quantity_affected'),
                ':materials_used' => post('materials_used'),
                ':labor_hours' => post('labor_hours'),
                ':cost' => post('cost'),
                ':performed_by' => post('performed_by'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Activity updated successfully!');
            redirect('nursery_production.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating activity: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM nursery_maintenances WHERE id = :id");
            $stmt->execute([':id' => post('maintenance_id')]);
            
            set_message('success', 'Activity deleted successfully!');
            redirect('nursery_production.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting activity: ' . $e->getMessage());
        }
    }
}

// Get activity for editing (before header)
$edit_activity = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT *, id as maintenance_id, nursery_stock_id as stock_id FROM nursery_maintenances WHERE id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_activity = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Nursery Production Activities";
require_once 'includes/header.php';

// Fetch active nursery stock
$stocks_stmt = $db->query("
    SELECT ns.id as stock_id, ns.batch_number, ns.status,
           ns.quantity_seeds, ns.quantity_sprouts, ns.quantity_polybag, ns.quantity_ready,
           bu.unit_name as nursery_name,
           pv.variety_name,
           ns.germination_date
    FROM nursery_stocks ns
    INNER JOIN business_units bu ON ns.business_unit_id = bu.business_unit_id
    INNER JOIN plant_varieties pv ON ns.plant_variety_id = pv.variety_id
    WHERE ns.status NOT IN ('Distributed')
    ORDER BY ns.germination_date DESC
");
$stocks = $stocks_stmt->fetchAll();

// Fetch production activities
$search = get('search', '');
$stock_filter = get('stock_id', '');
$activity_filter = get('activity_type', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');

$sql = "SELECT nm.*,
        nm.id as maintenance_id,
        nm.nursery_stock_id as stock_id,
        ns.batch_number,
        bu.unit_name as nursery_name,
        pv.variety_name
        FROM nursery_maintenances nm
        INNER JOIN nursery_stocks ns ON nm.nursery_stock_id = ns.id
        INNER JOIN business_units bu ON ns.business_unit_id = bu.business_unit_id
        INNER JOIN plant_varieties pv ON ns.plant_variety_id = pv.variety_id
        WHERE 1=1";

if ($search) {
    $sql .= " AND (ns.batch_number LIKE :search OR nm.description LIKE :search OR nm.performed_by LIKE :search)";
}
if ($stock_filter) {
    $sql .= " AND nm.nursery_stock_id = :stock_id";
}
if ($activity_filter) {
    $sql .= " AND nm.activity_type = :activity_type";
}
if ($date_from) {
    $sql .= " AND nm.activity_date >= :date_from";
}
if ($date_to) {
    $sql .= " AND nm.activity_date <= :date_to";
}

$sql .= " ORDER BY nm.activity_date DESC, nm.id DESC";

$stmt = $db->prepare($sql);
if ($search) {
    $stmt->bindValue(':search', "%$search%");
}
if ($stock_filter) {
    $stmt->bindValue(':stock_id', $stock_filter);
}
if ($activity_filter) {
    $stmt->bindValue(':activity_type', $activity_filter);
}
if ($date_from) {
    $stmt->bindValue(':date_from', $date_from);
}
if ($date_to) {
    $stmt->bindValue(':date_to', $date_to);
}
$stmt->execute();
$activities = $stmt->fetchAll();

// Calculate summary statistics
$total_cost = array_sum(array_column($activities, 'cost'));
$total_labor_hours = array_sum(array_column($activities, 'labor_hours'));
$watering_count = count(array_filter($activities, function($a) { return $a['activity_type'] == 'Watering'; }));
$fertilizing_count = count(array_filter($activities, function($a) { return $a['activity_type'] == 'Fertilizing'; }));
$pest_control_count = count(array_filter($activities, function($a) { return $a['activity_type'] == 'Pest Control'; }));

// Activity types
$activity_types = ['Watering', 'Fertilizing', 'Pest Control', 'Weeding', 'Sorting', 'Transfer', 'Other'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-clipboard-check"></i> Nursery Production Activities</h1>
            <p class="text-muted">Track daily maintenance and production activities</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Record Activity
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo count($activities); ?></h3>
                <p><i class="bi bi-list-check"></i> Total Activities</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3>Rp <?php echo format_number($total_cost, 0); ?></h3>
                <p><i class="bi bi-cash"></i> Total Cost</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_labor_hours, 1); ?></h3>
                <p><i class="bi bi-clock"></i> Labor Hours</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $watering_count + $fertilizing_count + $pest_control_count; ?></h3>
                <p><i class="bi bi-droplet"></i> Care Activities</p>
            </div>
        </div>
    </div>
</div>

<!-- Activity Breakdown -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bar-chart"></i> Activity Breakdown
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-2">
                        <h4 class="text-primary"><?php echo $watering_count; ?></h4>
                        <small>Watering</small>
                    </div>
                    <div class="col-md-2">
                        <h4 class="text-success"><?php echo $fertilizing_count; ?></h4>
                        <small>Fertilizing</small>
                    </div>
                    <div class="col-md-2">
                        <h4 class="text-warning"><?php echo $pest_control_count; ?></h4>
                        <small>Pest Control</small>
                    </div>
                    <div class="col-md-2">
                        <h4 class="text-info"><?php echo count(array_filter($activities, function($a) { return $a['activity_type'] == 'Weeding'; })); ?></h4>
                        <small>Weeding</small>
                    </div>
                    <div class="col-md-2">
                        <h4 class="text-secondary"><?php echo count(array_filter($activities, function($a) { return $a['activity_type'] == 'Sorting'; })); ?></h4>
                        <small>Sorting</small>
                    </div>
                    <div class="col-md-2">
                        <h4 class="text-dark"><?php echo count(array_filter($activities, function($a) { return $a['activity_type'] == 'Transfer'; })); ?></h4>
                        <small>Transfer</small>
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
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="stock_id">
                    <option value="">All Batches</option>
                    <?php foreach ($stocks as $stock): ?>
                        <option value="<?php echo $stock['stock_id']; ?>" <?php echo $stock_filter == $stock['stock_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($stock['batch_number'] . ' - ' . $stock['variety_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="activity_type">
                    <option value="">All Activities</option>
                    <?php foreach ($activity_types as $type): ?>
                        <option value="<?php echo $type; ?>" <?php echo $activity_filter == $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                <a href="nursery_production.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Activities Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Production Activities (<?php echo count($activities); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Batch</th>
                        <th>Variety</th>
                        <th>Activity Type</th>
                        <th>Description</th>
                        <th>Qty Affected</th>
                        <th>Materials</th>
                        <th>Labor Hours</th>
                        <th>Cost</th>
                        <th>Performed By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activities)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">No production activities found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                            <tr>
                                <td><?php echo format_date($activity['activity_date']); ?></td>
                                <td><strong><?php echo htmlspecialchars($activity['batch_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($activity['variety_name']); ?></td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo $activity['activity_type'] == 'Watering' ? 'primary' :
                                            ($activity['activity_type'] == 'Fertilizing' ? 'success' :
                                            ($activity['activity_type'] == 'Pest Control' ? 'warning' : 'secondary'));
                                    ?>">
                                        <?php echo htmlspecialchars($activity['activity_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars(substr($activity['description'], 0, 50)) . (strlen($activity['description']) > 50 ? '...' : ''); ?></td>
                                <td><?php echo $activity['quantity_affected'] ? format_number($activity['quantity_affected'], 0) : '-'; ?></td>
                                <td><?php echo htmlspecialchars($activity['materials_used']); ?></td>
                                <td><?php echo $activity['labor_hours'] ? format_number($activity['labor_hours'], 1) : '-'; ?></td>
                                <td>Rp <?php echo format_number($activity['cost'], 0); ?></td>
                                <td><?php echo htmlspecialchars($activity['performed_by']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $activity['maintenance_id']; ?>" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $activity['maintenance_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="nursery_production.php" style="display:inline;" onsubmit="return confirmDelete('Delete this activity?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="maintenance_id" value="<?php echo $activity['maintenance_id']; ?>">
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

<!-- View Details Modals (outside table to avoid invalid HTML) -->
<?php foreach ($activities as $activity): ?>
<div class="modal fade" id="viewModal<?php echo $activity['maintenance_id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Activity Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm">
                    <tr><th width="40%">Date:</th><td><?php echo format_date($activity['activity_date']); ?></td></tr>
                    <tr><th>Batch Number:</th><td><?php echo htmlspecialchars($activity['batch_number']); ?></td></tr>
                    <tr><th>Nursery:</th><td><?php echo htmlspecialchars($activity['nursery_name']); ?></td></tr>
                    <tr><th>Variety:</th><td><?php echo htmlspecialchars($activity['variety_name']); ?></td></tr>
                    <tr><th>Activity Type:</th><td><?php echo htmlspecialchars($activity['activity_type']); ?></td></tr>
                    <tr><th>Description:</th><td><?php echo htmlspecialchars($activity['description']); ?></td></tr>
                    <tr><th>Quantity Affected:</th><td><?php echo $activity['quantity_affected'] ? format_number($activity['quantity_affected'], 0) : '-'; ?></td></tr>
                    <tr><th>Materials Used:</th><td><?php echo htmlspecialchars($activity['materials_used']); ?></td></tr>
                    <tr><th>Labor Hours:</th><td><?php echo $activity['labor_hours'] ? format_number($activity['labor_hours'], 1) : '-'; ?></td></tr>
                    <tr><th>Cost:</th><td>Rp <?php echo format_number($activity['cost'], 0); ?></td></tr>
                    <tr><th>Performed By:</th><td><?php echo htmlspecialchars($activity['performed_by']); ?></td></tr>
                    <?php if ($activity['notes']): ?>
                    <tr><th>Notes:</th><td><?php echo htmlspecialchars($activity['notes']); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Add/Edit Activity Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="nursery_production.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_activity ? 'Edit Production Activity' : 'Record Production Activity'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_activity ? 'edit' : 'add'; ?>">
                    <?php if ($edit_activity): ?>
                        <input type="hidden" name="maintenance_id" value="<?php echo $edit_activity['maintenance_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Stock Batch <span class="text-danger">*</span></label>
                            <select class="form-select" name="stock_id" required id="stock_select">
                                <option value="">Select Batch</option>
                                <?php foreach ($stocks as $stock): ?>
                                    <option value="<?php echo $stock['stock_id']; ?>"
                                        data-nursery="<?php echo htmlspecialchars($stock['nursery_name']); ?>"
                                        data-variety="<?php echo htmlspecialchars($stock['variety_name']); ?>"
                                        data-status="<?php echo $stock['status']; ?>"
                                        <?php echo ($edit_activity && $edit_activity['stock_id'] == $stock['stock_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($stock['batch_number'] . ' - ' . $stock['variety_name'] . ' (' . $stock['status'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Activity Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="activity_date" required
                                   value="<?php echo $edit_activity ? $edit_activity['activity_date'] : date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Activity Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="activity_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($activity_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($edit_activity && $edit_activity['activity_type'] == $type) ? 'selected' : ''; ?>>
                                        <?php echo $type; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity Affected</label>
                            <input type="number" class="form-control" name="quantity_affected"
                                   value="<?php echo $edit_activity ? $edit_activity['quantity_affected'] : ''; ?>"
                                   placeholder="Number of seedlings">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="description" rows="2" required placeholder="Describe the activity..."><?php echo $edit_activity ? htmlspecialchars($edit_activity['description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Materials Used</label>
                        <input type="text" class="form-control" name="materials_used"
                               value="<?php echo $edit_activity ? htmlspecialchars($edit_activity['materials_used']) : ''; ?>"
                               placeholder="e.g., NPK 15-15-15, Pesticide XYZ">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Labor Hours</label>
                            <input type="number" step="0.1" class="form-control" name="labor_hours"
                                   value="<?php echo $edit_activity ? $edit_activity['labor_hours'] : ''; ?>"
                                   placeholder="e.g., 8.5">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Cost (Rp)</label>
                            <input type="number" class="form-control" name="cost"
                                   value="<?php echo $edit_activity ? $edit_activity['cost'] : ''; ?>"
                                   placeholder="e.g., 500000">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Performed By</label>
                            <input type="text" class="form-control" name="performed_by"
                                   value="<?php echo $edit_activity ? htmlspecialchars($edit_activity['performed_by']) : ''; ?>"
                                   placeholder="Worker name">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes..."><?php echo $edit_activity ? htmlspecialchars($edit_activity['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_activity ? 'Update' : 'Save'; ?> Activity
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_activity): ?>
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
