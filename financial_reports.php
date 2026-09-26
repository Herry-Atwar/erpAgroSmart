<?php
require_once 'includes/header.php';
require_once 'includes/lang.php';

// Get filter parameters — default to the logged-in user's assigned org units
$report_type  = $_GET['report']        ?? 'profit_loss';
$company_id   = $_GET['company_id']    ?? ($_SESSION['company_id']       ?? '');
$business_unit_id = $_GET['estate_id'] ?? ($_SESSION['business_unit_id'] ?? '');
$division_id  = $_GET['division_id']   ?? ($_SESSION['division_id']      ?? '');
$block_id     = $_GET['block_id']      ?? '';
$activity_id  = $_GET['activity_id']   ?? '';

// Default date range: use the actual min/max of posted entries so first load
// always shows data regardless of what year the cloud data was seeded in.
if (isset($_GET['date_from'])) {
    $date_from = $_GET['date_from'];
    $date_to   = $_GET['date_to'] ?? date('Y-m-t');
} else {
    try {
        $_dr = $pdo->query(
            "SELECT MAX(entry_date) as max_d
               FROM journal_entries WHERE status = 'posted'"
        )->fetch(PDO::FETCH_ASSOC);
        if ($_dr && $_dr['max_d']) {
            $max_year  = substr($_dr['max_d'], 0, 4);
            $date_from = $max_year . '-01-01';
            $date_to   = $_dr['max_d'];
        } else {
            $date_from = date('Y-01-01');
            $date_to   = date('Y-m-t');
        }
    } catch (Exception $_e) {
        $date_from = date('Y-01-01');
        $date_to   = date('Y-m-t');
    }
}
$cost_category  = $_GET['cost_category'] ?? '';
$status_filter  = $_GET['status']        ?? '';

// Keep legacy alias so existing WHERE-clause references still work
$estate_id = $business_unit_id;

// Get organizational data for filters
$sess_company_id = $_SESSION['company_id']       ?? null;
$sess_bu_id      = $_SESSION['business_unit_id'] ?? null;

try {
    $companies = $pdo->query("SELECT company_id, company_name FROM companies ORDER BY company_name")->fetchAll();

    // Business units: scope to user's company if assigned
    if ($sess_company_id) {
        $stmt = $pdo->prepare("SELECT business_unit_id as estate_id, unit_name as estate_name, company_id FROM business_units WHERE company_id = ? ORDER BY unit_name");
        $stmt->execute([$sess_company_id]);
    } else {
        $stmt = $pdo->query("SELECT business_unit_id as estate_id, unit_name as estate_name, company_id FROM business_units ORDER BY unit_name");
    }
    $estates = $stmt->fetchAll();

    // Divisions: scope to user's business unit if assigned
    if ($sess_bu_id) {
        $stmt = $pdo->prepare("SELECT division_id, division_name, business_unit_id as estate_id FROM divisions WHERE business_unit_id = ? ORDER BY division_name");
        $stmt->execute([$sess_bu_id]);
    } elseif ($sess_company_id) {
        $stmt = $pdo->prepare("SELECT d.division_id, d.division_name, d.business_unit_id as estate_id FROM divisions d INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id WHERE bu.company_id = ? ORDER BY d.division_name");
        $stmt->execute([$sess_company_id]);
    } else {
        $stmt = $pdo->query("SELECT division_id, division_name, business_unit_id as estate_id FROM divisions ORDER BY division_name");
    }
    $divisions = $stmt->fetchAll();

    // Blocks
    $blocks = $pdo->query("SELECT block_id, block_code, block_name FROM blocks ORDER BY block_code")->fetchAll();

    // Activities
    $activities = $pdo->query("SELECT id as activity_id, activity_code, activity_name FROM activities ORDER BY activity_code")->fetchAll();
} catch (Exception $e) {
    die("Database error: " . $e->getMessage() . "<br><br>Please check that all required tables exist in your database.");
}

$cost_categories = ['labor', 'material', 'vehicle_equipment', 'overhead', 'other'];
$block_statuses = ['LC', 'TBM', 'TM', 'Nursery', 'Replanting', 'HL', 'HP', 'HPT'];
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 style="color: #166c82;"><i class="bi bi-graph-up-arrow" style="color: #166c82;"></i> <?php echo __('financial_report_title'); ?></h2>
            <p class="text-muted"><?php echo __('financial_report_desc'); ?></p>
        </div>
    </div>

    <!-- Report Type Selection -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="btn-group flex-wrap" role="group">
                <a href="?report=profit_loss" class="btn btn-<?= $report_type === 'profit_loss' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'profit_loss' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-graph-up-arrow"></i> <?php echo __('fr_profit_loss'); ?>
                </a>
                <a href="?report=income_statement_chart" class="btn btn-<?= $report_type === 'income_statement_chart' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'income_statement_chart' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-bar-chart-steps"></i> <?php echo __('isc_nav_btn'); ?>
                </a>
                <a href="?report=balance_sheet_chart" class="btn btn-<?= $report_type === 'balance_sheet_chart' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'balance_sheet_chart' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-pie-chart"></i> <?php echo __('bsc_nav_btn'); ?>
                </a>
                <a href="?report=profit_loss_detail" class="btn btn-<?= $report_type === 'profit_loss_detail' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'profit_loss_detail' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-list-ul"></i> <?php echo __('fr_detail_profit_loss'); ?>
                </a>
                <a href="?report=balance_sheet_group" class="btn btn-<?= $report_type === 'balance_sheet_group' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'balance_sheet_group' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-bank"></i> <?php echo __('fr_balance_sheet'); ?>
                </a>
                <a href="?report=balance_sheet" class="btn btn-<?= $report_type === 'balance_sheet' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'balance_sheet' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-list-ul"></i> <?php echo __('fr_detail_balance_sheet'); ?>
                </a>
                <a href="?report=trial_balance" class="btn btn-<?= $report_type === 'trial_balance' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'trial_balance' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-journal-text"></i> <?php echo __('tb_title'); ?>
                </a>
                <a href="?report=general_ledger" class="btn btn-<?= $report_type === 'general_ledger' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'general_ledger' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-journal-richtext"></i> <?php echo __('glr_title'); ?>
                </a>
                <a href="?report=financial_ratios" class="btn btn-<?= $report_type === 'financial_ratios' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'financial_ratios' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-percent"></i> <?php echo __('fr_financial_ratios'); ?>
                </a>
                <a href="?report=dashboard" class="btn btn-<?= $report_type === 'dashboard' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'dashboard' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a href="?report=cost_by_block" class="btn btn-<?= $report_type === 'cost_by_block' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'cost_by_block' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-grid-3x3"></i> Cost by Block
                </a>
                <a href="?report=cost_by_activity" class="btn btn-<?= $report_type === 'cost_by_activity' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'cost_by_activity' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-list-task"></i> Cost by Activity
                </a>
                <a href="?report=cost_by_category" class="btn btn-<?= $report_type === 'cost_by_category' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'cost_by_category' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-pie-chart-fill"></i> Cost by Category
                </a>
                <a href="?report=block_profitability" class="btn btn-<?= $report_type === 'block_profitability' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'block_profitability' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-currency-dollar"></i> Block Profitability
                </a>
                <a href="?report=monthly_trends" class="btn btn-<?= $report_type === 'monthly_trends' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'monthly_trends' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-graph-up"></i> Monthly Trends
                </a>
                <a href="?report=cost_variance" class="btn btn-<?= $report_type === 'cost_variance' ? 'primary' : 'outline-primary' ?>" <?= $report_type === 'cost_variance' ? 'style="background-color: #166c82; border-color: #166c82;"' : '' ?>>
                    <i class="bi bi-bar-chart"></i> Cost Variance
                </a>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header text-white" style="background-color: #166c82;">
            <h5 class="mb-0"><i class="bi bi-funnel"></i> <?php echo __('fr_filters'); ?></h5>
        </div>
        <div class="card-body">
            <form method="GET" action="" id="filterForm">
                <input type="hidden" name="report" value="<?= htmlspecialchars($report_type) ?>">
                <div class="row g-3">
                    <?php
                    $lock_company  = !empty($_SESSION['company_id']);
                    $lock_bu       = !empty($_SESSION['business_unit_id']);
                    $lock_division = !empty($_SESSION['division_id']);
                    ?>
                    <div class="col-md-2">
                        <label class="form-label">
                            <?php echo __('fr_company'); ?>
                            <?php if ($lock_company): ?><i class="bi bi-lock-fill text-muted ms-1" title="Assigned to your account" style="font-size:0.75rem;"></i><?php endif; ?>
                        </label>
                        <select name="company_id" class="form-select" id="companyFilter" <?= $lock_company ? 'disabled' : '' ?>>
                            <option value=""><?php echo __('fr_all_companies'); ?></option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= $company['company_id'] ?>" <?= $company_id == $company['company_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($company['company_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($lock_company): ?>
                            <input type="hidden" name="company_id" value="<?= htmlspecialchars($company_id) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">
                            <?php echo __('fr_business_unit'); ?>
                            <?php if ($lock_bu): ?><i class="bi bi-lock-fill text-muted ms-1" title="Assigned to your account" style="font-size:0.75rem;"></i><?php endif; ?>
                        </label>
                        <select name="estate_id" class="form-select" id="estateFilter" <?= $lock_bu ? 'disabled' : '' ?>>
                            <option value=""><?php echo __('fr_all_business_units'); ?></option>
                            <?php foreach ($estates as $estate): ?>
                                <option value="<?= $estate['estate_id'] ?>" data-company="<?= $estate['company_id'] ?>" <?= $business_unit_id == $estate['estate_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($estate['estate_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($lock_bu): ?>
                            <input type="hidden" name="estate_id" value="<?= htmlspecialchars($business_unit_id) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">
                            <?php echo __('fr_division'); ?>
                            <?php if ($lock_division): ?><i class="bi bi-lock-fill text-muted ms-1" title="Assigned to your account" style="font-size:0.75rem;"></i><?php endif; ?>
                        </label>
                        <select name="division_id" class="form-select" id="divisionFilter" <?= $lock_division ? 'disabled' : '' ?>>
                            <option value=""><?php echo __('fr_all_divisions'); ?></option>
                            <?php foreach ($divisions as $division): ?>
                                <option value="<?= $division['division_id'] ?>" data-estate="<?= $division['estate_id'] ?>" <?= $division_id == $division['division_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($division['division_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($lock_division): ?>
                            <input type="hidden" name="division_id" value="<?= htmlspecialchars($division_id) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_block'); ?></label>
                        <select name="block_id" class="form-select" id="blockFilter">
                            <option value=""><?php echo __('fr_all_blocks'); ?></option>
                            <?php foreach ($blocks as $block): ?>
                                <option value="<?= $block['block_id'] ?>" <?= $block_id == $block['block_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($block['block_code'] . ' - ' . $block['block_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_activity'); ?></label>
                        <select name="activity_id" class="form-select">
                            <option value=""><?php echo __('fr_all_activities'); ?></option>
                            <?php foreach ($activities as $activity): ?>
                                <option value="<?= $activity['activity_id'] ?>" <?= $activity_id == $activity['activity_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($activity['activity_code'] . ' - ' . $activity['activity_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_cost_category'); ?></label>
                        <select name="cost_category" class="form-select">
                            <option value=""><?php echo __('fr_all_categories'); ?></option>
                            <?php foreach ($cost_categories as $cat): ?>
                                <option value="<?= $cat ?>" <?= $cost_category === $cat ? 'selected' : '' ?>>
                                    <?= ucfirst(str_replace('_', ' ', $cat)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_block_status'); ?></label>
                        <select name="status" class="form-select">
                            <option value=""><?php echo __('fr_all_statuses'); ?></option>
                            <?php foreach ($block_statuses as $status): ?>
                                <option value="<?= $status ?>" <?= $status_filter === $status ? 'selected' : '' ?>>
                                    <?= $status ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_date_from'); ?></label>
                        <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?php echo __('fr_date_to'); ?></label>
                        <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-custom-fr me-2">
                            <i class="bi bi-search"></i> <?php echo __('fr_apply'); ?>
                        </button>
                        <a href="?report=<?= $report_type ?>" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> <?php echo __('fr_reset'); ?>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php
    $entry_id_column = "je.id";
    
    // Build WHERE clause for filters
    $where_conditions = ["je.status = 'posted'"];
    $params = [];

    if ($company_id) {
        $where_conditions[] = "je.company_id = :company_id";
        $params[':company_id'] = $company_id;
    }
    if ($business_unit_id) {
        $where_conditions[] = "je.business_unit_id = :estate_id";
        $params[':estate_id'] = $business_unit_id;
    }
    if ($division_id) {
        $where_conditions[] = "je.division_id = :division_id";
        $params[':division_id'] = $division_id;
    }
    if ($block_id) {
        $where_conditions[] = "je.block_id = :block_id";
        $params[':block_id'] = $block_id;
    }
    if ($activity_id) {
        $where_conditions[] = "jel.activity_id = :activity_id";
        $params[':activity_id'] = $activity_id;
    }
    if ($cost_category) {
        $where_conditions[] = "jel.cost_category = :cost_category";
        $params[':cost_category'] = $cost_category;
    }
    if ($status_filter) {
        $where_conditions[] = "b.status = :status";
        $params[':status'] = $status_filter;
    }
    if ($date_from) {
        $where_conditions[] = "je.entry_date >= :date_from";
        $params[':date_from'] = $date_from;
    }
    if ($date_to) {
        $where_conditions[] = "je.entry_date <= :date_to";
        $params[':date_to'] = $date_to;
    }

    $where_clause = implode(' AND ', $where_conditions);

    // P&L-safe WHERE clause: excludes "b.status" which requires a blocks JOIN
    $pl_where_conditions = array_filter($where_conditions, fn($c) => strpos($c, 'b.status') === false);
    $pl_where_clause = implode(' AND ', $pl_where_conditions);
    $pl_params = $params;
    unset($pl_params[':status']);

    // Render the selected report
    switch ($report_type) {
        case 'profit_loss':
            include 'reports/profit_loss.php';
            break;
        case 'profit_loss_detail':
            include 'reports/profit_loss_detail.php';
            break;
        case 'balance_sheet':
            include 'reports/balance_sheet.php';
            break;
        case 'balance_sheet_group':
            include 'reports/balance_sheet_group.php';
            break;
        case 'trial_balance':
            include 'reports/trial_balance.php';
            break;
        case 'general_ledger':
            include 'reports/general_ledger.php';
            break;
        case 'income_statement_chart':
            include 'reports/income_statement_chart.php';
            break;
        case 'balance_sheet_chart':
            include 'reports/balance_sheet_chart.php';
            break;
        case 'financial_ratios':
            include 'reports/financial_ratios.php';
            break;
        case 'dashboard':
            include 'reports/dashboard_overview.php';
            break;
        case 'cost_by_block':
            include 'reports/cost_by_block.php';
            break;
        case 'cost_by_activity':
            include 'reports/cost_by_activity.php';
            break;
        case 'cost_by_category':
            include 'reports/cost_by_category.php';
            break;
        case 'block_profitability':
            include 'reports/block_profitability.php';
            break;
        case 'monthly_trends':
            include 'reports/monthly_trends.php';
            break;
        case 'cost_variance':
            include 'reports/cost_variance.php';
            break;
        default:
            echo '<div class="alert alert-warning">' . __('fr_report_not_found') . '</div>';
    }
    ?>
</div>

<script>
// Cascading filters
document.getElementById('companyFilter').addEventListener('change', function() {
    const companyId = this.value;
    const estateFilter = document.getElementById('estateFilter');
    const options = estateFilter.querySelectorAll('option');
    
    options.forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
        } else if (!companyId || option.dataset.company === companyId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
    
    if (companyId) {
        estateFilter.value = '';
    }
});

document.getElementById('estateFilter').addEventListener('change', function() {
    const estateId = this.value;
    const divisionFilter = document.getElementById('divisionFilter');
    const options = divisionFilter.querySelectorAll('option');
    
    options.forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
        } else if (!estateId || option.dataset.estate === estateId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
    
    if (estateId) {
        divisionFilter.value = '';
    }
});

document.getElementById('divisionFilter').addEventListener('change', function() {
    const divisionId = this.value;
    const blockFilter = document.getElementById('blockFilter');
    const options = blockFilter.querySelectorAll('option');
    
    options.forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
        } else if (!divisionId || option.dataset.division === divisionId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
    
    if (divisionId) {
        blockFilter.value = '';
    }
});

// Export functions
const _activeDateFrom = <?= json_encode($date_from) ?>;
const _activeDateTo   = <?= json_encode($date_to) ?>;

function exportToExcel(reportType) {
    const params = new URLSearchParams(window.location.search);
    params.set('date_from', _activeDateFrom);
    params.set('date_to',   _activeDateTo);
    params.set('export', 'excel');

    const printBy = document.querySelector('meta[name="x-username"]')?.content || '';
    const now     = new Date();
    const pad     = n => String(n).padStart(2, '0');
    const printDT = `${pad(now.getDate())}/${pad(now.getMonth()+1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
    const _area   = document.getElementById('pl-print-area') || document.getElementById('pld-print-area');
    const _i18n   = _area ? JSON.parse(_area.dataset.i18n || '{}') : {};
    params.set('printed_by',    printBy);
    params.set('print_dt',      printDT);
    params.set('print_by_lbl',  _i18n.print_by  || 'Print by');
    params.set('datetime_lbl',  _i18n.datetime  || 'Date/Time');

    window.location.href = 'export_report.php?' + params.toString();
}

function exportToPDF(reportType) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'pdf');
    window.open('export_report.php?' + params.toString(), '_blank');
}

function printReport() {
    window.print();
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Clean print window for Profit & Loss (#pl-print-area)
 */
function printProfitLoss() {
    const area = document.getElementById('pl-print-area');
    if (!area) { window.print(); return; }

    const tbl = area.querySelector('table');
    if (!tbl) { window.print(); return; }

    const tblClone = tbl.cloneNode(true);
    tblClone.querySelectorAll('i.bi').forEach(el => el.remove());
    tblClone.querySelectorAll('.no-print').forEach(el => el.remove());

    const fmtDate  = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const dateFrom = fmtDate(area.dataset.dateFrom || '');
    const dateTo   = fmtDate(area.dataset.dateTo   || '');
    const i18n     = JSON.parse(area.dataset.i18n  || '{}');
    const title    = i18n.title    || 'Profit and Loss Statement';
    const period   = i18n.period   || 'Period';
    const printByL = i18n.print_by || 'Print by';
    const dateTimeL= i18n.datetime || 'Date/Time';
    const company  = document.querySelector('meta[name="x-company"]')?.content || 'erpAgroSmart';
    const printBy  = document.querySelector('meta[name="x-username"]')?.content || '';
    const now      = new Date();
    const pad      = n => String(n).padStart(2, '0');
    const printDT  = `${pad(now.getDate())}/${pad(now.getMonth()+1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

    const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>${escHtml(title)}</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10.5pt; color: #1a1a1a; margin: 0; padding: 0; background: #fff; }
  .rpt-printinfo { text-align: right; font-size: 8.5pt; color: #555; line-height: 1.6; margin-bottom: 6px; }
  .rpt-header { text-align: center; border-bottom: 2px solid #166c82; padding-bottom: 8px; margin-bottom: 16px; }
  .rpt-company { font-size: 12pt; font-weight: 700; color: #166c82; letter-spacing: .3px; }
  .rpt-title   { font-size: 14pt; font-weight: 700; margin: 4px 0 2px; color: #1a1a1a; }
  .rpt-period  { font-size: 9.5pt; color: #555; }
  table { width: 100%; border-collapse: collapse; font-size: 9pt; }
  th, td { border: 1px solid #bbb; padding: 3px 7px; vertical-align: middle; }
  thead th { background-color: #e9ecef !important; font-weight: 700; text-align: left; }
  thead th:not(:first-child) { text-align: right; }
  .text-end  { text-align: right !important; }
  .ps-4      { padding-left: 18px !important; }
  .text-success { color: #1b5e20 !important; }
  .text-danger  { color: #b71c1c !important; }
  .text-primary { color: #0d47a1 !important; }
  .text-muted   { color: #555 !important; }
  .fw-semibold, .fw-bold, b, strong { font-weight: 700; }
  .small, small { font-size: 85%; }
  @page { size: A4 portrait; margin: 14mm 12mm 14mm 12mm; }
  @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body>
<div class="rpt-printinfo">
  ${escHtml(printByL)} : ${escHtml(printBy)}<br>
  ${escHtml(dateTimeL)} : ${escHtml(printDT)}
</div>
<div class="rpt-header">
  <div class="rpt-company">${escHtml(company)}</div>
  <div class="rpt-title">${escHtml(title)}</div>
  <div class="rpt-period">${escHtml(period)}: <strong>${escHtml(dateFrom)}</strong> &mdash; <strong>${escHtml(dateTo)}</strong></div>
</div>
${tblClone.outerHTML}
</body>
</html>`;

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); }, 400);
}

/**
 * Clean print window for Detail P&L (#pld-print-area)
 */
function printDetailPL() {
    const area = document.getElementById('pld-print-area');
    if (!area) { window.print(); return; }

    const fmtDate  = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const dateFrom = fmtDate(area.dataset.dateFrom || '');
    const dateTo   = fmtDate(area.dataset.dateTo   || '');
    const i18n     = JSON.parse(area.dataset.i18n  || '{}');
    const title    = i18n.title    || 'Detail Profit and Loss Statement';
    const period   = i18n.period   || 'Period';
    const printByL = i18n.print_by || 'Print by';
    const dateTimeL= i18n.datetime || 'Date/Time';
    const company  = document.querySelector('meta[name="x-company"]')?.content || 'erpAgroSmart';
    const printBy  = document.querySelector('meta[name="x-username"]')?.content || '';
    const now      = new Date();
    const pad      = n => String(n).padStart(2, '0');
    const printDT  = `${pad(now.getDate())}/${pad(now.getMonth()+1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

    const areaClone = area.cloneNode(true);
    areaClone.querySelectorAll('.no-print').forEach(el => el.remove());
    areaClone.querySelectorAll('i.bi-zoom-in').forEach(el => el.remove());
    areaClone.querySelectorAll('a').forEach(a => {
        const span = document.createElement('span');
        span.innerHTML = a.innerHTML;
        a.parentNode.replaceChild(span, a);
    });

    const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>${escHtml(title)}</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10pt; color: #1a1a1a; margin: 0; padding: 0; background: #fff; }
  .rpt-printinfo { text-align: right; font-size: 8.5pt; color: #555; line-height: 1.6; margin-bottom: 6px; }
  .rpt-header { text-align: center; border-bottom: 2px solid #166c82; padding-bottom: 10px; margin-bottom: 18px; }
  .rpt-company { font-size: 13pt; font-weight: 700; color: #166c82; }
  .rpt-title   { font-size: 15pt; font-weight: 700; margin: 4px 0 2px; }
  .rpt-period  { font-size: 10pt; color: #555; }
  .card { border: 1px solid #ccc; border-radius: 4px; margin-bottom: 0; }
  .card-header { padding: 6px 10px; font-size: 10pt; font-weight: 700; background-color: #166c82 !important; color: #fff !important; border-radius: 3px 3px 0 0; }
  .card-body { padding: 0; }
  .table-responsive { overflow: visible; }
  table { width: 100%; border-collapse: collapse; font-size: 8.5pt; page-break-inside: auto; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; page-break-after: auto; }
  th, td { border: 1px solid #ccc; padding: 2px 5px; vertical-align: middle; }
  thead th { background-color: #f0f0f0 !important; font-weight: 700; }
  .text-end  { text-align: right; }
  .ps-3, .ps-4 { padding-left: 14px; }
  .text-success { color: #1b5e20 !important; }
  .text-danger  { color: #b71c1c !important; }
  .text-primary { color: #0d47a1 !important; }
  .text-muted   { color: #555 !important; }
  .fw-semibold, .fw-bold { font-weight: 700; }
  .small, small { font-size: 85%; }
  code { font-family: monospace; font-size: 85%; }
  @page { size: A4 portrait; margin: 15mm 12mm 15mm 12mm; }
  @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body>
<div class="rpt-printinfo">
  ${escHtml(printByL)} : ${escHtml(printBy)}<br>
  ${escHtml(dateTimeL)} : ${escHtml(printDT)}
</div>
<div class="rpt-header">
  <div class="rpt-company">${escHtml(company)}</div>
  <div class="rpt-title">${escHtml(title)}</div>
  <div class="rpt-period">${escHtml(period)}: <strong>${escHtml(dateFrom)}</strong> &rarr; <strong>${escHtml(dateTo)}</strong></div>
</div>
${areaClone.innerHTML}
</body>
</html>`;

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); }, 400);
}

/**
 * Clean print window for Balance Sheet (Grouped) — #bsg-print-area
 */
function printBalanceSheet() {
    const area = document.getElementById('bsg-print-area');
    if (!area) { window.print(); return; }

    const fmtDate = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const asAt    = fmtDate(area.dataset.dateTo || '');
    const company = document.querySelector('meta[name="x-company"]')?.content || 'erpAgroSmart';
    const printBy = document.querySelector('meta[name="x-username"]')?.content || '';
    const now     = new Date();
    const pad     = n => String(n).padStart(2, '0');
    const printDT = `${pad(now.getDate())}/${pad(now.getMonth()+1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

    const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Balance Sheet</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11pt; color: #1a1a1a; margin: 0; padding: 0; background: #fff; }
  .rpt-printinfo { text-align: right; font-size: 8.5pt; color: #555; line-height: 1.6; margin-bottom: 6px; }
  .rpt-header { text-align: center; border-bottom: 2px solid #166c82; padding-bottom: 10px; margin-bottom: 18px; }
  .rpt-company { font-size: 13pt; font-weight: 700; color: #166c82; }
  .rpt-title   { font-size: 15pt; font-weight: 700; margin: 4px 0 2px; }
  .rpt-period  { font-size: 10pt; color: #555; }
  .card { border: 1px solid #ccc; border-radius: 4px; page-break-inside: avoid; margin-bottom: 0; }
  .card-header { padding: 6px 10px; font-size: 10pt; font-weight: 700; background-color: #166c82 !important; color: #fff !important; border-radius: 3px 3px 0 0; }
  .card-body { padding: 0; }
  .table-responsive { overflow: visible; }
  table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
  th, td { border: 1px solid #ccc; padding: 3px 6px; vertical-align: middle; }
  thead th { background-color: #f0f0f0 !important; font-weight: 700; }
  .text-end { text-align: right; }
  .ps-3, .ps-4 { padding-left: 14px; }
  .text-success { color: #1b5e20 !important; }
  .text-danger  { color: #b71c1c !important; }
  .text-primary { color: #0d47a1 !important; }
  .text-muted   { color: #555 !important; }
  .fw-semibold, .fw-bold, strong { font-weight: 700; }
  .small, small { font-size: 85%; }
  .fst-italic { font-style: italic; }
  @page { size: A4 portrait; margin: 15mm 12mm 15mm 12mm; }
  @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body>
<div class="rpt-printinfo">
  Print by : ${escHtml(printBy)}<br>
  Date/Time : ${escHtml(printDT)}
</div>
<div class="rpt-header">
  <div class="rpt-company">${escHtml(company)}</div>
  <div class="rpt-title">Balance Sheet</div>
  <div class="rpt-period">As at: <strong>${escHtml(asAt)}</strong></div>
</div>
${area.innerHTML}
</body>
</html>`;

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); }, 400);
}

/**
 * Clean print window for Detail Balance Sheet — #bs-print-area
 */
function printDetailBS() {
    const area = document.getElementById('bs-print-area');
    if (!area) { window.print(); return; }

    const fmtDate = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const asAt    = fmtDate(area.dataset.dateTo || '');
    const company = document.querySelector('meta[name="x-company"]')?.content || 'erpAgroSmart';
    const printBy = document.querySelector('meta[name="x-username"]')?.content || '';
    const now     = new Date();
    const pad     = n => String(n).padStart(2, '0');
    const printDT = `${pad(now.getDate())}/${pad(now.getMonth()+1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

    const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Detail Balance Sheet</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10pt; color: #1a1a1a; margin: 0; padding: 0; background: #fff; }
  .rpt-printinfo { text-align: right; font-size: 8.5pt; color: #555; line-height: 1.6; margin-bottom: 6px; }
  .rpt-header { text-align: center; border-bottom: 2px solid #166c82; padding-bottom: 10px; margin-bottom: 18px; }
  .rpt-company { font-size: 13pt; font-weight: 700; color: #166c82; }
  .rpt-title   { font-size: 15pt; font-weight: 700; margin: 4px 0 2px; }
  .rpt-period  { font-size: 10pt; color: #555; }
  .card { border: 1px solid #ccc; border-radius: 4px; page-break-inside: avoid; margin-bottom: 0; }
  .card-header { padding: 6px 10px; font-size: 10pt; font-weight: 700; background-color: #166c82 !important; color: #fff !important; border-radius: 3px 3px 0 0; }
  .card-body { padding: 0; }
  .table-responsive { overflow: visible; }
  table { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
  th, td { border: 1px solid #ccc; padding: 2px 5px; vertical-align: middle; }
  thead th { background-color: #f0f0f0 !important; font-weight: 700; }
  .text-end { text-align: right; }
  .ps-3, .ps-4 { padding-left: 14px; }
  .text-success { color: #1b5e20 !important; }
  .text-danger  { color: #b71c1c !important; }
  .text-primary { color: #0d47a1 !important; }
  .text-muted   { color: #555 !important; }
  .fw-semibold, .fw-bold, strong { font-weight: 700; }
  .small, small { font-size: 85%; }
  .fst-italic { font-style: italic; }
  code { font-family: monospace; font-size: 85%; }
  @page { size: A4 portrait; margin: 15mm 12mm 15mm 12mm; }
  @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body>
<div class="rpt-printinfo">
  Print by : ${escHtml(printBy)}<br>
  Date/Time : ${escHtml(printDT)}
</div>
<div class="rpt-header">
  <div class="rpt-company">${escHtml(company)}</div>
  <div class="rpt-title">Detail Balance Sheet</div>
  <div class="rpt-period">As at: <strong>${escHtml(asAt)}</strong></div>
</div>
${area.innerHTML}
</body>
</html>`;

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); }, 400);
}

// -- Shared helper: open a clean print window --
function _openPrintWindow(areaId, title, subtitleHtml, landscape) {
    const area = document.getElementById(areaId);
    if (!area) { window.print(); return; }
    const company = document.querySelector('meta[name="x-company"]')?.content || 'erpAgroSmart';
    const pageSize = landscape ? 'A4 landscape' : 'A4 portrait';
    const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>${escHtml(title)}</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10pt; color: #1a1a1a; margin: 0; padding: 0; background: #fff; }
  .rpt-header { text-align: center; border-bottom: 2px solid #166c82; padding-bottom: 10px; margin-bottom: 16px; }
  .rpt-company { font-size: 12pt; font-weight: 700; color: #166c82; }
  .rpt-title   { font-size: 14pt; font-weight: 700; margin: 4px 0 2px; }
  .rpt-period  { font-size: 9.5pt; color: #555; }
  .card { border: 1px solid #ccc; border-radius: 4px; page-break-inside: avoid; margin-bottom: 0; }
  .card-header { padding: 5px 10px; font-size: 9.5pt; font-weight: 700; background-color: #166c82 !important; color: #fff !important; border-radius: 3px 3px 0 0; }
  .card-body { padding: 0; }
  .table-responsive { overflow: visible; }
  table { width: 100%; border-collapse: collapse; font-size: 8pt; }
  th, td { border: 1px solid #ccc; padding: 2px 4px; vertical-align: middle; }
  thead th { background-color: #f0f0f0 !important; font-weight: 700; }
  .text-end    { text-align: right; }
  .text-center { text-align: center; }
  .text-muted  { color: #666 !important; }
  .fw-bold, strong { font-weight: 700; }
  .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7.5pt; font-weight: 700; }
  .bg-warning  { background-color: #ffc107 !important; color: #000 !important; }
  .bg-info     { background-color: #0dcaf0 !important; color: #000 !important; }
  .bg-success  { background-color: #198754 !important; color: #fff !important; }
  .bg-secondary{ background-color: #6c757d !important; color: #fff !important; }
  .table-primary { background-color: #cfe2ff !important; }
  @page { size: ${pageSize}; margin: 12mm 10mm 12mm 10mm; }
  @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body>
<div class="rpt-header">
  <div class="rpt-company">${escHtml(company)}</div>
  <div class="rpt-title">${escHtml(title)}</div>
  <div class="rpt-period">${subtitleHtml}</div>
</div>
${area.innerHTML}
</body>
</html>`;
    const win = window.open('', '_blank', 'width=1000,height=700');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); }, 400);
}

function printCostByBlock() {
    const area = document.getElementById('cbb-print-area');
    if (!area) { window.print(); return; }
    const fmtDate = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const from = fmtDate(area.dataset.dateFrom || '');
    const to   = fmtDate(area.dataset.dateTo   || '');
    _openPrintWindow('cbb-print-area', 'Cost by Block Report',
        `Period: <strong>${escHtml(from)}</strong> &rarr; <strong>${escHtml(to)}</strong>`, true);
}

function printCostByActivity() {
    const area = document.getElementById('cba-print-area');
    if (!area) { window.print(); return; }
    const fmtDate = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const from = fmtDate(area.dataset.dateFrom || '');
    const to   = fmtDate(area.dataset.dateTo   || '');
    _openPrintWindow('cba-print-area', 'Cost by Activity Report',
        `Period: <strong>${escHtml(from)}</strong> &rarr; <strong>${escHtml(to)}</strong>`, true);
}

function printCostByCategory() {
    const area = document.getElementById('cbc-print-area');
    if (!area) { window.print(); return; }
    const fmtDate = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const from = fmtDate(area.dataset.dateFrom || '');
    const to   = fmtDate(area.dataset.dateTo   || '');
    _openPrintWindow('cbc-print-area', 'Cost by Category Report',
        `Period: <strong>${escHtml(from)}</strong> &rarr; <strong>${escHtml(to)}</strong>`, false);
}

function printBlockProfitability() {
    const area = document.getElementById('bp-print-area');
    if (!area) { window.print(); return; }
    const fmtDate = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const from = fmtDate(area.dataset.dateFrom || '');
    const to   = fmtDate(area.dataset.dateTo   || '');
    _openPrintWindow('bp-print-area', 'Block Profitability Report (TM Blocks)',
        `Period: <strong>${escHtml(from)}</strong> &rarr; <strong>${escHtml(to)}</strong>`, true);
}

function printMonthlyTrends() {
    const area = document.getElementById('mt-print-area');
    if (!area) { window.print(); return; }
    const fmtDate = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const from = fmtDate(area.dataset.dateFrom || '');
    const to   = fmtDate(area.dataset.dateTo   || '');
    _openPrintWindow('mt-print-area', 'Monthly Cost Trends',
        `Period: <strong>${escHtml(from)}</strong> &rarr; <strong>${escHtml(to)}</strong>`, true);
}

function printCostVariance() {
    const area = document.getElementById('cv-print-area');
    if (!area) { window.print(); return; }
    const fmtDate = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const from = fmtDate(area.dataset.dateFrom || '');
    const to   = fmtDate(area.dataset.dateTo   || '');
    _openPrintWindow('cv-print-area', 'Cost Variance Analysis (Actual vs Standard)',
        `Period: <strong>${escHtml(from)}</strong> &rarr; <strong>${escHtml(to)}</strong>`, true);
}

function printTrialBalance() {
    const area = document.getElementById('tb-print-area');
    if (!area) { window.print(); return; }

    const fmtDate = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const dateFrom = fmtDate(area.dataset.dateFrom || '');
    const dateTo   = fmtDate(area.dataset.dateTo   || '');
    const company  = document.querySelector('meta[name="x-company"]')?.content || 'erpAgroSmart';
    const printBy  = document.querySelector('meta[name="x-username"]')?.content || '';
    const now      = new Date();
    const pad      = n => String(n).padStart(2, '0');
    const printDT  = `${pad(now.getDate())}/${pad(now.getMonth()+1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

    const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Trial Balance</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 9.5pt; color: #1a1a1a; margin: 0; padding: 0; background: #fff; }
  .rpt-printinfo { text-align: right; font-size: 8pt; color: #555; line-height: 1.6; margin-bottom: 6px; }
  .rpt-header { text-align: center; border-bottom: 2px solid #166c82; padding-bottom: 10px; margin-bottom: 16px; }
  .rpt-company { font-size: 12pt; font-weight: 700; color: #166c82; }
  .rpt-title   { font-size: 14pt; font-weight: 700; margin: 4px 0 2px; }
  .rpt-period  { font-size: 9pt; color: #555; }
  .card { border: 1px solid #ccc; border-radius: 4px; page-break-inside: avoid; margin-bottom: 0; }
  .card-header { padding: 5px 10px; font-size: 9.5pt; font-weight: 700; background-color: #166c82 !important; color: #fff !important; border-radius: 3px 3px 0 0; }
  .card-body { padding: 0; }
  .table-responsive { overflow: visible; }
  table { width: 100%; border-collapse: collapse; font-size: 8pt; }
  th, td { border: 1px solid #ccc; padding: 2px 5px; vertical-align: middle; }
  thead th { background-color: #f0f0f0 !important; font-weight: 700; }
  tfoot tr { background-color: #e9ecef !important; font-weight: 700; border-top: 2px solid #adb5bd; }
  .text-end    { text-align: right; }
  .text-muted  { color: #666 !important; }
  .text-primary { color: #0d47a1 !important; }
  .text-danger  { color: #b71c1c !important; }
  .text-success { color: #1b5e20 !important; }
  .fw-semibold, strong { font-weight: 700; }
  .badge { display: inline-block; padding: 1px 4px; border-radius: 3px; font-size: 7pt; background-color: #e9ecef; color: #495057; }
  code { font-family: monospace; font-size: 85%; }
  @page { size: A4 landscape; margin: 12mm 10mm 12mm 10mm; }
  @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body>
<div class="rpt-printinfo">
  Print by : ${escHtml(printBy)}<br>
  Date/Time : ${escHtml(printDT)}
</div>
<div class="rpt-header">
  <div class="rpt-company">${escHtml(company)}</div>
  <div class="rpt-title">Trial Balance</div>
  <div class="rpt-period">Period: <strong>${escHtml(dateFrom)}</strong> &rarr; <strong>${escHtml(dateTo)}</strong></div>
</div>
${area.innerHTML}
</body>
</html>`;

    const win = window.open('', '_blank', 'width=1100,height=750');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); }, 400);
}

function printGeneralLedger() {
    const area = document.getElementById('gl-print-area');
    if (!area) { window.print(); return; }

    const fmtDate  = s => { const [y,m,d] = s.split('-'); return d && m && y ? `${d}/${m}/${y}` : s; };
    const dateFrom = fmtDate(area.dataset.dateFrom || '');
    const dateTo   = fmtDate(area.dataset.dateTo   || '');
    const i18n     = JSON.parse(area.dataset.i18n  || '{}');
    const title    = i18n.title    || 'General Ledger';
    const printByL = i18n.print_by || 'Print by';
    const dateTimeL= i18n.datetime || 'Date/Time';
    const company  = document.querySelector('meta[name="x-company"]')?.content || 'erpAgroSmart';
    const printBy  = document.querySelector('meta[name="x-username"]')?.content || '';
    const now      = new Date();
    const pad      = n => String(n).padStart(2, '0');
    const printDT  = `${pad(now.getDate())}/${pad(now.getMonth()+1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

    const areaClone = area.cloneNode(true);
    areaClone.querySelectorAll('.no-print').forEach(el => el.remove());
    areaClone.querySelectorAll('a').forEach(a => {
        const span = document.createElement('span');
        span.innerHTML = a.innerHTML;
        a.parentNode.replaceChild(span, a);
    });

    const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>${escHtml(title)}</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 8.5pt; color: #1a1a1a; margin: 0; padding: 0; background: #fff; }
  .rpt-printinfo { text-align: right; font-size: 7.5pt; color: #555; line-height: 1.6; margin-bottom: 4px; }
  .rpt-header { text-align: center; border-bottom: 2px solid #166c82; padding-bottom: 8px; margin-bottom: 14px; }
  .rpt-company { font-size: 11pt; font-weight: 700; color: #166c82; }
  .rpt-title   { font-size: 13pt; font-weight: 700; margin: 3px 0 2px; }
  .rpt-period  { font-size: 8.5pt; color: #555; }
  .card { border: 1px solid #ccc; border-radius: 4px; page-break-inside: auto; margin-bottom: 8px; }
  .card-header { padding: 4px 8px; font-size: 8.5pt; font-weight: 700; background-color: #166c82 !important; color: #fff !important; border-radius: 3px 3px 0 0; }
  .card-body { padding: 0; }
  .table-responsive { overflow: visible; }
  table { width: 100%; border-collapse: collapse; font-size: 7pt; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  th, td { border: 1px solid #ddd; padding: 1px 4px; vertical-align: middle; }
  thead th { background-color: #e9f4f7 !important; font-weight: 700; }
  tfoot tr { background-color: #e9ecef !important; font-weight: 700; }
  .text-end { text-align: right; }
  .text-nowrap { white-space: nowrap; }
  .text-muted { color: #666 !important; }
  .fw-semibold, strong { font-weight: 700; }
  .badge { display: inline-block; padding: 1px 3px; border-radius: 3px; font-size: 6.5pt; background-color: #e9ecef; color: #495057; }
  code { font-family: monospace; font-size: 80%; }
  .fst-italic { font-style: italic; }
  @page { size: A4 landscape; margin: 10mm 8mm 10mm 8mm; }
  @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body>
<div class="rpt-printinfo">
  ${escHtml(printByL)} : ${escHtml(printBy)}<br>
  ${escHtml(dateTimeL)} : ${escHtml(printDT)}
</div>
<div class="rpt-header">
  <div class="rpt-company">${escHtml(company)}</div>
  <div class="rpt-title">${escHtml(title)}</div>
  <div class="rpt-period">Period: <strong>${escHtml(dateFrom)}</strong> &rarr; <strong>${escHtml(dateTo)}</strong></div>
</div>
${areaClone.innerHTML}
</body>
</html>`;

    const win = window.open('', '_blank', 'width=1200,height=800');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); }, 400);
}
</script>

<style>
@media print {
    .btn-group, .card-header form, .no-print {
        display: none !important;
    }
    .card {
        border: none;
        box-shadow: none;
    }
}

/* Custom button styles for Financial Reports */
.btn-custom-fr {
    background-color: #166c82;
    border-color: #166c82;
    color: white;
}

.btn-custom-fr:hover {
    background-color: #1a7d9a;
    border-color: #1a7d9a;
    color: white;
}

.btn-custom-fr:focus,
.btn-custom-fr:active {
    background-color: #145a6d;
    border-color: #145a6d;
    color: white;
}

.btn-group.flex-wrap .btn {
    margin-bottom: 4px;
}
</style>

<?php require_once 'includes/footer.php'; ?>
