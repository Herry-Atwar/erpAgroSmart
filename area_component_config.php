<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Area Component Configuration";
require_once 'includes/header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    try {
        if ($action === 'add_category') {
            $stmt = $db->prepare("
                INSERT INTO block_area_component_categories 
                (company_id, category_code, category_name, category_type, description, display_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                post('company_id') ?: null,
                post('category_code'),
                post('category_name'),
                post('category_type'),
                post('description'),
                post('display_order')
            ]);
            $success_message = "Category added successfully!";
            
        } elseif ($action === 'edit_category') {
            $stmt = $db->prepare("
                UPDATE block_area_component_categories
                SET company_id = ?, category_name = ?, category_type = ?, description = ?, display_order = ?
                WHERE id = ?
            ");
            $stmt->execute([
                post('company_id') ?: null,
                post('category_name'),
                post('category_type'),
                post('description'),
                post('display_order'),
                post('category_id')
            ]);
            $success_message = "Category updated successfully!";
            
        } elseif ($action === 'add_measurement') {
            $stmt = $db->prepare("
                INSERT INTO area_component_measurement_types 
                (category_id, measurement_code, measurement_name, unit_of_measure, data_type, 
                 decimal_places, is_required, is_for_budget, default_value, display_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                post('category_id'),
                post('measurement_code'),
                post('measurement_name'),
                post('unit_of_measure'),
                post('data_type'),
                post('decimal_places'),
                post('is_required') ? 1 : 0,
                post('is_for_budget') ? 1 : 0,
                post('default_value'),
                post('display_order')
            ]);
            $success_message = "Measurement added successfully!";
            
        } elseif ($action === 'edit_measurement') {
            $stmt = $db->prepare("
                UPDATE area_component_measurement_types
                SET measurement_name = ?, unit_of_measure = ?, data_type = ?,
                    decimal_places = ?, is_required = ?, is_for_budget = ?,
                    default_value = ?, display_order = ?
                WHERE id = ?
            ");
            $stmt->execute([
                post('measurement_name'),
                post('unit_of_measure'),
                post('data_type'),
                post('decimal_places'),
                post('is_required') ? 1 : 0,
                post('is_for_budget') ? 1 : 0,
                post('default_value'),
                post('display_order'),
                post('measurement_id')
            ]);
            $success_message = "Measurement updated successfully!";
            
        } elseif ($action === 'delete_measurement') {
            $stmt = $db->prepare("DELETE FROM area_component_measurement_types WHERE id = ?");
            $stmt->execute([post('measurement_id')]);
            $success_message = "Measurement deleted successfully!";
            
        } elseif ($action === 'toggle_active') {
            $stmt = $db->prepare("
                UPDATE block_area_component_categories 
                SET is_active = NOT is_active 
                WHERE id = ?
            ");
            $stmt->execute([post('category_id')]);
            $success_message = "Category status updated!";
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Fetch all companies for dropdown
$companies_stmt = $db->query("SELECT company_id, company_name FROM companies ORDER BY company_name");
$companies = $companies_stmt->fetchAll();

// Fetch all categories with their measurements
$categories_stmt = $db->query("
    SELECT
        bacc.*,
        c.company_name,
        (SELECT COUNT(*) FROM area_component_measurement_types acmt WHERE acmt.category_id = bacc.id) as measurement_count
    FROM block_area_component_categories bacc
    LEFT JOIN companies c ON bacc.company_id = c.company_id
    ORDER BY bacc.display_order
");
$categories = $categories_stmt->fetchAll();

// Fetch all measurements grouped by category
$measurements_stmt = $db->query("
    SELECT 
        acmt.*,
        bacc.category_name,
        bacc.category_code
    FROM area_component_measurement_types acmt
    JOIN block_area_component_categories bacc ON acmt.category_id = bacc.id
    ORDER BY bacc.display_order, acmt.display_order
");
$all_measurements = $measurements_stmt->fetchAll();
?>

<style>
.config-section {
    margin-bottom: 30px;
}

.measurement-item {
    border-left: 3px solid #2e7d32;
    padding-left: 15px;
    margin-bottom: 10px;
}

.badge-custom {
    font-size: 0.75rem;
    padding: 4px 8px;
}
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2><i class="bi bi-gear"></i> Area Component Configuration</h2>
            <p class="text-muted">Configure categories and measurements for block area components. Changes apply immediately to all blocks.</p>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Add New Category -->
    <div class="card config-section">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add New Category</h5>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="add_category">
                
                <div class="col-md-3">
                    <label class="form-label">Category Code *</label>
                    <input type="text" name="category_code" class="form-control" required 
                           placeholder="e.g., MAIN_ROAD" pattern="[A-Z_]+" 
                           title="Uppercase letters and underscores only">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Category Name *</label>
                    <input type="text" name="category_name" class="form-control" required 
                           placeholder="e.g., Main Road">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Type *</label>
                    <select name="category_type" class="form-select" required>
                        <option value="planted">Planted</option>
                        <option value="non_planted" selected>Non-Planted</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Display Order</label>
                    <input type="number" name="display_order" class="form-control" value="99" min="1">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Company</label>
                    <select name="company_id" class="form-select">
                        <option value="">All Companies</option>
                        <?php foreach ($companies as $company): ?>
                        <option value="<?= $company['company_id'] ?>">
                            <?= htmlspecialchars($company['company_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Leave empty for all</small>
                </div>
                
                <div class="col-md-12">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" 
                           placeholder="Brief description of this category">
                </div>
                
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Add Category
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add New Measurement -->
    <div class="card config-section">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add New Measurement</h5>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="add_measurement">
                
                <div class="col-md-3">
                    <label class="form-label">Category *</label>
                    <select name="category_id" class="form-select" required>
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>">
                            <?= htmlspecialchars($cat['category_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Code *</label>
                    <input type="text" name="measurement_code" class="form-control" required 
                           placeholder="e.g., LENGTH" pattern="[A-Z_]+" 
                           title="Uppercase letters and underscores only">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Name *</label>
                    <input type="text" name="measurement_name" class="form-control" required 
                           placeholder="e.g., Road Length">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Unit *</label>
                    <select name="unit_of_measure" class="form-select" required>
                        <option value="ha">ha (hectare)</option>
                        <option value="meter">meter</option>
                        <option value="km">km (kilometer)</option>
                        <option value="m2">m2 (square meter)</option>
                        <option value="trees">trees</option>
                        <option value="trees/ha">trees/ha</option>
                        <option value="units">units</option>
                        <option value="text">text</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Data Type *</label>
                    <select name="data_type" class="form-select" required>
                        <option value="decimal">Decimal</option>
                        <option value="integer">Integer</option>
                        <option value="text">Text</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Decimals</label>
                    <input type="number" name="decimal_places" class="form-control" value="2" min="0" max="4">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Default Value</label>
                    <input type="number" name="default_value" class="form-control" value="0" step="0.01">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Display Order</label>
                    <input type="number" name="display_order" class="form-control" value="99" min="1">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Options</label>
                    <div class="form-check">
                        <input type="checkbox" name="is_required" class="form-check-input" id="is_required">
                        <label class="form-check-label" for="is_required">Required</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_for_budget" class="form-check-input" id="is_for_budget">
                        <label class="form-check-label" for="is_for_budget">Use in Budget</label>
                    </div>
                </div>
                
                <div class="col-12">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-circle"></i> Add Measurement
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Categories and Measurements -->
    <div class="card">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-list-ul"></i> Current Configuration</h5>
        </div>
        <div class="card-body">
            <?php foreach ($categories as $category): ?>
            <div class="card mb-3">
                <div class="card-header bg-<?= $category['category_type'] == 'planted' ? 'success' : 'warning' ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= htmlspecialchars($category['category_name']) ?></strong>
                            <span class="badge bg-dark ms-2"><?= $category['category_code'] ?></span>
                            <span class="badge bg-secondary ms-1"><?= ucfirst($category['category_type']) ?></span>
                            <?php if ($category['company_name']): ?>
                            <span class="badge bg-primary ms-1"><?= htmlspecialchars($category['company_name']) ?></span>
                            <?php else: ?>
                            <span class="badge bg-info ms-1">All Companies</span>
                            <?php endif; ?>
                            <?php if (!$category['is_active']): ?>
                            <span class="badge bg-danger ms-1">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <button type="button" class="btn btn-sm btn-light me-1"
                                    onclick="editCategory(<?= htmlspecialchars(json_encode($category)) ?>)">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="category_id" value="<?= $category['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-light">
                                    <?= $category['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php if ($category['description']): ?>
                    <small class="text-white-50"><?= htmlspecialchars($category['description']) ?></small>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <h6>Measurements (<?= $category['measurement_count'] ?>):</h6>
                    <?php 
                    $cat_measurements = array_filter($all_measurements, function($m) use ($category) {
                        return $m['category_id'] == $category['id'];
                    });
                    
                    if (empty($cat_measurements)): ?>
                        <p class="text-muted">No measurements defined yet.</p>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($cat_measurements as $measurement): ?>
                            <div class="col-md-6 mb-2">
                                <div class="measurement-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?= htmlspecialchars($measurement['measurement_name']) ?></strong>
                                            <span class="badge bg-secondary ms-2"><?= $measurement['measurement_code'] ?></span>
                                            <br>
                                            <small class="text-muted">
                                                Unit: <strong><?= $measurement['unit_of_measure'] ?></strong> | 
                                                Type: <strong><?= $measurement['data_type'] ?></strong>
                                                <?php if ($measurement['data_type'] == 'decimal'): ?>
                                                (<?= $measurement['decimal_places'] ?> decimals)
                                                <?php endif; ?>
                                            </small>
                                            <br>
                                            <?php if ($measurement['is_required']): ?>
                                            <span class="badge badge-custom bg-danger">Required</span>
                                            <?php endif; ?>
                                            <?php if ($measurement['is_for_budget']): ?>
                                            <span class="badge badge-custom bg-info">Budget</span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-sm btn-outline-primary me-1"
                                                    onclick="editMeasurement(<?= htmlspecialchars(json_encode($measurement)) ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this measurement?');">
                                                <input type="hidden" name="action" value="delete_measurement">
                                                <input type="hidden" name="measurement_id" value="<?= $measurement['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_category">
                <input type="hidden" name="category_id" id="edit_cat_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Code</label>
                        <input type="text" class="form-control" id="edit_cat_code" disabled>
                        <small class="text-muted">Code cannot be changed</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" name="category_name" id="edit_cat_name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Company</label>
                        <select name="company_id" id="edit_cat_company" class="form-select">
                            <option value="">All Companies</option>
                            <?php foreach ($companies as $company): ?>
                            <option value="<?= $company['company_id'] ?>">
                                <?= htmlspecialchars($company['company_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Leave empty to apply to all companies</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Type *</label>
                        <select name="category_type" id="edit_cat_type" class="form-select" required>
                            <option value="planted">Planted</option>
                            <option value="non_planted">Non-Planted</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" id="edit_cat_order" class="form-control" min="1">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" id="edit_cat_desc" class="form-control">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Measurement Modal -->
<div class="modal fade" id="editMeasurementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_measurement">
                <input type="hidden" name="measurement_id" id="edit_meas_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Edit Measurement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <input type="text" class="form-control" id="edit_meas_category" disabled>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Code</label>
                            <input type="text" class="form-control" id="edit_meas_code" disabled>
                            <small class="text-muted">Code cannot be changed</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" name="measurement_name" id="edit_meas_name" class="form-control" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Unit *</label>
                            <select name="unit_of_measure" id="edit_meas_unit" class="form-select" required>
                                <option value="ha">ha (hectare)</option>
                                <option value="meter">meter</option>
                                <option value="km">km (kilometer)</option>
                                <option value="m2">m2 (square meter)</option>
                                <option value="trees">trees</option>
                                <option value="trees/ha">trees/ha</option>
                                <option value="units">units</option>
                                <option value="text">text</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Data Type *</label>
                            <select name="data_type" id="edit_meas_datatype" class="form-select" required>
                                <option value="decimal">Decimal</option>
                                <option value="integer">Integer</option>
                                <option value="text">Text</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Decimals</label>
                            <input type="number" name="decimal_places" id="edit_meas_decimals" class="form-control" min="0" max="4">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Default Value</label>
                            <input type="number" name="default_value" id="edit_meas_default" class="form-control" step="0.01">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Display Order</label>
                            <input type="number" name="display_order" id="edit_meas_order" class="form-control" min="1">
                        </div>
                        
                        <div class="col-md-8">
                            <label class="form-label">Options</label>
                            <div class="form-check">
                                <input type="checkbox" name="is_required" id="edit_meas_required" class="form-check-input">
                                <label class="form-check-label" for="edit_meas_required">Required</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="is_for_budget" id="edit_meas_budget" class="form-check-input">
                                <label class="form-check-label" for="edit_meas_budget">Use in Budget</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCategory(category) {
    document.getElementById('edit_cat_id').value = category.id;
    document.getElementById('edit_cat_code').value = category.category_code;
    document.getElementById('edit_cat_name').value = category.category_name;
    document.getElementById('edit_cat_company').value = category.company_id || '';
    document.getElementById('edit_cat_type').value = category.category_type;
    document.getElementById('edit_cat_order').value = category.display_order;
    document.getElementById('edit_cat_desc').value = category.description || '';
    
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

function editMeasurement(measurement) {
    document.getElementById('edit_meas_id').value = measurement.id;
    document.getElementById('edit_meas_category').value = measurement.category_name;
    document.getElementById('edit_meas_code').value = measurement.measurement_code;
    document.getElementById('edit_meas_name').value = measurement.measurement_name;
    document.getElementById('edit_meas_unit').value = measurement.unit_of_measure;
    document.getElementById('edit_meas_datatype').value = measurement.data_type;
    document.getElementById('edit_meas_decimals').value = measurement.decimal_places;
    document.getElementById('edit_meas_default').value = measurement.default_value;
    document.getElementById('edit_meas_order').value = measurement.display_order;
    document.getElementById('edit_meas_required').checked = measurement.is_required == 1;
    document.getElementById('edit_meas_budget').checked = measurement.is_for_budget == 1;
    
    new bootstrap.Modal(document.getElementById('editMeasurementModal')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob