<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$pageTitle = 'Update Capital Budget Item';
$db = getDB();

// Get item ID
$item_id = get('id');
if (!$item_id) {
    header('Location: activity_budget_capital.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'update') {
        $stmt = $db->prepare("
            UPDATE capital_budget_items 
            SET status = ?,
                actual_purchase_date = ?,
                start_depreciation_date = ?,
                approved_by = ?,
                approval_date = ?,
                approval_level = ?,
                priority = ?,
                notes = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE item_id = ?
        ");
        
        $stmt->execute([
            post('status'),
            post('actual_purchase_date') ?: null,
            post('start_depreciation_date') ?: null,
            post('approved_by') ?: null,
            post('approval_date') ?: null,
            post('approval_level') ?: null,
            post('priority'),
            post('notes'),
            'admin',
            $item_id
        ]);
        
        $success = "Capital budget item updated successfully!";
        
        // Redirect back to main page after 2 seconds
        header("refresh:2;url=activity_budget_capital.php");
    }
    
    if ($action === 'update_full') {
        $stmt = $db->prepare("
            UPDATE capital_budget_items 
            SET item_name = ?,
                description = ?,
                asset_category = ?,
                asset_subcategory = ?,
                quantity = ?,
                unit_price = ?,
                installation_cost = ?,
                other_costs = ?,
                useful_life_years = ?,
                depreciation_method = ?,
                salvage_value = ?,
                planned_purchase_date = ?,
                business_case = ?,
                expected_roi = ?,
                payback_period_months = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE item_id = ?
        ");
        
        $stmt->execute([
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
            post('business_case'),
            post('expected_roi') ?: null,
            post('payback_period_months') ?: null,
            'admin',
            $item_id
        ]);
        
        $success = "Capital budget item details updated successfully!";
    }
}

// Fetch item details
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
    WHERE cbi.item_id = ?
");
$stmt->execute([$item_id]);
$item = $stmt->fetch();

if (!$item) {
    header('Location: activity_budget_capital.php');
    exit;
}

include 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="activity_budget_capital.php">Capital Budget</a></li>
                    <li class="breadcrumb-item active">Update Item</li>
                </ol>
            </nav>
            <h2><i class="bi bi-pencil-square"></i> Update Capital Budget Item</h2>
            <p class="text-muted">Update status, dates, and details for capital budget item</p>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Item Summary Card -->
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-info-circle"></i> Item Summary</h5>
                </div>
                <div class="card-body">
                    <h6 class="text-muted">Item Code</h6>
                    <p class="mb-3"><strong><?= htmlspecialchars($item['item_code']) ?></strong></p>
                    
                    <h6 class="text-muted">Item Name</h6>
                    <p class="mb-3"><?= htmlspecialchars($item['item_name']) ?></p>
                    
                    <h6 class="text-muted">Category</h6>
                    <p class="mb-3">
                        <span class="badge bg-secondary"><?= $item['asset_category'] ?></span>
                        <?php if ($item['asset_subcategory']): ?>
                            <br><small><?= htmlspecialchars($item['asset_subcategory']) ?></small>
                        <?php endif; ?>
                    </p>
                    
                    <h6 class="text-muted">Budget Year</h6>
                    <p class="mb-3"><?= $item['budget_year'] ?></p>
                    
                    <h6 class="text-muted">Location</h6>
                    <p class="mb-3">
                        <?php if ($item['company_name']): ?>
                            <i class="bi bi-building"></i> <?= htmlspecialchars($item['company_name']) ?><br>
                        <?php endif; ?>
                        <?php if ($item['division_name']): ?>
                            <i class="bi bi-grid-3x3"></i> <?= htmlspecialchars($item['division_name']) ?><br>
                        <?php endif; ?>
                        <?php if ($item['block_code']): ?>
                            <i class="bi bi-grid"></i> <?= htmlspecialchars($item['block_code']) ?>
                        <?php endif; ?>
                    </p>
                    
                    <hr>
                    
                    <h6 class="text-muted">Financial Summary</h6>
                    <table class="table table-sm">
                        <tr>
                            <td>Quantity:</td>
                            <td class="text-end"><strong><?= number_format($item['quantity']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>Unit Price:</td>
                            <td class="text-end">Rp <?= number_format($item['unit_price']) ?></td>
                        </tr>
                        <tr>
                            <td>Equipment Cost:</td>
                            <td class="text-end">Rp <?= number_format($item['total_cost']) ?></td>
                        </tr>
                        <tr>
                            <td>Installation:</td>
                            <td class="text-end">Rp <?= number_format($item['installation_cost']) ?></td>
                        </tr>
                        <tr>
                            <td>Other Costs:</td>
                            <td class="text-end">Rp <?= number_format($item['other_costs']) ?></td>
                        </tr>
                        <tr class="table-primary">
                            <td><strong>Total Investment:</strong></td>
                            <td class="text-end"><strong>Rp <?= number_format($item['total_investment']) ?></strong></td>
                        </tr>
                        <tr>
                            <td>Useful Life:</td>
                            <td class="text-end"><?= $item['useful_life_years'] ?> years</td>
                        </tr>
                        <tr>
                            <td>Depreciation:</td>
                            <td class="text-end"><?= ucfirst(str_replace('_', ' ', $item['depreciation_method'])) ?></td>
                        </tr>
                        <tr>
                            <td>Salvage Value:</td>
                            <td class="text-end">Rp <?= number_format($item['salvage_value']) ?></td>
                        </tr>
                        <tr class="table-warning">
                            <td><strong>Annual Depreciation:</strong></td>
                            <td class="text-end"><strong>Rp <?= number_format($item['annual_depreciation']) ?></strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Update Forms -->
        <div class="col-md-8">
            <!-- Status Update Form -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-arrow-repeat"></i> Update Status & Tracking</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Status *</label>
                                <select name="status" class="form-select" required>
                                    <option value="proposed" <?= $item['status'] == 'proposed' ? 'selected' : '' ?>>Proposed</option>
                                    <option value="approved" <?= $item['status'] == 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="rejected" <?= $item['status'] == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                    <option value="purchased" <?= $item['status'] == 'purchased' ? 'selected' : '' ?>>Purchased</option>
                                    <option value="in_use" <?= $item['status'] == 'in_use' ? 'selected' : '' ?>>In Use</option>
                                    <option value="disposed" <?= $item['status'] == 'disposed' ? 'selected' : '' ?>>Disposed</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Priority *</label>
                                <select name="priority" class="form-select" required>
                                    <option value="critical" <?= $item['priority'] == 'critical' ? 'selected' : '' ?>>Critical</option>
                                    <option value="high" <?= $item['priority'] == 'high' ? 'selected' : '' ?>>High</option>
                                    <option value="medium" <?= $item['priority'] == 'medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="low" <?= $item['priority'] == 'low' ? 'selected' : '' ?>>Low</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Actual Purchase Date</label>
                                <input type="date" name="actual_purchase_date" class="form-control" 
                                       value="<?= $item['actual_purchase_date'] ?>">
                                <small class="text-muted">Planned: <?= date('d M Y', strtotime($item['planned_purchase_date'])) ?></small>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Start Depreciation Date</label>
                                <input type="date" name="start_depreciation_date" class="form-control" 
                                       value="<?= $item['start_depreciation_date'] ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Approved By</label>
                                <input type="text" name="approved_by" class="form-control" 
                                       value="<?= htmlspecialchars($item['approved_by'] ?? '') ?>" 
                                       placeholder="Name of approver">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Approval Date</label>
                                <input type="date" name="approval_date" class="form-control" 
                                       value="<?= $item['approval_date'] ?>">
                            </div>
                            
                            <div class="col-md-12">
                                <label class="form-label">Approval Level</label>
                                <select name="approval_level" class="form-select">
                                    <option value="">Not Set</option>
                                    <option value="1" <?= $item['approval_level'] == 1 ? 'selected' : '' ?>>Level 1 - Manager</option>
                                    <option value="2" <?= $item['approval_level'] == 2 ? 'selected' : '' ?>>Level 2 - Director</option>
                                    <option value="3" <?= $item['approval_level'] == 3 ? 'selected' : '' ?>>Level 3 - Board</option>
                                </select>
                            </div>
                            
                            <div class="col-md-12">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($item['notes'] ?? '') ?></textarea>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Update Status
                            </button>
                            <a href="activity_budget_capital.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to List
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Full Details Update Form -->
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-pencil"></i> Update Item Details</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_full">
                        
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label">Item Name *</label>
                                <input type="text" name="item_name" class="form-control" 
                                       value="<?= htmlspecialchars($item['item_name']) ?>" required>
                            </div>
                            
                            <div class="col-md-12">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($item['description'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Asset Category *</label>
                                <select name="asset_category" class="form-select" required>
                                    <option value="Equipment" <?= $item['asset_category'] == 'Equipment' ? 'selected' : '' ?>>Equipment</option>
                                    <option value="Vehicle" <?= $item['asset_category'] == 'Vehicle' ? 'selected' : '' ?>>Vehicle</option>
                                    <option value="Infrastructure" <?= $item['asset_category'] == 'Infrastructure' ? 'selected' : '' ?>>Infrastructure</option>
                                    <option value="Building" <?= $item['asset_category'] == 'Building' ? 'selected' : '' ?>>Building</option>
                                    <option value="Technology" <?= $item['asset_category'] == 'Technology' ? 'selected' : '' ?>>Technology</option>
                                    <option value="Replanting" <?= $item['asset_category'] == 'Replanting' ? 'selected' : '' ?>>Replanting</option>
                                    <option value="Other" <?= $item['asset_category'] == 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Asset Subcategory</label>
                                <input type="text" name="asset_subcategory" class="form-control" 
                                       value="<?= htmlspecialchars($item['asset_subcategory'] ?? '') ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Quantity *</label>
                                <input type="number" name="quantity" class="form-control" 
                                       value="<?= $item['quantity'] ?>" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Unit Price *</label>
                                <input type="number" name="unit_price" class="form-control" step="0.01" 
                                       value="<?= $item['unit_price'] ?>" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Installation Cost</label>
                                <input type="number" name="installation_cost" class="form-control" step="0.01" 
                                       value="<?= $item['installation_cost'] ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Other Costs</label>
                                <input type="number" name="other_costs" class="form-control" step="0.01" 
                                       value="<?= $item['other_costs'] ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Useful Life (years) *</label>
                                <input type="number" name="useful_life_years" class="form-control" 
                                       value="<?= $item['useful_life_years'] ?>" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Depreciation Method *</label>
                                <select name="depreciation_method" class="form-select" required>
                                    <option value="straight_line" <?= $item['depreciation_method'] == 'straight_line' ? 'selected' : '' ?>>Straight Line</option>
                                    <option value="declining_balance" <?= $item['depreciation_method'] == 'declining_balance' ? 'selected' : '' ?>>Declining Balance</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Salvage Value</label>
                                <input type="number" name="salvage_value" class="form-control" step="0.01" 
                                       value="<?= $item['salvage_value'] ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Planned Purchase Date *</label>
                                <input type="date" name="planned_purchase_date" class="form-control" 
                                       value="<?= $item['planned_purchase_date'] ?>" required>
                            </div>
                            
                            <div class="col-md-12">
                                <label class="form-label">Business Case / Justification</label>
                                <textarea name="business_case" class="form-control" rows="3"><?= htmlspecialchars($item['business_case'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Expected ROI (%)</label>
                                <input type="number" name="expected_roi" class="form-control" step="0.01" 
                                       value="<?= $item['expected_roi'] ?>" placeholder="e.g., 15.5">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Payback Period (months)</label>
                                <input type="number" name="payback_period_months" class="form-control" 
                                       value="<?= $item['payback_period_months'] ?>" placeholder="e.g., 36">
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-pencil"></i> Update Details
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

// Made with Bob
