<?php
// Profit and Loss Statement — compact, bilingual (EN / ID)
//
// Columns: Account | Current Period (net for date_from..date_to) | Year to Date (Jan-1 of year..date_to)

// ── 1. Current-period amounts (date_from → date_to) ─────────────────────────
$_pl_wc = isset($pl_where_clause) ? $pl_where_clause : $where_clause;
$_pl_pm = isset($pl_params)       ? $pl_params       : $params;

$sql = "
    SELECT
        gla.account_type,
        gla.account_name,
        gla.id            AS gl_account_id,
        SUM(jel.debit_amount)  AS total_debit,
        SUM(jel.credit_amount) AS total_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
    WHERE $_pl_wc
      AND gla.account_type IN (
            'revenue','cogs','operating_expense','expense',
            'depreciation','other_income','other_expenses','tax'
          )
    GROUP BY gla.id, gla.account_type, gla.account_name
    HAVING (SUM(jel.debit_amount) + SUM(jel.credit_amount)) > 0
    ORDER BY gla.account_type, gla.account_name
";
$stmt = $pdo->prepare($sql);
$stmt->execute($_pl_pm);
$rows = $stmt->fetchAll();

// ── 2. YTD amounts (Jan-1 of date_to's year → date_to) ──────────────────────
$_ytd_year     = substr($date_to, 0, 4);
$_ytd_jan1     = $_ytd_year . '-01-01';

// Build YTD WHERE — same org filters as PL but replace date range
$_ytd_conditions = ["je.status = 'posted'", "je.entry_date >= :ytd_from", "je.entry_date <= :ytd_to"];
$_ytd_params     = [':ytd_from' => $_ytd_jan1, ':ytd_to' => $date_to];
if (!empty($company_id))  { $_ytd_conditions[] = "je.company_id = :company_id";         $_ytd_params[':company_id']  = $company_id; }
if (!empty($estate_id))   { $_ytd_conditions[] = "je.business_unit_id = :estate_id";    $_ytd_params[':estate_id']   = $estate_id; }
if (!empty($division_id)) { $_ytd_conditions[] = "je.division_id = :division_id";       $_ytd_params[':division_id'] = $division_id; }
$_ytd_wc = implode(' AND ', $_ytd_conditions);

$sql_ytd = "
    SELECT
        gla.id            AS gl_account_id,
        gla.account_type,
        SUM(jel.debit_amount)  AS ytd_debit,
        SUM(jel.credit_amount) AS ytd_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
    WHERE $_ytd_wc
      AND gla.account_type IN (
            'revenue','cogs','operating_expense','expense',
            'depreciation','other_income','other_expenses','tax'
          )
    GROUP BY gla.id, gla.account_type
";
$stmt_ytd = $pdo->prepare($sql_ytd);
$stmt_ytd->execute($_ytd_params);
// Build lookup: gl_account_id => signed YTD net
$_ytd_map = [];
foreach ($stmt_ytd->fetchAll() as $_yr) {
    $dbt = (float)$_yr['ytd_debit'];
    $cdt = (float)$_yr['ytd_credit'];
    $type = $_yr['account_type'];
    $_ytd_map[$_yr['gl_account_id']] = in_array($type, ['revenue','other_income']) ? ($cdt - $dbt) : ($dbt - $cdt);
}

// ── 3. Bucket rows ───────────────────────────────────────────────────────────
$buckets = ['revenue' => [], 'cogs' => [], 'opex' => [], 'oi' => [], 'oe' => [], 'tax' => []];

foreach ($rows as $row) {
    $dbt  = (float)$row['total_debit'];
    $cdt  = (float)$row['total_credit'];
    $gid  = $row['gl_account_id'];
    $ytd  = $_ytd_map[$gid] ?? 0;
    switch ($row['account_type']) {
        case 'revenue':
            $buckets['revenue'][] = ['name' => $row['account_name'], 'net' => $cdt - $dbt, 'ytd' => $ytd]; break;
        case 'cogs':
            $buckets['cogs'][] = ['name' => $row['account_name'], 'net' => $dbt - $cdt, 'ytd' => $ytd]; break;
        case 'operating_expense':
        case 'expense':
            $buckets['opex'][] = ['name' => $row['account_name'], 'net' => $dbt - $cdt, 'ytd' => $ytd]; break;
        case 'other_income':
            $buckets['oi'][] = ['name' => $row['account_name'], 'net' => $cdt - $dbt, 'ytd' => $ytd]; break;
        case 'other_expenses':
            $buckets['oe'][] = ['name' => $row['account_name'], 'net' => $dbt - $cdt, 'ytd' => $ytd]; break;
        case 'tax':
            $buckets['tax'][] = ['name' => $row['account_name'], 'net' => $dbt - $cdt, 'ytd' => $ytd]; break;
    }
}

// ── 4. Build per-section drill-down URLs to P&L Detail ───────────────────────
// Each section passes its own account_type(s) so the detail page filters to
// only the accounts in that section — matching the BS Group → BS Detail pattern.
$_pl_base_params = array_filter([
    'report'      => 'profit_loss_detail',
    'from'        => 'profit_loss',
    'company_id'  => $company_id,
    'estate_id'   => $business_unit_id,
    'division_id' => $division_id,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
], fn($v) => $v !== '' && $v !== null);

function pl_drill_url(array $base, string $account_type): string {
    return '?' . http_build_query($base + ['filter_account_type' => $account_type]);
}
$_pl_url_revenue = pl_drill_url($_pl_base_params, 'revenue');
$_pl_url_cogs    = pl_drill_url($_pl_base_params, 'cogs');
$_pl_url_opex    = pl_drill_url($_pl_base_params, 'operating_expense');
$_pl_url_oi      = pl_drill_url($_pl_base_params, 'other_income');
$_pl_url_oe      = pl_drill_url($_pl_base_params, 'other_expenses');
$_pl_url_tax     = pl_drill_url($_pl_base_params, 'tax');

// ── 5. Section totals ─────────────────────────────────────────────────────────
$total_revenue      = array_sum(array_column($buckets['revenue'], 'net'));
$total_cogs         = array_sum(array_column($buckets['cogs'],    'net'));
$total_opex         = array_sum(array_column($buckets['opex'],    'net'));
$total_other_income = array_sum(array_column($buckets['oi'],      'net'));
$total_other_exp    = array_sum(array_column($buckets['oe'],      'net'));
$total_tax          = array_sum(array_column($buckets['tax'],     'net'));

$ytd_revenue      = array_sum(array_column($buckets['revenue'], 'ytd'));
$ytd_cogs         = array_sum(array_column($buckets['cogs'],    'ytd'));
$ytd_opex         = array_sum(array_column($buckets['opex'],    'ytd'));
$ytd_other_income = array_sum(array_column($buckets['oi'],      'ytd'));
$ytd_other_exp    = array_sum(array_column($buckets['oe'],      'ytd'));
$ytd_tax          = array_sum(array_column($buckets['tax'],     'ytd'));

$gross_profit         = $total_revenue - $total_cogs;
$operating_profit     = $gross_profit  - $total_opex;
$profit_before_tax    = $operating_profit + $total_other_income - $total_other_exp;
$net_profit_after_tax = $profit_before_tax - $total_tax;

$ytd_gross_profit      = $ytd_revenue - $ytd_cogs;
$ytd_operating_profit  = $ytd_gross_profit - $ytd_opex;
$ytd_profit_before_tax = $ytd_operating_profit + $ytd_other_income - $ytd_other_exp;
$ytd_npat              = $ytd_profit_before_tax - $ytd_tax;

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt_rp(float $v): string {
    return ($v < 0 ? 'Rp -' : 'Rp ') . number_format(abs($v), 0, ',', '.');
}
function fmt_net(float $v): string {
    return ($v < 0 ? '-' : '') . number_format(abs($v), 0, ',', '.');
}
function pl_fmt_date(string $ymd): string {
    $d = DateTime::createFromFormat('Y-m-d', $ymd);
    return $d ? $d->format('d/m/Y') : htmlspecialchars($ymd);
}

// Render detail rows for one bucket; credit_normal=true for revenue/income
// $detail_url = raw (unencoded) URL to profit_loss_detail with current filters
function render_bucket_rows(array $bucket, bool $credit_normal, string $detail_url = ''): void {
    foreach ($bucket as $item) {
        if ($detail_url) {
            // href needs HTML-encoded ampersands (&amp;); onclick JS string needs raw &
            $href_url = htmlspecialchars($detail_url, ENT_QUOTES);
            $js_url   = str_replace('&amp;', '&', $href_url); // undo & encoding for JS context
            echo '<tr class="pl-row-clickable" style="cursor:pointer;" onclick="window.location=\'' . $js_url . '\'">';
            echo '<td class="ps-4">';
            echo '<a href="' . $href_url . '" class="text-decoration-none text-body" onclick="event.stopPropagation()">';
            echo '<i class="bi bi-zoom-in me-1 text-muted" style="font-size:0.75em;"></i>';
            echo htmlspecialchars($item['name']);
            echo '</a></td>';
        } else {
            echo '<tr>';
            echo '<td class="ps-4">' . htmlspecialchars($item['name']) . '</td>';
        }
        echo '<td class="text-end">' . fmt_net($item['net']) . '</td>';
        echo '<td class="text-end">' . fmt_net($item['ytd']) . '</td>';
        echo '</tr>';
    }
}
?>

<!-- ── Header bar ──────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h5 class="mb-0 fw-semibold">
            <i class="bi bi-graph-up-arrow" style="color:#166c82;"></i>
            <?php echo __('pl_title'); ?>
        </h5>
        <div class="text-muted small mt-1">
            <?php echo __('pl_period'); ?>:
            <strong><?= pl_fmt_date($date_from) ?></strong> &rarr; <strong><?= pl_fmt_date($date_to) ?></strong>
        </div>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="exportToExcel('profit_loss')" class="btn btn-sm btn-success">
            <i class="bi bi-file-earmark-excel"></i> Export Excel
        </button>
        <button onclick="printProfitLoss()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer"></i> <?php echo __('pl_print'); ?>
        </button>
    </div>
</div>

<!-- ── 4 KPI cards ─────────────────────────────────────────────────────────── -->
<div class="row g-2 mb-3">
    <?php
    $cards = [
        ['key' => 'pl_gross_profit', 'val' => $gross_profit,         'formula' => 'pl_gross_formula', 'pos_color' => '#1565c0'],
        ['key' => 'pl_op_profit',    'val' => $operating_profit,     'formula' => 'pl_op_formula',    'pos_color' => '#00695c'],
        ['key' => 'pl_pbt',          'val' => $profit_before_tax,    'formula' => 'pl_pbt_formula',   'pos_color' => '#4527a0'],
        ['key' => 'pl_npat',         'val' => $net_profit_after_tax, 'formula' => 'pl_npat_formula',  'pos_color' => '#004d40'],
    ];
    foreach ($cards as $c):
        $bg = $c['val'] >= 0 ? $c['pos_color'] : '#c62828';
    ?>
    <div class="col-6 col-md-3">
        <div class="card text-white h-100" style="background-color:<?= $bg ?>;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold lh-sm" style="font-size:0.85rem;">
                    <?php echo __($c['key']); ?>
                </div>
                <div class="fs-6 fw-bold mt-1"><?= fmt_rp($c['val']) ?></div>
                <div class="opacity-75" style="font-size:0.72rem;">
                    <?php echo __($c['formula']); ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Print area wrapper (table only) ─────────────────────────────────────── -->
<div id="pl-print-area"
     data-date-from="<?= htmlspecialchars($date_from) ?>"
     data-date-to="<?= htmlspecialchars($date_to) ?>"
     data-ytd-year="<?= htmlspecialchars($_ytd_year) ?>"
     data-i18n="<?= htmlspecialchars(json_encode([
         'title'   => __('pl_title'),
         'period'  => __('pl_period'),
         'print_by'=> 'Print by',
         'datetime'=> 'Date/Time',
     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">

<style>.pl-row-clickable:hover { background-color: #e3f2fd !important; }</style>

<!-- ── P&L Table ───────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header py-2 text-white" style="background-color:#166c82;">
        <span class="fw-semibold"><i class="bi bi-table"></i>
            <?php echo __('pl_title'); ?>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" id="plTable" style="font-size:0.93rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:60%"><?php echo __('pl_col_account'); ?></th>
                        <th class="text-end" style="width:20%"><?php echo __('pl_col_current_period'); ?></th>
                        <th class="text-end" style="width:20%"><?php echo __('pl_col_ytd'); ?></th>
                    </tr>
                </thead>
                <tbody>

<?php // ── REVENUE ──────────────────────────────────────────────────────── ?>
<tr style="background-color:#e8f5e9;">
    <td colspan="3" class="py-1"><strong><i class="bi bi-arrow-up-circle-fill text-success"></i> <?php echo __('pl_sec_revenue'); ?></strong></td>
</tr>
<?php if (empty($buckets['revenue'])): ?>
<tr><td colspan="3" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_revenue'); ?></td></tr>
<?php else: render_bucket_rows($buckets['revenue'], true, $_pl_url_revenue); endif; ?>
<tr style="background-color:<?= $total_revenue >= 0 ? '#c8e6c9' : '#ffe0b2' ?>; font-weight:600;">
    <td class="py-1"><?php echo __('pl_total_revenue'); ?><?php echo $total_revenue < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= fmt_rp($total_revenue) ?></td>
    <td class="text-end py-1"><?= fmt_rp($ytd_revenue) ?></td>
</tr>

<?php // ── COGS ─────────────────────────────────────────────────────────── ?>
<tr style="background-color:#f3e5f5;">
    <td colspan="3" class="py-1"><strong><i class="bi bi-box-seam-fill" style="color:#7b1fa2;"></i> <?php echo __('pl_sec_cogs'); ?></strong></td>
</tr>
<?php if (empty($buckets['cogs'])): ?>
<tr><td colspan="3" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_cogs'); ?></td></tr>
<?php else: render_bucket_rows($buckets['cogs'], false, $_pl_url_cogs); endif; ?>
<tr style="background-color:<?= $total_cogs >= 0 ? '#e1bee7' : '#c8e6c9' ?>; font-weight:600;">
    <td class="py-1"><?php echo __('pl_total_cogs'); ?><?php echo $total_cogs < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= fmt_rp($total_cogs) ?></td>
    <td class="text-end py-1"><?= fmt_rp($ytd_cogs) ?></td>
</tr>

<?php // ── GROSS PROFIT ─────────────────────────────────────────────────── ?>
<tr style="background-color:<?= $gross_profit >= 0 ? '#bbdefb' : '#ffe0b2' ?>; font-weight:700; border-top:2px solid #90caf9;">
    <td class="py-1"><?php echo __('pl_gross_profit_row'); ?> <small class="fw-normal text-muted">(<?php echo __('pl_gross_formula_row'); ?>)</small></td>
    <td class="text-end py-1"><?= fmt_rp($gross_profit) ?></td>
    <td class="text-end py-1"><?= fmt_rp($ytd_gross_profit) ?></td>
</tr>

<?php // ── OPERATING EXPENSES ───────────────────────────────────────────── ?>
<tr style="background-color:#fce4e4;">
    <td colspan="3" class="py-1"><strong><i class="bi bi-arrow-down-circle-fill text-danger"></i> <?php echo __('pl_sec_opex'); ?></strong></td>
</tr>
<?php if (empty($buckets['opex'])): ?>
<tr><td colspan="3" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_opex'); ?></td></tr>
<?php else: render_bucket_rows($buckets['opex'], false, $_pl_url_opex); endif; ?>
<tr style="background-color:<?= $total_opex >= 0 ? '#ffcdd2' : '#c8e6c9' ?>; font-weight:600;">
    <td class="py-1"><?php echo __('pl_total_opex'); ?><?php echo $total_opex < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= fmt_rp($total_opex) ?></td>
    <td class="text-end py-1"><?= fmt_rp($ytd_opex) ?></td>
</tr>

<?php // ── OPERATING PROFIT ─────────────────────────────────────────────── ?>
<tr style="background-color:<?= $operating_profit >= 0 ? '#b2dfdb' : '#ffe0b2' ?>; font-weight:700; border-top:2px solid #80cbc4;">
    <td class="py-1"><?php echo __('pl_op_profit_row'); ?> <small class="fw-normal text-muted">(<?php echo __('pl_op_formula_row'); ?>)</small></td>
    <td class="text-end py-1"><?= fmt_rp($operating_profit) ?></td>
    <td class="text-end py-1"><?= fmt_rp($ytd_operating_profit) ?></td>
</tr>

<?php // ── OTHER INCOME ─────────────────────────────────────────────────── ?>
<tr style="background-color:#e8eaf6;">
    <td colspan="3" class="py-1"><strong><i class="bi bi-plus-circle-fill" style="color:#3949ab;"></i> <?php echo __('pl_sec_oi'); ?></strong></td>
</tr>
<?php if (empty($buckets['oi'])): ?>
<tr><td colspan="3" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_oi'); ?></td></tr>
<?php else: render_bucket_rows($buckets['oi'], true, $_pl_url_oi); endif; ?>
<tr style="background-color:<?= $total_other_income >= 0 ? '#c5cae9' : '#ffe0b2' ?>; font-weight:600;">
    <td class="py-1"><?php echo __('pl_total_oi'); ?><?php echo $total_other_income < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= fmt_rp($total_other_income) ?></td>
    <td class="text-end py-1"><?= fmt_rp($ytd_other_income) ?></td>
</tr>

<?php // ── OTHER EXPENSES ───────────────────────────────────────────────── ?>
<tr style="background-color:#fff3e0;">
    <td colspan="3" class="py-1"><strong><i class="bi bi-dash-circle-fill" style="color:#e65100;"></i> <?php echo __('pl_sec_oe'); ?></strong></td>
</tr>
<?php if (empty($buckets['oe'])): ?>
<tr><td colspan="3" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_oe'); ?></td></tr>
<?php else: render_bucket_rows($buckets['oe'], false, $_pl_url_oe); endif; ?>
<tr style="background-color:<?= $total_other_exp >= 0 ? '#ffe0b2' : '#c8e6c9' ?>; font-weight:600;">
    <td class="py-1"><?php echo __('pl_total_oe'); ?><?php echo $total_other_exp < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= fmt_rp($total_other_exp) ?></td>
    <td class="text-end py-1"><?= fmt_rp($ytd_other_exp) ?></td>
</tr>

<?php // ── PROFIT BEFORE TAX ────────────────────────────────────────────── ?>
<tr style="background-color:<?= $profit_before_tax >= 0 ? '#d1c4e9' : '#ffe0b2' ?>; font-weight:700; border-top:2px solid #9575cd;">
    <td class="py-1"><?php echo __('pl_pbt_row'); ?> <small class="fw-normal text-muted">(<?php echo __('pl_pbt_formula_row'); ?>)</small></td>
    <td class="text-end py-1"><?= fmt_rp($profit_before_tax) ?></td>
    <td class="text-end py-1"><?= fmt_rp($ytd_profit_before_tax) ?></td>
</tr>

<?php // ── TAX EXPENSE ──────────────────────────────────────────────────── ?>
<tr style="background-color:#eceff1;">
    <td colspan="3" class="py-1"><strong><i class="bi bi-receipt" style="color:#546e7a;"></i> <?php echo __('pl_sec_tax'); ?></strong></td>
</tr>
<?php if (empty($buckets['tax'])): ?>
<tr><td colspan="3" class="text-center text-muted ps-4 py-1 small"><?php echo __('pl_no_tax'); ?></td></tr>
<?php else: render_bucket_rows($buckets['tax'], false, $_pl_url_tax); endif; ?>
<tr style="background-color:<?= $total_tax >= 0 ? '#cfd8dc' : '#c8e6c9' ?>; font-weight:600;">
    <td class="py-1"><?php echo __('pl_total_tax'); ?><?php echo $total_tax < 0 ? ' ' . __('pl_abnormal') : ''; ?></td>
    <td class="text-end py-1"><?= fmt_rp($total_tax) ?></td>
    <td class="text-end py-1"><?= fmt_rp($ytd_tax) ?></td>
</tr>

<?php // ── NET PROFIT AFTER TAX ─────────────────────────────────────────── ?>
<tr style="background-color:<?= $net_profit_after_tax >= 0 ? '#a5d6a7' : '#ef9a9a' ?>; font-weight:700; font-size:0.9rem; border-top:2px solid #388e3c;">
    <td class="py-2"><?php echo __('pl_npat_row'); ?> <small class="fw-normal text-muted">(<?php echo __('pl_npat_formula_row'); ?>)</small></td>
    <td class="text-end py-2"><?= fmt_rp($net_profit_after_tax) ?></td>
    <td class="text-end py-2"><?= fmt_rp($ytd_npat) ?></td>
</tr>

                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /#pl-print-area -->

<?php if ($total_revenue != 0): ?>
<!-- ── Margin cards (screen only) ───────────────────────────────────────────── -->
<div class="row g-2 mt-2 no-print">
    <?php
    $margins = [
        ['key' => 'pl_gross_margin',  'val' => $gross_profit,         'lbl' => 'pl_margin_gross_lbl',  'pos' => 'text-primary'],
        ['key' => 'pl_op_margin',     'val' => $operating_profit,     'lbl' => 'pl_margin_op_lbl',     'pos' => 'text-success'],
        ['key' => 'pl_pretax_margin', 'val' => $profit_before_tax,    'lbl' => 'pl_margin_pretax_lbl', 'pos' => 'text-success'],
        ['key' => 'pl_net_margin',    'val' => $net_profit_after_tax, 'lbl' => 'pl_margin_net_lbl',    'pos' => 'text-success'],
    ];
    foreach ($margins as $m):
        $pct = number_format(($m['val'] / $total_revenue) * 100, 1);
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
