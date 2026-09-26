<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = 'User Management';
$is_admin = true;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $full_name        = trim($_POST['full_name'] ?? '');
        $username         = trim($_POST['username']  ?? '');
        $email            = trim($_POST['email']     ?? '');
        $role             = $_POST['role']            ?? 'user';
        $company_id       = ($_POST['company_id']       ?? '') ?: null;
        $business_unit_id = ($_POST['business_unit_id'] ?? '') ?: null;
        $division_id      = ($_POST['division_id']      ?? '') ?: null;
        $password_hash    = password_hash('password123', PASSWORD_BCRYPT);

        try {
            $stmt = $db->prepare("
                INSERT INTO agrosmart_users
                    (username, full_name, email, password_hash, role, company_id, business_unit_id, division_id, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE, NOW())
            ");
            $stmt->execute([$username, $full_name, $email, $password_hash, $role, $company_id, $business_unit_id, $division_id]);
            set_message('success', 'User created successfully! Default password: <strong>password123</strong>');
        } catch (Exception $e) {
            set_message('danger', 'Error creating user: ' . $e->getMessage());
        }
        redirect('users.php');

    } elseif ($action === 'update_user') {
        $user_id          = (int)($_POST['user_id']  ?? 0);
        $full_name        = trim($_POST['full_name'] ?? '');
        $email            = trim($_POST['email']     ?? '');
        $role             = $_POST['role']            ?? 'user';
        $company_id       = ($_POST['company_id']       ?? '') ?: null;
        $business_unit_id = ($_POST['business_unit_id'] ?? '') ?: null;
        $division_id      = ($_POST['division_id']      ?? '') ?: null;

        try {
            $stmt = $db->prepare("
                UPDATE agrosmart_users
                SET full_name = ?, email = ?, role = ?,
                    company_id = ?, business_unit_id = ?, division_id = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$full_name, $email, $role, $company_id, $business_unit_id, $division_id, $user_id]);
            set_message('success', 'User updated successfully!');
        } catch (Exception $e) {
            set_message('danger', 'Error updating user: ' . $e->getMessage());
        }
        redirect('users.php');

    } elseif ($action === 'toggle_status') {
        try {
            $stmt = $db->prepare("UPDATE agrosmart_users SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?");
            $stmt->execute([(int)($_POST['user_id'] ?? 0)]);
            set_message('success', 'User status updated!');
        } catch (Exception $e) {
            set_message('danger', 'Error: ' . $e->getMessage());
        }
        redirect('users.php');

    } elseif ($action === 'delete_user') {
        try {
            $stmt = $db->prepare("DELETE FROM agrosmart_users WHERE id = ?");
            $stmt->execute([(int)($_POST['user_id'] ?? 0)]);
            set_message('success', 'User deleted successfully!');
        } catch (Exception $e) {
            set_message('danger', 'Error deleting user: ' . $e->getMessage());
        }
        redirect('users.php');

    } elseif ($action === 'reset_password') {
        try {
            $new_hash = password_hash('password123', PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE agrosmart_users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_hash, (int)($_POST['user_id'] ?? 0)]);
            set_message('success', 'Password reset to <strong>password123</strong>');
        } catch (Exception $e) {
            set_message('danger', 'Error: ' . $e->getMessage());
        }
        redirect('users.php');
    }
}

// Fetch all users
$users = $db->query("
    SELECT
        u.*,
        c.company_name,
        bu.unit_code,
        bu.unit_name,
        d.division_code,
        d.division_name
    FROM agrosmart_users u
    LEFT JOIN companies c ON u.company_id = c.company_id
    LEFT JOIN business_units bu ON u.business_unit_id = bu.business_unit_id
    LEFT JOIN divisions d ON u.division_id = d.division_id
    ORDER BY u.full_name
")->fetchAll();

$companies      = $db->query("SELECT * FROM companies ORDER BY company_name")->fetchAll();
$business_units = $db->query("SELECT business_unit_id, unit_code, unit_name, company_id FROM business_units ORDER BY unit_code")->fetchAll();
$divisions      = $db->query("SELECT division_id, division_code, division_name, business_unit_id FROM divisions ORDER BY division_code")->fetchAll();

require_once 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2><i class="bi bi-people"></i> <?php echo $page_title; ?></h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item active">Users</li>
                </ol>
            </nav>
        </div>
    </div>

    <!-- Users List -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center" style="background-color: #166c82; color: white;">
            <span><i class="bi bi-list"></i> Users (<?php echo count($users); ?>)</span>
            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#newUserModal">
                <i class="bi bi-plus-circle"></i> New User
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Company</th>
                            <th>Business Unit</th>
                            <th>Division</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="9" class="text-center text-muted">No users found</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php if ($user['company_name']): ?>
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($user['company_name']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">All Companies</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['unit_code']): ?>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($user['unit_code']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">All Units</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['division_code']): ?>
                                            <span class="badge bg-success"><?php echo htmlspecialchars($user['division_code']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">All Divisions</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $role_colors = ['admin'=>'danger','manager'=>'warning','supervisor'=>'info','user'=>'secondary'];
                                        $color = $role_colors[$user['role']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>"><?php echo ucfirst($user['role']); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <!-- Edit -->
                                        <button type="button" class="btn btn-sm btn-warning"
                                            data-user="<?php echo htmlspecialchars(json_encode([
                                                'id'               => $user['id'],
                                                'username'         => $user['username'],
                                                'full_name'        => $user['full_name'],
                                                'email'            => $user['email'],
                                                'company_id'       => $user['company_id'],
                                                'business_unit_id' => $user['business_unit_id'],
                                                'division_id'      => $user['division_id'],
                                                'role'             => $user['role'],
                                            ]), ENT_QUOTES); ?>"
                                            onclick="editUser(this)" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <!-- Toggle status -->
                                        <form method="POST" action="users.php" style="display:inline;" onsubmit="return confirm('Toggle user status?');">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-info" title="Toggle Active">
                                                <i class="bi bi-toggle-on"></i>
                                            </button>
                                        </form>
                                        <!-- Reset password -->
                                        <form method="POST" action="users.php" style="display:inline;" onsubmit="return confirm('Reset password to password123?');">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary" title="Reset Password">
                                                <i class="bi bi-key"></i>
                                            </button>
                                        </form>
                                        <!-- Delete -->
                                        <form method="POST" action="users.php" style="display:inline;" onsubmit="return confirm('Delete this user?');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
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

<!-- New User Modal -->
<div class="modal fade" id="newUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="users.php">
                <input type="hidden" name="action" value="create_user">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Company <span class="text-muted">(Optional)</span></label>
                        <select name="company_id" id="new_company_id" class="form-select">
                            <option value="">All Companies</option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?php echo $c['company_id']; ?>"><?php echo htmlspecialchars($c['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Business Unit <span class="text-muted">(Optional)</span></label>
                        <select name="business_unit_id" id="new_business_unit_id" class="form-select">
                            <option value="">All Business Units</option>
                            <?php foreach ($business_units as $bu): ?>
                                <option value="<?php echo $bu['business_unit_id']; ?>" data-company-id="<?php echo $bu['company_id']; ?>">
                                    <?php echo $bu['unit_code']; ?> - <?php echo htmlspecialchars($bu['unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Division <span class="text-muted">(Optional)</span></label>
                        <select name="division_id" id="new_division_id" class="form-select">
                            <option value="">All Divisions</option>
                            <?php foreach ($divisions as $div): ?>
                                <option value="<?php echo $div['division_id']; ?>" data-business-unit-id="<?php echo $div['business_unit_id']; ?>">
                                    <?php echo $div['division_code']; ?> - <?php echo htmlspecialchars($div['division_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select" required>
                            <option value="user">User</option>
                            <option value="manager">Manager</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="alert alert-info py-2 mb-0">
                        <i class="bi bi-info-circle"></i> Default password will be <strong>password123</strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="users.php">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" id="edit_username" class="form-control" disabled>
                        <small class="text-muted">Username cannot be changed</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Company <span class="text-muted">(Optional)</span></label>
                        <select name="company_id" id="edit_company_id" class="form-select">
                            <option value="">All Companies</option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?php echo $c['company_id']; ?>"><?php echo htmlspecialchars($c['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Business Unit <span class="text-muted">(Optional)</span></label>
                        <select name="business_unit_id" id="edit_business_unit_id" class="form-select">
                            <option value="">All Business Units</option>
                            <?php foreach ($business_units as $bu): ?>
                                <option value="<?php echo $bu['business_unit_id']; ?>" data-company-id="<?php echo $bu['company_id']; ?>">
                                    <?php echo $bu['unit_code']; ?> - <?php echo htmlspecialchars($bu['unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Division <span class="text-muted">(Optional)</span></label>
                        <select name="division_id" id="edit_division_id" class="form-select">
                            <option value="">All Divisions</option>
                            <?php foreach ($divisions as $div): ?>
                                <option value="<?php echo $div['division_id']; ?>" data-business-unit-id="<?php echo $div['business_unit_id']; ?>">
                                    <?php echo $div['division_code']; ?> - <?php echo htmlspecialchars($div['division_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role <span class="text-danger">*</span></label>
                        <select name="role" id="edit_role" class="form-select" required>
                            <option value="user">User</option>
                            <option value="manager">Manager</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const allBusinessUnits = <?php echo json_encode($business_units); ?>;
const allDivisions     = <?php echo json_encode($divisions); ?>;

document.getElementById('new_company_id').addEventListener('change', function() {
    filterBusinessUnits('new_business_unit_id', this.value);
    filterDivisions('new_division_id', '');
});
document.getElementById('new_business_unit_id').addEventListener('change', function() {
    filterDivisions('new_division_id', this.value);
});
document.getElementById('edit_company_id').addEventListener('change', function() {
    filterBusinessUnits('edit_business_unit_id', this.value);
    filterDivisions('edit_division_id', '');
});
document.getElementById('edit_business_unit_id').addEventListener('change', function() {
    filterDivisions('edit_division_id', this.value);
});

function filterBusinessUnits(selectId, companyId) {
    const select = document.getElementById(selectId);
    select.innerHTML = '<option value="">All Business Units</option>';
    allBusinessUnits.forEach(bu => {
        if (!companyId || bu.company_id == companyId) {
            const o = document.createElement('option');
            o.value = bu.business_unit_id;
            o.textContent = bu.unit_code + ' - ' + bu.unit_name;
            select.appendChild(o);
        }
    });
}

function filterDivisions(selectId, businessUnitId) {
    const select = document.getElementById(selectId);
    select.innerHTML = '<option value="">All Divisions</option>';
    allDivisions.forEach(div => {
        if (!businessUnitId || div.business_unit_id == businessUnitId) {
            const o = document.createElement('option');
            o.value = div.division_id;
            o.textContent = div.division_code + ' - ' + div.division_name;
            select.appendChild(o);
        }
    });
}

function editUser(btn) {
    const user = JSON.parse(btn.dataset.user);
    document.getElementById('edit_user_id').value    = user.id;
    document.getElementById('edit_username').value   = user.username;
    document.getElementById('edit_full_name').value  = user.full_name;
    document.getElementById('edit_email').value      = user.email;
    document.getElementById('edit_company_id').value = user.company_id || '';
    document.getElementById('edit_role').value       = user.role;
    filterBusinessUnits('edit_business_unit_id', user.company_id);
    document.getElementById('edit_business_unit_id').value = user.business_unit_id || '';
    filterDivisions('edit_division_id', user.business_unit_id);
    document.getElementById('edit_division_id').value = user.division_id || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
