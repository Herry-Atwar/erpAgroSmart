<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$pageTitle = 'Capital Budget Management';
$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'add') {
        $stmt = $db->prepare("
            INSERT INTO capital_budget_items (
                budget_year, company_id, division_id, block_id,
                item_code, item_name, description, asset_category, asset_subcategory,
                quantity, unit_price, installation_cost, other_costs,
                useful_life_years, depreciation_method, salvage_value,
                planned_purchase_date, priority, status, business_case, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            post('budget_year'),
            post('company_id') ?: null,
            post('division_id') ?: null,
            post('block_id') ?: null,
            post('item_code'),
            post('item_name'),
            post('description'),
            post('asset_category'),
            post('asset_subcategory'),
            post('quantity'),
            post('unit_price'),
            post('installation_cost') ?: 0,
            post('other_costs') ?: 0,
            post('useful_life_years'),
            post('depreciation_method'),
            post('salvage_value') ?: 0,
            post('planned_purchase_date'),
            post('priority'),
            post('status'),
            post('business_case'),
            'admin'
        ]);
        
        $success = "Capital budget item added successfully!";
    }
    
    if ($action === 'update_status') {
        $stmt = $db->prepare("
            UPDATE capital_budget_items 
            SET status = ?,
                actual_purchase_date = ?,
                start_depreciation_date = ?,
                approved_by = ?,
                approval_date = ?,
                notes = ?
            WHERE item_id = ?
        ");
        
        $stmt->execute([
            post('status'),
            post('actual_purchase_date') ?: null,
            post('start_depreciation_date') ?: null,
            post('approved_by') ?: null,
            post('approval_date') ?: null,
            post('notes'),
            post('item_id')
        ]);
        
        $success = "Status updated successfully!";
    }
    
    if ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM capital_budget_items WHERE item_id = ?");
        $stmt->execute([post('item_id')]);
        $success = "Capital budget item deleted successfully!";
    }
}

// Get filter values
$filter_year = get('year', date('Y'));
$filter_status = get('status', 'all');
$filter_category = get('category', 'all');

// Fetch companies, divisions, blocks for dropdowns
$companies = $db->query("SELECT company_id, company_name FROM companies ORDER BY company_name")->fetchAll();
$divisions = $db->query("SELECT division_id, division_name FROM divisions ORDER BY division_name")->fetchAll();
$blocks = $db->query("SELECT block_id, block_code FROM blocks ORDER BY block_code")->fetchAll();

// Build query with filters
$where = ["budget_year = ?"];
$params = [$filter_year];

if ($filter_status !== 'all') {
    $where[] = "status = ?";
    $params[] = $filter_status;
}

if ($filter_category !== 'all') {
    $where[] = "asset_category = ?";
    $params[] = $filter_category;
}

$whereClause = implode(' AND ', $where);

// Fetch capital budget items
$stmt = $db->prepare("
    SELECT 
        cbi.*,
        c.company_name,
        d.division_name,
        b.block_code
    FROM capital_budget_items cbi
    LEFT JOIN companies c ON cbi.company_id = c.company_id
    LEFT JOIN divisions d ON cbi.division_id = d.division_id
    LEFT JOIN blocks b ON cbi.block_id = b.block_id
    WHERE $whereClause
    ORDER BY cbi.planned_purchase_date DESC, cbi.item_code
");
$stmt->execute($params);
$items = $stmt->fetchAll();

// Calculate summary
$summary = $db->prepare("
    SELECT
        COUNT(*) as total_items,
        SUM(total_cost) as total_investment,
        SUM(CASE WHEN status IN ('received', 'installed') THEN total_cost ELSE 0 END) as committed,
        SUM(CASE WHEN status = 'approved' THEN total_cost ELSE 0 END) as approved_pending,
        SUM(annual_depreciation) as total_depreciation
    FROM capital_budget_items
    WHERE $whereClause
");
$summary->execute($params);
$summary_data = $summary->fetch();

include 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i class="bi bi-building"></i> Capital Budget Management</h2>
            <p class="text-muted">Manage capital expenditure items and track depreciation</p>
        </div>
        <div class="col-md-4 text-end">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="bi bi-plus-circle"></i> Add Capital Item
            </button>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6 class="card-title">Total Items</h6>
                    <h3><?= number_format($summary_data['total_items']) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h6 class="card-title">Total Investment</h6>
                    <h3>Rp <?= number_format($summary_data['total_investment'] / 1000000, 1) ?>M</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h6 class="card-title">Committed</h6>
                    <h3>Rp <?= number_format($summary_data['committed'] / 1000000, 1) ?>M</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h6 class="card-title">Annual Depreciation</h6>
                    <h3>Rp <?= number_format($summary_data['total_depreciation'] / 1000000, 1) ?>M</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Budget Year</label>
                    <select name="year" class="form-select" onchange="this.form.submit()">
                        <?php for ($y = date('Y') + 1; $y >= date('Y') - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $y == $filter_year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="all">All Status</option>
                        <option value="proposed" <?= $filter_status == 'proposed' ? 'selected' : '' ?>>Proposed</option>
                        <option value="approved" <?= $filter_status == 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="rejected" <?= $filter_status == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="purchased" <?= $filter_status == 'purchased' ? 'selected' : '' ?>>Purchased</option>
                        <option value="in_use" <?= $filter_status == 'in_use' ? 'selected' : '' ?>>In Use</option>
                        <option value="disposed" <?= $filter_status == 'disposed' ? 'selected' : '' ?>>Disposed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select" onchange="this.form.submit()">
                        <option value="all">All Categories</option>
                        <option value="Equipment" <?= $filter_category == 'Equipment' ? 'selected' : '' ?>>Equipment</option>
                        <option value="Vehicle" <?= $filter_category == 'Vehicle' ? 'selected' : '' ?>>Vehicle</option>
                        <option value="Infrastructure" <?= $filter_category == 'Infrastructure' ? 'selected' : '' ?>>Infrastructure</option>
                        <option value="Building" <?= $filter_category == 'Building' ? 'selected' : '' ?>>Building</option>
                        <option value="Technology" <?= $filter_category == 'Technology' ? 'selected' : '' ?>>Technology</option>
                        <option value="Replanting" <?= $filter_category == 'Replanting' ? 'selected' : '' ?>>Replanting</option>
                        <option value="Other" <?= $filter_category == 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-secondary w-100">
                        <i class="bi bi-funnel"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Capital Items Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Qty</th>
                            <th>Total Investment</th>
                            <th>Annual Deprec.</th>
                            <th>Planned Date</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['item_code']) ?></strong></td>
                                <td>
                                    <?= htmlspecialchars($item['item_name']) ?>
                                    <?php if ($item['asset_subcategory']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($item['asset_subcategory']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-secondary"><?= $item['asset_category'] ?></span></td>
                                <td><?= number_format($item['quantity']) ?></td>
                                <td class="text-end">
                                    <strong>Rp <?= number_format($item['total_cost']) ?></strong>
                                    <br><small class="text-muted">
                                        Unit: Rp <?= number_format($item['unit_price']) ?>
                                    </small>
                                </td>
                                <td class="text-end">
                                    Rp <?= number_format($item['annual_depreciation']) ?>
                                    <br><small class="text-muted"><?= $item['useful_life_years'] ?> years</small>
                                </td>
                                <td><?= date('d M Y', strtotime($item['planned_purchase_date'])) ?></td>
                                <td>
                                    <?php
                                    $statusColors = [
                                        'proposed' => 'secondary',
                                        'approved' => 'info',
                                        'rejected' => 'danger',
                                        'purchased' => 'success',
                                        'in_use' => 'primary',
                                        'disposed' => 'dark'
                                    ];
                                    $color = $statusColors[$item['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $color ?>"><?= ucfirst($item['status']) ?></span>
                                </td>
                                <td>
                                    <?php
                                    $priorityColors = [
                                        'critical' => 'danger',
                                        'high' => 'warning',
                                        'medium' => 'info',
                                        'low' => 'secondary'
                                    ];
                                    $pColor = $priorityColors[$item['priority']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $pColor ?>"><?= ucfirst($item['priority']) ?></span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewDetails(<?= $item['item_id'] ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="updateStatus(<?= $item['item_id'] ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteItem(<?= $item['item_id'] ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Add Capital Budget Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Budget Year *</label>
                            <input type="number" name="budget_year" class="form-control" value="<?= date('Y') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Item Code *</label>
                            <input type="text" name="item_code" class="form-control" placeholder="CAP-2024-001" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Item Name *</label>
                            <input type="text" name="item_name" class="form-control" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Asset Category *</label>
                            <select name="asset_category" class="form-select" required>
                                <option value="Equipment">Equipment</option>
                                <option value="Vehicle">Vehicle</option>
                                <option value="Infrastructure">Infrastructure</option>
                                <option value="Building">Building</option>
                                <option value="Technology">Technology</option>
                                <option value="Replanting">Replanting</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Asset Subcategory</label>
                            <input type="text" name="asset_subcategory" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" class="form-control" value="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit Price *</label>
                            <input type="number" name="unit_price" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Installation Cost</label>
                            <input type="number" name="installation_cost" class="form-control" step="0.01" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Other Costs</label>
                            <input type="number" name="other_costs" class="form-control" step="0.01" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Useful Life (years) *</label>
                            <input type="number" name="useful_life_years" class="form-control" value="5" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Depreciation Method *</label>
                            <select name="depreciation_method" class="form-select" required>
                                <option value="straight_line">Straight Line</option>
                                <option value="declining_balance">Declining Balance</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Salvage Value</label>
                            <input type="number" name="salvage_value" class="form-control" step="0.01" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Planned Purchase Date *</label>
                            <input type="date" name="planned_purchase_date" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Priority *</label>
                            <select name="priority" class="form-select" required>
                                <option value="critical">Critical</option>
                                <option value="high">High</option>
                                <option value="medium" selected>Medium</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Company</label>
                            <select name="company_id" class="form-select">
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?= $company['company_id'] ?>"><?= htmlspecialchars($company['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Division</label>
                            <select name="division_id" class="form-select">
                                <option value="">Select Division</option>
                                <?php foreach ($divisions as $division): ?>
                                    <option value="<?= $division['division_id'] ?>"><?= htmlspecialchars($division['division_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Block (Optional)</label>
                            <select name="block_id" class="form-select">
                                <option value="">Select Block</option>
                                <?php foreach ($blocks as $block): ?>
                                    <option value="<?= $block['block_id'] ?>"><?= htmlspecialchars($block['block_code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required>
                                <option value="proposed" selected>Proposed</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="purchased">Purchased</option>
                                <option value="in_use">In Use</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Business Case / Justification</label>
                            <textarea name="business_case" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function viewDetails(itemId) {
    window.location.href = 'activity_budget_capital_detail.php?id=' + itemId;
}

function updateStatus(itemId) {
    window.location.href = 'activity_budget_capital_update.php?id=' + itemId;
}

function deleteItem(itemId) {
    if (confirm('Are you sure you want to delete this capital budget item?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="item_id" value="${itemId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include 'includes/footer.php'; ?>

// Made with Bob
