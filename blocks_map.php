<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'config/database.php';
require_once 'config/standards.php';
require_once 'includes/functions.php';

$db = getDB();

// ── Session scope ──────────────────────────────────────────────────────────────
$session_company_id = !empty($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : null;

// ── Blocks with GeoJSON ────────────────────────────────────────────────────────
$sql = "SELECT b.*,
        b.area,
        c.company_id,
        py.year as planting_year,
        d.division_code, d.division_name,
        bu.unit_code, bu.unit_name,
        c.company_code, c.company_name
        FROM blocks b
        LEFT JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        LEFT JOIN divisions d ON b.division_id = d.division_id
        LEFT JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        LEFT JOIN companies c ON bu.company_id = c.company_id
        WHERE b.geojson IS NOT NULL AND b.geojson != ''";
if ($session_company_id) {
    $sql .= " AND c.company_id = " . $session_company_id;
}
$sql .= " ORDER BY c.company_name, bu.unit_name, d.division_name, b.block_code";
$blocks = $db->query($sql)->fetchAll();

// ── Variety lookup ─────────────────────────────────────────────────────────────
$variety_map = [];
try {
    $vrows = $db->query("
        SELECT DISTINCT ON (bpv.block_id) bpv.block_id, pv.variety_name, pv.variety_code
        FROM block_plant_varieties bpv
        JOIN plant_varieties pv ON bpv.variety_id = pv.variety_id
        ORDER BY bpv.block_id, bpv.plant_count DESC
    ")->fetchAll();
    foreach ($vrows as $v) { $variety_map[$v['block_id']] = $v; }
} catch (PDOException $e) {}
foreach ($blocks as &$block) {
    $bid = $block['block_id'];
    $block['variety_name'] = $variety_map[$bid]['variety_name'] ?? null;
    $block['variety_code'] = $variety_map[$bid]['variety_code'] ?? null;
}
unset($block);

// ── Harvest summary per block (last 12 months) ────────────────────────────────
$harvest_map = [];
try {
    $h_rows = $db->query("
        SELECT block_id,
               SUM(actual_quantity_kg) AS total_ffb_kg,
               COUNT(*)           AS harvest_trips,
               AVG(actual_quantity_kg) AS avg_ffb_kg
        FROM harvest_realizations
        WHERE harvest_date >= NOW() - INTERVAL '12 months'
        GROUP BY block_id
    ")->fetchAll();
    foreach ($h_rows as $h) { $harvest_map[$h['block_id']] = $h; }
} catch (PDOException $e) {}

foreach ($blocks as &$block) {
    $bid = $block['block_id'];
    $block['harvest_total_kg'] = $harvest_map[$bid]['total_ffb_kg'] ?? null;
    $block['harvest_trips']    = $harvest_map[$bid]['harvest_trips'] ?? null;
    $block['harvest_avg_kg']   = $harvest_map[$bid]['avg_ffb_kg']    ?? null;
}
unset($block);

// ── POI types ─────────────────────────────────────────────────────────────────
$poi_types = [];
try {
    $poi_types = $db->query("SELECT * FROM poi_types WHERE is_active = TRUE ORDER BY sort_order")->fetchAll();
} catch (PDOException $e) {}

// ── POI records ───────────────────────────────────────────────────────────────
$pois = [];
try {
    $poi_sql = "SELECT p.*, t.type_code, t.type_name, t.category, t.map_color, t.icon_name
                FROM map_poi p
                JOIN poi_types t ON p.type_id = t.type_id
                WHERE p.is_active = TRUE";
    if ($session_company_id) {
        $poi_sql .= " AND (p.company_id = {$session_company_id} OR p.company_id IS NULL)";
    }
    $poi_sql .= " ORDER BY t.sort_order, p.poi_name";
    $pois = $db->query($poi_sql)->fetchAll();
} catch (PDOException $e) {}

// ── Company list for filter ────────────────────────────────────────────────────
if ($session_company_id) {
    $cs = $db->prepare("SELECT company_id, company_name FROM companies WHERE company_id = ?");
    $cs->execute([$session_company_id]);
} else {
    $cs = $db->query("SELECT DISTINCT company_id, company_name FROM companies ORDER BY company_name");
}
$companies = $cs->fetchAll();

// ── Summary stats ─────────────────────────────────────────────────────────────
$total_area     = array_sum(array_column($blocks, 'area_ha')) ?: array_sum(array_column($blocks, 'area'));
$total_plants   = array_sum(array_column($blocks, 'total_plants'));

$page_title = "SmartMap — Block & POI Information System";
require_once 'includes/header.php';
?>

<style>
/* ── Layout ──────────────────────────────────────────────────────────────── */
#smartmap-wrap {
    display: flex;
    gap: 0;
    height: calc(100vh - 190px);
    min-height: 520px;
    position: relative;
}
#map {
    flex: 1;
    border-radius: 0 6px 6px 0;
    box-shadow: 0 2px 8px rgba(0,0,0,.12);
}
/* ── Left panel ─────────────────────────────────────────────────────────── */
#info-panel {
    width: 320px;
    min-width: 280px;
    max-width: 340px;
    background: #fff;
    border-radius: 6px 0 0 6px;
    border-right: 2px solid #e5e7eb;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    box-shadow: 2px 0 6px rgba(0,0,0,.06);
    transition: width .25s;
}
#info-panel.collapsed { width: 0; min-width: 0; overflow: hidden; }
#panel-toggle {
    position: absolute;
    left: 314px;
    top: 12px;
    z-index: 500;
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 0 4px 4px 0;
    padding: 6px 5px;
    cursor: pointer;
    font-size: 0.8rem;
    box-shadow: 2px 0 4px rgba(0,0,0,.1);
    transition: left .25s;
}
#panel-toggle.collapsed { left: 0; }

/* ── Panel tabs ─────────────────────────────────────────────────────────── */
.panel-tab-bar {
    display: flex;
    border-bottom: 2px solid #e5e7eb;
    background: #f7f8fa;
    flex-shrink: 0;
}
.panel-tab {
    flex: 1;
    padding: 7px 4px;
    text-align: center;
    font-size: .72rem;
    font-weight: 600;
    cursor: pointer;
    color: #57606a;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    user-select: none;
}
.panel-tab.active { color: #1f2328; border-bottom-color: #3b82d4; background: #fff; }
.panel-content { flex: 1; overflow-y: auto; padding: 10px; display: none; }
.panel-content.active { display: block; }

/* ── Block info card ─────────────────────────────────────────────────────── */
.block-info-empty {
    padding: 24px 12px;
    text-align: center;
    color: #9ca3af;
    font-size: .82rem;
}
.block-info-empty i { font-size: 2rem; display: block; margin-bottom: 8px; }
.bi-row { display: flex; justify-content: space-between; padding: 3px 0;
          border-bottom: 1px solid #f3f4f6; font-size: .78rem; }
.bi-row .bi-label { color: #57606a; }
.bi-row .bi-val   { font-weight: 600; text-align: right; max-width: 60%; }
.kpi-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin: 8px 0; }
.kpi-box  { background: #f7f8fa; border-radius: 5px; padding: 8px 6px; text-align: center; }
.kpi-box .kpi-val  { font-size: 1.1rem; font-weight: 700; color: #1f2328; }
.kpi-box .kpi-lbl  { font-size: .65rem; color: #57606a; margin-top: 1px; }

/* ── POI list ───────────────────────────────────────────────────────────── */
.poi-list-item {
    display: flex; align-items: center; gap: 7px;
    padding: 5px 4px; border-radius: 4px; cursor: pointer;
    font-size: .78rem; border-bottom: 1px solid #f3f4f6;
}
.poi-list-item:hover { background: #f0f9ff; }
.poi-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; border: 1.5px solid rgba(0,0,0,.2); }

/* ── Choropleth mode badge ───────────────────────────────────────────────── */
.choro-select { max-width: 110px; }

/* ── Legend ─────────────────────────────────────────────────────────────── */
.leaflet-legend {
    background: white;
    padding: 6px 10px;
    border-radius: 5px;
    box-shadow: 0 2px 4px rgba(0,0,0,.2);
    font-size: .75rem;
    max-height: 240px;
    overflow-y: auto;
    min-width: 150px;
}
.leaflet-legend h6 { font-size: .75rem; margin-bottom: 3px; font-weight: 700; }
.leg-row { display: flex; align-items: center; gap: 5px; margin: 2px 0; }
.leg-swatch { width: 13px; height: 13px; border-radius: 2px; border: 1px solid rgba(0,0,0,.2); flex-shrink: 0; }

/* ── Map controls bar ───────────────────────────────────────────────────── */
.map-ctrls { background: #f8f9fa; border-radius: 6px; padding: 6px 10px;
             display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
             margin-bottom: 6px; font-size: .78rem; }
.map-stat  { display: flex; align-items: center; gap: 5px; color: #495057; }
.map-stat strong { color: #1f2328; font-size: .9rem; }
</style>

<!-- Leaflet CSS + Draw CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css"/>

<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-2">
    <h5 class="mb-0">
        <i class="bi bi-map-fill text-success"></i> SmartMap
        <small class="text-muted fw-normal">Block &amp; Infrastructure Information System</small>
    </h5>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary" onclick="openPoiModal()">
            <i class="bi bi-plus-circle"></i> Add POI
        </button>
        <a href="blocks.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-list"></i> Block List
        </a>
    </div>
</div>

<!-- ── Stats bar ────────────────────────────────────────────────────────────── -->
<div class="map-ctrls mb-2">
    <div class="map-stat"><i class="bi bi-grid-fill text-success"></i> <strong><?php echo count($blocks); ?></strong> blocks</div>
    <div class="map-stat"><i class="bi bi-rulers text-primary"></i> <strong><?php echo number_format($total_area, 0); ?></strong> Ha</div>
    <div class="map-stat"><i class="bi bi-tree text-success"></i> <strong id="cnt-plantation">0</strong> Plantation</div>
    <div class="map-stat"><i class="bi bi-tree-fill text-dark"></i> <strong id="cnt-forestry">0</strong> Forestry</div>
    <div class="map-stat"><i class="bi bi-geo-alt-fill text-danger"></i> <strong><?php echo count($pois); ?></strong> POIs</div>
    <div class="vr mx-1"></div>
    <!-- Colour mode -->
    <span class="text-muted me-1"><i class="bi bi-layers"></i> Choropleth:</span>
    <div class="btn-group btn-group-sm">
        <input type="radio" class="btn-check" name="colourMode" id="modeStatus"   value="status"        checked>
        <label class="btn btn-outline-success py-0 px-2"  for="modeStatus"   style="font-size:.75rem;">Status</label>
        <input type="radio" class="btn-check" name="colourMode" id="modePYear"    value="planting_year">
        <label class="btn btn-outline-warning py-0 px-2"  for="modePYear"    style="font-size:.75rem;">Year</label>
        <input type="radio" class="btn-check" name="colourMode" id="modeVariety"  value="variety">
        <label class="btn btn-outline-primary py-0 px-2"  for="modeVariety"  style="font-size:.75rem;">Variety</label>
        <input type="radio" class="btn-check" name="colourMode" id="modeHarvest"  value="harvest">
        <label class="btn btn-outline-danger  py-0 px-2"  for="modeHarvest"  style="font-size:.75rem;">Harvest</label>
    </div>
    <div class="vr mx-1"></div>
    <!-- Filters -->
    <select class="form-select form-select-sm" id="companyFilter" style="width:auto;min-width:120px;">
        <?php if (!$session_company_id): ?><option value="">All Companies</option><?php endif; ?>
        <?php foreach ($companies as $c): ?>
            <option value="<?php echo $c['company_id']; ?>"
                <?php if ($session_company_id && count($companies)===1) echo 'selected'; ?>>
                <?php echo htmlspecialchars($c['company_name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select class="form-select form-select-sm" id="operationFilter" style="width:auto;">
        <option value="">All Types</option>
        <option value="Plantation">Plantation</option>
        <option value="Forestry">Forestry</option>
    </select>
    <select class="form-select form-select-sm" id="statusFilter" style="width:auto;">
        <option value="">All Status</option>
        <option value="TBM">TBM</option>
        <option value="TM">TM</option>
        <option value="TR">TR</option>
        <option value="TTM">TTM</option>
    </select>
    <input type="text" class="form-control form-control-sm" id="blockSearch"
           placeholder="Search block…" style="width:150px;">
    <!-- POI type filter -->
    <select class="form-select form-select-sm" id="poiTypeFilter" style="width:auto;">
        <option value="">All POIs</option>
        <?php foreach ($poi_types as $pt): ?>
            <option value="<?php echo htmlspecialchars($pt['type_code']); ?>"><?php echo htmlspecialchars($pt['type_name']); ?></option>
        <?php endforeach; ?>
    </select>
    <label class="d-flex align-items-center gap-1 ms-1" style="font-size:.75rem; cursor:pointer;">
        <input type="checkbox" id="togglePOI" checked> POI Layer
    </label>
</div>

<!-- ── Main layout ───────────────────────────────────────────────────────────── -->
<div id="smartmap-wrap">
    <!-- Left info panel -->
    <div id="info-panel">
        <div class="panel-tab-bar">
            <div class="panel-tab active" data-tab="block-info">
                <i class="bi bi-grid"></i> Block Info
            </div>
            <div class="panel-tab" data-tab="poi-list">
                <i class="bi bi-geo-alt"></i> POI List
            </div>
            <div class="panel-tab" data-tab="draw-tools">
                <i class="bi bi-pencil-square"></i> Draw
            </div>
            <div class="panel-tab" data-tab="layer-ctrl">
                <i class="bi bi-layers"></i> Layers
            </div>
        </div>

        <!-- BLOCK INFO tab -->
        <div class="panel-content active" id="tab-block-info">
            <div id="block-info-body">
                <div class="block-info-empty">
                    <i class="bi bi-cursor-fill"></i>
                    Click a block on the map to view its information
                </div>
            </div>
        </div>

        <!-- POI LIST tab -->
        <div class="panel-content" id="tab-poi-list">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong style="font-size:.8rem;">Points of Interest</strong>
                <button class="btn btn-sm btn-success py-0" onclick="openPoiModal()" style="font-size:.72rem;">
                    <i class="bi bi-plus"></i> Add
                </button>
            </div>
            <div id="poi-list-body">
                <?php if (empty($pois)): ?>
                    <div class="text-center text-muted py-3" style="font-size:.78rem;">
                        <i class="bi bi-geo-alt" style="font-size:1.5rem;"></i><br>
                        No POIs yet. Run <code>schema_poi.sql</code> then add POIs.
                    </div>
                <?php else: ?>
                    <?php
                    $cat_labels = ['logistics'=>'Logistics','operations'=>'Operations','social'=>'Social','infrastructure'=>'Infrastructure'];
                    $cur_cat = null;
                    foreach ($pois as $poi):
                        if ($poi['category'] !== $cur_cat):
                            $cur_cat = $poi['category'];
                    ?>
                        <div style="font-size:.68rem;font-weight:700;color:#57606a;text-transform:uppercase;padding:6px 0 2px;">
                            <?php echo $cat_labels[$cur_cat] ?? $cur_cat; ?>
                        </div>
                    <?php endif; ?>
                    <div class="poi-list-item" onclick="flyToPoi(<?php echo $poi['poi_id']; ?>)"
                         data-poi-id="<?php echo $poi['poi_id']; ?>"
                         data-lat="<?php echo $poi['latitude']; ?>"
                         data-lng="<?php echo $poi['longitude']; ?>">
                        <span class="poi-dot" style="background:<?php echo htmlspecialchars($poi['map_color']); ?>;"></span>
                        <div style="flex:1; min-width:0;">
                            <div class="text-truncate"><?php echo htmlspecialchars($poi['poi_name']); ?></div>
                            <div style="font-size:.65rem; color:#9ca3af;"><?php echo htmlspecialchars($poi['type_name']); ?></div>
                        </div>
                        <button class="btn btn-sm py-0 px-1 text-danger" style="font-size:.65rem;"
                                onclick="event.stopPropagation(); deletePoi(<?php echo $poi['poi_id']; ?>, '<?php echo addslashes($poi['poi_name']); ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- DRAW TOOLS tab -->
        <div class="panel-content" id="tab-draw-tools">
            <p style="font-size:.78rem; color:#57606a;">
                Use the <strong>draw toolbar</strong> on the map (top-left) to digitise or redraw block boundaries.
            </p>
            <div style="font-size:.78rem;" class="mb-2">
                <strong>Workflow:</strong>
                <ol class="ps-3 mt-1" style="line-height:1.7;">
                    <li>Select the Polygon tool on the map</li>
                    <li>Click to trace the block boundary</li>
                    <li>Double-click to close the polygon</li>
                    <li>Enter the <strong>Block ID</strong> when prompted</li>
                    <li>Geometry is saved automatically via AJAX</li>
                </ol>
            </div>
            <div class="alert alert-info p-2" style="font-size:.72rem;">
                <i class="bi bi-info-circle"></i> Coordinates are saved to <code>blocks.geojson</code> and the PostGIS <code>blocks.geom</code> column (requires <code>migrate_geom_postgis.sql</code>).
            </div>
            <hr class="my-2">
            <p style="font-size:.78rem; color:#57606a;">
                <strong>Add POI by clicking map:</strong>
            </p>
            <label class="d-flex align-items-center gap-2 mb-2">
                <input type="checkbox" id="poiPlacementMode"> 
                <span style="font-size:.78rem;">Enable POI Placement Mode</span>
            </label>
            <select class="form-select form-select-sm mb-2" id="poiPlacementType">
                <option value="">Select POI type…</option>
                <?php foreach ($poi_types as $pt): ?>
                    <option value="<?php echo $pt['type_id']; ?>"
                            data-color="<?php echo htmlspecialchars($pt['map_color']); ?>"
                            data-name="<?php echo htmlspecialchars($pt['type_name']); ?>">
                        <?php echo htmlspecialchars($pt['type_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="text-muted" style="font-size:.7rem;">When enabled, clicking the map will drop a POI marker at that location.</div>
        </div>

        <!-- LAYERS tab -->
        <div class="panel-content" id="tab-layer-ctrl">
            <p style="font-size:.78rem; font-weight:600; mb-1">Toggle Map Layers</p>
            <div class="d-flex flex-column gap-2">
                <label class="d-flex align-items-center gap-2">
                    <input type="checkbox" id="layerBlocks" checked>
                    <span style="font-size:.78rem;"><i class="bi bi-grid text-success"></i> Block Polygons</span>
                </label>
                <label class="d-flex align-items-center gap-2">
                    <input type="checkbox" id="layerPOI" checked>
                    <span style="font-size:.78rem;"><i class="bi bi-geo-alt-fill text-danger"></i> POI Markers</span>
                </label>
                <label class="d-flex align-items-center gap-2">
                    <input type="checkbox" id="layerLabels" checked>
                    <span style="font-size:.78rem;"><i class="bi bi-fonts"></i> Block Labels</span>
                </label>
                <label class="d-flex align-items-center gap-2">
                    <input type="checkbox" id="layerHeatmap">
                    <span style="font-size:.78rem;"><i class="bi bi-fire text-warning"></i> Harvest Heatmap</span>
                </label>
            </div>
            <hr class="my-2">
            <p style="font-size:.78rem; font-weight:600;">Base Map</p>
            <div class="d-flex flex-column gap-2">
                <label class="d-flex align-items-center gap-2">
                    <input type="radio" name="baseMap" value="osm" checked>
                    <span style="font-size:.78rem;">OpenStreetMap</span>
                </label>
                <label class="d-flex align-items-center gap-2">
                    <input type="radio" name="baseMap" value="satellite">
                    <span style="font-size:.78rem;">Satellite (Esri)</span>
                </label>
                <label class="d-flex align-items-center gap-2">
                    <input type="radio" name="baseMap" value="topo">
                    <span style="font-size:.78rem;">Topo (OpenTopo)</span>
                </label>
            </div>
        </div>
    </div><!-- /info-panel -->

    <!-- Toggle button -->
    <button id="panel-toggle" title="Toggle info panel">◀</button>

    <!-- Map -->
    <div id="map"></div>
</div><!-- /smartmap-wrap -->

<!-- ── POI Add/Edit Modal ────────────────────────────────────────────────────── -->
<div class="modal fade" id="poiModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="poiModalTitle"><i class="bi bi-geo-alt-fill"></i> Add Point of Interest</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="poiFormMode" value="add">
                <input type="hidden" id="poiEditId" value="">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">POI Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="poi_type_id" required>
                            <option value="">Select type…</option>
                            <?php
                            $prev_cat = null;
                            foreach ($poi_types as $pt):
                                if ($pt['category'] !== $prev_cat):
                                    if ($prev_cat !== null) echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars(ucfirst($pt['category'])) . '">';
                                    $prev_cat = $pt['category'];
                                endif;
                            ?>
                                <option value="<?php echo $pt['type_id']; ?>"
                                        data-color="<?php echo htmlspecialchars($pt['map_color']); ?>">
                                    <?php echo htmlspecialchars($pt['type_name']); ?>
                                </option>
                            <?php endforeach; if ($prev_cat) echo '</optgroup>'; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="poi_name" placeholder="e.g. Gudang Pupuk Divisi 1" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Code</label>
                        <input type="text" class="form-control" id="poi_code" placeholder="WH-01">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Latitude <span class="text-danger">*</span></label>
                        <input type="number" step="0.0000001" class="form-control" id="poi_lat" placeholder="-2.1234567" required>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Longitude <span class="text-danger">*</span></label>
                        <input type="number" step="0.0000001" class="form-control" id="poi_lng" placeholder="113.9876543" required>
                    </div>
                    <div class="col-md-3 mb-3 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-success btn-sm w-100"
                                onclick="pickCoordFromMap()">
                            <i class="bi bi-crosshair2"></i> Pick on Map
                        </button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Capacity / Notes</label>
                        <input type="text" class="form-control" id="poi_capacity" placeholder="e.g. 500 ton, 20 trucks/day">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Address</label>
                        <input type="text" class="form-control" id="poi_address" placeholder="Block D3, Division II">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Name</label>
                        <input type="text" class="form-control" id="poi_contact_name">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Phone</label>
                        <input type="text" class="form-control" id="poi_contact_phone" placeholder="+62 8xx-xxxx-xxxx">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="poi_description" rows="2"></textarea>
                </div>
                <div id="poiFormResult" class="mt-1" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitPoiForm()">
                    <i class="bi bi-check2"></i> Save POI
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Leaflet + Draw JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>

<script>
// ═══════════════════════════════════════════════════════════════════════════════
// DATA FROM PHP
// ═══════════════════════════════════════════════════════════════════════════════
const blocksData = <?php echo json_encode($blocks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP); ?>;
const poisData   = <?php echo json_encode($pois,   JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP); ?>;
const poiTypes   = <?php echo json_encode($poi_types, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP); ?>;

// ═══════════════════════════════════════════════════════════════════════════════
// MAP INIT
// ═══════════════════════════════════════════════════════════════════════════════
const map = L.map('map', { zoomSnap: 0.5, preferCanvas: false }).setView([-2.2161, 113.9135], 13);

const baseLayers = {
    osm:       L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OSM', maxZoom: 22 }),
    satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: '© Esri', maxZoom: 22 }),
    topo:      L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',  { attribution: '© OTM', maxZoom: 17 }),
};
baseLayers.osm.addTo(map);

document.querySelectorAll('input[name="baseMap"]').forEach(r => {
    r.addEventListener('change', () => {
        Object.values(baseLayers).forEach(l => map.removeLayer(l));
        baseLayers[r.value].addTo(map);
    });
});

// ── Layer groups ──────────────────────────────────────────────────────────────
const blockLayerGroup  = L.layerGroup().addTo(map);
const poiLayerGroup    = L.layerGroup().addTo(map);
const labelLayerGroup  = L.layerGroup().addTo(map);
const drawnItems       = new L.FeatureGroup().addTo(map);

// ── Leaflet.Draw ──────────────────────────────────────────────────────────────
const drawControl = new L.Control.Draw({
    draw: {
        polygon:      { shapeOptions: { color: '#f59e0b', fillColor: '#f59e0b', fillOpacity: 0.25 }, showArea: true },
        polyline:     false, rectangle: false, circle: false, circlemarker: false, marker: false
    },
    edit: { featureGroup: drawnItems, remove: true }
});
map.addControl(drawControl);

map.on(L.Draw.Event.CREATED, function(e) {
    const layer = e.layer;
    drawnItems.addLayer(layer);
    const geojsonGeom = JSON.stringify(layer.toGeoJSON().geometry);

    const blockIdStr = prompt('Enter Block ID to assign this boundary to:\n(find it in the Block List)', '');
    if (!blockIdStr || isNaN(parseInt(blockIdStr))) {
        drawnItems.removeLayer(layer);
        return;
    }
    const fd = new FormData();
    fd.append('block_id', parseInt(blockIdStr));
    fd.append('geojson',  geojsonGeom);
    fetch('ajax/save_block_geom.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast('success', d.message);
                drawnItems.removeLayer(layer);  // reload will show it
            } else {
                showToast('danger', d.message);
                drawnItems.removeLayer(layer);
            }
        })
        .catch(err => { showToast('danger', 'Network error: ' + err.message); drawnItems.removeLayer(layer); });
});

// ═══════════════════════════════════════════════════════════════════════════════
// COLOUR PALETTES
// ═══════════════════════════════════════════════════════════════════════════════
let colourMode = 'status';

const statusColors = {
    Plantation: { TBM: '#FFA500', TM: '#228B22', TR: '#DC143C', TTM: '#808080' },
    Forestry:   { default: '#006400' }
};
const yearPalette    = ['#1a6b3c','#2e8b57','#3cb371','#52b788','#74c69d','#b7e4c7','#80b918','#a7c957','#386641','#6a994e','#bc6c25'];
const varietyPalette = ['#1d3557','#457b9d','#6a4c93','#8338ec','#3a86ff','#023e8a','#7b2d8b','#9d4edd','#5e60ce','#4361ee','#4cc9f0'];
const harvestColors  = ['#f7fbff','#c6dbef','#9ecae1','#6baed6','#4292c6','#2171b5','#084594'];

const yearColorMap    = {};
const varietyColorMap = {};
const harvestThresholds = [];

[...new Set(blocksData.map(b => b.planting_year).filter(Boolean))].sort()
    .forEach((y, i) => yearColorMap[y] = yearPalette[i % yearPalette.length]);
[...new Set(blocksData.map(b => b.variety_name).filter(Boolean))].sort()
    .forEach((v, i) => varietyColorMap[v] = varietyPalette[i % varietyPalette.length]);

// Harvest quintile thresholds
const harvestVals = blocksData.map(b => parseFloat(b.harvest_total_kg || 0)).filter(v => v > 0).sort((a,b) => a-b);
if (harvestVals.length) {
    const step = Math.ceil(harvestVals.length / 6);
    for (let i = 0; i < 6; i++) harvestThresholds.push(harvestVals[Math.min(i * step, harvestVals.length-1)]);
}

function getColor(block) {
    if (colourMode === 'planting_year') return yearColorMap[block.planting_year] || '#cccccc';
    if (colourMode === 'variety')       return varietyColorMap[block.variety_name] || '#cccccc';
    if (colourMode === 'harvest') {
        const v = parseFloat(block.harvest_total_kg || 0);
        if (!v) return '#f0f0f0';
        for (let i = harvestThresholds.length - 1; i >= 0; i--) {
            if (v >= harvestThresholds[i]) return harvestColors[i + 1] || harvestColors[harvestColors.length - 1];
        }
        return harvestColors[0];
    }
    // status (default)
    if (block.operation_type === 'Plantation') return statusColors.Plantation[block.status] || '#808080';
    return statusColors.Forestry.default;
}

document.querySelectorAll('input[name="colourMode"]').forEach(r => {
    r.addEventListener('change', () => { colourMode = r.value; applyFilters(); });
});

// ═══════════════════════════════════════════════════════════════════════════════
// BLOCK RENDERING
// ═══════════════════════════════════════════════════════════════════════════════
let allBlockLayers = [];
let globalBounds   = null;

function htmlDecode(str) {
    const t = document.createElement('textarea');
    t.innerHTML = str;
    let s = t.value;
    return s.replace(/"/g,'"').replace(/&#34;/g,'"').replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>');
}

function addBlocksToMap(blocks) {
    blockLayerGroup.clearLayers();
    labelLayerGroup.clearLayers();
    allBlockLayers = [];
    let cntP = 0, cntF = 0;

    blocks.forEach(block => {
        if (!block.geojson) return;
        try {
            const geojson = JSON.parse(htmlDecode(block.geojson));
            if (!geojson || !geojson.type) return;
            const color = getColor(block);

            const layer = L.geoJSON(geojson, {
                style: { color, fillColor: color, weight: 2, opacity: 0.85, fillOpacity: 0.38 }
            });
            layer.on('click', () => showBlockInfo(block));
            layer.bindTooltip(block.block_code, { permanent: false, direction: 'center', className: 'leaflet-label' });

            blockLayerGroup.addLayer(layer);
            allBlockLayers.push({ layer, data: block });

            // Persistent label
            if (document.getElementById('layerLabels').checked) {
                try {
                    const bounds = layer.getBounds();
                    const center = bounds.getCenter();
                    const lbl = L.marker(center, {
                        icon: L.divIcon({
                            className: '',
                            html: `<span style="font-size:.62rem;font-weight:700;color:#1f2328;text-shadow:0 0 3px #fff,0 0 3px #fff;white-space:nowrap;">${block.block_code}</span>`,
                            iconAnchor: [0, 0]
                        }),
                        interactive: false
                    });
                    labelLayerGroup.addLayer(lbl);
                } catch(e) {}
            }

            block.operation_type === 'Plantation' ? cntP++ : cntF++;
        } catch(e) { console.warn('GeoJSON error block', block.block_code, e.message); }
    });

    // Fit bounds
    if (allBlockLayers.length) {
        const grp = L.featureGroup(allBlockLayers.map(l => l.layer));
        const b   = grp.getBounds();
        if (b.isValid()) { if (!globalBounds) globalBounds = b; map.fitBounds(b.pad(0.25)); }
    } else if (globalBounds) {
        map.fitBounds(globalBounds.pad(0.25));
    }

    document.getElementById('cnt-plantation').textContent = cntP;
    document.getElementById('cnt-forestry').textContent   = cntF;
    updateLegend(blocks);
}

// ═══════════════════════════════════════════════════════════════════════════════
// BLOCK INFO PANEL
// ═══════════════════════════════════════════════════════════════════════════════
function showBlockInfo(block) {
    switchTab('block-info');

    const area        = parseFloat(block.area_ha || block.area || 0).toFixed(2);
    const harvestKg   = block.harvest_total_kg ? (parseFloat(block.harvest_total_kg)/1000).toFixed(1) + ' T' : '—';
    const harvestYield= (block.harvest_total_kg && area > 0)
                        ? (parseFloat(block.harvest_total_kg) / parseFloat(area) / 12).toFixed(0) + ' kg/ha/mo'
                        : '—';
    const statusBadge = { TBM:'warning', TM:'success', TR:'danger', TTM:'secondary' };
    const sBadge      = statusBadge[block.status] || 'secondary';

    let html = `
        <div style="padding:8px 0;">
            <div style="font-size:.9rem;font-weight:700;margin-bottom:2px;">
                ${block.block_code}
                <span class="badge bg-${sBadge} ms-1" style="font-size:.6rem;">${block.status || '—'}</span>
            </div>
            <div style="font-size:.72rem;color:#57606a;margin-bottom:8px;">${block.block_name || ''}${block.division_name ? ' &middot; ' + block.division_name : ''}${block.unit_name ? ' &middot; ' + block.unit_name : ''}</div>
        </div>
        <div class="kpi-grid">
            <div class="kpi-box">
                <div class="kpi-val">${area}</div>
                <div class="kpi-lbl">Area (Ha)</div>
            </div>
            <div class="kpi-box">
                <div class="kpi-val">${parseInt(block.total_plants || 0).toLocaleString()}</div>
                <div class="kpi-lbl">Plants</div>
            </div>
            <div class="kpi-box">
                <div class="kpi-val">${harvestKg}</div>
                <div class="kpi-lbl">FFB (12 mo)</div>
            </div>
            <div class="kpi-box">
                <div class="kpi-val">${harvestYield}</div>
                <div class="kpi-lbl">Yield/Ha/Mo</div>
            </div>
        </div>
        <div style="font-size:.8rem;font-weight:600;margin:8px 0 4px;">Details</div>`;

    const rows = [
        ['Company',       block.company_name  || '—'],
        ['Business Unit', block.unit_name     || '—'],
        ['Division',      block.division_name || '—'],
        ['Type',          block.operation_type|| '—'],
        ['Planting Year', block.planting_year || '—'],
        ['Variety',       block.variety_name  || '—'],
        ['Plant Age',     block.plant_age ? block.plant_age + ' yrs' : '—'],
    ];
    if (block.operation_type !== 'Plantation') {
        rows.push(
            ['Tree Species', block.tree_species || '—'],
            ['Forest Type',  block.forest_type  || '—'],
            ['Carbon Stock', block.carbon_stock_ton ? parseFloat(block.carbon_stock_ton).toFixed(1) + ' T' : '—'],
        );
    }
    rows.forEach(([lbl, val]) => {
        html += `<div class="bi-row"><span class="bi-label">${lbl}</span><span class="bi-val">${val}</span></div>`;
    });

    html += `
        <div class="mt-3 d-flex gap-2">
            <a href="blocks.php?action=edit&id=${block.block_id}" target="_blank"
               class="btn btn-sm btn-outline-success" style="font-size:.72rem;">
               <i class="bi bi-pencil"></i> Edit Block
            </a>
            <button onclick="map.fitBounds(allBlockLayers.find(l=>l.data.block_id==${block.block_id})?.layer?.getBounds()?.pad(0.5) || map.getBounds())"
                    class="btn btn-sm btn-outline-secondary" style="font-size:.72rem;">
                <i class="bi bi-zoom-in"></i> Zoom
            </button>
        </div>`;

    document.getElementById('block-info-body').innerHTML = html;
}

// ═══════════════════════════════════════════════════════════════════════════════
// POI MARKERS
// ═══════════════════════════════════════════════════════════════════════════════
const poiMarkerMap = {};   // poi_id → L.marker

function poiIcon(color, iconName) {
    return L.divIcon({
        className: '',
        html: `<div style="width:30px;height:30px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);
                           background:${color};border:2px solid #fff;
                           box-shadow:0 2px 6px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;">
                   <i class="bi ${iconName}" style="transform:rotate(45deg);color:#fff;font-size:.7rem;"></i>
               </div>`,
        iconSize:   [30, 30],
        iconAnchor: [15, 30],
        popupAnchor:[0, -32]
    });
}

function createPoiPopup(p) {
    return `<div style="min-width:200px;font-size:.78rem;">
        <strong style="font-size:.88rem;">${p.poi_name}</strong><br>
        <span class="badge" style="background:${p.map_color};font-size:.65rem;">${p.type_name}</span>
        ${p.poi_code ? `<code style="font-size:.65rem;margin-left:4px;">${p.poi_code}</code>` : ''}
        <hr style="margin:5px 0;">
        ${p.address       ? `<div><i class="bi bi-geo-alt"></i> ${p.address}</div>` : ''}
        ${p.capacity_info ? `<div><i class="bi bi-box"></i> ${p.capacity_info}</div>` : ''}
        ${p.description   ? `<div><i class="bi bi-info-circle"></i> ${p.description}</div>` : ''}
        ${p.contact_name  ? `<div><i class="bi bi-person"></i> ${p.contact_name} ${p.contact_phone ? '· '+p.contact_phone : ''}</div>` : ''}
        <div class="mt-2 d-flex gap-1">
            <button onclick="editPoi(${p.poi_id})" class="btn btn-sm btn-warning py-0 px-2" style="font-size:.65rem;"><i class="bi bi-pencil"></i> Edit</button>
            <button onclick="deletePoi(${p.poi_id},'${p.poi_name.replace(/'/g,"\\'")}'); map.closePopup();"
                    class="btn btn-sm btn-danger py-0 px-2" style="font-size:.65rem;"><i class="bi bi-trash"></i> Delete</button>
        </div>
    </div>`;
}

function renderPois(pois) {
    poiLayerGroup.clearLayers();
    const typeFilter = document.getElementById('poiTypeFilter').value;
    pois.forEach(p => {
        if (typeFilter && p.type_code !== typeFilter) return;
        const marker = L.marker([parseFloat(p.latitude), parseFloat(p.longitude)], {
            icon: poiIcon(p.map_color, p.icon_name)
        }).bindPopup(createPoiPopup(p), { maxWidth: 260 });
        poiLayerGroup.addLayer(marker);
        poiMarkerMap[p.poi_id] = marker;
    });
}
renderPois(poisData);

// Fly to POI from panel list
function flyToPoi(poiId) {
    const marker = poiMarkerMap[poiId];
    if (!marker) return;
    map.setView(marker.getLatLng(), Math.max(map.getZoom(), 16));
    marker.openPopup();
}

// ═══════════════════════════════════════════════════════════════════════════════
// FILTERS
// ═══════════════════════════════════════════════════════════════════════════════
function applyFilters() {
    const company  = document.getElementById('companyFilter').value;
    const opType   = document.getElementById('operationFilter').value;
    const status   = document.getElementById('statusFilter').value;
    const search   = document.getElementById('blockSearch').value.toLowerCase();

    const filtered = blocksData.filter(b => {
        if (company  && b.company_id != company)            return false;
        if (opType   && b.operation_type !== opType)        return false;
        if (status   && b.status !== status)                return false;
        if (search   && !(b.block_code||'').toLowerCase().includes(search)
                     && !(b.block_name||'').toLowerCase().includes(search)) return false;
        return true;
    });
    addBlocksToMap(filtered);
    renderPois(poisData);
}

['companyFilter','operationFilter','statusFilter','poiTypeFilter'].forEach(id =>
    document.getElementById(id)?.addEventListener('change', applyFilters));

let searchTimer;
document.getElementById('blockSearch').addEventListener('input', () => {
    clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 280);
});

// ═══════════════════════════════════════════════════════════════════════════════
// LAYER TOGGLES
// ═══════════════════════════════════════════════════════════════════════════════
document.getElementById('layerBlocks').addEventListener('change', e => {
    e.target.checked ? blockLayerGroup.addTo(map) : map.removeLayer(blockLayerGroup);
});
document.getElementById('layerPOI').addEventListener('change', e => {
    e.target.checked ? poiLayerGroup.addTo(map) : map.removeLayer(poiLayerGroup);
});
document.getElementById('layerLabels').addEventListener('change', () => applyFilters());
document.getElementById('togglePOI').addEventListener('change', e => {
    document.getElementById('layerPOI').checked = e.target.checked;
    e.target.checked ? poiLayerGroup.addTo(map) : map.removeLayer(poiLayerGroup);
});

// ═══════════════════════════════════════════════════════════════════════════════
// LEGEND (bottom-right)
// ═══════════════════════════════════════════════════════════════════════════════
const legend = L.control({ position: 'bottomright' });
legend.onAdd = function() {
    const d = L.DomUtil.create('div', 'leaflet-legend');
    d.id = 'map-legend';
    return d;
};
legend.addTo(map);

function updateLegend(visibleBlocks) {
    const div = document.getElementById('map-legend');
    if (!div) return;
    let html = '<h6>Block Legend</h6>';

    if (colourMode === 'status') {
        const lbl = { TBM:'TBM – Immature', TM:'TM – Mature', TR:'TR – Replanting', TTM:'TTM – Non-productive' };
        Object.entries(statusColors.Plantation).forEach(([s,c]) =>
            html += `<div class="leg-row"><span class="leg-swatch" style="background:${c}"></span>${lbl[s]||s}</div>`);
        html += `<div class="leg-row"><span class="leg-swatch" style="background:${statusColors.Forestry.default}"></span>Forestry</div>`;

    } else if (colourMode === 'planting_year') {
        const years = [...new Set(visibleBlocks.map(b => b.planting_year).filter(Boolean))].sort();
        years.forEach(y => html += `<div class="leg-row"><span class="leg-swatch" style="background:${yearColorMap[y]||'#aaa'}"></span>${y}</div>`);
        if (visibleBlocks.some(b => !b.planting_year))
            html += `<div class="leg-row"><span class="leg-swatch" style="background:#ccc"></span>Unknown</div>`;

    } else if (colourMode === 'variety') {
        const vars = [...new Set(visibleBlocks.map(b => b.variety_name).filter(Boolean))].sort();
        vars.forEach(v => html += `<div class="leg-row"><span class="leg-swatch" style="background:${varietyColorMap[v]||'#aaa'}"></span>${v}</div>`);
        if (visibleBlocks.some(b => !b.variety_name))
            html += `<div class="leg-row"><span class="leg-swatch" style="background:#ccc"></span>No variety</div>`;

    } else if (colourMode === 'harvest') {
        html += '<div style="font-size:.68rem;color:#57606a;">FFB (12 months)</div>';
        html += `<div class="leg-row"><span class="leg-swatch" style="background:#f0f0f0"></span>No data</div>`;
        harvestThresholds.forEach((t, i) => {
            const label = i === 0 ? `< ${(t/1000).toFixed(0)} T` : `≥ ${(t/1000).toFixed(0)} T`;
            html += `<div class="leg-row"><span class="leg-swatch" style="background:${harvestColors[i+1]||'#084594'}"></span>${label}</div>`;
        });
    }

    // POI type icons
    const seenTypes = new Set(poisData.map(p => p.type_code));
    if (seenTypes.size) {
        html += '<h6 class="mt-2">POI Types</h6>';
        poiTypes.filter(t => seenTypes.has(t.type_code)).slice(0, 8).forEach(t => {
            html += `<div class="leg-row"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${t.map_color};border:1px solid rgba(0,0,0,.2);margin-right:4px;"></span>${t.type_name}</div>`;
        });
    }
    div.innerHTML = html;
}

// ═══════════════════════════════════════════════════════════════════════════════
// POI PLACEMENT MODE (click map to drop marker)
// ═══════════════════════════════════════════════════════════════════════════════
let poiPlacementActive = false;
let tempPoiMarker = null;

document.getElementById('poiPlacementMode').addEventListener('change', function() {
    poiPlacementActive = this.checked;
    map.getContainer().style.cursor = poiPlacementActive ? 'crosshair' : '';
});

map.on('click', function(e) {
    if (!poiPlacementActive) return;
    if (tempPoiMarker) map.removeLayer(tempPoiMarker);

    const typeId = document.getElementById('poiPlacementType').value;
    const typeData = poiTypes.find(t => t.type_id == typeId);
    const color = typeData?.map_color || '#e74c3c';
    const icon  = typeData?.icon_name || 'bi-geo-alt-fill';

    tempPoiMarker = L.marker(e.latlng, { icon: poiIcon(color, icon) }).addTo(map);
    document.getElementById('poi_lat').value = e.latlng.lat.toFixed(7);
    document.getElementById('poi_lng').value = e.latlng.lng.toFixed(7);
    if (typeId) document.getElementById('poi_type_id').value = typeId;
    openPoiModal();
});

// ═══════════════════════════════════════════════════════════════════════════════
// POI CRUD via modal
// ═══════════════════════════════════════════════════════════════════════════════
function openPoiModal(data) {
    document.getElementById('poiFormMode').value  = 'add';
    document.getElementById('poiEditId').value    = '';
    document.getElementById('poiModalTitle').textContent = '  Add Point of Interest';
    ['poi_type_id','poi_name','poi_code','poi_lat','poi_lng',
     'poi_capacity','poi_address','poi_contact_name','poi_contact_phone','poi_description']
        .forEach(id => document.getElementById(id) && (document.getElementById(id).value = ''));

    if (data) {
        document.getElementById('poiFormMode').value   = 'update';
        document.getElementById('poiEditId').value     = data.poi_id;
        document.getElementById('poiModalTitle').textContent = '  Edit POI';
        document.getElementById('poi_type_id').value  = data.type_id      || '';
        document.getElementById('poi_name').value     = data.poi_name     || '';
        document.getElementById('poi_code').value     = data.poi_code     || '';
        document.getElementById('poi_lat').value      = data.latitude     || '';
        document.getElementById('poi_lng').value      = data.longitude    || '';
        document.getElementById('poi_capacity').value = data.capacity_info|| '';
        document.getElementById('poi_address').value  = data.address      || '';
        document.getElementById('poi_contact_name').value  = data.contact_name  || '';
        document.getElementById('poi_contact_phone').value = data.contact_phone || '';
        document.getElementById('poi_description').value   = data.description   || '';
    }
    document.getElementById('poiFormResult').style.display = 'none';
    new bootstrap.Modal(document.getElementById('poiModal')).show();
}

function editPoi(poiId) {
    const data = poisData.find(p => p.poi_id == poiId);
    if (data) openPoiModal(data);
}

function deletePoi(poiId, name) {
    if (!confirm(`Delete POI: "${name}"?`)) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('poi_id', poiId);
    fetch('ajax/poi_crud.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) { showToast('success', 'POI deleted'); setTimeout(() => location.reload(), 800); }
            else showToast('danger', d.message);
        });
}

function submitPoiForm() {
    const mode     = document.getElementById('poiFormMode').value;
    const resultEl = document.getElementById('poiFormResult');
    const fd = new FormData();
    fd.append('action',        mode === 'update' ? 'update' : 'add');
    if (mode === 'update') fd.append('poi_id', document.getElementById('poiEditId').value);
    fd.append('type_id',       document.getElementById('poi_type_id').value);
    fd.append('poi_name',      document.getElementById('poi_name').value.trim());
    fd.append('poi_code',      document.getElementById('poi_code').value.trim());
    fd.append('latitude',      document.getElementById('poi_lat').value);
    fd.append('longitude',     document.getElementById('poi_lng').value);
    fd.append('capacity_info', document.getElementById('poi_capacity').value.trim());
    fd.append('address',       document.getElementById('poi_address').value.trim());
    fd.append('contact_name',  document.getElementById('poi_contact_name').value.trim());
    fd.append('contact_phone', document.getElementById('poi_contact_phone').value.trim());
    fd.append('description',   document.getElementById('poi_description').value.trim());

    if (!fd.get('type_id') || !fd.get('poi_name') || !fd.get('latitude') || !fd.get('longitude')) {
        resultEl.innerHTML = '<div class="alert alert-warning p-2">Type, Name, Latitude and Longitude are required.</div>';
        resultEl.style.display = 'block';
        return;
    }

    fetch('ajax/poi_crud.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast('success', d.message);
                if (tempPoiMarker) { map.removeLayer(tempPoiMarker); tempPoiMarker = null; }
                bootstrap.Modal.getInstance(document.getElementById('poiModal'))?.hide();
                setTimeout(() => location.reload(), 700);
            } else {
                resultEl.innerHTML = `<div class="alert alert-danger p-2">${d.message}</div>`;
                resultEl.style.display = 'block';
            }
        });
}

// Pick coord — close modal, let user click map
function pickCoordFromMap() {
    bootstrap.Modal.getInstance(document.getElementById('poiModal'))?.hide();
    document.getElementById('poiPlacementMode').checked = true;
    poiPlacementActive = true;
    map.getContainer().style.cursor = 'crosshair';
    showToast('info', 'Click on the map to pick coordinates, then the modal will reopen.');
}

// ═══════════════════════════════════════════════════════════════════════════════
// LEFT PANEL: tab switching & toggle
// ═══════════════════════════════════════════════════════════════════════════════
function switchTab(tabId) {
    document.querySelectorAll('.panel-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabId));
    document.querySelectorAll('.panel-content').forEach(c => c.classList.toggle('active', c.id === 'tab-' + tabId));
}
document.querySelectorAll('.panel-tab').forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));

const panel    = document.getElementById('info-panel');
const togBtn   = document.getElementById('panel-toggle');
togBtn.addEventListener('click', () => {
    const collapsed = panel.classList.toggle('collapsed');
    togBtn.textContent = collapsed ? '▶' : '◀';
    togBtn.classList.toggle('collapsed', collapsed);
    setTimeout(() => map.invalidateSize(), 260);
});

// ═══════════════════════════════════════════════════════════════════════════════
// SCALE + TOAST
// ═══════════════════════════════════════════════════════════════════════════════
L.control.scale({ imperial: false }).addTo(map);

function showToast(type, msg) {
    const c = document.createElement('div');
    c.className = `alert alert-${type} alert-dismissible shadow`;
    c.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;max-width:320px;font-size:.82rem;';
    c.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    document.body.appendChild(c);
    setTimeout(() => c.remove(), 3500);
}

// ── Initial render ────────────────────────────────────────────────────────────
applyFilters();
</script>

<?php require_once 'includes/footer.php'; ?>
