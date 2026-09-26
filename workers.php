<?php
/**
 * HR Employee Management Module
 * Odoo-compatible employee management using hr_employee table
 * Migrated from workers table to integrate with Odoo HR module
 */
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'add') {
        try {
            // Check for duplicate employee code
            $check_code = $db->prepare("SELECT COUNT(*) FROM hr_employee_extend WHERE employee_code = ?");
            $check_code->execute([post('employee_code')]);
            if ($check_code->fetchColumn() > 0) {
                throw new Exception('Employee code already exists. Please use a different code.');
            }
            
            // Check for duplicate ID number (barcode)
            $check_barcode = $db->prepare("SELECT COUNT(*) FROM hr_employee WHERE barcode = ?");
            $check_barcode->execute([post('id_number')]);
            if ($check_barcode->fetchColumn() > 0) {
                throw new Exception('ID number already exists. Please use a different ID number.');
            }
            
            // Determine active status based on employee status
            $status = post('status') ?: 'active';
            $active = ($status === 'active') ? TRUE : FALSE;
            
            // First, create a resource record
            $stmt_resource = $db->prepare("
                INSERT INTO resource_resource (name, resource_type, tz, time_efficiency, company_id, active)
                VALUES (?, 'user', 'Asia/Jakarta', 100.0, 1, ?) RETURNING id
            ");
            $stmt_resource->execute([post('full_name'), $active]);
            $resource_id = $stmt_resource->fetchColumn();
            
            if (!$resource_id) {
                throw new Exception('Failed to create resource record');
            }
            
            // Insert into hr_employee table
            $stmt = $db->prepare("
                INSERT INTO hr_employee (
                    resource_id, company_id, name, barcode, mobile_phone, work_email, place_of_birth,
                    birthday, emergency_contact, emergency_phone, active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $resource_id,
                1, // company_id
                post('full_name'),
                post('id_number'),
                post('phone'),
                post('email') ?: null,
                post('address') ?: null,
                post('date_of_birth') ?: null,
                post('emergency_contact_name') ?: null,
                post('emergency_contact_phone') ?: null,
                $active
            ]);
            
            if (!$result) {
                throw new Exception('Failed to insert hr_employee: ' . implode(', ', $stmt->errorInfo()));
            }
            
            // Get the inserted employee ID
            $employee_id = $db->lastInsertId();
            
            // Check if hr_employee_extend was auto-created by trigger
            $check_ext = $db->prepare("SELECT id, employee_code FROM hr_employee_extend WHERE employee_id = ?");
            $check_ext->execute([$employee_id]);
            $existing_ext = $check_ext->fetch();
            
            if ($existing_ext) {
                // Extension record already created by trigger - update it with user's values
                $stmt_ext = $db->prepare("
                    UPDATE hr_employee_extend SET
                        employee_code = ?,
                        hire_date_custom = ?
                    WHERE employee_id = ?
                ");
                
                $result_ext = $stmt_ext->execute([
                    post('employee_code'),
                    post('hire_date'),
                    $employee_id
                ]);
            } else {
                // No trigger or trigger failed - insert manually
                $stmt_ext = $db->prepare("
                    INSERT INTO hr_employee_extend (
                        employee_id, employee_code, hire_date_custom
                    ) VALUES (?, ?, ?)
                ");
                
                $result_ext = $stmt_ext->execute([
                    $employee_id,
                    post('employee_code'),
                    post('hire_date')
                ]);
            }
            
            if (!$result_ext) {
                throw new Exception('Failed to save hr_employee_extend: ' . implode(', ', $stmt_ext->errorInfo()));
            }
            
            set_message('success', 'Employee added successfully!');
            redirect('workers.php');
        } catch (Exception $e) {
            set_message('danger', 'Error adding employee: ' . $e->getMessage());
        }
    } elseif ($action === 'update') {
        try {
            // Determine active status based on employee status
            $status = post('status');
            $active = ($status === 'active') ? TRUE : FALSE;
            
            // Check if barcode (ID number) has changed
            $current_barcode_stmt = $db->prepare("SELECT barcode FROM hr_employee WHERE id = ?");
            $current_barcode_stmt->execute([post('worker_id')]);
            $current_barcode = $current_barcode_stmt->fetchColumn();
            
            // Convert empty string to NULL for barcode
            $new_barcode = !empty(post('id_number')) ? post('id_number') : null;
            
            // Only check for duplicates if barcode is not NULL and has actually changed
            if ($new_barcode !== null && $current_barcode != $new_barcode) {
                $check_barcode = $db->prepare("SELECT COUNT(*) FROM hr_employee WHERE barcode = ? AND id != ?");
                $check_barcode->execute([$new_barcode, post('worker_id')]);
                if ($check_barcode->fetchColumn() > 0) {
                    throw new Exception('ID number already exists. Please use a different ID number.');
                }
            }
            
            // Update hr_employee table (only fields that exist)
            $stmt = $db->prepare("
                UPDATE hr_employee SET
                    name = ?, barcode = ?, mobile_phone = ?, work_email = ?,
                    place_of_birth = ?, birthday = ?,
                    emergency_contact = ?, emergency_phone = ?, active = ?
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                post('full_name'),
                $new_barcode, // NULL if empty
                !empty(post('phone')) ? post('phone') : null,
                post('email') ?: null,
                post('address') ?: null,
                post('date_of_birth') ?: null,
                post('emergency_contact_name') ?: null,
                post('emergency_contact_phone') ?: null,
                $active,
                post('worker_id')
            ]);
            
            if (!$result) {
                throw new Exception('Failed to update hr_employee: ' . implode(', ', $stmt->errorInfo()));
            }
            
            // Update hr_employee_extend table
            // Check if employee_code has changed to avoid duplicate key error
            $current_code_stmt = $db->prepare("SELECT employee_code FROM hr_employee_extend WHERE employee_id = ?");
            $current_code_stmt->execute([post('worker_id')]);
            $current_code = $current_code_stmt->fetchColumn();
            
            $new_employee_code = post('employee_code');
            
            if ($current_code !== $new_employee_code) {
                // Employee code changed - check for duplicates first
                $check_code = $db->prepare("SELECT COUNT(*) FROM hr_employee_extend WHERE employee_code = ? AND employee_id != ?");
                $check_code->execute([$new_employee_code, post('worker_id')]);
                if ($check_code->fetchColumn() > 0) {
                    throw new Exception('Employee code already exists. Please use a different code.');
                }
                
                // Update with new employee code
                $stmt_ext = $db->prepare("
                    UPDATE hr_employee_extend SET
                        employee_code = ?,
                        hire_date_custom = ?
                    WHERE employee_id = ?
                ");
                
                $result_ext = $stmt_ext->execute([
                    $new_employee_code,
                    post('hire_date'),
                    post('worker_id')
                ]);
            } else {
                // Employee code unchanged - only update hire_date
                $stmt_ext = $db->prepare("
                    UPDATE hr_employee_extend SET
                        hire_date_custom = ?
                    WHERE employee_id = ?
                ");
                
                $result_ext = $stmt_ext->execute([
                    post('hire_date'),
                    post('worker_id')
                ]);
            }
            
            if (!$result_ext) {
                throw new Exception('Failed to update hr_employee_extend: ' . implode(', ', $stmt_ext->errorInfo()));
            }
            
            set_message('success', 'Employee updated successfully!');
            redirect('workers.php');
        } catch (Exception $e) {
            set_message('danger', 'Error updating employee: ' . $e->getMessage());
        }
    } elseif ($action === 'delete') {
        try {
            $stmt = $db->prepare("UPDATE hr_employee SET active = FALSE, departure_date = CURRENT_DATE, write_date = NOW() WHERE id = ?");
            $stmt->execute([post('worker_id')]);
            
            set_message('Employee terminated successfully!', 'success');
            redirect('workers.php');
        } catch (Exception $e) {
            set_message('Error terminating employee: ' . $e->getMessage(), 'danger');
        }
    }
}

// Get edit record if editing
$edit_worker = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("
        SELECT
            e.id,
            ex.employee_code as employee_code,
            e.name as full_name,
            e.barcode as id_number,
            e.mobile_phone as phone,
            e.work_email as email,
            e.place_of_birth as address,
            '' as position,
            ex.hire_date_custom as hire_date,
            e.birthday as date_of_birth,
            '' as gender,
            COALESCE(ex.emergency_contact, e.emergency_contact) as emergency_contact_name,
            COALESCE(ex.emergency_phone, e.emergency_phone) as emergency_contact_phone,
            CASE
                WHEN e.active = TRUE THEN 'active'
                ELSE 'inactive'
            END as status,
            ex.worker_status_id,
            ex.default_business_unit_id,
            ex.default_division_id
        FROM hr_employee e
        LEFT JOIN hr_employee_extend ex ON e.id = ex.hr_employee_id
        WHERE e.id = ?
    ");
    $stmt->execute([$_GET['edit']]);
    $edit_worker = $stmt->fetch();
}

// Pagination and filters
$page = get('page', 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

$status_filter = get('status', '');
$position_filter = get('position', '');
$search = get('search', '');

// Fetch employees with filters (using JOIN with extension table)
$workers_sql = "
    SELECT
        e.id,
        ex.employee_code,
        e.name as full_name,
        e.barcode as id_number,
        e.mobile_phone as phone,
        e.work_email as email,
        e.place_of_birth as address,
        '' as position,
        ex.hire_date_custom as hire_date,
        e.birthday as date_of_birth,
        '' as gender,
        COALESCE(ex.emergency_contact, e.emergency_contact) as emergency_contact_name,
        COALESCE(ex.emergency_phone, e.emergency_phone) as emergency_contact_phone,
        CASE
            WHEN e.active = TRUE THEN 'active'
            ELSE 'inactive'
        END as status,
        ws.status_name as worker_status_name,
        ex.contract_type,
        bu.unit_name as business_unit_name,
        d.division_name
    FROM hr_employee e
    LEFT JOIN hr_employee_extend ex ON e.id = ex.hr_employee_id
    LEFT JOIN worker_status ws ON ex.worker_status_id = ws.id
    LEFT JOIN business_units bu ON ex.default_business_unit_id = bu.business_unit_id
    LEFT JOIN divisions d ON ex.default_division_id = d.division_id
    WHERE 1=1
";
$params = [];

if ($status_filter) {
    if ($status_filter === 'active') {
        $workers_sql .= " AND e.active = TRUE";
    } elseif ($status_filter === 'terminated') {
        $workers_sql .= " AND e.active = FALSE";
    } elseif ($status_filter === 'inactive') {
        $workers_sql .= " AND e.active = FALSE";
    }
}
if ($position_filter) {
    $workers_sql .= " AND ws.status_code = ?";
    $params[] = $position_filter;
}
if ($search) {
    $workers_sql .= " AND (e.name LIKE ? OR ex.employee_code LIKE ? OR e.mobile_phone LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$workers_sql .= " ORDER BY ex.employee_code LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($workers_sql);
$stmt->execute($params);
$workers = $stmt->fetchAll();

// Get total count
$count_sql = "SELECT COUNT(DISTINCT e.id) as total FROM hr_employee e LEFT JOIN hr_employee_extend ex ON e.id = ex.hr_employee_id WHERE 1=1";
$count_params = [];
if ($status_filter) {
    if ($status_filter === 'active') {
        $count_sql .= " AND active = TRUE AND departure_date IS NULL";
    } elseif ($status_filter === 'terminated') {
        $count_sql .= " AND departure_date IS NOT NULL";
    } elseif ($status_filter === 'inactive') {
        $count_sql .= " AND active = FALSE";
    }
}
if ($position_filter) {
    $count_sql .= " AND ws.status_code = ?";
    $count_params[] = $position_filter;
}
if ($search) {
    $count_sql .= " AND (e.name LIKE ? OR ex.employee_code LIKE ? OR e.mobile_phone LIKE ?)";
    $search_term = "%$search%";
    $count_params[] = $search_term;
    $count_params[] = $search_term;
    $count_params[] = $search_term;
}

$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_records = $stmt->fetch()['total'];
$total_pages = ceil($total_records / $per_page);

// Get unique worker statuses for filter (replacing positions)
$positions_stmt = $db->query("
    SELECT DISTINCT ws.status_code as position, ws.status_name
    FROM worker_status ws
    INNER JOIN hr_employee_extend ex ON ws.id = ex.worker_status_id
    WHERE ws.is_active = TRUE
    ORDER BY ws.status_name
");
$positions = $positions_stmt->fetchAll();

// Get statistics
$stats_stmt = $db->query("
    SELECT
        COUNT(*) as total_workers,
        SUM(CASE WHEN active = TRUE THEN 1 ELSE 0 END) as active_workers,
        SUM(CASE WHEN active = FALSE THEN 1 ELSE 0 END) as inactive_workers,
        0 as terminated_workers
    FROM hr_employee
");
$stats = $stats_stmt->fetch();

$page_title = "Workers Management";
require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-people"></i> Workers Management</h1>
            <p class="text-muted">Manage plantation workers and employees</p>
        </div>
        <div class="col-auto">
            <a href="worker_attendance.php" class="btn btn-info">
                <i class="bi bi-calendar-check"></i> Attendance
            </a>
            <a href="worker_assignments.php" class="btn btn-warning">
                <i class="bi bi-clipboard-check"></i> Assignments
            </a>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card border-primary">
            <div class="card-body text-center">
                <h3 class="text-primary"><?php echo number_format($stats['total_workers']); ?></h3>
                <p class="mb-0">Total Workers</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-success">
            <div class="card-body text-center">
                <h3 class="text-success"><?php echo number_format($stats['active_workers']); ?></h3>
                <p class="mb-0">Active Workers</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-warning">
            <div class="card-body text-center">
                <h3 class="text-warning"><?php echo number_format($stats['inactive_workers']); ?></h3>
                <p class="mb-0">Inactive Workers</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-danger">
            <div class="card-body text-center">
                <h3 class="text-danger"><?php echo number_format($stats['terminated_workers']); ?></h3>
                <p class="mb-0">Terminated</p>
            </div>
        </div>
    </div>
</div>

<!-- Entry Form -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-person-plus"></i> <?php echo $edit_worker ? 'Edit' : 'Add New'; ?> Worker
    </div>
    <div class="card-body">
        <form method="POST" action="workers.php">
            <input type="hidden" name="action" value="<?php echo $edit_worker ? 'update' : 'add'; ?>">
            <?php if ($edit_worker): ?>
                <input type="hidden" name="worker_id" value="<?php echo $edit_worker['id']; ?>">
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Employee Code <span class="text-danger">*</span></label>
                    <input type="text" name="employee_code" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['employee_code']) : ''; ?>" 
                           placeholder="EMP001" required>
                </div>
                
                <div class="col-md-5 mb-3">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['full_name']) : ''; ?>" 
                           placeholder="Full Name" required>
                </div>
                
                <div class="col-md-4 mb-3">
                    <label class="form-label">ID Number</label>
                    <input type="text" name="id_number" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['id_number']) : ''; ?>" 
                           placeholder="ID/KTP Number">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['phone']) : ''; ?>"
                           placeholder="08123456789">
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['email']) : ''; ?>"
                           placeholder="email@example.com">
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Position</label>
                    <input type="text" name="position" class="form-control"
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['position']) : ''; ?>"
                           placeholder="e.g., Harvester" list="position-list">
                    <datalist id="position-list">
                        <option value="Field Supervisor">
                        <option value="Harvester">
                        <option value="Maintenance Worker">
                        <option value="Fertilizer Applicator">
                        <option value="Pest Control Specialist">
                        <option value="Quality Inspector">
                        <option value="Equipment Operator">
                    </datalist>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="active" <?php echo ($edit_worker && $edit_worker['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($edit_worker && $edit_worker['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        <option value="suspended" <?php echo ($edit_worker && $edit_worker['status'] == 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                        <option value="terminated" <?php echo ($edit_worker && $edit_worker['status'] == 'terminated') ? 'selected' : ''; ?>>Terminated</option>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Hire Date</label>
                    <input type="date" name="hire_date" class="form-control"
                           value="<?php echo $edit_worker ? $edit_worker['hire_date'] : date('Y-m-d'); ?>">
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" 
                           value="<?php echo $edit_worker ? $edit_worker['date_of_birth'] : ''; ?>">
                </div>
                
                <div class="col-md-2 mb-3">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">Select</option>
                        <option value="male" <?php echo ($edit_worker && $edit_worker['gender'] == 'male') ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo ($edit_worker && $edit_worker['gender'] == 'female') ? 'selected' : ''; ?>>Female</option>
                        <option value="other" <?php echo ($edit_worker && $edit_worker['gender'] == 'other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div class="col-md-4 mb-3">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['address']) : ''; ?>" 
                           placeholder="Full Address">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Emergency Contact Name</label>
                    <input type="text" name="emergency_contact_name" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['emergency_contact_name']) : ''; ?>" 
                           placeholder="Emergency Contact Name">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">Emergency Contact Phone</label>
                    <input type="text" name="emergency_contact_phone" class="form-control" 
                           value="<?php echo $edit_worker ? htmlspecialchars($edit_worker['emergency_contact_phone']) : ''; ?>" 
                           placeholder="Emergency Contact Phone">
                </div>
            </div>
            
            <div class="row">
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_worker ? 'Update' : 'Save'; ?> Worker
                    </button>
                    <?php if ($edit_worker): ?>
                        <a href="workers.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Filter Section -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Name, Code, or Phone">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="suspended" <?php echo $status_filter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    <option value="terminated" <?php echo $status_filter == 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Position</label>
                <select name="position" class="form-select">
                    <option value="">All Positions</option>
                    <?php foreach ($positions as $pos): ?>
                        <option value="<?php echo htmlspecialchars($pos['position']); ?>"
                                <?php echo $position_filter == $pos['position'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($pos['position']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-funnel"></i> Filter
                </button>
                <a href="workers.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Clear
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Workers List -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Workers List (<?php echo number_format($total_records); ?> workers)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Employee Code</th>
                        <th>Name</th>
                        <th>Position</th>
                        <th>Phone</th>
                        <th>Hire Date</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($workers)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No workers found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($workers as $worker): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($worker['employee_code']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($worker['full_name']); ?><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($worker['email'] ?: 'No email'); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($worker['position']); ?></td>
                                <td><?php echo htmlspecialchars($worker['phone']); ?></td>
                                <td><?php echo date('d M Y', strtotime($worker['hire_date'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo match($worker['status']) {
                                            'active' => 'success',
                                            'inactive' => 'warning',
                                            'suspended' => 'danger',
                                            'terminated' => 'secondary',
                                            default => 'secondary'
                                        };
                                    ?>">
                                        <?php echo ucfirst($worker['status']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="workers.php?edit=<?php echo $worker['id']; ?>" 
                                       class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ($worker['status'] != 'terminated'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Terminate this worker?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="worker_id" value="<?php echo $worker['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Terminate">
                                                <i class="bi bi-person-x"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
            <?php echo generate_pagination($page, $total_pages, $_GET); ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
