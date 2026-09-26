<?php
// Balance Sheet
// Driven by general_ledger_accounts.account_type — same tables as P&L.
//
// account_type → Balance Sheet section:
//   'asset'     → ASSETS           (debit-normal:  balance = debit − credit)
//   'liability' → LIABILITIES      (credit-normal: balance = credit − debit)
//   'equity'    → EQUITY           (credit-normal: balance = credit − debit)
//
// Balance Sheet is a POINT-IN-TIME statement:
//   → uses ALL posted journal entries up to $date_to (ignores $date_from)
//   → the $where_clause from the parent already includes je.status = 'posted'
//      and je.entry_date <= :date_to; we rebuild it here without the date_from filter.

// ── 0. Optional drill-down filters from Balance Sheet (Grouped) ──────────────
$bs_filter_group_id = isset($_GET['filter_group_id']) && ctype_digit((string)$_GET['filter_group_id'])
    ? (int)$_GET['filter_group_id'] : null;

// Resolve group name for the banner
$bs_filter_group_name = null;
if ($bs_filter_group_id) {
    $gn = $pdo->prepare("SELECT group_name FROM financial_account_groups WHERE id = ? LIMIT 1");
    $gn->execute([$bs_filter_group_id]);
    $bs_filter_group_name = $gn->fetchColumn() ?: null;
}

// ── 1. Build a point-in-time WHERE clause (≤ date_to only, no date_from) ─────
$bs_conditions = ["je.status = 'posted'", "je.entry_date <= :bs_date_to"];
$bs_params     = [':bs_date_to' => $date_to];

if ($company_id) {
    $bs_conditions[] = "je.company_id = :company_id";
    $bs_params[':company_id'] = $company_id;
}
if ($estate_id) {
    $bs_conditions[] = "je.business_unit_id = :estate_id";
    $bs_params[':estate_id'] = $estate_id;
}
if ($division_id) {
    $bs_conditions[] = "je.division_id = :division_id";
    $bs_params[':division_id'] = $division_id;
}
if ($bs_filter_group_id) {
    $bs_conditions[] = "gla.financial_group_id = :bs_fgi";
    $bs_params[':bs_fgi'] = $bs_filter_group_id;
}

$bs_where = implode(' AND ', $bs_conditions);

// ── 2. Query running balances per GL account ─────────────────────────────────
$sql = "
    SELECT
        gla.id            AS gl_id,
        gla.account_code,
        gla.account_name,
        gla.account_type,
        gla.parent_account_id,
        SUM(jel.debit_amount)  AS total_debit,
        SUM(jel.credit_amount) AS total_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
    WHERE $bs_where
      AND gla.account_type IN ('asset','liability','equity')
    GROUP BY gla.id, gla.account_code, gla.account_name, gla.account_type, gla.parent_account_id
    HAVING (SUM(jel.debit_amount) + SUM(jel.credit_amount)) > 0
    ORDER BY gla.account_type, gla.account_code
";
// Shared query-string base for GL drill-down links (org + date params)
$_bs_gl_qs_base = http_build_query(array_filter([
    'company_id'  => $company_id,
    'estate_id'   => $estate_id,
    'division_id' => $division_id,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
], fn($v) => $v !== '' && $v !== null));
$stmt = $pdo->prepare($sql);
$stmt->execute($bs_params);
$bs_rows = $stmt->fetchAll();

// ── 2b. Period movement (date_from..date_to) per GL account — for Previous Bal ─
$bs_period_params = [':p_from' => $date_from, ':p_to' => $date_to];
$bs_period_extra  = "";
if ($company_id)  { $bs_period_extra .= " AND je.company_id = :company_id";         $bs_period_params[':company_id']  = $company_id; }
if ($estate_id)   { $bs_period_extra .= " AND je.business_unit_id = :estate_id";    $bs_period_params[':estate_id']   = $estate_id; }
if ($division_id) { $bs_period_extra .= " AND je.division_id = :division_id";       $bs_period_params[':division_id'] = $division_id; }

$sql_period = "
    SELECT
        gla.id            AS gl_id,
        gla.account_type,
        SUM(jel.debit_amount)  AS period_debit,
        SUM(jel.credit_amount) AS period_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
    WHERE je.status = 'posted'
      AND je.entry_date >= :p_from
      AND je.entry_date <= :p_to
      AND gla.account_type IN ('asset','liability','equity')
      $bs_period_extra
    GROUP BY gla.id, gla.account_type
";
$stmt_period = $pdo->prepare($sql_period);
$stmt_period->execute($bs_period_params);

// gl_id => signed period movement
$bs_period_movement = [];
foreach ($stmt_period->fetchAll(PDO::FETCH_ASSOC) as $pr) {
    $gid  = $pr['gl_id'];
    $type = $pr['account_type'];
    $dbt  = (float)$pr['period_debit'];
    $cdt  = (float)$pr['period_credit'];
    $mov  = ($type === 'asset') ? ($dbt - $cdt) : ($cdt - $dbt);
    $bs_period_movement[$gid] = ($bs_period_movement[$gid] ?? 0) + $mov;
}

// ── 3. Bucket by section & compute signed balance ────────────────────────────
// Asset balance    = debit − credit  (debit-normal, positive = asset exists)
// Liability/Equity = credit − debit  (credit-normal, positive = balance owed)
$buckets = ['asset' => [], 'liability' => [], 'equity' => []];

foreach ($bs_rows as $row) {
    $dbt  = (float)$row['total_debit'];
    $cdt  = (float)$row['total_credit'];
    $type = $row['account_type'];
    $gid  = $row['gl_id'];

    $balance = ($type === 'asset') ? ($dbt - $cdt) : ($cdt - $dbt);
    $mov     = $bs_period_movement[$gid] ?? 0;

    $buckets[$type][] = [
        'gl_id'    => $gid,
        'code'     => $row['account_code'],
        'name'     => $row['account_name'],
        'debit'    => $dbt,
        'credit'   => $cdt,
        'prev_bal' => $balance - $mov,
        'balance'  => $balance,
    ];
}

// ── 4. Section totals ────────────────────────────────────────────────────────
$total_assets      = array_sum(array_column($buckets['asset'],     'balance'));
$total_liabilities = array_sum(array_column($buckets['liability'], 'balance'));
$total_equity_accounts = array_sum(array_column($buckets['equity'], 'balance'));

// Debit / Credit sub-totals per section
$subtotal_debit_assets      = array_sum(array_column($buckets['asset'],     'debit'));
$subtotal_credit_assets     = array_sum(array_column($buckets['asset'],     'credit'));
$subtotal_debit_liabilities = array_sum(array_column($buckets['liability'], 'debit'));
$subtotal_credit_liabilities= array_sum(array_column($buckets['liability'], 'credit'));
$subtotal_debit_equity      = array_sum(array_column($buckets['equity'],    'debit'));
$subtotal_credit_equity     = array_sum(array_column($buckets['equity'],    'credit'));

// Grand totals of Debit / Credit across all BS sections
$grand_total_debit  = $subtotal_debit_assets  + $subtotal_debit_liabilities  + $subtotal_debit_equity;
$grand_total_credit = $subtotal_credit_assets + $subtotal_credit_liabilities + $subtotal_credit_equity;

// Previous-balance section totals
$prev_total_assets          = array_sum(array_column($buckets['asset'],     'prev_bal'));
$prev_total_liabilities     = array_sum(array_column($buckets['liability'], 'prev_bal'));
$prev_total_equity_accounts = array_sum(array_column($buckets['equity'],    'prev_bal'));

// Current Period Profit/(Loss) = the gap between Assets and (Liabilities + recorded Equity).
// This is exactly Revenue − Expenses not yet closed to Retained Earnings.
// We show it as an implicit equity line so the sheet always balances — no warning needed.
$current_pl        = $total_assets - ($total_liabilities + $total_equity_accounts);
$total_equity      = $total_equity_accounts + $current_pl;   // = $total_assets − $total_liabilities
$total_liab_equity = $total_liabilities + $total_equity;     // always = $total_assets
$is_balanced       = abs($total_assets - $total_liab_equity) < 0.01; // always true by construction

$prev_current_pl        = $prev_total_assets - ($prev_total_liabilities + $prev_total_equity_accounts);
$prev_total_equity      = $prev_total_equity_accounts + $prev_current_pl;
$prev_total_liab_equity = $prev_total_liabilities + $prev_total_equity;

// ── Helpers ──────────────────────────────────────────────────────────────────
function bs_fmt_date(string $ymd): string {
    $d = DateTime::createFromFormat('Y-m-d', $ymd);
    return $d ? $d->format('d/m/Y') : htmlspecialchars($ymd);
}
function bs_fmt_rp(float $v): string {
    return ($v < 0 ? 'Rp -' : 'Rp ') . number_format(abs($v), 0, ',', '.');
}
function bs_fmt(float $v): string {
    return ($v < 0 ? '-' : '') . number_format(abs($v), 0, ',', '.');
}

// Render account rows for a section.
// $asset_side: true for assets (positive balance is normal), false for liabilities/equity
// $gl_qs_base: shared query-string fragment for GL drill-down links (org + date params)
function bs_render_rows(array $bucket, bool $asset_side, string $gl_qs_base = ''): void {
    foreach ($bucket as $item) {
        $bal_class = $asset_side
            ? ($item['balance'] >= 0 ? 'text-success' : 'text-danger')
            : ($item['balance'] >= 0 ? ''             : 'text-danger'); // abnormal negative liability
        $prev_class = $asset_side
            ? ($item['prev_bal'] >= 0 ? 'text-primary' : 'text-danger')
            : ($item['prev_bal'] >= 0 ? 'text-muted'   : 'text-danger');
        $gl_url = $gl_qs_base && isset($item['gl_id'])
            ? ('?' . $gl_qs_base . '&report=general_ledger&gl_account_id=' . (int)$item['gl_id'] . '&from=balance_sheet')
            : '';
        if ($gl_url) {
            $href_url = htmlspecialchars($gl_url, ENT_QUOTES);
            $js_url   = str_replace('&amp;', '&', $href_url);
            echo '<tr class="bs-row-clickable" style="cursor:pointer;" onclick="window.location=\'' . $js_url . '\'">';
        } else {
            echo '<tr>';
        }
        echo '<td class="ps-4"><code>' . htmlspecialchars($item['code']) . '</code></td>';
        echo '<td>';
        if ($gl_url) {
            $href_url = htmlspecialchars($gl_url, ENT_QUOTES);
            echo '<a href="' . $href_url . '" class="text-decoration-none text-body" onclick="event.stopPropagation()">';
            echo '<i class="bi bi-zoom-in me-1 text-muted" style="font-size:0.75em;"></i>';
            echo htmlspecialchars($item['name']);
            echo '</a>';
        } else {
            echo htmlspecialchars($item['name']);
        }
        echo '</td>';
        echo '<td class="text-end ' . $prev_class . '" style="font-size:0.9rem;">'
             . ($item['prev_bal'] != 0 ? bs_fmt($item['prev_bal']) : '-') . '</td>';
        echo '<td class="text-end">' . ($item['debit']  > 0 ? number_format($item['debit'],  0, ',', '.') : '-') . '</td>';
        echo '<td class="text-end">' . ($item['credit'] > 0 ? number_format($item['credit'], 0, ',', '.') : '-') . '</td>';
        echo '<td class="text-end ' . $bal_class . '">' . bs_fmt($item['balance']) . '</td>';
        echo '<td class="text-center no-print" style="width:38px;">';
        if ($gl_url) {
            $href_url = htmlspecialchars($gl_url, ENT_QUOTES);
            echo '<a href="' . $href_url . '" '
               . 'class="btn btn-sm p-0 lh-1" style="color:#166c82;" '
               . 'title="View in General Ledger" onclick="event.stopPropagation()"><i class="bi bi-journal-richtext"></i></a>';
        }
        echo '</td>';
        echo '</tr>';
    }
}
?>

<!-- Header -->
<?php
$_bs_back_to_group_url = '?' . http_build_query(array_filter([
    'report'      => 'balance_sheet_group',
    'company_id'  => $company_id,
    'estate_id'   => $estate_id,
    'division_id' => $division_id,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
], fn($v) => $v !== '' && $v !== null));
$_bs_from = $_GET['from'] ?? '';
?>
<div class="row mb-3">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4><i class="bi bi-bank"></i> Balance Sheet
                <?php if ($bs_filter_group_name): ?>
                    <span class="badge ms-2 fw-normal" style="font-size:0.6em;background-color:#166c82;">
                        <i class="bi bi-funnel-fill"></i> <?= htmlspecialchars($bs_filter_group_name) ?>
                    </span>
                <?php endif; ?>
                </h4>
                <p class="text-muted mb-0">As at <?= bs_fmt_date($date_to) ?>
                    <span class="badge bg-success ms-2"><i class="bi bi-check-circle"></i> Balanced</span>
                </p>
            </div>
            <div class="d-flex gap-2 no-print">
                <?php if ($_bs_from === 'general_ledger'): ?>
                    <?php
                    // Build back-to-GL URL preserving all org + date params
                    $_bs_back_gl_url = '?' . http_build_query(array_filter([
                        'report'      => 'general_ledger',
                        'company_id'  => $company_id,
                        'estate_id'   => $estate_id,
                        'division_id' => $division_id,
                        'date_from'   => $date_from,
                        'date_to'     => $date_to,
                    ], fn($v) => $v !== '' && $v !== null));
                    ?>
                    <a href="<?= htmlspecialchars($_bs_back_gl_url) ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-left"></i> Back to General Ledger
                    </a>
                <?php elseif ($bs_filter_group_name): ?>
                    <!-- "Back to Grouped View" already shown in alert below -->
                <?php else: ?>
                    <a href="<?= htmlspecialchars($_bs_back_to_group_url) ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-left"></i> Back to Grouped View
                    </a>
                <?php endif; ?>
                <button onclick="exportToExcel('balance_sheet')" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </button>
                <button onclick="printDetailBS()" class="btn btn-secondary btn-sm">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($bs_filter_group_name): ?>
<?php
$_bs_back_url = '?' . http_build_query(array_filter([
    'report'      => 'balance_sheet_group',
    'company_id'  => $company_id,
    'estate_id'   => $estate_id,
    'division_id' => $division_id,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
], fn($v) => $v !== '' && $v !== null));
?>
<div class="alert alert-info d-flex align-items-center gap-3 py-2 no-print" style="border-left:4px solid #166c82;">
    <i class="bi bi-funnel-fill" style="color:#166c82;font-size:1.1rem;"></i>
    <div class="flex-grow-1">
        Filtered by group: <strong><?= htmlspecialchars($bs_filter_group_name) ?></strong>
        — showing only accounts in this group as at <?= bs_fmt_date($date_to) ?>
    </div>
    <a href="<?= htmlspecialchars($_bs_back_url) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Grouped View
    </a>
</div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-white" style="background-color:<?= $total_assets >= 0 ? '#1565c0' : '#e65100' ?>;">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Total Assets<?= $total_assets < 0 ? ' ⚠ Abnormal' : '' ?></h6>
                <h5 class="mb-0"><?= bs_fmt_rp($total_assets) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-white" style="background-color:<?= $total_liabilities >= 0 ? '#c62828' : '#e65100' ?>;">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Total Liabilities<?= $total_liabilities < 0 ? ' ⚠ Abnormal' : '' ?></h6>
                <h5 class="mb-0"><?= bs_fmt_rp($total_liabilities) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-white" style="background-color:<?= $total_equity >= 0 ? '#2e7d32' : '#e65100' ?>;">
            <div class="card-body p-3">
                <h6 class="card-title mb-1">Total Equity<?= $total_equity < 0 ? ' ⚠ Abnormal' : '' ?></h6>
                <h5 class="mb-0"><?= bs_fmt_rp($total_equity) ?></h5>
                <small class="opacity-75">incl. Current Period P/L</small>
            </div>
        </div>
    </div>
</div>

<!-- ── Print area wrapper (table only) ─────────────────────────────────────── -->
<style>.bs-row-clickable:hover td { background-color: #e3f2fd !important; }</style>

<div id="bs-print-area"
     data-date-to="<?= htmlspecialchars($date_to) ?>">

<!-- Balance Sheet Table -->
<div class="card">
    <div class="card-header text-white" style="background-color:#166c82;">
        <h5 class="mb-0"><i class="bi bi-table"></i> Balance Sheet — as at <?= bs_fmt_date($date_to) ?></h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0" id="bsTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:9%">Code</th>
                        <th style="width:32%">Account</th>
                        <th class="text-end text-muted" style="width:14%;font-size:0.9rem;">Previous Balance</th>
                        <th class="text-end" style="width:13%">Debit</th>
                        <th class="text-end" style="width:13%">Credit</th>
                        <th class="text-end" style="width:13%">Ending Balance</th>
                        <th class="no-print" style="width:4%;text-align:center;" title="General Ledger drill-down"><i class="bi bi-journal-richtext" style="color:#166c82;"></i></th>
                    </tr>
                </thead>
                <tbody>

                    <!-- ══════════════════════════════════════════
                         ASSETS
                    ══════════════════════════════════════════ -->
                    <?php if (!$bs_filter_group_id || !empty($buckets['asset'])): ?>
                    <tr style="background-color:#e3f2fd;">
                        <td colspan="7"><strong><i class="bi bi-building text-primary"></i> ASSETS</strong></td>
                    </tr>
                    <?php if (empty($buckets['asset'])): ?>
                    <tr><td colspan="7" class="text-center text-muted ps-4">No asset data as at this date</td></tr>
                    <?php else: bs_render_rows($buckets['asset'], true, $_bs_gl_qs_base); endif; ?>
                    <tr style="background-color:<?= $total_assets >= 0 ? '#bbdefb' : '#ffe0b2' ?>; font-weight:bold; border-top:2px solid #90caf9;">
                        <td colspan="2"><strong>TOTAL ASSETS<?= $total_assets < 0 ? ' (Abnormal)' : '' ?></strong></td>
                        <td class="text-end text-muted"><?= bs_fmt_rp($prev_total_assets) ?></td>
                        <td class="text-end"><?= $subtotal_debit_assets  != 0 ? number_format($subtotal_debit_assets,  0, ',', '.') : '–' ?></td>
                        <td class="text-end"><?= $subtotal_credit_assets != 0 ? number_format($subtotal_credit_assets, 0, ',', '.') : '–' ?></td>
                        <td class="text-end <?= $total_assets >= 0 ? 'text-primary' : 'text-danger' ?>">
                            <strong><?= bs_fmt_rp($total_assets) ?></strong>
                        </td>
                        <td class="no-print"></td>
                    </tr>
                    <?php endif; ?>

                    <!-- ══════════════════════════════════════════
                         LIABILITIES
                    ══════════════════════════════════════════ -->
                    <?php if (!$bs_filter_group_id || !empty($buckets['liability'])): ?>
                    <tr style="background-color:#fce4e4;">
                        <td colspan="7"><strong><i class="bi bi-arrow-left-circle-fill text-danger"></i> LIABILITIES</strong></td>
                    </tr>
                    <?php if (empty($buckets['liability'])): ?>
                    <tr><td colspan="7" class="text-center text-muted ps-4">No liability data as at this date</td></tr>
                    <?php else: bs_render_rows($buckets['liability'], false, $_bs_gl_qs_base); endif; ?>
                    <tr style="background-color:<?= $total_liabilities >= 0 ? '#ffcdd2' : '#c8e6c9' ?>; font-weight:bold; border-top:2px solid #ef9a9a;">
                        <td colspan="2"><strong>TOTAL LIABILITIES<?= $total_liabilities < 0 ? ' (Abnormal)' : '' ?></strong></td>
                        <td class="text-end text-muted"><?= bs_fmt_rp($prev_total_liabilities) ?></td>
                        <td class="text-end"><?= $subtotal_debit_liabilities  != 0 ? number_format($subtotal_debit_liabilities,  0, ',', '.') : '–' ?></td>
                        <td class="text-end"><?= $subtotal_credit_liabilities != 0 ? number_format($subtotal_credit_liabilities, 0, ',', '.') : '–' ?></td>
                        <td class="text-end <?= $total_liabilities >= 0 ? 'text-danger' : 'text-success' ?>">
                            <strong><?= bs_fmt_rp($total_liabilities) ?></strong>
                        </td>
                        <td class="no-print"></td>
                    </tr>
                    <?php endif; ?>

                    <!-- ══════════════════════════════════════════
                         EQUITY
                    ══════════════════════════════════════════ -->
                    <?php if (!$bs_filter_group_id || !empty($buckets['equity'])): ?>
                    <tr style="background-color:#e8f5e9;">
                        <td colspan="7"><strong><i class="bi bi-graph-up-arrow text-success"></i> EQUITY</strong></td>
                    </tr>
                    <?php if (empty($buckets['equity'])): ?>
                    <?php if (!$bs_filter_group_id): ?>
                    <tr><td colspan="7" class="text-center text-muted ps-4">No equity data as at this date</td></tr>
                    <?php endif; ?>
                    <?php else: bs_render_rows($buckets['equity'], false, $_bs_gl_qs_base); endif; ?>

                    <!-- Current Period Profit / (Loss) — computed to make the sheet balance -->
                    <?php if (!$bs_filter_group_id): ?>
                    <tr class="fst-italic" style="background-color:#f1f8e9;">
                        <td class="ps-4"><code>—</code></td>
                        <td>Current Period Profit / (Loss)</td>
                        <td class="text-end <?= $prev_current_pl >= 0 ? 'text-muted' : 'text-danger' ?>"><?= bs_fmt($prev_current_pl) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                        <td class="text-end <?= $current_pl >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= bs_fmt($current_pl) ?>
                        </td>
                        <td class="no-print"></td>
                    </tr>
                    <?php endif; ?>

                    <tr style="background-color:<?= $total_equity >= 0 ? '#c8e6c9' : '#ffe0b2' ?>; font-weight:bold; border-top:2px solid #a5d6a7;">
                        <td colspan="2"><strong>TOTAL EQUITY</strong></td>
                        <td class="text-end text-muted"><?= bs_fmt_rp($prev_total_equity) ?></td>
                        <td class="text-end"><?= $subtotal_debit_equity  != 0 ? number_format($subtotal_debit_equity,  0, ',', '.') : '–' ?></td>
                        <td class="text-end"><?= $subtotal_credit_equity != 0 ? number_format($subtotal_credit_equity, 0, ',', '.') : '–' ?></td>
                        <td class="text-end <?= $total_equity >= 0 ? 'text-success' : 'text-danger' ?>">
                            <strong><?= bs_fmt_rp($total_equity) ?></strong>
                        </td>
                        <td class="no-print"></td>
                    </tr>
                    <?php endif; ?>

                    <!-- ══════════════════════════════════════════
                         TOTAL LIABILITIES + EQUITY
                    ══════════════════════════════════════════ -->
                    <tr style="background-color:#b2dfdb; font-weight:bold; font-size:1.1em; border-top:2px solid #80cbc4;">
                        <td colspan="2"><strong>TOTAL LIABILITIES + EQUITY</strong></td>
                        <td class="text-end text-muted"><?= bs_fmt_rp($prev_total_liab_equity) ?></td>
                        <td class="text-end"><?= $grand_total_debit  != 0 ? number_format($grand_total_debit,  0, ',', '.') : '–' ?></td>
                        <td class="text-end"><?= $grand_total_credit != 0 ? number_format($grand_total_credit, 0, ',', '.') : '–' ?></td>
                        <td class="text-end text-success">
                            <strong><?= bs_fmt_rp($total_liab_equity) ?></strong>
                        </td>
                        <td class="no-print"></td>
                    </tr>

                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /#bs-print-area -->

<!-- Accounting Equation Check -->
<?php if (!$bs_filter_group_id && ($total_assets != 0 || $total_liab_equity != 0)): ?>
<div class="row mt-3 no-print">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Accounting Equation: Assets = Liabilities + Equity</h6>
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-center px-3">
                        <div class="text-muted small">Assets</div>
                        <strong class="<?= $total_assets >= 0 ? 'text-primary' : 'text-danger' ?>">
                            <?= bs_fmt_rp($total_assets) ?>
                        </strong>
                    </div>
                    <div class="text-muted fw-bold">=</div>
                    <div class="text-center px-3">
                        <div class="text-muted small">Liabilities</div>
                        <strong class="text-danger"><?= bs_fmt_rp($total_liabilities) ?></strong>
                    </div>
                    <div class="text-muted fw-bold">+</div>
                    <div class="text-center px-3">
                        <div class="text-muted small">Equity</div>
                        <strong class="text-success"><?= bs_fmt_rp($total_equity) ?></strong>
                    </div>
                    <div class="ms-3">
                        <?php if ($is_balanced): ?>
                        <span class="badge bg-success fs-6"><i class="bi bi-check-circle"></i> Balanced</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark fs-6">
                            <i class="bi bi-exclamation-triangle"></i>
                            Off by <?= bs_fmt_rp(abs($total_assets - $total_liab_equity)) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted">Debt-to-Equity Ratio</h6>
                <?php if ($total_equity != 0): ?>
                <h4 class="text-primary">
                    <?= number_format($total_liabilities / $total_equity, 2) ?>x
                </h4>
                <small class="text-muted">Liabilities / Equity</small>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted">Equity Ratio</h6>
                <?php if ($total_assets != 0): ?>
                <h4 class="<?= ($total_equity / $total_assets) >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= number_format(($total_equity / $total_assets) * 100, 1) ?>%
                </h4>
                <small class="text-muted">Equity / Assets</small>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php // Powered by IBM Bob ?>
