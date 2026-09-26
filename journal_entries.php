<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = 'Journal Entries';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    
    if ($action === 'create_entry') {
        try {
            $db->beginTransaction();
            
            // Generate reference number
            $stmt = $db->query("CALL sp_generate_journal_reference(@ref_num)");
            $stmt = $db->query("SELECT @ref_num as ref_num");
            $ref_result = $stmt->fetch();
            $reference_number = $ref_result['ref_num'];
            
            // Insert journal entry header
            $stmt = $db->prepare("
                INSERT INTO journal_entries (
                    entry_date, entry_type, reference_number, description,
                    company_id, business_unit_id, division_id, block_id, 
                    planting_year_id, activity_id, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
            ");
            
            $stmt->execute([
                post('entry_date'),
                post('entry_type'),
                $reference_number,
                post('description'),
                post('company_id') ?: null,
                post('business_unit_id') ?: null,
                post('division_id') ?: null,
                post('block_id') ?: null,
                post('planting_year_id') ?: null,
                post('activity_id') ?: null,
                'Admin' // Replace with actual user
            ]);
            
            $journal_entry_id = $db->lastInsertId();
            
            // Insert journal entry lines
            $line_accounts = post('line_account', []);
            $line_debits = post('line_debit', []);
            $line_credits = post('line_credit', []);
            $line_descriptions = post('line_description', []);
            $line_cost_categories = post('line_cost_category', []);
            
            $line_number = 1;
            foreach ($line_accounts as $index => $gl_account_id) {
                if (empty($gl_account_id)) continue;
                
                $debit = floatval($line_debits[$index] ?? 0);
                $credit = floatval($line_credits[$index] ?? 0);
                
                if ($debit == 0 && $credit == 0) continue;
                
                $stmt = $db->prepare("
                    INSERT INTO journal_entry_lines (
                        journal_entry_id, line_number, gl_account_id,
                        debit_amount, credit_amount, description,
                        cost_category, cost_type,
                        company_id, business_unit_id, division_id, block_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $journal_entry_id,
                    $line_number++,
                    $gl_account_id,
                    $debit,
                    $credit,
                    $line_descriptions[$index] ?? null,
                    $line_cost_categories[$index] ?? null,
                    'direct',
                    post('company_id') ?: null,
                    post('business_unit_id') ?: null,
                    post('division_id') ?: null,
                    post('block_id') ?: null
                ]);
            }
            
            $db->commit();
            set_message("Journal entry $reference_number created successfully!", 'success');
            redirect('journal_entries.php');
            
        } catch (Exception $e) {
            $db->rollBack();
            set_message('Error creating journal entry: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'post_entry') {
        try {
            $stmt = $db->prepare("
                UPDATE journal_entries 
                SET status = 'posted', posted_date = NOW(), posted_by = ?, updated_at = NOW()
                WHERE id = ? AND status = 'draft'
            ");
            $stmt->execute(['Admin', post('entry_id')]);
            
            set_message('Journal entry posted successfully!', 'success');
            redirect('journal_entries.php');
        } catch (Exception $e) {
            set_message('Error posting entry: ' . $e->getMessage(), 'danger');
        }
    } elseif ($action === 'delete_entry') {
        try {
            $stmt = $db->prepare("DELETE FROM journal_entries WHERE id = ? AND status = 'draft'");
            $stmt->execute([post('entry_id')]);
            
            set_message('Journal entry deleted successfully!', 'success');
            redirect('journal_entries.php');
        } catch (Exception $e) {
            set_message('Error deleting entry: ' . $e->getMessage(), 'danger');
        }
    }
}

// Get filters
$status_filter = get('status', '');
$type_filter = get('type', '');
$date_from = get('date_from', '');
$date_to = get('date_to', '');
$search = get('search', '');

// Build query
$where_clauses = [];
$params = [];

if ($status_filter) {
    $where_clauses[] = "je.status = ?";
    $params[] = $status_filter;
}

if ($type_filter) {
    $where_clauses[] = "je.entry_type = ?";
    $params[] = $type_filter;
}

if ($date_from) {
    $where_clauses[] = "je.entry_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_clauses[] = "je.entry_date <= ?";
    $params[] = $date_to;
}

if ($search) {
    $where_clauses[] = "(je.reference_number LIKE ? OR je.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Fetch journal entries
$sql = "
    SELECT
        je.*,
        c.company_name,
        bu.unit_name as estate_name,
        d.division_name,
        CASE
            WHEN je.total_debit = je.total_credit THEN 'Balanced'
            ELSE 'Unbalanced'
        END as balance_status
    FROM journal_entries je
    LEFT JOIN companies c ON je.company_id = c.company_id
    LEFT JOIN business_units bu ON je.business_unit_id = bu.business_unit_id
    LEFT JOIN divisions d ON je.division_id = d.division_id
    $where_sql
    ORDER BY je.entry_date DESC, je.id DESC
    LIMIT 50
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Get statistics
$stats_stmt = $db->query("
    SELECT 
        COUNT(*) as total_entries,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
        SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) as posted_count,
        SUM(CASE WHEN total_debit != total_credit THEN 1 ELSE 0 END) as unbalanced_count
    FROM journal_entries
");
$stats = $stats_stmt->fetch();

// Get GL accounts for dropdown
$gl_accounts_stmt = $db->query("
    SELECT id, account_code, account_name, account_type
    FROM general_ledger_accounts
    WHERE is_active = TRUE
    ORDER BY account_code
");
$gl_accounts = $gl_accounts_stmt->fetchAll();

// Get user's default company, business unit, and division from session
// These were set during login from the users table
$user_company_id = $_SESSION['company_id'] ?? null;
$user_business_unit_id = $_SESSION['business_unit_id'] ?? null;
$user_division_id = $_SESSION['division_id'] ?? null;

// Check if user has fixed company/business unit/division
$has_fixed_company = !empty($user_company_id);
$has_fixed_business_unit = !empty($user_business_unit_id);
$has_fixed_division = !empty($user_division_id);

// Also update session for consistency with existing code
if ($user_company_id) {
    set_user_company_id($user_company_id);
}
if ($user_business_unit_id) {
    set_user_business_unit_id($user_business_unit_id);
}

// Get companies, business units, etc. for filters
$companies_stmt = $db->query("SELECT * FROM companies ORDER BY company_name");
$companies = $companies_stmt->fetchAll();

// Get business units (estates) with company_id
if ($has_fixed_company) {
    // Filter by user's company
    $business_units_stmt = $db->prepare("SELECT business_unit_id, unit_code, unit_name, company_id FROM business_units WHERE company_id = ? ORDER BY unit_code");
    $business_units_stmt->execute([$user_company_id]);
    $business_units = $business_units_stmt->fetchAll();
} else {
    $business_units_stmt = $db->query("SELECT business_unit_id, unit_code, unit_name, company_id FROM business_units ORDER BY unit_code");
    $business_units = $business_units_stmt->fetchAll();
}

// Get divisions with business_unit_id
if ($has_fixed_business_unit) {
    // Filter by user's business unit
    $divisions_stmt = $db->prepare("SELECT division_id, division_code, division_name, business_unit_id FROM divisions WHERE business_unit_id = ? ORDER BY division_code");
    $divisions_stmt->execute([$user_business_unit_id]);
    $divisions = $divisions_stmt->fetchAll();
} else {
    $divisions_stmt = $db->query("SELECT division_id, division_code, division_name, business_unit_id FROM divisions ORDER BY division_code");
    $divisions = $divisions_stmt->fetchAll();
}

// Get activities for line-level selection
$activities_stmt = $db->query("SELECT id, activity_code, activity_name FROM activities WHERE is_active = TRUE ORDER BY activity_code");
$activities = $activities_stmt->fetchAll();

// Get blocks for line-level selection with division_id
$blocks_stmt = $db->query("SELECT block_id, block_code, block_name, division_id FROM blocks ORDER BY block_code");
$blocks = $blocks_stmt->fetchAll();

// Convert to JSON for JavaScript
$business_units_json = json_encode($business_units);
$divisions_json = json_encode($divisions);
$blocks_json = json_encode($blocks);

require_once 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 style="color: #166c82;"><i class="bi bi-journal-text" style="color: #166c82;"></i> <?php echo $page_title; ?></h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item active">Journal Entries</li>
                </ol>
            </nav>
        </div>
    </div>

    <?php display_message(); ?>

    <!-- DEBUG INFO (Set to false to hide in production) -->
    <?php if (false): // Change to true to show debug info ?>
    <div class="alert alert-info alert-dismissible fade show">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <strong>PHP Debug Info:</strong><br>
        Current Username: <code><?php echo $current_username; ?></code><br>
        User Company ID: <code><?php echo $user_company_id ? $user_company_id : 'NOT SET'; ?></code><br>
        User Business Unit ID: <code><?php echo $user_business_unit_id ? $user_business_unit_id : 'NOT SET'; ?></code><br>
        Has Fixed Company: <code><?php echo $has_fixed_company ? 'YES' : 'NO'; ?></code><br>
        Has Fixed Business Unit: <code><?php echo $has_fixed_business_unit ? 'YES' : 'NO'; ?></code><br>
        Session ID: <code><?php echo session_id(); ?></code><br>
        Business Units Count: <code><?php echo count($business_units); ?></code><br>
        User Defaults Query Result: <code><?php echo $user_defaults ? 'FOUND' : 'NOT FOUND'; ?></code><br>
        <small>To set company: <a href="set_user_company.php" target="_blank">Click here</a> |
        To debug session: <a href="debug_session.php" target="_blank">Click here</a></small>
    </div>
    
    <!-- JavaScript Debug Log -->
    <div class="alert alert-secondary">
        <strong>JavaScript Debug Log:</strong>
        <div id="jsDebugLog" style="font-family: monospace; font-size: 12px; max-height: 150px; overflow-y: auto;">
            Waiting for JavaScript to load...<br>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white" style="background-color: #3065b0;">
                <div class="card-body">
                    <h5 class="card-title">Total Entries</h5>
                    <h2><?php echo number_format($stats['total_entries']); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Draft</h5>
                    <h2><?php echo number_format($stats['draft_count']); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Posted</h5>
                    <h2><?php echo number_format($stats['posted_count']); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5 class="card-title">Unbalanced</h5>
                    <h2><?php echo number_format($stats['unbalanced_count']); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters and New Entry Button -->
    <div class="row mb-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center text-white" style="background-color: #166c82;">
                    <span><i class="bi bi-funnel"></i> Filters</span>
                    <button type="button" class="btn btn-custom-primary" data-bs-toggle="modal" data-bs-target="#newEntryModal">
                        <i class="bi bi-plus-circle"></i> New Journal Entry
                    </button>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="draft" <?php echo $status_filter == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="posted" <?php echo $status_filter == 'posted' ? 'selected' : ''; ?>>Posted</option>
                                <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="manual">Manual</option>
                                <option value="block_cost">Block Cost</option>
                                <option value="payroll">Payroll</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date From</label>
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control form-control-sm" placeholder="Reference or description" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-sm w-100 btn-custom-filter">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Journal Entries List -->
    <div class="card">
        <div class="card-header text-white" style="background-color: #166c82;">
            <i class="bi bi-list"></i> Journal Entries (<?php echo count($entries); ?> entries)
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th style="width: 10%;">Date</th>
                            <th style="width: 12%;">Reference</th>
                            <th style="width: 10%;">Type</th>
                            <th style="width: 25%;">Description</th>
                            <th style="width: 10%;">Block</th>
                            <th class="text-end" style="width: 10%;">Debit</th>
                            <th class="text-end" style="width: 10%;">Credit</th>
                            <th style="width: 8%;">Status</th>
                            <th class="text-center" style="width: 5%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entries)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">No journal entries found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($entries as $entry): ?>
                                <tr class="<?php echo $entry['balance_status'] == 'Unbalanced' ? 'table-danger' : ''; ?>">
                                    <td><?php echo date('d M Y', strtotime($entry['entry_date'])); ?></td>
                                    <td>
                                        <a href="journal_entry_detail.php?id=<?php echo $entry['id']; ?>" class="text-decoration-none">
                                            <code><?php echo htmlspecialchars($entry['reference_number']); ?></code>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo ucfirst(str_replace('_', ' ', $entry['entry_type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(substr($entry['description'], 0, 50)); ?>
                                        <?php if (strlen($entry['description']) > 50) echo '...'; ?>
                                    </td>
                                    <td>
                                        <span class="text-muted">-</span>
                                    </td>
                                    <td class="text-end">Rp <?php echo format_number($entry['total_debit'], 0); ?></td>
                                    <td class="text-end">Rp <?php echo format_number($entry['total_credit'], 0); ?></td>
                                    <td>
                                        <?php
                                        $status_colors = [
                                            'draft' => 'warning',
                                            'posted' => 'success',
                                            'approved' => 'primary',
                                            'cancelled' => 'danger'
                                        ];
                                        $color = $status_colors[$entry['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>">
                                            <?php echo ucfirst($entry['status']); ?>
                                        </span>
                                        <?php if ($entry['balance_status'] == 'Unbalanced'): ?>
                                            <br><small class="text-danger">Unbalanced!</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($entry['status'] == 'draft'): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Post this entry?');">
                                                <input type="hidden" name="action" value="post_entry">
                                                <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                <button type="submit" class="btn btn-sm" style="background-color: #166c82; color: white;" title="Post Entry">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this entry?');">
                                                <input type="hidden" name="action" value="delete_entry">
                                                <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <a href="journal_entry_detail.php?id=<?php echo $entry['id']; ?>" class="btn btn-sm btn-info" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        <?php endif; ?>
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

<!-- New Journal Entry Modal -->
<div class="modal fade" id="newEntryModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" id="journalEntryForm">
                <input type="hidden" name="action" value="create_entry">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> New Journal Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <!-- Header Information -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Entry Date <span class="text-danger">*</span></label>
                            <input type="date" name="entry_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Entry Type <span class="text-danger">*</span></label>
                            <select name="entry_type" class="form-select" required>
                                <option value="manual">Manual Entry</option>
                                <option value="block_cost">Block Cost</option>
                                <option value="payroll">Payroll</option>
                                <option value="adjustment">Adjustment</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" name="description" class="form-control" required>
                        </div>
                    </div>

                    <!-- Optional Dimensions -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Company <?php if ($has_fixed_company): ?><span class="text-muted">(Fixed)</span><?php endif; ?></label>
                            <select name="company_id" id="company_id" class="form-select" <?php echo $has_fixed_company ? 'disabled' : ''; ?>>
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo $company['company_id']; ?>"
                                        <?php echo ($has_fixed_company && $company['company_id'] == $user_company_id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($company['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($has_fixed_company): ?>
                                <!-- Hidden field to ensure company_id is submitted when select is disabled -->
                                <input type="hidden" name="company_id" value="<?php echo $user_company_id; ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Business Unit <?php if ($has_fixed_business_unit): ?><span class="text-muted">(Fixed)</span><?php endif; ?></label>
                            <select name="business_unit_id" id="business_unit_id" class="form-select" <?php echo $has_fixed_business_unit ? 'disabled' : ''; ?>>
                                <option value="">Select Business Unit</option>
                                <?php foreach ($business_units as $bu): ?>
                                    <option value="<?php echo $bu['business_unit_id']; ?>"
                                        data-company-id="<?php echo $bu['company_id']; ?>"
                                        <?php echo ($user_business_unit_id && $bu['business_unit_id'] == $user_business_unit_id) ? 'selected' : ''; ?>>
                                        <?php echo $bu['unit_code']; ?> - <?php echo htmlspecialchars($bu['unit_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($has_fixed_business_unit): ?>
                                <!-- Hidden field to ensure business_unit_id is submitted when select is disabled -->
                                <input type="hidden" name="business_unit_id" value="<?php echo $user_business_unit_id; ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Division <?php if ($has_fixed_division): ?><span class="text-muted">(Fixed)</span><?php endif; ?></label>
                            <select name="division_id" id="division_id" class="form-select" <?php echo $has_fixed_division ? 'disabled' : ''; ?>>
                                <option value="">Select Division</option>
                                <?php foreach ($divisions as $div): ?>
                                    <option value="<?php echo $div['division_id']; ?>"
                                        data-business-unit-id="<?php echo $div['business_unit_id']; ?>"
                                        <?php echo ($user_division_id && $div['division_id'] == $user_division_id) ? 'selected' : ''; ?>>
                                        <?php echo $div['division_code']; ?> - <?php echo htmlspecialchars($div['division_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($has_fixed_division): ?>
                                <!-- Hidden field to ensure division_id is submitted when select is disabled -->
                                <input type="hidden" name="division_id" value="<?php echo $user_division_id; ?>">
                            <?php endif; ?>
                        </div>
                    </div>

                    <hr>

                    <!-- Journal Entry Lines -->
                    <h6>Journal Entry Lines</h6>
                    <div id="journalLines">
                        <!-- Line 1 -->
                        <div class="row mb-3 journal-line border-bottom pb-2">
                            <div class="col-md-4">
                                <label class="form-label-sm">GL Account <span class="text-danger">*</span></label>
                                <select name="line_account[]" class="form-select form-select-sm" required>
                                    <option value="">Select GL Account</option>
                                    <?php foreach ($gl_accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>">
                                            <?php echo $account['account_code']; ?> - <?php echo htmlspecialchars($account['account_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label-sm">Debit</label>
                                <input type="number" name="line_debit[]" class="form-control form-control-sm debit-input" placeholder="0.00" step="0.01" min="0">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label-sm">Credit</label>
                                <input type="number" name="line_credit[]" class="form-control form-control-sm credit-input" placeholder="0.00" step="0.01" min="0">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label-sm">Cost Category</label>
                                <select name="line_cost_category[]" class="form-select form-select-sm">
                                    <option value="">Select Category</option>
                                    <option value="labor">Labor</option>
                                    <option value="material">Material</option>
                                    <option value="vehicle_equipment">Vehicle/Equipment</option>
                                    <option value="overhead">Overhead</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <label class="form-label-sm">&nbsp;</label>
                                <button type="button" class="btn btn-sm btn-danger remove-line d-block" disabled>
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <div class="col-md-4 mt-2">
                                <label class="form-label-sm">Activity</label>
                                <select name="line_activity[]" class="form-select form-select-sm">
                                    <option value="">Select Activity</option>
                                    <?php foreach ($activities as $activity): ?>
                                        <option value="<?php echo $activity['id']; ?>">
                                            <?php echo $activity['activity_code']; ?> - <?php echo htmlspecialchars($activity['activity_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mt-2">
                                <label class="form-label-sm">Block</label>
                                <select name="line_block[]" class="form-select form-select-sm block-select">
                                    <option value="">Select Block</option>
                                    <?php foreach ($blocks as $block): ?>
                                        <option value="<?php echo $block['block_id']; ?>" data-division-id="<?php echo $block['division_id']; ?>">
                                            <?php echo $block['block_code']; ?> - <?php echo htmlspecialchars($block['block_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mt-2">
                                <label class="form-label-sm">Description</label>
                                <input type="text" name="line_description[]" class="form-control form-control-sm" placeholder="Line description (optional)">
                            </div>
                        </div>
                    </div>

                    <button type="button" class="btn btn-sm btn-secondary" id="addLineBtn">
                        <i class="bi bi-plus"></i> Add Line
                    </button>

                    <!-- Totals -->
                    <div class="row mt-3">
                        <div class="col-md-6 offset-md-3">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Total Debit:</strong></td>
                                    <td class="text-end"><strong id="totalDebit">Rp 0</strong></td>
                                </tr>
                                <tr>
                                    <td><strong>Total Credit:</strong></td>
                                    <td class="text-end"><strong id="totalCredit">Rp 0</strong></td>
                                </tr>
                                <tr class="table-info">
                                    <td><strong>Difference:</strong></td>
                                    <td class="text-end"><strong id="difference">Rp 0</strong></td>
                                </tr>
                            </table>
                            <div id="balanceWarning" class="alert alert-warning d-none">
                                <i class="bi bi-exclamation-triangle"></i> Entry is not balanced! Debit must equal Credit.
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background-color: #166c82; color: white;" id="submitBtn">
                        <i class="bi bi-save"></i> Create Journal Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Debug logging function (only active when debug mode is on)
function debugLog(message) {
    const debugDiv = document.getElementById('jsDebugLog');
    if (debugDiv) {
        const time = new Date().toLocaleTimeString();
        debugDiv.innerHTML += `[${time}] ${message}<br>`;
    }
}

// Data from PHP
const businessUnitsData = <?php echo $business_units_json; ?>;
const divisionsData = <?php echo $divisions_json; ?>;
const blocksData = <?php echo $blocks_json; ?>;
const hasFixedCompany = <?php echo $has_fixed_company ? 'true' : 'false'; ?>;
const userCompanyId = <?php echo $user_company_id ? "'" . $user_company_id . "'" : 'null'; ?>;
const hasFixedBusinessUnit = <?php echo $has_fixed_business_unit ? 'true' : 'false'; ?>;
const userBusinessUnitId = <?php echo $user_business_unit_id ? "'" . $user_business_unit_id . "'" : 'null'; ?>;
const hasFixedDivision = <?php echo $has_fixed_division ? 'true' : 'false'; ?>;
const userDivisionId = <?php echo $user_division_id ? "'" . $user_division_id . "'" : 'null'; ?>;

// Cascading Dropdown Filters for Modal Form
document.addEventListener('DOMContentLoaded', function() {
    const companySelect = document.getElementById('company_id');
    const estateSelect = document.getElementById('business_unit_id');
    const divisionSelect = document.getElementById('division_id');
    
    if (!companySelect || !estateSelect || !divisionSelect) {
        console.error('One or more dropdowns not found!');
        return;
    }
    
    // Store original options
    const allEstates = Array.from(estateSelect.options).slice(1); // Skip first "Select Estate" option
    const allDivisions = Array.from(divisionSelect.options).slice(1); // Skip first "Select Division" option
    
    // Function to filter estates based on company
    function filterEstatesByCompany(companyId, preserveSelection) {
        // Save current selection if we need to preserve it
        const currentValue = preserveSelection ? estateSelect.value : '';
        
        // Clear and reset estate dropdown
        estateSelect.innerHTML = '<option value="">Select Business Unit</option>';
        
        if (companyId) {
            // Filter and add estates for selected company
            allEstates.forEach(option => {
                if (option.dataset.companyId === companyId) {
                    estateSelect.appendChild(option.cloneNode(true));
                }
            });
        } else {
            // Show all estates if no company selected
            allEstates.forEach(option => {
                estateSelect.appendChild(option.cloneNode(true));
            });
        }
        
        // Restore selection if preserving
        if (preserveSelection && currentValue) {
            estateSelect.value = currentValue;
        }
        
        // Reset division dropdown only if not preserving selection
        if (!preserveSelection) {
            divisionSelect.innerHTML = '<option value="">Select Division</option>';
            divisionSelect.value = '';
            allDivisions.forEach(option => {
                divisionSelect.appendChild(option.cloneNode(true));
            });
        }
    }
    
    // Filter estates based on selected company
    companySelect.addEventListener('change', function() {
        filterEstatesByCompany(this.value, false);
    });
    
    // Function to filter divisions based on business unit
    function filterDivisionsByBusinessUnit(businessUnitId) {
        // Clear and reset division dropdown
        divisionSelect.innerHTML = '<option value="">Select Division</option>';
        divisionSelect.value = '';
        
        if (businessUnitId) {
            // Filter and add divisions for selected business unit
            allDivisions.forEach(option => {
                if (option.dataset.businessUnitId === businessUnitId) {
                    divisionSelect.appendChild(option.cloneNode(true));
                }
            });
        } else {
            // Show all divisions if no business unit selected
            allDivisions.forEach(option => {
                divisionSelect.appendChild(option.cloneNode(true));
            });
        }
    }
    
    // Filter divisions based on selected estate
    estateSelect.addEventListener('change', function() {
        filterDivisionsByBusinessUnit(this.value);
    });
    
    // Function to filter blocks based on division
    function filterBlocksByDivision(divisionId) {
        // Get all block select elements in journal lines
        const blockSelects = document.querySelectorAll('.block-select');
        
        blockSelects.forEach(blockSelect => {
            // Save current selection
            const currentValue = blockSelect.value;
            
            // Get all options except the first one (placeholder)
            const allOptions = Array.from(blockSelect.options).slice(1);
            
            // Clear and reset block dropdown
            blockSelect.innerHTML = '<option value="">Select Block</option>';
            
            if (divisionId) {
                // Filter and add blocks for selected division
                allOptions.forEach(option => {
                    if (option.dataset.divisionId === divisionId) {
                        blockSelect.appendChild(option.cloneNode(true));
                    }
                });
            } else {
                // Show all blocks if no division selected
                allOptions.forEach(option => {
                    blockSelect.appendChild(option.cloneNode(true));
                });
            }
            
            // Try to restore selection if it's still valid
            const optionExists = Array.from(blockSelect.options).some(opt => opt.value === currentValue);
            if (optionExists) {
                blockSelect.value = currentValue;
            }
        });
    }
    
    // Filter blocks when division changes
    divisionSelect.addEventListener('change', function() {
        filterBlocksByDivision(this.value);
    });
    
    // Initialize filters and selections on page load
    if (hasFixedCompany && userCompanyId) {
        // Filter estates by company, preserving business unit selection
        filterEstatesByCompany(userCompanyId, true);
    }
    
    // Always trigger division filter if business unit is pre-selected (whether fixed or not)
    if (userBusinessUnitId) {
        setTimeout(() => {
            // Ensure the business unit is selected
            if (estateSelect.value === userBusinessUnitId) {
                // Already selected by PHP, just trigger the filter
                filterDivisionsByBusinessUnit(userBusinessUnitId);
            } else {
                // Try to select it if it exists in the dropdown
                const optionExists = Array.from(estateSelect.options).some(opt => opt.value === userBusinessUnitId);
                if (optionExists) {
                    estateSelect.value = userBusinessUnitId;
                    filterDivisionsByBusinessUnit(userBusinessUnitId);
                }
            }
        }, 100);
    }
    
    // Always trigger block filter if division is pre-selected (whether fixed or not)
    if (userDivisionId) {
        setTimeout(() => {
            // Ensure the division is selected
            if (divisionSelect.value === userDivisionId) {
                // Already selected by PHP, just trigger the filter
                filterBlocksByDivision(userDivisionId);
            } else {
                // Try to select it if it exists in the dropdown
                const optionExists = Array.from(divisionSelect.options).some(opt => opt.value === userDivisionId);
                if (optionExists) {
                    divisionSelect.value = userDivisionId;
                    filterBlocksByDivision(userDivisionId);
                }
            }
        }, 150);
    }
});

// Add new line
document.getElementById('addLineBtn').addEventListener('click', function() {
    const container = document.getElementById('journalLines');
    const firstLine = container.querySelector('.journal-line');
    const newLine = firstLine.cloneNode(true);
    
    // Clear values
    newLine.querySelectorAll('input, select').forEach(input => {
        if (input.type === 'number') {
            input.value = '';
        } else if (input.tagName === 'SELECT') {
            input.selectedIndex = 0;
        } else {
            input.value = '';
        }
    });
    
    // Enable remove button
    newLine.querySelector('.remove-line').disabled = false;
    
    container.appendChild(newLine);
    updateRemoveButtons();
    calculateTotals();
    
    // Apply current division filter to the new line's block select
    const divisionSelect = document.getElementById('division_id');
    if (divisionSelect && divisionSelect.value) {
        filterBlocksByDivision(divisionSelect.value);
    }
});

// Remove line
document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-line')) {
        e.target.closest('.journal-line').remove();
        updateRemoveButtons();
        calculateTotals();
    }
});

// Update remove buttons
function updateRemoveButtons() {
    const lines = document.querySelectorAll('.journal-line');
    lines.forEach((line, index) => {
        const removeBtn = line.querySelector('.remove-line');
        removeBtn.disabled = (lines.length === 1);
    });
}

// Calculate totals
document.addEventListener('input', function(e) {
    if (e.target.classList.contains('debit-input') || e.target.classList.contains('credit-input')) {
        calculateTotals();
    }
});

function calculateTotals() {
    let totalDebit = 0;
    let totalCredit = 0;
    
    document.querySelectorAll('.debit-input').forEach(input => {
        totalDebit += parseFloat(input.value) || 0;
    });
    
    document.querySelectorAll('.credit-input').forEach(input => {
        totalCredit += parseFloat(input.value) || 0;
    });
    
    const difference = Math.abs(totalDebit - totalCredit);
    
    document.getElementById('totalDebit').textContent = 'Rp ' + totalDebit.toLocaleString('id-ID', {minimumFractionDigits: 2});
    document.getElementById('totalCredit').textContent = 'Rp ' + totalCredit.toLocaleString('id-ID', {minimumFractionDigits: 2});
    document.getElementById('difference').textContent = 'Rp ' + difference.toLocaleString('id-ID', {minimumFractionDigits: 2});
    
    const warning = document.getElementById('balanceWarning');
    const submitBtn = document.getElementById('submitBtn');
    
    if (difference > 0.01) {
        warning.classList.remove('d-none');
        submitBtn.disabled = true;
    } else {
        warning.classList.add('d-none');
        submitBtn.disabled = false;
    }
}

// Initial calculation
calculateTotals();
</script>

<style>
/* Custom button styles for #166c82 color */
.btn-custom-primary {
    background-color: #166c82;
    border-color: #166c82;
    color: white;
}

.btn-custom-primary:hover {
    background-color: #1a7d9a;
    border-color: #1a7d9a;
    color: white;
}

.btn-custom-primary:focus,
.btn-custom-primary:active {
    background-color: #145a6d;
    border-color: #145a6d;
    color: white;
}

/* Custom filter button styles */
.btn-custom-filter {
    background-color: #166c82;
    border-color: #166c82;
    color: white;
}

.btn-custom-filter:hover {
    background-color: #1a7d9a;
    border-color: #1a7d9a;
    color: white;
}

.btn-custom-filter:focus,
.btn-custom-filter:active {
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
