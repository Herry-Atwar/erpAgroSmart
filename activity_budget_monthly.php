<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Monthly Budget Tracking";
require_once 'includes/header.php';

// Get parameters
$plan_id = get('plan_id', '');
$month_filter = get('month', '');
$year_filter = get('year', date('Y'));

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = post('action');
    
    if ($post_action === 'update_actual') {
        try {
            $stmt = $db->prepare("
                UPDATE activity_budget_monthly
                SET actual_man_days = ?,
                    actual_cost = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE monthly_id = ?
            ");
            
            $stmt->execute([
                post('actual_man_days'),
                post('actual_cost'),
                post('notes'),
                post('monthly_id')
            ]);
            
            $success_message = "Actual data updated successfully!";
            
        } catch (PDOException $e) {
            $error_message = "Error updating actual data: " . $e->getMessage();
        }
    }
}

// Get plan details if plan_id is provided
$plan_details = null;
if ($plan_id) {
    $stmt = $db->prepare("
        SELECT 
            abp.*,
            b.block_code,
            b.block_name,
            b.area as block_area,
            a.activity_code,
            a.activity_name,
            ag.group_name as activity_group,
            an.norm_name,
            an.man_days_per_unit,
            an.daily_wage
        FROM activity_budget_plans abp
        INNER JOIN blocks b ON abp.block_id = b.block_id
        INNER JOIN activities a ON abp.activity_id = a.id
        INNER JOIN activity_groups ag ON a.activity_group_id = ag.id
        INNER JOIN activity_norms an ON abp.norm_id = an.id
        WHERE abp.plan_id = ?
    ");
    $stmt->execute([$plan_id]);
    $plan_details = $stmt->fetch();
}

// Build query for monthly data
$sql = "
    SELECT
        abm.*,
        abp.budget_year,
        b.block_code,
        b.block_name,
        a.activity_name,
        ag.group_name as activity_group,
        (abm.actual_cost - abm.planned_cost) as variance_amount,
        CASE
            WHEN abm.planned_cost > 0 THEN
                ((abm.actual_cost - abm.planned_cost) / abm.planned_cost * 100)
            ELSE 0
        END as variance_percent
    FROM activity_budget_monthly abm
    INNER JOIN activity_budget_plans abp ON abm.plan_id = abp.plan_id
    INNER JOIN blocks b ON abp.block_id = b.block_id
    INNER JOIN activities a ON abp.activity_id = a.id
    INNER JOIN activity_groups ag ON a.activity_group_id = ag.id
    WHERE 1=1
";

$params = [];

if ($plan_id) {
    $sql .= " AND abm.plan_id = ?";
    $params[] = $plan_id;
}

if ($year_filter) {
    $sql .= " AND abp.budget_year = ?";
    $params[] = $year_filter;
}

if ($month_filter) {
    $sql .= " AND abm.budget_month = ?";
    $params[] = $month_filter;
}

$sql .= " ORDER BY abp.budget_year, abm.budget_month, b.block_code, ag.display_order, a.display_order";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$monthly_data = $stmt->fetchAll();

// Calculate summary
$total_planned = array_sum(array_column($monthly_data, 'planned_cost'));
$total_actual = array_sum(array_column($monthly_data, 'actual_cost'));
$total_variance = $total_actual - $total_planned;
$variance_percent = $total_planned > 0 ? ($total_variance / $total_planned * 100) : 0;

// Get list of plans for filter
$plans_stmt = $db->query("
    SELECT 
        abp.plan_id,
        abp.budget_year,
        b.block_code,
        a.activity_name
    FROM activity_budget_plans abp
    INNER JOIN blocks b ON abp.block_id = b.block_id
    INNER JOIN activities a ON abp.activity_id = a.id
    ORDER BY abp.budget_year DESC, b.block_code, a.activity_name
");
$plans = $plans_stmt->fetchAll();

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-calendar-month"></i> Monthly Budget Tracking</h2>
                <a href="activity_budget_plans.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Plans
                </a>
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

    <?php if ($plan_details): ?>
    <!-- Plan Details Card -->
    <div class="card mb-4 border-primary">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Budget Plan Details</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <strong>Plan ID:</strong> <?= $plan_details['plan_id'] ?><br>
                    <strong>Year:</strong> <?= $plan_details['budget_year'] ?>
                </div>
                <div class="col-md-3">
                    <strong>Block:</strong> <?= htmlspecialchars($plan_details['block_code']) ?><br>
                    <strong>Area:</strong> <?= $plan_details['block_area'] ?> ha
                </div>
                <div class="col-md-3">
                    <strong>Activity:</strong> <?= htmlspecialchars($plan_details['activity_name']) ?><br>
                    <strong>Group:</strong> <?= htmlspecialchars($plan_details['activity_group']) ?>
                </div>
                <div class="col-md-3">
                    <strong>Frequency:</strong> <?= ucfirst($plan_details['frequency_type']) ?><br>
                    <strong>Annual Budget:</strong> Rp <?= number_format($plan_details['total_annual_cost'], 0, ',', '.') ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h6>Total Planned</h6>
                    <h3>Rp <?= number_format($total_planned, 0, ',', '.') ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6>Total Actual</h6>
                    <h3>Rp <?= number_format($total_actual, 0, ',', '.') ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-<?= $total_variance > 0 ? 'danger' : 'success' ?> text-white">
                <div class="card-body">
                    <h6>Variance</h6>
                    <h3>Rp <?= number_format(abs($total_variance), 0, ',', '.') ?></h3>
                    <small><?= $total_variance > 0 ? 'Over' : 'Under' ?> Budget</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-<?= abs($variance_percent) > 10 ? 'warning' : 'secondary' ?> text-white">
                <div class="card-body">
                    <h6>Variance %</h6>
                    <h3><?= number_format(abs($variance_percent), 1) ?>%</h3>
                    <small><?= $total_variance > 0 ? 'Over' : 'Under' ?> Budget</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Budget Plan</label>
                    <select name="plan_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Plans</option>
                        <?php foreach ($plans as $p): ?>
                        <option value="<?= $p['plan_id'] ?>" <?= $p['plan_id'] == $plan_id ? 'selected' : '' ?>>
                            <?= $p['budget_year'] ?> - <?= htmlspecialchars($p['block_code']) ?> - <?= htmlspecialchars($p['activity_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Year</label>
                    <select name="year" class="form-select" onchange="this.form.submit()">
                        <?php for($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $year_filter ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Month</label>
                    <select name="month" class="form-select" onchange="this.form.submit()">
                        <option value="">All Months</option>
                        <?php foreach ($months as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $num == $month_filter ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <a href="activity_budget_monthly.php" class="btn btn-secondary w-100">
                        <i class="bi bi-arrow-clockwise"></i> Reset Filters
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Monthly Data Table -->
    <div class="card">
        <div class="card-header bg-light">
            <h5 class="mb-0">Monthly Budget vs Actual</h5>
        </div>
        <div class="card-body">
            <?php if (empty($monthly_data)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No monthly data found. Please select a budget plan or adjust filters.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Block</th>
                            <th>Activity</th>
                            <th>Planned<br>Man-Days</th>
                            <th>Actual<br>Man-Days</th>
                            <th>Planned<br>Cost</th>
                            <th>Actual<br>Cost</th>
                            <th>Variance</th>
                            <th>%</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthly_data as $row): ?>
                        <tr class="<?= abs($row['variance_percent']) > 10 ? 'table-warning' : '' ?>">
                            <td>
                                <strong><?= $months[$row['budget_month']] ?></strong><br>
                                <small class="text-muted"><?= $row['budget_year'] ?></small>
                            </td>
                            <td><?= htmlspecialchars($row['block_code']) ?></td>
                            <td>
                                <small><?= htmlspecialchars($row['activity_name']) ?></small>
                            </td>
                            <td><?= number_format($row['planned_man_days'], 2) ?></td>
                            <td>
                                <?php if ($row['actual_man_days'] > 0): ?>
                                    <strong><?= number_format($row['actual_man_days'], 2) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>Rp <?= number_format($row['planned_cost'], 0, ',', '.') ?></td>
                            <td>
                                <?php if ($row['actual_cost'] > 0): ?>
                                    <strong>Rp <?= number_format($row['actual_cost'], 0, ',', '.') ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= $row['variance_amount'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?php if ($row['actual_cost'] > 0): ?>
                                    <?= $row['variance_amount'] > 0 ? '+' : '' ?>Rp <?= number_format($row['variance_amount'], 0, ',', '.') ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['actual_cost'] > 0): ?>
                                    <span class="badge bg-<?= abs($row['variance_percent']) > 10 ? 'danger' : 'success' ?>">
                                        <?= number_format($row['variance_percent'], 1) ?>%
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?= htmlspecialchars($row['notes'] ?? '') ?></small>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" 
                                        onclick="editActual(<?= $row['monthly_id'] ?>, <?= $row['actual_man_days'] ?>, <?= $row['actual_cost'] ?>, '<?= htmlspecialchars($row['notes'] ?? '', ENT_QUOTES) ?>')">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-secondary">
                        <tr>
                            <th colspan="5" class="text-end">TOTAL:</th>
                            <th>Rp <?= number_format($total_planned, 0, ',', '.') ?></th>
                            <th>Rp <?= number_format($total_actual, 0, ',', '.') ?></th>
                            <th class="<?= $total_variance > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $total_variance > 0 ? '+' : '' ?>Rp <?= number_format($total_variance, 0, ',', '.') ?>
                            </th>
                            <th>
                                <span class="badge bg-<?= abs($variance_percent) > 10 ? 'danger' : 'success' ?>">
                                    <?= number_format($variance_percent, 1) ?>%
                                </span>
                            </th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Actual Modal -->
<div class="modal fade" id="editActualModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_actual">
                <input type="hidden" name="monthly_id" id="edit_monthly_id">
                <div class="modal-header">
                    <h5 class="modal-title">Update Actual Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Actual Man-Days</label>
                        <input type="number" name="actual_man_days" id="edit_man_days" 
                               class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Actual Cost (Rp)</label>
                        <input type="number" name="actual_cost" id="edit_cost" 
                               class="form-control" step="1" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editActual(monthlyId, manDays, cost, notes) {
    document.getElementById('edit_monthly_id').value = monthlyId;
    document.getElementById('edit_man_days').value = manDays;
    document.getElementById('edit_cost').value = cost;
    document.getElementById('edit_notes').value = notes;
    
    const modal = new bootstrap.Modal(document.getElementById('editActualModal'));
    modal.show();
}
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
