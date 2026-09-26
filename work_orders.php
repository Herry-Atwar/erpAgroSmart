<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            // Generate work order number (PostgreSQL syntax)
            $year = date('Y');
            $stmt = $db->prepare("SELECT COALESCE(MAX(SPLIT_PART(work_order_number, '-', 3)::integer), 0) AS max_num
                                  FROM work_orders WHERE work_order_number LIKE ?");
            $stmt->execute(["WO-$year-%"]);
            $result = $stmt->fetch();
            $next_num = ($result['max_num'] ?? 0) + 1;
            $work_order_number = sprintf('WO-%s-%04d', $year, $next_num);
            
            $stmt = $db->prepare("
                INSERT INTO work_orders 
                (work_order_number, block_id, work_type, priority, scheduled_date, start_date,
                 estimated_hours, estimated_cost, assigned_to, supervisor, status, description, notes, created_by)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $work_order_number,
                post('block_id'),
                post('work_type'),
                post('priority'),
                post('scheduled_date'),
                post('start_date') ?: null,
                post('estimated_hours') ?: null,
                post('estimated_cost') ?: null,
                post('assigned_to') ?: null,
                post('supervisor') ?: null,
                post('status') ?: 'Planned',
                post('description') ?: null,
                post('notes') ?: null,
                'admin'
            ]);
            
            set_message('success', 'Work order created successfully! Number: ' . $work_order_number);
            redirect('work_orders.php');
        } catch (PDOException $e) {
            set_message('error', 'Error creating work order: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE work_orders 
                SET block_id = ?, work_type = ?, priority = ?, scheduled_date = ?,
                    start_date = ?, completion_date = ?, estimated_hours = ?, actual_hours = ?,
                    estimated_cost = ?, actual_cost = ?, assigned_to = ?, supervisor = ?,
                    status = ?, description = ?, notes = ?, updated_by = ?
                WHERE work_order_id = ?
            ");
            
            $stmt->execute([
                post('block_id'),
                post('work_type'),
                post('priority'),
                post('scheduled_date'),
                post('start_date') ?: null,
                post('completion_date') ?: null,
                post('estimated_hours') ?: null,
                post('actual_hours') ?: null,
                post('estimated_cost') ?: null,
                post('actual_cost') ?: null,
                post('assigned_to') ?: null,
                post('supervisor') ?: null,
                post('status'),
                post('description') ?: null,
                post('notes') ?: null,
                'admin',
                post('work_order_id')
            ]);
            
            set_message('success', 'Work order updated successfully!');
            redirect('work_orders.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating work order: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM work_orders WHERE work_order_id = ?");
            $stmt->execute([post('work_order_id')]);
            
            set_message('success', 'Work order deleted successfully!');
            redirect('work_orders.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting work order: ' . $e->getMessage());
        }
    }
}

// Get work order for editing (before header)
$edit_wo = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM work_orders WHERE work_order_id = ?");
    $stmt->execute([get('id')]);
    $edit_wo = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Work Orders Management";
require_once 'includes/header.php';
?>

<style>
    /* Custom teal theme for work orders page */
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

// Fetch work orders with filters
$search = get('search', '');
$work_type_filter = get('work_type', '');
$status_filter = get('status', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');

$sql = "SELECT wo.*, 
        b.block_code, b.block_name,
        py.year as planting_year,
        d.division_name,
        bu.unit_name as estate_name,
        c.company_name
        FROM work_orders wo
        INNER JOIN blocks b ON wo.block_id = b.block_id
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (wo.work_order_number LIKE ? OR b.block_name LIKE ? OR wo.assigned_to LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($work_type_filter) {
    $sql .= " AND wo.work_type = ?";
    $params[] = $work_type_filter;
}
if ($status_filter) {
    $sql .= " AND wo.status = ?";
    $params[] = $status_filter;
}
if ($date_from) {
    $sql .= " AND wo.scheduled_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND wo.scheduled_date <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY wo.scheduled_date DESC, wo.work_order_id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$work_orders = $stmt->fetchAll();

// Calculate summary statistics
$total_orders = count($work_orders);
$planned_count = count(array_filter($work_orders, function($wo) { return $wo['status'] == 'Planned'; }));
$in_progress_count = count(array_filter($work_orders, function($wo) { return $wo['status'] == 'In Progress'; }));
$completed_count = count(array_filter($work_orders, function($wo) { return $wo['status'] == 'Completed'; }));
$total_estimated_cost = array_sum(array_column($work_orders, 'estimated_cost'));
$total_actual_cost = array_sum(array_column($work_orders, 'actual_cost'));

// Work types and statuses
$work_types = ['Maintenance', 'Fertilization', 'Pest Control', 'Weeding', 'Pruning', 'Harvesting', 'Other'];
$priorities = ['Low', 'Normal', 'High', 'Urgent'];
$statuses = ['Planned', 'Assigned', 'In Progress', 'Completed', 'Cancelled'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-clipboard-check"></i> Work Orders Management</h1>
            <p class="text-muted">Create and manage field operation work orders</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Create Work Order
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_orders; ?></h3>
                <p><i class="bi bi-list-check"></i> Total Orders</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $planned_count; ?></h3>
                <p><i class="bi bi-calendar"></i> Planned</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $in_progress_count; ?></h3>
                <p><i class="bi bi-hourglass-split"></i> In Progress</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $completed_count; ?></h3>
                <p><i class="bi bi-check-circle"></i> Completed</p>
            </div>
        </div>
    </div>
</div>

<!-- Cost Summary -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h5>Estimated Cost</h5>
                <h3 class="text-primary">Rp <?php echo format_number($total_estimated_cost, 0); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h5>Actual Cost</h5>
                <h3 class="text-success">Rp <?php echo format_number($total_actual_cost, 0); ?></h3>
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
                <select class="form-select" name="work_type">
                    <option value="">All Work Types</option>
                    <?php foreach ($work_types as $type): ?>
                        <option value="<?php echo $type; ?>" <?php echo $work_type_filter == $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
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

<!-- Work Orders Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Work Orders (<?php echo count($work_orders); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>WO Number</th>
                        <th>Block</th>
                        <th>Work Type</th>
                        <th>Priority</th>
                        <th>Scheduled Date</th>
                        <th>Assigned To</th>
                        <th>Est. Cost</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($work_orders)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">No work orders found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($work_orders as $wo): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($wo['work_order_number']); ?></strong></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($wo['estate_name']); ?></small><br>
                                    <?php echo htmlspecialchars($wo['block_name']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo $wo['work_type'] == 'Maintenance' ? 'primary' :
                                            ($wo['work_type'] == 'Fertilization' ? 'success' :
                                            ($wo['work_type'] == 'Pest Control' ? 'warning' : 'secondary'));
                                    ?>">
                                        <?php echo htmlspecialchars($wo['work_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo $wo['priority'] == 'Urgent' ? 'danger' :
                                            ($wo['priority'] == 'High' ? 'warning' :
                                            ($wo['priority'] == 'Normal' ? 'info' : 'secondary'));
                                    ?>">
                                        <?php echo htmlspecialchars($wo['priority']); ?>
                                    </span>
                                </td>
                                <td><?php echo format_date($wo['scheduled_date']); ?></td>
                                <td><?php echo htmlspecialchars($wo['assigned_to']); ?></td>
                                <td>Rp <?php echo format_number($wo['estimated_cost'], 0); ?></td>
                                <td><?php echo get_status_badge($wo['status']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $wo['work_order_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $wo['work_order_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="work_orders.php" style="display:inline;" onsubmit="return confirmDelete('Delete this work order?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="work_order_id" value="<?php echo $wo['work_order_id']; ?>">
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
<?php foreach ($work_orders as $wo): ?>
<div class="modal fade" id="viewModal<?php echo $wo['work_order_id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Work Order Details — <?php echo htmlspecialchars($wo['work_order_number']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr><th width="40%">WO Number:</th><td><?php echo htmlspecialchars($wo['work_order_number']); ?></td></tr>
                            <tr><th>Block:</th><td><?php echo htmlspecialchars($wo['block_name']); ?></td></tr>
                            <tr><th>Estate:</th><td><?php echo htmlspecialchars($wo['estate_name']); ?></td></tr>
                            <tr><th>Work Type:</th><td><?php echo htmlspecialchars($wo['work_type']); ?></td></tr>
                            <tr><th>Priority:</th><td><?php echo htmlspecialchars($wo['priority']); ?></td></tr>
                            <tr><th>Status:</th><td><?php echo htmlspecialchars($wo['status']); ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr><th width="40%">Scheduled Date:</th><td><?php echo format_date($wo['scheduled_date']); ?></td></tr>
                            <tr><th>Start Date:</th><td><?php echo $wo['start_date'] ? format_date($wo['start_date']) : '-'; ?></td></tr>
                            <tr><th>Completion Date:</th><td><?php echo $wo['completion_date'] ? format_date($wo['completion_date']) : '-'; ?></td></tr>
                            <tr><th>Assigned To:</th><td><?php echo htmlspecialchars($wo['assigned_to']); ?></td></tr>
                            <tr><th>Supervisor:</th><td><?php echo htmlspecialchars($wo['supervisor']); ?></td></tr>
                        </table>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <h6>Estimated</h6>
                        <table class="table table-sm">
                            <tr><th>Hours:</th><td><?php echo $wo['estimated_hours'] ? format_number($wo['estimated_hours'], 1) : '-'; ?></td></tr>
                            <tr><th>Cost:</th><td>Rp <?php echo format_number($wo['estimated_cost'], 0); ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Actual</h6>
                        <table class="table table-sm">
                            <tr><th>Hours:</th><td><?php echo $wo['actual_hours'] ? format_number($wo['actual_hours'], 1) : '-'; ?></td></tr>
                            <tr><th>Cost:</th><td>Rp <?php echo format_number($wo['actual_cost'], 0); ?></td></tr>
                        </table>
                    </div>
                </div>
                <?php if ($wo['description']): ?>
                <div class="mt-3"><h6>Description:</h6><p><?php echo nl2br(htmlspecialchars($wo['description'])); ?></p></div>
                <?php endif; ?>
                <?php if ($wo['notes']): ?>
                <div class="mt-3"><h6>Notes:</h6><p><?php echo nl2br(htmlspecialchars($wo['notes'])); ?></p></div>
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
            <form method="POST" action="work_orders.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_wo ? 'Edit Work Order' : 'Create Work Order'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_wo ? 'edit' : 'add'; ?>">
                    <?php if ($edit_wo): ?>
                        <input type="hidden" name="work_order_id" value="<?php echo $edit_wo['work_order_id']; ?>">
                    <?php endif; ?>
                    
                    <?php if ($edit_wo): ?>
                    <div class="alert alert-info">
                        <strong>WO Number:</strong> <?php echo htmlspecialchars($edit_wo['work_order_number']); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Block <span class="text-danger">*</span></label>
                            <select class="form-select" name="block_id" required>
                                <option value="">Select Block</option>
                                <?php foreach ($blocks as $block): ?>
                                    <option value="<?php echo $block['block_id']; ?>" 
                                        <?php echo ($edit_wo && $edit_wo['block_id'] == $block['block_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($block['company_name'] . ' - ' . $block['unit_name'] . ' - ' . $block['block_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Work Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="work_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($work_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($edit_wo && $edit_wo['work_type'] == $type) ? 'selected' : ''; ?>>
                                        <?php echo $type; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Priority <span class="text-danger">*</span></label>
                            <select class="form-select" name="priority" required>
                                <?php foreach ($priorities as $priority): ?>
                                    <option value="<?php echo $priority; ?>" <?php echo ($edit_wo && $edit_wo['priority'] == $priority) ? 'selected' : ((!$edit_wo && $priority == 'Normal') ? 'selected' : ''); ?>>
                                        <?php echo $priority; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Scheduled Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="scheduled_date" required
                                   value="<?php echo $edit_wo ? $edit_wo['scheduled_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date"
                                   value="<?php echo $edit_wo ? $edit_wo['start_date'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Completion Date</label>
                            <input type="date" class="form-control" name="completion_date"
                                   value="<?php echo $edit_wo ? $edit_wo['completion_date'] : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Assigned To</label>
                            <input type="text" class="form-control" name="assigned_to"
                                   value="<?php echo $edit_wo ? htmlspecialchars($edit_wo['assigned_to']) : ''; ?>"
                                   placeholder="Team or person name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supervisor</label>
                            <input type="text" class="form-control" name="supervisor"
                                   value="<?php echo $edit_wo ? htmlspecialchars($edit_wo['supervisor']) : ''; ?>"
                                   placeholder="Supervisor name">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Estimated Hours</label>
                            <input type="number" step="0.1" class="form-control" name="estimated_hours"
                                   value="<?php echo $edit_wo ? $edit_wo['estimated_hours'] : ''; ?>"
                                   placeholder="e.g., 8.5">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Estimated Cost (Rp)</label>
                            <input type="number" class="form-control" name="estimated_cost"
                                   value="<?php echo $edit_wo ? $edit_wo['estimated_cost'] : ''; ?>"
                                   placeholder="e.g., 1000000">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Actual Hours</label>
                            <input type="number" step="0.1" class="form-control" name="actual_hours"
                                   value="<?php echo $edit_wo ? $edit_wo['actual_hours'] : ''; ?>"
                                   placeholder="e.g., 9.0">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Actual Cost (Rp)</label>
                            <input type="number" class="form-control" name="actual_cost"
                                   value="<?php echo $edit_wo ? $edit_wo['actual_cost'] : ''; ?>"
                                   placeholder="e.g., 1100000">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo $status; ?>" <?php echo ($edit_wo && $edit_wo['status'] == $status) ? 'selected' : ((!$edit_wo && $status == 'Planned') ? 'selected' : ''); ?>>
                                    <?php echo $status; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3" placeholder="Work order description..."><?php echo $edit_wo ? htmlspecialchars($edit_wo['description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes..."><?php echo $edit_wo ? htmlspecialchars($edit_wo['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_wo ? 'Update' : 'Create'; ?> Work Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_wo): ?>
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
