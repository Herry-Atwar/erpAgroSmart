<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$pageTitle = 'Budget Variance Report';
$db = getDB();

// Get filters
$year = get('year', date('Y'));
$month_from = get('month_from', 1);
$month_to = get('month_to', date('n'));

// Fetch summary data
$stmt = $db->prepare("
    SELECT 
        abm.budget_month,
        SUM(abm.planned_cost) as total_planned,
        SUM(abm.actual_cost) as total_actual,
        SUM(abm.variance_cost) as total_variance,
        COUNT(*) as activity_count,
        SUM(CASE WHEN abm.actual_cost > abm.planned_cost * 1.1 THEN 1 ELSE 0 END) as over_budget_count,
        SUM(CASE WHEN abm.actual_cost < abm.planned_cost * 0.9 THEN 1 ELSE 0 END) as under_budget_count,
        SUM(CASE WHEN abm.actual_cost BETWEEN abm.planned_cost * 0.9 AND abm.planned_cost * 1.1 THEN 1 ELSE 0 END) as on_track_count
    FROM activity_budget_monthly abm
    WHERE abm.budget_year = ?
      AND abm.budget_month BETWEEN ? AND ?
      AND abm.actual_cost > 0
    GROUP BY abm.budget_month
    ORDER BY abm.budget_month
");
$stmt->execute([$year, $month_from, $month_to]);
$monthly_data = $stmt->fetchAll();

// Fetch detailed variance data
$stmt = $db->prepare("
    SELECT 
        abm.budget_month,
        a.activity_name,
        b.block_code,
        d.division_name,
        abm.planned_cost,
        abm.actual_cost,
        abm.variance_cost,
        abm.variance_percentage,
        CASE 
            WHEN abm.actual_cost > abm.planned_cost * 1.1 THEN 'Over Budget'
            WHEN abm.actual_cost < abm.planned_cost * 0.9 THEN 'Under Budget'
            ELSE 'On Track'
        END as status
    FROM activity_budget_monthly abm
    JOIN activity_budget_plans abp ON abm.plan_id = abp.plan_id
    JOIN activities a ON abp.activity_id = a.id
    JOIN blocks b ON abp.block_id = b.block_id
    JOIN divisions d ON b.division_id = d.division_id
    WHERE abm.budget_year = ?
      AND abm.budget_month BETWEEN ? AND ?
      AND abm.actual_cost > 0
    ORDER BY ABS(abm.variance_percentage) DESC
    LIMIT 50
");
$stmt->execute([$year, $month_from, $month_to]);
$details = $stmt->fetchAll();

// Calculate totals
$grand_total = [
    'planned' => array_sum(array_column($monthly_data, 'total_planned')),
    'actual' => array_sum(array_column($monthly_data, 'total_actual')),
    'variance' => array_sum(array_column($monthly_data, 'total_variance'))
];
$grand_total['variance_pct'] = $grand_total['planned'] > 0 
    ? ($grand_total['variance'] / $grand_total['planned'] * 100) 
    : 0;

include 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <h2><i class="bi bi-graph-up-arrow"></i> Budget Variance Report</h2>
    <p class="text-muted">Actual vs Budget Analysis - Year <?= $year ?></p>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label>Year</label>
                    <select name="year" class="form-select">
                        <?php for ($y = date('Y')+1; $y >= date('Y')-5; $y--): ?>
                            <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>From Month</label>
                    <select name="month_from" class="form-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m == $month_from ? 'selected' : '' ?>>
                                <?= date('F', mktime(0,0,0,$m,1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>To Month</label>
                    <select name="month_to" class="form-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m == $month_to ? 'selected' : '' ?>>
                                <?= date('F', mktime(0,0,0,$m,1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6>Total Planned</h6>
                    <h3>Rp <?= number_format($grand_total['planned']) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h6>Total Actual</h6>
                    <h3>Rp <?= number_format($grand_total['actual']) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card <?= $grand_total['variance'] >= 0 ? 'bg-success' : 'bg-danger' ?> text-white">
                <div class="card-body">
                    <h6>Total Variance</h6>
                    <h3>Rp <?= number_format($grand_total['variance']) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card <?= abs($grand_total['variance_pct']) <= 5 ? 'bg-success' : 'bg-warning' ?> text-white">
                <div class="card-body">
                    <h6>Variance %</h6>
                    <h3><?= number_format($grand_total['variance_pct'], 1) ?>%</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Summary -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Monthly Summary</h5>
        </div>
        <div class="card-body">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th class="text-end">Planned</th>
                        <th class="text-end">Actual</th>
                        <th class="text-end">Variance</th>
                        <th class="text-end">%</th>
                        <th class="text-center">Over</th>
                        <th class="text-center">Under</th>
                        <th class="text-center">On Track</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthly_data as $row): 
                        $var_pct = $row['total_planned'] > 0 ? ($row['total_variance'] / $row['total_planned'] * 100) : 0;
                    ?>
                        <tr>
                            <td><?= date('F', mktime(0,0,0,$row['budget_month'],1)) ?></td>
                            <td class="text-end">Rp <?= number_format($row['total_planned']) ?></td>
                            <td class="text-end">Rp <?= number_format($row['total_actual']) ?></td>
                            <td class="text-end <?= $row['total_variance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                Rp <?= number_format($row['total_variance']) ?>
                            </td>
                            <td class="text-end <?= abs($var_pct) <= 5 ? 'text-success' : 'text-warning' ?>">
                                <?= number_format($var_pct, 1) ?>%
                            </td>
                            <td class="text-center"><span class="badge bg-danger"><?= $row['over_budget_count'] ?></span></td>
                            <td class="text-center"><span class="badge bg-success"><?= $row['under_budget_count'] ?></span></td>
                            <td class="text-center"><span class="badge bg-info"><?= $row['on_track_count'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Top Variances -->
    <div class="card">
        <div class="card-header bg-warning">
            <h5 class="mb-0">Top Variances (by %)</h5>
        </div>
        <div class="card-body">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Activity</th>
                        <th>Block</th>
                        <th>Division</th>
                        <th class="text-end">Planned</th>
                        <th class="text-end">Actual</th>
                        <th class="text-end">Variance</th>
                        <th class="text-end">%</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($details as $row): ?>
                        <tr>
                            <td><?= date('M', mktime(0,0,0,$row['budget_month'],1)) ?></td>
                            <td><?= htmlspecialchars($row['activity_name']) ?></td>
                            <td><?= htmlspecialchars($row['block_code']) ?></td>
                            <td><?= htmlspecialchars($row['division_name']) ?></td>
                            <td class="text-end">Rp <?= number_format($row['planned_cost']) ?></td>
                            <td class="text-end">Rp <?= number_format($row['actual_cost']) ?></td>
                            <td class="text-end <?= $row['variance_cost'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                Rp <?= number_format($row['variance_cost']) ?>
                            </td>
                            <td class="text-end <?= abs($row['variance_percentage']) <= 10 ? 'text-success' : 'text-warning' ?>">
                                <?= number_format($row['variance_percentage'], 1) ?>%
                            </td>
                            <td>
                                <?php
                                $badge_color = $row['status'] == 'Over Budget' ? 'danger' : 
                                              ($row['status'] == 'Under Budget' ? 'success' : 'info');
                                ?>
                                <span class="badge bg-<?= $badge_color ?>"><?= $row['status'] ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

// Made with Bob
