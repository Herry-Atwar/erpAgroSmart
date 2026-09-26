<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submission
if (is_post()) {
    try {
        $stmt = $db->prepare("
            INSERT INTO nursery_stocks (
                business_unit_id, plant_variety_id, batch_number, seed_source,
                germination_date, quantity_seeds, quantity_sprouts, quantity_polybag,
                quantity_ready, status, notes, created_at, updated_at
            )
            VALUES (
                :business_unit_id, :variety_id, :batch_number, :seed_source,
                :germination_date, :quantity_seeds, :quantity_sprouts, :quantity_polybag,
                :quantity_ready, :status, :notes, NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            ':business_unit_id' => post('business_unit_id'),
            ':variety_id' => post('variety_id'),
            ':batch_number' => post('batch_number'),
            ':seed_source' => post('seed_source'),
            ':germination_date' => post('germination_date'),
            ':quantity_seeds' => post('quantity_seeds', 0),
            ':quantity_sprouts' => post('quantity_sprouts', 0),
            ':quantity_polybag' => post('quantity_polybag', 0),
            ':quantity_ready' => post('quantity_ready', 0),
            ':status' => post('status', 'Germination'),
            ':notes' => post('notes')
        ]);
        
        set_message('success', 'Nursery stock added successfully!');
        redirect('nursery_stocks.php');
    } catch (PDOException $e) {
        set_message('error', 'Error adding nursery stock: ' . $e->getMessage());
    }
}

$page_title = "Add Nursery Stock";
require_once 'includes/header.php';

// Include inventory CSS with nursery theme (green)
echo '<link rel="stylesheet" href="css/inventory.css">';
echo '<style>
.page-header {
    background: linear-gradient(135deg, #689f38 0%, #7cb342 100%) !important;
}
.card-header {
    background: linear-gradient(135deg, #689f38 0%, #7cb342 100%) !important;
}
.btn-primary {
    background-color: #689f38 !important;
    border-color: #689f38 !important;
}
.btn-primary:hover {
    background-color: #558b2f !important;
    border-color: #558b2f !important;
}
</style>';

// Get nurseries
$nurseries = $db->query("
    SELECT bu.business_unit_id, bu.unit_code, bu.unit_name, c.company_name 
    FROM business_units bu
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE bu.unit_type = 'Nursery' AND bu.status = 'Active'
    ORDER BY c.company_name, bu.unit_name
")->fetchAll();

// Get plant varieties
$varieties = $db->query("
    SELECT variety_id, variety_code, variety_name, plant_type 
    FROM plant_varieties 
    WHERE status = 'Active' 
    ORDER BY plant_type, variety_name
")->fetchAll();
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-plus-circle"></i> Add Nursery Stock</h1>
            <p class="text-muted">Create new nursery stock batch</p>
        </div>
        <div class="col-auto">
            <a href="nursery_stocks.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-box-seam"></i> Stock Information
            </div>
            <div class="card-body">
                <form method="POST" action="nursery_stock_create.php">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nursery <span class="text-danger">*</span></label>
                            <select class="form-select" name="business_unit_id" required>
                                <option value="">Select Nursery</option>
                                <?php foreach ($nurseries as $nursery): ?>
                                    <option value="<?php echo $nursery['business_unit_id']; ?>">
                                        <?php echo htmlspecialchars($nursery['company_name'] . ' - ' . $nursery['unit_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select the nursery location</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Plant Variety <span class="text-danger">*</span></label>
                            <select class="form-select" name="variety_id" required>
                                <option value="">Select Variety</option>
                                <?php 
                                $current_type = '';
                                foreach ($varieties as $variety): 
                                    if ($current_type != $variety['plant_type']) {
                                        if ($current_type != '') echo '</optgroup>';
                                        echo '<optgroup label="' . htmlspecialchars($variety['plant_type']) . '">';
                                        $current_type = $variety['plant_type'];
                                    }
                                ?>
                                    <option value="<?php echo $variety['variety_id']; ?>">
                                        <?php echo htmlspecialchars($variety['variety_name']); ?>
                                    </option>
                                <?php 
                                endforeach; 
                                if ($current_type != '') echo '</optgroup>';
                                ?>
                            </select>
                            <small class="text-muted">Select plant variety</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Batch Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="batch_number" required 
                                   placeholder="e.g., BATCH-2024-001">
                            <small class="text-muted">Unique identifier for this batch</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Germination Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="germination_date" required
                                   value="<?php echo date('Y-m-d'); ?>">
                            <small class="text-muted">Date when seeds were planted</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Seed Source</label>
                        <input type="text" class="form-control" name="seed_source"
                               placeholder="e.g., PPKS, Local Supplier, Internal">
                        <small class="text-muted">Origin of the seeds</small>
                    </div>
                    
                    <hr class="my-4">
                    <h5 class="mb-3"><i class="bi bi-bar-chart"></i> Quantity Information</h5>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity Seeds</label>
                            <input type="number" class="form-control" name="quantity_seeds" 
                                   value="0" min="0" step="1">
                            <small class="text-muted">Number of seeds planted</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity Sprouts</label>
                            <input type="number" class="form-control" name="quantity_sprouts" 
                                   value="0" min="0" step="1">
                            <small class="text-muted">Number of sprouted seeds</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity in Polybag</label>
                            <input type="number" class="form-control" name="quantity_polybag" 
                                   value="0" min="0" step="1">
                            <small class="text-muted">Number of seedlings in polybags</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity Ready</label>
                            <input type="number" class="form-control" name="quantity_ready" 
                                   value="0" min="0" step="1">
                            <small class="text-muted">Number ready for distribution</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select class="form-select" name="status" required>
                            <option value="Germination" selected>Germination</option>
                            <option value="Sprouting">Sprouting</option>
                            <option value="Polybag">Polybag</option>
                            <option value="Ready">Ready</option>
                            <option value="Distributed">Distributed</option>
                        </select>
                        <small class="text-muted">Current stage of the batch</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3" 
                                  placeholder="Additional information about this batch..."></textarea>
                    </div>
                    
                    <hr class="my-4">
                    
                    <div class="d-flex justify-content-between">
                        <a href="nursery_stocks.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Save Stock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob