<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            // Calculate productivity rate
            $quantity = post('quantity_kg');
            $hours = post('working_hours');
            $productivity_rate = ($hours > 0) ? ($quantity / $hours) : 0;
            
            $stmt = $db->prepare("
                INSERT INTO harvest_productivity 
                (harvest_id, harvester_name, harvest_date, bunches_harvested, quantity_kg,
                 loose_fruits_kg, working_hours, productivity_rate, payment_amount,
                 payment_type, notes, created_by)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                post('harvest_id'),
                post('harvester_name'),
                post('harvest_date'),
                post('bunches_harvested'),
                $quantity,
                post('loose_fruits_kg') ?: 0,
                $hours,
                $productivity_rate,
                post('payment_amount') ?: null,
                post('payment_type') ?: 'Per Bunch',
                post('notes') ?: null,
                'admin'
            ]);
            
            set_message('success', 'Harvester productivity record added successfully!');
            redirect('harvest_productivity.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding productivity record: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            // Recalculate productivity rate
            $quantity = post('quantity_kg');
            $hours = post('working_hours');
            $productivity_rate = ($hours > 0) ? ($quantity / $hours) : 0;
            
            $stmt = $db->prepare("
                UPDATE harvest_productivity 
                SET harvest_id = ?, harvester_name = ?, harvest_date = ?, bunches_harvested = ?,
                    quantity_kg = ?, loose_fruits_kg = ?, working_hours = ?, productivity_rate = ?,
                    payment_amount = ?, payment_type = ?, notes = ?
                WHERE productivity_id = ?
            ");
            
            $stmt->execute([
                post('harvest_id'),
                post('harvester_name'),
                post('harvest_date'),
                post('bunches_harvested'),
                $quantity,
                post('loose_fruits_kg') ?: 0,
                $hours,
                $productivity_rate,
                post('payment_amount') ?: null,
                post('payment_type'),
                post('notes') ?: null,
                post('productivity_id')
            ]);
            
            set_message('success', 'Harvester productivity record updated successfully!');
            redirect('harvest_productivity.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating productivity record: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM harvest_productivity WHERE productivity_id = ?");
            $stmt->execute([post('productivity_id')]);
            
            set_message('success', 'Harvester productivity record deleted successfully!');
            redirect('harvest_productivity.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting productivity record: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_record = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM harvest_productivity WHERE productivity_id = ?");
    $stmt->execute([get('id')]);
    $edit_record = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Harvester Productivity";
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

// Fetch productivity records with filters
$search = get('search', '');
$harvester_filter = get('harvester', '');
$payment_type_filter = get('payment_type', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');

$sql = "SELECT hp.*, 
        hr.harvest_number, hr.harvest_date,
        b.block_name,
        bu.unit_name as estate_name
        FROM harvest_productivity hp
        INNER JOIN harvest_realizations hr ON hp.harvest_id = hr.harvest_id
        INNER JOIN blocks b ON hr.block_id = b.block_id
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (hp.harvester_name LIKE ? OR hr.harvest_number LIKE ? OR b.block_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($harvester_filter) {
    $sql .= " AND hp.harvester_name LIKE ?";
    $params[] = "%$harvester_filter%";
}
if ($payment_type_filter) {
    $sql .= " AND hp.payment_type = ?";
    $params[] = $payment_type_filter;
}
if ($date_from) {
    $sql .= " AND hp.harvest_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND hp.harvest_date <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY hp.harvest_date DESC, hp.productivity_id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$productivity_records = $stmt->fetchAll();

// Calculate summary statistics
$total_records = count($productivity_records);
$total_bunches = array_sum(array_column($productivity_records, 'bunches_harvested'));
$total_quantity = array_sum(array_column($productivity_records, 'quantity_kg'));
$total_hours = array_sum(array_column($productivity_records, 'working_hours'));
$total_payment = array_sum(array_column($productivity_records, 'payment_amount'));
$avg_productivity = ($total_hours > 0) ? ($total_quantity / $total_hours) : 0;

// Get top performers
$top_performers_stmt = $db->query("
    SELECT harvester_name, 
           SUM(bunches_harvested) as total_bunches,
           SUM(quantity_kg) as total_kg,
           AVG(productivity_rate) as avg_rate,
           COUNT(*) as work_days
    FROM harvest_productivity
    GROUP BY harvester_name
    ORDER BY avg_rate DESC
    LIMIT 10
");
$top_performers = $top_performers_stmt->fetchAll();

// Payment types
$payment_types = ['Per Bunch', 'Per Kg', 'Daily Rate'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-person-badge"></i> Harvester Productivity</h1>
            <p class="text-muted">Track individual harvester performance and payments</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add Productivity Record
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
                <h3><?php echo format_number($total_bunches, 0); ?></h3>
                <p><i class="bi bi-basket"></i> Total Bunches</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($avg_productivity, 1); ?> Kg/Hr</h3>
                <p><i class="bi bi-speedometer"></i> Avg Productivity</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3>Rp <?php echo format_number($total_payment, 0); ?></h3>
                <p><i class="bi bi-cash"></i> Total Payment</p>
            </div>
        </div>
    </div>
</div>

<!-- Top Performers -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-trophy"></i> Top 10 Performers (by Productivity Rate)
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Harvester Name</th>
                                <th>Total Bunches</th>
                                <th>Total Kg</th>
                                <th>Avg Rate (Kg/Hr)</th>
                                <th>Work Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            foreach ($top_performers as $performer): 
                            ?>
                                <tr>
                                    <td>
                                        <?php if ($rank == 1): ?>
                                            <span class="badge bg-warning">🥇 <?php echo $rank; ?></span>
                                        <?php elseif ($rank == 2): ?>
                                            <span class="badge bg-secondary">🥈 <?php echo $rank; ?></span>
                                        <?php elseif ($rank == 3): ?>
                                            <span class="badge bg-danger">🥉 <?php echo $rank; ?></span>
                                        <?php else: ?>
                                            <?php echo $rank; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($performer['harvester_name']); ?></strong></td>
                                    <td><?php echo format_number($performer['total_bunches'], 0); ?></td>
                                    <td><?php echo format_number($performer['total_kg'], 0); ?></td>
                                    <td><span class="badge bg-success"><?php echo format_number($performer['avg_rate'], 2); ?></span></td>
                                    <td><?php echo $performer['work_days']; ?></td>
                                </tr>
                            <?php 
                            $rank++;
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
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
                <input type="text" class="form-control" name="harvester" placeholder="Harvester name..." value="<?php echo htmlspecialchars($harvester_filter); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="payment_type">
                    <option value="">All Payment Types</option>
                    <?php foreach ($payment_types as $type): ?>
                        <option value="<?php echo $type; ?>" <?php echo $payment_type_filter == $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                    <?php endforeach; ?>
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

<!-- Productivity Records Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Productivity Records (<?php echo count($productivity_records); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Harvest #</th>
                        <th>Harvester Name</th>
                        <th>Bunches</th>
                        <th>Quantity (Kg)</th>
                        <th>Hours</th>
                        <th>Rate (Kg/Hr)</th>
                        <th>Payment</th>
                        <th>Type</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productivity_records)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No productivity records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productivity_records as $record): ?>
                            <tr>
                                <td><?php echo format_date($record['harvest_date']); ?></td>
                                <td><?php echo htmlspecialchars($record['harvest_number']); ?></td>
                                <td><strong><?php echo htmlspecialchars($record['harvester_name']); ?></strong></td>
                                <td><?php echo format_number($record['bunches_harvested'], 0); ?></td>
                                <td><?php echo format_number($record['quantity_kg'], 0); ?></td>
                                <td><?php echo format_number($record['working_hours'], 1); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $record['productivity_rate'] >= 100 ? 'success' : ($record['productivity_rate'] >= 80 ? 'primary' : 'warning'); ?>">
                                        <?php echo format_number($record['productivity_rate'], 2); ?>
                                    </span>
                                </td>
                                <td>Rp <?php echo format_number($record['payment_amount'], 0); ?></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo htmlspecialchars($record['payment_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $record['productivity_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $record['productivity_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="harvest_productivity.php" style="display:inline;" onsubmit="return confirmDelete('Delete this record?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="productivity_id" value="<?php echo $record['productivity_id']; ?>">
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

<?php foreach ($productivity_records as $record): ?>
                            <!-- View Details Modal -->
                            <div class="modal fade" id="viewModal<?php echo $record['productivity_id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Productivity Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
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
                                                    <th>Block:</th>
                                                    <td><?php echo htmlspecialchars($record['block_name']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Estate:</th>
                                                    <td><?php echo htmlspecialchars($record['estate_name']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Harvester Name:</th>
                                                    <td><strong><?php echo htmlspecialchars($record['harvester_name']); ?></strong></td>
                                                </tr>
                                                <tr>
                                                    <th>Bunches Harvested:</th>
                                                    <td><?php echo format_number($record['bunches_harvested'], 0); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Quantity:</th>
                                                    <td><?php echo format_number($record['quantity_kg'], 0); ?> Kg</td>
                                                </tr>
                                                <tr>
                                                    <th>Loose Fruits:</th>
                                                    <td><?php echo format_number($record['loose_fruits_kg'], 0); ?> Kg</td>
                                                </tr>
                                                <tr>
                                                    <th>Working Hours:</th>
                                                    <td><?php echo format_number($record['working_hours'], 1); ?> hours</td>
                                                </tr>
                                                <tr>
                                                    <th>Productivity Rate:</th>
                                                    <td><strong><?php echo format_number($record['productivity_rate'], 2); ?> Kg/Hr</strong></td>
                                                </tr>
                                                <tr>
                                                    <th>Payment Amount:</th>
                                                    <td>Rp <?php echo format_number($record['payment_amount'], 0); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Payment Type:</th>
                                                    <td><?php echo htmlspecialchars($record['payment_type']); ?></td>
                                                </tr>
                                            </table>
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
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="harvest_productivity.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_record ? 'Edit Productivity Record' : 'Add Productivity Record'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_record ? 'edit' : 'add'; ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="productivity_id" value="<?php echo $edit_record['productivity_id']; ?>">
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
                            <label class="form-label">Harvester Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="harvester_name" required
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['harvester_name']) : ''; ?>"
                                   placeholder="e.g., Ahmad">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Harvest Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="harvest_date" required
                                   value="<?php echo $edit_record ? $edit_record['harvest_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Bunches Harvested <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="bunches_harvested" required
                                   value="<?php echo $edit_record ? $edit_record['bunches_harvested'] : ''; ?>"
                                   placeholder="e.g., 35">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Quantity (Kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="quantity_kg" required id="quantity_kg"
                                   value="<?php echo $edit_record ? $edit_record['quantity_kg'] : ''; ?>"
                                   placeholder="e.g., 700">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Loose Fruits (Kg)</label>
                            <input type="number" step="0.01" class="form-control" name="loose_fruits_kg"
                                   value="<?php echo $edit_record ? $edit_record['loose_fruits_kg'] : '0'; ?>"
                                   placeholder="e.g., 20">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Working Hours <span class="text-danger">*</span></label>
                            <input type="number" step="0.1" class="form-control" name="working_hours" required id="working_hours"
                                   value="<?php echo $edit_record ? $edit_record['working_hours'] : ''; ?>"
                                   placeholder="e.g., 8.0">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Productivity Rate (Kg/Hr)</label>
                            <input type="text" class="form-control" id="productivity_rate" readonly placeholder="Auto-calculated">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Payment Amount (Rp)</label>
                            <input type="number" class="form-control" name="payment_amount"
                                   value="<?php echo $edit_record ? $edit_record['payment_amount'] : ''; ?>"
                                   placeholder="e.g., 35000">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Payment Type</label>
                            <select class="form-select" name="payment_type">
                                <?php foreach ($payment_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($edit_record && $edit_record['payment_type'] == $type) ? 'selected' : ((!$edit_record && $type == 'Per Bunch') ? 'selected' : ''); ?>>
                                        <?php echo $type; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                        <i class="bi bi-save"></i> <?php echo $edit_record ? 'Update' : 'Add'; ?> Record
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
        calculateProductivity();
    });
</script>
<?php endif; ?>

<script>
function confirmDelete(message) {
    return confirm(message);
}

// Calculate productivity rate
function calculateProductivity() {
    var qty = parseFloat(document.getElementById('quantity_kg').value) || 0;
    var hours = parseFloat(document.getElementById('working_hours').value) || 0;
    
    if (qty > 0 && hours > 0) {
        var rate = qty / hours;
        document.getElementById('productivity_rate').value = rate.toFixed(2) + ' Kg/Hr';
    } else {
        document.getElementById('productivity_rate').value = '';
    }
}

document.getElementById('quantity_kg').addEventListener('input', calculateProductivity);
document.getElementById('working_hours').addEventListener('input', calculateProductivity);

// Trigger calculation on page load if editing
<?php if ($edit_record && $edit_record['quantity_kg'] && $edit_record['working_hours']): ?>
calculateProductivity();
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
