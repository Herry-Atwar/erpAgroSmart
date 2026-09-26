<?php
/**
 * export_report.php  — slim router  (erpagrosmart edition — PostgreSQL / Supabase)
 * Reads filter params, builds shared state, then delegates to:
 *   export_pl.php      — Profit & Loss (incl. Detail)
 *   export_bs.php      — Balance Sheet (Detail & Grouped)
 *   export_generic.php — all other reports + PDF fallback
 *
 * Auth: erpagrosmart uses header.php session pattern (no separate auth.php).
 * DB  : PostgreSQL — MySQL functions (DATE_FORMAT, DATE_SUB, CURDATE, SHOW TABLES) replaced.
 */

// ── Auth ──────────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/lang.php';
$pdo = getDB();

// ── 1. Read filter params ─────────────────────────────────────────────────────
$export_type      = $_GET['export']        ?? 'excel';
$report_type      = $_GET['report']        ?? 'profit_loss';
$company_id       = $_GET['company_id']    ?? ($_SESSION['company_id']       ?? '');
$business_unit_id = $_GET['estate_id']     ?? ($_SESSION['business_unit_id'] ?? '');
$division_id      = $_GET['division_id']   ?? ($_SESSION['division_id']      ?? '');
$block_id         = $_GET['block_id']      ?? '';
$activity_id      = $_GET['activity_id']   ?? '';
$date_from        = $_GET['date_from']     ?? date('Y-01-01');
$date_to          = $_GET['date_to']       ?? date('Y-m-t');
$cost_category    = $_GET['cost_category'] ?? '';
$status_filter    = $_GET['status']        ?? '';
$estate_id        = $business_unit_id;

// Print-info params (passed from exportToExcel() in financial_reports.php)
$printed_by   = trim($_GET['printed_by']   ?? '');
$print_dt     = trim($_GET['print_dt']     ?? '');
$print_by_lbl = trim($_GET['print_by_lbl'] ?? __('pl_xls_printed_by'));
$datetime_lbl = trim($_GET['datetime_lbl'] ?? __('pl_xls_datetime'));
if ($print_by_lbl === '') $print_by_lbl = __('pl_xls_printed_by');
if ($datetime_lbl === '') $datetime_lbl = __('pl_xls_datetime');

// ── 2. Company display name ───────────────────────────────────────────────────
$_company_display = 'erpAgroSmart — AI Agribusiness Solution';
if (!empty($_SESSION['company_id'])) {
    try {
        $__cs = $pdo->prepare("SELECT company_name FROM companies WHERE company_id = ? LIMIT 1");
        $__cs->execute([$_SESSION['company_id']]);
        $__cn = $__cs->fetchColumn();
        if ($__cn) { $_company_display = $__cn; }
    } catch (Exception $_e) {}
}

// ── 3. Generic WHERE clause (used by non-BS reports) ─────────────────────────
$where_conditions = ["je.status = 'posted'"];
$params = [];
if ($company_id)    { $where_conditions[] = "je.company_id = :company_id";        $params[':company_id']    = $company_id; }
if ($estate_id)     { $where_conditions[] = "je.business_unit_id = :estate_id";   $params[':estate_id']     = $estate_id; }
if ($division_id)   { $where_conditions[] = "je.division_id = :division_id";      $params[':division_id']   = $division_id; }
if ($block_id)      { $where_conditions[] = "je.block_id = :block_id";            $params[':block_id']      = $block_id; }
if ($activity_id)   { $where_conditions[] = "jel.activity_id = :activity_id";     $params[':activity_id']   = $activity_id; }
if ($cost_category) { $where_conditions[] = "jel.cost_category = :cost_category"; $params[':cost_category'] = $cost_category; }
if ($status_filter) { $where_conditions[] = "b.status = :status";                 $params[':status']        = $status_filter; }
if ($date_from)     { $where_conditions[] = "je.entry_date >= :date_from";         $params[':date_from']     = $date_from; }
if ($date_to)       { $where_conditions[] = "je.entry_date <= :date_to";           $params[':date_to']       = $date_to; }
$where_clause = implode(' AND ', $where_conditions);

// P&L-safe WHERE clause: strips "b.status" which requires a blocks JOIN not present in P&L queries
$pl_where_conditions = array_filter($where_conditions, fn($c) => strpos($c, 'b.status') === false);
$pl_where_clause = implode(' AND ', $pl_where_conditions);
$pl_params = $params;
unset($pl_params[':status']);

// ── 4. Shared helpers ─────────────────────────────────────────────────────────
function fmt_rp(float $v): string {
    return ($v < 0 ? '-' : '') . number_format(abs($v), 0, ',', '.');
}
function fmt_pct(float $v, int $dec = 1): string {
    return number_format($v, $dec) . '%';
}
function xe(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$xv      = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$fmtDate = fn(string $s): string => preg_replace('/(\d{4})-(\d{2})-(\d{2})/', '$3/$2/$1', $s);

$col_letter = function(int $n): string {
    $s = '';
    for ($i = $n; $i >= 0; $i = intdiv($i, 26) - 1) {
        $s = chr(65 + $i % 26) . $s;
    }
    return $s;
};

// ── 5. Route to specialist or generic handler ─────────────────────────────────
if ($export_type === 'excel' && in_array($report_type, ['profit_loss', 'profit_loss_detail'])) {
    if (file_exists(__DIR__ . '/export_pl.php')) { require __DIR__ . '/export_pl.php'; exit; }
    exit;
}

if ($export_type === 'excel' && in_array($report_type, ['balance_sheet', 'balance_sheet_group'])) {
    if (file_exists(__DIR__ . '/export_bs.php')) { require __DIR__ . '/export_bs.php'; exit; }
    exit;
}

// ── Generic reports: fetch data then hand off ─────────────────────────────────
$report = fetch_report_data($report_type, $pdo, $where_clause, $params,
                             $date_from, $date_to,
                             $estate_id, $company_id, $division_id, $block_id,
                             $cost_category);
$title         = $report['title'];
$headers       = $report['headers'];
$rows          = $report['rows'];
$safe_filename = preg_replace('/[^a-z0-9_\-]/i', '_', $title);

if (file_exists(__DIR__ . '/export_generic.php')) { require __DIR__ . '/export_generic.php'; exit; }
exit;

// ── Data-fetch function (generic/cost reports only) ───────────────────────────
function fetch_report_data(string $report_type, PDO $pdo, string $where_clause,
                            array $params, string $date_from, string $date_to,
                            string $estate_id, string $company_id, string $division_id,
                            string $block_id, string $cost_category = ''): array
{
    switch ($report_type) {

        case 'cost_by_block': {
            $sql = "
                SELECT c.company_name, bu.unit_name, d.division_name,
                       b.block_code, b.block_name, b.status,
                       b.area, py.year,
                       COUNT(DISTINCT je.id) as entry_count,
                       SUM(CASE WHEN jel.cost_category='labor'            THEN jel.debit_amount ELSE 0 END) as labor_cost,
                       SUM(CASE WHEN jel.cost_category='material'         THEN jel.debit_amount ELSE 0 END) as material_cost,
                       SUM(CASE WHEN jel.cost_category='vehicle_equipment'THEN jel.debit_amount ELSE 0 END) as equipment_cost,
                       SUM(CASE WHEN jel.cost_category='overhead'         THEN jel.debit_amount ELSE 0 END) as overhead_cost,
                       SUM(CASE WHEN jel.cost_category='other'            THEN jel.debit_amount ELSE 0 END) as other_cost,
                       SUM(jel.debit_amount) as total_cost,
                       SUM(jel.debit_amount)/NULLIF(b.area,0) as cost_per_ha
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                LEFT JOIN blocks b ON jel.block_id=b.block_id
                LEFT JOIN companies c ON b.company_id=c.company_id
                LEFT JOIN business_units bu ON b.business_unit_id=bu.business_unit_id
                LEFT JOIN divisions d ON b.division_id=d.division_id
                LEFT JOIN planting_years py ON b.planting_year_id=py.planting_year_id
                WHERE $where_clause AND jel.debit_amount>0 AND b.block_id IS NOT NULL
                GROUP BY c.company_name,bu.unit_name,d.division_name,
                         b.block_id,b.block_code,b.block_name,b.status,b.area,py.year
                ORDER BY total_cost DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
            $headers = ['Company','Estate','Division','Block Code','Block Name','Status',
                        'Area (Ha)','Year','Labor','Material','Equipment','Overhead','Other',
                        'Total Cost','Cost/Ha','Entries'];
            $data = [];
            foreach ($rows as $r) {
                $data[] = [$r['company_name'],$r['unit_name'],$r['division_name'],
                           $r['block_code'],$r['block_name'],$r['status'],
                           $r['area'],$r['year'],
                           $r['labor_cost'],$r['material_cost'],$r['equipment_cost'],
                           $r['overhead_cost'],$r['other_cost'],
                           $r['total_cost'],$r['cost_per_ha'],$r['entry_count']];
            }
            return ['title'=>'Cost by Block','headers'=>$headers,'rows'=>$data];
        }

        case 'cost_by_activity': {
            $sql = "
                SELECT a.activity_code, a.activity_name,
                       COUNT(DISTINCT je.id) as entry_count,
                       COUNT(DISTINCT jel.block_id) as block_count,
                       SUM(CASE WHEN jel.cost_category='labor'            THEN jel.debit_amount ELSE 0 END) as labor_cost,
                       SUM(CASE WHEN jel.cost_category='material'         THEN jel.debit_amount ELSE 0 END) as material_cost,
                       SUM(CASE WHEN jel.cost_category='vehicle_equipment'THEN jel.debit_amount ELSE 0 END) as equipment_cost,
                       SUM(CASE WHEN jel.cost_category='overhead'         THEN jel.debit_amount ELSE 0 END) as overhead_cost,
                       SUM(CASE WHEN jel.cost_category='other'            THEN jel.debit_amount ELSE 0 END) as other_cost,
                       SUM(jel.debit_amount) as total_cost,
                       SUM(CASE WHEN b.status='LC'  THEN jel.debit_amount ELSE 0 END) as lc_cost,
                       SUM(CASE WHEN b.status='TBM' THEN jel.debit_amount ELSE 0 END) as tbm_cost,
                       SUM(CASE WHEN b.status='TM'  THEN jel.debit_amount ELSE 0 END) as tm_cost
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                LEFT JOIN activities a ON jel.activity_id=a.id
                LEFT JOIN blocks b ON jel.block_id=b.block_id
                WHERE $where_clause AND jel.debit_amount>0 AND a.id IS NOT NULL
                GROUP BY a.id,a.activity_code,a.activity_name
                ORDER BY total_cost DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
            $headers = ['Activity Code','Activity Name','Labor','Material','Equipment',
                        'Overhead','Other','Total Cost','LC Cost','TBM Cost','TM Cost',
                        'Entries','Blocks'];
            $data = [];
            foreach ($rows as $r) {
                $data[] = [$r['activity_code'],$r['activity_name'],
                           $r['labor_cost'],$r['material_cost'],$r['equipment_cost'],
                           $r['overhead_cost'],$r['other_cost'],$r['total_cost'],
                           $r['lc_cost'],$r['tbm_cost'],$r['tm_cost'],
                           $r['entry_count'],$r['block_count']];
            }
            return ['title'=>'Cost by Activity','headers'=>$headers,'rows'=>$data];
        }

        case 'cost_by_category': {
            $sql = "
                SELECT jel.cost_category,
                       COUNT(DISTINCT je.id) as entry_count,
                       COUNT(DISTINCT jel.block_id) as block_count,
                       COUNT(DISTINCT je.activity_id) as activity_count,
                       SUM(CASE WHEN b.status='LC'  THEN jel.debit_amount ELSE 0 END) as lc_cost,
                       SUM(CASE WHEN b.status='TBM' THEN jel.debit_amount ELSE 0 END) as tbm_cost,
                       SUM(CASE WHEN b.status='TM'  THEN jel.debit_amount ELSE 0 END) as tm_cost,
                       SUM(jel.debit_amount) as total_cost
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                LEFT JOIN blocks b ON jel.block_id=b.block_id
                WHERE $where_clause AND jel.debit_amount>0 AND jel.cost_category IS NOT NULL
                GROUP BY jel.cost_category ORDER BY total_cost DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
            $grand = array_sum(array_column($rows,'total_cost')) ?: 1;
            $headers = ['Category','LC Cost','TBM Cost','TM Cost','Total Cost','% of Total',
                        'Entries','Blocks','Activities'];
            $data = [];
            foreach ($rows as $r) {
                $data[] = [ucfirst(str_replace('_',' ',$r['cost_category'])),
                           $r['lc_cost'],$r['tbm_cost'],$r['tm_cost'],$r['total_cost'],
                           round(($r['total_cost']/$grand)*100,1).'%',
                           $r['entry_count'],$r['block_count'],$r['activity_count']];
            }
            return ['title'=>'Cost by Category','headers'=>$headers,'rows'=>$data];
        }

        case 'block_profitability': {
            $sql_c = "
                SELECT b.block_id,b.block_code,b.block_name,b.area,
                       bu.unit_name,d.division_name,
                       SUM(jel.debit_amount) as total_cost,
                       SUM(jel.debit_amount)/NULLIF(b.area,0) as cost_per_ha
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                LEFT JOIN blocks b ON jel.block_id=b.block_id
                LEFT JOIN business_units bu ON b.business_unit_id=bu.business_unit_id
                LEFT JOIN divisions d ON b.division_id=d.division_id
                WHERE $where_clause AND jel.debit_amount>0
                  AND b.block_id IS NOT NULL AND b.status='TM'
                GROUP BY b.block_id,b.block_code,b.block_name,b.area,bu.unit_name,d.division_name";
            $stmt = $pdo->prepare($sql_c); $stmt->execute($params);
            $costs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ffb = 2500;
            $sql_r = "SELECT hr.block_id, SUM(hr.actual_quantity_kg)*$ffb as total_revenue,
                             SUM(hr.actual_quantity_kg) as qty
                      FROM harvest_realizations hr
                      WHERE hr.harvest_date BETWEEN :df AND :dt
                      ".($block_id?"AND hr.block_id=:block_id":"")."
                      GROUP BY hr.block_id";
            $rp = [':df'=>$date_from,':dt'=>$date_to];
            if ($block_id) $rp[':block_id']=$block_id;
            $stmt2=$pdo->prepare($sql_r); $stmt2->execute($rp);
            $rev=[]; foreach($stmt2->fetchAll() as $rv) $rev[$rv['block_id']]=$rv;
            $headers = ['Estate','Division','Block Code','Block Name','Area (Ha)',
                        'FFB (Kg)','Revenue','Cost','Profit','Margin %',
                        'Revenue/Ha','Cost/Ha','Profit/Ha'];
            $data = [];
            foreach ($costs as $c) {
                $r = $rev[$c['block_id']]['total_revenue'] ?? 0;
                $q = $rev[$c['block_id']]['qty'] ?? 0;
                $pr = $r - $c['total_cost'];
                $mg = $r > 0 ? round(($pr/$r)*100,1) : 0;
                $rha = $c['area'] > 0 ? $r/$c['area'] : 0;
                $pha = $c['area'] > 0 ? $pr/$c['area'] : 0;
                $data[] = [$c['unit_name']??'N/A',$c['division_name'],
                           $c['block_code'],$c['block_name'],$c['area'],
                           $q,$r,$c['total_cost'],$pr,$mg.'%',
                           round($rha),$c['cost_per_ha'],round($pha)];
            }
            usort($data,fn($a,$b)=>$b[8]<=>$a[8]);
            return ['title'=>'Block Profitability (TM Blocks)','headers'=>$headers,'rows'=>$data];
        }

        case 'monthly_trends': {
            // PostgreSQL version — no DATE_FORMAT / DATE_SUB / CURDATE
            $tc=[]; $tc_cond=["je.status='posted'","jel.debit_amount>0",
                              "je.entry_date >= CURRENT_DATE - INTERVAL '12 months'"];
            if ($company_id)    { $tc_cond[]="je.company_id=:company_id";        $tc[':company_id']   =$company_id; }
            if ($estate_id)     { $tc_cond[]="je.business_unit_id=:estate_id";   $tc[':estate_id']    =$estate_id; }
            if ($division_id)   { $tc_cond[]="je.division_id=:division_id";      $tc[':division_id']  =$division_id; }
            if ($block_id)      { $tc_cond[]="je.block_id=:block_id";            $tc[':block_id']     =$block_id; }
            if (!empty($cost_category)) { $tc_cond[]="jel.cost_category=:cost_category"; $tc[':cost_category']=$cost_category; }
            $tw=implode(' AND ',$tc_cond);
            $sql="SELECT TO_CHAR(je.entry_date,'YYYY-MM') as month,
                         TO_CHAR(je.entry_date,'Mon YYYY') as month_label,
                         COUNT(DISTINCT je.id) as entry_count,
                         SUM(CASE WHEN jel.cost_category='labor'            THEN jel.debit_amount ELSE 0 END) as labor_cost,
                         SUM(CASE WHEN jel.cost_category='material'         THEN jel.debit_amount ELSE 0 END) as material_cost,
                         SUM(CASE WHEN jel.cost_category='vehicle_equipment'THEN jel.debit_amount ELSE 0 END) as equipment_cost,
                         SUM(CASE WHEN jel.cost_category='overhead'         THEN jel.debit_amount ELSE 0 END) as overhead_cost,
                         SUM(CASE WHEN jel.cost_category='other'            THEN jel.debit_amount ELSE 0 END) as other_cost,
                         SUM(jel.debit_amount) as total_cost
                  FROM journal_entries je
                  JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                  LEFT JOIN blocks b ON jel.block_id=b.block_id
                  WHERE $tw GROUP BY month,month_label ORDER BY month";
            $stmt=$pdo->prepare($sql); $stmt->execute($tc); $rows=$stmt->fetchAll();
            $headers=['Month','Labor','Material','Equipment','Overhead','Other',
                      'Total Cost','MoM Growth %','Entries'];
            $data=[]; $prev=0;
            foreach($rows as $r){
                $g = $prev>0 ? round((($r['total_cost']-$prev)/$prev)*100,1) : 0;
                $data[]=[
                    $r['month_label'],$r['labor_cost'],$r['material_cost'],
                    $r['equipment_cost'],$r['overhead_cost'],$r['other_cost'],
                    $r['total_cost'],($prev>0?($g>=0?'+'.$g.'%':$g.'%'):'-'),
                    $r['entry_count']];
                $prev=$r['total_cost'];
            }
            return ['title'=>'Monthly Cost Trends','headers'=>$headers,'rows'=>$data];
        }

        case 'cost_variance': {
            $sql="SELECT b.block_id,b.block_code,b.block_name,b.area,b.status as block_status,
                         a.id as act_id,a.activity_code,a.activity_name,
                         SUM(jel.debit_amount) as actual_cost,
                         COUNT(DISTINCT je.id) as entry_count
                  FROM journal_entries je
                  JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                  LEFT JOIN blocks b ON jel.block_id=b.block_id
                  LEFT JOIN activities a ON je.activity_id=a.id
                  WHERE $where_clause AND jel.debit_amount>0
                    AND b.block_id IS NOT NULL AND a.id IS NOT NULL
                  GROUP BY b.block_id,b.block_code,b.block_name,b.area,b.status,
                           a.id,a.activity_code,a.activity_name";
            $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
            $norms=[];
            try {
                // PostgreSQL: use information_schema instead of SHOW TABLES
                $tbl_check = $pdo->query(
                    "SELECT 1 FROM information_schema.tables
                     WHERE table_schema = current_schema()
                       AND table_name = 'activity_norms'"
                )->fetch();
                if ($tbl_check) {
                    $ns=$pdo->query("SELECT activity_id,terrain_type,man_days_per_unit*150000 as cpu,is_default
                                     FROM activity_norms WHERE is_active=TRUE")->fetchAll();
                    foreach($ns as $n) $norms[$n['activity_id'].'_'.$n['terrain_type']]=$n;
                }
            } catch(Exception $e) {}
            $headers=['Block Code','Block Name','Block Status','Area (Ha)',
                      'Activity Code','Activity Name','Actual Cost','Standard Cost',
                      'Variance','Variance %','Status','Entries'];
            $data=[];
            foreach($rows as $r){
                $tt=['Flat'=>'flat','Undulating'=>'sloping','Hilly'=>'sloping','Steep'=>'steep'];
                $bt=$pdo->prepare("SELECT topography FROM blocks WHERE block_id=? LIMIT 1");
                $bt->execute([$r['block_id']]);
                $topo=$bt->fetchColumn()?:'Flat';
                $tn=$tt[$topo]??'flat';
                $norm=$norms[$r['act_id'].'_'.$tn]??null;
                if(!$norm) foreach($norms as $k=>$n) if(strpos($k,$r['act_id'].'_')===0&&$n['is_default']==1){$norm=$n;break;}
                $sc=$norm&&$r['area']>0?$r['area']*$norm['cpu']:0;
                $var=$r['actual_cost']-$sc;
                $vp=$sc>0?round(($var/$sc)*100,1):0;
                $st=!$norm?'No Norm':($var>0?'Unfavorable':'Favorable');
                $data[]=[$r['block_code'],$r['block_name'],$r['block_status'],$r['area'],
                          $r['activity_code'],$r['activity_name'],$r['actual_cost'],$sc,
                          $var,($sc>0?($vp>=0?'+'.$vp.'%':$vp.'%'):'-'),
                          $st,$r['entry_count']];
            }
            usort($data,fn($a,$b)=>abs($b[8])<=>abs($a[8]));
            return ['title'=>'Cost Variance (Actual vs Standard)','headers'=>$headers,'rows'=>$data];
        }

        case 'profit_loss':
        case 'profit_loss_detail': {
            // Fallback plain data (structured export handled above via export_pl.php)
            $detail = ($report_type === 'profit_loss_detail');
            $sql="SELECT gla.account_type,
                         ".($detail?"gla.account_code,":"")."
                         gla.account_name,
                         SUM(jel.debit_amount) as total_debit,
                         SUM(jel.credit_amount) as total_credit
                  FROM journal_entries je
                  JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                  JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                  WHERE $where_clause
                    AND gla.account_type IN('revenue','cogs','operating_expense','expense',
                                            'depreciation','other_income','other_expenses','tax')
                  GROUP BY gla.id,gla.account_type".($detail?",gla.account_code":"").",gla.account_name
                  HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0
                  ORDER BY gla.account_type,gla.account_name";
            $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
            $section_map=['revenue'=>'Revenue','cogs'=>'COGS',
                          'operating_expense'=>'Operating Expenses','expense'=>'Operating Expenses',
                          'other_income'=>'Other Income','other_expenses'=>'Other Expenses','tax'=>'Tax Expense'];
            $title = $detail ? 'Detail Profit & Loss' : 'Profit & Loss';
            $headers = $detail
                ? ['Section','Account Code','Account','Debit','Credit','Net Amount']
                : ['Section','Account','Debit','Credit','Net Amount'];
            $data=[];
            foreach($rows as $r){
                $d=(float)$r['total_debit']; $c=(float)$r['total_credit'];
                $t=in_array($r['account_type'],['revenue','other_income'])?$c-$d:$d-$c;
                $sec=$section_map[$r['account_type']]??$r['account_type'];
                if($detail) $data[]=[$sec,$r['account_code'],$r['account_name'],$d,$c,$t];
                else        $data[]=[$sec,$r['account_name'],$d,$c,$t];
            }
            return ['title'=>$title,'headers'=>$headers,'rows'=>$data];
        }

        case 'balance_sheet':
        case 'balance_sheet_group': {
            // Fallback plain data (structured export handled above via export_bs.php)
            $detail = ($report_type === 'balance_sheet');
            $bs_cond=["je.status='posted'","je.entry_date<=:bs_date_to"];
            $bs_p=[':bs_date_to'=>$date_to];
            if($company_id)  {$bs_cond[]="je.company_id=:company_id";        $bs_p[':company_id'] =$company_id;}
            if($estate_id)   {$bs_cond[]="je.business_unit_id=:estate_id";   $bs_p[':estate_id']  =$estate_id;}
            if($division_id) {$bs_cond[]="je.division_id=:division_id";      $bs_p[':division_id']=$division_id;}
            $bw=implode(' AND ',$bs_cond);
            if($detail){
                $sql="SELECT gla.account_code,gla.account_name,gla.account_type,
                             SUM(jel.debit_amount) as td,SUM(jel.credit_amount) as tc
                      FROM journal_entries je
                      JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                      JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                      WHERE $bw AND gla.account_type IN('asset','liability','equity')
                      GROUP BY gla.id,gla.account_code,gla.account_name,gla.account_type
                      HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0
                      ORDER BY gla.account_type,gla.account_code";
                $stmt=$pdo->prepare($sql); $stmt->execute($bs_p); $rows=$stmt->fetchAll();
                $headers=['Section','Account Code','Account','Debit','Credit','Balance'];
                $data=[];
                foreach($rows as $r){
                    $d=(float)$r['td']; $c=(float)$r['tc'];
                    $b=$r['account_type']==='asset'?$d-$c:$c-$d;
                    $data[]=[ucfirst($r['account_type']),$r['account_code'],$r['account_name'],$d,$c,$b];
                }
                return ['title'=>'Detail Balance Sheet — As at '.$date_to,'headers'=>$headers,'rows'=>$data];
            } else {
                $sql="SELECT fag.group_name,fag.report_section,gla.account_type,
                             SUM(jel.debit_amount) as td,SUM(jel.credit_amount) as tc
                      FROM journal_entries je
                      JOIN journal_entry_lines jel ON jel.journal_entry_id=je.id
                      JOIN general_ledger_accounts gla ON gla.id=jel.gl_account_id
                      JOIN financial_account_groups fag ON fag.id=gla.financial_group_id
                      WHERE $bw AND gla.account_type IN('asset','liability','equity')
                        AND fag.report_type='balance_sheet' AND fag.is_active=TRUE
                      GROUP BY fag.id,fag.group_name,fag.report_section,gla.account_type
                      HAVING (SUM(jel.debit_amount)+SUM(jel.credit_amount))>0
                      ORDER BY gla.account_type,fag.display_order";
                $stmt=$pdo->prepare($sql); $stmt->execute($bs_p); $rows=$stmt->fetchAll();
                $headers=['Section','Group','Report Section','Balance'];
                $data=[];
                foreach($rows as $r){
                    $d=(float)$r['td']; $c=(float)$r['tc'];
                    $b=$r['account_type']==='asset'?$d-$c:$c-$d;
                    $data[]=[ucfirst($r['account_type']),$r['group_name'],$r['report_section'],$b];
                }
                return ['title'=>'Balance Sheet — As at '.$date_to,'headers'=>$headers,'rows'=>$data];
            }
        }

        default:
            return ['title'=>'Report','headers'=>[],'rows'=>[]];
    }
}
