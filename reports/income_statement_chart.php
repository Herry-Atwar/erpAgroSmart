<?php
/**
 * Income Statement Chart — reports/income_statement_chart.php
 *
 * Waterfall / combo chart for the full Income Statement:
 *   Revenue → Gross Profit → EBITDA → EBIT (Operating Profit)
 *   → EBT (Profit Before Tax) → Tax → Net Income
 *
 * PostgreSQL version (erpAgroSmart / Supabase)
 *
 * Period toggle:  monthly | quarterly | yearly
 * Filters:        company / BU / division / date range (from parent)
 */

// ── 0. Period mode ────────────────────────────────────────────────────────────
$_isc_period = in_array($_GET['period'] ?? '', ['monthly','quarterly','yearly'])
    ? $_GET['period'] : 'monthly';

// ── 1. Build the time-bucket GROUP BY expression (PostgreSQL) ─────────────────
$_isc_group_expr = match($_isc_period) {
    'quarterly' => "TO_CHAR(je.entry_date, 'YYYY') || '-Q' || EXTRACT(QUARTER FROM je.entry_date)::TEXT",
    'yearly'    => "TO_CHAR(je.entry_date, 'YYYY')",
    default     => "TO_CHAR(je.entry_date, 'YYYY-MM')",   // monthly
};

// ── 2. Org-scope WHERE (same as parent $where_clause but no date restriction) ─
$_isc_conds  = ["je.status = 'posted'"];
$_isc_params = [];
if (!empty($company_id))  { $_isc_conds[] = "je.company_id = :company_id";         $_isc_params[':company_id']  = $company_id; }
if (!empty($estate_id))   { $_isc_conds[] = "je.business_unit_id = :estate_id";    $_isc_params[':estate_id']   = $estate_id; }
if (!empty($division_id)) { $_isc_conds[] = "je.division_id = :division_id";       $_isc_params[':division_id'] = $division_id; }
// Date range from the parent filter form
if (!empty($date_from))   { $_isc_conds[] = "je.entry_date >= :date_from";         $_isc_params[':date_from']   = $date_from; }
if (!empty($date_to))     { $_isc_conds[] = "je.entry_date <= :date_to";           $_isc_params[':date_to']     = $date_to; }
$_isc_wc = implode(' AND ', $_isc_conds);

// ── 3. Main aggregation query ─────────────────────────────────────────────────
$_isc_sql = "
    SELECT
        {$_isc_group_expr}                              AS period_bucket,
        gla.account_type,
        CASE WHEN gla.account_type = 'depreciation' THEN 1 ELSE 0 END AS is_da,
        SUM(jel.debit_amount)                           AS total_debit,
        SUM(jel.credit_amount)                          AS total_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
    WHERE {$_isc_wc}
      AND gla.account_type IN (
            'revenue','cogs','operating_expense','expense',
            'depreciation','other_income','other_expenses','tax'
          )
    GROUP BY period_bucket, gla.account_type, is_da
    ORDER BY period_bucket
";

$_isc_stmt = $pdo->prepare($_isc_sql);
$_isc_stmt->execute($_isc_params);
$_isc_rows = $_isc_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── 4. Aggregate into per-period buckets ──────────────────────────────────────
$_isc_data = [];
foreach ($_isc_rows as $_r) {
    $pb   = (string)$_r['period_bucket'];
    $dbt  = (float)$_r['total_debit'];
    $cdt  = (float)$_r['total_credit'];
    $is_da = (int)$_r['is_da'];
    if (!isset($_isc_data[$pb])) {
        $_isc_data[$pb] = ['revenue'=>0,'cogs'=>0,'opex'=>0,'da'=>0,'oi'=>0,'oe'=>0,'tax'=>0];
    }
    switch ($_r['account_type']) {
        case 'revenue':
            $_isc_data[$pb]['revenue'] += $cdt - $dbt; break;
        case 'cogs':
            $_isc_data[$pb]['cogs']    += $dbt - $cdt; break;
        case 'operating_expense':
        case 'expense':
            $net = $dbt - $cdt;
            $_isc_data[$pb]['opex'] += $net;
            if ($is_da) $_isc_data[$pb]['da'] += $net;
            break;
        case 'other_income':
            $_isc_data[$pb]['oi']  += $cdt - $dbt; break;
        case 'other_expenses':
            $_isc_data[$pb]['oe']  += $dbt - $cdt; break;
        case 'tax':
            $_isc_data[$pb]['tax'] += $dbt - $cdt; break;
    }
}

// ── 5. Derived metrics per period ─────────────────────────────────────────────
$_isc_labels        = [];
$_isc_revenue       = [];
$_isc_gross_profit  = [];
$_isc_ebitda        = [];
$_isc_da            = [];
$_isc_ebit          = [];
$_isc_ebt           = [];
$_isc_tax           = [];
$_isc_net_income    = [];
$_isc_cogs          = [];
$_isc_opex          = [];

foreach ($_isc_data as $pb => $v) {
    $gp          = $v['revenue'] - $v['cogs'];
    $non_da_opex = $v['opex'] - $v['da'];
    $ebitda      = $gp - $non_da_opex;
    $ebit        = $gp - $v['opex'];
    $ebt         = $ebit + $v['oi'] - $v['oe'];
    $net_income  = $ebt - $v['tax'];

    // Format period label (PostgreSQL returns strings directly)
    if ($_isc_period === 'monthly') {
        $parts = explode('-', $pb);
        $lbl   = (count($parts) === 2) ? date('M Y', mktime(0,0,0,(int)$parts[1],1,(int)$parts[0])) : $pb;
    } else {
        $lbl = $pb;
    }

    $_isc_labels[]       = $lbl;
    $_isc_revenue[]      = round($v['revenue'], 0);
    $_isc_cogs[]         = round($v['cogs'], 0);
    $_isc_gross_profit[] = round($gp, 0);
    $_isc_da[]           = round($v['da'], 0);
    $_isc_ebitda[]       = round($ebitda, 0);
    $_isc_ebit[]         = round($ebit, 0);
    $_isc_ebt[]          = round($ebt, 0);
    $_isc_tax[]          = round($v['tax'], 0);
    $_isc_net_income[]   = round($net_income, 0);
    $_isc_opex[]         = round($v['opex'], 0);
}

// ── 6. Summary totals (for KPI cards) ─────────────────────────────────────────
$_isc_tot_revenue     = array_sum($_isc_revenue);
$_isc_tot_cogs        = array_sum($_isc_cogs);
$_isc_tot_gp          = array_sum($_isc_gross_profit);
$_isc_tot_da          = array_sum($_isc_da);
$_isc_tot_ebitda      = array_sum($_isc_ebitda);
$_isc_tot_ebit        = array_sum($_isc_ebit);
$_isc_tot_ebt         = array_sum($_isc_ebt);
$_isc_tot_tax         = array_sum($_isc_tax);
$_isc_tot_net_income  = array_sum($_isc_net_income);

// ── 7. Chart.js JSON data ─────────────────────────────────────────────────────
$_isc_json_labels      = json_encode($_isc_labels,       JSON_UNESCAPED_UNICODE);
$_isc_json_revenue     = json_encode($_isc_revenue);
$_isc_json_cogs        = json_encode($_isc_cogs);
$_isc_json_gp          = json_encode($_isc_gross_profit);
$_isc_json_ebitda      = json_encode($_isc_ebitda);
$_isc_json_da          = json_encode($_isc_da);
$_isc_json_ebit        = json_encode($_isc_ebit);
$_isc_json_ebt         = json_encode($_isc_ebt);
$_isc_json_tax         = json_encode($_isc_tax);
$_isc_json_net         = json_encode($_isc_net_income);

// ── 8. Build period-toggle URLs (preserves all current filters) ───────────────
$_isc_base_qs = array_filter([
    'report'      => 'income_statement_chart',
    'company_id'  => $company_id,
    'estate_id'   => $estate_id,
    'division_id' => $division_id,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
], fn($v) => $v !== '' && $v !== null);

function isc_toggle_url(array $base, string $period): string {
    return '?' . http_build_query($base + ['period' => $period]);
}

// ── 9. Helpers ────────────────────────────────────────────────────────────────
function isc_fmt(float $v): string {
    return ($v < 0 ? 'Rp -' : 'Rp ') . number_format(abs($v), 0, ',', '.');
}
function isc_fmt_short(float $v): string {
    return ($v < 0 ? 'Rp -' : 'Rp ') . number_format(abs($v), 0, ',', '.');
}
?>

<!-- ── Chart.js CDN ──────────────────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<!-- ── Period toggle + header bar ───────────────────────────────────────────── -->
<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div>
        <h5 class="mb-0 fw-semibold">
            <i class="bi bi-bar-chart-steps" style="color:#166c82;"></i>
            <?= __('isc_title') ?>
        </h5>
        <div class="text-muted small mt-1">
            <?= __('pl_period') ?>:
            <strong><?= (new DateTime($date_from))->format('d/m/Y') ?></strong>
            &rarr;
            <strong><?= (new DateTime($date_to))->format('d/m/Y') ?></strong>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap no-print">
        <!-- Period toggle -->
        <div class="btn-group btn-group-sm" role="group">
            <?php
            $periods_map = [
                'monthly'   => __('isc_period_monthly'),
                'quarterly' => __('isc_period_quarterly'),
                'yearly'    => __('isc_period_yearly'),
            ];
            foreach ($periods_map as $pk => $pl):
                $active = $_isc_period === $pk;
            ?>
            <a href="<?= htmlspecialchars(isc_toggle_url($_isc_base_qs, $pk)) ?>"
               class="btn <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>"
               <?= $active ? 'style="background:#166c82;border-color:#166c82;"' : '' ?>>
                <?= htmlspecialchars($pl) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer"></i> <?= __('pl_print') ?>
        </button>
    </div>
</div>

<!-- ── KPI Cards ─────────────────────────────────────────────────────────────── -->
<div class="row g-2 mb-3">
<?php
$_isc_kpis = [
    ['key'=>'isc_kpi_revenue',    'val'=>$_isc_tot_revenue,    'color'=>'#1565c0'],
    ['key'=>'isc_kpi_gross',      'val'=>$_isc_tot_gp,         'color'=>'#00695c'],
    ['key'=>'isc_kpi_ebitda',     'val'=>$_isc_tot_ebitda,     'color'=>'#2e7d32'],
    ['key'=>'isc_kpi_da',         'val'=>$_isc_tot_da,         'color'=>'#4527a0'],
    ['key'=>'isc_kpi_ebit',       'val'=>$_isc_tot_ebit,       'color'=>'#00838f'],
    ['key'=>'isc_kpi_ebt',        'val'=>$_isc_tot_ebt,        'color'=>'#6a1b9a'],
    ['key'=>'isc_kpi_tax',        'val'=>$_isc_tot_tax,        'color'=>'#bf360c'],
    ['key'=>'isc_kpi_net',        'val'=>$_isc_tot_net_income, 'color'=>'#004d40'],
];
foreach ($_isc_kpis as $_kc):
    $bg = ($_kc['val'] >= 0) ? $_kc['color'] : '#c62828';
?>
<div class="col-6 col-sm-4 col-md-3 col-xl-1-5">
    <div class="card text-white h-100" style="background:<?= $bg ?>;">
        <div class="card-body py-2 px-2 text-center">
            <div style="font-size:0.72rem;line-height:1.3;" class="fw-semibold"><?= __($_kc['key']) ?></div>
            <div class="fw-bold mt-1" style="font-size:0.82rem;"><?= isc_fmt_short($_kc['val']) ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php if (empty($_isc_labels)): ?>
<div class="alert alert-info"><?= __('isc_no_data') ?></div>
<?php else: ?>

<!-- ── Chart area ────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header py-2 text-white" style="background:#166c82;">
        <strong><i class="bi bi-bar-chart-steps"></i> <?= __('isc_chart_title') ?></strong>
    </div>
    <div class="card-body p-2">
        <canvas id="iscChart" style="max-height:420px;"></canvas>
    </div>
</div>

<!-- ── D&A note card ─────────────────────────────────────────────────────────── -->
<div class="alert alert-light border mb-3 small" style="font-size:0.82rem;">
    <i class="bi bi-info-circle text-secondary me-1"></i>
    <?= __('isc_da_note') ?>
</div>

<!-- ── Summary table ─────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header py-2 text-white" style="background:#166c82;">
        <strong><i class="bi bi-table"></i> <?= __('isc_table_title') ?></strong>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" style="font-size:0.82rem;">
                <thead class="table-light" style="font-size:0.75rem;">
                    <tr>
                        <th><?= __('isc_col_metric') ?></th>
                        <?php foreach ($_isc_labels as $lbl): ?>
                        <th class="text-end"><?= htmlspecialchars($lbl) ?></th>
                        <?php endforeach; ?>
                        <th class="text-end fw-bold"><?= __('isc_col_total') ?></th>
                    </tr>
                </thead>
                <tbody>
<?php
// Recompute other_net
$_isc_other_net = [];
foreach (array_keys($_isc_data) as $pb) {
    $_isc_other_net[] = round(($_isc_data[$pb]['oi'] ?? 0) - ($_isc_data[$pb]['oe'] ?? 0), 0);
}
$_isc_tot_other_net = array_sum($_isc_other_net);

$_isc_table_rows = [
    ['isc_row_revenue',    $_isc_revenue,      $_isc_tot_revenue,    'background:#e8f5e9;',                  'fw-semibold'],
    ['isc_row_cogs',       $_isc_cogs,         $_isc_tot_cogs,       '',                                     'text-danger'],
    ['isc_row_gross',      $_isc_gross_profit, $_isc_tot_gp,         'background:#e3f2fd;font-weight:600;',  ''],
    ['isc_row_da',         $_isc_da,           $_isc_tot_da,         'background:#f3e5f5;',                  'text-muted'],
    ['isc_row_ebitda',     $_isc_ebitda,       $_isc_tot_ebitda,     'background:#dcedc8;font-weight:700;',  ''],
    ['isc_row_ebit',       $_isc_ebit,         $_isc_tot_ebit,       'background:#e0f2f1;font-weight:600;',  ''],
    ['isc_row_other_net',  $_isc_other_net,    $_isc_tot_other_net,  '',                                     'text-muted'],
    ['isc_row_ebt',        $_isc_ebt,          $_isc_tot_ebt,        'background:#ede7f6;font-weight:700;',  ''],
    ['isc_row_tax',        $_isc_tax,          $_isc_tot_tax,        '',                                     'text-danger'],
    ['isc_row_net_income', $_isc_net_income,   $_isc_tot_net_income, 'background:#c8e6c9;font-weight:700;font-size:0.87rem;border-top:2px solid #388e3c;', ''],
];
?>
<?php foreach ($_isc_table_rows as [$lkey, $vals, $tot, $rowstyle, $tcls]): ?>
<tr style="<?= $rowstyle ?>">
    <td class="<?= $tcls ?>"><?= __($lkey) ?></td>
    <?php foreach ($vals as $v): ?>
    <td class="text-end <?= $tcls ?>"
        style="<?= ((float)$v < 0) ? 'color:#c62828;' : '' ?>">
        <?= isc_fmt_short((float)$v) ?>
    </td>
    <?php endforeach; ?>
    <td class="text-end fw-bold <?= $tcls ?>"
        style="<?= ($tot !== null && $tot < 0) ? 'color:#c62828;' : '' ?>">
        <?= $tot !== null ? isc_fmt_short((float)$tot) : '—' ?>
    </td>
</tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Margin cards ───────────────────────────────────────────────────────────── -->
<?php if ($_isc_tot_revenue != 0): ?>
<div class="row g-2 mt-2 no-print">
<?php
$_isc_margin_cards = [
    ['isc_margin_gross',   $_isc_tot_gp,         'text-primary'],
    ['isc_margin_ebitda',  $_isc_tot_ebitda,     'text-success'],
    ['isc_margin_ebit',    $_isc_tot_ebit,       'text-info'],
    ['isc_margin_ebt',     $_isc_tot_ebt,        'text-secondary'],
    ['isc_margin_net',     $_isc_tot_net_income, 'text-success'],
];
foreach ($_isc_margin_cards as [$mkey, $mval, $mcls]):
    $pct = round(($mval / $_isc_tot_revenue) * 100, 1);
    $cls = $mval >= 0 ? $mcls : 'text-danger';
?>
<div class="col-6 col-md">
    <div class="card h-100">
        <div class="card-body text-center py-2 px-2">
            <div class="text-muted" style="font-size:0.78rem;"><?= __($mkey) ?></div>
            <div class="fs-5 fw-bold <?= $cls ?>"><?= $pct ?>%</div>
            <div class="text-muted" style="font-size:0.7rem;"><?= __($mkey . '_lbl') ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; // end empty check ?>

<!-- ── Chart.js initialization ───────────────────────────────────────────────── -->
<?php if (!empty($_isc_labels)): ?>
<script>
(function() {
    const labels  = <?= $_isc_json_labels ?>;
    const fmt     = v => {
        const s = v < 0 ? 'Rp -' : 'Rp ';
        return s + Math.abs(v).toLocaleString('id-ID', {maximumFractionDigits: 0});
    };

    const datasets = [
        {
            type: 'bar',
            label: '<?= __('isc_ds_revenue') ?>',
            data: <?= $_isc_json_revenue ?>,
            backgroundColor: 'rgba(21,101,192,0.75)',
            borderColor: '#1565c0',
            borderWidth: 1,
            order: 2,
        },
        {
            type: 'bar',
            label: '<?= __('isc_ds_cogs') ?>',
            data: <?= $_isc_json_cogs ?>,
            backgroundColor: 'rgba(198,40,40,0.65)',
            borderColor: '#c62828',
            borderWidth: 1,
            order: 2,
        },
        {
            type: 'bar',
            label: '<?= __('isc_ds_gross') ?>',
            data: <?= $_isc_json_gp ?>,
            backgroundColor: 'rgba(0,105,92,0.7)',
            borderColor: '#00695c',
            borderWidth: 1,
            order: 2,
        },
        {
            type: 'bar',
            label: '<?= __('isc_ds_da') ?>',
            data: <?= $_isc_json_da ?>,
            backgroundColor: 'rgba(149,117,205,0.55)',
            borderColor: '#9575cd',
            borderWidth: 1,
            order: 2,
        },
        {
            type: 'line',
            label: '<?= __('isc_ds_ebitda') ?>',
            data: <?= $_isc_json_ebitda ?>,
            borderColor: '#2e7d32',
            backgroundColor: 'rgba(46,125,50,0.12)',
            borderWidth: 2.5,
            pointRadius: 4,
            pointHoverRadius: 6,
            tension: 0.35,
            fill: false,
            order: 1,
        },
        {
            type: 'line',
            label: '<?= __('isc_ds_ebit') ?>',
            data: <?= $_isc_json_ebit ?>,
            borderColor: '#00838f',
            backgroundColor: 'rgba(0,131,143,0.1)',
            borderWidth: 2,
            borderDash: [5,3],
            pointRadius: 3,
            tension: 0.35,
            fill: false,
            order: 1,
        },
        {
            type: 'line',
            label: '<?= __('isc_ds_ebt') ?>',
            data: <?= $_isc_json_ebt ?>,
            borderColor: '#6a1b9a',
            backgroundColor: 'transparent',
            borderWidth: 2,
            borderDash: [4,4],
            pointRadius: 3,
            tension: 0.35,
            fill: false,
            order: 1,
        },
        {
            type: 'line',
            label: '<?= __('isc_ds_net') ?>',
            data: <?= $_isc_json_net ?>,
            borderColor: '#004d40',
            backgroundColor: 'rgba(0,77,64,0.1)',
            borderWidth: 3,
            pointRadius: 5,
            pointHoverRadius: 7,
            tension: 0.35,
            fill: false,
            order: 1,
        },
    ];

    const ctx = document.getElementById('iscChart').getContext('2d');
    new Chart(ctx, {
        data: { labels, datasets },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { boxWidth: 12, font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y)
                    }
                }
            },
            scales: {
                x: {
                    ticks: { font: { size: 10 } },
                    grid: { display: false }
                },
                y: {
                    ticks: {
                        font: { size: 10 },
                        callback: v => fmt(v)
                    },
                    grid: { color: 'rgba(0,0,0,0.06)' }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>
