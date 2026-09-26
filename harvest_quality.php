<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO harvest_quality_control 
                (harvest_id, inspection_date, inspector_name, quality_grade, ripeness_level,
                 defect_percentage, defect_types, oil_content_percentage, moisture_content_percentage,
                 foreign_matter_percentage, passed, rejection_reason, corrective_action, notes, created_by)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('harvest_id'),
                post('inspection_date'),
                post('inspector_name') ?: null,
                post('quality_grade'),
                post('ripeness_level'),
                post('defect_percentage') ?: 0,
                post('defect_types') ?: null,
                post('oil_content_percentage') ?: null,
                post('moisture_content_percentage') ?: null,
                post('foreign_matter_percentage') ?: null,
                post('passed') ? 1 : 0,
                post('rejection_reason') ?: null,
                post('corrective_action') ?: null,
                post('notes') ?: null,
                'admin'
            ]);
            
            set_message('success', 'Quality control record added successfully!');
            redirect('harvest_quality.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding quality control record: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE harvest_quality_control 
                SET harvest_id = ?, inspection_date = ?, inspector_name = ?, quality_grade = ?,
                    ripeness_level = ?, defect_percentage = ?, defect_types = ?,
                    oil_content_percentage = ?, moisture_content_percentage = ?,
                    foreign_matter_percentage = ?, passed = ?, rejection_reason = ?,
                    corrective_action = ?, notes = ?
                WHERE quality_id = ?
            ");
            
            $stmt->execute([
                post('harvest_id'),
                post('inspection_date'),
                post('inspector_name') ?: null,
                post('quality_grade'),
                post('ripeness_level'),
                post('defect_percentage') ?: 0,
                post('defect_types') ?: null,
                post('oil_content_percentage') ?: null,
                post('moisture_content_percentage') ?: null,
                post('foreign_matter_percentage') ?: null,
                post('passed') ? 1 : 0,
                post('rejection_reason') ?: null,
                post('corrective_action') ?: null,
                post('notes') ?: null,
                post('quality_id')
            ]);
            
            set_message('success', 'Quality control record updated successfully!');
            redirect('harvest_quality.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating quality control record: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM harvest_quality_control WHERE quality_id = ?");
            $stmt->execute([post('quality_id')]);
            
            set_message('success', 'Quality control record deleted successfully!');
            redirect('harvest_quality.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting quality control record: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_record = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM harvest_quality_control WHERE quality_id = ?");
    $stmt->execute([get('id')]);
    $edit_record = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Harvest Quality Control";
require_once 'includes/header.php';

// Fetch harvest records for dropdown
$harvests_stmt = $db->query("
    SELECT hr.harvest_id, hr.harvest_number, hr.harvest_date, b.block_name
    FROM harvest_realizations hr
    INNER JOIN blocks b ON hr.block_id = b.block_id
    ORDER BY hr.harvest_date DESC, hr.harvest_number DESC
    LIMIT 100
");
$harvests = $harvests_stmt->fetchAll();

// Fetch quality control records with filters
$search = get('search', '');
$grade_filter = get('grade', '');
$passed_filter = get('passed', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');

$sql = "SELECT hqc.*, 
        hr.harvest_number, hr.harvest_date, hr.actual_quantity_kg,
        b.block_name,
        bu.unit_name as estate_name
        FROM harvest_quality_control hqc
        INNER JOIN harvest_realizations hr ON hqc.harvest_id = hr.harvest_id
        INNER JOIN blocks b ON hr.block_id = b.block_id
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (hr.harvest_number LIKE ? OR b.block_name LIKE ? OR hqc.inspector_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($grade_filter) {
    $sql .= " AND hqc.quality_grade = ?";
    $params[] = $grade_filter;
}
if ($passed_filter !== '') {
    $sql .= " AND hqc.passed = ?";
    $params[] = $passed_filter;
}
if ($date_from) {
    $sql .= " AND hqc.inspection_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND hqc.inspection_date <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY hqc.inspection_date DESC, hqc.quality_id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$quality_records = $stmt->fetchAll();

// Calculate summary statistics
$total_inspections = count($quality_records);
$passed_count = count(array_filter($quality_records, function($r) { return $r['passed'] == 1; }));
$failed_count = $total_inspections - $passed_count;
$pass_rate = ($total_inspections > 0) ? ($passed_count / $total_inspections * 100) : 0;

// Calculate average quality metrics
$avg_oil_content = 0;
$avg_moisture = 0;
$avg_defect = 0;
$count_with_oil = 0;
$count_with_moisture = 0;

foreach ($quality_records as $record) {
    if ($record['oil_content_percentage']) {
        $avg_oil_content += $record['oil_content_percentage'];
        $count_with_oil++;
    }
    if ($record['moisture_content_percentage']) {
        $avg_moisture += $record['moisture_content_percentage'];
        $count_with_moisture++;
    }
    $avg_defect += $record['defect_percentage'];
}

$avg_oil_content = ($count_with_oil > 0) ? ($avg_oil_content / $count_with_oil) : 0;
$avg_moisture = ($count_with_moisture > 0) ? ($avg_moisture / $count_with_moisture) : 0;
$avg_defect = ($total_inspections > 0) ? ($avg_defect / $total_inspections) : 0;

// Quality grades and ripeness levels
$quality_grades = ['Premium', 'Grade A', 'Grade B', 'Grade C', 'Reject'];
$ripeness_levels = ['Under Ripe', 'Ripe', 'Over Ripe'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-award"></i> Harvest Quality Control</h1>
            <p class="text-muted">Quality inspection and control records</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add Inspection
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_inspections; ?></h3>
                <p><i class="bi bi-clipboard-check"></i> Total Inspections</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($pass_rate, 1); ?>%</h3>
                <p><i class="bi bi-check-circle"></i> Pass Rate</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($avg_oil_content, 1); ?>%</h3>
                <p><i class="bi bi-droplet"></i> Avg Oil Content</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($avg_defect, 1); ?>%</h3>
                <p><i class="bi bi-exclamation-triangle"></i> Avg Defect</p>
            </div>
        </div>
    </div>
</div>

<!-- Quality Grade Distribution -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bar-chart"></i> Quality Grade Distribution
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <?php 
                    $grade_counts = [];
                    foreach ($quality_records as $r) {
                        $grade = $r['quality_grade'];
                        if (!isset($grade_counts[$grade])) {
                            $grade_counts[$grade] = 0;
                        }
                        $grade_counts[$grade]++;
                    }
                    foreach ($quality_grades as $grade): 
                        $count = isset($grade_counts[$grade]) ? $grade_counts[$grade] : 0;
                        $percentage = ($total_inspections > 0) ? ($count / $total_inspections * 100) : 0;
                    ?>
                    <div class="col-md-2">
                        <h4 class="text-<?php echo $grade == 'Premium' ? 'success' : ($grade == 'Grade A' ? 'primary' : ($grade == 'Reject' ? 'danger' : 'secondary')); ?>">
                            <?php echo $count; ?>
                        </h4>
                        <small><?php echo htmlspecialchars($grade); ?></small><br>
                        <small class="text-muted"><?php echo format_number($percentage, 1); ?>%</small>
                    </div>
                    <?php endforeach; ?>
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
                <input type="text" class="form-control" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="grade">
                    <option value="">All Grades</option>
                    <?php foreach ($quality_grades as $grade): ?>
                        <option value="<?php echo $grade; ?>" <?php echo $grade_filter == $grade ? 'selected' : ''; ?>><?php echo $grade; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="passed">
                    <option value="">All Results</option>
                    <option value="1" <?php echo $passed_filter === '1' ? 'selected' : ''; ?>>Passed</option>
                    <option value="0" <?php echo $passed_filter === '0' ? 'selected' : ''; ?>>Failed</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" placeholder="From" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" placeholder="To" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- Quality Control Records Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Quality Control Records (<?php echo count($quality_records); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Inspection Date</th>
                        <th>Harvest #</th>
                        <th>Block</th>
                        <th>Quality Grade</th>
                        <th>Ripeness</th>
                        <th>Defect %</th>
                        <th>Oil %</th>
                        <th>Moisture %</th>
                        <th>Result</th>
                        <th>Inspector</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($quality_records)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">No quality control records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($quality_records as $record): ?>
                            <tr>
                                <td><?php echo format_date($record['inspection_date']); ?></td>
                                <td><?php echo htmlspecialchars($record['harvest_number']); ?></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($record['estate_name']); ?></small><br>
                                    <?php echo htmlspecialchars($record['block_name']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $record['quality_grade'] == 'Premium' ? 'success' : ($record['quality_grade'] == 'Grade A' ? 'primary' : ($record['quality_grade'] == 'Reject' ? 'danger' : 'secondary')); ?>">
                                        <?php echo htmlspecialchars($record['quality_grade']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $record['ripeness_level'] == 'Ripe' ? 'success' : 'warning'; ?>">
                                        <?php echo htmlspecialchars($record['ripeness_level']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $record['defect_percentage'] < 3 ? 'success' : ($record['defect_percentage'] < 5 ? 'warning' : 'danger'); ?>">
                                        <?php echo format_number($record['defect_percentage'], 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo $record['oil_content_percentage'] ? format_number($record['oil_content_percentage'], 1) . '%' : '-'; ?></td>
                                <td><?php echo $record['moisture_content_percentage'] ? format_number($record['moisture_content_percentage'], 1) . '%' : '-'; ?></td>
                                <td>
                                    <?php if ($record['passed']): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Passed</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($record['inspector_name']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $record['quality_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $record['quality_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="harvest_quality.php" style="display:inline;" onsubmit="return confirmDelete('Delete this record?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="quality_id" value="<?php echo $record['quality_id']; ?>">
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

<?php foreach ($quality_records as $record): ?>
                            <!-- View Details Modal -->
                            <div class="modal fade" id="viewModal<?php echo $record['quality_id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Quality Control Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">Harvest Number:</th>
                                                            <td><?php echo htmlspecialchars($record['harvest_number']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Harvest Date:</th>
                                                            <td><?php echo format_date($record['harvest_date']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Inspection Date:</th>
                                                            <td><?php echo format_date($record['inspection_date']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Block:</th>
                                                            <td><?php echo htmlspecialchars($record['block_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Estate:</th>
                                                            <td><?php echo htmlspecialchars($record['estate_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Inspector:</th>
                                                            <td><?php echo htmlspecialchars($record['inspector_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Quality Grade:</th>
                                                            <td><strong><?php echo htmlspecialchars($record['quality_grade']); ?></strong></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Ripeness Level:</th>
                                                            <td><?php echo htmlspecialchars($record['ripeness_level']); ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">Defect %:</th>
                                                            <td><?php echo format_number($record['defect_percentage'], 2); ?>%</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Defect Types:</th>
                                                            <td><?php echo htmlspecialchars($record['defect_types']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Oil Content %:</th>
                                                            <td><?php echo $record['oil_content_percentage'] ? format_number($record['oil_content_percentage'], 2) . '%' : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Moisture %:</th>
                                                            <td><?php echo $record['moisture_content_percentage'] ? format_number($record['moisture_content_percentage'], 2) . '%' : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Foreign Matter %:</th>
                                                            <td><?php echo $record['foreign_matter_percentage'] ? format_number($record['foreign_matter_percentage'], 2) . '%' : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Result:</th>
                                                            <td>
                                                                <?php if ($record['passed']): ?>
                                                                    <span class="badge bg-success">Passed</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-danger">Failed</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                        <?php if (!$record['passed'] && $record['rejection_reason']): ?>
                                                        <tr>
                                                            <th>Rejection Reason:</th>
                                                            <td><?php echo htmlspecialchars($record['rejection_reason']); ?></td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        <?php if ($record['corrective_action']): ?>
                                                        <tr>
                                                            <th>Corrective Action:</th>
                                                            <td><?php echo htmlspecialchars($record['corrective_action']); ?></td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </table>
                                                </div>
                                            </div>
                                            <?php if ($record['notes']): ?>
                                            <div class="mt-3">
                                                <h6>Notes:</h6>
                                                <p><?php echo nl2br(htmlspecialchars($record['notes'])); ?></p>
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
            <form method="POST" action="harvest_quality.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_record ? 'Edit Quality Control Record' : 'Add Quality Inspection'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_record ? 'edit' : 'add'; ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="quality_id" value="<?php echo $edit_record['quality_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Harvest Record <span class="text-danger">*</span></label>
                            <select class="form-select" name="harvest_id" required>
                                <option value="">Select Harvest</option>
                                <?php foreach ($harvests as $harvest): ?>
                                    <option value="<?php echo $harvest['harvest_id']; ?>" 
                                        <?php echo ($edit_record && $edit_record['harvest_id'] == $harvest['harvest_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($harvest['harvest_number'] . ' - ' . $harvest['block_name'] . ' (' . format_date($harvest['harvest_date']) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Inspection Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="inspection_date" required
                                   value="<?php echo $edit_record ? $edit_record['inspection_date'] : date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Inspector Name</label>
                            <input type="text" class="form-control" name="inspector_name"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['inspector_name']) : ''; ?>"
                                   placeholder="e.g., Quality Inspector 1">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Quality Grade <span class="text-danger">*</span></label>
                            <select class="form-select" name="quality_grade" required>
                                <?php foreach ($quality_grades as $grade): ?>
                                    <option value="<?php echo $grade; ?>" <?php echo ($edit_record && $edit_record['quality_grade'] == $grade) ? 'selected' : ((!$edit_record && $grade == 'Grade A') ? 'selected' : ''); ?>>
                                        <?php echo $grade; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ripeness Level <span class="text-danger">*</span></label>
                            <select class="form-select" name="ripeness_level" required>
                                <?php foreach ($ripeness_levels as $level): ?>
                                    <option value="<?php echo $level; ?>" <?php echo ($edit_record && $edit_record['ripeness_level'] == $level) ? 'selected' : ((!$edit_record && $level == 'Ripe') ? 'selected' : ''); ?>>
                                        <?php echo $level; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Defect Percentage (%)</label>
                            <input type="number" step="0.01" class="form-control" name="defect_percentage"
                                   value="<?php echo $edit_record ? $edit_record['defect_percentage'] : '0'; ?>"
                                   placeholder="e.g., 2.5">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Defect Types</label>
                            <input type="text" class="form-control" name="defect_types"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['defect_types']) : ''; ?>"
                                   placeholder="e.g., Bruised, Damaged, Diseased">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Oil Content (%)</label>
                            <input type="number" step="0.01" class="form-control" name="oil_content_percentage"
                                   value="<?php echo $edit_record ? $edit_record['oil_content_percentage'] : ''; ?>"
                                   placeholder="e.g., 22.5">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Moisture Content (%)</label>
                            <input type="number" step="0.01" class="form-control" name="moisture_content_percentage"
                                   value="<?php echo $edit_record ? $edit_record['moisture_content_percentage'] : ''; ?>"
                                   placeholder="e.g., 18.0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Foreign Matter (%)</label>
                            <input type="number" step="0.01" class="form-control" name="foreign_matter_percentage"
                                   value="<?php echo $edit_record ? $edit_record['foreign_matter_percentage'] : ''; ?>"
                                   placeholder="e.g., 0.5">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="passed" value="1" id="passed_check"
                                       <?php echo ($edit_record && $edit_record['passed']) ? 'checked' : (!$edit_record ? 'checked' : ''); ?>>
                                <label class="form-check-label" for="passed_check">
                                    <strong>Passed Quality Control</strong>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row" id="rejection_fields" style="display: none;">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Rejection Reason</label>
                            <textarea class="form-control" name="rejection_reason" rows="2" placeholder="Reason for rejection..."><?php echo $edit_record ? htmlspecialchars($edit_record['rejection_reason']) : ''; ?></textarea>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Corrective Action</label>
                            <textarea class="form-control" name="corrective_action" rows="2" placeholder="Actions taken or recommended..."><?php echo $edit_record ? htmlspecialchars($edit_record['corrective_action']) : ''; ?></textarea>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes..."><?php echo $edit_record ? htmlspecialchars($edit_record['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_record ? 'Update' : 'Add'; ?> Inspection
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
        toggleRejectionFields();
    });
</script>
<?php endif; ?>

<script>
function confirmDelete(message) {
    return confirm(message);
}

// Toggle rejection reason field based on passed checkbox
function toggleRejectionFields() {
    var passedCheck = document.getElementById('passed_check');
    var rejectionFields = document.getElementById('rejection_fields');
    
    if (passedCheck.checked) {
        rejectionFields.style.display = 'none';
    } else {
        rejectionFields.style.display = 'block';
    }
}

document.getElementById('passed_check').addEventListener('change', toggleRejectionFields);

// Initialize on page load
toggleRejectionFields();
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
