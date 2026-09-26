<?php
/**
 * AgroSmart - Agribusiness Intelligence
 * REST API: Dashboard Stats
 * GET /api/dashboard.php
 */

// Start session to access logged-in user's company scope
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CORS headers for React dev server
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

function json_error(string $message, int $code = 500): void {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit();
}

try {
    $db = getDB();
} catch (Exception $e) {
    json_error('Database connection failed', 503);
}

// Scope to logged-in user's company (mirrors index.php behaviour)
$cid = !empty($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : null;

// Company filter fragments
$co_filter  = $cid ? "AND c.company_id = $cid"  : "";
$bu_filter  = $cid ? "AND bu.company_id = $cid" : "";

try {
    // ─── Single CTE query — all KPIs + chart data in ONE round trip ───────────
    $sql = "
        WITH
        -- scope: blocks joined up to company
        scoped_blocks AS (
            SELECT b.block_id, b.area, b.status, b.total_plants,
                   b.planting_year_id, b.division_id
            FROM blocks b
            INNER JOIN divisions d      ON b.division_id        = d.division_id
            INNER JOIN business_units bu ON d.business_unit_id  = bu.business_unit_id
            INNER JOIN companies c       ON bu.company_id       = c.company_id
            WHERE 1=1 $bu_filter
        ),

        -- KPI counters
        kpi_companies AS (
            SELECT COUNT(*) AS v
            FROM companies c WHERE c.status = 'Active' $co_filter
        ),
        kpi_bu AS (
            SELECT COUNT(*) AS v
            FROM business_units bu WHERE bu.status = 'Active' $bu_filter
        ),
        kpi_divisions AS (
            SELECT COUNT(*) AS v
            FROM divisions d
            INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
            WHERE d.status = 'Active' $bu_filter
        ),
        kpi_blocks AS (
            SELECT
                COUNT(*)                                          AS blocks,
                COALESCE(SUM(area),          0)                  AS total_area,
                COALESCE(SUM(CASE WHEN status='TM'  THEN area END), 0) AS tm_area,
                COALESCE(SUM(CASE WHEN status='TBM' THEN area END), 0) AS tbm_area,
                COALESCE(SUM(total_plants),  0)                  AS total_plants
            FROM scoped_blocks
        ),

        -- Charts
        chart_blocks_by_status AS (
            SELECT status,
                   COUNT(*)           AS block_count,
                   COALESCE(SUM(area),0) AS total_area
            FROM scoped_blocks
            GROUP BY status
        ),
        chart_bu_by_type AS (
            SELECT bu.unit_type,
                   COUNT(*)                    AS cnt,
                   COALESCE(SUM(bu.total_area),0) AS total_area
            FROM business_units bu WHERE bu.status = 'Active' $bu_filter
            GROUP BY bu.unit_type
        ),
        chart_planting_years AS (
            SELECT py.year,
                   COUNT(sb.block_id)          AS block_count,
                   COALESCE(SUM(sb.area), 0)   AS total_area
            FROM planting_years py
            LEFT JOIN scoped_blocks sb ON py.planting_year_id = sb.planting_year_id
            GROUP BY py.year
            ORDER BY py.year DESC
            LIMIT 10
        )

        -- Final SELECT — pack everything into named columns
        SELECT
            (SELECT v FROM kpi_companies)  AS companies,
            (SELECT v FROM kpi_bu)         AS business_units,
            (SELECT v FROM kpi_divisions)  AS divisions,
            kb.blocks, kb.total_area, kb.tm_area, kb.tbm_area, kb.total_plants,

            -- blocks_by_status as JSON array
            (SELECT JSON_AGG(t ORDER BY t.status)
             FROM (SELECT status,block_count,total_area FROM chart_blocks_by_status) t
            ) AS blocks_by_status,

            -- bu_by_type as JSON array
            (SELECT JSON_AGG(t ORDER BY t.unit_type)
             FROM (SELECT unit_type, cnt AS count, total_area FROM chart_bu_by_type) t
            ) AS bu_by_type,

            -- planting_years as JSON array
            (SELECT JSON_AGG(t)
             FROM (SELECT year, block_count, total_area FROM chart_planting_years) t
            ) AS planting_years

        FROM kpi_blocks kb
    ";

    $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);

    $kpis = [
        'companies'      => (float)$row['companies'],
        'business_units' => (float)$row['business_units'],
        'divisions'      => (float)$row['divisions'],
        'blocks'         => (float)$row['blocks'],
        'total_area'     => (float)$row['total_area'],
        'tm_area'        => (float)$row['tm_area'],
        'tbm_area'       => (float)$row['tbm_area'],
        'total_plants'   => (float)$row['total_plants'],
    ];

    $blocks_by_status = json_decode($row['blocks_by_status'] ?? '[]', true) ?: [];
    $bu_by_type       = json_decode($row['bu_by_type']       ?? '[]', true) ?: [];
    $planting_years   = json_decode($row['planting_years']   ?? '[]', true) ?: [];

    // ─── FFB deliveries (last 12 months) ─────────────────────────────────────
    try {
        $ffb_sql = "
            SELECT TO_CHAR(fd.delivery_date, 'YYYY-MM') AS month,
                   COALESCE(SUM(fd.net_weight) / 1000, 0) AS weight_ton
            FROM ffb_deliveries fd
            INNER JOIN scoped_blocks sb ON fd.block_id = sb.block_id
            WHERE fd.delivery_date >= NOW() - INTERVAL '12 months'
            GROUP BY 1 ORDER BY 1 ASC
        ";
        // scoped_blocks CTE not available here; inline it
        $ffb_scope = $cid
            ? "INNER JOIN divisions _d   ON fd.block_id = (SELECT b2.block_id FROM blocks b2 WHERE b2.block_id = fd.block_id LIMIT 1)
               INNER JOIN business_units _bu ON 1=1 WHERE fd.delivery_date >= NOW() - INTERVAL '12 months' AND _bu.company_id = $cid"
            : "WHERE fd.delivery_date >= NOW() - INTERVAL '12 months'";

        $ffb_sql = $cid
            ? "SELECT TO_CHAR(fd.delivery_date,'YYYY-MM') AS month,
                      COALESCE(SUM(fd.net_weight)/1000,0) AS weight_ton
               FROM ffb_deliveries fd
               INNER JOIN blocks b      ON fd.block_id          = b.block_id
               INNER JOIN divisions d   ON b.division_id        = d.division_id
               INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
               WHERE fd.delivery_date >= NOW() - INTERVAL '12 months'
                 AND bu.company_id = $cid
               GROUP BY 1 ORDER BY 1"
            : "SELECT TO_CHAR(delivery_date,'YYYY-MM') AS month,
                      COALESCE(SUM(net_weight)/1000,0) AS weight_ton
               FROM ffb_deliveries
               WHERE delivery_date >= NOW() - INTERVAL '12 months'
               GROUP BY 1 ORDER BY 1";
        $ffb_monthly = $db->query($ffb_sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $ffb_monthly = [];
    }

    // ─── Harvest productivity (last 6 months) ─────────────────────────────────
    try {
        $hp_sql = $cid
            ? "SELECT TO_CHAR(hr.harvest_date,'YYYY-MM') AS month,
                      COALESCE(SUM(hr.actual_bunches),0)        AS bunches,
                      COALESCE(SUM(hr.actual_quantity_kg)/1000,0) AS weight_ton
               FROM harvest_realizations hr
               INNER JOIN blocks b      ON hr.block_id          = b.block_id
               INNER JOIN divisions d   ON b.division_id        = d.division_id
               INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
               WHERE hr.harvest_date >= NOW() - INTERVAL '6 months'
                 AND bu.company_id = $cid
               GROUP BY 1 ORDER BY 1"
            : "SELECT TO_CHAR(harvest_date,'YYYY-MM') AS month,
                      COALESCE(SUM(actual_bunches),0)        AS bunches,
                      COALESCE(SUM(actual_quantity_kg)/1000,0) AS weight_ton
               FROM harvest_realizations
               WHERE harvest_date >= NOW() - INTERVAL '6 months'
               GROUP BY 1 ORDER BY 1";
        $harvest_productivity = $db->query($hp_sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $harvest_productivity = [];
    }

    echo json_encode([
        'kpis'                 => $kpis,
        'blocks_by_status'     => $blocks_by_status,
        'bu_by_type'           => $bu_by_type,
        'planting_years'       => $planting_years,
        'ffb_monthly'          => $ffb_monthly,
        'harvest_productivity' => $harvest_productivity,
        'generated_at'         => date('c'),
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    json_error('Query failed: ' . $e->getMessage());
}
