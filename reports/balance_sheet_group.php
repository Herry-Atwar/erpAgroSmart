<?php
// Balance Sheet — Grouped View
// Summarises GL account balances into their financial_account_groups instead of
// listing every individual account.  Only groups tagged report_type = 'balance_sheet'
// and is_active = 1 appear here.
//
// account_type convention (same as balance_sheet.php):
//   asset     → balance = debit − credit
//   liability → balance = credit − debit
//   equity    → balance = credit − debit
//
// Point-in-time: uses ALL posted entries ≤ $date_to (ignores $date_from).

// ── 1. Point-in-time WHERE clause ────────────────────────────────────────────
$bsg_conditions = ["je.status = 'posted'", "je.entry_date <= :bsg_date_to"];
$bsg_params     = [':bsg_date_to' => $date_to];

if ($company_id) {
    $bsg_conditions[] = "je.company_id = :company_id";
    $bsg_params[':company_id'] = $company_id;
}
if ($estate_id) {
    $bsg_conditions[] = "je.business_unit_id = :estate_id";
    $bsg_params[':estate_id'] = $estate_id;
}
if ($division_id) {
    $bsg_conditions[] = "je.division_id = :division_id";
    $bsg_params[':division_id'] = $division_id;
}

$bsg_where = implode(' AND ', $bsg_conditions);

// ── 2. Fetch all active balance-sheet groups (ordered for display) ────────────
$groups_stmt = $pdo->query("
    SELECT id, group_code, group_name, report_section, parent_group_id,
           display_order, is_total_line
    FROM financial_account_groups
    WHERE report_type = 'balance_sheet'
      AND is_active   = TRUE
    ORDER BY display_order, group_code
");
$all_bs_groups = $groups_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build a lookup: group_id → group row
$group_by_id = [];
foreach ($all_bs_groups as $g) {
    $group_by_id[$g['id']] = $g;
}

// ── 3a. Ending balance: all posted entries ≤ date_to ────────────────────────
$sql = "
    SELECT
        fag.id            AS group_id,
        fag.group_name,
        fag.group_code,
        fag.report_section,
        gla.account_type,
        SUM(jel.debit_amount)  AS total_debit,
        SUM(jel.credit_amount) AS total_credit
    FROM journal_entries          je
    JOIN journal_entry_lines      jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts  gla ON gla.id               = jel.gl_account_id
    JOIN financial_account_groups fag ON fag.id               = gla.financial_group_id
    WHERE $bsg_where
      AND gla.account_type IN ('asset','liability','equity')
      AND fag.report_type  = 'balance_sheet'
      AND fag.is_active    = TRUE
    GROUP BY fag.id, fag.group_name, fag.group_code, fag.report_section, gla.account_type
    HAVING (SUM(jel.debit_amount) + SUM(jel.credit_amount)) > 0
";
$stmt = $pdo->prepare($sql);
$stmt->execute($bsg_params);
$raw_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── 3b. Period movement: entries within date_from..date_to (for Previous Bal) ─
$bsg_period_params = [':p_from' => $date_from, ':p_to' => $date_to];
$bsg_period_extra  = "";
if ($company_id)  { $bsg_period_extra .= " AND je.company_id = :company_id";         $bsg_period_params[':company_id']  = $company_id; }
if ($estate_id)   { $bsg_period_extra .= " AND je.business_unit_id = :estate_id";    $bsg_period_params[':estate_id']   = $estate_id; }
if ($division_id) { $bsg_period_extra .= " AND je.division_id = :division_id";       $bsg_period_params[':division_id'] = $division_id; }

$sql_period = "
    SELECT
        fag.id            AS group_id,
        gla.account_type,
        SUM(jel.debit_amount)  AS period_debit,
        SUM(jel.credit_amount) AS period_credit
    FROM journal_entries          je
    JOIN journal_entry_lines      jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts  gla ON gla.id               = jel.gl_account_id
    JOIN financial_account_groups fag ON fag.id               = gla.financial_group_id
    WHERE je.status = 'posted'
      AND je.entry_date >= :p_from
      AND je.entry_date <= :p_to
      AND gla.account_type IN ('asset','liability','equity')
      AND fag.report_type  = 'balance_sheet'
      AND fag.is_active    = TRUE
      $bsg_period_extra
    GROUP BY fag.id, gla.account_type
";
$stmt_period = $pdo->prepare($sql_period);
$stmt_period->execute($bsg_period_params);

// group_id => signed period movement
$period_movement = [];
foreach ($stmt_period->fetchAll(PDO::FETCH_ASSOC) as $pr) {
    $gid  = $pr['group_id'];
    $type = $pr['account_type'];
    $dbt  = (float)$pr['period_debit'];
    $cdt  = (float)$pr['period_credit'];
    $mov  = ($type === 'asset') ? ($dbt - $cdt) : ($cdt - $dbt);
    $period_movement[$gid] = ($period_movement[$gid] ?? 0) + $mov;
}

// ── 4. Merge rows into group_id → balance (a group may span one account_type) ─
$group_balances = []; // group_id => ['debit'=>, 'credit'=>, 'balance'=>, 'account_type'=>]
foreach ($raw_rows as $row) {
    $gid  = $row['group_id'];
    $type = $row['account_type'];
    $dbt  = (float)$row['total_debit'];
    $cdt  = (float)$row['total_credit'];
    $bal  = ($type === 'asset') ? ($dbt - $cdt) : ($cdt - $dbt);

    if (!isset($group_balances[$gid])) {
        $group_balances[$gid] = ['debit' => 0, 'credit' => 0, 'balance' => 0, 'account_type' => $type];
    }
    $group_balances[$gid]['debit']   += $dbt;
    $group_balances[$gid]['credit']  += $cdt;
    $group_balances[$gid]['balance'] += $bal;
}

// ── 5. Bucket groups into sections ───────────────────────────────────────────
// Canonical section names used: 'asset', 'liability', 'equity'
// We derive section from the account_type of the underlying accounts (most common),
// but the group's own report_section is used as the display label.
$buckets = ['asset' => [], 'liability' => [], 'equity' => []];

foreach ($all_bs_groups as $g) {
    if ($g['is_total_line']) continue; // skip computed totals — we compute them ourselves

    $gid = $g['id'];
    if (!isset($group_balances[$gid])) continue; // no activity → skip zero-balance groups

    $info     = $group_balances[$gid];
    $acc_type = $info['account_type']; // 'asset' | 'liability' | 'equity'

    if (!array_key_exists($acc_type, $buckets)) continue;

    $ending  = $info['balance'];
    $mov     = $period_movement[$gid] ?? 0;
    $prev_bal = $ending - $mov;

    $buckets[$acc_type][] = [
        'group_id' => $gid,
        'name'     => $g['group_name'],
        'section'  => $g['report_section'],
        'prev_bal' => $prev_bal,
        'balance'  => $ending,
    ];
}

// ── 6. Section totals + balancing plug ───────────────────────────────────────
$total_assets           = array_sum(array_column($buckets['asset'],     'balance'));
$total_liabilities      = array_sum(array_column($buckets['liability'], 'balance'));
$total_equity_accounts  = array_sum(array_column($buckets['equity'],    'balance'));

$current_pl         = $total_assets - ($total_liabilities + $total_equity_accounts);
$total_equity       = $total_equity_accounts + $current_pl;
$total_liab_equity  = $total_liabilities + $total_equity;
$is_balanced        = abs($total_assets - $total_liab_equity) < 0.01;

// Previous-balance section totals (same arithmetic applied to prev_bal column)
$prev_total_assets          = array_sum(array_column($buckets['asset'],     'prev_bal'));
$prev_total_liabilities     = array_sum(array_column($buckets['liability'], 'prev_bal'));
$prev_total_equity_accounts = array_sum(array_column($buckets['equity'],    'prev_bal'));
$prev_current_pl            = $prev_total_assets - ($prev_total_liabilities + $prev_total_equity_accounts);
$prev_total_equity          = $prev_total_equity_accounts + $prev_current_pl;
$prev_total_liab_equity     = $prev_total_liabilities + $prev_total_equity;

// ── 7. Helpers ────────────────────────────────────────────────────────────────
if (!function_exists('bsg_fmt_date')) {
    function bsg_fmt_date(string $ymd): string {
        $d = DateTime::createFromFormat('Y-m-d', $ymd);
        return $d ? $d->format('d/m/Y') : htmlspecialchars($ymd);
    }
}
if (!function_exists('bsg_fmt_rp')) {
    function bsg_fmt_rp(float $v): string {
        $n = 'Rp ' . number_format(abs($v), 0, ',', '.');
        return $v < 0 ? '(' . $n . ')' : $n;
    }
}
if (!function_exists('bsg_fmt')) {
    function bsg_fmt(float $v): string {
        $n = number_format(abs($v), 0, ',', '.');
        return $v < 0 ? '(' . $n . ')' : $n;
    }
}

// Render group rows for one section — each row drills into Balance Sheet Detail.
function bsg_render_rows(array $bucket, bool $asset_side): void {
    global $company_id, $estate_id, $division_id, $date_from, $date_to;
    foreach ($bucket as $item) {
        $variance   = $item['balance'] - $item['prev_bal'];
        $pct        = $item['prev_bal'] != 0 ? ($variance / abs($item['prev_bal'])) * 100 : null;
        $bal_class  = $asset_side
            ? ($item['balance'] >= 0 ? 'text-success' : 'text-danger')
            : ($item['balance'] >= 0 ? ''             : 'text-danger');
        $prev_class = $asset_side
            ? ($item['prev_bal'] >= 0 ? 'text-primary' : 'text-danger')
            : ($item['prev_bal'] >= 0 ? 'text-muted'   : 'text-danger');
        $var_class  = $variance > 0 ? 'text-success' : ($variance < 0 ? 'text-danger' : 'text-muted');

        $drill_url = '?' . http_build_query(array_filter([
            'report'          => 'balance_sheet',
            'company_id'      => $company_id,
            'estate_id'       => $estate_id,
            'division_id'     => $division_id,
            'date_from'       => $date_from,
            'date_to'         => $date_to,
            'filter_group_id' => $item['group_id'],
        ], fn($v) => $v !== '' && $v !== null));

        echo '<tr class="bsg-drill-row" style="cursor:pointer;"'
           . ' onclick="window.location=\'' . htmlspecialchars($drill_url, ENT_QUOTES) . '\'"'
           . ' title="Click to view accounts in: ' . htmlspecialchars($item['name'], ENT_QUOTES) . '">';
        echo '<td class="ps-4">'
           . '<i class="bi bi-zoom-in text-muted me-1" style="font-size:0.75rem;"></i>'
           . htmlspecialchars($item['name']) . '</td>';
        echo '<td class="text-end ' . $prev_class . '" style="font-size:0.9rem;">' . bsg_fmt($item['prev_bal']) . '</td>';
        echo '<td class="text-end ' . $bal_class . '">' . bsg_fmt($item['balance']) . '</td>';
        echo '<td class="text-end ' . $var_class . '" style="font-size:0.9rem;">' . bsg_fmt($variance) . '</td>';
        echo '<td class="text-end text-muted" style="font-size:0.85rem;">'
             . ($pct !== null ? number_format($pct, 1) . '%' : '–') . '</td>';
        echo '</tr>';
    }
}
?>

<!-- ── Header bar ────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h5 class="mb-0 fw-semibold">
            <i class="bi bi-bank" style="color:#166c82;"></i>
            <?php echo __('bsg_title'); ?>
            <span class="badge bg-secondary ms-1" style="font-size:0.65em;"><?php echo __('bsg_badge'); ?></span>
        </h5>
        <div class="text-muted small mt-1">
            <?php echo __('bsg_as_at'); ?>: <strong><?= bsg_fmt_date($date_to) ?></strong>
            <?php if ($is_balanced): ?>
                <span class="badge bg-success ms-2"><i class="bi bi-check-circle"></i> <?php echo __('bsg_balanced_badge'); ?></span>
            <?php endif; ?>
            <a href="?report=balance_sheet&<?= http_build_query(array_filter(['company_id'=>$company_id,'estate_id'=>$estate_id,'division_id'=>$division_id,'date_from'=>$date_from,'date_to'=>$date_to])) ?>"
               class="badge bg-outline-secondary ms-2 text-decoration-none border" style="color:#555;">
                <i class="bi bi-list-ul"></i> <?php echo __('bsg_view_by_account'); ?>
            </a>
        </div>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="exportToExcel('balance_sheet_group')" class="btn btn-sm btn-success">
            <i class="bi bi-file-earmark-excel"></i> Export Excel
        </button>
        <button onclick="printBalanceSheet()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer"></i> <?php echo __('bsg_print'); ?>
        </button>
    </div>
</div>

<!-- ── KPI cards ─────────────────────────────────────────────────────────── -->
<div class="row g-2 mb-3">
    <?php
    $bsg_cards = [
        ['key' => 'bsg_total_assets',      'val' => $total_assets,      'pos_color' => '#1565c0', 'sub' => null],
        ['key' => 'bsg_total_liabilities', 'val' => $total_liabilities, 'pos_color' => '#c62828', 'sub' => null],
        ['key' => 'bsg_total_equity',      'val' => $total_equity,      'pos_color' => '#2e7d32', 'sub' => 'bsg_incl_pl'],
    ];
    foreach ($bsg_cards as $bc):
        $bg = $bc['val'] >= 0 ? $bc['pos_color'] : '#e65100';
    ?>
    <div class="col-md-4">
        <div class="card text-white h-100" style="background-color:<?= $bg ?>;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold lh-sm" style="font-size:0.85rem;">
                    <?php echo __($bc['key']); ?><?= $bc['val'] < 0 ? ' ' . __('bsg_abnormal') : '' ?>
                </div>
                <div class="fs-6 fw-bold mt-1"><?= bsg_fmt_rp($bc['val']) ?></div>
                <?php if ($bc['sub']): ?>
                    <div class="opacity-75" style="font-size:0.72rem;"><?php echo __($bc['sub']); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Print area wrapper (table only) ─────────────────────────────────────── -->
<div id="bsg-print-area"
     data-date-to="<?= htmlspecialchars($date_to) ?>">

<!-- ── Balance Sheet Table ───────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header py-2 text-white" style="background-color:#166c82;">
        <span class="fw-semibold"><i class="bi bi-table"></i>
            <?php echo __('bsg_title'); ?> — <?php echo __('bsg_as_at'); ?> <?= bsg_fmt_date($date_to) ?>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" id="bsgTable" style="font-size:0.93rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:40%"><?php echo __('bsg_col_group'); ?></th>
                        <th class="text-end text-muted" style="width:15%;font-size:0.85rem;"><?php echo __('bsg_col_prev'); ?></th>
                        <th class="text-end" style="width:15%"><?php echo __('bsg_col_ending'); ?></th>
                        <th class="text-end text-muted" style="width:15%;font-size:0.85rem;">Variance</th>
                        <th class="text-end text-muted" style="width:15%;font-size:0.85rem;">%</th>
                    </tr>
                </thead>
                <tbody>

                    <?php
                    // Helper: variance + % cells for total rows
                    function bsg_var_cells(float $ending, float $prev): string {
                        $var = $ending - $prev;
                        $pct = $prev != 0 ? ($var / abs($prev)) * 100 : null;
                        $vc  = $var > 0 ? 'text-success' : ($var < 0 ? 'text-danger' : 'text-muted');
                        return '<td class="text-end py-1 ' . $vc . '" style="font-size:0.9rem;">' . bsg_fmt($var) . '</td>'
                             . '<td class="text-end py-1 text-muted" style="font-size:0.85rem;">'
                             . ($pct !== null ? number_format($pct, 1) . '%' : '–') . '</td>';
                    }
                    ?>

                    <!-- ASSETS -->
                    <tr style="background-color:#e3f2fd;">
                        <td colspan="5" class="py-1"><strong><i class="bi bi-building text-primary"></i> <?php echo __('bsg_sec_assets'); ?></strong></td>
                    </tr>
                    <?php if (empty($buckets['asset'])): ?>
                    <tr><td colspan="5" class="text-center text-muted ps-4 py-1 small"><?php echo __('bsg_no_assets'); ?></td></tr>
                    <?php else: bsg_render_rows($buckets['asset'], true); endif; ?>
                    <tr style="background-color:<?= $total_assets >= 0 ? '#bbdefb' : '#ffe0b2' ?>; font-weight:600; border-top:2px solid #90caf9;">
                        <td class="py-1"><?php echo __('bsg_total_assets_row'); ?><?= $total_assets < 0 ? ' ' . __('pl_abnormal') : '' ?></td>
                        <td class="text-end py-1 text-muted"><?= bsg_fmt_rp($prev_total_assets) ?></td>
                        <td class="text-end py-1 <?= $total_assets >= 0 ? 'text-primary' : 'text-danger' ?>"><?= bsg_fmt_rp($total_assets) ?></td>
                        <?= bsg_var_cells($total_assets, $prev_total_assets) ?>
                    </tr>

                    <!-- LIABILITIES -->
                    <tr style="background-color:#fce4e4;">
                        <td colspan="5" class="py-1"><strong><i class="bi bi-arrow-left-circle-fill text-danger"></i> <?php echo __('bsg_sec_liabilities'); ?></strong></td>
                    </tr>
                    <?php if (empty($buckets['liability'])): ?>
                    <tr><td colspan="5" class="text-center text-muted ps-4 py-1 small"><?php echo __('bsg_no_liabilities'); ?></td></tr>
                    <?php else: bsg_render_rows($buckets['liability'], false); endif; ?>
                    <tr style="background-color:<?= $total_liabilities >= 0 ? '#ffcdd2' : '#c8e6c9' ?>; font-weight:600; border-top:2px solid #ef9a9a;">
                        <td class="py-1"><?php echo __('bsg_total_liab_row'); ?><?= $total_liabilities < 0 ? ' ' . __('pl_abnormal') : '' ?></td>
                        <td class="text-end py-1 text-muted"><?= bsg_fmt_rp($prev_total_liabilities) ?></td>
                        <td class="text-end py-1 <?= $total_liabilities >= 0 ? 'text-danger' : 'text-success' ?>"><?= bsg_fmt_rp($total_liabilities) ?></td>
                        <?= bsg_var_cells($total_liabilities, $prev_total_liabilities) ?>
                    </tr>

                    <!-- EQUITY -->
                    <tr style="background-color:#e8f5e9;">
                        <td colspan="5" class="py-1"><strong><i class="bi bi-graph-up-arrow text-success"></i> <?php echo __('bsg_sec_equity'); ?></strong></td>
                    </tr>
                    <?php if (empty($buckets['equity'])): ?>
                    <tr><td colspan="5" class="text-center text-muted ps-4 py-1 small"><?php echo __('bsg_no_equity'); ?></td></tr>
                    <?php else: bsg_render_rows($buckets['equity'], false); endif; ?>

                    <!-- Current Period P/L — balancing plug -->
                    <?php
                    $cpl_var = $current_pl - $prev_current_pl;
                    $cpl_pct = $prev_current_pl != 0 ? ($cpl_var / abs($prev_current_pl)) * 100 : null;
                    $cpl_vc  = $cpl_var > 0 ? 'text-success' : ($cpl_var < 0 ? 'text-danger' : 'text-muted');
                    ?>
                    <tr class="fst-italic" style="background-color:#f1f8e9;">
                        <td class="ps-4 py-1"><?php echo __('bsg_current_pl'); ?></td>
                        <td class="text-end py-1 <?= $prev_current_pl >= 0 ? 'text-muted' : 'text-danger' ?>"><?= bsg_fmt($prev_current_pl) ?></td>
                        <td class="text-end py-1 <?= $current_pl >= 0 ? 'text-success' : 'text-danger' ?>"><?= bsg_fmt($current_pl) ?></td>
                        <td class="text-end py-1 <?= $cpl_vc ?>" style="font-size:0.9rem;"><?= bsg_fmt($cpl_var) ?></td>
                        <td class="text-end py-1 text-muted" style="font-size:0.85rem;"><?= $cpl_pct !== null ? number_format($cpl_pct, 1) . '%' : '–' ?></td>
                    </tr>

                    <tr style="background-color:<?= $total_equity >= 0 ? '#c8e6c9' : '#ffe0b2' ?>; font-weight:600; border-top:2px solid #a5d6a7;">
                        <td class="py-1"><?php echo __('bsg_total_equity_row'); ?></td>
                        <td class="text-end py-1 text-muted"><?= bsg_fmt_rp($prev_total_equity) ?></td>
                        <td class="text-end py-1 <?= $total_equity >= 0 ? 'text-success' : 'text-danger' ?>"><?= bsg_fmt_rp($total_equity) ?></td>
                        <?= bsg_var_cells($total_equity, $prev_total_equity) ?>
                    </tr>

                    <!-- TOTAL LIABILITIES + EQUITY -->
                    <tr style="background-color:#b2dfdb; font-weight:700; font-size:0.95rem; border-top:2px solid #80cbc4;">
                        <td class="py-2"><?php echo __('bsg_total_liab_equity'); ?></td>
                        <td class="text-end py-2 text-muted"><?= bsg_fmt_rp($prev_total_liab_equity) ?></td>
                        <td class="text-end py-2 text-success"><?= bsg_fmt_rp($total_liab_equity) ?></td>
                        <?= bsg_var_cells($total_liab_equity, $prev_total_liab_equity) ?>
                    </tr>

                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /#bsg-print-area -->

<style>
.bsg-drill-row:hover td { background-color:#e8f4fd !important; }
</style>

<!-- ── Unmapped accounts warning ─────────────────────────────────────────── -->
<?php
$unmapped_count = $pdo->prepare("
    SELECT COUNT(*)
    FROM journal_entry_lines      jel
    JOIN journal_entries          je  ON je.id  = jel.journal_entry_id
    JOIN general_ledger_accounts  gla ON gla.id = jel.gl_account_id
    WHERE je.status = 'posted'
      AND je.entry_date <= :dt
      AND gla.account_type IN ('asset','liability','equity')
      AND gla.financial_group_id IS NULL
");
$unmapped_count->execute([':dt' => $date_to]);
$n_unmapped = (int)$unmapped_count->fetchColumn();
if ($n_unmapped > 0):
?>
<div class="alert alert-warning mt-3 no-print">
    <i class="bi bi-exclamation-triangle"></i>
    <?php echo sprintf(__('bsg_unmapped_warn'), $n_unmapped); ?>
    <a href="../account_groups.php?tab=mapping" class="alert-link"><?php echo __('bsg_unmapped_link'); ?></a>
</div>
<?php endif; ?>

<!-- ── Accounting Equation Check ─────────────────────────────────────────── -->
<?php if ($total_assets != 0 || $total_liab_equity != 0): ?>
<div class="row g-2 mt-2 no-print">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted mb-2"><?php echo __('bsg_eq_title'); ?></h6>
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-center px-3">
                        <div class="text-muted small"><?php echo __('bsg_eq_assets'); ?></div>
                        <strong class="<?= $total_assets >= 0 ? 'text-primary' : 'text-danger' ?>"><?= bsg_fmt_rp($total_assets) ?></strong>
                    </div>
                    <div class="text-muted fw-bold">=</div>
                    <div class="text-center px-3">
                        <div class="text-muted small"><?php echo __('bsg_eq_liabilities'); ?></div>
                        <strong class="text-danger"><?= bsg_fmt_rp($total_liabilities) ?></strong>
                    </div>
                    <div class="text-muted fw-bold">+</div>
                    <div class="text-center px-3">
                        <div class="text-muted small"><?php echo __('bsg_eq_equity'); ?></div>
                        <strong class="text-success"><?= bsg_fmt_rp($total_equity) ?></strong>
                    </div>
                    <div class="ms-3">
                        <?php if ($is_balanced): ?>
                        <span class="badge bg-success fs-6"><i class="bi bi-check-circle"></i> <?php echo __('bsg_eq_balanced'); ?></span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark fs-6">
                            <i class="bi bi-exclamation-triangle"></i>
                            <?php echo __('bsg_eq_off_by'); ?> <?= bsg_fmt_rp(abs($total_assets - $total_liab_equity)) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body text-center py-2">
                <div class="text-muted" style="font-size:0.82rem;"><?php echo __('bsg_debt_equity_ratio'); ?></div>
                <?php if ($total_equity != 0): ?>
                <div class="fs-5 fw-bold text-primary"><?= number_format($total_liabilities / $total_equity, 2) ?>x</div>
                <div class="text-muted" style="font-size:0.75rem;"><?php echo __('bsg_debt_equity_lbl'); ?></div>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body text-center py-2">
                <div class="text-muted" style="font-size:0.82rem;"><?php echo __('bsg_equity_ratio'); ?></div>
                <?php if ($total_assets != 0): ?>
                <div class="fs-5 fw-bold <?= ($total_equity / $total_assets) >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= number_format(($total_equity / $total_assets) * 100, 1) ?>%
                </div>
                <div class="text-muted" style="font-size:0.75rem;"><?php echo __('bsg_equity_ratio_lbl'); ?></div>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php // Powered by IBM Bob ?>
