<?php
/**
 * export_bs.php
 * Structured Excel (.xlsx) export for Balance Sheet Detail and Grouped.
 * Called by export_report.php — all shared vars already defined.
 *
 * Required vars from router:
 *   $pdo, $date_from, $date_to, $company_id, $estate_id, $division_id,
 *   $_company_display, $report_type, $xv, $fmtDate, $col_letter
 */

if (ob_get_level()) { ob_end_clean(); }

$detail  = ($report_type === 'balance_sheet');
$grouped = ($report_type === 'balance_sheet_group');

// ── Fetch data ────────────────────────────────────────────────────────────────
$bs_cond = ["je.status='posted'", "je.entry_date<=:bs_date_to"];
$bs_p    = [':bs_date_to' => $date_to];
if ($company_id)  { $bs_cond[] = "je.company_id=:company_id";        $bs_p[':company_id']  = $company_id; }
if ($estate_id)   { $bs_cond[] = "je.business_unit_id=:estate_id";   $bs_p[':estate_id']   = $estate_id; }
if ($division_id) { $bs_cond[] = "je.division_id=:division_id";      $bs_p[':division_id'] = $division_id; }
$bw = implode(' AND ', $bs_cond);

if ($detail) {
    $sql = "SELECT gla.id AS gl_id, gla.account_code, gla.account_name, gla.account_type,
                   SUM(jel.debit_amount) AS td, SUM(jel.credit_amount) AS tc
            FROM journal_entries je
            JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
            JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
            WHERE $bw AND gla.account_type IN('asset','liability','equity')
            GROUP BY gla.id, gla.account_code, gla.account_name, gla.account_type
            HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0
            ORDER BY gla.account_type, gla.account_code";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bs_p);
    $raw_rows = $stmt->fetchAll();

    // Period movement for previous balance
    $det_prd_cond = ["je.status='posted'", "je.entry_date>=:p_from", "je.entry_date<=:p_to"];
    $det_prd_p    = [':p_from' => $date_from, ':p_to' => $date_to];
    if ($company_id)  { $det_prd_cond[] = "je.company_id=:company_id";        $det_prd_p[':company_id']  = $company_id; }
    if ($estate_id)   { $det_prd_cond[] = "je.business_unit_id=:estate_id";   $det_prd_p[':estate_id']   = $estate_id; }
    if ($division_id) { $det_prd_cond[] = "je.division_id=:division_id";      $det_prd_p[':division_id'] = $division_id; }
    $det_pw = implode(' AND ', $det_prd_cond);
    $sql_det_prd = "SELECT jel.gl_account_id, gla.account_type,
                           SUM(jel.debit_amount) AS td, SUM(jel.credit_amount) AS tc
                    FROM journal_entries je
                    JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                    JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                    WHERE $det_pw AND gla.account_type IN('asset','liability','equity')
                    GROUP BY jel.gl_account_id, gla.account_type";
    $stmt_det_prd = $pdo->prepare($sql_det_prd);
    $stmt_det_prd->execute($det_prd_p);
    $det_period_mov = [];
    foreach ($stmt_det_prd->fetchAll() as $pr) {
        $dbt = (float)$pr['td']; $cdt = (float)$pr['tc'];
        $mov = ($pr['account_type'] === 'asset') ? ($dbt - $cdt) : ($cdt - $dbt);
        $det_period_mov[$pr['gl_account_id']] = $mov;
    }

    $buckets = ['asset' => [], 'liability' => [], 'equity' => []];
    foreach ($raw_rows as $r) {
        $dbt = (float)$r['td']; $cdt = (float)$r['tc'];
        $bal = ($r['account_type'] === 'asset') ? ($dbt - $cdt) : ($cdt - $dbt);
        $mov = $det_period_mov[$r['gl_id']] ?? 0;
        $buckets[$r['account_type']][] = [
            'code'     => $r['account_code'],
            'name'     => $r['account_name'],
            'prev_bal' => $bal - $mov,
            'debit'    => $dbt,
            'credit'   => $cdt,
            'balance'  => $bal,
        ];
    }
} else {
    $all_groups_stmt = $pdo->query(
        "SELECT id, group_name, report_section, display_order
         FROM financial_account_groups
         WHERE report_type='balance_sheet' AND is_active=TRUE
           AND (is_total_line IS NULL OR is_total_line=FALSE)
         ORDER BY display_order, group_code"
    );
    $all_bs_groups = $all_groups_stmt ? $all_groups_stmt->fetchAll() : [];

    $sql_end = "SELECT fag.id AS group_id, gla.account_type,
                       SUM(jel.debit_amount) AS td, SUM(jel.credit_amount) AS tc
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                JOIN financial_account_groups fag ON fag.id=gla.financial_group_id
                WHERE $bw AND gla.account_type IN('asset','liability','equity')
                  AND fag.report_type='balance_sheet' AND fag.is_active=TRUE
                GROUP BY fag.id, gla.account_type
                HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0";
    $stmt = $pdo->prepare($sql_end);
    $stmt->execute($bs_p);
    $end_raw = $stmt->fetchAll();

    $prd_cond = ["je.status='posted'", "je.entry_date>=:p_from", "je.entry_date<=:p_to"];
    $prd_p    = [':p_from' => $date_from, ':p_to' => $date_to];
    if ($company_id)  { $prd_cond[] = "je.company_id=:company_id";        $prd_p[':company_id']  = $company_id; }
    if ($estate_id)   { $prd_cond[] = "je.business_unit_id=:estate_id";   $prd_p[':estate_id']   = $estate_id; }
    if ($division_id) { $prd_cond[] = "je.division_id=:division_id";      $prd_p[':division_id'] = $division_id; }
    $pw = implode(' AND ', $prd_cond);
    $sql_prd = "SELECT fag.id AS group_id, gla.account_type,
                       SUM(jel.debit_amount) AS td, SUM(jel.credit_amount) AS tc
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                JOIN financial_account_groups fag ON fag.id=gla.financial_group_id
                WHERE $pw AND gla.account_type IN('asset','liability','equity')
                  AND fag.report_type='balance_sheet' AND fag.is_active=TRUE
                GROUP BY fag.id, gla.account_type";
    $stmt_prd = $pdo->prepare($sql_prd);
    $stmt_prd->execute($prd_p);
    $period_mov = [];
    foreach ($stmt_prd->fetchAll() as $pr) {
        $dbt = (float)$pr['td']; $cdt = (float)$pr['tc'];
        $mov = ($pr['account_type'] === 'asset') ? ($dbt - $cdt) : ($cdt - $dbt);
        $period_mov[$pr['group_id']] = ($period_mov[$pr['group_id']] ?? 0) + $mov;
    }

    $grp_bal = [];
    foreach ($end_raw as $r) {
        $dbt = (float)$r['td']; $cdt = (float)$r['tc'];
        $bal = ($r['account_type'] === 'asset') ? ($dbt - $cdt) : ($cdt - $dbt);
        if (!isset($grp_bal[$r['group_id']])) {
            $grp_bal[$r['group_id']] = ['balance' => 0, 'debit' => 0, 'credit' => 0, 'account_type' => $r['account_type']];
        }
        $grp_bal[$r['group_id']]['balance'] += $bal;
        $grp_bal[$r['group_id']]['debit']   += $dbt;
        $grp_bal[$r['group_id']]['credit']  += $cdt;
    }

    $buckets = ['asset' => [], 'liability' => [], 'equity' => []];
    foreach ($all_bs_groups as $g) {
        $gid = $g['id'];
        if (!isset($grp_bal[$gid])) continue;
        $acc_type = $grp_bal[$gid]['account_type'];
        if (!array_key_exists($acc_type, $buckets)) continue;
        $ending = $grp_bal[$gid]['balance'];
        $mov    = $period_mov[$gid] ?? 0;
        $buckets[$acc_type][] = [
            'name'     => $g['group_name'],
            'section'  => $g['report_section'] ?? '',
            'prev_bal' => $ending - $mov,
            'debit'    => $grp_bal[$gid]['debit'],
            'credit'   => $grp_bal[$gid]['credit'],
            'balance'  => $ending,
        ];
    }
}

$total_assets   = array_sum(array_column($buckets['asset'],     'balance'));
$total_liab     = array_sum(array_column($buckets['liability'], 'balance'));
$total_eq_accts = array_sum(array_column($buckets['equity'],    'balance'));
$current_pl     = $total_assets - ($total_liab + $total_eq_accts);
$total_equity   = $total_eq_accts + $current_pl;
$total_liab_eq  = $total_liab + $total_equity;

// Pre-calculate all subtotal debit/credit/prev so we never need Excel formulas
$ta_d  = array_sum(array_column($buckets['asset'],     'debit'));
$ta_c  = array_sum(array_column($buckets['asset'],     'credit'));
$tl_d  = array_sum(array_column($buckets['liability'], 'debit'));
$tl_c  = array_sum(array_column($buckets['liability'], 'credit'));
$te_d  = array_sum(array_column($buckets['equity'],    'debit'));
$te_c  = array_sum(array_column($buckets['equity'],    'credit'));
$grand_d = $ta_d + $tl_d + $te_d;
$grand_c = $ta_c + $tl_c + $te_c;

$ta_p  = array_sum(array_column($buckets['asset'],     'prev_bal'));
$tl_p  = array_sum(array_column($buckets['liability'], 'prev_bal'));
$te_p_accts = array_sum(array_column($buckets['equity'], 'prev_bal'));
$cpl_p = $ta_p - ($tl_p + $te_p_accts);
$te_p  = $te_p_accts + $cpl_p;
$tle_p = $tl_p + $te_p;

$sec_totals_d = ['asset' => $ta_d, 'liability' => $tl_d, 'equity' => $te_d];
$sec_totals_c = ['asset' => $ta_c, 'liability' => $tl_c, 'equity' => $te_c];
$sec_totals_b = ['asset' => $total_assets, 'liability' => $total_liab, 'equity' => $total_eq_accts];
$sec_totals_p = ['asset' => $ta_p, 'liability' => $tl_p, 'equity' => $te_p_accts];

// ── Shared-string table ───────────────────────────────────────────────────────
$si_map = []; $si_list = [];
$si_add = function(string $s) use (&$si_map, &$si_list): int {
    if (!isset($si_map[$s])) { $si_map[$s] = count($si_list); $si_list[] = $s; }
    return $si_map[$s];
};

$bs_title   = $detail ? 'Balance Sheet (Detail)' : 'Balance Sheet (Grouped)';
$as_at_str  = 'As at: ' . $fmtDate($date_to);
$period_str = 'Period: ' . $fmtDate($date_from) . ' to ' . $fmtDate($date_to)
            . '  |  Previous Balance = balance before ' . $fmtDate($date_from);
foreach ([$_company_display, $bs_title, $as_at_str, $period_str] as $s) $si_add($s);

// Detail:  Code | Name | Prev Balance | Debit | Credit | Ending Balance  → 6 cols (A–F)
// Grouped: Report Section | Group | Prev Balance | Ending Balance        → 4 cols (A–D)
$COL_BAL   = $detail ? 5 : 3;
$COL_PREV  = 2;
$COL_DEBIT = $detail ? 3 : -1;
$COL_CRED  = $detail ? 4 : -1;
$HDR_BAL   = $col_letter($COL_BAL);
$HDR_PREV  = $col_letter($COL_PREV);
$HDR_DEBIT = $detail ? $col_letter($COL_DEBIT) : '';
$HDR_CRED  = $detail ? $col_letter($COL_CRED)  : '';

$hdr_labels = $detail
    ? ['Account Code', 'Account Name', 'Previous Balance', 'Debit', 'Credit', 'Ending Balance']
    : ['Report Section', 'Group', 'Previous Balance', 'Ending Balance'];
foreach ($hdr_labels as $h) $si_add($h);

$sec_labels      = ['asset' => 'ASSETS', 'liability' => 'LIABILITIES', 'equity' => 'EQUITY'];
$subtotal_labels = [
    'asset'     => 'TOTAL ASSETS',
    'liability' => 'TOTAL LIABILITIES',
    'equity'    => 'TOTAL EQUITY (excl. current period P/L)',
];
foreach ($sec_labels as $l)      $si_add($l);
foreach ($subtotal_labels as $l) $si_add($l);
foreach (['Current Period Profit / (Loss)', 'TOTAL EQUITY',
          'TOTAL LIABILITIES + EQUITY', '(no data)', '—'] as $s) $si_add($s);

// ── Row builder helpers ───────────────────────────────────────────────────────
$ws_rows = ''; $rn = 1;

$emit_row = function(array $cells, int $ht = 0) use (&$ws_rows, &$rn): void {
    $attr = $ht > 0 ? ' ht="'.$ht.'" customHeight="1"' : '';
    $ws_rows .= '<row r="'.$rn.'"'.$attr.'>';
    foreach ($cells as $cell) $ws_rows .= $cell;
    $ws_rows .= '</row>';
    $rn++;
};
$sc = function(int $col, string $val, int $style = 0) use (&$rn, $col_letter, &$si_map, $si_add): string {
    $si_add($val);
    return '<c r="'.$col_letter($col).$rn.'" t="s" s="'.$style.'"><v>'.$si_map[$val].'</v></c>';
};
$nc = function(int $col, float $val, int $style = 4) use (&$rn, $col_letter): string {
    return '<c r="'.$col_letter($col).$rn.'" s="'.$style.'"><v>'.$val.'</v></c>';
};
$fc = function(int $col, string $formula, int $style = 13) use (&$rn, $col_letter): string {
    return '<c r="'.$col_letter($col).$rn.'" s="'.$style.'"><f>'.htmlspecialchars($formula, ENT_XML1).'</f></c>';
};
$bc = function(int $col, int $style = 0) use (&$rn, $col_letter): string {
    return '<c r="'.$col_letter($col).$rn.'" s="'.$style.'"/>';
};

// ── Header rows ───────────────────────────────────────────────────────────────
$emit_row([$sc(0, $_company_display, 1)], 18);
$emit_row([$sc(0, $bs_title, 1)], 16);
$emit_row([$sc(0, $as_at_str, 2)]);
$emit_row([$sc(0, $period_str, 2)]);
$emit_row([]);
$hdr_cells = [];
foreach ($hdr_labels as $ci => $h) $hdr_cells[] = $sc($ci, $h, 3);
$emit_row($hdr_cells, 16);

// ── Section rows ─────────────────────────────────────────────────────────────
foreach (['asset', 'liability', 'equity'] as $sec) {
    $bkt = $buckets[$sec];

    $sec_cells = [$sc(0, $sec_labels[$sec], 7)];
    for ($ci = 1; $ci <= $COL_BAL; $ci++) $sec_cells[] = $bc($ci, 7);
    $emit_row($sec_cells, 14);

    if (empty($bkt)) {
        $emit_row([$sc(0, '(no data)', 8)]);
        if ($detail) {
            $emit_row([$sc(0, $subtotal_labels[$sec], 10), $bc(1,10), $nc(2, 0.0, 11), $nc(3, 0.0, 11), $nc(4, 0.0, 11), $nc(5, 0.0, 11)], 14);
        } else {
            $emit_row([$sc(0, $subtotal_labels[$sec], 10), $bc(1,10), $nc(2, 0.0, 11), $nc(3, 0.0, 11)], 14);
        }
    } else {
        $alt = false; $cur_section = null;
        foreach ($bkt as $item) {
            if (!$detail && isset($item['section']) && $item['section'] !== $cur_section) {
                $cur_section = $item['section'];
                $si_add($cur_section);
                $emit_row([$sc(0, $cur_section, 14), $bc(1,14), $bc(2,14), $bc(3,14)]);
            }
            $ts = $alt ? 5 : 8; $ns = $alt ? 6 : 9;
            if ($detail) {
                $emit_row([
                    $sc(0, $item['code'],     $ts),
                    $sc(1, $item['name'],     $ts),
                    $nc(2, $item['prev_bal'], $ns),
                    $nc(3, $item['debit'],    $ns),
                    $nc(4, $item['credit'],   $ns),
                    $nc(5, $item['balance'],  $ns),
                ]);
            } else {
                $emit_row([
                    $bc(0, $ts),
                    $sc(1, $item['name'],     $ts),
                    $nc(2, $item['prev_bal'], $ns),
                    $nc(3, $item['balance'],  $ns),
                ]);
            }
            $alt = !$alt;
        }
        // Subtotal row — static PHP values, no Excel formula
        if ($detail) {
            $emit_row([$sc(0, $subtotal_labels[$sec], 10), $bc(1,10), $nc(2, $sec_totals_p[$sec], 11), $nc(3, $sec_totals_d[$sec], 11), $nc(4, $sec_totals_c[$sec], 11), $nc(5, $sec_totals_b[$sec], 11)], 14);
        } else {
            $emit_row([$sc(0, $subtotal_labels[$sec], 10), $bc(1,10), $nc(2, $sec_totals_p[$sec], 11), $nc(3, $sec_totals_b[$sec], 11)], 14);
        }
    }
}

// ── Current Period P/L row — static values ────────────────────────────────────
if ($detail) {
    $emit_row([$sc(0, '—', 8), $sc(1, 'Current Period Profit / (Loss)', 8), $nc(2, $cpl_p, 9), $nc(3, 0.0, 9), $nc(4, 0.0, 9), $nc(5, $current_pl, 9)]);
} else {
    $emit_row([$bc(0, 8), $sc(1, 'Current Period Profit / (Loss)', 8), $nc(2, $cpl_p, 9), $nc(3, $current_pl, 9)]);
}

// ── TOTAL EQUITY row — static values ─────────────────────────────────────────
if ($detail) {
    $emit_row([$sc(0, 'TOTAL EQUITY', 10), $bc(1,10), $nc(2, $te_p, 11), $nc(3, $te_d, 11), $nc(4, $te_c, 11), $nc(5, $total_equity, 11)], 14);
} else {
    $emit_row([$sc(0, 'TOTAL EQUITY', 10), $bc(1,10), $nc(2, $te_p, 11), $nc(3, $total_equity, 11)], 14);
}

// ── TOTAL LIABILITIES + EQUITY row — static values ───────────────────────────
if ($detail) {
    $emit_row([$sc(0, 'TOTAL LIABILITIES + EQUITY', 12), $bc(1,12), $nc(2, $tle_p, 13), $nc(3, $grand_d, 13), $nc(4, $grand_c, 13), $nc(5, $total_liab_eq, 13)], 18);
} else {
    $emit_row([$sc(0, 'TOTAL LIABILITIES + EQUITY', 12), $bc(1,12), $nc(2, $tle_p, 13), $nc(3, $total_liab_eq, 13)], 18);
}

// ── Worksheet XML ─────────────────────────────────────────────────────────────
$col_widths = $detail ? [14, 40, 18, 16, 16, 18] : [28, 30, 20, 20];

$ws  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$ws .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
     . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
$ws .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
     . '<pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/>'
     . '</sheetView></sheetViews>';
$ws .= '<sheetFormatPr defaultRowHeight="15" customHeight="1"/>';
$ws .= '<cols>';
foreach ($col_widths as $ci => $w)
    $ws .= '<col min="'.($ci+1).'" max="'.($ci+1).'" width="'.$w.'" customWidth="1"/>';
$ws .= '</cols>';
$ws .= '<sheetData>'.$ws_rows.'</sheetData>';
$ws .= '<pageSetup orientation="portrait" paperSize="9" fitToPage="1" fitToWidth="1" fitToHeight="0"/>';
$ws .= '</worksheet>';

// ── sharedStrings XML ─────────────────────────────────────────────────────────
$n_si = count($si_list);
$sst  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
      . ' count="'.$n_si.'" uniqueCount="'.$n_si.'">';
foreach ($si_list as $s) $sst .= '<si><t xml:space="preserve">'.$xv($s).'</t></si>';
$sst .= '</sst>';

// ── Styles XML ────────────────────────────────────────────────────────────────
$xl_styles  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$xl_styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
$xl_styles .= '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>';
$xl_styles .= '<fonts count="7">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF166C82"/><name val="Calibri"/></font>'
            . '<font><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FFAAAAAA"/><name val="Calibri"/></font>'
            . '</fonts>';
$xl_styles .= '<fills count="9">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF166C82"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF0F7FF"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFCFE2FF"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0D4F60"/></patternFill></fill>'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF8F9FA"/></patternFill></fill>'
            . '</fills>';
$xl_styles .= '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>'
            . '<left style="thin"><color rgb="FFB0C4DE"/></left>'
            . '<right style="thin"><color rgb="FFB0C4DE"/></right>'
            . '<top style="thin"><color rgb="FFB0C4DE"/></top>'
            . '<bottom style="thin"><color rgb="FFB0C4DE"/></bottom>'
            . '<diagonal/></border>'
            . '</borders>';
$xl_styles .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
$xl_styles .= '<cellXfs count="15">'
            . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            . '<xf numFmtId="0"   fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0"   fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0"   fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf>'
            . '<xf numFmtId="0"   fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf>'
            . '<xf numFmtId="0"   fontId="3" fillId="6" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment indent="2"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf>'
            . '<xf numFmtId="0"   fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="164" fontId="4" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf>'
            . '<xf numFmtId="0"   fontId="5" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="5" fillId="5" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"><alignment horizontal="right"/></xf>'
            . '<xf numFmtId="0"   fontId="6" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '</cellXfs>';
$xl_styles .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
$xl_styles .= '</styleSheet>';

// ── Workbook & relationship files ─────────────────────────────────────────────
$sheet_name = $detail ? 'Balance Sheet' : 'BS Grouped';
$wb  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
     . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
     . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
     . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="14400" windowHeight="9000"/></bookViews>'
     . '<sheets><sheet name="'.$xv($sheet_name).'" sheetId="1" r:id="rId1"/></sheets>'
     . '</workbook>';

$wb_rels
    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"    Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"        Target="styles.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
    . '</Relationships>';

$root_rels
    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

$ct
    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml"  ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml"          ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/sharedStrings.xml"     ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
    . '<Override PartName="/xl/styles.xml"            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>';

$parts = [
    '[Content_Types].xml'        => $ct,
    '_rels/.rels'                => $root_rels,
    'xl/workbook.xml'            => $wb,
    'xl/_rels/workbook.xml.rels' => $wb_rels,
    'xl/styles.xml'              => $xl_styles,
    'xl/sharedStrings.xml'       => $sst,
    'xl/worksheets/sheet1.xml'   => $ws,
];

// ── Build ZIP (self-contained, no external require) ───────────────────────────
if (extension_loaded('zip')) {
    $tmp = tempnam(sys_get_temp_dir(), 'xls_');
    @unlink($tmp);
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE);
    foreach ($parts as $_n => $_d) { $zip->addFromString($_n, $_d); }
    $zip->close();
    $zip_bytes = file_get_contents($tmp);
    @unlink($tmp);
} else {
    $zip_bytes = ''; $central = ''; $offsets = [];
    $dostime = (((2024-1980)&0x7f)<<25)|((1&0x0f)<<21)|((1&0x1f)<<16);
    foreach ($parts as $_n => $_d) {
        $offsets[$_n] = strlen($zip_bytes);
        $_crc = crc32($_d); $_sz = strlen($_d); $_nl = strlen($_n);
        $zip_bytes .= "\x50\x4b\x03\x04".pack('v',20).pack('v',0).pack('v',0)
                    . pack('V',$dostime).pack('V',$_crc).pack('V',$_sz).pack('V',$_sz)
                    . pack('v',$_nl).pack('v',0).$_n.$_d;
        $central   .= "\x50\x4b\x01\x02".pack('v',20).pack('v',20).pack('v',0).pack('v',0)
                    . pack('V',$dostime).pack('V',$_crc).pack('V',$_sz).pack('V',$_sz)
                    . pack('v',$_nl).pack('v',0).pack('v',0).pack('v',0).pack('v',0)
                    . pack('V',0).pack('V',$offsets[$_n]).$_n;
    }
    $_cdo = strlen($zip_bytes); $_cds = strlen($central); $_ne = count($parts);
    $zip_bytes .= $central."\x50\x4b\x05\x06".pack('v',0).pack('v',0)
                . pack('v',$_ne).pack('v',$_ne).pack('V',$_cds).pack('V',$_cdo).pack('v',0);
}

$safe_fn = ($detail ? 'Balance_Sheet_Detail' : 'Balance_Sheet_Grouped') . '_' . date('Ymd') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$safe_fn.'"');
header('Content-Length: '.strlen($zip_bytes));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache'); header('Expires: 0');
echo $zip_bytes;
exit;
