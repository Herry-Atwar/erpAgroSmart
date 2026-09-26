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
                INSERT INTO pest_control_records 
                (work_order_id, block_id, application_date, pest_type, pest_name, severity,
                 pesticide_name, pesticide_type, quantity_used, application_method,
                 area_covered, labor_count, labor_hours, cost, weather_condition, performed_by,
                 supervisor, effectiveness, status, notes, created_by)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('work_order_id') ?: null,
                post('block_id'),
                post('application_date'),
                post('pest_type'),
                post('pest_name') ?: null,
                post('severity') ?: 'Medium',
                post('pesticide_name') ?: null,
                post('pesticide_type') ?: null,
                post('quantity_used') ?: null,
                post('application_method') ?: null,
                post('area_covered') ?: null,
                post('labor_count') ?: null,
                post('labor_hours') ?: null,
                post('cost') ?: null,
                post('weather_condition') ?: null,
                post('performed_by') ?: null,
                post('supervisor') ?: null,
                post('effectiveness') ?: 'Not Assessed',
                post('status') ?: 'Planned',
                post('notes') ?: null,
                'admin'
            ]);
            
            set_message('success', 'Pest control record added successfully!');
            redirect('pest_control.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding pest control: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE pest_control_records 
                SET work_order_id = ?, block_id = ?, application_date = ?, pest_type = ?,
                    pest_name = ?, severity = ?, pesticide_name = ?, pesticide_type = ?,
                    quantity_used = ?, application_method = ?,
                    area_covered = ?, labor_count = ?, labor_hours = ?, cost = ?,
                    weather_condition = ?, performed_by = ?, supervisor = ?,
                    effectiveness = ?, status = ?, notes = ?
                WHERE pest_control_id = ?
            ");
            
            $stmt->execute([
                post('work_order_id') ?: null,
                post('block_id'),
                post('application_date'),
                post('pest_type'),
                post('pest_name') ?: null,
                post('severity') ?: 'Medium',
                post('pesticide_name') ?: null,
                post('pesticide_type') ?: null,
                post('quantity_used') ?: null,
                post('application_method') ?: null,
                post('area_covered') ?: null,
                post('labor_count') ?: null,
                post('labor_hours') ?: null,
                post('cost') ?: null,
                post('weather_condition') ?: null,
                post('performed_by') ?: null,
                post('supervisor') ?: null,
                post('effectiveness') ?: 'Not Assessed',
                post('status'),
                post('notes') ?: null,
                post('pest_control_id')
            ]);
            
            set_message('success', 'Pest control record updated successfully!');
            redirect('pest_control.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating pest control: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM pest_control_records WHERE pest_control_id = ?");
            $stmt->execute([post('pest_control_id')]);
            
            set_message('success', 'Pest control record deleted successfully!');
            redirect('pest_control.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting pest control: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_record = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM pest_control_records WHERE pest_control_id = ?");
    $stmt->execute([get('id')]);
    $edit_record = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Pest & Disease Control";
require_once 'includes/header.php';
?>

<style>
    /* Custom teal theme for pest control page */
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
    SELECT b.block_id, b.block_code, b.block_name, b.total_plants,
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
    WHERE wo.status IN ('Planned', 'Assigned', 'In Progress') AND wo.work_type = 'Pest Control'
    ORDER BY wo.work_order_number DESC
");
$work_orders = $work_orders_stmt->fetchAll();

// Fetch pest control records with filters
$search = get('search', '');
$pest_type_filter = get('pest_type', '');
$severity_filter = get('severity', '');
$status_filter = get('status', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');

$sql = "SELECT pcr.*, 
        b.block_code, b.block_name, b.total_plants,
        py.year as planting_year,
        d.division_name,
        bu.unit_name as estate_name,
        c.company_name,
        wo.work_order_number
        FROM pest_control_records pcr
        INNER JOIN blocks b ON pcr.block_id = b.block_id
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        LEFT JOIN work_orders wo ON pcr.work_order_id = wo.work_order_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (b.block_name LIKE ? OR pcr.pest_type LIKE ? OR pcr.pest_name LIKE ? OR wo.work_order_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($pest_type_filter) {
    $sql .= " AND pcr.pest_type = ?";
    $params[] = $pest_type_filter;
}
if ($severity_filter) {
    $sql .= " AND pcr.severity = ?";
    $params[] = $severity_filter;
}
if ($status_filter) {
    $sql .= " AND pcr.status = ?";
    $params[] = $status_filter;
}
if ($date_from) {
    $sql .= " AND pcr.application_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND pcr.application_date <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY pcr.application_date DESC, pcr.pest_control_id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Calculate summary statistics
$total_records = count($records);
$total_quantity = array_sum(array_column($records, 'quantity_used'));
$total_area = array_sum(array_column($records, 'area_covered'));
$total_cost = array_sum(array_column($records, 'cost'));
$completed_count = count(array_filter($records, function($r) { return $r['status'] == 'Completed'; }));

// Pest types, severity levels, application methods, and statuses
$pest_types = ['Insect', 'Disease', 'Weed', 'Rodent', 'Other'];
$severity_levels = ['Low', 'Medium', 'High', 'Critical'];
$pesticide_types = ['Insecticide', 'Herbicide', 'Fungicide', 'Rodenticide', 'Other'];
$application_methods = ['Spraying', 'Baiting', 'Trapping', 'Manual', 'Other'];
$statuses = ['Planned', 'In Progress', 'Completed'];
$effectiveness_levels = ['Not Assessed', 'Poor', 'Fair', 'Good', 'Excellent'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-bug-fill"></i> Pest & Disease Control</h1>
            <p class="text-muted">Track pest and disease management activities</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Record Treatment
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_records; ?></h3>
                <p><i class="bi bi-list-check"></i> Total Treatments</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_quantity, 0); ?> L</h3>
                <p><i class="bi bi-droplet"></i> Total Pesticide</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_area, 1); ?> Ha</h3>
                <p><i class="bi bi-map"></i> Area Treated</p>
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

<!-- Pest Type Breakdown -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-pie-chart"></i> Treatment by Pest Type
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <?php 
                    $pest_type_counts = [];
                    foreach ($records as $r) {
                        $type = $r['pest_type'];
                        if (!isset($pest_type_counts[$type])) {
                            $pest_type_counts[$type] = 0;
                        }
                        $pest_type_counts[$type]++;
                    }
                    foreach ($pest_type_counts as $type => $count): 
                    ?>
                    <div class="col-md-2">
                        <h4 class="text-danger"><?php echo $count; ?></h4>
                        <small><?php echo htmlspecialchars($type); ?></small>
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
            <div class="col-md-2">
                <input type="text" class="form-control" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="pest_type">
                    <option value="">All Pest Types</option>
                    <?php foreach ($pest_types as $type): ?>
                        <option value="<?php echo $type; ?>" <?php echo $pest_type_filter == $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="severity">
                    <option value="">All Severity</option>
                    <?php foreach ($severity_levels as $level): ?>
                        <option value="<?php echo $level; ?>" <?php echo $severity_filter == $level ? 'selected' : ''; ?>><?php echo $level; ?></option>
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
            <div class="col-md-1">
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- Pest Control Records Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Pest Control Records (<?php echo count($records); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>WO Number</th>
                        <th>Block</th>
                        <th>Pest Type</th>
                        <th>Pest Name</th>
                        <th>Severity</th>
                        <th>Pesticide</th>
                        <th>Quantity (L)</th>
                        <th>Area (Ha)</th>
                        <th>Effectiveness</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted">No pest control records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?php echo format_date($record['application_date']); ?></td>
                                <td><?php echo $record['work_order_number'] ? htmlspecialchars($record['work_order_number']) : '-'; ?></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($record['estate_name']); ?></small><br>
                                    <?php echo htmlspecialchars($record['block_name']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-danger">
                                        <?php echo htmlspecialchars($record['pest_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($record['pest_name']); ?></td>
                                <td><?php echo get_severity_badge($record['severity']); ?></td>
                                <td><?php echo htmlspecialchars($record['pesticide_name']); ?></td>
                                <td><?php echo $record['quantity_used'] ? format_number($record['quantity_used'], 1) : '-'; ?></td>
                                <td><?php echo $record['area_covered'] ? format_number($record['area_covered'], 1) : '-'; ?></td>
                                <td><?php echo get_effectiveness_badge($record['effectiveness']); ?></td>
                                <td><?php echo get_status_badge($record['status']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $record['pest_control_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $record['pest_control_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="pest_control.php" style="display:inline;" onsubmit="return confirmDelete('Delete this record?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="pest_control_id" value="<?php echo $record['pest_control_id']; ?>">
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

<?php foreach ($records as $record): ?>
                            <!-- View Details Modal -->
                            <div class="modal fade" id="viewModal<?php echo $record['pest_control_id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Pest Control Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">Application Date:</th>
                                                            <td><?php echo format_date($record['application_date']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>WO Number:</th>
                                                            <td><?php echo $record['work_order_number'] ? htmlspecialchars($record['work_order_number']) : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Block:</th>
                                                            <td><?php echo htmlspecialchars($record['block_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Estate:</th>
                                                            <td><?php echo htmlspecialchars($record['estate_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Pest Type:</th>
                                                            <td><?php echo htmlspecialchars($record['pest_type']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Pest Name:</th>
                                                            <td><?php echo htmlspecialchars($record['pest_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Severity Level:</th>
                                                            <td><?php echo htmlspecialchars($record['severity']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Pesticide Name:</th>
                                                            <td><?php echo htmlspecialchars($record['pesticide_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Pesticide Type:</th>
                                                            <td><?php echo htmlspecialchars($record['pesticide_type']); ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">Quantity:</th>
                                                            <td><?php echo $record['quantity_used'] ? format_number($record['quantity_used'], 1) . ' L' : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Method:</th>
                                                            <td><?php echo htmlspecialchars($record['application_method']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Area Covered:</th>
                                                            <td><?php echo $record['area_covered'] ? format_number($record['area_covered'], 1) . ' Ha' : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Labor Count:</th>
                                                            <td><?php echo $record['labor_count'] ? $record['labor_count'] : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Labor Hours:</th>
                                                            <td><?php echo $record['labor_hours'] ? format_number($record['labor_hours'], 1) : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Cost:</th>
                                                            <td>Rp <?php echo format_number($record['cost'], 0); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Weather:</th>
                                                            <td><?php echo htmlspecialchars($record['weather_condition']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Performed By:</th>
                                                            <td><?php echo htmlspecialchars($record['performed_by']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Supervisor:</th>
                                                            <td><?php echo htmlspecialchars($record['supervisor']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Effectiveness:</th>
                                                            <td><?php echo htmlspecialchars($record['effectiveness']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Status:</th>
                                                            <td><?php echo htmlspecialchars($record['status']); ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                            <?php if ($record['notes']): ?>
                                            <div class="mt-3">
                                                <h6>Notes:</h6>
                                                <p><?php echo nl2br(htmlspecialchars($record['notes'])); ?></p>
                                            </div>
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
            <form method="POST" action="pest_control.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_record ? 'Edit Pest Control Record' : 'Record Pest Control Treatment'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_record ? 'edit' : 'add'; ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="pest_control_id" value="<?php echo $edit_record['pest_control_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Work Order (Optional)</label>
                            <select class="form-select" name="work_order_id">
                                <option value="">No Work Order</option>
                                <?php foreach ($work_orders as $wo): ?>
                                    <option value="<?php echo $wo['work_order_id']; ?>" 
                                        <?php echo ($edit_record && $edit_record['work_order_id'] == $wo['work_order_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($wo['work_order_number'] . ' - ' . $wo['block_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Block <span class="text-danger">*</span></label>
                            <select class="form-select" name="block_id" required id="block_select">
                                <option value="">Select Block</option>
                                <?php foreach ($blocks as $block): ?>
                                    <option value="<?php echo $block['block_id']; ?>" 
                                        data-plants="<?php echo $block['total_plants']; ?>"
                                        <?php echo ($edit_record && $edit_record['block_id'] == $block['block_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($block['company_name'] . ' - ' . $block['unit_name'] . ' - ' . $block['block_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" id="plant_count"></small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Application Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="application_date" required
                                   value="<?php echo $edit_record ? $edit_record['application_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Pest Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="pest_type" required>
                                <?php foreach ($pest_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($edit_record && $edit_record['pest_type'] == $type) ? 'selected' : ''; ?>>
                                        <?php echo $type; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Pest Name</label>
                            <input type="text" class="form-control" name="pest_name"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['pest_name']) : ''; ?>"
                                   placeholder="e.g., Bagworm, Leaf Blight">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Severity Level</label>
                            <select class="form-select" name="severity">
                                <?php foreach ($severity_levels as $level): ?>
                                    <option value="<?php echo $level; ?>" <?php echo ($edit_record && $edit_record['severity'] == $level) ? 'selected' : ((!$edit_record && $level == 'Medium') ? 'selected' : ''); ?>>
                                        <?php echo $level; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Pesticide Name</label>
                            <input type="text" class="form-control" name="pesticide_name"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['pesticide_name']) : ''; ?>"
                                   placeholder="e.g., Cypermethrin">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Pesticide Type</label>
                            <select class="form-select" name="pesticide_type">
                                <option value="">Select Type</option>
                                <?php foreach ($pesticide_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($edit_record && $edit_record['pesticide_type'] == $type) ? 'selected' : ''; ?>>
                                        <?php echo $type; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity (Liters)</label>
                            <input type="number" step="0.01" class="form-control" name="quantity_used"
                                   value="<?php echo $edit_record ? $edit_record['quantity_used'] : ''; ?>"
                                   placeholder="e.g., 50">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Application Method</label>
                            <select class="form-select" name="application_method">
                                <option value="">Select Method</option>
                                <?php foreach ($application_methods as $method): ?>
                                    <option value="<?php echo $method; ?>" <?php echo ($edit_record && $edit_record['application_method'] == $method) ? 'selected' : ''; ?>>
                                        <?php echo $method; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Area Covered (Ha)</label>
                            <input type="number" step="0.01" class="form-control" name="area_covered"
                                   value="<?php echo $edit_record ? $edit_record['area_covered'] : ''; ?>"
                                   placeholder="e.g., 5.5">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Labor Count</label>
                            <input type="number" class="form-control" name="labor_count"
                                   value="<?php echo $edit_record ? $edit_record['labor_count'] : ''; ?>"
                                   placeholder="Number of workers">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Labor Hours</label>
                            <input type="number" step="0.1" class="form-control" name="labor_hours"
                                   value="<?php echo $edit_record ? $edit_record['labor_hours'] : ''; ?>"
                                   placeholder="e.g., 40.5">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Cost (Rp)</label>
                            <input type="number" class="form-control" name="cost"
                                   value="<?php echo $edit_record ? $edit_record['cost'] : ''; ?>"
                                   placeholder="e.g., 2000000">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Weather Condition</label>
                            <input type="text" class="form-control" name="weather_condition"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['weather_condition']) : ''; ?>"
                                   placeholder="e.g., Sunny, Cloudy">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Performed By</label>
                            <input type="text" class="form-control" name="performed_by"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['performed_by']) : ''; ?>"
                                   placeholder="Team or person name">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Supervisor</label>
                            <input type="text" class="form-control" name="supervisor"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['supervisor']) : ''; ?>"
                                   placeholder="Supervisor name">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Effectiveness Rating</label>
                            <select class="form-select" name="effectiveness">
                                <?php foreach ($effectiveness_levels as $level): ?>
                                    <option value="<?php echo $level; ?>" <?php echo ($edit_record && $edit_record['effectiveness'] == $level) ? 'selected' : ((!$edit_record && $level == 'Not Assessed') ? 'selected' : ''); ?>>
                                        <?php echo $level; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo $status; ?>" <?php echo ($edit_record && $edit_record['status'] == $status) ? 'selected' : ((!$edit_record && $status == 'Planned') ? 'selected' : ''); ?>>
                                    <?php echo $status; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes..."><?php echo $edit_record ? htmlspecialchars($edit_record['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_record ? 'Update' : 'Record'; ?> Treatment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_record): ?>
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

// Show plant count when block is selected
document.getElementById('block_select').addEventListener('change', function() {
    var selected = this.options[this.selectedIndex];
    var plants = selected.getAttribute('data-plants');
    if (plants) {
        document.getElementById('plant_count').textContent = 'Total plants: ' + plants;
    } else {
        document.getElementById('plant_count').textContent = '';
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
