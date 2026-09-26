<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Get activity_id from URL
$activity_id = get('activity_id');
if (!$activity_id) {
    set_message('error', 'Activity ID is required');
    redirect('activities.php');
}

// Get activity details
$stmt = $db->prepare("
    SELECT a.*, ag.group_name 
    FROM activities a
    INNER JOIN activity_groups ag ON a.activity_group_id = ag.id
    WHERE a.id = ?
");
$stmt->execute([$activity_id]);
$activity = $stmt->fetch();

if (!$activity) {
    set_message('error', 'Activity not found');
    redirect('activities.php');
}

// Handle form submissions
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add_mapping') {
        try {
            $stmt = $db->prepare("
                INSERT INTO activity_gl_mapping (activity_id, block_status, gl_account_id, cost_category,
                                                cost_type, allocation_percentage, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([
                $activity_id,
                post('block_status'),
                post('gl_account_id'),
                post('cost_category', 'labor'),
                post('cost_type', 'direct'),
                post('allocation_percentage', 100),
                post('notes')
            ]);
            
            set_message('success', 'GL account mapping added successfully!');
            redirect('activity_gl_mapping.php?activity_id=' . $activity_id);
        } catch (PDOException $e) {
            set_message('error', 'Error adding mapping: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'update_mapping') {
        try {
            $stmt = $db->prepare("
                UPDATE activity_gl_mapping
                SET block_status = ?, gl_account_id = ?, cost_category = ?, cost_type = ?,
                    allocation_percentage = ?, is_active = ?, notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                post('block_status'),
                post('gl_account_id'),
                post('cost_category'),
                post('cost_type'),
                post('allocation_percentage'),
                post('is_active', 1),
                post('notes'),
                post('mapping_id')
            ]);
            
            set_message('success', 'GL account mapping updated successfully!');
            redirect('activity_gl_mapping.php?activity_id=' . $activity_id);
        } catch (PDOException $e) {
            set_message('error', 'Error updating mapping: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete_mapping') {
        try {
            $stmt = $db->prepare("DELETE FROM activity_gl_mapping WHERE id = ?");
            $stmt->execute([post('mapping_id')]);
            
            set_message('success', 'GL account mapping deleted successfully!');
            redirect('activity_gl_mapping.php?activity_id=' . $activity_id);
        } catch (PDOException $e) {
            set_message('error', 'Error deleting mapping: ' . $e->getMessage());
        }
    }
}

// Get mapping for editing
$edit_mapping = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM activity_gl_mapping WHERE id = ?");
    $stmt->execute([get('id')]);
    $edit_mapping = $stmt->fetch();
}

$page_title = "Activity GL Account Mapping";
require_once 'includes/header.php';

// Fetch all GL accounts for dropdown
$gl_accounts_stmt = $db->query("
    SELECT id, account_code, account_name, account_type 
    FROM general_ledger_accounts 
    WHERE is_active = TRUE 
    ORDER BY account_code
");
$gl_accounts = $gl_accounts_stmt->fetchAll();

// Fetch existing mappings for this activity
$mappings_stmt = $db->prepare("
    SELECT agm.*, 
           gla.account_code, gla.account_name, gla.account_type
    FROM activity_gl_mapping agm
    INNER JOIN general_ledger_accounts gla ON agm.gl_account_id = gla.id
    WHERE agm.activity_id = ?
    ORDER BY 
        FIELD(agm.block_status, 'LC', 'TBM', 'TM', 'ALL'),
        gla.account_code
");
$mappings_stmt->execute([$activity_id]);
$mappings = $mappings_stmt->fetchAll();
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-link-45deg"></i> GL Account Mapping</h1>
            <p class="text-muted">
                Activity: <strong><?php echo htmlspecialchars($activity['activity_code'] . ' - ' . $activity['activity_name']); ?></strong>
                <br>Group: <?php echo htmlspecialchars($activity['group_name']); ?>
            </p>
        </div>
        <div class="col-auto">
            <a href="activities.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Activities
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add GL Mapping
            </button>
        </div>
    </div>
</div>

<!-- Info Card -->
<div class="alert alert-info">
    <h5><i class="bi bi-info-circle"></i> About GL Account Mapping</h5>
    <p class="mb-0">
        Map this activity to different GL accounts based on block status:
    </p>
    <ul class="mb-0">
        <li><strong>LC (Land Clearing)</strong>: Costs are capitalized to Immature Oil Palms asset account</li>
        <li><strong>TBM (Tanaman Belum Menghasilkan)</strong>: Costs are capitalized to Immature Oil Palms asset account</li>
        <li><strong>TM (Tanaman Menghasilkan)</strong>: Costs are expensed to operating expense accounts</li>
        <li><strong>ALL</strong>: Default mapping for all statuses (if no specific mapping exists)</li>
    </ul>
</div>

<!-- Mappings Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> GL Account Mappings (<?php echo count($mappings); ?> mappings)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th style="width: 10%;">Block Status</th>
                        <th style="width: 12%;">GL Account Code</th>
                        <th style="width: 25%;">GL Account Name</th>
                        <th style="width: 10%;">Account Type</th>
                        <th style="width: 12%;">Cost Category</th>
                        <th style="width: 10%;">Cost Type</th>
                        <th style="width: 8%;">Allocation %</th>
                        <th style="width: 6%;">Status</th>
                        <th style="width: 7%;" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mappings)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">
                                No GL account mappings found. Add mappings to specify which accounts to use for different block statuses.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($mappings as $mapping): ?>
                            <tr>
                                <td>
                                    <?php
                                    $status_colors = [
                                        'LC' => 'warning',
                                        'TBM' => 'info',
                                        'TM' => 'success',
                                        'ALL' => 'secondary'
                                    ];
                                    $color = $status_colors[$mapping['block_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $color; ?>">
                                        <?php echo $mapping['block_status']; ?>
                                    </span>
                                </td>
                                <td><code><?php echo htmlspecialchars($mapping['account_code']); ?></code></td>
                                <td><?php echo htmlspecialchars($mapping['account_name']); ?></td>
                                <td>
                                    <?php
                                    $type_colors = [
                                        'asset' => 'success',
                                        'expense' => 'secondary'
                                    ];
                                    $type_color = $type_colors[$mapping['account_type']] ?? 'primary';
                                    ?>
                                    <span class="badge bg-<?php echo $type_color; ?>">
                                        <?php echo ucfirst($mapping['account_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $category_labels = [
                                        'labor' => 'Labor',
                                        'material' => 'Material',
                                        'vehicle_equipment' => 'Vehicle/Equipment',
                                        'overhead' => 'Overhead',
                                        'other' => 'Other'
                                    ];
                                    $category_colors = [
                                        'labor' => 'primary',
                                        'material' => 'success',
                                        'vehicle_equipment' => 'warning',
                                        'overhead' => 'info',
                                        'other' => 'secondary'
                                    ];
                                    $cat_label = $category_labels[$mapping['cost_category']] ?? 'Unknown';
                                    $cat_color = $category_colors[$mapping['cost_category']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $cat_color; ?>">
                                        <?php echo $cat_label; ?>
                                    </span>
                                </td>
                                <td><?php echo ucfirst($mapping['cost_type']); ?></td>
                                <td><?php echo number_format($mapping['allocation_percentage'], 2); ?>%</td>
                                <td>
                                    <span class="badge bg-<?php echo $mapping['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $mapping['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="?activity_id=<?php echo $activity_id; ?>&action=edit&id=<?php echo $mapping['id']; ?>" 
                                       class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirmDelete('Delete this mapping?');">
                                        <input type="hidden" name="action" value="delete_mapping">
                                        <input type="hidden" name="mapping_id" value="<?php echo $mapping['id']; ?>">
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
            <form method="POST" action="activity_gl_mapping.php?activity_id=<?php echo $activity_id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_mapping ? 'Edit GL Account Mapping' : 'Add GL Account Mapping'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_mapping ? 'update_mapping' : 'add_mapping'; ?>">
                    <?php if ($edit_mapping): ?>
                        <input type="hidden" name="mapping_id" value="<?php echo $edit_mapping['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Block Status <span class="text-danger">*</span></label>
                            <select class="form-select" name="block_status" required>
                                <option value="">Select Status</option>
                                <option value="LC" <?php echo ($edit_mapping && $edit_mapping['block_status'] == 'LC') ? 'selected' : ''; ?>>LC - Land Clearing</option>
                                <option value="TBM" <?php echo ($edit_mapping && $edit_mapping['block_status'] == 'TBM') ? 'selected' : ''; ?>>TBM - Immature</option>
                                <option value="TM" <?php echo ($edit_mapping && $edit_mapping['block_status'] == 'TM') ? 'selected' : ''; ?>>TM - Mature</option>
                                <option value="ALL" <?php echo ($edit_mapping && $edit_mapping['block_status'] == 'ALL') ? 'selected' : ''; ?>>ALL - All Statuses</option>
                            </select>
                            <small class="text-muted">Which block status this mapping applies to</small>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Cost Category <span class="text-danger">*</span></label>
                            <select class="form-select" name="cost_category" required>
                                <option value="labor" <?php echo ($edit_mapping && $edit_mapping['cost_category'] == 'labor') ? 'selected' : ''; ?>>Labor</option>
                                <option value="material" <?php echo ($edit_mapping && $edit_mapping['cost_category'] == 'material') ? 'selected' : ''; ?>>Material</option>
                                <option value="vehicle_equipment" <?php echo ($edit_mapping && $edit_mapping['cost_category'] == 'vehicle_equipment') ? 'selected' : ''; ?>>Vehicle/Heavy Equipment</option>
                                <option value="overhead" <?php echo ($edit_mapping && $edit_mapping['cost_category'] == 'overhead') ? 'selected' : ''; ?>>Overhead</option>
                                <option value="other" <?php echo ($edit_mapping && $edit_mapping['cost_category'] == 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                            <small class="text-muted">Type of cost resource</small>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Cost Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="cost_type" required>
                                <option value="direct" <?php echo ($edit_mapping && $edit_mapping['cost_type'] == 'direct') ? 'selected' : ''; ?>>Direct Cost</option>
                                <option value="indirect" <?php echo ($edit_mapping && $edit_mapping['cost_type'] == 'indirect') ? 'selected' : ''; ?>>Indirect Cost</option>
                            </select>
                            <small class="text-muted">Direct or indirect allocation</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">GL Account <span class="text-danger">*</span></label>
                        <select class="form-select select2-gl-account" name="gl_account_id" required>
                            <option value="">Select GL Account</option>
                            <?php foreach ($gl_accounts as $gl): ?>
                                <option value="<?php echo $gl['id']; ?>" 
                                    <?php echo ($edit_mapping && $edit_mapping['gl_account_id'] == $gl['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($gl['account_code'] . ' - ' . $gl['account_name'] . ' (' . ucfirst($gl['account_type']) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Type to search GL accounts</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Allocation Percentage</label>
                        <input type="number" class="form-control" name="allocation_percentage" 
                               min="0" max="100" step="0.01"
                               value="<?php echo $edit_mapping ? $edit_mapping['allocation_percentage'] : '100.00'; ?>">
                        <small class="text-muted">Percentage of cost to allocate to this account (default: 100%)</small>
                    </div>
                    
                    <?php if ($edit_mapping): ?>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="is_active" value="1"
                                   <?php echo $edit_mapping['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo $edit_mapping ? htmlspecialchars($edit_mapping['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_mapping ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_mapping): ?>
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

<?php require_once 'includes/footer.php'; ?>

<!-- Select2 JS (loaded after jQuery from footer) -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
// Initialize Select2 for searchable GL account dropdown
$(document).ready(function() {
    $('.select2-gl-account').select2({
        theme: 'bootstrap-5',
        placeholder: 'Select GL Account',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#addModal')
    });
});

function confirmDelete(message) {
    return confirm(message);
}
</script>

// Made with Bob