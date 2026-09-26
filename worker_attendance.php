<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'add_attendance') {
        try {
            // Check if attendance already exists
            $check_stmt = $db->prepare("
                SELECT id FROM worker_attendance 
                WHERE employee_id = ? AND attendance_date = ?
            ");
            $check_stmt->execute([post('employee_id'), post('attendance_date')]);
            
            if ($check_stmt->fetch()) {
                set_message('Attendance record already exists for this date!', 'warning');
            } else {
                $check_in = post('check_in_time');
                $check_out = post('check_out_time');
                $work_hours = 0;
                
                if ($check_in && $check_out) {
                    $in = new DateTime($check_in);
                    $out = new DateTime($check_out);
                    $diff = $out->diff($in);
                    $work_hours = $diff->h + ($diff->i / 60);
                }
                
                $stmt = $db->prepare("
                    INSERT INTO worker_attendance (
                        employee_id, attendance_date, check_in_time, check_out_time,
                        work_hours, overtime_hours, status, attendance_type, location, notes, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    post('employee_id'),
                    post('attendance_date'),
                    $check_in ?: null,
                    $check_out ?: null,
                    $work_hours,
                    post('overtime_hours') ?: 0,
                    post('status'),
                    post('attendance_type') ?: 'regular',
                    post('location'),
                    post('notes'),
                    1 // TODO: Get current user ID
                ]);
                
                set_message('Attendance recorded successfully!', 'success');
            }
            redirect('worker_attendance.php');
        } catch (Exception $e) {
            set_message('Error recording attendance: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'bulk_attendance') {
        try {
            $employee_ids = post('employee_ids', []);
            $attendance_date = post('bulk_date');
            $status = post('bulk_status');
            $check_in = post('bulk_check_in');
            $check_out = post('bulk_check_out');
            
            $work_hours = 0;
            if ($check_in && $check_out) {
                $in = new DateTime($check_in);
                $out = new DateTime($check_out);
                $diff = $out->diff($in);
                $work_hours = $diff->h + ($diff->i / 60);
            }
            
            $success_count = 0;
            foreach ($employee_ids as $employee_id) {
                try {
                    $stmt = $db->prepare("
                        INSERT INTO worker_attendance (
                            employee_id, attendance_date, check_in_time, check_out_time,
                            work_hours, status, attendance_type, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, 'regular', ?)
                        ON CONFLICT (employee_id, attendance_date) DO NOTHING
                    ");
                    
                    $stmt->execute([
                        $employee_id,
                        $attendance_date,
                        $check_in ?: null,
                        $check_out ?: null,
                        $work_hours,
                        $status,
                        1
                    ]);
                    
                    if ($stmt->rowCount() > 0) {
                        $success_count++;
                    }
                } catch (Exception $e) {
                    // Skip duplicates
                }
            }
            
            set_message("Bulk attendance recorded for $success_count workers!", 'success');
            redirect('worker_attendance.php');
        } catch (Exception $e) {
            set_message('Error recording bulk attendance: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'delete_attendance') {
        try {
            $stmt = $db->prepare("DELETE FROM worker_attendance WHERE id = ?");
            $stmt->execute([post('attendance_id')]);
            
            set_message('Attendance record deleted successfully!', 'success');
            redirect('worker_attendance.php');
        } catch (Exception $e) {
            set_message('Error deleting attendance: ' . $e->getMessage(), 'danger');
        }
    }
}

// Filters
$date_filter = get('date', date('Y-m-d'));
$status_filter = get('status', '');
$employee_filter = get('employee', '');
$month_filter = get('month', date('Y-m'));

// Fetch attendance records
$attendance_sql = "
    SELECT
        wa.*,
        e.name as employee_name,
        ex.employee_code,
        ws.status_name as worker_status
    FROM worker_attendance wa
    INNER JOIN hr_employee e ON wa.employee_id = e.id
    LEFT JOIN hr_employee_extend ex ON e.id = ex.hr_employee_id
    LEFT JOIN worker_status ws ON ex.worker_status_id = ws.id
    WHERE 1=1
";
$params = [];

if ($date_filter) {
    $attendance_sql .= " AND wa.attendance_date = ?";
    $params[] = $date_filter;
}

if ($status_filter) {
    $attendance_sql .= " AND wa.status = ?";
    $params[] = $status_filter;
}

if ($employee_filter) {
    $attendance_sql .= " AND wa.employee_id = ?";
    $params[] = $employee_filter;
}

$attendance_sql .= " ORDER BY wa.attendance_date DESC, e.name";

$stmt = $db->prepare($attendance_sql);
$stmt->execute($params);
$attendances = $stmt->fetchAll();

// Fetch employees for dropdown
$employees_stmt = $db->query("
    SELECT e.id, ex.employee_code, e.name
    FROM hr_employee e
    LEFT JOIN hr_employee_extend ex ON e.id = ex.hr_employee_id
    WHERE e.active = TRUE
    ORDER BY e.name
");
$employees = $employees_stmt->fetchAll();

// Get statistics for selected date
$stats_stmt = $db->prepare("
    SELECT
        COUNT(*) as total_records,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_count,
        AVG(work_hours) as avg_hours,
        SUM(overtime_hours) as total_overtime
    FROM worker_attendance
    WHERE attendance_date = ?
");
$stats_stmt->execute([$date_filter]);
$stats = $stats_stmt->fetch();

// Get monthly summary
$month_parts = explode('-', $month_filter);
$summary_stmt = $db->prepare("
    SELECT
        e.id,
        e.name as employee_name,
        ex.employee_code,
        COUNT(wa.id) as total_days,
        SUM(CASE WHEN wa.status = 'present' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN wa.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
        SUM(CASE WHEN wa.status = 'late' THEN 1 ELSE 0 END) as late_days,
        SUM(CASE WHEN wa.status = 'leave' THEN 1 ELSE 0 END) as leave_days,
        SUM(wa.work_hours) as total_hours,
        SUM(wa.overtime_hours) as overtime_hours
    FROM hr_employee e
    LEFT JOIN hr_employee_extend ex ON e.id = ex.hr_employee_id
    LEFT JOIN worker_attendance wa ON e.id = wa.employee_id 
        AND EXTRACT(YEAR FROM wa.attendance_date) = ?
        AND EXTRACT(MONTH FROM wa.attendance_date) = ?
    WHERE e.active = TRUE
    GROUP BY e.id, e.name, ex.employee_code
    ORDER BY e.name
");
$summary_stmt->execute([$month_parts[0], $month_parts[1]]);
$monthly_summary = $summary_stmt->fetchAll();

$page_title = "Worker Attendance";
require_once 'includes/header.php';
?>

<style>
    .stat-card {
        border-left: 4px solid #006359;
    }
    
    .stat-card h3 {
        color: #006359;
    }
    
    .attendance-present { background-color: #d4edda; }
    .attendance-absent { background-color: #f8d7da; }
    .attendance-late { background-color: #fff3cd; }
    .attendance-leave { background-color: #d1ecf1; }
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-calendar-check"></i> Worker Attendance</h1>
            <p class="text-muted">Track and manage worker attendance records</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAttendanceModal">
                <i class="bi bi-plus-circle"></i> Record Attendance
            </button>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#bulkAttendanceModal">
                <i class="bi bi-people"></i> Bulk Record
            </button>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h3><?php echo number_format($stats['total_records'] ?? 0); ?></h3>
                <p class="mb-0">Total Records</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card border-success">
            <div class="card-body text-center">
                <h3 class="text-success"><?php echo number_format($stats['present_count'] ?? 0); ?></h3>
                <p class="mb-0">Present</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card border-danger">
            <div class="card-body text-center">
                <h3 class="text-danger"><?php echo number_format($stats['absent_count'] ?? 0); ?></h3>
                <p class="mb-0">Absent</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card border-warning">
            <div class="card-body text-center">
                <h3 class="text-warning"><?php echo number_format($stats['late_count'] ?? 0); ?></h3>
                <p class="mb-0">Late</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card border-info">
            <div class="card-body text-center">
                <h3 class="text-info"><?php echo number_format($stats['leave_count'] ?? 0); ?></h3>
                <p class="mb-0">On Leave</p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card">
            <div class="card-body text-center">
                <h3><?php echo number_format($stats['avg_hours'] ?? 0, 1); ?></h3>
                <p class="mb-0">Avg Hours</p>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#daily">Daily Attendance</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#monthly">Monthly Summary</a>
    </li>
</ul>

<div class="tab-content">
    <!-- Daily Attendance Tab -->
    <div class="tab-pane fade show active" id="daily">
        <div class="card">
            <div class="card-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="mb-0">Attendance Records</h5>
                    </div>
                    <div class="col-auto">
                        <form method="GET" class="row g-2">
                            <div class="col-auto">
                                <input type="date" name="date" class="form-control form-control-sm" 
                                       value="<?php echo htmlspecialchars($date_filter); ?>">
                            </div>
                            <div class="col-auto">
                                <select name="status" class="form-select form-select-sm">
                                    <option value="">All Status</option>
                                    <option value="present" <?php echo $status_filter === 'present' ? 'selected' : ''; ?>>Present</option>
                                    <option value="absent" <?php echo $status_filter === 'absent' ? 'selected' : ''; ?>>Absent</option>
                                    <option value="late" <?php echo $status_filter === 'late' ? 'selected' : ''; ?>>Late</option>
                                    <option value="leave" <?php echo $status_filter === 'leave' ? 'selected' : ''; ?>>Leave</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <select name="employee" class="form-select form-select-sm">
                                    <option value="">All Employees</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>" 
                                                <?php echo $employee_filter == $emp['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['employee_code'] . ' - ' . $emp['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                                <a href="worker_attendance.php" class="btn btn-sm btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Employee</th>
                                <th>Code</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Hours</th>
                                <th>OT Hours</th>
                                <th>Status</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($attendances)): ?>
                                <tr>
                                    <td colspan="11" class="text-center">No attendance records found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attendances as $att): ?>
                                    <tr class="attendance-<?php echo $att['status']; ?>">
                                        <td><?php echo date('d M Y', strtotime($att['attendance_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($att['employee_name']); ?></td>
                                        <td><code><?php echo htmlspecialchars($att['employee_code']); ?></code></td>
                                        <td><?php echo $att['check_in_time'] ? date('H:i', strtotime($att['check_in_time'])) : '-'; ?></td>
                                        <td><?php echo $att['check_out_time'] ? date('H:i', strtotime($att['check_out_time'])) : '-'; ?></td>
                                        <td><?php echo number_format($att['work_hours'], 2); ?></td>
                                        <td><?php echo number_format($att['overtime_hours'], 2); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $att['status'] === 'present' ? 'success' : 
                                                    ($att['status'] === 'absent' ? 'danger' : 
                                                    ($att['status'] === 'late' ? 'warning' : 'info')); 
                                            ?>">
                                                <?php echo ucfirst($att['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo ucfirst($att['attendance_type']); ?></td>
                                        <td><?php echo htmlspecialchars($att['location'] ?? '-'); ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('Delete this attendance record?');">
                                                <input type="hidden" name="action" value="delete_attendance">
                                                <input type="hidden" name="attendance_id" value="<?php echo $att['id']; ?>">
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
    </div>

    <!-- Monthly Summary Tab -->
    <div class="tab-pane fade" id="monthly">
        <div class="card">
            <div class="card-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="mb-0">Monthly Attendance Summary</h5>
                    </div>
                    <div class="col-auto">
                        <form method="GET" class="row g-2">
                            <input type="hidden" name="tab" value="monthly">
                            <div class="col-auto">
                                <input type="month" name="month" class="form-control form-control-sm" 
                                       value="<?php echo htmlspecialchars($month_filter); ?>">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Code</th>
                                <th>Total Days</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Late</th>
                                <th>Leave</th>
                                <th>Total Hours</th>
                                <th>OT Hours</th>
                                <th>Attendance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthly_summary as $summary): ?>
                                <?php 
                                    $attendance_rate = $summary['total_days'] > 0 
                                        ? ($summary['present_days'] / $summary['total_days']) * 100 
                                        : 0;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($summary['employee_name']); ?></td>
                                    <td><code><?php echo htmlspecialchars($summary['employee_code']); ?></code></td>
                                    <td><?php echo number_format($summary['total_days']); ?></td>
                                    <td class="text-success"><?php echo number_format($summary['present_days']); ?></td>
                                    <td class="text-danger"><?php echo number_format($summary['absent_days']); ?></td>
                                    <td class="text-warning"><?php echo number_format($summary['late_days']); ?></td>
                                    <td class="text-info"><?php echo number_format($summary['leave_days']); ?></td>
                                    <td><?php echo number_format($summary['total_hours'], 2); ?></td>
                                    <td><?php echo number_format($summary['overtime_hours'], 2); ?></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar bg-<?php echo $attendance_rate >= 90 ? 'success' : ($attendance_rate >= 75 ? 'warning' : 'danger'); ?>" 
                                                 style="width: <?php echo $attendance_rate; ?>%">
                                                <?php echo number_format($attendance_rate, 1); ?>%
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Attendance Modal -->
<div class="modal fade" id="addAttendanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_attendance">
                <div class="modal-header">
                    <h5 class="modal-title">Record Attendance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Employee <span class="text-danger">*</span></label>
                        <select name="employee_id" class="form-select" required>
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>">
                                    <?php echo htmlspecialchars($emp['employee_code'] . ' - ' . $emp['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="attendance_date" class="form-control" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Check In Time</label>
                            <input type="time" name="check_in_time" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Check Out Time</label>
                            <input type="time" name="check_out_time" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select" required>
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                            <option value="late">Late</option>
                            <option value="half_day">Half Day</option>
                            <option value="leave">Leave</option>
                            <option value="sick">Sick</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Attendance Type</label>
                        <select name="attendance_type" class="form-select">
                            <option value="regular">Regular</option>
                            <option value="overtime">Overtime</option>
                            <option value="weekend">Weekend</option>
                            <option value="holiday">Holiday</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Overtime Hours</label>
                        <input type="number" name="overtime_hours" class="form-control" 
                               step="0.5" min="0" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" class="form-control" 
                               placeholder="e.g., Main Office, Field Block A">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Record Attendance</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Attendance Modal -->
<div class="modal fade" id="bulkAttendanceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="bulk_attendance">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Attendance Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="bulk_date" class="form-control" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Check In Time</label>
                            <input type="time" name="bulk_check_in" class="form-control" value="08:00">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Check Out Time</label>
                            <input type="time" name="bulk_check_out" class="form-control" value="17:00">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select name="bulk_status" class="form-select" required>
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                            <option value="leave">Leave</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Select Employees <span class="text-danger">*</span></label>
                        <div class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" id="select_all">
                                <label class="form-check-label fw-bold" for="select_all">Select All</label>
                            </div>
                            <hr>
                            <?php foreach ($employees as $emp): ?>
                                <div class="form-check">
                                    <input type="checkbox" name="employee_ids[]" value="<?php echo $emp['id']; ?>" 
                                           class="form-check-input employee-checkbox">
                                    <label class="form-check-label">
                                        <?php echo htmlspecialchars($emp['employee_code'] . ' - ' . $emp['name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Bulk Attendance</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Select all checkbox functionality
document.getElementById('select_all')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.employee-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
});

// Keep monthly tab active if coming from filter
<?php if (get('tab') === 'monthly'): ?>
    const monthlyTab = new bootstrap.Tab(document.querySelector('a[href="#monthly"]'));
    monthlyTab.show();
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
