<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Activity Budget Plans";
require_once 'includes/header.php';

// Get filters
$year = get('year', date('Y'));
$block_filter = get('block_id', '');
$activity_filter = get('activity_id', '');
$status_filter = get('status', '');
$coverage_filter = get('coverage', '');
$action = get('action', 'list');
$plan_id = get('id', '');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = post('action');
    
    if ($post_action === 'create_plan') {
        try {
            // Get coverage percentage, default to 100 if not provided
            $coverage_percentage = post('coverage_percentage', 100);
            
            // Call stored procedure with 8 parameters (including coverage_percentage)
            $stmt = $db->prepare("
                CALL sp_generate_activity_budget_plan(?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('budget_year'),
                post('block_id'),
                post('activity_id'),
                post('frequency_type'),
                post('frequency_value'),
                post('start_month'),
                'admin', // Replace with actual user
                $coverage_percentage
            ]);
            
            // Get the plan_id
            $result = $stmt->fetch();
            $new_plan_id = $result['plan_id'] ?? null;
            $stmt->closeCursor();
            $stmt = null;
            
            if ($new_plan_id) {
                // For custom frequency, update execution_months
                if (post('frequency_type') === 'custom' && post('execution_months')) {
                    $stmt_update = $db->prepare("
                        UPDATE activity_budget_plans
                        SET execution_months = ?
                        WHERE plan_id = ?
                    ");
                    $stmt_update->execute([post('execution_months'), $new_plan_id]);
                    $stmt_update->closeCursor();
                    $stmt_update = null;
                }
                
                // Generate monthly distribution
                $stmt2 = $db->prepare("CALL sp_generate_monthly_distribution(?)");
                $stmt2->execute([$new_plan_id]);
                $stmt2->closeCursor();
                $stmt2 = null;
                
                $success_message = "Budget plan created successfully! Plan ID: " . $new_plan_id;
            }
            
        } catch (PDOException $e) {
            $error_message = "Error creating budget plan: " . $e->getMessage();
        }
    } elseif ($post_action === 'update_plan') {
        try {
            $coverage_percentage = post('coverage_percentage', 100);
            
            $stmt = $db->prepare("
                UPDATE activity_budget_plans
                SET frequency_type = ?,
                    executions_per_year = ?,
                    start_month = ?,
                    execution_months = ?,
                    coverage_percentage = ?,
                    status = ?
                WHERE plan_id = ?
            ");
            $stmt->execute([
                post('frequency_type'),
                post('frequency_value'),
                post('start_month'),
                post('execution_months'),
                $coverage_percentage,
                post('status'),
                post('plan_id')
            ]);
            $stmt->closeCursor();
            $stmt = null;
            
            // Regenerate monthly distribution
            $stmt2 = $db->prepare("DELETE FROM activity_budget_monthly WHERE plan_id = ?");
            $stmt2->execute([post('plan_id')]);
            $stmt2->closeCursor();
            $stmt2 = null;
            
            $stmt3 = $db->prepare("CALL sp_generate_monthly_distribution(?)");
            $stmt3->execute([post('plan_id')]);
            $stmt3->closeCursor();
            $stmt3 = null;
            
            $success_message = "Budget plan updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating budget plan: " . $e->getMessage();
        }
    } elseif ($post_action === 'delete_plan') {
        try {
            $stmt = $db->prepare("DELETE FROM activity_budget_plans WHERE plan_id = ?");
            $stmt->execute([post('plan_id')]);
            $success_message = "Budget plan deleted successfully!";
        } catch (PDOException $e) {
            $error_message = "Error deleting budget plan: " . $e->getMessage();
        }
    }
}

// Fetch data for filters
$blocks_stmt = $db->query("
    SELECT b.block_id, b.block_code, b.block_name, b.area
    FROM blocks b
    ORDER BY b.block_code
");
$blocks = $blocks_stmt->fetchAll();

// Fetch activities (measurement_types table does not exist in this schema)
$activities_stmt = $db->query("
    SELECT a.id, a.activity_code, a.activity_name, ag.group_name, NULL as measurement_types
    FROM activities a
    INNER JOIN activity_groups ag ON a.activity_group_id = ag.id
    WHERE a.calculation_method = 'norm_based'
    ORDER BY ag.display_order, a.display_order
");
$activities = $activities_stmt->fetchAll();

// Fetch budget plans
$sql = "
    SELECT
        abp.plan_id,
        abp.budget_year,
        b.block_code,
        b.block_name,
        b.area as block_area,
        a.activity_code,
        a.activity_name,
        ag.group_name as activity_group,
        an.norm_name,
        abp.frequency_type,
        abp.executions_per_year,
        abp.man_days_per_execution,
        abp.cost_per_execution,
        abp.total_man_days,
        abp.total_annual_cost,
        abp.status,
        ROUND((abp.planned_area / b.area * 100), 2) as coverage_percentage,
        abp.created_at
    FROM activity_budget_plans abp
    INNER JOIN blocks b ON abp.block_id = b.block_id
    INNER JOIN activities a ON abp.activity_id = a.id
    INNER JOIN activity_groups ag ON a.activity_group_id = ag.id
    INNER JOIN activity_norms an ON abp.norm_id = an.id
    WHERE abp.budget_year = ?
";

$params = [$year];

if ($block_filter) {
    $sql .= " AND abp.block_id = ?";
    $params[] = $block_filter;
}

if ($activity_filter) {
    $sql .= " AND abp.activity_id = ?";
    $params[] = $activity_filter;
}

if ($status_filter) {
    $sql .= " AND abp.status = ?";
    $params[] = $status_filter;
}

if ($coverage_filter) {
    if ($coverage_filter === '100') {
        $sql .= " AND ROUND((abp.planned_area / b.area * 100), 2) = 100";
    } elseif ($coverage_filter === 'partial') {
        $sql .= " AND ROUND((abp.planned_area / b.area * 100), 2) < 100";
    } elseif (strpos($coverage_filter, '-') !== false) {
        // Custom range like "50-75"
        list($min, $max) = explode('-', $coverage_filter);
        $sql .= " AND ROUND((abp.planned_area / b.area * 100), 2) BETWEEN ? AND ?";
        $params[] = $min;
        $params[] = $max;
    }
}

$sql .= " ORDER BY b.block_code, ag.display_order, a.display_order";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$budget_plans = $stmt->fetchAll();

// Calculate summary
$total_plans = count($budget_plans);
$total_budget = array_sum(array_column($budget_plans, 'total_annual_cost'));
$total_man_days = array_sum(array_column($budget_plans, 'total_man_days'));
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-clipboard-check"></i> Activity Budget Plans</h2>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPlanModal">
                    <i class="bi bi-plus-circle"></i> Create Budget Plan
                </button>
            </div>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle"></i> <strong>Error:</strong> <?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>


    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6>Total Budget Plans</h6>
                    <h2><?= number_format($total_plans) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h6>Total Annual Budget</h6>
                    <h2>Rp <?= number_format($total_budget, 0, ',', '.') ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h6>Total Man-Days</h6>
                    <h2><?= number_format($total_man_days, 2, ',', '.') ?></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Year</label>
                    <select name="year" class="form-select" onchange="this.form.submit()">
                        <?php for($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Block</label>
                    <select name="block_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Blocks</option>
                        <?php foreach ($blocks as $block): ?>
                        <option value="<?= $block['block_id'] ?>" <?= $block['block_id'] == $block_filter ? 'selected' : '' ?>>
                            <?= htmlspecialchars($block['block_code']) ?> - <?= htmlspecialchars($block['block_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Activity</label>
                    <select name="activity_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Activities</option>
                        <?php foreach ($activities as $activity): ?>
                        <option value="<?= $activity['id'] ?>" <?= $activity['id'] == $activity_filter ? 'selected' : '' ?>>
                            <?= htmlspecialchars($activity['activity_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="draft" <?= $status_filter == 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Coverage</label>
                    <select name="coverage" class="form-select" onchange="this.form.submit()">
                        <option value="">All Coverage</option>
                        <option value="100" <?= $coverage_filter == '100' ? 'selected' : '' ?>>100% Only</option>
                        <option value="partial" <?= $coverage_filter == 'partial' ? 'selected' : '' ?>>Partial (&lt;100%)</option>
                        <option value="75-99" <?= $coverage_filter == '75-99' ? 'selected' : '' ?>>75-99%</option>
                        <option value="50-74" <?= $coverage_filter == '50-74' ? 'selected' : '' ?>>50-74%</option>
                        <option value="0-49" <?= $coverage_filter == '0-49' ? 'selected' : '' ?>>0-49%</option>
                    </select>
                </div>
            </form>
            <div class="row g-3 mt-2">
                <div class="col-md-2">
                    <a href="activity_budget_plans.php" class="btn btn-secondary w-100">
                        <i class="bi bi-arrow-clockwise"></i> Reset Filters
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Budget Plans Table -->
    <div class="card">
        <div class="card-header bg-light">
            <h5 class="mb-0">Budget Plans for <?= $year ?></h5>
        </div>
        <div class="card-body">
            <?php if (empty($budget_plans)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No budget plans found. Create your first budget plan using the button above.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Plan ID</th>
                            <th>Block</th>
                            <th>Activity</th>
                            <th>Norm</th>
                            <th>Frequency</th>
                            <th>Exec/Year</th>
                            <th>Coverage</th>
                            <th>Man-Days/Exec</th>
                            <th class="text-end">Cost/Exec</th>
                            <th class="text-end">Annual Cost</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($budget_plans as $plan):
                            $coverage = $plan['coverage_percentage'] ?? 100;
                            $coverage_class = '';
                            if ($coverage == 100) {
                                $coverage_class = 'bg-success';
                            } elseif ($coverage >= 75) {
                                $coverage_class = 'bg-primary';
                            } elseif ($coverage >= 50) {
                                $coverage_class = 'bg-warning';
                            } else {
                                $coverage_class = 'bg-danger';
                            }
                        ?>
                        <tr>
                            <td><?= $plan['plan_id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($plan['block_code']) ?></strong><br>
                                <small class="text-muted"><?= $plan['block_area'] ?> ha</small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($plan['activity_name']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($plan['activity_group']) ?></small>
                            </td>
                            <td><small><?= htmlspecialchars($plan['norm_name']) ?></small></td>
                            <td><?= ucfirst($plan['frequency_type']) ?></td>
                            <td><?= $plan['executions_per_year'] ?></td>
                            <td>
                                <span class="badge <?= $coverage_class ?>"><?= number_format($coverage, 0) ?>%</span>
                            </td>
                            <td><?= number_format($plan['man_days_per_execution'], 2) ?></td>
                            <td class="text-end">Rp <?= number_format($plan['cost_per_execution'], 0, ',', '.') ?></td>
                            <td class="text-end"><strong>Rp <?= number_format($plan['total_annual_cost'], 0, ',', '.') ?></strong></td>
                            <td>
                                <span class="badge bg-<?= $plan['status'] == 'approved' ? 'success' : ($plan['status'] == 'draft' ? 'secondary' : 'primary') ?>">
                                    <?= ucfirst($plan['status']) ?>
                                </span>
                            </td>
                            <td class="text-nowrap">
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-primary"
                                            onclick="editPlan(<?= $plan['plan_id'] ?>)" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <a href="activity_budget_monthly.php?plan_id=<?= $plan['plan_id'] ?>"
                                       class="btn btn-sm btn-info" title="View Monthly">
                                        <i class="bi bi-calendar-month"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="deletePlan(<?= $plan['plan_id'] ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary">
                            <th colspan="8" class="text-end">TOTAL:</th>
                            <th class="text-end">Rp <?= number_format($total_budget, 0, ',', '.') ?></th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create Plan Modal -->
<div class="modal fade" id="createPlanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_plan">
                <div class="modal-header">
                    <h5 class="modal-title">Create Budget Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Budget Year *</label>
                            <select name="budget_year" class="form-select" required>
                                <?php for($y = date('Y'); $y <= date('Y') + 2; $y++): ?>
                                <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Block *</label>
                            <select name="block_id" id="block_select" class="form-select" required <?= $has_measurement_types ? 'onchange="loadBlockComponents(this.value)"' : '' ?>>
                                <option value="">Select Block</option>
                                <?php foreach ($blocks as $block): ?>
                                <option value="<?= $block['block_id'] ?>">
                                    <?= htmlspecialchars($block['block_code']) ?> - <?= htmlspecialchars($block['block_name']) ?> (<?= $block['area'] ?> ha)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($has_measurement_types): ?>
                        <div class="col-md-12" id="block_components_info" style="display: none;">
                            <div class="alert alert-info mb-0">
                                <h6 class="mb-2"><i class="bi bi-info-circle"></i> Available Measurements for Selected Block:</h6>
                                <div id="block_components_list"></div>
                            </div>
                        </div>
                        <?php endif; ?>
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
                                    <?php if ($activity['measurement_types']): ?>
                                        - Uses: <?= htmlspecialchars($activity['measurement_types']) ?>
                                    <?php else: ?>
                                        - Uses: Block Area (ha)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                                <?php if ($current_group != '') echo '</optgroup>'; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Frequency Type *</label>
                            <select name="frequency_type" class="form-select" id="frequency_type" required onchange="toggleCustomMonths()">
                                <option value="custom">Custom (select specific months)</option>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="biweekly">Bi-weekly (every 2 weeks)</option>
                                <option value="monthly">Monthly</option>
                                <option value="bimonthly">Bi-monthly (every 2 months)</option>
                                <option value="quarterly">Quarterly (every 3 months)</option>
                                <option value="semiannual">Semi-annual (every 6 months)</option>
                                <option value="annual">Annual (once per year)</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="frequency_value_container">
                            <label class="form-label">Executions per Year *</label>
                            <input type="number" name="frequency_value" id="frequency_value" class="form-control" value="1" min="1" max="365" required>
                            <small class="text-muted">Auto-calculated from selected months for custom frequency</small>
                        </div>
                        <div class="col-md-6" id="start_month_container">
                            <label class="form-label">Start Month *</label>
                            <select name="start_month" class="form-select" required>
                                <?php
                                $months = ['January', 'February', 'March', 'April', 'May', 'June',
                                          'July', 'August', 'September', 'October', 'November', 'December'];
                                for($m = 1; $m <= 12; $m++):
                                ?>
                                <option value="<?= $m ?>"><?= $months[$m-1] ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <!-- Custom Month Selector (hidden by default) -->
                        <div class="col-md-12" id="custom_months_container" style="display: none;">
                            <label class="form-label">Select Execution Months *</label>
                            <div class="border rounded p-3 bg-light">
                                <div class="row g-2">
                                    <?php
                                    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                                    for($m = 1; $m <= 12; $m++):
                                    ?>
                                    <div class="col-md-3 col-sm-4 col-6">
                                        <div class="form-check">
                                            <input class="form-check-input custom-month-check" type="checkbox"
                                                   name="custom_months[]" value="<?= $m ?>" id="month_<?= $m ?>"
                                                   onchange="updateFrequencyValue()">
                                            <label class="form-check-label" for="month_<?= $m ?>">
                                                <?= $months[$m-1] ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="execution_months" id="execution_months" value="">
                            </div>
                            <small class="text-muted">Check the months when this activity will be executed</small>
                        </div>
                        
                        <!-- Coverage Percentage -->
                        <div class="col-md-6">
                            <label class="form-label">Coverage Percentage *</label>
                            <input type="range" name="coverage_percentage" id="coverage_percentage"
                                   class="form-range" min="0" max="100" value="100" step="5"
                                   oninput="updateCoverageDisplay()">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">0%</span>
                                <span id="coverage_display" class="badge bg-primary fs-6">100%</span>
                                <span class="text-muted">100%</span>
                            </div>
                            <small class="text-muted">Percentage of block area/measurement to be executed</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">&nbsp;</label>
                            <div class="alert alert-info mb-0 py-2">
                                <i class="bi bi-info-circle"></i>
                                <small>Default is 100%. Adjust if only partial area will be executed.</small>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3" id="info_alert">
                        <i class="bi bi-info-circle"></i>
                        <strong>Note:</strong> The system will automatically select the appropriate productivity norm based on the block's terrain and palm age.
                    </div>
                    <div class="alert alert-warning mt-3" id="custom_alert" style="display: none;">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Custom Frequency:</strong> Select the specific months when this activity will be executed. The executions per year will be calculated automatically.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Budget Plan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Plan Modal -->
<div class="modal fade" id="editPlanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="editPlanForm">
                <input type="hidden" name="action" value="update_plan">
                <input type="hidden" name="plan_id" id="edit_plan_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Budget Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Note:</strong> Editing will regenerate monthly distribution. Block and Activity cannot be changed.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Block</label>
                            <input type="text" class="form-control" id="edit_block_info" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Activity</label>
                            <input type="text" class="form-control" id="edit_activity_info" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Frequency Type *</label>
                            <select name="frequency_type" class="form-select" id="edit_frequency_type" required onchange="toggleEditCustomMonths()">
                                <option value="custom">Custom (select specific months)</option>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="biweekly">Bi-weekly (every 2 weeks)</option>
                                <option value="monthly">Monthly</option>
                                <option value="bimonthly">Bi-monthly (every 2 months)</option>
                                <option value="quarterly">Quarterly (every 3 months)</option>
                                <option value="semiannual">Semi-annual (every 6 months)</option>
                                <option value="annual">Annual (once per year)</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="edit_frequency_value_container">
                            <label class="form-label">Executions per Year *</label>
                            <input type="number" name="frequency_value" class="form-control"
                                   id="edit_frequency_value" min="1" max="365" required>
                            <small class="text-muted">Auto-calculated from selected months for custom frequency</small>
                        </div>
                        <div class="col-md-6" id="edit_start_month_container">
                            <label class="form-label">Start Month *</label>
                            <select name="start_month" class="form-select" id="edit_start_month" required>
                                <?php
                                $months = ['January', 'February', 'March', 'April', 'May', 'June',
                                          'July', 'August', 'September', 'October', 'November', 'December'];
                                for($m = 1; $m <= 12; $m++):
                                ?>
                                <option value="<?= $m ?>"><?= $months[$m-1] ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <!-- Custom Month Selector for Edit (hidden by default) -->
                        <div class="col-md-12" id="edit_custom_months_container" style="display: none;">
                            <label class="form-label">Select Execution Months *</label>
                            <div class="border rounded p-3 bg-light">
                                <div class="row g-2">
                                    <?php
                                    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                                    for($m = 1; $m <= 12; $m++):
                                    ?>
                                    <div class="col-md-3 col-sm-4 col-6">
                                        <div class="form-check">
                                            <input class="form-check-input edit-custom-month-check" type="checkbox"
                                                   name="edit_custom_months[]" value="<?= $m ?>" id="edit_month_<?= $m ?>"
                                                   onchange="updateEditFrequencyValue()">
                                            <label class="form-check-label" for="edit_month_<?= $m ?>">
                                                <?= $months[$m-1] ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="execution_months" id="edit_execution_months" value="">
                            </div>
                            <small class="text-muted">Check the months when this activity will be executed</small>
                        </div>
                        
                        <!-- Coverage Percentage for Edit -->
                        <div class="col-md-6">
                            <label class="form-label">Coverage Percentage *</label>
                            <input type="range" name="coverage_percentage" id="edit_coverage_percentage"
                                   class="form-range" min="0" max="100" value="100" step="5"
                                   oninput="updateEditCoverageDisplay()">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">0%</span>
                                <span id="edit_coverage_display" class="badge bg-primary fs-6">100%</span>
                                <span class="text-muted">100%</span>
                            </div>
                            <small class="text-muted">Percentage of block area/measurement to be executed</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" id="edit_status" required>
                                <option value="draft">Draft</option>
                                <option value="approved">Approved</option>
                                <option value="active">Active</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Budget Plan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleCustomMonths() {
    const frequencyType = document.getElementById('frequency_type').value;
    const customMonthsContainer = document.getElementById('custom_months_container');
    const startMonthContainer = document.getElementById('start_month_container');
    const infoAlert = document.getElementById('info_alert');
    const customAlert = document.getElementById('custom_alert');
    const frequencyValueInput = document.getElementById('frequency_value');
    
    if (frequencyType === 'custom') {
        customMonthsContainer.style.display = 'block';
        startMonthContainer.style.display = 'none';
        infoAlert.style.display = 'none';
        customAlert.style.display = 'block';
        frequencyValueInput.readOnly = true;
        frequencyValueInput.value = 0;
    } else {
        customMonthsContainer.style.display = 'none';
        startMonthContainer.style.display = 'block';
        infoAlert.style.display = 'block';
        customAlert.style.display = 'none';
        frequencyValueInput.readOnly = false;
        frequencyValueInput.value = 1;
    }
}

function updateFrequencyValue() {
    const checkboxes = document.querySelectorAll('.custom-month-check:checked');
    const frequencyValueInput = document.getElementById('frequency_value');
    const executionMonthsInput = document.getElementById('execution_months');
    
    // Update frequency value (count of checked months)
    frequencyValueInput.value = checkboxes.length;
    
    // Build comma-separated list of month numbers
    const selectedMonths = Array.from(checkboxes).map(cb => cb.value).join(',');
    executionMonthsInput.value = selectedMonths;
}

function updateCoverageDisplay() {
    const slider = document.getElementById('coverage_percentage');
    const display = document.getElementById('coverage_display');
    display.textContent = slider.value + '%';
    
    // Change badge color based on percentage
    display.className = 'badge fs-6 ';
    if (slider.value == 100) {
        display.className += 'bg-success';
    } else if (slider.value >= 75) {
        display.className += 'bg-primary';
    } else if (slider.value >= 50) {
        display.className += 'bg-warning';
    } else {
        display.className += 'bg-danger';
    }
}

function updateEditCoverageDisplay() {
    const slider = document.getElementById('edit_coverage_percentage');
    const display = document.getElementById('edit_coverage_display');
    display.textContent = slider.value + '%';
    
    // Change badge color based on percentage
    display.className = 'badge fs-6 ';
    if (slider.value == 100) {
        display.className += 'bg-success';
    } else if (slider.value >= 75) {
        display.className += 'bg-primary';
    } else if (slider.value >= 50) {
        display.className += 'bg-warning';
    } else {
        display.className += 'bg-danger';
    }
}

function toggleEditCustomMonths() {
    const frequencyType = document.getElementById('edit_frequency_type').value;
    const customMonthsContainer = document.getElementById('edit_custom_months_container');
    const startMonthContainer = document.getElementById('edit_start_month_container');
    const frequencyValueInput = document.getElementById('edit_frequency_value');
    
    if (frequencyType === 'custom') {
        customMonthsContainer.style.display = 'block';
        startMonthContainer.style.display = 'none';
        frequencyValueInput.readOnly = true;
    } else {
        customMonthsContainer.style.display = 'none';
        startMonthContainer.style.display = 'block';
        frequencyValueInput.readOnly = false;
    }
}

function updateEditFrequencyValue() {
    const checkboxes = document.querySelectorAll('.edit-custom-month-check:checked');
    const frequencyValueInput = document.getElementById('edit_frequency_value');
    const executionMonthsInput = document.getElementById('edit_execution_months');
    
    // Update frequency value (count of checked months)
    frequencyValueInput.value = checkboxes.length;
    
    // Build comma-separated list of month numbers
    const selectedMonths = Array.from(checkboxes).map(cb => cb.value).join(',');
    executionMonthsInput.value = selectedMonths;
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleCustomMonths();
});

function editPlan(planId) {
    // Fetch plan data
    fetch(`get_plan_data.php?plan_id=${planId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_plan_id').value = data.plan.plan_id;
                document.getElementById('edit_block_info').value = data.plan.block_code + ' - ' + data.plan.block_name;
                document.getElementById('edit_activity_info').value = data.plan.activity_name;
                document.getElementById('edit_frequency_type').value = data.plan.frequency_type;
                document.getElementById('edit_frequency_value').value = data.plan.executions_per_year;
                document.getElementById('edit_start_month').value = data.plan.start_month;
                document.getElementById('edit_status').value = data.plan.status;
                
                // Set coverage percentage
                const coveragePercentage = data.plan.coverage_percentage || 100;
                document.getElementById('edit_coverage_percentage').value = coveragePercentage;
                updateEditCoverageDisplay();
                
                // Handle custom months if frequency type is custom
                if (data.plan.frequency_type === 'custom' && data.plan.execution_months) {
                    const selectedMonths = data.plan.execution_months.split(',');
                    // Uncheck all first
                    document.querySelectorAll('.edit-custom-month-check').forEach(cb => cb.checked = false);
                    // Check the selected months
                    selectedMonths.forEach(month => {
                        const checkbox = document.getElementById('edit_month_' + month.trim());
                        if (checkbox) checkbox.checked = true;
                    });
                    document.getElementById('edit_execution_months').value = data.plan.execution_months;
                }
                
                // Toggle custom months display
                toggleEditCustomMonths();
                
                const modal = new bootstrap.Modal(document.getElementById('editPlanModal'));
                modal.show();
            } else {
                alert('Error loading plan data: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error);
        });
}

function deletePlan(planId) {
    if (confirm('Are you sure you want to delete this budget plan? This will also delete all monthly records.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_plan">
            <input type="hidden" name="plan_id" value="${planId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function loadBlockComponents(blockId) {
    const infoDiv = document.getElementById('block_components_info');
    const listDiv = document.getElementById('block_components_list');
    
    if (!blockId) {
        infoDiv.style.display = 'none';
        return;
    }
    
    // Show loading state
    infoDiv.style.display = 'block';
    listDiv.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> Loading block measurements...';
    
    fetch('ajax_get_block_components.php?block_id=' + blockId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.components.length > 0) {
                    let html = '<div class="row g-2">';
                    html += '<div class="col-12"><strong>' + data.block.block_code + ' - ' + data.block.block_name + '</strong></div>';
                    html += '<div class="col-md-6"><span class="badge bg-secondary">Block Area: ' + data.block.area + ' ha</span></div>';
                    
                    data.components.forEach(comp => {
                        html += '<div class="col-md-6">';
                        html += '<span class="badge bg-primary">' + comp.measurement_name + ': ' +
                                parseFloat(comp.measurement_value).toLocaleString() + ' ' + comp.unit_symbol + '</span>';
                        html += '</div>';
                    });
                    
                    html += '</div>';
                    listDiv.innerHTML = html;
                } else {
                    listDiv.innerHTML = '<div class="text-muted"><i class="bi bi-exclamation-circle"></i> No additional measurements configured for this block. Only Block Area (ha) will be available.</div>';
                }
            } else {
                listDiv.innerHTML = '<div class="text-danger">Error loading block data: ' + data.message + '</div>';
            }
        })
        .catch(error => {
            listDiv.innerHTML = '<div class="text-danger">Error: ' + error + '</div>';
        });
}
</script>

<?php require_once 'includes/footer.php'; ?>
