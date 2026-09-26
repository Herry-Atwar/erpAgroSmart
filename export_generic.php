<?php
/**
 * export_generic.php
 * Generic Excel (.xlsx) and PDF export for cost/operational reports.
 * Called by export_report.php — all shared vars already defined.
 *
 * Required vars from router:
 *   $pdo, $export_type, $title, $headers, $rows,
 *   $safe_filename, $fmtDate, $date_from, $date_to, $_company_display, $xv
 */

if ($export_type === 'excel') {
    if (ob_get_level()) { ob_end_clean(); }

    $col_letter = function(int $n): string {
        $s = '';
        for ($i = $n; $i >= 0; $i = intdiv($i, 26) - 1) {
            $s = chr(65 + $i % 26) . $s;
        }
        return $s;
    };

    // Detect purely-numeric columns
    $col_count  = count($headers);
    $is_num_col = array_fill(0, $col_count, true);
    foreach ($rows as $row) {
        foreach ($row as $ci => $cell) {
            if (!is_numeric($cell) || preg_match('/%/', (string)$cell)) {
                $is_num_col[$ci] = false;
            }
        }
    }

    // Grand-total values
    $totals = [];
    foreach ($headers as $ci => $h) {
        $vals = array_column($rows, $ci);
        $totals[$ci] = $is_num_col[$ci] ? array_sum($vals) : null;
    }

    // Shared-string table
    $si_map  = [];
    $si_list = [];
    $si_add  = function(string $s) use (&$si_map, &$si_list): int {
        if (!isset($si_map[$s])) {
            $si_map[$s] = count($si_list);
            $si_list[]  = $s;
        }
        return $si_map[$s];
    };

    $period_str = 'Period: ' . $fmtDate($date_from) . ' to ' . $fmtDate($date_to);
    $si_add($_company_display);
    $si_add($title);
    $si_add($period_str);
    $si_add('GRAND TOTAL');
    foreach ($headers as $h) { $si_add($h); }
    foreach ($rows as $row) {
        foreach ($row as $ci => $cell) {
            if (!$is_num_col[$ci]) { $si_add((string)$cell); }
        }
    }

    // Worksheet XML
    $ws  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $ws .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
         . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $ws .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
         . '<pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/>'
         . '</sheetView></sheetViews>';
    $ws .= '<sheetFormatPr defaultRowHeight="15" customHeight="1"/>';
    $ws .= '<sheetData>';

    $rn = 1;
    $ws .= '<row r="'.$rn.'"><c r="A'.$rn.'" t="s" s="1"><v>'.$si_map[$_company_display].'</v></c></row>'; $rn++;
    $ws .= '<row r="'.$rn.'"><c r="A'.$rn.'" t="s" s="1"><v>'.$si_map[$title].'</v></c></row>'; $rn++;
    $ws .= '<row r="'.$rn.'"><c r="A'.$rn.'" t="s" s="2"><v>'.$si_map[$period_str].'</v></c></row>'; $rn++;
    $ws .= '<row r="'.$rn.'"></row>'; $rn++;

    // Header row
    $ws .= '<row r="'.$rn.'">';
    foreach ($headers as $ci => $h) {
        $ws .= '<c r="'.$col_letter($ci).$rn.'" t="s" s="3"><v>'.$si_map[$h].'</v></c>';
    }
    $ws .= '</row>'; $rn++;

    // Data rows
    foreach ($rows as $di => $row) {
        $alt = ($di % 2 === 1);
        $ws .= '<row r="'.$rn.'">';
        foreach ($row as $ci => $cell) {
            $ref = $col_letter($ci).$rn;
            if ($is_num_col[$ci]) {
                $s = $alt ? 6 : 4;
                $ws .= '<c r="'.$ref.'" s="'.$s.'"><v>'.(float)$cell.'</v></c>';
            } else {
                $s = $alt ? 5 : 0;
                $ws .= '<c r="'.$ref.'" t="s" s="'.$s.'"><v>'.$si_map[(string)$cell].'</v></c>';
            }
        }
        $ws .= '</row>'; $rn++;
    }

    // Grand-total row
    if (!empty($rows)) {
        $ws .= '<row r="'.$rn.'">';
        foreach ($headers as $ci => $h) {
            $ref = $col_letter($ci).$rn;
            if ($ci === 0) {
                $ws .= '<c r="'.$ref.'" t="s" s="7"><v>'.$si_map['GRAND TOTAL'].'</v></c>';
            } elseif ($totals[$ci] !== null) {
                $ws .= '<c r="'.$ref.'" s="8"><v>'.(float)$totals[$ci].'</v></c>';
            } else {
                $ws .= '<c r="'.$ref.'" s="7"></c>';
            }
        }
        $ws .= '</row>'; $rn++;
    }

    $ws .= '</sheetData>';
    $ws .= '<pageSetup orientation="landscape" fitToPage="1" fitToWidth="1" fitToHeight="0"/>';
    $ws .= '</worksheet>';

    // sharedStrings XML
    $n_si = count($si_list);
    $sst  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sst .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
          . ' count="'.$n_si.'" uniqueCount="'.$n_si.'">';
    foreach ($si_list as $s) $sst .= '<si><t xml:space="preserve">'.$xv($s).'</t></si>';
    $sst .= '</sst>';

    // Styles XML
    $xl_styles  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xl_styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xl_styles .= '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>';
    $xl_styles .= '<fonts count="5">'
                . '<font><sz val="11"/><name val="Calibri"/></font>'
                . '<font><b/><sz val="14"/><color rgb="FF166C82"/><name val="Calibri"/></font>'
                . '<font><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/></font>'
                . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
                . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
                . '</fonts>';
    $xl_styles .= '<fills count="5">'
                . '<fill><patternFill patternType="none"/></fill>'
                . '<fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FF166C82"/></patternFill></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FFF0F7FF"/></patternFill></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FFCFE2FF"/></patternFill></fill>'
                . '</fills>';
    $xl_styles .= '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';
    $xl_styles .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
    $xl_styles .= '<cellXfs count="9">'
                . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="0"   fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                . '<xf numFmtId="0"   fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                . '<xf numFmtId="0"   fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
                . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                . '<xf numFmtId="0"   fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/>'
                . '<xf numFmtId="164" fontId="0" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1"/>'
                . '<xf numFmtId="0"   fontId="4" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
                . '<xf numFmtId="164" fontId="4" fillId="4" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
                . '</cellXfs>';
    $xl_styles .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
    $xl_styles .= '</styleSheet>';

    // Workbook & relationships
    $sheet_name = mb_substr(preg_replace('/[\\/\?*\[\]:]/', '', $title), 0, 31);
    $xv_local   = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $wb  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
         . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
         . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="14400" windowHeight="9000"/></bookViews>'
         . '<sheets><sheet name="'.$xv_local($sheet_name).'" sheetId="1" r:id="rId1"/></sheets>'
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
        . '<Override PartName="/xl/workbook.xml"           ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml"  ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml"      ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/xl/styles.xml"             ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
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

    // Build ZIP (self-contained)
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

    $filename = $safe_filename . '_' . date('Ymd') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($zip_bytes));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $zip_bytes;
    exit;

} else {
    // PDF: clean HTML page — browser prints/saves as PDF
    header('Content-Type: text/html; charset=UTF-8');
    $col_count  = count($headers);
    $is_num_col = array_fill(0, $col_count, true);
    foreach ($rows as $row) {
        foreach ($row as $ci => $cell) {
            if (!is_numeric($cell) || preg_match('/%/', (string)$cell)) {
                $is_num_col[$ci] = false;
            }
        }
    }
    $totals = [];
    foreach ($headers as $ci => $h) {
        $vals = array_column($rows, $ci);
        $totals[$ci] = $is_num_col[$ci] ? array_sum($vals) : null;
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10pt;
         color: #1a1a1a; margin: 20px; background:#fff; }
  .rpt-header { text-align:center; border-bottom:2px solid #166c82;
                padding-bottom:10px; margin-bottom:18px; }
  .rpt-company { font-size:13pt; font-weight:700; color:#166c82; }
  .rpt-title   { font-size:15pt; font-weight:700; margin:4px 0 2px; }
  .rpt-period  { font-size:10pt; color:#555; }
  table { width:100%; border-collapse:collapse; font-size:8.5pt; }
  th { background:#166c82 !important; color:#fff !important; font-weight:700;
       padding:4px 6px; border:1px solid #0e5060; text-align:left;
       -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  td { padding:3px 6px; border:1px solid #ccc; vertical-align:middle; }
  tr:nth-child(even) td { background:#f0f7ff; }
  .num { text-align:right; }
  .total-row td { background:#cfe2ff !important; font-weight:700;
                  border-top:2px solid #3065b0;
                  -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .no-print { margin-bottom:16px; }
  @page { size: A4 landscape; margin:12mm 10mm; }
  @media print { .no-print { display:none !important; } body { margin:0; } }
</style>
</head>
<body>
<div class="no-print">
  <button onclick="window.print()" style="padding:6px 18px;background:#166c82;color:#fff;
          border:none;border-radius:4px;font-size:10pt;cursor:pointer;">
    Print / Save as PDF
  </button>
  <button onclick="window.close()" style="margin-left:8px;padding:6px 14px;
          background:#6c757d;color:#fff;border:none;border-radius:4px;
          font-size:10pt;cursor:pointer;">Close</button>
</div>
<div class="rpt-header">
  <div class="rpt-company"><?= htmlspecialchars($_company_display) ?></div>
  <div class="rpt-title"><?= htmlspecialchars($title) ?></div>
  <div class="rpt-period">Period: <strong><?= htmlspecialchars($fmtDate($date_from)) ?></strong>
    &rarr; <strong><?= htmlspecialchars($fmtDate($date_to)) ?></strong></div>
</div>
<table>
  <thead>
    <tr>
<?php foreach ($headers as $ci => $h): ?>
      <th<?= $is_num_col[$ci] ? ' class="num"' : '' ?>><?= htmlspecialchars($h) ?></th>
<?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $row): ?>
    <tr>
<?php foreach ($row as $ci => $cell): ?>
      <td<?= $is_num_col[$ci] ? ' class="num"' : '' ?>>
        <?= $is_num_col[$ci] ? number_format((float)$cell, 0, ',', '.') : htmlspecialchars((string)$cell) ?>
      </td>
<?php endforeach; ?>
    </tr>
<?php endforeach; ?>
<?php if (!empty($rows)): ?>
    <tr class="total-row">
<?php foreach ($headers as $ci => $h): ?>
      <td<?= $is_num_col[$ci] ? ' class="num"' : '' ?>>
        <?php if ($ci === 0): ?>GRAND TOTAL
        <?php elseif ($totals[$ci] !== null): echo number_format($totals[$ci], 0, ',', '.'); ?>
        <?php endif; ?>
      </td>
<?php endforeach; ?>
    </tr>
<?php endif; ?>
  </tbody>
</table>
</body>
</html>
<?php
}
exit;
