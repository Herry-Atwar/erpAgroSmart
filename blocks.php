<?php
// Version check - if you see this error, clear browser cache
// Last updated: 2026-06-09 23:48
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Helper function to recursively update parent divisions for Forestry
function update_parent_divisions_forestry($db, $division_id) {
    // Get parent division
    $parent_stmt = $db->prepare("SELECT parent_division_id FROM divisions WHERE division_id = ?");
    $parent_stmt->execute([$division_id]);
    $parent_division_id = $parent_stmt->fetchColumn();
    
    if ($parent_division_id) {
        error_log("FORESTRY - Updating parent division ID: " . $parent_division_id);
        
        // Update parent division with sum of all child divisions
        $update_parent = $db->prepare("
            UPDATE divisions
            SET
                forestry_area_ha = COALESCE((
                    SELECT SUM(child.forestry_area_ha)
                    FROM divisions child
                    WHERE child.parent_division_id = ?
                ), 0),
                total_volume_m3 = COALESCE((
                    SELECT SUM(child.total_volume_m3)
                    FROM divisions child
                    WHERE child.parent_division_id = ?
                ), 0),
                total_carbon_stock_ton = COALESCE((
                    SELECT SUM(child.total_carbon_stock_ton)
                    FROM divisions child
                    WHERE child.parent_division_id = ?
                ), 0),
                forestry_blocks = COALESCE((
                    SELECT SUM(child.forestry_blocks)
                    FROM divisions child
                    WHERE child.parent_division_id = ?
                ), 0)
            WHERE division_id = ?
        ");
        $update_parent->execute([$parent_division_id, $parent_division_id, $parent_division_id, $parent_division_id, $parent_division_id]);
        
        // Recursively update the parent's parent
        update_parent_divisions_forestry($db, $parent_division_id);
    }
}

// Helper function to recursively update parent business units for Forestry
function update_parent_business_units_forestry($db, $business_unit_id) {
    // Get parent business unit
    $parent_stmt = $db->prepare("SELECT parent_unit_id FROM business_units WHERE business_unit_id = ?");
    $parent_stmt->execute([$business_unit_id]);
    $parent_unit_id = $parent_stmt->fetchColumn();
    
    if ($parent_unit_id) {
        error_log("FORESTRY - Updating parent business unit ID: " . $parent_unit_id);
        
        // Update parent business unit with sum of all child business units
        $update_parent = $db->prepare("
            UPDATE business_units
            SET
                forestry_area_ha = COALESCE((
                    SELECT SUM(child.forestry_area_ha)
                    FROM business_units child
                    WHERE child.parent_unit_id = ?
                ), 0),
                total_volume_m3 = COALESCE((
                    SELECT SUM(child.total_volume_m3)
                    FROM business_units child
                    WHERE child.parent_unit_id = ?
                ), 0),
                total_carbon_stock_ton = COALESCE((
                    SELECT SUM(child.total_carbon_stock_ton)
                    FROM business_units child
                    WHERE child.parent_unit_id = ?
                ), 0),
                forestry_blocks = COALESCE((
                    SELECT SUM(child.forestry_blocks)
                    FROM business_units child
                    WHERE child.parent_unit_id = ?
                ), 0)
            WHERE business_unit_id = ?
        ");
        $update_parent->execute([$parent_unit_id, $parent_unit_id, $parent_unit_id, $parent_unit_id, $parent_unit_id]);
        
        // Recursively update the parent's parent
        update_parent_business_units_forestry($db, $parent_unit_id);
    }
}

// Helper function to update business_unit and company totals
function update_business_unit_and_company_totals($db, $division_id, $is_forestry = false) {
    $bu_stmt = $db->prepare("SELECT business_unit_id FROM divisions WHERE division_id = ?");
    $bu_stmt->execute([$division_id]);
    $business_unit_id = $bu_stmt->fetchColumn();
    
    if (!$business_unit_id) return;
    
    if ($is_forestry) {
        // Update business_unit forestry totals - only from top-level divisions
        $update_bu = $db->prepare("
            UPDATE business_units
            SET
                forestry_area_ha = COALESCE((SELECT SUM(d.forestry_area_ha) FROM divisions d WHERE d.business_unit_id = ? AND d.parent_division_id IS NULL), 0),
                total_volume_m3 = COALESCE((SELECT SUM(d.total_volume_m3) FROM divisions d WHERE d.business_unit_id = ? AND d.parent_division_id IS NULL), 0),
                total_carbon_stock_ton = COALESCE((SELECT SUM(d.total_carbon_stock_ton) FROM divisions d WHERE d.business_unit_id = ? AND d.parent_division_id IS NULL), 0),
                forestry_blocks = COALESCE((SELECT SUM(d.forestry_blocks) FROM divisions d WHERE d.business_unit_id = ? AND d.parent_division_id IS NULL), 0)
            WHERE business_unit_id = ?
        ");
        $update_bu->execute([$business_unit_id, $business_unit_id, $business_unit_id, $business_unit_id, $business_unit_id]);
        
        // Update parent business units recursively for Forestry
        update_parent_business_units_forestry($db, $business_unit_id);
        
        // Update company forestry totals
        $comp_stmt = $db->prepare("SELECT company_id FROM business_units WHERE business_unit_id = ?");
        $comp_stmt->execute([$business_unit_id]);
        $company_id = $comp_stmt->fetchColumn();
        
        if ($company_id) {
            $update_comp = $db->prepare("
                UPDATE companies
                SET
                    forestry_area_ha = COALESCE((SELECT SUM(bu.forestry_area_ha) FROM business_units bu WHERE bu.company_id = ? AND bu.parent_unit_id IS NULL), 0),
                    total_volume_m3 = COALESCE((SELECT SUM(bu.total_volume_m3) FROM business_units bu WHERE bu.company_id = ? AND bu.parent_unit_id IS NULL), 0),
                    total_carbon_stock_ton = COALESCE((SELECT SUM(bu.total_carbon_stock_ton) FROM business_units bu WHERE bu.company_id = ? AND bu.parent_unit_id IS NULL), 0),
                    forestry_blocks = COALESCE((SELECT SUM(bu.forestry_blocks) FROM business_units bu WHERE bu.company_id = ? AND bu.parent_unit_id IS NULL), 0)
                WHERE company_id = ?
            ");
            $update_comp->execute([$company_id, $company_id, $company_id, $company_id, $company_id]);
        }
    } else {
        // Update business_unit plantation totals - only from top-level divisions
        $update_bu = $db->prepare("
            UPDATE business_units
            SET
                total_area_ha = COALESCE((SELECT SUM(d.total_area_ha) FROM divisions d WHERE d.business_unit_id = ? AND d.parent_division_id IS NULL), 0),
                total_plants = COALESCE((SELECT SUM(d.total_plants) FROM divisions d WHERE d.business_unit_id = ? AND d.parent_division_id IS NULL), 0)
            WHERE business_unit_id = ?
        ");
        $update_bu->execute([$business_unit_id, $business_unit_id, $business_unit_id]);
        
        // Update company plantation totals
        $comp_stmt = $db->prepare("SELECT company_id FROM business_units WHERE business_unit_id = ?");
        $comp_stmt->execute([$business_unit_id]);
        $company_id = $comp_stmt->fetchColumn();
        
        if ($company_id) {
            $update_comp = $db->prepare("
                UPDATE companies
                SET
                    total_area_ha = COALESCE((SELECT SUM(bu.total_area_ha) FROM business_units bu WHERE bu.company_id = ? AND bu.parent_unit_id IS NULL), 0),
                    total_plants = COALESCE((SELECT SUM(bu.total_plants) FROM business_units bu WHERE bu.company_id = ? AND bu.parent_unit_id IS NULL), 0)
                WHERE company_id = ?
            ");
            $update_comp->execute([$company_id, $company_id, $company_id]);
        }
    }
}

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    if ($action == 'add') {
        try {
            // Calculate plant age if planting date is provided
            $plant_age = 0;
            if (post('planting_date')) {
                $plant_age = calculate_plant_age(post('planting_date'));
            }
            
            $stmt = $db->prepare("
                INSERT INTO blocks (operation_type, planting_year_id, division_id, block_code, block_name, area, planted_area,
                                  plant_density, normal_plants, abnormal_plants, dead_plants, total_plants,
                                  planting_date, plant_age, topography,
                                  soil_type, soil_ph, elevation_m, status, harvest_status,
                                  tree_species, stand_density, average_dbh, volume_m3, carbon_stock_ton,
                                  age_class, establishment_year, forest_type,
                                  latitude, longitude, geojson, notes, created_by)
                VALUES (:operation_type, :planting_year_id, :division_id, :block_code, :block_name, :area, :planted_area,
                        :plant_density, :normal_plants, :abnormal_plants, :dead_plants, :total_plants,
                        :planting_date, :plant_age, :topography,
                        :soil_type, :soil_ph, :elevation_m, :status, :harvest_status,
                        :tree_species, :stand_density, :average_dbh, :volume_m3, :carbon_stock_ton,
                        :age_class, :establishment_year, :forest_type,
                        :latitude, :longitude, :geojson, :notes, 'admin')
                RETURNING block_id
            ");
            
            $stmt->execute([
                ':operation_type' => post('operation_type', 'Plantation'),
                ':planting_year_id' => post('operation_type') == 'Forestry' ? null : (post('planting_year_id') ?: null),
                ':division_id' => post('division_id'),
                ':block_code' => post('block_code'),
                ':block_name' => post('block_name'),
                ':area' => post('area'),
                ':planted_area' => post('planted_area') ?: 0,
                ':plant_density' => post('plant_density') ?: null,
                ':normal_plants' => post('normal_plants') ?: null,
                ':abnormal_plants' => post('abnormal_plants') ?: null,
                ':dead_plants' => post('dead_plants') ?: null,
                ':total_plants' => post('total_plants') ?: null,
                ':planting_date' => post('planting_date') ?: null,
                ':plant_age' => $plant_age,
                ':topography' => post('topography', 'Flat'),
                ':soil_type' => post('soil_type') ?: null,
                ':soil_ph' => post('soil_ph') ?: null,
                ':elevation_m' => post('elevation_m') ?: null,
                ':status' => post('status', 'TBM'),
                ':harvest_status' => post('harvest_status', 'Not Ready'),
                ':tree_species' => post('tree_species') ?: null,
                ':stand_density' => post('stand_density') ?: null,
                ':average_dbh' => post('average_dbh') ?: null,
                ':volume_m3' => post('volume_m3') ?: null,
                ':carbon_stock_ton' => post('carbon_stock_ton') ?: null,
                ':age_class' => post('age_class') ?: null,
                ':establishment_year' => post('establishment_year') ?: null,
                ':forest_type' => post('forest_type') ?: null,
                ':latitude' => post('latitude') ?: null,
                ':longitude' => post('longitude') ?: null,
                ':geojson' => post('geojson') ? html_entity_decode(post('geojson'), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
                ':notes' => post('notes') ?: null
            ]);
            $new_block_id = (int)$stmt->fetchColumn(); // PostgreSQL RETURNING

            // Update planting year totals for Plantation blocks
            if (post('operation_type') == 'Plantation' && post('planting_year_id')) {
                $py_id = post('planting_year_id');
                error_log("ADD BLOCK - Updating planting year ID: " . $py_id);
                $update_py = $db->prepare("
                    UPDATE planting_years
                    SET
                        actual_area = COALESCE((
                            SELECT SUM(b.area)
                            FROM blocks b
                            WHERE b.planting_year_id = ?
                        ), 0),
                        actual_plants = COALESCE((
                            SELECT SUM(b.total_plants)
                            FROM blocks b
                            WHERE b.planting_year_id = ?
                        ), 0)
                    WHERE planting_year_id = ?
                ");
                $result = $update_py->execute([$py_id, $py_id, $py_id]);
                error_log("ADD BLOCK - Planting year update result: " . ($result ? 'SUCCESS' : 'FAILED'));
                error_log("ADD BLOCK - Rows affected: " . $update_py->rowCount());
                
                // Get division_id from planting_year
                $div_stmt = $db->prepare("SELECT division_id FROM planting_years WHERE planting_year_id = ?");
                $div_stmt->execute([$py_id]);
                $division_id = $div_stmt->fetchColumn();
                
                if ($division_id) {
                    // Update division totals (sum from all planting years in this division)
                    $update_div = $db->prepare("
                        UPDATE divisions
                        SET
                            total_area_ha = COALESCE((
                                SELECT SUM(py.actual_area)
                                FROM planting_years py
                                WHERE py.division_id = ?
                            ), 0),
                            total_plants = COALESCE((
                                SELECT SUM(py.actual_plants)
                                FROM planting_years py
                                WHERE py.division_id = ?
                            ), 0),
                            total_blocks = COALESCE((
                                SELECT COUNT(*)
                                FROM blocks b
                                INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
                                WHERE py.division_id = ?
                            ), 0)
                        WHERE division_id = ?
                    ");
                    $div_result = $update_div->execute([$division_id, $division_id, $division_id, $division_id]);
                    error_log("ADD BLOCK - Division update for ID: " . $division_id);
                    error_log("ADD BLOCK - Division update result: " . ($div_result ? 'SUCCESS' : 'FAILED'));
                    error_log("ADD BLOCK - Division rows affected: " . $update_div->rowCount());
                    
                    // Update business_unit and company
                    update_business_unit_and_company_totals($db, $division_id, false);
                }
            } elseif (post('operation_type') == 'Forestry') {
                // For Forestry blocks, update division directly (no planting years)
                $division_id = post('division_id');
                error_log("ADD BLOCK - Updating Forestry division ID: " . $division_id);
                
                $update_div = $db->prepare("
                    UPDATE divisions
                    SET
                        forestry_area_ha = COALESCE((
                            SELECT SUM(b.area)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0),
                        total_volume_m3 = COALESCE((
                            SELECT SUM(b.volume_m3)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0),
                        total_carbon_stock_ton = COALESCE((
                            SELECT SUM(b.carbon_stock_ton)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0),
                        forestry_blocks = COALESCE((
                            SELECT COUNT(*)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0)
                    WHERE division_id = ?
                ");
                $result = $update_div->execute([$division_id, $division_id, $division_id, $division_id, $division_id]);
                error_log("ADD BLOCK - Forestry division update result: " . ($result ? 'SUCCESS' : 'FAILED'));
                
                // Update parent divisions recursively for Forestry
                update_parent_divisions_forestry($db, $division_id);
                
                // Update business_unit and company for Forestry
                update_business_unit_and_company_totals($db, $division_id, true);
            }
            
            // Save dominant variety for Plantation blocks
            if (post('operation_type') == 'Plantation' && post('variety_id')) {
                try {
                    $ins_v = $db->prepare("
                        INSERT INTO block_plant_varieties (block_id, variety_id, plant_count, percentage)
                        VALUES (?, ?, 0, 100)
                        ON CONFLICT (block_id, variety_id) DO NOTHING
                    ");
                    $ins_v->execute([$new_block_id, (int)post('variety_id')]);
                } catch (PDOException $e) { /* ignore if table not yet created */ }
            }

            set_message('success', 'Block added successfully!');
            redirect('blocks.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding block: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            // Get old block data before updating
            $old_block_stmt = $db->prepare("SELECT planting_year_id, operation_type FROM blocks WHERE block_id = :id");
            $old_block_stmt->execute([':id' => post('block_id')]);
            $old_block_data = $old_block_stmt->fetch();
            
            // Debug logging
            error_log("EDIT BLOCK - Operation Type: " . post('operation_type'));
            error_log("EDIT BLOCK - Block ID: " . post('block_id'));
            error_log("EDIT BLOCK - Planting Year ID: " . post('planting_year_id'));
            
            // Calculate plant age if planting date is provided
            $plant_age = 0;
            if (post('planting_date')) {
                $plant_age = calculate_plant_age(post('planting_date'));
            }
            
            $stmt = $db->prepare("
                UPDATE blocks
                SET operation_type = :operation_type,
                    planting_year_id = :planting_year_id, division_id = :division_id,
                    block_code = :block_code, block_name = :block_name,
                    area = :area, planted_area = :planted_area, plant_density = :plant_density,
                    normal_plants = :normal_plants, abnormal_plants = :abnormal_plants,
                    dead_plants = :dead_plants, total_plants = :total_plants,
                    planting_date = :planting_date, plant_age = :plant_age,
                    topography = :topography, soil_type = :soil_type, soil_ph = :soil_ph,
                    elevation_m = :elevation_m, status = :status, harvest_status = :harvest_status,
                    tree_species = :tree_species, stand_density = :stand_density,
                    average_dbh = :average_dbh, volume_m3 = :volume_m3,
                    carbon_stock_ton = :carbon_stock_ton, age_class = :age_class,
                    establishment_year = :establishment_year, forest_type = :forest_type,
                    latitude = :latitude, longitude = :longitude, geojson = :geojson,
                    notes = :notes, updated_by = 'admin'
                WHERE block_id = :id
            ");
            
            $stmt->execute([
                ':id' => post('block_id'),
                ':operation_type' => post('operation_type', 'Plantation'),
                ':planting_year_id' => post('operation_type') == 'Forestry' ? null : (post('planting_year_id') ?: null),
                ':division_id' => post('division_id'),
                ':block_code' => post('block_code'),
                ':block_name' => post('block_name'),
                ':area' => post('area'),
                ':planted_area' => post('planted_area') ?: 0,
                ':plant_density' => post('plant_density') ?: null,
                ':normal_plants' => post('normal_plants') ?: null,
                ':abnormal_plants' => post('abnormal_plants') ?: null,
                ':dead_plants' => post('dead_plants') ?: null,
                ':total_plants' => post('total_plants') ?: null,
                ':planting_date' => post('planting_date') ?: null,
                ':plant_age' => $plant_age,
                ':topography' => post('topography'),
                ':soil_type' => post('soil_type') ?: null,
                ':soil_ph' => post('soil_ph') ?: null,
                ':elevation_m' => post('elevation_m') ?: null,
                ':status' => post('status'),
                ':harvest_status' => post('harvest_status'),
                ':tree_species' => post('tree_species') ?: null,
                ':stand_density' => post('stand_density') ?: null,
                ':average_dbh' => post('average_dbh') ?: null,
                ':volume_m3' => post('volume_m3') ?: null,
                ':carbon_stock_ton' => post('carbon_stock_ton') ?: null,
                ':age_class' => post('age_class') ?: null,
                ':establishment_year' => post('establishment_year') ?: null,
                ':forest_type' => post('forest_type') ?: null,
                ':latitude' => post('latitude') ?: null,
                ':longitude' => post('longitude') ?: null,
                ':geojson' => post('geojson') ? html_entity_decode(post('geojson'), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
                ':notes' => post('notes') ?: null
            ]);
            
            // Update planting year totals for Plantation blocks
            if (post('operation_type') == 'Plantation') {
                // Update new planting year if exists
                if (post('planting_year_id')) {
                    $py_id = post('planting_year_id');
                    error_log("EDIT BLOCK - Updating NEW planting year ID: " . $py_id);
                    $update_py = $db->prepare("
                        UPDATE planting_years
                        SET
                            actual_area = COALESCE((
                                SELECT SUM(b.area)
                                FROM blocks b
                                WHERE b.planting_year_id = ?
                            ), 0),
                            actual_plants = COALESCE((
                                SELECT SUM(b.total_plants)
                                FROM blocks b
                                WHERE b.planting_year_id = ?
                            ), 0)
                        WHERE planting_year_id = ?
                    ");
                    $result = $update_py->execute([$py_id, $py_id, $py_id]);
                    error_log("EDIT BLOCK - NEW planting year update result: " . ($result ? 'SUCCESS' : 'FAILED'));
                    error_log("EDIT BLOCK - Rows affected: " . $update_py->rowCount());
                    
                    // Get division_id and update division totals
                    $div_stmt = $db->prepare("SELECT division_id FROM planting_years WHERE planting_year_id = ?");
                    $div_stmt->execute([$py_id]);
                    $division_id = $div_stmt->fetchColumn();
                    
                    if ($division_id) {
                        $update_div = $db->prepare("
                            UPDATE divisions
                            SET
                                total_area_ha = COALESCE((
                                    SELECT SUM(py.actual_area)
                                    FROM planting_years py
                                    WHERE py.division_id = ?
                                ), 0),
                                total_plants = COALESCE((
                                    SELECT SUM(py.actual_plants)
                                    FROM planting_years py
                                    WHERE py.division_id = ?
                                ), 0),
                                total_blocks = COALESCE((
                                    SELECT COUNT(*)
                                    FROM blocks b
                                    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
                                    WHERE py.division_id = ?
                                ), 0)
                            WHERE division_id = ?
                        ");
                        $update_div->execute([$division_id, $division_id, $division_id, $division_id]);
                        error_log("EDIT BLOCK - Division update for ID: " . $division_id);
                        
                        // Update business_unit and company for new division
                        update_business_unit_and_company_totals($db, $division_id, false);
                    }
                }
                
                // Update old planting year if it changed
                if ($old_block_data && $old_block_data['planting_year_id'] &&
                    $old_block_data['planting_year_id'] != post('planting_year_id')) {
                    $old_py_id = $old_block_data['planting_year_id'];
                    error_log("EDIT BLOCK - Updating OLD planting year ID: " . $old_py_id);
                    $update_old_py = $db->prepare("
                        UPDATE planting_years
                        SET
                            actual_area = COALESCE((
                                SELECT SUM(b.area)
                                FROM blocks b
                                WHERE b.planting_year_id = ?
                            ), 0),
                            actual_plants = COALESCE((
                                SELECT SUM(b.total_plants)
                                FROM blocks b
                                WHERE b.planting_year_id = ?
                            ), 0)
                        WHERE planting_year_id = ?
                    ");
                    $result = $update_old_py->execute([$old_py_id, $old_py_id, $old_py_id]);
                    error_log("EDIT BLOCK - OLD planting year update result: " . ($result ? 'SUCCESS' : 'FAILED'));
                    
                    // Update old division too
                    $old_div_stmt = $db->prepare("SELECT division_id FROM planting_years WHERE planting_year_id = ?");
                    $old_div_stmt->execute([$old_py_id]);
                    $old_division_id = $old_div_stmt->fetchColumn();
                    
                    if ($old_division_id) {
                        $update_old_div = $db->prepare("
                            UPDATE divisions
                            SET
                                total_area_ha = COALESCE((
                                    SELECT SUM(py.actual_area)
                                    FROM planting_years py
                                    WHERE py.division_id = ?
                                ), 0),
                                total_plants = COALESCE((
                                    SELECT SUM(py.actual_plants)
                                    FROM planting_years py
                                    WHERE py.division_id = ?
                                ), 0),
                                total_blocks = COALESCE((
                                    SELECT COUNT(*)
                                    FROM blocks b
                                    INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
                                    WHERE py.division_id = ?
                                ), 0)
                            WHERE division_id = ?
                        ");
                        $update_old_div->execute([$old_division_id, $old_division_id, $old_division_id, $old_division_id]);
                        error_log("EDIT BLOCK - OLD Division update for ID: " . $old_division_id);
                        
                        // Update business_unit and company for old division
                        update_business_unit_and_company_totals($db, $old_division_id, false);
                    }
                }
            } elseif (post('operation_type') == 'Forestry') {
                // For Forestry blocks, update division directly
                $division_id = post('division_id');
                error_log("EDIT BLOCK - Updating Forestry division ID: " . $division_id);
                
                $update_div = $db->prepare("
                    UPDATE divisions
                    SET
                        forestry_area_ha = COALESCE((
                            SELECT SUM(b.area)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0),
                        total_volume_m3 = COALESCE((
                            SELECT SUM(b.volume_m3)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0),
                        total_carbon_stock_ton = COALESCE((
                            SELECT SUM(b.carbon_stock_ton)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0),
                        forestry_blocks = COALESCE((
                            SELECT COUNT(*)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0)
                    WHERE division_id = ?
                ");
                $result = $update_div->execute([$division_id, $division_id, $division_id, $division_id, $division_id]);
                error_log("EDIT BLOCK - Forestry division update result: " . ($result ? 'SUCCESS' : 'FAILED'));
                error_log("EDIT BLOCK - Forestry division rows affected: " . $update_div->rowCount());
                
                // Update parent divisions recursively for Forestry
                update_parent_divisions_forestry($db, $division_id);
                
                // Update business_unit and company for new Forestry division
                update_business_unit_and_company_totals($db, $division_id, true);
                
                // If division changed, update old division too
                if ($old_block_data && isset($old_block_data['division_id']) && $old_block_data['division_id'] != $division_id) {
                    $old_division_id = $old_block_data['division_id'];
                    error_log("EDIT BLOCK - Updating OLD Forestry division ID: " . $old_division_id);
                    
                    $update_old_div = $db->prepare("
                        UPDATE divisions
                        SET
                            forestry_area_ha = COALESCE((
                                SELECT SUM(b.area)
                                FROM blocks b
                                WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                            ), 0),
                            total_volume_m3 = COALESCE((
                                SELECT SUM(b.volume_m3)
                                FROM blocks b
                                WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                            ), 0),
                            total_carbon_stock_ton = COALESCE((
                                SELECT SUM(b.carbon_stock_ton)
                                FROM blocks b
                                WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                            ), 0),
                            forestry_blocks = COALESCE((
                                SELECT COUNT(*)
                                FROM blocks b
                                WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                            ), 0)
                        WHERE division_id = ?
                    ");
                    $update_old_div->execute([$old_division_id, $old_division_id, $old_division_id, $old_division_id, $old_division_id]);
                    error_log("EDIT BLOCK - OLD Forestry division update result: SUCCESS");
                    
                    // Update parent divisions recursively for old Forestry division
                    update_parent_divisions_forestry($db, $old_division_id);
                    
                    // Update business_unit and company for old Forestry division
                    update_business_unit_and_company_totals($db, $old_division_id, true);
                }
            }
            
            // Save dominant variety for Plantation blocks
            if (post('operation_type') == 'Plantation') {
                $bid = (int)post('block_id');
                try {
                    if (post('variety_id')) {
                        // Upsert: delete old then insert new (compatible with MySQL & PgSQL)
                        $del_v = $db->prepare("DELETE FROM block_plant_varieties WHERE block_id = ?");
                        $del_v->execute([$bid]);
                        $ins_v = $db->prepare("
                            INSERT INTO block_plant_varieties (block_id, variety_id, plant_count, percentage)
                            VALUES (?, ?, 0, 100)
                        ");
                        $ins_v->execute([$bid, (int)post('variety_id')]);
                    } else {
                        // Variety cleared — remove existing entry
                        $del_v = $db->prepare("DELETE FROM block_plant_varieties WHERE block_id = ?");
                        $del_v->execute([$bid]);
                    }
                } catch (PDOException $e) { /* ignore if table not yet created */ }
            }

            error_log("EDIT BLOCK - Update successful!");
            set_message('success', 'Block updated successfully!');
            redirect('blocks.php');
        } catch (PDOException $e) {
            error_log("EDIT BLOCK - Error: " . $e->getMessage());
            set_message('error', 'Error updating block: ' . $e->getMessage());
            // Don't redirect on error so user can see the error message
        } catch (Exception $e) {
            error_log("EDIT BLOCK - General Error: " . $e->getMessage());
            set_message('error', 'Error: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            // Get block info before deleting
            $block_stmt = $db->prepare("SELECT planting_year_id, division_id, operation_type FROM blocks WHERE block_id = :id");
            $block_stmt->execute([':id' => post('block_id')]);
            $deleted_block = $block_stmt->fetch();
            
            $stmt = $db->prepare("DELETE FROM blocks WHERE block_id = :id");
            $stmt->execute([':id' => post('block_id')]);
            
            // Update planting year totals for Plantation blocks
            if ($deleted_block && $deleted_block['operation_type'] == 'Plantation' && $deleted_block['planting_year_id']) {
                $py_id = $deleted_block['planting_year_id'];
                $update_py = $db->prepare("
                    UPDATE planting_years
                    SET
                        actual_area = COALESCE((
                            SELECT SUM(b.area)
                            FROM blocks b
                            WHERE b.planting_year_id = ?
                        ), 0),
                        actual_plants = COALESCE((
                            SELECT SUM(b.total_plants)
                            FROM blocks b
                            WHERE b.planting_year_id = ?
                        ), 0)
                    WHERE planting_year_id = ?
                ");
                $update_py->execute([$py_id, $py_id, $py_id]);
                
                // Update division totals
                $div_stmt = $db->prepare("SELECT division_id FROM planting_years WHERE planting_year_id = ?");
                $div_stmt->execute([$py_id]);
                $division_id = $div_stmt->fetchColumn();
                
                if ($division_id) {
                    $update_div = $db->prepare("
                        UPDATE divisions
                        SET
                            total_area_ha = COALESCE((
                                SELECT SUM(py.actual_area)
                                FROM planting_years py
                                WHERE py.division_id = ?
                            ), 0),
                            total_plants = COALESCE((
                                SELECT SUM(py.actual_plants)
                                FROM planting_years py
                                WHERE py.division_id = ?
                            ), 0),
                            total_blocks = COALESCE((
                                SELECT COUNT(*)
                                FROM blocks b
                                INNER JOIN planting_years py ON b.planting_year_id = py.planting_year_id
                                WHERE py.division_id = ?
                            ), 0)
                        WHERE division_id = ?
                    ");
                    $update_div->execute([$division_id, $division_id, $division_id, $division_id]);
                    error_log("DELETE BLOCK - Plantation division update for ID: " . $division_id);
                    
                    // Update business_unit and company for Plantation
                    update_business_unit_and_company_totals($db, $division_id, false);
                }
            }
            // Update division totals for Forestry blocks
            elseif ($deleted_block && $deleted_block['operation_type'] == 'Forestry' && $deleted_block['division_id']) {
                $division_id = $deleted_block['division_id'];
                error_log("DELETE BLOCK - Updating Forestry division ID: " . $division_id);
                
                $update_div = $db->prepare("
                    UPDATE divisions
                    SET
                        forestry_area_ha = COALESCE((
                            SELECT SUM(b.area)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0),
                        total_volume_m3 = COALESCE((
                            SELECT SUM(b.volume_m3)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0),
                        total_carbon_stock_ton = COALESCE((
                            SELECT SUM(b.carbon_stock_ton)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0),
                        forestry_blocks = COALESCE((
                            SELECT COUNT(*)
                            FROM blocks b
                            WHERE b.division_id = ? AND b.operation_type = 'Forestry'
                        ), 0)
                    WHERE division_id = ?
                ");
                $update_div->execute([$division_id, $division_id, $division_id, $division_id, $division_id]);
                error_log("DELETE BLOCK - Forestry division update result: SUCCESS");
                
                // Update parent divisions recursively for Forestry
                update_parent_divisions_forestry($db, $division_id);
                
                // Update business_unit and company for Forestry
                update_business_unit_and_company_totals($db, $division_id, true);
            }
            
            set_message('success', 'Block deleted successfully!');
            redirect('blocks.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting block: ' . $e->getMessage());
        }
    }
}

// Get block for editing (before header)
$edit_block = null;
$edit_block_variety_id = null; // dominant variety for edit form
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM blocks WHERE block_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_block = $stmt->fetch();

    // Fetch existing dominant variety for this block
    try {
        $ev_stmt = $db->prepare("
            SELECT variety_id FROM block_plant_varieties
            WHERE block_id = ?
            ORDER BY plant_count DESC
            LIMIT 1
        ");
        $ev_stmt->execute([$edit_block['block_id']]);
        $edit_block_variety_id = $ev_stmt->fetchColumn() ?: null;
    } catch (PDOException $e) { /* table may not exist yet */ }
}

// Fetch all plant varieties for dropdown
$plant_varieties = [];
try {
    $pv_stmt = $db->query("SELECT variety_id, variety_code, variety_name FROM plant_varieties ORDER BY variety_name");
    $plant_varieties = $pv_stmt->fetchAll();
} catch (PDOException $e) { /* table may not exist yet */ }

// Now include header after form processing
$page_title = "Blocks Management";
require_once 'includes/header.php';

// Determine the user's company scope from session.
// If the logged-in user (any role) has a company_id assigned, scope everything to that company.
// null = no company assigned → sees all companies (true global admin).
$session_company_id = !empty($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : null;

// Fetch companies visible to this user
if ($session_company_id) {
    $companies_stmt = $db->prepare("SELECT company_id, company_name FROM companies WHERE company_id = ? ORDER BY company_name");
    $companies_stmt->execute([$session_company_id]);
} else {
    $companies_stmt = $db->query("SELECT company_id, company_name FROM companies ORDER BY company_name");
}
$companies = $companies_stmt->fetchAll();

// Fetch planting years (scoped to user's company)
$py_sql = "
    SELECT py.planting_year_id, py.year,
           d.division_code, d.division_name,
           bu.unit_code, bu.unit_name,
           c.company_id, c.company_name
    FROM planting_years py
    INNER JOIN divisions d ON py.division_id = d.division_id
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id";
if ($session_company_id) {
    $py_sql .= " WHERE c.company_id = " . (int)$session_company_id;
}
$py_sql .= " ORDER BY py.year DESC, c.company_name, bu.unit_name, d.division_code";
$planting_years = $db->query($py_sql)->fetchAll();

// Fetch divisions (scoped to user's company)
$div_sql = "
    SELECT d.division_id, d.division_code, d.division_name,
           bu.unit_code, bu.unit_name,
           c.company_id, c.company_name
    FROM divisions d
    INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
    INNER JOIN companies c ON bu.company_id = c.company_id";
if ($session_company_id) {
    $div_sql .= " WHERE c.company_id = " . (int)$session_company_id;
}
$div_sql .= " ORDER BY c.company_name, bu.unit_name, d.division_code";
$divisions = $db->query($div_sql)->fetchAll();

// Fetch blocks with statistics
$search = get('search', '');
$company_filter = $session_company_id ?: get('company_id', '');
$planting_year_filter = get('planting_year_id', '');
$status_filter = get('status', '');
$harvest_status_filter = get('harvest_status', '');
$topography_filter = get('topography', '');

$sql = "SELECT b.*,
        py.year as planting_year,
        d.division_code, d.division_name,
        bu.unit_code, bu.unit_name,
        c.company_id, c.company_name, c.company_code
        FROM blocks b
        LEFT JOIN planting_years py ON b.planting_year_id = py.planting_year_id
        INNER JOIN divisions d ON b.division_id = d.division_id
        INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
        INNER JOIN companies c ON bu.company_id = c.company_id
        WHERE 1=1";

if ($search) {
    $sql .= " AND (b.block_code ILIKE :search OR b.block_name ILIKE :search)";
}
if ($company_filter) {
    $sql .= " AND c.company_id = :company_id";
}
if ($planting_year_filter) {
    $sql .= " AND b.planting_year_id = :planting_year_id";
}
if ($status_filter) {
    $sql .= " AND b.status = :status";
}
if ($harvest_status_filter) {
    $sql .= " AND b.harvest_status = :harvest_status";
}
if ($topography_filter) {
    $sql .= " AND b.topography = :topography";
}

$sql .= " ORDER BY py.year DESC, c.company_name, bu.unit_name, d.division_code, b.block_code";

$stmt = $db->prepare($sql);
if ($search) {
    $stmt->bindValue(':search', "%$search%");
}
if ($company_filter) {
    $stmt->bindValue(':company_id', (int)$company_filter, PDO::PARAM_INT);
}
if ($planting_year_filter) {
    $stmt->bindValue(':planting_year_id', $planting_year_filter);
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
if ($harvest_status_filter) {
    $stmt->bindValue(':harvest_status', $harvest_status_filter);
}
if ($topography_filter) {
    $stmt->bindValue(':topography', $topography_filter);
}
$stmt->execute();
$blocks = $stmt->fetchAll();

// Calculate summary statistics
$total_blocks = count($blocks);
$total_area = array_sum(array_column($blocks, 'area'));
$total_plants = array_sum(array_column($blocks, 'total_plants'));
$tm_blocks = count(array_filter($blocks, function($b) { return $b['status'] == 'TM'; }));
$tbm_blocks = count(array_filter($blocks, function($b) { return $b['status'] == 'TBM'; }));
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-grid"></i> Blocks Management</h1>
            <p class="text-muted">Manage individual planting blocks and track their status</p>
        </div>
        <div class="col-auto">
            <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-house"></i> Dashboard
            </a>
            <a href="blocks_map.php" class="btn btn-success me-2">
                <i class="bi bi-map"></i> View Map
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New Block
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-2 g-2">
    <div class="col-md-3">
        <div class="card stat-card" style="border-left-width:3px;">
            <div class="card-body py-2 px-3">
                <h5 class="mb-0 fw-bold" style="color:var(--primary-color);"><?php echo $total_blocks; ?></h5>
                <small class="text-muted"><i class="bi bi-grid"></i> Total Blocks</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" style="border-left-width:3px;">
            <div class="card-body py-2 px-3">
                <h5 class="mb-0 fw-bold" style="color:var(--primary-color);"><?php echo format_number($total_area); ?></h5>
                <small class="text-muted"><i class="bi bi-map"></i> Total Area (Ha)</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" style="border-left-width:3px;">
            <div class="card-body py-2 px-3">
                <h5 class="mb-0 fw-bold" style="color:var(--primary-color);"><?php echo format_number($total_plants, 0); ?></h5>
                <small class="text-muted"><i class="bi bi-tree"></i> Total Plants</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" style="border-left-width:3px;">
            <div class="card-body py-2 px-3">
                <h5 class="mb-0 fw-bold" style="color:var(--primary-color);"><?php echo $tm_blocks; ?> / <?php echo $tbm_blocks; ?></h5>
                <small class="text-muted"><i class="bi bi-pie-chart"></i> TM / TBM Blocks</small>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-<?php echo $session_company_id ? '3' : '2'; ?>">
                <input type="text" class="form-control" name="search" placeholder="Search by code or name..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <?php if (!$session_company_id): ?>
            <div class="col-md-2">
                <select class="form-select" name="company_id">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?php echo $c['company_id']; ?>" <?php echo $company_filter == $c['company_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <select class="form-select" name="planting_year_id">
                    <option value="">All Planting Years</option>
                    <?php foreach ($planting_years as $py): ?>
                        <option value="<?php echo $py['planting_year_id']; ?>" <?php echo $planting_year_filter == $py['planting_year_id'] ? 'selected' : ''; ?>>
                            <?php echo $py['year'] . ' - ' . htmlspecialchars($py['company_name'] . ' - ' . $py['division_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="TBM" <?php echo $status_filter == 'TBM' ? 'selected' : ''; ?>>TBM (Immature)</option>
                    <option value="TM" <?php echo $status_filter == 'TM' ? 'selected' : ''; ?>>TM (Mature)</option>
                    <option value="TR" <?php echo $status_filter == 'TR' ? 'selected' : ''; ?>>TR (Replanting)</option>
                    <option value="Forestry" <?php echo $status_filter == 'Forestry' ? 'selected' : ''; ?>>Forestry</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="harvest_status">
                    <option value="">All Harvest Status</option>
                    <option value="Not Ready" <?php echo $harvest_status_filter == 'Not Ready' ? 'selected' : ''; ?>>Not Ready</option>
                    <option value="Ready" <?php echo $harvest_status_filter == 'Ready' ? 'selected' : ''; ?>>Ready</option>
                    <option value="Harvesting" <?php echo $harvest_status_filter == 'Harvesting' ? 'selected' : ''; ?>>Harvesting</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                <a href="blocks.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Blocks Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Blocks List (<?php echo count($blocks); ?> records)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm" style="font-size:0.82rem;">
                <thead>
                    <tr>
                        <th>Block</th>
                        <th>Location</th>
                        <th class="text-end">Area (Ha)</th>
                        <th class="text-end">Plants</th>
                        <th>Details</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($blocks)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No blocks found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($blocks as $index => $block): ?>
                            <tr class="<?php echo ($index % 2 == 0) ? 'table-row-even' : 'table-row-odd'; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($block['block_code']); ?></strong>
                                    <?php if ($block['block_name'] && $block['block_name'] !== $block['block_code']): ?>
                                        <br><span class="text-muted"><?php echo htmlspecialchars($block['block_name']); ?></span>
                                    <?php endif; ?>
                                    <br>
                                    <?php if ($block['operation_type'] == 'Forestry'): ?>
                                        <span class="badge bg-success" style="font-size:0.7rem;">Forestry</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary" style="font-size:0.7rem;">Plantation</span>
                                    <?php endif; ?>
                                    <?php if ($block['planting_year']): ?>
                                        <span class="badge bg-secondary" style="font-size:0.7rem;"><?php echo $block['planting_year']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-muted"><?php echo htmlspecialchars($block['unit_code']); ?></span>
                                    / <?php echo htmlspecialchars($block['division_name']); ?>
                                </td>
                                <td class="text-end"><?php echo format_number($block['area']); ?></td>
                                <td class="text-end"><?php echo $block['total_plants'] ? format_number($block['total_plants'], 0) : '<span class="text-muted">-</span>'; ?></td>
                                <td>
                                    <small class="text-muted">
                                        <?php if ($block['plant_age']): ?>Age: <?php echo $block['plant_age']; ?>y &nbsp;<?php endif; ?>
                                        <?php if ($block['plant_density']): ?>D: <?php echo $block['plant_density']; ?>/ha &nbsp;<?php endif; ?>
                                        <?php if ($block['topography']): ?><?php echo $block['topography']; ?><?php endif; ?>
                                        <?php if ($block['soil_type']): ?><br><?php echo htmlspecialchars($block['soil_type']); ?><?php if ($block['soil_ph']): ?> pH<?php echo $block['soil_ph']; ?><?php endif; ?><?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <?php echo get_status_badge($block['status']); ?>
                                    <br><?php echo get_status_badge($block['harvest_status']); ?>
                                </td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $block['block_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="blocks.php" style="display:inline;" onsubmit="return confirmDelete('Delete this block?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="block_id" value="<?php echo $block['block_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="blocks.php" id="blockForm">
                <div class="modal-header py-2">
                    <h6 class="modal-title mb-0">
                        <i class="bi bi-grid"></i> <?php echo $edit_block ? 'Edit Block' : 'Add New Block'; ?>
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-2 px-3" style="font-size:0.875rem;">
                    <input type="hidden" name="action" value="<?php echo $edit_block ? 'edit' : 'add'; ?>">
                    <?php if ($edit_block): ?>
                        <input type="hidden" name="block_id" value="<?php echo $edit_block['block_id']; ?>">
                    <?php endif; ?>

                    <!-- Row 1: Type / Planting Year / Company+Division -->
                    <div class="row g-2 mb-1 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label mb-1">Type <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" name="operation_type" id="operation_type" required>
                                <option value="Plantation" <?php echo ($edit_block && $edit_block['operation_type'] == 'Plantation') ? 'selected' : ''; ?>>Plantation</option>
                                <option value="Forestry"   <?php echo ($edit_block && $edit_block['operation_type'] == 'Forestry')   ? 'selected' : ''; ?>>Forestry</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Planting Year</label>
                            <select class="form-select form-select-sm" name="planting_year_id" id="planting_year_id">
                                <option value="">— Select —</option>
                                <?php foreach ($planting_years as $py): ?>
                                    <option value="<?php echo $py['planting_year_id']; ?>"
                                        data-company-id="<?php echo $py['company_id']; ?>"
                                        <?php echo ($edit_block && $edit_block['planting_year_id'] == $py['planting_year_id']) ? 'selected' : ''; ?>>
                                        <?php echo $py['year'] . ' · ' . htmlspecialchars($py['unit_name'] . ' · ' . $py['division_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if (!$session_company_id): ?>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Company</label>
                            <select class="form-select form-select-sm" id="modal_company_id">
                                <option value="">All Companies</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?php echo $c['company_id']; ?>"
                                        <?php
                                        if ($edit_block) {
                                            foreach ($divisions as $div) {
                                                if ($div['division_id'] == $edit_block['division_id']) {
                                                    echo $div['company_id'] == $c['company_id'] ? 'selected' : '';
                                                    break;
                                                }
                                            }
                                        }
                                        ?>>
                                        <?php echo htmlspecialchars($c['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                        <?php else: ?>
                        <input type="hidden" id="modal_company_id" value="<?php echo (int)$session_company_id; ?>">
                        <div class="col-md-7">
                        <?php endif; ?>
                            <label class="form-label mb-1">Division <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" name="division_id" id="modal_division_id" required>
                                <option value="">— Select Division —</option>
                                <?php foreach ($divisions as $div): ?>
                                    <option value="<?php echo $div['division_id']; ?>"
                                        data-company-id="<?php echo $div['company_id']; ?>"
                                        <?php echo ($edit_block && $edit_block['division_id'] == $div['division_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($div['unit_name'] . ' · ' . $div['division_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Row 2: Code / Name -->
                    <div class="row g-2 mb-1">
                        <div class="col-md-3">
                            <label class="form-label mb-1">Block Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="block_code" required
                                   value="<?php echo $edit_block ? htmlspecialchars($edit_block['block_code']) : ''; ?>"
                                   placeholder="e.g., BLK-01">
                        </div>
                        <div class="col-md-9">
                            <label class="form-label mb-1">Block Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="block_name" required
                                   value="<?php echo $edit_block ? htmlspecialchars($edit_block['block_name']) : ''; ?>"
                                   placeholder="e.g., Block 01">
                        </div>
                    </div>

                    <hr class="my-2">
                    <p class="text-muted mb-1" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">Area &amp; Planting</p>

                    <!-- Row 3: Area / Planted Area / Planting Date / Plant Age -->
                    <div class="row g-2 mb-1">
                        <div class="col-md-2">
                            <label class="form-label mb-1">Area (Ha) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control form-control-sm" name="area" required
                                   value="<?php echo $edit_block ? $edit_block['area'] : ''; ?>" placeholder="0.00">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Planted (Ha)</label>
                            <input type="number" step="0.01" class="form-control form-control-sm" name="planted_area"
                                   value="<?php echo $edit_block ? $edit_block['planted_area'] : ''; ?>" placeholder="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Planting Date</label>
                            <input type="date" class="form-control form-control-sm" name="planting_date"
                                   value="<?php echo $edit_block ? $edit_block['planting_date'] : ''; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Age (yrs)</label>
                            <input type="number" class="form-control form-control-sm" name="plant_age" readonly
                                   value="<?php echo $edit_block ? $edit_block['plant_age'] : '0'; ?>"
                                   style="background-color:#e9ecef;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Status / Harvest</label>
                            <div class="input-group input-group-sm">
                                <select class="form-select form-select-sm" name="status">
                                    <option value="TBM"       <?php echo ($edit_block && $edit_block['status'] == 'TBM')       ? 'selected' : ''; ?>>TBM</option>
                                    <option value="TM"        <?php echo ($edit_block && $edit_block['status'] == 'TM')        ? 'selected' : ''; ?>>TM</option>
                                    <option value="TR"        <?php echo ($edit_block && $edit_block['status'] == 'TR')        ? 'selected' : ''; ?>>TR</option>
                                    <option value="Forestry"<?php echo ($edit_block && $edit_block['status'] == 'Forestry')? 'selected' : ''; ?>>Forestry</option>
                                </select>
                                <select class="form-select form-select-sm" name="harvest_status">
                                    <option value="Not Ready" <?php echo ($edit_block && $edit_block['harvest_status'] == 'Not Ready') ? 'selected' : ''; ?>>Not Ready</option>
                                    <option value="Ready"     <?php echo ($edit_block && $edit_block['harvest_status'] == 'Ready')     ? 'selected' : ''; ?>>Ready</option>
                                    <option value="Harvesting"<?php echo ($edit_block && $edit_block['harvest_status'] == 'Harvesting')? 'selected' : ''; ?>>Harvesting</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Variety (Plantation only) -->
                    <?php if (!empty($plant_varieties)): ?>
                    <div class="row g-2 mb-1" id="varietyRow">
                        <div class="col-md-4">
                            <label class="form-label mb-1">Dominant Variety</label>
                            <select class="form-select form-select-sm" name="variety_id">
                                <option value="">— Select Variety —</option>
                                <?php foreach ($plant_varieties as $pv): ?>
                                <option value="<?php echo $pv['variety_id']; ?>"
                                    <?php echo ($edit_block_variety_id == $pv['variety_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pv['variety_code'] . ' – ' . $pv['variety_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Row 4: Normal / Abnormal / Dead / Total / Density -->
                    <div class="row g-2 mb-1">
                        <div class="col-md-2">
                            <label class="form-label mb-1">Normal Plants</label>
                            <input type="number" class="form-control form-control-sm" name="normal_plants"
                                   value="<?php echo $edit_block ? $edit_block['normal_plants'] : '0'; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Abnormal</label>
                            <input type="number" class="form-control form-control-sm" name="abnormal_plants"
                                   value="<?php echo $edit_block ? $edit_block['abnormal_plants'] : '0'; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Dead</label>
                            <input type="number" class="form-control form-control-sm" name="dead_plants"
                                   value="<?php echo $edit_block ? $edit_block['dead_plants'] : '0'; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Total Plants</label>
                            <input type="number" class="form-control form-control-sm" name="total_plants" readonly
                                   value="<?php echo $edit_block ? $edit_block['total_plants'] : '0'; ?>"
                                   style="background-color:#e9ecef;">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Density/Ha</label>
                            <input type="number" class="form-control form-control-sm" name="plant_density" readonly
                                   value="<?php echo $edit_block ? $edit_block['plant_density'] : '0'; ?>"
                                   style="background-color:#e9ecef;">
                        </div>
                    </div>

                    <hr class="my-2">
                    <p class="text-muted mb-1" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">Land &amp; Forestry</p>

                    <!-- Row 5: Topography / Soil Type / Soil pH / Elevation -->
                    <div class="row g-2 mb-1">
                        <div class="col-md-2">
                            <label class="form-label mb-1">Topography</label>
                            <select class="form-select form-select-sm" name="topography">
                                <option value="Flat"       <?php echo ($edit_block && $edit_block['topography'] == 'Flat')       ? 'selected' : ''; ?>>Flat</option>
                                <option value="Undulating" <?php echo ($edit_block && $edit_block['topography'] == 'Undulating') ? 'selected' : ''; ?>>Undulating</option>
                                <option value="Hilly"      <?php echo ($edit_block && $edit_block['topography'] == 'Hilly')      ? 'selected' : ''; ?>>Hilly</option>
                                <option value="Steep"      <?php echo ($edit_block && $edit_block['topography'] == 'Steep')      ? 'selected' : ''; ?>>Steep</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Soil Type</label>
                            <input type="text" class="form-control form-control-sm" name="soil_type"
                                   value="<?php echo $edit_block ? htmlspecialchars($edit_block['soil_type']) : ''; ?>"
                                   placeholder="e.g., Peat, Mineral">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Soil pH</label>
                            <input type="number" step="0.1" class="form-control form-control-sm" name="soil_ph"
                                   value="<?php echo $edit_block ? $edit_block['soil_ph'] : ''; ?>" placeholder="5.5">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Elevation (m)</label>
                            <input type="number" class="form-control form-control-sm" name="elevation_m"
                                   value="<?php echo $edit_block ? $edit_block['elevation_m'] : ''; ?>">
                        </div>
                    </div>

                    <!-- Row 6: Tree Species / Stand Density / DBH / Volume / Carbon / Age Class / Est. Year / Forest Type -->
                    <div class="row g-2 mb-1">
                        <div class="col-md-3">
                            <label class="form-label mb-1">Tree Species</label>
                            <input type="text" class="form-control form-control-sm" name="tree_species"
                                   value="<?php echo $edit_block ? htmlspecialchars($edit_block['tree_species']) : ''; ?>"
                                   placeholder="e.g., Teak, Pine">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Stand Density</label>
                            <input type="number" class="form-control form-control-sm" name="stand_density"
                                   value="<?php echo $edit_block ? $edit_block['stand_density'] : ''; ?>" placeholder="trees/ha">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Avg DBH (cm)</label>
                            <input type="number" step="0.01" class="form-control form-control-sm" name="average_dbh"
                                   value="<?php echo $edit_block ? $edit_block['average_dbh'] : ''; ?>" placeholder="35.5">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Volume (m³)</label>
                            <input type="number" step="0.01" class="form-control form-control-sm" name="volume_m3"
                                   value="<?php echo $edit_block ? $edit_block['volume_m3'] : ''; ?>" placeholder="850.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Carbon Stock (tons)</label>
                            <input type="number" step="0.01" class="form-control form-control-sm" name="carbon_stock_ton"
                                   value="<?php echo $edit_block ? $edit_block['carbon_stock_ton'] : ''; ?>" placeholder="425.00">
                        </div>
                    </div>

                    <div class="row g-2 mb-1">
                        <div class="col-md-3">
                            <label class="form-label mb-1">Age Class</label>
                            <select class="form-select form-select-sm" name="age_class">
                                <option value="">— Select —</option>
                                <option value="Young"  <?php echo ($edit_block && $edit_block['age_class'] == 'Young')  ? 'selected' : ''; ?>>Young (0–10 yrs)</option>
                                <option value="Middle" <?php echo ($edit_block && $edit_block['age_class'] == 'Middle') ? 'selected' : ''; ?>>Middle (11–20 yrs)</option>
                                <option value="Mature" <?php echo ($edit_block && $edit_block['age_class'] == 'Mature') ? 'selected' : ''; ?>>Mature (21–40 yrs)</option>
                                <option value="Old"    <?php echo ($edit_block && $edit_block['age_class'] == 'Old')    ? 'selected' : ''; ?>>Old (40+ yrs)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Est. Year</label>
                            <input type="number" class="form-control form-control-sm" name="establishment_year"
                                   min="1900" max="<?php echo date('Y'); ?>"
                                   value="<?php echo $edit_block ? $edit_block['establishment_year'] : ''; ?>" placeholder="1995">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Forest Type</label>
                            <select class="form-select form-select-sm" name="forest_type">
                                <option value="">— Select —</option>
                                <option value="Production"   <?php echo ($edit_block && $edit_block['forest_type'] == 'Production')   ? 'selected' : ''; ?>>Production</option>
                                <option value="Protection"   <?php echo ($edit_block && $edit_block['forest_type'] == 'Protection')   ? 'selected' : ''; ?>>Protection</option>
                                <option value="Conservation" <?php echo ($edit_block && $edit_block['forest_type'] == 'Conservation') ? 'selected' : ''; ?>>Conservation</option>
                                <option value="Mixed"        <?php echo ($edit_block && $edit_block['forest_type'] == 'Mixed')        ? 'selected' : ''; ?>>Mixed</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Latitude</label>
                            <input type="number" step="0.00000001" class="form-control form-control-sm" name="latitude"
                                   value="<?php echo $edit_block ? $edit_block['latitude'] : ''; ?>" placeholder="-0.123456">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1">Longitude</label>
                            <input type="number" step="0.00000001" class="form-control form-control-sm" name="longitude"
                                   value="<?php echo $edit_block ? $edit_block['longitude'] : ''; ?>" placeholder="101.12345">
                        </div>
                    </div>

                    <!-- Notes (GeoJSON collapsed into details) -->
                    <div class="row g-2 mb-1">
                        <div class="col-md-12">
                            <label class="form-label mb-1">Notes</label>
                            <textarea class="form-control form-control-sm" name="notes" rows="2"><?php echo $edit_block ? htmlspecialchars($edit_block['notes']) : ''; ?></textarea>
                        </div>
                    </div>
                    <details class="mt-1">
                        <summary class="text-muted" style="font-size:0.8rem;cursor:pointer;">GeoJSON (optional, for mapping)</summary>
                        <textarea class="form-control form-control-sm mt-1" name="geojson" rows="2"><?php echo $edit_block ? htmlspecialchars($edit_block['geojson']) : ''; ?></textarea>
                    </details>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_block ? 'Update Block' : 'Save Block'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Confirm delete
function confirmDelete(message) {
    return confirm(message);
}

// Function to setup auto-calculation
function setupCalculations() {
    const areaInput = document.querySelector('input[name="area"]');
    const densityInput = document.querySelector('input[name="plant_density"]');
    const normalPlantsInput = document.querySelector('input[name="normal_plants"]');
    const abnormalPlantsInput = document.querySelector('input[name="abnormal_plants"]');
    const totalPlantsInput = document.querySelector('input[name="total_plants"]');
    
    function calculateTotalAndDensity() {
        if (!areaInput || !densityInput || !normalPlantsInput || !abnormalPlantsInput || !totalPlantsInput) {
            return;
        }
        
        // Calculate total plants = normal + abnormal (dead plants not included)
        const normalPlants = parseInt(normalPlantsInput.value) || 0;
        const abnormalPlants = parseInt(abnormalPlantsInput.value) || 0;
        const totalPlants = normalPlants + abnormalPlants;
        totalPlantsInput.value = totalPlants;
        
        // Calculate density = total plants / area
        const area = parseFloat(areaInput.value) || 0;
        if (area > 0 && totalPlants > 0) {
            const density = Math.round(totalPlants / area);
            densityInput.value = density;
        } else {
            densityInput.value = 0;
        }
    }
    
    if (areaInput && densityInput && normalPlantsInput && abnormalPlantsInput && totalPlantsInput) {
        // Remove existing listeners to avoid duplicates
        areaInput.removeEventListener('input', calculateTotalAndDensity);
        normalPlantsInput.removeEventListener('input', calculateTotalAndDensity);
        abnormalPlantsInput.removeEventListener('input', calculateTotalAndDensity);
        
        // Add new listeners
        areaInput.addEventListener('input', calculateTotalAndDensity);
        normalPlantsInput.addEventListener('input', calculateTotalAndDensity);
        abnormalPlantsInput.addEventListener('input', calculateTotalAndDensity);
        
        // Calculate immediately
        calculateTotalAndDensity();
    }
}

// ── Company-based filtering for modal dropdowns ──────────────────────────────
function filterModalByCompany(companyId) {
    const divSelect   = document.getElementById('modal_division_id');
    const pySelect    = document.getElementById('planting_year_id');
    const prevDiv     = divSelect.value;
    const prevPy      = pySelect.value;

    // Filter Division options
    Array.from(divSelect.options).forEach(opt => {
        if (!opt.value) return; // keep placeholder
        opt.hidden = companyId && opt.dataset.companyId != companyId;
    });
    // Reset selected if hidden
    if (divSelect.selectedOptions[0]?.hidden) divSelect.value = '';
    else divSelect.value = prevDiv;

    // Filter Planting Year options
    Array.from(pySelect.options).forEach(opt => {
        if (!opt.value) return; // keep placeholder
        opt.hidden = companyId && opt.dataset.companyId != companyId;
    });
    if (pySelect.selectedOptions[0]?.hidden) pySelect.value = '';
    else pySelect.value = prevPy;
}

document.getElementById('modal_company_id')?.addEventListener('change', function() {
    filterModalByCompany(this.value);
});

// Apply company filter on modal open (pre-selects company for edit, or resets for add)
document.getElementById('addModal')?.addEventListener('shown.bs.modal', function() {
    const companyId = document.getElementById('modal_company_id').value;
    filterModalByCompany(companyId);
    setupCalculations();
});

// Setup on page load
document.addEventListener('DOMContentLoaded', function() {
    setupCalculations();
    // Apply filter immediately if a company is pre-selected (edit mode)
    const companyId = document.getElementById('modal_company_id')?.value;
    if (companyId) filterModalByCompany(companyId);
});
</script>

<?php if ($edit_block): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>