# AGROSMART STANDARDS PROTOCOL
## Anti-Hallucination & Data Integrity Rules for erpAgroSmart

**Version:** 1.0  
**Date:** 2025  
**Applies to:** All PHP pages, standards engine, ISPO gap scoring, and AI/QnA integrations in erpAgroSmart

---

## 1. GOLDEN RULE

> **Every numerical standard, threshold, or benchmark displayed in erpAgroSmart MUST originate from `config/standards.php`.  
> No hardcoded thresholds anywhere else in the codebase.**

---

## 2. Authoritative Standards File

**File:** `erpagrosmart/config/standards.php`  
**Constant:** `AGRO_STANDARDS` (associative array, ~59+ parameters)

### Accessing Standards — Required Pattern

```php
// ✅ CORRECT — always use the helper functions
$std = agro_std_get('cpo_ffa');          // get single standard by ID
$status = agro_std_check($value, $std);  // returns 'pass' | 'warn' | 'fail'
$all  = agro_std_all();                  // all standards
$mill = agro_std_by_category('mill');    // filter by category

// ✅ CORRECT — render badge
echo render_std_badge($status, $std['param'], $std['display'], $std['source']);

// ❌ FORBIDDEN — hardcoded numbers
if ($ffa <= 5) { ... }          // WRONG: must use agro_std_check()
if ($oer >= 20) { ... }         // WRONG: must use agro_std_get('oer_target')
echo "Standard: ≤0.25%";        // WRONG: must read from $std['display']
```

---

## 3. ISPO Gap Scoring Rules

### 3.1 Data Source Hierarchy

1. **Primary:** `ispo_criteria` table (seeded from `seed_ispo_criteria.sql`)
2. **Secondary:** `config/standards.php` for quantitative thresholds (OER, FFA, KER, etc.)
3. **Never:** Invent criteria, thresholds, or compliance percentages

### 3.2 Scoring Algorithm (non-negotiable)

| Compliance Status | Score Weight |
|---|---|
| `compliant`       | criterion weight × 100 |
| `partial`         | criterion weight × 50  |
| `non_compliant`   | 0                      |
| `not_applicable`  | excluded from denominator |
| `not_assessed`    | excluded from denominator |

**Overall score = SUM(weighted_scores) / SUM(applicable_weights) × 100**

This is implemented in `ispo_assessment.php` — do not change the formula.

### 3.3 ISPO Certification Readiness Bands

| Score | Band |
|---|---|
| ≥ 80% | ISPO Ready |
| 60–79% | Needs Improvement |
| < 60% | Significant Gaps |

These bands are from ISPO 2020 guidance, not fabricated.

---

## 4. Mill Quality Standards

All mill quality checks must use these `standards.php` IDs:

| Parameter | Standards ID | Pass | Warn | Source |
|---|---|---|---|---|
| FFA % | `cpo_ffa` | ≤ 3.5% | ≤ 5.0% | SNI 7182:2015 / Permentan No.29/2016 |
| Moisture % | `cpo_moisture` | ≤ 0.15% | ≤ 0.25% | SNI 7182:2015 |
| OER % | `oer_target` | ≥ 22% | ≥ 20% | GAPKI / SNI 7182:2015 |
| KER % | `ker_target` | ≥ 4.5% | ≥ 4.0% | GAPKI / PPKS Medan |
| Oil Losses | `mill_losses_oil` | ≤ 1.65% | ≤ 2.2% | GAPKI Norma Pabrik |

**The old hardcoded thresholds (FFA ≤5%, Moisture ≤0.25%) were INCORRECT and have been replaced.**

---

## 5. Plantation Standards (key parameters)

| Parameter | Standards ID | Pass Range | Source |
|---|---|---|---|
| Plant density (SPH) | `sph_aktual` | 136–148 trees/ha | PPKS Medan |
| Harvest interval | `harvest_interval_days` | 10–14 days | PPKS / GAPKI |
| Riparian buffer | `hcv_buffer` | ≥ 50m | RSPO P&C 2018 |
| Conservation area | `conservation_ratio` | ≥ 20% of HGU | ISPO 2020 |
| Production cost | `cost_per_kg_ffb` | ≤ Rp 800/kg | GAPKI 2023 |

---

## 6. POI Layer — Integrity Rules

POI types are seeded from `database/schema_poi.sql` (`poi_types` table).  
**Do not hardcode POI type codes** in PHP — always join to `poi_types`.  
Valid categories: `logistics` | `operations` | `social` | `infrastructure`

---

## 7. GIS Standards — PostGIS

| Parameter | Value | Source |
|---|---|---|
| Coordinate system | WGS84 EPSG:4326 | All GeoJSON |
| River buffer ISPO | ≥ 50m (compliant), 50–100m (warning) | ISPO 2020 / RSPO P&C 2018 |
| HCV area minimum | ≥ 20% of HGU | ISPO 2020 / Permentan No.11/2015 |

---

## 8. Forbidden Patterns

The following are **strictly forbidden** anywhere in the codebase:

```php
// ❌ Fabricating standards
if ($ffa < 3) { echo "Excellent"; }          // Not in standards.php

// ❌ Different thresholds from standards.php
'pass_max' => 5.0   // for cpo_ffa — WRONG, standard is 3.5

// ❌ Showing standards without citation
echo "Standard: ≤3.5%";                      // Missing source citation

// ❌ ISPO criteria not in the database
echo "ISPO Criteria 8.1 — Not in standards"; // Invented criteria

// ❌ Scores without the weighted formula
$score = ($compliant / $total) * 100;        // Wrong — must use weighted formula
```

---

## 9. File Inventory

| File | Purpose | Status |
|---|---|---|
| `config/standards.php` | Master standards library (59+ params) | ✅ Do not modify thresholds |
| `includes/standards_engine.php` | PHP engine (9 public functions) | ✅ Port of agro system |
| `includes/functions.php` | `render_std_badge()` helper | ✅ Added |
| `database/schema_ispo.sql` | ISPO tables DDL | ✅ Run on Supabase |
| `database/seed_ispo_criteria.sql` | 7 Principles + ~95 criteria/indicators | ✅ Run after schema |
| `database/schema_poi.sql` | POI tables DDL + 20 seed types | ✅ Run on Supabase |
| `database/migrate_geom_postgis.sql` | PostGIS migration + river_features | ✅ Run on Supabase |
| `ispo_criteria.php` | Browse ISPO criteria | ✅ |
| `ispo_assessment.php` | Create & score assessments | ✅ |
| `ispo_gap_dashboard.php` | Visual gap analysis | ✅ |
| `corrective_actions.php` | CAP management | ✅ |
| `ajax/upload_evidence.php` | Evidence file upload | ✅ |
| `ajax/save_block_geom.php` | Save drawn polygon to DB | ✅ |
| `ajax/poi_crud.php` | POI add/edit/delete/list | ✅ |
| `blocks_map.php` | SmartMap + Block Info + POI layers | ✅ Upgraded |
| `mill_quality.php` | SNI badges via standards engine | ✅ |
| `mill_production.php` | OER/KER SNI badges | ✅ |

---

## 10. SQL Run Order for Fresh Supabase Instance

```sql
-- 1. Core schema (already exists for most deployments)
-- 2. Custom standards table
\i database/schema_custom_standards.sql
-- 3. ISPO tables
\i database/schema_ispo.sql
\i database/seed_ispo_criteria.sql
-- 4. POI tables
\i database/schema_poi.sql
-- 5. PostGIS migration
\i database/migrate_geom_postgis.sql
```

---

*This document is the authoritative anti-hallucination protocol for erpAgroSmart.  
When in doubt about any standard or threshold — check `config/standards.php` first.*
