<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Activity Norms Management";
require_once 'includes/header.php';

// Get filters
$activity_filter = get('activity_id', '');
$terrain_filter = get('terrain_type', '');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = post('action');
    
    if ($post_action === 'create_norm') {
        try {
            $stmt = $db->prepare("
                INSERT INTO activity_norms (
                    activity_id, norm_name, terrain_type, palm_age_min, palm_age_max,
                    man_days_per_unit, daily_wage, effective_date, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('activity_id'),
                post('norm_name'),
                post('terrain_type'),
                post('palm_age_min'),
                post('palm_age_max'),
                post('man_days_per_unit'),
                post('daily_wage'),
                post('effective_date'),
                post('notes')
            ]);
            
            $success_message = "Activity norm created successfully!";
            
        } catch (PDOException $e) {
            $error_message = "Error creating norm: " . $e->getMessage();
        }
    } elseif ($post_action === 'update_norm') {
        try {
            $stmt = $db->prepare("
                UPDATE activity_norms
                SET norm_name = ?,
                    terrain_type = ?,
                    palm_age_min = ?,
                    palm_age_max = ?,
                    man_days_per_unit = ?,
                    daily_wage = ?,
                    effective_date = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                post('norm_name'),
                post('terrain_type'),
                post('palm_age_min'),
                post('palm_age_max'),
                post('man_days_per_unit'),
                post('daily_wage'),
                post('effective_date'),
                post('notes'),
                post('norm_id')
            ]);
            
            $success_message = "Activity norm updated successfully!";
            
        } catch (PDOException $e) {
            $error_message = "Error updating norm: " . $e->getMessage();
        }
    } elseif ($post_action === 'delete_norm') {
        try {
            $stmt = $db->prepare("DELETE FROM activity_norms WHERE id = ?");
            $stmt->execute([post('norm_id')]);
            $success_message = "Activity norm deleted successfully!";
        } catch (PDOException $e) {
            $error_message = "Error deleting norm: " . $e->getMessage();
        }
    }
}

// Fetch activities
$activities_stmt = $db->query("
    SELECT a.id, a.activity_code, a.activity_name, ag.group_name
    FROM activities a
    INNER JOIN activity_groups ag ON a.activity_group_id = ag.id
    WHERE a.calculation_method = 'norm_based'
    ORDER BY ag.display_order, a.display_order
");
$activities = $activities_stmt->fetchAll();

// Fetch activity norms
$sql = "
    SELECT 
        an.*,
        a.activity_code,
        a.activity_name,
        ag.group_name as activity_group
    FROM activity_norms an
    INNER JOIN activities a ON an.activity_id = a.id
    INNER JOIN activity_groups ag ON a.activity_group_id = ag.id
    WHERE 1=1
";

$params = [];

if ($activity_filter) {
    $sql .= " AND an.activity_id = ?";
    $params[] = $activity_filter;
}

if ($terrain_filter) {
    $sql .= " AND an.terrain_type = ?";
    $params[] = $terrain_filter;
}

$sql .= " ORDER BY ag.display_order, a.display_order, an.terrain_type, an.palm_age_min";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$norms = $stmt->fetchAll();
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-speedometer2"></i> Activity Norms Management</h2>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createNormModal">
                    <i class="bi bi-plus-circle"></i> Create Norm
                </button>
            </div>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i> <?= $success_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> <?= $error_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Info Alert -->
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i>
        <strong>About Activity Norms:</strong> Activity norms define productivity standards (man-days per hectare) 
        for different activities based on terrain type and palm age. These norms are used to automatically calculate 
        budget requirements when creating activity budget plans.
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Activity</label>
                    <select name="activity_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Activities</option>
                        <?php 
                        $current_group = '';
                        foreach ($activities as $activity): 
                            if ($current_group != $activity['group_name']) {
                                if ($current_group != '') echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($activity['group_name']) . '">';
                                $current_group = $activity['group_name'];
                            }
                        ?>
                        <option value="<?= $activity['id'] ?>" <?= $activity['id'] == $activity_filter ? 'selected' : '' ?>>
                            <?= htmlspecialchars($activity['activity_name']) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if ($current_group != '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Terrain Type</label>
                    <select name="terrain_type" class="form-select" onchange="this.form.submit()">
                        <option value="">All Terrain</option>
                        <option value="flat" <?= $terrain_filter == 'flat' ? 'selected' : '' ?>>Flat</option>
                        <option value="hilly" <?= $terrain_filter == 'hilly' ? 'selected' : '' ?>>Hilly</option>
                        <option value="steep" <?= $terrain_filter == 'steep' ? 'selected' : '' ?>>Steep</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <a href="activity_norms_manage.php" class="btn btn-secondary w-100">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Norms Table -->
    <div class="card">
        <div class="card-header bg-light">
            <h5 class="mb-0">Activity Norms</h5>
        </div>
        <div class="card-body">
            <?php if (empty($norms)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No activity norms found. Create your first norm using the button above.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Activity</th>
                            <th>Norm Name</th>
                            <th>Terrain</th>
                            <th>Palm Age</th>
                            <th>Man-Days/Ha</th>
                            <th>Daily Wage</th>
                            <th>Cost/Ha</th>
                            <th>Effective Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($norms as $norm): ?>
                        <tr>
                            <td><?= $norm['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($norm['activity_name']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($norm['activity_group']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($norm['norm_name']) ?></td>
                            <td>
                                <span class="badge bg-<?= $norm['terrain_type'] == 'flat' ? 'success' : ($norm['terrain_type'] == 'hilly' ? 'warning' : 'danger') ?>">
                                    <?= ucfirst($norm['terrain_type']) ?>
                                </span>
                            </td>
                            <td><?= $norm['palm_age_min'] ?> - <?= $norm['palm_age_max'] ?> years</td>
                            <td><strong><?= number_format($norm['man_days_per_unit'], 2) ?></strong></td>
                            <td>Rp <?= number_format($norm['daily_wage'], 0, ',', '.') ?></td>
                            <td>
                                <strong>Rp <?= number_format($norm['man_days_per_unit'] * $norm['daily_wage'], 0, ',', '.') ?></strong>
                            </td>
                            <td><?= !empty($norm['effective_date']) ? date('d M Y', strtotime($norm['effective_date'])) : '-' ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" 
                                        onclick='editNorm(<?= json_encode($norm) ?>)' title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" 
                                        onclick="deleteNorm(<?= $norm['id'] ?>)" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create Norm Modal -->
<div class="modal fade" id="createNormModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_norm">
                <div class="modal-header">
                    <h5 class="modal-title">Create Activity Norm</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Activity *</label>
                            <select name="activity_id" class="form-select" required>
                                <option value="">Select Activity</option>
                                <?php 
                                $current_group = '';
                                foreach ($activities as $activity): 
                                    if ($current_group != $activity['group_name']) {
                                        if ($current_group != '') echo '</optgroup>';
                                        echo '<optgroup label="' . htmlspecialchars($activity['group_name']) . '">';
                                        $current_group = $activity['group_name'];
                                    }
                                ?>
                                <option value="<?= $activity['id'] ?>">
                                    <?= htmlspecialchars($activity['activity_name']) ?>
                                </option>
                                <?php endforeach; ?>
                                <?php if ($current_group != '') echo '</optgroup>'; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Norm Name *</label>
                            <input type="text" name="norm_name" class="form-control" required
                                   placeholder="e.g., Standard Flat Terrain">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Terrain Type *</label>
                            <select name="terrain_type" class="form-select" required>
                                <option value="flat">Flat</option>
                                <option value="hilly">Hilly</option>
                                <option value="steep">Steep</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Palm Age Min (years) *</label>
                            <input type="number" name="palm_age_min" class="form-control" 
                                   min="0" max="30" value="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Palm Age Max (years) *</label>
                            <input type="number" name="palm_age_max" class="form-control" 
                                   min="0" max="30" value="30" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Man-Days per Hectare *</label>
                            <input type="number" name="man_days_per_unit" class="form-control" 
                                   step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Daily Wage (Rp) *</label>
                            <input type="number" name="daily_wage" class="form-control" 
                                   step="1000" min="0" value="100000" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Effective Date *</label>
                            <input type="date" name="effective_date" class="form-control" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Norm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Norm Modal -->
<div class="modal fade" id="editNormModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_norm">
                <input type="hidden" name="norm_id" id="edit_norm_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Activity Norm</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Activity</label>
                            <input type="text" id="edit_activity_name" class="form-control" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Norm Name *</label>
                            <input type="text" name="norm_name" id="edit_norm_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Terrain Type *</label>
                            <select name="terrain_type" id="edit_terrain_type" class="form-select" required>
                                <option value="flat">Flat</option>
                                <option value="hilly">Hilly</option>
                                <option value="steep">Steep</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Palm Age Min (years) *</label>
                            <input type="number" name="palm_age_min" id="edit_palm_age_min" 
                                   class="form-control" min="0" max="30" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Palm Age Max (years) *</label>
                            <input type="number" name="palm_age_max" id="edit_palm_age_max" 
                                   class="form-control" min="0" max="30" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Man-Days per Hectare *</label>
                            <input type="number" name="man_days_per_unit" id="edit_man_days_per_unit" 
                                   class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Daily Wage (Rp) *</label>
                            <input type="number" name="daily_wage" id="edit_daily_wage" 
                                   class="form-control" step="1000" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Effective Date *</label>
                            <input type="date" name="effective_date" id="edit_effective_date" 
                                   class="form-control" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Norm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editNorm(norm) {
    document.getElementById('edit_norm_id').value = norm.id;
    document.getElementById('edit_activity_name').value = norm.activity_name;
    document.getElementById('edit_norm_name').value = norm.norm_name;
    document.getElementById('edit_terrain_type').value = norm.terrain_type;
    document.getElementById('edit_palm_age_min').value = norm.palm_age_min;
    document.getElementById('edit_palm_age_max').value = norm.palm_age_max;
    document.getElementById('edit_man_days_per_unit').value = norm.man_days_per_unit;
    document.getElementById('edit_daily_wage').value = norm.daily_wage;
    document.getElementById('edit_effective_date').value = norm.effective_date;
    document.getElementById('edit_notes').value = norm.notes || '';
    
    const modal = new bootstrap.Modal(document.getElementById('editNormModal'));
    modal.show();
}

function deleteNorm(normId) {
    if (confirm('Are you sure you want to delete this activity norm? This may affect existing budget plans.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_norm">
            <input type="hidden" name="norm_id" value="${normId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
