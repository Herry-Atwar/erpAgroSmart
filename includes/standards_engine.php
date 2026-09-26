<?php
/**
 * AgroSmart Standards Engine — erpAgroSmart (Supabase / PostgreSQL)
 *
 * Ported from agro/includes/standards_engine.php (MySQL version).
 * All logic is pure PHP — no SQL executed in this file — so the port
 * requires no PostgreSQL-specific changes.
 *
 * Integrates the AgroSmart Standards Library (config/standards.php) so that
 * any module can:
 *
 *   1. Identify which standard(s) apply to a given entity + metric context
 *   2. Compare an actual measured value against that standard
 *   3. Produce a structured response that CLEARLY separates:
 *        (a) actual_data    — what the system recorded
 *        (b) standard       — the official benchmark (GAPKI/PPKS/SNI/RSPO/ISPO)
 *        (c) gap_analysis   — difference, status (pass/warn/fail), magnitude
 *        (d) interpretation — factual reading of the gap
 *        (e) recommendation — action advice derived ONLY from documented standards
 *
 * INVARIANTS (enforced by design):
 *   • NEVER returns a standard that does not exist in AGRO_STANDARDS or the
 *     custom standards table (agro_std_all() / agro_std_get()).
 *   • NEVER presents an AI-generated value as a standard.
 *   • Recommendations are generated ONLY from the standard's own fail_note /
 *     warn_note fields.
 *   • Every output carries a `source` and `source_year` citation.
 *
 * ── Public API ────────────────────────────────────────────────────────────────
 *   agro_std_exists(string $id)                    → bool
 *   agro_std_identify(array $semantic)             → string[]
 *   agro_std_extract_actuals(array $qna_result)    → array
 *   agro_std_run_checks(array $actuals)            → array[]
 *   agro_std_build_response(array $semantic, array $qna_result) → array
 *   agro_std_format_label(string $status)          → string
 *   agro_std_attention_needed(array $report)       → bool
 *   agro_std_one_liner(array $response)            → string
 *   agro_std_has_checkable_data(array, array)      → bool
 */

if (!function_exists('agro_std_check')) {
    require_once __DIR__ . '/../config/standards.php';
}

// ─────────────────────────────────────────────────────────────────────────────
// Standard existence guard
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns true when $id exists in the authoritative standards table
 * (built-in AGRO_STANDARDS OR custom standards from the DB).
 */
function agro_std_exists(string $id): bool
{
    return agro_std_get($id) !== null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 1 — Standard identification
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Maps an entity + metric context to a list of applicable standard IDs.
 *
 * @param  array $semantic  Resolved semantic request
 * @return string[]         Applicable standard IDs (all guaranteed to exist)
 */
function agro_std_identify(array $semantic): array
{
    $entity  = $semantic['entity']  ?? '';
    $metrics = $semantic['metrics'] ?? [];
    $norm    = mb_strtolower($semantic['question'] ?? '');

    $explicit = $semantic['standard']['standard_ids'] ?? [];

    static $entityDefaults = [
        // Block / plantation-wide
        'block'                  => ['sph_aktual', 'tm_ratio', 'block_size', 'planted_ratio',
                                     'normal_plant_ratio', 'dead_plant_ratio', 'abnormal_plant_ratio',
                                     'sisip_ratio'],
        'planting_year'          => ['sph_aktual', 'tm_ratio'],
        'division'               => ['tm_ratio', 'division_size', 'planted_ratio'],
        'business_unit'          => ['tm_ratio', 'planted_ratio', 'conservation_ratio', 'hcv_buffer'],
        // Harvesting
        'harvest_realization'    => ['yield_per_ha_tm', 'abw_mature', 'losses_ratio', 'harvest_interval'],
        'harvest_plan'           => ['yield_per_ha_tm', 'harvest_interval'],
        'harvest_productivity'   => ['yield_per_ha_tm', 'losses_ratio'],
        // Nursery
        'nursery_batch'          => ['nursery_germination_rate', 'nursery_pre_nursery_survival',
                                     'nursery_main_nursery_survival', 'nursery_abnormal_seedling',
                                     'nursery_seedling_height_9mo', 'nursery_leaf_count_9mo',
                                     'nursery_polybag_density', 'disease_crown_disease_pct'],
        // Mill
        'cpo_production'         => ['oer_target', 'mill_losses_oil', 'mill_capacity_util'],
        'kernel_production'      => ['ker_target'],
        'cpo_quality_test'       => ['cpo_ffa', 'cpo_moisture'],
        'mill_processing_batch'  => ['oer_target', 'ker_target', 'mill_losses_oil'],
        'mill_daily_performance' => ['oer_target', 'ker_target', 'mill_capacity_util', 'cpo_ffa'],
        // Field ops
        'fertilization_record'   => ['fert_realization_rate', 'fert_timing_rounds',
                                     'fert_n_tm_dose', 'fert_p_tm_dose', 'fert_k_tm_dose', 'fert_mg_tm_dose'],
        'pest_control_record'    => ['pest_rat_attack_pct', 'pest_nettle_caterpillar_pct',
                                     'pest_oryctes_attack_pct', 'pest_bagworm_attack_pct',
                                     'disease_ganoderma_pct', 'disease_crown_disease_pct',
                                     'pest_monitoring_frequency', 'pest_natural_enemy_ratio',
                                     'disease_spear_rot_pct', 'disease_bud_rot_pct',
                                     'pest_barn_owl_coverage', 'weed_circle_clean_pct',
                                     'weed_gawangan_clean_pct', 'weed_rotation_days'],
        // Finance
        'journal_entry'          => ['cost_per_kg_ffb', 'cost_per_ha_maintenance'],
        'budget'                 => ['cost_per_kg_ffb', 'cost_per_ha_maintenance'],
        // Sustainability
        'company'                => ['conservation_ratio', 'hcv_buffer'],
        // Inventory — no SNI standard
        'storage_tank'           => [],
        'material'               => [],
    ];

    $candidates = $entityDefaults[$entity] ?? [];

    static $metricToStd = [
        'oer'                     => ['oer_target'],
        'ker'                     => ['ker_target'],
        'cpo_ffa_pct'             => ['cpo_ffa'],
        'cpo_moisture_pct'        => ['cpo_moisture'],
        'abw'                     => ['abw_mature'],
        'ffb_yield_per_ha'        => ['yield_per_ha_tm'],
        'harvest_losses_pct'      => ['losses_ratio'],
        'harvest_achievement_pct' => [],
        'sph'                     => ['sph_aktual'],
        'tm_ratio'                => ['tm_ratio'],
        'fert_realization_pct'    => ['fert_realization_rate', 'fert_timing_rounds'],
        'nursery_germination_rate'=> ['nursery_germination_rate'],
        'nursery_survival_rate'   => ['nursery_pre_nursery_survival', 'nursery_main_nursery_survival'],
        'cost_per_kg_ffb'         => ['cost_per_kg_ffb'],
        'cost_per_ha_maintenance' => ['cost_per_ha_maintenance'],
        'mill_utilization_pct'    => ['mill_capacity_util'],
        'metric_area_total'       => ['tm_ratio', 'planted_ratio'],
        'metric_area_tm'          => ['tm_ratio'],
    ];

    foreach ($metrics as $metric) {
        foreach ($metricToStd[$metric] ?? [] as $sid) {
            if (!in_array($sid, $candidates)) $candidates[] = $sid;
        }
    }

    static $keywordToStd = [
        'ganoderma'     => ['disease_ganoderma_pct'],
        'bsr'           => ['disease_ganoderma_pct'],
        'rat'           => ['pest_rat_attack_pct'],
        'tikus'         => ['pest_rat_attack_pct', 'pest_barn_owl_coverage'],
        'ulat api'      => ['pest_nettle_caterpillar_pct'],
        'caterpillar'   => ['pest_nettle_caterpillar_pct'],
        'ulat kantong'  => ['pest_bagworm_attack_pct'],
        'bagworm'       => ['pest_bagworm_attack_pct'],
        'oryctes'       => ['pest_oryctes_attack_pct', 'pest_rhinoceros_beetle_damage'],
        'kumbang'       => ['pest_oryctes_attack_pct'],
        'piringan'      => ['weed_circle_clean_pct'],
        'gawangan'      => ['weed_gawangan_clean_pct'],
        'gulma'         => ['weed_circle_clean_pct', 'weed_gawangan_clean_pct', 'weed_rotation_days'],
        'weed'          => ['weed_circle_clean_pct', 'weed_gawangan_clean_pct'],
        'roundup'       => ['weed_herbicide_dose_roundup'],
        'glifosat'      => ['weed_herbicide_dose_roundup'],
        'rotasi panen'  => ['harvest_interval'],
        'harvest interval' => ['harvest_interval'],
        'konservasi'    => ['conservation_ratio'],
        'sempadan'      => ['hcv_buffer'],
        'riparian'      => ['hcv_buffer'],
        'buffer'        => ['hcv_buffer'],
        'crown disease' => ['disease_crown_disease_pct'],
        'busuk tandan'  => ['disease_spear_rot_pct'],
        'spear rot'     => ['disease_spear_rot_pct'],
        'busuk pucuk'   => ['disease_bud_rot_pct'],
        'bud rot'       => ['disease_bud_rot_pct'],
        'barn owl'      => ['pest_barn_owl_coverage'],
        'burung hantu'  => ['pest_barn_owl_coverage'],
        'tyto'          => ['pest_barn_owl_coverage'],
        'monitoring opt'=> ['pest_monitoring_frequency'],
        'sensus'        => ['pest_monitoring_frequency'],
        'jalan produksi'=> ['road_prod_per_ha', 'road_width_prod', 'road_prod_main_ratio'],
        'jalan poros'   => ['road_main_per_ha', 'road_width_main'],
        'jembatan'      => ['bridge_per_km_road'],
        'ffa'           => ['cpo_ffa'],
        'moisture'      => ['cpo_moisture'],
        'kadar air'     => ['cpo_moisture'],
        'kecambah'      => ['nursery_germination_rate'],
        'germination'   => ['nursery_germination_rate'],
        'survival'      => ['nursery_pre_nursery_survival', 'nursery_main_nursery_survival'],
        'daya hidup'    => ['nursery_pre_nursery_survival', 'nursery_main_nursery_survival'],
        'polybag'       => ['nursery_polybag_density'],
        'tinggi bibit'  => ['nursery_seedling_height_9mo'],
        'seedling height'=> ['nursery_seedling_height_9mo'],
        'daun bibit'    => ['nursery_leaf_count_9mo'],
        'leaf count'    => ['nursery_leaf_count_9mo'],
        'afkir'         => ['nursery_abnormal_seedling'],
        'urea'          => ['fert_n_tm_dose'],
        'tsp'           => ['fert_p_tm_dose'],
        'mop'           => ['fert_k_tm_dose'],
        'kieserit'      => ['fert_mg_tm_dose'],
        'nitrogen'      => ['fert_n_tm_dose'],
        'kalium'        => ['fert_k_tm_dose'],
        'fosfor'        => ['fert_p_tm_dose'],
        'magnesium'     => ['fert_mg_tm_dose'],
        'oer'           => ['oer_target'],
        'ker'           => ['ker_target'],
        'rendemen'      => ['oer_target', 'ker_target'],
    ];

    foreach ($keywordToStd as $kw => $stds) {
        if (mb_strpos($norm, $kw) !== false) {
            foreach ($stds as $sid) {
                if (!in_array($sid, $candidates)) $candidates[] = $sid;
            }
        }
    }

    foreach ($explicit as $sid) {
        if (agro_std_exists($sid) && !in_array($sid, $candidates)) {
            $candidates[] = $sid;
        }
    }

    return array_values(array_filter($candidates, 'agro_std_exists'));
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 2 — Extract actual metric values from a data result array
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Maps result-array fields to their corresponding standard IDs.
 * Only extracts values that are directly measurable against a defined standard.
 *
 * Returns: [ standard_id => float_value, ... ]
 *
 * RULE: Missing/null values are NOT included — never fabricate a value.
 *
 * @param  array $qnaResult  Data result array (from any module, not just Q&A)
 * @return array<string,float>
 */
function agro_std_extract_actuals(array $qnaResult): array
{
    $actuals = [];

    $add = static function (string $stdId, mixed $raw) use (&$actuals): void {
        if ($raw === null || $raw === '' || $raw === false) return;
        $v = (float)$raw;
        if (!is_finite($v)) return;
        $actuals[$stdId] = $v;
    };

    // ── Mill ─────────────────────────────────────────────────────────────────
    $add('oer_target',        $qnaResult['oer']                ?? $qnaResult['oil_extraction_rate']    ?? null);
    $add('ker_target',        $qnaResult['ker']                ?? $qnaResult['kernel_extraction_rate'] ?? null);
    $add('cpo_ffa',           $qnaResult['ffa']                ?? $qnaResult['ffa_percentage']         ?? null);
    $add('cpo_moisture',      $qnaResult['moisture']           ?? $qnaResult['moisture_content_pct']   ?? null);
    $add('mill_capacity_util',$qnaResult['capacity_util_pct']  ?? $qnaResult['utilization_pct']        ?? null);
    $add('mill_losses_oil',   $qnaResult['oil_losses_pct']     ?? null);

    // ── Harvesting ───────────────────────────────────────────────────────────
    $add('abw_mature',        $qnaResult['abw']                ?? $qnaResult['average_bunch_weight']   ?? null);
    $add('losses_ratio',      $qnaResult['losses_pct']         ?? $qnaResult['harvest_losses_pct']     ?? null);
    $add('harvest_interval',  $qnaResult['harvest_interval_days'] ?? null);

    if (!empty($qnaResult['total_kg']) && !empty($qnaResult['tm_area'])) {
        $tmArea = (float)$qnaResult['tm_area'];
        if ($tmArea > 0) {
            $add('yield_per_ha_tm', ((float)$qnaResult['total_kg'] / 1000) / $tmArea);
        }
    }
    $add('yield_per_ha_tm',   $qnaResult['yield_per_ha']       ?? $qnaResult['ton_per_ha']             ?? null);

    // ── Nursery ──────────────────────────────────────────────────────────────
    $add('nursery_germination_rate',       $qnaResult['germination_rate']      ?? $qnaResult['daya_kecambah']   ?? null);
    $add('nursery_pre_nursery_survival',   $qnaResult['pre_nursery_survival']  ?? null);
    $add('nursery_main_nursery_survival',  $qnaResult['main_nursery_survival'] ?? $qnaResult['survival_rate']   ?? null);
    $add('nursery_abnormal_seedling',      $qnaResult['abnormal_pct']          ?? $qnaResult['afkir_pct']       ?? null);
    $add('nursery_seedling_height_9mo',    $qnaResult['avg_height_cm']         ?? null);
    $add('nursery_leaf_count_9mo',         $qnaResult['avg_leaf_count']        ?? null);
    $add('nursery_polybag_density',        $qnaResult['polybag_density']       ?? null);
    $add('disease_crown_disease_pct',      $qnaResult['crown_disease_pct']     ?? null);

    // ── Plantation ───────────────────────────────────────────────────────────
    $add('sph_aktual',          $qnaResult['sph']               ?? $qnaResult['stand_per_ha']           ?? null);
    $add('tm_ratio',            $qnaResult['tm_ratio']          ?? $qnaResult['tm_pct']                 ?? null);
    $add('planted_ratio',       $qnaResult['planted_ratio_pct'] ?? null);
    $add('block_size',          $qnaResult['avg_block_size']    ?? null);
    $add('division_size',       $qnaResult['avg_division_size'] ?? null);
    $add('normal_plant_ratio',  $qnaResult['normal_pct']        ?? null);
    $add('dead_plant_ratio',    $qnaResult['dead_pct']          ?? null);
    $add('abnormal_plant_ratio',$qnaResult['abnormal_plant_pct']?? null);
    $add('sisip_ratio',         $qnaResult['sisip_pct']         ?? null);

    // ── Fertilization ────────────────────────────────────────────────────────
    $add('fert_realization_rate', $qnaResult['realization_pct']         ?? $qnaResult['fert_realization_pct'] ?? null);
    $add('fert_timing_rounds',    $qnaResult['rounds_per_year']         ?? $qnaResult['fert_rounds']          ?? null);
    $add('fert_n_tm_dose',        $qnaResult['urea_dose_kg_per_tree']   ?? $qnaResult['n_dose']               ?? null);
    $add('fert_p_tm_dose',        $qnaResult['tsp_dose_kg_per_tree']    ?? $qnaResult['p_dose']               ?? null);
    $add('fert_k_tm_dose',        $qnaResult['mop_dose_kg_per_tree']    ?? $qnaResult['k_dose']               ?? null);
    $add('fert_mg_tm_dose',       $qnaResult['kieserit_dose_kg_per_tree']?? $qnaResult['mg_dose']             ?? null);

    // ── Pest & Disease ───────────────────────────────────────────────────────
    $add('pest_rat_attack_pct',           $qnaResult['rat_attack_pct']         ?? null);
    $add('pest_nettle_caterpillar_pct',   $qnaResult['caterpillar_pct']        ?? $qnaResult['nettle_caterpillar_pct'] ?? null);
    $add('pest_bagworm_attack_pct',       $qnaResult['bagworm_pct']            ?? null);
    $add('pest_oryctes_attack_pct',       $qnaResult['oryctes_pct']            ?? null);
    $add('pest_rhinoceros_beetle_damage', $qnaResult['rhinoceros_damage_pct']  ?? null);
    $add('disease_ganoderma_pct',         $qnaResult['ganoderma_pct']          ?? $qnaResult['bsr_pct']              ?? null);
    $add('disease_spear_rot_pct',         $qnaResult['spear_rot_pct']          ?? null);
    $add('disease_bud_rot_pct',           $qnaResult['bud_rot_pct']            ?? null);
    $add('pest_barn_owl_coverage',        $qnaResult['barn_owl_ha_per_pair']   ?? null);
    $add('pest_monitoring_frequency',     $qnaResult['monitoring_per_month']   ?? null);
    $add('pest_natural_enemy_ratio',      $qnaResult['bio_control_pct']        ?? null);

    // ── Weed control ─────────────────────────────────────────────────────────
    $add('weed_circle_clean_pct',         $qnaResult['circle_clean_pct']       ?? null);
    $add('weed_gawangan_clean_pct',       $qnaResult['gawangan_clean_pct']     ?? null);
    $add('weed_rotation_days',            $qnaResult['weed_rotation_days']     ?? null);
    $add('weed_herbicide_dose_roundup',   $qnaResult['roundup_dose_l_ha']      ?? null);
    $add('weed_manual_norm',              $qnaResult['manual_weed_norm']       ?? null);

    // ── Infrastructure ───────────────────────────────────────────────────────
    $add('road_prod_per_ha',      $qnaResult['road_prod_m_per_ha']    ?? null);
    $add('road_main_per_ha',      $qnaResult['road_main_m_per_ha']    ?? null);
    $add('road_width_prod',       $qnaResult['road_prod_width_m']     ?? null);
    $add('road_width_main',       $qnaResult['road_main_width_m']     ?? null);
    $add('road_prod_main_ratio',  $qnaResult['road_prod_main_ratio']  ?? null);
    $add('road_access_main_ratio',$qnaResult['road_access_main_ratio']?? null);
    $add('bridge_per_km_road',    $qnaResult['bridge_per_km']         ?? null);

    // ── Sustainability ────────────────────────────────────────────────────────
    $add('conservation_ratio',    $qnaResult['conservation_pct']      ?? $qnaResult['conservation_ratio'] ?? null);
    $add('hcv_buffer',            $qnaResult['hcv_buffer_m']          ?? $qnaResult['riparian_buffer_m']  ?? null);

    // ── Finance ──────────────────────────────────────────────────────────────
    $add('cost_per_kg_ffb',         $qnaResult['cost_per_kg']          ?? $qnaResult['biaya_per_kg']       ?? null);
    $add('cost_per_ha_maintenance', $qnaResult['cost_per_ha_million']  ?? $qnaResult['maint_cost_per_ha']  ?? null);

    return $actuals;
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 3 — Run checks and build per-metric report
// ─────────────────────────────────────────────────────────────────────────────

/**
 * For each (stdId, actualValue) pair, run agro_std_check() and build a
 * structured report entry with full citation.
 *
 * @param  array<string,float> $actuals  Output of agro_std_extract_actuals()
 * @param  string[]|null       $stdIds   Limit to these IDs (null = all in $actuals)
 * @return array[]
 */
function agro_std_run_checks(array $actuals, ?array $stdIds = null): array
{
    $reports = [];
    $idsToCheck = $stdIds ?? array_keys($actuals);

    foreach ($idsToCheck as $stdId) {
        if (!agro_std_exists($stdId)) continue;
        if (!array_key_exists($stdId, $actuals)) continue;

        $std    = agro_std_get($stdId);
        $actual = $actuals[$stdId];
        $status = agro_std_check($actual, $std);

        $passMin = $std['pass_min'] ?? null;
        $passMax = $std['pass_max'] ?? null;
        $gap     = null;
        $gapPct  = null;
        $refVal  = null;

        if ($passMin !== null && $actual < $passMin) {
            $gap    = $actual - $passMin;
            $refVal = $passMin;
        } elseif ($passMax !== null && $actual > $passMax) {
            $gap    = $actual - $passMax;
            $refVal = $passMax;
        } elseif ($passMin !== null) {
            $gap    = $actual - $passMin;
            $refVal = $passMin;
        }

        if ($gap !== null && $refVal !== null && abs($refVal) > 0) {
            $gapPct = round($gap / abs($refVal) * 100, 1);
        }

        $reports[$stdId] = [
            'std_id'             => $stdId,
            'param'              => $std['param'],
            'unit'               => $std['unit'],
            'display'            => $std['display'],
            'description'        => $std['description'] ?? '',
            'source'             => $std['source'],
            'source_year'        => $std['source_year'],
            'standard_min'       => $passMin,
            'standard_max'       => $passMax,
            'actual_value'       => $actual,
            'status'             => $status,
            'gap'                => $gap !== null ? round($gap, 3) : null,
            'gap_pct'            => $gapPct,
            'note'               => agro_std_note($actual, $std),
            'attention_required' => in_array($status, ['warn', 'fail']),
        ];
    }

    // Sort: fail → warn → pass
    uasort($reports, static function (array $a, array $b): int {
        $order = ['fail' => 0, 'warn' => 1, 'pass' => 2];
        return ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
    });

    return $reports;
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 4 — Build the full structured standards response
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Assemble the complete standards analysis response.
 *
 * @param  array $semantic    Semantic context (entity, metrics, question)
 * @param  array $qnaResult   Data result array
 * @return array              Full structured standards response
 */
function agro_std_build_response(array $semantic, array $qnaResult): array
{
    $applicableIds  = agro_std_identify($semantic);
    $actuals        = agro_std_extract_actuals($qnaResult);
    $idsWithData    = array_intersect($applicableIds, array_keys($actuals));
    $idsWithoutData = array_diff($applicableIds, array_keys($actuals));
    $checks         = agro_std_run_checks($actuals, $idsWithData);

    $provenance = [];
    foreach ($idsWithData as $sid) {
        $std = agro_std_get($sid);
        if ($std === null) continue;
        $provenance[$sid] = [
            'param'       => $std['param'],
            'display'     => $std['display'],
            'unit'        => $std['unit'],
            'source'      => $std['source'],
            'source_year' => $std['source_year'],
        ];
    }

    $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
    foreach ($checks as $c) {
        $counts[$c['status']]++;
    }
    $overallStatus = $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'pass');

    $interpretation  = agro_std_build_interpretation($checks, $semantic);
    $recommendations = agro_std_build_recommendations($checks, $semantic);

    $sourcesUsed = array_unique(array_map(
        fn($s) => (agro_std_get($s)['source'] ?? ''),
        $idsWithData
    ));

    return [
        'actual_data'       => $actuals,
        'standards_applied' => array_keys($checks),
        'standards_no_data' => array_values($idsWithoutData),
        'checks'            => $checks,
        'gap_summary'       => [
            'overall_status'   => $overallStatus,
            'total_checked'    => count($checks),
            'pass_count'       => $counts['pass'],
            'warn_count'       => $counts['warn'],
            'fail_count'       => $counts['fail'],
            'attention_needed' => $counts['fail'] > 0 || $counts['warn'] > 0,
        ],
        'interpretation'    => $interpretation,
        'recommendations'   => $recommendations,
        'provenance'        => $provenance,
        'disclaimer'        => 'All standard values sourced from '
                             . implode(', ', $sourcesUsed)
                             . '. Actual values sourced from erpAgroSmart records. '
                             . 'Interpretation and recommendations are readings of the '
                             . 'gap between actual data and documented standards — not '
                             . 'independent official standards.',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Interpretation builder — factual, no invention
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param  array[] $checks
 * @param  array   $semantic
 * @return array
 */
function agro_std_build_interpretation(array $checks, array $semantic): array
{
    $items = [];
    foreach ($checks as $stdId => $c) {
        $unit    = $c['unit'];
        $actual  = $c['actual_value'];
        $display = $c['display'];

        $gapDesc = '';
        if ($c['gap'] !== null) {
            $absGap   = abs($c['gap']);
            $dir      = $c['gap'] < 0 ? 'below' : 'above';
            $pctStr   = $c['gap_pct'] !== null ? ' (' . abs($c['gap_pct']) . '%)' : '';
            $boundary = $c['gap'] < 0
                ? 'the minimum standard (' . ($c['standard_min'] ?? '–') . ' ' . $unit . ')'
                : 'the maximum standard (' . ($c['standard_max'] ?? '–') . ' ' . $unit . ')';
            $gapDesc = "Actual value {$actual} {$unit} is {$dir} {$boundary} by {$absGap} {$unit}{$pctStr}.";
        } else {
            $gapDesc = "Actual value {$actual} {$unit} (standard: {$display} {$unit}).";
        }

        $items[] = [
            'std_id'          => $stdId,
            'param'           => $c['param'],
            'actual_value'    => $actual,
            'actual_label'    => $actual . ' ' . $unit,
            'standard_range'  => $display . ' ' . $unit,
            'source'          => $c['source'],
            'source_year'     => $c['source_year'],
            'status'          => $c['status'],
            'status_label'    => agro_std_format_label($c['status']),
            'gap_description' => $gapDesc,
        ];
    }
    return $items;
}

// ─────────────────────────────────────────────────────────────────────────────
// Recommendation builder — grounded in standard notes only
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Generates recommendations sourced EXCLUSIVELY from fail_note / warn_note.
 *
 * @param  array[] $checks
 * @param  array   $semantic
 * @return array
 */
function agro_std_build_recommendations(array $checks, array $semantic): array
{
    $recs = [];
    foreach ($checks as $stdId => $c) {
        if ($c['status'] === 'pass') continue;
        if (!agro_std_exists($stdId)) continue;
        $std = agro_std_get($stdId);
        if ($std === null) continue;

        $recText = $c['status'] === 'fail'
            ? ($std['fail_note'] ?? null)
            : ($std['warn_note'] ?? null);
        if (!$recText) continue;

        $recs[] = [
            'priority'             => $c['status'] === 'fail' ? 'high' : 'medium',
            'std_id'               => $stdId,
            'param'                => $c['param'],
            'actual_value'         => $c['actual_value'],
            'actual_label'         => $c['actual_value'] . ' ' . $c['unit'],
            'standard_range'       => $c['display'] . ' ' . $c['unit'],
            'recommendation'       => $recText,
            'source'               => $std['source'],
            'source_year'          => $std['source_year'],
            'recommendation_basis' => 'Text cited from ' . $std['source']
                                    . ' (' . $std['source_year'] . '), '
                                    . 'not AI-generated advice.',
        ];
    }
    usort($recs, fn($a, $b) => strcmp($a['priority'], $b['priority']));
    return $recs;
}

// ─────────────────────────────────────────────────────────────────────────────
// Utility helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Human-readable status label (English).
 */
function agro_std_format_label(string $status): string
{
    return match ($status) {
        'pass'  => '✓ Meets Standard',
        'warn'  => '⚠ Needs Attention',
        'fail'  => '✗ Does Not Meet Standard',
        default => $status,
    };
}

/**
 * Returns true when any check in the report requires attention.
 */
function agro_std_attention_needed(array $report): bool
{
    foreach ($report as $c) {
        if ($c['attention_required'] ?? false) return true;
    }
    return false;
}

/**
 * Compact one-line summary of the overall standards check result.
 */
function agro_std_one_liner(array $response): string
{
    $g = $response['gap_summary'] ?? [];
    if (empty($g)) return '';

    $total  = $g['total_checked']  ?? 0;
    $fail   = $g['fail_count']     ?? 0;
    $warn   = $g['warn_count']     ?? 0;
    $pass   = $g['pass_count']     ?? 0;
    $status = $g['overall_status'] ?? 'pass';

    $icon = match ($status) {
        'fail'  => '✗',
        'warn'  => '⚠',
        default => '✓',
    };

    if ($total === 0) return 'No actual data available to compare against standards.';

    $parts = [];
    if ($fail > 0) $parts[] = "$fail does not meet standard";
    if ($warn > 0) $parts[] = "$warn needs attention";
    if ($pass > 0) $parts[] = "$pass meets standard";

    return "$icon From $total parameters checked: " . implode(', ', $parts) . '.';
}

// ─────────────────────────────────────────────────────────────────────────────
// Opportunistic trigger
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns true when $qnaResult contains at least one metric value that can be
 * matched against an existing standard — enabling opportunistic compliance
 * annotation on any data retrieval result.
 */
function agro_std_has_checkable_data(array $qnaResult, array $semantic): bool
{
    static $knownKeys = [
        'oer', 'ker', 'oil_extraction_rate', 'kernel_extraction_rate',
        'ffa', 'ffa_percentage', 'moisture', 'moisture_content_pct',
        'abw', 'average_bunch_weight', 'sph', 'tm_ratio',
        'germination_rate', 'survival_rate', 'daya_kecambah',
        'yield_per_ha', 'ton_per_ha', 'losses_pct', 'realization_pct',
        'conservation_pct', 'hcv_buffer_m', 'cost_per_kg', 'ganoderma_pct',
        'rat_attack_pct', 'caterpillar_pct', 'circle_clean_pct',
        'capacity_util_pct', 'utilization_pct', 'oil_losses_pct',
    ];

    foreach ($knownKeys as $key) {
        if (isset($qnaResult[$key]) && $qnaResult[$key] !== null && $qnaResult[$key] !== '') {
            return true;
        }
    }

    if (!empty($qnaResult['total_kg']) && !empty($qnaResult['tm_area'])) {
        return true;
    }

    return false;
}
