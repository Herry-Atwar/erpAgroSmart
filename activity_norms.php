<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Get activity_id from URL
$activity_id = get('activity_id');
if (!$activity_id) {
    set_message('Activity ID is required', 'danger');
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
    set_message('Activity not found', 'danger');
    redirect('activities.php');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'add_norm') {
        try {
            $stmt = $db->prepare("
                INSERT INTO activity_norms (
                    activity_id, norm_name, man_days_per_unit, unit_of_measure,
                    productivity_factor, terrain_type, palm_age_min, palm_age_max,
                    is_default, notes, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $is_default = post('is_default') ? 1 : 0;
            
            // If this is set as default, unset other defaults
            if ($is_default) {
                $db->prepare("UPDATE activity_norms SET is_default = 0 WHERE activity_id = ?")->execute([$activity_id]);
            }
            
            $stmt->execute([
                $activity_id,
                post('norm_name'),
                post('man_days_per_unit'),
                post('unit_of_measure'),
                post('productivity_factor') ?: 1.00,
                post('terrain_type') ?: 'flat',
                post('palm_age_min') ?: 0,
                post('palm_age_max') ?: 100,
                $is_default,
                post('notes')
            ]);
            
            set_message('Activity norm added successfully!', 'success');
            redirect('activity_norms.php?activity_id=' . $activity_id);
        } catch (Exception $e) {
            set_message('Error adding norm: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'update_norm') {
        try {
            $is_default = post('is_default') ? 1 : 0;
            
            // If this is set as default, unset other defaults
            if ($is_default) {
                $db->prepare("UPDATE activity_norms SET is_default = 0 WHERE activity_id = ? AND id != ?")->execute([$activity_id, post('norm_id')]);
            }
            
            $stmt = $db->prepare("
                UPDATE activity_norms SET
                    norm_name = ?, man_days_per_unit = ?, unit_of_measure = ?,
                    productivity_factor = ?, terrain_type = ?, palm_age_min = ?, palm_age_max = ?,
                    is_default = ?, is_active = ?, notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                post('norm_name'),
                post('man_days_per_unit'),
                post('unit_of_measure'),
                post('productivity_factor'),
                post('terrain_type'),
                post('palm_age_min'),
                post('palm_age_max'),
                $is_default,
                post('is_active') ? 1 : 0,
                post('notes'),
                post('norm_id')
            ]);
            
            set_message('Activity norm updated successfully!', 'success');
            redirect('activity_norms.php?activity_id=' . $activity_id);
        } catch (Exception $e) {
            set_message('Error updating norm: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'delete_norm') {
        try {
            $stmt = $db->prepare("DELETE FROM activity_norms WHERE id = ?");
            $stmt->execute([post('norm_id')]);
            
            set_message('Activity norm deleted successfully!', 'success');
            redirect('activity_norms.php?activity_id=' . $activity_id);
        } catch (Exception $e) {
            set_message('Error deleting norm: ' . $e->getMessage(), 'danger');
        }
    }
}

// Get edit record
$edit_norm = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM activity_norms WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_norm = $stmt->fetch();
}

// Fetch norms for this activity
$norms_stmt = $db->prepare("SELECT * FROM activity_norms WHERE activity_id = ? ORDER BY is_default DESC, norm_name");
$norms_stmt->execute([$activity_id]);
$norms = $norms_stmt->fetchAll();

// Get worker statuses for cost calculation examples
try {
    $statuses_stmt = $db->query("SELECT * FROM vw_current_wage_rates ORDER BY display_order");
    $worker_statuses = $statuses_stmt->fetchAll();
} catch (Exception $e) {
    // View doesn't exist yet - worker status system not set up
    $worker_statuses = [];
}

$page_title = "Activity Norms - " . $activity['activity_name'];
require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-calculator"></i> Activity Norms</h1>
            <p class="text-muted">
                <strong><?php echo htmlspecialchars($activity['activity_code']); ?></strong> - 
                <?php echo htmlspecialchars($activity['activity_name']); ?>
                <span class="badge bg-info"><?php echo htmlspecialchars($activity['group_name']); ?></span>
            </p>
        </div>
        <div class="col-auto">
            <a href="activities.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Activities
            </a>
        </div>
    </div>
</div>

<!-- Norm Entry Form -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <i class="bi bi-plus-circle"></i> <?php echo $edit_norm ? 'Edit' : 'Add'; ?> Activity Norm
    </div>
    <div class="card-body">
        <form method="POST" action="activity_norms.php?activity_id=<?php echo $activity_id; ?>">
            <input type="hidden" name="action" value="<?php echo $edit_norm ? 'update_norm' : 'add_norm'; ?>">
            <?php if ($edit_norm): ?>
                <input type="hidden" name="norm_id" value="<?php echo $edit_norm['id']; ?>">
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Norm Name <span class="text-danger">*</span></label>
                    <input type="text" name="norm_name" class="form-control" 
                           value="<?php echo $edit_norm ? htmlspecialchars($edit_norm['norm_name']) : ''; ?>" 
                           placeholder="e.g., Standard Norm - Flat Terrain" required>
                </div>
                
                <div class="col-md-2 mb-3">
                    <label class="form-label">Man-Days/Unit <span class="text-danger">*</span></label>
                    <input type="number" name="man_days_per_unit" class="form-control" step="0.0001" min="0"
                           value="<?php echo $edit_norm ? $edit_norm['man_days_per_unit'] : ''; ?>" 
                           placeholder="0.125" required>
                    <small class="text-muted">e.g., 0.125 = 8 units/day</small>
                </div>
                
                <div class="col-md-2 mb-3">
                    <label class="form-label">Unit <span class="text-danger">*</span></label>
                    <input type="text" name="unit_of_measure" class="form-control" 
                           value="<?php echo $edit_norm ? htmlspecialchars($edit_norm['unit_of_measure']) : ''; ?>" 
                           placeholder="ton, hectare, km" required list="unit-list">
                    <datalist id="unit-list">
                        <option value="ton">
                        <option value="hectare">
                        <option value="km">
                        <option value="palm">
                        <option value="hours">
                    </datalist>
                </div>
                
                <div class="col-md-2 mb-3">
                    <label class="form-label">Productivity Factor</label>
                    <input type="number" name="productivity_factor" class="form-control" step="0.01" min="0"
                           value="<?php echo $edit_norm ? $edit_norm['productivity_factor'] : '1.00'; ?>" 
                           placeholder="1.00">
                    <small class="text-muted">1.00 = standard</small>
                </div>
                
                <div class="col-md-2 mb-3">
                    <label class="form-label">Terrain Type</label>
                    <select name="terrain_type" class="form-select">
                        <option value="flat" <?php echo ($edit_norm && $edit_norm['terrain_type'] == 'flat') ? 'selected' : ''; ?>>Flat</option>
                        <option value="sloping" <?php echo ($edit_norm && $edit_norm['terrain_type'] == 'sloping') ? 'selected' : ''; ?>>Sloping</option>
                        <option value="steep" <?php echo ($edit_norm && $edit_norm['terrain_type'] == 'steep') ? 'selected' : ''; ?>>Steep</option>
                        <option value="mixed" <?php echo ($edit_norm && $edit_norm['terrain_type'] == 'mixed') ? 'selected' : ''; ?>>Mixed</option>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-2 mb-3">
                    <label class="form-label">Palm Age Min (years)</label>
                    <input type="number" name="palm_age_min" class="form-control" min="0"
                           value="<?php echo $edit_norm ? $edit_norm['palm_age_min'] : '0'; ?>">
                </div>
                
                <div class="col-md-2 mb-3">
                    <label class="form-label">Palm Age Max (years)</label>
                    <input type="number" name="palm_age_max" class="form-control" min="0"
                           value="<?php echo $edit_norm ? $edit_norm['palm_age_max'] : '100'; ?>">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" 
                           value="<?php echo $edit_norm ? htmlspecialchars($edit_norm['notes']) : ''; ?>" 
                           placeholder="Additional information">
                </div>
                
                <div class="col-md-2 mb-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="form-check">
                        <input type="checkbox" name="is_default" class="form-check-input" id="is_default"
                               <?php echo ($edit_norm && $edit_norm['is_default']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_default">Default Norm</label>
                    </div>
                    <?php if ($edit_norm): ?>
                        <div class="form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="is_active"
                                   <?php echo $edit_norm['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> <?php echo $edit_norm ? 'Update' : 'Save'; ?> Norm
            </button>
            <?php if ($edit_norm): ?>
                <a href="activity_norms.php?activity_id=<?php echo $activity_id; ?>" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Norms List -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Activity Norms (<?php echo count($norms); ?> norms)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Norm Name</th>
                        <th class="text-end">Man-Days/Unit</th>
                        <th class="text-end">Units/Man-Day</th>
                        <th>Unit</th>
                        <th>Terrain</th>
                        <th>Palm Age</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($norms)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">No norms defined yet</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($norms as $norm): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($norm['norm_name']); ?></strong>
                                    <?php if ($norm['is_default']): ?>
                                        <span class="badge bg-success">Default</span>
                                    <?php endif; ?>
                                    <?php if ($norm['notes']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($norm['notes']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo format_number($norm['man_days_per_unit'], 4); ?></td>
                                <td class="text-end">
                                    <strong><?php echo format_number(1 / $norm['man_days_per_unit'], 2); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($norm['unit_of_measure']); ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo ucfirst($norm['terrain_type']); ?></span>
                                </td>
                                <td><?php echo $norm['palm_age_min']; ?>-<?php echo $norm['palm_age_max']; ?> yrs</td>
                                <td>
                                    <span class="badge bg-<?php echo $norm['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $norm['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="activity_norms.php?activity_id=<?php echo $activity_id; ?>&edit=<?php echo $norm['id']; ?>" 
                                       class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this norm?');">
                                        <input type="hidden" name="action" value="delete_norm">
                                        <input type="hidden" name="norm_id" value="<?php echo $norm['id']; ?>">
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

<!-- Cost Calculation Examples -->
<?php if (!empty($norms) && !empty($worker_statuses)): ?>
<div class="card">
    <div class="card-header bg-info text-white">
        <i class="bi bi-calculator-fill"></i> Cost Calculation Examples
    </div>
    <div class="card-body">
        <p class="text-muted">Example calculations for 10 units using different worker statuses:</p>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Norm</th>
                        <th>Worker Status</th>
                        <th class="text-end">Daily Wage</th>
                        <th class="text-end">Man-Days Needed</th>
                        <th class="text-end">Total Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($norms as $norm): ?>
                        <?php if ($norm['is_active']): ?>
                            <?php foreach ($worker_statuses as $status): ?>
                                <?php 
                                $quantity = 10;
                                $man_days = $quantity * $norm['man_days_per_unit'];
                                $total_cost = $man_days * $status['daily_wage'];
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($norm['norm_name']); ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo $status['status_code']; ?></span>
                                        <?php echo htmlspecialchars($status['status_name']); ?>
                                    </td>
                                    <td class="text-end">Rp <?php echo format_number($status['daily_wage'], 0); ?></td>
                                    <td class="text-end"><?php echo format_number($man_days, 2); ?></td>
                                    <td class="text-end"><strong>Rp <?php echo format_number($total_cost, 0); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
