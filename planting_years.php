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
                INSERT INTO planting_years (division_id, year, target_area, actual_area, target_plants, 
                                          actual_plants, planting_start_date, planting_end_date, 
                                          plant_type, status, notes, created_by)
                VALUES (:division_id, :year, :target_area, :actual_area, :target_plants,
                        :actual_plants, :planting_start_date, :planting_end_date,
                        :plant_type, :status, :notes, 'admin')
            ");
            
            $stmt->execute([
                ':division_id' => post('division_id'),
                ':year' => post('year'),
                ':target_area' => post('target_area', 0),
                ':actual_area' => post('actual_area', 0),
                ':target_plants' => post('target_plants', 0),
                ':actual_plants' => post('actual_plants', 0),
                ':planting_start_date' => post('planting_start_date'),
                ':planting_end_date' => post('planting_end_date'),
                ':plant_type' => post('plant_type', 'Oil Palm'),
                ':status' => post('status', 'Planning'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Planting year added successfully!');
            redirect('planting_years.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding planting year: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE planting_years 
                SET division_id = :division_id, year = :year, target_area = :target_area,
                    actual_area = :actual_area, target_plants = :target_plants, actual_plants = :actual_plants,
                    planting_start_date = :planting_start_date, planting_end_date = :planting_end_date,
                    plant_type = :plant_type, status = :status, notes = :notes, updated_by = 'admin'
                WHERE planting_year_id = :id
            ");
            
            $stmt->execute([
                ':id' => post('planting_year_id'),
                ':division_id' => post('division_id'),
                ':year' => post('year'),
                ':target_area' => post('target_area', 0),
                ':actual_area' => post('actual_area', 0),
                ':target_plants' => post('target_plants', 0),
                ':actual_plants' => post('actual_plants', 0),
                ':planting_start_date' => post('planting_start_date'),
                ':planting_end_date' => post('planting_end_date'),
                ':plant_type' => post('plant_type'),
                ':status' => post('status'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Planting year updated successfully!');
            redirect('planting_years.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating planting year: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM planting_years WHERE planting_year_id = :id");
            $stmt->execute([':id' => post('planting_year_id')]);
            
            set_message('success', 'Planting year deleted successfully!');
            redirect('planting_years.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting planting year: ' . $e->getMessage());
        }
    }
}

// Get planting year for editing (before header)
$edit_planting_year = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM planting_years WHERE planting_year_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_planting_year = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Planting Years Management";
require_once 'includes/header.php';

// Fetch divisions for dropdown
$divisions_stmt = $db->query("
    SELECT d.division_id, d.division_code, d.division_name, 
           bu.unit_name, c.company_name 
    FROM divisions d
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE d.status = 'Active'
    ORDER BY c.company_name, bu.unit_name, d.division_code
");
$divisions = $divisions_stmt->fetchAll();

// Fetch planting years with statistics
$search = get('search', '');
$division_filter = get('division_id', '');
$year_filter = get('year', '');
$plant_type_filter = get('plant_type', '');
$status_filter = get('status', '');

$sql = "SELECT py.*,
        d.division_code, d.division_name,
        bu.unit_code, bu.unit_name,
        c.company_name, c.company_code,
        (SELECT COUNT(*) FROM blocks b WHERE b.planting_year_id = py.planting_year_id) as total_blocks,
        COALESCE(py.total_area_ha, 0) as total_area_ha,
        COALESCE(py.total_plants, 0) as total_plants,
        (SELECT COUNT(*) FROM blocks b WHERE b.planting_year_id = py.planting_year_id AND b.status = 'TM') as tm_blocks,
        (SELECT COUNT(*) FROM blocks b WHERE b.planting_year_id = py.planting_year_id AND b.status = 'TBM') as tbm_blocks
        FROM planting_years py
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE 1=1";

if ($search) {
    $sql .= " AND (py.year LIKE :search OR d.division_name LIKE :search)";
}
if ($division_filter) {
    $sql .= " AND py.division_id = :division_id";
}
if ($year_filter) {
    $sql .= " AND py.year = :year";
}
if ($plant_type_filter) {
    $sql .= " AND py.plant_type = :plant_type";
}
if ($status_filter) {
    $sql .= " AND py.status = :status";
}

$sql .= " ORDER BY py.year DESC, c.company_name, bu.unit_name, d.division_code";

$stmt = $db->prepare($sql);
if ($search) {
    $stmt->bindValue(':search', "%$search%");
}
if ($division_filter) {
    $stmt->bindValue(':division_id', $division_filter);
}
if ($year_filter) {
    $stmt->bindValue(':year', $year_filter);
}
if ($plant_type_filter) {
    $stmt->bindValue(':plant_type', $plant_type_filter);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$planting_years = $stmt->fetchAll();

// Calculate summary statistics from recursive summary columns
$total_planting_years = count($planting_years);
$total_target_area = array_sum(array_column($planting_years, 'target_area'));
$total_actual_area = array_sum(array_column($planting_years, 'total_area_ha')); // Use recursive summary
$total_blocks = array_sum(array_column($planting_years, 'total_blocks'));
$total_plants = array_sum(array_column($planting_years, 'total_plants'));

// Get unique years for filter
$years_stmt = $db->query("SELECT DISTINCT year FROM planting_years ORDER BY year DESC");
$years = $years_stmt->fetchAll();
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-calendar-event"></i> Planting Years Management</h1>
            <p class="text-muted">Manage planting years and track planting progress</p>
        </div>
        <div class="col-auto">
            <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-house"></i> Dashboard
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New Planting Year
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_planting_years; ?></h3>
                <p><i class="bi bi-calendar-event"></i> Total Planting Years</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_actual_area); ?></h3>
                <p><i class="bi bi-map"></i> Total Area (Ha)</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <a href="blocks.php" class="text-decoration-none">
            <div class="card stat-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                <div class="card-body">
                    <h3 class="text-dark"><?php echo $total_blocks; ?></h3>
                    <p class="text-muted"><i class="bi bi-grid"></i> Total Blocks</p>
                </div>
            </div>
        </a>
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
                <select class="form-select" name="division_id">
                    <option value="">All Divisions</option>
                    <?php foreach ($divisions as $division): ?>
                        <option value="<?php echo $division['division_id']; ?>" <?php echo $division_filter == $division['division_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($division['company_name'] . ' - ' . $division['unit_name'] . ' - ' . $division['division_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="year">
                    <option value="">All Years</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo $y['year']; ?>" <?php echo $year_filter == $y['year'] ? 'selected' : ''; ?>>
                            <?php echo $y['year']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="plant_type">
                    <option value="">All Types</option>
                    <option value="Oil Palm" <?php echo $plant_type_filter == 'Oil Palm' ? 'selected' : ''; ?>>Oil Palm</option>
                    <option value="Rubber" <?php echo $plant_type_filter == 'Rubber' ? 'selected' : ''; ?>>Rubber</option>
                    <option value="Other" <?php echo $plant_type_filter == 'Other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                <a href="planting_years.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Planting Years Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Planting Years List (<?php echo count($planting_years); ?> records)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Year</th>
                        <th>Division</th>
                        <th>Plant Type</th>
                        <th>Blocks</th>
                        <th>Area (Ha)</th>
                        <th>Plants</th>
                        <th>TM/TBM</th>
                        <th>Planting Period</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($planting_years)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No planting years found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($planting_years as $py): ?>
                            <tr>
                                <td><strong><?php echo $py['year']; ?></strong></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($py['company_code']); ?> - <?php echo htmlspecialchars($py['unit_code']); ?></small><br>
                                    <?php echo htmlspecialchars($py['division_name']); ?>
                                </td>
                                <td><span class="badge bg-info"><?php echo isset($py['plant_type']) ? htmlspecialchars($py['plant_type']) : '-'; ?></span></td>
                                <td><span class="badge bg-secondary"><?php echo format_number($py['total_blocks'], 0); ?></span></td>
                                <td class="text-end"><?php echo format_number($py['total_area_ha']); ?></td>
                                <td class="text-end"><?php echo format_number($py['total_plants'], 0); ?></td>
                                <td>
                                    <small>
                                        <span class="badge bg-success"><?php echo $py['tm_blocks']; ?> TM</span>
                                        <span class="badge bg-warning"><?php echo $py['tbm_blocks']; ?> TBM</span>
                                    </small>
                                </td>
                                <td>
                                    <small>
                                        <?php echo isset($py['planting_start_date']) ? format_date($py['planting_start_date'], 'd M Y') : '-'; ?><br>
                                        to <?php echo isset($py['planting_end_date']) ? format_date($py['planting_end_date'], 'd M Y') : '-'; ?>
                                    </small>
                                </td>
                                <td><?php echo get_status_badge($py['status']); ?></td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $py['planting_year_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="blocks.php?planting_year_id=<?php echo $py['planting_year_id']; ?>" class="btn btn-sm btn-info" title="View Blocks">
                                        <i class="bi bi-grid"></i>
                                    </a>
                                    <form method="POST" action="planting_years.php" style="display:inline;" onsubmit="return confirmDelete('Delete this planting year and all related blocks?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="planting_year_id" value="<?php echo $py['planting_year_id']; ?>">
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
            <form method="POST" action="planting_years.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_planting_year ? 'Edit Planting Year' : 'Add New Planting Year'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_planting_year ? 'edit' : 'add'; ?>">
                    <?php if ($edit_planting_year): ?>
                        <input type="hidden" name="planting_year_id" value="<?php echo $edit_planting_year['planting_year_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Division <span class="text-danger">*</span></label>
                            <select class="form-select" name="division_id" required>
                                <option value="">Select Division</option>
                                <?php foreach ($divisions as $division): ?>
                                    <option value="<?php echo $division['division_id']; ?>" 
                                        <?php echo ($edit_planting_year && $edit_planting_year['division_id'] == $division['division_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($division['company_name'] . ' - ' . $division['unit_name'] . ' - ' . $division['division_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Year <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="year" required min="1000" max="9999"
                                   value="<?php echo $edit_planting_year ? $edit_planting_year['year'] : date('Y'); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Plant Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="plant_type" required>
                                <option value="Oil Palm" <?php echo ($edit_planting_year && $edit_planting_year['plant_type'] == 'Oil Palm') ? 'selected' : ''; ?>>Oil Palm</option>
                                <option value="Rubber" <?php echo ($edit_planting_year && $edit_planting_year['plant_type'] == 'Rubber') ? 'selected' : ''; ?>>Rubber</option>
                                <option value="Other" <?php echo ($edit_planting_year && $edit_planting_year['plant_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Planning" <?php echo ($edit_planting_year && $edit_planting_year['status'] == 'Planning') ? 'selected' : ''; ?>>Planning</option>
                                <option value="In Progress" <?php echo ($edit_planting_year && $edit_planting_year['status'] == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Completed" <?php echo ($edit_planting_year && $edit_planting_year['status'] == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="Inactive" <?php echo ($edit_planting_year && $edit_planting_year['status'] == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Planting Start Date</label>
                            <input type="date" class="form-control" name="planting_start_date"
                                   value="<?php echo $edit_planting_year ? $edit_planting_year['planting_start_date'] : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Planting End Date</label>
                            <input type="date" class="form-control" name="planting_end_date"
                                   value="<?php echo $edit_planting_year ? $edit_planting_year['planting_end_date'] : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo $edit_planting_year ? htmlspecialchars($edit_planting_year['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_planting_year ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_planting_year): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

<script>
// Confirm delete
function confirmDelete(message) {
    return confirm(message);
}
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob