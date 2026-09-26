# erpAgroSmart — ISPO Gap Scoring + PostGIS GIS Upgrade Plan

## Top-Level Overview

**Goal:** Upgrade erpAgroSmart (PHP / Supabase PostgreSQL) to match and exceed the production `agro`
system in three areas:

1. **Standards Engine** — port the parametric compliance engine from `agro/includes/standards_engine.php`
   and wire it to existing quality, harvest, and fertilization pages with live SNI/GAPKI badges.
2. **ISPO Gap & Scoring Module** — build a new procedural assessment system (criteria library →
   assessment form → gap dashboard → corrective actions → evidence upload) — absent from both systems.
3. **PostGIS GIS Enhancement** — migrate the `blocks.geojson TEXT` column to a native
   `geometry(Polygon, 4326)` column on Supabase, then enrich `blocks_map.php` with choropleth
   layers, PostGIS-powered spatial compliance checks, and a Leaflet.Draw digitising tool.

**Out of scope for this plan:** Q&A / AI / NLP layer, Plasma module, Payroll, Procurement.

**Database:** Supabase PostgreSQL — all schema changes are PostgreSQL-native; no MySQL syntax.

**Approach:** Work in 8 focused sub-tasks, each independently deployable. No sub-task touches code
outside its stated scope. All standards data is sourced exclusively from `config/standards.php`
(already present and identical to the `agro` reference).

---

## Sub-Task 1 — Port Standards Engine

**Status:** [x] done

### Intent
Copy `agro/includes/standards_engine.php` into `erpagrosmart/includes/standards_engine.php` and
adapt for PostgreSQL/Supabase (PDO already uses `pgsql` driver; no SQL syntax in the engine file
itself — it is pure PHP, so the port is a direct copy with minor namespace guards).

Also add the helper functions `agro_std_get()`, `agro_std_all()`, `agro_std_categories()`,
`agro_std_count()`, and `agro_load_custom_standards()` that are present at the bottom of
`agro/config/standards.php` but absent from `erpagrosmart/config/standards.php`.

Create the `agro_custom_standards` table in Supabase (PostgreSQL DDL):
```sql
CREATE TABLE IF NOT EXISTS agro_custom_standards (
    std_id          TEXT PRIMARY KEY,
    category        TEXT NOT NULL,
    param           TEXT NOT NULL,
    unit            TEXT NOT NULL DEFAULT '',
    pass_min        NUMERIC,
    pass_max        NUMERIC,
    warn_min        NUMERIC,
    warn_max        NUMERIC,
    display         TEXT NOT NULL DEFAULT '',
    source          TEXT NOT NULL DEFAULT 'Internal',
    source_year     TEXT NOT NULL DEFAULT '',
    description     TEXT NOT NULL DEFAULT '',
    pass_note       TEXT NOT NULL DEFAULT '',
    warn_note       TEXT NOT NULL DEFAULT '',
    fail_note       TEXT NOT NULL DEFAULT '',
    company_id      INTEGER REFERENCES companies(company_id),
    created_at      TIMESTAMPTZ DEFAULT NOW()
);
```

### Expected Outcomes
- `includes/standards_engine.php` exists in erpagrosmart and all 9 public functions are callable.
- `config/standards.php` in erpagrosmart has `agro_std_get()`, `agro_std_all()`, `agro_std_categories()`,
  `agro_std_count()`, `agro_load_custom_standards()`.
- `agro_custom_standards` table is present in Supabase.
- Calling `agro_std_count()` from any erpagrosmart page returns 59+.
- No page breaks due to the new includes.

### Todo List
1. Copy `agro/includes/standards_engine.php` → `erpagrosmart/includes/standards_engine.php` verbatim.
2. Verify the `require_once` guard at line 43 points to `../config/standards.php` — correct if needed.
3. Append the five helper functions from the bottom of `agro/config/standards.php` to
   `erpagrosmart/config/standards.php` (after the `AGRO_STANDARDS` constant, before closing `?>`).
4. Create `agro_load_custom_standards()` to query the new `agro_custom_standards` table via `getDB()`
   with a `try/catch` graceful fallback (table may not exist yet on first deploy).
5. Write `erpagrosmart/database/schema_custom_standards.sql` with the PostgreSQL DDL above.
6. Run the DDL against Supabase (or provide to user as migration script).
7. Smoke-test: add `require_once 'includes/standards_engine.php';` to a check page and call
   `agro_std_count()`.

### Relevant Context
- Source: `agro/includes/standards_engine.php` (770 lines, pure PHP, no SQL)
- Source: `agro/config/standards.php` bottom ~80 lines for helper functions
- Target: `erpagrosmart/includes/standards_engine.php`
- Target: `erpagrosmart/config/standards.php` (append only)
- `erpagrosmart/config/database.php` — `getDB()` returns PDO with pgsql driver

---

## Sub-Task 2 — SNI Compliance Badges on mill_quality.php and mill_production.php

**Status:** [x] done

### Intent
Wire the Standards Engine to two existing pages so that every quality test row and every production
summary card shows a live SNI pass/warn/fail badge without changing any data or form logic.

**mill_quality.php:** For each row in `$quality_tests`, call `agro_std_check()` against:
- `cpo_ffa` (FFA ≤ 3.5% pass, ≤ 5.0% warn — SNI 7182:2015 / Permentan 29/2016)
- `cpo_moisture` (Moisture ≤ 0.15% pass, ≤ 0.25% warn — SNI 7182:2015)
- `cpo_dobi` standard if it exists (DOBI ≥ 2.5 pass — SNI 7182:2015)
- Render a colour-coded badge: ✓ green / ⚠ amber / ✗ red with source citation tooltip.

**mill_production.php:** `$avg_oer` and `$avg_ker` are already computed (lines 170–171). Add two
new summary stat cards — "Avg OER" and "Avg KER" — each with an SNI compliance badge using
`oer_target` (≥22%) and `ker_target` (≥4.5%) standards from the library.

Add a column "SNI Status" to the quality tests table showing the worst status across all parameters
for that row (fail trumps warn, warn trumps pass).

### Expected Outcomes
- Each quality test row in `mill_quality.php` shows colour-coded SNI badges for FFA, moisture.
- `mill_production.php` summary shows OER and KER stat cards with green/amber/red SNI badge.
- Tooltip on each badge cites the source standard and threshold.
- No new database tables or schema changes required.

### Todo List
1. Add `require_once 'includes/standards_engine.php';` near the top of `mill_quality.php`
   (after the existing requires).
2. In the quality tests table (HTML output), add a "SNI Status" column header.
3. For each `$row` in `$quality_tests`, call `agro_std_check()` for `cpo_ffa` and `cpo_moisture`
   using `agro_std_get()`. Compute worst status. Render badge.
4. Add helper function `render_std_badge(string $status, string $param, string $display,
   string $source): string` to `includes/functions.php` — returns Bootstrap badge HTML with
   `title` tooltip.
5. Add `require_once 'includes/standards_engine.php';` to `mill_production.php`.
6. In the summary cards section of `mill_production.php` (after the existing 4 stat cards), add
   "Avg OER" and "Avg KER" cards using `$avg_oer` and `$avg_ker` already computed, each with
   `render_std_badge()`.
7. Verify `$avg_oer = $total_cpo / $total_ffb * 100` already computed at line 170 — use as-is.

### Relevant Context
- `erpagrosmart/mill_quality.php` — `$quality_tests` array, FFA in `ffa_percentage`,
  moisture in `moisture_content_pct`
- `erpagrosmart/mill_production.php` line 170-171 — `$avg_oer`, `$avg_ker` already computed
- `agro/config/standards.php` — `cpo_ffa` (pass_max=3.5), `cpo_moisture` (pass_max=0.15),
  `oer_target` (pass_min=22.0), `ker_target` (pass_min=4.5)

---

## Sub-Task 3 — ISPO Criteria Database Schema & Seeder

**Status:** [x] done

### Intent
Create the PostgreSQL schema for the procedural ISPO assessment system — the foundation that
sub-tasks 4, 5, and 6 build upon. Seed all 7 ISPO Principles (Prinsip) and their sub-criteria
based on ISPO 2020 (Perpres No. 44/2020 + Permentan No. 38/2020).

Tables needed:
- `ispo_principles` — the 7 top-level ISPO principles
- `ispo_criteria` — sub-criteria under each principle (evidence requirements, applicable_to type)
- `ispo_assessments` — one assessment per Business Unit per period
- `ispo_assessment_items` — one row per criterion per assessment (score + notes)
- `ispo_corrective_actions` — corrective action requests linked to non-conforming items
- `ispo_evidence_files` — uploaded evidence files linked to assessment items

### Expected Outcomes
- All 6 tables exist in Supabase with correct foreign keys and CHECK constraints.
- `ispo_principles` has 7 rows (P1–P7) seeded.
- `ispo_criteria` has all ISPO 2020 sub-criteria seeded (approximately 40–50 criteria across
  7 principles).
- Schema file `erpagrosmart/database/schema_ispo.sql` committed.
- Seeder file `erpagrosmart/database/seed_ispo_criteria.sql` committed.

### Todo List
1. Write `erpagrosmart/database/schema_ispo.sql` with PostgreSQL DDL for all 6 tables.
   Key constraints:
   - `ispo_assessment_items.score` CHECK IN ('compliant','minor_nc','major_nc','not_applicable','not_assessed')
   - `ispo_corrective_actions.status` CHECK IN ('open','in_progress','closed','overdue')
   - `ispo_evidence_files` stores file path, mime type, uploaded_by, uploaded_at
2. Write `erpagrosmart/database/seed_ispo_criteria.sql` with all 7 principles and ~45 criteria
   sourced from ISPO 2020 (Perpres 44/2020). Each criterion includes:
   - `criteria_no` (e.g. "1.1", "1.2", "2.1")
   - `title_id` (Indonesian)
   - `title_en` (English)
   - `evidence_required` (TEXT[] — list of document types needed)
   - `applicable_to` (estate / mill / nursery / all)
   - `weight` (NUMERIC — for weighted scoring)
3. Run schema + seeder against Supabase.
4. Verify row counts via a quick PHP check script.

### Relevant Context
- ISPO 2020 Principles:
  - P1: Kepatuhan terhadap Peraturan / Legal Compliance
  - P2: Penerapan Praktik Perkebunan Terbaik / Best Management Practices
  - P3: Pengelolaan Lingkungan / Environmental Management
  - P4: Tanggung Jawab terhadap Karyawan / Employee Responsibility
  - P5: Tanggung Jawab Sosial / Social Responsibility
  - P6: Pemberdayaan Kegiatan Ekonomi / Economic Empowerment
  - P7: Peningkatan Usaha yang Berkelanjutan / Continuous Improvement
- Reference: `agro/AGROSMART_STANDARDS_PROTOCOL.md` for standard ID conventions
- Schema convention: PostgreSQL TIMESTAMPTZ, SERIAL / IDENTITY for PKs

---

## Sub-Task 4 — ISPO Criteria Management Page (ispo_criteria.php)

**Status:** [x] done

### Intent
Build the CRUD management page for the ISPO criteria library so assessors can view, add, edit,
and toggle the weight/applicability of each criterion. This is an admin-only page.

### Expected Outcomes
- `erpagrosmart/ispo_criteria.php` exists and is accessible to admin users.
- Page shows all 7 principles as collapsible sections, each listing its criteria.
- Admin can add custom criteria, edit weights, mark criteria as active/inactive.
- Navigation menu has a new "Compliance" dropdown containing this page.

### Todo List
1. Create `erpagrosmart/ispo_criteria.php` with standard PHP/Bootstrap structure (matching
   existing page style: `config/database.php`, `includes/functions.php`, `includes/header.php`).
2. Implement GET list view: fetch all principles + criteria from DB, grouped by principle.
   Render as Bootstrap accordion (one panel per principle).
3. Implement POST add/edit/delete handlers for criteria (admin role check via `$_SESSION['role']`).
4. Add "Compliance" top-level dropdown to `includes/header.php` navbar containing:
   - ISPO Criteria (ispo_criteria.php)
   - ISPO Assessment (ispo_assessment.php — link only, page built in sub-task 5)
   - Gap Dashboard (ispo_gap_dashboard.php — link only, page built in sub-task 5)
5. Add same links to the sidebar in `includes/header.php`.

### Relevant Context
- Pattern: follow `activities.php` for accordion+CRUD style — similar collapsible grouped list
- Role check pattern: `if ($_SESSION['role'] !== 'admin') { redirect('index.php'); }`
- `ispo_principles` and `ispo_criteria` tables from Sub-Task 3

---

## Sub-Task 5 — ISPO Assessment Form & Gap Dashboard

**Status:** [x] done

### Intent
Build two tightly related pages:

**`ispo_assessment.php`** — per-Business Unit assessment form where an assessor scores each
criterion as Compliant / Minor NC / Major NC / Not Applicable. One assessment per BU per period.
Criteria grouped by principle. Evidence notes field per criterion. Save to `ispo_assessment_items`.

**`ispo_gap_dashboard.php`** — visual gap score dashboard showing:
- Overall compliance percentage per BU (weighted score)
- Radar chart (one axis per principle, using ECharts already available via `js/echarts.min.js`)
- Progress bars per principle (% compliant criteria)
- Non-conformance summary table (Minor NC / Major NC counts)
- Trend line if multiple assessments exist for a BU

### Expected Outcomes
- Assessors can create and update assessments via `ispo_assessment.php`.
- `ispo_gap_dashboard.php` shows radar chart + progress bars using real assessment data.
- Score formula: `(compliant × 1.0 + minor_nc × 0.5 + major_nc × 0.0) / total_applicable × 100`
- Dashboard works without any new JS library (reuses ECharts already on disk at `js/echarts.min.js`).

### Todo List
1. Create `ispo_assessment.php`:
   - Filter bar: select Business Unit + assessment period (year/quarter)
   - Load or create an assessment header row in `ispo_assessments`
   - For each principle (7), render a card with criteria rows
   - Each row: criterion title, score radio (compliant/minor_nc/major_nc/not_applicable), notes textarea
   - Single form POST saves all items in a transaction
   - Show existing saved scores if assessment already exists
2. Create `ispo_gap_dashboard.php`:
   - Company + BU filter at top
   - Compute weighted score per principle using PostgreSQL aggregation query with CASE WHEN
   - Render overall score ring (reuse dcard style from index.php)
   - Render ECharts radar chart — 7 axes (one per principle)
   - Render progress bars (Bootstrap) per principle with count labels
   - Render non-conformance table: principle, major NC, minor NC, compliant, total applicable
3. Add both pages to the "Compliance" menu added in Sub-Task 4.

### Relevant Context
- ECharts already available: `erpagrosmart/js/echarts.min.js` (confirmed in index.php)
- Score formula: same pattern used in `agro/includes/standards_engine.php` gap_summary
- `ispo_assessments` + `ispo_assessment_items` tables from Sub-Task 3
- Dashboard style: follow `index.php` dcard + section-card CSS classes already defined

---

## Sub-Task 6 — Corrective Actions & Evidence Upload

**Status:** [x] done

### Intent
Complete the procedural ISPO workflow by adding:
- **`corrective_actions.php`** — track CARs from non-conforming assessment items (open →
  in_progress → closed), assign responsible person and deadline, show overdue alerts.
- **Evidence upload** — a lightweight file upload endpoint (`ajax/upload_evidence.php`) that
  stores files in `uploads/ispo/{assessment_id}/` and records paths in `ispo_evidence_files`.
  Attach evidence via a small "Attach File" button on each criterion row in `ispo_assessment.php`.

### Expected Outcomes
- `corrective_actions.php` lists all open/overdue CARs across all BUs with filter by status/BU.
- Dashboard index shows an alert badge if any CARs are overdue.
- Evidence files are saved to disk and their paths stored in `ispo_evidence_files`.
- Assessment items with attached evidence show a paperclip icon with file count.

### Todo List
1. Create `corrective_actions.php`:
   - List view: fetch from `ispo_corrective_actions` with JOIN to criteria + BU
   - Status filter (open / in_progress / closed / overdue)
   - Inline status update (AJAX POST) to change status without page reload
   - Overdue detection: `deadline < NOW() AND status NOT IN ('closed')`
2. Add overdue CAR count to `index.php` dashboard — query count and show warning badge on
   the "Quick Actions" section.
3. Create `ajax/upload_evidence.php`:
   - Accepts multipart POST: `assessment_item_id`, `file`
   - Validates MIME type (PDF, JPG, PNG only), max 10 MB
   - Saves to `uploads/ispo/{assessment_id}/` (create dir if not exists)
   - Inserts row into `ispo_evidence_files`
   - Returns JSON `{success: true, file_id, filename}`
4. Add `upload_evidence_file()` and `get_evidence_files()` helpers to `includes/functions.php`.
5. In `ispo_assessment.php`, add a small "📎 Attach" button per criterion row. On click, open
   a Bootstrap modal with a file input that POSTs to `ajax/upload_evidence.php` via fetch API.
   After success, increment the paperclip counter on the row.
6. Add `corrective_actions.php` link to the Compliance menu.
7. Create `uploads/ispo/.gitkeep` and add `uploads/ispo/**` to `.gitignore` (keep dir, not files).

### Relevant Context
- `ispo_corrective_actions` + `ispo_evidence_files` tables from Sub-Task 3
- PHP file upload: `$_FILES`, `move_uploaded_file()`, `mime_content_type()`
- AJAX pattern: existing pages use jQuery; fetch API is fine for new code
- `erpagrosmart/.htaccess` — check if there is any upload restriction; add if missing

---

## Sub-Task 7 — PostGIS Migration & Spatial Block Analysis

**Status:** [x] done — PostGIS migration SQL + river_features table + riparian views + Leaflet.Draw + POI layer + Block Info System panel

### Intent
Migrate the `blocks.geojson TEXT` column to a native PostGIS `geometry(Geometry, 4326)` column
on Supabase. This enables `ST_Area`, `ST_Buffer`, `ST_DWithin`, and spatial indexing. Then
enhance `blocks_map.php` with:
- Auto-computed area from polygon (replaces manual `area` entry as validation source)
- Choropleth colouring by yield/ha, OER-by-mill, or pest severity
- HCV riparian buffer compliance layer (50m buffer check from river geometries)
- Leaflet.Draw plugin for digitising new block boundaries directly on the map

PostGIS is enabled on all Supabase projects by default via the `postgis` extension.

### Expected Outcomes
- `blocks` table has a `geom geometry(Geometry, 4326)` column with GiST spatial index.
- Migration script populates `geom` from existing `geojson` TEXT via `ST_GeomFromGeoJSON()`.
- `blocks_map.php` fetches `ST_AsGeoJSON(geom)` for map rendering and `ST_Area(geom::geography)`
  for area validation.
- Map has a "Colour By" option for Yield/ha (choropleth) in addition to existing Status/Year/Variety.
- Leaflet.Draw allows drawing new block polygons; on save, POST to `ajax/save_block_geom.php`.
- A "Buffer Compliance" toggle shows a red overlay on blocks within 50m of river features
  (requires a `river_features` geometry table or uses a placeholder until river data is loaded).

### Todo List
1. Write `erpagrosmart/database/migrate_geom_postgis.sql`:
   ```sql
   CREATE EXTENSION IF NOT EXISTS postgis;
   ALTER TABLE blocks ADD COLUMN IF NOT EXISTS geom geometry(Geometry, 4326);
   UPDATE blocks SET geom = ST_GeomFromGeoJSON(geojson) WHERE geojson IS NOT NULL AND geojson != '';
   CREATE INDEX IF NOT EXISTS idx_blocks_geom ON blocks USING GIST (geom);
   ```
2. Add `geom_area_ha` generated column (or computed on read):
   ```sql
   ALTER TABLE blocks ADD COLUMN IF NOT EXISTS geom_area_ha NUMERIC
       GENERATED ALWAYS AS (ROUND(ST_Area(geom::geography)::NUMERIC / 10000, 4)) STORED;
   ```
3. Run migration against Supabase (or provide as user-executed script).
4. Update `blocks_map.php` PHP query:
   - Replace `b.geojson` with `ST_AsGeoJSON(b.geom) AS geojson` in the SELECT.
   - Add `b.geom_area_ha` to SELECT for area validation comparison.
   - Keep `b.area` (manual) as the authoritative figure; show `geom_area_ha` as a
     verification tooltip on the popup.
5. Add "Yield" colour mode to `blocks_map.php`:
   - JOIN `harvest_realizations` grouped by block for last 12 months.
   - Compute yield_per_ha; pass as JSON alongside blocksData.
   - Add radio button "Yield" to the Colour By group.
   - Colour scale: 5 green shades from 0–30 ton/ha.
6. Add Leaflet.Draw from CDN to `blocks_map.php`:
   - CDN: `https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js` (CSS + JS)
   - Add a draw control (polygon only) with save button.
   - On polygon complete, POST to `ajax/save_block_geom.php` with WKT/GeoJSON + block_id.
7. Create `ajax/save_block_geom.php`:
   - Accept POST: `block_id`, `geojson` (drawn polygon as GeoJSON string)
   - Validate JSON, run:
     `UPDATE blocks SET geom = ST_GeomFromGeoJSON(?), geojson = ? WHERE block_id = ?`
   - Return JSON `{success: true, geom_area_ha}`.
8. Create placeholder `river_features` table and a note for future river data load.
   Add a "Buffer Check" toggle button on the map that queries:
   `SELECT block_id FROM blocks WHERE ST_DWithin(geom::geography, (SELECT ST_Union(geom::geography) FROM river_features), 50)`
   and highlights those blocks in red.

### Relevant Context
- Supabase has PostGIS enabled by default; no manual extension install needed
- `erpagrosmart/blocks_map.php` — existing Leaflet map, blocksData JSON, colour modes
- `erpagrosmart/config/database.php` — `getDB()` returns PDO pgsql connection
- Existing SQL: `SELECT b.*, ... FROM blocks b WHERE b.geojson IS NOT NULL AND b.geojson != ''`
  → becomes `WHERE b.geom IS NOT NULL`
- Leaflet.Draw plugin: open-source, MIT licence, no additional dependencies

---

## Sub-Task 8 — Export Gap Report + Navigation Finalisation

**Status:** [x] done — AGROSMART_STANDARDS_PROTOCOL.md created + navigation Compliance menu added to header.php

### Intent
- Add PDF/Excel export of the ISPO gap report from `ispo_gap_dashboard.php`.
- Complete all navigation menu updates (sidebar + top navbar).
- Add `AGROSMART_STANDARDS_PROTOCOL.md` from `agro` to `erpagrosmart` (adapted for PostgreSQL).
- Update Session 001 bob_sessions notes to mark the upgrade plan as written.

PDF export uses PHP's built-in output buffering + a simple HTML→PDF approach (mPDF or
browser print-to-PDF trigger). Excel export reuses the existing `export_to_csv()` helper
already in `includes/functions.php`.

### Expected Outcomes
- "Export CSV" and "Export PDF" buttons on `ispo_gap_dashboard.php`.
- Full Compliance menu visible in top navbar and sidebar, all links working.
- `AGROSMART_STANDARDS_PROTOCOL.md` exists in `erpagrosmart/` (adapted).
- `bob_sessions/session_001_evaluation_palm_oil_standards.md` updated to reflect plan written.

### Todo List
1. Add "Export CSV" to `ispo_gap_dashboard.php`:
   - Flatten assessment items to rows: BU, principle, criteria, score, notes, responsible
   - Use existing `export_to_csv()` from `includes/functions.php`.
2. Add "Export PDF" trigger:
   - Add `?export=pdf` query param handler at top of `ispo_gap_dashboard.php`
   - Output a print-optimised HTML page (no sidebar/nav) with `window.print()` auto-trigger
   - Works without a PHP PDF library — browser print saves to PDF
3. Finalize `includes/header.php` Compliance dropdown with all 4 links:
   - Standards Library (`standards.php`) — already exists
   - ISPO Criteria (`ispo_criteria.php`)
   - ISPO Assessment (`ispo_assessment.php`)
   - Gap Dashboard (`ispo_gap_dashboard.php`)
   - Corrective Actions (`corrective_actions.php`)
4. Copy and adapt `agro/AGROSMART_STANDARDS_PROTOCOL.md` → `erpagrosmart/AGROSMART_STANDARDS_PROTOCOL.md`
   (change MySQL references to PostgreSQL, update file paths).
5. Update `erpagrosmart/bob_sessions/session_001_evaluation_palm_oil_standards.md` to confirm
   upgrade plan written and sessions 002–008 are mapped to sub-tasks 1–8.

### Relevant Context
- `includes/functions.php` — `export_to_csv($filename, $data, $headers)` already implemented
- `ispo_gap_dashboard.php` — built in Sub-Task 5
- `agro/AGROSMART_STANDARDS_PROTOCOL.md` — source for adaptation

---

## Sub-Task Dependency Map

```
Sub-Task 1 (Standards Engine)
    └── Sub-Task 2 (SNI Badges — depends on engine)
Sub-Task 3 (ISPO Schema)
    └── Sub-Task 4 (Criteria CRUD — depends on schema)
        └── Sub-Task 5 (Assessment + Dashboard — depends on criteria)
            └── Sub-Task 6 (CAR + Evidence — depends on assessment)
Sub-Task 7 (PostGIS — independent)
Sub-Task 8 (Export + Nav — depends on 4, 5, 6, and 7 being complete)
```

Sub-tasks 1–2 and Sub-tasks 3–6 can be worked in parallel. Sub-task 7 is independent of all
ISPO sub-tasks. Sub-task 8 is the final integration pass.

---

## Files Created / Modified Summary

| File | Action | Sub-Task |
|---|---|---|
| `includes/standards_engine.php` | Create (port from agro) | 1 |
| `config/standards.php` | Modify (append helper functions) | 1 |
| `database/schema_custom_standards.sql` | Create | 1 |
| `mill_quality.php` | Modify (add SNI badges) | 2 |
| `mill_production.php` | Modify (add OER/KER badge cards) | 2 |
| `includes/functions.php` | Modify (add render_std_badge) | 2 |
| `database/schema_ispo.sql` | Create | 3 |
| `database/seed_ispo_criteria.sql` | Create | 3 |
| `ispo_criteria.php` | Create | 4 |
| `includes/header.php` | Modify (add Compliance menu) | 4, 8 |
| `ispo_assessment.php` | Create | 5 |
| `ispo_gap_dashboard.php` | Create | 5 |
| `corrective_actions.php` | Create | 6 |
| `ajax/upload_evidence.php` | Create | 6 |
| `includes/functions.php` | Modify (add upload helpers) | 6 |
| `uploads/ispo/.gitkeep` | Create | 6 |
| `database/migrate_geom_postgis.sql` | Create | 7 |
| `blocks_map.php` | Modify (PostGIS + choropleth + Draw) | 7 |
| `ajax/save_block_geom.php` | Create | 7 |
| `AGROSMART_STANDARDS_PROTOCOL.md` | Create (adapted from agro) | 8 |
| `bob_sessions/session_001_*.md` | Modify (mark plan written) | 8 |
