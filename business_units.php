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
                INSERT INTO business_units (company_id, parent_unit_id, unit_code, unit_name, unit_type, location, province, district,
                                          total_area, capacity, manager_name, manager_phone, manager_email,
                                          established_date, latitude, longitude, status, notes, created_by)
                VALUES (:company_id, :parent_unit_id, :unit_code, :unit_name, :unit_type, :location, :province, :district,
                        :total_area, :capacity, :manager_name, :manager_phone, :manager_email,
                        :established_date, :latitude, :longitude, :status, :notes, 'admin')
            ");
            
            $stmt->execute([
                ':company_id' => post('company_id'),
                ':parent_unit_id' => post('parent_unit_id') ?: null,
                ':unit_code' => post('unit_code'),
                ':unit_name' => post('unit_name'),
                ':unit_type' => post('unit_type'),
                ':location' => post('location'),
                ':province' => post('province'),
                ':district' => post('district'),
                ':total_area' => post('total_area', 0) ?: 0,
                ':capacity' => post('capacity', 0) ?: 0,
                ':manager_name' => post('manager_name'),
                ':manager_phone' => post('manager_phone'),
                ':manager_email' => post('manager_email'),
                ':established_date' => post('established_date') ?: null,
                ':latitude' => post('latitude') ?: null,
                ':longitude' => post('longitude') ?: null,
                ':status' => post('status', 'Active'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Business unit added successfully!');
            redirect('business_units.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding business unit: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE business_units
                SET company_id = :company_id, parent_unit_id = :parent_unit_id, unit_code = :unit_code, unit_name = :unit_name,
                    unit_type = :unit_type, location = :location, province = :province, district = :district,
                    total_area = :total_area, capacity = :capacity, manager_name = :manager_name,
                    manager_phone = :manager_phone, manager_email = :manager_email,
                    established_date = :established_date, latitude = :latitude, longitude = :longitude,
                    status = :status, notes = :notes, updated_by = 'admin'
                WHERE business_unit_id = :id
            ");
            
            $stmt->execute([
                ':id' => post('business_unit_id'),
                ':company_id' => post('company_id'),
                ':parent_unit_id' => post('parent_unit_id') ?: null,
                ':unit_code' => post('unit_code'),
                ':unit_name' => post('unit_name'),
                ':unit_type' => post('unit_type'),
                ':location' => post('location'),
                ':province' => post('province'),
                ':district' => post('district'),
                ':total_area' => post('total_area', 0) ?: 0,
                ':capacity' => post('capacity', 0) ?: 0,
                ':manager_name' => post('manager_name'),
                ':manager_phone' => post('manager_phone'),
                ':manager_email' => post('manager_email'),
                ':established_date' => post('established_date') ?: null,
                ':latitude' => post('latitude') ?: null,
                ':longitude' => post('longitude') ?: null,
                ':status' => post('status'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Business unit updated successfully!');
            redirect('business_units.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating business unit: ' . $e->getMessage() . ' | POST: ' . json_encode(array_map('htmlspecialchars', $_POST)));
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM business_units WHERE business_unit_id = :id");
            $stmt->execute([':id' => post('business_unit_id')]);
            
            set_message('success', 'Business unit deleted successfully!');
            redirect('business_units.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting business unit: ' . $e->getMessage());
        }
    }
}

// Get business unit for editing (before header)
$edit_unit = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM business_units WHERE business_unit_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_unit = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Business Units Management";
require_once 'includes/header.php';

// Scope to the logged-in user's company if one is assigned
$user_company_id = $_SESSION['company_id'] ?? get_user_company_id();

// Fetch companies for dropdown (scoped to user's company if applicable)
if ($user_company_id) {
    $companies_stmt = $db->prepare("SELECT company_id, company_code, company_name FROM companies WHERE status = 'Active' AND company_id = :cid ORDER BY company_name");
    $companies_stmt->execute([':cid' => $user_company_id]);
} else {
    $companies_stmt = $db->query("SELECT company_id, company_code, company_name FROM companies WHERE status = 'Active' ORDER BY company_name");
}
$companies = $companies_stmt->fetchAll();

// Fetch employees for manager dropdown (using hr_employee with extension)
$workers_stmt = $db->query("
    SELECT
        e.id,
        ex.employee_code,
        e.name as full_name,
        '' as position
    FROM hr_employee e
    LEFT JOIN hr_employee_extend ex ON e.id = ex.hr_employee_id
    WHERE e.active = TRUE
    ORDER BY e.name
");
$workers = $workers_stmt->fetchAll();

// Fetch business units with statistics
$search = get('search', '');
$company_filter = get('company_id', '');
$unit_type_filter = get('unit_type', '');
$status_filter = get('status', '');

$sql = "SELECT bu.*, c.company_name, c.company_code,
        COALESCE(bu.total_area_ha, 0) + COALESCE(bu.forestry_area_ha, 0) as combined_total_area_ha,
        COALESCE(bu.total_plants, 0) as total_plants,
        COALESCE(bu.forestry_area_ha, 0) as forestry_area_ha,
        COALESCE(bu.total_volume_m3, 0) as total_volume_m3,
        COALESCE(bu.total_carbon_stock_ton, 0) as total_carbon_stock_ton,
        COALESCE(bu.forestry_blocks, 0) as forestry_blocks,
        (SELECT COUNT(*) FROM divisions d WHERE d.business_unit_id = bu.business_unit_id AND d.parent_division_id IS NULL) as total_divisions,
        (SELECT COUNT(*) FROM blocks b
         INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id) as total_blocks,
        (SELECT COUNT(*) FROM blocks b
         INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id AND b.operation_type = 'Plantation' AND b.status = 'TM') as tm_blocks,
        (SELECT COUNT(*) FROM blocks b
         INNER JOIN divisions d ON b.division_id = d.division_id
         WHERE d.business_unit_id = bu.business_unit_id AND b.operation_type = 'Plantation' AND b.status = 'TBM') as tbm_blocks
        FROM business_units bu
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE 1=1";

if ($user_company_id) {
    $sql .= " AND bu.company_id = :user_company_id";
}
if ($search) {
    $sql .= " AND (bu.unit_code LIKE :search OR bu.unit_name LIKE :search OR bu.location LIKE :search)";
}
if ($company_filter) {
    $sql .= " AND bu.company_id = :company_id";
}
if ($unit_type_filter) {
    $sql .= " AND bu.unit_type = :unit_type";
}
if ($status_filter) {
    $sql .= " AND bu.status = :status";
}

$sql .= " ORDER BY c.company_name, bu.unit_name";

$stmt = $db->prepare($sql);
if ($user_company_id) {
    $stmt->bindValue(':user_company_id', $user_company_id);
}
if ($search) {
    $stmt->bindValue(':search', "%$search%");
}
if ($company_filter) {
    $stmt->bindValue(':company_id', $company_filter);
}
if ($unit_type_filter) {
    $stmt->bindValue(':unit_type', $unit_type_filter);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$business_units = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-diagram-3"></i> Business Units Management</h1>
            <p class="text-muted">Manage estates, mills, nurseries, and other operational units</p>
        </div>
        <div class="col-auto">
            <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-house"></i> Dashboard
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New Business Unit
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<?php
// Calculate totals from recursive summary columns
// Only count top-level business units (without parent) to avoid double-counting
$top_level_units = array_filter($business_units, function($unit) {
    return empty($unit['parent_unit_id']);
});

$total_units = count($business_units); // Count all units for display
$total_divisions = array_sum(array_column($top_level_units, 'total_divisions'));
$total_blocks = array_sum(array_column($top_level_units, 'total_blocks'));

// Include forestry area in total area
$total_area = array_sum(array_map(function($unit) {
    return ($unit['total_area_ha'] ?? 0) + ($unit['forestry_area_ha'] ?? 0);
}, $top_level_units));

$total_plants = array_sum(array_column($top_level_units, 'total_plants'));
$total_forestry_area = array_sum(array_column($top_level_units, 'forestry_area_ha'));
?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_units; ?></h3>
                <p><i class="bi bi-diagram-3"></i> Total Business Units</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <a href="divisions.php" class="text-decoration-none">
            <div class="card stat-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                <div class="card-body">
                    <h3 class="text-dark"><?php echo $total_divisions; ?></h3>
                    <p class="text-muted"><i class="bi bi-grid-3x3"></i> Total Divisions</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_area); ?></h3>
                <p><i class="bi bi-map"></i> Total Area (Ha)</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_plants, 0); ?></h3>
                <p><i class="bi bi-tree"></i> Total Plants</p>
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
                <select class="form-select" name="company_id">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?php echo $company['company_id']; ?>" <?php echo $company_filter == $company['company_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($company['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="unit_type">
                    <option value="">All Types</option>
                    <optgroup label="Plantation">
                        <option value="Estate" <?php echo $unit_type_filter == 'Estate' ? 'selected' : ''; ?>>Estate</option>
                        <option value="Mill" <?php echo $unit_type_filter == 'Mill' ? 'selected' : ''; ?>>Mill</option>
                        <option value="Nursery" <?php echo $unit_type_filter == 'Nursery' ? 'selected' : ''; ?>>Nursery</option>
                    </optgroup>
                    <optgroup label="Forestry">
                        <option value="Divisi Regional" <?php echo $unit_type_filter == 'Divisi Regional' ? 'selected' : ''; ?>>Divisi Regional</option>
                        <option value="KPH" <?php echo $unit_type_filter == 'KPH' ? 'selected' : ''; ?>>KPH (Kesatuan Pengelolaan Hutan)</option>
                    </optgroup>
                    <optgroup label="Other">
                        <option value="Workshop" <?php echo $unit_type_filter == 'Workshop' ? 'selected' : ''; ?>>Workshop</option>
                        <option value="Office" <?php echo $unit_type_filter == 'Office' ? 'selected' : ''; ?>>Office</option>
                        <option value="Other" <?php echo $unit_type_filter == 'Other' ? 'selected' : ''; ?>>Other</option>
                    </optgroup>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="Active" <?php echo $status_filter == 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo $status_filter == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="Under Construction" <?php echo $status_filter == 'Under Construction' ? 'selected' : ''; ?>>Under Construction</option>
                    <option value="Maintenance" <?php echo $status_filter == 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                <a href="business_units.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Business Units Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Business Units List (<?php echo count($business_units); ?> records)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Unit Name</th>
                        <th>Company</th>
                        <th>Type</th>
                        <th>Divisions</th>
                        <th>Blocks</th>
                        <th>Area (Ha)</th>
                        <th>Plants</th>
                        <th>TM/TBM</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($business_units)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">No business units found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($business_units as $unit): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($unit['unit_code']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($unit['unit_name']); ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($unit['province']); ?></small>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($unit['company_code']); ?></small><br>
                                    <?php echo htmlspecialchars($unit['company_name']); ?>
                                </td>
                                <td><span class="badge bg-info"><?php echo $unit['unit_type']; ?></span></td>
                                <td><span class="badge bg-secondary"><?php echo $unit['total_divisions']; ?></span></td>
                                <td><span class="badge bg-secondary"><?php echo format_number($unit['total_blocks'], 0); ?></span></td>
                                <td class="text-end"><?php echo format_number(($unit['total_area_ha'] ?? 0) + ($unit['forestry_area_ha'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo format_number($unit['total_plants'], 0); ?></td>
                                <td>
                                    <small>
                                        <span class="badge bg-success"><?php echo $unit['tm_blocks']; ?> TM</span>
                                        <span class="badge bg-warning"><?php echo $unit['tbm_blocks']; ?> TBM</span>
                                    </small>
                                </td>
                                <td><?php echo get_status_badge($unit['status']); ?></td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $unit['business_unit_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="divisions.php?business_unit_id=<?php echo $unit['business_unit_id']; ?>" class="btn btn-sm btn-info" title="View Divisions">
                                        <i class="bi bi-grid-3x3"></i>
                                    </a>
                                    <form method="POST" action="business_units.php" style="display:inline;" onsubmit="return confirmDelete('Delete this business unit and all related data?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="business_unit_id" value="<?php echo $unit['business_unit_id']; ?>">
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
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="business_units.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_unit ? 'Edit Business Unit' : 'Add New Business Unit'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_unit ? 'edit' : 'add'; ?>">
                    <?php if ($edit_unit): ?>
                        <input type="hidden" name="business_unit_id" value="<?php echo $edit_unit['business_unit_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Company <span class="text-danger">*</span></label>
                            <select class="form-select" name="company_id" required>
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo $company['company_id']; ?>" 
                                        <?php echo ($edit_unit && $edit_unit['company_id'] == $company['company_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($company['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Parent Business Unit</label>
                            <select class="form-select" name="parent_unit_id" id="parent_unit_id">
                                <option value="">None (Top Level)</option>
                                <?php
                                // Fetch business units for parent selection (scoped to user's company)
                                if ($user_company_id) {
                                    $parent_units_stmt = $db->prepare("
                                        SELECT bu.business_unit_id, bu.unit_code, bu.unit_name, bu.unit_type, c.company_name
                                        FROM business_units bu
                                        INNER JOIN companies c ON bu.company_id = c.company_id
                                        WHERE bu.company_id = :cid
                                        ORDER BY c.company_name, bu.unit_name
                                    ");
                                    $parent_units_stmt->execute([':cid' => $user_company_id]);
                                } else {
                                    $parent_units_stmt = $db->query("
                                        SELECT bu.business_unit_id, bu.unit_code, bu.unit_name, bu.unit_type, c.company_name
                                        FROM business_units bu
                                        INNER JOIN companies c ON bu.company_id = c.company_id
                                        ORDER BY c.company_name, bu.unit_name
                                    ");
                                }
                                $parent_units = $parent_units_stmt->fetchAll();
                                foreach ($parent_units as $pu):
                                    // Don't show self as parent option when editing
                                    if ($edit_unit && $pu['business_unit_id'] == $edit_unit['business_unit_id']) continue;
                                ?>
                                    <option value="<?php echo $pu['business_unit_id']; ?>"
                                        <?php echo ($edit_unit && $edit_unit['parent_unit_id'] == $pu['business_unit_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($pu['company_name'] . ' - ' . $pu['unit_name'] . ' (' . $pu['unit_type'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Optional: Select parent unit (e.g., KPH under Divisi Regional)</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Unit Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="unit_code" required 
                                   value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['unit_code']) : ''; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Unit Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="unit_type" required id="unit_type">
                                <optgroup label="Plantation">
                                    <option value="Estate" <?php echo ($edit_unit && $edit_unit['unit_type'] == 'Estate') ? 'selected' : ''; ?>>Estate</option>
                                    <option value="Mill" <?php echo ($edit_unit && $edit_unit['unit_type'] == 'Mill') ? 'selected' : ''; ?>>Mill</option>
                                    <option value="Nursery" <?php echo ($edit_unit && $edit_unit['unit_type'] == 'Nursery') ? 'selected' : ''; ?>>Nursery</option>
                                </optgroup>
                                <optgroup label="Forestry">
                                    <option value="Divisi Regional" <?php echo ($edit_unit && $edit_unit['unit_type'] == 'Divisi Regional') ? 'selected' : ''; ?>>Divisi Regional</option>
                                    <option value="KPH" <?php echo ($edit_unit && $edit_unit['unit_type'] == 'KPH') ? 'selected' : ''; ?>>KPH (Kesatuan Pengelolaan Hutan)</option>
                                </optgroup>
                                <optgroup label="Other">
                                    <option value="Workshop" <?php echo ($edit_unit && $edit_unit['unit_type'] == 'Workshop') ? 'selected' : ''; ?>>Workshop</option>
                                    <option value="Office" <?php echo ($edit_unit && $edit_unit['unit_type'] == 'Office') ? 'selected' : ''; ?>>Office</option>
                                    <option value="Other" <?php echo ($edit_unit && $edit_unit['unit_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Unit Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="unit_name" required
                               value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['unit_name']) : ''; ?>">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location"
                                   value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['location']) : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Province</label>
                            <input type="text" class="form-control" name="province"
                                   value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['province']) : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">District</label>
                            <input type="text" class="form-control" name="district"
                                   value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['district']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3" id="capacity_field">
                            <label class="form-label">Capacity (Tons/Hour)</label>
                            <input type="number" step="0.01" class="form-control" name="capacity"
                                   value="<?php echo $edit_unit ? $edit_unit['capacity'] : '0.00'; ?>">
                            <small class="text-muted">For Mill only - Total area is calculated automatically from divisions and blocks</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Manager</label>
                        <select class="form-select select2-manager" name="manager_name" id="manager_select">
                            <option value="">Select Manager</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?php echo htmlspecialchars($worker['full_name']); ?>"
                                    <?php echo ($edit_unit && $edit_unit['manager_name'] == $worker['full_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($worker['employee_code'] . ' - ' . $worker['full_name'] . ' (' . $worker['position'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Type to search from active workers</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Established Date</label>
                            <input type="date" class="form-control" name="established_date"
                                   value="<?php echo $edit_unit ? $edit_unit['established_date'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Latitude</label>
                            <input type="number" step="0.00000001" class="form-control" name="latitude"
                                   value="<?php echo $edit_unit ? $edit_unit['latitude'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Longitude</label>
                            <input type="number" step="0.00000001" class="form-control" name="longitude"
                                   value="<?php echo $edit_unit ? $edit_unit['longitude'] : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="Active" <?php echo ($edit_unit && $edit_unit['status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo ($edit_unit && $edit_unit['status'] == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                            <option value="Under Construction" <?php echo ($edit_unit && $edit_unit['status'] == 'Under Construction') ? 'selected' : ''; ?>>Under Construction</option>
                            <option value="Maintenance" <?php echo ($edit_unit && $edit_unit['status'] == 'Maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo $edit_unit ? htmlspecialchars($edit_unit['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_unit ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_unit): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

<script>
// Show/hide capacity field based on unit type
document.getElementById('unit_type').addEventListener('change', function() {
    var unitType = this.value;
    var capacityField = document.getElementById('capacity_field');
    
    if (unitType === 'Mill') {
        capacityField.style.display = 'block';
    } else {
        capacityField.style.display = 'none';
    }
});

// Trigger on page load
document.getElementById('unit_type').dispatchEvent(new Event('change'));

// Confirm delete
function confirmDelete(message) {
    return confirm(message);
}
</script>

<?php require_once 'includes/footer.php'; ?>

<!-- Select2 JS (loaded after jQuery from footer) -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
// Initialize Select2 for searchable dropdowns
$(document).ready(function() {
    $('.select2-manager').select2({
        theme: 'bootstrap-5',
        placeholder: 'Select Manager',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#addModal')
    });
});
</script>