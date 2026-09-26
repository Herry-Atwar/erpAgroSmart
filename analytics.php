<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Analytics Dashboard";
require_once 'includes/header.php';

// Get filters
$year = get('year', date('Y'));
$company_filter = get('company_id', '');
$view_type = get('view', 'overview');

// Fetch companies
$companies = $db->query("SELECT * FROM companies ORDER BY company_code")->fetchAll();

// 1. PRODUCTION ANALYTICS
$production_sql = "
    SELECT
        EXTRACT(MONTH FROM hr.harvest_date) AS month,
        SUM(hr.actual_quantity_kg)          AS total_ffb_kg,
        COUNT(DISTINCT hr.block_id)         AS active_blocks,
        COUNT(*)                            AS harvest_count,
        AVG(hr.average_bunch_weight)        AS avg_bunch_weight
    FROM harvest_realizations hr
    INNER JOIN blocks b        ON hr.block_id           = b.block_id
    INNER JOIN planting_years py ON b.planting_year_id  = py.planting_year_id
    INNER JOIN divisions d     ON py.division_id        = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id  = bu.business_unit_id
    INNER JOIN companies c     ON bu.company_id         = c.company_id
    WHERE EXTRACT(YEAR FROM hr.harvest_date) = ?
";
$prod_params = [$year];
if ($company_filter) { $production_sql .= " AND c.company_id = ?"; $prod_params[] = $company_filter; }
$production_sql .= " GROUP BY EXTRACT(MONTH FROM hr.harvest_date) ORDER BY month";
$prod_stmt = $db->prepare($production_sql);
$prod_stmt->execute($prod_params);
$production_data = $prod_stmt->fetchAll();

// 2. COST ANALYTICS (from journal_entry_lines — no block_costs table)
$cost_sql = "
    SELECT
        COALESCE(jel.cost_type, 'other') AS cost_category,
        SUM(jel.debit_amount)            AS total_cost,
        COUNT(*)                         AS cost_entries
    FROM journal_entries je
    INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    INNER JOIN blocks b        ON jel.block_id          = b.block_id
    INNER JOIN planting_years py ON b.planting_year_id  = py.planting_year_id
    INNER JOIN divisions d     ON py.division_id        = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id  = bu.business_unit_id
    INNER JOIN companies c     ON bu.company_id         = c.company_id
    WHERE EXTRACT(YEAR FROM je.entry_date) = ?
      AND jel.debit_amount > 0
";
$cost_params = [$year];
if ($company_filter) { $cost_sql .= " AND c.company_id = ?"; $cost_params[] = $company_filter; }
$cost_sql .= " GROUP BY COALESCE(jel.cost_type, 'other') ORDER BY total_cost DESC";
$cost_stmt = $db->prepare($cost_sql);
$cost_stmt->execute($cost_params);
$cost_data = $cost_stmt->fetchAll();

// 3. SALES ANALYTICS (from sale_order + sale_order_line — no sales table)
$sales_sql = "
    SELECT
        EXTRACT(MONTH FROM so.date_order) AS month,
        sol.product_type,
        SUM(sol.product_uom_qty)          AS total_quantity,
        SUM(so.amount_total)              AS total_revenue,
        COUNT(DISTINCT so.id)             AS transaction_count
    FROM sale_order so
    JOIN sale_order_line sol ON sol.order_id = so.id
    WHERE EXTRACT(YEAR FROM so.date_order) = ?
";
$sales_params = [$year];
if ($company_filter) { $sales_sql .= " AND so.company_id = ?"; $sales_params[] = $company_filter; }
$sales_sql .= " GROUP BY EXTRACT(MONTH FROM so.date_order), sol.product_type ORDER BY month, product_type";
$sales_stmt = $db->prepare($sales_sql);
$sales_stmt->execute($sales_params);
$sales_data = $sales_stmt->fetchAll();

// 4. BUDGET ANALYTICS (unified over activity_budget_plans + capital_budget_items — no budgets table)
$budget_params = [$year, $year];
if ($company_filter) { $budget_params[] = $company_filter; $budget_params[] = $company_filter; }
$cap_company = $company_filter ? " AND cbi.company_id = ?" : "";
$budget_sql = "
    WITH unified AS (
        SELECT 'operational' AS budget_type,
               abp.total_annual_cost AS planned_amount,
               COALESCE((SELECT SUM(abm.actual_cost) FROM activity_budget_monthly abm WHERE abm.plan_id = abp.plan_id), 0) AS actual_amount
        FROM activity_budget_plans abp
        WHERE abp.budget_year = ?
        UNION ALL
        SELECT 'capital' AS budget_type,
               cbi.total_cost AS planned_amount,
               CASE WHEN cbi.status = 'approved' THEN cbi.total_cost ELSE 0 END AS actual_amount
        FROM capital_budget_items cbi
        WHERE cbi.budget_year = ? $cap_company
    )
    SELECT budget_type,
           SUM(planned_amount) AS total_planned,
           SUM(actual_amount)  AS total_actual,
           SUM(planned_amount - actual_amount) AS total_variance
    FROM unified
    GROUP BY budget_type
";
$budget_stmt = $db->prepare($budget_sql);
$budget_stmt->execute($budget_params);
$budget_data = $budget_stmt->fetchAll();

// 5. BLOCK STATUS SUMMARY
$block_status_sql = "
    SELECT
        b.status,
        COUNT(*)     AS block_count,
        SUM(b.area)  AS total_area,
        AVG(b.plant_age) AS avg_age
    FROM blocks b
    INNER JOIN planting_years py ON b.planting_year_id  = py.planting_year_id
    INNER JOIN divisions d     ON py.division_id        = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id  = bu.business_unit_id
    INNER JOIN companies c     ON bu.company_id         = c.company_id
    WHERE 1=1
";
$block_params = [];
if ($company_filter) { $block_status_sql .= " AND c.company_id = ?"; $block_params[] = $company_filter; }
$block_status_sql .= " GROUP BY b.status";
$block_stmt = $db->prepare($block_status_sql);
$block_stmt->execute($block_params);
$block_status = $block_stmt->fetchAll();

// Calculate KPIs
$total_production = array_sum(array_column($production_data, 'total_ffb_kg'));
$total_costs = array_sum(array_column($cost_data, 'total_cost'));
$total_revenue = array_sum(array_column($sales_data, 'total_revenue'));
$total_profit = $total_revenue - $total_costs;
$profit_margin = $total_revenue > 0 ? ($total_profit / $total_revenue * 100) : 0;

// Prepare chart data
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$production_by_month = array_fill(1, 12, 0);
foreach ($production_data as $row) {
    $production_by_month[$row['month']] = $row['total_ffb_kg'];
}

$revenue_by_month = array_fill(1, 12, 0);
foreach ($sales_data as $row) {
    if (!isset($revenue_by_month[$row['month']])) {
        $revenue_by_month[$row['month']] = 0;
    }
    $revenue_by_month[$row['month']] += $row['total_revenue'];
}
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-graph-up"></i> Analytics Dashboard - <?= $year ?></h5>
                </div>
                <div class="card-body">
                    <!-- Filters -->
                    <form method="GET" class="row g-3 mb-4">
                        <div class="col-md-2">
                            <label class="form-label">Year</label>
                            <select name="year" class="form-select" onchange="this.form.submit()">
                                <?php for ($y = date('Y') - 3; $y <= date('Y'); $y++): ?>
                                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Company</label>
                            <select name="company_id" class="form-select" onchange="this.form.submit()">
                                <option value="">All Companies</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= $c['company_id'] ?>" <?= $c['company_id'] == $company_filter ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['company_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">View</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="view" id="view_overview" value="overview" 
                                    <?= $view_type == 'overview' ? 'checked' : '' ?> onchange="this.form.submit()">
                                <label class="btn btn-outline-primary" for="view_overview">Overview</label>
                                
                                <input type="radio" class="btn-check" name="view" id="view_production" value="production" 
                                    <?= $view_type == 'production' ? 'checked' : '' ?> onchange="this.form.submit()">
                                <label class="btn btn-outline-primary" for="view_production">Production</label>
                                
                                <input type="radio" class="btn-check" name="view" id="view_financial" value="financial" 
                                    <?= $view_type == 'financial' ? 'checked' : '' ?> onchange="this.form.submit()">
                                <label class="btn btn-outline-primary" for="view_financial">Financial</label>
                            </div>
                        </div>
                    </form>

                    <!-- KPI Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card bg-primary text-white">
                                <div class="card-body">
                                    <h6>Total Production</h6>
                                    <h3><?= number_format($total_production/1000, 2) ?> MT</h3>
                                    <small><?= number_format($total_production, 0) ?> kg FFB</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-success text-white">
                                <div class="card-body">
                                    <h6>Total Revenue</h6>
                                    <h3>Rp <?= number_format($total_revenue/1000000, 1) ?>M</h3>
                                    <small>Rp <?= number_format($total_revenue, 0, ',', '.') ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-warning text-white">
                                <div class="card-body">
                                    <h6>Total Costs</h6>
                                    <h3>Rp <?= number_format($total_costs/1000000, 1) ?>M</h3>
                                    <small>Rp <?= number_format($total_costs, 0, ',', '.') ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-<?= $total_profit >= 0 ? 'success' : 'danger' ?> text-white">
                                <div class="card-body">
                                    <h6>Net Profit</h6>
                                    <h3>Rp <?= number_format($total_profit/1000000, 1) ?>M</h3>
                                    <small>Margin: <?= number_format($profit_margin, 1) ?>%</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($view_type == 'overview' || $view_type == 'production'): ?>
                    <!-- Production Analytics -->
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Monthly Production Trend</h6>
                                </div>
                                <div class="card-body">
                                    <div id="productionChart" style="height:260px;width:100%"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Block Status Distribution</h6>
                                </div>
                                <div class="card-body">
                                    <div id="blockStatusChart" style="height:300px;width:100%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Production Details -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">Production Details by Month</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Month</th>
                                            <th class="text-end">FFB (kg)</th>
                                            <th class="text-end">FFB (MT)</th>
                                            <th class="text-end">Harvests</th>
                                            <th class="text-end">Active Blocks</th>
                                            <th class="text-end">Avg Bunch Weight</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($production_data as $row): ?>
                                        <tr>
                                            <td><?= $months[$row['month']-1] ?></td>
                                            <td class="text-end"><?= number_format($row['total_ffb_kg'], 0) ?></td>
                                            <td class="text-end"><?= number_format($row['total_ffb_kg']/1000, 2) ?></td>
                                            <td class="text-end"><?= $row['harvest_count'] ?></td>
                                            <td class="text-end"><?= $row['active_blocks'] ?></td>
                                            <td class="text-end"><?= number_format($row['avg_bunch_weight'], 2) ?> kg</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($view_type == 'overview' || $view_type == 'financial'): ?>
                    <!-- Financial Analytics -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Cost Breakdown by Category</h6>
                                </div>
                                <div class="card-body">
                                    <div id="costChart" style="height:300px;width:100%"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Budget vs Actual</h6>
                                </div>
                                <div class="card-body">
                                    <div id="budgetChart" style="height:300px;width:100%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Revenue Trend -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">Monthly Revenue Trend</h6>
                        </div>
                        <div class="card-body">
                            <div id="revenueChart" style="height:260px;width:100%"></div>
                        </div>
                    </div>

                    <!-- Cost Details -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">Cost Analysis</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Category</th>
                                            <th class="text-end">Total Cost</th>
                                            <th class="text-end">Entries</th>
                                            <th class="text-end">% of Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cost_data as $row): ?>
                                        <tr>
                                            <td><?= ucfirst($row['cost_category']) ?></td>
                                            <td class="text-end">Rp <?= number_format($row['total_cost'], 0, ',', '.') ?></td>
                                            <td class="text-end"><?= $row['cost_entries'] ?></td>
                                            <td class="text-end"><?= number_format(($row['total_cost']/$total_costs)*100, 1) ?>%</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ECharts + ECharts-GL (local copies) -->
<script src="js/echarts.min.js"></script>
<script src="js/echarts-gl.min.js"></script>

<!-- 2D / 3D toggle toolbar -->
<style>
.chart-toolbar { display:flex; align-items:center; gap:8px; margin:18px 0 10px; }
.chart-toolbar span { font-size:12px; color:#555; font-weight:500; }
.toggle-group { display:flex; border:1px solid #d0d7de; border-radius:5px; overflow:hidden; }
.toggle-btn   { padding:5px 16px; font-size:12px; font-weight:500; cursor:pointer;
                background:#fff; color:#555; border:none; border-right:1px solid #d0d7de; }
.toggle-btn:last-child { border-right:none; }
.toggle-btn.active { background:#2e7d32; color:#fff; }
.toggle-btn:hover:not(.active) { background:#f3f4f6; }
</style>

<div class="chart-toolbar">
    <span>Chart View:</span>
    <div class="toggle-group">
        <button class="toggle-btn active" id="btn2d" onclick="setChartMode('2d')">2D</button>
        <button class="toggle-btn"        id="btn3d" onclick="setChartMode('3d')">3D</button>
    </div>
    <span id="modeHint" style="font-size:11px;color:#777;">Hover for tooltips</span>
</div>

<script>
const pal = ['#2e7d32','#558b2f','#8bc34a','#1565c0','#7c5cd8','#e65100','#b71c1c','#0277bd','#558b2f','#4a148c'];

// ── shared data ────────────────────────────────────────────────────────────
const prodMonths    = <?= json_encode($months) ?>;
const prodMT        = <?= json_encode(array_map(function($v){ return round($v/1000,2); }, array_values($production_by_month))) ?>;
const revMillion    = <?= json_encode(array_map(function($v){ return round($v/1000000,2); }, array_values($revenue_by_month))) ?>;
const blockLabels   = <?= json_encode(array_column($block_status, 'status')) ?>;
const blockCounts   = <?= json_encode(array_map('intval', array_column($block_status, 'block_count'))) ?>;
const costLabels    = <?= json_encode(array_column($cost_data, 'cost_category')) ?>;
const costValues    = <?= json_encode(array_map('floatval', array_column($cost_data, 'total_cost'))) ?>;
const budgetTypes   = <?= json_encode(array_column($budget_data, 'budget_type')) ?>;
const budgetPlan    = <?= json_encode(array_map('floatval', array_column($budget_data, 'total_planned'))) ?>;
const budgetActual  = <?= json_encode(array_map('floatval', array_column($budget_data, 'total_actual'))) ?>;

// ── option builders ────────────────────────────────────────────────────────

// 1. Production Trend — line 2D / line 3D
function optProd2d() {
    return {
        backgroundColor:'#fff',
        tooltip:{ trigger:'axis', axisPointer:{ type:'cross' } },
        legend:{ data:['FFB Production (MT)'], bottom:0, textStyle:{ fontSize:11 } },
        grid:{ left:60, right:20, top:20, bottom:50 },
        xAxis:{ type:'category', data:prodMonths, axisLabel:{ fontSize:10 } },
        yAxis:{ type:'value', name:'MT', nameTextStyle:{ fontSize:10 },
                axisLabel:{ fontSize:10, formatter: v => v.toLocaleString() } },
        series:[{ name:'FFB Production (MT)', type:'line', data:prodMT,
                  smooth:true, symbol:'circle', symbolSize:5,
                  lineStyle:{ color:'#2e7d32', width:2 },
                  itemStyle:{ color:'#2e7d32' },
                  areaStyle:{ color:{ type:'linear',x:0,y:0,x2:0,y2:1,
                    colorStops:[{offset:0,color:'rgba(46,125,50,0.28)'},{offset:1,color:'rgba(46,125,50,0.02)'}] } } }]
    };
}
function optProd3d() {
    const data3d = prodMT.map((v,i) => [i, 0, v]);
    const maxV   = Math.max(...prodMT, 1);
    return {
        backgroundColor:'#fff', tooltip:{},
        visualMap:{ show:false, min:0, max:maxV, inRange:{ color:pal } },
        xAxis3D:{ type:'category', data:prodMonths, axisLabel:{ fontSize:10 } },
        yAxis3D:{ type:'category', data:[''], show:false },
        zAxis3D:{ type:'value', name:'MT', axisLabel:{ fontSize:10, formatter: v => v.toLocaleString() } },
        grid3D:{ boxWidth:220, boxDepth:30, boxHeight:100,
                 viewControl:{ alpha:22, beta:28, rotateSensitivity:2, zoomSensitivity:1.2 },
                 light:{ main:{ intensity:1.2 }, ambient:{ intensity:0.4 } } },
        series:[{ type:'bar3D', data:data3d, shading:'lambert', itemStyle:{ opacity:0.9 } }]
    };
}

// 2. Block Status — doughnut 2D / bar3D 3D
function optBlock2d() {
    const pieData = blockLabels.map((l,i) => ({ name:l, value:blockCounts[i], itemStyle:{ color:pal[i%pal.length] } }));
    return {
        backgroundColor:'#fff',
        tooltip:{ formatter: p => p.name + ': ' + p.value + ' blocks (' + p.percent + '%)' },
        legend:{ orient:'vertical', right:10, top:'center', textStyle:{ fontSize:10 } },
        series:[{ type:'pie', radius:['35%','65%'], center:['42%','50%'], data:pieData,
                  itemStyle:{ borderColor:'#fff', borderWidth:2, borderRadius:4 },
                  label:{ fontSize:10, formatter:'{b}\n{d}%' },
                  emphasis:{ scaleSize:8 } }]
    };
}
function optBlock3d() {
    const pieData = blockLabels.map((l,i) => ({ name:l, value:blockCounts[i], itemStyle:{ color:pal[i%pal.length] } }));
    return {
        backgroundColor:'#fff',
        tooltip:{ formatter: p => p.name + ': ' + p.value + ' blocks (' + p.percent + '%)' },
        legend:{ orient:'vertical', right:10, top:'center', textStyle:{ fontSize:10 } },
        series:[{ type:'pie', radius:['0%','65%'], center:['42%','50%'], data:pieData,
                  itemStyle:{ borderRadius:4, borderColor:'#fff', borderWidth:2,
                               shadowBlur:24, shadowColor:'rgba(0,0,0,0.35)',
                               shadowOffsetX:6, shadowOffsetY:8 },
                  label:{ fontSize:10, formatter:'{b}\n{d}%' },
                  emphasis:{ itemStyle:{ shadowBlur:34, shadowColor:'rgba(0,0,0,0.55)' }, scaleSize:10 } }]
    };
}

// 3. Cost Breakdown — pie 2D / bar3D 3D
function optCost2d() {
    const pieData = costLabels.map((l,i) => ({ name:l, value:costValues[i], itemStyle:{ color:pal[i%pal.length] } }));
    return {
        backgroundColor:'#fff',
        tooltip:{ formatter: p => p.name + '<br/>Rp ' + p.value.toLocaleString('id-ID') + ' (' + p.percent + '%)' },
        legend:{ orient:'vertical', right:5, top:'center', textStyle:{ fontSize:10 } },
        series:[{ type:'pie', radius:'62%', center:['40%','50%'], data:pieData,
                  itemStyle:{ borderColor:'#fff', borderWidth:2, borderRadius:4 },
                  label:{ fontSize:9, formatter:'{b}\n{d}%' },
                  emphasis:{ scaleSize:8 } }]
    };
}
function optCost3d() {
    const data3d = costValues.map((v,i) => [i, 0, v]);
    const maxV   = Math.max(...costValues, 1);
    return {
        backgroundColor:'#fff', tooltip:{},
        visualMap:{ show:false, min:0, max:maxV, inRange:{ color:pal } },
        xAxis3D:{ type:'category', data:costLabels, axisLabel:{ fontSize:9 } },
        yAxis3D:{ type:'category', data:[''], show:false },
        zAxis3D:{ type:'value', axisLabel:{ fontSize:9, formatter: v => (v/1e6).toFixed(1)+'M' } },
        grid3D:{ boxWidth:200, boxDepth:30, boxHeight:100,
                 viewControl:{ alpha:22, beta:28, rotateSensitivity:2, zoomSensitivity:1.2 },
                 light:{ main:{ intensity:1.2 }, ambient:{ intensity:0.4 } } },
        series:[{ type:'bar3D', data:data3d, shading:'lambert', itemStyle:{ opacity:0.9 } }]
    };
}

// 4. Budget vs Actual — grouped bar 2D / bar3D 3D
function optBudget2d() {
    return {
        backgroundColor:'#fff',
        tooltip:{ trigger:'axis', axisPointer:{ type:'shadow' } },
        legend:{ data:['Planned','Actual'], bottom:0, textStyle:{ fontSize:11 } },
        grid:{ left:70, right:16, top:16, bottom:50 },
        xAxis:{ type:'category', data:budgetTypes, axisLabel:{ fontSize:10 } },
        yAxis:{ type:'value', axisLabel:{ fontSize:10, formatter: v => (v/1e6).toFixed(1)+'M' } },
        series:[
            { name:'Planned', type:'bar', data:budgetPlan,
              itemStyle:{ color:'#1565c0', borderRadius:[3,3,0,0] }, barMaxWidth:50 },
            { name:'Actual',  type:'bar', data:budgetActual,
              itemStyle:{ color:'#e65100', borderRadius:[3,3,0,0] }, barMaxWidth:50 }
        ]
    };
}
function optBudget3d() {
    const d1 = budgetPlan.map((v,i)   => [0, i, v]);
    const d2 = budgetActual.map((v,i) => [1, i, v]);
    return {
        backgroundColor:'#fff', tooltip:{},
        legend:{ data:['Planned','Actual'], bottom:0, textStyle:{ fontSize:10 } },
        xAxis3D:{ type:'category', data:['Planned','Actual'], axisLabel:{ fontSize:10 } },
        yAxis3D:{ type:'category', data:budgetTypes, axisLabel:{ fontSize:10 } },
        zAxis3D:{ type:'value', axisLabel:{ fontSize:10, formatter: v => (v/1e6).toFixed(1)+'M' } },
        grid3D:{ boxWidth:60, boxDepth:120, boxHeight:100,
                 viewControl:{ alpha:20, beta:-25, rotateSensitivity:2, zoomSensitivity:1.2 },
                 light:{ main:{ intensity:1.2 }, ambient:{ intensity:0.4 } } },
        series:[
            { name:'Planned', type:'bar3D', data:d1, shading:'lambert', itemStyle:{ color:'#1565c0', opacity:0.9 } },
            { name:'Actual',  type:'bar3D', data:d2, shading:'lambert', itemStyle:{ color:'#e65100', opacity:0.9 } }
        ]
    };
}

// 5. Revenue Trend — bar 2D / bar3D 3D
function optRev2d() {
    return {
        backgroundColor:'#fff',
        tooltip:{ trigger:'axis', axisPointer:{ type:'shadow' } },
        grid:{ left:60, right:16, top:16, bottom:50 },
        xAxis:{ type:'category', data:prodMonths, axisLabel:{ fontSize:10 } },
        yAxis:{ type:'value', name:'M IDR', nameTextStyle:{ fontSize:10 },
                axisLabel:{ fontSize:10, formatter: v => v.toLocaleString() } },
        series:[{ type:'bar', data:revMillion.map((v,i) => ({ value:v, itemStyle:{ color:pal[i%pal.length] } })),
                  barMaxWidth:40, itemStyle:{ borderRadius:[3,3,0,0] },
                  label:{ show:false } }]
    };
}
function optRev3d() {
    const data3d = revMillion.map((v,i) => [i, 0, v]);
    const maxV   = Math.max(...revMillion, 1);
    return {
        backgroundColor:'#fff', tooltip:{},
        visualMap:{ show:false, min:0, max:maxV, inRange:{ color:pal } },
        xAxis3D:{ type:'category', data:prodMonths, axisLabel:{ fontSize:10 } },
        yAxis3D:{ type:'category', data:[''], show:false },
        zAxis3D:{ type:'value', name:'M IDR', axisLabel:{ fontSize:10, formatter: v => v.toLocaleString() } },
        grid3D:{ boxWidth:220, boxDepth:30, boxHeight:100,
                 viewControl:{ alpha:22, beta:28, rotateSensitivity:2, zoomSensitivity:1.2 },
                 light:{ main:{ intensity:1.2 }, ambient:{ intensity:0.4 } } },
        series:[{ type:'bar3D', data:data3d, shading:'lambert', itemStyle:{ opacity:0.9 } }]
    };
}

// ── init charts ────────────────────────────────────────────────────────────
const charts = {};
const elProd   = document.getElementById('productionChart');
const elBlock  = document.getElementById('blockStatusChart');
const elCost   = document.getElementById('costChart');
const elBudget = document.getElementById('budgetChart');
const elRev    = document.getElementById('revenueChart');

if (elProd)   charts.prod   = { el: echarts.init(elProd),   opt2d: optProd2d,   opt3d: optProd3d };
if (elBlock)  charts.block  = { el: echarts.init(elBlock),  opt2d: optBlock2d,  opt3d: optBlock3d };
if (elCost)   charts.cost   = { el: echarts.init(elCost),   opt2d: optCost2d,   opt3d: optCost3d };
if (elBudget) charts.budget = { el: echarts.init(elBudget), opt2d: optBudget2d, opt3d: optBudget3d };
if (elRev)    charts.rev    = { el: echarts.init(elRev),    opt2d: optRev2d,    opt3d: optRev3d };

let currentMode = '2d';
Object.values(charts).forEach(({ el, opt2d }) => { el.setOption(opt2d()); });
window.addEventListener('resize', () => Object.values(charts).forEach(({ el }) => el.resize()));

function setChartMode(mode) {
    if (mode === currentMode) return;
    currentMode = mode;
    document.getElementById('btn2d').classList.toggle('active', mode === '2d');
    document.getElementById('btn3d').classList.toggle('active', mode === '3d');
    document.getElementById('modeHint').textContent =
        mode === '3d' ? 'Drag to rotate \u2022 Scroll to zoom' : 'Hover for tooltips';
    Object.values(charts).forEach(({ el, opt2d, opt3d }) => {
        el.clear();
        el.setOption(mode === '3d' ? opt3d() : opt2d());
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
