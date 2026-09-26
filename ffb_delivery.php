<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            // Auto-generate delivery number
            $year = date('Y');
            $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(delivery_number, 10) AS UNSIGNED)) as max_num 
                               FROM ffb_deliveries WHERE delivery_number LIKE 'DLV-$year-%'");
            $result = $stmt->fetch();
            $next_num = ($result['max_num'] ?? 0) + 1;
            $delivery_number = sprintf('DLV-%s-%04d', $year, $next_num);
            
            // Calculate net weight
            $gross_weight = post('gross_weight');
            $tare_weight = post('tare_weight');
            $net_weight = $gross_weight - $tare_weight;
            
            $stmt = $db->prepare("
                INSERT INTO ffb_deliveries 
                (delivery_number, harvest_id, delivery_date, delivery_time, vehicle_number,
                 driver_name, origin_estate, destination_mill, gross_weight, tare_weight,
                 net_weight, bunch_count, quality_grade, ripeness_level, temperature_celsius,
                 travel_time_hours, distance_km, delivery_status, received_by,
                 weighbridge_operator, rejection_reason, notes, created_by)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $delivery_number,
                post('harvest_id'),
                post('delivery_date'),
                post('delivery_time'),
                post('vehicle_number') ?: null,
                post('driver_name') ?: null,
                post('origin_estate') ?: null,
                post('destination_mill'),
                $gross_weight,
                $tare_weight,
                $net_weight,
                post('bunch_count') ?: null,
                post('quality_grade') ?: 'Grade A',
                post('ripeness_level') ?: 'Ripe',
                post('temperature_celsius') ?: null,
                post('travel_time_hours') ?: null,
                post('distance_km') ?: null,
                post('delivery_status') ?: 'In Transit',
                post('received_by') ?: null,
                post('weighbridge_operator') ?: null,
                post('rejection_reason') ?: null,
                post('notes') ?: null,
                'admin'
            ]);
            
            set_message('success', 'FFB delivery record created successfully! Delivery Number: ' . $delivery_number);
            redirect('ffb_delivery.php');
        } catch (PDOException $e) {
            set_message('error', 'Error creating delivery record: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            // Recalculate net weight
            $gross_weight = post('gross_weight');
            $tare_weight = post('tare_weight');
            $net_weight = $gross_weight - $tare_weight;
            
            $stmt = $db->prepare("
                UPDATE ffb_deliveries 
                SET harvest_id = ?, delivery_date = ?, delivery_time = ?, vehicle_number = ?,
                    driver_name = ?, origin_estate = ?, destination_mill = ?, gross_weight = ?,
                    tare_weight = ?, net_weight = ?, bunch_count = ?, quality_grade = ?,
                    ripeness_level = ?, temperature_celsius = ?, travel_time_hours = ?,
                    distance_km = ?, delivery_status = ?, received_by = ?,
                    weighbridge_operator = ?, rejection_reason = ?, notes = ?
                WHERE delivery_id = ?
            ");
            
            $stmt->execute([
                post('harvest_id'),
                post('delivery_date'),
                post('delivery_time'),
                post('vehicle_number') ?: null,
                post('driver_name') ?: null,
                post('origin_estate') ?: null,
                post('destination_mill'),
                $gross_weight,
                $tare_weight,
                $net_weight,
                post('bunch_count') ?: null,
                post('quality_grade'),
                post('ripeness_level'),
                post('temperature_celsius') ?: null,
                post('travel_time_hours') ?: null,
                post('distance_km') ?: null,
                post('delivery_status'),
                post('received_by') ?: null,
                post('weighbridge_operator') ?: null,
                post('rejection_reason') ?: null,
                post('notes') ?: null,
                post('delivery_id')
            ]);
            
            set_message('success', 'FFB delivery record updated successfully!');
            redirect('ffb_delivery.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating delivery record: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM ffb_deliveries WHERE delivery_id = ?");
            $stmt->execute([post('delivery_id')]);
            
            set_message('success', 'FFB delivery record deleted successfully!');
            redirect('ffb_delivery.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting delivery record: ' . $e->getMessage());
        }
    }
}

// Get record for editing (before header)
$edit_record = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM ffb_deliveries WHERE delivery_id = ?");
    $stmt->execute([get('id')]);
    $edit_record = $stmt->fetch();
}

// Now include header after form processing
$page_title = "FFB Delivery";
require_once 'includes/header.php';

// Fetch harvest records for dropdown
$harvests_stmt = $db->query("
    SELECT hr.harvest_id, hr.harvest_number, hr.harvest_date, hr.actual_quantity_kg, b.block_name
    FROM harvest_realizations hr
    INNER JOIN blocks b ON hr.block_id = b.block_id
    WHERE hr.status IN ('Harvested', 'In Transit')
    ORDER BY hr.harvest_date DESC, hr.harvest_number DESC
    LIMIT 100
");
$harvests = $harvests_stmt->fetchAll();

// Fetch delivery records with filters
$search = get('search', '');
$status_filter = get('status', '');
$grade_filter = get('grade', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');

$sql = "SELECT fd.*, 
        hr.harvest_number, hr.harvest_date,
        b.block_name,
        bu.unit_name as estate_name
        FROM ffb_deliveries fd
        INNER JOIN harvest_realizations hr ON fd.harvest_id = hr.harvest_id
        INNER JOIN blocks b ON hr.block_id = b.block_id
        INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON py.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        WHERE 1=1";

$params = [];
if ($search) {
    $sql .= " AND (fd.delivery_number LIKE ? OR hr.harvest_number LIKE ? OR fd.vehicle_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $sql .= " AND fd.delivery_status = ?";
    $params[] = $status_filter;
}
if ($grade_filter) {
    $sql .= " AND fd.quality_grade = ?";
    $params[] = $grade_filter;
}
if ($date_from) {
    $sql .= " AND fd.delivery_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND fd.delivery_date <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY fd.delivery_date DESC, fd.delivery_time DESC, fd.delivery_id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$deliveries = $stmt->fetchAll();

// Calculate summary statistics
$total_deliveries = count($deliveries);
$total_net_weight = array_sum(array_column($deliveries, 'net_weight'));
$total_bunches = array_sum(array_column($deliveries, 'bunch_count'));
$unloaded_count = count(array_filter($deliveries, function($d) { return $d['delivery_status'] == 'Unloaded'; }));
$avg_travel_time = 0;
$count_with_travel = 0;

foreach ($deliveries as $delivery) {
    if ($delivery['travel_time_hours']) {
        $avg_travel_time += $delivery['travel_time_hours'];
        $count_with_travel++;
    }
}
$avg_travel_time = ($count_with_travel > 0) ? ($avg_travel_time / $count_with_travel) : 0;

// Quality grades, ripeness levels, and statuses
$quality_grades = ['Premium', 'Grade A', 'Grade B', 'Grade C', 'Reject'];
$ripeness_levels = ['Under Ripe', 'Ripe', 'Over Ripe'];
$delivery_statuses = ['In Transit', 'Arrived', 'Weighed', 'Unloaded', 'Rejected'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-truck-flatbed"></i> FFB Delivery</h1>
            <p class="text-muted">Track Fresh Fruit Bunches delivery to mill</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Record Delivery
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_deliveries; ?></h3>
                <p><i class="bi bi-truck"></i> Total Deliveries</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_net_weight, 0); ?> Kg</h3>
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
                <h3><?php echo format_number($avg_travel_time, 1); ?> Hrs</h3>
                <p><i class="bi bi-clock"></i> Avg Travel Time</p>
            </div>
        </div>
    </div>
</div>

<!-- Delivery Status Breakdown -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bar-chart"></i> Delivery Status
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <?php 
                    $status_counts = [];
                    foreach ($deliveries as $d) {
                        $status = $d['delivery_status'];
                        if (!isset($status_counts[$status])) {
                            $status_counts[$status] = 0;
                        }
                        $status_counts[$status]++;
                    }
                    foreach ($delivery_statuses as $status): 
                        $count = isset($status_counts[$status]) ? $status_counts[$status] : 0;
                    ?>
                    <div class="col-md-2">
                        <h4 class="text-<?php echo $status == 'Unloaded' ? 'success' : ($status == 'Rejected' ? 'danger' : 'primary'); ?>">
                            <?php echo $count; ?>
                        </h4>
                        <small><?php echo htmlspecialchars($status); ?></small>
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
                    <?php foreach ($delivery_statuses as $status): ?>
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

<!-- FFB Delivery Records Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> FFB Delivery Records (<?php echo count($deliveries); ?>)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>Delivery #</th>
                        <th>Date/Time</th>
                        <th>Harvest #</th>
                        <th>Vehicle</th>
                        <th>Net Weight (Kg)</th>
                        <th>Bunches</th>
                        <th>Quality</th>
                        <th>Destination</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No delivery records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($deliveries as $delivery): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($delivery['delivery_number']); ?></strong></td>
                                <td>
                                    <?php echo format_date($delivery['delivery_date']); ?><br>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($delivery['delivery_time'])); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($delivery['harvest_number']); ?></td>
                                <td><?php echo htmlspecialchars($delivery['vehicle_number']); ?></td>
                                <td><?php echo format_number($delivery['net_weight'], 0); ?></td>
                                <td><?php echo format_number($delivery['bunch_count'], 0); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $delivery['quality_grade'] == 'Premium' ? 'success' : ($delivery['quality_grade'] == 'Grade A' ? 'primary' : 'secondary'); ?>">
                                        <?php echo htmlspecialchars($delivery['quality_grade']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($delivery['destination_mill']); ?></td>
                                <td><?php echo get_status_badge($delivery['delivery_status']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $delivery['delivery_id']; ?>" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="?action=edit&id=<?php echo $delivery['delivery_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="ffb_delivery.php" style="display:inline;" onsubmit="return confirmDelete('Delete this record?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="delivery_id" value="<?php echo $delivery['delivery_id']; ?>">
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

<?php foreach ($deliveries as $delivery): ?>
                            <!-- View Details Modal -->
                            <div class="modal fade" id="viewModal<?php echo $delivery['delivery_id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">FFB Delivery Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">Delivery Number:</th>
                                                            <td><strong><?php echo htmlspecialchars($delivery['delivery_number']); ?></strong></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Harvest Number:</th>
                                                            <td><?php echo htmlspecialchars($delivery['harvest_number']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Delivery Date:</th>
                                                            <td><?php echo format_date($delivery['delivery_date']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Delivery Time:</th>
                                                            <td><?php echo date('H:i', strtotime($delivery['delivery_time'])); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Vehicle Number:</th>
                                                            <td><?php echo htmlspecialchars($delivery['vehicle_number']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Driver Name:</th>
                                                            <td><?php echo htmlspecialchars($delivery['driver_name']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Origin Estate:</th>
                                                            <td><?php echo htmlspecialchars($delivery['origin_estate']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Destination Mill:</th>
                                                            <td><?php echo htmlspecialchars($delivery['destination_mill']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Distance:</th>
                                                            <td><?php echo $delivery['distance_km'] ? format_number($delivery['distance_km'], 1) . ' Km' : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Travel Time:</th>
                                                            <td><?php echo $delivery['travel_time_hours'] ? format_number($delivery['travel_time_hours'], 1) . ' Hours' : '-'; ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                                <div class="col-md-6">
                                                    <table class="table table-sm">
                                                        <tr>
                                                            <th width="40%">Gross Weight:</th>
                                                            <td><?php echo format_number($delivery['gross_weight'], 0); ?> Kg</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Tare Weight:</th>
                                                            <td><?php echo format_number($delivery['tare_weight'], 0); ?> Kg</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Net Weight:</th>
                                                            <td><strong><?php echo format_number($delivery['net_weight'], 0); ?> Kg</strong></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Bunch Count:</th>
                                                            <td><?php echo format_number($delivery['bunch_count'], 0); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Quality Grade:</th>
                                                            <td><?php echo htmlspecialchars($delivery['quality_grade']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Ripeness Level:</th>
                                                            <td><?php echo htmlspecialchars($delivery['ripeness_level']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Temperature:</th>
                                                            <td><?php echo $delivery['temperature_celsius'] ? format_number($delivery['temperature_celsius'], 1) . ' °C' : '-'; ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Received By:</th>
                                                            <td><?php echo htmlspecialchars($delivery['received_by']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Weighbridge Operator:</th>
                                                            <td><?php echo htmlspecialchars($delivery['weighbridge_operator']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Status:</th>
                                                            <td><?php echo htmlspecialchars($delivery['delivery_status']); ?></td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                            <?php if ($delivery['rejection_reason']): ?>
                                            <div class="mt-3">
                                                <h6>Rejection Reason:</h6>
                                                <p class="text-danger"><?php echo nl2br(htmlspecialchars($delivery['rejection_reason'])); ?></p>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($delivery['notes']): ?>
                                            <div class="mt-3">
                                                <h6>Notes:</h6>
                                                <p><?php echo nl2br(htmlspecialchars($delivery['notes'])); ?></p>
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
            <form method="POST" action="ffb_delivery.php">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_record ? 'Edit FFB Delivery' : 'Record FFB Delivery'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_record ? 'edit' : 'add'; ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="delivery_id" value="<?php echo $edit_record['delivery_id']; ?>">
                        <div class="alert alert-info">
                            <strong>Delivery Number:</strong> <?php echo htmlspecialchars($edit_record['delivery_number']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Harvest Record <span class="text-danger">*</span></label>
                            <select class="form-select" name="harvest_id" required>
                                <option value="">Select Harvest</option>
                                <?php foreach ($harvests as $harvest): ?>
                                    <option value="<?php echo $harvest['harvest_id']; ?>" 
                                        <?php echo ($edit_record && $edit_record['harvest_id'] == $harvest['harvest_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($harvest['harvest_number'] . ' - ' . $harvest['block_name'] . ' (' . format_number($harvest['actual_quantity_kg'], 0) . ' Kg)'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Delivery Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="delivery_date" required
                                   value="<?php echo $edit_record ? $edit_record['delivery_date'] : date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Delivery Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="delivery_time" required
                                   value="<?php echo $edit_record ? $edit_record['delivery_time'] : date('H:i'); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Vehicle Number</label>
                            <input type="text" class="form-control" name="vehicle_number"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['vehicle_number']) : ''; ?>"
                                   placeholder="e.g., Truck-01">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Driver Name</label>
                            <input type="text" class="form-control" name="driver_name"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['driver_name']) : ''; ?>"
                                   placeholder="Driver name">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Origin Estate</label>
                            <input type="text" class="form-control" name="origin_estate"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['origin_estate']) : ''; ?>"
                                   placeholder="Estate name">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Destination Mill <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="destination_mill" required
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['destination_mill']) : ''; ?>"
                                   placeholder="e.g., Main Mill">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Distance (Km)</label>
                            <input type="number" step="0.1" class="form-control" name="distance_km"
                                   value="<?php echo $edit_record ? $edit_record['distance_km'] : ''; ?>"
                                   placeholder="e.g., 15.0">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Travel Time (Hours)</label>
                            <input type="number" step="0.1" class="form-control" name="travel_time_hours"
                                   value="<?php echo $edit_record ? $edit_record['travel_time_hours'] : ''; ?>"
                                   placeholder="e.g., 2.5">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Gross Weight (Kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="gross_weight" required id="gross_weight"
                                   value="<?php echo $edit_record ? $edit_record['gross_weight'] : ''; ?>"
                                   placeholder="e.g., 7200">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Tare Weight (Kg) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="tare_weight" required id="tare_weight"
                                   value="<?php echo $edit_record ? $edit_record['tare_weight'] : ''; ?>"
                                   placeholder="e.g., 2000">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Net Weight (Kg)</label>
                            <input type="text" class="form-control" id="net_weight" readonly placeholder="Auto-calculated">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Bunch Count</label>
                            <input type="number" class="form-control" name="bunch_count"
                                   value="<?php echo $edit_record ? $edit_record['bunch_count'] : ''; ?>"
                                   placeholder="e.g., 260">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Quality Grade</label>
                            <select class="form-select" name="quality_grade">
                                <?php foreach ($quality_grades as $grade): ?>
                                    <option value="<?php echo $grade; ?>" <?php echo ($edit_record && $edit_record['quality_grade'] == $grade) ? 'selected' : ((!$edit_record && $grade == 'Grade A') ? 'selected' : ''); ?>>
                                        <?php echo $grade; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Ripeness Level</label>
                            <select class="form-select" name="ripeness_level">
                                <?php foreach ($ripeness_levels as $level): ?>
                                    <option value="<?php echo $level; ?>" <?php echo ($edit_record && $edit_record['ripeness_level'] == $level) ? 'selected' : ((!$edit_record && $level == 'Ripe') ? 'selected' : ''); ?>>
                                        <?php echo $level; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Temperature (°C)</label>
                            <input type="number" step="0.1" class="form-control" name="temperature_celsius"
                                   value="<?php echo $edit_record ? $edit_record['temperature_celsius'] : ''; ?>"
                                   placeholder="e.g., 32.5">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Received By</label>
                            <input type="text" class="form-control" name="received_by"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['received_by']) : ''; ?>"
                                   placeholder="Mill supervisor name">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Weighbridge Operator</label>
                            <input type="text" class="form-control" name="weighbridge_operator"
                                   value="<?php echo $edit_record ? htmlspecialchars($edit_record['weighbridge_operator']) : ''; ?>"
                                   placeholder="Operator name">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Delivery Status</label>
                            <select class="form-select" name="delivery_status" id="delivery_status">
                                <?php foreach ($delivery_statuses as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo ($edit_record && $edit_record['delivery_status'] == $status) ? 'selected' : ((!$edit_record && $status == 'In Transit') ? 'selected' : ''); ?>>
                                        <?php echo $status; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row" id="rejection_field" style="display: none;">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Rejection Reason</label>
                            <textarea class="form-control" name="rejection_reason" rows="2" placeholder="Reason for rejection..."><?php echo $edit_record ? htmlspecialchars($edit_record['rejection_reason']) : ''; ?></textarea>
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
                        <i class="bi bi-save"></i> <?php echo $edit_record ? 'Update' : 'Record'; ?> Delivery
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
        calculateNetWeight();
        toggleRejectionField();
    });
</script>
<?php endif; ?>

<script>
function confirmDelete(message) {
    return confirm(message);
}

// Calculate net weight
function calculateNetWeight() {
    var gross = parseFloat(document.getElementById('gross_weight').value) || 0;
    var tare = parseFloat(document.getElementById('tare_weight').value) || 0;
    
    if (gross > 0 && tare > 0) {
        var net = gross - tare;
        document.getElementById('net_weight').value = net.toFixed(2) + ' Kg';
    } else {
        document.getElementById('net_weight').value = '';
    }
}

document.getElementById('gross_weight').addEventListener('input', calculateNetWeight);
document.getElementById('tare_weight').addEventListener('input', calculateNetWeight);

// Toggle rejection reason field
function toggleRejectionField() {
    var status = document.getElementById('delivery_status').value;
    var rejectionField = document.getElementById('rejection_field');
    
    if (status === 'Rejected') {
        rejectionField.style.display = 'block';
    } else {
        rejectionField.style.display = 'none';
    }
}

document.getElementById('delivery_status').addEventListener('change', toggleRejectionField);

// Initialize on page load
calculateNetWeight();
toggleRejectionField();
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
