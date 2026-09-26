<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'create_assignment') {
        try {
            $db->beginTransaction();
            
            // Generate assignment code
            $code_stmt = $db->query("SELECT 'ASG-' || TO_CHAR(CURRENT_DATE, 'YYYYMMDD') || '-' || LPAD(FLOOR(RANDOM() * 10000)::TEXT, 4, '0') as code");
            $assignment_code = $code_stmt->fetch()['code'];
            
            // Insert assignment
            $stmt = $db->prepare("
                INSERT INTO worker_assignments (
                    assignment_code, assignment_date, business_unit_id, division_id, block_id,
                    activity_id, description, target_quantity, target_unit,
                    estimated_hours, priority, status, supervisor_id, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            
            $stmt->execute([
                $assignment_code,
                post('assignment_date'),
                post('business_unit_id') ?: null,
                post('division_id') ?: null,
                post('block_id') ?: null,
                post('activity_id'),
                post('description'),
                post('target_quantity') ?: null,
                post('target_unit'),
                post('estimated_hours') ?: null,
                post('priority') ?: 'normal',
                'planned',
                post('supervisor_id') ?: null,
                post('notes'),
                1
            ]);
            
            $assignment_id = $stmt->fetch()['id'];
            
            // Assign workers
            $worker_ids = post('worker_ids', []);
            if (!empty($worker_ids)) {
                $worker_stmt = $db->prepare("
                    INSERT INTO worker_assignment_details (assignment_id, employee_id, status)
                    VALUES (?, ?, 'assigned')
                ");
                
                foreach ($worker_ids as $worker_id) {
                    $worker_stmt->execute([$assignment_id, $worker_id]);
                }
            }
            
            $db->commit();
            set_message("Assignment $assignment_code created successfully!", 'success');
            redirect('worker_assignments.php');
        } catch (Exception $e) {
            $db->rollBack();
            set_message('Error creating assignment: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'delete_assignment') {
        try {
            $stmt = $db->prepare("DELETE FROM worker_assignments WHERE id = ?");
            $stmt->execute([post('assignment_id')]);
            
            set_message('Assignment deleted successfully!', 'success');
            redirect('worker_assignments.php');
        } catch (Exception $e) {
            set_message('Error deleting assignment: ' . $e->getMessage(), 'danger');
        }
    }
}

// Filters
$date_filter = get('date', '');
$status_filter = get('status', '');

// Fetch assignments
$assignments_sql = "
    SELECT
        wa.*,
        a.activity_name,
        a.activity_code,
        COUNT(DISTINCT wad.employee_id) as worker_count,
        AVG(wad.completion_percentage) as avg_completion
    FROM worker_assignments wa
    INNER JOIN activities a ON wa.activity_id = a.id
    LEFT JOIN worker_assignment_details wad ON wa.id = wad.assignment_id
    WHERE 1=1
";
$params = [];

if ($date_filter) {
    $assignments_sql .= " AND wa.assignment_date = ?";
    $params[] = $date_filter;
}

if ($status_filter) {
    $assignments_sql .= " AND wa.status = ?";
    $params[] = $status_filter;
}

$assignments_sql .= " GROUP BY wa.id, a.activity_name, a.activity_code
                      ORDER BY wa.assignment_date DESC, wa.priority DESC";

$stmt = $db->prepare($assignments_sql);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Fetch activities for dropdown
$activities_stmt = $db->query("
    SELECT id, activity_code, activity_name
    FROM activities
    WHERE is_active = TRUE
    ORDER BY activity_name
");
$activities = $activities_stmt->fetchAll();

// Fetch employees for dropdown
$employees_stmt = $db->query("
    SELECT e.id, ex.employee_code, e.name
    FROM hr_employee e
    LEFT JOIN hr_employee_extend ex ON e.id = ex.hr_employee_id
    WHERE e.active::integer = 1
    ORDER BY e.name
");
$employees = $employees_stmt->fetchAll();

// Get statistics
$stats_stmt = $db->query("
    SELECT
        COUNT(*) as total_assignments,
        SUM(CASE WHEN status = 'planned' THEN 1 ELSE 0 END) as planned_count,
        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_count,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
    FROM worker_assignments
    WHERE assignment_date >= CURRENT_DATE - INTERVAL '30 days'
");
$stats = $stats_stmt->fetch();

$page_title = "Worker Assignments";
require_once 'includes/header.php';
?>

<style>
    .stat-card {
        border-left: 4px solid #006359;
    }
    
    .stat-card h3 {
        color: #006359;
    }
    
    .priority-urgent { border-left: 3px solid #dc3545; }
    .priority-high { border-left: 3px solid #ffc107; }
    .priority-normal { border-left: 3px solid #0dcaf0; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-clipboard-check"></i> Worker Assignments</h1>
            <p class="text-muted">Manage and track worker task assignments</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAssignmentModal">
                <i class="bi bi-plus-circle"></i> Create Assignment
            </button>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h3><?php echo number_format($stats['total_assignments']); ?></h3>
                <p class="mb-0">Total</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card border-secondary">
            <div class="card-body text-center">
                <h3 class="text-secondary"><?php echo number_format($stats['planned_count']); ?></h3>
                <p class="mb-0">Planned</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-warning">
            <div class="card-body text-center">
                <h3 class="text-warning"><?php echo number_format($stats['assigned_count']); ?></h3>
                <p class="mb-0">Assigned</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card border-primary">
            <div class="card-body text-center">
                <h3 class="text-primary"><?php echo number_format($stats['in_progress_count']); ?></h3>
                <p class="mb-0">In Progress</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-success">
            <div class="card-body text-center">
                <h3 class="text-success"><?php echo number_format($stats['completed_count']); ?></h3>
                <p class="mb-0">Completed</p>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Date</label>
                <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($date_filter); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="planned" <?php echo $status_filter === 'planned' ? 'selected' : ''; ?>>Planned</option>
                    <option value="assigned" <?php echo $status_filter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                    <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <div>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="worker_assignments.php" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Assignments List -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Assignment List</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Date</th>
                        <th>Activity</th>
                        <th>Target</th>
                        <th>Workers</th>
                        <th>Progress</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments)): ?>
                        <tr>
                            <td colspan="9" class="text-center">No assignments found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($assignments as $assign): ?>
                            <tr class="priority-<?php echo $assign['priority']; ?>">
                                <td><code><?php echo htmlspecialchars($assign['assignment_code']); ?></code></td>
                                <td><?php echo date('d M Y', strtotime($assign['assignment_date'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($assign['activity_name']); ?></strong></td>
                                <td>
                                    <?php if ($assign['target_quantity']): ?>
                                        <?php echo number_format($assign['target_quantity'], 2); ?> <?php echo htmlspecialchars($assign['target_unit']); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-secondary"><?php echo $assign['worker_count']; ?> workers</span></td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-<?php echo $assign['avg_completion'] >= 75 ? 'success' : 'info'; ?>" 
                                             style="width: <?php echo $assign['avg_completion']; ?>%">
                                            <?php echo number_format($assign['avg_completion'], 0); ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $assign['priority'] === 'urgent' ? 'danger' : 
                                            ($assign['priority'] === 'high' ? 'warning' : 'info'); 
                                    ?>">
                                        <?php echo ucfirst($assign['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $assign['status'] === 'completed' ? 'success' : 
                                            ($assign['status'] === 'in_progress' ? 'primary' : 'secondary'); 
                                    ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $assign['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Delete this assignment?');">
                                        <input type="hidden" name="action" value="delete_assignment">
                                        <input type="hidden" name="assignment_id" value="<?php echo $assign['id']; ?>">
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

<!-- Create Assignment Modal -->
<div class="modal fade" id="createAssignmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_assignment">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Assignment Date <span class="text-danger">*</span></label>
                            <input type="date" name="assignment_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="low">Low</option>
                                <option value="normal" selected>Normal</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Activity <span class="text-danger">*</span></label>
                        <select name="activity_id" class="form-select" required>
                            <option value="">Select Activity</option>
                            <?php foreach ($activities as $act): ?>
                                <option value="<?php echo $act['id']; ?>">
                                    <?php echo htmlspecialchars($act['activity_code'] . ' - ' . $act['activity_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Target Quantity</label>
                            <input type="number" name="target_quantity" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Unit</label>
                            <input type="text" name="target_unit" class="form-control" placeholder="e.g., kg, hours">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Estimated Hours</label>
                            <input type="number" name="estimated_hours" class="form-control" step="0.5" min="0">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Assign Workers</label>
                        <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" id="select_all_workers">
                                <label class="form-check-label fw-bold" for="select_all_workers">Select All</label>
                            </div>
                            <hr>
                            <?php foreach ($employees as $emp): ?>
                                <div class="form-check">
                                    <input type="checkbox" name="worker_ids[]" value="<?php echo $emp['id']; ?>" 
                                           class="form-check-input worker-checkbox">
                                    <label class="form-check-label">
                                        <?php echo htmlspecialchars($emp['employee_code'] . ' - ' . $emp['name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Select all workers checkbox
document.getElementById('select_all_workers')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.worker-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
});
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
