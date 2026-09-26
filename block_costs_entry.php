<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO block_costs (
                    block_id, cost_date, cost_category, cost_description, 
                    cost_amount, quantity, unit, reference_no, remarks, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('block_id'),
                post('cost_date'),
                post('cost_category'),
                post('cost_description'),
                post('cost_amount'),
                post('quantity') ?: null,
                post('unit') ?: null,
                post('reference_no') ?: null,
                post('remarks') ?: null,
                'Admin' // Replace with actual user when authentication is implemented
            ]);
            
            set_message('Cost entry added successfully!', 'success');
            redirect('block_costs_entry.php');
        } catch (Exception $e) {
            set_message('Error adding cost entry: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'update') {
        try {
            $stmt = $db->prepare("
                UPDATE block_costs SET
                    block_id = ?,
                    cost_date = ?,
                    cost_category = ?,
                    cost_description = ?,
                    cost_amount = ?,
                    quantity = ?,
                    unit = ?,
                    reference_no = ?,
                    remarks = ?,
                    updated_by = ?
                WHERE cost_id = ?
            ");
            
            $stmt->execute([
                post('block_id'),
                post('cost_date'),
                post('cost_category'),
                post('cost_description'),
                post('cost_amount'),
                post('quantity') ?: null,
                post('unit') ?: null,
                post('reference_no') ?: null,
                post('remarks') ?: null,
                'Admin',
                post('cost_id')
            ]);
            
            set_message('Cost entry updated successfully!', 'success');
            redirect('block_costs_entry.php');
        } catch (Exception $e) {
            set_message('Error updating cost entry: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM block_costs WHERE cost_id = ?");
            $stmt->execute([post('cost_id')]);
            
            set_message('Cost entry deleted successfully!', 'success');
            redirect('block_costs_entry.php');
        } catch (Exception $e) {
            set_message('Error deleting cost entry: ' . $e->getMessage(), 'danger');
        }
    }
}

// Get edit record if editing
$edit_cost = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM block_costs WHERE cost_id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_cost = $stmt->fetch();
}

// Pagination
$page = get('page', 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$company_filter = get('company_id', '');
$division_filter = get('division_id', '');
$block_filter = get('block_id', '');
$category_filter = get('category', '');
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-t'));

// Fetch companies
$companies_stmt = $db->query("SELECT company_id, company_code, company_name FROM companies ORDER BY company_code");
$companies = $companies_stmt->fetchAll();

// Fetch divisions
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

// Fetch blocks
$blocks_sql = "SELECT b.block_id, b.block_code, b.block_name, d.division_name, py.year as planting_year
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

// Fetch cost entries with filters
$costs_sql = "
    SELECT 
        bc.*,
        b.block_code,
        b.block_name,
        d.division_name,
        c.company_name
    FROM block_costs bc
    INNER JOIN blocks b ON bc.block_id = b.block_id
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE bc.cost_date BETWEEN ? AND ?
";
$cost_params = [$date_from, $date_to];

if ($company_filter) {
    $costs_sql .= " AND c.company_id = ?";
    $cost_params[] = $company_filter;
}
if ($division_filter) {
    $costs_sql .= " AND d.division_id = ?";
    $cost_params[] = $division_filter;
}
if ($block_filter) {
    $costs_sql .= " AND b.block_id = ?";
    $cost_params[] = $block_filter;
}
if ($category_filter) {
    $costs_sql .= " AND bc.cost_category = ?";
    $cost_params[] = $category_filter;
}

$costs_sql .= " ORDER BY bc.cost_date DESC, bc.cost_id DESC LIMIT ? OFFSET ?";
$cost_params[] = $per_page;
$cost_params[] = $offset;

$stmt = $db->prepare($costs_sql);
$stmt->execute($cost_params);
$cost_entries = $stmt->fetchAll();

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total
    FROM block_costs bc
    INNER JOIN blocks b ON bc.block_id = b.block_id
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE bc.cost_date BETWEEN ? AND ?
";
$count_params = [$date_from, $date_to];

if ($company_filter) {
    $count_sql .= " AND c.company_id = ?";
    $count_params[] = $company_filter;
}
if ($division_filter) {
    $count_sql .= " AND d.division_id = ?";
    $count_params[] = $division_filter;
}
if ($block_filter) {
    $count_sql .= " AND b.block_id = ?";
    $count_params[] = $block_filter;
}
if ($category_filter) {
    $count_sql .= " AND bc.cost_category = ?";
    $count_params[] = $category_filter;
}

$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_records = $stmt->fetch()['total'];
$total_pages = ceil($total_records / $per_page);

$page_title = "Block Cost Entry";
require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-plus-circle"></i> Block Cost Entry</h1>
            <p class="text-muted">Record and manage block-level costs</p>
        </div>
        <div class="col-auto">
            <a href="block_costing.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Analysis
            </a>
        </div>
    </div>
</div>

<!-- Entry Form -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-pencil-square"></i> <?php echo $edit_cost ? 'Edit' : 'Add New'; ?> Cost Entry
    </div>
    <div class="card-body">
        <form method="POST" action="block_costs_entry.php">
            <input type="hidden" name="action" value="<?php echo $edit_cost ? 'update' : 'add'; ?>">
            <?php if ($edit_cost): ?>
                <input type="hidden" name="cost_id" value="<?php echo $edit_cost['cost_id']; ?>">
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Block <span class="text-danger">*</span></label>
                    <select name="block_id" class="form-select" required>
                        <option value="">Select Block</option>
                        <?php foreach ($blocks as $block): ?>
                            <option value="<?php echo $block['block_id']; ?>"
                                    <?php echo ($edit_cost && $edit_cost['block_id'] == $block['block_id']) ? 'selected' : ''; ?>>
                                <?php echo $block['block_code']; ?> - <?php echo $block['block_name']; ?>
                                (<?php echo $block['division_name']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Cost Date <span class="text-danger">*</span></label>
                    <input type="date" name="cost_date" class="form-control" 
                           value="<?php echo $edit_cost ? $edit_cost['cost_date'] : date('Y-m-d'); ?>" required>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Category <span class="text-danger">*</span></label>
                    <select name="cost_category" class="form-select" required>
                        <option value="">Select Category</option>
                        <option value="labor" <?php echo ($edit_cost && $edit_cost['cost_category'] == 'labor') ? 'selected' : ''; ?>>Labor</option>
                        <option value="material" <?php echo ($edit_cost && $edit_cost['cost_category'] == 'material') ? 'selected' : ''; ?>>Material</option>
                        <option value="equipment" <?php echo ($edit_cost && $edit_cost['cost_category'] == 'equipment') ? 'selected' : ''; ?>>Equipment</option>
                        <option value="fertilizer" <?php echo ($edit_cost && $edit_cost['cost_category'] == 'fertilizer') ? 'selected' : ''; ?>>Fertilizer</option>
                        <option value="pesticide" <?php echo ($edit_cost && $edit_cost['cost_category'] == 'pesticide') ? 'selected' : ''; ?>>Pesticide</option>
                        <option value="harvesting" <?php echo ($edit_cost && $edit_cost['cost_category'] == 'harvesting') ? 'selected' : ''; ?>>Harvesting</option>
                        <option value="maintenance" <?php echo ($edit_cost && $edit_cost['cost_category'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="overhead" <?php echo ($edit_cost && $edit_cost['cost_category'] == 'overhead') ? 'selected' : ''; ?>>Overhead</option>
                        <option value="other" <?php echo ($edit_cost && $edit_cost['cost_category'] == 'other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Description <span class="text-danger">*</span></label>
                    <input type="text" name="cost_description" class="form-control" 
                           value="<?php echo $edit_cost ? htmlspecialchars($edit_cost['cost_description']) : ''; ?>" 
                           placeholder="e.g., Manual weeding, NPK fertilizer" required>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Amount (Rp) <span class="text-danger">*</span></label>
                    <input type="number" name="cost_amount" class="form-control" step="0.01" min="0"
                           value="<?php echo $edit_cost ? $edit_cost['cost_amount'] : ''; ?>" 
                           placeholder="0.00" required>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Reference No</label>
                    <input type="text" name="reference_no" class="form-control" 
                           value="<?php echo $edit_cost ? htmlspecialchars($edit_cost['reference_no']) : ''; ?>" 
                           placeholder="e.g., INV-001">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="quantity" class="form-control" step="0.01" min="0"
                           value="<?php echo $edit_cost ? $edit_cost['quantity'] : ''; ?>" 
                           placeholder="0.00">
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Unit</label>
                    <input type="text" name="unit" class="form-control" 
                           value="<?php echo $edit_cost ? htmlspecialchars($edit_cost['unit']) : ''; ?>" 
                           placeholder="e.g., kg, hours, bags">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">Remarks</label>
                    <input type="text" name="remarks" class="form-control" 
                           value="<?php echo $edit_cost ? htmlspecialchars($edit_cost['remarks']) : ''; ?>" 
                           placeholder="Additional notes">
                </div>
            </div>
            
            <div class="row">
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_cost ? 'Update' : 'Save'; ?> Cost Entry
                    </button>
                    <?php if ($edit_cost): ?>
                        <a href="block_costs_entry.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Filter Section -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Company</label>
                <select name="company_id" class="form-select" onchange="this.form.submit()">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?php echo $company['company_id']; ?>"
                                <?php echo $company_filter == $company['company_id'] ? 'selected' : ''; ?>>
                            <?php echo $company['company_code']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Division</label>
                <select name="division_id" class="form-select" onchange="this.form.submit()">
                    <option value="">All Divisions</option>
                    <?php foreach ($divisions as $division): ?>
                        <option value="<?php echo $division['division_id']; ?>"
                                <?php echo $division_filter == $division['division_id'] ? 'selected' : ''; ?>>
                            <?php echo $division['division_code']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Block</label>
                <select name="block_id" class="form-select">
                    <option value="">All Blocks</option>
                    <?php foreach ($blocks as $block): ?>
                        <option value="<?php echo $block['block_id']; ?>"
                                <?php echo $block_filter == $block['block_id'] ? 'selected' : ''; ?>>
                            <?php echo $block['block_code']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <option value="labor" <?php echo $category_filter == 'labor' ? 'selected' : ''; ?>>Labor</option>
                    <option value="material" <?php echo $category_filter == 'material' ? 'selected' : ''; ?>>Material</option>
                    <option value="equipment" <?php echo $category_filter == 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                    <option value="fertilizer" <?php echo $category_filter == 'fertilizer' ? 'selected' : ''; ?>>Fertilizer</option>
                    <option value="pesticide" <?php echo $category_filter == 'pesticide' ? 'selected' : ''; ?>>Pesticide</option>
                    <option value="harvesting" <?php echo $category_filter == 'harvesting' ? 'selected' : ''; ?>>Harvesting</option>
                    <option value="maintenance" <?php echo $category_filter == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    <option value="overhead" <?php echo $category_filter == 'overhead' ? 'selected' : ''; ?>>Overhead</option>
                    <option value="other" <?php echo $category_filter == 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
            </div>
            
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel"></i> Apply Filters
                </button>
                <a href="block_costs_entry.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Clear Filters
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Cost Entries List -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Recent Cost Entries (<?php echo number_format($total_records); ?> records)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Block</th>
                        <th>Division</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th class="text-end">Quantity</th>
                        <th class="text-end">Amount</th>
                        <th>Reference</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cost_entries)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">No cost entries found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cost_entries as $cost): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($cost['cost_date'])); ?></td>
                                <td>
                                    <strong><?php echo $cost['block_code']; ?></strong><br>
                                    <small class="text-muted"><?php echo $cost['block_name']; ?></small>
                                </td>
                                <td><?php echo $cost['division_name']; ?></td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo match($cost['cost_category']) {
                                            'labor' => 'primary',
                                            'material' => 'info',
                                            'equipment' => 'warning',
                                            'fertilizer' => 'success',
                                            'pesticide' => 'danger',
                                            'harvesting' => 'dark',
                                            'maintenance' => 'secondary',
                                            'overhead' => 'light text-dark',
                                            default => 'secondary'
                                        };
                                    ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $cost['cost_category'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($cost['cost_description']); ?>
                                    <?php if ($cost['remarks']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($cost['remarks']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($cost['quantity']): ?>
                                        <?php echo format_number($cost['quantity'], 2); ?>
                                        <?php echo $cost['unit'] ? ' ' . $cost['unit'] : ''; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <strong>Rp <?php echo format_number($cost['cost_amount'], 0); ?></strong>
                                </td>
                                <td>
                                    <?php echo $cost['reference_no'] ? htmlspecialchars($cost['reference_no']) : '<span class="text-muted">-</span>'; ?>
                                </td>
                                <td class="text-center">
                                    <a href="block_costs_entry.php?edit=<?php echo $cost['cost_id']; ?>" 
                                       class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this cost entry?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="cost_id" value="<?php echo $cost['cost_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
            <?php echo generate_pagination($page, $total_pages, $_GET); ?>
        <?php endif; ?>
    </div>
</div>

<script>
function confirmDelete(message) {
    return confirm(message || 'Are you sure you want to delete this entry?');
}
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
