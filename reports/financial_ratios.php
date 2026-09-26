<?php
// ── Financial Ratios Dashboard ────────────────────────────────────────────────
// Pulls Balance Sheet totals (point-in-time ≤ date_to) and P&L totals
// (date_from → date_to) then computes all standard financial ratios.

// ── 1. Balance Sheet totals (point-in-time ≤ date_to) ────────────────────────
$_fr_bs_conditions = ["je.status = 'posted'", "je.entry_date <= :fr_date_to"];
$_fr_bs_params     = [':fr_date_to' => $date_to];
if ($company_id)  { $_fr_bs_conditions[] = "je.company_id = :company_id";      $_fr_bs_params[':company_id']  = $company_id; }
if ($estate_id)   { $_fr_bs_conditions[] = "je.business_unit_id = :estate_id"; $_fr_bs_params[':estate_id']   = $estate_id; }
if ($division_id) { $_fr_bs_conditions[] = "je.division_id = :division_id";    $_fr_bs_params[':division_id'] = $division_id; }
$_fr_bs_where = implode(' AND ', $_fr_bs_conditions);

$_fr_bs_sql = "
    SELECT
        gla.account_type,
        SUM(jel.debit_amount)  AS total_debit,
        SUM(jel.credit_amount) AS total_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
    WHERE $_fr_bs_where
      AND gla.account_type IN ('asset','liability','equity')
    GROUP BY gla.account_type
";
$_fr_bs_stmt = $pdo->prepare($_fr_bs_sql);
$_fr_bs_stmt->execute($_fr_bs_params);

$_fr_raw_assets = $_fr_raw_liab = $_fr_raw_equity = 0.0;
foreach ($_fr_bs_stmt->fetchAll() as $_r) {
    $d = (float)$_r['total_debit'];
    $c = (float)$_r['total_credit'];
    switch ($_r['account_type']) {
        case 'asset':     $_fr_raw_assets = $d - $c; break;
        case 'liability': $_fr_raw_liab   = $c - $d; break;
        case 'equity':    $_fr_raw_equity = $c - $d; break;
    }
}
// Include implicit current-period P/L in equity (same logic as balance_sheet.php)
$_fr_current_pl  = $_fr_raw_assets - ($_fr_raw_liab + $_fr_raw_equity);
$_fr_total_assets      = $_fr_raw_assets;
$_fr_total_liabilities = $_fr_raw_liab;
$_fr_total_equity      = $_fr_raw_equity + $_fr_current_pl;

// ── 2. Current Assets & Current Liabilities (account_code starts with 1 / 2) ─
// Convention: current assets = asset accounts whose code starts with 1x or explicitly
// tagged. We use a heuristic: asset accounts with code < 15000, liability < 25000.
// Adjust the HAVING/WHERE prefix to match your chart of accounts numbering.
$_fr_ca_sql = "
    SELECT
        gla.account_type,
        gla.account_code,
        SUM(jel.debit_amount)  AS total_debit,
        SUM(jel.credit_amount) AS total_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
    WHERE $_fr_bs_where
      AND gla.account_type IN ('asset','liability')
    GROUP BY gla.id, gla.account_type, gla.account_code
";
$_fr_ca_stmt = $pdo->prepare($_fr_ca_sql);
$_fr_ca_stmt->execute($_fr_bs_params);

$_fr_current_assets = 0.0;
$_fr_current_liab   = 0.0;
foreach ($_fr_ca_stmt->fetchAll() as $_r) {
    $code = (int) preg_replace('/\D/', '', $_r['account_code']);
    $d = (float)$_r['total_debit'];
    $c = (float)$_r['total_credit'];
    if ($_r['account_type'] === 'asset' && $code < 150000) {
        $_fr_current_assets += ($d - $c);
    }
    if ($_r['account_type'] === 'liability' && $code < 250000) {
        $_fr_current_liab += ($c - $d);
    }
}

// ── 3. P&L totals (date_from → date_to) ──────────────────────────────────────
$_fr_pl_conditions = ["je.status = 'posted'", "je.entry_date >= :fr_date_from", "je.entry_date <= :fr_date_to2"];
$_fr_pl_params     = [':fr_date_from' => $date_from, ':fr_date_to2' => $date_to];
if ($company_id)  { $_fr_pl_conditions[] = "je.company_id = :company_id";      $_fr_pl_params[':company_id']  = $company_id; }
if ($estate_id)   { $_fr_pl_conditions[] = "je.business_unit_id = :estate_id"; $_fr_pl_params[':estate_id']   = $estate_id; }
if ($division_id) { $_fr_pl_conditions[] = "je.division_id = :division_id";    $_fr_pl_params[':division_id'] = $division_id; }
$_fr_pl_where = implode(' AND ', $_fr_pl_conditions);

$_fr_pl_sql = "
    SELECT
        gla.account_type,
        SUM(jel.debit_amount)  AS total_debit,
        SUM(jel.credit_amount) AS total_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
    WHERE $_fr_pl_where
      AND gla.account_type IN (
            'revenue','cogs','operating_expense','expense',
            'depreciation','other_income','other_expenses','tax'
          )
    GROUP BY gla.account_type
";
$_fr_pl_stmt = $pdo->prepare($_fr_pl_sql);
$_fr_pl_stmt->execute($_fr_pl_params);

$_fr_revenue = $_fr_cogs = $_fr_opex = $_fr_other_income = $_fr_other_exp = $_fr_tax = 0.0;
foreach ($_fr_pl_stmt->fetchAll() as $_r) {
    $d = (float)$_r['total_debit'];
    $c = (float)$_r['total_credit'];
    switch ($_r['account_type']) {
        case 'revenue':           $_fr_revenue      += ($c - $d); break;
        case 'cogs':              $_fr_cogs         += ($d - $c); break;
        case 'operating_expense':
        case 'expense':
        case 'depreciation':      $_fr_opex         += ($d - $c); break;
        case 'other_income':      $_fr_other_income += ($c - $d); break;
        case 'other_expenses':    $_fr_other_exp    += ($d - $c); break;
        case 'tax':               $_fr_tax          += ($d - $c); break;
    }
}
$_fr_gross_profit    = $_fr_revenue - $_fr_cogs;
$_fr_op_profit       = $_fr_gross_profit - $_fr_opex;
$_fr_pbt             = $_fr_op_profit + $_fr_other_income - $_fr_other_exp;
$_fr_net_profit      = $_fr_pbt - $_fr_tax;

// ── 4. Compute ratios ─────────────────────────────────────────────────────────
function _fr_ratio(?float $num, ?float $den, int $dp = 2): ?float {
    return ($den !== null && $den != 0) ? round($num / $den, $dp) : null;
}
function _fr_pct(?float $num, ?float $den, int $dp = 1): ?float {
    return ($den !== null && $den != 0) ? round(($num / $den) * 100, $dp) : null;
}
function _fr_fmt(float $v): string {
    return ($v < 0 ? 'Rp -' : 'Rp ') . number_format(abs($v), 0, ',', '.');
}

// Liquidity
$_fr_current_ratio = _fr_ratio($_fr_current_assets, $_fr_current_liab);
$_fr_quick_ratio   = _fr_ratio($_fr_current_assets - 0 /* no inventory split yet */, $_fr_current_liab);

// Solvency / Leverage
$_fr_de_ratio      = _fr_ratio($_fr_total_liabilities, $_fr_total_equity);
$_fr_debt_ratio    = _fr_pct($_fr_total_liabilities, $_fr_total_assets);
$_fr_equity_ratio  = _fr_pct($_fr_total_equity, $_fr_total_assets);

// Profitability
$_fr_gross_margin  = _fr_pct($_fr_gross_profit, $_fr_revenue);
$_fr_op_margin     = _fr_pct($_fr_op_profit,    $_fr_revenue);
$_fr_net_margin    = _fr_pct($_fr_net_profit,   $_fr_revenue);

// Return ratios (cross BS+PL)
$_fr_roa           = _fr_pct($_fr_net_profit, $_fr_total_assets);
$_fr_roe           = _fr_pct($_fr_net_profit, $_fr_total_equity);

// Efficiency
$_fr_asset_turnover = _fr_ratio($_fr_revenue, $_fr_total_assets);

// ── helpers ──────────────────────────────────────────────────────────────────
function _fr_date(string $ymd): string {
    $d = DateTime::createFromFormat('Y-m-d', $ymd);
    return $d ? $d->format('d M Y') : htmlspecialchars($ymd);
}

// ── render helpers ────────────────────────────────────────────────────────────
// Returns a colour class based on whether higher is better or worse
function _fr_color(?float $v, float $warn, float $good, bool $higher_is_better = true): string {
    if ($v === null) return 'text-muted';
    if ($higher_is_better) {
        if ($v >= $good) return 'text-success';
        if ($v >= $warn) return 'text-warning';
        return 'text-danger';
    } else {
        if ($v <= $good) return 'text-success';
        if ($v <= $warn) return 'text-warning';
        return 'text-danger';
    }
}
?>

<!-- ── Header ─────────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h5 class="mb-0 fw-semibold">
            <i class="bi bi-percent" style="color:#166c82;"></i>
            <?php echo __('fr_ratios_title'); ?>
        </h5>
        <div class="text-muted small mt-1">
            <?php echo __('fr_ratios_period'); ?>:
            <strong><?= _fr_date($date_from) ?></strong> &rarr; <strong><?= _fr_date($date_to) ?></strong>
            &nbsp;|&nbsp;
            <?php echo __('fr_ratios_bs_asof'); ?>: <strong><?= _fr_date($date_to) ?></strong>
        </div>
    </div>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print">
        <i class="bi bi-printer"></i> <?php echo __('fr_ratios_print'); ?>
    </button>
</div>

<!-- ── Base figures summary ───────────────────────────────────────────────── -->
<div class="row g-2 mb-4">
    <?php
    $base_cards = [
        [__('fr_ratios_total_assets'),  $_fr_total_assets,      '#1565c0'],
        [__('fr_ratios_total_liab'),    $_fr_total_liabilities, '#c62828'],
        [__('fr_ratios_total_equity'),  $_fr_total_equity,      '#2e7d32'],
        [__('fr_ratios_revenue'),       $_fr_revenue,           '#6a1b9a'],
        [__('fr_ratios_gross_profit'),  $_fr_gross_profit,      '#00695c'],
        [__('fr_ratios_net_profit'),    $_fr_net_profit,        '#004d40'],
    ];
    foreach ($base_cards as [$label, $val, $color]):
        $bg   = ($val < 0) ? '#c62828' : $color;
    ?>
    <div class="col-6 col-md-2">
        <div class="card text-white h-100" style="background:<?= $bg ?>;">
            <div class="card-body py-2 px-3">
                <div style="font-size:0.78rem; line-height:1.3; opacity:.9;"><?= $label ?></div>
                <div class="fw-bold mt-1" style="font-size:0.92rem;"><?= _fr_fmt($val) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── LIQUIDITY ─────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header text-white d-flex align-items-center gap-2" style="background:#166c82;">
        <i class="bi bi-droplet-half"></i>
        <strong><?php echo __('fr_ratios_sec_liquidity'); ?></strong>
        <small class="opacity-75 fw-normal ms-1"><?php echo __('fr_ratios_sec_liquidity_sub'); ?></small>
    </div>
    <div class="card-body">
        <div class="row g-3">

            <!-- Current Ratio -->
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_current'); ?></div>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_current_ratio, 1.0, 1.5) ?>">
                        <?= $_fr_current_ratio !== null ? number_format($_fr_current_ratio, 2) . 'x' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_current_formula'); ?></div>
                    <div class="mt-2" style="font-size:0.78rem;">
                        <span class="badge bg-success me-1">&ge;1.5 <?php echo __('fr_label_good'); ?></span>
                        <span class="badge bg-warning text-dark me-1">&ge;1.0 <?php echo __('fr_label_fair'); ?></span>
                        <span class="badge bg-danger">&lt;1.0 <?php echo __('fr_label_weak'); ?></span>
                    </div>
                    <div class="text-muted mt-2" style="font-size:0.75rem;">
                        <?= __('fr_label_curr_assets') ?>: <strong><?= _fr_fmt($_fr_current_assets) ?></strong><br>
                        <?= __('fr_label_curr_liab') ?>: <strong><?= _fr_fmt($_fr_current_liab) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Quick Ratio -->
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_quick'); ?></div>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_quick_ratio, 0.8, 1.0) ?>">
                        <?= $_fr_quick_ratio !== null ? number_format($_fr_quick_ratio, 2) . 'x' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_quick_formula'); ?></div>
                    <div class="mt-2" style="font-size:0.78rem;">
                        <span class="badge bg-success me-1">&ge;1.0 <?php echo __('fr_label_good'); ?></span>
                        <span class="badge bg-warning text-dark me-1">&ge;0.8 <?php echo __('fr_label_fair'); ?></span>
                        <span class="badge bg-danger">&lt;0.8 <?php echo __('fr_label_weak'); ?></span>
                    </div>
                    <div class="text-muted mt-2" style="font-size:0.75rem;">
                        <?php echo __('fr_ratio_quick_note'); ?>
                    </div>
                </div>
            </div>

            <!-- Working Capital -->
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_working_capital'); ?></div>
                    <?php $_fr_wc = $_fr_current_assets - $_fr_current_liab; ?>
                    <div class="fs-3 fw-bold <?= $_fr_wc >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= _fr_fmt($_fr_wc) ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_wc_formula'); ?></div>
                    <div class="mt-2" style="font-size:0.75rem; color:#57606a;">
                        <?php echo __('fr_ratio_wc_note'); ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ── SOLVENCY / LEVERAGE ────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header text-white d-flex align-items-center gap-2" style="background:#5c3d99;">
        <i class="bi bi-shield-half"></i>
        <strong><?php echo __('fr_ratios_sec_solvency'); ?></strong>
        <small class="opacity-75 fw-normal ms-1"><?php echo __('fr_ratios_sec_solvency_sub'); ?></small>
    </div>
    <div class="card-body">
        <div class="row g-3">

            <!-- D/E Ratio -->
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_de'); ?></div>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_de_ratio, 2.0, 1.0, false) ?>">
                        <?= $_fr_de_ratio !== null ? number_format($_fr_de_ratio, 2) . 'x' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_de_formula'); ?></div>
                    <div class="mt-2" style="font-size:0.78rem;">
                        <span class="badge bg-success me-1">&le;1.0 <?php echo __('fr_label_good'); ?></span>
                        <span class="badge bg-warning text-dark me-1">&le;2.0 <?php echo __('fr_label_fair'); ?></span>
                        <span class="badge bg-danger">&gt;2.0 <?php echo __('fr_label_high'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Debt Ratio -->
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_debt'); ?></div>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_debt_ratio, 60, 50, false) ?>">
                        <?= $_fr_debt_ratio !== null ? number_format($_fr_debt_ratio, 1) . '%' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_debt_formula'); ?></div>
                    <div class="mt-2" style="font-size:0.78rem;">
                        <span class="badge bg-success me-1">&lt;50% <?php echo __('fr_label_good'); ?></span>
                        <span class="badge bg-warning text-dark me-1">&lt;60% <?php echo __('fr_label_fair'); ?></span>
                        <span class="badge bg-danger">&ge;60% <?php echo __('fr_label_high'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Equity Ratio -->
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_equity'); ?></div>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_equity_ratio, 40, 50) ?>">
                        <?= $_fr_equity_ratio !== null ? number_format($_fr_equity_ratio, 1) . '%' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_equity_formula'); ?></div>
                    <div class="mt-2" style="font-size:0.78rem;">
                        <span class="badge bg-success me-1">&ge;50% <?php echo __('fr_label_good'); ?></span>
                        <span class="badge bg-warning text-dark me-1">&ge;40% <?php echo __('fr_label_fair'); ?></span>
                        <span class="badge bg-danger">&lt;40% <?php echo __('fr_label_weak'); ?></span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ── PROFITABILITY ───────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header text-white d-flex align-items-center gap-2" style="background:#2e7d32;">
        <i class="bi bi-graph-up-arrow"></i>
        <strong><?php echo __('fr_ratios_sec_profit'); ?></strong>
        <small class="opacity-75 fw-normal ms-1"><?php echo __('fr_ratios_sec_profit_sub'); ?></small>
    </div>
    <div class="card-body">
        <div class="row g-3">

            <!-- Gross Margin -->
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_gross_margin'); ?></div>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_gross_margin, 20, 35) ?>">
                        <?= $_fr_gross_margin !== null ? number_format($_fr_gross_margin, 1) . '%' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_gross_formula'); ?></div>
                </div>
            </div>

            <!-- Operating Margin -->
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_op_margin'); ?></div>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_op_margin, 5, 15) ?>">
                        <?= $_fr_op_margin !== null ? number_format($_fr_op_margin, 1) . '%' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_op_formula'); ?></div>
                </div>
            </div>

            <!-- Net Profit Margin -->
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_net_margin'); ?></div>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_net_margin, 3, 10) ?>">
                        <?= $_fr_net_margin !== null ? number_format($_fr_net_margin, 1) . '%' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_net_formula'); ?></div>
                </div>
            </div>

            <!-- EBITDA-proxy (Op Profit + Depreciation) -->
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_ebitda_margin'); ?></div>
                    <?php
                    // Re-query depreciation separately for a better EBITDA proxy
                    $_fr_ebitda_margin = null;
                    if ($_fr_revenue != 0) {
                        $_fr_ebitda_margin = _fr_pct($_fr_op_profit, $_fr_revenue);
                    }
                    ?>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_ebitda_margin, 10, 20) ?>">
                        <?= $_fr_ebitda_margin !== null ? number_format($_fr_ebitda_margin, 1) . '%' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_ebitda_formula'); ?></div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ── RETURN RATIOS ───────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header text-white d-flex align-items-center gap-2" style="background:#00695c;">
        <i class="bi bi-arrow-repeat"></i>
        <strong><?php echo __('fr_ratios_sec_return'); ?></strong>
        <small class="opacity-75 fw-normal ms-1"><?php echo __('fr_ratios_sec_return_sub'); ?></small>
    </div>
    <div class="card-body">
        <div class="row g-3">

            <!-- ROA -->
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_roa'); ?></div>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_roa, 2, 5) ?>">
                        <?= $_fr_roa !== null ? number_format($_fr_roa, 1) . '%' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_roa_formula'); ?></div>
                    <div class="mt-2" style="font-size:0.78rem;">
                        <span class="badge bg-success me-1">&ge;5% <?php echo __('fr_label_good'); ?></span>
                        <span class="badge bg-warning text-dark me-1">&ge;2% <?php echo __('fr_label_fair'); ?></span>
                        <span class="badge bg-danger">&lt;2% <?php echo __('fr_label_weak'); ?></span>
                    </div>
                </div>
            </div>

            <!-- ROE -->
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_roe'); ?></div>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_roe, 8, 15) ?>">
                        <?= $_fr_roe !== null ? number_format($_fr_roe, 1) . '%' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_roe_formula'); ?></div>
                    <div class="mt-2" style="font-size:0.78rem;">
                        <span class="badge bg-success me-1">&ge;15% <?php echo __('fr_label_good'); ?></span>
                        <span class="badge bg-warning text-dark me-1">&ge;8% <?php echo __('fr_label_fair'); ?></span>
                        <span class="badge bg-danger">&lt;8% <?php echo __('fr_label_weak'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Asset Turnover -->
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small mb-1"><?php echo __('fr_ratio_asset_turnover'); ?></div>
                    <div class="fs-3 fw-bold <?= _fr_color($_fr_asset_turnover, 0.3, 0.5) ?>">
                        <?= $_fr_asset_turnover !== null ? number_format($_fr_asset_turnover, 2) . 'x' : '—' ?>
                    </div>
                    <div class="text-muted small"><?php echo __('fr_ratio_at_formula'); ?></div>
                    <div class="mt-2" style="font-size:0.78rem;">
                        <span class="badge bg-success me-1">&ge;0.5x <?php echo __('fr_label_good'); ?></span>
                        <span class="badge bg-warning text-dark me-1">&ge;0.3x <?php echo __('fr_label_fair'); ?></span>
                        <span class="badge bg-danger">&lt;0.3x <?php echo __('fr_label_weak'); ?></span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ── Summary table ──────────────────────────────────────────────────────── -->
<div class="card mb-3 no-print">
    <div class="card-header" style="background:#f7f8fa; border-bottom:1px solid #e5e7eb;">
        <strong style="font-size:0.88rem; color:#57606a;"><?php echo __('fr_ratios_summary_title'); ?></strong>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:0.85rem;">
            <thead class="table-light">
                <tr>
                    <th><?php echo __('fr_ratios_col_ratio'); ?></th>
                    <th><?php echo __('fr_ratios_col_category'); ?></th>
                    <th><?php echo __('fr_ratios_col_value'); ?></th>
                    <th><?php echo __('fr_ratios_col_formula'); ?></th>
                    <th><?php echo __('fr_ratios_col_benchmark'); ?></th>
                    <th><?php echo __('fr_ratios_col_signal'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $summary_rows = [
                [__('fr_ratio_current'),        __('fr_ratios_sec_liquidity'), $_fr_current_ratio,     'x',  __('fr_ratio_current_formula'),  '≥1.5',  _fr_color($_fr_current_ratio, 1.0, 1.5)],
                [__('fr_ratio_quick'),           __('fr_ratios_sec_liquidity'), $_fr_quick_ratio,       'x',  __('fr_ratio_quick_formula'),    '≥1.0',  _fr_color($_fr_quick_ratio, 0.8, 1.0)],
                [__('fr_ratio_de'),              __('fr_ratios_sec_solvency'),  $_fr_de_ratio,          'x',  __('fr_ratio_de_formula'),       '≤1.0',  _fr_color($_fr_de_ratio, 2.0, 1.0, false)],
                [__('fr_ratio_debt'),            __('fr_ratios_sec_solvency'),  $_fr_debt_ratio,        '%',  __('fr_ratio_debt_formula'),     '<50%',  _fr_color($_fr_debt_ratio, 60, 50, false)],
                [__('fr_ratio_equity'),          __('fr_ratios_sec_solvency'),  $_fr_equity_ratio,      '%',  __('fr_ratio_equity_formula'),   '≥50%',  _fr_color($_fr_equity_ratio, 40, 50)],
                [__('fr_ratio_gross_margin'),    __('fr_ratios_sec_profit'),    $_fr_gross_margin,      '%',  __('fr_ratio_gross_formula'),    '≥35%',  _fr_color($_fr_gross_margin, 20, 35)],
                [__('fr_ratio_op_margin'),       __('fr_ratios_sec_profit'),    $_fr_op_margin,         '%',  __('fr_ratio_op_formula'),       '≥15%',  _fr_color($_fr_op_margin, 5, 15)],
                [__('fr_ratio_net_margin'),      __('fr_ratios_sec_profit'),    $_fr_net_margin,        '%',  __('fr_ratio_net_formula'),      '≥10%',  _fr_color($_fr_net_margin, 3, 10)],
                [__('fr_ratio_roa'),             __('fr_ratios_sec_return'),    $_fr_roa,               '%',  __('fr_ratio_roa_formula'),      '≥5%',   _fr_color($_fr_roa, 2, 5)],
                [__('fr_ratio_roe'),             __('fr_ratios_sec_return'),    $_fr_roe,               '%',  __('fr_ratio_roe_formula'),      '≥15%',  _fr_color($_fr_roe, 8, 15)],
                [__('fr_ratio_asset_turnover'),  __('fr_ratios_sec_return'),    $_fr_asset_turnover,    'x',  __('fr_ratio_at_formula'),       '≥0.5x', _fr_color($_fr_asset_turnover, 0.3, 0.5)],
            ];
            foreach ($summary_rows as [$name, $cat, $val, $unit, $formula, $bench, $cls]):
                $signal = '';
                if (str_contains($cls, 'success')) $signal = '<span class="badge bg-success">'. __('fr_label_good') .'</span>';
                elseif (str_contains($cls, 'warning')) $signal = '<span class="badge bg-warning text-dark">'. __('fr_label_fair') .'</span>';
                elseif (str_contains($cls, 'danger'))  $signal = '<span class="badge bg-danger">'. __('fr_label_weak') .'</span>';
                else $signal = '<span class="text-muted">—</span>';
            ?>
            <tr>
                <td><strong><?= $name ?></strong></td>
                <td class="text-muted"><?= $cat ?></td>
                <td class="fw-bold <?= $cls ?>">
                    <?= $val !== null ? number_format($val, 2) . $unit : '—' ?>
                </td>
                <td class="text-muted small"><?= $formula ?></td>
                <td class="text-muted small"><?= $bench ?></td>
                <td><?= $signal ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php // Powered by IBM Bob ?>
