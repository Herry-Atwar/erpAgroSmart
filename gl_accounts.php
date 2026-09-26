<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO general_ledger_accounts
                    (account_code, account_name, account_type, account_category,
                     parent_account_id, sap_gl_account, description, normal_balance,
                     financial_group_id,
                     is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?,
                    CASE WHEN ? IN ('liability','equity','revenue') THEN 'credit' ELSE 'debit' END,
                    ?,
                    TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");

            $stmt->execute([
                post('account_code'),
                post('account_name'),
                post('account_type'),
                post('account_category') ?: null,
                post('parent_account_id') ?: null,
                post('sap_gl_account') ?: null,
                post('description') ?: null,
                post('account_type'),
                post('financial_group_id') ?: null,
            ]);

            $db->commit();
            set_message('success', 'GL Account added successfully!');
            redirect('gl_accounts.php');
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error adding GL account: ' . $e->getMessage());
        }
    }

    elseif ($action == 'edit') {
        try {
            $db->beginTransaction();

            $account_id = post('account_id');

            $stmt = $db->prepare("
                UPDATE general_ledger_accounts
                SET account_code       = ?,
                    account_name       = ?,
                    account_type       = ?,
                    parent_account_id  = ?,
                    sap_gl_account     = ?,
                    account_category   = ?,
                    description        = ?,
                    financial_group_id = ?,
                    updated_at         = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $stmt->execute([
                post('account_code'),
                post('account_name'),
                post('account_type'),
                post('parent_account_id') ?: null,
                post('sap_gl_account') ?: null,
                post('account_category') ?: null,
                post('description') ?: null,
                post('financial_group_id') ?: null,
                $account_id
            ]);

            $db->commit();
            set_message('success', 'GL Account updated successfully!');
            redirect('gl_accounts.php');
        } catch (PDOException $e) {
            $db->rollBack();
            set_message('error', 'Error updating GL account: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM general_ledger_accounts WHERE id = ?");
            $stmt->execute([post('account_id')]);

            set_message('success', 'GL Account deleted successfully!');
            redirect('gl_accounts.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting GL account: ' . $e->getMessage());
        }
    }
}

$page_title = "General Ledger Accounts";
require_once 'includes/header.php';

// Fetch all accounts for parent dropdown
$parent_accounts_stmt = $db->query("
    SELECT id, account_code, account_name, account_type
    FROM general_ledger_accounts
    ORDER BY account_code
");
$parent_accounts = $parent_accounts_stmt->fetchAll();

// Fetch account groups for dropdown (grouped by report_type)
$account_groups_stmt = $db->query("
    SELECT id, group_code, group_name, report_type
    FROM financial_account_groups
    WHERE is_active = TRUE
    ORDER BY report_type, display_order, group_code
");
$account_groups = $account_groups_stmt->fetchAll();

// Fetch GL accounts with filters using the view
$search = get('search', '');
$type_filter = get('account_type', '');
$status_filter = get('status', '');

$sql = "SELECT
        g.id,
        g.account_code,
        g.account_name,
        g.account_type,
        g.description,
        g.sap_gl_account,
        g.account_category,
        g.parent_account_id,
        p.account_code as parent_code,
        p.account_name as parent_name,
        g.financial_group_id,
        fag.group_code,
        fag.group_name,
        g.created_at,
        g.updated_at,
        g.is_active
        FROM general_ledger_accounts g
        LEFT JOIN general_ledger_accounts p ON g.parent_account_id = p.id
        LEFT JOIN financial_account_groups fag ON fag.id = g.financial_group_id
        WHERE 1=1";

if ($search) {
    $sql .= " AND (g.account_code LIKE :search OR g.account_name LIKE :search OR g.sap_gl_account LIKE :search)";
}
if ($type_filter) {
    $sql .= " AND g.account_type = :account_type";
}

$sql .= " ORDER BY g.account_code";

$stmt = $db->prepare($sql);
if ($search) {
    $stmt->bindValue(':search', "%$search%");
}
if ($type_filter) {
    $stmt->bindValue(':account_type', $type_filter);
}
$stmt->execute();
$accounts = $stmt->fetchAll();

// Calculate statistics
$stats_stmt = $db->query("
    SELECT
        COUNT(*) as total_accounts,
        SUM(CASE WHEN account_type = 'asset'     THEN 1 ELSE 0 END) as assets,
        SUM(CASE WHEN account_type = 'liability' THEN 1 ELSE 0 END) as liabilities,
        SUM(CASE WHEN account_type = 'equity'    THEN 1 ELSE 0 END) as equity,
        SUM(CASE WHEN account_type = 'revenue'   THEN 1 ELSE 0 END) as revenue,
        SUM(CASE WHEN account_type = 'expense'   THEN 1 ELSE 0 END) as expenses,
        SUM(CASE WHEN is_active = TRUE           THEN 1 ELSE 0 END) as active_accounts
    FROM general_ledger_accounts
");
$stats = $stats_stmt->fetch();
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color: #166c82;"><i class="bi bi-journal-text" style="color: #166c82;"></i> General Ledger Accounts</h1>
            <p class="text-muted">Manage Chart of Accounts (COA) - SAP Style</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-custom-gl" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New GL Account
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card stat-card" style="border-color: #3065b0;">
            <div class="card-body text-center">
                <h3 style="color: #3065b0;"><?php echo $stats['total_accounts']; ?></h3>
                <p class="mb-0"><small>Total Accounts</small></p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card border-success">
            <div class="card-body text-center">
                <h3 class="text-success"><?php echo $stats['assets']; ?></h3>
                <p class="mb-0"><small>Assets</small></p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card border-danger">
            <div class="card-body text-center">
                <h3 class="text-danger"><?php echo $stats['liabilities']; ?></h3>
                <p class="mb-0"><small>Liabilities</small></p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card border-info">
            <div class="card-body text-center">
                <h3 class="text-info"><?php echo $stats['equity']; ?></h3>
                <p class="mb-0"><small>Equity</small></p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card border-warning">
            <div class="card-body text-center">
                <h3 class="text-warning"><?php echo $stats['revenue']; ?></h3>
                <p class="mb-0"><small>Revenue</small></p>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card stat-card border-secondary">
            <div class="card-body text-center">
                <h3 class="text-secondary"><?php echo $stats['expenses']; ?></h3>
                <p class="mb-0"><small>Expenses</small></p>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="Search by code, name, or SAP GL..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="account_type">
                    <option value="">All Account Types</option>
                    <option value="asset" <?php echo $type_filter == 'asset' ? 'selected' : ''; ?>>Asset</option>
                    <option value="liability" <?php echo $type_filter == 'liability' ? 'selected' : ''; ?>>Liability</option>
                    <option value="equity" <?php echo $type_filter == 'equity' ? 'selected' : ''; ?>>Equity</option>
                    <option value="revenue" <?php echo $type_filter == 'revenue' ? 'selected' : ''; ?>>Revenue</option>
                    <option value="expense" <?php echo $type_filter == 'expense' ? 'selected' : ''; ?>>Expense</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Active</option>
                    <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-custom-gl"><i class="bi bi-search"></i> Search</button>
                <a href="gl_accounts.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- GL Accounts Table -->
<div class="card">
    <div class="card-header text-white d-flex justify-content-between align-items-center" style="background-color: #166c82;">
        <span><i class="bi bi-list-ul"></i> Chart of Accounts (<?php echo count($accounts); ?> accounts)</span>
        <a href="account_groups.php?tab=mapping" class="btn btn-sm btn-light">
            <i class="bi bi-collection"></i> Manage Account Groups
        </a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th style="width: 10%;">Account Code</th>
                        <th style="width: 22%;">Account Name</th>
                        <th style="width: 8%;">Type</th>
                        <th style="width: 13%;">Category</th>
                        <th style="width: 9%;">SAP GL</th>
                        <th style="width: 13%;">Parent Account</th>
                        <th style="width: 13%;">Group</th>
                        <th style="width: 7%;">Status</th>
                        <th style="width: 5%;" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($accounts)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">No GL accounts found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($account['account_code']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($account['account_name']); ?></strong></td>
                                <td>
                                    <?php
                                    $type_colors = [
                                        'asset' => 'success',
                                        'liability' => 'danger',
                                        'equity' => 'info',
                                        'revenue' => 'warning',
                                        'expense' => 'secondary'
                                    ];
                                    $color = $type_colors[$account['account_type']] ?? 'primary';
                                    ?>
                                    <span class="badge bg-<?php echo $color; ?>">
                                        <?php echo ucfirst($account['account_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($account['account_category'] ?? '-'); ?></td>
                                <td><code><?php echo htmlspecialchars($account['sap_gl_account'] ?? '-'); ?></code></td>
                                <td>
                                    <?php if (isset($account['parent_code']) && $account['parent_code']): ?>
                                        <small><?php echo htmlspecialchars($account['parent_code'] . ' - ' . $account['parent_name']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($account['financial_group_id']): ?>
                                        <a href="account_groups.php?tab=groups&edit_group=<?php echo $account['financial_group_id']; ?>"
                                           class="text-decoration-none" title="<?php echo htmlspecialchars($account['group_name']); ?>">
                                            <span class="badge bg-primary">
                                                <?php echo htmlspecialchars($account['group_code']); ?>
                                            </span>
                                        </a>
                                    <?php else: ?>
                                        <a href="account_groups.php?tab=mapping" class="text-decoration-none">
                                            <span class="badge bg-warning text-dark">Unmapped</span>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $account['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $account['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-warning btn-edit-gl" title="Edit"
                                        data-id="<?php echo $account['id']; ?>"
                                        data-code="<?php echo htmlspecialchars($account['account_code'], ENT_QUOTES); ?>"
                                        data-name="<?php echo htmlspecialchars($account['account_name'], ENT_QUOTES); ?>"
                                        data-type="<?php echo htmlspecialchars($account['account_type'], ENT_QUOTES); ?>"
                                        data-category="<?php echo htmlspecialchars($account['account_category'] ?? '', ENT_QUOTES); ?>"
                                        data-sap="<?php echo htmlspecialchars($account['sap_gl_account'] ?? '', ENT_QUOTES); ?>"
                                        data-parent="<?php echo (int)($account['parent_account_id'] ?? 0); ?>"
                                        data-description="<?php echo htmlspecialchars($account['description'] ?? '', ENT_QUOTES); ?>"
                                        data-group="<?php echo (int)($account['financial_group_id'] ?? 0); ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirmDelete('Delete this GL account?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
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

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gl_accounts.php" id="glAccountForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New GL Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="account_id" id="formAccountId" value="">

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Account Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="account_code" id="formAccountCode"
                                   placeholder="e.g., 1110" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Account Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="account_type" id="formAccountType" required>
                                <option value="">-- Select Type --</option>
                                <option value="asset">Asset</option>
                                <option value="liability">Liability</option>
                                <option value="equity">Equity</option>
                                <option value="revenue">Revenue</option>
                                <option value="expense">Expense</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">SAP GL Account</label>
                            <input type="text" class="form-control" name="sap_gl_account" id="formSapGl"
                                   placeholder="e.g., 100000">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Account Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="account_name" id="formAccountName"
                               placeholder="e.g., Cash and Cash Equivalents" required>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Category</label>
                            <input type="text" class="form-control" name="account_category" id="formAccountCategory"
                                   placeholder="e.g., Current Assets, Fixed Assets">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Parent Account</label>
                            <select class="form-select" name="parent_account_id" id="formParentAccount">
                                <option value="">None (Top Level Account)</option>
                                <?php foreach ($parent_accounts as $parent): ?>
                                    <option value="<?php echo $parent['id']; ?>">
                                        <?php echo htmlspecialchars($parent['account_code'] . ' - ' . $parent['account_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="formDescription" rows="3"
                                  placeholder="Optional description..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-collection"></i> Account Group
                            <small class="text-muted fw-normal ms-1">— untuk grouped financial reports</small>
                        </label>
                        <select class="form-select" name="financial_group_id" id="formFinancialGroup">
                            <option value="">— Belum di-assign (Unmapped) —</option>
                            <?php
                            $grouped_by_type = [];
                            foreach ($account_groups as $g) {
                                $grouped_by_type[$g['report_type']][] = $g;
                            }
                            $type_labels = ['balance_sheet' => 'Balance Sheet', 'profit_loss' => 'Profit & Loss'];
                            foreach ($grouped_by_type as $rtype => $rgroups):
                            ?>
                            <optgroup label="<?php echo $type_labels[$rtype] ?? $rtype; ?>">
                                <?php foreach ($rgroups as $g): ?>
                                <option value="<?php echo $g['id']; ?>">
                                    <?php echo htmlspecialchars($g['group_code'] . ' – ' . $g['group_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($account_groups)): ?>
                        <div class="form-text text-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            Belum ada account group. <a href="account_groups.php?tab=groups" target="_blank">Buat group dulu</a>.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-custom-gl" id="modalSubmitBtn">
                        <i class="bi bi-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var _editMode = false;

// Reset modal to "Add" mode when opened via the Add button
document.getElementById('addModal').addEventListener('show.bs.modal', function () {
    if (!_editMode) {
        document.getElementById('modalTitle').textContent    = 'Add New GL Account';
        document.getElementById('formAction').value          = 'add';
        document.getElementById('formAccountId').value        = '';
        document.getElementById('formAccountCode').value      = '';
        document.getElementById('formAccountName').value      = '';
        document.getElementById('formAccountType').value      = '';
        document.getElementById('formSapGl').value            = '';
        document.getElementById('formAccountCategory').value  = '';
        document.getElementById('formParentAccount').value    = '';
        document.getElementById('formDescription').value      = '';
        document.getElementById('formFinancialGroup').value   = '';
        document.getElementById('modalSubmitBtn').innerHTML   = '<i class="bi bi-save"></i> Save';
    }
    _editMode = false; // reset flag after each open
});

// Wire up every Edit button
document.querySelectorAll('.btn-edit-gl').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var d = this.dataset;
        _editMode = true; // prevent show.bs.modal from resetting the form

        document.getElementById('modalTitle').textContent    = 'Edit GL Account';
        document.getElementById('formAction').value          = 'edit';
        document.getElementById('formAccountId').value       = d.id;
        document.getElementById('formAccountCode').value     = d.code;
        document.getElementById('formAccountName').value     = d.name;
        document.getElementById('formAccountType').value     = d.type;
        document.getElementById('formSapGl').value           = d.sap;
        document.getElementById('formAccountCategory').value = d.category;
        document.getElementById('formParentAccount').value   = d.parent || '';
        document.getElementById('formDescription').value     = d.description;
        document.getElementById('formFinancialGroup').value  = d.group || '';
        document.getElementById('modalSubmitBtn').innerHTML  = '<i class="bi bi-save"></i> Update';

        var modal = new bootstrap.Modal(document.getElementById('addModal'));
        modal.show();
    });
});

function confirmDelete(message) {
    return confirm(message);
}
</script>

<style>
/* Custom button styles for GL Accounts */
.btn-custom-gl {
    background-color: #166c82;
    border-color: #166c82;
    color: white;
}

.btn-custom-gl:hover {
    background-color: #1a7d9a;
    border-color: #1a7d9a;
    color: white;
}

.btn-custom-gl:focus,
.btn-custom-gl:active {
    background-color: #145a6d;
    border-color: #145a6d;
    color: white;
}

/* Hover effect for action buttons in table */
.table .btn-sm:hover {
    opacity: 0.85;
    transform: scale(1.05);
    transition: all 0.2s ease;
}
</style>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob