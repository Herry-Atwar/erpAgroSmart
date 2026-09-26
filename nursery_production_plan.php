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
                INSERT INTO nursery_production_plan (business_unit_id, variety_id, plan_year, plan_month,
                                                     target_quantity, germination_date, expected_ready_date,
                                                     actual_quantity, status, notes, created_by)
                VALUES (:business_unit_id, :variety_id, :plan_year, :plan_month,
                        :target_quantity, :germination_date, :expected_ready_date,
                        :actual_quantity, :status, :notes, 'admin')
            ");
            
            $stmt->execute([
                ':business_unit_id' => post('business_unit_id'),
                ':variety_id' => post('variety_id'),
                ':plan_year' => post('plan_year'),
                ':plan_month' => post('plan_month'),
                ':target_quantity' => post('target_quantity'),
                ':germination_date' => post('germination_date'),
                ':expected_ready_date' => post('expected_ready_date'),
                ':actual_quantity' => post('actual_quantity', 0),
                ':status' => post('status', 'Planned'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Production plan added successfully!');
            redirect('nursery_production_plan.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding production plan: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE nursery_production_plan 
                SET business_unit_id = :business_unit_id, variety_id = :variety_id,
                    plan_year = :plan_year, plan_month = :plan_month,
                    target_quantity = :target_quantity, germination_date = :germination_date,
                    expected_ready_date = :expected_ready_date, actual_quantity = :actual_quantity,
                    status = :status, notes = :notes, updated_by = 'admin'
                WHERE plan_id = :id
            ");
            
            $stmt->execute([
                ':id' => post('plan_id'),
                ':business_unit_id' => post('business_unit_id'),
                ':variety_id' => post('variety_id'),
                ':plan_year' => post('plan_year'),
                ':plan_month' => post('plan_month'),
                ':target_quantity' => post('target_quantity'),
                ':germination_date' => post('germination_date'),
                ':expected_ready_date' => post('expected_ready_date'),
                ':actual_quantity' => post('actual_quantity'),
                ':status' => post('status'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Production plan updated successfully!');
            redirect('nursery_production_plan.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating production plan: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM nursery_production_plan WHERE plan_id = :id");
            $stmt->execute([':id' => post('plan_id')]);
            
            set_message('success', 'Production plan deleted successfully!');
            redirect('nursery_production_plan.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting production plan: ' . $e->getMessage());
        }
    }
}

// Get plan for editing (before header)
$edit_plan = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM nursery_production_plan WHERE plan_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_plan = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Nursery Production Plan";
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

// Fetch production plans
$year_filter = get('year', date('Y'));
$nursery_filter = get('business_unit_id', '');
$status_filter = get('status', '');

$sql = "SELECT npp.*, 
        bu.unit_name as nursery_name, bu.unit_code as nursery_code,
        c.company_name,
        pv.variety_name, pv.variety_code
        FROM nursery_production_plan npp
        INNER JOIN business_units bu ON npp.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        INNER JOIN plant_varieties pv ON npp.variety_id = pv.variety_id
        WHERE npp.plan_year = :year";

if ($nursery_filter) {
    $sql .= " AND npp.business_unit_id = :business_unit_id";
}
if ($status_filter) {
    $sql .= " AND npp.status = :status";
}

$sql .= " ORDER BY npp.plan_year DESC, npp.plan_month, bu.unit_name";

$stmt = $db->prepare($sql);
$stmt->bindValue(':year', $year_filter);
if ($nursery_filter) {
    $stmt->bindValue(':business_unit_id', $nursery_filter);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$plans = $stmt->fetchAll();

// Calculate summary statistics
$total_target = array_sum(array_column($plans, 'target_quantity'));
$total_actual = array_sum(array_column($plans, 'actual_quantity'));
$achievement_rate = $total_target > 0 ? ($total_actual / $total_target * 100) : 0;
$completed_plans = count(array_filter($plans, function($p) { return $p['status'] == 'Completed'; }));

// Month names
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-calendar-check"></i> Nursery Production Plan</h1>
            <p class="text-muted">Plan and track seedling production targets</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add Production Plan
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_target, 0); ?></h3>
                <p><i class="bi bi-bullseye"></i> Target Quantity</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_actual, 0); ?></h3>
                <p><i class="bi bi-check-circle"></i> Actual Quantity</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($achievement_rate, 1); ?>%</h3>
                <p><i class="bi bi-graph-up"></i> Achievement Rate</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $completed_plans; ?></h3>
                <p><i class="bi bi-clipboard-check"></i> Completed Plans</p>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Year</label>
                <select class="form-select" name="year">
                    <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Nursery</label>
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
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="Planned" <?php echo $status_filter == 'Planned' ? 'selected' : ''; ?>>Planned</option>
                    <option value="In Progress" <?php echo $status_filter == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="Completed" <?php echo $status_filter == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="Cancelled" <?php echo $status_filter == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Production Plan Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Production Plans for <?php echo $year_filter; ?> (<?php echo count($plans); ?> plans)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Nursery</th>
                        <th>Variety</th>
                        <th>Target Qty</th>
                        <th>Actual Qty</th>
                        <th>Achievement</th>
                        <th>Germination Date</th>
                        <th>Expected Ready</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No production plans found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($plans as $plan): 
                            $achievement = $plan['target_quantity'] > 0 ? ($plan['actual_quantity'] / $plan['target_quantity'] * 100) : 0;
                        ?>
                            <tr>
                                <td><strong><?php echo $months[$plan['plan_month']] . ' ' . $plan['plan_year']; ?></strong></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($plan['company_name']); ?></small><br>
                                    <?php echo htmlspecialchars($plan['nursery_name']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($plan['variety_name']); ?></td>
                                <td><?php echo format_number($plan['target_quantity'], 0); ?></td>
                                <td><?php echo format_number($plan['actual_quantity'], 0); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $achievement >= 100 ? 'success' : ($achievement >= 80 ? 'warning' : 'danger'); ?>">
                                        <?php echo format_number($achievement, 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo $plan['germination_date'] ? format_date($plan['germination_date']) : '-'; ?></td>
                                <td><?php echo $plan['expected_ready_date'] ? format_date($plan['expected_ready_date']) : '-'; ?></td>
                                <td><?php echo get_status_badge($plan['status']); ?></td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $plan['plan_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="nursery_production_plan.php" style="display:inline;" onsubmit="return confirmDelete('Delete this production plan?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="plan_id" value="<?php echo $plan['plan_id']; ?>">
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
            <form method="POST" action="nursery_production_plan.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_plan ? 'Edit Production Plan' : 'Add Production Plan'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_plan ? 'edit' : 'add'; ?>">
                    <?php if ($edit_plan): ?>
                        <input type="hidden" name="plan_id" value="<?php echo $edit_plan['plan_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nursery <span class="text-danger">*</span></label>
                            <select class="form-select" name="business_unit_id" required>
                                <option value="">Select Nursery</option>
                                <?php foreach ($nurseries as $nursery): ?>
                                    <option value="<?php echo $nursery['business_unit_id']; ?>" 
                                        <?php echo ($edit_plan && $edit_plan['business_unit_id'] == $nursery['business_unit_id']) ? 'selected' : ''; ?>>
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
                                        <?php echo ($edit_plan && $edit_plan['variety_id'] == $variety['variety_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($variety['variety_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Plan Year <span class="text-danger">*</span></label>
                            <select class="form-select" name="plan_year" required>
                                <?php for ($y = date('Y') - 1; $y <= date('Y') + 3; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($edit_plan && $edit_plan['plan_year'] == $y) ? 'selected' : ($y == date('Y') ? 'selected' : ''); ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Plan Month <span class="text-danger">*</span></label>
                            <select class="form-select" name="plan_month" required>
                                <?php foreach ($months as $num => $name): ?>
                                    <option value="<?php echo $num; ?>" <?php echo ($edit_plan && $edit_plan['plan_month'] == $num) ? 'selected' : ''; ?>>
                                        <?php echo $name; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Target Quantity <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="target_quantity" required
                                   value="<?php echo $edit_plan ? $edit_plan['target_quantity'] : ''; ?>"
                                   placeholder="e.g., 10000">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Actual Quantity</label>
                            <input type="number" class="form-control" name="actual_quantity"
                                   value="<?php echo $edit_plan ? $edit_plan['actual_quantity'] : '0'; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Germination Date</label>
                            <input type="date" class="form-control" name="germination_date"
                                   value="<?php echo $edit_plan ? $edit_plan['germination_date'] : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Expected Ready Date</label>
                            <input type="date" class="form-control" name="expected_ready_date"
                                   value="<?php echo $edit_plan ? $edit_plan['expected_ready_date'] : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="Planned" <?php echo ($edit_plan && $edit_plan['status'] == 'Planned') ? 'selected' : ''; ?>>Planned</option>
                            <option value="In Progress" <?php echo ($edit_plan && $edit_plan['status'] == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="Completed" <?php echo ($edit_plan && $edit_plan['status'] == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="Cancelled" <?php echo ($edit_plan && $edit_plan['status'] == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo $edit_plan ? htmlspecialchars($edit_plan['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_plan ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_plan): ?>
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
