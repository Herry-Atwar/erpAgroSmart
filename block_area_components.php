<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Block Area Components";
require_once 'includes/header.php';
?>

<style>
/* Compact layout for block area components */
.content-wrapper {
    padding: 15px !important;
}

.card {
    margin-bottom: 15px !important;
}

.card-header {
    padding: 10px 15px !important;
}

.card-body {
    padding: 15px !important;
}

.row.g-3 {
    row-gap: 10px !important;
}

.form-label {
    margin-bottom: 4px !important;
    font-size: 0.9rem;
}

.input-group {
    margin-bottom: 0 !important;
}

.col-md-4 {
    padding-top: 0 !important;
    padding-bottom: 0 !important;
}

h2 {
    font-size: 1.5rem !important;
    margin-bottom: 15px !important;
}

.alert {
    padding: 10px 15px !important;
    margin-bottom: 15px !important;
}
</style>

<?php

// Get parameters
$block_id = get('block_id', '');
$action = get('action', 'list');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = post('action');
    
    if ($post_action === 'save_values') {
        try {
            $block_id = post('block_id');
            $values = post('values', []);
            
            foreach ($values as $key => $value) {
                list($category_id, $measurement_type_id) = explode('_', $key);
                
                // Check if value exists
                $check_stmt = $db->prepare("
                    SELECT id FROM block_area_component_values 
                    WHERE block_id = ? AND category_id = ? AND measurement_type_id = ?
                ");
                $check_stmt->execute([$block_id, $category_id, $measurement_type_id]);
                $existing = $check_stmt->fetch();
                
                if ($existing) {
                    // Update
                    $stmt = $db->prepare("
                        UPDATE block_area_component_values 
                        SET value = ?, updated_by = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$value, 'admin', $existing['id']]);
                } else {
                    // Insert
                    $stmt = $db->prepare("
                        INSERT INTO block_area_component_values 
                        (block_id, category_id, measurement_type_id, value, updated_by)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$block_id, $category_id, $measurement_type_id, $value, 'admin']);
                }
            }
            
            $success_message = "Block area components saved successfully!";
        } catch (PDOException $e) {
            $error_message = "Error saving values: " . $e->getMessage();
        }
    }
}

// Fetch blocks
$blocks_stmt = $db->query("
    SELECT block_id, block_code, block_name, area 
    FROM blocks 
    ORDER BY block_code
");
$blocks = $blocks_stmt->fetchAll();

// Fetch categories and measurements
$categories_stmt = $db->query("
    SELECT 
        bacc.id as category_id,
        bacc.category_code,
        bacc.category_name,
        bacc.category_type,
        bacc.description,
        acmt.id as measurement_type_id,
        acmt.measurement_code,
        acmt.measurement_name,
        acmt.unit_of_measure,
        acmt.data_type,
        acmt.decimal_places,
        acmt.is_required,
        acmt.is_for_budget
    FROM block_area_component_categories bacc
    INNER JOIN area_component_measurement_types acmt ON bacc.id = acmt.category_id
    WHERE bacc.is_active = TRUE
    ORDER BY bacc.display_order, acmt.display_order
");
$all_measurements = $categories_stmt->fetchAll();

// Group by category
$categories = [];
foreach ($all_measurements as $row) {
    $cat_id = $row['category_id'];
    if (!isset($categories[$cat_id])) {
        $categories[$cat_id] = [
            'id' => $row['category_id'],
            'code' => $row['category_code'],
            'name' => $row['category_name'],
            'type' => $row['category_type'],
            'description' => $row['description'],
            'measurements' => []
        ];
    }
    $categories[$cat_id]['measurements'][] = [
        'id' => $row['measurement_type_id'],
        'code' => $row['measurement_code'],
        'name' => $row['measurement_name'],
        'unit' => $row['unit_of_measure'],
        'data_type' => $row['data_type'],
        'decimal_places' => $row['decimal_places'],
        'required' => $row['is_required'],
        'for_budget' => $row['is_for_budget']
    ];
}

// Fetch values for selected block
$block_values = [];
if ($block_id) {
    $values_stmt = $db->prepare("
        SELECT
            bacv.category_id,
            bacv.measurement_type_id,
            bacv.value,
            bacv.text_value,
            acmt.data_type
        FROM block_area_component_values bacv
        INNER JOIN area_component_measurement_types acmt ON bacv.measurement_type_id = acmt.id
        WHERE bacv.block_id = ?
    ");
    $values_stmt->execute([$block_id]);
    foreach ($values_stmt->fetchAll() as $row) {
        $key = $row['category_id'] . '_' . $row['measurement_type_id'];
        $block_values[$key] = $row['data_type'] == 'text' ? $row['text_value'] : $row['value'];
    }
}

// Get selected block info
$selected_block = null;
if ($block_id) {
    foreach ($blocks as $block) {
        if ($block['block_id'] == $block_id) {
            $selected_block = $block;
            break;
        }
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-grid-3x3"></i> Block Area Components</h2>
                <a href="areal_statement_report.php" class="btn btn-info">
                    <i class="bi bi-file-earmark-text"></i> Areal Statement Report
                </a>
            </div>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle"></i> <strong>Error:</strong> <?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Block Selection -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Select Block</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Block *</label>
                    <select name="block_id" class="form-select" required onchange="this.form.submit()">
                        <option value="">-- Select Block --</option>
                        <?php foreach ($blocks as $block): ?>
                        <option value="<?= $block['block_id'] ?>" <?= $block['block_id'] == $block_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($block['block_code']) ?> - <?= htmlspecialchars($block['block_name']) ?> (<?= $block['area'] ?> ha)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selected_block): ?>
    <!-- Block Info -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <strong>Block Code:</strong> <?= htmlspecialchars($selected_block['block_code']) ?>
                </div>
                <div class="col-md-4">
                    <strong>Block Name:</strong> <?= htmlspecialchars($selected_block['block_name']) ?>
                </div>
                <div class="col-md-3">
                    <strong>Total Area:</strong> <?= number_format($selected_block['area'], 2) ?> ha
                </div>
            </div>
        </div>
    </div>

    <!-- Area Components Form -->
    <form method="POST">
        <input type="hidden" name="action" value="save_values">
        <input type="hidden" name="block_id" value="<?= $block_id ?>">
        
        <?php foreach ($categories as $category): ?>
        <div class="card mb-4">
            <div class="card-header bg-<?= $category['type'] == 'planted' ? 'success' : 'warning' ?> text-white">
                <h5 class="mb-0">
                    <i class="bi bi-<?= $category['type'] == 'planted' ? 'tree' : 'building' ?>"></i>
                    <?= htmlspecialchars($category['name']) ?>
                    <small class="ms-2">(<?= ucfirst($category['type']) ?>)</small>
                </h5>
                <?php if ($category['description']): ?>
                <small><?= htmlspecialchars($category['description']) ?></small>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($category['measurements'] as $measurement): ?>
                    <div class="col-md-4">
                        <label class="form-label">
                            <?= htmlspecialchars($measurement['name']) ?>
                            <?php if ($measurement['required']): ?>
                            <span class="text-danger">*</span>
                            <?php endif; ?>
                            <?php if ($measurement['for_budget']): ?>
                            <span class="badge bg-info ms-1" title="Used in budget calculations">Budget</span>
                            <?php endif; ?>
                        </label>
                        <div class="input-group">
                            <?php 
                            $field_name = 'values[' . $category['id'] . '_' . $measurement['id'] . ']';
                            $field_value = $block_values[$category['id'] . '_' . $measurement['id']] ?? 0;
                            ?>
                            
                            <?php
                            // Special handling for specific measurements
                            $isPlantedArea = ($measurement['name'] === 'Planted Area');
                            $isTotalTrees = ($measurement['name'] === 'Total Trees');
                            $isProductiveTrees = ($measurement['name'] === 'Productive Trees');
                            $isPlantingDensity = ($measurement['name'] === 'Planting Density');
                            
                            if ($measurement['data_type'] == 'text'):
                            ?>
                            <input type="text" name="<?= $field_name ?>" class="form-control"
                                   value="<?= htmlspecialchars($field_value) ?>"
                                   <?= $measurement['required'] ? 'required' : '' ?>>
                            <?php elseif ($isPlantingDensity): ?>
                            <input type="text"
                                   name="<?= $field_name ?>"
                                   id="planting_density_<?= $category['id'] ?>"
                                   class="form-control bg-light text-end"
                                   value="<?= number_format($field_value, 0, ',', ',') ?>"
                                   readonly>
                            <?php elseif ($isPlantedArea): ?>
                            <input type="number"
                                   name="<?= $field_name ?>"
                                   id="planted_area_<?= $category['id'] ?>"
                                   class="form-control"
                                   value="<?= number_format($field_value, 2, '.', '') ?>"
                                   step="0.01"
                                   min="0"
                                   onchange="calculatePlantingDensity(<?= $category['id'] ?>)"
                                   <?= $measurement['required'] ? 'required' : '' ?>>
                            <?php elseif ($isTotalTrees): ?>
                            <input type="text"
                                   name="<?= $field_name ?>"
                                   id="total_trees_<?= $category['id'] ?>"
                                   data-raw-value="<?= intval($field_value) ?>"
                                   class="form-control text-end tree-count-input"
                                   value="<?= number_format($field_value, 0, ',', ',') ?>"
                                   onkeyup="formatTreeCount(this, <?= $category['id'] ?>)"
                                   onblur="formatTreeCount(this, <?= $category['id'] ?>)"
                                   <?= $measurement['required'] ? 'required' : '' ?>>
                            <?php elseif ($isProductiveTrees): ?>
                            <input type="text"
                                   name="<?= $field_name ?>"
                                   class="form-control text-end tree-count-input"
                                   value="<?= number_format($field_value, 0, ',', ',') ?>"
                                   onkeyup="formatTreeCount(this)"
                                   onblur="formatTreeCount(this)"
                                   <?= $measurement['required'] ? 'required' : '' ?>>
                            <?php elseif ($measurement['data_type'] == 'integer'): ?>
                            <input type="number" name="<?= $field_name ?>" class="form-control"
                                   value="<?= intval($field_value) ?>" step="1" min="0"
                                   <?= $measurement['required'] ? 'required' : '' ?>>
                            <?php else:
                                // All decimal fields use 2 decimals (except integers/trees which are handled above)
                                $step = '0.01';
                                $decimals = 2;
                                $formatted_value = number_format($field_value, $decimals, '.', '');
                            ?>
                            <input type="number" name="<?= $field_name ?>" class="form-control"
                                   value="<?= $formatted_value ?>" step="<?= $step ?>" min="0"
                                   <?= $measurement['required'] ? 'required' : '' ?>>
                            <?php endif; ?>
                            
                            <span class="input-group-text"><?= htmlspecialchars($measurement['unit']) ?></span>
                        </div>
                        <?php if ($isPlantingDensity): ?>
                        <small class="text-muted">Auto-calculated: Total Trees ÷ Planted Area</small>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <div class="card">
            <div class="card-body text-end">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-save"></i> Save All Components
                </button>
            </div>
        </div>
    </form>
    <?php else: ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> Please select a block to manage its area components.
    </div>
    <?php endif; ?>
</div>

<script>
// Format tree count with thousand separators
function formatTreeCount(input, categoryId) {
    // Remove all non-digit characters
    let value = input.value.replace(/[^\d]/g, '');
    
    // Store raw value
    if (value === '') {
        input.dataset.rawValue = '0';
        input.value = '';
    } else {
        input.dataset.rawValue = value;
        // Format with thousand separators
        input.value = parseInt(value).toLocaleString('en-US');
    }
    
    // Trigger density calculation if this is total trees
    if (categoryId) {
        calculatePlantingDensity(categoryId);
    }
}

function calculatePlantingDensity(categoryId) {
    const plantedAreaInput = document.getElementById('planted_area_' + categoryId);
    const totalTreesInput = document.getElementById('total_trees_' + categoryId);
    const plantingDensityInput = document.getElementById('planting_density_' + categoryId);
    
    if (plantedAreaInput && totalTreesInput && plantingDensityInput) {
        const plantedArea = parseFloat(plantedAreaInput.value) || 0;
        const totalTrees = parseInt(totalTreesInput.dataset.rawValue || totalTreesInput.value.replace(/[^\d]/g, '')) || 0;
        
        if (plantedArea > 0 && totalTrees > 0) {
            const density = Math.round(totalTrees / plantedArea);
            plantingDensityInput.value = density.toLocaleString('en-US');
        } else {
            plantingDensityInput.value = '0';
        }
    }
}

// Before form submit, convert formatted numbers back to raw values
document.addEventListener('DOMContentLoaded', function() {
    // Calculate density on page load
    <?php foreach ($categories as $category): ?>
    calculatePlantingDensity(<?= $category['id'] ?>);
    <?php endforeach; ?>
    
    // Handle form submission
    const form = document.querySelector('form[method="POST"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Convert all tree count inputs back to raw numbers
            document.querySelectorAll('.tree-count-input').forEach(function(input) {
                const rawValue = input.dataset.rawValue || input.value.replace(/[^\d]/g, '');
                input.value = rawValue;
            });
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
