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
                INSERT INTO divisions (business_unit_id, parent_division_id, division_code, division_name, division_type,
                                     total_area, assistant_name, assistant_phone, status, notes, created_by)
                VALUES (:business_unit_id, :parent_division_id, :division_code, :division_name, :division_type,
                        :total_area, :assistant_name, :assistant_phone, :status, :notes, 'admin')
            ");
            
            $stmt->execute([
                ':business_unit_id' => post('business_unit_id'),
                ':parent_division_id' => post('parent_division_id') ?: null,
                ':division_code' => post('division_code'),
                ':division_name' => post('division_name'),
                ':division_type' => post('division_type'),
                ':total_area' => post('total_area', 0),
                ':assistant_name' => post('assistant_name'),
                ':assistant_phone' => post('assistant_phone'),
                ':status' => post('status', 'Active'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Division added successfully!');
            redirect('divisions.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding division: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE divisions
                SET business_unit_id = :business_unit_id, parent_division_id = :parent_division_id,
                    division_code = :division_code, division_name = :division_name, division_type = :division_type,
                    total_area = :total_area, assistant_name = :assistant_name,
                    assistant_phone = :assistant_phone, status = :status, notes = :notes, updated_by = 'admin'
                WHERE division_id = :id
            ");
            
            $stmt->execute([
                ':id' => post('division_id'),
                ':business_unit_id' => post('business_unit_id'),
                ':parent_division_id' => post('parent_division_id') ?: null,
                ':division_code' => post('division_code'),
                ':division_name' => post('division_name'),
                ':division_type' => post('division_type'),
                ':total_area' => post('total_area', 0),
                ':assistant_name' => post('assistant_name'),
                ':assistant_phone' => post('assistant_phone'),
                ':status' => post('status'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Division updated successfully!');
            redirect('divisions.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating division: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM divisions WHERE division_id = :id");
            $stmt->execute([':id' => post('division_id')]);
            
            set_message('success', 'Division deleted successfully!');
            redirect('divisions.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting division: ' . $e->getMessage());
        }
    }
}

// Get division for editing (before header)
$edit_division = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM divisions WHERE division_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_division = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Divisions Management";
require_once 'includes/header.php';

// Scope to the logged-in user's company if one is assigned
$user_company_id = $_SESSION['company_id'] ?? get_user_company_id();

// Fetch business units for dropdown (scoped to user's company if applicable)
if ($user_company_id) {
    $business_units_stmt = $db->prepare("
        SELECT bu.business_unit_id, bu.unit_code, bu.unit_name, c.company_name
        FROM business_units bu
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE bu.status = 'Active' AND bu.company_id = :cid
        ORDER BY c.company_name, bu.unit_name
    ");
    $business_units_stmt->execute([':cid' => $user_company_id]);
} else {
    $business_units_stmt = $db->query("
        SELECT bu.business_unit_id, bu.unit_code, bu.unit_name, c.company_name
        FROM business_units bu
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE bu.status = 'Active'
        ORDER BY c.company_name, bu.unit_name
    ");
}
$business_units = $business_units_stmt->fetchAll();

// Fetch employees for assistant/supervisor dropdown (using hr_employee with extension)
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

// Fetch divisions with statistics
$search = get('search', '');
$business_unit_filter = get('business_unit_id', '');
$status_filter = get('status', '');

$sql = "SELECT d.*,
        bu.unit_code, bu.unit_name, bu.unit_type,
        c.company_name, c.company_code,
        (SELECT COUNT(DISTINCT py.planting_year_id) FROM planting_years py WHERE py.division_id = d.division_id) as total_planting_years,
        (SELECT COUNT(*) FROM blocks b WHERE b.division_id = d.division_id) as total_blocks,
        COALESCE(d.total_area, 0) + COALESCE(d.forestry_area_ha, 0) as total_area_ha,
        COALESCE(d.total_plants, 0) as total_plants,
        (SELECT COUNT(*) FROM blocks b WHERE b.division_id = d.division_id AND b.operation_type = 'Plantation' AND b.status = 'TM') as tm_blocks,
        (SELECT COUNT(*) FROM blocks b WHERE b.division_id = d.division_id AND b.operation_type = 'Plantation' AND b.status = 'TBM') as tbm_blocks,
        COALESCE(d.forestry_blocks, 0) as forestry_blocks,
        COALESCE(d.forestry_area_ha, 0) as forestry_area_ha,
        COALESCE(d.total_area, 0) as plantation_area_ha
        FROM divisions d
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE 1=1";

if ($user_company_id) {
    $sql .= " AND c.company_id = :user_company_id";
}
if ($search) {
    $sql .= " AND (d.division_code LIKE :search OR d.division_name LIKE :search)";
}
if ($business_unit_filter) {
    $sql .= " AND d.business_unit_id = :business_unit_id";
}
if ($status_filter) {
    $sql .= " AND d.status = :status";
}

$sql .= " ORDER BY c.company_name, bu.unit_name, d.division_code";

$stmt = $db->prepare($sql);
if ($user_company_id) {
    $stmt->bindValue(':user_company_id', $user_company_id);
}
if ($search) {
    $stmt->bindValue(':search', "%$search%");
}
if ($business_unit_filter) {
    $stmt->bindValue(':business_unit_id', $business_unit_filter);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$divisions = $stmt->fetchAll();

// Calculate summary statistics from recursive summary columns
// Only count top-level divisions (without parent) to avoid double-counting
$top_level_divisions = array_filter($divisions, function($div) {
    return empty($div['parent_division_id']);
});

$total_divisions = count($divisions); // Count all divisions for display
$total_planting_years = array_sum(array_column($top_level_divisions, 'total_planting_years'));
$total_blocks = array_sum(array_column($top_level_divisions, 'total_blocks'));
$total_area = array_sum(array_column($top_level_divisions, 'total_area_ha'));
$total_plants = array_sum(array_column($top_level_divisions, 'total_plants'));
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-grid-3x3"></i> Divisions Management</h1>
            <p class="text-muted">Manage divisions (Afdeling) within business units</p>
        </div>
        <div class="col-auto">
            <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-house"></i> Dashboard
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New Division
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_divisions; ?></h3>
                <p><i class="bi bi-grid-3x3"></i> Total Divisions</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <a href="planting_years.php" class="text-decoration-none">
            <div class="card stat-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                <div class="card-body">
                    <h3 class="text-dark"><?php echo $total_planting_years; ?></h3>
                    <p class="text-muted"><i class="bi bi-calendar-event"></i> Planting Years</p>
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
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="Search by code or name..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-4">
                <select class="form-select" name="business_unit_id">
                    <option value="">All Business Units</option>
                    <?php foreach ($business_units as $unit): ?>
                        <option value="<?php echo $unit['business_unit_id']; ?>" <?php echo $business_unit_filter == $unit['business_unit_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($unit['company_name'] . ' - ' . $unit['unit_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="Active" <?php echo $status_filter == 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo $status_filter == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                <a href="divisions.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Divisions Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Divisions List (<?php echo count($divisions); ?> records)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Division Name</th>
                        <th>Business Unit</th>
                        <th>Planting Years</th>
                        <th>Blocks</th>
                        <th>Area (Ha)</th>
                        <th>Plants</th>
                        <th>TM/TBM</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($divisions)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No divisions found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($divisions as $division): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($division['division_code']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($division['division_name']); ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($division['division_type']); ?></small>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($division['company_code']); ?></small><br>
                                    <?php echo htmlspecialchars($division['unit_name']); ?>
                                </td>
                                <td><span class="badge bg-secondary"><?php echo $division['total_planting_years']; ?></span></td>
                                <td><span class="badge bg-secondary"><?php echo format_number($division['total_blocks'], 0); ?></span></td>
                                <td class="text-end"><?php echo format_number($division['total_area_ha']); ?></td>
                                <td class="text-end"><?php echo format_number($division['total_plants'], 0); ?></td>
                                <td>
                                    <small>
                                        <span class="badge bg-success"><?php echo $division['tm_blocks']; ?> TM</span>
                                        <span class="badge bg-warning"><?php echo $division['tbm_blocks']; ?> TBM</span>
                                    </small>
                                </td>
                                <td><?php echo get_status_badge($division['status']); ?></td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $division['division_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="planting_years.php?division_id=<?php echo $division['division_id']; ?>" class="btn btn-sm btn-info" title="View Planting Years">
                                        <i class="bi bi-calendar-event"></i>
                                    </a>
                                    <form method="POST" action="divisions.php" style="display:inline;" onsubmit="return confirmDelete('Delete this division and all related data?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="division_id" value="<?php echo $division['division_id']; ?>">
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
            <form method="POST" action="divisions.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_division ? 'Edit Division' : 'Add New Division'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_division ? 'edit' : 'add'; ?>">
                    <?php if ($edit_division): ?>
                        <input type="hidden" name="division_id" value="<?php echo $edit_division['division_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Business Unit <span class="text-danger">*</span></label>
                        <select class="form-select" name="business_unit_id" required>
                            <option value="">Select Business Unit</option>
                            <?php foreach ($business_units as $unit): ?>
                                <option value="<?php echo $unit['business_unit_id']; ?>" 
                                    <?php echo ($edit_division && $edit_division['business_unit_id'] == $unit['business_unit_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($unit['company_name'] . ' - ' . $unit['unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Parent Division</label>
                        <select class="form-select" name="parent_division_id" id="parent_division_id">
                            <option value="">None (Top Level)</option>
                            <?php
                            // Fetch divisions for parent selection (scoped to user's company)
                            if ($user_company_id) {
                                $parent_divisions_stmt = $db->prepare("
                                    SELECT d.division_id, d.division_code, d.division_name, d.division_type,
                                           bu.unit_name, c.company_name
                                    FROM divisions d
                                    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
                                    INNER JOIN companies c ON bu.company_id = c.company_id
                                    WHERE bu.company_id = :cid
                                    ORDER BY c.company_name, bu.unit_name, d.division_name
                                ");
                                $parent_divisions_stmt->execute([':cid' => $user_company_id]);
                            } else {
                                $parent_divisions_stmt = $db->query("
                                    SELECT d.division_id, d.division_code, d.division_name, d.division_type,
                                           bu.unit_name, c.company_name
                                    FROM divisions d
                                    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
                                    INNER JOIN companies c ON bu.company_id = c.company_id
                                    ORDER BY c.company_name, bu.unit_name, d.division_name
                                ");
                            }
                            $parent_divisions = $parent_divisions_stmt->fetchAll();
                            foreach ($parent_divisions as $pd):
                                // Don't show self as parent option when editing
                                if ($edit_division && $pd['division_id'] == $edit_division['division_id']) continue;
                            ?>
                                <option value="<?php echo $pd['division_id']; ?>"
                                    <?php echo ($edit_division && $edit_division['parent_division_id'] == $pd['division_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pd['company_name'] . ' - ' . $pd['unit_name'] . ' - ' . $pd['division_name'] . ' (' . $pd['division_type'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Optional: Select parent division (e.g., RPH under BKPH)</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Division Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="division_code" required 
                                   value="<?php echo $edit_division ? htmlspecialchars($edit_division['division_code']) : ''; ?>"
                                   placeholder="e.g., AFD-A">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Division Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="division_type" required>
                                <optgroup label="Plantation">
                                    <option value="Afdeling" <?php echo ($edit_division && $edit_division['division_type'] == 'Afdeling') ? 'selected' : ''; ?>>Afdeling</option>
                                </optgroup>
                                <optgroup label="Forestry">
                                    <option value="BKPH" <?php echo ($edit_division && $edit_division['division_type'] == 'BKPH') ? 'selected' : ''; ?>>BKPH (Bagian KPH)</option>
                                    <option value="RPH" <?php echo ($edit_division && $edit_division['division_type'] == 'RPH') ? 'selected' : ''; ?>>RPH (Resort Pengelolaan Hutan)</option>
                                </optgroup>
                                <optgroup label="Other">
                                    <option value="Other" <?php echo ($edit_division && $edit_division['division_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Division Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="division_name" required
                               value="<?php echo $edit_division ? htmlspecialchars($edit_division['division_name']) : ''; ?>"
                               placeholder="e.g., Afdeling A">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Assistant/Supervisor</label>
                        <select class="form-select select2-assistant" name="assistant_name" id="assistant_select">
                            <option value="">Select Worker</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?php echo htmlspecialchars($worker['full_name']); ?>"
                                    <?php echo ($edit_division && $edit_division['assistant_name'] == $worker['full_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($worker['employee_code'] . ' - ' . $worker['full_name'] . ' (' . $worker['position'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Type to search from active workers</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="Active" <?php echo ($edit_division && $edit_division['status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo ($edit_division && $edit_division['status'] == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo $edit_division ? htmlspecialchars($edit_division['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_division ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_division): ?>
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
    $('.select2-assistant').select2({
        theme: 'bootstrap-5',
        placeholder: 'Select Assistant/Supervisor',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#addModal')
    });
});
</script>