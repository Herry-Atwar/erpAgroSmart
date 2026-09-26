<?php
require_once 'config/database.php';
require_once 'config/standards.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO mill_production
                (batch_id, production_date, cpo_produced_kg, kernel_produced_kg, 
                 fiber_produced_kg, shell_produced_kg, empty_bunches_kg,
                 oil_extraction_rate, kernel_extraction_rate, moisture_content,
                 ffa_percentage, quality_grade, storage_tank, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('batch_id'),
                post('production_date'),
                post('cpo_produced_kg'),
                post('kernel_produced_kg'),
                post('fiber_produced_kg', 0),
                post('shell_produced_kg', 0),
                post('empty_bunches_kg', 0),
                post('oil_extraction_rate'),
                post('kernel_extraction_rate'),
                post('moisture_content'),
                post('ffa_percentage'),
                post('quality_grade'),
                post('storage_tank'),
                post('remarks'),
                'admin'
            ]);
            
            set_message('success', 'Production record added successfully!');
            redirect('mill_production.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding production: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE mill_production
                SET batch_id = ?, production_date = ?, cpo_produced_kg = ?, 
                    kernel_produced_kg = ?, fiber_produced_kg = ?, shell_produced_kg = ?,
                    empty_bunches_kg = ?, oil_extraction_rate = ?, kernel_extraction_rate = ?,
                    moisture_content = ?, ffa_percentage = ?, quality_grade = ?,
                    storage_tank = ?, remarks = ?, updated_by = ?
                WHERE production_id = ?
            ");
            
            $stmt->execute([
                post('batch_id'),
                post('production_date'),
                post('cpo_produced_kg'),
                post('kernel_produced_kg'),
                post('fiber_produced_kg'),
                post('shell_produced_kg'),
                post('empty_bunches_kg'),
                post('oil_extraction_rate'),
                post('kernel_extraction_rate'),
                post('moisture_content'),
                post('ffa_percentage'),
                post('quality_grade'),
                post('storage_tank'),
                post('remarks'),
                'admin',
                post('production_id')
            ]);
            
            set_message('success', 'Production record updated successfully!');
            redirect('mill_production.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating production: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM mill_production WHERE production_id = ?");
            $stmt->execute([post('production_id')]);
            
            set_message('success', 'Production record deleted successfully!');
            redirect('mill_production.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting production: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_record = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM mill_production WHERE production_id = ?");
    $stmt->execute([get('id')]);
    $edit_record = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Mill Production";
require_once 'includes/header.php';

// Fetch completed batches for dropdown
$batches_stmt = $db->query("
    SELECT b.batch_id, b.batch_no, b.processing_date, b.ffb_input_kg,
           m.mill_name, m.mill_code
    FROM mill_processing_batch b
    INNER JOIN mill_master m ON b.mill_id = m.mill_id
    WHERE b.status = 'completed'
    ORDER BY b.processing_date DESC, b.batch_no DESC
");
$batches = $batches_stmt->fetchAll();

// Fetch production records with filters
$search = get('search', '');
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-d'));
$quality_filter = get('quality_grade', '');

$sql = "SELECT p.*,
        p.cpo_quantity_kg as cpo_produced_kg,
        p.kernel_quantity_kg as kernel_produced_kg,
        p.pko_quantity_kg as pko_produced_kg,
        p.shell_quantity_kg as shell_produced_kg,
        p.fiber_quantity_kg as fiber_produced_kg,
        b.batch_no, b.ffb_input_kg, b.processing_date as batch_date,
        m.mill_name, m.mill_code
        FROM mill_production p
        INNER JOIN mill_processing_batch b ON p.batch_id = b.batch_id
        INNER JOIN mill_master m ON b.mill_id = m.mill_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (b.batch_no LIKE ? OR m.mill_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($date_from) {
    $sql .= " AND p.production_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND p.production_date <= ?";
    $params[] = $date_to;
}
if ($quality_filter) {
    $sql .= " AND p.quality_grade = ?";
    $params[] = $quality_filter;
}

$sql .= " ORDER BY p.production_date DESC, p.production_id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$productions = $stmt->fetchAll();

// Calculate summary statistics
$total_records = count($productions);
$total_cpo = array_sum(array_column($productions, 'cpo_produced_kg'));
$total_kernel = array_sum(array_column($productions, 'kernel_produced_kg'));
$total_ffb = array_sum(array_column($productions, 'ffb_input_kg'));
$avg_oer = $total_ffb > 0 ? ($total_cpo / $total_ffb * 100) : 0;
$avg_ker = $total_ffb > 0 ? ($total_kernel / $total_ffb * 100) : 0;

$quality_grades = ['Premium', 'Grade A', 'Grade B', 'Grade C', 'Below Standard'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color: #d6a51e;"><i class="bi bi-droplet-fill" style="color: #d6a51e;"></i> Mill Production</h1>
            <p class="text-muted">Track CPO, kernel, and by-product production</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-mill" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add Production Record
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_records; ?></h3>
                <p><i class="bi bi-list-check"></i> Total Records</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_cpo / 1000, 1); ?> T</h3>
                <p><i class="bi bi-droplet-fill text-warning"></i> Total CPO</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_kernel / 1000, 1); ?> T</h3>
                <p><i class="bi bi-circle-fill text-secondary"></i> Total Kernel</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <?php
                $std_oer    = agro_std_get('oer_target');
                $std_ker    = agro_std_get('ker_target');
                $oer_status = $std_oer ? agro_std_check($avg_oer, $std_oer) : 'secondary';
                $ker_status = $std_ker ? agro_std_check($avg_ker, $std_ker) : 'secondary';
                ?>
                <h3><?php echo format_number($avg_oer, 2); ?>%</h3>
                <p>
                    <i class="bi bi-percent"></i> Avg OER
                    <?php echo render_std_badge($oer_status, 'OER', $std_oer['display'] ?? '≥22%', $std_oer['source'] ?? 'GAPKI/SNI 7182:2015'); ?>
                </p>
            </div>
        </div>
    </div>
    <div class="col-md-3" style="margin-top:-1rem;">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($avg_ker, 2); ?>%</h3>
                <p>
                    <i class="bi bi-circle-fill text-secondary"></i> Avg KER
                    <?php echo render_std_badge($ker_status, 'KER', $std_ker['display'] ?? '≥4.5%', $std_ker['source'] ?? 'GAPKI/PPKS Medan'); ?>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Extraction Rates -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header text-white" style="background-color: #d6a51e;">
                <i class="bi bi-bar-chart"></i> Extraction Rates & Quality
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h4 class="text-warning"><?php echo format_number($avg_oer, 2); ?>%</h4>
                        <small>Oil Extraction Rate (OER)</small>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-secondary"><?php echo format_number($avg_ker, 2); ?>%</h4>
                        <small>Kernel Extraction Rate (KER)</small>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-info"><?php echo format_number($total_ffb / 1000, 1); ?> T</h4>
                        <small>Total FFB Processed</small>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-success"><?php echo count(array_filter($productions, function($p) { return $p['quality_grade'] == 'Premium' || $p['quality_grade'] == 'Grade A'; })); ?></h4>
                        <small>Premium/Grade A Records</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search batch or mill..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="quality_grade">
                    <option value="">All Quality</option>
                    <?php foreach ($quality_grades as $grade): ?>
                        <option value="<?php echo $grade; ?>" <?php echo $quality_filter == $grade ? 'selected' : ''; ?>>
                            <?php echo $grade; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-mill"><i class="bi bi-search"></i> Search</button>
                <a href="mill_production.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Production Records Table -->
<div class="card">
    <div class="card-header text-white" style="background-color: #d6a51e;">
        <i class="bi bi-list-ul"></i> Production Records (<?php echo count($productions); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Batch #</th>
                        <th>Mill</th>
                        <th>FFB Input</th>
                        <th>CPO (Kg)</th>
                        <th>Kernel (Kg)</th>
                        <th>OER %</th>
                        <th>KER %</th>
                        <th>Quality</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productions)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No production records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productions as $prod): ?>
                            <?php
                            $oer = $prod['ffb_input_kg'] > 0 ? ($prod['cpo_produced_kg'] / $prod['ffb_input_kg'] * 100) : 0;
                            $ker = $prod['ffb_input_kg'] > 0 ? ($prod['kernel_produced_kg'] / $prod['ffb_input_kg'] * 100) : 0;
                            ?>
                            <tr>
                                <td><?php echo format_date($prod['production_date']); ?></td>
                                <td><strong><?php echo htmlspecialchars($prod['batch_no']); ?></strong></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($prod['mill_code']); ?></small><br>
                                    <?php echo htmlspecialchars($prod['mill_name']); ?>
                                </td>
                                <td><?php echo format_number($prod['ffb_input_kg'], 0); ?></td>
                                <td class="text-warning"><strong><?php echo format_number($prod['cpo_produced_kg'], 0); ?></strong></td>
                                <td class="text-secondary"><strong><?php echo format_number($prod['kernel_produced_kg'], 0); ?></strong></td>
                                <td>
                                    <?php
                                    $row_oer_std = agro_std_get('oer_target');
                                    $row_ker_std = agro_std_get('ker_target');
                                    $row_oer_st  = $row_oer_std ? agro_std_check($oer, $row_oer_std) : 'secondary';
                                    $row_ker_st  = $row_ker_std ? agro_std_check($ker, $row_ker_std) : 'secondary';
                                    echo render_std_badge($row_oer_st, 'OER', format_number($oer, 2).'%', $row_oer_std['source'] ?? 'GAPKI/SNI');
                                    ?>
                                </td>
                                <td>
                                    <?php echo render_std_badge($row_ker_st, 'KER', format_number($ker, 2).'%', $row_ker_std['source'] ?? 'GAPKI/PPKS'); ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo $prod['quality_grade'] == 'Premium' ? 'success' :
                                            ($prod['quality_grade'] == 'Grade A' ? 'primary' :
                                            ($prod['quality_grade'] == 'Grade B' ? 'info' : 'warning'));
                                    ?>">
                                        <?php echo $prod['quality_grade']; ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $prod['production_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $prod['production_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="production_id" value="<?php echo $prod['production_id']; ?>">
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
    </div>
</div>

<?php foreach ($productions as $prod): ?>
                            <?php
                            $oer = $prod['ffb_input_kg'] > 0 ? ($prod['cpo_produced_kg'] / $prod['ffb_input_kg'] * 100) : 0;
                            $ker = $prod['ffb_input_kg'] > 0 ? ($prod['kernel_produced_kg'] / $prod['ffb_input_kg'] * 100) : 0;
                            ?>
                            <!-- View Details Modal -->
                            <div class="modal fade" id="viewModal<?php echo $prod['production_id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header bg-primary text-white">
                                            <h5 class="modal-title">Production Details</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <h6 class="border-bottom pb-2">Batch Information</h6>
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="50%">Batch Number:</th>
                                                            <td><strong><?php echo htmlspecialchars($prod['batch_no']); ?></strong></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Mill:</th>
                                                            <td><?php echo htmlspecialchars($prod['mill_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Production Date:</th>
                                                            <td><?php echo format_date($prod['production_date']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>FFB Input:</th>
                                                            <td><?php echo format_number($prod['ffb_input_kg'], 0); ?> Kg</td>
                                                        </tr>
                                                    </table>
                                                    
                                                    <h6 class="border-bottom pb-2 mt-3">Main Products</h6>
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="50%">CPO Produced:</th>
                                                            <td class="text-warning"><strong><?php echo format_number($prod['cpo_produced_kg'], 0); ?> Kg</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Kernel Produced:</th>
                                                            <td class="text-secondary"><strong><?php echo format_number($prod['kernel_produced_kg'], 0); ?> Kg</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <th>OER:</th>
                                                            <td><?php echo format_number($oer, 2); ?>%</td>
                                                        </tr>
                                                        <tr>
                                                            <th>KER:</th>
                                                            <td><?php echo format_number($ker, 2); ?>%</td>
                                                        </tr>
                                                    </table>
                                                </div>
                                                <div class="col-md-6">
                                                    <h6 class="border-bottom pb-2">By-Products</h6>
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="50%">Fiber:</th>
                                                            <td><?php echo format_number($prod['fiber_produced_kg'], 0); ?> Kg</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Shell:</th>
                                                            <td><?php echo format_number($prod['shell_produced_kg'], 0); ?> Kg</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Empty Bunches:</th>
                                                            <td><?php echo format_number($prod['empty_bunches_kg'] ?? 0, 0); ?> Kg</td>
                                                        </tr>
                                                    </table>
                                                    
                                                    <h6 class="border-bottom pb-2 mt-3">Quality Parameters</h6>
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="50%">Quality Grade:</th>
                                                            <td><span class="badge bg-success"><?php echo $prod['quality_grade']; ?></span></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Moisture Content:</th>
                                                            <td><?php echo format_number($prod['moisture_content'] ?? 0, 2); ?>%</td>
                                                        </tr>
                                                        <tr>
                                                            <th>FFA:</th>
                                                            <td><?php echo format_number($prod['ffa_percentage'] ?? 0, 2); ?>%</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Storage Tank:</th>
                                                            <td><?php echo htmlspecialchars($prod['storage_tank'] ?? $prod['storage_tank_no'] ?? '-'); ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                            <?php if ($prod['remarks']): ?>
                                            <div class="mt-3">
                                                <h6>Remarks:</h6>
                                                <p><?php echo nl2br(htmlspecialchars($prod['remarks'])); ?></p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
<?php endforeach; ?>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="mill_production.php">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <?php echo $edit_record ? 'Edit Production Record' : 'Add Production Record'; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_record ? 'edit' : 'add'; ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="production_id" value="<?php echo $edit_record['production_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Processing Batch <span class="text-danger">*</span></label>
                            <select class="form-select" name="batch_id" required id="batch_select">
                                <option value="">Select Batch</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?php echo $batch['batch_id']; ?>"
                                        data-ffb="<?php echo $batch['ffb_input_kg']; ?>"
                                        <?php echo ($edit_record && $edit_record['batch_id'] == $batch['batch_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($batch['batch_no'] . ' - ' . $batch['mill_name'] . ' (' . format_date($batch['processing_date']) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" id="batch_info"></small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Production Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="production_date" required
                                   value="<?php echo $edit_record ? $edit_record['production_date'] : date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <!-- Main Products -->
                    <h6 class="border-bottom pb-2 mb-3">Main Products</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">CPO Produced (Kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="cpo_produced_kg" required id="cpo_kg"
                                   value="<?php echo $edit_record ? $edit_record['cpo_produced_kg'] : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Kernel Produced (Kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="kernel_produced_kg" required id="kernel_kg"
                                   value="<?php echo $edit_record ? $edit_record['kernel_produced_kg'] : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Oil Extraction Rate (OER %)</label>
                            <input type="number" step="0.01" class="form-control" name="oil_extraction_rate" readonly id="oer_display"
                                   value="<?php echo $edit_record ? $edit_record['oil_extraction_rate'] : ''; ?>"
                                   style="background-color: #e9ecef;">
                            <small class="text-muted">Auto-calculated: (CPO / FFB) × 100</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Kernel Extraction Rate (KER %)</label>
                            <input type="number" step="0.01" class="form-control" name="kernel_extraction_rate" readonly id="ker_display"
                                   value="<?php echo $edit_record ? $edit_record['kernel_extraction_rate'] : ''; ?>"
                                   style="background-color: #e9ecef;">
                            <small class="text-muted">Auto-calculated: (Kernel / FFB) × 100</small>
                        </div>
                    </div>
                    
                    <!-- By-Products -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4">By-Products</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Fiber (Kg)</label>
                            <input type="number" step="0.01" class="form-control" name="fiber_produced_kg"
                                   value="<?php echo $edit_record ? $edit_record['fiber_produced_kg'] : '0'; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Shell (Kg)</label>
                            <input type="number" step="0.01" class="form-control" name="shell_produced_kg"
                                   value="<?php echo $edit_record ? $edit_record['shell_produced_kg'] : '0'; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Empty Bunches (Kg)</label>
                            <input type="number" step="0.01" class="form-control" name="empty_bunches_kg"
                                   value="<?php echo $edit_record ? $edit_record['empty_bunches_kg'] : '0'; ?>">
                        </div>
                    </div>
                    
                    <!-- Quality Parameters -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4">Quality Parameters</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Moisture Content (%)</label>
                            <input type="number" step="0.01" class="form-control" name="moisture_content"
                                   value="<?php echo $edit_record ? $edit_record['moisture_content'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">FFA (%)</label>
                            <input type="number" step="0.01" class="form-control" name="ffa_percentage"
                                   value="<?php echo $edit_record ? $edit_record['ffa_percentage'] : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Quality Grade</label>
                            <select class="form-select" name="quality_grade">
                                <?php foreach ($quality_grades as $grade): ?>
                                    <option value="<?php echo $grade; ?>" <?php echo ($edit_record && $edit_record['quality_grade'] == $grade) ? 'selected' : ''; ?>>
                                        <?php echo $grade; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Storage Tank</label>
                            <input type="text" class="form-control" name="storage_tank"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['storage_tank']) : ''; ?>"
                                   placeholder="e.g., Tank-01">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="2"><?php echo $edit_record ? htmlspecialchars($edit_record['remarks']) : ''; ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_record ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_record): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addModal'));
        editModal.show();
        calculateRates();
    });
</script>
<?php endif; ?>

<script>
// Show FFB info when batch selected
document.getElementById('batch_select').addEventListener('change', function() {
    var selected = this.options[this.selectedIndex];
    var ffb = selected.getAttribute('data-ffb');
    if (ffb) {
        document.getElementById('batch_info').textContent = 'FFB Input: ' + parseFloat(ffb).toLocaleString() + ' Kg';
    } else {
        document.getElementById('batch_info').textContent = '';
    }
    calculateRates();
});

// Calculate extraction rates
function calculateRates() {
    var batchSelect = document.getElementById('batch_select');
    var selected = batchSelect.options[batchSelect.selectedIndex];
    var ffb = parseFloat(selected.getAttribute('data-ffb')) || 0;
    
    var cpo = parseFloat(document.getElementById('cpo_kg').value) || 0;
    var kernel = parseFloat(document.getElementById('kernel_kg').value) || 0;
    
    if (ffb > 0) {
        var oer = (cpo / ffb * 100).toFixed(2);
        var ker = (kernel / ffb * 100).toFixed(2);
        document.getElementById('oer_display').value = oer;
        document.getElementById('ker_display').value = ker;
    } else {
        document.getElementById('oer_display').value = '';
        document.getElementById('ker_display').value = '';
    }
}

document.getElementById('cpo_kg').addEventListener('input', calculateRates);
document.getElementById('kernel_kg').addEventListener('input', calculateRates);
</script>
<style>
/* Custom button styles for Mill Operations */
.btn-mill {
    background-color: #c98600;
    border-color: #c98600;
    color: white;
}

.btn-mill:hover {
    background-color: #b07600;
    border-color: #b07600;
    color: white;
}

.btn-mill:focus,
.btn-mill:active {
    background-color: #976500;
    border-color: #976500;
    color: white;
}

/* Custom stat-card border and number color for Mill Production */
.stat-card {
    border-left: 4px solid #c98600;
}

.stat-card h3 {
    color: #c98600;
}
</style>


<?php require_once 'includes/footer.php'; ?>

// Made with Bob