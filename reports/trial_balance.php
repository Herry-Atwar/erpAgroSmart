<?php
// Trial Balance
//   Code | Account | Type | Previous Balance | Debit | Credit | Ending Balance
//
//   Balance = Debit − Credit  (always, regardless of account type)
//
//   Balance Sheet accounts (asset / liability / equity):
//     Ending   = SUM of all posted entries ≤ date_to  (point-in-time)
//     Previous = Ending − period movement              (entries before date_from)
//     Debit/Credit = period entries date_from → date_to
//
//   P&L accounts (revenue / cogs / opex / other_income / other_expenses / tax):
//     Ending   = Previous + net period movement
//     Previous = SUM of entries Jan-1-of-year → day-before-date_from
//     Debit/Credit = period entries date_from → date_to

$_tb_bs_types = ['asset', 'liability', 'equity'];
$_tb_pl_types = ['revenue', 'cogs', 'operating_expense', 'expense',
                 'depreciation', 'other_income', 'other_expenses', 'tax'];

// Org-filter fragment (reused in all queries)
$_tb_org  = '';
$_tb_orgp = [];
if ($company_id)  { $_tb_org .= " AND je.company_id = :company_id";        $_tb_orgp[':company_id']  = $company_id; }
if ($estate_id)   { $_tb_org .= " AND je.business_unit_id = :estate_id";   $_tb_orgp[':estate_id']   = $estate_id; }
if ($division_id) { $_tb_org .= " AND je.division_id = :division_id";      $_tb_orgp[':division_id'] = $division_id; }

// Date helpers
$_tb_day_before = date('Y-m-d', strtotime($date_from . ' -1 day'));   // day before date_from
$_tb_year_start = substr($date_from, 0, 4) . '-01-01';                // Jan 1 of date_from's year

// ══════════════════════════════════════════════════════════════════════════════
// BALANCE SHEET ACCOUNTS
// Ending  = SUM of all entries ≤ date_to
// Period  = SUM of entries date_from → date_to  (used to derive Previous)
// ══════════════════════════════════════════════════════════════════════════════
$_tb_bs_tl = "'" . implode("','", $_tb_bs_types) . "'";

// A1 — BS ending balance (≤ date_to)
$tb_bs_end_sql = "
    SELECT gla.id AS gl_id, gla.account_code, gla.account_name, gla.account_type,
           SUM(jel.debit_amount) AS total_debit, SUM(jel.credit_amount) AS total_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id = jel.gl_account_id
    WHERE je.status = 'posted' AND je.entry_date <= :bs_to
      AND gla.account_type IN ($_tb_bs_tl) $_tb_org
    GROUP BY gla.id, gla.account_code, gla.account_name, gla.account_type
    HAVING (SUM(jel.debit_amount) + SUM(jel.credit_amount)) > 0
";
$tb_bs_end_stmt = $pdo->prepare($tb_bs_end_sql);
$tb_bs_end_stmt->execute(array_merge([':bs_to' => $date_to], $_tb_orgp));
$_tb_bs_end = $tb_bs_end_stmt->fetchAll(PDO::FETCH_ASSOC);

// A2 — BS period movement (date_from → date_to)
$tb_bs_mov_sql = "
    SELECT gla.id AS gl_id, gla.account_type,
           SUM(jel.debit_amount) AS period_debit, SUM(jel.credit_amount) AS period_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id = jel.gl_account_id
    WHERE je.status = 'posted' AND je.entry_date >= :bs_from AND je.entry_date <= :bs_to
      AND gla.account_type IN ($_tb_bs_tl) $_tb_org
    GROUP BY gla.id, gla.account_type
";
$tb_bs_mov_stmt = $pdo->prepare($tb_bs_mov_sql);
$tb_bs_mov_stmt->execute(array_merge([':bs_from' => $date_from, ':bs_to' => $date_to], $_tb_orgp));

$_tb_bs_mov = [];   // gl_id => net period movement (debit − credit)
foreach ($tb_bs_mov_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $_tb_bs_mov[$r['gl_id']] = (float)$r['period_debit'] - (float)$r['period_credit'];
}

// ══════════════════════════════════════════════════════════════════════════════
// P&L ACCOUNTS
// Ending  = SUM of entries date_from → date_to
// Previous = SUM of entries year_start → day_before_date_from
// ══════════════════════════════════════════════════════════════════════════════
$_tb_pl_tl = "'" . implode("','", $_tb_pl_types) . "'";

// B1 — P&L current period (date_from → date_to)  → used as Debit/Credit columns
$tb_pl_cur_sql = "
    SELECT gla.id AS gl_id, gla.account_code, gla.account_name, gla.account_type,
           SUM(jel.debit_amount) AS total_debit, SUM(jel.credit_amount) AS total_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id = jel.gl_account_id
    WHERE je.status = 'posted' AND je.entry_date >= :pl_from AND je.entry_date <= :pl_to
      AND gla.account_type IN ($_tb_pl_tl) $_tb_org
    GROUP BY gla.id, gla.account_code, gla.account_name, gla.account_type
    HAVING (SUM(jel.debit_amount) + SUM(jel.credit_amount)) > 0
";
$tb_pl_cur_stmt = $pdo->prepare($tb_pl_cur_sql);
$tb_pl_cur_stmt->execute(array_merge([':pl_from' => $date_from, ':pl_to' => $date_to], $_tb_orgp));
$_tb_pl_cur = $tb_pl_cur_stmt->fetchAll(PDO::FETCH_ASSOC);

// B2 — P&L previous balance (year_start → day_before_date_from)
$_tb_pl_prev      = [];  // gl_id => signed previous balance
$_tb_pl_prev_meta = [];  // gl_id => [account_code, account_name, account_type]
if ($_tb_year_start <= $_tb_day_before) {
    $tb_pl_prev_sql = "
        SELECT gla.id AS gl_id, gla.account_code, gla.account_name, gla.account_type,
               SUM(jel.debit_amount) AS prev_debit, SUM(jel.credit_amount) AS prev_credit
        FROM journal_entries je
        JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
        JOIN general_ledger_accounts gla ON gla.id = jel.gl_account_id
        WHERE je.status = 'posted' AND je.entry_date >= :pl_yr AND je.entry_date <= :pl_day_before
          AND gla.account_type IN ($_tb_pl_tl) $_tb_org
        GROUP BY gla.id, gla.account_code, gla.account_name, gla.account_type
    ";
    $tb_pl_prev_stmt = $pdo->prepare($tb_pl_prev_sql);
    $tb_pl_prev_stmt->execute(array_merge(
        [':pl_yr' => $_tb_year_start, ':pl_day_before' => $_tb_day_before],
        $_tb_orgp
    ));
    foreach ($tb_pl_prev_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $gid = $r['gl_id'];
        $_tb_pl_prev[$gid]      = (float)$r['prev_debit'] - (float)$r['prev_credit'];
        $_tb_pl_prev_meta[$gid] = [
            'code' => $r['account_code'],
            'name' => $r['account_name'],
            'type' => $r['account_type'],
        ];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// Build unified row list, sorted by account_code
// ══════════════════════════════════════════════════════════════════════════════
$tb_accounts     = [];
$tb_grand_prev   = 0;
$tb_grand_debit  = 0;
$tb_grand_credit = 0;
$tb_grand_end    = 0;

// Process BS rows
foreach ($_tb_bs_end as $row) {
    $gid    = $row['gl_id'];
    $dbt    = (float)$row['total_debit'];
    $cdt    = (float)$row['total_credit'];
    $ending = $dbt - $cdt;                          // always debit − credit
    $mov    = $_tb_bs_mov[$gid] ?? 0;
    $prev   = $ending - $mov;

    $tb_accounts[$gid] = [
        'gl_id'  => $gid,
        'code'   => $row['account_code'],
        'name'   => $row['account_name'],
        'type'   => $row['account_type'],
        'prev'   => $prev,
        'debit'  => 0,   // filled by period gross query below
        'credit' => 0,
        'ending' => $ending,
    ];
    $tb_grand_prev += $prev;
    $tb_grand_end  += $ending;
}

// Process P&L rows
foreach ($_tb_pl_cur as $row) {
    $gid    = $row['gl_id'];
    $dbt    = (float)$row['total_debit'];
    $cdt    = (float)$row['total_credit'];
    $prev   = $_tb_pl_prev[$gid] ?? 0;
    $ending = $prev + ($dbt - $cdt);                // always debit − credit movement

    $tb_accounts[$gid] = [
        'gl_id'  => $gid,
        'code'   => $row['account_code'],
        'name'   => $row['account_name'],
        'type'   => $row['account_type'],
        'prev'   => $prev,
        'debit'  => $dbt,
        'credit' => $cdt,
        'ending' => $ending,
    ];
    $tb_grand_debit  += $dbt;
    $tb_grand_credit += $cdt;
    $tb_grand_prev   += $prev;
    $tb_grand_end    += $ending;
}

// P&L accounts that had ONLY prior-period activity (no current-period entries)
// must still appear with prev > 0, debit = 0, credit = 0, ending = prev
foreach ($_tb_pl_prev as $gid => $prev) {
    if (isset($tb_accounts[$gid])) continue;   // already added via current-period loop
    $tb_accounts[$gid] = [
        'gl_id'  => $gid,
        'code'   => $_tb_pl_prev_meta[$gid]['code'],
        'name'   => $_tb_pl_prev_meta[$gid]['name'],
        'type'   => $_tb_pl_prev_meta[$gid]['type'],
        'prev'   => $prev,
        'debit'  => 0,
        'credit' => 0,
        'ending' => $prev,
    ];
    $tb_grand_prev += $prev;
    $tb_grand_end  += $prev;
}

// Now fill BS period debit/credit from separate mov query (gross amounts)
$tb_bs_mov_gross_sql = "
    SELECT gla.id AS gl_id,
           SUM(jel.debit_amount) AS period_debit, SUM(jel.credit_amount) AS period_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id = jel.gl_account_id
    WHERE je.status = 'posted' AND je.entry_date >= :bs_from AND je.entry_date <= :bs_to
      AND gla.account_type IN ($_tb_bs_tl) $_tb_org
    GROUP BY gla.id
";
$tb_bs_mov_gross_stmt = $pdo->prepare($tb_bs_mov_gross_sql);
$tb_bs_mov_gross_stmt->execute(array_merge([':bs_from' => $date_from, ':bs_to' => $date_to], $_tb_orgp));
foreach ($tb_bs_mov_gross_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $gid = $r['gl_id'];
    if (isset($tb_accounts[$gid])) {
        $tb_accounts[$gid]['debit']  = (float)$r['period_debit'];
        $tb_accounts[$gid]['credit'] = (float)$r['period_credit'];
        $tb_grand_debit  += (float)$r['period_debit'];
        $tb_grand_credit += (float)$r['period_credit'];
    }
}

// Sort by account_code
usort($tb_accounts, fn($a, $b) => strcmp($a['code'], $b['code']));

$tb_is_balanced = abs($tb_grand_debit - $tb_grand_credit) < 0.01;

// Shared query-string base for GL drill-down links
$_tb_gl_qs_base = http_build_query(array_filter([
    'company_id'  => $company_id,
    'estate_id'   => $estate_id,
    'division_id' => $division_id,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
], fn($v) => $v !== '' && $v !== null));

// ── Helpers ──────────────────────────────────────────────────────────────────
function tb_fmt(float $v): string {
    if ($v == 0) return '-';
    return $v < 0
        ? '(' . number_format(abs($v), 0, ',', '.') . ')'
        :        number_format($v,     0, ',', '.');
}
function tb_rp(float $v): string {
    if ($v == 0) return 'Rp -';
    return $v < 0
        ? 'Rp (' . number_format(abs($v), 0, ',', '.') . ')'
        : 'Rp '  . number_format($v,      0, ',', '.');
}
function tb_fmt_date(string $ymd): string {
    $d = DateTime::createFromFormat('Y-m-d', $ymd);
    return $d ? $d->format('d/m/Y') : htmlspecialchars($ymd);
}
function tb_type_label(string $type): string {
    return [
        'asset'             => 'Asset',
        'liability'         => 'Liability',
        'equity'            => 'Equity',
        'revenue'           => 'Revenue',
        'cogs'              => 'COGS',
        'operating_expense' => 'Opex',
        'expense'           => 'Expense',
        'other_income'      => 'Other Income',
        'other_expenses'    => 'Other Expenses',
        'tax'               => 'Tax',
    ][$type] ?? ucfirst(str_replace('_', ' ', $type));
}
?>

<!-- ── Header bar ──────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h5 class="mb-0 fw-semibold">
            <i class="bi bi-journal-text" style="color:#166c82;"></i>
            Trial Balance
        </h5>
        <div class="text-muted small mt-1">
            Period: <strong><?= tb_fmt_date($date_from) ?></strong> &rarr; <strong><?= tb_fmt_date($date_to) ?></strong>
            <?php if ($tb_is_balanced): ?>
                <span class="badge bg-success ms-2"><i class="bi bi-check-circle"></i> Balanced</span>
            <?php else: ?>
                <span class="badge bg-warning text-dark ms-2"><i class="bi bi-exclamation-triangle"></i> Debit ≠ Credit</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="exportToExcel('trial_balance')" class="btn btn-sm btn-success">
            <i class="bi bi-file-earmark-excel"></i> Export Excel
        </button>
        <button onclick="printTrialBalance()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
</div>

<!-- ── Summary cards ────────────────────────────────────────────────────────── -->
<div class="row g-2 mb-3">
    <div class="col-md-3">
        <div class="card text-white" style="background-color:#1565c0;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold" style="font-size:0.82rem;">Total Debit (Period)</div>
                <div class="fs-6 fw-bold mt-1"><?= tb_rp($tb_grand_debit) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white" style="background-color:#c62828;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold" style="font-size:0.82rem;">Total Credit (Period)</div>
                <div class="fs-6 fw-bold mt-1"><?= tb_rp($tb_grand_credit) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white" style="background-color:#4527a0;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold" style="font-size:0.82rem;">Total Previous Balance</div>
                <div class="fs-6 fw-bold mt-1"><?= tb_rp($tb_grand_prev) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white" style="background-color:<?= $tb_is_balanced ? '#2e7d32' : '#e65100' ?>;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold" style="font-size:0.82rem;">Total Ending Balance</div>
                <div class="fs-6 fw-bold mt-1"><?= tb_rp($tb_grand_end) ?></div>
                <small class="opacity-75"><?= $tb_is_balanced ? 'Dr = Cr ✓' : 'Dr ≠ Cr ✗' ?></small>
            </div>
        </div>
    </div>
</div>

<style>.tb-row-clickable:hover td { background-color: #e8f4fd !important; }</style>

<!-- ── Print area ───────────────────────────────────────────────────────────── -->
<div id="tb-print-area"
     data-date-from="<?= htmlspecialchars($date_from) ?>"
     data-date-to="<?= htmlspecialchars($date_to) ?>">

<div class="card">
    <div class="card-header text-white py-2" style="background-color:#166c82;">
        <h5 class="mb-0 fs-6">
            <i class="bi bi-table"></i>
            Trial Balance &mdash; <?= tb_fmt_date($date_from) ?> to <?= tb_fmt_date($date_to) ?>
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" id="tbTable" style="font-size:0.88rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:8%">Code</th>
                        <th style="width:27%">Account</th>
                        <th style="width:9%">Type</th>
                        <th class="text-end text-muted" style="width:14%;font-size:0.82rem;">Previous Balance</th>
                        <th class="text-end" style="width:12%">Debit</th>
                        <th class="text-end" style="width:12%">Credit</th>
                        <th class="text-end" style="width:12%">Ending Balance</th>
                        <th class="no-print" style="width:4%;text-align:center;" title="General Ledger drill-down"><i class="bi bi-journal-richtext" style="color:#166c82;"></i></th>
                    </tr>
                </thead>
                <tbody>
<?php if (empty($tb_accounts)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-3">No posted transactions found for this period.</td>
                </tr>
<?php else: ?>
<?php foreach ($tb_accounts as $acc):
    $end_class = $acc['ending'] < 0 ? 'text-danger' : '';
    $gl_url     = '?' . $_tb_gl_qs_base . '&report=general_ledger&gl_account_id=' . (int)$acc['gl_id'] . '&from=trial_balance';
    $gl_url_esc = htmlspecialchars($gl_url, ENT_QUOTES);
?>
                <tr class="tb-row-clickable" style="cursor:pointer;"
                    onclick="window.location='<?= str_replace('&amp;', '&', $gl_url_esc) ?>'">
                    <td><code><?= htmlspecialchars($acc['code']) ?></code></td>
                    <td>
                        <a href="<?= $gl_url_esc ?>" class="text-decoration-none text-body"
                           onclick="event.stopPropagation()">
                            <i class="bi bi-zoom-in me-1 text-muted" style="font-size:0.75em;"></i><?= htmlspecialchars($acc['name']) ?>
                        </a>
                    </td>
                    <td><span class="badge" style="font-size:0.7rem;background-color:#e9ecef;color:#495057;"><?= tb_type_label($acc['type']) ?></span></td>
                    <td class="text-end text-muted" style="font-size:0.85rem;"><?= tb_fmt($acc['prev']) ?></td>
                    <td class="text-end"><?= $acc['debit']  > 0 ? number_format($acc['debit'],  0, ',', '.') : '-' ?></td>
                    <td class="text-end"><?= $acc['credit'] > 0 ? number_format($acc['credit'], 0, ',', '.') : '-' ?></td>
                    <td class="text-end <?= $end_class ?>"><?= tb_fmt($acc['ending']) ?></td>
                    <td class="text-center no-print" style="width:38px;">
                        <a href="<?= $gl_url_esc ?>" class="btn btn-sm p-0 lh-1" style="color:#166c82;"
                           title="View in General Ledger" onclick="event.stopPropagation()">
                            <i class="bi bi-journal-richtext"></i>
                        </a>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background-color:#e9ecef;font-weight:700;border-top:2px solid #adb5bd;">
                        <td colspan="3"><strong>GRAND TOTAL</strong></td>
                        <td class="text-end text-muted"><?= tb_fmt($tb_grand_prev) ?></td>
                        <td class="text-end text-primary"><?= number_format($tb_grand_debit,  0, ',', '.') ?></td>
                        <td class="text-end text-danger" ><?= number_format($tb_grand_credit, 0, ',', '.') ?></td>
                        <td class="text-end <?= $tb_is_balanced ? 'text-success' : 'text-danger' ?>">
                            <strong><?= tb_fmt($tb_grand_end) ?></strong>
                        </td>
                        <td class="no-print"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

</div><!-- /#tb-print-area -->

<?php // Powered by IBM Bob ?>
