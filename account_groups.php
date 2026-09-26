<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// ── Active tab (groups | mapping) ────────────────────────────────────────────
$tab = get('tab', 'groups');
if (!in_array($tab, ['groups', 'mapping'])) $tab = 'groups';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // ── Add group ──────────────────────────────────────────────────────────────
    if ($action === 'add_group') {
        try {
            $stmt = $db->prepare("
                INSERT INTO financial_account_groups
                    (group_code, group_name, report_type, report_section, parent_group_id,
                     display_order, is_total_line, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, NOW(), NOW())
            ");
            $stmt->execute([
                post('group_code'),
                post('group_name'),
                post('report_type'),
                post('report_section'),
                post('parent_group_id') ?: null,
                post('display_order') ?: 0,
                post('is_total_line') ? true : false,
            ]);
            set_message('Account group added successfully!', 'success');
            redirect('account_groups.php?tab=groups');
        } catch (Exception $e) {
            set_message('Error adding account group: ' . $e->getMessage(), 'danger');
        }
    }

    // ── Update group ───────────────────────────────────────────────────────────
    elseif ($action === 'update_group') {
        try {
            $stmt = $db->prepare("
                UPDATE financial_account_groups SET
                    group_code      = ?,
                    group_name      = ?,
                    report_type     = ?,
                    report_section  = ?,
                    parent_group_id = ?,
                    display_order   = ?,
                    is_total_line   = ?,
                    is_active       = ?,
                    updated_at      = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                post('group_code'),
                post('group_name'),
                post('report_type'),
                post('report_section'),
                post('parent_group_id') ?: null,
                post('display_order') ?: 0,
                post('is_total_line') ? true : false,
                post('is_active') ? true : false,
                post('group_id'),
            ]);
            set_message('Account group updated successfully!', 'success');
            redirect('account_groups.php?tab=groups');
        } catch (Exception $e) {
            set_message('Error updating account group: ' . $e->getMessage(), 'danger');
        }
    }

    // ── Delete group ───────────────────────────────────────────────────────────
    elseif ($action === 'delete_group') {
        try {
            // Unlink GL accounts that point to this group first
            $db->prepare("UPDATE general_ledger_accounts SET financial_group_id = NULL WHERE financial_group_id = ?")
               ->execute([post('group_id')]);
            $db->prepare("DELETE FROM financial_account_groups WHERE id = ?")
               ->execute([post('group_id')]);
            set_message('Account group deleted successfully.', 'success');
            redirect('account_groups.php?tab=groups');
        } catch (Exception $e) {
            set_message('Error deleting account group: ' . $e->getMessage(), 'danger');
        }
    }

    // ── Save mapping (bulk update financial_group_id per GL account) ───────────
    elseif ($action === 'save_mapping') {
        try {
            $mappings = $_POST['mapping'] ?? []; // array [gl_account_id => group_id]
            $stmt = $db->prepare("UPDATE general_ledger_accounts SET financial_group_id = ?, updated_at = NOW() WHERE id = ?");
            foreach ($mappings as $gl_id => $grp_id) {
                $stmt->execute([$grp_id ?: null, (int)$gl_id]);
            }
            set_message('Account mapping saved successfully!', 'success');
            redirect('account_groups.php?tab=mapping');
        } catch (Exception $e) {
            set_message('Error saving mapping: ' . $e->getMessage(), 'danger');
        }
    }
}

// ── Load edit record ──────────────────────────────────────────────────────────
$edit_group = null;
if (isset($_GET['edit_group'])) {
    $s = $db->prepare("SELECT * FROM financial_account_groups WHERE id = ?");
    $s->execute([$_GET['edit_group']]);
    $edit_group = $s->fetch();
}

// ── Fetch all groups ──────────────────────────────────────────────────────────
$all_groups = $db->query("
    SELECT g.*, p.group_name AS parent_name
    FROM financial_account_groups g
    LEFT JOIN financial_account_groups p ON p.id = g.parent_group_id
    ORDER BY g.report_type, g.display_order, g.group_code
")->fetchAll();

// ── Fetch GL accounts with their current group assignment ─────────────────────
$gl_accounts = $db->query("
    SELECT gla.id, gla.account_code, gla.account_name, gla.account_type, gla.is_active,
           gla.financial_group_id,
           fag.group_name AS current_group
    FROM general_ledger_accounts gla
    LEFT JOIN financial_account_groups fag ON fag.id = gla.financial_group_id
    ORDER BY gla.account_code
")->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$total_groups   = count($all_groups);
$active_groups  = count(array_filter($all_groups, fn($g) => $g['is_active']));
$unmapped_gl    = count(array_filter($gl_accounts, fn($a) => !$a['financial_group_id']));

// Options for parent group select (exclude self on edit)
$edit_id = $edit_group ? $edit_group['id'] : null;
$parent_options = array_filter($all_groups, fn($g) => $g['id'] != $edit_id);

$page_title = "Account Groups";
require_once 'includes/header.php';
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-collection"></i> Account Groups</h1>
            <p class="text-muted">Manage financial account groups and GL account mapping for reports</p>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card stat-card border-primary">
            <div class="card-body text-center">
                <h3 class="text-primary"><?php echo $total_groups; ?></h3>
                <p class="mb-0">Total Groups</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card border-success">
            <div class="card-body text-center">
                <h3 class="text-success"><?php echo $active_groups; ?></h3>
                <p class="mb-0">Active Groups</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card <?php echo $unmapped_gl ? 'border-warning' : 'border-info'; ?>">
            <div class="card-body text-center">
                <h3 class="<?php echo $unmapped_gl ? 'text-warning' : 'text-info'; ?>"><?php echo $unmapped_gl; ?></h3>
                <p class="mb-0">Unmapped GL Accounts</p>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="accountGroupsTabs">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'groups' ? 'active' : ''; ?>" href="account_groups.php?tab=groups">
            <i class="bi bi-folder2-open"></i> Groups
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'mapping' ? 'active' : ''; ?>" href="account_groups.php?tab=mapping">
            <i class="bi bi-diagram-2"></i> GL Mapping
            <?php if ($unmapped_gl): ?>
                <span class="badge bg-warning text-dark ms-1"><?php echo $unmapped_gl; ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'groups'): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: GROUPS                                                                  -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="row">
    <!-- Form panel -->
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-<?php echo $edit_group ? 'pencil-square' : 'plus-circle'; ?>"></i>
                <?php echo $edit_group ? 'Edit' : 'Add'; ?> Account Group
            </div>
            <div class="card-body">
                <form method="POST" action="account_groups.php">
                    <input type="hidden" name="action" value="<?php echo $edit_group ? 'update_group' : 'add_group'; ?>">
                    <?php if ($edit_group): ?>
                        <input type="hidden" name="group_id" value="<?php echo $edit_group['id']; ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Group Code <span class="text-danger">*</span></label>
                        <input type="text" name="group_code" class="form-control"
                               value="<?php echo htmlspecialchars($edit_group['group_code'] ?? ''); ?>"
                               placeholder="e.g., CURR_ASSETS" required maxlength="30">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Group Name <span class="text-danger">*</span></label>
                        <input type="text" name="group_name" class="form-control"
                               value="<?php echo htmlspecialchars($edit_group['group_name'] ?? ''); ?>"
                               placeholder="e.g., Current Assets" required maxlength="150">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Report Type <span class="text-danger">*</span></label>
                        <select name="report_type" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach (['balance_sheet' => 'Balance Sheet', 'profit_loss' => 'Profit & Loss'] as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>" <?php echo (($edit_group['report_type'] ?? '') == $val) ? 'selected' : ''; ?>>
                                    <?php echo $lbl; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Report Section <span class="text-danger">*</span></label>
                        <select name="report_section" class="form-select" required>
                            <option value="">-- Select --</option>
                            <optgroup label="Balance Sheet">
                                <?php foreach (['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity'] as $v => $l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo (($edit_group['report_section'] ?? '') == $v) ? 'selected' : ''; ?>><?php echo $l; ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Profit &amp; Loss">
                                <?php foreach (['revenue' => 'Revenue', 'cogs' => 'COGS', 'operating_expense' => 'Operating Expense', 'depreciation' => 'Depreciation', 'other_income' => 'Other Income', 'other_expenses' => 'Other Expenses', 'tax' => 'Tax'] as $v => $l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo (($edit_group['report_section'] ?? '') == $v) ? 'selected' : ''; ?>><?php echo $l; ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Parent Group</label>
                        <select name="parent_group_id" class="form-select">
                            <option value="">-- None (top level) --</option>
                            <?php foreach ($parent_options as $pg): ?>
                                <option value="<?php echo $pg['id']; ?>"
                                    <?php echo (($edit_group['parent_group_id'] ?? null) == $pg['id']) ? 'selected' : ''; ?>>
                                    [<?php echo htmlspecialchars($pg['report_type']); ?>]
                                    <?php echo htmlspecialchars($pg['group_code'] . ' – ' . $pg['group_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" class="form-control"
                               value="<?php echo $edit_group['display_order'] ?? 0; ?>" min="0" max="9999">
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_total_line" class="form-check-input" id="chkTotalLine"
                               value="1" <?php echo ($edit_group['is_total_line'] ?? false) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chkTotalLine">Is total/subtotal line</label>
                        <div class="form-text">Total lines are skipped in grouped display and computed automatically.</div>
                    </div>

                    <?php if ($edit_group): ?>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_active" class="form-check-input" id="chkActive"
                               value="1" <?php echo ($edit_group['is_active'] ?? true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="chkActive">Active</label>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-<?php echo $edit_group ? 'check-circle' : 'plus-circle'; ?>"></i>
                            <?php echo $edit_group ? 'Update Group' : 'Add Group'; ?>
                        </button>
                        <?php if ($edit_group): ?>
                            <a href="account_groups.php?tab=groups" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Groups table -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table"></i> Account Groups</span>
                <span class="badge bg-light text-dark"><?php echo $total_groups; ?> groups</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($all_groups)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-inbox fs-2"></i>
                        <p class="mt-2">No account groups yet. Add one using the form on the left.</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Report</th>
                                <th>Section</th>
                                <th>Parent</th>
                                <th class="text-center">Ord</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_groups as $i => $g): ?>
                            <tr class="<?php echo $i % 2 === 0 ? 'table-row-even' : 'table-row-odd'; ?>">
                                <td><code><?php echo htmlspecialchars($g['group_code']); ?></code></td>
                                <td><?php echo htmlspecialchars($g['group_name']); ?></td>
                                <td>
                                    <span class="badge <?php echo $g['report_type'] === 'balance_sheet' ? 'bg-primary' : 'bg-success'; ?>">
                                        <?php echo $g['report_type'] === 'balance_sheet' ? 'BS' : 'PL'; ?>
                                    </span>
                                </td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($g['report_section']); ?></small></td>
                                <td><small><?php echo $g['parent_name'] ? htmlspecialchars($g['parent_name']) : '<span class="text-muted">—</span>'; ?></small></td>
                                <td class="text-center"><?php echo (int)$g['display_order']; ?></td>
                                <td class="text-center">
                                    <?php if ($g['is_total_line']): ?>
                                        <i class="bi bi-check-circle-fill text-warning" title="Total line"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $g['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $g['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="account_groups.php?tab=groups&edit_group=<?php echo $g['id']; ?>"
                                       class="btn btn-sm btn-outline-primary me-1" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="account_groups.php" style="display:inline;"
                                          onsubmit="return confirm('Delete group \'<?php echo addslashes($g['group_name']); ?>\'? All GL accounts mapped to this group will be unmapped.');">
                                        <input type="hidden" name="action" value="delete_group">
                                        <input type="hidden" name="group_id" value="<?php echo $g['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'mapping'): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: GL MAPPING                                                              -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<?php if ($unmapped_gl): ?>
<div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
    <div><?php echo $unmapped_gl; ?> GL account(s) are not mapped to any group and will be excluded from grouped financial reports.</div>
</div>
<?php endif; ?>

<?php if (empty($all_groups)): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    No account groups exist yet. <a href="account_groups.php?tab=groups">Add groups first</a> before mapping GL accounts.
</div>
<?php else: ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-2"></i> GL Account → Group Mapping</span>
        <span class="badge bg-light text-dark"><?php echo count($gl_accounts); ?> GL accounts</span>
    </div>
    <div class="card-body p-0">
        <form method="POST" action="account_groups.php">
            <input type="hidden" name="action" value="save_mapping">

            <?php if (empty($gl_accounts)): ?>
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-inbox fs-2"></i>
                    <p class="mt-2">No GL accounts found in <code>general_ledger_accounts</code>.</p>
                </div>
            <?php else: ?>
            <!-- Quick filter -->
            <div class="p-3 border-bottom">
                <div class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <input type="text" id="glSearchInput" class="form-control form-control-sm"
                               placeholder="Filter by code or name…">
                    </div>
                    <div class="col-md-3">
                        <select id="glTypeFilter" class="form-select form-select-sm">
                            <option value="">All account types</option>
                            <?php
                            $types = array_unique(array_column($gl_accounts, 'account_type'));
                            sort($types);
                            foreach ($types as $t): ?>
                                <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select id="glMappedFilter" class="form-select form-select-sm">
                            <option value="">All mapping status</option>
                            <option value="unmapped">Unmapped only</option>
                            <option value="mapped">Mapped only</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0" id="mappingTable">
                    <thead>
                        <tr>
                            <th>Account Code</th>
                            <th>Account Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th style="min-width:220px;">Assigned Group</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gl_accounts as $i => $acc): ?>
                        <tr class="<?php echo $i % 2 === 0 ? 'table-row-even' : 'table-row-odd'; ?>"
                            data-code="<?php echo strtolower($acc['account_code']); ?>"
                            data-name="<?php echo strtolower($acc['account_name']); ?>"
                            data-type="<?php echo htmlspecialchars($acc['account_type']); ?>"
                            data-mapped="<?php echo $acc['financial_group_id'] ? 'mapped' : 'unmapped'; ?>">
                            <td><code><?php echo htmlspecialchars($acc['account_code']); ?></code></td>
                            <td><?php echo htmlspecialchars($acc['account_name']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($acc['account_type']); ?></span></td>
                            <td>
                                <span class="badge <?php echo $acc['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo $acc['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <select name="mapping[<?php echo $acc['id']; ?>]" class="form-select form-select-sm">
                                    <option value="">— Unmapped —</option>
                                    <?php
                                    // Group options by report_type
                                    $grouped_opts = [];
                                    foreach ($all_groups as $g) {
                                        $grouped_opts[$g['report_type']][] = $g;
                                    }
                                    $labels = ['balance_sheet' => 'Balance Sheet', 'profit_loss' => 'Profit & Loss'];
                                    foreach ($grouped_opts as $rtype => $rgroups):
                                    ?>
                                    <optgroup label="<?php echo $labels[$rtype] ?? $rtype; ?>">
                                        <?php foreach ($rgroups as $g): ?>
                                        <option value="<?php echo $g['id']; ?>"
                                            <?php echo ($acc['financial_group_id'] == $g['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($g['group_code'] . ' – ' . $g['group_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="p-3 border-top">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Mapping
                </button>
                <span class="text-muted ms-3 small">Changes apply to all grouped financial reports immediately.</span>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
(function () {
    const searchInput  = document.getElementById('glSearchInput');
    const typeFilter   = document.getElementById('glTypeFilter');
    const mappedFilter = document.getElementById('glMappedFilter');
    const rows = document.querySelectorAll('#mappingTable tbody tr');

    function applyFilters() {
        const q    = searchInput.value.toLowerCase();
        const type = typeFilter.value;
        const mp   = mappedFilter.value;

        rows.forEach(function(row) {
            const matchQ  = !q    || row.dataset.code.includes(q) || row.dataset.name.includes(q);
            const matchT  = !type || row.dataset.type  === type;
            const matchM  = !mp   || row.dataset.mapped === mp;
            row.style.display = (matchQ && matchT && matchM) ? '' : 'none';
        });
    }

    searchInput.addEventListener('input',  applyFilters);
    typeFilter .addEventListener('change', applyFilters);
    mappedFilter.addEventListener('change', applyFilters);
})();
</script>
<?php endif; ?>

<?php endif; // end tabs ?>

<?php require_once 'includes/footer.php'; ?>
