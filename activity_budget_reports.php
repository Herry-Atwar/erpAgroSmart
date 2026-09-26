<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Activity Budget Reports";
require_once 'includes/header.php';

// Get parameters
$report_type = get('report_type', 'summary');
$year = get('year', date('Y'));
$block_id = get('block_id', '');
$activity_group = get('activity_group', '');

// Fetch blocks for filter
$blocks_stmt = $db->query("SELECT block_id, block_code, block_name FROM blocks ORDER BY block_code");
$blocks = $blocks_stmt->fetchAll();

// Fetch activity groups for filter
$groups_stmt = $db->query("SELECT group_name FROM activity_groups GROUP BY group_name ORDER BY MIN(display_order)");
$activity_groups = $groups_stmt->fetchAll();

// Report data variables
$report_data = [];
$summary_data = [];

// Generate report based on type
switch ($report_type) {
    case 'summary':
        // Annual Budget Summary by Block and Activity Group
        $sql = "
            SELECT
                abp.budget_year,
                b.block_code,
                b.block_name,
                ag.group_name as activity_group,
                COUNT(DISTINCT abp.plan_id) as plan_count,
                SUM(abp.total_annual_cost) as total_budget,
                SUM(abp.total_man_days) as total_man_days
            FROM activity_budget_plans abp
            INNER JOIN blocks b ON abp.block_id = b.block_id
            INNER JOIN activities a ON abp.activity_id = a.id
            INNER JOIN activity_groups ag ON a.activity_group_id = ag.id
            WHERE abp.budget_year = ?
        ";
        $params = [$year];
        
        if ($block_id) {
            $sql .= " AND abp.block_id = ?";
            $params[] = $block_id;
        }
        
        if ($activity_group) {
            $sql .= " AND ag.group_name = ?";
            $params[] = $activity_group;
        }
        
        $sql .= " GROUP BY abp.budget_year, b.block_code, b.block_name, ag.group_name
                  ORDER BY b.block_code, ag.group_name";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll();
        break;
        
    case 'monthly':
        // Monthly Budget vs Actual Analysis
        $sql = "
            SELECT
                abm.budget_year,
                abm.budget_month,
                b.block_code,
                ag.group_name as activity_group,
                SUM(abm.planned_cost) as total_budgeted,
                SUM(abm.actual_cost) as total_actual,
                SUM(abm.actual_cost - abm.planned_cost) as total_variance,
                CASE
                    WHEN SUM(abm.planned_cost) > 0 THEN
                        (SUM(abm.actual_cost - abm.planned_cost) / SUM(abm.planned_cost) * 100)
                    ELSE 0
                END as variance_percent
            FROM activity_budget_monthly abm
            INNER JOIN activity_budget_plans abp ON abm.plan_id = abp.plan_id
            INNER JOIN blocks b ON abp.block_id = b.block_id
            INNER JOIN activities a ON abp.activity_id = a.id
            INNER JOIN activity_groups ag ON a.activity_group_id = ag.id
            WHERE abm.budget_year = ?
        ";
        $params = [$year];
        
        if ($block_id) {
            $sql .= " AND abp.block_id = ?";
            $params[] = $block_id;
        }
        
        if ($activity_group) {
            $sql .= " AND ag.group_name = ?";
            $params[] = $activity_group;
        }
        
        $sql .= " GROUP BY abm.budget_year, abm.budget_month, b.block_code, ag.group_name
                  ORDER BY abm.budget_month, b.block_code, ag.group_name";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll();
        break;
        
    case 'variance':
        // Variance Analysis - Over/Under Budget
        $sql = "
            SELECT
                abm.budget_year,
                abm.budget_month,
                b.block_code,
                b.block_name,
                a.activity_name,
                ag.group_name as activity_group,
                abm.planned_cost as budgeted_cost,
                abm.actual_cost,
                (abm.actual_cost - abm.planned_cost) as variance_amount,
                CASE
                    WHEN abm.planned_cost > 0 THEN
                        ((abm.actual_cost - abm.planned_cost) / abm.planned_cost * 100)
                    ELSE 0
                END as variance_percent,
                CASE
                    WHEN abm.planned_cost > 0 AND ((abm.actual_cost - abm.planned_cost) / abm.planned_cost * 100) > 10 THEN 'Over Budget'
                    WHEN abm.planned_cost > 0 AND ((abm.actual_cost - abm.planned_cost) / abm.planned_cost * 100) < -10 THEN 'Under Budget'
                    ELSE 'Within Range'
                END as status
            FROM activity_budget_monthly abm
            INNER JOIN activity_budget_plans abp ON abm.plan_id = abp.plan_id
            INNER JOIN blocks b ON abp.block_id = b.block_id
            INNER JOIN activities a ON abp.activity_id = a.id
            INNER JOIN activity_groups ag ON a.activity_group_id = ag.id
            WHERE abm.budget_year = ?
              AND abm.actual_cost > 0
              AND ABS((abm.actual_cost - abm.planned_cost) / abm.planned_cost * 100) > 5
        ";
        $params = [$year];
        
        if ($block_id) {
            $sql .= " AND abp.block_id = ?";
            $params[] = $block_id;
        }
        
        if ($activity_group) {
            $sql .= " AND ag.group_name = ?";
            $params[] = $activity_group;
        }
        
        $sql .= " ORDER BY ABS((abm.actual_cost - abm.planned_cost) / abm.planned_cost * 100) DESC, abm.budget_month";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll();
        break;
        
    case 'classification':
        // Budget by Classification (Operational, Capital, TBM)
        $sql = "
            SELECT
                abp.budget_year,
                abp.budget_classification,
                COUNT(DISTINCT abp.plan_id) as plan_count,
                SUM(abp.total_annual_cost) as total_budget,
                SUM(abp.total_man_days) as total_man_days
            FROM activity_budget_plans abp
            WHERE abp.budget_year = ?
        ";
        $params = [$year];
        
        if ($block_id) {
            $sql .= " AND block_id = ?";
            $params[] = $block_id;
        }
        
        $sql .= " GROUP BY abp.budget_year, abp.budget_classification
                  ORDER BY abp.budget_classification";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll();
        break;
}

// Calculate overall summary
$total_budget = 0;
$total_actual = 0;
$total_variance = 0;

if ($report_type == 'summary') {
    $total_budget = array_sum(array_column($report_data, 'total_budget'));
} elseif ($report_type == 'monthly' || $report_type == 'variance') {
    $total_budget = array_sum(array_column($report_data, 'total_budgeted') ?: array_column($report_data, 'budgeted_cost'));
    $total_actual = array_sum(array_column($report_data, 'total_actual') ?: array_column($report_data, 'actual_cost'));
    $total_variance = $total_actual - $total_budget;
} elseif ($report_type == 'classification') {
    $total_budget = array_sum(array_column($report_data, 'total_budget'));
}

$months = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
    5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
    9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'
];
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-graph-up"></i> Activity Budget Reports</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-secondary">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <a href="activity_budget_plans.php" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i> Back to Plans
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Type Selection -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Report Type</label>
                    <select name="report_type" class="form-select" onchange="this.form.submit()">
                        <option value="summary" <?= $report_type == 'summary' ? 'selected' : '' ?>>Budget Summary</option>
                        <option value="monthly" <?= $report_type == 'monthly' ? 'selected' : '' ?>>Monthly Analysis</option>
                        <option value="variance" <?= $report_type == 'variance' ? 'selected' : '' ?>>Variance Analysis</option>
                    </select>
                </div>
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
                        <option value="<?= $block['block_id'] ?>" <?= $block['block_id'] == $block_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($block['block_code']) ?> - <?= htmlspecialchars($block['block_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Activity Group</label>
                    <select name="activity_group" class="form-select" onchange="this.form.submit()">
                        <option value="">All Groups</option>
                        <?php foreach ($activity_groups as $group): ?>
                        <option value="<?= $group['group_name'] ?>" <?= $group['group_name'] == $activity_group ? 'selected' : '' ?>>
                            <?= htmlspecialchars($group['group_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <a href="activity_budget_reports.php" class="btn btn-secondary w-100">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6>Total Budget</h6>
                    <h2>Rp <?= number_format($total_budget, 0, ',', '.') ?></h2>
                </div>
            </div>
        </div>
        <?php if ($report_type == 'monthly' || $report_type == 'variance'): ?>
        <div class="col-md-4">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h6>Total Actual</h6>
                    <h2>Rp <?= number_format($total_actual, 0, ',', '.') ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-<?= $total_variance > 0 ? 'danger' : 'success' ?> text-white">
                <div class="card-body">
                    <h6>Total Variance</h6>
                    <h2>Rp <?= number_format(abs($total_variance), 0, ',', '.') ?></h2>
                    <small><?= $total_variance > 0 ? 'Over' : 'Under' ?> Budget</small>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Report Content -->
    <div class="card">
        <div class="card-header bg-light">
            <h5 class="mb-0">
                <?php
                switch ($report_type) {
                    case 'summary': echo 'Budget Summary Report'; break;
                    case 'monthly': echo 'Monthly Budget Analysis'; break;
                    case 'variance': echo 'Variance Analysis Report'; break;
                    case 'classification': echo 'Budget by Classification'; break;
                }
                ?> - <?= $year ?>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($report_data)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No data available for the selected filters.
            </div>
            <?php else: ?>
            
            <?php if ($report_type == 'summary'): ?>
            <!-- Budget Summary Report -->
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Block</th>
                            <th>Activity Group</th>
                            <th class="text-center">Plans</th>
                            <th class="text-end">Total Man-Days</th>
                            <th class="text-end">Total Budget</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($row['block_code']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($row['block_name']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($row['activity_group']) ?></td>
                            <td class="text-center"><?= $row['plan_count'] ?></td>
                            <td class="text-end"><?= number_format($row['total_man_days'], 2) ?></td>
                            <td class="text-end"><strong>Rp <?= number_format($row['total_budget'], 0, ',', '.') ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-secondary">
                        <tr>
                            <th colspan="4" class="text-end">TOTAL:</th>
                            <th class="text-end">Rp <?= number_format($total_budget, 0, ',', '.') ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <?php elseif ($report_type == 'monthly'): ?>
            <!-- Monthly Analysis Report -->
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Block</th>
                            <th>Activity Group</th>
                            <th class="text-end">Budgeted</th>
                            <th class="text-end">Actual</th>
                            <th class="text-end">Variance</th>
                            <th class="text-center">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                        <tr class="<?= abs($row['variance_percent']) > 10 ? 'table-warning' : '' ?>">
                            <td><strong><?= $months[$row['budget_month']] ?></strong></td>
                            <td><?= htmlspecialchars($row['block_code']) ?></td>
                            <td><?= htmlspecialchars($row['activity_group']) ?></td>
                            <td class="text-end">Rp <?= number_format($row['total_budgeted'], 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($row['total_actual'], 0, ',', '.') ?></td>
                            <td class="text-end <?= $row['total_variance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $row['total_variance'] > 0 ? '+' : '' ?>Rp <?= number_format($row['total_variance'], 0, ',', '.') ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= abs($row['variance_percent']) > 10 ? 'danger' : 'success' ?>">
                                    <?= number_format($row['variance_percent'], 1) ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php elseif ($report_type == 'variance'): ?>
            <!-- Variance Analysis Report -->
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Block</th>
                            <th>Activity</th>
                            <th class="text-end">Budgeted</th>
                            <th class="text-end">Actual</th>
                            <th class="text-end">Variance</th>
                            <th class="text-center">%</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                        <tr>
                            <td><?= $months[$row['budget_month']] ?></td>
                            <td><?= htmlspecialchars($row['block_code']) ?></td>
                            <td>
                                <small><?= htmlspecialchars($row['activity_name']) ?></small><br>
                                <small class="text-muted"><?= htmlspecialchars($row['activity_group']) ?></small>
                            </td>
                            <td class="text-end">Rp <?= number_format($row['budgeted_cost'], 0, ',', '.') ?></td>
                            <td class="text-end">Rp <?= number_format($row['actual_cost'], 0, ',', '.') ?></td>
                            <td class="text-end <?= $row['variance_amount'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <strong><?= $row['variance_amount'] > 0 ? '+' : '' ?>Rp <?= number_format($row['variance_amount'], 0, ',', '.') ?></strong>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= abs($row['variance_percent']) > 10 ? 'danger' : 'warning' ?>">
                                    <?= number_format($row['variance_percent'], 1) ?>%
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $row['status'] == 'Over Budget' ? 'danger' : ($row['status'] == 'Under Budget' ? 'success' : 'secondary') ?>">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php elseif ($report_type == 'classification'): ?>
            <!-- Classification Report -->
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Classification</th>
                            <th>Number of Plans</th>
                            <th>Total Man-Days</th>
                            <th>Total Budget</th>
                            <th>% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                        <tr>
                            <td>
                                <span class="badge bg-<?= $row['budget_classification'] == 'operational' ? 'primary' : ($row['budget_classification'] == 'capital' ? 'success' : 'warning') ?> fs-6">
                                    <?= ucfirst($row['budget_classification']) ?>
                                </span>
                            </td>
                            <td><?= $row['plan_count'] ?></td>
                            <td><?= number_format($row['total_man_days'], 2) ?></td>
                            <td><strong>Rp <?= number_format($row['total_budget'], 0, ',', '.') ?></strong></td>
                            <td>
                                <div class="progress" style="height: 25px;">
                                    <div class="progress-bar" role="progressbar" 
                                         style="width: <?= $total_budget > 0 ? ($row['total_budget'] / $total_budget * 100) : 0 ?>%">
                                        <?= $total_budget > 0 ? number_format($row['total_budget'] / $total_budget * 100, 1) : 0 ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-secondary">
                        <tr>
                            <th colspan="3" class="text-end">TOTAL:</th>
                            <th>Rp <?= number_format($total_budget, 0, ',', '.') ?></th>
                            <th>100%</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, .card-header, nav, .sidebar { display: none !important; }
    .card { border: none !important; }
}
</style>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
