<?php
/**
 * Balance Sheet Chart — reports/balance_sheet_chart.php
 *
 * Unlike the Income Statement Chart (which is periodic), the Balance Sheet
 * is a POSITION/SNAPSHOT report: balances are cumulative as of a given date.
 *
 * This chart does two things:
 *   1. SNAPSHOT at date_to  → KPI cards + donut chart (Assets / Liabilities / Equity)
 *   2. TREND across snapshots → stacked bar comparing multiple position dates
 *      Snapshot modes: End-of-month (last 12), End-of-quarter (last 8), End-of-year (last 5)
 *
 * The filter form's "date_to" drives the primary snapshot.
 * "date_from" is ignored (BS is cumulative, not periodic).
 */

// ── 0. Snapshot trend mode ────────────────────────────────────────────────────
$_bsc_mode = in_array($_GET['bs_snap'] ?? '', ['monthly','quarterly','yearly'])
    ? $_GET['bs_snap'] : 'quarterly';

// ── 1. Org-scope params (reused for every snapshot query) ─────────────────────
$_bsc_org_conds  = [];
$_bsc_org_params = [];
if (!empty($company_id))  { $_bsc_org_conds[] = "je.company_id = :company_id";         $_bsc_org_params[':company_id']  = $company_id; }
if (!empty($estate_id))   { $_bsc_org_conds[] = "je.business_unit_id = :estate_id";    $_bsc_org_params[':estate_id']   = $estate_id; }
if (!empty($division_id)) { $_bsc_org_conds[] = "je.division_id = :division_id";       $_bsc_org_params[':division_id'] = $division_id; }
$_bsc_org_extra = $_bsc_org_conds ? (' AND ' . implode(' AND ', $_bsc_org_conds)) : '';

// ── 2. Helper: fetch Assets / Liabilities / Equity as of a given date ─────────
function bsc_snapshot(PDO $pdo, string $as_of, string $org_extra, array $org_params): array {
    $params = array_merge([':bsc_as_of' => $as_of], $org_params);
    $sql = "
        SELECT
            gla.account_type,
            SUM(jel.debit_amount)  AS total_debit,
            SUM(jel.credit_amount) AS total_credit
        FROM journal_entries          je
        JOIN journal_entry_lines      jel ON jel.journal_entry_id = je.id
        JOIN general_ledger_accounts  gla ON gla.id               = jel.gl_account_id
        WHERE je.status        = 'posted'
          AND je.entry_date   <= :bsc_as_of
          AND gla.account_type IN ('asset','liability','equity')
          $org_extra
        GROUP BY gla.account_type
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $dbt = (float)$r['total_debit'];
        $cdt = (float)$r['total_credit'];
        $result[$r['account_type']] = match($r['account_type']) {
            'asset'               => $dbt - $cdt,
            'liability', 'equity' => $cdt - $dbt,
            default               => 0.0,
        };
    }
    return $result;
}

// ── 3. Primary snapshot (as of date_to) ───────────────────────────────────────
$_bsc_primary = bsc_snapshot($pdo, $date_to, $_bsc_org_extra, $_bsc_org_params);
$_bsc_assets      = $_bsc_primary['asset'];
$_bsc_liabilities = $_bsc_primary['liability'];
$_bsc_equity      = $_bsc_primary['equity'];
$_bsc_l_plus_e    = $_bsc_liabilities + $_bsc_equity;
$_bsc_gap         = abs($_bsc_assets - $_bsc_l_plus_e);
$_bsc_balanced    = $_bsc_gap < 0.01;
$_bsc_de_ratio    = $_bsc_equity != 0 ? round($_bsc_liabilities / $_bsc_equity, 2) : null;

// ── 4. Build trend snapshot dates ─────────────────────────────────────────────
// Generate a list of position dates going back from date_to
$_bsc_as_of   = new DateTime($date_to);
$_bsc_snap_dates = [];

if ($_bsc_mode === 'monthly') {
    // Last 12 end-of-month dates
    for ($i = 0; $i < 12; $i++) {
        $d = clone $_bsc_as_of;
        $d->modify("-{$i} months");
        $d->modify('last day of this month');
        // cap at date_to
        if ($d > $_bsc_as_of) $d = clone $_bsc_as_of;
        $_bsc_snap_dates[] = $d->format('Y-m-d');
    }
} elseif ($_bsc_mode === 'quarterly') {
    // Last 8 end-of-quarter dates
    $qm = [3,6,9,12];
    $cur = clone $_bsc_as_of;
    $count = 0;
    while ($count < 8) {
        $m = (int)$cur->format('n');
        // find the quarter-end month ≤ current month
        $qend = null;
        foreach (array_reverse($qm) as $qm_val) {
            if ($qm_val <= $m) { $qend = $qm_val; break; }
        }
        if ($qend === null) { $qend = 12; $cur->modify('-1 year'); }
        $d = new DateTime($cur->format('Y') . '-' . str_pad($qend, 2, '0', STR_PAD_LEFT) . '-01');
        $d->modify('last day of this month');
        if ($d > $_bsc_as_of) $d = clone $_bsc_as_of;
        $_bsc_snap_dates[] = $d->format('Y-m-d');
        $cur->modify('first day of this month');
        $cur->modify("-{$qend} months + 1 month");
        $cur->modify('last day of previous month');
        $count++;
    }
} else {
    // Last 5 end-of-year dates
    $yr = (int)$_bsc_as_of->format('Y');
    for ($i = 0; $i < 5; $i++) {
        $y = $yr - $i;
        $d = new DateTime("{$y}-12-31");
        if ($d > $_bsc_as_of) $d = clone $_bsc_as_of;
        $_bsc_snap_dates[] = $d->format('Y-m-d');
    }
}

$_bsc_snap_dates = array_unique(array_reverse($_bsc_snap_dates));

// ── 5. Fetch each snapshot ────────────────────────────────────────────────────
$_bsc_trend_labels = [];
$_bsc_trend_assets = [];
$_bsc_trend_liab   = [];
$_bsc_trend_equity = [];

foreach ($_bsc_snap_dates as $snap_date) {
    $snap = bsc_snapshot($pdo, $snap_date, $_bsc_org_extra, $_bsc_org_params);
    // Skip snapshots with no data at all
    if ($snap['asset'] == 0 && $snap['liability'] == 0 && $snap['equity'] == 0) continue;

    // Format label
    if ($_bsc_mode === 'monthly') {
        $_bsc_trend_labels[] = (new DateTime($snap_date))->format('M Y');
    } elseif ($_bsc_mode === 'quarterly') {
        $dt = new DateTime($snap_date);
        $q  = ceil((int)$dt->format('n') / 3);
        $_bsc_trend_labels[] = 'Q' . $q . ' ' . $dt->format('Y');
    } else {
        $_bsc_trend_labels[] = (new DateTime($snap_date))->format('Y');
    }

    $_bsc_trend_assets[] = round($snap['asset'],     0);
    $_bsc_trend_liab[]   = round($snap['liability'], 0);
    $_bsc_trend_equity[] = round($snap['equity'],    0);
}

// ── 6. URL builder for snapshot toggle ───────────────────────────────────────
$_bsc_base_qs = array_filter([
    'report'      => 'balance_sheet_chart',
    'company_id'  => $company_id,
    'estate_id'   => $business_unit_id,
    'division_id' => $division_id,
    'date_to'     => $date_to,
], fn($v) => $v !== '' && $v !== null);

// ── 7. Helpers ────────────────────────────────────────────────────────────────
function bsc_fmt(float $v): string {
    return ($v < 0 ? 'Rp -' : 'Rp ') . number_format(abs($v), 0, ',', '.');
}
function bsc_fmt_short(float $v): string {
    return ($v < 0 ? 'Rp -' : 'Rp ') . number_format(abs($v), 0, ',', '.');
}

// JSON for chart
$_bsc_json_labels = json_encode($_bsc_trend_labels, JSON_UNESCAPED_UNICODE);
$_bsc_json_assets = json_encode($_bsc_trend_assets);
$_bsc_json_liab   = json_encode($_bsc_trend_liab);
$_bsc_json_equity = json_encode($_bsc_trend_equity);
$_bsc_json_lpe    = json_encode(array_map(fn($l,$e) => $l+$e, $_bsc_trend_liab, $_bsc_trend_equity));
?>

<!-- ── Chart.js (skip if already loaded by income_statement_chart) ───────────── -->
<?php if (!defined('_CHARTJS_LOADED')): define('_CHARTJS_LOADED', true); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php endif; ?>

<!-- ── Header bar ────────────────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div>
        <h5 class="mb-0 fw-semibold">
            <i class="bi bi-bank" style="color:#166c82;"></i>
            <?= __('bsc_title') ?>
        </h5>
        <div class="text-muted small mt-1">
            <?= __('bsc_as_at') ?>: <strong><?= (new DateTime($date_to))->format('d/m/Y') ?></strong>
            <?php if ($_bsc_balanced): ?>
                <span class="badge bg-success ms-2"><i class="bi bi-check-circle"></i> <?= __('bsc_balanced') ?></span>
            <?php else: ?>
                <span class="badge bg-danger ms-2"><i class="bi bi-exclamation-triangle"></i> <?= __('bsc_unbalanced') ?> <?= bsc_fmt($_bsc_gap) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap no-print">
        <!-- Trend snapshot toggle -->
        <div class="btn-group btn-group-sm" role="group">
            <?php
            $snap_modes = [
                'monthly'   => __('bsc_snap_monthly'),
                'quarterly' => __('bsc_snap_quarterly'),
                'yearly'    => __('bsc_snap_yearly'),
            ];
            foreach ($snap_modes as $sk => $sl):
                $active = $_bsc_mode === $sk;
            ?>
            <a href="?<?= http_build_query($_bsc_base_qs + ['bs_snap' => $sk]) ?>"
               class="btn <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>"
               <?= $active ? 'style="background:#166c82;border-color:#166c82;"' : '' ?>>
                <?= htmlspecialchars($sl) ?>
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
    $kpis = [
        ['bsc_kpi_assets',      $_bsc_assets,      '#1565c0'],
        ['bsc_kpi_liabilities', $_bsc_liabilities, '#b71c1c'],
        ['bsc_kpi_equity',      $_bsc_equity,      '#1b5e20'],
        ['bsc_kpi_lpe',         $_bsc_l_plus_e,    $_bsc_balanced ? '#004d40' : '#e65100'],
    ];
    foreach ($kpis as [$key, $val, $col]):
        $bg = $val >= 0 ? $col : '#c62828';
    ?>
    <div class="col-6 col-md-3">
        <div class="card text-white h-100" style="background:<?= $bg ?>;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold lh-sm" style="font-size:0.82rem;"><?= __($key) ?></div>
                <div class="fw-bold mt-1" style="font-size:0.85rem;"><?= bsc_fmt_short((float)$val) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if ($_bsc_de_ratio !== null): ?>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body py-2 px-3 text-center">
                <div class="text-muted" style="font-size:0.78rem;"><?= __('bsc_kpi_de_ratio') ?></div>
                <div class="fw-bold fs-5 <?= $_bsc_de_ratio <= 1 ? 'text-success' : ($_bsc_de_ratio <= 2 ? 'text-warning' : 'text-danger') ?>">
                    <?= number_format($_bsc_de_ratio, 2, '.', '') ?>x
                </div>
                <div class="text-muted" style="font-size:0.7rem;"><?= __('bsc_kpi_de_lbl') ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($_bsc_trend_labels)): ?>
<div class="alert alert-info"><?= __('bsc_no_data') ?></div>
<?php else: ?>

<div class="row g-3 mb-3">
    <!-- ── Trend Chart (stacked bar) ────────────────────────────────────────── -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header py-2 text-white" style="background:#166c82;">
                <strong><i class="bi bi-bar-chart-line"></i> <?= __('bsc_chart_trend_title') ?></strong>
                <small class="ms-2 opacity-75"><?= __('bsc_chart_trend_sub') ?></small>
            </div>
            <div class="card-body p-2">
                <canvas id="bscTrendChart" style="max-height:380px;"></canvas>
            </div>
        </div>
    </div>
    <!-- ── Composition Donut ────────────────────────────────────────────────── -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header py-2 text-white" style="background:#166c82;">
                <strong><i class="bi bi-pie-chart"></i> <?= __('bsc_chart_donut_title') ?></strong>
                <small class="ms-2 opacity-75"><?= __('bsc_as_at') ?> <?= (new DateTime($date_to))->format('d/m/Y') ?></small>
            </div>
            <div class="card-body p-2 d-flex align-items-center justify-content-center">
                <canvas id="bscDonutChart" style="max-height:300px;max-width:300px;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ── Summary Table ─────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header py-2 text-white" style="background:#166c82;">
        <strong><i class="bi bi-table"></i> <?= __('bsc_table_title') ?></strong>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" style="font-size:0.82rem;">
                <thead class="table-light" style="font-size:0.75rem;">
                    <tr>
                        <th><?= __('bsc_col_item') ?></th>
                        <?php foreach ($_bsc_trend_labels as $lbl): ?>
                        <th class="text-end"><?= htmlspecialchars($lbl) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $rows = [
                    ['bsc_row_assets',     $_bsc_trend_assets, 'background:#e3f2fd;font-weight:600;', ''],
                    ['bsc_row_liabilities',$_bsc_trend_liab,   '', 'text-danger'],
                    ['bsc_row_equity',     $_bsc_trend_equity, '', 'text-success'],
                    ['bsc_row_lpe',        array_map(fn($l,$e)=>$l+$e, $_bsc_trend_liab, $_bsc_trend_equity),
                                                                'background:#e8f5e9;font-weight:700;', ''],
                ];
                foreach ($rows as [$lkey, $vals, $rstyle, $tcls]): ?>
                <tr style="<?= $rstyle ?>">
                    <td class="<?= $tcls ?> fw-semibold"><?= __($lkey) ?></td>
                    <?php foreach ($vals as $v): ?>
                    <td class="text-end <?= $tcls ?>" style="<?= ((float)$v < 0) ? 'color:#c62828;' : '' ?>">
                        <?= bsc_fmt_short((float)$v) ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <!-- Balanced check row -->
                <tr style="border-top:2px solid #adb5bd;font-size:0.75rem;">
                    <td class="text-muted fst-italic"><?= __('bsc_row_check') ?></td>
                    <?php
                    foreach (array_keys($_bsc_trend_assets) as $i):
                        $a   = (float)$_bsc_trend_assets[$i];
                        $lpe = (float)$_bsc_trend_liab[$i] + (float)$_bsc_trend_equity[$i];
                        $diff = abs($a - $lpe);
                        $ok  = $diff < 0.01;
                    ?>
                    <td class="text-center <?= $ok ? 'text-success' : 'text-danger' ?>">
                        <?= $ok ? '✓' : bsc_fmt_short($diff) ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ── Chart.js scripts ───────────────────────────────────────────────────────── -->
<?php if (!empty($_bsc_trend_labels)): ?>
<script>
(function() {
    const fmtRp = v => {
        const s = v < 0 ? 'Rp -' : 'Rp ';
        return s + Math.abs(v).toLocaleString('id-ID', {maximumFractionDigits: 0});
    };

    // ── Trend stacked bar + assets line ──────────────────────────────────────
    new Chart(document.getElementById('bscTrendChart').getContext('2d'), {
        data: {
            labels: <?= $_bsc_json_labels ?>,
            datasets: [
                {
                    type: 'bar',
                    label: '<?= __('bsc_ds_liabilities') ?>',
                    data: <?= $_bsc_json_liab ?>,
                    backgroundColor: 'rgba(183,28,28,0.7)',
                    borderColor: '#b71c1c',
                    borderWidth: 1,
                    stack: 'lpe',
                    order: 2,
                },
                {
                    type: 'bar',
                    label: '<?= __('bsc_ds_equity') ?>',
                    data: <?= $_bsc_json_equity ?>,
                    backgroundColor: 'rgba(27,94,32,0.7)',
                    borderColor: '#1b5e20',
                    borderWidth: 1,
                    stack: 'lpe',
                    order: 2,
                },
                {
                    type: 'line',
                    label: '<?= __('bsc_ds_assets') ?>',
                    data: <?= $_bsc_json_assets ?>,
                    borderColor: '#1565c0',
                    backgroundColor: 'rgba(21,101,192,0.1)',
                    borderWidth: 3,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    tension: 0.3,
                    fill: false,
                    stack: undefined,
                    order: 1,
                },
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.dataset.label + ': ' + fmtRp(ctx.parsed.y)
                    }
                }
            },
            scales: {
                x: { ticks: { font: { size: 10 } }, grid: { display: false } },
                y: {
                    ticks: { font: { size: 10 }, callback: v => fmtRp(v) },
                    grid: { color: 'rgba(0,0,0,0.06)' }
                }
            }
        }
    });

    // ── Donut chart (Assets / Liabilities / Equity at date_to) ───────────────
    const donutData = [
        Math.abs(<?= round($_bsc_assets, 0) ?>),
        Math.abs(<?= round($_bsc_liabilities, 0) ?>),
        Math.abs(<?= round($_bsc_equity, 0) ?>),
    ];
    new Chart(document.getElementById('bscDonutChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: [
                '<?= __('bsc_ds_assets') ?>',
                '<?= __('bsc_ds_liabilities') ?>',
                '<?= __('bsc_ds_equity') ?>',
            ],
            datasets: [{
                data: donutData,
                backgroundColor: [
                    'rgba(21,101,192,0.8)',
                    'rgba(183,28,28,0.8)',
                    'rgba(27,94,32,0.8)',
                ],
                borderWidth: 2,
                borderColor: '#fff',
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.label + ': ' + fmtRp(ctx.parsed)
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>
