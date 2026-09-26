# SmartMap Layers Plan — Block + Planting Year + Variety

## Overview

Enhance `blocks_map.php` to support three switchable colour-coding modes (layers):
1. **Block Status** — existing behaviour (TBM=orange, TM=green, TR=red)
2. **Planting Year** — each year gets a distinct colour from a fixed palette; legend shows year → colour
3. **Plant Variety** — each variety gets a distinct colour; legend shows variety name → colour

Plant variety data requires an additional SQL query via the `block_plant_varieties` junction table.
All changes are self-contained inside `blocks_map.php` — no new files needed.

---

## Sub-Task 1 — Add variety data to the PHP query

**Intent:** The current SQL does not join `block_plant_varieties` or `plant_varieties`. We need the primary variety name (highest `plant_count` or first) for each block so JavaScript can colour-code by variety without a second HTTP round-trip.

**Expected Outcomes:**
- `$blocks` array contains two extra keys per block: `variety_name` (string or null) and `variety_code` (string or null)
- No visible change to the rendered map yet

**Todo List:**
1. After the main `$blocks` query, run a second query to fetch the dominant variety per block:
   ```sql
   SELECT bpv.block_id,
          pv.variety_name,
          pv.variety_code
   FROM block_plant_varieties bpv
   JOIN plant_varieties pv ON bpv.variety_id = pv.variety_id
   WHERE bpv.plant_count = (
       SELECT MAX(plant_count) FROM block_plant_varieties b2
       WHERE b2.block_id = bpv.block_id
   )
   ```
2. Index the result by `block_id` in PHP
3. Merge `variety_name` and `variety_code` into each block row before `json_encode`

**Relevant Context:**
- Main query: `blocks_map.php` lines 12–27
- Junction table: `block_plant_varieties` (block_id, variety_id, plant_count)
- Varieties table: `plant_varieties` (variety_id, variety_code, variety_name)

**Status:** [ ] pending

---

## Sub-Task 2 — Add Layer Mode switcher UI

**Intent:** Add a "Colour By" toggle above the map so the user can switch between Status / Planting Year / Variety colouring. This controls which `getColor()` function branch is active.

**Expected Outcomes:**
- A pill-style button group appears in the map controls card: `[Status] [Planting Year] [Variety]`
- Selecting a mode re-renders the map with the new colour scheme immediately
- The legend updates to match the active mode

**Todo List:**
1. Add a `<div>` with three Bootstrap toggle buttons (`id="modeStatus"`, `"modePYear"`, `"modeVariety"`) inside the existing map controls card in `blocks_map.php`
2. Track active mode in a JS variable `let colourMode = 'status'`
3. Add `change` listeners that set `colourMode` and call `applyFilters()`

**Relevant Context:**
- Map controls card: `blocks_map.php` lines 122–166
- Filter dropdowns already call `applyFilters()` on change — same pattern applies

**Status:** [ ] pending

---

## Sub-Task 3 — Implement colour palettes and getColor() logic

**Intent:** Build JS colour palettes for planting year and variety, and extend `getColor()` to return the right colour based on `colourMode`.

**Expected Outcomes:**
- Planting years (e.g. 2015–2024) each get a distinct colour from a 12-colour palette
- Varieties each get a distinct colour from a separate 12-colour palette
- Blocks with no planting year or no variety fall back to `#aaa`
- `getColor()` correctly routes to the right palette based on active mode

**Todo List:**
1. Extract all unique planting years and variety names from `blocksData` in JS at init time and build lookup maps: `yearColorMap`, `varietyColorMap`
2. Define two distinct 12-colour palettes (earthy/green tones for years, blue/purple tones for varieties) to avoid clashing with status colours
3. Update `getColor(block)` to accept the full block object and branch on `colourMode`

**Relevant Context:**
- Current `getColor()`: `blocks_map.php` lines 228–234
- Current `colors` constant: lines 215–225
- `blocksData` is available globally at line 180

**Status:** [ ] pending

---

## Sub-Task 4 — Dynamic legend

**Intent:** The legend currently shows static Status entries. It must rebuild itself whenever the colour mode changes to show the correct year or variety legend.

**Expected Outcomes:**
- In Status mode: existing legend (TBM/TM/TR/Forestry)
- In Planting Year mode: one row per year present in the current filtered view, with its colour swatch
- In Variety mode: one row per variety present in the current filtered view, with its colour swatch
- Legend updates every time filters or mode changes

**Todo List:**
1. Extract legend rendering into a JS function `updateLegend(visibleBlocks)` called from `addBlocksToMap()`
2. In Status mode render the existing 4 static rows
3. In Planting Year mode derive unique years from `visibleBlocks` and render dynamically
4. In Variety mode derive unique variety names from `visibleBlocks` and render dynamically
5. Replace the static `legend.onAdd` content with a call to `updateLegend()`

**Relevant Context:**
- Static legend: `blocks_map.php` lines 406–427
- `addBlocksToMap()` already receives the filtered block list — pass it to `updateLegend()`

**Status:** [ ] pending

---

## Sub-Task 5 — Update block popup to show variety

**Intent:** The block popup currently shows Planting Year but not variety. Add variety info to the Plantation section of the popup.

**Expected Outcomes:**
- Plantation block popups show a "Variety" row with the variety name (or "—" if none)
- No change to Forestry popup

**Todo List:**
1. In `createPopupContent()`, add a variety row inside the Plantation block to display `block.variety_name || '—'`

**Relevant Context:**
- `createPopupContent()`: `blocks_map.php` lines 237–276
- `variety_name` will be available on each block after Sub-Task 1

**Status:** [ ] pending
