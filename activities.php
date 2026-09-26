<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'add_group') {
        try {
            $stmt = $db->prepare("
                INSERT INTO activity_groups (group_code, group_name, description, icon, color, display_order, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([
                post('group_code'),
                post('group_name'),
                post('description'),
                post('icon') ?: 'bi-clipboard-check',
                post('color') ?: 'primary',
                post('display_order') ?: 0
            ]);
            
            set_message('Activity group added successfully!', 'success');
            redirect('activities.php');
        } catch (Exception $e) {
            set_message('Error adding activity group: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'update_group') {
        try {
            $stmt = $db->prepare("
                UPDATE activity_groups SET
                    group_code = ?, group_name = ?, description = ?, icon = ?, color = ?, 
                    display_order = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                post('group_code'),
                post('group_name'),
                post('description'),
                post('icon'),
                post('color'),
                post('display_order'),
                post('is_active') ? 1 : 0,
                post('group_id')
            ]);
            
            set_message('Activity group updated successfully!', 'success');
            redirect('activities.php');
        } catch (Exception $e) {
            set_message('Error updating activity group: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'add_activity') {
        try {
            $stmt = $db->prepare("
                INSERT INTO activities (
                    activity_group_id, activity_code, activity_name, description,
                    unit_of_measure, standard_rate, calculation_method, estimated_duration,
                    requires_equipment, requires_materials, display_order, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([
                post('activity_group_id'),
                post('activity_code'),
                post('activity_name'),
                post('description'),
                post('unit_of_measure') ?: 'hours',
                post('standard_rate') ?: 0,
                post('calculation_method') ?: 'direct_rate',
                post('estimated_duration') ?: 0,
                post('requires_equipment') ? 1 : 0,
                post('requires_materials') ? 1 : 0,
                post('display_order') ?: 0
            ]);
            
            set_message('Activity added successfully!', 'success');
            redirect('activities.php');
        } catch (Exception $e) {
            set_message('Error adding activity: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'update_activity') {
        try {
            $stmt = $db->prepare("
                UPDATE activities SET
                    activity_group_id = ?, activity_code = ?, activity_name = ?, description = ?,
                    unit_of_measure = ?, standard_rate = ?, calculation_method = ?, estimated_duration = ?,
                    requires_equipment = ?, requires_materials = ?, is_active = ?,
                    display_order = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                post('activity_group_id'),
                post('activity_code'),
                post('activity_name'),
                post('description'),
                post('unit_of_measure'),
                post('standard_rate'),
                post('calculation_method'),
                post('estimated_duration'),
                post('requires_equipment') ? 1 : 0,
                post('requires_materials') ? 1 : 0,
                post('is_active') ? 1 : 0,
                post('display_order'),
                post('activity_id')
            ]);
            
            set_message('Activity updated successfully!', 'success');
            redirect('activities.php');
        } catch (Exception $e) {
            set_message('Error updating activity: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'delete_activity') {
        try {
            $stmt = $db->prepare("DELETE FROM activities WHERE id = ?");
            $stmt->execute([post('activity_id')]);
            
            set_message('Activity deleted successfully!', 'success');
            redirect('activities.php');
        } catch (Exception $e) {
            set_message('Error deleting activity: ' . $e->getMessage(), 'danger');
        }
    }
}

// Get edit records
$edit_group = null;
$edit_activity = null;
if (isset($_GET['edit_group'])) {
    $stmt = $db->prepare("SELECT * FROM activity_groups WHERE id = ?");
    $stmt->execute([$_GET['edit_group']]);
    $edit_group = $stmt->fetch();
}
if (isset($_GET['edit_activity'])) {
    $stmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
    $stmt->execute([$_GET['edit_activity']]);
    $edit_activity = $stmt->fetch();
}

// Fetch activity groups
$groups_stmt = $db->query("SELECT * FROM activity_groups ORDER BY display_order, group_name");
$activity_groups = $groups_stmt->fetchAll();

// Fetch activities with group info
$group_filter = get('group_id', '');
$activities_sql = "
    SELECT
        a.*,
        a.is_active as activity_active,
        ag.id as group_id,
        ag.group_code,
        ag.group_name,
        ag.description as group_description,
        ag.icon as group_icon,
        ag.color as group_color,
        ag.is_active as group_active,
        ag.display_order as group_display_order
    FROM activities a
    INNER JOIN activity_groups ag ON a.activity_group_id = ag.id
    WHERE 1=1
";
$params = [];

if ($group_filter) {
    $activities_sql .= " AND ag.id = ?";
    $params[] = $group_filter;
}

$activities_sql .= " ORDER BY ag.display_order, a.display_order, a.activity_name";
$stmt = $db->prepare($activities_sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

// Get statistics
$stats_stmt = $db->query("
    SELECT 
        COUNT(DISTINCT ag.id) as total_groups,
        COUNT(a.id) as total_activities,
        SUM(CASE WHEN a.is_active = TRUE THEN 1 ELSE 0 END) as active_activities
    FROM activity_groups ag
    LEFT JOIN activities a ON ag.id = a.activity_group_id
");
$stats = $stats_stmt->fetch();

$page_title = "Activities Management";
require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-clipboard-check"></i> Activities Management</h1>
            <p class="text-muted">Manage activity groups and field activities</p>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card stat-card border-primary">
            <div class="card-body text-center">
                <h3 class="text-primary"><?php echo number_format($stats['total_groups']); ?></h3>
                <p class="mb-0">Activity Groups</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card border-success">
            <div class="card-body text-center">
                <h3 class="text-success"><?php echo number_format($stats['total_activities']); ?></h3>
                <p class="mb-0">Total Activities</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card border-info">
            <div class="card-body text-center">
                <h3 class="text-info"><?php echo number_format($stats['active_activities']); ?></h3>
                <p class="mb-0">Active Activities</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Activity Groups Section -->
    <div class="col-md-3">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-folder"></i> <?php echo $edit_group ? 'Edit' : 'Add'; ?> Activity Group
            </div>
            <div class="card-body">
                <form method="POST" action="activities.php">
                    <input type="hidden" name="action" value="<?php echo $edit_group ? 'update_group' : 'add_group'; ?>">
                    <?php if ($edit_group): ?>
                        <input type="hidden" name="group_id" value="<?php echo $edit_group['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Group Code <span class="text-danger">*</span></label>
                        <input type="text" name="group_code" class="form-control" 
                               value="<?php echo $edit_group ? htmlspecialchars($edit_group['group_code']) : ''; ?>" 
                               placeholder="e.g., HARVESTING" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Group Name <span class="text-danger">*</span></label>
                        <input type="text" name="group_name" class="form-control" 
                               value="<?php echo $edit_group ? htmlspecialchars($edit_group['group_name']) : ''; ?>" 
                               placeholder="e.g., Harvesting" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?php echo $edit_group ? htmlspecialchars($edit_group['description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Icon</label>
                            <input type="text" name="icon" class="form-control" 
                                   value="<?php echo $edit_group ? htmlspecialchars($edit_group['icon']) : 'bi-clipboard-check'; ?>" 
                                   placeholder="bi-clipboard-check">
                            <small class="text-muted">Bootstrap icon class</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Color</label>
                            <select name="color" class="form-select">
                                <option value="primary" <?php echo ($edit_group && $edit_group['color'] == 'primary') ? 'selected' : ''; ?>>Primary</option>
                                <option value="success" <?php echo ($edit_group && $edit_group['color'] == 'success') ? 'selected' : ''; ?>>Success</option>
                                <option value="info" <?php echo ($edit_group && $edit_group['color'] == 'info') ? 'selected' : ''; ?>>Info</option>
                                <option value="warning" <?php echo ($edit_group && $edit_group['color'] == 'warning') ? 'selected' : ''; ?>>Warning</option>
                                <option value="danger" <?php echo ($edit_group && $edit_group['color'] == 'danger') ? 'selected' : ''; ?>>Danger</option>
                                <option value="secondary" <?php echo ($edit_group && $edit_group['color'] == 'secondary') ? 'selected' : ''; ?>>Secondary</option>
                                <option value="dark" <?php echo ($edit_group && $edit_group['color'] == 'dark') ? 'selected' : ''; ?>>Dark</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" class="form-control" 
                               value="<?php echo $edit_group ? $edit_group['display_order'] : '0'; ?>">
                    </div>
                    
                    <?php if ($edit_group): ?>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="group_active"
                                   <?php echo $edit_group['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="group_active">Active</label>
                        </div>
                    <?php endif; ?>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-save"></i> <?php echo $edit_group ? 'Update' : 'Save'; ?> Group
                    </button>
                    <?php if ($edit_group): ?>
                        <a href="activities.php" class="btn btn-secondary w-100 mt-2">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- Activity Groups List -->
        <div class="card">
            <div class="card-header">
                <i class="bi bi-list"></i> Activity Groups
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($activity_groups as $group): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <i class="<?php echo $group['icon']; ?> text-<?php echo $group['color']; ?>"></i>
                            <strong><?php echo htmlspecialchars($group['group_name']); ?></strong>
                            <br>
                            <small class="text-muted"><?php echo htmlspecialchars($group['group_code']); ?></small>
                        </div>
                        <div>
                            <a href="activities.php?edit_group=<?php echo $group['id']; ?>" 
                               class="btn btn-sm btn-warning">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Activities Section -->
    <div class="col-md-9">
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <i class="bi bi-plus-circle"></i> <?php echo $edit_activity ? 'Edit' : 'Add'; ?> Activity
            </div>
            <div class="card-body">
                <form method="POST" action="activities.php">
                    <input type="hidden" name="action" value="<?php echo $edit_activity ? 'update_activity' : 'add_activity'; ?>">
                    <?php if ($edit_activity): ?>
                        <input type="hidden" name="activity_id" value="<?php echo $edit_activity['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Activity Group <span class="text-danger">*</span></label>
                            <select name="activity_group_id" class="form-select" required>
                                <option value="">Select Group</option>
                                <?php foreach ($activity_groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>"
                                            <?php echo ($edit_activity && $edit_activity['activity_group_id'] == $group['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($group['group_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Activity Code <span class="text-danger">*</span></label>
                            <input type="text" name="activity_code" class="form-control"
                                   value="<?php echo $edit_activity ? htmlspecialchars($edit_activity['activity_code']) : ''; ?>"
                                   placeholder="e.g., HARV-001" required>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Activity Name <span class="text-danger">*</span></label>
                            <input type="text" name="activity_name" class="form-control"
                                   value="<?php echo $edit_activity ? htmlspecialchars($edit_activity['activity_name']) : ''; ?>"
                                   placeholder="e.g., FFB Harvesting" required>
                        </div>
                        
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Calculation <span class="text-danger">*</span></label>
                            <select name="calculation_method" class="form-select" required>
                                <option value="direct_rate" <?php echo ($edit_activity && $edit_activity['calculation_method'] == 'direct_rate') ? 'selected' : ''; ?>>Direct Rate</option>
                                <option value="norm_based" <?php echo ($edit_activity && $edit_activity['calculation_method'] == 'norm_based') ? 'selected' : ''; ?>>Norm Based</option>
                            </select>
                            <small class="text-muted">Cost method</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?php echo $edit_activity ? htmlspecialchars($edit_activity['description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Unit of Measure</label>
                            <input type="text" name="unit_of_measure" class="form-control" 
                                   value="<?php echo $edit_activity ? htmlspecialchars($edit_activity['unit_of_measure']) : 'hours'; ?>" 
                                   placeholder="hours">
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Standard Rate (Rp)</label>
                            <input type="number" name="standard_rate" class="form-control" step="0.01"
                                   value="<?php echo $edit_activity ? $edit_activity['standard_rate'] : '0'; ?>">
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Est. Duration (hrs)</label>
                            <input type="number" name="estimated_duration" class="form-control" step="0.01"
                                   value="<?php echo $edit_activity ? $edit_activity['estimated_duration'] : '0'; ?>">
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Display Order</label>
                            <input type="number" name="display_order" class="form-control" 
                                   value="<?php echo $edit_activity ? $edit_activity['display_order'] : '0'; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3 form-check">
                            <input type="checkbox" name="requires_equipment" class="form-check-input" id="req_equipment"
                                   <?php echo ($edit_activity && $edit_activity['requires_equipment']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="req_equipment">Requires Equipment</label>
                        </div>
                        
                        <div class="col-md-4 mb-3 form-check">
                            <input type="checkbox" name="requires_materials" class="form-check-input" id="req_materials"
                                   <?php echo ($edit_activity && $edit_activity['requires_materials']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="req_materials">Requires Materials</label>
                        </div>
                        
                        <?php if ($edit_activity): ?>
                            <div class="col-md-4 mb-3 form-check">
                                <input type="checkbox" name="is_active" class="form-check-input" id="activity_active"
                                       <?php echo $edit_activity['is_active'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="activity_active">Active</label>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save"></i> <?php echo $edit_activity ? 'Update' : 'Save'; ?> Activity
                    </button>
                    <?php if ($edit_activity): ?>
                        <a href="activities.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- Filter -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-10">
                        <label class="form-label">Filter by Group</label>
                        <select name="group_id" class="form-select">
                            <option value="">All Groups</option>
                            <?php foreach ($activity_groups as $group): ?>
                                <option value="<?php echo $group['id']; ?>"
                                        <?php echo $group_filter == $group['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($group['group_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Activities List -->
        <div class="card">
            <div class="card-header">
                <i class="bi bi-list-ul"></i> Activities List (<?php echo count($activities); ?> activities)
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th style="width: 10%;">Code</th>
                                <th style="width: 30%;">Activity Name</th>
                                <th style="width: 10%;">Group</th>
                                <th style="width: 5%;">Unit</th>
                                <th class="text-end" style="width: 8%;">Rate</th>
                                <th class="text-end" style="width: 7%;">Duration</th>
                                <th style="width: 6%;">Status</th>
                                <th class="text-center" style="width: 4%;">GL Map</th>
                                <th class="text-center" style="width: 4%;">Norms</th>
                                <th class="text-center" style="width: 4%;">Edit</th>
                                <th class="text-center" style="width: 4%;">Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activities)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">No activities found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($activities as $activity): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($activity['activity_code']); ?></code></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($activity['activity_name']); ?></strong>
                                            <?php if (isset($activity['calculation_method']) && $activity['calculation_method'] == 'norm_based'): ?>
                                                <span class="badge bg-info">Norm Based</span>
                                            <?php endif; ?>
                                            <?php if ($activity['requires_equipment']): ?>
                                                <i class="bi bi-tools text-warning" title="Requires Equipment"></i>
                                            <?php endif; ?>
                                            <?php if ($activity['requires_materials']): ?>
                                                <i class="bi bi-box text-info" title="Requires Materials"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $activity['group_color']; ?>">
                                                <?php echo htmlspecialchars($activity['group_name']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($activity['unit_of_measure']); ?></td>
                                        <td class="text-end">Rp <?php echo format_number($activity['standard_rate'], 0); ?></td>
                                        <td class="text-end"><?php echo format_number($activity['estimated_duration'], 1); ?> hrs</td>
                                        <td>
                                            <span class="badge bg-<?php echo $activity['activity_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $activity['activity_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="activity_gl_mapping.php?activity_id=<?php echo $activity['id']; ?>"
                                               class="btn btn-sm btn-primary" title="GL Account Mapping">
                                                <i class="bi bi-link-45deg"></i>
                                            </a>
                                        </td>
                                        <td class="text-center">
                                            <?php if (isset($activity['calculation_method']) && $activity['calculation_method'] == 'norm_based'): ?>
                                                <a href="activity_norms.php?activity_id=<?php echo $activity['id']; ?>"
                                                   class="btn btn-sm btn-info" title="Manage Norms">
                                                    <i class="bi bi-calculator"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="activities.php?edit_activity=<?php echo $activity['id']; ?>"
                                               class="btn btn-sm btn-warning" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this activity?');">
                                                <input type="hidden" name="action" value="delete_activity">
                                                <input type="hidden" name="activity_id" value="<?php echo $activity['id']; ?>">
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
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
