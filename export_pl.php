<?php
/**
 * export_pl.php
 * Structured Excel (.xlsx) export for Profit & Loss and Detail P&L.
 * Called by export_report.php — all shared vars already defined.
 *
 * Required vars from router:
 *   $pdo, $where_clause, $params, $date_from, $date_to,
 *   $_company_display, $report_type, $xv, $fmtDate, $col_letter
 *
 * NOTE: All totals are written as pre-calculated static numeric values (no
 * Excel formula cells) so the file opens correctly in Google Sheets, LibreOffice,
 * WPS, and older Excel versions without requiring a recalculation pass.
 */

if (ob_get_level()) { ob_end_clean(); }

$detail = ($report_type === 'profit_loss_detail');

// ── Fetch raw account rows ────────────────────────────────────────────────────
// Use $pl_where_clause / $pl_params (stripped of b.status which has no blocks JOIN here)
$_xpl_wc = isset($pl_where_clause) ? $pl_where_clause : $where_clause;
$_xpl_pm = isset($pl_params)       ? $pl_params       : $params;

$sql = "SELECT gla.account_type,
               " . ($detail ? "gla.account_code," : "") . "
               gla.account_name,
               SUM(jel.debit_amount)  AS total_debit,
               SUM(jel.credit_amount) AS total_credit
        FROM journal_entries je
        JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
        JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
        WHERE $_xpl_wc
          AND gla.account_type IN ('revenue','cogs','operating_expense','expense',
                                   'depreciation','other_income','other_expenses','tax')
        GROUP BY gla.id, gla.account_type" . ($detail ? ", gla.account_code" : "") . ", gla.account_name
        HAVING (SUM(jel.debit_amount) + SUM(jel.credit_amount)) > 0
        ORDER BY gla.account_type, gla.account_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($_xpl_pm);
$raw_rows = $stmt->fetchAll();

// ── Bucket rows per section ───────────────────────────────────────────────────
$buckets = ['revenue' => [], 'cogs' => [], 'opex' => [], 'oi' => [], 'oe' => [], 'tax' => []];
foreach ($raw_rows as $r) {
    $dbt = (float)$r['total_debit'];
    $cdt = (float)$r['total_credit'];
    $net_rev = $cdt - $dbt;
    $net_exp = $dbt - $cdt;
    $base = ['name' => $r['account_name'], 'debit' => $dbt, 'credit' => $cdt];
    if ($detail) $base['code'] = $r['account_code'] ?? '';
    switch ($r['account_type']) {
        case 'revenue':          $buckets['revenue'][] = $base + ['net' => $net_rev]; break;
        case 'cogs':             $buckets['cogs'][]    = $base + ['net' => $net_exp]; break;
        case 'operating_expense':
        case 'expense':          $buckets['opex'][]    = $base + ['net' => $net_exp]; break;
        case 'other_income':     $buckets['oi'][]      = $base + ['net' => $net_rev]; break;
        case 'other_expenses':   $buckets['oe'][]      = $base + ['net' => $net_exp]; break;
        case 'tax':              $buckets['tax'][]     = $base + ['net' => $net_exp]; break;
    }
}

// ── Pre-calculate all section totals (PHP-side, no Excel formulas needed) ─────
$sec_totals = [];
foreach ($buckets as $key => $bkt) {
    $sec_totals[$key] = [
        'debit'  => array_sum(array_column($bkt, 'debit')),
        'credit' => array_sum(array_column($bkt, 'credit')),
        'net'    => array_sum(array_column($bkt, 'net')),
    ];
}

$total_revenue = $sec_totals['revenue']['net'];
$total_cogs    = $sec_totals['cogs']['net'];
$total_opex    = $sec_totals['opex']['net'];
$total_oi      = $sec_totals['oi']['net'];
$total_oe      = $sec_totals['oe']['net'];
$total_tax     = $sec_totals['tax']['net'];

$gross_profit      = $total_revenue - $total_cogs;
$operating_profit  = $gross_profit  - $total_opex;
$profit_before_tax = $operating_profit + $total_oi - $total_oe;
$net_profit        = $profit_before_tax - $total_tax;

// Grand totals across all sections (for Net Profit row Debit/Credit columns)
$grand_debit  = array_sum(array_column($sec_totals, 'debit'));
$grand_credit = array_sum(array_column($sec_totals, 'credit'));

// ── Shared-string table ───────────────────────────────────────────────────────
$si_map  = [];
$si_list = [];
$si_add  = function(string $s) use (&$si_map, &$si_list): int {
    if (!isset($si_map[$s])) {
        $si_map[$s] = count($si_list);
        $si_list[]  = $s;
    }
    return $si_map[$s];
};

$pl_title      = $detail ? __('pl_xls_detail_title') : __('pl_xls_title');
$period_str    = __('pl_xls_period_prefix') . ': ' . $fmtDate($date_from) . ' ' . __('pl_xls_to') . ' ' . $fmtDate($date_to);
$printby_str   = $print_by_lbl . ': ' . $printed_by;
$datetime_str  = $datetime_lbl . ': ' . $print_dt;
foreach ([$_company_display, $pl_title, $period_str, $printby_str, $datetime_str] as $s) $si_add($s);

$col_count  = $detail ? 5 : 4;
$COL_DEBIT  = $detail ? 2 : 1;
$COL_CREDIT = $detail ? 3 : 2;
$COL_NET    = $col_count - 1;

$hdr_labels = $detail
    ? [__('pl_xls_col_account_code'), __('pl_xls_col_account_name'), __('pl_xls_col_debit'), __('pl_xls_col_credit'), __('pl_xls_col_net')]
    : [__('pl_xls_col_account_name'), __('pl_xls_col_debit'), __('pl_xls_col_credit'), __('pl_xls_col_net')];
foreach ($hdr_labels as $h) $si_add($h);

$sections = [
    ['key'=>'revenue', 'label'=>__('pl_xls_sec_revenue')],
    ['key'=>'cogs',    'label'=>__('pl_xls_sec_cogs')],
    ['key'=>'opex',    'label'=>__('pl_xls_sec_opex')],
    ['key'=>'oi',      'label'=>__('pl_xls_sec_oi')],
    ['key'=>'oe',      'label'=>__('pl_xls_sec_oe')],
    ['key'=>'tax',     'label'=>__('pl_xls_sec_tax')],
];
foreach ($sections as $sec) $si_add($sec['label']);

$subtotal_labels = [
    'revenue' => __('pl_xls_total_revenue'),
    'cogs'    => __('pl_xls_total_cogs'),
    'opex'    => __('pl_xls_total_opex'),
    'oi'      => __('pl_xls_total_oi'),
    'oe'      => __('pl_xls_total_oe'),
    'tax'     => __('pl_xls_total_tax'),
];
foreach ($subtotal_labels as $lbl) $si_add($lbl);

$calc_labels = [
    'gross' => __('pl_xls_gross_profit'),
    'opinc' => __('pl_xls_op_profit'),
    'pbt'   => __('pl_xls_pbt'),
    'npat'  => __('pl_xls_npat'),
];
foreach ($calc_labels as $lbl) $si_add($lbl);
$si_add(__('pl_xls_no_transactions'));

// ── Row builder helpers ───────────────────────────────────────────────────────
$ws_rows = ''; $rn = 1;

$emit_row = function(array $cells, int $ht = 0) use (&$ws_rows, &$rn): void {
    $attr = $ht > 0 ? ' ht="'.$ht.'" customHeight="1"' : '';
    $ws_rows .= '<row r="'.$rn.'"'.$attr.'>';
    foreach ($cells as $cell) $ws_rows .= $cell;
    $ws_rows .= '</row>';
    $rn++;
};

// String cell
$sc = function(int $col, string $val, int $style = 0) use (&$rn, $col_letter, &$si_map, $si_add): string {
    $si_add($val);
    return '<c r="'.$col_letter($col).$rn.'" t="s" s="'.$style.'"><v>'.$si_map[$val].'</v></c>';
};
// Numeric cell (static value — no formula, works everywhere)
$nc = function(int $col, float $val, int $style = 4) use (&$rn, $col_letter): string {
    return '<c r="'.$col_letter($col).$rn.'" s="'.$style.'"><v>'.$val.'</v></c>';
};
// Blank cell
$bc = function(int $col, int $style = 0) use (&$rn, $col_letter): string {
    return '<c r="'.$col_letter($col).$rn.'" s="'.$style.'"/>';
};

// ── Header rows ───────────────────────────────────────────────────────────────
// Row 1-2: Print-info top-right in the last column (matches PDF layout)
$emit_row([$sc($COL_NET, $printby_str,  14)]);
$emit_row([$sc($COL_NET, $datetime_str, 14)]);
// Row 3: blank separator
$emit_row([]);
// Row 4-6: Company / Title / Period (centred header block)
$emit_row([$sc(0, $_company_display, 1)], 18);
$emit_row([$sc(0, $pl_title, 1)], 16);
$emit_row([$sc(0, $period_str, 2)]);
// Row 7: blank before column headers
$emit_row([]);
// Row 8: column headers (freeze pane sits here)
$hdr_cells = [];
foreach ($hdr_labels as $ci => $h) $hdr_cells[] = $sc($ci, $h, 3);
$emit_row($hdr_cells, 16);

// ── Helpers: emit one section + its subtotal row ─────────────────────────────
$emit_section = function(string $key) use (
    &$emit_row, $sc, $bc, $nc, $detail, $buckets, $sec_totals,
    $sections, $subtotal_labels
): void {
    $sec   = array_values(array_filter($sections, fn($s) => $s['key'] === $key))[0];
    $bkt   = $buckets[$key];
    $tot   = $sec_totals[$key];

    $emit_row([$sc(0, $sec['label'], 7), $bc(1,7), $bc(2,7), $bc(3,7), ...($detail?[$bc(4,7)]:[])], 14);

    if (empty($bkt)) {
        $emit_row([$sc(0, __('pl_xls_no_transactions'), 8)]);
        if ($detail) {
            $emit_row([$sc(0, $subtotal_labels[$key], 10), $bc(1,10), $nc(2, 0.0, 11), $nc(3, 0.0, 11), $nc(4, 0.0, 11)], 14);
        } else {
            $emit_row([$sc(0, $subtotal_labels[$key], 10), $nc(1, 0.0, 11), $nc(2, 0.0, 11), $nc(3, 0.0, 11)], 14);
        }
    } else {
        $alt = false;
        foreach ($bkt as $item) {
            $ts = $alt ? 5 : 8;
            $ns = $alt ? 6 : 9;
            if ($detail) {
                $emit_row([
                    $sc(0, $item['code'] ?? '', $ts),
                    $sc(1, $item['name'], $ts),
                    $nc(2, $item['debit'],  $ns),
                    $nc(3, $item['credit'], $ns),
                    $nc(4, $item['net'],    $ns),
                ]);
            } else {
                $emit_row([
                    $sc(0, $item['name'], $ts),
                    $nc(1, $item['debit'],  $ns),
                    $nc(2, $item['credit'], $ns),
                    $nc(3, $item['net'],    $ns),
                ]);
            }
            $alt = !$alt;
        }
        if ($detail) {
            $emit_row([
                $sc(0, $subtotal_labels[$key], 10),
                $bc(1, 10),
                $nc(2, $tot['debit'],  11),
                $nc(3, $tot['credit'], 11),
                $nc(4, $tot['net'],    11),
            ], 14);
        } else {
            $emit_row([
                $sc(0, $subtotal_labels[$key], 10),
                $nc(1, $tot['debit'],  11),
                $nc(2, $tot['credit'], 11),
                $nc(3, $tot['net'],    11),
            ], 14);
        }
    }
};

// ── Cumulative debit/credit for calc rows ─────────────────────────────────────
$gross_dbt = $sec_totals['revenue']['debit'] + $sec_totals['cogs']['debit'];
$gross_cdt = $sec_totals['revenue']['credit'] + $sec_totals['cogs']['credit'];
$op_dbt    = $gross_dbt + $sec_totals['opex']['debit'];
$op_cdt    = $gross_cdt + $sec_totals['opex']['credit'];
$pbt_dbt   = $op_dbt   + $sec_totals['oi']['debit']  + $sec_totals['oe']['debit'];
$pbt_cdt   = $op_cdt   + $sec_totals['oi']['credit'] + $sec_totals['oe']['credit'];
$npat_dbt  = $pbt_dbt  + $sec_totals['tax']['debit'];
$npat_cdt  = $pbt_cdt  + $sec_totals['tax']['credit'];

$emit_calc = function(string $label, float $net, float $debit, float $credit,
                      int $ht = 16) use (
    &$emit_row, $sc, $bc, $nc, $detail, $COL_NET, $COL_DEBIT, $COL_CREDIT
): void {
    if ($detail) {
        $emit_row([
            $sc(0, $label, 12),
            $bc(1, 12),
            $nc($COL_DEBIT,  $debit,  13),
            $nc($COL_CREDIT, $credit, 13),
            $nc($COL_NET, $net, 13),
        ], $ht);
    } else {
        $emit_row([
            $sc(0, $label, 12),
            $nc($COL_DEBIT,  $debit,  13),
            $nc($COL_CREDIT, $credit, 13),
            $nc($COL_NET, $net, 13),
        ], $ht);
    }
};

// ── Rows in HTML order ────────────────────────────────────────────────────────
$emit_section('revenue');
$emit_section('cogs');
$emit_calc($calc_labels['gross'], $gross_profit,      $gross_dbt, $gross_cdt);
$emit_section('opex');
$emit_calc($calc_labels['opinc'], $operating_profit,  $op_dbt,    $op_cdt);
$emit_section('oi');
$emit_section('oe');
$emit_calc($calc_labels['pbt'],   $profit_before_tax, $pbt_dbt,   $pbt_cdt);
$emit_section('tax');
$emit_calc($calc_labels['npat'],  $net_profit,        $npat_dbt,  $npat_cdt, 18);

// ── Worksheet XML ─────────────────────────────────────────────────────────────
$col_widths = $detail ? [16, 40, 18, 18, 18] : [46, 18, 18, 18];

$ws  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$ws .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
     . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
$ws .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
     . '<pane ySplit="7" topLeftCell="A8" activePane="bottomLeft" state="frozen"/>'
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
$sst  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$sst .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
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
$xl_styles .= '<fills count="8">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF166C82"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF0F7FF"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFCFE2FF"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0D4F60"/></patternFill></fill>'
            . '<fill><patternFill patternType="none"/></fill>'
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
            . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'           // 0 default
            . '<xf numFmtId="0"   fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'              // 1 title/company (teal bold)
            . '<xf numFmtId="0"   fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'              // 2 muted grey (period etc, left)
            . '<xf numFmtId="0"   fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'// 3 col header (white on teal)
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf>'// 4 number
            . '<xf numFmtId="0"   fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'// 5 alt row text
            . '<xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf>'// 6 alt row num
            . '<xf numFmtId="0"   fontId="3" fillId="6" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'// 7 section header (white on dark)
            . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment indent="2"/></xf>'// 8 indented text
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf>'// 9 indented num
            . '<xf numFmtId="0"   fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'// 10 subtotal text (blue bg)
            . '<xf numFmtId="164" fontId="4" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf>'// 11 subtotal num
            . '<xf numFmtId="0"   fontId="5" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'// 12 calc row text (dark bg)
            . '<xf numFmtId="164" fontId="5" fillId="5" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"><alignment horizontal="right"/></xf>'// 13 calc row num
            . '<xf numFmtId="0"   fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="right"/></xf>'// 14 muted grey RIGHT (print-info)
            . '</cellXfs>';
$xl_styles .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
$xl_styles .= '</styleSheet>';

// ── Workbook & relationship files ─────────────────────────────────────────────
$sheet_name = $detail ? __('pl_xls_detail_sheet_name') : __('pl_xls_sheet_name');
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

$safe_fn = ($detail ? 'Detail_PL' : 'Profit_Loss') . '_' . date('Ymd') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$safe_fn.'"');
header('Content-Length: '.strlen($zip_bytes));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache'); header('Expires: 0');
echo $zip_bytes;
exit;
