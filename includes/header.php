<?php
ob_start();
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

// Initialize PDO connection for use in all pages
$pdo = getDB();

// Get user info from session
$logged_in_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>erpAgroSmart - Agrobusiness Solution</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #2e7d32;
            --secondary-color: #558b2f;
            --accent-color: #8bc34a;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: url('/erpagrosmart/images/AgroSmart.jpg') center center / cover no-repeat fixed;
            min-height: 100vh;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(255, 255, 255, 0.82);
            z-index: 0;
            pointer-events: none;
        }

        .navbar, .sidebar, .card {
            position: relative;
            z-index: 1;
        }
        .content-wrapper {
            position: relative;
        }
        
        .navbar {
            background: #2c2c2c;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: relative;
            z-index: 1050;
        }
        
        .navbar-brand {
            font-weight: bold;
            font-size: 1.3rem;
        }

        /* Top nav module dropdowns */
        .navbar .nav-link {
            color: rgba(255,255,255,.85) !important;
            font-size: 0.875rem;
            padding: 0.5rem 0.75rem !important;
        }
        .navbar .nav-link:hover,
        .navbar .nav-link:focus {
            color: #fff !important;
        }
        .navbar .dropdown-menu {
            background-color: #ffffff !important;
            background: #ffffff !important;
            opacity: 1 !important;
            border: 1px solid #e0e0e0 !important;
            box-shadow: 0 8px 28px rgba(0,0,0,0.18) !important;
            border-radius: 6px;
            min-width: 210px;
            padding: 6px 0;
            z-index: 9999 !important;
            position: absolute !important;
        }
        .navbar .dropdown-item {
            font-size: 0.85rem;
            padding: 6px 18px;
            color: #222 !important;
            background-color: transparent;
        }
        .navbar .dropdown-item:hover,
        .navbar .dropdown-item:focus {
            background-color: #e8f5e9 !important;
            color: var(--primary-color) !important;
        }
        .navbar .dropdown-item i {
            width: 18px;
            margin-right: 6px;
            color: var(--primary-color);
        }
        .navbar .dropdown-header {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--primary-color);
            font-weight: 700;
            padding: 6px 18px 3px;
        }
        .navbar .dropdown-divider {
            margin: 4px 0;
            border-color: #e0e0e0;
        }
        /* Active top-nav item */
        .navbar .nav-item.active > .nav-link,
        .navbar .nav-link.top-active {
            color: #fff !important;
            font-weight: 600;
        }
        
        .sidebar {
            background-color: #c8e6c9;
            box-shadow: 2px 0 5px rgba(0,0,0,0.08);
            padding: 10px 0;
            max-width: 288px;
            position: sticky;
            top: 56px;
            height: calc(100vh - 56px);
            align-self: flex-start;
        }
        
        .sidebar .nav-link {
            color: #333;
            padding: 6px 15px;
            margin: 1px 8px;
            border-radius: 4px;
            transition: all 0.3s;
            font-size: 1rem;
        }
        
        .sidebar .nav-link:hover {
            background-color: #f0f0f0;
            color: var(--primary-color);
        }
        
        .sidebar .nav-link.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .sidebar .nav-link i {
            margin-right: 10px;
            width: 20px;
        }
        
        .content-wrapper {
            padding: 30px;
        }
        
        .card {
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
            padding: 15px 20px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .table thead {
            background-color: #f8f9fa;
        }
        
        /* Alternating row colors for tables */
        .table tbody tr.table-row-even,
        .table-hover tbody tr.table-row-even {
            background-color: #ffffff !important;
        }
        
        .table tbody tr.table-row-odd,
        .table-hover tbody tr.table-row-odd {
            background-color: #e8f5e9 !important; /* Soft green */
        }
        
        .table tbody tr.table-row-even:hover,
        .table-hover tbody tr.table-row-even:hover,
        .table tbody tr.table-row-odd:hover,
        .table-hover tbody tr.table-row-odd:hover {
            background-color: #c8e6c9 !important; /* Slightly darker green on hover */
        }
        
        /* Override Bootstrap table striped if present */
        .table-striped tbody tr.table-row-even,
        .table-striped tbody tr.table-row-odd {
            background-color: inherit !important;
        }
        
        .badge {
            padding: 5px 10px;
            font-weight: 500;
        }
        
        .page-header {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary-color);
        }
        
        .page-header h1 {
            color: var(--primary-color);
            font-size: 2rem;
            font-weight: 600;
        }
        
        .stat-card {
            border-left: 4px solid var(--primary-color);
        }
        
        .stat-card .card-body {
            padding: 20px;
        }
        
        .stat-card h3 {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .stat-card p {
            color: #666;
            margin-bottom: 0;
        }
        
        .sidebar-heading {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            padding: 8px 15px;
            margin-top: 8px !important;
            margin-bottom: 2px !important;
            color: var(--primary-color) !important;
            cursor: pointer;
            background-color: rgba(46,125,50,0.1);
            border-radius: 4px;
            transition: all 0.3s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .sidebar-heading:hover {
            background-color: rgba(46,125,50,0.2);
        }
        
        .sidebar-heading i {
            transition: transform 0.3s;
        }
        
        .sidebar-heading.collapsed i {
            transform: rotate(-90deg);
        }
        
        .menu-section {
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .sidebar {
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }
        
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        /* Tablet optimizations for desktop site mode (Galaxy Tab) */
        @media (min-width: 768px) and (max-width: 1024px) {
            /* Reduce sidebar width on tablets */
            .sidebar {
                max-width: 180px !important;
                font-size: 0.85rem;
            }
            
            /* Adjust sidebar link padding and font size */
            .sidebar .nav-link {
                padding: 4px 8px;
                font-size: 0.8rem;
                margin: 1px 5px;
            }
            
            /* Reduce icon spacing */
            .sidebar .nav-link i {
                margin-right: 6px;
                width: 16px;
                font-size: 0.85rem;
            }
            
            /* Compact sidebar headings */
            .sidebar-heading {
                font-size: 0.7rem;
                padding: 0 8px !important;
                margin-top: 6px !important;
                margin-bottom: 1px !important;
            }
            
            /* Adjust main content padding */
            .content-wrapper {
                padding: 15px;
            }
            
            /* Reduce navbar brand size */
            .navbar-brand {
                font-size: 1rem;
            }
            
            /* Optimize card spacing */
            .card {
                margin-bottom: 15px;
            }
            
            .card-header {
                padding: 10px 15px;
                font-size: 0.95rem;
            }
            
            /* Reduce page header size */
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            /* Optimize table font size */
            .table {
                font-size: 0.85rem;
            }
            
            /* Reduce button padding */
            .btn {
                padding: 0.25rem 0.5rem;
                font-size: 0.85rem;
            }
        }
        
        /* Landscape tablet specific optimizations */
        @media (min-width: 768px) and (max-width: 1024px) and (orientation: landscape) {
            .sidebar {
                max-width: 160px !important;
            }
            
            .sidebar .nav-link {
                padding: 3px 6px;
                font-size: 0.75rem;
            }
            
            .sidebar-heading {
                font-size: 0.65rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <?php
    $cur = basename($_SERVER['PHP_SELF']);
    $in = function($pages) use ($cur) {
        foreach ((array)$pages as $p) { if (strpos($cur, $p) !== false) return true; }
        return false;
    };
    ?>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-tree"></i> erpAgroSmart
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">

                    <!-- Dashboard -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo $cur=='index.php' ? 'top-active' : ''; ?>" href="index.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>

                    <!-- Master Data -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo $in(['companies','business_units','divisions','planting_years','blocks','plant_varieties','workers','activities','users','partners','areal_statement','block_area','area_component']) ? 'top-active' : ''; ?>" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-database"></i> Master Data
                        </a>
                        <ul class="dropdown-menu">
                            <li><h6 class="dropdown-header">Organization</h6></li>
                            <li><a class="dropdown-item" href="companies.php"><i class="bi bi-building"></i> Companies</a></li>
                            <li><a class="dropdown-item" href="business_units.php"><i class="bi bi-diagram-3"></i> Business Units</a></li>
                            <li><a class="dropdown-item" href="divisions.php"><i class="bi bi-grid-3x3"></i> Divisions</a></li>
                            <li><a class="dropdown-item" href="planting_years.php"><i class="bi bi-calendar-event"></i> Planting Years</a></li>
                            <li><a class="dropdown-item" href="blocks.php"><i class="bi bi-grid"></i> Blocks</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header">Configuration</h6></li>
                            <li><a class="dropdown-item" href="plant_varieties.php"><i class="bi bi-flower1"></i> Plant Varieties</a></li>
                            <li><a class="dropdown-item" href="block_area_components.php"><i class="bi bi-grid-3x3"></i> Block Area Components</a></li>
                            <li><a class="dropdown-item" href="area_component_config.php"><i class="bi bi-gear"></i> Area Config</a></li>
                            <li><a class="dropdown-item" href="areal_statement_dynamic.php"><i class="bi bi-file-earmark-text"></i> Areal Statement</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header">People</h6></li>
                            <li><a class="dropdown-item" href="workers.php"><i class="bi bi-people"></i> Workers</a></li>
                            <li><a class="dropdown-item" href="activities.php"><i class="bi bi-clipboard-check"></i> Activities</a></li>
                            <li><a class="dropdown-item" href="users.php"><i class="bi bi-people-fill"></i> Users</a></li>
                            <li><a class="dropdown-item" href="partners.php"><i class="bi bi-person-badge"></i> Partners</a></li>
                        </ul>
                    </li>

                    <!-- Nursery -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo $in(['nursery']) ? 'top-active' : ''; ?>" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-flower2"></i> Nursery
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="nursery_stocks.php"><i class="bi bi-box-seam"></i> Seedling Stock</a></li>
                            <li><a class="dropdown-item" href="nursery_production.php"><i class="bi bi-graph-up"></i> Production Plan</a></li>
                            <li><a class="dropdown-item" href="nursery_distribution.php"><i class="bi bi-truck"></i> Distribution</a></li>
                        </ul>
                    </li>

                    <!-- Field Operations -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo $in(['work_orders','maintenance','fertilization','pest_control']) ? 'top-active' : ''; ?>" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-hammer"></i> Field Ops
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="work_orders.php"><i class="bi bi-clipboard-check"></i> Work Orders</a></li>
                            <li><a class="dropdown-item" href="maintenance.php"><i class="bi bi-tools"></i> Maintenance</a></li>
                            <li><a class="dropdown-item" href="fertilization.php"><i class="bi bi-droplet-fill"></i> Fertilization</a></li>
                            <li><a class="dropdown-item" href="pest_control.php"><i class="bi bi-bug"></i> Pest Control</a></li>
                        </ul>
                    </li>

                    <!-- Harvesting -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo $in(['harvest','ffb_delivery']) ? 'top-active' : ''; ?>" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-basket"></i> Harvesting
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="harvest_plans.php"><i class="bi bi-calendar-check"></i> Harvest Plans</a></li>
                            <li><a class="dropdown-item" href="harvest_realizations.php"><i class="bi bi-basket"></i> Realization</a></li>
                            <li><a class="dropdown-item" href="harvest_productivity.php"><i class="bi bi-person-badge"></i> Productivity</a></li>
                            <li><a class="dropdown-item" href="harvest_quality.php"><i class="bi bi-award"></i> Quality Control</a></li>
                            <li><a class="dropdown-item" href="ffb_delivery.php"><i class="bi bi-truck-flatbed"></i> FFB Delivery</a></li>
                        </ul>
                    </li>

                    <!-- Mill -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo $in(['mill_','inventory_cpo','inventory_kernel','cpo_stock','kernel_stock']) ? 'top-active' : ''; ?>" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-gear-wide-connected"></i> Mill
                        </a>
                        <ul class="dropdown-menu">
                            <li><h6 class="dropdown-header">Processing</h6></li>
                            <li><a class="dropdown-item" href="mill_processing.php"><i class="bi bi-gear-wide-connected"></i> Processing</a></li>
                            <li><a class="dropdown-item" href="mill_production.php"><i class="bi bi-bar-chart"></i> Production</a></li>
                            <li><a class="dropdown-item" href="mill_quality.php"><i class="bi bi-award"></i> Quality Control</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header">Inventory</h6></li>
                            <li><a class="dropdown-item" href="inventory_cpo.php"><i class="bi bi-droplet"></i> CPO Stock</a></li>
                            <li><a class="dropdown-item" href="inventory_kernel.php"><i class="bi bi-circle"></i> Kernel Stock</a></li>
                            <li><a class="dropdown-item" href="inventory_materials.php"><i class="bi bi-box"></i> Materials</a></li>
                        </ul>
                    </li>

                    <!-- Financial -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo $in(['journal_entries','gl_accounts','account_groups','financial_reports','budget','activity_budget','activity_norms','sales']) ? 'top-active' : ''; ?>" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-currency-dollar"></i> Financial
                        </a>
                        <ul class="dropdown-menu">
                            <li><h6 class="dropdown-header">Accounting</h6></li>
                            <li><a class="dropdown-item" href="journal_entries.php"><i class="bi bi-journal-text"></i> Journal Entries</a></li>
                            <li><a class="dropdown-item" href="gl_accounts.php"><i class="bi bi-list-ul"></i> GL Accounts</a></li>
                            <li><a class="dropdown-item" href="account_groups.php"><i class="bi bi-collection"></i> Account Groups</a></li>
                            <li><a class="dropdown-item" href="financial_reports.php"><i class="bi bi-graph-up-arrow"></i> Financial Reports</a></li>
                            <li><a class="dropdown-item" href="sales.php"><i class="bi bi-cart"></i> Sales</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header">Budgeting</h6></li>
                            <li><a class="dropdown-item" href="budget.php"><i class="bi bi-wallet2"></i> Budget</a></li>
                            <li><a class="dropdown-item" href="activity_budget_plans.php"><i class="bi bi-clipboard-check"></i> Budget Plans</a></li>
                            <li><a class="dropdown-item" href="activity_budget_monthly.php"><i class="bi bi-calendar-month"></i> Monthly Tracking</a></li>
                            <li><a class="dropdown-item" href="activity_budget_reports.php"><i class="bi bi-graph-up"></i> Budget Reports</a></li>
                            <li><a class="dropdown-item" href="activity_norms_manage.php"><i class="bi bi-speedometer2"></i> Activity Norms</a></li>
                            <li><a class="dropdown-item" href="activity_budget_capital.php"><i class="bi bi-building"></i> Capital Budget</a></li>
                            <li><a class="dropdown-item" href="budget_variance_report.php"><i class="bi bi-bar-chart-line"></i> Variance Report</a></li>
                        </ul>
                    </li>

                    <!-- Compliance / ISPO -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo $in(['ispo_criteria','ispo_assessment','ispo_gap_dashboard','corrective_actions']) ? 'top-active' : ''; ?>" href="#" data-bs-toggle="dropdown"
                           style="<?php echo $in(['ispo_criteria','ispo_assessment','ispo_gap_dashboard','corrective_actions']) ? '' : ''; ?>">
                            <i class="bi bi-patch-check-fill" style="color:#f59e0b;"></i> <span style="color:#f59e0b;">Compliance</span>
                        </a>
                        <ul class="dropdown-menu">
                            <li><h6 class="dropdown-header"><i class="bi bi-patch-check"></i> ISPO Standards</h6></li>
                            <li><a class="dropdown-item" href="ispo_criteria.php"><i class="bi bi-list-check"></i> ISPO Criteria</a></li>
                            <li><a class="dropdown-item" href="ispo_assessment.php"><i class="bi bi-clipboard2-check"></i> Assessments</a></li>
                            <li><a class="dropdown-item" href="ispo_gap_dashboard.php"><i class="bi bi-bar-chart-steps"></i> Gap Dashboard</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header"><i class="bi bi-tools"></i> Corrective Actions</h6></li>
                            <li><a class="dropdown-item" href="corrective_actions.php"><i class="bi bi-exclamation-triangle"></i> Action Plans (CAP)</a></li>
                        </ul>
                    </li>

                    <!-- Analysis Report --> 
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo $in(['qna']) ? 'top-active' : ''; ?>" href="#" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                            <i class="bi bi-bar-chart-line"></i> Analysis Report
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark-custom">
                            <li><a class="dropdown-item" href="qna.php?analisis=infrastruktur"><i class="bi bi-buildings"></i> Infrastructure Analysis</a></li>
                            <li><a class="dropdown-item" href="qna.php?analisis=pembibitan"><i class="bi bi-flower1"></i> Nursery Analysis</a></li>
                            <li><a class="dropdown-item" href="qna.php?analisis=bahan_kimia"><i class="bi bi-eyedropper"></i> Chemicals Analysis</a></li>
                            <li><a class="dropdown-item" href="qna.php?analisis=pemupukan"><i class="bi bi-droplet-fill"></i> Fertilization Analysis</a></li>
                            <li><a class="dropdown-item" href="qna.php?analisis=gulma"><i class="bi bi-tree"></i> Weed Analysis</a></li>
                            <li><a class="dropdown-item" href="qna.php?analisis=hama_penyakit"><i class="bi bi-bug"></i> Pest &amp; Disease Analysis</a></li>
                            <li><a class="dropdown-item" href="qna.php?analisis=perkebunan"><i class="bi bi-map"></i> Plantation Analysis</a></li>
                            <li><a class="dropdown-item" href="qna.php?analisis=pabrik"><i class="bi bi-gear-wide-connected"></i> Mill Analysis</a></li>
<!--                            <li><a class="dropdown-item" href="qna.php?analisis=keberlanjutan"><i class="bi bi-globe"></i> Sustainability Analysis</a></li> -->
                            <li><a class="dropdown-item" href="qna.php?analisis=keuangan"><i class="bi bi-journal-text"></i> Financial Analysis</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="qna.php"><i class="bi bi-chat-dots"></i> Open Q&amp;A Chat</a></li>
                        </ul>
                    </li> 
					
                    <!-- Reports -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo $in(['reports','analytics','dashboard_kpi']) ? 'top-active' : ''; ?>" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-graph-up-arrow"></i> Reports
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="reports.php"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a></li>
                            <li><a class="dropdown-item" href="analytics.php"><i class="bi bi-graph-up-arrow"></i> Analytics</a></li>
                            <li><a class="dropdown-item" href="dashboard_kpi.php"><i class="bi bi-speedometer"></i> KPI Dashboard</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php /* React Dashboard — hidden, not deleted
                            <li><a class="dropdown-item" href="/epragrosmart/react/dist/" target="_blank"><i class="bi bi-box-arrow-up-right text-success"></i> <span class="text-success">React Dashboard ↗</span></a></li>
                            */ ?>
                        </ul>
                    </li>

                    <!-- SmartMap — standalone top-level link -->
                    <li class="nav-item">
                        <a class="nav-link fw-semibold <?php echo $in(['blocks_map']) ? 'top-active' : ''; ?>" href="blocks_map.php"
                           style="color:#4ade80 !important; border: 1px solid rgba(74,222,128,0.45); border-radius:6px; padding: 0.35rem 0.75rem !important; margin-left:4px;">
                            <i class="bi bi-map-fill"></i> SmartMap
                        </a>
                    </li>

                    <!-- Hackathon Submission — standalone top-level link -->
                    <li class="nav-item">
                        <a class="nav-link fw-semibold" href="hackathon.php" target="_blank"
                           style="color:#fbbf24 !important; border: 1px solid rgba(251,191,36,0.5); border-radius:6px; padding: 0.35rem 0.75rem !important; margin-left:4px;">
                            <i class="bi bi-award-fill"></i> Hackathon
                        </a>
                    </li>

                </ul>

                <!-- Right side: user menu -->
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($logged_in_user); ?>
                            <span class="badge bg-success ms-1"><?php echo ucfirst($user_role); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><h6 class="dropdown-header"><i class="bi bi-person"></i> <?php echo htmlspecialchars($logged_in_user); ?></h6></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="user_defaults.php"><i class="bi bi-gear"></i> User Settings</a></li>
                            <li><a class="dropdown-item" href="users.php"><i class="bi bi-people"></i> Manage Users</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 col-xl-2 d-md-block sidebar">
                <div class="position-sticky">
                    <ul class="nav flex-column">
                        <!-- Dashboard -->
                        <li class="nav-item">
                            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>" href="index.php">
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a>
                        </li>
                        <?php /* React Dashboard — hidden, not deleted
                        <li class="nav-item">
                            <a class="nav-link" href="/erpagrosmart/react/dist/" target="_blank">
                                <i class="bi bi-graph-up-arrow text-success"></i> <span class="text-success fw-semibold">React Dashboard ↗</span>
                            </a>
                        </li>
                        */ ?>
                        
                        <!-- Master Data Section -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading" data-bs-toggle="collapse" data-bs-target="#masterDataMenu">
                                <span>MASTER DATA</span>
                                <i class="bi bi-chevron-down"></i>
                            </h6>
                        </li>
                        <div class="collapse menu-section" id="masterDataMenu">
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'companies') !== false) ? 'active' : ''; ?>" href="companies.php">
                                <i class="bi bi-building"></i> Companies
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'business_units') !== false) ? 'active' : ''; ?>" href="business_units.php">
                                <i class="bi bi-diagram-3"></i> Business Units
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'divisions') !== false) ? 'active' : ''; ?>" href="divisions.php">
                                <i class="bi bi-grid-3x3"></i> Divisions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'planting_years') !== false) ? 'active' : ''; ?>" href="planting_years.php">
                                <i class="bi bi-calendar-event"></i> Planting Years
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'blocks') !== false) ? 'active' : ''; ?>" href="blocks.php">
                                <i class="bi bi-grid"></i> Blocks
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'block_area_components') !== false) ? 'active' : ''; ?>" href="block_area_components.php">
                                <i class="bi bi-grid-3x3"></i> Block Area Components
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'area_component_config') !== false) ? 'active' : ''; ?>" href="area_component_config.php">
                                <i class="bi bi-gear"></i> Area Config
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'areal_statement') !== false) ? 'active' : ''; ?>" href="areal_statement_dynamic.php">
                                <i class="bi bi-file-earmark-text"></i> Areal Statement
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'plant_varieties') !== false) ? 'active' : ''; ?>" href="plant_varieties.php">
                                <i class="bi bi-flower1"></i> Plant Varieties
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'workers') !== false) ? 'active' : ''; ?>" href="workers.php">
                                <i class="bi bi-people"></i> Workers
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'activities') !== false) ? 'active' : ''; ?>" href="activities.php">
                                <i class="bi bi-clipboard-check"></i> Activities
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'users') !== false) ? 'active' : ''; ?>" href="users.php">
                                <i class="bi bi-people-fill"></i> Users
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'partners') !== false) ? 'active' : ''; ?>" href="partners.php">
                                <i class="bi bi-person-badge"></i> Partners
                            </a>
                        </li>
                        </div>
                        
                        <!-- Nursery Management -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading" data-bs-toggle="collapse" data-bs-target="#nurseryMenu">
                                <span>NURSERY</span>
                                <i class="bi bi-chevron-down"></i>
                            </h6>
                        </li>
                        <div class="collapse menu-section" id="nurseryMenu">
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'nursery_stocks') !== false) ? 'active' : ''; ?>" href="nursery_stocks.php">
                                <i class="bi bi-box-seam"></i> Seedling Stock
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="nursery_production.php">
                                <i class="bi bi-graph-up"></i> Production Plan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="nursery_distribution.php">
                                <i class="bi bi-truck"></i> Distribution
                            </a>
                        </div>
                        
                        <!-- Field Operations -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading" data-bs-toggle="collapse" data-bs-target="#fieldOpsMenu">
                                <span>FIELD OPERATIONS</span>
                                <i class="bi bi-chevron-down"></i>
                            </h6>
                        </li>
                        <div class="collapse menu-section" id="fieldOpsMenu">
                        <li class="nav-item">
                            <a class="nav-link" href="work_orders.php">
                                <i class="bi bi-clipboard-check"></i> Work Orders
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="maintenance.php">
                                <i class="bi bi-tools"></i> Maintenance
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="fertilization.php">
                                <i class="bi bi-droplet-fill"></i> Fertilization
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="pest_control.php">
                                <i class="bi bi-bug"></i> Pest Control
                            </a>
                        </div>
                        
                        <!-- Harvesting -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading" data-bs-toggle="collapse" data-bs-target="#harvestMenu">
                                <span>HARVESTING</span>
                                <i class="bi bi-chevron-down"></i>
                            </h6>
                        </li>
                        <div class="collapse menu-section" id="harvestMenu">
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'harvest_plans') !== false) ? 'active' : ''; ?>" href="harvest_plans.php">
                                <i class="bi bi-calendar-check"></i> Harvest Plans
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'harvest_realizations') !== false) ? 'active' : ''; ?>" href="harvest_realizations.php">
                                <i class="bi bi-basket"></i> Harvest Realization
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'harvest_productivity') !== false) ? 'active' : ''; ?>" href="harvest_productivity.php">
                                <i class="bi bi-person-badge"></i> Productivity
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'harvest_quality') !== false) ? 'active' : ''; ?>" href="harvest_quality.php">
                                <i class="bi bi-award"></i> Quality Control
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'ffb_delivery') !== false) ? 'active' : ''; ?>" href="ffb_delivery.php">
                                <i class="bi bi-truck-flatbed"></i> FFB Delivery
                            </a>
                        </div>
                        
                        <!-- Mill Operations -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading" data-bs-toggle="collapse" data-bs-target="#millMenu">
                                <span>MILL OPERATIONS</span>
                                <i class="bi bi-chevron-down"></i>
                            </h6>
                        </li>
                        <div class="collapse menu-section" id="millMenu">
                        <li class="nav-item">
                            <a class="nav-link" href="mill_processing.php">
                                <i class="bi bi-gear-wide-connected"></i> Processing
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="mill_production.php">
                                <i class="bi bi-bar-chart"></i> Production
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="mill_quality.php">
                                <i class="bi bi-award"></i> Quality Control
                            </a>
                        </div>
                        
                        <!-- Inventory & Logistics -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading" data-bs-toggle="collapse" data-bs-target="#inventoryMenu">
                                <span>INVENTORY</span>
                                <i class="bi bi-chevron-down"></i>
                            </h6>
                        </li>
                        <div class="collapse menu-section" id="inventoryMenu">
                        <li class="nav-item">
                            <a class="nav-link" href="inventory_cpo.php">
                                <i class="bi bi-droplet"></i> CPO Stock
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="inventory_kernel.php">
                                <i class="bi bi-circle"></i> Kernel Stock
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="inventory_materials.php">
                                <i class="bi bi-box"></i> Materials
                            </a>
                        </div>
                        
                        <!-- Financial -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading" data-bs-toggle="collapse" data-bs-target="#financialMenu">
                                <span>FINANCIAL</span>
                                <i class="bi bi-chevron-down"></i>
                            </h6>
                        </li>
                        <div class="collapse menu-section" id="financialMenu">
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'journal_entries') !== false) ? 'active' : ''; ?>" href="journal_entries.php">
                                <i class="bi bi-journal-text"></i> Journal Entries
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'gl_accounts') !== false) ? 'active' : ''; ?>" href="gl_accounts.php">
                                <i class="bi bi-list-ul"></i> GL Accounts
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'account_groups') !== false) ? 'active' : ''; ?>" href="account_groups.php">
                                <i class="bi bi-collection"></i> Account Groups
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'financial_reports') !== false) ? 'active' : ''; ?>" href="financial_reports.php">
                                <i class="bi bi-graph-up-arrow"></i> Financial Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="budget.php">
                                <i class="bi bi-wallet2"></i> Budget
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'activity_budget_plans') !== false) ? 'active' : ''; ?>" href="activity_budget_plans.php">
                                <i class="bi bi-clipboard-check"></i> Activity Budget Plans
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'activity_budget_monthly') !== false) ? 'active' : ''; ?>" href="activity_budget_monthly.php">
                                <i class="bi bi-calendar-month"></i> Monthly Tracking
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'activity_budget_reports') !== false) ? 'active' : ''; ?>" href="activity_budget_reports.php">
                                <i class="bi bi-graph-up"></i> Budget Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'activity_norms_manage') !== false) ? 'active' : ''; ?>" href="activity_norms_manage.php">
                                <i class="bi bi-speedometer2"></i> Activity Norms
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'activity_budget_capital') !== false) ? 'active' : ''; ?>" href="activity_budget_capital.php">
                                <i class="bi bi-building"></i> Capital Budget
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'budget_variance_report') !== false) ? 'active' : ''; ?>" href="budget_variance_report.php">
                                <i class="bi bi-bar-chart-line"></i> Variance Report
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="sales.php">
                                <i class="bi bi-cart"></i> Sales
                            </a>
                        </div>
                        
                        <!-- Reports & Analytics -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading" data-bs-toggle="collapse" data-bs-target="#reportsMenu">
                                <span>REPORTS</span>
                                <i class="bi bi-chevron-down"></i>
                            </h6>
                        </li>
                        <div class="collapse menu-section" id="reportsMenu">
                        <li class="nav-item">
                            <a class="nav-link" href="reports.php">
                                <i class="bi bi-file-earmark-bar-graph"></i> Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="analytics.php">
                                <i class="bi bi-graph-up-arrow"></i> Analytics
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard_kpi.php">
                                <i class="bi bi-speedometer"></i> KPI Dashboard
                            </a>
                        </div>

                        <!-- SmartMap -->
                        <li class="nav-item mt-2">
                            <a class="nav-link fw-semibold <?php echo $in(['blocks_map']) ? 'active' : ''; ?>"
                               href="blocks_map.php"
                               style="color:#1b5e20; background:rgba(74,222,128,0.18); border:1px solid rgba(74,222,128,0.45); border-radius:6px; margin: 4px 8px;">
                                <i class="bi bi-map-fill" style="color:#16a34a;"></i> SmartMap
                            </a>
                        </li>

                        <!-- Hackathon -->
                        <li class="nav-item mt-1">
                            <a class="nav-link fw-semibold"
                               href="hackathon.php" target="_blank"
                               style="color:#78350f; background:rgba(251,191,36,0.15); border:1px solid rgba(251,191,36,0.45); border-radius:6px; margin: 4px 8px;">
                                <i class="bi bi-award-fill" style="color:#f59e0b;"></i> Hackathon Submission ↗
                            </a>
                        </li>

                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 col-lg-10 col-xl-10 ms-sm-auto px-md-4 content-wrapper">
                <?php display_message(); ?>

// Made with Bob
