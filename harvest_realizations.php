<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            // Auto-generate harvest number
            $year = date('Y');
            $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(harvest_number, 9) AS UNSIGNED)) as max_num 
                               FROM harvest_realizations WHERE harvest_number LIKE 'HV-$year-%'");
            $result = $stmt->fetch();
            $next_num = ($result['max_num'] ?? 0) + 1;
            $harvest_number = sprintf('HV-%s-%04d', $year, $next_num);
            
            // Calculate average bunch weight
            $actual_qty = post('actual_quantity_kg');
            $actual_bunches = post('actual_bunches');
            $avg_weight = ($actual_bunches > 0) ? ($actual_qty / $actual_bunches) : 0;
            
            $stmt = $db->prepare("
                INSERT INTO harvest_realizations 
                (harvest_number, harvest_plan_id, block_id, harvest_date, actual_quantity_kg,
                 actual_bunches, loose_fruits_kg, average_bunch_weight, harvesting_round,
                 harvester_count, harvester_names, supervisor, quality_grade, ripeness_level,
                 weather_condition, transport_vehicle, delivery_destination, delivery_time,
                 status, notes, created_by)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $harvest_number,
                post('harvest_plan_id') ?: null,
                post('block_id'),
                post('harvest_date'),
                $actual_qty,
                $actual_bunches,
                post('loose_fruits_kg') ?: 0,
                $avg_weight,
                post('harvesting_round') ?: 'Round 1',
                post('harvester_count') ?: null,
                post('harvester_names') ?: null,
                post('supervisor') ?: null,
                post('quality_grade') ?: 'Grade A',
                post('ripeness_level') ?: 'Ripe',
                post('weather_condition') ?: null,
                post('transport_vehicle') ?: null,
                post('delivery_destination') ?: null,
                post('delivery_time') ?: null,
                post('status') ?: 'Harvested',
                post('notes') ?: null,
                'admin'
            ]);
            
            set_message('success', 'Harvest record created successfully! Harvest Number: ' . $harvest_number);
            redirect('harvest_realizations.php');
        } catch (PDOException $e) {
            set_message('error', 'Error creating harvest record: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            // Recalculate average bunch weight
            $actual_qty = post('actual_quantity_kg');
            $actual_bunches = post('actual_bunches');
            $avg_weight = ($actual_bunches > 0) ? ($actual_qty / $actual_bunches) : 0;
            
            $stmt = $db->prepare("
                UPDATE harvest_realizations 
                SET harvest_plan_id = ?, block_id = ?, harvest_date = ?, actual_quantity_kg = ?,
                    actual_bunches = ?, loose_fruits_kg = ?, average_bunch_weight = ?,
                    harvesting_round = ?, harvester_count = ?, harvester_names = ?,
                    supervisor = ?, quality_grade = ?, ripeness_level = ?, weather_condition = ?,
                    transport_vehicle = ?, delivery_destination = ?, delivery_time = ?,
                    status = ?, notes = ?
                WHERE harvest_id = ?
            ");
            
            $stmt->execute([
                post('harvest_plan_id') ?: null,
                post('block_id'),
                post('harvest_date'),
                $actual_qty,
                $actual_bunches,
                post('loose_fruits_kg') ?: 0,
                $avg_weight,
                post('harvesting_round'),
                post('harvester_count') ?: null,
                post('harvester_names') ?: null,
                post('supervisor') ?: null,
                post('quality_grade'),
                post('ripeness_level'),
                post('weather_condition') ?: null,
                post('transport_vehicle') ?: null,
                post('delivery_destination') ?: null,
                post('delivery_time') ?: null,
                post('status'),
                post('notes') ?: null,
                post('harvest_id')
            ]);
            
            set_message('success', 'Harvest record updated successfully!');
            redirect('harvest_realizations.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating harvest record: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM harvest_realizations WHERE harvest_id = ?");
            $stmt->execute([post('harvest_id')]);
            
            set_message('success', 'Harvest record deleted successfully!');
            redirect('harvest_realizations.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting harvest record: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_record = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM harvest_realizations WHERE harvest_id = ?");
    $stmt->execute([get('id')]);
    $edit_record = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Harvest Realization";
require_once 'includes/header.php';

// Fetch blocks for dropdown (only TM blocks)
$blocks_stmt = $db->query("
    SELECT b.block_id, b.block_code, b.block_name, b.total_plants, b.area,
           py.year, d.division_name, bu.unit_name, c.company_name
    FROM blocks b
    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id
    WHERE b.status = 'TM'
    ORDER BY c.company_name, bu.unit_name, d.division_name, b.block_name
");
$blocks = $blocks_stmt->fetchAll();

// Fetch harvest plans for dropdown
$plans_stmt = $db->query("
    SELECT hp.harvest_plan_id, hp.plan_number, b.block_name
    FROM harvest_plans hp
    INNER JOIN blocks b ON hp.block_id = b.block_id
    WHERE hp.status IN ('Planned', 'In Progress')
    ORDER BY hp.plan_number DESC
");
$harvest_plans = $plans_stmt->fetchAll();

// Fetch harvest realizations with filters
$search = get('search', '');
$status_filter = get('status', '');
$grade_filter = get('grade', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');

$sql = "SELECT hr.*, 
        b.block_code, b.block_name, b.total_plants, b.area,
        py.year as planting_year,
        d.division_name,
        bu.unit_name as estate_name,
        c.company_name,
        hp.plan_number
        FROM harvest_realizations hr
        INNER JOIN blocks b ON hr.block_id = b.block_id
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        LEFT JOIN harvest_plans hp ON hr.harvest_plan_id = hp.harvest_plan_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (hr.harvest_number LIKE ? OR b.block_name LIKE ? OR hp.plan_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $sql .= " AND hr.status = ?";
    $params[] = $status_filter;
}
if ($grade_filter) {
    $sql .= " AND hr.quality_grade = ?";
    $params[] = $grade_filter;
}
if ($date_from) {
    $sql .= " AND hr.harvest_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND hr.harvest_date <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY hr.harvest_date DESC, hr.harvest_id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$harvests = $stmt->fetchAll();

// Calculate summary statistics
$total_harvests = count($harvests);
$total_quantity = array_sum(array_column($harvests, 'actual_quantity_kg'));
$total_bunches = array_sum(array_column($harvests, 'actual_bunches'));
$total_loose_fruits = array_sum(array_column($harvests, 'loose_fruits_kg'));
$avg_bunch_weight = ($total_bunches > 0) ? ($total_quantity / $total_bunches) : 0;

// Quality grades, ripeness levels, and statuses
$quality_grades = ['Premium', 'Grade A', 'Grade B', 'Grade C', 'Reject'];
$ripeness_levels = ['Under Ripe', 'Ripe', 'Over Ripe'];
$harvesting_rounds = ['Round 1', 'Round 2', 'Round 3', 'Round 4'];
$statuses = ['Harvested', 'In Transit', 'Delivered', 'Rejected'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-basket"></i> Harvest Realization</h1>
            <p class="text-muted">Record actual harvest results</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Record Harvest
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_harvests; ?></h3>
                <p><i class="bi bi-list-check"></i> Total Harvests</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_quantity, 0); ?> Kg</h3>
                <p><i class="bi bi-box-seam"></i> Total FFB</p>
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
                <h3><?php echo format_number($avg_bunch_weight, 2); ?> Kg</h3>
                <p><i class="bi bi-graph-up"></i> Avg Bunch Weight</p>
            </div>
        </div>
    </div>
</div>

<!-- Quality Grade Breakdown -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-award"></i> Harvest by Quality Grade
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <?php 
                    $grade_counts = [];
                    foreach ($harvests as $h) {
                        $grade = $h['quality_grade'];
                        if (!isset($grade_counts[$grade])) {
                            $grade_counts[$grade] = ['count' => 0, 'qty' => 0];
                        }
                        $grade_counts[$grade]['count']++;
                        $grade_counts[$grade]['qty'] += $h['actual_quantity_kg'];
                    }
                    foreach ($grade_counts as $grade => $data): 
                    ?>
                    <div class="col-md-2">
                        <h4 class="text-success"><?php echo $data['count']; ?></h4>
                        <small><?php echo htmlspecialchars($grade); ?></small><br>
                        <small class="text-muted"><?php echo format_number($data['qty'], 0); ?> Kg</small>
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
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $status_filter == $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                    <?php endforeach; ?>
                </select>
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

<!-- Harvest Realizations Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Harvest Records (<?php echo count($harvests); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Harvest #</th>
                        <th>Date</th>
                        <th>Block</th>
                        <th>Quantity (Kg)</th>
                        <th>Bunches</th>
                        <th>Avg Weight</th>
                        <th>Quality</th>
                        <th>Ripeness</th>
                        <th>Destination</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($harvests)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">No harvest records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($harvests as $harvest): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($harvest['harvest_number']); ?></strong></td>
                                <td><?php echo format_date($harvest['harvest_date']); ?></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($harvest['estate_name']); ?></small><br>
                                    <?php echo htmlspecialchars($harvest['block_name']); ?>
                                </td>
                                <td><?php echo format_number($harvest['actual_quantity_kg'], 0); ?></td>
                                <td><?php echo format_number($harvest['actual_bunches'], 0); ?></td>
                                <td><?php echo format_number($harvest['average_bunch_weight'], 2); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $harvest['quality_grade'] == 'Premium' ? 'success' : ($harvest['quality_grade'] == 'Grade A' ? 'primary' : 'secondary'); ?>">
                                        <?php echo htmlspecialchars($harvest['quality_grade']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $harvest['ripeness_level'] == 'Ripe' ? 'success' : 'warning'; ?>">
                                        <?php echo htmlspecialchars($harvest['ripeness_level']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($harvest['delivery_destination']); ?></td>
                                <td><?php echo get_status_badge($harvest['status']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $harvest['harvest_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $harvest['harvest_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="harvest_realizations.php" style="display:inline;" onsubmit="return confirmDelete('Delete this record?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="harvest_id" value="<?php echo $harvest['harvest_id']; ?>">
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

<?php foreach ($harvests as $harvest): ?>
                            <!-- View Details Modal -->
                            <div class="modal fade" id="viewModal<?php echo $harvest['harvest_id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Harvest Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">Harvest Number:</th>
                                                            <td><strong><?php echo htmlspecialchars($harvest['harvest_number']); ?></strong></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Plan Number:</th>
                                                            <td><?php echo $harvest['plan_number'] ? htmlspecialchars($harvest['plan_number']) : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Harvest Date:</th>
                                                            <td><?php echo format_date($harvest['harvest_date']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Block:</th>
                                                            <td><?php echo htmlspecialchars($harvest['block_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Estate:</th>
                                                            <td><?php echo htmlspecialchars($harvest['estate_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Actual Quantity:</th>
                                                            <td><?php echo format_number($harvest['actual_quantity_kg'], 0); ?> Kg</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Actual Bunches:</th>
                                                            <td><?php echo format_number($harvest['actual_bunches'], 0); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Loose Fruits:</th>
                                                            <td><?php echo format_number($harvest['loose_fruits_kg'], 0); ?> Kg</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Avg Bunch Weight:</th>
                                                            <td><?php echo format_number($harvest['average_bunch_weight'], 2); ?> Kg</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Harvesting Round:</th>
                                                            <td><?php echo htmlspecialchars($harvest['harvesting_round']); ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">Harvester Count:</th>
                                                            <td><?php echo $harvest['harvester_count'] ? $harvest['harvester_count'] : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Harvester Names:</th>
                                                            <td><?php echo htmlspecialchars($harvest['harvester_names']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Supervisor:</th>
                                                            <td><?php echo htmlspecialchars($harvest['supervisor']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Quality Grade:</th>
                                                            <td><?php echo htmlspecialchars($harvest['quality_grade']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Ripeness Level:</th>
                                                            <td><?php echo htmlspecialchars($harvest['ripeness_level']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Weather:</th>
                                                            <td><?php echo htmlspecialchars($harvest['weather_condition']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Transport Vehicle:</th>
                                                            <td><?php echo htmlspecialchars($harvest['transport_vehicle']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Destination:</th>
                                                            <td><?php echo htmlspecialchars($harvest['delivery_destination']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Delivery Time:</th>
                                                            <td><?php echo $harvest['delivery_time'] ? date('H:i', strtotime($harvest['delivery_time'])) : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Status:</th>
                                                            <td><?php echo htmlspecialchars($harvest['status']); ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                            <?php if ($harvest['notes']): ?>
                                            <div class="mt-3">
                                                <h6>Notes:</h6>
                                                <p><?php echo nl2br(htmlspecialchars($harvest['notes'])); ?></p>
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
            <form method="POST" action="harvest_realizations.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_record ? 'Edit Harvest Record' : 'Record Harvest'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_record ? 'edit' : 'add'; ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="harvest_id" value="<?php echo $edit_record['harvest_id']; ?>">
                        <div class="alert alert-info">
                            <strong>Harvest Number:</strong> <?php echo htmlspecialchars($edit_record['harvest_number']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Harvest Plan (Optional)</label>
                            <select class="form-select" name="harvest_plan_id">
                                <option value="">No Plan</option>
                                <?php foreach ($harvest_plans as $plan): ?>
                                    <option value="<?php echo $plan['harvest_plan_id']; ?>" 
                                        <?php echo ($edit_record && $edit_record['harvest_plan_id'] == $plan['harvest_plan_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($plan['plan_number'] . ' - ' . $plan['block_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Block (TM Only) <span class="text-danger">*</span></label>
                            <select class="form-select" name="block_id" required id="block_select">
                                <option value="">Select Block</option>
                                <?php foreach ($blocks as $block): ?>
                                    <option value="<?php echo $block['block_id']; ?>" 
                                        data-plants="<?php echo $block['total_plants']; ?>"
                                        data-area="<?php echo $block['area']; ?>"
                                        <?php echo ($edit_record && $edit_record['block_id'] == $block['block_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($block['company_name'] . ' - ' . $block['unit_name'] . ' - ' . $block['block_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" id="block_info"></small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Harvest Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="harvest_date" required
                                   value="<?php echo $edit_record ? $edit_record['harvest_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Actual Quantity (Kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="actual_quantity_kg" required id="actual_qty"
                                   value="<?php echo $edit_record ? $edit_record['actual_quantity_kg'] : ''; ?>"
                                   placeholder="e.g., 5200">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Actual Bunches <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="actual_bunches" required id="actual_bunches"
                                   value="<?php echo $edit_record ? $edit_record['actual_bunches'] : ''; ?>"
                                   placeholder="e.g., 260">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Loose Fruits (Kg)</label>
                            <input type="number" step="0.01" class="form-control" name="loose_fruits_kg"
                                   value="<?php echo $edit_record ? $edit_record['loose_fruits_kg'] : '0'; ?>"
                                   placeholder="e.g., 150">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Avg Bunch Weight (Kg)</label>
                            <input type="text" class="form-control" id="avg_weight" readonly placeholder="Auto-calculated">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Harvesting Round</label>
                            <select class="form-select" name="harvesting_round">
                                <?php foreach ($harvesting_rounds as $round): ?>
                                    <option value="<?php echo $round; ?>" <?php echo ($edit_record && $edit_record['harvesting_round'] == $round) ? 'selected' : ((!$edit_record && $round == 'Round 1') ? 'selected' : ''); ?>>
                                        <?php echo $round; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Harvester Count</label>
                            <input type="number" class="form-control" name="harvester_count"
                                   value="<?php echo $edit_record ? $edit_record['harvester_count'] : ''; ?>"
                                   placeholder="Number of harvesters">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Harvester Names</label>
                            <input type="text" class="form-control" name="harvester_names"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['harvester_names']) : ''; ?>"
                                   placeholder="Comma-separated names">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Supervisor</label>
                            <input type="text" class="form-control" name="supervisor"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['supervisor']) : ''; ?>"
                                   placeholder="Supervisor name">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Quality Grade</label>
                            <select class="form-select" name="quality_grade">
                                <?php foreach ($quality_grades as $grade): ?>
                                    <option value="<?php echo $grade; ?>" <?php echo ($edit_record && $edit_record['quality_grade'] == $grade) ? 'selected' : ((!$edit_record && $grade == 'Grade A') ? 'selected' : ''); ?>>
                                        <?php echo $grade; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ripeness Level</label>
                            <select class="form-select" name="ripeness_level">
                                <?php foreach ($ripeness_levels as $level): ?>
                                    <option value="<?php echo $level; ?>" <?php echo ($edit_record && $edit_record['ripeness_level'] == $level) ? 'selected' : ((!$edit_record && $level == 'Ripe') ? 'selected' : ''); ?>>
                                        <?php echo $level; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Weather Condition</label>
                            <input type="text" class="form-control" name="weather_condition"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['weather_condition']) : ''; ?>"
                                   placeholder="e.g., Sunny, Cloudy">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Transport Vehicle</label>
                            <input type="text" class="form-control" name="transport_vehicle"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['transport_vehicle']) : ''; ?>"
                                   placeholder="e.g., Truck-01">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Delivery Destination</label>
                            <input type="text" class="form-control" name="delivery_destination"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['delivery_destination']) : ''; ?>"
                                   placeholder="e.g., Main Mill">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Delivery Time</label>
                            <input type="time" class="form-control" name="delivery_time"
                                   value="<?php echo $edit_record ? $edit_record['delivery_time'] : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo ($edit_record && $edit_record['status'] == $status) ? 'selected' : ((!$edit_record && $status == 'Harvested') ? 'selected' : ''); ?>>
                                        <?php echo $status; ?>
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
                        <i class="bi bi-save"></i> <?php echo $edit_record ? 'Update' : 'Record'; ?> Harvest
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

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
