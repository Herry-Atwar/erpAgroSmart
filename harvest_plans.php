<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            // Auto-generate plan number
            $year = date('Y');
            $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(plan_number, 9) AS UNSIGNED)) as max_num 
                               FROM harvest_plans WHERE plan_number LIKE 'HP-$year-%'");
            $result = $stmt->fetch();
            $next_num = ($result['max_num'] ?? 0) + 1;
            $plan_number = sprintf('HP-%s-%04d', $year, $next_num);
            
            $stmt = $db->prepare("
                INSERT INTO harvest_plans
                (plan_number, block_id, plan_date, planned_start_date, planned_end_date,
                 estimated_quantity_kg, estimated_bunches, harvesting_round, harvesting_criteria,
                 assigned_team, supervisor, status, notes, created_at, updated_at)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->execute([
                $plan_number,
                post('block_id'),
                post('plan_date'),
                post('planned_start_date'),
                post('planned_end_date') ?: null,
                post('estimated_quantity_kg') ?: null,
                post('estimated_bunches') ?: null,
                post('harvesting_round') ?: 'Round 1',
                post('harvesting_criteria') ?: null,
                post('assigned_team') ?: null,
                post('supervisor') ?: null,
                post('status') ?: 'Planned',
                post('notes') ?: null
            ]);
            
            set_message('success', 'Harvest plan created successfully! Plan Number: ' . $plan_number);
            redirect('harvest_plans.php');
        } catch (PDOException $e) {
            set_message('error', 'Error creating harvest plan: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE harvest_plans
                SET block_id = ?, plan_date = ?, planned_start_date = ?, planned_end_date = ?,
                    estimated_quantity_kg = ?, estimated_bunches = ?, harvesting_round = ?,
                    harvesting_criteria = ?, assigned_team = ?, supervisor = ?, status = ?, notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                post('block_id'),
                post('plan_date'),
                post('planned_start_date'),
                post('planned_end_date') ?: null,
                post('estimated_quantity_kg') ?: null,
                post('estimated_bunches') ?: null,
                post('harvesting_round'),
                post('harvesting_criteria') ?: null,
                post('assigned_team') ?: null,
                post('supervisor') ?: null,
                post('status'),
                post('notes') ?: null,
                post('harvest_plan_id')
            ]);
            
            set_message('success', 'Harvest plan updated successfully!');
            redirect('harvest_plans.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating harvest plan: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM harvest_plans WHERE id = ?");
            $stmt->execute([post('harvest_plan_id')]);
            
            set_message('success', 'Harvest plan deleted successfully!');
            redirect('harvest_plans.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting harvest plan: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_record = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM harvest_plans WHERE harvest_plan_id = ?");
    $stmt->execute([get('id')]);
    $edit_record = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Harvest Planning";
require_once 'includes/header.php';

// Fetch blocks for dropdown (only TM blocks)
$blocks_stmt = $db->query("
    SELECT b.block_id, b.block_code, b.block_name, b.total_plants, b.area,
           py.year, d.division_name, bu.unit_name, c.company_name
    FROM blocks b
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE b.status = 'TM'
    ORDER BY c.company_name, bu.unit_name, d.division_name, b.block_name
");
$blocks = $blocks_stmt->fetchAll();

// Fetch harvest plans with filters
$search = get('search', '');
$status_filter = get('status', '');
$round_filter = get('round', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');

$sql = "SELECT hp.*,
        hp.harvest_plan_id,
        b.block_code, b.block_name, b.total_plants, b.area,
        py.year as planting_year,
        d.division_name,
        bu.unit_name as estate_name,
        c.company_name
        FROM harvest_plans hp
        INNER JOIN blocks b ON hp.block_id = b.block_id
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (hp.plan_number LIKE ? OR b.block_name LIKE ? OR hp.assigned_team LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $sql .= " AND hp.status = ?";
    $params[] = $status_filter;
}
if ($round_filter) {
    $sql .= " AND hp.harvesting_round = ?";
    $params[] = $round_filter;
}
if ($date_from) {
    $sql .= " AND hp.planned_start_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND hp.planned_start_date <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY hp.planned_start_date DESC, hp.harvest_plan_id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$plans = $stmt->fetchAll();

// Calculate summary statistics
$total_plans = count($plans);
$total_estimated_qty = array_sum(array_column($plans, 'estimated_quantity_kg'));
$total_estimated_bunches = array_sum(array_column($plans, 'estimated_bunches'));
$planned_count = count(array_filter($plans, function($p) { return $p['status'] == 'Planned'; }));
$in_progress_count = count(array_filter($plans, function($p) { return $p['status'] == 'In Progress'; }));
$completed_count = count(array_filter($plans, function($p) { return $p['status'] == 'Completed'; }));

// Harvesting rounds and statuses
$harvesting_rounds = ['Round 1', 'Round 2', 'Round 3', 'Round 4'];
$statuses = ['Planned', 'In Progress', 'Completed', 'Cancelled'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-calendar-check"></i> Harvest Planning</h1>
            <p class="text-muted">Plan and schedule harvesting activities</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Create Harvest Plan
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_plans; ?></h3>
                <p><i class="bi bi-list-check"></i> Total Plans</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_estimated_qty, 0); ?> Kg</h3>
                <p><i class="bi bi-box-seam"></i> Estimated Quantity</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_estimated_bunches, 0); ?></h3>
                <p><i class="bi bi-basket"></i> Estimated Bunches</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $in_progress_count; ?> / <?php echo $completed_count; ?></h3>
                <p><i class="bi bi-graph-up"></i> In Progress / Completed</p>
            </div>
        </div>
    </div>
</div>

<!-- Status Breakdown -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bar-chart"></i> Plans by Status
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h4 class="text-info"><?php echo $planned_count; ?></h4>
                        <small>Planned</small>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-primary"><?php echo $in_progress_count; ?></h4>
                        <small>In Progress</small>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-success"><?php echo $completed_count; ?></h4>
                        <small>Completed</small>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-secondary"><?php echo count(array_filter($plans, function($p) { return $p['status'] == 'Cancelled'; })); ?></h4>
                        <small>Cancelled</small>
                    </div>
                </div>
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
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $status_filter == $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="round">
                    <option value="">All Rounds</option>
                    <?php foreach ($harvesting_rounds as $round): ?>
                        <option value="<?php echo $round; ?>" <?php echo $round_filter == $round ? 'selected' : ''; ?>><?php echo $round; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" placeholder="From" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" placeholder="To" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- Harvest Plans Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Harvest Plans (<?php echo count($plans); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Plan Number</th>
                        <th>Block</th>
                        <th>Plan Date</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Est. Qty (Kg)</th>
                        <th>Est. Bunches</th>
                        <th>Round</th>
                        <th>Team</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">No harvest plans found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($plans as $plan): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($plan['plan_number']); ?></strong></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($plan['estate_name']); ?></small><br>
                                    <?php echo htmlspecialchars($plan['block_name']); ?>
                                </td>
                                <td><?php echo format_date($plan['plan_date']); ?></td>
                                <td><?php echo format_date($plan['planned_start_date']); ?></td>
                                <td><?php echo format_date($plan['planned_end_date']); ?></td>
                                <td><?php echo format_number($plan['estimated_quantity_kg'], 0); ?></td>
                                <td><?php echo format_number($plan['estimated_bunches'], 0); ?></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo htmlspecialchars($plan['harvesting_round']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($plan['assigned_team']); ?></td>
                                <td><?php echo get_status_badge($plan['status']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $plan['harvest_plan_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $plan['harvest_plan_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="harvest_plans.php" style="display:inline;" onsubmit="return confirmDelete('Delete this plan?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="harvest_plan_id" value="<?php echo $plan['harvest_plan_id']; ?>">
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

<?php foreach ($plans as $plan): ?>
                            <!-- View Details Modal -->
                            <div class="modal fade" id="viewModal<?php echo $plan['harvest_plan_id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Harvest Plan Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">Plan Number:</th>
                                                            <td><strong><?php echo htmlspecialchars($plan['plan_number']); ?></strong></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Block:</th>
                                                            <td><?php echo htmlspecialchars($plan['block_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Estate:</th>
                                                            <td><?php echo htmlspecialchars($plan['estate_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Block Area:</th>
                                                            <td><?php echo format_number($plan['area'], 2); ?> Ha</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Total Plants:</th>
                                                            <td><?php echo format_number($plan['total_plants'], 0); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Plan Date:</th>
                                                            <td><?php echo format_date($plan['plan_date']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Start Date:</th>
                                                            <td><?php echo format_date($plan['planned_start_date']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>End Date:</th>
                                                            <td><?php echo format_date($plan['planned_end_date']); ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">Est. Quantity:</th>
                                                            <td><?php echo format_number($plan['estimated_quantity_kg'], 0); ?> Kg</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Est. Bunches:</th>
                                                            <td><?php echo format_number($plan['estimated_bunches'], 0); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Avg Bunch Weight:</th>
                                                            <td><?php echo $plan['estimated_bunches'] > 0 ? format_number($plan['estimated_quantity_kg'] / $plan['estimated_bunches'], 2) . ' Kg' : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Harvesting Round:</th>
                                                            <td><?php echo htmlspecialchars($plan['harvesting_round']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Criteria:</th>
                                                            <td><?php echo htmlspecialchars($plan['harvesting_criteria']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Assigned Team:</th>
                                                            <td><?php echo htmlspecialchars($plan['assigned_team']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Supervisor:</th>
                                                            <td><?php echo htmlspecialchars($plan['supervisor']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Status:</th>
                                                            <td><?php echo htmlspecialchars($plan['status']); ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                            <?php if ($plan['notes']): ?>
                                            <div class="mt-3">
                                                <h6>Notes:</h6>
                                                <p><?php echo nl2br(htmlspecialchars($plan['notes'])); ?></p>
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
            <form method="POST" action="harvest_plans.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_record ? 'Edit Harvest Plan' : 'Create Harvest Plan'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_record ? 'edit' : 'add'; ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="harvest_plan_id" value="<?php echo $edit_record['harvest_plan_id']; ?>">
                        <div class="alert alert-info">
                            <strong>Plan Number:</strong> <?php echo htmlspecialchars($edit_record['plan_number']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Block (TM Only) <span class="text-danger">*</span></label>
                            <select class="form-select" name="block_id" required id="block_select">
                                <option value="">Select Block</option>
                                <?php foreach ($blocks as $block): ?>
                                    <option value="<?php echo $block['block_id']; ?>"
                                        data-plants="<?php echo $block['total_plants']; ?>"
                                        data-area="<?php echo $block['area']; ?>"
                                        <?php echo ($edit_record && $edit_record['block_id'] == $block['block_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($block['company_name'] . ' - ' . $block['unit_name'] . ' - ' . $block['block_name'] . ' (' . $block['area'] . ' Ha)'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" id="block_info"></small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Plan Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="plan_date" required
                                   value="<?php echo $edit_record ? $edit_record['plan_date'] : date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Planned Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="planned_start_date" required
                                   value="<?php echo $edit_record ? $edit_record['planned_start_date'] : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Planned End Date</label>
                            <input type="date" class="form-control" name="planned_end_date"
                                   value="<?php echo $edit_record ? $edit_record['planned_end_date'] : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Estimated Quantity (Kg)</label>
                            <input type="number" step="0.01" class="form-control" name="estimated_quantity_kg" id="est_qty"
                                   value="<?php echo $edit_record ? $edit_record['estimated_quantity_kg'] : ''; ?>"
                                   placeholder="e.g., 5000">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Estimated Bunches</label>
                            <input type="number" class="form-control" name="estimated_bunches" id="est_bunches"
                                   value="<?php echo $edit_record ? $edit_record['estimated_bunches'] : ''; ?>"
                                   placeholder="e.g., 250">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Avg Bunch Weight (Kg)</label>
                            <input type="text" class="form-control" id="avg_weight" readonly placeholder="Auto-calculated">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Harvesting Round</label>
                            <select class="form-select" name="harvesting_round">
                                <?php foreach ($harvesting_rounds as $round): ?>
                                    <option value="<?php echo $round; ?>" <?php echo ($edit_record && $edit_record['harvesting_round'] == $round) ? 'selected' : ((!$edit_record && $round == 'Round 1') ? 'selected' : ''); ?>>
                                        <?php echo $round; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Harvesting Criteria</label>
                            <input type="text" class="form-control" name="harvesting_criteria"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['harvesting_criteria']) : ''; ?>"
                                   placeholder="e.g., Ripe bunches, 5-10 loose fruits">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Assigned Team</label>
                            <input type="text" class="form-control" name="assigned_team"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['assigned_team']) : ''; ?>"
                                   placeholder="e.g., Team A">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Supervisor</label>
                            <input type="text" class="form-control" name="supervisor"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['supervisor']) : ''; ?>"
                                   placeholder="Supervisor name">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo ($edit_record && $edit_record['status'] == $status) ? 'selected' : ((!$edit_record && $status == 'Planned') ? 'selected' : ''); ?>>
                                        <?php echo $status; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes..."><?php echo $edit_record ? htmlspecialchars($edit_record['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_record ? 'Update' : 'Create'; ?> Plan
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

// Show block info when selected
document.getElementById('block_select').addEventListener('change', function() {
    var selected = this.options[this.selectedIndex];
    var plants = selected.getAttribute('data-plants');
    var area = selected.getAttribute('data-area');
    if (plants && area) {
        document.getElementById('block_info').textContent = 'Area: ' + area + ' Ha, Plants: ' + plants;
    } else {
        document.getElementById('block_info').textContent = '';
    }
});

// Calculate average bunch weight
function calculateAvgWeight() {
    var qty = parseFloat(document.getElementById('est_qty').value) || 0;
    var bunches = parseFloat(document.getElementById('est_bunches').value) || 0;
    
    if (qty > 0 && bunches > 0) {
        var avg = qty / bunches;
        document.getElementById('avg_weight').value = avg.toFixed(2) + ' Kg';
    } else {
        document.getElementById('avg_weight').value = '';
    }
}

document.getElementById('est_qty').addEventListener('input', calculateAvgWeight);
document.getElementById('est_bunches').addEventListener('input', calculateAvgWeight);

// Trigger calculation on page load if editing
<?php if ($edit_record && $edit_record['estimated_quantity_kg'] && $edit_record['estimated_bunches']): ?>
calculateAvgWeight();
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
