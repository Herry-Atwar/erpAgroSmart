<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Scope all queries to the logged-in user's company (any role)
$session_company_id = !empty($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : null;

// Build reusable SQL fragments for company scoping
// blocks scope: join needed to reach company
$blocks_company_join  = "INNER JOIN divisions _d ON b.division_id = _d.division_id
        INNER JOIN business_units _bu ON _d.business_unit_id = _bu.business_unit_id";
$blocks_company_where = $session_company_id ? " AND _bu.company_id = $session_company_id" : "";

// Set page title and include header
$page_title = "Dashboard";
require_once 'includes/header.php';

// Get statistics (all scoped to user's company if set)
$stats = [];

// Total Companies
if ($session_company_id) {
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM companies WHERE status = 'Active' AND company_id = ?");
    $stmt->execute([$session_company_id]);
} else {
    $stmt = $db->query("SELECT COUNT(*) as total FROM companies WHERE status = 'Active'");
}
$stats['companies'] = $stmt->fetch()['total'];

// Total Business Units
if ($session_company_id) {
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM business_units WHERE status = 'Active' AND company_id = ?");
    $stmt->execute([$session_company_id]);
} else {
    $stmt = $db->query("SELECT COUNT(*) as total FROM business_units WHERE status = 'Active'");
}
$stats['business_units'] = $stmt->fetch()['total'];

// Total Divisions
if ($session_company_id) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as total FROM divisions d
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        WHERE d.status = 'Active' AND bu.company_id = ?");
    $stmt->execute([$session_company_id]);
} else {
    $stmt = $db->query("SELECT COUNT(*) as total FROM divisions WHERE status = 'Active'");
}
$stats['divisions'] = $stmt->fetch()['total'];

// Total Blocks
$stmt = $db->query("SELECT COUNT(*) as total FROM blocks b $blocks_company_join WHERE 1=1$blocks_company_where");
$stats['blocks'] = $stmt->fetch()['total'];

// Total Area
$stmt = $db->query("SELECT SUM(b.area) as total FROM blocks b $blocks_company_join WHERE 1=1$blocks_company_where");
$stats['total_area'] = $stmt->fetch()['total'] ?? 0;

// TM Area (Mature)
$stmt = $db->query("SELECT SUM(b.area) as total FROM blocks b $blocks_company_join WHERE b.status = 'TM'$blocks_company_where");
$stats['tm_area'] = $stmt->fetch()['total'] ?? 0;

// TBM Area (Immature)
$stmt = $db->query("SELECT SUM(b.area) as total FROM blocks b $blocks_company_join WHERE b.status = 'TBM'$blocks_company_where");
$stats['tbm_area'] = $stmt->fetch()['total'] ?? 0;

// Total Plants
$stmt = $db->query("SELECT SUM(b.total_plants) as total FROM blocks b $blocks_company_join WHERE 1=1$blocks_company_where");
$stats['total_plants'] = $stmt->fetch()['total'] ?? 0;

// Recent Companies
if ($session_company_id) {
    $stmt = $db->prepare("SELECT * FROM companies WHERE company_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$session_company_id]);
} else {
    $stmt = $db->query("SELECT * FROM companies ORDER BY created_at DESC LIMIT 5");
}
$recent_companies = $stmt->fetchAll();

// Business Units by Type
if ($session_company_id) {
    $stmt = $db->prepare("
        SELECT unit_type, COUNT(*) as count, SUM(total_area) as total_area
        FROM business_units
        WHERE status = 'Active' AND company_id = ?
        GROUP BY unit_type");
    $stmt->execute([$session_company_id]);
} else {
    $stmt = $db->query("
        SELECT unit_type, COUNT(*) as count, SUM(total_area) as total_area
        FROM business_units
        WHERE status = 'Active'
        GROUP BY unit_type");
}
$bu_by_type = $stmt->fetchAll();

// Blocks by Status
$stmt = $db->query("
    SELECT b.status, COUNT(*) as count, SUM(b.area) as total_area
    FROM blocks b $blocks_company_join
    WHERE 1=1$blocks_company_where
    GROUP BY b.status
");
$blocks_by_status = $stmt->fetchAll();

// Planting Years Summary
$stmt = $db->query("
    SELECT py.year, COUNT(b.block_id) as block_count, SUM(b.area) as total_area
    FROM planting_years py
    LEFT JOIN blocks b ON py.planting_year_id = b.planting_year_id
    " . ($session_company_id ? "
    INNER JOIN divisions _d ON py.division_id = _d.division_id
    INNER JOIN business_units _bu ON _d.business_unit_id = _bu.business_unit_id
    WHERE _bu.company_id = $session_company_id" : "WHERE 1=1") . "
    GROUP BY py.year
    ORDER BY py.year DESC
    LIMIT 10
");
$planting_years = $stmt->fetchAll();

// FFB Deliveries — last 12 months
try {
    $ffb_sql = $session_company_id
        ? "SELECT TO_CHAR(fd.delivery_date,'YYYY-MM') AS month,
                  COALESCE(SUM(fd.net_weight)/1000,0) AS weight_ton
           FROM ffb_deliveries fd
           INNER JOIN blocks b   ON fd.block_id          = b.block_id
           INNER JOIN divisions d ON b.division_id        = d.division_id
           INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
           WHERE fd.delivery_date >= NOW() - INTERVAL '12 months'
             AND bu.company_id = $session_company_id
           GROUP BY 1 ORDER BY 1"
        : "SELECT TO_CHAR(delivery_date,'YYYY-MM') AS month,
                  COALESCE(SUM(net_weight)/1000,0) AS weight_ton
           FROM ffb_deliveries
           WHERE delivery_date >= NOW() - INTERVAL '12 months'
           GROUP BY 1 ORDER BY 1";
    $ffb_monthly = $db->query($ffb_sql)->fetchAll();
} catch (Exception $e) { $ffb_monthly = []; }

// Harvest Productivity — last 6 months
try {
    $hp_sql = $session_company_id
        ? "SELECT TO_CHAR(hr.harvest_date,'YYYY-MM') AS month,
                  COALESCE(SUM(hr.actual_bunches),0)         AS bunches,
                  COALESCE(SUM(hr.actual_quantity_kg)/1000,0) AS weight_ton
           FROM harvest_realizations hr
           INNER JOIN blocks b   ON hr.block_id            = b.block_id
           INNER JOIN divisions d ON b.division_id          = d.division_id
           INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
           WHERE hr.harvest_date >= NOW() - INTERVAL '12 months'
              AND bu.company_id = $session_company_id
           GROUP BY 1 ORDER BY 1"
        : "SELECT TO_CHAR(harvest_date,'YYYY-MM') AS month,
                  COALESCE(SUM(actual_bunches),0)         AS bunches,
                  COALESCE(SUM(actual_quantity_kg)/1000,0) AS weight_ton
           FROM harvest_realizations
           WHERE harvest_date >= NOW() - INTERVAL '12 months'
           GROUP BY 1 ORDER BY 1";
    $harvest_productivity = $db->query($hp_sql)->fetchAll();
} catch (Exception $e) { $harvest_productivity = []; }
?>

<style>
/* ── Dashboard beautification ── */
.dash-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; padding-bottom: 14px;
    border-bottom: 2px solid rgba(46,125,50,0.2);
}
.dash-header h1 { color: #1b5e20; font-size: 1.8rem; font-weight: 700; margin: 0; }
.dash-header p  { margin: 0; color: #57606a; font-size: 0.875rem; }

/* Stat cards — compact */
.dcard {
    border: none;
    border-radius: 10px;
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    padding: 10px 12px 10px 14px;
    cursor: pointer;
    transition: transform 0.15s, box-shadow 0.15s;
    position: relative;
    overflow: hidden;
    text-decoration: none;
    display: block;
}
.dcard:hover { transform: translateY(-3px); box-shadow: 0 6px 18px rgba(0,0,0,0.10); }
.dcard .dcard-icon {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    font-size: 1.8rem; opacity: 0.22;
}
.dcard .dcard-label { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 2px; }
.dcard .dcard-value { font-size: 1.35rem; font-weight: 800; line-height: 1; margin-bottom: 1px; }
.dcard .dcard-sub   { font-size: 0.68rem; opacity: 0.70; }

.dcard-blue   { background: rgba(59,130,212,0.13); border-left: 4px solid #3b82d4; color: #1e3a5f; }
.dcard-green  { background: rgba(46,125,50,0.12);  border-left: 4px solid #2e7d32; color: #1b5e20; }
.dcard-teal   { background: rgba(0,150,136,0.11);  border-left: 4px solid #00897b; color: #004d40; }
.dcard-amber  { background: rgba(245,158,11,0.13); border-left: 4px solid #f59e0b; color: #78350f; }
.dcard-indigo { background: rgba(99,102,241,0.11); border-left: 4px solid #6366f1; color: #312e81; }
.dcard-emerald{ background: rgba(16,185,129,0.11); border-left: 4px solid #10b981; color: #064e3b; }
.dcard-orange { background: rgba(249,115,22,0.11); border-left: 4px solid #f97316; color: #7c2d12; }
.dcard-rose   { background: rgba(244,63,94,0.10);  border-left: 4px solid #f43f5e; color: #881337; }

/* Section cards */
.section-card {
    background: rgba(255,255,255,0.72);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px solid rgba(229,231,235,0.8);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 24px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
}
.section-card .sc-header {
    background: rgba(27,94,32,0.08);
    border-bottom: 1px solid rgba(46,125,50,0.15);
    padding: 14px 20px;
    font-weight: 700; font-size: 0.9rem;
    color: #1b5e20;
    display: flex; align-items: center; gap: 8px;
}
.section-card .sc-body { padding: 18px 20px; }

/* Quick action buttons */
.qa-btn {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    padding: 12px 16px; border-radius: 10px; font-size: 0.85rem; font-weight: 600;
    border: 1.5px solid; transition: all 0.18s; text-decoration: none;
    backdrop-filter: blur(4px);
}
.qa-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.1); }
.qa-btn-blue   { background: rgba(59,130,212,0.08);  border-color: #3b82d4; color: #1e3a5f; }
.qa-btn-green  { background: rgba(46,125,50,0.08);   border-color: #2e7d32; color: #1b5e20; }
.qa-btn-teal   { background: rgba(0,150,136,0.08);   border-color: #00897b; color: #004d40; }
.qa-btn-amber  { background: rgba(245,158,11,0.08);  border-color: #f59e0b; color: #78350f; }

.dash-divider { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #57606a; margin: 8px 0 14px; padding-bottom: 6px; border-bottom: 1px solid rgba(0,0,0,0.08); }
</style>

<!-- Dashboard Header -->
<div class="dash-header">
    <div>
        <h1><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>
        <p>Plantation Master Data Overview</p>
    </div>
</div>

<!-- All 8 stat cards: 4×2 on tablet (sm+), 8×1 on large desktop (lg+) -->
<div class="row g-2 mb-3">
    <div class="col-6 col-sm-3 col-lg">
        <a href="companies.php" class="dcard dcard-blue">
            <div class="dcard-label">Companies</div>
            <div class="dcard-value"><?php echo number_format($stats['companies']); ?></div>
            <div class="dcard-sub">Active</div>
            <i class="bi bi-buildings dcard-icon"></i>
        </a>
    </div>
    <div class="col-6 col-sm-3 col-lg">
        <a href="business_units.php" class="dcard dcard-green">
            <div class="dcard-label">Business Units</div>
            <div class="dcard-value"><?php echo number_format($stats['business_units']); ?></div>
            <div class="dcard-sub">Active</div>
            <i class="bi bi-diagram-3 dcard-icon"></i>
        </a>
    </div>
    <div class="col-6 col-sm-3 col-lg">
        <a href="divisions.php" class="dcard dcard-teal">
            <div class="dcard-label">Divisions</div>
            <div class="dcard-value"><?php echo number_format($stats['divisions']); ?></div>
            <div class="dcard-sub">Active</div>
            <i class="bi bi-grid-3x3 dcard-icon"></i>
        </a>
    </div>
    <div class="col-6 col-sm-3 col-lg">
        <a href="blocks.php" class="dcard dcard-amber">
            <div class="dcard-label">Blocks</div>
            <div class="dcard-value"><?php echo number_format($stats['blocks']); ?></div>
            <div class="dcard-sub">Total</div>
            <i class="bi bi-grid dcard-icon"></i>
        </a>
    </div>
    <div class="col-6 col-sm-3 col-lg">
        <div class="dcard dcard-indigo" style="cursor:default;">
            <div class="dcard-label">Total Area</div>
            <div class="dcard-value"><?php echo format_number($stats['total_area']); ?></div>
            <div class="dcard-sub">Ha</div>
            <i class="bi bi-map dcard-icon"></i>
        </div>
    </div>
    <div class="col-6 col-sm-3 col-lg">
        <div class="dcard dcard-emerald" style="cursor:default;">
            <div class="dcard-label">TM (Mature)</div>
            <div class="dcard-value"><?php echo format_number($stats['tm_area']); ?></div>
            <div class="dcard-sub"><?php echo $stats['total_area'] > 0 ? number_format(($stats['tm_area'] / $stats['total_area']) * 100, 1) : 0; ?>% Ha</div>
            <i class="bi bi-tree dcard-icon"></i>
        </div>
    </div>
    <div class="col-6 col-sm-3 col-lg">
        <div class="dcard dcard-orange" style="cursor:default;">
            <div class="dcard-label">TBM (Immature)</div>
            <div class="dcard-value"><?php echo format_number($stats['tbm_area']); ?></div>
            <div class="dcard-sub"><?php echo $stats['total_area'] > 0 ? number_format(($stats['tbm_area'] / $stats['total_area']) * 100, 1) : 0; ?>% Ha</div>
            <i class="bi bi-seedling dcard-icon"></i>
        </div>
    </div>
    <div class="col-6 col-sm-3 col-lg">
        <div class="dcard dcard-rose" style="cursor:default;">
            <div class="dcard-label">Plants</div>
            <div class="dcard-value"><?php echo number_format($stats['total_plants']); ?></div>
            <div class="dcard-sub">Total</div>
            <i class="bi bi-flower1 dcard-icon"></i>
        </div>
    </div>
</div>

<!-- ── Charts Section ─────────────────────────────────────────────────────── -->
<p class="dash-divider"><i class="bi bi-bar-chart-line me-1"></i> Charts</p>

<!-- 2D / 3D toggle -->
<div class="d-flex align-items-center justify-content-end mb-2" style="gap:8px;">
  <span style="font-size:0.78rem;font-weight:600;color:#57606a;text-transform:uppercase;letter-spacing:.5px;">View:</span>
  <div class="btn-group btn-group-sm" role="group" aria-label="Chart dimension toggle" id="chartDimToggle">
    <button type="button" class="btn btn-outline-success" id="btn2d" onclick="setChartMode('2d')">
      <i class="bi bi-bar-chart-fill me-1"></i>2D
    </button>
    <button type="button" class="btn btn-success active" id="btn3d" onclick="setChartMode('3d')">
      <i class="bi bi-box me-1"></i>3D
    </button>
  </div>
</div>

<!-- 2 × 2 chart grid -->
<div class="row mb-4" id="chartsGrid">
    <!-- Top-left -->
    <div class="col-md-6 mb-3">
        <div class="section-card">
            <div class="sc-header"><i class="bi bi-pie-chart-fill"></i> Area by Block Status</div>
            <div class="sc-body" style="height:340px;position:relative;padding:0;">
                <canvas id="chartBlockStatus" style="display:none;width:100%;height:100%;"></canvas>
                <div id="chart3dBlockStatus" style="width:100%;height:100%;"></div>
            </div>
        </div>
    </div>
    <!-- Top-right -->
    <div class="col-md-6 mb-3">
        <div class="section-card">
            <div class="sc-header"><i class="bi bi-buildings"></i> Business Units by Type</div>
            <div class="sc-body" style="height:340px;position:relative;padding:0;">
                <canvas id="chartBuType" style="display:none;width:100%;height:100%;"></canvas>
                <div id="chart3dBuType" style="width:100%;height:100%;"></div>
            </div>
        </div>
    </div>
    <!-- Bottom-left -->
    <div class="col-md-6 mb-3">
        <div class="section-card">
            <div class="sc-header"><i class="bi bi-calendar-range"></i> Planting Years — Blocks &amp; Area</div>
            <div class="sc-body" style="height:340px;position:relative;padding:0;">
                <canvas id="chartPlantingYears" style="display:none;width:100%;height:100%;"></canvas>
                <div id="chart3dPlantingYears" style="width:100%;height:100%;"></div>
            </div>
        </div>
    </div>
    <!-- Bottom-right: FFB if available, else Harvest -->
    <?php if (!empty($ffb_monthly)): ?>
    <div class="col-md-6 mb-3">
        <div class="section-card">
            <div class="sc-header"><i class="bi bi-truck-flatbed"></i> FFB Deliveries (last 12 months)</div>
            <div class="sc-body" style="height:340px;position:relative;padding:0;">
                <canvas id="chartFfb" style="display:none;width:100%;height:100%;"></canvas>
                <div id="chart3dFfb" style="width:100%;height:100%;"></div>
            </div>
        </div>
    </div>
    <?php elseif (!empty($harvest_productivity)): ?>
    <div class="col-md-6 mb-3">
        <div class="section-card">
            <div class="sc-header"><i class="bi bi-basket2-fill"></i> Harvest Productivity (last 6 months)</div>
            <div class="sc-body" style="height:340px;position:relative;padding:0;">
                <canvas id="chartHarvest" style="display:none;width:100%;height:100%;"></canvas>
                <div id="chart3dHarvest" style="width:100%;height:100%;"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
// ── Chart data — embedded as JSON for use in $extra_js below ─────────────
$chart_bs_json  = json_encode(array_values($blocks_by_status));
$chart_bu_json  = json_encode(array_values($bu_by_type));
$chart_py_json  = json_encode(array_reverse(array_values($planting_years)));
$chart_ffb_json = json_encode(array_values($ffb_monthly));
$chart_hp_json  = json_encode(array_values($harvest_productivity));

// $extra_js is output by footer.php AFTER Bootstrap JS + jQuery — guaranteed loaded
ob_start();
?>
<!-- Chart.js (2D) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<!-- ECharts + GL (local — enables true bar3D / 3D pie) -->
<script src="js/echarts.min.js"></script>
<script src="js/echarts-gl.min.js"></script>
<script>
(function () {
  'use strict';

  // ── Raw data ──────────────────────────────────────────────────────────────
  var bsData  = <?php echo $chart_bs_json; ?>;
  var buData  = <?php echo $chart_bu_json; ?>;
  var pyData  = <?php echo $chart_py_json; ?>;
  var ffbData = <?php echo $chart_ffb_json; ?>;
  var hpData  = <?php echo $chart_hp_json; ?>;

  // ── Palette ───────────────────────────────────────────────────────────────
  var STATUS_COLORS = { TM:'#2e7d32', TBM:'#f57f17', TR:'#b71c1c', HP:'#1565c0', HPT:'#6a1b9a', LC:'#795548' };
  var GP = ['#2e7d32','#558b2f','#8bc34a','#c5e1a5','#33691e','#aed581','#1b5e20','#4caf50'];
  var EC_TEXT = { color:'#1f2328', fontSize:11 };

  // ── 2D — Chart.js ─────────────────────────────────────────────────────────
  var cjDone = false, cj = {};

  function initChartJs() {
    if (cjDone) return; cjDone = true;
    var D = { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ labels:{ boxWidth:12, font:{size:11} } } } };

    if (bsData.length)
      cj.bs = new Chart(document.getElementById('chartBlockStatus'), {
        type:'pie',
        data:{ labels:bsData.map(function(r){return r.status;}),
               datasets:[{ data:bsData.map(function(r){return parseFloat(r.total_area);}),
                           backgroundColor:bsData.map(function(r){return STATUS_COLORS[r.status]||'#888';}), borderWidth:1 }] },
        options:{ ...D, plugins:{ ...D.plugins, tooltip:{ callbacks:{ label:function(c){ return ' '+c.label+': '+Number(c.parsed).toLocaleString('en-US',{minimumFractionDigits:1,maximumFractionDigits:1})+' Ha'; } } } } }
      });

    if (buData.length)
      cj.bu = new Chart(document.getElementById('chartBuType'), {
        type:'bar',
        data:{ labels:buData.map(function(r){return r.unit_type;}),
               datasets:[
                 { label:'Units',     data:buData.map(function(r){return parseInt(r.count);}),        backgroundColor:'#2e7d32', yAxisID:'y',  borderRadius:4 },
                 { label:'Area (Ha)', data:buData.map(function(r){return parseFloat(r.total_area);}), backgroundColor:'#8bc34a', yAxisID:'y2', borderRadius:4 }] },
        options:{ ...D, scales:{ y:{position:'left',ticks:{font:{size:10}},grid:{color:'#f0f0f0'}}, y2:{position:'right',ticks:{font:{size:10}},grid:{drawOnChartArea:false}}, x:{ticks:{font:{size:11}}} } }
      });

    if (pyData.length)
      cj.py = new Chart(document.getElementById('chartPlantingYears'), {
        type:'bar',
        data:{ labels:pyData.map(function(r){return String(r.year);}),
               datasets:[
                 { label:'Blocks',    data:pyData.map(function(r){return parseInt(r.block_count);}),  backgroundColor:'#33691e', yAxisID:'y',  borderRadius:4 },
                 { label:'Area (Ha)', data:pyData.map(function(r){return parseFloat(r.total_area);}), backgroundColor:'#aed581', yAxisID:'y2', borderRadius:4 }] },
        options:{ ...D, scales:{ y:{position:'left',ticks:{font:{size:10}},grid:{color:'#f0f0f0'}}, y2:{position:'right',ticks:{font:{size:10}},grid:{drawOnChartArea:false}}, x:{ticks:{font:{size:11}}} } }
      });

    var elCjFfb = document.getElementById('chartFfb');
    if (ffbData.length && elCjFfb)
      cj.ffb = new Chart(elCjFfb, {
        type:'line',
        data:{ labels:ffbData.map(function(r){return r.month;}),
               datasets:[{ label:'FFB (ton)', data:ffbData.map(function(r){return parseFloat(r.weight_ton);}),
                           borderColor:'#2e7d32', backgroundColor:'rgba(46,125,50,0.08)', borderWidth:2, pointRadius:3, fill:true, tension:0.3 }] },
        options:{ ...D, scales:{ y:{ticks:{font:{size:10}},grid:{color:'#f0f0f0'}}, x:{ticks:{font:{size:10}}} } }
      });

    var elCjHp = document.getElementById('chartHarvest');
    if (hpData.length && elCjHp)
      cj.hp = new Chart(elCjHp, {
        type:'line',
        data:{ labels:hpData.map(function(r){return r.month;}),
               datasets:[
                 { label:'Bunches',      data:hpData.map(function(r){return parseInt(r.bunches);}),       borderColor:'#558b2f', backgroundColor:'rgba(85,139,47,0.07)',  borderWidth:2,pointRadius:3,fill:true,tension:0.3,yAxisID:'y'  },
                 { label:'Weight (ton)', data:hpData.map(function(r){return parseFloat(r.weight_ton);}),  borderColor:'#8bc34a', backgroundColor:'rgba(139,195,74,0.07)', borderWidth:2,pointRadius:3,fill:true,tension:0.3,yAxisID:'y2' }] },
        options:{ ...D, scales:{ y:{position:'left',ticks:{font:{size:10}},grid:{color:'#f0f0f0'}}, y2:{position:'right',ticks:{font:{size:10}},grid:{drawOnChartArea:false}}, x:{ticks:{font:{size:10}}} } }
      });
  }

  // ── 3D — ECharts-GL (true bar3D / 3D pie) ─────────────────────────────────
  var ec = {};

  function ecResize() { Object.keys(ec).forEach(function(k){ if(ec[k]&&ec[k].resize) ec[k].resize(); }); }

  var GL_LIGHT = { main:{ intensity:1.2 }, ambient:{ intensity:0.4 } };

  function initECharts() {
    Object.keys(ec).forEach(function(k){ if(ec[k]&&ec[k].dispose) ec[k].dispose(); });
    ec = {};

    // Block Status — 3D pie (full cone with deep shadows)
    if (bsData.length) {
      ec.bs = echarts.init(document.getElementById('chart3dBlockStatus'));
      ec.bs.setOption({
        backgroundColor:'transparent',
        tooltip:{ trigger:'item', formatter:'{b}: {c} Ha ({d}%)' },
        legend:{ bottom:0, textStyle:EC_TEXT, itemWidth:10, itemHeight:10 },
        series:[{ type:'pie', radius:['0%','62%'], center:['50%','46%'],
          itemStyle:{ borderRadius:6, borderColor:'#fff', borderWidth:2,
                      shadowBlur:28, shadowColor:'rgba(0,0,0,0.40)',
                      shadowOffsetX:6, shadowOffsetY:10 },
          label:{ fontSize:10 },
          emphasis:{ scale:true, scaleSize:8,
                     itemStyle:{ shadowBlur:36, shadowColor:'rgba(0,0,0,0.55)' } },
          data:bsData.map(function(r,i){
            return { name:r.status,
                     value:parseFloat(parseFloat(r.total_area).toFixed(1)),
                     itemStyle:{ color:STATUS_COLORS[r.status]||GP[i%GP.length] } };
          })
        }]
      });
    }

    // Business Units — 3D donut (pie with inner radius + deep shadows)
    if (buData.length) {
      ec.bu = echarts.init(document.getElementById('chart3dBuType'));
      var buLabels = buData.map(function(r){ return r.unit_type; });
      var buCounts = buData.map(function(r){ return parseInt(r.count); });
      ec.bu.setOption({
        backgroundColor:'transparent',
        tooltip:{ trigger:'item', formatter:'{b}<br/>Units: <b>{c}</b>' },
        legend:{ bottom:0, textStyle:EC_TEXT, itemWidth:10, itemHeight:10 },
        series:[{ type:'pie', radius:['36%','65%'], center:['50%','46%'],
          itemStyle:{ borderRadius:8, borderColor:'#fff', borderWidth:2,
                      shadowBlur:30, shadowColor:'rgba(0,0,0,0.42)',
                      shadowOffsetX:6, shadowOffsetY:10 },
          label:{ fontSize:10, formatter:'{b}\n{c}' },
          emphasis:{ scale:true, scaleSize:8,
                     itemStyle:{ shadowBlur:38, shadowColor:'rgba(0,0,0,0.58)' } },
          data:buLabels.map(function(l,i){
            return { name:l, value:buCounts[i], itemStyle:{ color:GP[i%GP.length] } };
          })
        }]
      });
    }

    // Planting Years — bar3D (Blocks + Area side-by-side)
    if (pyData.length) {
      ec.py = echarts.init(document.getElementById('chart3dPlantingYears'));
      var pyLabels = pyData.map(function(r){ return String(r.year); });
      var pyBlocks = pyData.map(function(r){ return parseInt(r.block_count); });
      var pyAreas  = pyData.map(function(r){ return parseFloat(r.total_area); });
      var d1py = pyBlocks.map(function(v,i){ return [i, 0, v]; });
      var d2py = pyAreas.map(function(v,i){  return [i, 1, v]; });
      var maxPy = Math.max(Math.max.apply(null,pyBlocks), Math.max.apply(null,pyAreas), 1);
      ec.py.setOption({
        backgroundColor:'transparent', tooltip:{},
        legend:{ data:['Blocks','Area (Ha)'], bottom:0, textStyle:EC_TEXT, itemWidth:10, itemHeight:10 },
        visualMap:{ show:false, min:0, max:maxPy, inRange:{ color:GP } },
        xAxis3D:{ type:'category', data:pyLabels, axisLabel:{fontSize:9} },
        yAxis3D:{ type:'category', data:['Blocks','Area (Ha)'], axisLabel:{fontSize:9} },
        zAxis3D:{ type:'value', axisLabel:{fontSize:9} },
        grid3D:{ boxWidth:200, boxDepth:60, boxHeight:100,
                 viewControl:{ alpha:22, beta:-30, rotateSensitivity:2, zoomSensitivity:1.2 },
                 light:GL_LIGHT },
        series:[
          { name:'Blocks',   type:'bar3D', data:d1py, shading:'lambert', itemStyle:{ color:'#66bb6a', opacity:0.9 } },
          { name:'Area (Ha)',type:'bar3D', data:d2py, shading:'lambert', itemStyle:{ color:'#dce775', opacity:0.9 } }
        ]
      });
    }

    // FFB Deliveries — bar3D (month × weight)
    var elFfb = document.getElementById('chart3dFfb');
    if (ffbData.length && elFfb) {
      ec.ffb = echarts.init(elFfb);
      var ffbLabels = ffbData.map(function(r){ return r.month; });
      var ffbTons   = ffbData.map(function(r){ return parseFloat(r.weight_ton); });
      var ffbData3d = ffbTons.map(function(v,i){ return [i, 0, v]; });
      var maxFfb    = Math.max.apply(null, ffbTons.concat([1]));
      ec.ffb.setOption({
        backgroundColor:'transparent', tooltip:{},
        visualMap:{ show:false, min:0, max:maxFfb, inRange:{ color:GP } },
        xAxis3D:{ type:'category', data:ffbLabels, axisLabel:{fontSize:8, rotate:30} },
        yAxis3D:{ type:'category', data:[''], show:false },
        zAxis3D:{ type:'value', name:'ton', axisLabel:{fontSize:9} },
        grid3D:{ boxWidth:200, boxDepth:30, boxHeight:100,
                 viewControl:{ alpha:22, beta:28, rotateSensitivity:2, zoomSensitivity:1.2 },
                 light:GL_LIGHT },
        series:[{ type:'bar3D', data:ffbData3d, shading:'lambert', itemStyle:{ opacity:0.9 } }]
      });
    }

    // Harvest Productivity — bar3D (Bunches + Weight side-by-side)
    var elHp = document.getElementById('chart3dHarvest');
    if (hpData.length && elHp) {
      ec.hp = echarts.init(elHp);
      var hpLabels  = hpData.map(function(r){ return r.month; });
      var hpBunches = hpData.map(function(r){ return parseInt(r.bunches); });
      var hpTons    = hpData.map(function(r){ return parseFloat(r.weight_ton); });
      var d1hp = hpBunches.map(function(v,i){ return [i, 0, v]; });
      var d2hp = hpTons.map(function(v,i){    return [i, 1, v]; });
      var maxHp = Math.max(Math.max.apply(null,hpBunches), Math.max.apply(null,hpTons), 1);
      ec.hp.setOption({
        backgroundColor:'transparent', tooltip:{},
        legend:{ data:['Bunches','Weight (ton)'], bottom:0, textStyle:EC_TEXT, itemWidth:10, itemHeight:10 },
        visualMap:{ show:false, min:0, max:maxHp, inRange:{ color:GP } },
        xAxis3D:{ type:'category', data:hpLabels, axisLabel:{fontSize:9} },
        yAxis3D:{ type:'category', data:['Bunches','Weight (ton)'], axisLabel:{fontSize:9} },
        zAxis3D:{ type:'value', axisLabel:{fontSize:9} },
        grid3D:{ boxWidth:160, boxDepth:60, boxHeight:100,
                 viewControl:{ alpha:22, beta:-30, rotateSensitivity:2, zoomSensitivity:1.2 },
                 light:GL_LIGHT },
        series:[
          { name:'Bunches',      type:'bar3D', data:d1hp, shading:'lambert', itemStyle:{ color:'#558b2f', opacity:0.9 } },
          { name:'Weight (ton)', type:'bar3D', data:d2hp, shading:'lambert', itemStyle:{ color:'#8bc34a', opacity:0.9 } }
        ]
      });
    }

    setTimeout(ecResize, 100);
  }

  // ── WebGL detection ───────────────────────────────────────────────────────
  function hasWebGL() {
    try {
      var c = document.createElement('canvas');
      return !!(window.WebGLRenderingContext &&
                (c.getContext('webgl') || c.getContext('experimental-webgl')));
    } catch(e) { return false; }
  }
  var webglOk = hasWebGL();

  // ── Toggle ────────────────────────────────────────────────────────────────
  var currentMode = webglOk ? '3d' : '2d';

  function show3d() {
    document.querySelectorAll('[id^="chart3d"]').forEach(function(el){ el.style.display='block'; });
    document.querySelectorAll('#chartsGrid canvas').forEach(function(el){ el.style.display='none'; });
    setTimeout(ecResize, 150);
  }
  function show2d() {
    document.querySelectorAll('[id^="chart3d"]').forEach(function(el){ el.style.display='none'; });
    document.querySelectorAll('#chartsGrid canvas').forEach(function(el){ el.style.display='block'; });
    Object.keys(cj).forEach(function(k){ if(cj[k]&&cj[k].resize) cj[k].resize(); });
  }

  function applyToggleUI(mode) {
    var b2 = document.getElementById('btn2d'), b3 = document.getElementById('btn3d');
    if (mode === '2d') {
      b2.classList.add('btn-success','active');    b2.classList.remove('btn-outline-success');
      b3.classList.remove('btn-success','active'); b3.classList.add('btn-outline-success');
    } else {
      b3.classList.add('btn-success','active');    b3.classList.remove('btn-outline-success');
      b2.classList.remove('btn-success','active'); b2.classList.add('btn-outline-success');
    }
  }

  window.setChartMode = function(mode) {
    if (mode === currentMode) return;
    if (mode === '3d' && !webglOk) {
      alert('3D charts require WebGL, which is not supported by this browser.\nSwitching to 2D.');
      return;
    }
    currentMode = mode;
    applyToggleUI(mode);
    if (mode === '2d') {
      initChartJs(); show2d();
    } else {
      // show3d FIRST so containers have real dimensions before ECharts init
      show3d();
      initECharts(); ecResize();
    }
  };

  // ── Boot ──────────────────────────────────────────────────────────────────
  window.addEventListener('load', function() {
    applyToggleUI(currentMode);
    if (webglOk) {
      // Mark 3D button disabled-looking if no WebGL (shouldn't reach here, but safety)
      show3d();
      initECharts();
    } else {
      // WebGL unavailable — boot in 2D and disable the 3D button
      var b3 = document.getElementById('btn3d');
      b3.disabled = true;
      b3.title = '3D charts require WebGL (not supported on this device)';
      b3.style.opacity = '0.45';
      b3.style.cursor = 'not-allowed';
      initChartJs();
      show2d();
    }
  });

  window.addEventListener('resize', function() {
    if (currentMode === '3d') ecResize();
    else Object.keys(cj).forEach(function(k){ if(cj[k]&&cj[k].resize) cj[k].resize(); });
  });

})();
</script>
<?php
$extra_js = ob_get_clean();
?>

<!-- Row 3: Tables -->
<p class="dash-divider"><i class="bi bi-table me-1"></i> Breakdown</p>
<div class="row">
    <div class="col-md-6 mb-4">
        <div class="section-card">
            <div class="sc-header"><i class="bi bi-pie-chart"></i> Business Units by Type</div>
            <div class="sc-body">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Type</th><th class="text-end">Count</th><th class="text-end">Area (Ha)</th></tr></thead>
                    <tbody>
                        <?php foreach ($bu_by_type as $row): ?>
                            <tr>
                                <td><i class="bi bi-circle-fill text-primary" style="font-size:0.5rem;vertical-align:middle;margin-right:6px;"></i><?php echo $row['unit_type']; ?></td>
                                <td class="text-end"><strong><?php echo number_format($row['count']); ?></strong></td>
                                <td class="text-end"><?php echo format_number($row['total_area']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-4">
        <div class="section-card">
            <div class="sc-header"><i class="bi bi-bar-chart"></i> Blocks by Status</div>
            <div class="sc-body">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Status</th><th class="text-end">Blocks</th><th class="text-end">Area (Ha)</th></tr></thead>
                    <tbody>
                        <?php foreach ($blocks_by_status as $row): ?>
                            <tr>
                                <td><?php echo get_status_badge($row['status']); ?></td>
                                <td class="text-end"><strong><?php echo number_format($row['count']); ?></strong></td>
                                <td class="text-end"><?php echo format_number($row['total_area']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="section-card">
            <div class="sc-header"><i class="bi bi-clock-history"></i> Recent Companies</div>
            <div class="sc-body">
                <div class="list-group list-group-flush">
                    <?php foreach ($recent_companies as $company): ?>
                        <div class="list-group-item px-0" style="background:transparent; border-color:rgba(0,0,0,0.06);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($company['company_name']); ?></h6>
                                    <small class="text-muted"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($company['province']); ?></small>
                                </div>
                                <?php echo get_status_badge($company['status']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-center mt-3">
                    <a href="companies.php" class="btn btn-sm btn-outline-primary">View All Companies</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-4">
        <div class="section-card">
            <div class="sc-header"><i class="bi bi-calendar-event"></i> Planting Years Summary</div>
            <div class="sc-body">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Year</th><th class="text-end">Blocks</th><th class="text-end">Area (Ha)</th></tr></thead>
                    <tbody>
                        <?php foreach ($planting_years as $row): ?>
                            <tr>
                                <td><strong><?php echo $row['year']; ?></strong></td>
                                <td class="text-end"><?php echo number_format($row['block_count']); ?></td>
                                <td class="text-end"><?php echo format_number($row['total_area']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-center mt-3">
                    <a href="planting_years.php" class="btn btn-sm btn-outline-primary">View All Planting Years</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<p class="dash-divider"><i class="bi bi-lightning me-1"></i> Quick Actions</p>
<div class="row mb-4">
    <div class="col-md-3 mb-2">
        <a href="companies.php" class="qa-btn qa-btn-blue w-100">
            <i class="bi bi-building"></i> Manage Companies
        </a>
    </div>
    <div class="col-md-3 mb-2">
        <a href="business_units.php" class="qa-btn qa-btn-green w-100">
            <i class="bi bi-diagram-3"></i> Manage Business Units
        </a>
    </div>
    <div class="col-md-3 mb-2">
        <a href="divisions.php" class="qa-btn qa-btn-teal w-100">
            <i class="bi bi-grid-3x3"></i> Manage Divisions
        </a>
    </div>
    <div class="col-md-3 mb-2">
        <a href="blocks.php" class="qa-btn qa-btn-amber w-100">
            <i class="bi bi-grid"></i> Manage Blocks
        </a>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
