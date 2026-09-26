# Session 001 — Project Evaluation & Upgrade Plan ✅ PLAN WRITTEN

> **Upgrade plan file:** `erpagrosmart/ispo-gis-upgrade-plan.md`

**Date:** 2026-09-25  
**Session Type:** Deep Evaluation / Upgrade Planning  
**Goal:** Full assessment of erpAgroSmart (Supabase/PostgreSQL) and its sibling `agro` (Hostinger/MySQL) systems, understanding what already exists for Indonesian Palm Oil Standards, and planning the upgrade to match or exceed the `agro` system — including enhanced GIS capabilities.

---

## 1. Dual-System Architecture

| Attribute | **erpAgroSmart** (upgrade target) | **agro** (production reference) |
|---|---|---|
| Path | `c:\xampp3\htdocs\erpagrosmart` | `c:\xampp3\htdocs\agro` |
| Hosting | Local XAMPP → Supabase (PostgreSQL) | Hostinger (MySQL/MariaDB) |
| PHP Version | 7.4+ | 8.1+ |
| Standards Library | `config/standards.php` ✅ (identical) | `config/standards.php` ✅ |
| Standards Engine | ❌ Not ported yet | ✅ `includes/standards_engine.php` |
| Standards Protocol | `standards.php` (reference page only) | ✅ Full page + protocol doc |
| Q&A / NLP Layer | ❌ Not present | ✅ `qna.php` + semantic interpreter |
| AI Integration | ❌ Not present | ✅ `config/ai.php` (OpenAI-compatible) |
| Gap & Scoring | ❌ Not present | ❌ Not present |
| GIS Map | ✅ Leaflet, 3 colour modes, street+satellite | ✅ Leaflet, scoped RBAC |
| Plasma Module | ❌ | ✅ |
| Payroll | ❌ | ✅ |
| Procurement | ❌ | ✅ (PR, PO, GRN) |
| Delivery/Logistics | ❌ | ✅ |
| Taksasi Panen | ❌ | ✅ |
| HR Payroll GL | ❌ | ✅ |
| Custom Standards DB | ❌ | ✅ `agro_custom_standards` table |

**Direction:** Upgrade `erpAgroSmart` to match and exceed `agro`, using PostgreSQL (Supabase) capabilities including PostGIS for enhanced GIS.

---

## 2. What Already Exists — Standards Library (Shared)

Both systems share the same `config/standards.php` — a comprehensive **59+ parameter** library:

| Category | Count | Key Sources |
|---|---|---|
| `plantation` | 9+ | PPKS Medan, GAPKI, SNI 8171:2015, Permentan 98/2013 |
| `pest_disease` | 12+ | GAPKI PHT 2020, PPKS Medan, Ditjenbun OPT 2020 |
| `nursery` | 7 | PPKS Medan 2019, SNI 8171:2015, Permentan 50/2015 |
| `fertilization` | 6 | PPKS Medan 2020, GAPKI |
| `mill` | 6 | SNI 7182:2015, GAPKI, Permentan 29/2016 |
| `infrastructure` | 7 | GAPKI, Ditjenbun |
| `weed_control` | 5 | PPKS Medan, GAPKI |
| `agrochemical` | 6+ | RSPO P&C 2018, Permentan 39/2017, PP 101/2014 |
| `sustainability` | 2 | ISPO 2020, RSPO P&C 2018, Permentan 11/2015 |
| `finance` | 2 | GAPKI benchmark 2023 |

The schema is excellent: `pass_min/max`, `warn_min/max`, `display`, `source`, `source_year`, `pass_note`, `warn_note`, `fail_note`. The `agro_std_check()` and `agro_std_note()` functions already exist in both systems.

---

## 3. What `agro` Has That `erpAgroSmart` Needs — Standards Engine

The `agro` system has a full production-grade engine at `includes/standards_engine.php`:

```
agro_std_exists($id)                → bool        guard: only real standard IDs
agro_std_identify($semantic)        → string[]    entity+metric → applicable std IDs
agro_std_extract_actuals($result)   → array       metric→value from Q&A data
agro_std_run_checks($actuals)       → array[]     per-metric pass/warn/fail + gap calc
agro_std_build_response($sem,$res)  → array       5-layer structured response
agro_std_build_interpretation(…)    → array       factual gap description
agro_std_build_recommendations(…)   → array       action advice from warn_note/fail_note only
agro_std_attention_needed($report)  → bool        alert trigger (any fail or warn×2)
agro_std_one_liner($response)       → string      compact status summary
agro_std_has_checkable_data(…)      → bool        opportunistic compliance trigger
```

The engine maps **every module entity** (block, harvest_realization, nursery_batch, cpo_production, fertilization_record, pest_control_record, etc.) to its applicable standards automatically. This must be ported to `erpagrosmart`.

The `agro` system also has:
- `qna.php` — 30+ intent handlers (Indonesian + English), natural language queries
- `includes/semantic_interpreter.php` — intent detection, entity resolution
- `includes/conversation_context.php` — conversational follow-up
- `ajax/semantic_interpret.php` — JSON API with `standards_response` including full citation
- `agro_custom_standards` DB table — company-specific targets overlaid on built-in library
- `AGROSMART_STANDARDS_PROTOCOL.md` — anti-hallucination AI protocol
- `AGROSMART_SEMANTIC_MODEL.md` + `agrosmart_semantic_model.json` — full domain map

---

## 4. Current GIS State — Both Systems

### erpAgroSmart `blocks_map.php`
- Leaflet.js (1.9.4), OpenStreetMap + ArcGIS Satellite tiles
- GeoJSON blocks from `blocks.geojson` column
- **3 colour modes**: Status (TBM/TM/TR), Planting Year, Variety
- Company-scoped filter, operation type filter (Plantation/Forestry), status filter, text search
- Popup: block metadata, planting year, variety badge, area, total plants, age
- **Legend** panel, **Stats bar** (total blocks, area, plantation/forestry counts)
- PostgreSQL `DISTINCT ON` for dominant variety per block

### agro `blocks_map.php`
- Same Leaflet base, but with **finer RBAC scoping** (division → BU → company → admin)
- Named params PDO for scope injection

### GIS Enhancement Opportunities (PostgreSQL / PostGIS)
Since `erpAgroSmart` runs on **Supabase (PostgreSQL)**, PostGIS extensions are available:

| Enhancement | Technology | Value |
|---|---|---|
| True geometry columns (`geography(Polygon, 4326)`) | PostGIS | Spatial indexing, faster queries |
| Spatial area calculation (`ST_Area`) | PostGIS | Accurate ha from polygon, replaces manual entry |
| Centroid calculation (`ST_Centroid`) | PostGIS | Auto-compute block centre for labels |
| Spatial nearest-mill routing | PostGIS + `ST_Distance` | Optimal FFB delivery routing |
| HCV / conservation buffer zones | PostGIS + `ST_Buffer` | ISPO buffer zone compliance |
| Riparian buffer violations | PostGIS + `ST_DWithin` | Auto-detect blocks too close to rivers |
| Block intersection analysis | PostGIS + `ST_Intersects` | Land overlap / HGU boundary checks |
| Choropleth / heatmap layers | Leaflet + PostGIS data | Yield, OER, pest density by block |
| Time-series animation | Leaflet.TimeDimension | Monthly harvest progress animated |
| Drawing / digitising tool | Leaflet.Draw | Field staff can sketch new block boundaries |
| GPS tracking integration | Real-time WebSocket | Harvester location on map |
| Offline map tiles | Leaflet.offline / MapTiler | Field use without connectivity |

---

## 5. Revised Gap Analysis — erpAgroSmart vs agro + ISPO

### 5.1 Modules to Port from `agro`

| Module | Priority | Notes |
|---|---|---|
| Standards Engine (`standards_engine.php`) | 🔴 Critical | Direct port; adapt PDO for PostgreSQL |
| Q&A / NLP (`qna.php` + semantic interpreter) | 🔴 High | Major port; PostgreSQL syntax adjustments needed |
| Taksasi Panen (harvest estimation) | 🟡 Medium | New feature for erpagrosmart |
| Plasma Farmers module | 🟡 Medium | Smallholder management (ISPO social criterion) |
| Payroll (`payroll_*.php`) | 🟡 Medium | Workers' welfare tracking (ISPO P5) |
| Procurement (PR, PO, GRN) | 🟡 Medium | Chemical procurement audit trail |
| Delivery / Logistics | 🟡 Medium | Traceability |
| `agro_custom_standards` DB table | 🔴 High | Company-specific targets |
| `AGROSMART_STANDARDS_PROTOCOL.md` | 🔴 High | Port protocol + AI config |

### 5.2 Gap & Scoring Module (New — Not in Either System)

| Feature | Status |
|---|---|
| ISPO P1–P7 criteria library (DB table) | ❌ Neither system |
| ISPO assessment form (per criterion, per BU) | ❌ Neither system |
| Scoring: Compliant / Minor NC / Major NC / N/A | ❌ Neither system |
| Gap score dashboard (radar by principle, progress) | ❌ Neither system |
| Evidence document upload per criterion | ❌ Neither system |
| Corrective Action (CAR) tracking | ❌ Neither system |
| Audit schedule (internal + external) | ❌ Neither system |
| Gap score export (PDF/Excel) | ❌ Neither system |

### 5.3 Quality Compliance Overlays (Quick Wins)

| Feature | Status |
|---|---|
| SNI compliance badges on `mill_quality.php` (FFA ≤3.5%, Moisture ≤0.15%, DOBI ≥2.5) | ❌ Not in erpagrosmart; engine exists in agro but not wired to UI |
| OER/KER calculation + SNI flag on `mill_production.php` | ❌ Neither system computes OER/KER in UI |
| FFB ripeness % compliance vs ISPO norm | ❌ Neither |
| Harvest interval compliance check per block | ❌ Neither |
| Fertilizer dose compliance scoring | ❌ Data tracked; not scored |

### 5.4 New GIS Enhancements

| Feature | Status |
|---|---|
| PostGIS geometry columns for blocks | ❌ Currently JSON string |
| Block area auto-calc via `ST_Area` | ❌ Manual |
| HCV buffer zones on map | ❌ Neither |
| Choropleth layers (yield, pest, quality) | ❌ Neither |
| Spatial compliance analysis (riparian buffer) | ❌ Neither |
| Leaflet.Draw for digitising new blocks | ❌ Neither |

---

## 6. Upgrade Readiness Score (Revised)

| Domain | erpAgroSmart Now | After Upgrade Target |
|---|---|---|
| Standards Library (parametric) | 85% | 100% + custom DB table |
| CPO/Kernel Quality Compliance | 70% (data) → 30% (scoring) | 100% |
| BMP (Fertilization, Pest, Harvest) | 65% (data) → 25% (scoring) | 95% |
| Traceability | 45% | 90% |
| GIS Capabilities | 40% | 90% (PostGIS enhanced) |
| Social Responsibility (Plasma, Payroll) | 15% | 70% |
| ISPO Procedural Gap & Scoring | 0% | 100% |
| Q&A / AI Layer | 0% | 100% (parity with agro) |
| **Overall ISPO Readiness** | **~37%** | **≥85%** |

---

## 7. Planned Upgrade Roadmap

### Phase 1 — Foundation (Sessions 002–004)
1. **Session 002:** Port `standards_engine.php` from `agro` → `erpagrosmart`; port `agro_custom_standards` DB table (PostgreSQL); add SNI compliance badges to `mill_quality.php`; add OER/KER to `mill_production.php`
2. **Session 003:** Design & create ISPO criteria DB schema (PostgreSQL); seed all ISPO P1–P7 criteria; build `ispo_criteria.php` CRUD
3. **Session 004:** Build `ispo_assessment.php` — per-criterion scoring per Business Unit (Compliant / Minor NC / Major NC / N/A)

### Phase 2 — Gap Scoring & Dashboard (Sessions 005–006)
5. **Session 005:** Build `ispo_gap_dashboard.php` — radar chart by principle, progress bars, heat map, score trend over time
6. **Session 006:** Corrective Action (CAR) module + evidence document upload (`uploads/ispo/`)

### Phase 3 — GIS Enhancement (Sessions 007–008)
7. **Session 007:** Add PostGIS geometry to `blocks` table (PostgreSQL); migrate `geojson` text → `geometry(Polygon, 4326)`; add spatial layers to `blocks_map.php` — choropleth (yield, OER, pest density), HCV buffer zone overlay, `ST_Area` auto-calc, Leaflet.Draw for digitising
8. **Session 008:** Q&A / NLP layer port from `agro` (PostgreSQL syntax adaptation); AI integration (`config/ai.php`); `AGROSMART_STANDARDS_PROTOCOL.md` port

### Phase 4 — Module Parity with agro (Sessions 009–012)
9. **Session 009:** Plasma farmers module (ISPO P5 — social responsibility)
10. **Session 010:** Payroll module port + GL integration
11. **Session 011:** Procurement (PR → PO → GRN) + chemical tracking (RSPO 4.6 compliance)
12. **Session 012:** Full regression test; navigation menu update; export Gap report PDF/Excel; deployment readiness

---

## 8. PostgreSQL Advantages Over MySQL (`agro`)

Since `erpAgroSmart` uses Supabase/PostgreSQL, the upgrade will leverage:

| Feature | PostgreSQL Advantage |
|---|---|
| **PostGIS** | Full spatial DB — `ST_Area`, `ST_Buffer`, `ST_Distance`, `ST_Intersects`, etc. |
| **JSONB** | Better indexable storage for GeoJSON; `@>` containment queries |
| **DISTINCT ON** | Already in use — avoids subqueries for dominant-variety-per-block |
| **Window Functions** | `LAG/LEAD` for harvest interval calculation, trend analysis |
| **CTEs (WITH)** | Complex gap analysis queries in single statements |
| **Row-Level Security (RLS)** | Supabase RLS for multi-tenant data isolation |
| **Real-time (Supabase)** | Live dashboard updates via WebSocket subscriptions |
| **pg_trgm / Full-text Search** | Better Q&A search than MySQL LIKE |
| **Generated Columns** | Auto-compute `plant_age`, density ratios |

---

## 9. Key Files to Create / Modify

### New Files (erpAgroSmart)
```
includes/standards_engine.php        ← port from agro
includes/semantic_interpreter.php    ← port from agro
includes/conversation_context.php    ← port from agro
config/ai.php                        ← port from agro
qna.php                              ← port + PostgreSQL adaptation
ispo_criteria.php                    ← new (Gap & Scoring)
ispo_assessment.php                  ← new
ispo_gap_dashboard.php               ← new
corrective_actions.php               ← new
evidence_upload.php                  ← new
plasma_farmers.php                   ← port from agro
payroll_*.php (6 files)             ← port from agro
AGROSMART_STANDARDS_PROTOCOL.md     ← port from agro
```

### Modified Files (erpAgroSmart)
```
mill_quality.php           ← add SNI compliance badges using agro_std_check()
mill_production.php        ← add OER/KER calculation + SNI flag
harvest_quality.php        ← add ripeness % compliance check
harvest_realizations.php   ← add harvest interval compliance
blocks_map.php             ← PostGIS geometry + choropleth + Leaflet.Draw + HCV
blocks.php                 ← add PostGIS area auto-calc
config/database.php        ← add PostGIS connection verification
includes/header.php        ← add ISPO / Q&A menu items
database/schema_ispo.sql   ← new ISPO tables schema
```

---

*Session recorded by Bob (AI Assistant)*  
*Last updated: 2026-09-25*
