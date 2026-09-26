<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

$page_title = "Block Costing Analysis";
require_once 'includes/header.php';

// Get filters
$year = get('year', date('Y'));
$month = get('month', date('m'));
$company_filter = get('company_id', '');
$division_filter = get('division_id', '');
$block_filter = get('block_id', '');
$report_type = get('report_type', 'summary');

// Fetch companies
$companies_stmt = $db->query("SELECT company_id, company_code, company_name FROM companies ORDER BY company_code");
$companies = $companies_stmt->fetchAll();

// Fetch divisions based on company filter
$divisions_sql = "SELECT d.division_id, d.division_code, d.division_name
                  FROM divisions d
                  INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
                  WHERE 1=1";
$div_params = [];
if ($company_filter) {
    $divisions_sql .= " AND bu.company_id = ?";
    $div_params[] = $company_filter;
}
$divisions_sql .= " ORDER BY d.division_code";
$stmt = $db->prepare($divisions_sql);
$stmt->execute($div_params);
$divisions = $stmt->fetchAll();

// Fetch blocks based on filters
$blocks_sql = "SELECT b.*, d.division_name, py.year as planting_year
               FROM blocks b
               INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
               INNER JOIN divisions d ON py.division_id = d.division_id
               INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
               WHERE 1=1";
$block_params = [];
if ($company_filter) {
    $blocks_sql .= " AND bu.company_id = ?";
    $block_params[] = $company_filter;
}
if ($division_filter) {
    $blocks_sql .= " AND d.division_id = ?";
    $block_params[] = $division_filter;
}
$blocks_sql .= " ORDER BY b.block_code";
$stmt = $db->prepare($blocks_sql);
$stmt->execute($block_params);
$blocks = $stmt->fetchAll();

// Get cost summary for the period
$cost_summary_sql = "
    SELECT 
        COUNT(DISTINCT block_id) as total_blocks,
        SUM(total_cost) as total_cost,
        SUM(labor_cost) as total_labor,
        SUM(material_cost) as total_material,
        SUM(equipment_cost) as total_equipment,
        SUM(overhead_cost) as total_overhead
    FROM vw_block_cost_summary
    WHERE cost_year = ? AND cost_month = ?
";
$summary_params = [$year, $month];

if ($company_filter) {
    $cost_summary_sql .= " AND company_id = ?";
    $summary_params[] = $company_filter;
}
if ($division_filter) {
    $cost_summary_sql .= " AND division_id = ?";
    $summary_params[] = $division_filter;
}

$stmt = $db->prepare($cost_summary_sql);
$stmt->execute($summary_params);
$cost_summary = $stmt->fetch();

// Get block costs for the period
$block_costs_sql = "
    SELECT * FROM vw_block_cost_summary
    WHERE cost_year = ? AND cost_month = ?
";
$bc_params = [$year, $month];

if ($company_filter) {
    $block_costs_sql .= " AND company_id = ?";
    $bc_params[] = $company_filter;
}
if ($division_filter) {
    $block_costs_sql .= " AND division_id = ?";
    $bc_params[] = $division_filter;
}
if ($block_filter) {
    $block_costs_sql .= " AND block_id = ?";
    $bc_params[] = $block_filter;
}

$block_costs_sql .= " ORDER BY total_cost DESC";

$stmt = $db->prepare($block_costs_sql);
$stmt->execute($bc_params);
$block_costs = $stmt->fetchAll();

// Get cost breakdown by category
$cost_by_category = $db->prepare("
    SELECT 
        cost_category,
        COUNT(*) as transaction_count,
        SUM(cost_amount) as total_cost
    FROM block_costs
    WHERE YEAR(cost_date) = ? AND MONTH(cost_date) = ?
    GROUP BY cost_category
    ORDER BY total_cost DESC
");
$cost_by_category->execute([$year, $month]);
$cost_categories = $cost_by_category->fetchAll();

// Get top cost blocks
$top_blocks = array_slice($block_costs, 0, 10);

// Calculate averages
$avg_cost_per_block = count($block_costs) > 0 ? ($cost_summary['total_cost'] ?? 0) / count($block_costs) : 0;
$total_area = array_sum(array_column($block_costs, 'area_ha'));
$avg_cost_per_ha = $total_area > 0 ? ($cost_summary['total_cost'] ?? 0) / $total_area : 0;

$months = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color: #166c82;"><i class="bi bi-calculator" style="color: #166c82;"></i> Block Costing Analysis</h1>
            <p class="text-muted">Cost tracking and profitability analysis by block</p>
        </div>
        <div class="col-auto">
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="bi bi-printer"></i> Print Report
            </button>
            <a href="block_costs_entry.php" class="btn btn-custom-bc">
                <i class="bi bi-plus-circle"></i> Add Cost Entry
            </a>
        </div>
    </div>
</div>

<!-- Filter Section -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Report Type</label>
                <select class="form-select" name="report_type">
                    <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Summary</option>
                    <option value="detailed" <?php echo $report_type == 'detailed' ? 'selected' : ''; ?>>Detailed</option>
                    <option value="comparison" <?php echo $report_type == 'comparison' ? 'selected' : ''; ?>>Comparison</option>
                    <option value="trend" <?php echo $report_type == 'trend' ? 'selected' : ''; ?>>Trend Analysis</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Year</label>
                <select class="form-select" name="year">
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Month</label>
                <select class="form-select" name="month">
                    <?php foreach ($months as $m => $name): ?>
                        <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Company</label>
                <select class="form-select" name="company_id" onchange="this.form.submit()">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $comp): ?>
                        <option value="<?php echo $comp['company_id']; ?>" <?php echo $company_filter == $comp['company_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($comp['company_code']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Division</label>
                <select class="form-select" name="division_id">
                    <option value="">All Divisions</option>
                    <?php foreach ($divisions as $div): ?>
                        <option value="<?php echo $div['division_id']; ?>" <?php echo $division_filter == $div['division_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($div['division_code']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Block</label>
                <select class="form-select" name="block_id">
                    <option value="">All Blocks</option>
                    <?php foreach ($blocks as $blk): ?>
                        <option value="<?php echo $blk['block_id']; ?>" <?php echo $block_filter == $blk['block_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($blk['block_code']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <div>
                    <button type="submit" class="btn btn-custom-bc w-100">
                        <i class="bi bi-search"></i> Apply
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4><?php echo count($block_costs); ?></h4>
                <small>Blocks with Costs</small>
                <div class="text-muted mt-1">
                    <?php echo $months[$month]; ?> <?php echo $year; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4>Rp <?php echo format_number($cost_summary['total_cost'] ?? 0, 0); ?></h4>
                <small>Total Costs</small>
                <div class="text-muted mt-1">Period Total</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4>Rp <?php echo format_number($avg_cost_per_block, 0); ?></h4>
                <small>Avg Cost/Block</small>
                <div class="text-muted mt-1">Average</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4>Rp <?php echo format_number($avg_cost_per_ha, 0); ?></h4>
                <small>Avg Cost/Ha</small>
                <div class="text-muted mt-1">Per Hectare</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4>Rp <?php echo format_number($cost_summary['total_labor'] ?? 0, 0); ?></h4>
                <small>Labor Costs</small>
                <div class="text-muted mt-1">
                    <?php echo $cost_summary['total_cost'] > 0 ? format_number(($cost_summary['total_labor'] ?? 0) / $cost_summary['total_cost'] * 100, 1) : 0; ?>%
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h4>Rp <?php echo format_number($cost_summary['total_material'] ?? 0, 0); ?></h4>
                <small>Material Costs</small>
                <div class="text-muted mt-1">
                    <?php echo $cost_summary['total_cost'] > 0 ? format_number(($cost_summary['total_material'] ?? 0) / $cost_summary['total_cost'] * 100, 1) : 0; ?>%
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cost Breakdown -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <i class="bi bi-pie-chart"></i> Cost Breakdown by Category
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th class="text-center">Transactions</th>
                                <th class="text-end">Total Cost</th>
                                <th class="text-end">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_cat_cost = array_sum(array_column($cost_categories, 'total_cost'));
                            foreach ($cost_categories as $cat): 
                                $pct = $total_cat_cost > 0 ? ($cat['total_cost'] / $total_cat_cost * 100) : 0;
                            ?>
                                <tr>
                                    <td><strong><?php echo ucfirst(str_replace('_', ' ', $cat['cost_category'])); ?></strong></td>
                                    <td class="text-center"><?php echo $cat['transaction_count']; ?></td>
                                    <td class="text-end">Rp <?php echo format_number($cat['total_cost'], 0); ?></td>
                                    <td class="text-end"><?php echo format_number($pct, 1); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th>TOTAL</th>
                                <th class="text-center"><?php echo array_sum(array_column($cost_categories, 'transaction_count')); ?></th>
                                <th class="text-end">Rp <?php echo format_number($total_cat_cost, 0); ?></th>
                                <th class="text-end">100.0%</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="mt-3">
                    <canvas id="costCategoryChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-white" style="background-color: #166c82;">
                <i class="bi bi-trophy"></i> Top 10 Highest Cost Blocks
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Block</th>
                                <th class="text-end">Area (Ha)</th>
                                <th class="text-end">Total Cost</th>
                                <th class="text-end">Cost/Ha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_blocks as $tb): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($tb['block_code']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($tb['division_name']); ?></small>
                                    </td>
                                    <td class="text-end"><?php echo format_number($tb['area_ha'], 2); ?></td>
                                    <td class="text-end">Rp <?php echo format_number($tb['total_cost'], 0); ?></td>
                                    <td class="text-end">Rp <?php echo format_number($tb['cost_per_ha'], 0); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Block Costs Table -->
<?php if ($report_type == 'summary' || $report_type == 'detailed'): ?>
<div class="card">
    <div class="card-header text-white" style="background-color: #166c82;">
        <i class="bi bi-list-ul"></i> Block Costs Detail (<?php echo count($block_costs); ?> blocks)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Block Code</th>
                        <th>Division</th>
                        <th class="text-end">Area (Ha)</th>
                        <th class="text-end">Labor</th>
                        <th class="text-end">Material</th>
                        <th class="text-end">Equipment</th>
                        <th class="text-end">Overhead</th>
                        <th class="text-end">Total Cost</th>
                        <th class="text-end">Cost/Ha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($block_costs)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">No cost data for selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($block_costs as $bc): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($bc['block_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($bc['division_name']); ?></td>
                                <td class="text-end"><?php echo format_number($bc['area_ha'], 2); ?></td>
                                <td class="text-end">Rp <?php echo format_number($bc['labor_cost'], 0); ?></td>
                                <td class="text-end">Rp <?php echo format_number($bc['material_cost'], 0); ?></td>
                                <td class="text-end">Rp <?php echo format_number($bc['equipment_cost'], 0); ?></td>
                                <td class="text-end">Rp <?php echo format_number($bc['overhead_cost'], 0); ?></td>
                                <td class="text-end"><strong>Rp <?php echo format_number($bc['total_cost'], 0); ?></strong></td>
                                <td class="text-end">Rp <?php echo format_number($bc['cost_per_ha'], 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="2">TOTAL</th>
                        <th class="text-end"><?php echo format_number($total_area, 2); ?></th>
                        <th class="text-end">Rp <?php echo format_number($cost_summary['total_labor'] ?? 0, 0); ?></th>
                        <th class="text-end">Rp <?php echo format_number($cost_summary['total_material'] ?? 0, 0); ?></th>
                        <th class="text-end">Rp <?php echo format_number($cost_summary['total_equipment'] ?? 0, 0); ?></th>
                        <th class="text-end">Rp <?php echo format_number($cost_summary['total_overhead'] ?? 0, 0); ?></th>
                        <th class="text-end"><strong>Rp <?php echo format_number($cost_summary['total_cost'] ?? 0, 0); ?></strong></th>
                        <th class="text-end">Rp <?php echo format_number($avg_cost_per_ha, 0); ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Print Styles -->
<style>
@media print {
    .page-header .btn,
    .card-body form,
    .no-print {
        display: none !important;
    }
    .card {
        page-break-inside: avoid;
    }
}

/* Custom button styles for Block Costing */
.btn-custom-bc {
    background-color: #166c82;
    border-color: #166c82;
    color: white;
}

.btn-custom-bc:hover {
    background-color: #1a7d9a;
    border-color: #1a7d9a;
    color: white;
}

.btn-custom-bc:focus,
.btn-custom-bc:active {
    background-color: #145a6d;
    border-color: #145a6d;
    color: white;
}
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Cost Category Pie Chart
    const ctx = document.getElementById('costCategoryChart');
    if (ctx && <?php echo !empty($cost_categories) ? 'true' : 'false'; ?>) {
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_map(function($c) { return ucfirst(str_replace('_', ' ', $c['cost_category'])); }, $cost_categories)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($cost_categories, 'total_cost')); ?>,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.8)',
                        'rgba(54, 162, 235, 0.8)',
                        'rgba(255, 206, 86, 0.8)',
                        'rgba(75, 192, 192, 0.8)',
                        'rgba(153, 102, 255, 0.8)',
                        'rgba(255, 159, 64, 0.8)',
                        'rgba(199, 199, 199, 0.8)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    title: {
                        display: true,
                        text: 'Cost Distribution by Category'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += 'Rp ' + context.parsed.toLocaleString();
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
