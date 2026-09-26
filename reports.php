<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Set page title and include header
$page_title = "Reports & Analytics";
require_once 'includes/header.php';

// Get report type
$report_type = get('report', 'overview');

// Fetch data for overview report
if ($report_type == 'overview') {
    // Company summary
    $company_stats = $db->query("
        SELECT 
            COUNT(*) as total_companies,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_companies
        FROM companies
    ")->fetch();
    
    // Business unit summary
    $bu_stats = $db->query("
        SELECT 
            COUNT(*) as total_units,
            SUM(CASE WHEN unit_type = 'Estate' THEN 1 ELSE 0 END) as estates,
            SUM(CASE WHEN unit_type = 'Mill' THEN 1 ELSE 0 END) as mills,
            SUM(CASE WHEN unit_type = 'Nursery' THEN 1 ELSE 0 END) as nurseries,
            SUM(CASE WHEN unit_type = 'Estate' THEN total_area ELSE 0 END) as total_estate_area
        FROM business_units
        WHERE status = 'Active'
    ")->fetch();
    
    // Block summary
    $block_stats = $db->query("
        SELECT 
            COUNT(*) as total_blocks,
            SUM(area) as total_area,
            SUM(total_plants) as total_plants,
            SUM(CASE WHEN status = 'TM' THEN area ELSE 0 END) as tm_area,
            SUM(CASE WHEN status = 'TBM' THEN area ELSE 0 END) as tbm_area,
            SUM(CASE WHEN status = 'TR' THEN area ELSE 0 END) as tr_area,
            AVG(plant_age) as avg_age
        FROM blocks
    ")->fetch();
    
    // Planting year summary
    $py_stats = $db->query("
        SELECT 
            COUNT(*) as total_planting_years,
            SUM(target_area) as total_target,
            SUM(actual_area) as total_actual,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed
        FROM planting_years
    ")->fetch();
}

// Fetch data for area statement report
elseif ($report_type == 'area_statement') {
    $area_data = $db->query("
        SELECT
            c.company_name,
            bu.unit_name,
            d.division_name,
            py.year,
            COUNT(b.block_id) as total_blocks,
            SUM(b.area) as total_area,
            SUM(CASE WHEN b.status = 'TM' THEN b.area ELSE 0 END) as tm_area,
            SUM(CASE WHEN b.status = 'TBM' THEN b.area ELSE 0 END) as tbm_area,
            SUM(CASE WHEN b.status = 'TR' THEN b.area ELSE 0 END) as tr_area,
            SUM(b.total_plants) as total_plants
        FROM companies c
        INNER JOIN business_units bu ON c.company_id = bu.company_id
        INNER JOIN divisions d ON bu.business_unit_id = d.business_unit_id
        INNER JOIN planting_years py ON d.division_id = py.division_id
        LEFT JOIN blocks b ON py.planting_year_id = b.planting_year_id
        WHERE c.status = 'Active' AND bu.status = 'Active'
        GROUP BY c.company_id, c.company_name, bu.business_unit_id, bu.unit_name, d.division_id, d.division_name, py.planting_year_id, py.year
        ORDER BY c.company_name, bu.unit_name, d.division_name, py.year
    ")->fetchAll();
}

// Fetch data for block status report
elseif ($report_type == 'block_status') {
    $block_data = $db->query("
        SELECT 
            b.status,
            COUNT(*) as block_count,
            SUM(b.area) as total_area,
            SUM(b.total_plants) as total_plants,
            AVG(b.plant_age) as avg_age,
            MIN(b.plant_age) as min_age,
            MAX(b.plant_age) as max_age
        FROM blocks b
        GROUP BY b.status
        ORDER BY b.status
    ")->fetchAll();
}

// Fetch data for planting progress report
elseif ($report_type == 'planting_progress') {
    $progress_data = $db->query("
        SELECT
            py.year,
            py.plant_type,
            d.division_name,
            bu.unit_name,
            c.company_name,
            py.target_area,
            py.actual_area,
            py.target_plants,
            py.actual_plants,
            py.status,
            COUNT(b.block_id) as total_blocks,
            CASE
                WHEN py.target_area > 0 THEN (py.actual_area / py.target_area * 100)
                ELSE 0
            END as achievement_pct
        FROM planting_years py
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        LEFT JOIN blocks b ON py.planting_year_id = b.planting_year_id
        GROUP BY py.planting_year_id, py.year, py.plant_type, py.target_area, py.actual_area, py.target_plants, py.actual_plants, py.status, d.division_name, bu.unit_name, c.company_name
        ORDER BY py.year DESC, c.company_name
    ")->fetchAll();
}

// Fetch data for topography analysis
elseif ($report_type == 'topography') {
    $topo_data = $db->query("
        SELECT 
            b.topography,
            COUNT(*) as block_count,
            SUM(b.area) as total_area,
            AVG(b.plant_density) as avg_density,
            SUM(b.total_plants) as total_plants
        FROM blocks b
        GROUP BY b.topography
        ORDER BY total_area DESC
    ")->fetchAll();
}

// Fetch data for soil analysis
elseif ($report_type == 'soil') {
    $soil_data = $db->query("
        SELECT 
            b.soil_type,
            COUNT(*) as block_count,
            SUM(b.area) as total_area,
            AVG(b.soil_ph) as avg_ph,
            MIN(b.soil_ph) as min_ph,
            MAX(b.soil_ph) as max_ph,
            SUM(b.total_plants) as total_plants
        FROM blocks b
        WHERE b.soil_type IS NOT NULL AND b.soil_type != ''
        GROUP BY b.soil_type
        ORDER BY total_area DESC
    ")->fetchAll();
}
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-file-earmark-bar-graph"></i> Reports & Analytics</h1>
            <p class="text-muted">Comprehensive plantation management reports and analysis</p>
        </div>
        <div class="col-auto">
            <button class="btn btn-success" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Report
            </button>
        </div>
    </div>
</div>

<!-- Report Navigation -->
<div class="card mb-4">
    <div class="card-body">
        <div class="btn-group flex-wrap" role="group">
            <a href="?report=overview" class="btn btn-<?php echo $report_type == 'overview' ? 'primary' : 'outline-primary'; ?>">
                <i class="bi bi-speedometer2"></i> Overview
            </a>
            <a href="?report=area_statement" class="btn btn-<?php echo $report_type == 'area_statement' ? 'primary' : 'outline-primary'; ?>">
                <i class="bi bi-map"></i> Area Statement
            </a>
            <a href="?report=block_status" class="btn btn-<?php echo $report_type == 'block_status' ? 'primary' : 'outline-primary'; ?>">
                <i class="bi bi-grid"></i> Block Status
            </a>
            <a href="?report=planting_progress" class="btn btn-<?php echo $report_type == 'planting_progress' ? 'primary' : 'outline-primary'; ?>">
                <i class="bi bi-graph-up"></i> Planting Progress
            </a>
            <a href="?report=topography" class="btn btn-<?php echo $report_type == 'topography' ? 'primary' : 'outline-primary'; ?>">
                <i class="bi bi-geo-alt"></i> Topography
            </a>
            <a href="?report=soil" class="btn btn-<?php echo $report_type == 'soil' ? 'primary' : 'outline-primary'; ?>">
                <i class="bi bi-droplet"></i> Soil Analysis
            </a>
        </div>
    </div>
</div>

<?php if ($report_type == 'overview'): ?>
    <!-- Overview Report -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="card-body">
                    <h3><?php echo $company_stats['active_companies']; ?></h3>
                    <p><i class="bi bi-building"></i> Active Companies</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="card-body">
                    <h3><?php echo $bu_stats['estates']; ?></h3>
                    <p><i class="bi bi-tree"></i> Estates</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="card-body">
                    <h3><?php echo format_number($bu_stats['total_estate_area']); ?></h3>
                    <p><i class="bi bi-map"></i> Total Area (Ha)</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="card-body">
                    <h3><?php echo $block_stats['total_blocks']; ?></h3>
                    <p><i class="bi bi-grid"></i> Total Blocks</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-pie-chart"></i> Area Distribution by Status
                </div>
                <div class="card-body">
                    <table class="table">
                        <tr>
                            <td><strong>TM (Mature)</strong></td>
                            <td class="text-end"><?php echo format_number($block_stats['tm_area']); ?> Ha</td>
                            <td class="text-end"><?php echo format_number($block_stats['tm_area'] / $block_stats['total_area'] * 100, 1); ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>TBM (Immature)</strong></td>
                            <td class="text-end"><?php echo format_number($block_stats['tbm_area']); ?> Ha</td>
                            <td class="text-end"><?php echo format_number($block_stats['tbm_area'] / $block_stats['total_area'] * 100, 1); ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>TR (Rejuvenation)</strong></td>
                            <td class="text-end"><?php echo format_number($block_stats['tr_area']); ?> Ha</td>
                            <td class="text-end"><?php echo format_number($block_stats['tr_area'] / $block_stats['total_area'] * 100, 1); ?>%</td>
                        </tr>
                        <tr class="table-active">
                            <td><strong>Total</strong></td>
                            <td class="text-end"><strong><?php echo format_number($block_stats['total_area']); ?> Ha</strong></td>
                            <td class="text-end"><strong>100%</strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-bar-chart"></i> Business Unit Summary
                </div>
                <div class="card-body">
                    <table class="table">
                        <tr>
                            <td><strong>Total Business Units</strong></td>
                            <td class="text-end"><?php echo $bu_stats['total_units']; ?></td>
                        </tr>
                        <tr>
                            <td>Estates</td>
                            <td class="text-end"><?php echo $bu_stats['estates']; ?></td>
                        </tr>
                        <tr>
                            <td>Mills</td>
                            <td class="text-end"><?php echo $bu_stats['mills']; ?></td>
                        </tr>
                        <tr>
                            <td>Nurseries</td>
                            <td class="text-end"><?php echo $bu_stats['nurseries']; ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-tree"></i> Plant Statistics
                </div>
                <div class="card-body">
                    <table class="table">
                        <tr>
                            <td><strong>Total Plants</strong></td>
                            <td class="text-end"><?php echo format_number($block_stats['total_plants'], 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Average Plant Age</strong></td>
                            <td class="text-end"><?php echo format_number($block_stats['avg_age'], 1); ?> years</td>
                        </tr>
                        <tr>
                            <td><strong>Average Density</strong></td>
                            <td class="text-end"><?php echo format_number($block_stats['total_plants'] / $block_stats['total_area'], 0); ?> plants/ha</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-calendar-event"></i> Planting Year Summary
                </div>
                <div class="card-body">
                    <table class="table">
                        <tr>
                            <td><strong>Total Planting Years</strong></td>
                            <td class="text-end"><?php echo $py_stats['total_planting_years']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Target Area</strong></td>
                            <td class="text-end"><?php echo format_number($py_stats['total_target']); ?> Ha</td>
                        </tr>
                        <tr>
                            <td><strong>Actual Area</strong></td>
                            <td class="text-end"><?php echo format_number($py_stats['total_actual']); ?> Ha</td>
                        </tr>
                        <tr>
                            <td><strong>Achievement</strong></td>
                            <td class="text-end"><?php echo format_number($py_stats['total_actual'] / $py_stats['total_target'] * 100, 1); ?>%</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($report_type == 'area_statement'): ?>
    <!-- Area Statement Report -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-map"></i> Area Statement Report
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Company</th>
                            <th>Business Unit</th>
                            <th>Division</th>
                            <th>Year</th>
                            <th class="text-end">Blocks</th>
                            <th class="text-end">Total Area</th>
                            <th class="text-end">TM Area</th>
                            <th class="text-end">TBM Area</th>
                            <th class="text-end">TR Area</th>
                            <th class="text-end">Total Plants</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_area = 0;
                        $total_tm = 0;
                        $total_tbm = 0;
                        $total_tr = 0;
                        $total_plants = 0;
                        foreach ($area_data as $row): 
                            $total_area += $row['total_area'];
                            $total_tm += $row['tm_area'];
                            $total_tbm += $row['tbm_area'];
                            $total_tr += $row['tr_area'];
                            $total_plants += $row['total_plants'];
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['company_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['unit_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['division_name']); ?></td>
                                <td><?php echo $row['year']; ?></td>
                                <td class="text-end"><?php echo $row['total_blocks']; ?></td>
                                <td class="text-end"><?php echo format_number($row['total_area']); ?></td>
                                <td class="text-end"><?php echo format_number($row['tm_area']); ?></td>
                                <td class="text-end"><?php echo format_number($row['tbm_area']); ?></td>
                                <td class="text-end"><?php echo format_number($row['tr_area']); ?></td>
                                <td class="text-end"><?php echo format_number($row['total_plants'], 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-active fw-bold">
                            <td colspan="5" class="text-end">GRAND TOTAL:</td>
                            <td class="text-end"><?php echo format_number($total_area); ?></td>
                            <td class="text-end"><?php echo format_number($total_tm); ?></td>
                            <td class="text-end"><?php echo format_number($total_tbm); ?></td>
                            <td class="text-end"><?php echo format_number($total_tr); ?></td>
                            <td class="text-end"><?php echo format_number($total_plants, 0); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($report_type == 'block_status'): ?>
    <!-- Block Status Report -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-grid"></i> Block Status Analysis
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Status</th>
                            <th class="text-end">Block Count</th>
                            <th class="text-end">Total Area (Ha)</th>
                            <th class="text-end">Total Plants</th>
                            <th class="text-end">Avg Age</th>
                            <th class="text-end">Min Age</th>
                            <th class="text-end">Max Age</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($block_data as $row): ?>
                            <tr>
                                <td><?php echo get_status_badge($row['status']); ?></td>
                                <td class="text-end"><?php echo $row['block_count']; ?></td>
                                <td class="text-end"><?php echo format_number($row['total_area']); ?></td>
                                <td class="text-end"><?php echo format_number($row['total_plants'], 0); ?></td>
                                <td class="text-end"><?php echo format_number($row['avg_age'], 1); ?> yrs</td>
                                <td class="text-end"><?php echo $row['min_age']; ?> yrs</td>
                                <td class="text-end"><?php echo $row['max_age']; ?> yrs</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($report_type == 'planting_progress'): ?>
    <!-- Planting Progress Report -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-graph-up"></i> Planting Progress Report
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Year</th>
                            <th>Company</th>
                            <th>Business Unit</th>
                            <th>Division</th>
                            <th>Plant Type</th>
                            <th class="text-end">Target Area</th>
                            <th class="text-end">Actual Area</th>
                            <th class="text-end">Achievement</th>
                            <th class="text-end">Blocks</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($progress_data as $row): 
                            $achievement_class = $row['achievement_pct'] >= 90 ? 'success' : ($row['achievement_pct'] >= 70 ? 'warning' : 'danger');
                        ?>
                            <tr>
                                <td><?php echo $row['year']; ?></td>
                                <td><?php echo htmlspecialchars($row['company_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['unit_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['division_name']); ?></td>
                                <td><span class="badge bg-info"><?php echo $row['plant_type']; ?></span></td>
                                <td class="text-end"><?php echo format_number($row['target_area']); ?></td>
                                <td class="text-end"><?php echo format_number($row['actual_area']); ?></td>
                                <td class="text-end">
                                    <span class="badge bg-<?php echo $achievement_class; ?>">
                                        <?php echo format_number($row['achievement_pct'], 1); ?>%
                                    </span>
                                </td>
                                <td class="text-end"><?php echo $row['total_blocks']; ?></td>
                                <td><?php echo get_status_badge($row['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($report_type == 'topography'): ?>
    <!-- Topography Analysis Report -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-geo-alt"></i> Topography Analysis
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Topography</th>
                            <th class="text-end">Block Count</th>
                            <th class="text-end">Total Area (Ha)</th>
                            <th class="text-end">Avg Density</th>
                            <th class="text-end">Total Plants</th>
                            <th class="text-end">% of Total Area</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_area = array_sum(array_column($topo_data, 'total_area'));
                        foreach ($topo_data as $row): 
                        ?>
                            <tr>
                                <td><strong><?php echo $row['topography']; ?></strong></td>
                                <td class="text-end"><?php echo $row['block_count']; ?></td>
                                <td class="text-end"><?php echo format_number($row['total_area']); ?></td>
                                <td class="text-end"><?php echo format_number($row['avg_density'], 0); ?> plants/ha</td>
                                <td class="text-end"><?php echo format_number($row['total_plants'], 0); ?></td>
                                <td class="text-end"><?php echo format_number($row['total_area'] / $total_area * 100, 1); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($report_type == 'soil'): ?>
    <!-- Soil Analysis Report -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-droplet"></i> Soil Analysis Report
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Soil Type</th>
                            <th class="text-end">Block Count</th>
                            <th class="text-end">Total Area (Ha)</th>
                            <th class="text-end">Avg pH</th>
                            <th class="text-end">Min pH</th>
                            <th class="text-end">Max pH</th>
                            <th class="text-end">Total Plants</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($soil_data as $row): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['soil_type']); ?></strong></td>
                                <td class="text-end"><?php echo $row['block_count']; ?></td>
                                <td class="text-end"><?php echo format_number($row['total_area']); ?></td>
                                <td class="text-end"><?php echo format_number($row['avg_ph'], 1); ?></td>
                                <td class="text-end"><?php echo format_number($row['min_ph'], 1); ?></td>
                                <td class="text-end"><?php echo format_number($row['max_ph'], 1); ?></td>
                                <td class="text-end"><?php echo format_number($row['total_plants'], 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<style>
@media print {
    .navbar, .sidebar, .page-header .btn, .card-body .btn-group {
        display: none !important;
    }
    .content-wrapper {
        padding: 0 !important;
    }
    .col-md-10 {
        width: 100% !important;
        max-width: 100% !important;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob