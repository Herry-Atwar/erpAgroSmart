<?php
// General Ledger Report
//
// Displays every posted journal-entry line grouped by GL account.
// For each account:
//   • Opening balance  = net of all posted entries BEFORE date_from
//                        (BS accounts: from inception; P&L: from Jan-1 of date_from's year)
//   • Detail lines     = every jel row in the period, with date / ref / description /
//                        division / activity / block / debit / credit / running balance
//   • Closing balance  = Opening + net period movement
//
// Org filters (company / estate / division) are inherited from financial_reports.php.
// An additional "GL Account" filter lets users drill into a single account.

// ── Extra filter: account range (from / to by account_code) ──────────────────
$gl_acct_from = trim($_GET['gl_acct_from'] ?? '');  // account_code lower bound
$gl_acct_to   = trim($_GET['gl_acct_to']   ?? '');  // account_code upper bound

// Legacy single-account param forwarded from drill-down links
if (isset($_GET['gl_account_id']) && (int)$_GET['gl_account_id'] > 0) {
    // Will be resolved to a code below after $_gl_all_accounts is loaded
    $_gl_legacy_single_id = (int)$_GET['gl_account_id'];
} else {
    $_gl_legacy_single_id = 0;
}

// ── Back-navigation: detect which report drilled into GL ─────────────────────
$_gl_from         = $_GET['from'] ?? '';           // 'profit_loss_detail' | 'balance_sheet' | 'trial_balance' | ''
$_gl_back_fat     = $_GET['filter_account_type'] ?? ''; // carried from profit_loss_detail

// Fetch all GL accounts for the filter dropdown
$_gl_all_accounts = $pdo->query(
    "SELECT id, account_code, account_name, account_type
     FROM general_ledger_accounts
     ORDER BY account_code"
)->fetchAll(PDO::FETCH_ASSOC);

// Resolve legacy single-account drill-down → set both bounds to that account's code
if ($_gl_legacy_single_id) {
    foreach ($_gl_all_accounts as $_a) {
        if ($_a['id'] == $_gl_legacy_single_id) {
            $gl_acct_from = $_a['account_code'];
            $gl_acct_to   = $_a['account_code'];
            break;
        }
    }
}

// ── Org filter fragment (mirrors trial_balance.php) ───────────────────────────
$_gl_org  = '';
$_gl_orgp = [];
if ($company_id)  { $_gl_org .= " AND je.company_id = :company_id";        $_gl_orgp[':company_id']  = $company_id; }
if ($estate_id)   { $_gl_org .= " AND je.business_unit_id = :estate_id";   $_gl_orgp[':estate_id']   = $estate_id; }
if ($division_id) { $_gl_org .= " AND je.division_id = :division_id";      $_gl_orgp[':division_id'] = $division_id; }
if ($gl_acct_from !== '' || $gl_acct_to !== '') {
    if ($gl_acct_from !== '' && $gl_acct_to !== '') {
        $_gl_org  .= " AND gla.account_code BETWEEN :gl_acct_from AND :gl_acct_to";
        $_gl_orgp[':gl_acct_from'] = $gl_acct_from;
        $_gl_orgp[':gl_acct_to']   = $gl_acct_to;
    } elseif ($gl_acct_from !== '') {
        $_gl_org  .= " AND gla.account_code >= :gl_acct_from";
        $_gl_orgp[':gl_acct_from'] = $gl_acct_from;
    } else {
        $_gl_org  .= " AND gla.account_code <= :gl_acct_to";
        $_gl_orgp[':gl_acct_to'] = $gl_acct_to;
    }
}
// Convenience flag: is any account range active?
$_gl_range_active = ($gl_acct_from !== '' || $gl_acct_to !== '');

// ── Date helpers ──────────────────────────────────────────────────────────────
$_gl_year_start  = substr($date_from, 0, 4) . '-01-01';
$_gl_day_before  = date('Y-m-d', strtotime($date_from . ' -1 day'));

$_gl_bs_types = ['asset', 'liability', 'equity'];
$_gl_pl_types = ['revenue', 'cogs', 'operating_expense', 'expense',
                 'other_income', 'other_expenses', 'tax'];
$_gl_bs_tl    = "'" . implode("','", $_gl_bs_types) . "'";
$_gl_pl_tl    = "'" . implode("','", $_gl_pl_types) . "'";

// ── Opening balance queries ───────────────────────────────────────────────────
// BS: sum all entries from inception up to (date_from - 1 day)
$_gl_ob_bs = [];
$_gl_ob_bs_sql = "
    SELECT gla.id AS gl_id,
           SUM(jel.debit_amount)  AS ob_debit,
           SUM(jel.credit_amount) AS ob_credit
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id = jel.gl_account_id
    WHERE je.status = 'posted'
      AND je.entry_date <= :ob_before
      AND gla.account_type IN ($_gl_bs_tl)
      $_gl_org
    GROUP BY gla.id
";
$_gl_ob_bs_stmt = $pdo->prepare($_gl_ob_bs_sql);
$_gl_ob_bs_stmt->execute(array_merge([':ob_before' => $_gl_day_before], $_gl_orgp));
foreach ($_gl_ob_bs_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $_gl_ob_bs[$r['gl_id']] = (float)$r['ob_debit'] - (float)$r['ob_credit'];
}

// P&L: sum from Jan-1 of year up to (date_from - 1 day)
$_gl_ob_pl = [];
if ($_gl_year_start <= $_gl_day_before) {
    $_gl_ob_pl_sql = "
        SELECT gla.id AS gl_id,
               SUM(jel.debit_amount)  AS ob_debit,
               SUM(jel.credit_amount) AS ob_credit
        FROM journal_entries je
        JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
        JOIN general_ledger_accounts gla ON gla.id = jel.gl_account_id
        WHERE je.status = 'posted'
          AND je.entry_date >= :ob_yr_start
          AND je.entry_date <= :ob_before
          AND gla.account_type IN ($_gl_pl_tl)
          $_gl_org
        GROUP BY gla.id
    ";
    $_gl_ob_pl_stmt = $pdo->prepare($_gl_ob_pl_sql);
    $_gl_ob_pl_stmt->execute(array_merge(
        [':ob_yr_start' => $_gl_year_start, ':ob_before' => $_gl_day_before],
        $_gl_orgp
    ));
    foreach ($_gl_ob_pl_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $_gl_ob_pl[$r['gl_id']] = (float)$r['ob_debit'] - (float)$r['ob_credit'];
    }
}

// ── Period detail lines ───────────────────────────────────────────────────────
$_gl_lines_sql = "
    SELECT
        gla.id               AS gl_id,
        gla.account_code,
        gla.account_name,
        gla.account_type,
        je.entry_date,
        je.reference_number,
        je.description       AS je_description,
        je.entry_type,
        jel.description      AS line_description,
        jel.debit_amount,
        jel.credit_amount,
        jel.cost_category,
        jel.cost_center,
        jel.profit_center,
        jel.currency_code,
        c.company_name,
        bu.unit_name         AS estate_name,
        d.division_name,
        b.block_code,
        a.activity_code,
        a.activity_name,
        jel.sap_text         AS notes
    FROM journal_entries je
    JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
    JOIN general_ledger_accounts gla ON gla.id = jel.gl_account_id
    LEFT JOIN companies          c   ON je.company_id        = c.company_id
    LEFT JOIN business_units     bu  ON je.business_unit_id  = bu.business_unit_id
    LEFT JOIN divisions          d   ON je.division_id       = d.division_id
    LEFT JOIN blocks             b   ON jel.block_id         = b.block_id
    LEFT JOIN activities         a   ON jel.activity_id      = a.id
    WHERE je.status = 'posted'
      AND je.entry_date >= :gl_from
      AND je.entry_date <= :gl_to
      $_gl_org
    ORDER BY gla.account_code, je.entry_date, je.reference_number, jel.line_number
";
$_gl_lines_stmt = $pdo->prepare($_gl_lines_sql);
$_gl_lines_stmt->execute(array_merge(
    [':gl_from' => $date_from, ':gl_to' => $date_to],
    $_gl_orgp
));
$_gl_raw = $_gl_lines_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Group lines by GL account ─────────────────────────────────────────────────
$_gl_accounts = [];  // account_code => ['meta' => [...], 'lines' => [...]]
foreach ($_gl_raw as $row) {
    $gid = $row['gl_id'];
    if (!isset($_gl_accounts[$gid])) {
        $_gl_accounts[$gid] = [
            'meta'  => [
                'code' => $row['account_code'],
                'name' => $row['account_name'],
                'type' => $row['account_type'],
            ],
            'lines' => [],
        ];
    }
    $_gl_accounts[$gid]['lines'][] = $row;
}

// Include accounts that have an opening balance but no period activity.
// These must still appear so the user can see the carried-forward balance.
// Build a lookup from the already-fetched dropdown list to avoid an extra query.
$_gl_acct_meta_lookup = [];
foreach ($_gl_all_accounts as $_acct) {
    $_gl_acct_meta_lookup[$_acct['id']] = $_acct;
}
foreach (array_keys($_gl_ob_bs + $_gl_ob_pl) as $gid) {
    if (!isset($_gl_accounts[$gid]) && isset($_gl_acct_meta_lookup[$gid])) {
        $_m = $_gl_acct_meta_lookup[$gid];
        $_gl_accounts[$gid] = [
            'meta'  => [
                'code' => $_m['account_code'],
                'name' => $_m['account_name'],
                'type' => $_m['account_type'],
            ],
            'lines' => [],
        ];
    }
}

// Sort by account_code
uasort($_gl_accounts, fn($a, $b) => strcmp($a['meta']['code'], $b['meta']['code']));

// ── P&L account types (for drill-down link logic) ─────────────────────────────
$_gl_pl_drill_types = ['revenue', 'cogs', 'operating_expense', 'expense',
                       'other_income', 'other_expenses', 'tax'];
// Map account_type → filter_account_type param accepted by profit_loss_detail
$_gl_pl_type_map = [
    'revenue'           => 'revenue',
    'cogs'              => 'cogs',
    'operating_expense' => 'operating_expense',
    'expense'           => 'operating_expense',  // expense maps to opex section
    'other_income'      => 'other_income',
    'other_expenses'    => 'other_expenses',
    'tax'               => 'tax',
];

// Build shared query-string base for cross-report links (org + date filters)
$_gl_qs_base = http_build_query(array_filter([
    'company_id'  => $company_id,
    'estate_id'   => $estate_id,
    'division_id' => $division_id,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
], fn($v) => $v !== '' && $v !== null));

// Base URL for the clear-filter link (org + date, no account range)
$_gl_clear_url = '?report=general_ledger'
    . ($company_id  ? '&company_id='.$company_id   : '')
    . ($estate_id   ? '&estate_id='.$estate_id     : '')
    . ($division_id ? '&division_id='.$division_id : '')
    . '&date_from=' . $date_from
    . '&date_to='   . $date_to;

// ── Helper functions ──────────────────────────────────────────────────────────
if (!function_exists('gl_fmt')) {
    function gl_fmt(float $v): string {
        if ($v == 0) return '-';
        return $v < 0
            ? '(' . number_format(abs($v), 0, ',', '.') . ')'
            :        number_format($v, 0, ',', '.');
    }
    function gl_bal(float $v): string {
        // Balance: positive = plain number, negative = (number)
        $abs = abs($v);
        if ($abs < 0.005) return '-';
        return $v < 0
            ? '(' . number_format($abs, 0, ',', '.') . ')'
            :        number_format($abs, 0, ',', '.');
    }
    function gl_fmt_date(string $ymd): string {
        $d = DateTime::createFromFormat('Y-m-d', $ymd);
        return $d ? $d->format('d/m/Y') : htmlspecialchars($ymd);
    }
    function gl_type_label(string $t): string {
        return [
            'asset'             => __('glr_type_asset'),
            'liability'         => __('glr_type_liability'),
            'equity'            => __('glr_type_equity'),
            'revenue'           => __('glr_type_revenue'),
            'cogs'              => __('glr_type_cogs'),
            'operating_expense' => __('glr_type_opex'),
            'expense'           => __('glr_type_expense'),
            'other_income'      => __('glr_type_other_income'),
            'other_expenses'    => __('glr_type_other_exp'),
            'tax'               => __('glr_type_tax'),
        ][$t] ?? ucfirst(str_replace('_', ' ', $t));
    }
}

// ── Totals ────────────────────────────────────────────────────────────────────
$_gl_grand_ob     = 0;
$_gl_grand_debit  = 0;
$_gl_grand_credit = 0;
$_gl_grand_close  = 0;
foreach ($_gl_accounts as $gid => &$acc) {
    $ob  = $_gl_ob_bs[$gid] ?? ($_gl_ob_pl[$gid] ?? 0);
    $dr  = array_sum(array_column($acc['lines'], 'debit_amount'));
    $cr  = array_sum(array_column($acc['lines'], 'credit_amount'));
    $cl  = $ob + $dr - $cr;
    $acc['opening'] = $ob;
    $acc['total_debit']  = $dr;
    $acc['total_credit'] = $cr;
    $acc['closing'] = $cl;
    $_gl_grand_ob     += $ob;
    $_gl_grand_debit  += $dr;
    $_gl_grand_credit += $cr;
    $_gl_grand_close  += $cl;
}
unset($acc);
?>

<!-- ── Header bar ──────────────────────────────────────────────────────────── -->
<?php
// Build the back URL based on where the user came from
$_gl_back_url = null;
if ($_gl_from === 'profit_loss_detail') {
    $_gl_back_url = '?' . http_build_query(array_filter([
        'report'              => 'profit_loss_detail',
        'company_id'          => $company_id,
        'estate_id'           => $estate_id,
        'division_id'         => $division_id,
        'date_from'           => $date_from,
        'date_to'             => $date_to,
        'filter_account_type' => $_gl_back_fat ?: null,
        'from'                => 'profit_loss_detail',
    ], fn($v) => $v !== '' && $v !== null));
    $_gl_back_label = htmlspecialchars(__('glr_back_pl_detail'));
} elseif ($_gl_from === 'balance_sheet') {
    $_gl_back_url = '?' . http_build_query(array_filter([
        'report'      => 'balance_sheet',
        'company_id'  => $company_id,
        'estate_id'   => $estate_id,
        'division_id' => $division_id,
        'date_from'   => $date_from,
        'date_to'     => $date_to,
    ], fn($v) => $v !== '' && $v !== null));
    $_gl_back_label = htmlspecialchars(__('glr_back_balance_sheet'));
} elseif ($_gl_from === 'trial_balance') {
    $_gl_back_url = '?' . http_build_query(array_filter([
        'report'      => 'trial_balance',
        'company_id'  => $company_id,
        'estate_id'   => $estate_id,
        'division_id' => $division_id,
        'date_from'   => $date_from,
        'date_to'     => $date_to,
    ], fn($v) => $v !== '' && $v !== null));
    $_gl_back_label = htmlspecialchars(__('glr_back_trial_balance'));
}
?>
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h5 class="mb-0 fw-semibold">
            <i class="bi bi-journal-richtext" style="color:#166c82;"></i>
            <?= htmlspecialchars(__('glr_title')) ?>
        </h5>
        <div class="text-muted small mt-1">
            <?= htmlspecialchars(__('glr_period')) ?>: <strong><?= gl_fmt_date($date_from) ?></strong> &rarr; <strong><?= gl_fmt_date($date_to) ?></strong>
            &nbsp;&bull;&nbsp; <?= count($_gl_accounts) ?> <?= htmlspecialchars(__('glr_accounts_count')) ?> &nbsp;&bull;&nbsp;
            <?= array_sum(array_map(fn($a) => count($a['lines']), $_gl_accounts)) ?> <?= htmlspecialchars(__('glr_lines_count')) ?>
        </div>
    </div>
    <div class="d-flex gap-2 no-print">
        <?php if ($_gl_back_url): ?>
        <a href="<?= htmlspecialchars($_gl_back_url) ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-arrow-left"></i> <?= $_gl_back_label ?>
        </a>
        <?php endif; ?>
        <button onclick="exportToExcel('general_ledger')" class="btn btn-sm btn-success">
            <i class="bi bi-file-earmark-excel"></i> <?= htmlspecialchars(__('glr_export_excel')) ?>
        </button>
        <button onclick="printGeneralLedger()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer"></i> <?= htmlspecialchars(__('glr_print')) ?>
        </button>
    </div>
</div>

<!-- ── Extra: GL Account filter ─────────────────────────────────────────────── -->
<div class="card mb-3 no-print">
    <div class="card-body py-2 px-3">
        <form method="GET" action="" class="row g-2 align-items-end">
            <?php foreach ($_GET as $k => $v):
                if (in_array($k, ['gl_acct_from','gl_acct_to','gl_account_id'])) continue; ?>
                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="report" value="general_ledger">
            <div class="col-auto">
                <label class="form-label mb-0 small fw-semibold"><?= htmlspecialchars(__('glr_acct_from')) ?></label>
                <select name="gl_acct_from" class="form-select form-select-sm" style="min-width:240px;">
                    <option value=""><?= htmlspecialchars(__('glr_all_accounts')) ?></option>
                    <?php foreach ($_gl_all_accounts as $acct): ?>
                        <option value="<?= htmlspecialchars($acct['account_code']) ?>"
                            <?= $gl_acct_from === $acct['account_code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($acct['account_code']) ?> &ndash; <?= htmlspecialchars($acct['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto" style="padding-top:1.6rem;">
                <span class="text-muted small fw-semibold">&rarr;</span>
            </div>
            <div class="col-auto">
                <label class="form-label mb-0 small fw-semibold"><?= htmlspecialchars(__('glr_acct_to')) ?></label>
                <select name="gl_acct_to" class="form-select form-select-sm" style="min-width:240px;">
                    <option value=""><?= htmlspecialchars(__('glr_all_accounts')) ?></option>
                    <?php foreach ($_gl_all_accounts as $acct): ?>
                        <option value="<?= htmlspecialchars($acct['account_code']) ?>"
                            <?= $gl_acct_to === $acct['account_code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($acct['account_code']) ?> &ndash; <?= htmlspecialchars($acct['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm" style="background-color:#166c82;color:#fff;">
                    <i class="bi bi-search"></i> <?= htmlspecialchars(__('glr_apply')) ?>
                </button>
                <?php if ($_gl_range_active): ?>
                    <a href="<?= htmlspecialchars($_gl_clear_url) ?>"
                       class="btn btn-sm btn-outline-secondary ms-1">
                        <i class="bi bi-x-circle"></i> <?= htmlspecialchars(__('glr_clear')) ?>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ── Summary cards ─────────────────────────────────────────────────────────── -->
<div class="row g-2 mb-3">
    <div class="col-md-3">
        <div class="card text-white" style="background-color:#4527a0;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold" style="font-size:0.82rem;"><?= htmlspecialchars(__('glr_opening_balance')) ?></div>
                <div class="fs-6 fw-bold mt-1"><?= gl_fmt($_gl_grand_ob) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white" style="background-color:#1565c0;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold" style="font-size:0.82rem;"><?= htmlspecialchars(__('glr_total_debit')) ?></div>
                <div class="fs-6 fw-bold mt-1"><?= 'Rp ' . number_format($_gl_grand_debit, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white" style="background-color:#c62828;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold" style="font-size:0.82rem;"><?= htmlspecialchars(__('glr_total_credit')) ?></div>
                <div class="fs-6 fw-bold mt-1"><?= 'Rp ' . number_format($_gl_grand_credit, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-white" style="background-color:#2e7d32;">
            <div class="card-body py-2 px-3">
                <div class="fw-semibold" style="font-size:0.82rem;"><?= htmlspecialchars(__('glr_closing_balance')) ?></div>
                <div class="fs-6 fw-bold mt-1"><?= gl_fmt($_gl_grand_close) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Print area ────────────────────────────────────────────────────────────── -->
<div id="gl-print-area"
     data-date-from="<?= htmlspecialchars($date_from) ?>"
     data-date-to="<?= htmlspecialchars($date_to) ?>"
     data-i18n="<?= htmlspecialchars(json_encode([
         'title'    => __('glr_title'),
         'period'   => __('glr_period'),
         'print_by' => __('rpt_print_by'),
         'datetime' => __('rpt_datetime'),
     ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">

<?php if (empty($_gl_accounts)): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> <?= htmlspecialchars(__('glr_no_transactions')) ?>
</div>
<?php else: ?>

<?php foreach ($_gl_accounts as $gid => $acc):
    $ob  = $acc['opening'];
    $cl  = $acc['closing'];
    $dr  = $acc['total_debit'];
    $cr  = $acc['total_credit'];
    $running = $ob;
?>
<?php
// Build drill-down URLs for this account
$_gl_pld_url = null;   // → profit_loss_detail filtered to this account's P&L section
$_gl_gl_self_url = null; // → GL filtered to just this account
if (in_array($acc['meta']['type'], $_gl_pl_drill_types)) {
    $_fat = $_gl_pl_type_map[$acc['meta']['type']] ?? null;
    if ($_fat) {
        $_gl_pld_url = '?' . $_gl_qs_base . '&report=profit_loss_detail'
                     . '&filter_account_type=' . urlencode($_fat)
                     . '&from=general_ledger';
    }
}
$_gl_gl_self_url = '?' . $_gl_qs_base . '&report=general_ledger'
    . '&gl_acct_from=' . urlencode($acc['meta']['code'])
    . '&gl_acct_to='   . urlencode($acc['meta']['code']);
?>
<div class="card mb-3">
    <!-- Account header -->
    <div class="card-header py-2 d-flex justify-content-between align-items-center"
         style="background-color:#166c82;color:#fff;">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <strong>
                <code style="color:#a8d5e2;"><?= htmlspecialchars($acc['meta']['code']) ?></code>
                &nbsp;<?= htmlspecialchars($acc['meta']['name']) ?>
            </strong>
            <span class="badge" style="background-color:#0d4f5e;font-size:0.72rem;">
                <?= gl_type_label($acc['meta']['type']) ?>
            </span>
            <?php if ($_gl_pld_url): ?>
            <a href="<?= htmlspecialchars($_gl_pld_url) ?>"
               class="badge no-print text-decoration-none"
               style="background-color:#e8f5e9;color:#1b5e20;font-size:0.72rem;"
               title="<?= htmlspecialchars(__('glr_pld_link_title')) ?>">
                <i class="bi bi-graph-up-arrow"></i> <?= htmlspecialchars(__('glr_pld_link_label')) ?>
            </a>
            <?php endif; ?>
            <?php if (!$_gl_range_active): ?>
            <a href="<?= htmlspecialchars($_gl_gl_self_url) ?>"
               class="badge no-print text-decoration-none"
               style="background-color:#fff3cd;color:#664d03;font-size:0.72rem;"
               title="<?= htmlspecialchars(__('glr_filter_link_title')) ?>">
                <i class="bi bi-funnel-fill"></i> <?= htmlspecialchars(__('glr_filter_link_label')) ?>
            </a>
            <?php endif; ?>
        </div>
        <div class="text-end flex-shrink-0" style="font-size:0.82rem;opacity:0.9;">
            <?= htmlspecialchars(__('glr_opening_balance')) ?>: <strong><?= gl_bal($ob) ?></strong>
            &nbsp;&bull;&nbsp;
            <?= htmlspecialchars(__('glr_closing_balance')) ?>: <strong><?= gl_bal($cl) ?></strong>
        </div>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" style="font-size:0.82rem;">
                <thead style="background-color:#e9f4f7;">
                    <tr>
                        <th style="width:7%"><?= htmlspecialchars(__('glr_col_date')) ?></th>
                        <th style="width:10%"><?= htmlspecialchars(__('glr_col_journal_no')) ?></th>
                        <th style="width:18%"><?= htmlspecialchars(__('glr_col_description')) ?></th>
                        <th style="width:7%"><?= htmlspecialchars(__('glr_col_type')) ?></th>
                        <th style="width:8%"><?= htmlspecialchars(__('glr_col_division')) ?></th>
                        <th style="width:8%"><?= htmlspecialchars(__('glr_col_activity')) ?></th>
                        <th style="width:6%"><?= htmlspecialchars(__('glr_col_block')) ?></th>
                        <th style="width:7%"><?= htmlspecialchars(__('glr_col_category')) ?></th>
                        <th class="text-end" style="width:9%"><?= htmlspecialchars(__('glr_col_debit')) ?></th>
                        <th class="text-end" style="width:9%"><?= htmlspecialchars(__('glr_col_credit')) ?></th>
                        <th class="text-end" style="width:11%"><?= htmlspecialchars(__('glr_col_balance')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Opening balance row -->
                    <tr style="background-color:#f0f8ff;">
                        <td colspan="8" class="text-muted fst-italic" style="font-size:0.78rem;">
                            <strong><?= htmlspecialchars(__('glr_opening_row')) ?></strong>
                            <span class="ms-2 small"><?= sprintf(htmlspecialchars(__('glr_opening_before')), gl_fmt_date($date_from)) ?></span>
                        </td>
                        <td class="text-end text-muted">-</td>
                        <td class="text-end text-muted">-</td>
                        <td class="text-end fw-semibold">
                            <?= gl_bal($ob) ?>
                        </td>
                    </tr>

                    <?php foreach ($acc['lines'] as $ln):
                        $running += (float)$ln['debit_amount'] - (float)$ln['credit_amount'];
                        // Description: prefer line description, fall back to header description
                        $desc = trim($ln['line_description'] ?: $ln['je_description']);
                        // Combine notes if any
                        if (!empty($ln['notes'])) $desc .= ' — ' . $ln['notes'];
                    ?>
                    <tr>
                        <td class="text-nowrap"><?= gl_fmt_date($ln['entry_date']) ?></td>
                        <td>
                            <a href="../journal_entry_detail.php?ref=<?= urlencode($ln['reference_number']) ?>"
                               class="text-decoration-none" target="_blank">
                                <code style="font-size:0.78rem;"><?= htmlspecialchars($ln['reference_number']) ?></code>
                            </a>
                        </td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            title="<?= htmlspecialchars($desc) ?>">
                            <?= htmlspecialchars($desc) ?>
                        </td>
                        <td>
                            <span class="badge" style="font-size:0.68rem;background-color:#dee2e6;color:#495057;">
                                <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $ln['entry_type'] ?? ''))) ?>
                            </span>
                        </td>
                        <td class="text-truncate" style="max-width:80px;"
                            title="<?= htmlspecialchars($ln['division_name'] ?? '') ?>">
                            <?= htmlspecialchars($ln['division_name'] ?? '-') ?>
                        </td>
                        <td class="text-truncate" style="max-width:80px;"
                            title="<?= htmlspecialchars(($ln['activity_code'] ? $ln['activity_code'].' ' : '') . ($ln['activity_name'] ?? '')) ?>">
                            <?= $ln['activity_code']
                                ? htmlspecialchars($ln['activity_code'])
                                : '<span class="text-muted">-</span>' ?>
                        </td>
                        <td><?= $ln['block_code'] ? htmlspecialchars($ln['block_code']) : '<span class="text-muted">-</span>' ?></td>
                        <td>
                            <?php if ($ln['cost_category']): ?>
                                <span class="badge" style="font-size:0.68rem;background-color:#e8f5e9;color:#2e7d32;">
                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $ln['cost_category']))) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end <?= $ln['debit_amount'] > 0 ? 'fw-semibold' : 'text-muted' ?>">
                            <?= $ln['debit_amount'] > 0 ? number_format($ln['debit_amount'], 0, ',', '.') : '-' ?>
                        </td>
                        <td class="text-end <?= $ln['credit_amount'] > 0 ? 'fw-semibold' : 'text-muted' ?>">
                            <?= $ln['credit_amount'] > 0 ? '(' . number_format($ln['credit_amount'], 0, ',', '.') . ')' : '-' ?>
                        </td>
                        <td class="text-end fw-semibold">
                            <?= gl_bal($running) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Subtotal row -->
                    <tr style="background-color:#f8f9fa;font-weight:700;border-top:2px solid #adb5bd;">
                        <td colspan="8" class="text-end" style="font-size:0.8rem;">
                            <?= htmlspecialchars(__('glr_subtotal')) ?> &mdash; <?= htmlspecialchars($acc['meta']['code']) ?>
                        </td>
                        <td class="text-end fw-semibold"><?= $dr > 0 ? number_format($dr, 0, ',', '.') : '-' ?></td>
                        <td class="text-end fw-semibold"><?= $cr > 0 ? '(' . number_format($cr, 0, ',', '.') . ')' : '-' ?></td>
                        <td class="text-end fw-semibold">
                            <?= gl_bal($cl) ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ── Grand Total ────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body py-2 px-3">
        <table class="table table-sm mb-0" style="font-size:0.88rem;">
            <tfoot>
                <tr style="background-color:#e9ecef;font-weight:700;border-top:2px solid #adb5bd;">
                    <td style="width:70%"><strong><?= htmlspecialchars(__('glr_grand_total')) ?></strong></td>
                    <td class="text-end" style="width:10%"><?= number_format($_gl_grand_debit,  0, ',', '.') ?></td>
                    <td class="text-end" style="width:10%"><?= $_gl_grand_credit > 0 ? '(' . number_format($_gl_grand_credit, 0, ',', '.') . ')' : '-' ?></td>
                    <td class="text-end" style="width:10%">
                        <strong><?= gl_bal($_gl_grand_close) ?></strong>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php endif; ?>
</div><!-- /#gl-print-area -->

<?php // Powered by IBM Bob ?>
