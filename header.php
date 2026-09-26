<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Agrobusiness Solution</title>
    
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
            background-color: #f5f5f5;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .navbar-brand {
            font-weight: bold;
            font-size: 1.3rem;
        }
        
        .sidebar {
            min-height: calc(100vh - 56px);
            background-color: #fff;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            padding: 10px 0;
            max-width: 240px;
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
            padding: 0;
            margin-top: 8px !important;
            margin-bottom: 2px !important;
            color: var(--primary-color) !important;
        }
        
        .sidebar {
            max-height: calc(100vh - 56px);
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
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-tree"></i> Agrobusiness Solution
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> Admin
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#"><i class="bi bi-gear"></i> Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
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
                        
                        <!-- Master Data Section -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading px-3 text-muted">
                                <span>MASTER DATA</span>
                            </h6>
                        </li>
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
                        
                        <!-- Nursery Management -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading px-3 text-muted">
                                <span>NURSERY</span>
                            </h6>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="nursery_stock.php">
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
                        </li>
                        
                        <!-- Field Operations -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading px-3 text-muted">
                                <span>FIELD OPERATIONS</span>
                            </h6>
                        </li>
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
                        </li>
                        
                        <!-- Harvesting -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading px-3 text-muted">
                                <span>HARVESTING</span>
                            </h6>
                        </li>
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
                        </li>
                        
                        <!-- Mill Operations -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading px-3 text-muted">
                                <span>MILL OPERATIONS</span>
                            </h6>
                        </li>
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
                        </li>
                        
                        <!-- Inventory & Logistics -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading px-3 text-muted">
                                <span>INVENTORY</span>
                            </h6>
                        </li>
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
                        </li>
                        
                        <!-- Financial -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading px-3 text-muted">
                                <span>FINANCIAL</span>
                            </h6>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="block_costing.php">
                                <i class="bi bi-calculator"></i> Block Costing
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="budget.php">
                                <i class="bi bi-wallet2"></i> Budget
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="sales.php">
                                <i class="bi bi-cart"></i> Sales
                            </a>
                        </li>
                        
                        <!-- Reports & Analytics -->
                        <li class="nav-item mt-2">
                            <h6 class="sidebar-heading px-3 text-muted">
                                <span>REPORTS</span>
                            </h6>
                        </li>
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
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 col-lg-10 col-xl-10 ms-sm-auto px-md-4 content-wrapper">
                <?php display_message(); ?>

// Made with Bob
