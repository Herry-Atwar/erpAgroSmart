<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

$page_title = "Budget Management";
require_once 'includes/header.php';

// Get filters
$year = get('year', date('Y'));
$company_filter = get('company_id', '');
$division_filter = get('division_id', '');
$budget_type = get('budget_type', 'all');
$search = get('search', '');
$action = get('action', '');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'create_budget') {
        try {
            $btype = post('budget_type');
            if ($btype === 'capital') {
                $stmt = $db->prepare("
                    INSERT INTO capital_budget_items (
                        budget_year, company_id, division_id, block_id,
                        item_name, description, total_cost, status, notes, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
                ");
                $stmt->execute([
                    post('budget_year'),
                    post('company_id') ?: null,
                    post('division_id') ?: null,
                    post('block_id') ?: null,
                    post('category'),
                    post('description'),
                    post('planned_amount'),
                    post('notes'),
                    $_SESSION['username'] ?? 'admin',
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO activity_budget_plans (
                        budget_year, block_id, total_annual_cost, status, notes, created_by
                    ) VALUES (?, ?, ?, 'draft', ?, ?)
                ");
                $stmt->execute([
                    post('budget_year'),
                    post('block_id') ?: null,
                    post('planned_amount'),
                    post('notes'),
                    $_SESSION['username'] ?? 'admin',
                ]);
            }
            $success_message = "Budget created successfully!";
        } catch (PDOException $e) {
            $error_message = "Error creating budget: " . $e->getMessage();
        }
    } elseif ($action === 'update_budget') {
        try {
            $btype  = post('budget_type');
            $bid    = post('budget_id');
            $actual = (float) post('actual_amount');
            $planned = (float) post('planned_amount');
            $variance = $planned - $actual;
            $variance_pct = $planned > 0 ? (($variance / $planned) * 100) : 0;

            if ($btype === 'capital') {
                $stmt = $db->prepare("
                    UPDATE capital_budget_items
                    SET total_cost = ?, notes = ?, updated_at = NOW()
                    WHERE item_id = ?
                ");
                $stmt->execute([$planned, post('notes'), $bid]);
            } else {
                $stmt = $db->prepare("
                    UPDATE activity_budget_plans
                    SET total_annual_cost = ?, notes = ?, updated_at = NOW(), updated_by = ?
                    WHERE plan_id = ?
                ");
                $stmt->execute([$planned, post('notes'), $_SESSION['username'] ?? 'admin', $bid]);
            }
            $success_message = "Budget updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating budget: " . $e->getMessage();
        }
    }
}

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

// Build unified budget CTE from real tables
// Parameters: year appears 2x (once per UNION leg), then company/division filters repeat
$cte_params = [];

$op_company_cond  = '';
$op_division_cond = '';
$cap_company_cond  = '';
$cap_division_cond = '';

if ($company_filter) {
    $op_company_cond  = ' AND abp.block_id IN (SELECT bl2.block_id FROM blocks bl2 JOIN divisions dv2 ON bl2.division_id = dv2.division_id JOIN business_units bu2 ON dv2.business_unit_id = bu2.business_unit_id WHERE bu2.company_id = ?)';
    $cap_company_cond = ' AND cbi.company_id = ?';
}
if ($division_filter) {
    $op_division_cond  = ' AND abp.block_id IN (SELECT bl3.block_id FROM blocks bl3 WHERE bl3.division_id = ?)';
    $cap_division_cond = ' AND cbi.division_id = ?';
}

// Collect CTE params: operational leg then capital leg
$op_params  = [$year];
if ($company_filter)  $op_params[]  = $company_filter;
if ($division_filter) $op_params[]  = $division_filter;
$cap_params = [$year];
if ($company_filter)  $cap_params[] = $company_filter;
if ($division_filter) $cap_params[] = $division_filter;

$cte_params = array_merge($op_params, $cap_params);

$cte_sql = "
    WITH unified_budgets AS (
        SELECT
            abp.plan_id::text                          AS budget_id,
            'operational'                               AS budget_type,
            COALESCE(a.activity_name, 'Activity #' || abp.activity_id::text) AS category,
            COALESCE(abp.notes, '')                    AS description,
            abp.budget_year,
            NULL::bigint                                AS company_id,
            NULL::bigint                                AS division_id,
            abp.block_id,
            abp.total_annual_cost                       AS planned_amount,
            COALESCE((
                SELECT SUM(abm.actual_cost)
                FROM activity_budget_monthly abm
                WHERE abm.plan_id = abp.plan_id
            ), 0)                                       AS actual_amount,
            abp.total_annual_cost - COALESCE((
                SELECT SUM(abm.actual_cost)
                FROM activity_budget_monthly abm
                WHERE abm.plan_id = abp.plan_id
            ), 0)                                       AS variance,
            CASE WHEN abp.total_annual_cost > 0 THEN
                (abp.total_annual_cost - COALESCE((
                    SELECT SUM(abm.actual_cost)
                    FROM activity_budget_monthly abm
                    WHERE abm.plan_id = abp.plan_id
                ), 0)) / abp.total_annual_cost * 100
            ELSE 0 END                                  AS variance_percentage,
            abp.created_at,
            abp.status
        FROM activity_budget_plans abp
        LEFT JOIN activities a ON abp.activity_id = a.id
        WHERE abp.budget_year = ?
        $op_company_cond
        $op_division_cond

        UNION ALL

        SELECT
            cbi.item_id::text                           AS budget_id,
            'capital'                                    AS budget_type,
            COALESCE(cbi.asset_category, cbi.item_name) AS category,
            COALESCE(cbi.description, '')               AS description,
            cbi.budget_year,
            cbi.company_id,
            cbi.division_id,
            cbi.block_id,
            cbi.total_cost                              AS planned_amount,
            CASE WHEN cbi.status = 'approved' THEN cbi.total_cost ELSE 0 END AS actual_amount,
            CASE WHEN cbi.status = 'approved' THEN 0 ELSE cbi.total_cost END AS variance,
            CASE WHEN cbi.status = 'approved' THEN 0 ELSE 100 END            AS variance_percentage,
            cbi.created_at,
            cbi.status
        FROM capital_budget_items cbi
        WHERE cbi.budget_year = ?
        $cap_company_cond
        $cap_division_cond
    )
";

// Fetch budget summary
$summary_sql = $cte_sql . "
    SELECT
        budget_type,
        COUNT(*)               AS budget_count,
        SUM(planned_amount)    AS total_planned,
        SUM(actual_amount)     AS total_actual,
        SUM(variance)          AS total_variance,
        AVG(variance_percentage) AS avg_variance_pct
    FROM unified_budgets
    WHERE 1=1
";
$summary_params = $cte_params;

if ($budget_type !== 'all') {
    $summary_sql .= " AND budget_type = ?";
    $summary_params[] = $budget_type;
}
$summary_sql .= " GROUP BY budget_type ORDER BY budget_type";

$stmt = $db->prepare($summary_sql);
$stmt->execute($summary_params);
$budget_summary = $stmt->fetchAll();

// Fetch detailed budgets
$budgets_sql = $cte_sql . "
    SELECT
        ub.*,
        c.company_name,
        d.division_name,
        bl.block_code
    FROM unified_budgets ub
    LEFT JOIN companies  c  ON ub.company_id  = c.company_id
    LEFT JOIN divisions  d  ON ub.division_id = d.division_id
    LEFT JOIN blocks     bl ON ub.block_id    = bl.block_id
    WHERE 1=1
";
$budget_params = $cte_params;

if ($budget_type !== 'all') {
    $budgets_sql .= " AND ub.budget_type = ?";
    $budget_params[] = $budget_type;
}
if ($search) {
    $budgets_sql .= " AND (ub.category ILIKE ? OR ub.description ILIKE ? OR c.company_name ILIKE ? OR d.division_name ILIKE ?)";
    $search_term    = "%$search%";
    $budget_params[] = $search_term;
    $budget_params[] = $search_term;
    $budget_params[] = $search_term;
    $budget_params[] = $search_term;
}
$budgets_sql .= " ORDER BY ub.budget_type, ub.category, ub.created_at DESC";

$stmt = $db->prepare($budgets_sql);
$stmt->execute($budget_params);
$budgets = $stmt->fetchAll();

// Calculate totals
$total_planned = array_sum(array_column($budgets, 'planned_amount'));
$total_actual = array_sum(array_column($budgets, 'actual_amount'));
$total_variance = $total_planned - $total_actual;
$variance_pct = $total_planned > 0 ? (($total_variance / $total_planned) * 100) : 0;
?>

<div class="container-fluid mt-4">
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 style="color: #166c82;"><i class="bi bi-cash-stack" style="color: #166c82;"></i> Budget Management</h1>
                <p class="text-muted">Plan and track operational and capital budgets across divisions</p>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-custom-budget" data-bs-toggle="modal" data-bs-target="#createBudgetModal">
                    <i class="bi bi-plus-circle"></i> Create New Budget
                </button>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header text-white" style="background-color: #166c82;">
                    <h5 class="mb-0"><i class="bi bi-funnel"></i> Filters - Budget Year <?= $year ?></h5>
                </div>
                <div class="card-body">
                    <!-- Filters -->
                    <form method="GET" class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" placeholder="Search category, description, company..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Year</label>
                            <select name="year" class="form-select">
                                <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Company</label>
                            <select name="company_id" class="form-select">
                                <option value="">All Companies</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?= $company['company_id'] ?>"
                                        <?= $company['company_id'] == $company_filter ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($company['company_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Division</label>
                            <select name="division_id" class="form-select">
                                <option value="">All Divisions</option>
                                <?php foreach ($divisions as $division): ?>
                                    <option value="<?= $division['division_id'] ?>"
                                        <?= $division['division_id'] == $division_filter ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($division['division_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Budget Type</label>
                            <select name="budget_type" class="form-select">
                                <option value="all">All Types</option>
                                <option value="operational" <?= $budget_type == 'operational' ? 'selected' : '' ?>>Operational</option>
                                <option value="capital" <?= $budget_type == 'capital' ? 'selected' : '' ?>>Capital</option>
                                <option value="maintenance" <?= $budget_type == 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                <option value="development" <?= $budget_type == 'development' ? 'selected' : '' ?>>Development</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-custom-budget w-100"><i class="bi bi-search"></i> Search</button>
                        </div>
                    </form>

                    <!-- Budget Summary Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted">Total Planned</h6>
                                    <h4 style="color: #166c82;">Rp <?= number_format($total_planned, 0, ',', '.') ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted">Total Actual</h6>
                                    <h4 style="color: #166c82;">Rp <?= number_format($total_actual, 0, ',', '.') ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted">Variance</h6>
                                    <h4 class="<?= $total_variance >= 0 ? '' : 'text-danger' ?>" <?= $total_variance >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                        Rp <?= number_format($total_variance, 0, ',', '.') ?>
                                    </h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted">Variance %</h6>
                                    <h4 class="<?= $variance_pct >= 0 ? '' : 'text-danger' ?>" <?= $variance_pct >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                        <?= number_format($variance_pct, 2) ?>%
                                    </h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Budget Summary by Type -->
                    <?php if (!empty($budget_summary)): ?>
                    <div class="table-responsive mb-4">
                        <h6>Budget Summary by Type</h6>
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Budget Type</th>
                                    <th class="text-end">Count</th>
                                    <th class="text-end">Planned</th>
                                    <th class="text-end">Actual</th>
                                    <th class="text-end">Variance</th>
                                    <th class="text-end">Variance %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($budget_summary as $summary): ?>
                                <tr>
                                    <td><strong><?= ucfirst($summary['budget_type']) ?></strong></td>
                                    <td class="text-end"><?= $summary['budget_count'] ?></td>
                                    <td class="text-end">Rp <?= number_format($summary['total_planned'], 0, ',', '.') ?></td>
                                    <td class="text-end">Rp <?= number_format($summary['total_actual'], 0, ',', '.') ?></td>
                                    <td class="text-end <?= $summary['total_variance'] >= 0 ? '' : 'text-danger' ?>" <?= $summary['total_variance'] >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                        Rp <?= number_format($summary['total_variance'], 0, ',', '.') ?>
                                    </td>
                                    <td class="text-end <?= $summary['avg_variance_pct'] >= 0 ? '' : 'text-danger' ?>" <?= $summary['avg_variance_pct'] >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                        <?= number_format($summary['avg_variance_pct'], 2) ?>%
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <!-- Detailed Budget List -->
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header text-white" style="background-color: #166c82;">
            <i class="bi bi-list"></i> Budget Details (<?= count($budgets) ?> records)
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Company</th>
                            <th>Division</th>
                            <th>Block</th>
                            <th class="text-end">Planned</th>
                            <th class="text-end">Actual</th>
                            <th class="text-end">Variance</th>
                            <th class="text-end">%</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                            <tbody>
                                <?php if (empty($budgets)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">No budget records found</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($budgets as $budget): ?>
                                    <tr>
                                        <td><span class="badge bg-info"><?= ucfirst($budget['budget_type']) ?></span></td>
                                        <td><?= htmlspecialchars($budget['category']) ?></td>
                                        <td><?= htmlspecialchars($budget['description']) ?></td>
                                        <td><?= htmlspecialchars($budget['company_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($budget['division_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($budget['block_code'] ?? '-') ?></td>
                                        <td class="text-end">Rp <?= number_format($budget['planned_amount'], 0, ',', '.') ?></td>
                                        <td class="text-end">Rp <?= number_format($budget['actual_amount'], 0, ',', '.') ?></td>
                                        <td class="text-end <?= $budget['variance'] >= 0 ? '' : 'text-danger' ?>" <?= $budget['variance'] >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                            Rp <?= number_format($budget['variance'], 0, ',', '.') ?>
                                        </td>
                                        <td class="text-end <?= $budget['variance_percentage'] >= 0 ? '' : 'text-danger' ?>" <?= $budget['variance_percentage'] >= 0 ? 'style="color: #166c82;"' : '' ?>>
                                            <?= number_format($budget['variance_percentage'], 1) ?>%
                                        </td>
                                        <td>
                                            <button class="btn btn-sm" style="background-color: #166c82; color: white;" onclick="editBudget(<?= htmlspecialchars(json_encode($budget)) ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Budget Modal -->
<div class="modal fade" id="createBudgetModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Budget</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_budget">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Budget Year *</label>
                            <input type="number" name="budget_year" class="form-control" value="<?= $year ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Budget Type *</label>
                            <select name="budget_type" class="form-select" required>
                                <option value="operational">Operational</option>
                                <option value="capital">Capital</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="development">Development</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category *</label>
                            <input type="text" name="category" class="form-control" placeholder="e.g., Labor, Fertilizer" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Planned Amount *</label>
                            <input type="number" name="planned_amount" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description *</label>
                            <input type="text" name="description" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Company</label>
                            <select name="company_id" class="form-select">
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?= $company['company_id'] ?>">
                                        <?= htmlspecialchars($company['company_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Division</label>
                            <select name="division_id" class="form-select">
                                <option value="">Select Division</option>
                                <?php foreach ($divisions as $division): ?>
                                    <option value="<?= $division['division_id'] ?>">
                                        <?= htmlspecialchars($division['division_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Block (Optional)</label>
                            <input type="number" name="block_id" class="form-control" placeholder="Block ID">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background-color: #166c82; color: white;">Create Budget</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Budget Modal -->
<div class="modal fade" id="editBudgetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Update Budget</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_budget">
                    <input type="hidden" name="budget_id" id="edit_budget_id">
                    <input type="hidden" name="budget_type" id="edit_budget_type">
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" id="edit_description" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Planned Amount</label>
                        <input type="number" name="planned_amount" id="edit_planned_amount" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Actual Amount</label>
                        <input type="number" name="actual_amount" id="edit_actual_amount" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background-color: #166c82; color: white;">Update Budget</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editBudget(budget) {
    document.getElementById('edit_budget_id').value = budget.budget_id;
    document.getElementById('edit_budget_type').value = budget.budget_type;
    document.getElementById('edit_description').value = budget.description;
    document.getElementById('edit_planned_amount').value = budget.planned_amount;
    document.getElementById('edit_actual_amount').value = budget.actual_amount;
    document.getElementById('edit_notes').value = budget.notes || '';
    
    var modal = new bootstrap.Modal(document.getElementById('editBudgetModal'));
    modal.show();
}
</script>
<style>
/* Custom button styles for Budget Management */
.btn-custom-budget {
    background-color: #166c82;
    border-color: #166c82;
    color: white;
}

.btn-custom-budget:hover {
    background-color: #1a7d9a;
    border-color: #1a7d9a;
    color: white;
}

.btn-custom-budget:focus,
.btn-custom-budget:active {
    background-color: #145a6d;
    border-color: #145a6d;
    color: white;
}
</style>


<?php require_once 'includes/footer.php'; ?>

// Made with Bob
