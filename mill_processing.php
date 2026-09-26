<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            // Auto-generate batch number
            $year = date('Y');
            $month = date('m');
            $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(batch_no, 12) AS UNSIGNED)) as max_num
                               FROM mill_processing_batch WHERE batch_no LIKE 'BATCH-$year$month-%'");
            $result = $stmt->fetch();
            $next_num = ($result['max_num'] ?? 0) + 1;
            $batch_no = sprintf('BATCH-%s%s-%04d', $year, $month, $next_num);
            
            $stmt = $db->prepare("
                INSERT INTO mill_processing_batch
                (batch_no, mill_id, processing_date, shift, start_time, ffb_input_kg,
                 reception_ids, status, shift_supervisor, operators, remarks, created_by)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $batch_no,
                post('mill_id'),
                post('processing_date'),
                post('shift'),
                post('start_time'),
                post('ffb_input_kg'),
                post('reception_ids') ?: null,
                'pending',
                post('shift_supervisor') ?: null,
                post('operators') ?: null,
                post('remarks') ?: null,
                'admin'
            ]);
            
            set_message('success', 'Processing batch created successfully! Batch No: ' . $batch_no);
            redirect('mill_processing.php');
        } catch (PDOException $e) {
            set_message('error', 'Error creating batch: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $stmt = $db->prepare("
                UPDATE mill_processing_batch
                SET mill_id = ?, processing_date = ?, shift = ?, start_time = ?,
                    end_time = ?, ffb_input_kg = ?, reception_ids = ?, status = ?,
                    shift_supervisor = ?, operators = ?, remarks = ?
                WHERE batch_id = ?
            ");
            
            $stmt->execute([
                post('mill_id'),
                post('processing_date'),
                post('shift'),
                post('start_time'),
                post('end_time') ?: null,
                post('ffb_input_kg'),
                post('reception_ids') ?: null,
                post('status'),
                post('shift_supervisor') ?: null,
                post('operators') ?: null,
                post('remarks') ?: null,
                post('batch_id')
            ]);
            
            set_message('success', 'Processing batch updated successfully!');
            redirect('mill_processing.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating batch: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM mill_processing_batch WHERE batch_id = ?");
            $stmt->execute([post('batch_id')]);
            
            set_message('success', 'Processing batch deleted successfully!');
            redirect('mill_processing.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting batch: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_record = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM mill_processing_batch WHERE batch_id = ?");
    $stmt->execute([get('id')]);
    $edit_record = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Mill Processing";
require_once 'includes/header.php';

// Fetch mills for dropdown
$mills_stmt = $db->query("
    SELECT mill_id, mill_code, mill_name, capacity_tph
    FROM mill_master
    WHERE status = 'active'
    ORDER BY mill_name
");
$mills = $mills_stmt->fetchAll();

// Fetch processing batches with filters
$search = get('search', '');
$mill_filter = get('mill_id', '');
$status_filter = get('status', '');
$date_from = get('date_from', date('Y-m-01'));
$date_to = get('date_to', date('Y-m-d'));

$sql = "SELECT b.*,
        m.mill_code, m.mill_name, m.capacity_tph,
        EXTRACT(EPOCH FROM (b.end_time - b.start_time))/3600 as duration_hours
        FROM mill_processing_batch b
        INNER JOIN mill_master m ON b.mill_id = m.mill_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (b.batch_no LIKE ? OR m.mill_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($mill_filter) {
    $sql .= " AND b.mill_id = ?";
    $params[] = $mill_filter;
}
if ($status_filter) {
    $sql .= " AND b.status = ?";
    $params[] = $status_filter;
}
if ($date_from) {
    $sql .= " AND b.processing_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND b.processing_date <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY b.processing_date DESC, b.batch_no DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll();

// Calculate summary statistics
$total_batches = count($batches);
$total_ffb = array_sum(array_column($batches, 'ffb_input_kg'));
$completed_batches = count(array_filter($batches, function($b) { return $b['status'] == 'completed'; }));
$pending_batches = count(array_filter($batches, function($b) { return $b['status'] == 'pending'; }));

// Status options
$shifts = ['shift_1' => 'Shift 1', 'shift_2' => 'Shift 2', 'shift_3' => 'Shift 3'];
$statuses = ['pending', 'sterilizing', 'stripping', 'digesting', 'pressing', 'clarifying', 'completed', 'cancelled'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color: #d6a51e;"><i class="bi bi-gear-wide-connected" style="color: #d6a51e;"></i> Mill Processing</h1>
            <p class="text-muted">Manage mill processing batches and operations</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-mill" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> New Processing Batch
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_batches; ?></h3>
                <p><i class="bi bi-list-check"></i> Total Batches</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_ffb, 0); ?> Kg</h3>
                <p><i class="bi bi-box-seam"></i> Total FFB Processed</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $completed_batches; ?></h3>
                <p><i class="bi bi-check-circle"></i> Completed</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $pending_batches; ?></h3>
                <p><i class="bi bi-clock-history"></i> In Progress</p>
            </div>
        </div>
    </div>
</div>

<!-- Status Breakdown -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header text-white" style="background-color: #d6a51e;">
                <i class="bi bi-bar-chart"></i> Batches by Status
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <?php
                    $status_counts = [];
                    foreach ($batches as $b) {
                        $status = $b['status'];
                        if (!isset($status_counts[$status])) {
                            $status_counts[$status] = ['count' => 0, 'ffb' => 0];
                        }
                        $status_counts[$status]['count']++;
                        $status_counts[$status]['ffb'] += $b['ffb_input_kg'];
                    }
                    foreach ($status_counts as $status => $data):
                    ?>
                    <div class="col-md-2">
                        <h4 class="text-success"><?php echo $data['count']; ?></h4>
                        <small><?php echo ucfirst($status); ?></small><br>
                        <small class="text-muted"><?php echo format_number($data['ffb'], 0); ?> Kg</small>
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
                <input type="text" class="form-control" name="search" placeholder="Search batch number or mill..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="mill_id">
                    <option value="">All Mills</option>
                    <?php foreach ($mills as $mill): ?>
                        <option value="<?php echo $mill['mill_id']; ?>" <?php echo $mill_filter == $mill['mill_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($mill['mill_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $status_filter == $status ? 'selected' : ''; ?>>
                            <?php echo ucfirst($status); ?>
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
            <div class="col-md-1">
                <button type="submit" class="btn btn-mill w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- Processing Batches Table -->
<div class="card">
    <div class="card-header text-white" style="background-color: #d6a51e;">
        <i class="bi bi-list-ul"></i> Processing Batches (<?php echo count($batches); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Batch #</th>
                        <th>Date</th>
                        <th>Mill</th>
                        <th>Shift</th>
                        <th>FFB Input (Kg)</th>
                        <th>Start Time</th>
                        <th>Duration</th>
                        <th>Supervisor</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($batches)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No processing batches found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($batches as $batch): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($batch['batch_no']); ?></strong></td>
                                <td><?php echo format_date($batch['processing_date']); ?></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($batch['mill_code']); ?></small><br>
                                    <?php echo htmlspecialchars($batch['mill_name']); ?>
                                </td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $batch['shift'])); ?></td>
                                <td><?php echo format_number($batch['ffb_input_kg'], 0); ?></td>
                                <td><?php echo date('H:i', strtotime($batch['start_time'])); ?></td>
                                <td>
                                    <?php if ($batch['duration_hours']): ?>
                                        <?php echo format_number($batch['duration_hours'], 1); ?> hrs
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($batch['shift_supervisor'] ?: '-'); ?></td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo $batch['status'] == 'completed' ? 'success' :
                                            ($batch['status'] == 'pending' ? 'warning' :
                                            ($batch['status'] == 'cancelled' ? 'danger' : 'info'));
                                    ?>">
                                        <?php echo ucfirst($batch['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $batch['batch_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $batch['batch_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="mill_processing.php" style="display:inline;" onsubmit="return confirmDelete('Delete this batch?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="batch_id" value="<?php echo $batch['batch_id']; ?>">
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

<?php foreach ($batches as $batch): ?>
                            <!-- View Details Modal -->
                            <div class="modal fade" id="viewModal<?php echo $batch['batch_id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header bg-primary text-white">
                                            <h5 class="modal-title">Batch Details</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">Batch Number:</th>
                                                            <td><strong><?php echo htmlspecialchars($batch['batch_no']); ?></strong></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Mill:</th>
                                                            <td><?php echo htmlspecialchars($batch['mill_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Mill Code:</th>
                                                            <td><?php echo htmlspecialchars($batch['mill_code']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Processing Date:</th>
                                                            <td><?php echo format_date($batch['processing_date']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Shift:</th>
                                                            <td><?php echo ucfirst(str_replace('_', ' ', $batch['shift'])); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Start Time:</th>
                                                            <td><?php echo date('H:i', strtotime($batch['start_time'])); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>End Time:</th>
                                                            <td><?php echo $batch['end_time'] ? date('H:i', strtotime($batch['end_time'])) : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Duration:</th>
                                                            <td><?php echo $batch['duration_hours'] ? format_number($batch['duration_hours'], 1) . ' hours' : '-'; ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">FFB Input:</th>
                                                            <td><?php echo format_number($batch['ffb_input_kg'], 0); ?> Kg</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Reception IDs:</th>
                                                            <td><?php echo $batch['reception_ids'] ? htmlspecialchars($batch['reception_ids']) : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Shift Supervisor:</th>
                                                            <td><?php echo $batch['shift_supervisor'] ? htmlspecialchars($batch['shift_supervisor']) : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Operators:</th>
                                                            <td><?php echo $batch['operators'] ? htmlspecialchars($batch['operators']) : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Status:</th>
                                                            <td>
                                                                <span class="badge bg-<?php
                                                                    echo $batch['status'] == 'completed' ? 'success' :
                                                                        ($batch['status'] == 'pending' ? 'warning' :
                                                                        ($batch['status'] == 'cancelled' ? 'danger' : 'info'));
                                                                ?>">
                                                                    <?php echo ucfirst($batch['status']); ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th>Created:</th>
                                                            <td><?php echo format_date($batch['created_at']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Created By:</th>
                                                            <td><?php echo htmlspecialchars($batch['created_by']); ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                            <?php if ($batch['remarks']): ?>
                                            <div class="mt-3">
                                                <h6>Remarks:</h6>
                                                <p><?php echo nl2br(htmlspecialchars($batch['remarks'])); ?></p>
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
            <form method="POST" action="mill_processing.php">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <?php echo $edit_record ? 'Edit Processing Batch' : 'New Processing Batch'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_record ? 'edit' : 'add'; ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="batch_id" value="<?php echo $edit_record['batch_id']; ?>">
                        <div class="alert alert-info">
                            <strong>Batch Number:</strong> <?php echo htmlspecialchars($edit_record['batch_no']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Mill <span class="text-danger">*</span></label>
                            <select class="form-select" name="mill_id" required>
                                <option value="">Select Mill</option>
                                <?php foreach ($mills as $mill): ?>
                                    <option value="<?php echo $mill['mill_id']; ?>"
                                        <?php echo ($edit_record && $edit_record['mill_id'] == $mill['mill_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($mill['mill_name'] . ' (' . $mill['mill_code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Processing Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="processing_date" required
                                   value="<?php echo $edit_record ? $edit_record['processing_date'] : date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Shift <span class="text-danger">*</span></label>
                            <select class="form-select" name="shift" required>
                                <option value="">Select Shift</option>
                                <?php foreach ($shifts as $shift_val => $shift_label): ?>
                                    <option value="<?php echo $shift_val; ?>"
                                        <?php echo ($edit_record && $edit_record['shift'] == $shift_val) ? 'selected' : ''; ?>>
                                        <?php echo $shift_label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Start Time <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="start_time" required
                                   value="<?php echo $edit_record ? date('Y-m-d\TH:i', strtotime($edit_record['start_time'])) : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">End Time</label>
                            <input type="datetime-local" class="form-control" name="end_time"
                                   value="<?php echo ($edit_record && $edit_record['end_time']) ? date('Y-m-d\TH:i', strtotime($edit_record['end_time'])) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">FFB Input (Kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="ffb_input_kg" required
                                   value="<?php echo $edit_record ? $edit_record['ffb_input_kg'] : ''; ?>"
                                   placeholder="e.g., 50000">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reception IDs (Optional)</label>
                            <input type="text" class="form-control" name="reception_ids"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['reception_ids']) : ''; ?>"
                                   placeholder="e.g., RCP-001, RCP-002">
                            <small class="text-muted">Comma-separated reception IDs</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Shift Supervisor</label>
                            <input type="text" class="form-control" name="shift_supervisor"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['shift_supervisor']) : ''; ?>"
                                   placeholder="Supervisor name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Operators</label>
                            <input type="text" class="form-control" name="operators"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['operators']) : ''; ?>"
                                   placeholder="Comma-separated operator names">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo ($edit_record && $edit_record['status'] == $status) ? 'selected' : ((!$edit_record && $status == 'pending') ? 'selected' : ''); ?>>
                                        <?php echo ucfirst($status); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="3" placeholder="Additional remarks..."><?php echo $edit_record ? htmlspecialchars($edit_record['remarks']) : ''; ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_record ? 'Update Batch' : 'Create Batch'; ?>
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
        calculateAvgWeight();
    });
</script>
<?php endif; ?>

<script>
function confirmDelete(message) {
    return confirm(message);
}

// Show block info when selected
document.getElementById('block_select').addEventListener('change', function() {
    var selected = this.options[this.selectedIndex];
    var plants = selected.getAttribute('data-plants');
    var area = selected.getAttribute('data-area');
    if (plants && area) {
        document.getElementById('block_info').textContent = 'Area: ' + area + ' Ha, Plants: ' + plants;
    } else {
        document.getElementById('block_info').textContent = '';
    }
});

// Calculate average bunch weight
function calculateAvgWeight() {
    var qty = parseFloat(document.getElementById('actual_qty').value) || 0;
    var bunches = parseFloat(document.getElementById('actual_bunches').value) || 0;
    
    if (qty > 0 && bunches > 0) {
        var avg = qty / bunches;
        document.getElementById('avg_weight').value = avg.toFixed(2) + ' Kg';
    } else {
        document.getElementById('avg_weight').value = '';
    }
}

document.getElementById('actual_qty').addEventListener('input', calculateAvgWeight);
document.getElementById('actual_bunches').addEventListener('input', calculateAvgWeight);

// Trigger calculation on page load if editing
<?php if ($edit_record && $edit_record['actual_quantity_kg'] && $edit_record['actual_bunches']): ?>
calculateAvgWeight();
<?php endif; ?>
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

/* Custom stat-card border and number color for Mill Processing */
.stat-card {
    border-left: 4px solid #c98600;
}

.stat-card h3 {
    color: #c98600;
}
</style>


<?php require_once 'includes/footer.php'; ?>

// Made with Bob
