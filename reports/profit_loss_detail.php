<?php
// Detail Profit & Loss Statement — per GL account
//
// Columns: Code | Account | Current Period | Year to Date

// ── 0. Drill-down filters ─────────────────────────────────────────────────────

// From BS-style group drill (filter_group_id)
$pld_filter_group_id = isset($_GET['filter_group_id']) && ctype_digit((string)$_GET['filter_group_id'])
    ? (int)$_GET['filter_group_id'] : null;

// From P&L Summary drill (from=profit_loss + filter_account_type)
$pld_from_pl = (($_GET['from'] ?? '') === 'profit_loss');

// From General Ledger drill (from=general_ledger)
$pld_from_gl = (($_GET['from'] ?? '') === 'general_ledger');

// Account-type filter passed from P&L Summary rows
$_valid_pl_types = ['revenue','cogs','operating_expense','depreciation','other_income','other_expenses','tax'];
$pld_filter_account_type = (isset($_GET['filter_account_type']) && in_array($_GET['filter_account_type'], $_valid_pl_types))
    ? $_GET['filter_account_type'] : null;

// Human-readable section label for the banner
$_pl_section_labels = [
    'revenue'           => 'Revenue',
    'cogs'              => 'Cost of Goods Sold',
    'operating_expense' => 'Operating Expenses',
    'depreciation'      => 'Depreciation & Amortization',
    'other_income'      => 'Other Income',
    'other_expenses'    => 'Other Expenses',
    'tax'               => 'Tax Expense',
];
$pld_filter_section_name = $pld_filter_account_type ? ($_pl_section_labels[$pld_filter_account_type] ?? $pld_filter_account_type) : null;

// Resolve group name for the banner (BS-style)
$pld_filter_group_name = null;
if ($pld_filter_group_id) {
    $gn = $pdo->prepare("SELECT group_name FROM financial_account_groups WHERE id = ? LIMIT 1");
    $gn->execute([$pld_filter_group_id]);
    $pld_filter_group_name = $gn->fetchColumn() ?: null;
}

// ── 1. Current-period query ──────────────────────────────────────────────────
$_pld_wc = isset($pl_where_clause) ? $pl_where_clause : $where_clause;
$_pld_pm = isset($pl_params)       ? $pl_params       : $params;

if ($pld_filter_group_id) {
    $_pld_wc .= " AND gla.financial_group_id = :pld_fgi";
    $_pld_pm[':pld_fgi'] = $pld_filter_group_id;
}
// Filter to a single P&L section when drilled from P&L Summary
if ($pld_filter_account_type) {
    // 'operating_expense' and 'expense' are both opex — include both when section is operating_expense
    if ($pld_filter_account_type === 'operating_expense') {
        $_pld_wc .= " AND gla.account_type IN ('operating_expense','expense','depreciation')";
    } else {
        $_pld_wc .= " AND gla.account_type = :pld_fat";
        $_pld_pm[':pld_fat'] = $pld_filter_account_type;
    }
}

// Determine the IN list for account_type (full list when no section filter, restricted otherwise)
$_pld_type_in = $pld_filter_account_type
    ? '' // already appended above
    : "AND gla.account_type IN ('revenue','cogs','operating_expense','expense','depreciation','other_income','other_expenses','tax')";

$pld_sql = "
    SELECT
        gla.id            AS gl_id,
        gla.account_code,
        gla.account_name,
        gla.account_type,
        fag.group_name    AS account_group,
        fag.group_code    AS group_code,
        SUM(jel.debit_amount)  AS total_debit,
        SUM(jel.credit_amount) AS total_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
    LEFT JOIN financial_account_groups fag ON fag.id         = gla.financial_group_id
    WHERE $_pld_wc
      $_pld_type_in
    GROUP BY gla.id, gla.account_code, gla.account_name, gla.account_type,
             fag.group_name, fag.group_code
    HAVING (SUM(jel.debit_amount) + SUM(jel.credit_amount)) > 0
    ORDER BY gla.account_type, gla.account_code
";
$pld_stmt = $pdo->prepare($pld_sql);
$pld_stmt->execute($_pld_pm);
$pld_rows = $pld_stmt->fetchAll();

// ── 2. YTD query (Jan-1 of date_to year → date_to) ──────────────────────────
$_pld_ytd_year  = substr($date_to, 0, 4);
$_pld_ytd_jan1  = $_pld_ytd_year . '-01-01';

$_pld_ytd_cond  = ["je.status = 'posted'", "je.entry_date >= :ytd_from", "je.entry_date <= :ytd_to"];
$_pld_ytd_params = [':ytd_from' => $_pld_ytd_jan1, ':ytd_to' => $date_to];
if (!empty($company_id))  { $_pld_ytd_cond[] = "je.company_id = :company_id";         $_pld_ytd_params[':company_id']  = $company_id; }
if (!empty($estate_id))   { $_pld_ytd_cond[] = "je.business_unit_id = :estate_id";    $_pld_ytd_params[':estate_id']   = $estate_id; }
if (!empty($division_id)) { $_pld_ytd_cond[] = "je.division_id = :division_id";       $_pld_ytd_params[':division_id'] = $division_id; }
if ($pld_filter_account_type) {
    if ($pld_filter_account_type === 'operating_expense') {
        $_pld_ytd_cond[] = "gla.account_type IN ('operating_expense','expense','depreciation')";
    } else {
        $_pld_ytd_cond[] = "gla.account_type = :ytd_fat";
        $_pld_ytd_params[':ytd_fat'] = $pld_filter_account_type;
    }
}
$_pld_ytd_wc = implode(' AND ', $_pld_ytd_cond);

$pld_ytd_sql = "
    SELECT
        gla.id            AS gl_id,
        gla.account_type,
        SUM(jel.debit_amount)  AS ytd_debit,
        SUM(jel.credit_amount) AS ytd_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
    WHERE $_pld_ytd_wc
      " . ($pld_filter_account_type ? '' : "AND gla.account_type IN ('revenue','cogs','operating_expense','expense','depreciation','other_income','other_expenses','tax')") . "
    GROUP BY gla.id, gla.account_type
";
$pld_ytd_stmt = $pdo->prepare($pld_ytd_sql);
$pld_ytd_stmt->execute($_pld_ytd_params);
$_pld_ytd_map = [];
foreach ($pld_ytd_stmt->fetchAll() as $_yr) {
    $dbt  = (float)$_yr['ytd_debit'];
    $cdt  = (float)$_yr['ytd_credit'];
    $type = $_yr['account_type'];
    $_pld_ytd_map[$_yr['gl_id']] = in_array($type, ['revenue','other_income']) ? ($cdt - $dbt) : ($dbt - $cdt);
}

// ── 3. Bucket rows ───────────────────────────────────────────────────────────
$pld_buckets = ['revenue' => [], 'cogs' => [], 'opex' => [], 'oi' => [], 'oe' => [], 'tax' => []];

// Base query string for GL drill-down links (carry all org + date filters)
$_pld_gl_qs_base = http_build_query(array_filter([
    'company_id'  => $company_id,
    'estate_id'   => $estate_id,
    'division_id' => $division_id,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
], fn($v) => $v !== '' && $v !== null));

foreach ($pld_rows as $row) {
    $dbt  = (float)$row['total_debit'];
    $cdt  = (float)$row['total_credit'];
    $gid  = $row['gl_id'];
    $item = [
        'gl_id'  => $gid,
        'code'   => $row['account_code'],
        'name'   => $row['account_name'],
        'group'  => $row['account_group'],
        'gcode'  => $row['group_code'],
        'ytd'    => $_pld_ytd_map[$gid] ?? 0,
    ];
    switch ($row['account_type']) {
        case 'revenue':
            $item['net'] = $cdt - $dbt; $pld_buckets['revenue'][] = $item; break;
        case 'cogs':
            $item['net'] = $dbt - $cdt; $pld_buckets['cogs'][] = $item; break;
        case 'operating_expense':
        case 'expense':
            $item['net'] = $dbt - $cdt; $pld_buckets['opex'][] = $item; break;
        case 'other_income':
            $item['net'] = $cdt - $dbt; $pld_buckets['oi'][] = $item; break;
        case 'other_expenses':
            $item['net'] = $dbt - $cdt; $pld_buckets['oe'][] = $item; break;
        case 'tax':
            $item['net'] = $dbt - $cdt; $pld_buckets['tax'][] = $item; break;
    }
}

// ── 4. Section totals ─────────────────────────────────────────────────────────
$pld_total_revenue = array_sum(array_column($pld_buckets['revenue'], 'net'));
$pld_total_cogs    = array_sum(array_column($pld_buckets['cogs'],    'net'));
$pld_total_opex    = array_sum(array_column($pld_buckets['opex'],    'net'));
$pld_total_oi      = array_sum(array_column($pld_buckets['oi'],      'net'));
$pld_total_oe      = array_sum(array_column($pld_buckets['oe'],      'net'));
$pld_total_tax     = array_sum(array_column($pld_buckets['tax'],     'net'));

$pld_ytd_revenue = array_sum(array_column($pld_buckets['revenue'], 'ytd'));
$pld_ytd_cogs    = array_sum(array_column($pld_buckets['cogs'],    'ytd'));
$pld_ytd_opex    = array_sum(array_column($pld_buckets['opex'],    'ytd'));
$pld_ytd_oi      = array_sum(array_column($pld_buckets['oi'],      'ytd'));
$pld_ytd_oe      = array_sum(array_column($pld_buckets['oe'],      'ytd'));
$pld_ytd_tax     = array_sum(array_column($pld_buckets['tax'],     'ytd'));

$pld_gross_profit      = $pld_total_revenue - $pld_total_cogs;
$pld_op_profit         = $pld_gross_profit  - $pld_total_opex;
$pld_profit_before_tax = $pld_op_profit + $pld_total_oi - $pld_total_oe;
$pld_npat              = $pld_profit_before_tax - $pld_total_tax;

$pld_ytd_gross_profit      = $pld_ytd_revenue - $pld_ytd_cogs;
$pld_ytd_op_profit         = $pld_ytd_gross_profit - $pld_ytd_opex;
$pld_ytd_profit_before_tax = $pld_ytd_op_profit + $pld_ytd_oi - $pld_ytd_oe;
$pld_ytd_npat              = $pld_ytd_profit_before_tax - $pld_ytd_tax;

// ── Helpers ──────────────────────────────────────────────────────────────────
function pld_rp(float $v): string {
    return ($v < 0 ? 'Rp -' : 'Rp ') . number_format(abs($v), 0, ',', '.');
}
function pld_num(float $v): string {
    return ($v < 0 ? '-' : '') . number_format(abs($v), 0, ',', '.');
}
function pld_fmt_date(string $ymd): string {
    $d = DateTime::createFromFormat('Y-m-d', $ymd);
    return $d ? $d->format('d/m/Y') : htmlspecialchars($ymd);
}

// Render detail rows for one bucket, grouped by account_group
function pld_render_rows(array $bucket, bool $credit_normal, string $gl_qs_base = ''): void {
    foreach ($bucket as $item) {
        // GL drill-down URL for this specific account
        $gl_url = $gl_qs_base
            ? ('?' . $gl_qs_base . '&report=general_ledger&gl_account_id=' . (int)$item['gl_id'] . '&from=profit_loss_detail')
            : '';
        // Make the entire row clickable when a GL URL is available
        if ($gl_url) {
            $href_url = htmlspecialchars($gl_url, ENT_QUOTES);
            $js_url   = str_replace('&amp;', '&', $href_url);
            echo '<tr class="pld-row-clickable" style="cursor:pointer;" onclick="window.location=\'' . $js_url . '\'">';
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
        echo '<td class="text-end">' . pld_num($item['net']) . '</td>';
        echo '<td class="text-end">' . pld_num($item['ytd']) . '</td>';
        echo '<td class="text-center no-print" style="width:38px;">';
        if ($gl_url) {
            $href_url = htmlspecialchars($gl_url, ENT_QUOTES);
            echo '<a href="' . $href_url . '" '
               . 'class="btn btn-sm p-0 lh-1" style="color:#166c82;" '
               . 'title="' . __('pld_gl_drill_tooltip') . '" onclick="event.stopPropagation()"><i class="bi bi-journal-richtext"></i></a>';
        }
        echo '</td>';
        echo '</tr>';
    }
}
?>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
<?php
$_pld_back_url = '?' . http_build_query(array_filter([
    'report'      => 'profit_loss',
    'company_id'  => $company_id,
    'estate_id'   => $estate_id,
    'division_id' => $division_id,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
], fn($v) => $v !== '' && $v !== null));
?>
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h5 class="mb-0 fw-semibold">
            <i class="bi bi-graph-up-arrow" style="color:#166c82;"></i>
            <?php echo __('pld_title'); ?>
            <?php if ($pld_filter_section_name): ?>
                <span class="badge ms-2 fw-normal" style="font-size:0.6em;background-color:#166c82;">
                    <i class="bi bi-funnel-fill"></i> <?= htmlspecialchars($pld_filter_section_name) ?>
                </span>
            <?php elseif ($pld_filter_group_name): ?>
                <span class="badge ms-2 fw-normal" style="font-size:0.6em;background-color:#166c82;">
                    <i class="bi bi-funnel-fill"></i> <?= htmlspecialchars($pld_filter_group_name) ?>
                </span>
            <?php endif; ?>
        </h5>
        <div class="text-muted small mt-1">
            <?php echo __('pl_period'); ?>:
            <strong><?= pld_fmt_date($date_from) ?></strong> &rarr; <strong><?= pld_fmt_date($date_to) ?></strong>
        </div>
    </div>
    <div class="d-flex gap-2 no-print">
        <?php if ($pld_from_gl): ?>
            <?php
            // Back to General Ledger — restore the single-account GL view if gl_account_id was present
            $_pld_back_gl_url = '?' . http_build_query(array_filter([
                'report'       => 'general_ledger',
                'company_id'   => $company_id,
                'estate_id'    => $estate_id,
                'division_id'  => $division_id,
                'date_from'    => $date_from,
                'date_to'      => $date_to,
            ], fn($v) => $v !== '' && $v !== null));
            ?>
            <a href="<?= htmlspecialchars($_pld_back_gl_url) ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-arrow-left"></i> <?php echo __('pld_btn_back_gl'); ?>
            </a>
        <?php elseif ($pld_from_pl || $pld_filter_group_name): ?>
        <a href="<?= htmlspecialchars($_pld_back_url) ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-arrow-left"></i> <?php echo __('pld_btn_back_pl'); ?>
        </a>
        <?php endif; ?>
        <button onclick="exportToExcel('profit_loss_detail')" class="btn btn-sm btn-success">
            <i class="bi bi-file-earmark-excel"></i> Export Excel
        </button>
        <button onclick="printDetailPL()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer"></i> <?php echo __('pl_print'); ?>
        </button>
    </div>
</div>

<?php if ($pld_filter_section_name): ?>
<div class="alert alert-info d-flex align-items-center gap-3 py-2 no-print" style="border-left:4px solid #166c82;">
    <i class="bi bi-funnel-fill" style="color:#166c82;font-size:1.1rem;"></i>
    <div class="flex-grow-1">
        <?php echo __('pld_filter_section_label'); ?>: <strong><?= htmlspecialchars($pld_filter_section_name) ?></strong>
            — <?php echo __('pld_filter_section_showing'); ?> <strong><?= htmlspecialchars($pld_filter_section_name) ?></strong> <?php echo __('pld_filter_section_accts'); ?>
            <?php echo pld_fmt_date($date_from) ?> &rarr; <?= pld_fmt_date($date_to) ?>
    </div>
</div>
<?php elseif ($pld_filter_group_name): ?>
<div class="alert alert-info d-flex align-items-center gap-3 py-2 no-print" style="border-left:4px solid #166c82;">
    <i class="bi bi-funnel-fill" style="color:#166c82;font-size:1.1rem;"></i>
    <div class="flex-grow-1">
        <?php echo __('pld_filter_group_label'); ?>: <strong><?= htmlspecialchars($pld_filter_group_name) ?></strong>
            — <?php echo __('pld_filter_group_showing'); ?> <?= pld_fmt_date($date_from) ?> &rarr; <?= pld_fmt_date($date_to) ?>
    </div>
</div>
<?php endif; ?>

<!-- ── 4 KPI summary cards ─────────────────────────────────────────────────── -->
<?php if (!$pld_filter_group_id && !$pld_filter_account_type): ?>
<div class="row g-2 mb-3">
    <?php
    $pld_cards = [
        ['key' => 'pl_gross_profit', 'val' => $pld_gross_profit,      'formula' => 'pl_gross_formula', 'pos_color' => '#1565c0'],
        ['key' => 'pl_op_profit',    'val' => $pld_op_profit,         'formula' => 'pl_op_formula',    'pos_color' => '#00695c'],
        ['key' => 'pl_pbt',          'val' => $pld_profit_before_tax, 'formula' => 'pl_pbt_formula',   'pos_color' => '#4527a0'],
        ['key' => 'pl_npat',         'val' => $pld_npat,              'formula' => 'pl_npat_formula',  'pos_color' => '#004d40'],
    ];
    foreach ($pld_cards as $c):
        $bg = $c['val'] >= 0 ? $c['pos_color'] : '#c62828';
    ?>
    <div class="col-6 col-md-3">
        <div class="card text-white h-100" style="background-color:<?= $bg ?>;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold lh-sm" style="font-size:0.85rem;"><?php echo __($c['key']); ?></div>
                <div class="fs-6 fw-bold mt-1"><?= pld_rp($c['val']) ?></div>
                <div class="opacity-75" style="font-size:0.72rem;"><?php echo __($c['formula']); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Print area wrapper ───────────────────────────────────────────────────── -->
<div id="pld-print-area"
     data-date-from="<?= htmlspecialchars($date_from) ?>"
     data-date-to="<?= htmlspecialchars($date_to) ?>"
     data-ytd-year="<?= htmlspecialchars($_pld_ytd_year) ?>"
     data-i18n="<?= htmlspecialchars(json_encode([
         'title'   => __('pld_title'),
         'period'  => __('pl_period'),
         'print_by'=> 'Print by',
         'datetime'=> 'Date/Time',
     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">

<!-- ── Detail P&L Table ─────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header py-2 text-white" style="background-color:#166c82;">
        <span class="fw-semibold">
            <i class="bi bi-table"></i> <?php echo __('pld_title'); ?>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" id="pldTable" style="font-size:0.9rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:10%"><?php echo __('pld_col_code'); ?></th>
                        <th style="width:44%"><?php echo __('pl_col_account'); ?></th>
                        <th class="text-end" style="width:21%"><?php echo __('pl_col_current_period'); ?></th>
                        <th class="text-end" style="width:21%"><?php echo __('pl_col_ytd'); ?></th>
                        <th class="no-print" style="width:4%;text-align:center;" title="General Ledger drill-down"><i class="bi bi-journal-richtext" style="color:#166c82;"></i></th>
                    </tr>
                </thead>
                <tbody>

<?php
// $_pld_show_full: show all sections + computed subtotals only on the unfiltered view
$_pld_show_full = !$pld_filter_group_id && !$pld_filter_account_type;
?>

<!-- ══ REVENUE ══════════════════════════════════════════════════════════════ -->
<?php if ($_pld_show_full || !empty($pld_buckets['revenue'])): ?>
<tr style="background-color:#e8f5e9;">
    <td colspan="5" class="py-1"><strong><i class="bi bi-arrow-up-circle-fill text-success"></i> <?php echo __('pl_sec_revenue'); ?></strong></td>
</tr>
<?php if (empty($pld_buckets['revenue'])): ?>
<tr><td colspan="5" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_revenue'); ?></td></tr>
<?php else: pld_render_rows($pld_buckets['revenue'], true, $_pld_gl_qs_base); endif; ?>
<tr style="background-color:<?= $pld_total_revenue >= 0 ? '#c8e6c9' : '#ffe0b2' ?>; font-weight:600;">
    <td colspan="2" class="py-1"><?php echo __('pl_total_revenue'); ?><?php echo $pld_total_revenue < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_total_revenue) ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_ytd_revenue) ?></td>
    <td class="no-print"></td>
</tr>
<?php endif; ?>

<!-- ══ COGS ══════════════════════════════════════════════════════════════════ -->
<?php if ($_pld_show_full || !empty($pld_buckets['cogs'])): ?>
<tr style="background-color:#f3e5f5;">
    <td colspan="5" class="py-1"><strong><i class="bi bi-box-seam-fill" style="color:#7b1fa2;"></i> <?php echo __('pl_sec_cogs'); ?></strong></td>
</tr>
<?php if (empty($pld_buckets['cogs'])): ?>
<tr><td colspan="5" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_cogs'); ?></td></tr>
<?php else: pld_render_rows($pld_buckets['cogs'], false, $_pld_gl_qs_base); endif; ?>
<tr style="background-color:<?= $pld_total_cogs >= 0 ? '#e1bee7' : '#c8e6c9' ?>; font-weight:600;">
    <td colspan="2" class="py-1"><?php echo __('pl_total_cogs'); ?><?php echo $pld_total_cogs < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_total_cogs) ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_ytd_cogs) ?></td>
    <td class="no-print"></td>
</tr>
<?php endif; ?>

<!-- ══ GROSS PROFIT ══════════════════════════════════════════════════════════ -->
<?php if ($_pld_show_full): ?>
<tr style="background-color:<?= $pld_gross_profit >= 0 ? '#bbdefb' : '#ffe0b2' ?>; font-weight:700; border-top:2px solid #90caf9;">
    <td colspan="2" class="py-1"><?php echo __('pl_gross_profit_row'); ?> <small class="fw-normal text-muted">(<?php echo __('pl_gross_formula_row'); ?>)</small></td>
    <td class="text-end py-1"><?= pld_rp($pld_gross_profit) ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_ytd_gross_profit) ?></td>
    <td class="no-print"></td>
</tr>
<?php endif; ?>

<!-- ══ OPERATING EXPENSES ════════════════════════════════════════════════════ -->
<?php if ($_pld_show_full || !empty($pld_buckets['opex'])): ?>
<tr style="background-color:#fce4e4;">
    <td colspan="5" class="py-1"><strong><i class="bi bi-arrow-down-circle-fill text-danger"></i> <?php echo __('pl_sec_opex'); ?></strong></td>
</tr>
<?php if (empty($pld_buckets['opex'])): ?>
<tr><td colspan="5" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_opex'); ?></td></tr>
<?php else: pld_render_rows($pld_buckets['opex'], false, $_pld_gl_qs_base); endif; ?>
<tr style="background-color:<?= $pld_total_opex >= 0 ? '#ffcdd2' : '#c8e6c9' ?>; font-weight:600;">
    <td colspan="2" class="py-1"><?php echo __('pl_total_opex'); ?><?php echo $pld_total_opex < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_total_opex) ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_ytd_opex) ?></td>
    <td class="no-print"></td>
</tr>
<?php endif; ?>

<!-- ══ OPERATING PROFIT ══════════════════════════════════════════════════════ -->
<?php if ($_pld_show_full): ?>
<tr style="background-color:<?= $pld_op_profit >= 0 ? '#b2dfdb' : '#ffe0b2' ?>; font-weight:700; border-top:2px solid #80cbc4;">
    <td colspan="2" class="py-1"><?php echo __('pl_op_profit_row'); ?> <small class="fw-normal text-muted">(<?php echo __('pl_op_formula_row'); ?>)</small></td>
    <td class="text-end py-1"><?= pld_rp($pld_op_profit) ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_ytd_op_profit) ?></td>
    <td class="no-print"></td>
</tr>
<?php endif; ?>

<!-- ══ OTHER INCOME ══════════════════════════════════════════════════════════ -->
<?php if ($_pld_show_full || !empty($pld_buckets['oi'])): ?>
<tr style="background-color:#e8eaf6;">
    <td colspan="5" class="py-1"><strong><i class="bi bi-plus-circle-fill" style="color:#3949ab;"></i> <?php echo __('pl_sec_oi'); ?></strong></td>
</tr>
<?php if (empty($pld_buckets['oi'])): ?>
<tr><td colspan="5" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_oi'); ?></td></tr>
<?php else: pld_render_rows($pld_buckets['oi'], true, $_pld_gl_qs_base); endif; ?>
<tr style="background-color:<?= $pld_total_oi >= 0 ? '#c5cae9' : '#ffe0b2' ?>; font-weight:600;">
    <td colspan="2" class="py-1"><?php echo __('pl_total_oi'); ?><?php echo $pld_total_oi < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_total_oi) ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_ytd_oi) ?></td>
    <td class="no-print"></td>
</tr>
<?php endif; ?>

<!-- ══ OTHER EXPENSES ════════════════════════════════════════════════════════ -->
<?php if ($_pld_show_full || !empty($pld_buckets['oe'])): ?>
<tr style="background-color:#fff3e0;">
    <td colspan="5" class="py-1"><strong><i class="bi bi-dash-circle-fill" style="color:#e65100;"></i> <?php echo __('pl_sec_oe'); ?></strong></td>
</tr>
<?php if (empty($pld_buckets['oe'])): ?>
<tr><td colspan="5" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_oe'); ?></td></tr>
<?php else: pld_render_rows($pld_buckets['oe'], false, $_pld_gl_qs_base); endif; ?>
<tr style="background-color:<?= $pld_total_oe >= 0 ? '#ffe0b2' : '#c8e6c9' ?>; font-weight:600;">
    <td colspan="2" class="py-1"><?php echo __('pl_total_oe'); ?><?php echo $pld_total_oe < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_total_oe) ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_ytd_oe) ?></td>
    <td class="no-print"></td>
</tr>
<?php endif; ?>

<!-- ══ PROFIT BEFORE TAX ═════════════════════════════════════════════════════ -->
<?php if ($_pld_show_full): ?>
<tr style="background-color:<?= $pld_profit_before_tax >= 0 ? '#d1c4e9' : '#ffe0b2' ?>; font-weight:700; border-top:2px solid #9575cd;">
    <td colspan="2" class="py-1"><?php echo __('pl_pbt_row'); ?> <small class="fw-normal text-muted">(<?php echo __('pl_pbt_formula_row'); ?>)</small></td>
    <td class="text-end py-1"><?= pld_rp($pld_profit_before_tax) ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_ytd_profit_before_tax) ?></td>
    <td class="no-print"></td>
</tr>
<?php endif; ?>

<!-- ══ TAX EXPENSE ═══════════════════════════════════════════════════════════ -->
<?php if ($_pld_show_full || !empty($pld_buckets['tax'])): ?>
<tr style="background-color:#eceff1;">
    <td colspan="5" class="py-1"><strong><i class="bi bi-receipt" style="color:#546e7a;"></i> <?php echo __('pl_sec_tax'); ?></strong></td>
</tr>
<?php if (empty($pld_buckets['tax'])): ?>
<tr><td colspan="5" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_tax'); ?></td></tr>
<?php else: pld_render_rows($pld_buckets['tax'], false, $_pld_gl_qs_base); endif; ?>
<tr style="background-color:<?= $pld_total_tax >= 0 ? '#cfd8dc' : '#c8e6c9' ?>; font-weight:600;">
    <td colspan="2" class="py-1"><?php echo __('pl_total_tax'); ?><?php echo $pld_total_tax < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_total_tax) ?></td>
    <td class="text-end py-1"><?= pld_rp($pld_ytd_tax) ?></td>
    <td class="no-print"></td>
</tr>
<?php endif; ?>

<!-- ══ NET PROFIT AFTER TAX ══════════════════════════════════════════════════ -->
<?php if ($_pld_show_full): ?>
<tr style="background-color:<?= $pld_npat >= 0 ? '#a5d6a7' : '#ef9a9a' ?>; font-weight:700; font-size:0.9rem; border-top:2px solid #388e3c;">
    <td colspan="2" class="py-2"><?php echo __('pl_npat_row'); ?> <small class="fw-normal text-muted">(<?php echo __('pl_npat_formula_row'); ?>)</small></td>
    <td class="text-end py-2"><?= pld_rp($pld_npat) ?></td>
    <td class="text-end py-2"><?= pld_rp($pld_ytd_npat) ?></td>
    <td class="no-print"></td>
</tr>
<?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /#pld-print-area -->

<style>.pld-row-clickable:hover { background-color: #e3f2fd !important; }</style>

<?php if ($_pld_show_full && $pld_total_revenue != 0): ?>
<!-- ── Margin cards (screen only) ───────────────────────────────────────────── -->
<div class="row g-2 mt-2 no-print">
    <?php
    $pld_margins = [
        ['key' => 'pl_gross_margin',  'val' => $pld_gross_profit,      'lbl' => 'pl_margin_gross_lbl',  'pos' => 'text-primary'],
        ['key' => 'pl_op_margin',     'val' => $pld_op_profit,         'lbl' => 'pl_margin_op_lbl',     'pos' => 'text-success'],
        ['key' => 'pl_pretax_margin', 'val' => $pld_profit_before_tax, 'lbl' => 'pl_margin_pretax_lbl', 'pos' => 'text-success'],
        ['key' => 'pl_net_margin',    'val' => $pld_npat,              'lbl' => 'pl_margin_net_lbl',    'pos' => 'text-success'],
    ];
    foreach ($pld_margins as $m):
        $pct = number_format(($m['val'] / $pld_total_revenue) * 100, 1);
        $cls = $m['val'] >= 0 ? $m['pos'] : 'text-danger';
    ?>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body text-center py-2 px-2">
                <div class="text-muted lh-sm" style="font-size:0.82rem;"><?php echo __($m['key']); ?></div>
                <div class="fs-5 fw-bold <?= $cls ?>"><?= $pct ?>%</div>
                <div class="text-muted" style="font-size:0.75rem;"><?php echo __($m['lbl']); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php // Powered by IBM Bob ?>
