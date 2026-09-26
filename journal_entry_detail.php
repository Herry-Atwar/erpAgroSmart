<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = 'Journal Entry Detail';

// Get journal entry ID
$entry_id = get('id');
if (!$entry_id) {
    set_message('Journal entry ID is required', 'danger');
    redirect('journal_entries.php');
}

// Fetch journal entry header
$stmt = $db->prepare("
    SELECT
        je.*,
        c.company_name,
        bu.unit_name as estate_name,
        d.division_name,
        b.block_code,
        a.activity_name,
        a.activity_code,
        CASE
            WHEN je.total_debit = je.total_credit THEN 'Balanced'
            ELSE 'Unbalanced'
        END as balance_status,
        ABS(je.total_debit - je.total_credit) as balance_difference
    FROM journal_entries je
    LEFT JOIN companies c ON je.company_id = c.company_id
    LEFT JOIN business_units bu ON je.business_unit_id = bu.business_unit_id
    LEFT JOIN divisions d ON je.division_id = d.division_id
    LEFT JOIN blocks b ON je.block_id = b.block_id
    LEFT JOIN activities a ON je.activity_id = a.id
    WHERE je.id = ?
");
$stmt->execute([$entry_id]);
$entry = $stmt->fetch();

if (!$entry) {
    set_message('Journal entry not found', 'danger');
    redirect('journal_entries.php');
}

// Fetch journal entry lines
$lines_stmt = $db->prepare("
    SELECT 
        jel.*,
        gla.account_code,
        gla.account_name,
        gla.account_type,
        gla.account_category,
        b.block_code as line_block_code,
        a.activity_name as line_activity_name,
        e.name as worker_name
    FROM journal_entry_lines jel
    INNER JOIN general_ledger_accounts gla ON jel.gl_account_id = gla.id
    LEFT JOIN blocks b ON jel.block_id = b.block_id
    LEFT JOIN activities a ON jel.activity_id = a.id
    LEFT JOIN hr_employee e ON jel.worker_id = e.id
    WHERE jel.journal_entry_id = ?
    ORDER BY jel.line_number
");
$lines_stmt->execute([$entry_id]);
$lines = $lines_stmt->fetchAll();

require_once 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-journal-text"></i> Journal Entry Detail</h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="journal_entries.php">Journal Entries</a></li>
                            <li class="breadcrumb-item active"><?php echo htmlspecialchars($entry['reference_number']); ?></li>
                        </ol>
                    </nav>
                </div>
                <div>
                    <a href="journal_entries.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                    <?php if ($entry['status'] == 'draft'): ?>
                        <a href="journal_entries.php?edit=<?php echo $entry['id']; ?>" class="btn btn-warning">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php display_message(); ?>

    <!-- Journal Entry Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-info-circle"></i> Journal Entry Information</span>
                    <?php
                    $status_colors = [
                        'draft' => 'warning',
                        'posted' => 'success',
                        'approved' => 'primary',
                        'cancelled' => 'danger'
                    ];
                    $color = $status_colors[$entry['status']] ?? 'secondary';
                    ?>
                    <span class="badge bg-<?php echo $color; ?> fs-6">
                        <?php echo strtoupper($entry['status']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Left Column -->
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td width="40%" class="text-muted"><strong>Reference Number:</strong></td>
                                    <td><code class="fs-5"><?php echo htmlspecialchars($entry['reference_number']); ?></code></td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><strong>Entry Date:</strong></td>
                                    <td><?php echo date('d F Y', strtotime($entry['entry_date'])); ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><strong>Entry Type:</strong></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo ucfirst(str_replace('_', ' ', $entry['entry_type'])); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted"><strong>Description:</strong></td>
                                    <td><?php echo htmlspecialchars($entry['description']); ?></td>
                                </tr>
                                <?php if ($entry['notes']): ?>
                                <tr>
                                    <td class="text-muted"><strong>Notes:</strong></td>
                                    <td><?php echo nl2br(htmlspecialchars($entry['notes'])); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>

                        <!-- Right Column -->
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <?php if ($entry['company_name']): ?>
                                <tr>
                                    <td width="40%" class="text-muted"><strong>Company:</strong></td>
                                    <td><?php echo htmlspecialchars($entry['company_name']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($entry['estate_name']): ?>
                                <tr>
                                    <td class="text-muted"><strong>Estate:</strong></td>
                                    <td><?php echo htmlspecialchars($entry['estate_name']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($entry['division_name']): ?>
                                <tr>
                                    <td class="text-muted"><strong>Division:</strong></td>
                                    <td><?php echo htmlspecialchars($entry['division_name']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($entry['block_code']): ?>
                                <tr>
                                    <td class="text-muted"><strong>Block:</strong></td>
                                    <td><code><?php echo htmlspecialchars($entry['block_code']); ?></code></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($entry['activity_name']): ?>
                                <tr>
                                    <td class="text-muted"><strong>Activity:</strong></td>
                                    <td>
                                        <code><?php echo htmlspecialchars($entry['activity_code']); ?></code> - 
                                        <?php echo htmlspecialchars($entry['activity_name']); ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td class="text-muted"><strong>Created:</strong></td>
                                    <td>
                                        <?php echo date('d M Y H:i', strtotime($entry['created_at'])); ?>
                                        <?php if ($entry['created_by']): ?>
                                            by <?php echo htmlspecialchars($entry['created_by']); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ($entry['posted_date']): ?>
                                <tr>
                                    <td class="text-muted"><strong>Posted:</strong></td>
                                    <td>
                                        <?php echo date('d M Y H:i', strtotime($entry['posted_date'])); ?>
                                        <?php if ($entry['posted_by']): ?>
                                            by User ID: <?php echo $entry['posted_by']; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>

                    <!-- Balance Status -->
                    <?php if ($entry['balance_status'] == 'Unbalanced'): ?>
                    <div class="alert alert-danger mt-3">
                        <i class="bi bi-exclamation-triangle"></i> 
                        <strong>Warning:</strong> This entry is unbalanced! 
                        Difference: Rp <?php echo format_number($entry['balance_difference'], 2); ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success mt-3">
                        <i class="bi bi-check-circle"></i> 
                        <strong>Balanced:</strong> Debit equals Credit
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Journal Entry Lines -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-list-ol"></i> Journal Entry Lines (<?php echo count($lines); ?> lines)
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th style="width: 5%;">#</th>
                                    <th style="width: 12%;">Account Code</th>
                                    <th style="width: 25%;">Account Name</th>
                                    <th style="width: 10%;">Type</th>
                                    <th class="text-end" style="width: 12%;">Debit</th>
                                    <th class="text-end" style="width: 12%;">Credit</th>
                                    <th style="width: 10%;">Cost Category</th>
                                    <th style="width: 14%;">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_debit = 0;
                                $total_credit = 0;
                                foreach ($lines as $line): 
                                    $total_debit += $line['debit_amount'];
                                    $total_credit += $line['credit_amount'];
                                ?>
                                    <tr>
                                        <td><?php echo $line['line_number']; ?></td>
                                        <td><code><?php echo htmlspecialchars($line['account_code']); ?></code></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($line['account_name']); ?></strong>
                                            <?php if ($line['line_block_code']): ?>
                                                <br><small class="text-muted">Block: <?php echo htmlspecialchars($line['line_block_code']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $type_colors = [
                                                'asset' => 'primary',
                                                'liability' => 'danger',
                                                'equity' => 'info',
                                                'revenue' => 'success',
                                                'expense' => 'warning'
                                            ];
                                            $type_color = $type_colors[$line['account_type']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $type_color; ?>">
                                                <?php echo ucfirst($line['account_type']); ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($line['debit_amount'] > 0): ?>
                                                <strong>Rp <?php echo format_number($line['debit_amount'], 2); ?></strong>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($line['credit_amount'] > 0): ?>
                                                <strong>Rp <?php echo format_number($line['credit_amount'], 2); ?></strong>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($line['cost_category']): ?>
                                                <?php
                                                $category_colors = [
                                                    'labor' => 'primary',
                                                    'material' => 'success',
                                                    'vehicle_equipment' => 'warning',
                                                    'overhead' => 'info',
                                                    'other' => 'secondary'
                                                ];
                                                $cat_color = $category_colors[$line['cost_category']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $cat_color; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $line['cost_category'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($line['description']): ?>
                                                <small><?php echo htmlspecialchars($line['description']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="4" class="text-end">TOTAL:</th>
                                    <th class="text-end">Rp <?php echo format_number($total_debit, 2); ?></th>
                                    <th class="text-end">Rp <?php echo format_number($total_credit, 2); ?></th>
                                    <th colspan="2">
                                        <?php if (abs($total_debit - $total_credit) < 0.01): ?>
                                            <span class="badge bg-success">Balanced</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Unbalanced</span>
                                        <?php endif; ?>
                                    </th>
                                </tr>
                                <tr>
                                    <th colspan="4" class="text-end">DIFFERENCE:</th>
                                    <th colspan="2" class="text-end">
                                        Rp <?php echo format_number(abs($total_debit - $total_credit), 2); ?>
                                    </th>
                                    <th colspan="2"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SAP Integration Info (if applicable) -->
    <?php if ($entry['sap_document_number'] || $entry['financial_transaction_id']): ?>
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-link-45deg"></i> SAP Integration
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <?php if ($entry['sap_document_number']): ?>
                            <p><strong>SAP Document Number:</strong> <?php echo htmlspecialchars($entry['sap_document_number']); ?></p>
                            <?php endif; ?>
                            <?php if ($entry['sap_fiscal_year']): ?>
                            <p><strong>SAP Fiscal Year:</strong> <?php echo $entry['sap_fiscal_year']; ?></p>
                            <?php endif; ?>
                            <?php if ($entry['sap_posting_date']): ?>
                            <p><strong>SAP Posting Date:</strong> <?php echo date('d M Y', strtotime($entry['sap_posting_date'])); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <?php if ($entry['financial_transaction_id']): ?>
                            <p><strong>Financial Transaction ID:</strong> <?php echo $entry['financial_transaction_id']; ?></p>
                            <?php endif; ?>
                            <p><strong>Sync Status:</strong> 
                                <?php
                                $sync_colors = ['pending' => 'warning', 'synced' => 'success', 'failed' => 'danger'];
                                $sync_color = $sync_colors[$entry['sync_status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?php echo $sync_color; ?>">
                                    <?php echo ucfirst($entry['sync_status']); ?>
                                </span>
                            </p>
                            <?php if ($entry['sync_date']): ?>
                            <p><strong>Sync Date:</strong> <?php echo date('d M Y H:i', strtotime($entry['sync_date'])); ?></p>
                            <?php endif; ?>
                            <?php if ($entry['sync_error']): ?>
                            <div class="alert alert-danger">
                                <strong>Sync Error:</strong> <?php echo htmlspecialchars($entry['sync_error']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body text-center">
                    <a href="journal_entries.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                    
                    <?php if ($entry['status'] == 'draft'): ?>
                        <form method="POST" action="journal_entries.php" style="display:inline;" onsubmit="return confirm('Post this journal entry?');">
                            <input type="hidden" name="action" value="post_entry">
                            <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-circle"></i> Post Entry
                            </button>
                        </form>
                        
                        <form method="POST" action="journal_entries.php" style="display:inline;" onsubmit="return confirm('Delete this journal entry? This cannot be undone.');">
                            <input type="hidden" name="action" value="delete_entry">
                            <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-trash"></i> Delete Entry
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <button onclick="window.print()" class="btn btn-info">
                        <i class="bi bi-printer"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .sidebar, .navbar, .btn, .breadcrumb, .card-header { display: none !important; }
    .card { border: 1px solid #000 !important; }
    .alert { border: 1px solid #000 !important; }
}
</style>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
