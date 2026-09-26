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
                INSERT INTO plant_varieties (variety_code, variety_name, category, clone_name, origin,
                                           characteristics, avg_yield, maturity_age, productive_lifespan,
                                           status, notes)
                VALUES (:variety_code, :variety_name, :category, :clone_name, :origin,
                        :characteristics, :avg_yield, :maturity_age, :productive_lifespan,
                        :status, :notes)
            ");
            
            $stmt->execute([
                ':variety_code' => post('variety_code'),
                ':variety_name' => post('variety_name'),
                ':category' => post('category', 'Oil Palm'),
                ':clone_name' => post('clone_name'),
                ':origin' => post('origin'),
                ':characteristics' => post('characteristics'),
                ':avg_yield' => post('avg_yield', 0),
                ':maturity_age' => post('maturity_age', 0),
                ':productive_lifespan' => post('productive_lifespan', 0),
                ':status' => post('status', 'Active'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Plant variety added successfully!');
            redirect('plant_varieties.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding plant variety: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE plant_varieties 
                SET variety_code = :variety_code, variety_name = :variety_name, category = :category,
                    clone_name = :clone_name, origin = :origin, characteristics = :characteristics,
                    avg_yield = :avg_yield, maturity_age = :maturity_age, productive_lifespan = :productive_lifespan,
                    status = :status, notes = :notes
                WHERE variety_id = :id
            ");
            
            $stmt->execute([
                ':id' => post('variety_id'),
                ':variety_code' => post('variety_code'),
                ':variety_name' => post('variety_name'),
                ':category' => post('category'),
                ':clone_name' => post('clone_name'),
                ':origin' => post('origin'),
                ':characteristics' => post('characteristics'),
                ':avg_yield' => post('avg_yield'),
                ':maturity_age' => post('maturity_age'),
                ':productive_lifespan' => post('productive_lifespan'),
                ':status' => post('status'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Plant variety updated successfully!');
            redirect('plant_varieties.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating plant variety: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM plant_varieties WHERE variety_id = :id");
            $stmt->execute([':id' => post('variety_id')]);
            
            set_message('success', 'Plant variety deleted successfully!');
            redirect('plant_varieties.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting plant variety: ' . $e->getMessage());
        }
    }
}

// Get variety for editing (before header)
$edit_variety = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM plant_varieties WHERE variety_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_variety = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Plant Varieties Management";
require_once 'includes/header.php';

// Fetch plant varieties
$search = get('search', '');
$category_filter = get('category', '');
$status_filter = get('status', '');

$sql = "SELECT pv.*,
        0 as blocks_using
        FROM plant_varieties pv
        WHERE 1=1";

if ($search) {
    $sql .= " AND (pv.variety_code LIKE :search OR pv.variety_name LIKE :search OR pv.clone_name LIKE :search)";
}
if ($category_filter) {
    $sql .= " AND pv.category = :category";
}
if ($status_filter) {
    $sql .= " AND pv.status = :status";
}

$sql .= " ORDER BY pv.category, pv.variety_name";

$stmt = $db->prepare($sql);
if ($search) {
    $stmt->bindValue(':search', "%$search%");
}
if ($category_filter) {
    $stmt->bindValue(':category', $category_filter);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$varieties = $stmt->fetchAll();

// Calculate summary statistics
$total_varieties = count($varieties);
$oil_palm_varieties = count(array_filter($varieties, function($v) { return $v['category'] == 'Oil Palm'; }));
$rubber_varieties = count(array_filter($varieties, function($v) { return $v['category'] == 'Rubber'; }));
$active_varieties = count(array_filter($varieties, function($v) { return $v['status'] == 'Active'; }));
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-flower1"></i> Plant Varieties Management</h1>
            <p class="text-muted">Manage plant varieties, clones, and their characteristics</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New Variety
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_varieties; ?></h3>
                <p><i class="bi bi-flower1"></i> Total Varieties</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $oil_palm_varieties; ?></h3>
                <p><i class="bi bi-tree"></i> Oil Palm Varieties</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $rubber_varieties; ?></h3>
                <p><i class="bi bi-droplet"></i> Rubber Varieties</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $active_varieties; ?></h3>
                <p><i class="bi bi-check-circle"></i> Active Varieties</p>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-5">
                <input type="text" class="form-control" name="search" placeholder="Search by code, name, or clone..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="category">
                    <option value="">All Categories</option>
                    <option value="Oil Palm" <?php echo $category_filter == 'Oil Palm' ? 'selected' : ''; ?>>Oil Palm</option>
                    <option value="Rubber" <?php echo $category_filter == 'Rubber' ? 'selected' : ''; ?>>Rubber</option>
                    <option value="Other" <?php echo $category_filter == 'Other' ? 'selected' : ''; ?>>Other</option>
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
                <a href="plant_varieties.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Varieties Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Plant Varieties List (<?php echo count($varieties); ?> records)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Variety Name</th>
                        <th>Category</th>
                        <th>Clone Name</th>
                        <th>Origin</th>
                        <th>Avg Yield (T/Ha/Yr)</th>
                        <th>Maturity Age</th>
                        <th>Lifespan</th>
                        <th>Blocks Using</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($varieties)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">No plant varieties found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($varieties as $variety): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($variety['variety_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($variety['variety_name']); ?></td>
                                <td><span class="badge bg-info"><?php echo $variety['category']; ?></span></td>
                                <td><?php echo htmlspecialchars($variety['clone_name']); ?></td>
                                <td><?php echo htmlspecialchars($variety['origin']); ?></td>
                                <td><?php echo format_number($variety['avg_yield']); ?></td>
                                <td><?php echo $variety['maturity_age']; ?> years</td>
                                <td><?php echo $variety['productive_lifespan']; ?> years</td>
                                <td>
                                    <?php if ($variety['blocks_using'] > 0): ?>
                                        <span class="badge bg-success"><?php echo $variety['blocks_using']; ?> blocks</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Not used</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo get_status_badge($variety['status']); ?></td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $variety['variety_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#detailModal<?php echo $variety['variety_id']; ?>" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <form method="POST" action="plant_varieties.php" style="display:inline;" onsubmit="return confirmDelete('Delete this plant variety?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="variety_id" value="<?php echo $variety['variety_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete" <?php echo $variety['blocks_using'] > 0 ? 'disabled' : ''; ?>>
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            
                            <!-- Detail Modal for each variety -->
                            <div class="modal fade" id="detailModal<?php echo $variety['variety_id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><?php echo htmlspecialchars($variety['variety_name']); ?> - Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <p><strong>Variety Code:</strong> <?php echo htmlspecialchars($variety['variety_code']); ?></p>
                                                    <p><strong>Category:</strong> <?php echo $variety['category']; ?></p>
                                                    <p><strong>Clone Name:</strong> <?php echo htmlspecialchars($variety['clone_name']); ?></p>
                                                    <p><strong>Origin:</strong> <?php echo htmlspecialchars($variety['origin']); ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p><strong>Average Yield:</strong> <?php echo format_number($variety['avg_yield']); ?> T/Ha/Year</p>
                                                    <p><strong>Maturity Age:</strong> <?php echo $variety['maturity_age']; ?> years</p>
                                                    <p><strong>Productive Lifespan:</strong> <?php echo $variety['productive_lifespan']; ?> years</p>
                                                    <p><strong>Status:</strong> <?php echo get_status_badge($variety['status']); ?></p>
                                                </div>
                                            </div>
                                            <hr>
                                            <p><strong>Characteristics:</strong></p>
                                            <p><?php echo nl2br(htmlspecialchars($variety['characteristics'])); ?></p>
                                            <?php if ($variety['notes']): ?>
                                                <hr>
                                                <p><strong>Notes:</strong></p>
                                                <p><?php echo nl2br(htmlspecialchars($variety['notes'])); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
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
            <form method="POST" action="plant_varieties.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_variety ? 'Edit Plant Variety' : 'Add New Plant Variety'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_variety ? 'edit' : 'add'; ?>">
                    <?php if ($edit_variety): ?>
                        <input type="hidden" name="variety_id" value="<?php echo $edit_variety['variety_id']; ?>">
                    <?php endif; ?>
                    
                    <!-- Basic Information -->
                    <h6 class="border-bottom pb-2 mb-3">Basic Information</h6>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Variety Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="variety_code" required 
                                   value="<?php echo $edit_variety ? htmlspecialchars($edit_variety['variety_code']) : ''; ?>"
                                   placeholder="e.g., DXP-TENERA">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Variety Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="variety_name" required
                                   value="<?php echo $edit_variety ? htmlspecialchars($edit_variety['variety_name']) : ''; ?>"
                                   placeholder="e.g., DxP Tenera">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select class="form-select" name="category" required>
                                <option value="Oil Palm" <?php echo ($edit_variety && $edit_variety['category'] == 'Oil Palm') ? 'selected' : ''; ?>>Oil Palm</option>
                                <option value="Rubber" <?php echo ($edit_variety && $edit_variety['category'] == 'Rubber') ? 'selected' : ''; ?>>Rubber</option>
                                <option value="Other" <?php echo ($edit_variety && $edit_variety['category'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Clone Name</label>
                            <input type="text" class="form-control" name="clone_name"
                                   value="<?php echo $edit_variety ? htmlspecialchars($edit_variety['clone_name']) : ''; ?>"
                                   placeholder="e.g., DxP">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Origin</label>
                            <input type="text" class="form-control" name="origin"
                                   value="<?php echo $edit_variety ? htmlspecialchars($edit_variety['origin']) : ''; ?>"
                                   placeholder="e.g., PPKS Indonesia">
                        </div>
                    </div>
                    
                    <!-- Performance Characteristics -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4">Performance Characteristics</h6>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Average Yield (Tons/Ha/Year)</label>
                            <input type="number" step="0.01" class="form-control" name="avg_yield"
                                   value="<?php echo $edit_variety ? $edit_variety['avg_yield'] : ''; ?>"
                                   placeholder="e.g., 25.00">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Maturity Age (Years)</label>
                            <input type="number" class="form-control" name="maturity_age"
                                   value="<?php echo $edit_variety ? $edit_variety['maturity_age'] : '3'; ?>"
                                   placeholder="e.g., 3">
                            <small class="text-muted">Years until first harvest</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Productive Lifespan (Years)</label>
                            <input type="number" class="form-control" name="productive_lifespan"
                                   value="<?php echo $edit_variety ? $edit_variety['productive_lifespan'] : '25'; ?>"
                                   placeholder="e.g., 25">
                            <small class="text-muted">Years of productive life</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Characteristics</label>
                        <textarea class="form-control" name="characteristics" rows="4" 
                                  placeholder="Describe key characteristics: yield potential, disease resistance, growth habit, etc."><?php echo $edit_variety ? htmlspecialchars($edit_variety['characteristics']) : ''; ?></textarea>
                    </div>
                    
                    <!-- Status and Notes -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4">Status & Notes</h6>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="Active" <?php echo ($edit_variety && $edit_variety['status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo ($edit_variety && $edit_variety['status'] == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo $edit_variety ? htmlspecialchars($edit_variety['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_variety ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_variety): ?>
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