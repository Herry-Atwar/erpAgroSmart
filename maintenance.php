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
                INSERT INTO maintenance_activities 
                (work_order_id, block_id, activity_date, activity_type, area_covered,
                 labor_count, labor_hours, equipment_used, materials_used, cost,
                 performed_by, supervisor, status, description, notes, created_by)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('work_order_id') ?: null,
                post('block_id'),
                post('activity_date'),
                post('activity_type'),
                post('area_covered') ?: null,
                post('labor_count') ?: null,
                post('labor_hours') ?: null,
                post('equipment_used') ?: null,
                post('materials_used') ?: null,
                post('cost') ?: null,
                post('performed_by') ?: null,
                post('supervisor') ?: null,
                post('status') ?: 'Planned',
                post('description') ?: null,
                post('notes') ?: null,
                'admin'
            ]);
            
            set_message('success', 'Maintenance activity recorded successfully!');
            redirect('maintenance.php');
        } catch (PDOException $e) {
            set_message('error', 'Error recording activity: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE maintenance_activities 
                SET work_order_id = ?, block_id = ?, activity_date = ?, activity_type = ?,
                    area_covered = ?, labor_count = ?, labor_hours = ?, equipment_used = ?,
                    materials_used = ?, cost = ?, performed_by = ?, supervisor = ?,
                    status = ?, description = ?, notes = ?
                WHERE activity_id = ?
            ");
            
            $stmt->execute([
                post('work_order_id') ?: null,
                post('block_id'),
                post('activity_date'),
                post('activity_type'),
                post('area_covered') ?: null,
                post('labor_count') ?: null,
                post('labor_hours') ?: null,
                post('equipment_used') ?: null,
                post('materials_used') ?: null,
                post('cost') ?: null,
                post('performed_by') ?: null,
                post('supervisor') ?: null,
                post('status'),
                post('description') ?: null,
                post('notes') ?: null,
                post('activity_id')
            ]);
            
            set_message('success', 'Maintenance activity updated successfully!');
            redirect('maintenance.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating activity: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM maintenance_activities WHERE activity_id = ?");
            $stmt->execute([post('activity_id')]);
            
            set_message('success', 'Maintenance activity deleted successfully!');
            redirect('maintenance.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting activity: ' . $e->getMessage());
        }
    }
}

// Get activity for editing (before header)
$edit_activity = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM maintenance_activities WHERE activity_id = ?");
    $stmt->execute([get('id')]);
    $edit_activity = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Maintenance Activities";
require_once 'includes/header.php';
?>

<style>
    /* Custom teal theme for maintenance page */
    .card-header {
        background-color: #006359 !important;
        color: white !important;
    }
    
    .page-header h1 {
        color: #006359 !important;
    }
    
    .page-header {
        border-bottom-color: #006359 !important;
    }
    
    .stat-card {
        border-left-color: #006359 !important;
    }
    
    .stat-card h3 {
        color: #006359 !important;
    }
    
    .btn-primary {
        background-color: #006359 !important;
        border-color: #006359 !important;
    }
    
    .btn-primary:hover {
        background-color: #004d45 !important;
        border-color: #004d45 !important;
    }
    
    .text-primary {
        color: #006359 !important;
    }
</style>

<?php
// Fetch blocks for dropdown
$blocks_stmt = $db->query("
    SELECT b.block_id, b.block_code, b.block_name,
           py.year, d.division_name, bu.unit_name, c.company_name
    FROM blocks b
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE b.status IN ('TBM', 'TM', 'TR')
    ORDER BY c.company_name, bu.unit_name, d.division_name, b.block_name
");
$blocks = $blocks_stmt->fetchAll();

// Fetch work orders for dropdown
$work_orders_stmt = $db->query("
    SELECT wo.work_order_id, wo.work_order_number, b.block_name
    FROM work_orders wo
    INNER JOIN blocks b ON wo.block_id = b.block_id
    WHERE wo.status IN ('Planned', 'Assigned', 'In Progress')
    ORDER BY wo.work_order_number DESC
");
$work_orders = $work_orders_stmt->fetchAll();

// Fetch maintenance activities with filters
$search = get('search', '');
$activity_type_filter = get('activity_type', '');
$status_filter = get('status', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');

$sql = "SELECT ma.*, 
        b.block_code, b.block_name,
        py.year as planting_year,
        d.division_name,
        bu.unit_name as estate_name,
        c.company_name,
        wo.work_order_number
        FROM maintenance_activities ma
        INNER JOIN blocks b ON ma.block_id = b.block_id
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        LEFT JOIN work_orders wo ON ma.work_order_id = wo.work_order_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (b.block_name LIKE ? OR ma.performed_by LIKE ? OR wo.work_order_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($activity_type_filter) {
    $sql .= " AND ma.activity_type = ?";
    $params[] = $activity_type_filter;
}
if ($status_filter) {
    $sql .= " AND ma.status = ?";
    $params[] = $status_filter;
}
if ($date_from) {
    $sql .= " AND ma.activity_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND ma.activity_date <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY ma.activity_date DESC, ma.activity_id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

// Calculate summary statistics
$total_activities = count($activities);
$total_area = array_sum(array_column($activities, 'area_covered'));
$total_labor_hours = array_sum(array_column($activities, 'labor_hours'));
$total_cost = array_sum(array_column($activities, 'cost'));
$completed_count = count(array_filter($activities, function($a) { return $a['status'] == 'Completed'; }));

// Activity types and statuses
$activity_types = ['Weeding', 'Pruning', 'Path Maintenance', 'Drainage', 'Infrastructure', 'Other'];
$statuses = ['Planned', 'In Progress', 'Completed'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-tools"></i> Maintenance Activities</h1>
            <p class="text-muted">Track field maintenance operations</p>
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
                <h3><?php echo $total_activities; ?></h3>
                <p><i class="bi bi-list-check"></i> Total Activities</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_area, 1); ?> Ha</h3>
                <p><i class="bi bi-map"></i> Area Covered</p>
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
                <h3>Rp <?php echo format_number($total_cost, 0); ?></h3>
                <p><i class="bi bi-cash"></i> Total Cost</p>
            </div>
        </div>
    </div>
</div>

<!-- Activity Type Breakdown -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bar-chart"></i> Activity Breakdown
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <?php foreach ($activity_types as $type): 
                        $count = count(array_filter($activities, function($a) use ($type) { return $a['activity_type'] == $type; }));
                    ?>
                    <div class="col-md-2">
                        <h4 class="text-primary"><?php echo $count; ?></h4>
                        <small><?php echo $type; ?></small>
                    </div>
                    <?php endforeach; ?>
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
            <div class="col-md-2">
                <select class="form-select" name="activity_type">
                    <option value="">All Activity Types</option>
                    <?php foreach ($activity_types as $type): ?>
                        <option value="<?php echo $type; ?>" <?php echo $activity_type_filter == $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $status_filter == $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- Activities Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Maintenance Activities (<?php echo count($activities); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>WO Number</th>
                        <th>Block</th>
                        <th>Activity Type</th>
                        <th>Area (Ha)</th>
                        <th>Labor</th>
                        <th>Hours</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activities)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No maintenance activities found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                            <tr>
                                <td><?php echo format_date($activity['activity_date']); ?></td>
                                <td><?php echo $activity['work_order_number'] ? htmlspecialchars($activity['work_order_number']) : '-'; ?></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($activity['estate_name']); ?></small><br>
                                    <?php echo htmlspecialchars($activity['block_name']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $activity['activity_type'] == 'Weeding' ? 'success' : 
                                            ($activity['activity_type'] == 'Pruning' ? 'warning' : 
                                            ($activity['activity_type'] == 'Path Maintenance' ? 'info' : 'secondary')); 
                                    ?>">
                                        <?php echo htmlspecialchars($activity['activity_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo $activity['area_covered'] ? format_number($activity['area_covered'], 1) : '-'; ?></td>
                                <td><?php echo $activity['labor_count'] ? $activity['labor_count'] : '-'; ?></td>
                                <td><?php echo $activity['labor_hours'] ? format_number($activity['labor_hours'], 1) : '-'; ?></td>
                                <td>Rp <?php echo format_number($activity['cost'], 0); ?></td>
                                <td><?php echo get_status_badge($activity['status']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $activity['activity_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $activity['activity_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="maintenance.php" style="display:inline;" onsubmit="return confirmDelete('Delete this activity?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="activity_id" value="<?php echo $activity['activity_id']; ?>">
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
<div class="modal fade" id="viewModal<?php echo $activity['activity_id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Maintenance Activity Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr><th width="40%">Activity Date:</th><td><?php echo format_date($activity['activity_date']); ?></td></tr>
                            <tr><th>WO Number:</th><td><?php echo $activity['work_order_number'] ? htmlspecialchars($activity['work_order_number']) : '-'; ?></td></tr>
                            <tr><th>Block:</th><td><?php echo htmlspecialchars($activity['block_name']); ?></td></tr>
                            <tr><th>Estate:</th><td><?php echo htmlspecialchars($activity['estate_name']); ?></td></tr>
                            <tr><th>Activity Type:</th><td><?php echo htmlspecialchars($activity['activity_type']); ?></td></tr>
                            <tr><th>Status:</th><td><?php echo htmlspecialchars($activity['status']); ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr><th width="40%">Area Covered:</th><td><?php echo $activity['area_covered'] ? format_number($activity['area_covered'], 1) . ' Ha' : '-'; ?></td></tr>
                            <tr><th>Labor Count:</th><td><?php echo $activity['labor_count'] ?: '-'; ?></td></tr>
                            <tr><th>Labor Hours:</th><td><?php echo $activity['labor_hours'] ? format_number($activity['labor_hours'], 1) : '-'; ?></td></tr>
                            <tr><th>Cost:</th><td>Rp <?php echo format_number($activity['cost'], 0); ?></td></tr>
                            <tr><th>Performed By:</th><td><?php echo htmlspecialchars($activity['performed_by']); ?></td></tr>
                            <tr><th>Supervisor:</th><td><?php echo htmlspecialchars($activity['supervisor']); ?></td></tr>
                        </table>
                    </div>
                </div>
                <?php if ($activity['equipment_used']): ?>
                <div class="mt-3"><h6>Equipment Used:</h6><p><?php echo htmlspecialchars($activity['equipment_used']); ?></p></div>
                <?php endif; ?>
                <?php if ($activity['materials_used']): ?>
                <div class="mt-3"><h6>Materials Used:</h6><p><?php echo htmlspecialchars($activity['materials_used']); ?></p></div>
                <?php endif; ?>
                <?php if ($activity['description']): ?>
                <div class="mt-3"><h6>Description:</h6><p><?php echo nl2br(htmlspecialchars($activity['description'])); ?></p></div>
                <?php endif; ?>
                <?php if ($activity['notes']): ?>
                <div class="mt-3"><h6>Notes:</h6><p><?php echo nl2br(htmlspecialchars($activity['notes'])); ?></p></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="maintenance.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_activity ? 'Edit Maintenance Activity' : 'Record Maintenance Activity'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_activity ? 'edit' : 'add'; ?>">
                    <?php if ($edit_activity): ?>
                        <input type="hidden" name="activity_id" value="<?php echo $edit_activity['activity_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Work Order (Optional)</label>
                            <select class="form-select" name="work_order_id">
                                <option value="">No Work Order</option>
                                <?php foreach ($work_orders as $wo): ?>
                                    <option value="<?php echo $wo['work_order_id']; ?>" 
                                        <?php echo ($edit_activity && $edit_activity['work_order_id'] == $wo['work_order_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($wo['work_order_number'] . ' - ' . $wo['block_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Block <span class="text-danger">*</span></label>
                            <select class="form-select" name="block_id" required>
                                <option value="">Select Block</option>
                                <?php foreach ($blocks as $block): ?>
                                    <option value="<?php echo $block['block_id']; ?>" 
                                        <?php echo ($edit_activity && $edit_activity['block_id'] == $block['block_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($block['company_name'] . ' - ' . $block['unit_name'] . ' - ' . $block['block_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Activity Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="activity_date" required
                                   value="<?php echo $edit_activity ? $edit_activity['activity_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
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
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo ($edit_activity && $edit_activity['status'] == $status) ? 'selected' : ((!$edit_activity && $status == 'Planned') ? 'selected' : ''); ?>>
                                        <?php echo $status; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Area Covered (Ha)</label>
                            <input type="number" step="0.01" class="form-control" name="area_covered"
                                   value="<?php echo $edit_activity ? $edit_activity['area_covered'] : ''; ?>"
                                   placeholder="e.g., 5.5">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Labor Count</label>
                            <input type="number" class="form-control" name="labor_count"
                                   value="<?php echo $edit_activity ? $edit_activity['labor_count'] : ''; ?>"
                                   placeholder="Number of workers">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Labor Hours</label>
                            <input type="number" step="0.1" class="form-control" name="labor_hours"
                                   value="<?php echo $edit_activity ? $edit_activity['labor_hours'] : ''; ?>"
                                   placeholder="e.g., 40.5">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Equipment Used</label>
                            <input type="text" class="form-control" name="equipment_used"
                                   value="<?php echo $edit_activity ? htmlspecialchars($edit_activity['equipment_used']) : ''; ?>"
                                   placeholder="e.g., Brush cutter, Hand tools">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Materials Used</label>
                            <input type="text" class="form-control" name="materials_used"
                                   value="<?php echo $edit_activity ? htmlspecialchars($edit_activity['materials_used']) : ''; ?>"
                                   placeholder="e.g., Fuel, spare parts">
                        </div>
                    </div>
                    
                    <div class="row">
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
                                   placeholder="Team or person name">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Supervisor</label>
                            <input type="text" class="form-control" name="supervisor"
                                   value="<?php echo $edit_activity ? htmlspecialchars($edit_activity['supervisor']) : ''; ?>"
                                   placeholder="Supervisor name">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2" placeholder="Activity description..."><?php echo $edit_activity ? htmlspecialchars($edit_activity['description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes..."><?php echo $edit_activity ? htmlspecialchars($edit_activity['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_activity ? 'Update' : 'Record'; ?> Activity
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
