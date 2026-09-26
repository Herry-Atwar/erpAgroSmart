<?php
/**
 * Agro Q&A — natural-language question answering for the Agrobusiness Solution.
 *
 * Understands questions (Indonesian / English) about:
 *   • Companies, Business Units, Divisions, Blocks
 *   • Harvest data (realizations, plans, totals)
 *   • Mill production & CPO stock
 *   • Budget & financial summaries
 *   • General counts / lookups
 *
 * Intent detection uses regex + keyword matching.
 * Optional LLM enrichment via agro/config/ai.php (OpenAI-compatible API).
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/ai.php';
require_once __DIR__ . '/config/standards.php';

// Prevent browser from caching this page so history never bleeds across requests
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$db = getDB();

// ─────────────────────────────────────────────────────────────────────────────
// Intent engine
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Parse a question string and return a structured answer array.
 *
 * @param  PDO    $db
 * @param  string $q   raw question text
 * @return array<string,mixed>
 */
function agro_resolve(PDO $db, string $q): array
{
    $norm = mb_strtolower(trim($q));

    // ── Shared keyword atoms (reused across multiple rules) ───────────────────
    $analyzeKw  = '(?:analisa|analisis|analiz[ei]|analyze|analysis)';
    $harvestKw  = '(?:panen|harvest|ffb|hasil\s+panen|hasil\s+harvest)';
    $financeKw  = '(?:keuangan|finansial|financial|finance|laporan\s+keuangan|laba\s+rugi|neraca|profit\s+(?:and\s+)?loss|income\s+statement|p&l|pl\b|balance\s+sheet|pendapatan|revenue|laba|profit)';
    $bsKw       = '(?:neraca|balance\s+sheet|laporan\s+neraca)';

    // ── 30. "Analisa Keuangan [scope]" → fetch financial summary then auto-analyze ─
    // Must be before harvest rule and rule 19 so "analisa" keyword wins.
    if (preg_match("/^$analyzeKw\\s+$financeKw\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$financeKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$financeKw\\s+$analyzeKw\\s*\$/ui", $norm)
     || preg_match("/^$financeKw\\s+(.+)\\s+$analyzeKw\$/ui", $norm, $m)) {
        $rawPlace = trim($m[1] ?? '');
        $place = agro_clean($rawPlace);
        $res = agro_financial_summary($db, $q, $place);
        $res['auto_analyze'] = true;
        // Flag when the question is specifically about the balance sheet
        if (preg_match("/$bsKw/ui", $norm)) {
            $res['bs_focus'] = true;
        }
        return $res;
    }

    // ── 30b. Standalone finance report phrases → financial_summary ───────────
    // "Profit and loss report" / "Laporan laba rugi" / "Income statement"
    // "P&L report" / "Financial report [scope]"
    // These match when the phrase is JUST a finance keyword (possibly + "report/laporan")
    // with no separate "analyze" word — they produce the data view (not auto_analyze).
    $finReportKw = '(?:report|laporan|statement|ringkasan|summary)';
    if (preg_match("/^$financeKw(?:\\s+$finReportKw)?\\s*\$/ui", $norm)
     || preg_match("/^$finReportKw\\s+$financeKw\\s*\$/ui", $norm)
     || preg_match("/^$financeKw\\s+$finReportKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$finReportKw\\s+$financeKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m)) {
        $rawPlace = trim($m[1] ?? '');
        $place = agro_clean($rawPlace);
        $res = agro_financial_summary($db, $q, $place);
        $res['auto_analyze'] = true;
        if (preg_match("/$bsKw/ui", $norm)) {
            $res['bs_focus'] = true;
        }
        return $res;
    }

    // ── 29. "Analisa Hasil Panen [scope]" → fetch harvest then auto-analyze ───
    // Must be FIRST so it wins before the generic harvest rules (rule 1 / rule 58)
    // which would otherwise match "hasil panen" mid-string and route to harvest_total.
    if (preg_match("/^$analyzeKw\\s+$harvestKw\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$harvestKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$harvestKw\\s+$analyzeKw\\s*\$/ui", $norm)
     || preg_match("/^$harvestKw\\s+(.+)\\s+$analyzeKw\$/ui", $norm, $m)) {
        $rawPlace = trim($m[1] ?? '');
        $place = agro_clean($rawPlace);
        $res = agro_harvest_summary($db, $q, $place);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 1. Harvest total for a division or block ──────────────────────────────
    // "total panen Afdeling A" / "berapa panen di Afdeling B?" / "harvest total Block 01"
    // bare:   "Panen" alone → all-up harvest summary
    // scoped: "Panen ANP" / "Panen di Riau Estate" → harvest summary for that BU/company
    // bare harvest summary (single word)
    if (preg_match('/^(?:panen|harvest|ffb|hasil\s+panen)\s*$/ui', $norm)) {
        return agro_harvest_summary($db, $q, '');
    }
    // scoped harvest summary: "panen ANP" / "panen di Riau Estate"
    if (preg_match('/^(?:panen|harvest|ffb|hasil\s+panen)\s+(?:di\s+|in\s+|of\s+|at\s+)?(.+)$/ui', $norm, $m)) {
        $place = agro_clean(trim($m[1]));
        if (strlen($place) >= 2) {
            return agro_harvest_summary($db, $q, $place);
        }
    }
    // Uses non-greedy connector: optional "di/for/of" then scope
    if (preg_match('/(?:total|jumlah|berapa|how much)\s+(?:panen|harvest|ffb|hasil\s+panen)\s+(?:di\s+|for\s+|of\s+)?(.+)/ui', $norm, $m)
     || preg_match('/(?:panen|harvest|ffb|hasil\s+panen)\s+(?:total\s+)?(?:di\s+|for\s+|of\s+)?(?!dan\s+(?:pengangkutan|angkut|transport|delivery|pengiriman))(.+)/ui', $norm, $m)) {
        $place = agro_clean($m[1]);
        return agro_harvest_total($db, $place, $q);
    }

    // ── 2. Blocks in a division ───────────────────────────────────────────────
    // "sebutkan blok di Afdeling A" / "list blocks in Afdeling B"
    if (preg_match('/(?:daftar|sebutkan|list|tampilkan)\s+(?:semua\s+)?(?:blok|blocks?)\s+(?:di|in)\s+(.+)/ui', $norm, $m)
     || preg_match('/(?:blok|blocks?)\s+(?:di|in|dalam|pada)\s+(.+)/ui', $norm, $m)) {
        $place = agro_clean($m[1]);
        return agro_blocks_in_division($db, $place, $q);
    }

    // ── 3. Divisions in a business unit / estate ─────────────────────────────
    // "sebutkan divisi di Riau Estate" / "afdeling in Jambi Estate"
    if (preg_match('/(?:daftar|sebutkan|list|tampilkan)\s+(?:semua\s+)?(?:divisi|afdeling|division)\s+(?:di|in)\s+(.+)/ui', $norm, $m)
     || preg_match('/(?:divisi|afdeling|division)\s+(?:di|in|dalam|pada)\s+(.+)/ui', $norm, $m)) {
        $place = agro_clean($m[1]);
        return agro_divisions_in_bu($db, $place, $q);
    }

    // ── 4. Business units (estates) in a company ─────────────────────────────
    // "estate apa saja di PTPN?" / "list business units in PT Agro Nusantara"
    if (preg_match('/(?:daftar|sebutkan|list|tampilkan)\s+(?:semua\s+)?(?:estate|business\s*unit[s]?|kebun|mill)\s+(?:di|in)\s+(.+)/ui', $norm, $m)
     || preg_match('/(?:estate|business\s*unit[s]?|kebun)\s+(?:apa\s+saja\s+)?(?:di|in)\s+(.+)/ui', $norm, $m)) {
        $place = agro_clean($m[1]);
        return agro_bus_in_company($db, $place, $q);
    }

    // ── 5. Count blocks / ha in a division ───────────────────────────────────
    // "berapa blok di Afdeling A?" — must come BEFORE the generic blocks intent
    if (preg_match('/(?:berapa|how many|jumlah)\s+(?:blok|blocks?)\s+(?:di|in)\s+(.+)/ui', $norm, $m)) {
        $place = agro_clean($m[1]);
        return agro_count_blocks($db, $place, $q);
    }

    // ── 12. Area table by division — must come BEFORE generic area intent ──────
    // "area per divisi Riau Estate" / "tabel luas ANP berdasarkan divisi"
    // "tampilkan data-data area di ANP" / "show data area ANP"
    $aKw = '(?:luas|area|hektar|ha)';
    $tKw = '(?:tabel|table|rincian|breakdown|rekap|rekapitulasi|detail)';
    $bKw = '(?:berdasarkan|per|by)';
    $dKw = '(?:divisi|division|afdeling)(?:nya)?';
    $scopeCapture = '(?:di\s+|of\s+|in\s+)?(.+)';
    if (
        preg_match("/$tKw\\s+$aKw(?:\\s+$aKw)?\\s+$scopeCapture/ui", $norm, $m)
     || preg_match("/$tKw\\s+$aKw\\s+(?:$bKw\\s+)?$dKw\\s+$scopeCapture/ui", $norm, $m)
     || preg_match("/$aKw\\s+$bKw\\s+$dKw\\s+$scopeCapture/ui", $norm, $m)
     || preg_match("/^(.+?)\\s+$aKw\\s+(?:$bKw\\s+)?$dKw/ui", $norm, $m)
        // "tampilkan/show/lihat data(-data)? area/luas di/in/of <scope>"
     || preg_match('/(?:tampil(?:kan)?|show|lihat|display)\s+(?:data(?:-\w+)?\s+)+(?:area|luas|hektar)\s+(?:di\s+|in\s+|of\s+)?(.+)/ui', $norm, $m)
        // "data(-data)? area/luas di <scope>"
     || preg_match('/\bdata(?:-\w+)?\s+(?:data(?:-\w+)?\s+)*(?:area|luas|hektar)\s+(?:di\s+|in\s+|of\s+)(.+)/ui', $norm, $m)
    ) {
        $place = agro_clean(trim($m[1]));
        if (strlen($place) >= 2) {
            return agro_area_by_division($db, $place, $q);
        }
    }

    // ── 6. Total area of a division / BU / company ───────────────────────────
    // "berapa luas Jambi Estate?" / "total area Riau Estate" / "luas PT Agro Nusantara"
    // "tampilkan data area perkebunan" / "data area semua kebun" (scopeless → all companies)
    if (preg_match('/(?:berapa|how (?:much|large|big)|total)\s+(?:luas|area)\s+(?:area\s+)?(.+)/ui', $norm, $m)
     || preg_match('/(?:luas|area)\s+(?:area\s+)?(?:total\s+)?(?:divisi|afdeling|division)\s+(.+)/ui', $norm, $m)
     || preg_match('/^(?:luas|area)\s+(.+)/ui', $norm, $m)) {
        $place = agro_clean($m[1]);
        return agro_area($db, $place, $q);
    }
    // Scopeless: "tampilkan data area perkebunan" / "data-data area perkebunan" / "ringkasan luas kebun"
    // bare: "Area" or "Luas" alone
    if (preg_match('/\b(?:data(?:-\w+)?|tampil(?:kan)?|show|ringkasan|summary|rekap|laporan)\b.{0,30}\b(?:area|luas|hektar)\b.{0,20}\b(?:perkebunan|kebun|estate|seluruh|semua|all)\b/ui', $norm)
     || preg_match('/\b(?:area|luas|hektar)\b.{0,20}\b(?:perkebunan|kebun|seluruh|semua|all|total)\b\s*$/ui', $norm)
     || preg_match('/\b(?:data(?:-\w+)?|tampil(?:kan)?|show|ringkasan|rekap)\b.{0,30}\b(?:luas|area)\b\s*$/ui', $norm)
     || preg_match('/^(?:area|luas|hektar)\s*$/ui', $norm)) {
        return agro_area($db, '', $q);
    }

    // ── 7b. Nursery / Pembibitan summary ─────────────────────────────────────
    // "data pembibitan" / "tampilkan nursery" / "statistik bibit" / "ringkasan pembibitan"
    // scoped: "pembibitan ANP" / "nursery Riau Estate" / "tampilkan pembibitan di ANP"
    // bare:   "Pembibitan" alone → "Tampilkan data Pembibitan"
    // NOTE: skip if "per batch / menurut batch" present — rule 27b handles those.
    $nurseryBatchKw = '(?:per\s+batch|menurut\s+batch|berdasarkan\s+batch|by\s+batch|batch\s+pivot|pivot\s+batch)';
    if (!preg_match("/$nurseryBatchKw/ui", $norm)) {
        if (preg_match('/\b(?:data|tampil(?:kan)?|ringkasan|summary|statistik|laporan|rekap|show|list)\b.{0,25}\b(?:pembibitan|nursery|bibit|persemaian|kecambah|germina)\b\s+(?:di\s+|in\s+|of\s+|at\s+)?(.+)/ui', $norm, $m)
         || preg_match('/\b(?:pembibitan|nursery)\b\s+(?:di\s+|in\s+|of\s+|at\s+)?(.+)/ui', $norm, $m)) {
            $place = agro_clean(trim($m[1]));
            if (strlen($place) >= 2) {
                return agro_nursery_summary($db, $q, $place);
            }
        }
        if (preg_match('/\b(?:data|tampil(?:kan)?|ringkasan|summary|statistik|laporan|rekap|show|list)\b.{0,25}\b(?:pembibitan|nursery|bibit|persemaian|kecambah|germina)\b/ui', $norm)
         || preg_match('/\b(?:pembibitan|nursery|bibit|persemaian)\b.{0,20}\b(?:data|ringkasan|summary|statistik|laporan|rekap)\b/ui', $norm)
         || preg_match('/^(?:pembibitan|nursery)\s*$/ui', $norm)) {
            return agro_nursery_summary($db, $q);
        }
    }

    // ── 7a. Rendemen CPO & PK ─────────────────────────────────────────────────
    // "rendemen CPO" / "rendemen PK" / "OER KER" / "extraction rate" / "tampilkan rendemen"
    // "rendemen CPO bulan Juni 2026" / "OER KER 25-28 Januari 2026"
    if (preg_match('/\b(?:rendemen|oer|ker|extraction\s+rate|oil\s+extraction|kernel\s+extraction)\b/ui', $norm)
     || preg_match('/\brendemen\b.{0,20}\b(?:cpo|pk|kernel|minyak)\b/ui', $norm)
     || preg_match('/\b(?:cpo|pk|kernel|minyak)\b.{0,20}\brendemen\b/ui', $norm)) {
        [, $rDateFrom, $rDateTo, $rDateLabel] = agro_extract_date_filter($norm);
        return agro_rendemen($db, $q, $rDateFrom, $rDateTo, $rDateLabel);
    }

    // ── 7. Mill production summary ────────────────────────────────────────────
    // "produksi pabrik bulan ini" / "mill production this month" / "summary produksi"
    if (preg_match('/(?:produksi|production|output)\b.{0,40}(?:pabrik|mill|cpo|kernel)\b/ui', $norm)
     || preg_match('/(?:pabrik|mill)\b.{0,40}(?:produksi|production|output)\b/ui', $norm)) {
        return agro_mill_production($db, $q);
    }

    // ── 8. CPO / kernel stock ─────────────────────────────────────────────────
    // "stok CPO" / "berapa stok kernel?" / "cpo stock"
    if (preg_match('/(?:stok|stock|persediaan|inventory)\b.{0,20}(?:cpo|kernel|minyak)\b/ui', $norm)
     || preg_match('/(?:cpo|kernel)\b.{0,20}(?:stok|stock|persediaan|inventory)\b/ui', $norm)) {
        return agro_cpo_stock($db, $q);
    }

    // ── 9. Harvest plans for a block ──────────────────────────────────────────
    // "rencana panen Block 01" / "harvest plan for BLK-01"
    if (preg_match('/(?:rencana|plan)\b.{0,20}(?:panen|harvest)\b.{0,20}(?:blok|block|blk)?\s*(.+)/ui', $norm, $m)
     || preg_match('/(?:panen|harvest)\b.{0,20}(?:rencana|plan)\b.{0,20}(?:blok|block|blk)?\s*(.+)/ui', $norm, $m)) {
        $block = agro_clean($m[1]);
        return agro_harvest_plans($db, $block, $q);
    }

    // ── 10. Find a block by name/code ─────────────────────────────────────────
    // "cari blok BLK-01" / "find block 01" / "info blok 01"
    if (preg_match('/(?:cari|find|search|info|detail|dimana|where is)\s+(?:blok|block|blk)?\s*(.+)/ui', $norm, $m)) {
        $block = agro_clean($m[1]);
        return agro_find_block($db, $block, $q);
    }

    // ── 11. Companies list ────────────────────────────────────────────────────
    // "daftar perusahaan" / "list companies"
    if (preg_match('/(?:daftar|list|sebutkan|tampilkan)\s+(?:semua\s+)?(?:perusahaan|company|companies)\b/ui', $norm)
     || preg_match('/(?:perusahaan|company|companies)\b.{0,20}(?:apa saja|ada apa|list)\b/ui', $norm)) {
        return agro_companies($db, $q);
    }

    // ── 12. Area table by division for a BU / company ────────────────────────
    // Patterns (all case-insensitive):
    //   "tabel luas [area] di ANP"  /  "buatkan tabel luas area di ANP berdasarkan divisi"
    //   "rincian luas per divisi Riau Estate"  /  "area per divisi ANP"
    //   "luas area berdasarkan divisi di ANP"  /  "breakdown area ANP by division"
    $divKeyword  = '(?:divisi|division|afdeling)(?:nya)?';
    $areaKeyword = '(?:luas|area|hektar|ha)';
    $tableKw     = '(?:tabel|table|rincian|breakdown|rekap|rekapitulasi|detail)';
    $byKw        = '(?:berdasarkan|per|by)';
    $scopeCapture = '(?:di\s+|of\s+|in\s+)?(.+)';
    if (
        // "tabel luas [area] di <scope>" / "buatkan tabel luas area di <scope>"
        preg_match("/$tableKw\\s+$areaKeyword(?:\\s+$areaKeyword)?\\s+$scopeCapture/ui", $norm, $m)
        // "rincian/tabel luas [per] divisi [di] <scope>"
     || preg_match("/$tableKw\\s+$areaKeyword\\s+(?:$byKw\\s+)?$divKeyword\\s+$scopeCapture/ui", $norm, $m)
        // "area/luas per divisi <scope>"
     || preg_match("/$areaKeyword\\s+$byKw\\s+$divKeyword\\s+$scopeCapture/ui", $norm, $m)
        // "area/luas berdasarkan divisi [di] <scope>"
     || preg_match("/$areaKeyword\\s+$byKw\\s+$divKeyword\\s+$scopeCapture/ui", $norm, $m)
        // "<scope> area per divisi" — scope first
     || preg_match("/^(.+?)\\s+$areaKeyword\\s+(?:$byKw\\s+)?$divKeyword/ui", $norm, $m)
    ) {
        $place = agro_clean(trim($m[1]));
        // Make sure we didn't accidentally capture a very short noise token
        if (strlen($place) >= 2) {
            return agro_area_by_division($db, $place, $q);
        }
    }
    // bare "tabel luas area" / "luas area" / "area table" with no scope → use session scope
    if (preg_match("/^$tableKw\\s+$areaKeyword(?:\\s+$areaKeyword)?\\s*\$/ui", $norm)
     || preg_match("/^$areaKeyword\\s+$byKw\\s+$divKeyword\\s*\$/ui", $norm)
     || preg_match('/^(?:luas\s+area|area\s+table|area\s+per\s+divisi|luas\s+per\s+divisi)\s*$/ui', $norm)) {
        return agro_area_by_division($db, '', $q);
    }

    // ── 13. Harvest ranking (top blocks) ──────────────────────────────────────
    // "blok terbaik" / "top harvest blocks" / "blok dengan panen terbanyak"
    if (preg_match('/(?:top|terbaik|terbanyak|tertinggi|ranking|peringkat|best)\b.{0,30}(?:blok|block|panen|harvest)\b/ui', $norm)
     || preg_match('/(?:blok|block)\b.{0,30}(?:top|terbaik|terbanyak|tertinggi|ranking|best)\b/ui', $norm)) {
        return agro_top_blocks($db, $q);
    }

    // ── 15. Plant density table ───────────────────────────────────────────────
    // "kerapatan tanaman di ANP berdasarkan divisi"
    // "tabel kerapatan Riau Estate" / "plant density ANP"
    // Strategy: find the density keyword, then capture scope words after any
    // optional connector (di/in/of/at + optional type words)
    // ── 15. Plant density table ───────────────────────────────────────────────
    // Strategy: strip all known filler words around the density keyword,
    // then capture what remains as the scope name.
    if (preg_match('/(?:tabel|table|rincian|rekap|breakdown|buat(?:kan)?)\s+(?:tabel\s+)?(?:kerapatan|density|populasi|population|spjp|spp)\s+(?:tanaman\s+)?(?:di\s+)?(.+)/ui', $norm, $m)
     || preg_match('/(?:kerapatan|density|populasi|population|spjp|spp)\s+(?:tanaman\s+|plants?\s+|pohon\s+|tree\s+)?(?:di\s+|of\s+|in\s+)?(.+)/ui', $norm, $m)) {
        $place = agro_clean(trim($m[1]));
        if (strlen($place) >= 2) {
            return agro_plant_density($db, $place, $q);
        }
    }

    // ── 18. Map of blocks for a scope ─────────────────────────────────────────
    // "tampilkan peta blok ANP" / "peta block Riau Estate" / "show map ANP"
    if (preg_match('/(?:tampil(?:kan)?|lihat|buka|show|open|view|display)?\s*(?:peta|map)\s+(?:blok?|blocks?|kebun|estate)?\s*(?:di\s+|of\s+|for\s+)?(.+)/ui', $norm, $m)
     || preg_match('/(?:peta|map)\s+(?:blok?|blocks?|kebun)?\s*(?:di\s+|of\s+)?(.+)/ui', $norm, $m)) {
        $place = agro_clean($m[1]);
        if (strlen($place) >= 2) {
            return agro_map_link($db, $place, $q);
        }
    }

    // ── 17. Roads — panjang & luas per divisi (default: menurut divisi) ─────────
    // Triggers on ANY road question; always groups result by division.
    // Patterns covered:
    //   "panjang jalan di ANP"  /  "data jalan Riau Estate"
    //   "tabel panjang dan luas jalan di ANP"
    //   "panjang dan luas jalan ANP menurut divisi"
    //   "jenis jalan di Riau Estate"  /  "road type ANP"
    //   "tampilkan jalan ANP"  /  "luas jalan per divisi ANP"
    $roadScope = null;
    // Pattern A — scope follows di/in/at/pada
    if (preg_match('/(?:jalan|road)\b.{0,50}(?:jenis|type)\b.{0,30}(?:di|in|at|pada)\s+(.+)/ui', $norm, $m)
     || preg_match('/(?:jenis|type)\b.{0,10}(?:jalan|road)\b.{0,30}(?:di|in|at|pada)\s+(.+)/ui', $norm, $m)
     || preg_match('/(?:panjang|jumlah|luas|tabel|table|data|tampil(?:kan)?|length|area)\s+(?:(?:panjang|luas|jumlah|dan)\s+)*(?:panjang|luas|jumlah|dan\s+)?jalan\b.{0,40}(?:di|in|at|pada)\s+(.+)/ui', $norm, $m)
     || preg_match('/road\s+(?:type|length|count|area|data)\b.{0,30}(?:in|at|by\s+division\s+of|division|of)?\s+(.+)/ui', $norm, $m)
     || preg_match('/\bjalan\b.{0,30}(?:di|in|at|pada)\s+(.+)/ui', $norm, $m)) {
        $raw = $m[1];
        $raw = preg_replace('/\s+(?:berdasarkan\s+(?:jenis|divisi)(?:nya)?|per\s+(?:jenis|divisi)(?:nya)?|menurut\s+(?:jenis|divisi)(?:nya)?|by\s+(?:type|division)|jenis(?:nya)?|divisi(?:nya)?|nya)\s*$/iu', '', $raw) ?? $raw;
        $roadScope = agro_clean($raw);
    }
    // Pattern B — scope sits BEFORE connector or at end of phrase (no "di")
    //   "panjang dan luas jalan ANP menurut divisi"
    //   "panjang jalan ANP per divisi"
    //   "data jalan Riau Estate"
    //   "tabel jalan ANP"
    if (!$roadScope) {
        if (preg_match('/(?:panjang|luas|jumlah|data|tabel|table|tampil(?:kan)?|show|list)\s+(?:(?:panjang|luas|jumlah|dan)\s+)*(?:panjang|luas|jumlah|dan\s+)?jalan\s+(.+?)\s+(?:menurut|per|berdasarkan)\s+(?:divisi|jenis|type)/ui', $norm, $m)
         || preg_match('/(?:panjang|luas|jumlah|data|tabel|table|tampil(?:kan)?|show|list)\s+(?:(?:panjang|luas|jumlah|dan)\s+)*(?:panjang|luas|jumlah|dan\s+)?jalan\s+(\S+(?:\s+\S+){0,3})\s*$/ui', $norm, $m)) {
            $roadScope = agro_clean($m[1]);
        }
    }
    // Pattern C — bare "panjang jalan" / "data jalan" / "road length" with no scope → use session scope
    if (!$roadScope) {
        if (preg_match('/^(?:panjang|luas|data|tabel|table|tampil(?:kan)?|show|list)\s+(?:(?:panjang|luas|dan)\s+)*jalan\s*$/ui', $norm)
         || preg_match('/^(?:road|roads)\s*(?:length|data|type|types)?\s*$/ui', $norm)
         || preg_match('/^jalan\s*$/ui', $norm)) {
            return agro_road_by_type($db, '', $q);
        }
    }
    if ($roadScope && strlen($roadScope) >= 2) {
        return agro_road_by_type($db, $roadScope, $q);
    }

    // ── 16. Bridge / culvert count for a scope ────────────────────────────────
    // "jumlah jembatan di ANP" / "berapa jembatan Riau Estate?" / "bridge count ANP"
    if (preg_match('/(?:jumlah|berapa|how many|count)\s+(?:jembatan|bridges?|culverts?|gorong-gorong)\s+(?:di\s+|in\s+|at\s+)?(.+)/ui', $norm, $m)
     || preg_match('/(?:jembatan|bridges?|culverts?)\s+(?:di|in|at|pada)\s+(.+)/ui', $norm, $m)) {
        $place = agro_clean($m[1]);
        return agro_bridge_count($db, $place, $q);
    }
    // bare "jumlah jembatan" / "data jembatan" / "bridge" with no scope → use session scope
    if (preg_match('/^(?:jumlah|berapa|data|tabel|tampil(?:kan)?|show|list|count)?\s*(?:jembatan|bridges?|culverts?|gorong-gorong)\s*$/ui', $norm)) {
        return agro_bridge_count($db, '', $q);
    }

    // ── 14. Chart of last answer ───────────────────────────────────────────────
    // "tampilkan grafik" / "buat grafik pie" / "grafik batang" / "chart donut"
    // "buat grafik pie 3d" / "pie chart 3D" / "grafik batang 3D" / "bar 3D"
    if (preg_match('/(?:tampil(?:kan)?|buat(?:kan)?|show|display|lihat|create|make)?\s*(?:grafik|chart|diagram|visualisasi|graph)\s*(pie|donut|doughnut|batangnya?|bar|line|garis)?\s*(?:3d|tiga\s*dimensi|3\s*dimensi)?\b/ui', $norm, $m)
     || preg_match('/(?:pie|donut|doughnut)\s*(?:chart|grafik|graph)?\s*(?:3d|tiga\s*dimensi)?\b/ui', $norm, $m)
     || preg_match('/(?:3d|tiga\s*dimensi)\s*(?:pie|donut|doughnut)\s*(?:chart|grafik|graph)?\b/ui', $norm, $m)
     || preg_match('/(?:batangnya?|bar|kolom|column)\s*(?:chart|grafik|graph)?\s*(?:3d|tiga\s*dimensi)\b/ui', $norm, $m)
     || preg_match('/(?:3d|tiga\s*dimensi)\s*(?:batangnya?|bar|kolom|column)\s*(?:chart|grafik|graph)?\b/ui', $norm, $m)) {
        // Detect chart sub-type from the question
        $raw = mb_strtolower(implode(' ', $m) . ' ' . $norm); // include full norm for 3d keyword
        $chartSubtype = 'bar'; // default
        if (preg_match('/\b(?:pie|donut|doughnut)\b/ui', $raw)) {
            $chartSubtype = preg_match('/\b3d\b|tiga\s*dimensi/ui', $norm) ? 'pie3d' : 'pie';
        } elseif (preg_match('/\b(?:line|garis)\b/ui', $raw)) {
            $chartSubtype = 'line';
        } elseif (preg_match('/\b(?:batangnya?|bar|kolom|column)\b/ui', $raw)) {
            $chartSubtype = preg_match('/\b3d\b|tiga\s*dimensi/ui', $norm) ? 'bar3d' : 'bar';
        }
        return ['type' => 'chart_request', 'question' => $q, 'subtype' => $chartSubtype];
    }

    // ── 21. Browse full standards library ─────────────────────────────────────
    // "lihat semua standar GAPKI" / "daftar standar" / "standar apa saja?"
    if (preg_match('/\b(?:lihat|tampil(?:kan)?|daftar|list|show|semua|all)\b.{0,20}\b(?:standar|standard|benchmark|norm)\b/ui', $norm)
     || preg_match('/\bstandar\s+(?:apa\s+saja|yang\s+ada|lengkap|semua|list)\b/ui', $norm)) {
        return ['type' => 'standards_list', 'question' => $q];
    }

    // ── 22. Seed / variety usage table ───────────────────────────────────────
    // "tabel bibit di ANP" / "varietas yang digunakan di Riau Estate"
    // "daftar bibit ANP" / "seed variety ANP" / "bibit apa saja di ANP"
    $seedKw  = '(?:bibit|benih|varietas|variety|varieties|seed(?:ling)?s?)';
    $tableKw = '(?:tabel|table|daftar|list|sebutkan|tampilkan|rekap|show|data(?:-\w+)?|gunakan|digunakan|apa\s+saja|used?)';
    $scopeC  = '(?:di\s+|in\s+|of\s+|at\s+|pada\s+)?(.+)';
    if (
        preg_match("/$tableKw\\s+(?:\\s*$seedKw\\s+)?$seedKw\\s+$scopeC/ui", $norm, $m)
     || preg_match("/$seedKw\\s+(?:yang\\s+)?(?:digunakan|used|ada|tersedia|ditanam)\\s+$scopeC/ui", $norm, $m)
     || preg_match("/$seedKw\\s+$scopeC/ui", $norm, $m)
    ) {
        $place = agro_clean(trim($m[1]));
        if (strlen($place) >= 2) {
            return agro_seed_varieties($db, $place, $q);
        }
    }

    // ── Common keyword patterns shared across rules 23 / 26 / 28 ──────────────
    // ($analyzeKw is already defined at the top of agro_resolve)
    $chemKw  = '(?:bahan\s+kimia|kimia|pestisida|pesticide|herbisida|herbicide|fungisida|fungicide|insektisida|insecticide|rodentisida|rodenticide|chemical(?:s)?'
             . '|hama(?:\s+dan\s+penyakit)?|penyakit(?:\s+tanaman)?'
             . '|pemberantasan(?:\s+hama(?:\s+dan\s+penyakit)?)?'
             . '|pengendalian\s+(?:hama(?:\s+dan\s+penyakit)?|opt|penyakit|organisme)'
             . '|opt|organisme\s+pengganggu\s+tanaman|pest(?:\s+(?:and\s+)?disease)?|disease(?:\s+control)?)';
    $chemTbl = '(?:tabel|table|daftar|list|sebutkan|tampilkan|rekap|show|data(?:-\w+)?|gunakan|digunakan|apa\s+saja|used?|penggunaan|pemakaian)';
    $scopeC2 = '(?:di\s+|in\s+|of\s+|at\s+|pada\s+)?(.+)';

    // ── 36. "Analisa Keberlanjutan [scope]" → sustainability analysis ─────────
    // Triggers: "Analisa Keberlanjutan" / "Sustainability Analysis"
    //           "Analisa ISPO" / "Analisa RSPO" / "Analisa Lingkungan"
    //           "Analisa Konservasi" / "Analisa HCV" / "Analisa Carbon"
    $sustainKw = '(?:keberlanjutan|sustainability|sustainable|ispo|rspo'
               . '|lingkungan\s+hidup|lingkungan(?:\s+hidup)?|environmental'
               . '|konservasi|conservation|hcv|hcs|high\s+carbon\s+stock'
               . '|karbon|carbon(?:\s+stock)?|emisi|emission'
               . '|area\s+non.?planted|lahan\s+non.?planted|non.?planted'
               . '|perlindungan\s+lingkungan|kawasan\s+(?:lindung|konservasi|penyangga)'
               . '|buffer\s+zone|sempadan\s+sungai|riparian'
               . ')';
    if (preg_match("/^$analyzeKw\\s+$sustainKw\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$sustainKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$sustainKw\\s+$analyzeKw\\s*\$/ui", $norm)
     || preg_match("/^$sustainKw\\s+(.+)\\s+$analyzeKw\$/ui", $norm, $m)) {
        $rawPlace = trim($m[1] ?? '');
        $place    = agro_clean($rawPlace);
        if (strlen($place) >= 2 || $place === '') {
            $res = agro_sustainability_analysis($db, $place, $q);
            $res['auto_analyze'] = true;
            return $res;
        }
    }

    // ── 35. "Analisa Perkebunan [scope]" → plantation analysis ───────────────
    // Triggers: "Analisa Perkebunan" / "Plantation Analysis" / "Analisa Kebun ANP"
    //           "Analisa Tanaman" / "Analisa Populasi Tanaman"
    $plantKw = '(?:perkebunan|plantation|kebun(?:\s+kelapa\s+sawit)?|populasi\s+tanaman'
             . '|kondisi\s+tanaman|analisa\s+blok|block\s+analysis'
             . '|kerapatan\s+tanaman|sph|stand\s+per\s+hectare'
             . '|tanaman\s+menghasilkan\s*(?:tm)?|productivit(?:as|y)\s+(?:tbs|ffb|kebun)'
             . '|luas\s+(?:kebun|afdeling|divisi|blok)(?:\s+analisa)?'
             . '|rasio\s+(?:tm|tanaman|lahan)'
             . ')';
    if (preg_match("/^$analyzeKw\\s+$plantKw\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$plantKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$plantKw\\s+$analyzeKw\\s*\$/ui", $norm)
     || preg_match("/^$plantKw\\s+(.+)\\s+$analyzeKw\$/ui", $norm, $m)) {
        $rawPlace = trim($m[1] ?? '');
        $place    = agro_clean($rawPlace);
        if (strlen($place) >= 2 || $place === '') {
            $res = agro_plantation_analysis($db, $place, $q);
            $res['auto_analyze'] = true;
            return $res;
        }
    }

    // ── 34d. "Analisa Gulma per Tahun Tanam [scope]" → planting-year pivot ──
    // Must come BEFORE 34c, 34b AND 34 so "per tahun tanam" wins.
    $weedKw  = '(?:gulma|weed(?:ing|s)?|pengendalian\s+gulma|weed\s+control|herbisida\b|alang-alang|mikania|teki)';
    $wPyPivP = '(?:per\s+tahun\s+tanam|menurut\s+tahun\s+tanam|berdasarkan\s+tahun\s+tanam|by\s+planting\s+year|per\s+tahun|menurut\s+tahun|pivot\s+tahun\s+tanam|tahun\s+tanam\s+pivot)';
    if (
        preg_match("/^$analyzeKw\\s+$weedKw\\s+$wPyPivP\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$weedKw\\s+$wPyPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$analyzeKw\\s+$weedKw\\s+(.+?)\\s+$wPyPivP\\s*\$/ui", $norm, $m)
     || preg_match("/^$weedKw\\s+$wPyPivP\\s*\$/ui", $norm)
     || preg_match("/^$weedKw\\s+$wPyPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$weedKw\\s+(.+?)\\s+$wPyPivP\\s*\$/ui", $norm, $m)
    ) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_weed_by_planting_year($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 34c. "Analisa Gulma per Divisi [scope]" → per-division pivot ─────────
    // Must come BEFORE 34b AND 34 so "per divisi / menurut divisi" wins.
    $wDivPivP = '(?:menurut\s+divisi|per\s+divisi|by\s+division|per\s+afdeling|menurut\s+afdeling|pivot\s+divisi|berdasarkan\s+divisi)';
    if (
        preg_match("/^$analyzeKw\\s+$weedKw\\s+$wDivPivP\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$weedKw\\s+$wDivPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$analyzeKw\\s+$weedKw\\s+(.+?)\\s+$wDivPivP\\s*\$/ui", $norm, $m)
     || preg_match("/^$weedKw\\s+$wDivPivP\\s*\$/ui", $norm)
     || preg_match("/^$weedKw\\s+$wDivPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$weedKw\\s+(.+?)\\s+$wDivPivP\\s*\$/ui", $norm, $m)
    ) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_weed_by_division($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 34b. "Analisa Gulma per Blok [scope]" → per-block pivot ──────────────
    // Must come BEFORE rule 34 so "per blok / menurut blok" wins.
    $wBlokPivP = '(?:menurut\s+blok|per\s+blok|by\s+block|pivot\s+blok|pivot\s+block|berdasarkan\s+blok|blok\s+pivot)';
    if (
        preg_match("/^$analyzeKw\\s+$weedKw\\s+$wBlokPivP\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$weedKw\\s+$wBlokPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$analyzeKw\\s+$weedKw\\s+(.+?)\\s+$wBlokPivP\\s*\$/ui", $norm, $m)
     || preg_match("/^$weedKw\\s+$wBlokPivP\\s*\$/ui", $norm)
     || preg_match("/^$weedKw\\s+$wBlokPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$weedKw\\s+(.+?)\\s+$wBlokPivP\\s*\$/ui", $norm, $m)
    ) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_weed_by_block($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 34. "Analisa Gulma [scope]" → weed-focused analysis ─────────────────
    // Must come BEFORE rule 33 (generic pest) so weed keyword wins.
    // Triggers: "Analisa Gulma" / "Analisa Weed" / "Analisa Pengendalian Gulma"
    //           "Analisa Gulma ANP" / "Weed Analysis Riau Estate"
    if (preg_match("/^$analyzeKw\\s+$weedKw\\s*\$/ui", $norm)
     || (preg_match("/^$analyzeKw\\s+$weedKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m) && !preg_match("/$wDivPivP|$wBlokPivP|$wPyPivP/ui", $m[1] ?? ''))
     || preg_match("/^$weedKw\\s+$analyzeKw\\s*\$/ui", $norm)
     || (preg_match("/^$weedKw\\s+(.+)\\s+$analyzeKw\$/ui", $norm, $m) && !preg_match("/$wDivPivP|$wBlokPivP|$wPyPivP/ui", $m[1] ?? ''))) {
        $rawPlace = trim($m[1] ?? '');
        $place    = agro_clean($rawPlace);
        if (strlen($place) >= 2 || $place === '') {
            $res = agro_weed_analysis($db, $place, $q);
            $res['auto_analyze'] = true;
            return $res;
        }
    }

    // ── 33d. "Analisa Hama & Penyakit per Tahun Tanam [scope]" → per-planting-year pivot ──
    // Must come BEFORE 33c, 33b AND 33 so "per tahun tanam" wins.
    $pestKw    = '(?:hama(?:\s+(?:dan\s+)?(?:&\s+)?penyakit)?|penyakit(?:\s+tanaman)?|opt\b|organisme\s+pengganggu\s+tanaman|pest(?:\s+(?:(?:and\s+|&\s+)?(?:disease|control|penyakit)|\s*&\s*(?:disease|control|penyakit)))?|disease(?:\s+control)?|pengendalian\s+(?:hama|opt|penyakit)|infestation)';
    $pyPivP    = '(?:per\s+tahun\s+tanam|menurut\s+tahun\s+tanam|berdasarkan\s+tahun\s+tanam|by\s+planting\s+year|per\s+tahun|menurut\s+tahun|pivot\s+tahun\s+tanam|tahun\s+tanam\s+pivot)';
    if (
        preg_match("/^$analyzeKw\\s+$pestKw\\s+$pyPivP\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$pestKw\\s+$pyPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$analyzeKw\\s+$pestKw\\s+(.+?)\\s+$pyPivP\\s*\$/ui", $norm, $m)
     || preg_match("/^$pestKw\\s+$pyPivP\\s*\$/ui", $norm)
     || preg_match("/^$pestKw\\s+$pyPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$pestKw\\s+(.+?)\\s+$pyPivP\\s*\$/ui", $norm, $m)
    ) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_pest_by_planting_year($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 33c. "Analisa Hama & Penyakit per Divisi [scope]" → per-division pivot ──
    // Must come BEFORE 33b AND 33 so "per divisi / menurut divisi" wins.
    $divPivP   = '(?:menurut\s+divisi|per\s+divisi|by\s+division|per\s+afdeling|menurut\s+afdeling|pivot\s+divisi|berdasarkan\s+divisi)';
    if (
        preg_match("/^$analyzeKw\\s+$pestKw\\s+$divPivP\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$pestKw\\s+$divPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$analyzeKw\\s+$pestKw\\s+(.+?)\\s+$divPivP\\s*\$/ui", $norm, $m)
     || preg_match("/^$pestKw\\s+$divPivP\\s*\$/ui", $norm)
     || preg_match("/^$pestKw\\s+$divPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$pestKw\\s+(.+?)\\s+$divPivP\\s*\$/ui", $norm, $m)
    ) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_pest_by_division($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 33b. "Analisa Hama & Penyakit per Blok [scope]" → per-block pivot ───────
    // Must come BEFORE rule 33 so "per blok / menurut blok" wins.
    $blokPivP  = '(?:menurut\s+blok|per\s+blok|by\s+block|pivot\s+blok|pivot\s+block|berdasarkan\s+blok|blok\s+pivot)';
    if (
        preg_match("/^$analyzeKw\\s+$pestKw\\s+$blokPivP\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$pestKw\\s+$blokPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$analyzeKw\\s+$pestKw\\s+(.+?)\\s+$blokPivP\\s*\$/ui", $norm, $m)
     || preg_match("/^$pestKw\\s+$blokPivP\\s*\$/ui", $norm)
     || preg_match("/^$pestKw\\s+$blokPivP\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$pestKw\\s+(.+?)\\s+$blokPivP\\s*\$/ui", $norm, $m)
    ) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_pest_by_block($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 33. "Analisa Hama & Penyakit [scope]" → pest/disease focused analysis ──
    // Must come BEFORE rule 28 (generic chemicals) so the hama/penyakit keyword wins.
    // NOTE: must NOT match if "per blok / menurut blok" is present (rule 33b handles those).
    if (preg_match("/^$analyzeKw\\s+$pestKw\\s*\$/ui", $norm)
     || (preg_match("/^$analyzeKw\\s+$pestKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m) && !preg_match("/$blokPivP/ui", $m[1] ?? ''))
     || preg_match("/^$pestKw\\s+$analyzeKw\\s*\$/ui", $norm)
     || (preg_match("/^$pestKw\\s+(.+)\\s+$analyzeKw\$/ui", $norm, $m)                                           && !preg_match("/$blokPivP/ui", $m[1] ?? ''))) {
        $rawPlace = trim($m[1] ?? '');
        $place    = agro_clean($rawPlace);
        if (strlen($place) >= 2 || $place === '') {
            $res = agro_pest_analysis($db, $place, $q);
            $res['auto_analyze'] = true;
            return $res;
        }
    }

    // ── 28c. "Analisa Bahan Kimia per Tahun Tanam [scope]" → pivot by planting year ─
    // Must come BEFORE 28b / 28a / 28 so "per tahun tanam / by planting year" wins.
    $pyPivotC = '(?:per\s+tahun\s+tanam|menurut\s+tahun\s+tanam|berdasarkan\s+tahun\s+tanam'
              . '|by\s+planting\s+year|per\s+tahun|menurut\s+tahun'
              . '|pivot\s+tahun\s+tanam|tahun\s+tanam\s+pivot)';
    if (preg_match("/^$analyzeKw\\s+$chemKw\\s+$pyPivotC\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$chemKw\\s+$pyPivotC\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$chemKw\\s+$pyPivotC\\s*\$/ui", $norm)
     || preg_match("/^$chemKw\\s+$pyPivotC\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/$pyPivotC.*$chemKw/ui", $norm)
     || preg_match("/$chemKw.*$pyPivotC/ui", $norm)) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_chemicals_by_planting_year($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 28b. "Analisa Bahan Kimia per Divisi [scope]" → pivot table by division ─
    // Must come BEFORE 28a AND 28 so "per divisi / menurut divisi" wins.
    $divPivotC = '(?:menurut\s+divisi|per\s+divisi|by\s+division|per\s+afdeling|menurut\s+afdeling|pivot\s+divisi|berdasarkan\s+divisi)';
    if (preg_match("/^$analyzeKw\\s+$chemKw\\s+$divPivotC\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$chemKw\\s+$divPivotC\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$chemKw\\s+$divPivotC\\s*\$/ui", $norm)
     || preg_match("/^$chemKw\\s+$divPivotC\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/$divPivotC.*$chemKw/ui", $norm)
     || preg_match("/$chemKw.*$divPivotC/ui", $norm)) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_chemicals_by_division($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 28a. "Analisa Bahan Kimia per Blok [scope]" → pivot table by block ────
    // Must come BEFORE rule 28 so "per blok / menurut blok / by block" wins.
    $blokPivotC = '(?:menurut\s+blok|per\s+blok|by\s+block|pivot\s+blok|pivot\s+block|berdasarkan\s+blok|blok\s+pivot)';
    if (preg_match("/^$analyzeKw\\s+$chemKw\\s+$blokPivotC\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$chemKw\\s+$blokPivotC\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$chemKw\\s+$blokPivotC\\s*\$/ui", $norm)
     || preg_match("/^$chemKw\\s+$blokPivotC\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/$blokPivotC.*$chemKw/ui", $norm)
     || preg_match("/$chemKw.*$blokPivotC/ui", $norm)) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_chemicals_by_block($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 28. "Analisa Bahan Kimia [scope]" → fetch chemicals then auto-analyze ───
    // Must come BEFORE rule 23 (generic chemicals) so the analyze keyword wins.
    if (preg_match("/^$analyzeKw\\s+$chemKw\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$chemKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$chemKw\\s+$analyzeKw\\s*\$/ui", $norm)
     || preg_match("/^$chemKw\\s+(.+)\\s+$analyzeKw\$/ui", $norm, $m)) {
        $rawPlace = trim($m[1] ?? '');
        $place = agro_clean($rawPlace);
        if (strlen($place) >= 2 || $place === '') {
            $res = agro_chemicals_used($db, $place, $q);
            $res['auto_analyze'] = true;
            return $res;
        }
    }

    // ── 23. Chemicals / pesticides / pest & disease control used ─────────────
    // "bahan kimia yang digunakan di ANP" / "pestisida di Riau Estate"
    // "daftar bahan kimia ANP" / "chemicals used in ANP" / "pesticide ANP"
    // "pemberantasan hama dan penyakit di ANP" / "pengendalian hama ANP"
    // "data hama penyakit di ANP" / "OPT di Riau Estate"
    // Scopeless checks first — must come BEFORE scoped patterns so that
    // "tampilkan bahan kimia yang digunakan" (no location) is not mis-parsed.
    if (preg_match("/$chemTbl\\s+$chemKw\\s*\$/ui", $norm)
     || preg_match("/\b(?:data-data|data)\s+pemakaian\s+$chemKw\\s*\$/ui", $norm)
     || preg_match("/$chemTbl\\s+$chemKw\\s+(?:yang\\s+)?(?:digunakan|used|dipakai|applied)\\s*\$/ui", $norm)
     || preg_match("/^$chemKw\\s*\$/ui", $norm)) {
        return agro_chemicals_used($db, '', $q);
    }
    if (
        // Explicit: "data/tabel pemberantasan/pengendalian hama [dan penyakit] di <scope>"
        preg_match("/(?:$chemTbl\\s+)?(?:pemberantasan|pengendalian)\\s+(?:hama|opt|penyakit|organisme)(?:\\s+dan\\s+(?:penyakit|opt))?\\s+$scopeC2/ui", $norm, $m)
     || preg_match("/$chemTbl\\s+$chemKw\\s+$scopeC2/ui", $norm, $m)
     || preg_match("/$chemKw\\s+(?:yang\\s+)?(?:digunakan|used|ada|tersedia|dipakai|applied)\\s+$scopeC2/ui", $norm, $m)
     || preg_match("/$chemKw\\s+$scopeC2/ui", $norm, $m)
    ) {
        $place = agro_clean(trim($m[1] ?? ''));
        // Allow scopeless query — agro_chemicals_used handles empty $place as "all data"
        if (strlen($place) >= 2 || $place === '') {
            return agro_chemicals_used($db, $place, $q);
        }
    }

    // ── 26c. "Analisa Pemupukan per Tahun Tanam [scope]" → pivot by planting year ──
    // Must come BEFORE 26b, 26a AND 26 so "per tahun tanam" wins.
    $pyPivotF = '(?:per\s+tahun\s+tanam|menurut\s+tahun\s+tanam|berdasarkan\s+tahun\s+tanam'
              . '|by\s+planting\s+year|per\s+tahun|menurut\s+tahun'
              . '|pivot\s+tahun\s+tanam|tahun\s+tanam\s+pivot)';
    $fertKw3  = '(?:pupuk|pemupukan|fertiliz(?:er|ation|izers?)|pemupuk(?:an)?)';
    if (preg_match("/^$analyzeKw\\s+$fertKw3\\s+$pyPivotF\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$fertKw3\\s+$pyPivotF\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$fertKw3\\s+$pyPivotF\\s*\$/ui", $norm)
     || preg_match("/^$fertKw3\\s+$pyPivotF\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/$pyPivotF.*$fertKw3/ui", $norm)
     || preg_match("/$fertKw3.*$pyPivotF/ui", $norm)) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_fertilization_by_planting_year($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 26b. "Analisa Pemupukan per Divisi [scope]" → pivot table by division ──
    // Must come BEFORE 26a AND 26 so "per divisi / menurut divisi" wins.
    $fertKw2   = '(?:pupuk|pemupukan|fertiliz(?:er|ation|izers?)|pemupuk(?:an)?)';
    $divPivotF = '(?:menurut\s+divisi|per\s+divisi|by\s+division|per\s+afdeling|menurut\s+afdeling|pivot\s+divisi|berdasarkan\s+divisi)';
    if (preg_match("/^$analyzeKw\\s+$fertKw2\\s+$divPivotF\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$fertKw2\\s+$divPivotF\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$fertKw2\\s+$divPivotF\\s*\$/ui", $norm)
     || preg_match("/^$fertKw2\\s+$divPivotF\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/$divPivotF.*$fertKw2/ui", $norm)
     || preg_match("/$fertKw2.*$divPivotF/ui", $norm)) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_fertilization_by_division($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 26a. "Analisa Pemupukan menurut Blok [scope]" → pivot table by block ──
    // Must come BEFORE rule 26 so "menurut blok / per blok / by block" wins.
    $blokPivot = '(?:menurut\s+blok|per\s+blok|by\s+block|pivot\s+blok|pivot\s+block|berdasarkan\s+blok|blok\s+pivot)';
    if (preg_match("/^$analyzeKw\\s+$fertKw2\\s+$blokPivot\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$fertKw2\\s+$blokPivot\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$fertKw2\\s+$blokPivot\\s*\$/ui", $norm)
     || preg_match("/^$fertKw2\\s+$blokPivot\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/$blokPivot.*$fertKw2/ui", $norm)
     || preg_match("/$fertKw2.*$blokPivot/ui", $norm)) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        $res = agro_fertilization_by_block($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 26. "Analisa Pemupukan [scope]" → fetch data then auto-analyze ────────
    // "Analisa Pemupukan" / "Analisa Pupuk ANP" / "Analisa Pemupukan di Riau Estate"
    // Must come BEFORE rule 24 (generic fertilizer) so the analyze keyword wins.
    if (preg_match("/^$analyzeKw\\s+$fertKw2\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$fertKw2\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$fertKw2\\s+$analyzeKw\\s*\$/ui", $norm)
     || preg_match("/^$fertKw2\\s+(.+)\\s+$analyzeKw\$/ui", $norm, $m)) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        if (strlen($place) >= 2 || $place === '') {
            $res = agro_fertilization_used($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
            $res['auto_analyze'] = true;
            return $res;
        }
    }

    // ── 24. Fertilizer / pupuk used ──────────────────────────────────────────
    // "pupuk yang digunakan di ANP" / "fertilizer used in Riau Estate"
    // "daftar pupuk ANP" / "tabel pemupukan ANP" / "pupuk ANP"
    // "pemupukan di ANP bulan Januari 2026" / "pupuk ANP Maret 2025"
    // "pemupukan ANP tanggal 25 s/d 28 Januari 2026"
    $fertKw  = '(?:pupuk|pemupukan|fertiliz(?:er|ation|izers?)|pemupuk(?:an)?)';
    $fertTbl = '(?:tabel|table|daftar|list|sebutkan|tampilkan|rekap|show|data(?:-\w+)?|gunakan|digunakan|apa\s+saja|used?|penggunaan|pemakaian|realisasi|realization)';
    $scopeC3 = '(?:di\s+|in\s+|of\s+|at\s+|pada\s+)?(.+)';
    if (
        preg_match("/$fertTbl\\s+$fertKw\\s+$scopeC3/ui", $norm, $m)
     || preg_match("/$fertTbl\\s+$fertKw\\s*$/ui", $norm)                                                                  // scopeless: "tampilkan pemupukan"
     || preg_match("/$fertKw\\s+(?:yang\\s+)?(?:digunakan|used|ada|tersedia|dipakai|applied|dilakukan)\\s+$scopeC3/ui", $norm, $m)
     || preg_match("/$fertKw\\s+$scopeC3/ui", $norm, $m)
     || preg_match("/^$fertKw\\s*\$/ui", $norm)                                                                            // bare: "Pupuk" or "Pemupukan" alone
    ) {
        $rawPlace = trim($m[1] ?? '');
        // Extract optional date range / month / year from the captured scope string
        [$rawPlace, $dateFrom, $dateTo, $dateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        // Allow scopeless query: agro_fertilization_used handles empty $place as "all data"
        if (strlen($place) >= 2 || $place === '') {
            return agro_fertilization_used($db, $place, $q, $dateFrom, $dateTo, $dateLabel);
        }
    }

    // ── 25. Harvest + Transport (FFB Delivery) combined ──────────────────────
    // "data panen dan pengangkutan di ANP" / "panen dan ffb delivery Riau Estate"
    // "tampilkan panen dan transport ANP" / "harvest transport summary ANP"
    $htKw  = '(?:panen|harvest|ffb|hasil\s+panen)';
    $trKw  = '(?:pengangkutan|angkut|transport(?:asi)?|ffb\s+delivery|delivery|pengiriman)';
    $scopeCH = '(?:di\s+|in\s+|of\s+|at\s+|pada\s+)?(.+)';
    if (
        preg_match("/$htKw\\s+dan\\s+$trKw\\s+$scopeCH/ui", $norm, $m)
     || preg_match("/$trKw\\s+dan\\s+$htKw\\s+$scopeCH/ui", $norm, $m)
     || preg_match("/(?:data|tabel|rekap|ringkasan|summary)\\s+$htKw\\s+(?:dan\\s+$trKw\\s+)?$scopeCH/ui", $norm, $m)
     || preg_match("/(?:data|tabel|rekap)\\s+$trKw\\s+$scopeCH/ui", $norm, $m)
    ) {
        $place = agro_clean(trim($m[1]));
        if (strlen($place) >= 2) {
            return agro_harvest_transport($db, $place, $q);
        }
    }

    // ── 20. Standards / compliance check against last table ───────────────────
    // "apakah sesuai standar?" / "sudah sesuai standar?" / "comply with standard?"
    // "bandingkan dengan standarnya" / "compare to standard"
    // Must come BEFORE analyze so it wins when both keywords are present.
    if (preg_match('/\b(?:sesuai|comply|compliance|memenuhi)\b.{0,20}\b(?:standar|standard|norm|baku|snp|gapki|ppks)\b/ui', $norm)
     || preg_match('/\b(?:standar|standard|benchmark|norm|gapki|ppks)\b.{0,20}\b(?:sesuai|comply|compliant|terpenuhi|sudah|apakah|cek|check|bandingkan|compare)\b/ui', $norm)
     || preg_match('/^apakah\b.{0,40}\b(?:standar|standard|normal|baik)\b/ui', $norm)
     || preg_match('/\b(?:sudah\s+(?:sesuai|memenuhi)|already\s+(?:comply|meeting))\b/ui', $norm)
     || preg_match('/\b(?:cek|check)\s+(?:standar|standard|kesesuaian)\b/ui', $norm)
     || preg_match('/\b(?:bandingkan|compare|dibandingkan|komparasi)\b.{0,30}\b(?:standar|standard|norm|baku|benchmark)\b/ui', $norm)
     || preg_match('/\b(?:standar|standard|norm|baku|benchmark)\b.{0,30}\b(?:bandingkan|compare|dibandingkan)\b/ui', $norm)) {
        return ['type' => 'standards_check', 'question' => $q];
    }

    // ── 27b. "Analisa Pembibitan per Batch [scope]" → per-batch pivot ───────────
    // Must come BEFORE rule 27 so "per batch / menurut batch" wins.
    // Supported word orders:
    //   "Analisa Pembibitan per Batch"              (no scope)
    //   "Analisa Pembibitan per Batch ANP"           (scope AFTER batch)
    //   "Analisa Pembibitan ANP per Batch"           (scope BETWEEN nursery & batch)
    //   "Pembibitan ANP per Batch"
    //   "Nursery per Batch Riau Estate"
    $batchPivotN = '(?:per\s+batch|menurut\s+batch|berdasarkan\s+batch|by\s+batch|batch\s+pivot|pivot\s+batch)';
    $nursKwB     = '(?:pembibitan|nursery|bibit|persemaian|kecambah|germina(?:si)?)';
    if (
        // "Analisa Pembibitan per Batch"  (no scope)
        preg_match("/^$analyzeKw\\s+$nursKwB\\s+$batchPivotN\\s*\$/ui", $norm)
        // "Analisa Pembibitan per Batch ANP"  (scope AFTER batch keyword)
     || preg_match("/^$analyzeKw\\s+$nursKwB\\s+$batchPivotN\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
        // "Analisa Pembibitan ANP per Batch"  (scope BETWEEN nursery & batch)
     || preg_match("/^$analyzeKw\\s+$nursKwB\\s+(.+?)\\s+$batchPivotN\\s*\$/ui", $norm, $m)
        // "Pembibitan per Batch"  (no scope)
     || preg_match("/^$nursKwB\\s+$batchPivotN\\s*\$/ui", $norm)
        // "Pembibitan per Batch ANP"  (scope AFTER)
     || preg_match("/^$nursKwB\\s+$batchPivotN\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+)?(.+)\$/ui", $norm, $m)
        // "Pembibitan ANP per Batch"  (scope BETWEEN nursery & batch)
     || preg_match("/^$nursKwB\\s+(.+?)\\s+$batchPivotN\\s*\$/ui", $norm, $m)
    ) {
        $place = agro_clean(trim($m[1] ?? ''));
        $res = agro_nursery_by_batch($db, $q, $place);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 27. "Analisa Pembibitan [scope]" → fetch nursery data then auto-analyze ─
    // "Analisa Pembibitan" / "Analisa Nursery ANP" / "Analisa Pembibitan di Riau Estate"
    // Must come BEFORE the generic analyze_request check so the nursery keyword wins.
    // NOTE: must NOT match if "per batch / menurut batch" is present (rule 27b above handles those).
    $nursKw = '(?:pembibitan|nursery|bibit|persemaian|kecambah|germina(?:si)?)';
    $noBatch = '(?!.*\b(?:per\s+batch|menurut\s+batch|berdasarkan\s+batch|by\s+batch)\b)';
    if (preg_match("/^$analyzeKw\\s+$nursKw\\s*\$/ui", $norm)
     || (preg_match("/^$analyzeKw\\s+$nursKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m) && !preg_match("/$batchPivotN/ui", $m[1] ?? ''))
     || preg_match("/^$nursKw\\s+$analyzeKw\\s*\$/ui", $norm)
     || (preg_match("/^$nursKw\\s+(.+)\\s+$analyzeKw\$/ui", $norm, $m)            && !preg_match("/$batchPivotN/ui", $m[1] ?? ''))) {
        $place = agro_clean(trim($m[1] ?? ''));
        $res = agro_nursery_summary($db, $q, $place);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 31. "Analisa Infrastruktur [scope]" → road + bridge combined then auto-analyze ─
    // "Analisa Infrastruktur" / "Analisa Infrastruktur ANP" / "Analisa Jalan dan Jembatan di Riau Estate"
    // Must come BEFORE the generic analyze_request check so the infra keyword wins.
    $infraKw = '(?:infrastruktur|infrastructure|jalan(?:\s+dan\s+jembatan)?|jembatan(?:\s+dan\s+jalan)?|road(?:s)?(?:\s+and\s+bridge(?:s)?)?|bridge(?:s)?(?:\s+and\s+road(?:s)?)?)';
    if (preg_match("/^$analyzeKw\\s+$infraKw\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$infraKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$infraKw\\s+$analyzeKw\\s*\$/ui", $norm)
     || preg_match("/^$infraKw\\s+(.+)\\s+$analyzeKw\$/ui", $norm, $m)) {
        $rawPlace = trim($m[1] ?? '');
        $place = agro_clean($rawPlace);
        $res = agro_infrastructure_summary($db, $q, $place);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 32. "Analisa Pabrik" → rendemen (OER/KER/FFA/moisture) + auto-analyze ──
    // "Analisa Pabrik" / "Analisa Produksi Pabrik" / "Analisa Mill" / "Mill Analysis"
    // "Analisa Pabrik bulan Juni 2026" / "Analisa CPO dan Kernel"
    // Must come BEFORE the generic analyze_request check so the mill keyword wins.
    $millKw = '(?:pabrik|mill|produksi\s+pabrik|cpo(?:\s+dan\s+kernel)?|kernel(?:\s+dan\s+cpo)?|rendemen|oer|ker|extraction)';
    if (preg_match("/^$analyzeKw\\s+$millKw\\b/ui", $norm)
     || preg_match("/^$millKw\\s+$analyzeKw\\s*\$/ui", $norm)) {
        [, $mDateFrom, $mDateTo, $mDateLabel] = agro_extract_date_filter($norm);
        $res = agro_rendemen($db, $q, $mDateFrom, $mDateTo, $mDateLabel);
        $res['auto_analyze'] = true;
        return $res;
    }

    // ── 38. "Executive Summary [scope] [year]" → compact GAPKI one-pager ──────
    // Triggers: "Executive Summary ANP 2026" / "Executive Summary APN"
    //           "Exec Summary" / "Ringkasan Eksekutif ANP" / "Laporan Eksekutif"
    //           "Summary Eksekutif APN 2026" / "One Page Summary"
    //           "Ringkasan Analisis ANP 2026" / "Ringkasan Analisis"
    // Must come BEFORE rule 37 so "summary" wins before the generic analyze.
    $execKw = '(?:executive\s+summary|exec(?:utive)?\s+report|ringkasan\s+eksekutif|laporan\s+eksekutif|summary\s+eksekutif|one[\s-]page\s+(?:summary|report)|one[\s-]pager|ringkasan\s+analisis)';
    if (preg_match("/^$execKw\\s*\$/ui", $norm)
     || preg_match("/^$execKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|untuk\\s+|for\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^(.+)\\s+$execKw\$/ui", $norm, $m)) {
        $rawPlace = trim($m[1] ?? '');
        [$rawPlace, $eDateFrom, $eDateTo, $eDateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        return agro_comprehensive_analysis($db, $place, $q, $eDateFrom, $eDateTo, $eDateLabel, true);
    }

    // ── 37. "Analisa Keseluruhan [scope]" → comprehensive multi-domain analysis ─
    // Triggers: "Analisa Keseluruhan" / "Analisa Keseluruhan APN"
    //           "Comprehensive Analysis" / "Full Analysis" / "Analisa Lengkap"
    //           "Analisa Semua" / "Analisa Terpadu" / "Overall Analysis"
    //           "Analisa Keseluruhan APN Tahun 2026" / "Analisa Keseluruhan 2026"
    // Must come BEFORE rule 19 so it wins before the generic analyze_request check.
    $comprehensiveKw = '(?:keseluruhan|lengkap|terpadu|semua|overall|comprehensive|full\s+analysis|complete\s+analysis|menyeluruh|seluruh)';
    if (preg_match("/^$analyzeKw\\s+$comprehensiveKw\\s*\$/ui", $norm)
     || preg_match("/^$analyzeKw\\s+$comprehensiveKw\\s+(?:di\\s+|in\\s+|of\\s+|at\\s+|pada\\s+)?(.+)\$/ui", $norm, $m)
     || preg_match("/^$comprehensiveKw\\s+$analyzeKw\\s*\$/ui", $norm)
     || preg_match("/^$comprehensiveKw\\s+(.+)\\s+$analyzeKw\$/ui", $norm, $m)) {
        $rawPlace = trim($m[1] ?? '');
        // Extract optional date filter from the captured scope string
        [$rawPlace, $cDateFrom, $cDateTo, $cDateLabel] = agro_extract_date_filter($rawPlace);
        $place = agro_clean($rawPlace);
        return agro_comprehensive_analysis($db, $place, $q, $cDateFrom, $cDateTo, $cDateLabel);
    }

    // ── 19. Analyze last table ─────────────────────────────────────────────────
    // "analisa tabel ini" / "analisis tabel terakhir" / "analyze this" / "bisa analisa?"
    if (preg_match('/\b(?:analisa|analisis|analiz[ei]|analyze|analysis|ringkasan|summarize|summary|insight)\b/ui', $norm)
     || preg_match('/\b(?:apa\s+yang\s+bisa\s+disimpulkan|kesimpulan|temuan|findings?)\b/ui', $norm)) {
        return ['type' => 'analyze_request', 'question' => $q];
    }

    return [
        'type'    => 'unknown',
        'message' => 'Maaf, pertanyaan tidak dikenali. Coba: "Analisa Keseluruhan APN", "Analisa Keseluruhan", "Sebutkan blok di Afdeling A", "Total panen Afdeling B", "Berapa luas Afdeling A?", "Tabel luas area di ANP berdasarkan divisi", "Data panen dan pengangkutan di ANP", "Jumlah jembatan di ANP", "Panjang jalan per jenis di ANP", "Tampilkan peta blok ANP", "Bahan kimia yang digunakan di ANP", "Pemberantasan hama dan penyakit di ANP", "Pupuk yang digunakan di ANP", "Analisa Hama &amp; Penyakit ANP", "Analisa Pest &amp; Control", "Analisa Pengendalian OPT", "Analisa Infrastruktur ANP", "Analisa tabel ini", "Bandingkan dengan standarnya", "Apakah sesuai standar?", atau "Tampilkan grafik".',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Query methods
// ─────────────────────────────────────────────────────────────────────────────

function agro_harvest_total(PDO $db, string $place, string $q): array
{
    // Try division first
    $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
    $div->execute(['%' . $place . '%']);
    $divRow = $div->fetch();

    if ($divRow) {
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(hr.actual_quantity_kg),0) AS total_kg,
                    COALESCE(SUM(hr.actual_bunches),0)     AS total_bunches,
                    COUNT(hr.harvest_id)                   AS harvest_count,
                    MIN(hr.harvest_date)                   AS first_date,
                    MAX(hr.harvest_date)                   AS last_date
             FROM harvest_realizations hr
             JOIN blocks b ON b.block_id = hr.block_id
             JOIN planting_years py ON py.planting_year_id = b.planting_year_id
             WHERE py.division_id = ?"
        );
        $stmt->execute([(int)$divRow['division_id']]);
        $row = $stmt->fetch();
        return [
            'type'        => 'harvest_total',
            'question'    => $q,
            'scope_type'  => 'division',
            'scope'       => $divRow['division_name'],
            'total_kg'    => (float)$row['total_kg'],
            'bunches'     => (int)$row['total_bunches'],
            'records'     => (int)$row['harvest_count'],
            'first_date'  => $row['first_date'],
            'last_date'   => $row['last_date'],
        ];
    }

    // Try block code/name
    $blk = $db->prepare("SELECT block_id, block_name, block_code FROM blocks WHERE block_name LIKE ? OR block_code LIKE ? ORDER BY LENGTH(block_name) ASC LIMIT 1");
    $blk->execute(['%' . $place . '%', '%' . $place . '%']);
    $blkRow = $blk->fetch();

    if ($blkRow) {
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(actual_quantity_kg),0) AS total_kg,
                    COALESCE(SUM(actual_bunches),0)     AS total_bunches,
                    COUNT(harvest_id)                   AS harvest_count,
                    MIN(harvest_date) AS first_date, MAX(harvest_date) AS last_date
             FROM harvest_realizations WHERE block_id = ?"
        );
        $stmt->execute([(int)$blkRow['block_id']]);
        $row = $stmt->fetch();
        return [
            'type'       => 'harvest_total',
            'question'   => $q,
            'scope_type' => 'block',
            'scope'      => $blkRow['block_name'] . ' (' . $blkRow['block_code'] . ')',
            'total_kg'   => (float)$row['total_kg'],
            'bunches'    => (int)$row['total_bunches'],
            'records'    => (int)$row['harvest_count'],
            'first_date' => $row['first_date'],
            'last_date'  => $row['last_date'],
        ];
    }

    return agro_did_you_mean($db, 'division', $place, $q);
}

function agro_harvest_summary(PDO $db, string $q, string $place, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // ── Resolve optional scope (BU or company) ───────────────────────────────
    $scopeLabel = '';
    $whereJoin  = '';
    $bindIds    = [];
    // Build optional date filter fragment (appended after JOIN/WHERE)
    $dateWhere  = '';
    $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = ' AND hr.harvest_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = ' AND hr.harvest_date >= ?';
        $dateParams = [$dateFrom];
    }

    if ($place !== '') {
        // Try business unit first
        $buStmt = $db->prepare(
            "SELECT business_unit_id, unit_name FROM business_units
              WHERE unit_name LIKE ? OR unit_code LIKE ?
              ORDER BY LENGTH(unit_name) ASC LIMIT 1"
        );
        $buStmt->execute(['%' . $place . '%', '%' . $place . '%']);
        $buRow = $buStmt->fetch();

        if ($buRow) {
            $scopeLabel = $buRow['unit_name'];
            $bindIds[]  = (int)$buRow['business_unit_id'];
            $whereJoin  = " JOIN divisions d2 ON d2.division_id = py.division_id
                             AND d2.business_unit_id = ?";
        } else {
            // Try company
            $coRow = agro_find_company($db, $place);

            if ($coRow) {
                $scopeLabel = $coRow['company_name'];
                $buList = $db->query(
                    "SELECT business_unit_id FROM business_units
                      WHERE company_id = " . (int)$coRow['company_id']
                )->fetchAll(\PDO::FETCH_COLUMN);
                if (!empty($buList)) {
                    $ph        = implode(',', array_fill(0, count($buList), '?'));
                    $bindIds   = array_map('intval', $buList);
                    $whereJoin = " JOIN divisions d2 ON d2.division_id = py.division_id
                                    JOIN business_units bu2 ON bu2.business_unit_id = d2.business_unit_id
                                    AND bu2.business_unit_id IN ($ph)";
                }
            }

            if ($scopeLabel === '') {
                // Try to resolve as a division name.
                // Handles:
                //   "Afdeling I"            → direct match
                //   "JMBE - Afdeling I"     → split on " - ", try right part
                //   "JMBE Afdeling I"       → split on space, try progressively longer suffixes
                $divStmt2 = $db->prepare(
                    "SELECT division_id, division_name FROM divisions
                      WHERE division_name LIKE ?
                      ORDER BY LENGTH(division_name) ASC LIMIT 1"
                );

                $divRow2 = false;

                // Build list of candidate search strings, most-specific first:
                //   1. After " - " separator  (e.g. "Afdeling I" from "JMBE - Afdeling I")
                //   2. Each word-boundary suffix, shortest first
                //      "JMBE Afdeling I" → ["afdeling i", "jmbe afdeling i"]
                //   3. Full place as-is
                $candidates = [];
                if (preg_match('/^.+?\s*-\s*(.+)$/u', $place, $dm)) {
                    $candidates[] = trim($dm[1]);
                }
                $words = preg_split('/\s+/u', $place) ?: [];
                for ($i = 1; $i < count($words); $i++) {
                    $candidates[] = implode(' ', array_slice($words, $i));
                }
                $candidates[] = $place;
                $candidates = array_unique($candidates);

                foreach ($candidates as $cand) {
                    if ($cand === '') continue;
                    $divStmt2->execute(['%' . $cand . '%']);
                    $divRow2 = $divStmt2->fetch();
                    if ($divRow2) break;
                }

                if ($divRow2) {
                    return agro_harvest_total($db, (string)$divRow2['division_name'], $q);
                }

                return agro_did_you_mean($db, 'business_unit', $place, $q);
            }
        }
    }

    // ── Overall totals ───────────────────────────────────────────────────────
    $totSql = "SELECT COALESCE(SUM(hr.actual_quantity_kg),0) AS total_kg,
                      COALESCE(SUM(hr.actual_bunches),0)     AS total_bunches,
                      COUNT(hr.harvest_id)                   AS harvest_count,
                      MIN(hr.harvest_date)                   AS first_date,
                      MAX(hr.harvest_date)                   AS last_date
               FROM harvest_realizations hr
               JOIN blocks b ON b.block_id = hr.block_id
               JOIN planting_years py ON py.planting_year_id = b.planting_year_id"
             . $whereJoin . $dateWhere;
    $totStmt = $db->prepare($totSql);
    $totStmt->execute(array_merge($bindIds, $dateParams));
    $tot = $totStmt->fetch();

    // ── Per-division breakdown ───────────────────────────────────────────────
    $divSql = "SELECT d.division_name,
                      COALESCE(SUM(hr.actual_quantity_kg),0) AS total_kg,
                      COALESCE(SUM(hr.actual_bunches),0)     AS total_bunches,
                      COUNT(hr.harvest_id)                   AS harvest_count
               FROM harvest_realizations hr
               JOIN blocks b ON b.block_id = hr.block_id
               JOIN planting_years py ON py.planting_year_id = b.planting_year_id
               JOIN divisions d ON d.division_id = py.division_id"
             . $whereJoin . $dateWhere .
             " GROUP BY d.division_id, d.division_name
               ORDER BY total_kg DESC";
    $divStmt = $db->prepare($divSql);
    $divStmt->execute(array_merge($bindIds, $dateParams));
    $byDivision = $divStmt->fetchAll();

    return [
        'type'        => 'harvest_summary',
        'question'    => $q,
        'scope'       => $scopeLabel,
        'date_label'  => $dateLabel,
        'total_kg'    => (float)$tot['total_kg'],
        'bunches'     => (int)$tot['total_bunches'],
        'records'     => (int)$tot['harvest_count'],
        'first_date'  => $tot['first_date'],
        'last_date'   => $tot['last_date'],
        'by_division' => $byDivision,
    ];
}

function agro_blocks_in_division(PDO $db, string $place, string $q): array
{
    // 1. Try matching a division by name
    $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
    $div->execute(['%' . $place . '%']);
    $divRow = $div->fetch();

    if ($divRow) {
        $rows = $db->prepare(
            "SELECT b.block_code, b.block_name, b.area, b.status, b.harvest_status,
                    b.total_plants, py.year AS planting_year, d.division_name
             FROM blocks b
             JOIN planting_years py ON py.planting_year_id = b.planting_year_id
             JOIN divisions d ON d.division_id = py.division_id
             WHERE py.division_id = ?
             ORDER BY py.year ASC, b.block_code ASC"
        );
        $rows->execute([(int)$divRow['division_id']]);
        $blocks = $rows->fetchAll();
        return [
            'type'     => 'blocks_in_division',
            'question' => $q,
            'division' => $divRow['division_name'],
            'blocks'   => $blocks,
            'count'    => count($blocks),
        ];
    }

    // 2. Try matching a business unit — return blocks from all its divisions
    $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
    $bu->execute(['%' . $place . '%']);
    $buRow = $bu->fetch();

    if ($buRow) {
        $rows = $db->prepare(
            "SELECT b.block_code, b.block_name, b.area, b.status, b.harvest_status,
                    b.total_plants, py.year AS planting_year, d.division_name
             FROM blocks b
             JOIN planting_years py ON py.planting_year_id = b.planting_year_id
             JOIN divisions d ON d.division_id = py.division_id
             WHERE d.business_unit_id = ?
             ORDER BY d.division_name ASC, py.year ASC, b.block_code ASC"
        );
        $rows->execute([(int)$buRow['business_unit_id']]);
        $blocks = $rows->fetchAll();
        return [
            'type'     => 'blocks_in_division',
            'question' => $q,
            'division' => $buRow['unit_name'],
            'blocks'   => $blocks,
            'count'    => count($blocks),
            'show_division' => true,
        ];
    }

    // 3. Try matching a company — return blocks from all its estates
    $coRow = agro_find_company($db, $place);

    if ($coRow) {
        $rows = $db->prepare(
            "SELECT b.block_code, b.block_name, b.area, b.status, b.harvest_status,
                    b.total_plants, py.year AS planting_year, d.division_name
             FROM blocks b
             JOIN planting_years py ON py.planting_year_id = b.planting_year_id
             JOIN divisions d ON d.division_id = py.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE bu.company_id = ?
             ORDER BY bu.unit_name ASC, d.division_name ASC, py.year ASC, b.block_code ASC"
        );
        $rows->execute([(int)$coRow['company_id']]);
        $blocks = $rows->fetchAll();
        return [
            'type'          => 'blocks_in_division',
            'question'      => $q,
            'division'      => $coRow['company_name'],
            'blocks'        => $blocks,
            'count'         => count($blocks),
            'show_division' => true,
        ];
    }

    return agro_did_you_mean($db, 'division', $place, $q);
}

function agro_divisions_in_bu(PDO $db, string $place, string $q): array
{
    // 1. Try matching a business unit by name
    $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
    $bu->execute(['%' . $place . '%']);
    $buRow = $bu->fetch();

    if ($buRow) {
        $rows = $db->prepare(
            "SELECT d.division_id, d.division_name, d.division_type, d.total_area_ha, d.status
             FROM divisions d WHERE d.business_unit_id = ? ORDER BY d.division_name ASC"
        );
        $rows->execute([(int)$buRow['business_unit_id']]);
        $divs = $rows->fetchAll();
        return [
            'type'         => 'divisions_in_bu',
            'question'     => $q,
            'business_unit'=> $buRow['unit_name'],
            'divisions'    => $divs,
            'count'        => count($divs),
        ];
    }

    // 2. Try matching a company name — return all divisions across all its BUs
    $coRow = agro_find_company($db, $place);

    if ($coRow) {
        $rows = $db->prepare(
            "SELECT d.division_id, d.division_name, d.division_type, d.total_area_ha, d.status,
                    bu.unit_name AS business_unit
             FROM divisions d
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE bu.company_id = ?
             ORDER BY bu.unit_name ASC, d.division_name ASC"
        );
        $rows->execute([(int)$coRow['company_id']]);
        $divs = $rows->fetchAll();
        return [
            'type'         => 'divisions_in_bu',
            'question'     => $q,
            'business_unit'=> $coRow['company_name'],
            'divisions'    => $divs,
            'count'        => count($divs),
            'grouped_by_bu'=> true,
        ];
    }

    return agro_did_you_mean($db, 'business_unit', $place, $q);
}

function agro_bus_in_company(PDO $db, string $place, string $q): array
{
    $coRow = agro_find_company($db, $place);

    if (!$coRow) {
        return agro_did_you_mean($db, 'company', $place, $q);
    }

    $rows = $db->prepare(
        "SELECT business_unit_id, unit_code, unit_name, unit_type, province, status
         FROM business_units WHERE company_id = ? ORDER BY unit_name ASC"
    );
    $rows->execute([(int)$coRow['company_id']]);
    $bus = $rows->fetchAll();

    return [
        'type'    => 'bus_in_company',
        'question'=> $q,
        'company' => $coRow['company_name'],
        'units'   => $bus,
        'count'   => count($bus),
    ];
}

function agro_count_blocks(PDO $db, string $place, string $q): array
{
    $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
    $div->execute(['%' . $place . '%']);
    $divRow = $div->fetch();

    if (!$divRow) {
        return agro_did_you_mean($db, 'division', $place, $q);
    }

    $cnt = $db->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(b.area),0) AS total_ha
         FROM blocks b
         JOIN planting_years py ON py.planting_year_id = b.planting_year_id
         WHERE py.division_id = ?"
    );
    $cnt->execute([(int)$divRow['division_id']]);
    $row = $cnt->fetch();

    return [
        'type'    => 'count_blocks',
        'question'=> $q,
        'scope'   => $divRow['division_name'],
        'count'   => (int)$row['cnt'],
        'total_ha'=> (float)$row['total_ha'],
    ];
}

function agro_area(PDO $db, string $place, string $q): array
{
    // 0. Scopeless — return all-company overview with BU + division detail + non-planted breakdown
    if ($place === '') {
        // Sum only blocks that resolve to a company via direct division_id
        $total = (float)$db->query(
            "SELECT COALESCE(SUM(b.area),0) AS ha
             FROM blocks b
             JOIN divisions d       ON d.division_id       = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             JOIN companies c       ON c.company_id        = bu.company_id"
        )->fetchColumn();

        // ── Non-planted area lookups ──────────────────────────────────────────
        // blocks has a direct division_id FK — use it instead of the
        // blocks → planting_years → divisions chain which can have broken FKs.
        $npBaseSql =
            "FROM block_area_component_values bacv
             JOIN area_component_measurement_types acmt ON acmt.id        = bacv.measurement_type_id
             JOIN block_area_component_categories  bacc ON bacc.id        = bacv.category_id
             JOIN blocks b           ON b.block_id                        = bacv.block_id
             LEFT JOIN divisions d       ON d.division_id                 = b.division_id
             LEFT JOIN business_units bu ON bu.business_unit_id           = d.business_unit_id
             LEFT JOIN companies c       ON c.company_id                  = bu.company_id
             WHERE bacc.category_type = 'non_planted'
               AND acmt.measurement_code = 'AREA'
               AND acmt.data_type != 'text'";

        $npByCompanyMap = [];
        foreach ($db->query(
            "SELECT c.company_id,
                    COALESCE(SUM(bacv.value),0) AS non_planted_ha
             $npBaseSql AND c.company_id IS NOT NULL
             GROUP BY c.company_id"
        )->fetchAll() as $r) {
            $npByCompanyMap[(int)$r['company_id']] = (float)$r['non_planted_ha'];
        }

        $npByBUMap = [];
        foreach ($db->query(
            "SELECT bu.business_unit_id,
                    COALESCE(SUM(bacv.value),0) AS non_planted_ha
             $npBaseSql AND bu.business_unit_id IS NOT NULL
             GROUP BY bu.business_unit_id"
        )->fetchAll() as $r) {
            $npByBUMap[(int)$r['business_unit_id']] = (float)$r['non_planted_ha'];
        }

        $npByDivMap = [];
        foreach ($db->query(
            "SELECT d.division_id,
                    COALESCE(SUM(bacv.value),0) AS non_planted_ha
             $npBaseSql AND d.division_id IS NOT NULL
             GROUP BY d.division_id"
        )->fetchAll() as $r) {
            $npByDivMap[(int)$r['division_id']] = (float)$r['non_planted_ha'];
        }

        // Per-company summary — join via blocks.division_id (direct FK)
        $byCompany = $db->query(
            "SELECT c.company_id, c.company_name,
                    COALESCE(SUM(b.area),0)             AS ha,
                    COUNT(DISTINCT bu.business_unit_id) AS bu_count,
                    COUNT(DISTINCT d.division_id)       AS div_count,
                    COUNT(DISTINCT b.block_id)          AS block_count
             FROM blocks b
             JOIN divisions d       ON d.division_id       = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             JOIN companies c       ON c.company_id        = bu.company_id
             GROUP BY c.company_id, c.company_name
             ORDER BY ha DESC"
        )->fetchAll();

        // Per-BU detail
        $byBU = $db->query(
            "SELECT c.company_id, c.company_name,
                    bu.business_unit_id, bu.unit_name,
                    COALESCE(SUM(b.area),0)       AS ha,
                    COUNT(DISTINCT d.division_id) AS div_count,
                    COUNT(DISTINCT b.block_id)    AS block_count
             FROM blocks b
             JOIN divisions d       ON d.division_id       = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             JOIN companies c       ON c.company_id        = bu.company_id
             GROUP BY c.company_id, c.company_name, bu.business_unit_id, bu.unit_name
             ORDER BY c.company_name ASC, ha DESC"
        )->fetchAll();

        // Per-division detail
        $byDiv = $db->query(
            "SELECT bu.business_unit_id,
                    d.division_id, d.division_name,
                    COALESCE(SUM(b.area),0)    AS ha,
                    COUNT(DISTINCT b.block_id) AS block_count
             FROM blocks b
             JOIN divisions d       ON d.division_id       = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             GROUP BY bu.business_unit_id, d.division_id, d.division_name
             ORDER BY bu.business_unit_id ASC, ha DESC"
        )->fetchAll();

        // Per-division per-category non-planted breakdown (for the detail table)
        $npDetail = $db->query(
            "SELECT c.company_name, bu.unit_name, d.division_name,
                    bacc.category_code, bacc.category_name,
                    COALESCE(SUM(bacv.value),0) AS ha
             FROM block_area_component_values bacv
             JOIN area_component_measurement_types acmt ON acmt.id = bacv.measurement_type_id
             JOIN block_area_component_categories  bacc ON bacc.id = bacv.category_id
             JOIN blocks b ON b.block_id = bacv.block_id
             JOIN divisions d       ON d.division_id       = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             JOIN companies c       ON c.company_id        = bu.company_id
             WHERE bacc.category_type = 'non_planted'
               AND acmt.measurement_code = 'AREA'
               AND acmt.data_type != 'text'
             GROUP BY c.company_name, bu.unit_name, d.division_id, d.division_name,
                      bacc.id, bacc.category_code, bacc.category_name
             ORDER BY c.company_name, bu.unit_name, d.division_name, ha DESC"
        )->fetchAll();

        // Distinct category codes/names that actually have data (for column headers)
        $npCategories = [];
        foreach ($npDetail as $r) {
            $npCategories[$r['category_code']] = $r['category_name'];
        }

        return [
            'type'          => 'area',
            'question'      => $q,
            'scope_type'    => 'Semua Perkebunan',
            'scope'         => 'Semua Perkebunan',
            'ha'            => $total,
            'breakdown'     => array_map(fn($r) => [
                'unit_name'      => $r['company_name'],
                'company_id'     => $r['company_id'],
                'ha'             => $r['ha'],
                'non_planted_ha' => $npByCompanyMap[(int)$r['company_id']] ?? 0.0,
                'bu_count'       => $r['bu_count'],
                'div_count'      => $r['div_count'],
                'block_count'    => $r['block_count'],
            ], $byCompany),
            'detail_bu'     => array_map(fn($r) => $r + [
                'non_planted_ha' => $npByBUMap[(int)$r['business_unit_id']] ?? 0.0,
            ], $byBU),
            'detail_div'    => array_map(fn($r) => $r + [
                'non_planted_ha' => $npByDivMap[(int)$r['division_id']] ?? 0.0,
            ], $byDiv),
            'np_detail'     => $npDetail,
            'np_categories' => $npCategories,
            'show_counts'   => true,
        ];
    }

    // ── Helper: build the shared non-planted detail for any scope ────────────
    $npDetailForScope = function(string $whereClause, array $whereParams) use ($db): array {
        $stmt = $db->prepare(
            "SELECT c.company_name, bu.unit_name, d.division_name,
                    bacc.category_code, bacc.category_name,
                    COALESCE(SUM(bacv.value),0) AS ha
             FROM block_area_component_values bacv
             JOIN area_component_measurement_types acmt ON acmt.id = bacv.measurement_type_id
             JOIN block_area_component_categories  bacc ON bacc.id = bacv.category_id
             JOIN blocks b ON b.block_id = bacv.block_id
             JOIN divisions d       ON d.division_id       = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             JOIN companies c       ON c.company_id        = bu.company_id
             WHERE bacc.category_type = 'non_planted'
               AND acmt.measurement_code = 'AREA'
               AND acmt.data_type != 'text'
               AND $whereClause
             GROUP BY c.company_name, bu.unit_name, d.division_id, d.division_name,
                      bacc.id, bacc.category_code, bacc.category_name
             ORDER BY c.company_name, bu.unit_name, d.division_name, ha DESC"
        );
        $stmt->execute($whereParams);
        $rows = $stmt->fetchAll();
        $cats = [];
        foreach ($rows as $r) { $cats[$r['category_code']] = $r['category_name']; }
        return ['np_detail' => $rows, 'np_categories' => $cats];
    };

    // 1. Try division
    $div = $db->prepare("SELECT division_id, division_name, total_area_ha FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
    $div->execute(['%' . $place . '%']);
    $divRow = $div->fetch();

    if ($divRow) {
        $divId = (int)$divRow['division_id'];
        $haStmt = $db->prepare("SELECT COALESCE(SUM(b.area),0) AS ha FROM blocks b WHERE b.division_id = ?");
        $haStmt->execute([$divId]);
        $ha = (float)$haStmt->fetch()['ha'] ?: (float)($divRow['total_area_ha'] ?? 0);

        // Per-division breakdown (single row, but consistent with scoped render)
        $byDiv = $db->prepare(
            "SELECT bu.business_unit_id, d.division_id, d.division_name,
                    COALESCE(SUM(b.area),0) AS ha, COUNT(DISTINCT b.block_id) AS block_count
             FROM blocks b
             JOIN divisions d       ON d.division_id       = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE d.division_id = ?
             GROUP BY bu.business_unit_id, d.division_id, d.division_name"
        );
        $byDiv->execute([$divId]);

        return array_merge([
            'type'        => 'area',
            'question'    => $q,
            'scope_type'  => 'Division',
            'scope'       => $divRow['division_name'],
            'ha'          => $ha,
            'detail_div'  => $byDiv->fetchAll(),
            'show_counts' => true,
        ], $npDetailForScope('b.division_id = ?', [$divId]));
    }

    // 2. Try business unit
    $bu = $db->prepare("SELECT business_unit_id, unit_name, total_area FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
    $bu->execute(['%' . $place . '%']);
    $buRow = $bu->fetch();

    if ($buRow) {
        $buId = (int)$buRow['business_unit_id'];
        $haStmt = $db->prepare(
            "SELECT COALESCE(SUM(b.area),0) AS ha
             FROM blocks b JOIN divisions d ON d.division_id = b.division_id
             WHERE d.business_unit_id = ?"
        );
        $haStmt->execute([$buId]);
        $ha = (float)$haStmt->fetch()['ha'] ?: (float)($buRow['total_area'] ?? 0);

        $byDiv = $db->prepare(
            "SELECT bu.business_unit_id, d.division_id, d.division_name,
                    COALESCE(SUM(b.area),0) AS ha, COUNT(DISTINCT b.block_id) AS block_count
             FROM blocks b
             JOIN divisions d       ON d.division_id       = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE d.business_unit_id = ?
             GROUP BY bu.business_unit_id, d.division_id, d.division_name
             ORDER BY ha DESC"
        );
        $byDiv->execute([$buId]);

        return array_merge([
            'type'        => 'area',
            'question'    => $q,
            'scope_type'  => 'Business Unit',
            'scope'       => $buRow['unit_name'],
            'ha'          => $ha,
            'detail_div'  => $byDiv->fetchAll(),
            'show_counts' => true,
        ], $npDetailForScope('d.business_unit_id = ?', [$buId]));
    }

    // 3. Try company
    $coRow = agro_find_company($db, $place);

    if ($coRow) {
        $coId = (int)$coRow['company_id'];
        $haStmt = $db->prepare(
            "SELECT COALESCE(SUM(b.area),0) AS ha
             FROM blocks b
             JOIN divisions d       ON d.division_id       = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE bu.company_id = ?"
        );
        $haStmt->execute([$coId]);
        $ha = (float)$haStmt->fetch()['ha'];

        // BU + division breakdown
        $byBU = $db->prepare(
            "SELECT bu.business_unit_id, bu.unit_name, bu.company_id,
                    COALESCE(SUM(b.area),0) AS ha,
                    COUNT(DISTINCT d.division_id) AS div_count,
                    COUNT(DISTINCT b.block_id)    AS block_count
             FROM blocks b
             JOIN divisions d       ON d.division_id       = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE bu.company_id = ?
             GROUP BY bu.business_unit_id, bu.unit_name, bu.company_id
             ORDER BY ha DESC"
        );
        $byBU->execute([$coId]);
        $buRows = $byBU->fetchAll();

        $byDiv = $db->prepare(
            "SELECT bu.business_unit_id, d.division_id, d.division_name,
                    COALESCE(SUM(b.area),0) AS ha, COUNT(DISTINCT b.block_id) AS block_count
             FROM blocks b
             JOIN divisions d       ON d.division_id       = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE bu.company_id = ?
             GROUP BY bu.business_unit_id, d.division_id, d.division_name
             ORDER BY bu.business_unit_id ASC, ha DESC"
        );
        $byDiv->execute([$coId]);

        // Non-planted per BU for this company
        $npBUStmt = $db->prepare(
            "SELECT bu.business_unit_id, COALESCE(SUM(bacv.value),0) AS non_planted_ha
             FROM block_area_component_values bacv
             JOIN area_component_measurement_types acmt ON acmt.id = bacv.measurement_type_id
             JOIN block_area_component_categories  bacc ON bacc.id = bacv.category_id
             JOIN blocks b ON b.block_id = bacv.block_id
             JOIN divisions d ON d.division_id = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE bu.company_id = ? AND bacc.category_type='non_planted'
               AND acmt.measurement_code='AREA' AND acmt.data_type!='text'
             GROUP BY bu.business_unit_id"
        );
        $npBUStmt->execute([$coId]);
        $npBUMap = [];
        foreach ($npBUStmt->fetchAll() as $r) {
            $npBUMap[(int)$r['business_unit_id']] = (float)$r['non_planted_ha'];
        }

        $npDivStmt = $db->prepare(
            "SELECT d.division_id, COALESCE(SUM(bacv.value),0) AS non_planted_ha
             FROM block_area_component_values bacv
             JOIN area_component_measurement_types acmt ON acmt.id = bacv.measurement_type_id
             JOIN block_area_component_categories  bacc ON bacc.id = bacv.category_id
             JOIN blocks b ON b.block_id = bacv.block_id
             JOIN divisions d ON d.division_id = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE bu.company_id = ? AND bacc.category_type='non_planted'
               AND acmt.measurement_code='AREA' AND acmt.data_type!='text'
             GROUP BY d.division_id"
        );
        $npDivStmt->execute([$coId]);
        $npDivMap = [];
        foreach ($npDivStmt->fetchAll() as $r) {
            $npDivMap[(int)$r['division_id']] = (float)$r['non_planted_ha'];
        }

        $npTotal = array_sum($npBUMap);

        return array_merge([
            'type'        => 'area',
            'question'    => $q,
            'scope_type'  => 'Company',
            'scope'       => $coRow['company_name'],
            'ha'          => $ha,
            'breakdown'   => array_map(fn($r) => $r + [
                'unit_name'      => $r['unit_name'],
                'company_id'     => $r['company_id'],
                'non_planted_ha' => $npBUMap[(int)$r['business_unit_id']] ?? 0.0,
            ], $buRows),
            'detail_bu'   => array_map(fn($r) => $r + [
                'non_planted_ha' => $npBUMap[(int)$r['business_unit_id']] ?? 0.0,
            ], $buRows),
            'detail_div'  => array_map(fn($r) => $r + [
                'non_planted_ha' => $npDivMap[(int)$r['division_id']] ?? 0.0,
            ], $byDiv->fetchAll()),
            'show_counts' => true,
        ], $npDetailForScope('bu.company_id = ?', [$coId]));
    }

    return agro_did_you_mean($db, 'division', $place, $q);
}

function agro_mill_production(PDO $db, string $q): array
{
    try {
        $row = $db->query(
            "SELECT COUNT(*)                               AS batches,
                    COALESCE(SUM(b.ffb_input_kg), 0)       AS ffb_kg,
                    COALESCE(SUM(p.cpo_produced_kg),  0)   AS cpo_kg,
                    COALESCE(SUM(p.kernel_produced_kg), 0) AS kernel_kg,
                    MIN(p.production_date)                 AS first_date,
                    MAX(p.production_date)                 AS last_date
             FROM mill_production p
             INNER JOIN mill_processing_batch b ON b.batch_id = p.batch_id"
        )->fetch();

        return [
            'type'       => 'mill_production',
            'question'   => $q,
            'batches'    => (int)$row['batches'],
            'ffb_kg'     => (float)$row['ffb_kg'],
            'cpo_kg'     => (float)$row['cpo_kg'],
            'kernel_kg'  => (float)$row['kernel_kg'],
            'first_date' => $row['first_date'],
            'last_date'  => $row['last_date'],
        ];
    } catch (\Exception $e) {
        return [
            'type'    => 'unknown',
            'message' => 'Data produksi pabrik belum tersedia.',
        ];
    }
}

function agro_nursery_summary(PDO $db, string $q, string $place = ''): array
{
    try {
        // ── Resolve optional scope (BU or company) ────────────────────────────
        $scopeLabel = '';
        $buIds      = [];   // list of business_unit_id to filter on

        if ($place !== '') {
            // Try business unit first
            $buStmt = $db->prepare(
                "SELECT business_unit_id, unit_name FROM business_units
                  WHERE unit_name LIKE ? OR unit_code LIKE ?
                  ORDER BY LENGTH(unit_name) ASC LIMIT 1"
            );
            $buStmt->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $buStmt->fetch();

            if ($buRow) {
                $buIds[]    = (int)$buRow['business_unit_id'];
                $scopeLabel = $buRow['unit_name'];
            } else {
                // Try company
                $coRow = agro_find_company($db, $place);

                if ($coRow) {
                    $scopeLabel = $coRow['company_name'];
                    foreach ($db->query(
                        "SELECT business_unit_id FROM business_units
                          WHERE company_id = " . (int)$coRow['company_id']
                    )->fetchAll() as $r) {
                        $buIds[] = (int)$r['business_unit_id'];
                    }
                }
            }

            if (empty($buIds)) {
                return agro_did_you_mean($db, 'business_unit', $place, $q);
            }
        }

        // Build optional WHERE clause fragment
        $whereClause = '';
        $bindIds     = [];
        if (!empty($buIds)) {
            $placeholders = implode(',', array_fill(0, count($buIds), '?'));
            $whereClause  = " WHERE ns.business_unit_id IN ($placeholders)";
            $bindIds      = $buIds;
        }

        // Overall totals
        $totStmt = $db->prepare(
            "SELECT COUNT(*)                            AS batches,
                    COALESCE(SUM(ns.quantity_seeds), 0)    AS total_seeds,
                    COALESCE(SUM(ns.quantity_sprouts), 0)  AS total_sprouts,
                    COALESCE(SUM(ns.quantity_polybag), 0)  AS total_polybag,
                    COALESCE(SUM(ns.quantity_ready), 0)    AS total_ready,
                    MIN(ns.germination_date)               AS first_date,
                    MAX(ns.germination_date)               AS last_date
             FROM nursery_stocks ns" . $whereClause
        );
        $totStmt->execute($bindIds);
        $row = $totStmt->fetch();

        $seeds    = (int)$row['total_seeds'];
        $sprouts  = (int)$row['total_sprouts'];
        $polybag  = (int)$row['total_polybag'];
        $ready    = (int)$row['total_ready'];

        // Computed rates
        $germRate    = $seeds   > 0 ? round($sprouts / $seeds   * 100, 2) : null;
        $preNursRate = $sprouts > 0 ? round($polybag / $sprouts * 100, 2) : null;
        $mainNursRate= $polybag > 0 ? round($ready   / $polybag * 100, 2) : null;

        // Per-variety breakdown
        $varStmt = $db->prepare(
            "SELECT pv.variety_name, pv.variety_code,
                    COUNT(ns.id)                         AS batches,
                    COALESCE(SUM(ns.quantity_seeds), 0)  AS seeds,
                    COALESCE(SUM(ns.quantity_sprouts),0) AS sprouts,
                    COALESCE(SUM(ns.quantity_polybag),0) AS polybag,
                    COALESCE(SUM(ns.quantity_ready), 0)  AS ready
             FROM nursery_stocks ns
             JOIN plant_varieties pv ON pv.variety_id = ns.plant_variety_id"
             . $whereClause .
            " GROUP BY pv.variety_id, pv.variety_name, pv.variety_code
             ORDER BY seeds DESC"
        );
        $varStmt->execute($bindIds);
        $byVariety = $varStmt->fetchAll();

        // Per-status breakdown
        $statStmt = $db->prepare(
            "SELECT ns.status,
                    COUNT(*)                            AS batches,
                    COALESCE(SUM(ns.quantity_seeds), 0) AS seeds,
                    COALESCE(SUM(ns.quantity_ready), 0) AS ready
             FROM nursery_stocks ns" . $whereClause .
            " GROUP BY ns.status
             ORDER BY CASE ns.status
                 WHEN 'Germination'  THEN 1
                 WHEN 'Sprout'       THEN 2
                 WHEN 'Polybag'      THEN 3
                 WHEN 'Ready'        THEN 4
                 WHEN 'Distributed'  THEN 5
                 ELSE 6
             END"
        );
        $statStmt->execute($bindIds);
        $byStatus = $statStmt->fetchAll();

        return [
            'type'          => 'nursery_summary',
            'question'      => $q,
            'scope'         => $scopeLabel,
            'batches'       => (int)$row['batches'],
            'total_seeds'   => $seeds,
            'total_sprouts' => $sprouts,
            'total_polybag' => $polybag,
            'total_ready'   => $ready,
            'germ_rate'     => $germRate,
            'pre_nurs_rate' => $preNursRate,
            'main_nurs_rate'=> $mainNursRate,
            'by_variety'    => $byVariety,
            'by_status'     => $byStatus,
            'first_date'    => $row['first_date'],
            'last_date'     => $row['last_date'],
        ];
    } catch (\Exception $e) {
        return [
            'type'    => 'unknown',
            'message' => 'Data pembibitan belum tersedia: ' . $e->getMessage(),
        ];
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// Nursery per Batch
// ─────────────────────────────────────────────────────────────────────────────

function agro_nursery_by_batch(PDO $db, string $q, string $place = ''): array
{
    // Resolve optional scope: BU → company
    $scopeLabel  = 'Semua Kebun';
    $scopeWhere  = '';
    $bindIds     = [];

    if ($place !== '') {
        $buStmt = $db->prepare(
            "SELECT business_unit_id, unit_name FROM business_units
              WHERE unit_name LIKE ? OR unit_code LIKE ?
              ORDER BY LENGTH(unit_name) ASC LIMIT 1"
        );
        $buStmt->execute(['%' . $place . '%', '%' . $place . '%']);
        $buRow = $buStmt->fetch();

        if ($buRow) {
            $scopeLabel = $buRow['unit_name'];
            $bindIds[]  = (int)$buRow['business_unit_id'];
            $scopeWhere = 'WHERE ns.business_unit_id = ?';
        } else {
            $coRow = agro_find_company($db, $place);
            if ($coRow) {
                $scopeLabel = $coRow['company_name'];
                foreach ($db->query(
                    "SELECT business_unit_id FROM business_units WHERE company_id = " . (int)$coRow['company_id']
                )->fetchAll() as $r) {
                    $bindIds[] = (int)$r['business_unit_id'];
                }
                if (!empty($bindIds)) {
                    $ph = implode(',', array_fill(0, count($bindIds), '?'));
                    $scopeWhere = "WHERE ns.business_unit_id IN ($ph)";
                }
            } else {
                return agro_did_you_mean($db, 'business_unit', $place, $q);
            }
        }
    }

    try {
        // One row per batch_number
        $sql = "SELECT ns.batch_number,
                       bu.unit_name                           AS estate_name,
                       pv.variety_name, pv.variety_code,
                       ns.seed_source,
                       ns.germination_date,
                       ns.status,
                       ns.quantity_seeds                      AS seeds,
                       ns.quantity_sprouts                    AS sprouts,
                       ns.quantity_polybag                    AS polybag,
                       ns.quantity_ready                      AS ready,
                       ns.notes
                FROM nursery_stocks ns
                LEFT JOIN business_units  bu ON bu.business_unit_id = ns.business_unit_id
                LEFT JOIN plant_varieties pv ON pv.variety_id       = ns.plant_variety_id
                $scopeWhere
                ORDER BY ns.germination_date DESC, ns.batch_number";

        $stmt = $db->prepare($sql);
        $stmt->execute($bindIds);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return [
            'type'      => 'nursery_by_batch',
            'question'  => $q,
            'scope'     => $scopeLabel,
            'batches'   => [],
            'totals'    => [],
            'empty'     => true,
            'db_error'  => $e->getMessage(),
        ];
    }

    if (empty($rows)) {
        return [
            'type'    => 'nursery_by_batch',
            'question'=> $q,
            'scope'   => $scopeLabel,
            'batches' => [],
            'totals'  => [],
            'empty'   => true,
        ];
    }

    // Compute per-batch rates + grand totals
    $batches = [];
    $gtSeeds = 0; $gtSprouts = 0; $gtPolybag = 0; $gtReady = 0;
    foreach ($rows as $r) {
        $s  = (int)$r['seeds'];
        $sp = (int)$r['sprouts'];
        $pb = (int)$r['polybag'];
        $rd = (int)$r['ready'];
        $batches[] = [
            'batch_number'    => $r['batch_number'],
            'estate'          => $r['estate_name']   ?? '',
            'variety'         => $r['variety_name']  ?? '',
            'variety_code'    => $r['variety_code']  ?? '',
            'seed_source'     => $r['seed_source']   ?? '',
            'germination_date'=> $r['germination_date'] ?? '',
            'status'          => $r['status']         ?? '',
            'seeds'           => $s,
            'sprouts'         => $sp,
            'polybag'         => $pb,
            'ready'           => $rd,
            'germ_rate'       => $s  > 0 ? round($sp / $s  * 100, 1) : null,
            'pre_rate'        => $sp > 0 ? round($pb / $sp * 100, 1) : null,
            'main_rate'       => $pb > 0 ? round($rd / $pb * 100, 1) : null,
            'notes'           => $r['notes'] ?? '',
        ];
        $gtSeeds   += $s;
        $gtSprouts += $sp;
        $gtPolybag += $pb;
        $gtReady   += $rd;
    }

    $totals = [
        'seeds'     => $gtSeeds,
        'sprouts'   => $gtSprouts,
        'polybag'   => $gtPolybag,
        'ready'     => $gtReady,
        'germ_rate' => $gtSeeds   > 0 ? round($gtSprouts / $gtSeeds   * 100, 1) : null,
        'pre_rate'  => $gtSprouts > 0 ? round($gtPolybag / $gtSprouts * 100, 1) : null,
        'main_rate' => $gtPolybag > 0 ? round($gtReady   / $gtPolybag * 100, 1) : null,
    ];

    return [
        'type'    => 'nursery_by_batch',
        'question'=> $q,
        'scope'   => $scopeLabel,
        'batches' => $batches,
        'totals'  => $totals,
        'count'   => count($batches),
        'empty'   => false,
    ];
}


function agro_rendemen(PDO $db, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Build optional date filter
    $dateWhere  = '';
    $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = 'AND p.production_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = 'AND p.production_date >= ?';
        $dateParams = [$dateFrom];
    }

    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*)                               AS batches,
                    COALESCE(SUM(b.ffb_input_kg), 0)       AS ffb_kg,
                    COALESCE(SUM(p.cpo_produced_kg),  0)   AS cpo_kg,
                    COALESCE(SUM(p.kernel_produced_kg), 0) AS kernel_kg,
                    AVG(p.oil_extraction_rate)             AS avg_oer,
                    AVG(p.kernel_extraction_rate)          AS avg_ker,
                    MIN(p.oil_extraction_rate)             AS min_oer,
                    MAX(p.oil_extraction_rate)             AS max_oer,
                    MIN(p.kernel_extraction_rate)          AS min_ker,
                    MAX(p.kernel_extraction_rate)          AS max_ker,
                    AVG(p.ffa_percentage)                  AS avg_ffa,
                    MIN(p.ffa_percentage)                  AS min_ffa,
                    MAX(p.ffa_percentage)                  AS max_ffa,
                    AVG(p.moisture_content)                AS avg_moisture,
                    MIN(p.moisture_content)                AS min_moisture,
                    MAX(p.moisture_content)                AS max_moisture,
                    MIN(p.production_date)                 AS first_date,
                    MAX(p.production_date)                 AS last_date
             FROM mill_production p
             INNER JOIN mill_processing_batch b ON b.batch_id = p.batch_id
             WHERE b.ffb_input_kg > 0 $dateWhere"
        );
        $stmt->execute($dateParams);
        $row = $stmt->fetch();

        // Weighted rates from totals (more accurate than AVG of stored rates)
        $ffbKg = (float)$row['ffb_kg'];
        $cpoKg = (float)$row['cpo_kg'];
        $kerKg = (float)$row['kernel_kg'];
        $oer   = $ffbKg > 0 ? round($cpoKg / $ffbKg * 100, 2) : (float)$row['avg_oer'];
        $ker   = $ffbKg > 0 ? round($kerKg / $ffbKg * 100, 2) : (float)$row['avg_ker'];

        // Monthly breakdown (filtered to same range)
        $mStmt = $db->prepare(
            "SELECT DATE_FORMAT(p.production_date, '%Y-%m') AS bulan,
                    COUNT(*)                                 AS batches,
                    COALESCE(SUM(b.ffb_input_kg), 0)        AS ffb_kg,
                    COALESCE(SUM(p.cpo_produced_kg),  0)    AS cpo_kg,
                    COALESCE(SUM(p.kernel_produced_kg), 0)  AS kernel_kg,
                    AVG(p.ffa_percentage)                   AS avg_ffa,
                    AVG(p.moisture_content)                 AS avg_moisture
             FROM mill_production p
             INNER JOIN mill_processing_batch b ON b.batch_id = p.batch_id
             WHERE b.ffb_input_kg > 0 $dateWhere
             GROUP BY DATE_FORMAT(p.production_date, '%Y-%m')
             ORDER BY bulan ASC"
        );
        $mStmt->execute($dateParams);
        $monthly = $mStmt->fetchAll();

        // ── Kernel stock summary ─────────────────────────────────────────────
        $kernelStock = [];
        $kernelStockTotal = 0.0;
        $kernelStockIn    = 0.0;
        $kernelStockOut   = 0.0;
        try {
            $ksRow = $db->query(
                "SELECT
                    COALESCE(SUM(CASE WHEN transaction_type = 'in'  THEN quantity_kg ELSE 0 END), 0) AS total_in,
                    COALESCE(SUM(CASE WHEN transaction_type = 'out' THEN quantity_kg ELSE 0 END), 0) AS total_out,
                    COUNT(*) AS tx_count,
                    MAX(transaction_date) AS last_tx_date
                 FROM kernel_stock_transactions"
            )->fetch();
            $kernelStockIn  = (float)($ksRow['total_in']  ?? 0);
            $kernelStockOut = (float)($ksRow['total_out'] ?? 0);

            // Per-storage current stock via view (graceful fallback)
            try {
                $ksByStorage = $db->query(
                    "SELECT s.storage_code, s.storage_name, s.capacity_kg,
                            COALESCE(v.current_stock_kg, 0) AS current_stock_kg
                     FROM kernel_storage s
                     LEFT JOIN vw_kernel_stock_summary v ON v.storage_id = s.storage_id
                     WHERE s.status = 'active'
                     ORDER BY current_stock_kg DESC"
                )->fetchAll();
            } catch (\Exception $e2) {
                $ksByStorage = [];
            }
            $kernelStockTotal = array_sum(array_column($ksByStorage, 'current_stock_kg'));
            $kernelStock = [
                'total_in'     => $kernelStockIn,
                'total_out'    => $kernelStockOut,
                'current_stock'=> $kernelStockTotal,
                'tx_count'     => (int)($ksRow['tx_count'] ?? 0),
                'last_tx_date' => $ksRow['last_tx_date'] ?? null,
                'by_storage'   => $ksByStorage,
            ];
        } catch (\Exception $e) {
            // kernel_stock_transactions table may not exist yet — silently skip
        }

        return [
            'type'         => 'rendemen',
            'question'     => $q,
            'date_label'   => $dateLabel,
            'batches'      => (int)$row['batches'],
            'ffb_kg'       => $ffbKg,
            'cpo_kg'       => $cpoKg,
            'kernel_kg'    => $kerKg,
            'oer'          => $oer,
            'ker'          => $ker,
            'min_oer'      => (float)$row['min_oer'],
            'max_oer'      => (float)$row['max_oer'],
            'min_ker'      => (float)$row['min_ker'],
            'max_ker'      => (float)$row['max_ker'],
            'avg_ffa'      => $row['avg_ffa']      !== null ? round((float)$row['avg_ffa'],      3) : null,
            'min_ffa'      => $row['min_ffa']      !== null ? (float)$row['min_ffa']             : null,
            'max_ffa'      => $row['max_ffa']      !== null ? (float)$row['max_ffa']             : null,
            'avg_moisture' => $row['avg_moisture'] !== null ? round((float)$row['avg_moisture'],  3) : null,
            'min_moisture' => $row['min_moisture'] !== null ? (float)$row['min_moisture']         : null,
            'max_moisture' => $row['max_moisture'] !== null ? (float)$row['max_moisture']         : null,
            'monthly'      => $monthly,
            'first_date'   => $row['first_date'],
            'last_date'    => $row['last_date'],
            'kernel_stock' => $kernelStock,
        ];
    } catch (\Exception $e) {
        return [
            'type'    => 'unknown',
            'message' => 'Data rendemen / mill production belum tersedia.',
        ];
    }
}

function agro_cpo_stock(PDO $db, string $q): array
{
    try {
        $row = $db->query(
            "SELECT COALESCE(SUM(quantity_kg),0) AS total_kg,
                    COUNT(*) AS records,
                    MAX(transaction_date) AS last_date
             FROM cpo_stock_transactions"
        )->fetch();

        return [
            'type'      => 'cpo_stock',
            'question'  => $q,
            'total_kg'  => (float)$row['total_kg'],
            'records'   => (int)$row['records'],
            'last_date' => $row['last_date'],
        ];
    } catch (\Exception $e) {
        return [
            'type'    => 'unknown',
            'message' => 'Data stok CPO/Kernel belum tersedia.',
        ];
    }
}

function agro_harvest_plans(PDO $db, string $block, string $q): array
{
    $blk = $db->prepare("SELECT block_id, block_name, block_code FROM blocks WHERE block_name LIKE ? OR block_code LIKE ? ORDER BY LENGTH(block_name) ASC LIMIT 1");
    $blk->execute(['%' . $block . '%', '%' . $block . '%']);
    $blkRow = $blk->fetch();

    if (!$blkRow) {
        return agro_did_you_mean($db, 'block', $block, $q);
    }

    $rows = $db->prepare(
        "SELECT plan_number, plan_date, planned_start_date, planned_end_date,
                estimated_quantity_kg, status, supervisor
         FROM harvest_plans WHERE block_id = ?
         ORDER BY plan_date DESC LIMIT 10"
    );
    $rows->execute([(int)$blkRow['block_id']]);
    $plans = $rows->fetchAll();

    return [
        'type'     => 'harvest_plans',
        'question' => $q,
        'block'    => $blkRow['block_name'] . ' (' . $blkRow['block_code'] . ')',
        'plans'    => $plans,
        'count'    => count($plans),
    ];
}

function agro_find_block(PDO $db, string $block, string $q): array
{
    $rows = $db->prepare(
        "SELECT b.block_code, b.block_name, b.area, b.status, b.harvest_status,
                b.total_plants, b.topography, b.soil_type, b.plant_age,
                py.year AS planting_year, d.division_name,
                bu.unit_name AS business_unit
         FROM blocks b
         JOIN planting_years py ON py.planting_year_id = b.planting_year_id
         JOIN divisions d ON d.division_id = py.division_id
         JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         WHERE b.block_name LIKE ? OR b.block_code LIKE ?
         ORDER BY b.block_code ASC LIMIT 20"
    );
    $rows->execute(['%' . $block . '%', '%' . $block . '%']);
    $blocks = $rows->fetchAll();

    if (empty($blocks)) {
        return agro_did_you_mean($db, 'block', $block, $q);
    }

    return [
        'type'     => 'find_block',
        'question' => $q,
        'blocks'   => $blocks,
        'count'    => count($blocks),
    ];
}

function agro_companies(PDO $db, string $q): array
{
    $rows = $db->query(
        "SELECT company_code, company_name, city, province, status,
                (SELECT COUNT(*) FROM business_units WHERE company_id = c.company_id) AS bu_count
         FROM companies c ORDER BY company_name ASC"
    )->fetchAll();

    return [
        'type'      => 'companies',
        'question'  => $q,
        'companies' => $rows,
        'count'     => count($rows),
    ];
}

function agro_top_blocks(PDO $db, string $q): array
{
    $rows = $db->query(
        "SELECT b.block_code, b.block_name,
                d.division_name,
                bu.unit_name AS business_unit,
                COALESCE(SUM(hr.actual_quantity_kg),0) AS total_kg,
                COUNT(hr.harvest_id) AS harvests
         FROM blocks b
         LEFT JOIN harvest_realizations hr ON hr.block_id = b.block_id
         JOIN planting_years py ON py.planting_year_id = b.planting_year_id
         JOIN divisions d ON d.division_id = py.division_id
         JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         GROUP BY b.block_id, b.block_code, b.block_name, d.division_name, bu.unit_name
         ORDER BY total_kg DESC LIMIT 10"
    )->fetchAll();

    return [
        'type'     => 'top_blocks',
        'question' => $q,
        'blocks'   => $rows,
        'count'    => count($rows),
    ];
}

function agro_plant_density(PDO $db, string $place, string $q): array
{
    // Resolve scope: company → BU → division cascade
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    $coRow = agro_find_company($db, $place);
    if ($coRow) {
        $scopeLabel = $coRow['company_name'];
        $scopeWhere = 'bu.company_id = ?';
        $scopeParam = (int)$coRow['company_id'];
        $scopeLevel = 'company';
    }

    if (!$scopeParam) {
        $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
        $bu->execute(['%' . $place . '%']);
        $buRow = $bu->fetch();
        if ($buRow) {
            $scopeLabel = $buRow['unit_name'];
            $scopeWhere = 'd.business_unit_id = ?';
            $scopeParam = (int)$buRow['business_unit_id'];
            $scopeLevel = 'bu';
        }
    }

    if (!$scopeParam) {
        $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
        $div->execute(['%' . $place . '%']);
        $divRow = $div->fetch();
        if ($divRow) {
            $scopeLabel = $divRow['division_name'];
            $scopeWhere = 'py.division_id = ?';
            $scopeParam = (int)$divRow['division_id'];
            $scopeLevel = 'division';
        }
    }

    if (!$scopeParam) {
        return agro_did_you_mean($db, 'business_unit', $place, $q);
    }

    $rows = $db->prepare(
        "SELECT bu.unit_name            AS business_unit,
                d.division_name,
                COUNT(b.block_id)                                           AS block_count,
                COALESCE(SUM(b.area), 0)                                    AS total_ha,
                COALESCE(SUM(b.planted_area), 0)                            AS planted_ha,
                COALESCE(SUM(b.total_plants), 0)                            AS total_plants,
                COALESCE(SUM(b.normal_plants), 0)                           AS normal_plants,
                COALESCE(SUM(b.abnormal_plants), 0)                         AS abnormal_plants,
                COALESCE(SUM(b.dead_plants), 0)                             AS dead_plants,
                ROUND(AVG(NULLIF(b.plant_density, 0)), 1)                   AS design_density,
                ROUND(COALESCE(SUM(b.total_plants), 0)
                    / NULLIF(COALESCE(SUM(b.planted_area), 0), 0), 1)      AS actual_density
         FROM blocks b
         JOIN planting_years py ON py.planting_year_id = b.planting_year_id
         JOIN divisions d       ON d.division_id        = py.division_id
         JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
         WHERE $scopeWhere
           AND b.operation_type = 'Plantation'
         GROUP BY d.division_id, d.division_name, bu.unit_name
         ORDER BY bu.unit_name ASC, d.division_name ASC"
    );
    $rows->execute([$scopeParam]);
    $divs = $rows->fetchAll();

    $grandHa      = array_sum(array_column($divs, 'total_ha'));
    $grandPlanted = array_sum(array_column($divs, 'planted_ha'));
    $grandPlants  = array_sum(array_column($divs, 'total_plants'));
    $grandNormal  = array_sum(array_column($divs, 'normal_plants'));
    $grandAbnorm  = array_sum(array_column($divs, 'abnormal_plants'));
    $grandDead    = array_sum(array_column($divs, 'dead_plants'));
    $grandActual  = $grandPlanted > 0 ? round($grandPlants / $grandPlanted, 1) : null;

    return [
        'type'          => 'plant_density',
        'question'      => $q,
        'scope'         => $scopeLabel,
        'scope_level'   => $scopeLevel,
        'rows'          => $divs,
        'grand_ha'      => $grandHa,
        'grand_planted' => $grandPlanted,
        'grand_plants'  => $grandPlants,
        'grand_normal'  => $grandNormal,
        'grand_abnorm'  => $grandAbnorm,
        'grand_dead'    => $grandDead,
        'grand_actual'  => $grandActual,
    ];
}

function agro_area_by_division(PDO $db, string $place, string $q): array
{
    // Build scope: try company first, then business unit
    $scopeLabel = '';
    $scopeWhere = '';
    $scopeParam = null;

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);

        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'WHERE bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
        } else {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'WHERE d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
            }
        }

        if ($scopeParam === null) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        // No place given — fall back to logged-in user's session scope
        $ss = agro_session_scope($db);
        if ($ss) {
            $scopeLabel = $ss['label'];
            $scopeWhere = $ss['where_plain'];
            $scopeParam = $ss['param'];
        }
        // no scope at all → no WHERE clause, show all divisions
    }

    $rows = $db->prepare(
        "SELECT d.division_id,
                bu.unit_name   AS business_unit,
                d.division_name,
                d.division_type,
                COUNT(b.block_id)               AS block_count,
                COALESCE(SUM(b.area), 0)        AS total_ha,
                COALESCE(SUM(CASE WHEN b.status='TM'  THEN b.area ELSE 0 END), 0) AS tm_ha,
                COALESCE(SUM(CASE WHEN b.status='TBM' THEN b.area ELSE 0 END), 0) AS tbm_ha,
                COALESCE(SUM(b.total_plants), 0) AS total_plants
         FROM divisions d
         JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         LEFT JOIN planting_years py ON py.division_id = d.division_id
         LEFT JOIN blocks b ON b.planting_year_id = py.planting_year_id
         $scopeWhere
         GROUP BY d.division_id, d.division_name, d.division_type, bu.unit_name
         ORDER BY bu.unit_name ASC, d.division_name ASC"
    );
    $rows->execute($scopeParam !== null ? [$scopeParam] : []);
    $divs = $rows->fetchAll();

    // ── Non-planted area per division ─────────────────────────────────────────
    // Reuse the same WHERE clause built above, adapted for the NP tables.
    // scopeWhere uses "WHERE bu.company_id = ?" or "WHERE d.business_unit_id = ?";
    // replace "WHERE" with "AND" for embedding inside the NP query's own WHERE.
    $npWhere  = str_replace('WHERE ', 'AND ', $scopeWhere);

    $npDivStmt = $db->prepare(
        "SELECT d.division_id,
                COALESCE(SUM(bacv.value), 0) AS non_planted_ha
         FROM block_area_component_values bacv
         JOIN area_component_measurement_types acmt ON acmt.id   = bacv.measurement_type_id
         JOIN block_area_component_categories  bacc ON bacc.id   = bacv.category_id
         JOIN blocks b ON b.block_id = bacv.block_id
         JOIN divisions d       ON d.division_id       = b.division_id
         JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         WHERE bacc.category_type = 'non_planted'
           AND acmt.measurement_code = 'AREA'
           AND acmt.data_type != 'text'
           $npWhere
         GROUP BY d.division_id"
    );
    $npDivStmt->execute($scopeParam !== null ? [$scopeParam] : []);
    $npDivMap = [];
    foreach ($npDivStmt->fetchAll() as $r) {
        $npDivMap[(int)$r['division_id']] = (float)$r['non_planted_ha'];
    }

    // Per-division per-category breakdown (for the detail section)
    $npDetailStmt = $db->prepare(
        "SELECT d.division_name,
                bacc.category_code, bacc.category_name,
                COALESCE(SUM(bacv.value), 0) AS ha
         FROM block_area_component_values bacv
         JOIN area_component_measurement_types acmt ON acmt.id   = bacv.measurement_type_id
         JOIN block_area_component_categories  bacc ON bacc.id   = bacv.category_id
         JOIN blocks b ON b.block_id = bacv.block_id
         JOIN divisions d       ON d.division_id       = b.division_id
         JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         WHERE bacc.category_type = 'non_planted'
           AND acmt.measurement_code = 'AREA'
           AND acmt.data_type != 'text'
           $npWhere
         GROUP BY d.division_id, d.division_name, bacc.id, bacc.category_code, bacc.category_name
         ORDER BY d.division_name ASC, ha DESC"
    );
    $npDetailStmt->execute($scopeParam !== null ? [$scopeParam] : []);
    $npDetail = $npDetailStmt->fetchAll();

    // Distinct NP category codes/names that actually have data
    $npCategories = [];
    foreach ($npDetail as $r) {
        $npCategories[$r['category_code']] = $r['category_name'];
    }

    // Merge non_planted_ha into each division row using division_id (now in SELECT)
    $divsWithNp = array_map(function($r) use ($npDivMap) {
        $r = (array)$r;
        $r['non_planted_ha'] = $npDivMap[(int)($r['division_id'] ?? 0)] ?? 0.0;
        return $r;
    }, $divs);

    // Compute grand total
    $grandHa     = array_sum(array_column($divsWithNp, 'total_ha'));
    $grandTm     = array_sum(array_column($divsWithNp, 'tm_ha'));
    $grandTbm    = array_sum(array_column($divsWithNp, 'tbm_ha'));
    $grandBlocks = array_sum(array_column($divsWithNp, 'block_count'));
    $grandPlants = array_sum(array_column($divsWithNp, 'total_plants'));
    $grandNp     = array_sum(array_column($divsWithNp, 'non_planted_ha'));

    return [
        'type'          => 'area_by_division',
        'question'      => $q,
        'scope'         => $scopeLabel,
        'rows'          => $divsWithNp,
        'grand_ha'      => $grandHa,
        'grand_tm'      => $grandTm,
        'grand_tbm'     => $grandTbm,
        'grand_blocks'  => $grandBlocks,
        'grand_plants'  => $grandPlants,
        'grand_np'      => $grandNp,
        'np_detail'     => $npDetail,
        'np_categories' => $npCategories,
    ];
}

function agro_bridge_count(PDO $db, string $place, string $q): array
{
    // Resolve scope: company → BU → division cascade
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }

        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }

        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }

        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        // No place given — fall back to logged-in user's session scope
        $ss = agro_session_scope($db);
        if ($ss) {
            $scopeLabel = $ss['label'];
            $scopeWhere = $ss['where_and'];
            $scopeParam = $ss['param'];
            $scopeLevel = $ss['level'];
        }
    }

    try {
        // Fetch LENGTH (primary/required) and COUNT (optional) per division,
        // mirroring how ROADS uses LENGTH as its main measurement.
        $rows = $db->prepare(
            "SELECT d.division_id,
                    d.division_name,
                    bu.unit_name AS business_unit,
                    COALESCE(SUM(CASE WHEN acmt.measurement_code = 'LENGTH'
                                      THEN bacv.value END), 0)  AS bridge_length_m,
                    COALESCE(SUM(CASE WHEN acmt.measurement_code = 'COUNT'
                                      THEN bacv.value END), 0)  AS bridge_count,
                    -- flag: 1 if ANY block in this division has a LENGTH value entered
                    MAX(CASE WHEN acmt.measurement_code = 'LENGTH'
                              AND bacv.value IS NOT NULL
                              AND bacv.value > 0
                              THEN 1 ELSE 0 END)                AS has_length
             FROM divisions d
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             LEFT JOIN blocks b ON b.division_id = d.division_id
             LEFT JOIN block_area_component_values bacv ON bacv.block_id = b.block_id
             LEFT JOIN block_area_component_categories bacc
                    ON bacc.id = bacv.category_id AND bacc.category_code = 'BRIDGES'
             LEFT JOIN area_component_measurement_types acmt
                    ON acmt.id = bacv.measurement_type_id
             WHERE 1=1 $scopeWhere
             GROUP BY d.division_id, d.division_name, bu.unit_name
             ORDER BY bu.unit_name ASC, d.division_name ASC"
        );
        $rows->execute($scopeParam !== null ? [$scopeParam] : []);
        $divs = $rows->fetchAll();
    } catch (\Exception $e) {
        return ['type' => 'unknown', 'message' => 'Data jembatan belum tersedia.'];
    }

    $grandLengthM = array_sum(array_column($divs, 'bridge_length_m'));
    $grandCount   = (int)array_sum(array_column($divs, 'bridge_count'));
    // warn if no division has any length entered at all
    $missingLength = !array_filter($divs, fn($r) => (int)$r['has_length'] > 0);

    return [
        'type'           => 'bridge_count',
        'question'       => $q,
        'scope'          => $scopeLabel,
        'scope_level'    => $scopeLevel,
        'rows'           => $divs,
        'grand_length_m' => (float)$grandLengthM,
        'grand_count'    => $grandCount,
        'missing_length' => $missingLength,
    ];
}

function agro_map_link(PDO $db, string $place, string $q): array
{
    // Resolve scope: company → BU → division cascade
    // Map page filters by company_id, so we find the company and pass that.
    $scopeLabel  = ''; $companyId = null; $buId = null;

    $coRow = agro_find_company($db, $place);
    if ($coRow) {
        $scopeLabel = $coRow['company_name'];
        $companyId  = (int)$coRow['company_id'];
    }

    if (!$companyId) {
        $bu = $db->prepare("SELECT bu.business_unit_id, bu.unit_name, bu.company_id, c.company_name FROM business_units bu JOIN companies c ON c.company_id = bu.company_id WHERE bu.unit_name LIKE ? ORDER BY LENGTH(bu.unit_name) ASC LIMIT 1");
        $bu->execute(['%' . $place . '%']);
        $buRow = $bu->fetch();
        if ($buRow) {
            $scopeLabel = $buRow['unit_name'];
            $companyId  = (int)$buRow['company_id'];
            $buId       = (int)$buRow['business_unit_id'];
        }
    }

    if (!$companyId) {
        $div = $db->prepare("SELECT d.division_id, d.division_name, bu.company_id, c.company_name FROM divisions d JOIN business_units bu ON bu.business_unit_id = d.business_unit_id JOIN companies c ON c.company_id = bu.company_id WHERE d.division_name LIKE ? ORDER BY LENGTH(d.division_name) ASC LIMIT 1");
        $div->execute(['%' . $place . '%']);
        $divRow = $div->fetch();
        if ($divRow) {
            $scopeLabel = $divRow['division_name'];
            $companyId  = (int)$divRow['company_id'];
        }
    }

    if (!$companyId) {
        return agro_did_you_mean($db, 'company', $place, $q);
    }

    // Count blocks with GeoJSON in this company
    $cnt = $db->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN b.geojson IS NOT NULL AND b.geojson != '' THEN 1 ELSE 0 END), 0) AS with_geojson
         FROM blocks b
         JOIN planting_years py ON py.planting_year_id = b.planting_year_id
         JOIN divisions d ON d.division_id = py.division_id
         JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         WHERE bu.company_id = ?"
    );
    $cnt->execute([$companyId]);
    $stats = $cnt->fetch();

    $mapUrl = 'blocks_map.php?company_id=' . $companyId;

    return [
        'type'         => 'map_link',
        'question'     => $q,
        'scope'        => $scopeLabel,
        'company_id'   => $companyId,
        'map_url'      => $mapUrl,
        'total_blocks' => (int)($stats['total']        ?? 0),
        'geo_blocks'   => (int)($stats['with_geojson'] ?? 0),
    ];
}

function agro_road_by_type(PDO $db, string $place, string $q): array
{
    // Resolve scope: company → BU → division cascade
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }

        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }

        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }

        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        // No place given — fall back to logged-in user's session scope
        $ss = agro_session_scope($db);
        if ($ss) {
            $scopeLabel = $ss['label'];
            $scopeWhere = $ss['where_and'];
            $scopeParam = $ss['param'];
            $scopeLevel = $ss['level'];
        }
        // If still no scope, proceed with no filter (show all)
    }

    try {
        // Fetch all road-type categories that have any data in scope
        $rows = $db->prepare(
            "SELECT bacc.category_name                                          AS road_type,
                    d.division_name,
                    bu.unit_name                                                AS business_unit,
                    COALESCE(SUM(CASE WHEN acmt.measurement_code = 'LENGTH'
                                      THEN bacv.value END), 0)                 AS length_m,
                    COALESCE(SUM(CASE WHEN acmt.measurement_code = 'AREA'
                                      THEN bacv.value END), 0)                 AS area_ha,
                    MAX(CASE WHEN acmt.measurement_code = 'LENGTH'
                              AND bacv.value > 0 THEN 1 ELSE 0 END)            AS has_length
             FROM block_area_component_categories bacc
             JOIN area_component_measurement_types acmt ON acmt.category_id = bacc.id
             JOIN block_area_component_values bacv       ON bacv.category_id = bacc.id
                                                        AND bacv.measurement_type_id = acmt.id
             JOIN blocks b       ON b.block_id            = bacv.block_id
             JOIN divisions d    ON d.division_id          = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE (bacc.category_code LIKE 'ROADS%'
                 OR bacc.category_code IN ('ACCESS_ROAD','MAIN_ROAD','COLL_ROAD'))
               $scopeWhere
             GROUP BY bacc.id, bacc.category_name, d.division_id, d.division_name, bu.unit_name
             ORDER BY bu.unit_name ASC, d.division_name ASC, bacc.display_order ASC, bacc.category_name ASC"
        );
        $rows->execute($scopeParam !== null ? [$scopeParam] : []);
        $data = $rows->fetchAll();
    } catch (\Exception $e) {
        return ['type' => 'unknown', 'message' => 'Data jalan belum tersedia.'];
    }

    // Build pivot: division → road_type → {length_m, area_ha}
    // Also collect sorted list of all road types present
    $divMap    = [];   // [division_name => [bu, types => [road_type => row]]]
    $roadTypes = [];   // ordered unique road types
    foreach ($data as $r) {
        $r    = (array)$r;
        $div  = (string)$r['division_name'];
        $type = (string)$r['road_type'];
        if (!isset($divMap[$div])) {
            $divMap[$div] = ['bu' => $r['business_unit'], 'types' => []];
        }
        $divMap[$div]['types'][$type] = $r;
        if (!in_array($type, $roadTypes, true)) {
            $roadTypes[] = $type;
        }
    }

    // Grand totals per road type
    $grandByType = [];
    foreach ($roadTypes as $t) {
        $grandByType[$t] = ['length_m' => 0, 'area_ha' => 0];
    }
    foreach ($data as $r) {
        $r = (array)$r;
        $grandByType[(string)$r['road_type']]['length_m'] += (float)$r['length_m'];
        $grandByType[(string)$r['road_type']]['area_ha']  += (float)$r['area_ha'];
    }

    return [
        'type'         => 'road_by_type',
        'question'     => $q,
        'scope'        => $scopeLabel,
        'scope_level'  => $scopeLevel,
        'road_types'   => $roadTypes,
        'div_map'      => $divMap,
        'grand_by_type'=> $grandByType,
        'empty'        => empty($data),
    ];
}

/**
 * agro_infrastructure_summary — combined road + bridge data for a scope.
 * Fetches both road (by type) and bridge data in one call for "Analisa Infrastruktur".
 */
function agro_infrastructure_summary(PDO $db, string $q, string $place): array
{
    // ── 1. Resolve scope (company → BU → division) ───────────────────────────
    $scopeLabel = 'Semua Kebun'; $scopeWhere = ''; $scopeParam = null; $scopeLevel = 'all';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }

        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }

        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND d.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }

        if (!$scopeParam) {
            return agro_did_you_mean($db, 'company', $place, $q);
        }
    } else {
        // ── No place given: fall back to the logged-in user's assigned scope ──
        // Use the same priority order as the named-place path (company → BU → division),
        // resolving the tightest scope the user has assigned.
        // We resolve via ID lookup so the WHERE clause and scopeLabel match exactly
        // what the named-place path would produce for the same entity.
        $sessionCoId  = !empty($_SESSION['company_id'])       ? (int)$_SESSION['company_id']       : null;
        $sessionBuId  = !empty($_SESSION['business_unit_id']) ? (int)$_SESSION['business_unit_id'] : null;
        $sessionDivId = !empty($_SESSION['division_id'])      ? (int)$_SESSION['division_id']      : null;

        if ($sessionDivId) {
            $stmt = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_id = ? LIMIT 1");
            $stmt->execute([$sessionDivId]);
            $row = $stmt->fetch();
            if ($row) {
                $scopeLabel = $row['division_name'];
                $scopeWhere = 'AND d.division_id = ?';
                $scopeParam = $sessionDivId;
                $scopeLevel = 'division';
            }
        }

        if (!$scopeParam && $sessionBuId) {
            $stmt = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE business_unit_id = ? LIMIT 1");
            $stmt->execute([$sessionBuId]);
            $row = $stmt->fetch();
            if ($row) {
                $scopeLabel = $row['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = $sessionBuId;
                $scopeLevel = 'bu';
            }
        }

        if (!$scopeParam && $sessionCoId) {
            // Company resolved — but if the company has exactly one BU, narrow to that BU
            // so the result matches "Analisa Infrastruktur <BU name>" exactly.
            $stmt = $db->prepare("SELECT company_id, company_name FROM companies WHERE company_id = ? LIMIT 1");
            $stmt->execute([$sessionCoId]);
            $row = $stmt->fetch();
            if ($row) {
                // Try to narrow to a single BU under this company
                $buStmt = $db->prepare(
                    "SELECT business_unit_id, unit_name FROM business_units
                      WHERE company_id = ? ORDER BY unit_name ASC"
                );
                $buStmt->execute([$sessionCoId]);
                $buList = $buStmt->fetchAll();
                if (count($buList) === 1) {
                    // Exactly one BU — use BU scope (matches named "Analisa Infrastruktur <BU>")
                    $scopeLabel = $buList[0]['unit_name'];
                    $scopeWhere = 'AND d.business_unit_id = ?';
                    $scopeParam = (int)$buList[0]['business_unit_id'];
                    $scopeLevel = 'bu';
                } else {
                    // Multiple BUs — use company scope
                    $scopeLabel = $row['company_name'];
                    $scopeWhere = 'AND bu.company_id = ?';
                    $scopeParam = $sessionCoId;
                    $scopeLevel = 'company';
                }
            }
        }
        // If user has no company/BU/division assigned, $scopeParam stays null → returns all data.
    }

    $bindParam = $scopeParam !== null ? [$scopeParam] : [];

    // ── 2. Road data per division ─────────────────────────────────────────────
    // Schema: category_code='ROADS', measurements: LENGTH (m), AREA (ha)
    $roadRows = [];
    try {
        $rs = $db->prepare(
            "SELECT d.division_name,
                    bu.unit_name                                                AS business_unit,
                    COALESCE(SUM(CASE WHEN acmt.measurement_code = 'LENGTH'
                                      THEN bacv.value END), 0)                 AS length_m,
                    COALESCE(SUM(CASE WHEN acmt.measurement_code = 'AREA'
                                      THEN bacv.value END), 0)                 AS area_ha
             FROM block_area_component_categories bacc
             JOIN area_component_measurement_types acmt ON acmt.category_id = bacc.id
             JOIN block_area_component_values bacv       ON bacv.category_id = bacc.id
                                                        AND bacv.measurement_type_id = acmt.id
             JOIN blocks b       ON b.block_id            = bacv.block_id
             JOIN divisions d    ON d.division_id          = b.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE bacc.category_code = 'ROADS'
               $scopeWhere
             GROUP BY d.division_id, d.division_name, bu.unit_name
             ORDER BY bu.unit_name ASC, d.division_name ASC"
        );
        $rs->execute($bindParam);
        $roadRows = $rs->fetchAll();
    } catch (\Exception $e) { /* no road data */ }

    // Build per-division map and grand totals (single road type — no pivot needed)
    $roadDivMap    = [];
    $roadTypes     = ['Roads & Paths'];
    $roadGrandType = ['Roads & Paths' => ['length_m' => 0, 'area_ha' => 0]];
    foreach ($roadRows as $r) {
        $r   = (array)$r;
        $div = (string)$r['division_name'];
        $roadDivMap[$div] = [
            'bu'    => $r['business_unit'],
            'types' => ['Roads & Paths' => $r],
        ];
        $roadGrandType['Roads & Paths']['length_m'] += (float)$r['length_m'];
        $roadGrandType['Roads & Paths']['area_ha']  += (float)$r['area_ha'];
    }
    $grandRoadM = $roadGrandType['Roads & Paths']['length_m'];

    // ── 3. Bridge data per division ──────────────────────────────────────────
    // Schema: category_code='BRIDGES', measurements: COUNT (units), AREA (ha)
    $bridgeRows = [];
    try {
        $bs = $db->prepare(
            "SELECT d.division_id,
                    d.division_name,
                    bu.unit_name                                                    AS business_unit,
                    COALESCE(SUM(CASE WHEN acmt.measurement_code = 'COUNT'
                                      THEN bacv.value END), 0)                     AS bridge_count,
                    COALESCE(SUM(CASE WHEN acmt.measurement_code = 'AREA'
                                      THEN bacv.value END), 0)                     AS bridge_area_ha
             FROM divisions d
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             LEFT JOIN blocks b ON b.division_id = d.division_id
             LEFT JOIN block_area_component_values bacv ON bacv.block_id = b.block_id
             LEFT JOIN block_area_component_categories bacc
                    ON bacc.id = bacv.category_id AND bacc.category_code = 'BRIDGES'
             LEFT JOIN area_component_measurement_types acmt
                    ON acmt.id = bacv.measurement_type_id
             WHERE 1=1 $scopeWhere
             GROUP BY d.division_id, d.division_name, bu.unit_name
             ORDER BY bu.unit_name ASC, d.division_name ASC"
        );
        $bs->execute($bindParam);
        $bridgeRows = $bs->fetchAll();
    } catch (\Exception $e) { /* no bridge data */ }

    $grandBridgeN  = (int)  array_sum(array_column($bridgeRows, 'bridge_count'));
    $grandBridgeHa = (float)array_sum(array_column($bridgeRows, 'bridge_area_ha'));

    return [
        'type'              => 'infrastructure_summary',
        'question'          => $q,
        'scope'             => $scopeLabel,
        'scope_level'       => $scopeLevel,
        // Road
        'road_types'        => $roadTypes,
        'road_div_map'      => $roadDivMap,
        'road_grand_type'   => $roadGrandType,
        'grand_road_m'      => $grandRoadM,
        'road_empty'        => empty($roadRows),
        // Bridge
        'bridge_rows'       => $bridgeRows,
        'grand_bridge_ha'   => $grandBridgeHa,
        'grand_bridge_n'    => $grandBridgeN,
        'bridge_empty'      => ($grandBridgeN === 0 && $grandBridgeHa === 0.0),
    ];
}

function agro_seed_varieties(PDO $db, string $place, string $q): array
{
    // Resolve scope: company → BU → division cascade
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    $coRow = agro_find_company($db, $place);
    if ($coRow) {
        $scopeLabel = $coRow['company_name'];
        $scopeWhere = 'AND bu.company_id = ?';
        $scopeParam = (int)$coRow['company_id'];
        $scopeLevel = 'company';
    }

    if (!$scopeParam) {
        $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
        $bu->execute(['%' . $place . '%']);
        $buRow = $bu->fetch();
        if ($buRow) {
            $scopeLabel = $buRow['unit_name'];
            $scopeWhere = 'AND d.business_unit_id = ?';
            $scopeParam = (int)$buRow['business_unit_id'];
            $scopeLevel = 'bu';
        }
    }

    if (!$scopeParam) {
        $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
        $div->execute(['%' . $place . '%']);
        $divRow = $div->fetch();
        if ($divRow) {
            $scopeLabel = $divRow['division_name'];
            $scopeWhere = 'AND py.division_id = ?';
            $scopeParam = (int)$divRow['division_id'];
            $scopeLevel = 'division';
        }
    }

    if (!$scopeParam) {
        return agro_did_you_mean($db, 'business_unit', $place, $q);
    }

    try {
        $rows = $db->prepare(
            "SELECT pv.variety_id,
                    pv.variety_code,
                    pv.variety_name,
                    pv.category,
                    pv.clone_name,
                    pv.origin,
                    pv.avg_yield,
                    pv.maturity_age,
                    pv.productive_lifespan,
                    COUNT(DISTINCT b.block_id)              AS block_count,
                    COALESCE(SUM(bpv.plant_count), 0)       AS total_plants,
                    COALESCE(SUM(b.area), 0)                AS total_ha
             FROM plant_varieties pv
             JOIN block_plant_varieties bpv ON bpv.variety_id = pv.variety_id
             JOIN blocks b       ON b.block_id            = bpv.block_id
             JOIN planting_years py ON py.planting_year_id = b.planting_year_id
             JOIN divisions d    ON d.division_id          = py.division_id
             JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE 1=1 $scopeWhere
             GROUP BY pv.variety_id, pv.variety_code, pv.variety_name, pv.category,
                      pv.clone_name, pv.origin, pv.avg_yield, pv.maturity_age, pv.productive_lifespan
             ORDER BY total_plants DESC, pv.variety_name ASC"
        );
        $rows->execute([$scopeParam]);
        $varieties = $rows->fetchAll();
    } catch (\Exception $e) {
        return ['type' => 'unknown', 'message' => 'Data bibit/varietas belum tersedia.'];
    }

    $grandBlocks = array_sum(array_column($varieties, 'block_count'));
    $grandPlants = array_sum(array_column($varieties, 'total_plants'));
    $grandHa     = array_sum(array_column($varieties, 'total_ha'));

    return [
        'type'          => 'seed_varieties',
        'question'      => $q,
        'scope'         => $scopeLabel,
        'scope_level'   => $scopeLevel,
        'varieties'     => $varieties,
        'count'         => count($varieties),
        'grand_blocks'  => (int)$grandBlocks,
        'grand_plants'  => (int)$grandPlants,
        'grand_ha'      => (float)$grandHa,
        'empty'         => empty($varieties),
    ];
}

function agro_chemicals_used(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope: company (with initials fallback) → BU → division cascade
    // Empty $place = no scope filter → return all records ("Semua Kebun")
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';
    $dateWhere  = '';
    $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = ' AND pcr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = ' AND pcr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    if ($place !== '') {
        // Company — uses agro_find_company() which supports initials like "APN"
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }

        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }

        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }

        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    try {
        $rows = $db->prepare(
            "SELECT pcr.pesticide_name,
                    pcr.pesticide_type,
                    COUNT(*)                              AS application_count,
                    COALESCE(SUM(pcr.quantity_used), 0)  AS total_qty,
                    MIN(pcr.unit)                         AS unit,
                    COALESCE(SUM(pcr.area_covered), 0)   AS total_area_ha,
                    COALESCE(SUM(pcr.cost), 0)            AS total_cost,
                    MIN(pcr.application_date)             AS first_date,
                    MAX(pcr.application_date)             AS last_date
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id              = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id     = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id           = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id     = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY pcr.pesticide_name, pcr.pesticide_type
             ORDER BY total_qty DESC, pcr.pesticide_name ASC"
        );
        $execParams = $scopeParam !== null ? [$scopeParam] : [];
        $rows->execute(array_merge($execParams, $dateParams));
        $chemicals = $rows->fetchAll();
    } catch (\Exception $e) {
        return ['type' => 'unknown', 'message' => 'Data bahan kimia belum tersedia.'];
    }

    $grandQty    = array_sum(array_column($chemicals, 'total_qty'));
    $grandArea   = array_sum(array_column($chemicals, 'total_area_ha'));
    $grandCost   = array_sum(array_column($chemicals, 'total_cost'));
    $grandApps   = array_sum(array_column($chemicals, 'application_count'));

    return [
        'type'        => 'chemicals_used',
        'question'    => $q,
        'date_label'  => $dateLabel,
        'scope'       => $scopeLabel,
        'scope_level' => $scopeLevel,
        'chemicals'   => $chemicals,
        'count'       => count($chemicals),
        'grand_qty'   => (float)$grandQty,
        'grand_area'  => (float)$grandArea,
        'grand_cost'  => (float)$grandCost,
        'grand_apps'  => (int)$grandApps,
        'empty'       => empty($chemicals),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Chemicals (Pest Control) pivot by Block
// ─────────────────────────────────────────────────────────────────────────────

function agro_chemicals_by_block(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope — same cascade as agro_chemicals_used
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    // Date filter
    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = 'AND pcr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = 'AND pcr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    try {
        $execParams = $scopeParam !== null
            ? array_merge([$scopeParam], $dateParams)
            : $dateParams;

        $stmt = $db->prepare(
            "SELECT pcr.block_id,
                    b.block_code, b.block_name,
                    d.division_name,
                    bu.unit_name     AS estate_name,
                    pcr.pesticide_type,
                    SUM(pcr.quantity_used) AS total_qty,
                    MIN(pcr.unit)          AS unit,
                    SUM(pcr.area_covered)  AS total_ha,
                    SUM(pcr.cost)          AS total_cost,
                    COUNT(*)               AS app_count
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id           = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY pcr.block_id, b.block_code, b.block_name,
                      d.division_name, bu.unit_name, pcr.pesticide_type
             ORDER BY bu.unit_name, d.division_name, b.block_name, pcr.pesticide_type"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return ['type' => 'unknown', 'message' => 'Data bahan kimia per blok belum tersedia.'];
    }

    if (empty($rows)) {
        return [
            'type'        => 'chemicals_by_block',
            'question'    => $q,
            'scope'       => $scopeLabel,
            'scope_level' => $scopeLevel,
            'date_label'  => $dateLabel,
            'ctypes'      => [],
            'pivot'       => [],
            'meta'        => [],
            'sorted_keys' => [],
            'grand_totals'=> [],
            'grand_total' => 0,
            'block_count' => 0,
            'empty'       => true,
        ];
    }

    // Build pivot matrices — pivot on pesticide_type (Herbicide/Insecticide/Fungicide/…)
    $pivot  = [];   // [block_id] => [pesticide_type => qty]
    $meta   = [];   // [block_id] => [estate, division, block_name, block_code, total_ha, total_cost, app_count]
    $ctypes = [];   // unique pesticide types

    foreach ($rows as $r) {
        $bkey  = $r['block_id'];
        $ctype = $r['pesticide_type'] ?: 'Other';
        if (!isset($pivot[$bkey])) {
            $pivot[$bkey] = [];
            $meta[$bkey]  = [
                'estate'     => $r['estate_name'],
                'division'   => $r['division_name'],
                'block_name' => $r['block_name'],
                'block_code' => $r['block_code'],
                'total_ha'   => 0,
                'total_cost' => 0,
                'app_count'  => 0,
            ];
        }
        $pivot[$bkey][$ctype]       = ($pivot[$bkey][$ctype] ?? 0) + (float)$r['total_qty'];
        $meta[$bkey]['total_ha']   += (float)($r['total_ha']   ?? 0);
        $meta[$bkey]['total_cost'] += (float)($r['total_cost'] ?? 0);
        $meta[$bkey]['app_count']  += (int)  ($r['app_count']  ?? 0);
        $ctypes[$ctype] = true;
    }

    // Sort types in a logical order
    $typeOrder = ['Herbicide','Insecticide','Fungicide','Rodenticide','Other'];
    uksort($ctypes, function($a, $b) use ($typeOrder) {
        $ia = array_search($a, $typeOrder); $ib = array_search($b, $typeOrder);
        $ia = $ia === false ? 99 : $ia;     $ib = $ib === false ? 99 : $ib;
        return $ia <=> $ib ?: strcmp($a, $b);
    });
    $ctypes = array_keys($ctypes);

    // Sort blocks: estate > division > block_name
    $sortedKeys = array_keys($meta);
    usort($sortedKeys, function($a, $b) use ($meta) {
        $c = strcmp($meta[$a]['estate'],   $meta[$b]['estate']);   if ($c) return $c;
        $c = strcmp($meta[$a]['division'], $meta[$b]['division']); if ($c) return $c;
        return strcmp($meta[$a]['block_name'], $meta[$b]['block_name']);
    });

    // Grand totals per pesticide type
    $grandTotals = array_fill_keys($ctypes, 0.0);
    foreach ($pivot as $bdata) {
        foreach ($ctypes as $ct) {
            $grandTotals[$ct] += ($bdata[$ct] ?? 0);
        }
    }
    $grandTotal = array_sum($grandTotals);

    // Paraquat check across all records
    $hasParaquat = false;
    foreach ($rows as $r) {
        $nm = mb_strtolower((string)($r['pesticide_type'] ?? ''));
        if (str_contains($nm, 'paraquat') || str_contains($nm, 'gramoxone')) {
            $hasParaquat = true; break;
        }
    }

    return [
        'type'         => 'chemicals_by_block',
        'question'     => $q,
        'scope'        => $scopeLabel,
        'scope_level'  => $scopeLevel,
        'date_label'   => $dateLabel,
        'ctypes'       => $ctypes,
        'pivot'        => $pivot,
        'meta'         => $meta,
        'sorted_keys'  => $sortedKeys,
        'grand_totals' => $grandTotals,
        'grand_total'  => $grandTotal,
        'block_count'  => count($sortedKeys),
        'has_paraquat' => $hasParaquat,
        'empty'        => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Pest & Disease attack records pivot by Block
// ─────────────────────────────────────────────────────────────────────────────

function agro_pest_by_block(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope: company → BU → division (same cascade as siblings)
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    // Date filter
    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = 'AND pcr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = 'AND pcr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    try {
        $execParams = $scopeParam !== null
            ? array_merge([$scopeParam], $dateParams)
            : $dateParams;

        // Group by block × pest_type — pivot columns = pest types
        $stmt = $db->prepare(
            "SELECT pcr.block_id,
                    b.block_code, b.block_name,
                    d.division_name,
                    bu.unit_name                              AS estate_name,
                    pcr.pest_type,
                    COUNT(*)                                  AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)        AS total_ha,
                    COALESCE(SUM(pcr.cost), 0)                AS total_cost,
                    SUM(CASE WHEN pcr.severity IN ('High','Critical') THEN 1 ELSE 0 END) AS high_sev_count,
                    MAX(pcr.application_date)                 AS last_date
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id           = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY pcr.block_id, b.block_code, b.block_name,
                      d.division_name, bu.unit_name, pcr.pest_type
             ORDER BY bu.unit_name, d.division_name, b.block_name, pcr.pest_type"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return [
            'type'       => 'pest_by_block',
            'question'   => $q,
            'scope'      => $scopeLabel,
            'date_label' => $dateLabel,
            'ptypes'     => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
            'grand_totals' => [], 'grand_total' => 0, 'block_count' => 0,
            'empty'      => true,
            'db_error'   => $e->getMessage(),
        ];
    }

    if (empty($rows)) {
        return [
            'type'        => 'pest_by_block',
            'question'    => $q,
            'scope'       => $scopeLabel,
            'scope_level' => $scopeLevel,
            'date_label'  => $dateLabel,
            'ptypes'      => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
            'grand_totals'=> [], 'grand_total' => 0, 'block_count' => 0,
            'empty'       => true,
        ];
    }

    // Build pivot: rows = blocks, columns = pest_type (records count)
    $pivot  = [];   // [block_id] => [pest_type => record_count]
    $meta   = [];   // [block_id] => [estate, division, block_name, block_code, total_ha, total_cost, high_sev, last_date]
    $ptypes = [];

    foreach ($rows as $r) {
        $bkey  = $r['block_id'];
        $ptype = $r['pest_type'] ?: 'Other';
        if (!isset($pivot[$bkey])) {
            $pivot[$bkey] = [];
            $meta[$bkey]  = [
                'estate'      => $r['estate_name'],
                'division'    => $r['division_name'],
                'block_name'  => $r['block_name'],
                'block_code'  => $r['block_code'],
                'total_ha'    => 0,
                'total_cost'  => 0,
                'high_sev'    => 0,
                'last_date'   => '',
            ];
        }
        $pivot[$bkey][$ptype]        = ($pivot[$bkey][$ptype] ?? 0) + (int)$r['record_count'];
        $meta[$bkey]['total_ha']    += (float)($r['total_ha']      ?? 0);
        $meta[$bkey]['total_cost']  += (float)($r['total_cost']    ?? 0);
        $meta[$bkey]['high_sev']    += (int)  ($r['high_sev_count']?? 0);
        if ($r['last_date'] > $meta[$bkey]['last_date']) {
            $meta[$bkey]['last_date'] = $r['last_date'];
        }
        $ptypes[$ptype] = true;
    }

    // Sort pest types in logical order
    $typeOrder = ['Insect','Insecticide','Disease','Fungal','Weed','Rat','Vertebrate','Other'];
    uksort($ptypes, function($a, $b) use ($typeOrder) {
        $ia = array_search($a, $typeOrder); $ib = array_search($b, $typeOrder);
        $ia = $ia === false ? 99 : $ia;     $ib = $ib === false ? 99 : $ib;
        return $ia <=> $ib ?: strcmp($a, $b);
    });
    $ptypes = array_keys($ptypes);

    // Sort blocks: estate > division > block_name
    $sortedKeys = array_keys($meta);
    usort($sortedKeys, function($a, $b) use ($meta) {
        $c = strcmp($meta[$a]['estate'],   $meta[$b]['estate']);   if ($c) return $c;
        $c = strcmp($meta[$a]['division'], $meta[$b]['division']); if ($c) return $c;
        return strcmp($meta[$a]['block_name'], $meta[$b]['block_name']);
    });

    // Grand totals per pest type (record counts)
    $grandTotals = array_fill_keys($ptypes, 0);
    foreach ($pivot as $bdata) {
        foreach ($ptypes as $pt) { $grandTotals[$pt] += ($bdata[$pt] ?? 0); }
    }
    $grandTotal      = array_sum($grandTotals);
    $grandHighSev    = array_sum(array_column($meta, 'high_sev'));

    return [
        'type'          => 'pest_by_block',
        'question'      => $q,
        'scope'         => $scopeLabel,
        'scope_level'   => $scopeLevel,
        'date_label'    => $dateLabel,
        'ptypes'        => $ptypes,
        'pivot'         => $pivot,
        'meta'          => $meta,
        'sorted_keys'   => $sortedKeys,
        'grand_totals'  => $grandTotals,
        'grand_total'   => $grandTotal,
        'grand_high_sev'=> $grandHighSev,
        'block_count'   => count($sortedKeys),
        'empty'         => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Pest & Disease attack records pivot by Planting Year
// ─────────────────────────────────────────────────────────────────────────────

function agro_pest_by_planting_year(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope: company → BU → division (same cascade as siblings)
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    // Date filter
    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = 'AND pcr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = 'AND pcr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    try {
        $execParams = $scopeParam !== null
            ? array_merge([$scopeParam], $dateParams)
            : $dateParams;

        // Group by planting year × pest_type — pivot columns = pest types (record counts)
        $stmt = $db->prepare(
            "SELECT py.year                                   AS planting_year,
                    pcr.pest_type,
                    COUNT(*)                                  AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)        AS total_ha,
                    COALESCE(SUM(pcr.cost), 0)                AS total_cost,
                    SUM(CASE WHEN pcr.severity IN ('High','Critical') THEN 1 ELSE 0 END) AS high_sev_count,
                    COUNT(DISTINCT pcr.block_id)              AS block_count,
                    MAX(pcr.application_date)                 AS last_date
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id           = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY py.year, pcr.pest_type
             ORDER BY py.year, pcr.pest_type"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return [
            'type'       => 'pest_by_planting_year',
            'question'   => $q,
            'scope'      => $scopeLabel,
            'date_label' => $dateLabel,
            'ptypes'     => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
            'grand_totals' => [], 'grand_total' => 0, 'year_count' => 0,
            'empty'      => true,
            'db_error'   => $e->getMessage(),
        ];
    }

    if (empty($rows)) {
        return [
            'type'        => 'pest_by_planting_year',
            'question'    => $q,
            'scope'       => $scopeLabel,
            'scope_level' => $scopeLevel,
            'date_label'  => $dateLabel,
            'ptypes'      => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
            'grand_totals'=> [], 'grand_total' => 0, 'year_count' => 0,
            'empty'       => true,
        ];
    }

    // Build pivot: rows = planting years, columns = pest_type (record counts)
    $pivot  = [];
    $meta   = [];
    $ptypes = [];

    foreach ($rows as $r) {
        $yr    = (int)$r['planting_year'];
        $ptype = $r['pest_type'] ?: 'Other';
        if (!isset($pivot[$yr])) {
            $pivot[$yr] = [];
            $meta[$yr]  = [
                'total_ha'    => 0,
                'total_cost'  => 0,
                'high_sev'    => 0,
                'block_count' => 0,
                'last_date'   => '',
            ];
        }
        $pivot[$yr][$ptype]        = ($pivot[$yr][$ptype] ?? 0) + (int)$r['record_count'];
        $meta[$yr]['total_ha']    += (float)($r['total_ha']       ?? 0);
        $meta[$yr]['total_cost']  += (float)($r['total_cost']     ?? 0);
        $meta[$yr]['high_sev']    += (int)  ($r['high_sev_count'] ?? 0);
        $meta[$yr]['block_count'] += (int)  ($r['block_count']    ?? 0);
        if ($r['last_date'] > $meta[$yr]['last_date']) {
            $meta[$yr]['last_date'] = $r['last_date'];
        }
        $ptypes[$ptype] = true;
    }

    // Sort pest types in logical order
    $typeOrder = ['Insect','Insecticide','Disease','Fungal','Weed','Rat','Vertebrate','Other'];
    uksort($ptypes, function($a, $b) use ($typeOrder) {
        $ia = array_search($a, $typeOrder); $ib = array_search($b, $typeOrder);
        $ia = $ia === false ? 99 : $ia;     $ib = $ib === false ? 99 : $ib;
        return $ia <=> $ib ?: strcmp($a, $b);
    });
    $ptypes = array_keys($ptypes);

    // Sort rows ascending by planting year
    $sortedKeys = array_keys($meta);
    sort($sortedKeys);

    // Grand totals per pest type
    $grandTotals = array_fill_keys($ptypes, 0);
    foreach ($pivot as $ydata) {
        foreach ($ptypes as $pt) { $grandTotals[$pt] += ($ydata[$pt] ?? 0); }
    }
    $grandTotal   = array_sum($grandTotals);
    $grandHighSev = array_sum(array_column($meta, 'high_sev'));

    return [
        'type'          => 'pest_by_planting_year',
        'question'      => $q,
        'scope'         => $scopeLabel,
        'scope_level'   => $scopeLevel,
        'date_label'    => $dateLabel,
        'ptypes'        => $ptypes,
        'pivot'         => $pivot,
        'meta'          => $meta,
        'sorted_keys'   => $sortedKeys,
        'grand_totals'  => $grandTotals,
        'grand_total'   => $grandTotal,
        'grand_high_sev'=> $grandHighSev,
        'year_count'    => count($sortedKeys),
        'empty'         => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Pest & Disease attack records pivot by Division
// ─────────────────────────────────────────────────────────────────────────────

function agro_pest_by_division(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope: company → BU → division (same cascade as siblings)
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    // Date filter
    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = 'AND pcr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = 'AND pcr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    try {
        $execParams = $scopeParam !== null
            ? array_merge([$scopeParam], $dateParams)
            : $dateParams;

        // Group by division × pest_type — pivot columns = pest types (record counts)
        $stmt = $db->prepare(
            "SELECT d.division_id,
                    d.division_name,
                    bu.unit_name                              AS estate_name,
                    pcr.pest_type,
                    COUNT(*)                                  AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)        AS total_ha,
                    COALESCE(SUM(pcr.cost), 0)                AS total_cost,
                    SUM(CASE WHEN pcr.severity IN ('High','Critical') THEN 1 ELSE 0 END) AS high_sev_count,
                    COUNT(DISTINCT pcr.block_id)              AS block_count,
                    MAX(pcr.application_date)                 AS last_date
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id           = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY d.division_id, d.division_name, bu.unit_name, pcr.pest_type
             ORDER BY bu.unit_name, d.division_name, pcr.pest_type"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return [
            'type'       => 'pest_by_division',
            'question'   => $q,
            'scope'      => $scopeLabel,
            'date_label' => $dateLabel,
            'ptypes'     => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
            'grand_totals' => [], 'grand_total' => 0, 'div_count' => 0,
            'empty'      => true,
            'db_error'   => $e->getMessage(),
        ];
    }

    if (empty($rows)) {
        return [
            'type'        => 'pest_by_division',
            'question'    => $q,
            'scope'       => $scopeLabel,
            'scope_level' => $scopeLevel,
            'date_label'  => $dateLabel,
            'ptypes'      => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
            'grand_totals'=> [], 'grand_total' => 0, 'div_count' => 0,
            'empty'       => true,
        ];
    }

    // Build pivot: rows = divisions, columns = pest_type (record counts)
    $pivot  = [];
    $meta   = [];
    $ptypes = [];

    foreach ($rows as $r) {
        $dkey  = $r['division_id'];
        $ptype = $r['pest_type'] ?: 'Other';
        if (!isset($pivot[$dkey])) {
            $pivot[$dkey] = [];
            $meta[$dkey]  = [
                'estate'      => $r['estate_name'],
                'division'    => $r['division_name'],
                'total_ha'    => 0,
                'total_cost'  => 0,
                'high_sev'    => 0,
                'block_count' => 0,
                'last_date'   => '',
            ];
        }
        $pivot[$dkey][$ptype]        = ($pivot[$dkey][$ptype] ?? 0) + (int)$r['record_count'];
        $meta[$dkey]['total_ha']    += (float)($r['total_ha']       ?? 0);
        $meta[$dkey]['total_cost']  += (float)($r['total_cost']     ?? 0);
        $meta[$dkey]['high_sev']    += (int)  ($r['high_sev_count'] ?? 0);
        $meta[$dkey]['block_count'] += (int)  ($r['block_count']    ?? 0);
        if ($r['last_date'] > $meta[$dkey]['last_date']) {
            $meta[$dkey]['last_date'] = $r['last_date'];
        }
        $ptypes[$ptype] = true;
    }

    // Sort pest types in logical order
    $typeOrder = ['Insect','Insecticide','Disease','Fungal','Weed','Rat','Vertebrate','Other'];
    uksort($ptypes, function($a, $b) use ($typeOrder) {
        $ia = array_search($a, $typeOrder); $ib = array_search($b, $typeOrder);
        $ia = $ia === false ? 99 : $ia;     $ib = $ib === false ? 99 : $ib;
        return $ia <=> $ib ?: strcmp($a, $b);
    });
    $ptypes = array_keys($ptypes);

    // Sort rows: estate > division_name
    $sortedKeys = array_keys($meta);
    usort($sortedKeys, function($a, $b) use ($meta) {
        $c = strcmp($meta[$a]['estate'],   $meta[$b]['estate']);   if ($c) return $c;
        return strcmp($meta[$a]['division'], $meta[$b]['division']);
    });

    // Grand totals per pest type
    $grandTotals = array_fill_keys($ptypes, 0);
    foreach ($pivot as $ddata) {
        foreach ($ptypes as $pt) { $grandTotals[$pt] += ($ddata[$pt] ?? 0); }
    }
    $grandTotal   = array_sum($grandTotals);
    $grandHighSev = array_sum(array_column($meta, 'high_sev'));

    return [
        'type'          => 'pest_by_division',
        'question'      => $q,
        'scope'         => $scopeLabel,
        'scope_level'   => $scopeLevel,
        'date_label'    => $dateLabel,
        'ptypes'        => $ptypes,
        'pivot'         => $pivot,
        'meta'          => $meta,
        'sorted_keys'   => $sortedKeys,
        'grand_totals'  => $grandTotals,
        'grand_total'   => $grandTotal,
        'grand_high_sev'=> $grandHighSev,
        'div_count'     => count($sortedKeys),
        'empty'         => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Chemicals (Pest Control) pivot by Division
// ─────────────────────────────────────────────────────────────────────────────

function agro_chemicals_by_division(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope — same cascade as agro_chemicals_used / agro_chemicals_by_block
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    // Date filter
    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = 'AND pcr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = 'AND pcr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    try {
        $execParams = $scopeParam !== null
            ? array_merge([$scopeParam], $dateParams)
            : $dateParams;

        $stmt = $db->prepare(
            "SELECT d.division_id,
                    d.division_name,
                    bu.unit_name      AS estate_name,
                    pcr.pesticide_type,
                    SUM(pcr.quantity_used) AS total_qty,
                    MIN(pcr.unit)          AS unit,
                    SUM(pcr.area_covered)  AS total_ha,
                    SUM(pcr.cost)          AS total_cost,
                    COUNT(*)               AS app_count,
                    COUNT(DISTINCT pcr.block_id) AS block_count
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id           = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY d.division_id, d.division_name, bu.unit_name, pcr.pesticide_type
             ORDER BY bu.unit_name, d.division_name, pcr.pesticide_type"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return ['type' => 'unknown', 'message' => 'Data bahan kimia per divisi belum tersedia.'];
    }

    if (empty($rows)) {
        return [
            'type'        => 'chemicals_by_division',
            'question'    => $q,
            'scope'       => $scopeLabel,
            'scope_level' => $scopeLevel,
            'date_label'  => $dateLabel,
            'ctypes'      => [],
            'pivot'       => [],
            'meta'        => [],
            'sorted_keys' => [],
            'grand_totals'=> [],
            'grand_total' => 0,
            'div_count'   => 0,
            'empty'       => true,
        ];
    }

    // Build pivot: rows = divisions, columns = pesticide_type
    $pivot  = [];   // [division_id] => [pesticide_type => qty]
    $meta   = [];   // [division_id] => [estate, division_name, total_ha, total_cost, app_count, block_count]
    $ctypes = [];

    foreach ($rows as $r) {
        $dkey  = $r['division_id'];
        $ctype = $r['pesticide_type'] ?: 'Other';
        if (!isset($pivot[$dkey])) {
            $pivot[$dkey] = [];
            $meta[$dkey]  = [
                'estate'      => $r['estate_name'],
                'division'    => $r['division_name'],
                'total_ha'    => 0,
                'total_cost'  => 0,
                'app_count'   => 0,
                'block_count' => 0,
            ];
        }
        $pivot[$dkey][$ctype]        = ($pivot[$dkey][$ctype] ?? 0) + (float)$r['total_qty'];
        $meta[$dkey]['total_ha']    += (float)($r['total_ha']    ?? 0);
        $meta[$dkey]['total_cost']  += (float)($r['total_cost']  ?? 0);
        $meta[$dkey]['app_count']   += (int)  ($r['app_count']   ?? 0);
        $meta[$dkey]['block_count'] += (int)  ($r['block_count'] ?? 0);
        $ctypes[$ctype] = true;
    }

    // Sort types in logical order
    $typeOrder = ['Herbicide','Insecticide','Fungicide','Rodenticide','Other'];
    uksort($ctypes, function($a, $b) use ($typeOrder) {
        $ia = array_search($a, $typeOrder); $ib = array_search($b, $typeOrder);
        $ia = $ia === false ? 99 : $ia;     $ib = $ib === false ? 99 : $ib;
        return $ia <=> $ib ?: strcmp($a, $b);
    });
    $ctypes = array_keys($ctypes);

    // Sort rows: estate > division_name
    $sortedKeys = array_keys($meta);
    usort($sortedKeys, function($a, $b) use ($meta) {
        $c = strcmp($meta[$a]['estate'],   $meta[$b]['estate']);   if ($c) return $c;
        return strcmp($meta[$a]['division'], $meta[$b]['division']);
    });

    // Grand totals per pesticide type
    $grandTotals = array_fill_keys($ctypes, 0.0);
    foreach ($pivot as $bdata) {
        foreach ($ctypes as $ct) { $grandTotals[$ct] += ($bdata[$ct] ?? 0); }
    }
    $grandTotal = array_sum($grandTotals);

    // Paraquat flag
    $hasParaquat = false;
    foreach ($rows as $r) {
        $nm = mb_strtolower((string)($r['pesticide_type'] ?? ''));
        if (str_contains($nm, 'paraquat') || str_contains($nm, 'gramoxone')) { $hasParaquat = true; break; }
    }

    return [
        'type'         => 'chemicals_by_division',
        'question'     => $q,
        'scope'        => $scopeLabel,
        'scope_level'  => $scopeLevel,
        'date_label'   => $dateLabel,
        'ctypes'       => $ctypes,
        'pivot'        => $pivot,
        'meta'         => $meta,
        'sorted_keys'  => $sortedKeys,
        'grand_totals' => $grandTotals,
        'grand_total'  => $grandTotal,
        'div_count'    => count($sortedKeys),
        'has_paraquat' => $hasParaquat,
        'empty'        => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Chemicals (Pest Control) pivot by Planting Year
// ─────────────────────────────────────────────────────────────────────────────

function agro_chemicals_by_planting_year(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope: company → BU → division cascade (same as other chemical pivots)
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    // Date filter
    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = 'AND pcr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = 'AND pcr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    try {
        $execParams = $scopeParam !== null
            ? array_merge([$scopeParam], $dateParams)
            : $dateParams;

        // Group by planting year × pesticide type
        $stmt = $db->prepare(
            "SELECT py.year                    AS planting_year,
                    pcr.pesticide_type,
                    SUM(pcr.quantity_used)     AS total_qty,
                    MIN(pcr.unit)              AS unit,
                    SUM(pcr.area_covered)      AS total_ha,
                    SUM(pcr.cost)              AS total_cost,
                    COUNT(*)                   AS app_count,
                    COUNT(DISTINCT pcr.block_id) AS block_count
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id           = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY py.year, pcr.pesticide_type
             ORDER BY py.year, pcr.pesticide_type"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return [
            'type'      => 'chemicals_by_planting_year',
            'question'  => $q,
            'scope'     => $scopeLabel,
            'ctypes'    => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
            'grand_totals' => [], 'grand_total' => 0, 'year_count' => 0,
            'date_label'=> $dateLabel,
            'empty'     => true,
            'db_error'  => $e->getMessage(),
        ];
    }

    if (empty($rows)) {
        return [
            'type'        => 'chemicals_by_planting_year',
            'question'    => $q,
            'scope'       => $scopeLabel,
            'scope_level' => $scopeLevel,
            'date_label'  => $dateLabel,
            'ctypes'      => [],
            'pivot'       => [],
            'meta'        => [],
            'sorted_keys' => [],
            'grand_totals'=> [],
            'grand_total' => 0,
            'year_count'  => 0,
            'empty'       => true,
        ];
    }

    // Build pivot: rows = planting years, columns = pesticide_type
    $pivot  = [];   // [year] => [pesticide_type => qty]
    $meta   = [];   // [year] => [total_ha, total_cost, app_count, block_count]
    $ctypes = [];

    foreach ($rows as $r) {
        $yr    = (int)$r['planting_year'];
        $ctype = $r['pesticide_type'] ?: 'Other';
        if (!isset($pivot[$yr])) {
            $pivot[$yr] = [];
            $meta[$yr]  = [
                'total_ha'    => 0,
                'total_cost'  => 0,
                'app_count'   => 0,
                'block_count' => 0,
            ];
        }
        $pivot[$yr][$ctype]        = ($pivot[$yr][$ctype] ?? 0) + (float)$r['total_qty'];
        $meta[$yr]['total_ha']    += (float)($r['total_ha']    ?? 0);
        $meta[$yr]['total_cost']  += (float)($r['total_cost']  ?? 0);
        $meta[$yr]['app_count']   += (int)  ($r['app_count']   ?? 0);
        $meta[$yr]['block_count'] += (int)  ($r['block_count'] ?? 0);
        $ctypes[$ctype] = true;
    }

    // Sort chemical types in logical order
    $typeOrder = ['Herbicide','Insecticide','Fungicide','Rodenticide','Other'];
    uksort($ctypes, function($a, $b) use ($typeOrder) {
        $ia = array_search($a, $typeOrder); $ib = array_search($b, $typeOrder);
        $ia = $ia === false ? 99 : $ia;     $ib = $ib === false ? 99 : $ib;
        return $ia <=> $ib ?: strcmp($a, $b);
    });
    $ctypes = array_keys($ctypes);

    // Sort rows ascending by planting year
    $sortedKeys = array_keys($meta);
    sort($sortedKeys);

    // Grand totals per pesticide type
    $grandTotals = array_fill_keys($ctypes, 0.0);
    foreach ($pivot as $ydata) {
        foreach ($ctypes as $ct) { $grandTotals[$ct] += ($ydata[$ct] ?? 0); }
    }
    $grandTotal = array_sum($grandTotals);

    // Paraquat flag
    $hasParaquat = false;
    foreach ($rows as $r) {
        $nm = mb_strtolower((string)($r['pesticide_type'] ?? ''));
        if (str_contains($nm, 'paraquat') || str_contains($nm, 'gramoxone')) { $hasParaquat = true; break; }
    }

    return [
        'type'         => 'chemicals_by_planting_year',
        'question'     => $q,
        'scope'        => $scopeLabel,
        'scope_level'  => $scopeLevel,
        'date_label'   => $dateLabel,
        'ctypes'       => $ctypes,
        'pivot'        => $pivot,
        'meta'         => $meta,
        'sorted_keys'  => $sortedKeys,
        'grand_totals' => $grandTotals,
        'grand_total'  => $grandTotal,
        'year_count'   => count($sortedKeys),
        'has_paraquat' => $hasParaquat,
        'empty'        => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Pest & Disease Analysis
// ─────────────────────────────────────────────────────────────────────────────

function agro_pest_analysis(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope: company → BU → division cascade (same pattern as chemicals)
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';
    $dateWhere  = '';
    $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = ' AND pcr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = ' AND pcr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    try {
        $baseParam  = $scopeParam !== null ? [$scopeParam] : [];
        $param      = array_merge($baseParam, $dateParams);

        // ── 1. By pest type + severity breakdown ─────────────────────────────
        $byTypeStmt = $db->prepare(
            "SELECT pcr.pest_type,
                    pcr.severity,
                    COUNT(*)                              AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)   AS total_area_ha,
                    COALESCE(SUM(pcr.cost), 0)            AS total_cost,
                    MIN(pcr.application_date)             AS first_date,
                    MAX(pcr.application_date)             AS last_date
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id          = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id       = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY pcr.pest_type, pcr.severity
             ORDER BY record_count DESC, pcr.pest_type ASC"
        );
        $byTypeStmt->execute($param);
        $byTypeSeverity = $byTypeStmt->fetchAll();

        // ── 2. Top pest names ─────────────────────────────────────────────────
        $pestNamesStmt = $db->prepare(
            "SELECT pcr.pest_name,
                    pcr.pest_type,
                    COUNT(*)                              AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)   AS total_area_ha,
                    MIN(pcr.severity)                     AS min_severity,
                    MAX(pcr.severity)                     AS max_severity
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id          = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id       = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE pcr.pest_name IS NOT NULL AND pcr.pest_name <> '' $scopeWhere $dateWhere
             GROUP BY pcr.pest_name, pcr.pest_type
             ORDER BY record_count DESC
             LIMIT 15"
        );
        $pestNamesStmt->execute($param);
        $topPests = $pestNamesStmt->fetchAll();

        // ── 3. Effectiveness summary ──────────────────────────────────────────
        $effStmt = $db->prepare(
            "SELECT pcr.effectiveness,
                    COUNT(*) AS record_count
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id          = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id       = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE pcr.effectiveness IS NOT NULL AND pcr.effectiveness <> '' $scopeWhere $dateWhere
             GROUP BY pcr.effectiveness
             ORDER BY record_count DESC"
        );
        $effStmt->execute($param);
        $effectiveness = $effStmt->fetchAll();

        // ── 4. By division breakdown ──────────────────────────────────────────
        $byDivStmt = $db->prepare(
            "SELECT d.division_name,
                    COUNT(*)                              AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)   AS total_area_ha,
                    COALESCE(SUM(pcr.cost), 0)            AS total_cost,
                    SUM(CASE WHEN pcr.severity = 'Critical' THEN 1 ELSE 0 END) AS critical_count,
                    SUM(CASE WHEN pcr.severity = 'High'     THEN 1 ELSE 0 END) AS high_count
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id          = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id       = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY d.division_id, d.division_name
             ORDER BY (SUM(CASE WHEN pcr.severity = 'Critical' THEN 1 ELSE 0 END)
                     + SUM(CASE WHEN pcr.severity = 'High'     THEN 1 ELSE 0 END)) DESC,
                      COUNT(*) DESC"
        );
        $byDivStmt->execute($param);
        $byDivision = $byDivStmt->fetchAll();

        // ── 5. Grand totals ───────────────────────────────────────────────────
        $totStmt = $db->prepare(
            "SELECT COUNT(*)                              AS total_records,
                    COALESCE(SUM(pcr.area_covered), 0)   AS total_area_ha,
                    COALESCE(SUM(pcr.cost), 0)            AS total_cost,
                    SUM(CASE WHEN pcr.severity = 'Critical' THEN 1 ELSE 0 END) AS critical_count,
                    SUM(CASE WHEN pcr.severity = 'High'     THEN 1 ELSE 0 END) AS high_count,
                    SUM(CASE WHEN pcr.severity = 'Medium'   THEN 1 ELSE 0 END) AS medium_count,
                    SUM(CASE WHEN pcr.severity = 'Low'      THEN 1 ELSE 0 END) AS low_count,
                    MIN(pcr.application_date)             AS first_date,
                    MAX(pcr.application_date)             AS last_date
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id          = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id       = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere"
        );
        $totStmt->execute($param);
        $tot = $totStmt->fetch();

        return [
            'type'            => 'pest_analysis',
            'date_label'      => $dateLabel,
            'question'        => $q,
            'scope'           => $scopeLabel,
            'scope_level'     => $scopeLevel,
            'total_records'   => (int)($tot['total_records']  ?? 0),
            'total_area_ha'   => (float)($tot['total_area_ha'] ?? 0),
            'total_cost'      => (float)($tot['total_cost']    ?? 0),
            'critical_count'  => (int)($tot['critical_count']  ?? 0),
            'high_count'      => (int)($tot['high_count']      ?? 0),
            'medium_count'    => (int)($tot['medium_count']    ?? 0),
            'low_count'       => (int)($tot['low_count']       ?? 0),
            'first_date'      => $tot['first_date']  ?? null,
            'last_date'       => $tot['last_date']   ?? null,
            'by_type_severity'=> $byTypeSeverity,
            'top_pests'       => $topPests,
            'effectiveness'   => $effectiveness,
            'by_division'     => $byDivision,
            'empty'           => (int)($tot['total_records'] ?? 0) === 0,
        ];
    } catch (\Exception $e) {
        // Surface the real DB error so it's visible in Q&A (not swallowed silently)
        $msg = $e->getMessage();
        // Strip long SQL noise — keep first sentence only
        $short = preg_replace('/\s+/', ' ', explode('(SQL', $msg)[0] ?? $msg);
        return [
            'type'    => 'pest_analysis',
            'question' => $q,
            'scope'    => $scopeLabel ?: 'Semua Kebun',
            'scope_level' => $scopeLevel ?: 'all',
            'total_records' => 0,
            'total_area_ha' => 0.0,
            'total_cost'    => 0.0,
            'critical_count'=> 0,
            'high_count'    => 0,
            'medium_count'  => 0,
            'low_count'     => 0,
            'first_date'    => null,
            'last_date'     => null,
            'by_type_severity' => [],
            'top_pests'        => [],
            'effectiveness'    => [],
            'by_division'      => [],
            'empty'   => true,
            'db_error' => trim($short),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Sustainability Analysis (Keberlanjutan — ISPO / RSPO / Carbon)
// ─────────────────────────────────────────────────────────────────────────────

function agro_sustainability_analysis(PDO $db, string $place, string $q): array
{
    // ── Scope resolution ───────────────────────────────────────────────────────
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';
    $buWhere    = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $buWhere    = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $buWhere    = 'AND bu.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND d.division_id = ?';
                $buWhere    = 'AND d.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        // Session scope
        $sessionCoId  = !empty($_SESSION['company_id'])       ? (int)$_SESSION['company_id']       : null;
        $sessionBuId  = !empty($_SESSION['business_unit_id']) ? (int)$_SESSION['business_unit_id'] : null;
        $sessionDivId = !empty($_SESSION['division_id'])      ? (int)$_SESSION['division_id']      : null;

        if ($sessionDivId) {
            $stmt = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_id = ? LIMIT 1");
            $stmt->execute([$sessionDivId]);
            $row = $stmt->fetch();
            if ($row) {
                $scopeLabel = $row['division_name'];
                $scopeWhere = 'AND d.division_id = ?';
                $buWhere    = 'AND d.division_id = ?';
                $scopeParam = $sessionDivId;
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam && $sessionBuId) {
            $stmt = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE business_unit_id = ? LIMIT 1");
            $stmt->execute([$sessionBuId]);
            $row = $stmt->fetch();
            if ($row) {
                $scopeLabel = $row['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $buWhere    = 'AND bu.business_unit_id = ?';
                $scopeParam = $sessionBuId;
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam && $sessionCoId) {
            $stmt = $db->prepare("SELECT company_id, company_name FROM companies WHERE company_id = ? LIMIT 1");
            $stmt->execute([$sessionCoId]);
            $row = $stmt->fetch();
            if ($row) {
                $buStmt = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE company_id = ? ORDER BY unit_name ASC");
                $buStmt->execute([$sessionCoId]);
                $buList = $buStmt->fetchAll();
                if (count($buList) === 1) {
                    $scopeLabel = $buList[0]['unit_name'];
                    $scopeWhere = 'AND d.business_unit_id = ?';
                    $buWhere    = 'AND bu.business_unit_id = ?';
                    $scopeParam = (int)$buList[0]['business_unit_id'];
                    $scopeLevel = 'bu';
                } else {
                    $scopeLabel = $row['company_name'];
                    $scopeWhere = 'AND bu.company_id = ?';
                    $buWhere    = 'AND bu.company_id = ?';
                    $scopeParam = $sessionCoId;
                    $scopeLevel = 'company';
                }
            }
        }
        if (!$scopeParam) {
            $scopeLabel = 'Semua Kebun';
            $scopeLevel = 'all';
        }
    }

    $param = $scopeParam !== null ? [$scopeParam] : [];

    // ── 1. Total area summary (planted vs non-planted) from divisions ─────────
    $areaStmt = $db->prepare(
        "SELECT
            COALESCE(SUM(d.total_area_ha), 0)        AS total_area_ha,
            COALESCE(SUM(d.total_planted_area_ha), 0) AS planted_ha,
            COALESCE(SUM(d.total_area_ha) - SUM(d.total_planted_area_ha), 0) AS non_planted_ha,
            COALESCE(SUM(d.tm_area), 0)              AS tm_ha,
            COALESCE(SUM(d.tbm_area), 0)             AS tbm_ha,
            COALESCE(SUM(d.none_area), 0)            AS undefined_ha,
            COUNT(d.division_id)                     AS div_count
         FROM divisions d
         INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         WHERE d.status = 'Active' $buWhere"
    );
    $areaStmt->execute($param);
    $area = $areaStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── 2. Non-planted component breakdown from block_area_component_values ───
    // Categories: CONSERVATION, WATER, SWAMP, HTKH, and other non-planted
    $compStmt = $db->prepare(
        "SELECT bacc.category_code,
                bacc.category_name,
                COALESCE(SUM(bacv.value), 0) AS area_ha,
                COUNT(DISTINCT bacv.block_id) AS block_count
         FROM block_area_component_categories bacc
         JOIN block_area_component_values bacv ON bacv.category_id = bacc.id
         JOIN blocks b    ON b.block_id    = bacv.block_id
         JOIN divisions d ON d.division_id = b.division_id
         INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         WHERE bacc.category_type = 'non_planted'
           AND bacc.category_code NOT IN ('COLL_ROAD','ACCESS_ROAD','MAIN_ROAD','ROADS','BRIDGES','BUILDINGS')
           $scopeWhere
         GROUP BY bacc.id, bacc.category_code, bacc.category_name
         HAVING area_ha > 0
         ORDER BY area_ha DESC"
    );
    $compStmt->execute($param);
    $compRows = $compStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 3. Carbon stock from blocks ──────────────────────────────────────────
    $carbonStmt = $db->prepare(
        "SELECT
            COALESCE(SUM(b.carbon_stock_ton), 0)  AS total_carbon_ton,
            COUNT(CASE WHEN b.carbon_stock_ton > 0 THEN 1 END) AS blocks_with_carbon,
            COUNT(b.block_id) AS total_blocks
         FROM blocks b
         INNER JOIN planting_years py ON py.planting_year_id = b.planting_year_id
         INNER JOIN divisions d       ON d.division_id       = py.division_id
         INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         WHERE b.operation_type = 'Plantation' $scopeWhere"
    );
    $carbonStmt->execute($param);
    $carbon = $carbonStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Derived: Conservation ratio (CONSERVATION + WATER + SWAMP / total_ha) ─
    $totalAreaHa    = (float)($area['total_area_ha']    ?? 0);
    $plantedHa      = (float)($area['planted_ha']       ?? 0);
    $nonPlantedHa   = (float)($area['non_planted_ha']   ?? 0);
    $nonPlantedPct  = $totalAreaHa > 0 ? round($nonPlantedHa / $totalAreaHa * 100, 2) : 0.0;

    // Conservation area = CONSERVATION + WATER + SWAMP + HTKH from components
    $conservCodes    = ['CONSERVATION', 'WATER', 'SWAMP', 'HTKH', 'HTKH'];
    $conservHa       = 0.0;
    $waterHa         = 0.0;
    $swampHa         = 0.0;
    $hcvHa           = 0.0;
    $compByCode      = [];
    foreach ($compRows as $r) {
        $code = strtoupper((string)$r['category_code']);
        $ha   = (float)$r['area_ha'];
        $compByCode[$code] = $r;
        if ($code === 'CONSERVATION' || $code === 'HTKH') $hcvHa     += $ha;
        if ($code === 'WATER')                             $waterHa   += $ha;
        if ($code === 'SWAMP')                             $swampHa   += $ha;
        if (in_array($code, $conservCodes, true))         $conservHa += $ha;
    }
    // Also count the non-planted total (roads excluded) as conservation proxy
    $conservRatioPct = $totalAreaHa > 0 ? round($conservHa / $totalAreaHa * 100, 2) : null;

    // Buffer zone: we can't compute actual buffer width from current data;
    // presence of WATER records with area ≥ 0.5 ha/block suggests buffers exist
    // We'll set a proxy: null = no data, otherwise estimate from water area density
    $hasWaterData  = $waterHa > 0 || $swampHa > 0;
    $bufferProxy   = null; // DB does not store buffer width directly

    $totalCarbonTon = (float)($carbon['total_carbon_ton']   ?? 0);
    $blocksWithCarbon = (int)($carbon['blocks_with_carbon'] ?? 0);
    $totalBlocks    = (int)  ($carbon['total_blocks']       ?? 0);

    $isEmpty = ($totalAreaHa === 0.0 && count($compRows) === 0 && $totalCarbonTon === 0.0);

    return [
        'type'               => 'sustainability_analysis',
        'question'           => $q,
        'scope'              => $scopeLabel,
        'scope_level'        => $scopeLevel,
        'empty'              => $isEmpty,

        // Area
        'total_area_ha'      => $totalAreaHa,
        'planted_ha'         => $plantedHa,
        'non_planted_ha'     => $nonPlantedHa,
        'non_planted_pct'    => $nonPlantedPct,

        // Conservation
        'conserv_ha'         => $conservHa,
        'conserv_ratio_pct'  => $conservRatioPct,
        'water_ha'           => $waterHa,
        'swamp_ha'           => $swampHa,
        'hcv_ha'             => $hcvHa,
        'has_water_data'     => $hasWaterData,
        'buffer_proxy'       => $bufferProxy,  // null = no direct data

        // Carbon
        'total_carbon_ton'   => $totalCarbonTon,
        'blocks_with_carbon' => $blocksWithCarbon,
        'total_blocks'       => $totalBlocks,

        // Component detail
        'components'         => $compRows,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Plantation Analysis
// ─────────────────────────────────────────────────────────────────────────────

function agro_plantation_analysis(PDO $db, string $place, string $q): array
{
    // ── Scope resolution: company → BU → division ────────────────────────────
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';
    $buWhere    = ''; $buParam    = null;

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $buWhere    = 'AND bu.company_id = ?';
            $scopeParam = $buParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $buWhere    = 'AND bu.business_unit_id = ?';
                $scopeParam = $buParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND d.division_id = ?';
                $buWhere    = 'AND d.division_id = ?';
                $scopeParam = $buParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        // ── No place given: filter by the logged-in user's company / BU / division ──
        $sessionCoId  = !empty($_SESSION['company_id'])       ? (int)$_SESSION['company_id']       : null;
        $sessionBuId  = !empty($_SESSION['business_unit_id']) ? (int)$_SESSION['business_unit_id'] : null;
        $sessionDivId = !empty($_SESSION['division_id'])      ? (int)$_SESSION['division_id']      : null;

        if ($sessionDivId) {
            $stmt = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_id = ? LIMIT 1");
            $stmt->execute([$sessionDivId]);
            $row = $stmt->fetch();
            if ($row) {
                $scopeLabel = $row['division_name'];
                $scopeWhere = 'AND d.division_id = ?';
                $buWhere    = 'AND d.division_id = ?';
                $scopeParam = $buParam = $sessionDivId;
                $scopeLevel = 'division';
            }
        }

        if (!$scopeParam && $sessionBuId) {
            $stmt = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE business_unit_id = ? LIMIT 1");
            $stmt->execute([$sessionBuId]);
            $row = $stmt->fetch();
            if ($row) {
                $scopeLabel = $row['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $buWhere    = 'AND bu.business_unit_id = ?';
                $scopeParam = $buParam = $sessionBuId;
                $scopeLevel = 'bu';
            }
        }

        if (!$scopeParam && $sessionCoId) {
            $stmt = $db->prepare("SELECT company_id, company_name FROM companies WHERE company_id = ? LIMIT 1");
            $stmt->execute([$sessionCoId]);
            $row = $stmt->fetch();
            if ($row) {
                // Narrow to single BU if only one exists under this company
                $buStmt = $db->prepare(
                    "SELECT business_unit_id, unit_name FROM business_units
                      WHERE company_id = ? ORDER BY unit_name ASC"
                );
                $buStmt->execute([$sessionCoId]);
                $buList = $buStmt->fetchAll();
                if (count($buList) === 1) {
                    $scopeLabel = $buList[0]['unit_name'];
                    $scopeWhere = 'AND d.business_unit_id = ?';
                    $buWhere    = 'AND bu.business_unit_id = ?';
                    $scopeParam = $buParam = (int)$buList[0]['business_unit_id'];
                    $scopeLevel = 'bu';
                } else {
                    $scopeLabel = $row['company_name'];
                    $scopeWhere = 'AND bu.company_id = ?';
                    $buWhere    = 'AND bu.company_id = ?';
                    $scopeParam = $buParam = $sessionCoId;
                    $scopeLevel = 'company';
                }
            }
        }

        // No session scope → show all data
        if (!$scopeParam) {
            $scopeLabel = 'Semua Kebun';
            $scopeLevel = 'all';
        }
    }

    $param = $scopeParam !== null ? [$scopeParam] : [];

    // ── 1. Block-level population summary ────────────────────────────────────
    // Aggregate from blocks table: area, plants, SPH, normal/abnormal/dead ratio
    $blkStmt = $db->prepare(
        "SELECT
            COUNT(b.block_id)                           AS total_blocks,
            COALESCE(SUM(b.planted_area), SUM(b.area)) AS total_planted_ha,
            COALESCE(SUM(b.area), 0)                    AS total_area_ha,
            COALESCE(SUM(b.total_plants), 0)            AS total_plants,
            COALESCE(SUM(b.normal_plants), 0)           AS normal_plants,
            COALESCE(SUM(b.abnormal_plants), 0)         AS abnormal_plants,
            COALESCE(SUM(b.dead_plants), 0)             AS dead_plants,
            AVG(NULLIF(b.plant_density, 0))             AS avg_sph,
            AVG(NULLIF(b.planted_area, 0))              AS avg_block_ha,
            COUNT(CASE WHEN b.harvest_status = 'Harvesting' OR b.status IN ('TM','TM1','TM2','TM3') THEN 1 END) AS tm_blocks,
            COALESCE(SUM(CASE WHEN b.harvest_status = 'Harvesting' OR b.status IN ('TM','TM1','TM2','TM3') THEN COALESCE(b.planted_area, b.area) ELSE 0 END), 0) AS tm_area_ha
         FROM blocks b
         INNER JOIN planting_years py ON py.planting_year_id = b.planting_year_id
         INNER JOIN divisions d       ON d.division_id       = py.division_id
         INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         WHERE b.operation_type = 'Plantation' $scopeWhere"
    );
    $blkStmt->execute($param);
    $blk = $blkStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── 2. Division-level summary ─────────────────────────────────────────────
    // Derive TM ratio from divisions.tm_area / total_planted_area_ha
    $divStmt = $db->prepare(
        "SELECT
            COUNT(d.division_id)                          AS total_divisions,
            COALESCE(SUM(d.total_planted_area_ha), 0)     AS total_planted_ha,
            COALESCE(SUM(d.total_area_ha), 0)             AS total_area_ha,
            COALESCE(SUM(d.tm_area), 0)                   AS tm_area_ha,
            COALESCE(SUM(d.tbm_area), 0)                  AS tbm_area_ha,
            COALESCE(SUM(d.total_plants), 0)              AS total_plants,
            COALESCE(SUM(d.tm_plants), 0)                 AS tm_plants,
            COALESCE(SUM(d.tm_blocks), 0)                 AS tm_blocks,
            COALESCE(SUM(d.total_blocks), 0)              AS total_blocks,
            AVG(NULLIF(d.total_planted_area_ha, 0))       AS avg_div_ha
         FROM divisions d
         INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         WHERE d.status = 'Active' $buWhere"
    );
    $divStmt->execute($param);
    $divSum = $divStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── 3. Per-division detail (top 10 by area) ───────────────────────────────
    $divDetail = $db->prepare(
        "SELECT d.division_name,
                d.total_planted_area_ha,
                d.total_area_ha,
                d.tm_area, d.tm_blocks, d.total_blocks,
                d.tm_plants, d.total_plants,
                d.tbm_area
         FROM divisions d
         INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         WHERE d.status = 'Active' $buWhere
         ORDER BY d.total_planted_area_ha DESC
         LIMIT 10"
    );
    $divDetail->execute($param);
    $divRows = $divDetail->fetchAll(PDO::FETCH_ASSOC);

    // ── 4. Harvest summary: ABW, yield/ha, total bunches ─────────────────────
    $harvStmt = $db->prepare(
        "SELECT
            COUNT(hr.harvest_id)                    AS harvest_records,
            COALESCE(SUM(hr.actual_quantity_kg), 0) AS total_kg,
            COALESCE(SUM(hr.actual_bunches), 0)     AS total_bunches,
            AVG(NULLIF(hr.average_bunch_weight, 0)) AS avg_abw,
            MIN(hr.harvest_date)                    AS first_date,
            MAX(hr.harvest_date)                    AS last_date
         FROM harvest_realizations hr
         INNER JOIN blocks b          ON b.block_id          = hr.block_id
         INNER JOIN planting_years py ON py.planting_year_id = b.planting_year_id
         INNER JOIN divisions d       ON d.division_id       = py.division_id
         INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
         WHERE b.operation_type = 'Plantation' $scopeWhere"
    );
    $harvStmt->execute($param);
    $harv = $harvStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── 5. Business-unit list (for BU-level counts / land use ratio) ──────────
    $buStmt = $db->prepare(
        "SELECT
            COUNT(bu.business_unit_id)           AS total_bus,
            COALESCE(SUM(bu.total_area_ha), 0)   AS total_area_ha,
            COALESCE(SUM(bu.total_planted_area_ha), 0) AS total_planted_ha,
            COALESCE(SUM(bu.total_plants), 0)    AS total_plants
         FROM business_units bu
         WHERE bu.unit_type = 'Estate' $buWhere"
    );
    $buStmt->execute($param);
    $buSum = $buStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Derived metrics ───────────────────────────────────────────────────────
    $totalPlants   = (int)  ($blk['total_plants']   ?? $divSum['total_plants']   ?? 0);
    $normalPlants  = (int)  ($blk['normal_plants']  ?? 0);
    $abnPlants     = (int)  ($blk['abnormal_plants']?? 0);
    $deadPlants    = (int)  ($blk['dead_plants']    ?? 0);
    $totalPlantedHa= (float)($divSum['total_planted_ha'] ?? $blk['total_planted_ha'] ?? 0);
    $totalAreaHa   = (float)($divSum['total_area_ha']    ?? $blk['total_area_ha']    ?? 0);
    $tmAreaHa      = (float)($divSum['tm_area_ha']  ?? $blk['tm_area_ha']  ?? 0);
    $tbmAreaHa     = (float)($divSum['tbm_area_ha'] ?? 0);
    $totalBlocks   = (int)  ($divSum['total_blocks']?? $blk['total_blocks']?? 0);
    $tmBlocks      = (int)  ($divSum['tm_blocks']   ?? $blk['tm_blocks']   ?? 0);
    $totalDivs     = (int)  ($divSum['total_divisions'] ?? 0);
    $avgSph        = (float)($blk['avg_sph']        ?? 0);
    $avgBlockHa    = (float)($blk['avg_block_ha']   ?? 0);
    $avgDivHa      = (float)($divSum['avg_div_ha']  ?? 0);

    // TM ratio — prefer division aggregate, fall back to block estimate
    $tmRatioPct    = ($totalPlantedHa > 0 && $tmAreaHa > 0)
        ? round($tmAreaHa / $totalPlantedHa * 100, 1) : 0.0;

    // Planted/total area ratio
    $plantedRatioPct = ($totalAreaHa > 0 && $totalPlantedHa > 0)
        ? round($totalPlantedHa / $totalAreaHa * 100, 1) : 0.0;

    // Population ratios (only if plants data present)
    $normalRatioPct = ($totalPlants > 0) ? round($normalPlants  / $totalPlants * 100, 1) : null;
    $abnRatioPct    = ($totalPlants > 0) ? round($abnPlants     / $totalPlants * 100, 1) : null;
    $deadRatioPct   = ($totalPlants > 0) ? round($deadPlants    / $totalPlants * 100, 1) : null;
    $sisipRatioPct  = ($totalPlants > 0) ? round(($abnPlants + $deadPlants) / $totalPlants * 100, 1) : null;

    // Harvest-derived metrics
    $totalKg       = (float)($harv['total_kg']      ?? 0);
    $totalBunches  = (int)  ($harv['total_bunches'] ?? 0);
    $avgAbw        = (float)($harv['avg_abw']       ?? 0);
    $harvRecords   = (int)  ($harv['harvest_records']?? 0);

    // Yield per ha TM — only if we have TM area and harvest tonnes
    $yieldPerHaTm  = ($tmAreaHa > 0 && $totalKg > 0)
        ? round($totalKg / 1000 / $tmAreaHa, 2) : null; // ton/ha (annualised below in render)

    $isEmpty = ($totalBlocks === 0 && $totalPlants === 0 && $harvRecords === 0);

    return [
        'type'              => 'plantation_analysis',
        'question'          => $q,
        'scope'             => $scopeLabel,
        'scope_level'       => $scopeLevel,
        'empty'             => $isEmpty,

        // Area & structure
        'total_area_ha'     => $totalAreaHa,
        'total_planted_ha'  => $totalPlantedHa,
        'planted_ratio_pct' => $plantedRatioPct,
        'tm_area_ha'        => $tmAreaHa,
        'tbm_area_ha'       => $tbmAreaHa,
        'tm_ratio_pct'      => $tmRatioPct,
        'total_blocks'      => $totalBlocks,
        'tm_blocks'         => $tmBlocks,
        'avg_block_ha'      => $avgBlockHa,
        'total_divisions'   => $totalDivs,
        'avg_div_ha'        => $avgDivHa,

        // Population
        'total_plants'      => $totalPlants,
        'normal_plants'     => $normalPlants,
        'abnormal_plants'   => $abnPlants,
        'dead_plants'       => $deadPlants,
        'avg_sph'           => $avgSph,
        'normal_ratio_pct'  => $normalRatioPct,
        'abnormal_ratio_pct'=> $abnRatioPct,
        'dead_ratio_pct'    => $deadRatioPct,
        'sisip_ratio_pct'   => $sisipRatioPct,

        // Harvest
        'harvest_records'   => $harvRecords,
        'total_kg'          => $totalKg,
        'total_bunches'     => $totalBunches,
        'avg_abw'           => $avgAbw,
        'yield_per_ha_tm'   => $yieldPerHaTm,
        'first_date'        => $harv['first_date'] ?? null,
        'last_date'         => $harv['last_date']  ?? null,

        // Division detail rows
        'divisions'         => $divRows,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Weed (Gulma) Analysis
// ─────────────────────────────────────────────────────────────────────────────

function agro_weed_analysis(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Scope resolution: company → BU → division
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';
    $dateWhere  = '';
    $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = ' AND pcr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = ' AND pcr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    try {
        $baseParam = $scopeParam !== null ? [$scopeParam] : [];
        $param     = array_merge($baseParam, $dateParams);

        // Base JOIN (same for all queries)
        $join = "FROM pest_control_records pcr
                 INNER JOIN blocks b          ON b.block_id          = pcr.block_id
                 INNER JOIN planting_years py ON py.planting_year_id = b.planting_year_id
                 INNER JOIN divisions d       ON d.division_id       = py.division_id
                 INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
                 WHERE pcr.pest_type = 'Weed' $scopeWhere $dateWhere";

        // ── 1. Top weed species ───────────────────────────────────────────────
        $topWeedsStmt = $db->prepare(
            "SELECT pcr.pest_name,
                    COUNT(*)                              AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)   AS total_area_ha,
                    MAX(pcr.severity)                     AS max_severity,
                    SUM(CASE WHEN pcr.severity IN ('High','Critical') THEN 1 ELSE 0 END) AS high_count
             $join
             AND pcr.pest_name IS NOT NULL AND pcr.pest_name <> ''
             GROUP BY pcr.pest_name
             ORDER BY total_area_ha DESC, record_count DESC
             LIMIT 10"
        );
        $topWeedsStmt->execute($param);
        $topWeeds = $topWeedsStmt->fetchAll();

        // ── 2. Control method breakdown ───────────────────────────────────────
        $methodStmt = $db->prepare(
            "SELECT pcr.application_method,
                    pcr.pesticide_type,
                    COUNT(*)                              AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)   AS total_area_ha,
                    COALESCE(SUM(pcr.cost), 0)            AS total_cost
             $join
             GROUP BY pcr.application_method, pcr.pesticide_type
             ORDER BY total_area_ha DESC"
        );
        $methodStmt->execute($param);
        $byMethod = $methodStmt->fetchAll();

        // ── 3. Effectiveness summary ──────────────────────────────────────────
        $effStmt = $db->prepare(
            "SELECT pcr.effectiveness,
                    COUNT(*) AS record_count
             $join
             AND pcr.effectiveness IS NOT NULL AND pcr.effectiveness <> ''
             GROUP BY pcr.effectiveness
             ORDER BY record_count DESC"
        );
        $effStmt->execute($param);
        $effectiveness = $effStmt->fetchAll();

        // ── 4. By division breakdown ──────────────────────────────────────────
        $byDivStmt = $db->prepare(
            "SELECT d.division_name,
                    COUNT(*)                              AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)   AS total_area_ha,
                    COALESCE(SUM(pcr.cost), 0)            AS total_cost,
                    SUM(CASE WHEN pcr.severity IN ('High','Critical') THEN 1 ELSE 0 END) AS high_count,
                    SUM(CASE WHEN pcr.application_method = 'Manual' THEN 1 ELSE 0 END)   AS manual_count,
                    SUM(CASE WHEN pcr.application_method = 'Spraying' THEN 1 ELSE 0 END) AS spray_count
             $join
             GROUP BY d.division_id, d.division_name
             ORDER BY (SUM(CASE WHEN pcr.severity IN ('High','Critical') THEN 1 ELSE 0 END)) DESC,
                      SUM(pcr.area_covered) DESC"
        );
        $byDivStmt->execute($param);
        $byDivision = $byDivStmt->fetchAll();

        // ── 5. Herbicide vs manual ratio + Paraquat check ────────────────────
        $herbStmt = $db->prepare(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(pcr.area_covered), 0) AS total_area_ha,
                COALESCE(SUM(pcr.cost), 0)          AS total_cost,
                SUM(CASE WHEN pcr.application_method = 'Manual'   THEN 1 ELSE 0 END) AS manual_count,
                SUM(CASE WHEN pcr.application_method = 'Spraying' THEN 1 ELSE 0 END) AS spray_count,
                SUM(CASE WHEN pcr.pesticide_type = 'Herbicide'    THEN 1 ELSE 0 END) AS herbicide_count,
                SUM(CASE WHEN pcr.pesticide_name LIKE '%Paraquat%' OR pcr.pesticide_name LIKE '%paraquat%'
                              OR pcr.pesticide_name LIKE '%Gramoxone%' THEN 1 ELSE 0 END) AS paraquat_count,
                SUM(CASE WHEN pcr.severity = 'High'     THEN 1 ELSE 0 END) AS high_count,
                SUM(CASE WHEN pcr.severity = 'Critical' THEN 1 ELSE 0 END) AS critical_count,
                MIN(pcr.application_date) AS first_date,
                MAX(pcr.application_date) AS last_date
             $join"
        );
        $herbStmt->execute($param);
        $tot = $herbStmt->fetch();

        return [
            'type'           => 'weed_analysis',
            'question'       => $q,
            'date_label'     => $dateLabel,
            'scope'          => $scopeLabel,
            'scope_level'    => $scopeLevel,
            'total_records'  => (int)($tot['total']          ?? 0),
            'total_area_ha'  => (float)($tot['total_area_ha'] ?? 0),
            'total_cost'     => (float)($tot['total_cost']    ?? 0),
            'high_count'     => (int)($tot['high_count']      ?? 0),
            'critical_count' => (int)($tot['critical_count']  ?? 0),
            'manual_count'   => (int)($tot['manual_count']    ?? 0),
            'spray_count'    => (int)($tot['spray_count']     ?? 0),
            'herbicide_count'=> (int)($tot['herbicide_count'] ?? 0),
            'paraquat_count' => (int)($tot['paraquat_count']  ?? 0),
            'first_date'     => $tot['first_date'] ?? null,
            'last_date'      => $tot['last_date']  ?? null,
            'top_weeds'      => $topWeeds,
            'by_method'      => $byMethod,
            'effectiveness'  => $effectiveness,
            'by_division'    => $byDivision,
            'empty'          => (int)($tot['total'] ?? 0) === 0,
        ];
    } catch (\Exception $e) {
        $short = preg_replace('/\s+/', ' ', explode('(SQL', $e->getMessage())[0] ?? $e->getMessage());
        return [
            'type'    => 'weed_analysis', 'question' => $q,
            'scope'   => $scopeLabel ?: 'Semua Kebun', 'scope_level' => $scopeLevel ?: 'all',
            'total_records' => 0, 'total_area_ha' => 0.0, 'total_cost' => 0.0,
            'high_count' => 0, 'critical_count' => 0, 'manual_count' => 0,
            'spray_count' => 0, 'herbicide_count' => 0, 'paraquat_count' => 0,
            'first_date' => null, 'last_date' => null,
            'top_weeds' => [], 'by_method' => [], 'effectiveness' => [], 'by_division' => [],
            'empty' => true, 'db_error' => trim($short),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Weed control pivot by Division
// ─────────────────────────────────────────────────────────────────────────────

function agro_weed_by_division(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Shared scope resolution helper (company → BU → division)
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) { $scopeLabel = $coRow['company_name']; $scopeWhere = 'AND bu.company_id = ?'; $scopeParam = (int)$coRow['company_id']; $scopeLevel = 'company'; }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) { $scopeLabel = $buRow['unit_name']; $scopeWhere = 'AND d.business_unit_id = ?'; $scopeParam = (int)$buRow['business_unit_id']; $scopeLevel = 'bu'; }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) { $scopeLabel = $divRow['division_name']; $scopeWhere = 'AND py.division_id = ?'; $scopeParam = (int)$divRow['division_id']; $scopeLevel = 'division'; }
        }
        if (!$scopeParam) return agro_did_you_mean($db, 'business_unit', $place, $q);
    } else {
        $scopeLabel = 'Semua Kebun'; $scopeLevel = 'all';
    }

    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) { $dateWhere = 'AND pcr.application_date BETWEEN ? AND ?'; $dateParams = [$dateFrom, $dateTo]; }
    elseif ($dateFrom !== null) { $dateWhere = 'AND pcr.application_date >= ?'; $dateParams = [$dateFrom]; }

    try {
        $execParams = $scopeParam !== null ? array_merge([$scopeParam], $dateParams) : $dateParams;

        // Pivot: rows = divisions, columns = weed species (top 8 by records + Other)
        $stmt = $db->prepare(
            "SELECT d.division_id, d.division_name,
                    COALESCE(pcr.pest_name, 'Other')          AS weed_name,
                    COUNT(*)                                  AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)        AS total_ha,
                    COALESCE(SUM(pcr.cost), 0)                AS total_cost,
                    SUM(CASE WHEN pcr.severity IN ('High','Critical') THEN 1 ELSE 0 END) AS high_sev_count,
                    MAX(pcr.application_date)                 AS last_date
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id           = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE pcr.pest_type = 'Weed' $scopeWhere $dateWhere
             GROUP BY d.division_id, d.division_name, COALESCE(pcr.pest_name, 'Other')
             ORDER BY d.division_name, record_count DESC"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return ['type' => 'weed_by_division', 'question' => $q, 'scope' => $scopeLabel, 'date_label' => $dateLabel,
                'wtypes' => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
                'grand_totals' => [], 'grand_total' => 0, 'div_count' => 0,
                'empty' => true, 'db_error' => trim(preg_replace('/\s+/', ' ', explode('(SQL', $e->getMessage())[0] ?? $e->getMessage()))];
    }

    if (empty($rows)) {
        return ['type' => 'weed_by_division', 'question' => $q, 'scope' => $scopeLabel, 'scope_level' => $scopeLevel, 'date_label' => $dateLabel,
                'wtypes' => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
                'grand_totals' => [], 'grand_total' => 0, 'div_count' => 0, 'empty' => true];
    }

    // Determine top-8 weed names globally + collapse rest into 'Other'
    $weedTotals = [];
    foreach ($rows as $r) { $wn = $r['weed_name']; $weedTotals[$wn] = ($weedTotals[$wn] ?? 0) + (int)$r['record_count']; }
    arsort($weedTotals);
    $topNames = array_slice(array_keys($weedTotals), 0, 8);
    if (!in_array('Other', $topNames, true) && count($weedTotals) > 8) $topNames[] = 'Other';

    $pivot = []; $meta = [];
    foreach ($rows as $r) {
        $dk   = $r['division_id'];
        $wn   = in_array($r['weed_name'], $topNames, true) ? $r['weed_name'] : 'Other';
        if (!isset($pivot[$dk])) {
            $pivot[$dk] = [];
            $meta[$dk]  = ['division' => $r['division_name'], 'total_ha' => 0, 'total_cost' => 0, 'high_sev' => 0, 'last_date' => ''];
        }
        $pivot[$dk][$wn]         = ($pivot[$dk][$wn] ?? 0) + (int)$r['record_count'];
        $meta[$dk]['total_ha']   += (float)$r['total_ha'];
        $meta[$dk]['total_cost'] += (float)$r['total_cost'];
        $meta[$dk]['high_sev']   += (int)$r['high_sev_count'];
        if ($r['last_date'] > $meta[$dk]['last_date']) $meta[$dk]['last_date'] = $r['last_date'];
    }

    // Sort weed columns by descending grand total
    $wtypes = [];
    foreach ($topNames as $wn) {
        $tot = 0; foreach ($pivot as $yd) $tot += ($yd[$wn] ?? 0);
        if ($tot > 0) $wtypes[$wn] = $tot;
    }
    arsort($wtypes); $wtypes = array_keys($wtypes);

    $sortedKeys = array_keys($meta);
    usort($sortedKeys, fn($a, $b) => strcmp((string)$meta[$a]['division'], (string)$meta[$b]['division']));

    $grandTotals = array_fill_keys($wtypes, 0);
    foreach ($pivot as $yd) { foreach ($wtypes as $wn) $grandTotals[$wn] += ($yd[$wn] ?? 0); }
    $grandTotal   = array_sum($grandTotals);
    $grandHighSev = array_sum(array_column($meta, 'high_sev'));

    return [
        'type'          => 'weed_by_division', 'question' => $q,
        'scope'         => $scopeLabel, 'scope_level' => $scopeLevel, 'date_label' => $dateLabel,
        'wtypes'        => $wtypes, 'pivot' => $pivot, 'meta' => $meta, 'sorted_keys' => $sortedKeys,
        'grand_totals'  => $grandTotals, 'grand_total' => $grandTotal,
        'grand_high_sev'=> $grandHighSev, 'div_count' => count($sortedKeys), 'empty' => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Weed control pivot by Block
// ─────────────────────────────────────────────────────────────────────────────

function agro_weed_by_block(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) { $scopeLabel = $coRow['company_name']; $scopeWhere = 'AND bu.company_id = ?'; $scopeParam = (int)$coRow['company_id']; $scopeLevel = 'company'; }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) { $scopeLabel = $buRow['unit_name']; $scopeWhere = 'AND d.business_unit_id = ?'; $scopeParam = (int)$buRow['business_unit_id']; $scopeLevel = 'bu'; }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) { $scopeLabel = $divRow['division_name']; $scopeWhere = 'AND py.division_id = ?'; $scopeParam = (int)$divRow['division_id']; $scopeLevel = 'division'; }
        }
        if (!$scopeParam) return agro_did_you_mean($db, 'business_unit', $place, $q);
    } else {
        $scopeLabel = 'Semua Kebun'; $scopeLevel = 'all';
    }

    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) { $dateWhere = 'AND pcr.application_date BETWEEN ? AND ?'; $dateParams = [$dateFrom, $dateTo]; }
    elseif ($dateFrom !== null) { $dateWhere = 'AND pcr.application_date >= ?'; $dateParams = [$dateFrom]; }

    try {
        $execParams = $scopeParam !== null ? array_merge([$scopeParam], $dateParams) : $dateParams;

        $stmt = $db->prepare(
            "SELECT pcr.block_id, b.block_code, b.block_name,
                    d.division_name, bu.unit_name AS estate_name,
                    COALESCE(pcr.pest_name, 'Other')          AS weed_name,
                    COUNT(*)                                  AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)        AS total_ha,
                    COALESCE(SUM(pcr.cost), 0)                AS total_cost,
                    SUM(CASE WHEN pcr.severity IN ('High','Critical') THEN 1 ELSE 0 END) AS high_sev_count,
                    MAX(pcr.application_date)                 AS last_date
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id           = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE pcr.pest_type = 'Weed' $scopeWhere $dateWhere
             GROUP BY pcr.block_id, b.block_code, b.block_name,
                      d.division_name, bu.unit_name, COALESCE(pcr.pest_name, 'Other')
             ORDER BY bu.unit_name, d.division_name, b.block_name, record_count DESC"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return ['type' => 'weed_by_block', 'question' => $q, 'scope' => $scopeLabel, 'date_label' => $dateLabel,
                'wtypes' => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
                'grand_totals' => [], 'grand_total' => 0, 'block_count' => 0,
                'empty' => true, 'db_error' => trim(preg_replace('/\s+/', ' ', explode('(SQL', $e->getMessage())[0] ?? $e->getMessage()))];
    }

    if (empty($rows)) {
        return ['type' => 'weed_by_block', 'question' => $q, 'scope' => $scopeLabel, 'scope_level' => $scopeLevel, 'date_label' => $dateLabel,
                'wtypes' => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
                'grand_totals' => [], 'grand_total' => 0, 'block_count' => 0, 'empty' => true];
    }

    // Top-8 weed names
    $weedTotals = [];
    foreach ($rows as $r) { $wn = $r['weed_name']; $weedTotals[$wn] = ($weedTotals[$wn] ?? 0) + (int)$r['record_count']; }
    arsort($weedTotals);
    $topNames = array_slice(array_keys($weedTotals), 0, 8);
    if (!in_array('Other', $topNames, true) && count($weedTotals) > 8) $topNames[] = 'Other';

    $pivot = []; $meta = [];
    foreach ($rows as $r) {
        $bk  = $r['block_id'];
        $wn  = in_array($r['weed_name'], $topNames, true) ? $r['weed_name'] : 'Other';
        if (!isset($pivot[$bk])) {
            $pivot[$bk] = [];
            $meta[$bk]  = ['estate' => $r['estate_name'], 'division' => $r['division_name'],
                           'block_name' => $r['block_name'], 'block_code' => $r['block_code'],
                           'total_ha' => 0, 'total_cost' => 0, 'high_sev' => 0, 'last_date' => ''];
        }
        $pivot[$bk][$wn]         = ($pivot[$bk][$wn] ?? 0) + (int)$r['record_count'];
        $meta[$bk]['total_ha']   += (float)$r['total_ha'];
        $meta[$bk]['total_cost'] += (float)$r['total_cost'];
        $meta[$bk]['high_sev']   += (int)$r['high_sev_count'];
        if ($r['last_date'] > $meta[$bk]['last_date']) $meta[$bk]['last_date'] = $r['last_date'];
    }

    $wtypes = [];
    foreach ($topNames as $wn) {
        $tot = 0; foreach ($pivot as $yd) $tot += ($yd[$wn] ?? 0);
        if ($tot > 0) $wtypes[$wn] = $tot;
    }
    arsort($wtypes); $wtypes = array_keys($wtypes);

    $sortedKeys = array_keys($meta);
    usort($sortedKeys, function($a, $b) use ($meta) {
        $c = strcmp($meta[$a]['estate'], $meta[$b]['estate']); if ($c) return $c;
        $c = strcmp($meta[$a]['division'], $meta[$b]['division']); if ($c) return $c;
        return strcmp($meta[$a]['block_name'], $meta[$b]['block_name']);
    });

    $grandTotals = array_fill_keys($wtypes, 0);
    foreach ($pivot as $yd) { foreach ($wtypes as $wn) $grandTotals[$wn] += ($yd[$wn] ?? 0); }
    $grandTotal   = array_sum($grandTotals);
    $grandHighSev = array_sum(array_column($meta, 'high_sev'));

    return [
        'type'          => 'weed_by_block', 'question' => $q,
        'scope'         => $scopeLabel, 'scope_level' => $scopeLevel, 'date_label' => $dateLabel,
        'wtypes'        => $wtypes, 'pivot' => $pivot, 'meta' => $meta, 'sorted_keys' => $sortedKeys,
        'grand_totals'  => $grandTotals, 'grand_total' => $grandTotal,
        'grand_high_sev'=> $grandHighSev, 'block_count' => count($sortedKeys), 'empty' => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Weed control pivot by Planting Year
// ─────────────────────────────────────────────────────────────────────────────

function agro_weed_by_planting_year(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) { $scopeLabel = $coRow['company_name']; $scopeWhere = 'AND bu.company_id = ?'; $scopeParam = (int)$coRow['company_id']; $scopeLevel = 'company'; }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) { $scopeLabel = $buRow['unit_name']; $scopeWhere = 'AND d.business_unit_id = ?'; $scopeParam = (int)$buRow['business_unit_id']; $scopeLevel = 'bu'; }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) { $scopeLabel = $divRow['division_name']; $scopeWhere = 'AND py.division_id = ?'; $scopeParam = (int)$divRow['division_id']; $scopeLevel = 'division'; }
        }
        if (!$scopeParam) return agro_did_you_mean($db, 'business_unit', $place, $q);
    } else {
        $scopeLabel = 'Semua Kebun'; $scopeLevel = 'all';
    }

    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) { $dateWhere = 'AND pcr.application_date BETWEEN ? AND ?'; $dateParams = [$dateFrom, $dateTo]; }
    elseif ($dateFrom !== null) { $dateWhere = 'AND pcr.application_date >= ?'; $dateParams = [$dateFrom]; }

    try {
        $execParams = $scopeParam !== null ? array_merge([$scopeParam], $dateParams) : $dateParams;

        $stmt = $db->prepare(
            "SELECT py.year                                   AS planting_year,
                    COALESCE(pcr.pest_name, 'Other')          AS weed_name,
                    COUNT(*)                                  AS record_count,
                    COALESCE(SUM(pcr.area_covered), 0)        AS total_ha,
                    COALESCE(SUM(pcr.cost), 0)                AS total_cost,
                    SUM(CASE WHEN pcr.severity IN ('High','Critical') THEN 1 ELSE 0 END) AS high_sev_count,
                    COUNT(DISTINCT pcr.block_id)              AS block_count,
                    MAX(pcr.application_date)                 AS last_date
             FROM pest_control_records pcr
             INNER JOIN blocks b          ON b.block_id           = pcr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE pcr.pest_type = 'Weed' $scopeWhere $dateWhere
             GROUP BY py.year, COALESCE(pcr.pest_name, 'Other')
             ORDER BY py.year, record_count DESC"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return ['type' => 'weed_by_planting_year', 'question' => $q, 'scope' => $scopeLabel, 'date_label' => $dateLabel,
                'wtypes' => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
                'grand_totals' => [], 'grand_total' => 0, 'year_count' => 0,
                'empty' => true, 'db_error' => trim(preg_replace('/\s+/', ' ', explode('(SQL', $e->getMessage())[0] ?? $e->getMessage()))];
    }

    if (empty($rows)) {
        return ['type' => 'weed_by_planting_year', 'question' => $q, 'scope' => $scopeLabel, 'scope_level' => $scopeLevel, 'date_label' => $dateLabel,
                'wtypes' => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
                'grand_totals' => [], 'grand_total' => 0, 'year_count' => 0, 'empty' => true];
    }

    // Top-8 weed names globally
    $weedTotals = [];
    foreach ($rows as $r) { $wn = $r['weed_name']; $weedTotals[$wn] = ($weedTotals[$wn] ?? 0) + (int)$r['record_count']; }
    arsort($weedTotals);
    $topNames = array_slice(array_keys($weedTotals), 0, 8);
    if (!in_array('Other', $topNames, true) && count($weedTotals) > 8) $topNames[] = 'Other';

    $pivot = []; $meta = [];
    foreach ($rows as $r) {
        $yr = (int)$r['planting_year'];
        $wn  = in_array($r['weed_name'], $topNames, true) ? $r['weed_name'] : 'Other';
        if (!isset($pivot[$yr])) {
            $pivot[$yr] = [];
            $meta[$yr]  = ['total_ha' => 0, 'total_cost' => 0, 'high_sev' => 0, 'block_count' => 0, 'last_date' => ''];
        }
        $pivot[$yr][$wn]          = ($pivot[$yr][$wn] ?? 0) + (int)$r['record_count'];
        $meta[$yr]['total_ha']    += (float)$r['total_ha'];
        $meta[$yr]['total_cost']  += (float)$r['total_cost'];
        $meta[$yr]['high_sev']    += (int)$r['high_sev_count'];
        $meta[$yr]['block_count'] += (int)$r['block_count'];
        if ($r['last_date'] > $meta[$yr]['last_date']) $meta[$yr]['last_date'] = $r['last_date'];
    }

    $wtypes = [];
    foreach ($topNames as $wn) {
        $tot = 0; foreach ($pivot as $yd) $tot += ($yd[$wn] ?? 0);
        if ($tot > 0) $wtypes[$wn] = $tot;
    }
    arsort($wtypes); $wtypes = array_keys($wtypes);

    $sortedKeys = array_keys($meta); sort($sortedKeys);

    $grandTotals = array_fill_keys($wtypes, 0);
    foreach ($pivot as $yd) { foreach ($wtypes as $wn) $grandTotals[$wn] += ($yd[$wn] ?? 0); }
    $grandTotal   = array_sum($grandTotals);
    $grandHighSev = array_sum(array_column($meta, 'high_sev'));

    return [
        'type'          => 'weed_by_planting_year', 'question' => $q,
        'scope'         => $scopeLabel, 'scope_level' => $scopeLevel, 'date_label' => $dateLabel,
        'wtypes'        => $wtypes, 'pivot' => $pivot, 'meta' => $meta, 'sorted_keys' => $sortedKeys,
        'grand_totals'  => $grandTotals, 'grand_total' => $grandTotal,
        'grand_high_sev'=> $grandHighSev, 'year_count' => count($sortedKeys), 'empty' => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Fertilizer / pupuk used
// ─────────────────────────────────────────────────────────────────────────────

function agro_fertilization_used(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope: company → BU → division cascade
    // Empty $place = no scope filter → return all records ("Semua Kebun")
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }

        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }

        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }

        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    // Build optional date filter clause (date_from / date_to are YYYY-MM-DD strings)
    $dateWhere  = '';
    $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = 'AND fr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = 'AND fr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    try {
        $execParams = $scopeParam !== null
            ? array_merge([$scopeParam], $dateParams)
            : $dateParams;

        $rows = $db->prepare(
            "SELECT fr.fertilizer_type,
                    fr.fertilizer_grade,
                    fr.application_method,
                    COUNT(*)                               AS application_count,
                    COALESCE(SUM(fr.quantity_kg), 0)      AS total_qty_kg,
                    COALESCE(SUM(fr.area_covered), 0)     AS total_area_ha,
                    COALESCE(SUM(fr.dosage_per_tree), 0)  AS sum_dosage,
                    COALESCE(SUM(fr.cost), 0)             AS total_cost,
                    MIN(fr.application_date)              AS first_date,
                    MAX(fr.application_date)              AS last_date,
                    MIN(fr.status)                        AS status_sample
             FROM fertilization_records fr
             INNER JOIN blocks b          ON b.block_id              = fr.block_id
             INNER JOIN planting_years py ON py.planting_year_id     = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id           = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id     = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY fr.fertilizer_type, fr.fertilizer_grade, fr.application_method
             ORDER BY total_qty_kg DESC, fr.fertilizer_type ASC"
        );
        $rows->execute($execParams);
        $fertilizers = $rows->fetchAll();
    } catch (\Exception $e) {
        return ['type' => 'unknown', 'message' => 'Data pupuk belum tersedia.'];
    }

    $grandQty    = array_sum(array_column($fertilizers, 'total_qty_kg'));
    $grandArea   = array_sum(array_column($fertilizers, 'total_area_ha'));
    $grandCost   = array_sum(array_column($fertilizers, 'total_cost'));
    $grandApps   = array_sum(array_column($fertilizers, 'application_count'));

    return [
        'type'        => 'fertilization_used',
        'question'    => $q,
        'scope'       => $scopeLabel,
        'scope_level' => $scopeLevel,
        'date_label'  => $dateLabel,
        'fertilizers' => $fertilizers,
        'count'       => count($fertilizers),
        'grand_qty'   => (float)$grandQty,
        'grand_area'  => (float)$grandArea,
        'grand_cost'  => (float)$grandCost,
        'grand_apps'  => (int)$grandApps,
        'empty'       => empty($fertilizers),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Fertilization pivot by Block
// ─────────────────────────────────────────────────────────────────────────────

function agro_fertilization_by_block(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope (same cascade as agro_fertilization_used)
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    // Date filter
    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = 'AND fr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = 'AND fr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    try {
        $execParams = $scopeParam !== null
            ? array_merge([$scopeParam], $dateParams)
            : $dateParams;

        $stmt = $db->prepare(
            "SELECT fr.block_id,
                    b.block_code, b.block_name,
                    d.division_name,
                    bu.unit_name  AS estate_name,
                    fr.fertilizer_type,
                    SUM(fr.quantity_kg)  AS total_kg,
                    SUM(fr.area_covered) AS total_ha,
                    SUM(fr.cost)         AS total_cost,
                    COUNT(*)             AS app_count
             FROM fertilization_records fr
             INNER JOIN blocks b          ON b.block_id           = fr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY fr.block_id, b.block_code, b.block_name,
                      d.division_name, bu.unit_name, fr.fertilizer_type
             ORDER BY bu.unit_name, d.division_name, b.block_name, fr.fertilizer_type"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return ['type' => 'unknown', 'message' => 'Data pemupukan per blok belum tersedia.'];
    }

    if (empty($rows)) {
        return [
            'type'        => 'fertilization_by_block',
            'question'    => $q,
            'scope'       => $scopeLabel,
            'scope_level' => $scopeLevel,
            'date_label'  => $dateLabel,
            'rows'        => [],
            'ftypes'      => [],
            'pivot'       => [],
            'grand_totals'=> [],
            'grand_total' => 0,
            'empty'       => true,
        ];
    }

    // Build pivot matrices
    $pivot    = [];   // [block_id] => [fert_type => kg]
    $meta     = [];   // [block_id] => [estate, division, block_name, block_code, total_ha, total_cost, app_count]
    $ftypes   = [];

    foreach ($rows as $r) {
        $bkey  = $r['block_id'];
        $ftype = $r['fertilizer_type'];
        if (!isset($pivot[$bkey])) {
            $pivot[$bkey] = [];
            $meta[$bkey]  = [
                'estate'     => $r['estate_name'],
                'division'   => $r['division_name'],
                'block_name' => $r['block_name'],
                'block_code' => $r['block_code'],
                'total_ha'   => 0,
                'total_cost' => 0,
                'app_count'  => 0,
            ];
        }
        $pivot[$bkey][$ftype]        = ($pivot[$bkey][$ftype] ?? 0) + (float)$r['total_kg'];
        $meta[$bkey]['total_ha']    += (float)($r['total_ha']   ?? 0);
        $meta[$bkey]['total_cost']  += (float)($r['total_cost'] ?? 0);
        $meta[$bkey]['app_count']   += (int)  ($r['app_count']  ?? 0);
        $ftypes[$ftype] = true;
    }

    ksort($ftypes);
    $ftypes = array_keys($ftypes);

    // Sort blocks: estate > division > block_name
    $sortedKeys = array_keys($meta);
    usort($sortedKeys, function($a, $b) use ($meta) {
        $c = strcmp($meta[$a]['estate'],   $meta[$b]['estate']);   if ($c) return $c;
        $c = strcmp($meta[$a]['division'], $meta[$b]['division']); if ($c) return $c;
        return strcmp($meta[$a]['block_name'], $meta[$b]['block_name']);
    });

    // Grand totals per fertilizer type
    $grandTotals = array_fill_keys($ftypes, 0.0);
    foreach ($pivot as $bdata) {
        foreach ($ftypes as $ft) {
            $grandTotals[$ft] += ($bdata[$ft] ?? 0);
        }
    }
    $grandTotal = array_sum($grandTotals);

    return [
        'type'         => 'fertilization_by_block',
        'question'     => $q,
        'scope'        => $scopeLabel,
        'scope_level'  => $scopeLevel,
        'date_label'   => $dateLabel,
        'ftypes'       => $ftypes,
        'pivot'        => $pivot,
        'meta'         => $meta,
        'sorted_keys'  => $sortedKeys,
        'grand_totals' => $grandTotals,
        'grand_total'  => $grandTotal,
        'block_count'  => count($sortedKeys),
        'empty'        => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Fertilization pivot by Division
// ─────────────────────────────────────────────────────────────────────────────

function agro_fertilization_by_division(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope: company → BU → division (same cascade as by_block)
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    // Date filter
    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = 'AND fr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = 'AND fr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    try {
        $execParams = $scopeParam !== null
            ? array_merge([$scopeParam], $dateParams)
            : $dateParams;

        $stmt = $db->prepare(
            "SELECT d.division_id,
                    d.division_name,
                    bu.unit_name     AS estate_name,
                    fr.fertilizer_type,
                    SUM(fr.quantity_kg)    AS total_kg,
                    SUM(fr.area_covered)   AS total_ha,
                    SUM(fr.cost)           AS total_cost,
                    COUNT(*)               AS app_count,
                    COUNT(DISTINCT fr.block_id) AS block_count
             FROM fertilization_records fr
             INNER JOIN blocks b          ON b.block_id           = fr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY d.division_id, d.division_name, bu.unit_name, fr.fertilizer_type
             ORDER BY bu.unit_name, d.division_name, fr.fertilizer_type"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return [
            'type'       => 'fertilization_by_division',
            'question'   => $q,
            'scope'      => $scopeLabel,
            'date_label' => $dateLabel,
            'ftypes'     => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
            'grand_totals' => [], 'grand_total' => 0, 'div_count' => 0,
            'empty'      => true,
            'db_error'   => $e->getMessage(),
        ];
    }

    if (empty($rows)) {
        return [
            'type'        => 'fertilization_by_division',
            'question'    => $q,
            'scope'       => $scopeLabel,
            'scope_level' => $scopeLevel,
            'date_label'  => $dateLabel,
            'ftypes'      => [],
            'pivot'       => [],
            'meta'        => [],
            'sorted_keys' => [],
            'grand_totals'=> [],
            'grand_total' => 0,
            'div_count'   => 0,
            'empty'       => true,
        ];
    }

    // Build pivot: rows = divisions, columns = fertilizer_type
    $pivot  = [];   // [division_id] => [fert_type => kg]
    $meta   = [];   // [division_id] => [estate, division_name, total_ha, total_cost, app_count, block_count]
    $ftypes = [];

    foreach ($rows as $r) {
        $dkey  = $r['division_id'];
        $ftype = $r['fertilizer_type'] ?: 'Other';
        if (!isset($pivot[$dkey])) {
            $pivot[$dkey] = [];
            $meta[$dkey]  = [
                'estate'      => $r['estate_name'],
                'division'    => $r['division_name'],
                'total_ha'    => 0,
                'total_cost'  => 0,
                'app_count'   => 0,
                'block_count' => 0,
            ];
        }
        $pivot[$dkey][$ftype]        = ($pivot[$dkey][$ftype] ?? 0) + (float)$r['total_kg'];
        $meta[$dkey]['total_ha']    += (float)($r['total_ha']    ?? 0);
        $meta[$dkey]['total_cost']  += (float)($r['total_cost']  ?? 0);
        $meta[$dkey]['app_count']   += (int)  ($r['app_count']   ?? 0);
        $meta[$dkey]['block_count'] += (int)  ($r['block_count'] ?? 0);
        $ftypes[$ftype] = true;
    }

    ksort($ftypes);
    $ftypes = array_keys($ftypes);

    // Sort rows: estate > division_name
    $sortedKeys = array_keys($meta);
    usort($sortedKeys, function($a, $b) use ($meta) {
        $c = strcmp($meta[$a]['estate'],   $meta[$b]['estate']);   if ($c) return $c;
        return strcmp($meta[$a]['division'], $meta[$b]['division']);
    });

    // Grand totals per fertilizer type
    $grandTotals = array_fill_keys($ftypes, 0.0);
    foreach ($pivot as $ddata) {
        foreach ($ftypes as $ft) { $grandTotals[$ft] += ($ddata[$ft] ?? 0); }
    }
    $grandTotal = array_sum($grandTotals);

    return [
        'type'         => 'fertilization_by_division',
        'question'     => $q,
        'scope'        => $scopeLabel,
        'scope_level'  => $scopeLevel,
        'date_label'   => $dateLabel,
        'ftypes'       => $ftypes,
        'pivot'        => $pivot,
        'meta'         => $meta,
        'sorted_keys'  => $sortedKeys,
        'grand_totals' => $grandTotals,
        'grand_total'  => $grandTotal,
        'div_count'    => count($sortedKeys),
        'empty'        => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Fertilization pivot by Planting Year
// ─────────────────────────────────────────────────────────────────────────────

function agro_fertilization_by_planting_year(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // Resolve scope: company → BU → division (same cascade as siblings)
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    if ($place !== '') {
        $coRow = agro_find_company($db, $place);
        if ($coRow) {
            $scopeLabel = $coRow['company_name'];
            $scopeWhere = 'AND bu.company_id = ?';
            $scopeParam = (int)$coRow['company_id'];
            $scopeLevel = 'company';
        }
        if (!$scopeParam) {
            $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? OR unit_code LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
            $bu->execute(['%' . $place . '%', '%' . $place . '%']);
            $buRow = $bu->fetch();
            if ($buRow) {
                $scopeLabel = $buRow['unit_name'];
                $scopeWhere = 'AND d.business_unit_id = ?';
                $scopeParam = (int)$buRow['business_unit_id'];
                $scopeLevel = 'bu';
            }
        }
        if (!$scopeParam) {
            $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
            $div->execute(['%' . $place . '%']);
            $divRow = $div->fetch();
            if ($divRow) {
                $scopeLabel = $divRow['division_name'];
                $scopeWhere = 'AND py.division_id = ?';
                $scopeParam = (int)$divRow['division_id'];
                $scopeLevel = 'division';
            }
        }
        if (!$scopeParam) {
            return agro_did_you_mean($db, 'business_unit', $place, $q);
        }
    } else {
        $scopeLabel = 'Semua Kebun';
        $scopeLevel = 'all';
    }

    // Date filter
    $dateWhere = ''; $dateParams = [];
    if ($dateFrom !== null && $dateTo !== null) {
        $dateWhere  = 'AND fr.application_date BETWEEN ? AND ?';
        $dateParams = [$dateFrom, $dateTo];
    } elseif ($dateFrom !== null) {
        $dateWhere  = 'AND fr.application_date >= ?';
        $dateParams = [$dateFrom];
    }

    try {
        $execParams = $scopeParam !== null
            ? array_merge([$scopeParam], $dateParams)
            : $dateParams;

        // Group by planting year × fertilizer type
        $stmt = $db->prepare(
            "SELECT py.year                    AS planting_year,
                    fr.fertilizer_type,
                    SUM(fr.quantity_kg)        AS total_kg,
                    SUM(fr.area_covered)       AS total_ha,
                    SUM(fr.cost)               AS total_cost,
                    COUNT(*)                   AS app_count,
                    COUNT(DISTINCT fr.block_id) AS block_count
             FROM fertilization_records fr
             INNER JOIN blocks b          ON b.block_id           = fr.block_id
             INNER JOIN planting_years py ON py.planting_year_id  = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id        = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id  = d.business_unit_id
             WHERE 1=1 $scopeWhere $dateWhere
             GROUP BY py.year, fr.fertilizer_type
             ORDER BY py.year, fr.fertilizer_type"
        );
        $stmt->execute($execParams);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) {
        return [
            'type'       => 'fertilization_by_planting_year',
            'question'   => $q,
            'scope'      => $scopeLabel,
            'date_label' => $dateLabel,
            'ftypes'     => [], 'pivot' => [], 'meta' => [], 'sorted_keys' => [],
            'grand_totals' => [], 'grand_total' => 0, 'year_count' => 0,
            'empty'      => true,
            'db_error'   => $e->getMessage(),
        ];
    }

    if (empty($rows)) {
        return [
            'type'        => 'fertilization_by_planting_year',
            'question'    => $q,
            'scope'       => $scopeLabel,
            'scope_level' => $scopeLevel,
            'date_label'  => $dateLabel,
            'ftypes'      => [],
            'pivot'       => [],
            'meta'        => [],
            'sorted_keys' => [],
            'grand_totals'=> [],
            'grand_total' => 0,
            'year_count'  => 0,
            'empty'       => true,
        ];
    }

    // Build pivot: rows = planting years, columns = fertilizer_type
    $pivot  = [];   // [year] => [fert_type => kg]
    $meta   = [];   // [year] => [total_ha, total_cost, app_count, block_count]
    $ftypes = [];

    foreach ($rows as $r) {
        $yr    = (int)$r['planting_year'];
        $ftype = $r['fertilizer_type'] ?: 'Other';
        if (!isset($pivot[$yr])) {
            $pivot[$yr] = [];
            $meta[$yr]  = [
                'total_ha'    => 0,
                'total_cost'  => 0,
                'app_count'   => 0,
                'block_count' => 0,
            ];
        }
        $pivot[$yr][$ftype]        = ($pivot[$yr][$ftype] ?? 0) + (float)$r['total_kg'];
        $meta[$yr]['total_ha']    += (float)($r['total_ha']    ?? 0);
        $meta[$yr]['total_cost']  += (float)($r['total_cost']  ?? 0);
        $meta[$yr]['app_count']   += (int)  ($r['app_count']   ?? 0);
        $meta[$yr]['block_count'] += (int)  ($r['block_count'] ?? 0);
        $ftypes[$ftype] = true;
    }

    ksort($ftypes);
    $ftypes = array_keys($ftypes);

    // Sort rows ascending by planting year
    $sortedKeys = array_keys($meta);
    sort($sortedKeys);

    // Grand totals per fertilizer type
    $grandTotals = array_fill_keys($ftypes, 0.0);
    foreach ($pivot as $ydata) {
        foreach ($ftypes as $ft) { $grandTotals[$ft] += ($ydata[$ft] ?? 0); }
    }
    $grandTotal = array_sum($grandTotals);

    return [
        'type'         => 'fertilization_by_planting_year',
        'question'     => $q,
        'scope'        => $scopeLabel,
        'scope_level'  => $scopeLevel,
        'date_label'   => $dateLabel,
        'ftypes'       => $ftypes,
        'pivot'        => $pivot,
        'meta'         => $meta,
        'sorted_keys'  => $sortedKeys,
        'grand_totals' => $grandTotals,
        'grand_total'  => $grandTotal,
        'year_count'   => count($sortedKeys),
        'empty'        => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Harvest + Transport (FFB Delivery) combined
// ─────────────────────────────────────────────────────────────────────────────

function agro_harvest_transport(PDO $db, string $place, string $q): array
{
    // Resolve scope: company → BU → division
    $scopeLabel = ''; $scopeWhere = ''; $scopeParam = null; $scopeLevel = '';

    $coRow = agro_find_company($db, $place);
    if ($coRow) {
        $scopeLabel = $coRow['company_name'];
        $scopeWhere = 'AND bu.company_id = ?';
        $scopeParam = (int)$coRow['company_id'];
        $scopeLevel = 'company';
    }

    if (!$scopeParam) {
        $bu = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE unit_name LIKE ? ORDER BY LENGTH(unit_name) ASC LIMIT 1");
        $bu->execute(['%' . $place . '%']);
        $buRow = $bu->fetch();
        if ($buRow) {
            $scopeLabel = $buRow['unit_name'];
            $scopeWhere = 'AND d.business_unit_id = ?';
            $scopeParam = (int)$buRow['business_unit_id'];
            $scopeLevel = 'bu';
        }
    }

    if (!$scopeParam) {
        $div = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_name LIKE ? ORDER BY LENGTH(division_name) ASC LIMIT 1");
        $div->execute(['%' . $place . '%']);
        $divRow = $div->fetch();
        if ($divRow) {
            $scopeLabel = $divRow['division_name'];
            $scopeWhere = 'AND py.division_id = ?';
            $scopeParam = (int)$divRow['division_id'];
            $scopeLevel = 'division';
        }
    }

    if (!$scopeParam) {
        return agro_did_you_mean($db, 'business_unit', $place, $q);
    }

    // ── Harvest realisations per division ────────────────────────────────────
    try {
        $hRows = $db->prepare(
            "SELECT d.division_name,
                    COUNT(hr.harvest_id)                   AS harvest_count,
                    COALESCE(SUM(hr.actual_quantity_kg),0) AS total_kg,
                    COALESCE(SUM(hr.actual_bunches),0)     AS total_bunches,
                    COALESCE(SUM(hr.loose_fruits_kg),0)    AS loose_fruits_kg,
                    MIN(hr.harvest_date)                   AS first_harvest,
                    MAX(hr.harvest_date)                   AS last_harvest
             FROM harvest_realizations hr
             INNER JOIN blocks b          ON b.block_id          = hr.block_id
             INNER JOIN planting_years py ON py.planting_year_id = b.planting_year_id
             INNER JOIN divisions d       ON d.division_id       = py.division_id
             INNER JOIN business_units bu ON bu.business_unit_id = d.business_unit_id
             WHERE 1=1 $scopeWhere
             GROUP BY d.division_id, d.division_name
             ORDER BY total_kg DESC"
        );
        $hRows->execute([$scopeParam]);
        $harvestByDiv = $hRows->fetchAll();
    } catch (\Exception $e) {
        $harvestByDiv = [];
    }

    // ── FFB deliveries per division ──────────────────────────────────────────
    try {
        $dRows = $db->prepare(
            "SELECT d.division_name,
                    COUNT(fd.delivery_id)                  AS delivery_count,
                    COALESCE(SUM(fd.net_weight),0)         AS total_net_kg,
                    COALESCE(SUM(fd.gross_weight),0)       AS total_gross_kg,
                    COALESCE(SUM(fd.bunch_count),0)        AS total_bunches,
                    COALESCE(AVG(fd.travel_time_hours),0)  AS avg_travel_hrs,
                    COALESCE(AVG(fd.distance_km),0)        AS avg_distance_km,
                    MIN(fd.delivery_date)                  AS first_delivery,
                    MAX(fd.delivery_date)                  AS last_delivery,
                    SUM(CASE WHEN fd.delivery_status='Rejected' THEN 1 ELSE 0 END) AS rejected_count,
                    SUM(CASE WHEN fd.delivery_status='Unloaded' THEN 1 ELSE 0 END) AS unloaded_count
             FROM ffb_deliveries fd
             INNER JOIN harvest_realizations hr ON hr.harvest_id       = fd.harvest_id
             INNER JOIN blocks b                ON b.block_id          = hr.block_id
             INNER JOIN planting_years py       ON py.planting_year_id = b.planting_year_id
             INNER JOIN divisions d             ON d.division_id       = py.division_id
             INNER JOIN business_units bu       ON bu.business_unit_id = d.business_unit_id
             WHERE 1=1 $scopeWhere
             GROUP BY d.division_id, d.division_name
             ORDER BY total_net_kg DESC"
        );
        $dRows->execute([$scopeParam]);
        $deliveryByDiv = $dRows->fetchAll();
    } catch (\Exception $e) {
        $deliveryByDiv = [];
    }

    // ── Quality grade breakdown (deliveries) ─────────────────────────────────
    try {
        $gRows = $db->prepare(
            "SELECT fd.quality_grade,
                    COUNT(*)                         AS count,
                    COALESCE(SUM(fd.net_weight), 0)  AS total_kg
             FROM ffb_deliveries fd
             INNER JOIN harvest_realizations hr ON hr.harvest_id       = fd.harvest_id
             INNER JOIN blocks b                ON b.block_id          = hr.block_id
             INNER JOIN planting_years py       ON py.planting_year_id = b.planting_year_id
             INNER JOIN divisions d             ON d.division_id       = py.division_id
             INNER JOIN business_units bu       ON bu.business_unit_id = d.business_unit_id
             WHERE 1=1 $scopeWhere
             GROUP BY fd.quality_grade
             ORDER BY total_kg DESC"
        );
        $gRows->execute([$scopeParam]);
        $gradeBreakdown = $gRows->fetchAll();
    } catch (\Exception $e) {
        $gradeBreakdown = [];
    }

    // ── Grand totals ─────────────────────────────────────────────────────────
    $grandHarvestKg  = array_sum(array_column($harvestByDiv,  'total_kg'));
    $grandHarvestCnt = array_sum(array_column($harvestByDiv,  'harvest_count'));
    $grandBunches    = array_sum(array_column($harvestByDiv,  'total_bunches'));
    $grandDelivKg    = array_sum(array_column($deliveryByDiv, 'total_net_kg'));
    $grandDelivCnt   = array_sum(array_column($deliveryByDiv, 'delivery_count'));
    $grandRejected   = array_sum(array_column($deliveryByDiv, 'rejected_count'));
    $grandUnloaded   = array_sum(array_column($deliveryByDiv, 'unloaded_count'));

    // Transport efficiency: delivered vs harvested (net weight)
    $transportRatio  = $grandHarvestKg > 0 ? round($grandDelivKg / $grandHarvestKg * 100, 1) : null;
    // Average bunch weight from harvest
    $avgAbw          = $grandBunches > 0 ? round($grandHarvestKg / $grandBunches, 2) : null;

    return [
        'type'            => 'harvest_transport',
        'question'        => $q,
        'scope'           => $scopeLabel,
        'scope_level'     => $scopeLevel,
        'harvest_by_div'  => $harvestByDiv,
        'delivery_by_div' => $deliveryByDiv,
        'grade_breakdown' => $gradeBreakdown,
        'grand_harvest_kg'  => (float)$grandHarvestKg,
        'grand_harvest_cnt' => (int)$grandHarvestCnt,
        'grand_bunches'     => (int)$grandBunches,
        'grand_deliv_kg'    => (float)$grandDelivKg,
        'grand_deliv_cnt'   => (int)$grandDelivCnt,
        'grand_rejected'    => (int)$grandRejected,
        'grand_unloaded'    => (int)$grandUnloaded,
        'transport_ratio'   => $transportRatio,
        'avg_abw'           => $avgAbw,
        'empty'             => empty($harvestByDiv) && empty($deliveryByDiv),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Comprehensive Analysis — all 10 domains in one response
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run all available analyses for a given scope (company/BU/division or empty = all).
 * Each domain is silently skipped if it returns no data, so the result always
 * contains only meaningful sections.
 *
 * Domains (in display order, matching GAPKI scoring framework):
 *  1. Perkebunan / Plantation
 *  2. Hasil Panen / Harvest
 *  3. Pemupukan / Fertilization
 *  4. Bahan Kimia / Pest Control Chemicals
 *  5. Hama & Penyakit / Pest & Disease
 *  6. Gulma / Weed Control
 *  7. Pembibitan / Nursery
 *  8. Infrastruktur / Roads & Bridges
 *  9. Pabrik / Mill Production (OER / KER)
 * 10. Keuangan / Financial
 */
function agro_comprehensive_analysis(PDO $db, string $place, string $q, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = '', bool $execMode = false): array
{
    // Default to current year when no explicit date filter was provided
    if ($dateFrom === null) {
        $currentYear = (int)date('Y');
        $dateFrom    = $currentYear . '-01-01';
        $dateTo      = $currentYear . '-12-31';
        $dateLabel   = (string)$currentYear;
    }

    $domains = [];

    // Helper: silently skip a domain if the data-fetch throws
    $try = function(string $key, callable $fn) use (&$domains): void {
        try {
            $result = $fn();
            // Keep only if it has real data (not empty / not_found / unknown)
            $type = $result['type'] ?? '';
            if (!in_array($type, ['not_found', 'unknown', ''], true)) {
                $domains[$key] = $result;
            }
        } catch (\Throwable) {
            // silently skip
        }
    };

    // Plantation, nursery, infrastructure are structural (area/road data) — no date column.
    // All other domains use date columns on their main transaction table.
    $try('plantation',     fn() => agro_plantation_analysis($db, $place, $q));
    $try('harvest',        fn() => agro_harvest_summary($db, $q, $place, $dateFrom, $dateTo, $dateLabel));
    $try('fertilization',  fn() => agro_fertilization_used($db, $place, $q, $dateFrom, $dateTo, $dateLabel));
    $try('chemicals',      fn() => agro_chemicals_used($db, $place, $q, $dateFrom, $dateTo, $dateLabel));
    $try('pest',           fn() => agro_pest_analysis($db, $place, $q, $dateFrom, $dateTo, $dateLabel));
    $try('weed',           fn() => agro_weed_analysis($db, $place, $q, $dateFrom, $dateTo, $dateLabel));
    $try('nursery',        fn() => agro_nursery_summary($db, $q, $place));
    $try('infrastructure', fn() => agro_infrastructure_summary($db, $q, $place));
    $try('mill',           fn() => agro_rendemen($db, $q, $dateFrom, $dateTo, $dateLabel));
    $try('financial',      fn() => agro_financial_summary($db, $q, $place, $dateFrom, $dateTo, $dateLabel));

    // Resolve scope label from the first domain that has one, or fall back to place
    $scopeLabel = $place ?: 'Semua Kebun';
    foreach ($domains as $d) {
        if (!empty($d['scope']) && $d['scope'] !== 'Semua Kebun') {
            $scopeLabel = $d['scope'];
            break;
        }
    }

    return [
        'type'        => 'comprehensive_analysis',
        'question'    => $q,
        'scope'       => $scopeLabel,
        'place'       => $place,
        'date_label'  => $dateLabel,
        'date_from'   => $dateFrom,
        'date_to'     => $dateTo,
        'domains'     => $domains,
        'domain_count'=> count($domains),
        'exec_mode'   => $execMode,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Financial Summary (P&L + Balance Sheet + Ratios)
// ─────────────────────────────────────────────────────────────────────────────

function agro_financial_summary(PDO $db, string $q, string $place, ?string $dateFrom = null, ?string $dateTo = null, string $dateLabel = ''): array
{
    // ── 1. Resolve scope ─────────────────────────────────────────────────────
    $scopeLabel = 'Semua Kebun';

    // $orgWhere / $orgParam are built once scope is known
    $orgWhere = '';
    $orgParam = [];

    if ($place !== '') {
        // Try BU first (more specific), then company
        $buStmt = $db->prepare(
            "SELECT business_unit_id, unit_name FROM business_units
              WHERE unit_name LIKE ? OR unit_code LIKE ?
              ORDER BY LENGTH(unit_name) ASC LIMIT 1"
        );
        $buStmt->execute(['%' . $place . '%', '%' . $place . '%']);
        $buRow = $buStmt->fetch();

        if ($buRow) {
            $scopeLabel = $buRow['unit_name'];
            $orgWhere   = 'AND je.business_unit_id = ?';
            $orgParam   = [(int)$buRow['business_unit_id']];
        } else {
            // Try company — match by name OR code
            $coRow = agro_find_company($db, $place);

            if ($coRow) {
                $scopeLabel = $coRow['company_name'];
                // Use company_id filter — all journal entries under this company
                $orgWhere = 'AND je.company_id = ?';
                $orgParam = [(int)$coRow['company_id']];
            } else {
                return agro_did_you_mean($db, 'company', $place, $q);
            }
        }
    }

    try {
        // ── 3. Determine date range: explicit > auto-detect latest year ───────
        if ($dateFrom !== null && $dateTo !== null) {
            $dateFrom_ = $dateFrom;
            $dateTo_   = $dateTo;
        } else {
            $maxDateStmt = $db->prepare("SELECT MAX(entry_date) AS max_d FROM journal_entries je WHERE je.status = 'posted' $orgWhere");
            $maxDateStmt->execute($orgParam);
            $maxDateRow = $maxDateStmt->fetch();
            $maxDate    = $maxDateRow['max_d'] ?? null;
            if ($maxDate) {
                $year     = substr($maxDate, 0, 4);
                $dateFrom_ = $year . '-01-01';
                $dateTo_   = $maxDate;
            }
        }
        if (empty($dateFrom_)) {
            return [
                'type'  => 'financial_summary',
                'question' => $q,
                'scope' => $scopeLabel,
                'empty' => true,
            ];
        }

        if ($dateLabel === '') {
            $year      = substr($dateTo_, 0, 4);
            $dateLabel = 'YTD ' . $year;
        }
        $dateFrom = $dateFrom_;
        $dateTo   = $dateTo_;

        // ── 4. P&L totals ────────────────────────────────────────────────────
        $plSql = "SELECT gla.account_type,
                         SUM(jel.debit_amount)  AS total_debit,
                         SUM(jel.credit_amount) AS total_credit
                  FROM journal_entries je
                  JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
                  JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
                  WHERE je.status = 'posted'
                    AND je.entry_date >= ? AND je.entry_date <= ?
                    $orgWhere
                    AND gla.account_type IN (
                        'revenue','cogs','operating_expense','expense',
                        'depreciation','other_income','other_expenses','tax')
                  GROUP BY gla.account_type";
        $plStmt = $db->prepare($plSql);
        $plStmt->execute(array_merge([$dateFrom, $dateTo], $orgParam));

        $revenue = $cogs = $opex = $otherIncome = $otherExp = $tax = 0.0;
        foreach ($plStmt->fetchAll() as $r) {
            $d = (float)$r['total_debit'];
            $c = (float)$r['total_credit'];
            switch ($r['account_type']) {
                case 'revenue':           $revenue     += ($c - $d); break;
                case 'cogs':              $cogs        += ($d - $c); break;
                case 'operating_expense':
                case 'expense':
                case 'depreciation':      $opex        += ($d - $c); break;
                case 'other_income':      $otherIncome += ($c - $d); break;
                case 'other_expenses':    $otherExp    += ($d - $c); break;
                case 'tax':               $tax         += ($d - $c); break;
            }
        }
        $grossProfit = $revenue - $cogs;
        $opProfit    = $grossProfit - $opex;
        $pbt         = $opProfit + $otherIncome - $otherExp;
        $netProfit   = $pbt - $tax;

        // ── 5. Balance Sheet totals (point-in-time ≤ dateTo) ─────────────────
        $bsSql = "SELECT gla.account_type,
                         SUM(jel.debit_amount)  AS total_debit,
                         SUM(jel.credit_amount) AS total_credit
                  FROM journal_entries je
                  JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
                  JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
                  WHERE je.status = 'posted'
                    AND je.entry_date <= ?
                    $orgWhere
                    AND gla.account_type IN ('asset','liability','equity')
                  GROUP BY gla.account_type";
        $bsStmt = $db->prepare($bsSql);
        $bsStmt->execute(array_merge([$dateTo], $orgParam));

        $rawAssets = $rawLiab = $rawEquity = 0.0;
        foreach ($bsStmt->fetchAll() as $r) {
            $d = (float)$r['total_debit'];
            $c = (float)$r['total_credit'];
            switch ($r['account_type']) {
                case 'asset':     $rawAssets  = $d - $c; break;
                case 'liability': $rawLiab    = $c - $d; break;
                case 'equity':    $rawEquity  = $c - $d; break;
            }
        }
        $currentPl   = $rawAssets - ($rawLiab + $rawEquity);
        $totalAssets = $rawAssets;
        $totalLiab   = $rawLiab;
        $totalEquity = $rawEquity + $currentPl;

        // ── 6. Current assets / liabilities (heuristic by account code) ──────
        $caSql = "SELECT gla.account_type, gla.account_code,
                         SUM(jel.debit_amount)  AS total_debit,
                         SUM(jel.credit_amount) AS total_credit
                  FROM journal_entries je
                  JOIN journal_entry_lines     jel ON jel.journal_entry_id = je.id
                  JOIN general_ledger_accounts gla ON gla.id               = jel.gl_account_id
                  WHERE je.status = 'posted'
                    AND je.entry_date <= ?
                    $orgWhere
                    AND gla.account_type IN ('asset','liability')
                  GROUP BY gla.id, gla.account_type, gla.account_code";
        $caStmt = $db->prepare($caSql);
        $caStmt->execute(array_merge([$dateTo], $orgParam));

        $currentAssets = $currentLiab = 0.0;
        foreach ($caStmt->fetchAll() as $r) {
            $code = (int) preg_replace('/\D/', '', (string)($r['account_code'] ?? '0'));
            $d = (float)$r['total_debit'];
            $c = (float)$r['total_credit'];
            if ($r['account_type'] === 'asset'     && $code < 150000) $currentAssets += ($d - $c);
            if ($r['account_type'] === 'liability' && $code < 250000) $currentLiab   += ($c - $d);
        }

        // ── 7. Ratios ─────────────────────────────────────────────────────────
        $safeDiv = fn($n, $d) => $d != 0 ? round($n / $d, 4) : null;
        $safePct = fn($n, $d) => $d != 0 ? round($n / $d * 100, 2) : null;

        $currentRatio   = $safeDiv($currentAssets, $currentLiab);
        $deRatio        = $safeDiv($totalLiab, $totalEquity);
        $debtRatio      = $safePct($totalLiab, $totalAssets);
        $grossMargin    = $safePct($grossProfit, $revenue);
        $opMargin       = $safePct($opProfit,    $revenue);
        $netMargin      = $safePct($netProfit,   $revenue);
        $roa            = $safePct($netProfit,   $totalAssets);
        $roe            = $safePct($netProfit,   $totalEquity);
        $assetTurnover  = $safeDiv($revenue,     $totalAssets);

    } catch (\Exception $e) {
        return ['type' => 'unknown', 'message' => 'Data keuangan belum tersedia: ' . $e->getMessage()];
    }

    return [
        'type'           => 'financial_summary',
        'question'       => $q,
        'scope'          => $scopeLabel,
        'date_from'      => $dateFrom,
        'date_to'        => $dateTo,
        'date_label'     => $dateLabel,
        'revenue'        => $revenue,
        'cogs'           => $cogs,
        'gross_profit'   => $grossProfit,
        'opex'           => $opex,
        'op_profit'      => $opProfit,
        'other_income'   => $otherIncome,
        'other_exp'      => $otherExp,
        'tax'            => $tax,
        'pbt'            => $pbt,
        'net_profit'     => $netProfit,
        'total_assets'   => $totalAssets,
        'total_liab'     => $totalLiab,
        'total_equity'   => $totalEquity,
        'current_assets' => $currentAssets,
        'current_liab'   => $currentLiab,
        'current_ratio'  => $currentRatio,
        'de_ratio'       => $deRatio,
        'debt_ratio'     => $debtRatio,
        'gross_margin'   => $grossMargin,
        'op_margin'      => $opMargin,
        'net_margin'     => $netMargin,
        'roa'            => $roa,
        'roe'            => $roe,
        'asset_turnover' => $assetTurnover,
        'empty'          => ($revenue == 0 && $totalAssets == 0),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Fuzzy "Did you mean?"
// ─────────────────────────────────────────────────────────────────────────────

function agro_did_you_mean(PDO $db, string $entityType, string $place, string $q): array
{
    try {
        $candidates = match ($entityType) {
            'division'      => $db->query("SELECT division_name AS name FROM divisions ORDER BY division_name")->fetchAll(),
            'block'         => $db->query("SELECT block_name AS name FROM blocks UNION SELECT block_code AS name FROM blocks ORDER BY name LIMIT 200")->fetchAll(),
            'business_unit' => $db->query("SELECT unit_name AS name FROM business_units ORDER BY unit_name")->fetchAll(),
            'company'       => $db->query("SELECT company_name AS name FROM companies ORDER BY company_name")->fetchAll(),
            default         => [],
        };
    } catch (\Exception $e) {
        $candidates = [];
    }

    $scored = [];
    foreach ($candidates as $row) {
        $name = (string)$row['name'];
        similar_text(mb_strtolower($place), mb_strtolower($name), $pct);
        $scored[] = ['name' => $name, 'pct' => $pct];
    }
    usort($scored, fn($a, $b) => $b['pct'] <=> $a['pct']);
    $suggestions = array_slice(
        array_column(array_filter($scored, fn($s) => $s['pct'] > 30), 'name'),
        0, 5
    );

    return [
        'type'        => 'not_found',
        'question'    => $q,
        'entity'      => $entityType,
        'searched'    => $place,
        'suggestions' => $suggestions,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Utility
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Extract an optional date filter phrase from a raw scope string.
 *
 * Supports date ranges, single dates, month+year, and bare year:
 *   "ANP tanggal 25 s/d 28 Januari 2026"    → ["ANP", "2026-01-25", "2026-01-28", "25–28 Januari 2026"]
 *   "ANP 25-28 Jan 2026"                    → ["ANP", "2026-01-25", "2026-01-28", "25–28 Jan 2026"]
 *   "ANP tanggal 25 Januari 2026"           → ["ANP", "2026-01-25", "2026-01-25", "25 Januari 2026"]
 *   "ANP bulan Januari 2026"                → ["ANP", "2026-01-01", "2026-01-31", "Januari 2026"]
 *   "ANP Maret 2025"                        → ["ANP", "2025-03-01", "2025-03-31", "Maret 2025"]
 *   "ANP 2025"                              → ["ANP", "2025-01-01", "2025-12-31", "2025"]
 *   "ANP"                                   → ["ANP", null, null, ""]
 *
 * @return array{0:string, 1:string|null, 2:string|null, 3:string}
 *         [cleaned_place, date_from|null, date_to|null, display_label]
 */
function agro_extract_date_filter(string $raw): array
{
    static $monthMap = [
        'januari'=>1,'february'=>2,'februari'=>2,'maret'=>3,'april'=>4,
        'mei'=>5,'may'=>5,'juni'=>6,'june'=>6,'juli'=>7,'july'=>7,
        'agustus'=>8,'august'=>8,'september'=>9,'oktober'=>10,'october'=>10,
        'november'=>11,'desember'=>12,'december'=>12,
        'jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'jun'=>6,'jul'=>7,
        'aug'=>8,'agu'=>8,'sep'=>9,'okt'=>10,'oct'=>10,'nov'=>11,'des'=>12,'dec'=>12,
    ];
    static $monthNames = ['','Januari','Februari','Maret','April','Mei','Juni',
                          'Juli','Agustus','September','Oktober','November','Desember'];

    $mKeys = implode('|', array_keys($monthMap));

    // ── 1. Date range: "tanggal 25 s/d 28 Januari 2026"
    //                   "25-28 Jan 2026" / "25 to 28 January 2026"
    //                   "25 Jan s/d 28 Jan 2026" / "25 Jan - 28 Jan 2026"
    $sep = '(?:s\/d|sd|to|hingga|sampai|–|-|–)';
    // Pattern A: DD [month] SEP DD month YEAR
    $pA = '/\s+(?:(?:pada\s+)?tanggal\s+|tgl\s+)?(\d{1,2})\s*(?:(' . $mKeys . ')\s+)?'
        . $sep . '\s*(\d{1,2})\s+(' . $mKeys . ')\s+(\d{4})\s*$/iu';
    // Pattern B: DD month YEAR SEP DD month YEAR  (both sides have full date)
    $pB = '/\s+(?:(?:pada\s+)?tanggal\s+|tgl\s+)?(\d{1,2})\s+(' . $mKeys . ')\s+(\d{4})\s*'
        . $sep . '\s*(\d{1,2})\s+(' . $mKeys . ')\s+(\d{4})\s*$/iu';

    if (preg_match($pB, $raw, $m)) {
        $d1 = (int)$m[1]; $mo1 = $monthMap[mb_strtolower($m[2])]; $y1 = (int)$m[3];
        $d2 = (int)$m[4]; $mo2 = $monthMap[mb_strtolower($m[5])]; $y2 = (int)$m[6];
        $from  = sprintf('%04d-%02d-%02d', $y1, $mo1, $d1);
        $to    = sprintf('%04d-%02d-%02d', $y2, $mo2, $d2);
        $label = $d1 . ' ' . $monthNames[$mo1] . ($y1 !== $y2 ? ' ' . $y1 : '')
               . '–' . $d2 . ' ' . $monthNames[$mo2] . ' ' . $y2;
        return [trim(preg_replace($pB, '', $raw) ?? $raw), $from, $to, $label];
    }

    if (preg_match($pA, $raw, $m)) {
        // $m[1]=day1, $m[2]=optional month1 (may be empty), $m[3]=day2, $m[4]=month2, $m[5]=year
        $d1  = (int)$m[1];
        $mo2 = $monthMap[mb_strtolower($m[4])];
        $d2  = (int)$m[3];
        $yr  = (int)$m[5];
        // If month of start was specified use it, else same month as end
        $mo1 = !empty($m[2]) ? $monthMap[mb_strtolower($m[2])] : $mo2;
        $from  = sprintf('%04d-%02d-%02d', $yr, $mo1, $d1);
        $to    = sprintf('%04d-%02d-%02d', $yr, $mo2, $d2);
        $label = $d1 . ($mo1 !== $mo2 ? ' ' . $monthNames[$mo1] : '')
               . '–' . $d2 . ' ' . $monthNames[$mo2] . ' ' . $yr;
        return [trim(preg_replace($pA, '', $raw) ?? $raw), $from, $to, $label];
    }

    // ── 2. Single date: "tanggal 25 Januari 2026"
    $pD = '/\s+(?:tanggal\s+|tgl\s+|pada\s+tanggal\s+)(\d{1,2})\s+(' . $mKeys . ')\s+(\d{4})\s*$/iu';
    if (preg_match($pD, $raw, $m)) {
        $d  = (int)$m[1]; $mo = $monthMap[mb_strtolower($m[2])]; $yr = (int)$m[3];
        $dt = sprintf('%04d-%02d-%02d', $yr, $mo, $d);
        return [trim(preg_replace($pD, '', $raw) ?? $raw), $dt, $dt, $d . ' ' . $monthNames[$mo] . ' ' . $yr];
    }

    // ── 3. Month + year: "bulan Januari 2026" / "Maret 2025"
    $pM = '/\s+(?:bulan\s+)?(' . $mKeys . ')\s+(\d{4})\s*$/iu';
    if (preg_match($pM, $raw, $m)) {
        $mo = $monthMap[mb_strtolower($m[1])]; $yr = (int)$m[2];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $mo, $yr);
        $from  = sprintf('%04d-%02d-01', $yr, $mo);
        $to    = sprintf('%04d-%02d-%02d', $yr, $mo, $daysInMonth);
        return [trim(preg_replace($pM, '', $raw) ?? $raw), $from, $to, $monthNames[$mo] . ' ' . $yr];
    }

    // ── 4. Month only (no year): "bulan Januari" / "Maret"
    $pMo = '/\s+(?:bulan\s+)?(' . $mKeys . ')\s*$/iu';
    if (preg_match($pMo, $raw, $m)) {
        $mo = $monthMap[mb_strtolower($m[1])];
        return [trim(preg_replace($pMo, '', $raw) ?? $raw), null, null, $monthNames[$mo]];
    }

    // ── 5. Year only: "2025" / "tahun 2025"
    $pY = '/\s+(?:tahun\s+)?(\d{4})\s*$/iu';
    if (preg_match($pY, $raw, $m)) {
        $yr = (int)$m[1];
        if ($yr >= 2000 && $yr <= 2100) {
            $from = sprintf('%04d-01-01', $yr);
            $to   = sprintf('%04d-12-31', $yr);
            return [trim(preg_replace($pY, '', $raw) ?? $raw), $from, $to, (string)$yr];
        }
    }

    return [trim($raw), null, null, ''];
}

/**
 * Find a company row by name, code, or initials.
 *
 * Matching order:
 *   1. Exact code match   (company_code = ?)
 *   2. LIKE on code       (company_code LIKE '%?%')
 *   3. LIKE on name       (company_name LIKE '%?%')
 *   4. Initials match     — "APN" matches "Agro Prima Nusantara"
 *                           Strips PT/CV/Tbk/Ltd before computing initials.
 *
 * @return array|false  Associative row with company_id, company_code, company_name
 */
/**
 * Resolve the logged-in user's session scope (company → BU → division).
 * Returns ['label'=>..., 'where'=>..., 'param'=>..., 'level'=>...]
 * or null when no session scope is set (show all data).
 */
function agro_session_scope(PDO $db): ?array
{
    $coId  = !empty($_SESSION['company_id'])       ? (int)$_SESSION['company_id']       : null;
    $buId  = !empty($_SESSION['business_unit_id']) ? (int)$_SESSION['business_unit_id'] : null;
    $divId = !empty($_SESSION['division_id'])      ? (int)$_SESSION['division_id']      : null;

    if ($divId) {
        $st = $db->prepare("SELECT division_id, division_name FROM divisions WHERE division_id = ? LIMIT 1");
        $st->execute([$divId]);
        $row = $st->fetch();
        if ($row) return ['label' => $row['division_name'], 'where_and' => 'AND d.division_id = ?',      'where_plain' => 'WHERE d.division_id = ?',      'param' => $divId, 'level' => 'division'];
    }
    if ($buId) {
        $st = $db->prepare("SELECT business_unit_id, unit_name FROM business_units WHERE business_unit_id = ? LIMIT 1");
        $st->execute([$buId]);
        $row = $st->fetch();
        if ($row) return ['label' => $row['unit_name'], 'where_and' => 'AND d.business_unit_id = ?',     'where_plain' => 'WHERE d.business_unit_id = ?',     'param' => $buId, 'level' => 'bu'];
    }
    if ($coId) {
        $st = $db->prepare("SELECT company_id, company_name FROM companies WHERE company_id = ? LIMIT 1");
        $st->execute([$coId]);
        $row = $st->fetch();
        if ($row) return ['label' => $row['company_name'], 'where_and' => 'AND bu.company_id = ?', 'where_plain' => 'WHERE bu.company_id = ?', 'param' => $coId, 'level' => 'company'];
    }
    return null; // no session scope — caller may show all data
}

function agro_find_company(PDO $db, string $place): array|false
{
    if ($place === '') return false;

    // 1+2+3: standard LIKE on code or name
    $st = $db->prepare(
        "SELECT company_id, company_code, company_name
           FROM companies
          WHERE company_code = ?
             OR company_code LIKE ?
             OR company_name LIKE ?
          ORDER BY
            CASE WHEN company_code = ? THEN 0
                 WHEN company_code LIKE ? THEN 1
                 ELSE 2 END,
            LENGTH(company_name) ASC
          LIMIT 1"
    );
    $like = '%' . $place . '%';
    $st->execute([$place, $like, $like, $place, $like]);
    $row = $st->fetch();
    if ($row) return $row;

    // 4. Initials match: compute initials for every company and compare
    $all = $db->query(
        "SELECT company_id, company_code, company_name FROM companies WHERE status = 'Active' OR status IS NULL ORDER BY company_name"
    )->fetchAll();
    $needle = strtoupper(trim($place));
    foreach ($all as $co) {
        // Strip legal prefixes/suffixes before computing initials
        $stripped = preg_replace('/\b(?:PT\.?|CV\.?|Tbk\.?|Ltd\.?|Inc\.?|Corp\.?|Group|Indonesia|Perkebunan|Nusantara)\b\.?/i', ' ', $co['company_name']);
        $words    = array_filter(preg_split('/\s+/', trim($stripped ?? '')));
        $initials = strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), $words)));
        if ($initials === $needle) {
            return $co;
        }
    }

    return false;
}

function agro_clean(string $raw): string
{
    $clean = preg_replace('/[?.,!;]+$/', '', trim($raw)) ?? trim($raw);
    // Strip leading filler words (NOT "estate" — it's part of real BU names)
    $clean = preg_replace('/^(?:the|di|dari|pada|divisi|afdeling|blok|block|division|tanaman|plants?|pohon)\s+/iu', '', $clean) ?? $clean;
    // Strip trailing noise phrases repeatedly until stable
    // e.g. "ANP berdasarkan divisinya"    → "ANP"
    // e.g. "ANP dan penggunaannya"        → "ANP"
    // e.g. "ANP dan pemakaiannya"         → "ANP"
    $noiseUsage = '(?:penggunaan(?:nya)?|pemakaian(?:nya)?|aplikasi(?:nya)?|penerapan(?:nya)?|rekap(?:nya)?)';
    $trailing = '/\s+(?:berdasarkan\s+divisi(?:nya)?|per\s+divisi(?:nya)?|by\s+division|divisinya|berdasarkan|divisi|division|afdeling'
              . '|dan\s+' . $noiseUsage
              . '|nya)\s*$/iu';
    $prev = null;
    while ($prev !== $clean) {
        $prev  = $clean;
        $clean = preg_replace($trailing, '', $clean) ?? $clean;
        // Also strip leading "per divisi" / "dan penggunaannya" that leaked through
        $clean = preg_replace('/^(?:per\s+divisi|berdasarkan)\s+/iu', '', trim($clean)) ?? trim($clean);
        $clean = preg_replace('/^dan\s+' . $noiseUsage . '\s*$/iu', '', trim($clean)) ?? trim($clean);
    }
    // If what remains is purely a noise/connector phrase, return empty → caller treats as scopeless
    if (preg_match('/^(?:dan\s+' . $noiseUsage . '|dan\s+penyakit(?:\s+tanaman)?)\s*$/iu', $clean)) {
        return '';
    }
    return trim($clean);
}

function agro_fmt_kg(float $kg): string
{
    if ($kg >= 1_000_000) return number_format($kg / 1_000_000, 2) . ' ton (×1000)';
    if ($kg >= 1_000)     return number_format($kg / 1_000, 2) . ' ton';
    return number_format($kg, 0) . ' kg';
}

// ─────────────────────────────────────────────────────────────────────────────
// Analysis shortcut — ?analisis=X pre-fills and auto-submits a canonical phrase
// ─────────────────────────────────────────────────────────────────────────────

// ── Language selection ────────────────────────────────────────────────────────
// Priority: GET ?lang=  >  POST lang_toggle  >  session  >  default (id)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['id','en'], true)) {
    $_SESSION['qna_lang'] = $_GET['lang'];
}
if (isset($_POST['lang_toggle']) && in_array($_POST['lang_toggle'], ['id','en'], true)) {
    $_SESSION['qna_lang'] = $_POST['lang_toggle'];
}
$qna_lang = $_SESSION['qna_lang'] ?? 'id';   // 'id' = Bahasa Indonesia (default), 'en' = English

// ── UI string lookup ──────────────────────────────────────────────────────────
$_ui = [
    'id' => [
        'page_title'         => 'Analysis Report — Q&A',
        'subtitle'           => 'Tanyakan pertanyaan tentang data perkebunan dalam Bahasa Indonesia atau Inggris.',
        'ai_on'              => 'AI aktif',
        'ai_off'             => 'AI tidak aktif',
        'clear_btn'          => 'Hapus Semua',
        'clear_confirm'      => 'Hapus semua riwayat pertanyaan?',
        'clear_title'        => 'Hapus semua pertanyaan',
        'ai_cfg_title'       => 'Konfigurasi AI (OpenAI-compatible)',
        'ai_save_btn'        => 'Simpan',
        'suggest_label'      => 'Klik pertanyaan untuk langsung menganalisis',
        'input_placeholder'  => 'Contoh: Total panen Afdeling A',
        'ask_btn'            => 'Tanya',
        'empty_line1'        => 'Belum ada pertanyaan.',
        'empty_line2'        => 'Klik contoh di atas atau ketik pertanyaan Anda.',
        'pinned_label'       => '📌 Pertanyaan Tersimpan',
        'pin_title'          => 'Pin — pertanyaan ini tidak akan terhapus',
        'del_title'          => 'Hapus pertanyaan ini',
        'lang_btn'           => '🌐 English',
        'lang_switch'        => 'en',
        'default_h1'         => 'Agro Q&amp;A',
    ],
    'en' => [
        'page_title'         => 'Analysis Report — Q&A',
        'subtitle'           => 'Ask questions about plantation data in English or Bahasa Indonesia.',
        'ai_on'              => 'AI active',
        'ai_off'             => 'AI inactive',
        'clear_btn'          => 'Clear All',
        'clear_confirm'      => 'Clear all question history?',
        'clear_title'        => 'Clear all questions',
        'ai_cfg_title'       => 'AI Configuration (OpenAI-compatible)',
        'ai_save_btn'        => 'Save',
        'suggest_label'      => 'Click a question to analyse directly',
        'input_placeholder'  => 'Example: Total harvest Division A',
        'ask_btn'            => 'Ask',
        'empty_line1'        => 'No questions yet.',
        'empty_line2'        => 'Click an example above or type your question.',
        'pinned_label'       => '📌 Saved Questions',
        'pin_title'          => 'Pin — this question won\'t be cleared',
        'del_title'          => 'Remove this question',
        'lang_btn'           => '🌐 Indonesia',
        'lang_switch'        => 'id',
        'default_h1'         => 'Agro Q&amp;A',
    ],
];
$_t = $_ui[$qna_lang];   // shortcut: $_t['key']

// ── Analysis topic labels — bilingual ─────────────────────────────────────────
$_analisis_map_all = [
    'id' => [
        'infrastruktur' => 'Analisa Infrastruktur',
        'pembibitan'    => 'Analisa Pembibitan',
        'bahan_kimia'   => 'Analisa Bahan Kimia',
        'pemupukan'     => 'Analisa Pemupukan',
        'gulma'         => 'Analisa Gulma',
        'hama_penyakit' => 'Analisa Hama Penyakit',
        'perkebunan'    => 'Analisa Perkebunan',
        'pabrik'        => 'Analisa Pabrik',
        'keberlanjutan' => 'Analisa Keberlanjutan',
        'keuangan'      => 'Analisa Keuangan',
    ],
    'en' => [
        'infrastruktur' => 'Infrastructure Analysis',
        'pembibitan'    => 'Nursery Analysis',
        'bahan_kimia'   => 'Chemicals Analysis',
        'pemupukan'     => 'Fertilization Analysis',
        'gulma'         => 'Weed Control Analysis',
        'hama_penyakit' => 'Pest & Disease Analysis',
        'perkebunan'    => 'Plantation Analysis',
        'pabrik'        => 'Mill Analysis',
        'keberlanjutan' => 'Sustainability Analysis',
        'keuangan'      => 'Financial Analysis',
    ],
];
$_analisis_map = $_analisis_map_all[$qna_lang];

// Icon class for each analisis topic (used in page header h1)
$_analisis_icon_map = [
    'infrastruktur' => 'bi bi-buildings',
    'pembibitan'    => 'bi bi-flower1',
    'bahan_kimia'   => 'bi bi-eyedropper',
    'pemupukan'     => 'bi bi-droplet-fill',
    'gulma'         => 'bi bi-tree',
    'hama_penyakit' => 'bi bi-bug',
    'perkebunan'    => 'bi bi-map',
    'pabrik'        => 'bi bi-gear-wide-connected',
    'keberlanjutan' => 'bi bi-globe',
    'keuangan'      => 'bi bi-journal-text',
];

// Per-topic suggested questions shown as clickable "Tanya" buttons
$_analisis_suggested_all = [
    'id' => [
        'bahan_kimia' => [
            ['q' => 'Analisa Bahan Kimia',               'label' => 'Chemicals Analysis (summary)'],
            ['q' => 'Analisa Bahan Kimia per Divisi',     'label' => 'Chemicals by Division'],
            ['q' => 'Analisa Bahan Kimia per Blok',       'label' => 'Chemicals by Block'],
            ['q' => 'Analisa Bahan Kimia per Tahun Tanam','label' => 'Chemicals by Planting Year'],
            ['q' => 'Tampilkan bahan kimia yang digunakan','label' => 'Chemicals Used List'],
        ],
        'pemupukan' => [
            ['q' => 'Analisa Pemupukan',                  'label' => 'Fertilization Analysis (summary)'],
            ['q' => 'Analisa Pemupukan per Divisi',        'label' => 'Fertilization by Division'],
            ['q' => 'Analisa Pemupukan per Blok',          'label' => 'Fertilization by Block'],
            ['q' => 'Analisa Pemupukan per Tahun Tanam',   'label' => 'Fertilization by Planting Year'],
        ],
        'gulma' => [
            ['q' => 'Analisa Gulma',                      'label' => 'Weed Control Analysis (summary)'],
            ['q' => 'Analisa Gulma per Divisi',            'label' => 'Weed Control by Division'],
            ['q' => 'Analisa Gulma per Blok',              'label' => 'Weed Control by Block'],
            ['q' => 'Analisa Gulma per Tahun Tanam',       'label' => 'Weed Control by Planting Year'],
            ['q' => 'Tampilkan data pengendalian gulma',   'label' => 'Weed Control Data'],
        ],
        'hama_penyakit' => [
            ['q' => 'Analisa Hama Penyakit',              'label' => 'Pest & Disease Analysis (summary)'],
            ['q' => 'Analisa Hama Penyakit per Divisi',    'label' => 'Pest & Disease by Division'],
            ['q' => 'Analisa Hama Penyakit per Blok',      'label' => 'Pest & Disease by Block'],
            ['q' => 'Analisa Hama Penyakit per Tahun Tanam','label' => 'Pest & Disease by Planting Year'],
            ['q' => 'Tampilkan data pengendalian hama',    'label' => 'Pest Control Data'],
        ],
        'perkebunan' => [
            ['q' => 'Analisa Perkebunan',                 'label' => 'Plantation Analysis (summary)'],
            ['q' => 'Luas area per divisi',               'label' => 'Area by Division'],
            ['q' => 'Kerapatan tanaman',                  'label' => 'Plant Density'],
            ['q' => 'Daftar blok',                        'label' => 'Block List'],
        ],
        'pembibitan' => [
            ['q' => 'Analisa Pembibitan',                 'label' => 'Nursery Analysis (summary)'],
            ['q' => 'Tampilkan data pembibitan',          'label' => 'Nursery Data'],
            ['q' => 'Statistik nursery',                  'label' => 'Nursery Statistics'],
        ],
        'infrastruktur' => [
            ['q' => 'Analisa Infrastruktur',              'label' => 'Infrastructure Analysis (summary)'],
            ['q' => 'Panjang jalan',                      'label' => 'Road Length Data'],
            ['q' => 'Jumlah jembatan',                    'label' => 'Bridge Count'],
        ],
        'pabrik' => [
            ['q' => 'Analisa Pabrik',                     'label' => 'Mill Analysis (summary)'],
            ['q' => 'Produksi pabrik',                    'label' => 'Mill Production'],
            ['q' => 'Stok CPO',                           'label' => 'CPO Stock'],
            ['q' => 'Rendemen CPO',                       'label' => 'CPO Extraction / OER'],
            ['q' => 'Stok kernel',                        'label' => 'Kernel Stock'],
        ],
//        'keberlanjutan' => [
//            ['q' => 'Analisa Keberlanjutan',              'label' => 'Sustainability Analysis (summary)'],
//            ['q' => 'Analisa ISPO',                       'label' => 'ISPO Analysis'],
//            ['q' => 'Analisa RSPO',                       'label' => 'RSPO Analysis'],
//            ['q' => 'Analisa Lingkungan',                 'label' => 'Environmental Analysis'],
//            ['q' => 'Analisa Konservasi',                 'label' => 'Conservation / HCV Analysis'],
//        ],
        'keuangan' => [
            ['q' => 'Analisa Keuangan',                   'label' => 'Financial Analysis (summary)'],
            ['q' => 'Laporan laba rugi',                  'label' => 'P&L Report'],
            ['q' => 'Analisa Neraca',                     'label' => 'Balance Sheet'],
            ['q' => 'Revenue',                            'label' => 'Revenue'],
            ['q' => 'Analisa anggaran',                   'label' => 'Budget Analysis'],
        ],
    ],
    'en' => [
        'bahan_kimia' => [
            ['q' => 'Analyze chemicals',                        'label' => 'Chemicals Analysis (summary)'],
            ['q' => 'Chemicals analysis by division',           'label' => 'Chemicals by Division'],
            ['q' => 'Chemicals analysis by block',              'label' => 'Chemicals by Block'],
            ['q' => 'Chemicals analysis by planting year',      'label' => 'Chemicals by Planting Year'],
            ['q' => 'Show chemicals used',                      'label' => 'List of Chemicals Used'],
        ],
        'pemupukan' => [
            ['q' => 'Analyze fertilization',                    'label' => 'Fertilization Analysis (summary)'],
            ['q' => 'Fertilization analysis by division',       'label' => 'Fertilization by Division'],
            ['q' => 'Fertilization analysis by block',          'label' => 'Fertilization by Block'],
            ['q' => 'Fertilization analysis by planting year',  'label' => 'Fertilization by Planting Year'],
        ],
        'gulma' => [
            ['q' => 'Analyze weed control',                     'label' => 'Weed Control Analysis (summary)'],
            ['q' => 'Weed control analysis by division',        'label' => 'Weed Control by Division'],
            ['q' => 'Weed control analysis by block',           'label' => 'Weed Control by Block'],
            ['q' => 'Weed control analysis by planting year',   'label' => 'Weed Control by Planting Year'],
            ['q' => 'Show weed control data',                   'label' => 'Weed Control Data'],
        ],
        'hama_penyakit' => [
            ['q' => 'Analyze pest and disease',                 'label' => 'Pest & Disease Analysis (summary)'],
            ['q' => 'Pest analysis by division',                'label' => 'Pest & Disease by Division'],
            ['q' => 'Pest analysis by block',                   'label' => 'Pest & Disease by Block'],
            ['q' => 'Pest analysis by planting year',           'label' => 'Pest & Disease by Planting Year'],
            ['q' => 'Show pest control data',                   'label' => 'Pest Control (OPT) Data'],
        ],
        'perkebunan' => [
            ['q' => 'Analyze plantation',                       'label' => 'Plantation Analysis (summary)'],
            ['q' => 'Area by division',                         'label' => 'Area by Division'],
            ['q' => 'Plant density',                            'label' => 'Plant Density'],
            ['q' => 'List blocks',                              'label' => 'Block List'],
        ],
        'pembibitan' => [
            ['q' => 'Analyze nursery',                          'label' => 'Nursery Analysis (summary)'],
            ['q' => 'Show nursery data',                        'label' => 'Nursery Data'],
            ['q' => 'Nursery statistics',                       'label' => 'Nursery Statistics'],
        ],
        'infrastruktur' => [
            ['q' => 'Analyze infrastructure',                   'label' => 'Infrastructure Analysis (summary)'],
            ['q' => 'Road length',                              'label' => 'Road Length Data'],
            ['q' => 'Bridge count',                             'label' => 'Number of Bridges'],
        ],
        'pabrik' => [
            ['q' => 'Analyze mill',                             'label' => 'Mill Analysis (summary)'],
            ['q' => 'Mill production',                          'label' => 'Mill Production'],
            ['q' => 'CPO stock',                                'label' => 'CPO Stock'],
            ['q' => 'CPO extraction rate',                      'label' => 'CPO OER / Extraction Rate'],
            ['q' => 'Kernel stock',                             'label' => 'Kernel Stock'],
        ],
//        'keberlanjutan' => [
//            ['q' => 'Analyze sustainability',                   'label' => 'Sustainability Analysis (summary)'],
//            ['q' => 'ISPO analysis',                            'label' => 'ISPO Analysis'],
//            ['q' => 'RSPO analysis',                            'label' => 'RSPO Analysis'],
//            ['q' => 'Environmental analysis',                   'label' => 'Environmental Analysis'],
//            ['q' => 'Conservation analysis',                    'label' => 'Conservation / HCV Analysis'],
//        ],
        'keuangan' => [
            ['q' => 'Analyze financials',                       'label' => 'Financial Analysis (summary)'],
            ['q' => 'Profit and loss report',                   'label' => 'Profit & Loss Report'],
            ['q' => 'Balance sheet analysis',                   'label' => 'Balance Sheet'],
            ['q' => 'Revenue',                                  'label' => 'Revenue'],
            ['q' => 'Budget analysis',                          'label' => 'Budget Analysis'],
        ],
    ],
];
$_analisis_suggested = $_analisis_suggested_all[$qna_lang];

$qna_auto_question = '';
$_analisis_key = trim((string)($_GET['analisis'] ?? ''));
if ($_analisis_key !== '' && isset($_analisis_map[$_analisis_key])) {
    $qna_auto_question = $_analisis_map[$_analisis_key];
}
// Suggested questions for this topic (shown as clickable buttons)
$_analisis_topic_questions = ($_analisis_key !== '' && isset($_analisis_suggested[$_analisis_key]))
    ? $_analisis_suggested[$_analisis_key]
    : [];
// When a suggestion panel exists, don't auto-submit (user will click a button instead)
if (!empty($_analisis_topic_questions)) {
    $qna_auto_question = '';
}

// Base URL for all form actions / redirects — preserves ?analisis=X and ?lang=X
$_qna_lang_param = '&lang=' . $qna_lang;
$_qna_self = 'qna.php?' . ($_analisis_key !== '' ? 'analisis=' . urlencode($_analisis_key) . $_qna_lang_param : 'lang=' . $qna_lang);
// On POST, read the analisis key that was submitted via hidden field so redirects keep it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_analisis_key'])) {
    $_post_ak = trim((string)$_POST['_analisis_key']);
    if ($_post_ak !== '' && isset($_analisis_map[$_post_ak])) {
        $_qna_self    = 'qna.php?analisis=' . urlencode($_post_ak) . $_qna_lang_param;
        $_analisis_key = $_post_ak;
        // Re-populate the topic questions so the panel renders after POST too
        $_analisis_topic_questions = $_analisis_suggested[$_post_ak] ?? [];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Handle POST (process question)
// ─────────────────────────────────────────────────────────────────────────────

// History is stored entirely in $_SESSION — no hidden fields needed.
if (!isset($_SESSION['qna_history']) || !is_array($_SESSION['qna_history'])) {
    $_SESSION['qna_history'] = [];
}
$history = &$_SESSION['qna_history'];
$question = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Clear history ─────────────────────────────────────────────────────────
    if (isset($_POST['qna_clear'])) {
        if (!empty($_POST['qna_keep_pinned'])) {
            // Keep only pinned turns, discard the rest
            $keepTexts = json_decode((string)$_POST['qna_keep_pinned'], true);
            if (is_array($keepTexts) && count($keepTexts) > 0) {
                $_SESSION['qna_history'] = array_values(
                    array_filter($_SESSION['qna_history'], function($turn) use ($keepTexts) {
                        return in_array((string)($turn['q'] ?? ''), $keepTexts, true);
                    })
                );
            } else {
                $_SESSION['qna_history'] = [];
            }
        } else {
            $_SESSION['qna_history'] = [];
        }
        header('Location: ' . $_qna_self);
        exit;
    }

    // ── Delete a single turn ──────────────────────────────────────────────────
    if (isset($_POST['qna_delete_idx'])) {
        $idx = (int)$_POST['qna_delete_idx'];
        array_splice($history, $idx, 1);
        header('Location: ' . $_qna_self);
        exit;
    }

    // ── AI config save ────────────────────────────────────────────────────────
    if (isset($_POST['agro_ai_save'])) {
        $_SESSION['agro_ai_key']      = trim((string)($_POST['agro_ai_key']      ?? ''));
        $_SESSION['agro_ai_endpoint'] = trim((string)($_POST['agro_ai_endpoint'] ?? ''));
        $_SESSION['agro_ai_model']    = trim((string)($_POST['agro_ai_model']    ?? ''));
        header('Location: ' . $_qna_self);
        exit;
    }

    // ── Add custom standard ───────────────────────────────────────────────────
    if (isset($_POST['add_custom_standard'])) {
        $csErr = [];
        $csStdId = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($_POST['cs_std_id'] ?? ''))));
        if ($csStdId === '') $csErr[] = 'ID standar wajib diisi (huruf kecil, angka, garis bawah).';

        $csParam = trim((string)($_POST['cs_param'] ?? ''));
        if ($csParam === '') $csErr[] = 'Nama parameter wajib diisi.';

        $csCategory = trim((string)($_POST['cs_category'] ?? 'pest_disease'));
        $csUnit     = trim((string)($_POST['cs_unit']     ?? '%'));
        $csDisplay  = trim((string)($_POST['cs_display']  ?? ''));
        $csSource   = trim((string)($_POST['cs_source']   ?? 'GAPKI — Panduan PHT Kelapa Sawit'));
        $csSrcYear  = trim((string)($_POST['cs_source_year'] ?? '2020'));
        $csDesc     = trim((string)($_POST['cs_description'] ?? ''));
        $csPassNote = trim((string)($_POST['cs_pass_note']   ?? 'Memenuhi standar'));
        $csWarnNote = trim((string)($_POST['cs_warn_note']   ?? 'Perlu perhatian'));
        $csFailNote = trim((string)($_POST['cs_fail_note']   ?? 'Tidak memenuhi standar'));

        $toNullFloat = fn($k) => isset($_POST[$k]) && $_POST[$k] !== '' ? (float)$_POST[$k] : null;
        $csPassMin = $toNullFloat('cs_pass_min');
        $csPassMax = $toNullFloat('cs_pass_max');
        $csWarnMin = $toNullFloat('cs_warn_min');
        $csWarnMax = $toNullFloat('cs_warn_max');

        // Auto-build display string if blank
        if ($csDisplay === '') {
            if ($csPassMax !== null && $csPassMin !== null) $csDisplay = $csPassMin . '–' . $csPassMax;
            elseif ($csPassMax !== null) $csDisplay = '<' . $csPassMax;
            elseif ($csPassMin !== null) $csDisplay = '≥' . $csPassMin;
        }

        if (empty($csErr)) {
            try {
                $stmt = $db->prepare(
                    "INSERT INTO agro_custom_standards
                     (std_id,category,param,unit,pass_min,pass_max,warn_min,warn_max,
                      display,source,source_year,description,pass_note,warn_note,fail_note)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                      category=VALUES(category),param=VALUES(param),unit=VALUES(unit),
                      pass_min=VALUES(pass_min),pass_max=VALUES(pass_max),
                      warn_min=VALUES(warn_min),warn_max=VALUES(warn_max),
                      display=VALUES(display),source=VALUES(source),
                      source_year=VALUES(source_year),description=VALUES(description),
                      pass_note=VALUES(pass_note),warn_note=VALUES(warn_note),fail_note=VALUES(fail_note)"
                );
                $stmt->execute([
                    $csStdId, $csCategory, $csParam, $csUnit,
                    $csPassMin, $csPassMax, $csWarnMin, $csWarnMax,
                    $csDisplay, $csSource, $csSrcYear, $csDesc,
                    $csPassNote, $csWarnNote, $csFailNote,
                ]);
                $_SESSION['qna_std_saved'] = "✅ Standar <strong>" . htmlspecialchars($csParam) . "</strong> berhasil disimpan.";
            } catch (PDOException $e) {
                $_SESSION['qna_std_error'] = "❌ Gagal menyimpan standar: " . htmlspecialchars($e->getMessage());
            }
        } else {
            $_SESSION['qna_std_error'] = implode('<br>', $csErr);
        }
        header('Location: qna.php?q=' . urlencode('Lihat standar'));
        exit;
    }

    // ── Delete custom standard ────────────────────────────────────────────────
    if (isset($_POST['delete_custom_standard'])) {
        $delId = trim((string)($_POST['cs_delete_id'] ?? ''));
        if ($delId !== '') {
            try {
                $stmt = $db->prepare("DELETE FROM agro_custom_standards WHERE std_id = ?");
                $stmt->execute([$delId]);
                $_SESSION['qna_std_saved'] = "🗑️ Standar <strong>" . htmlspecialchars($delId) . "</strong> dihapus.";
            } catch (PDOException $e) {
                $_SESSION['qna_std_error'] = "❌ Gagal menghapus: " . htmlspecialchars($e->getMessage());
            }
        }
        header('Location: qna.php?q=' . urlencode('Lihat standar'));
        exit;
    }

    $question = trim((string)($_POST['q'] ?? ''));

    if ($question !== '') {
        // ── Compound detection ────────────────────────────────────────────────
        // Supports trailing clauses separated by "dan", "lalu", "then", "kemudian":
        //   "X dan grafik batang"       → table X  +  chart_request bar
        //   "X dan buat grafik pie"     → table X  +  chart_request pie
        //   "X dan analisa"             → table X  +  analyze_request
        //   "X dan cek standar"         → table X  +  standards_check
        //
        // We match from the RIGHT so "panjang dan luas jalan di ANP dan grafik"
        // correctly splits as primary="panjang dan luas jalan di ANP", chain=grafik.
        $primaryQ     = $question;
        $addAnalysis  = false;
        $addChart     = false;
        $chartSubtype = 'bar';
        $_addStandards = false;

        $sep = '(?:\s+(?:dan|beserta|serta|dengan|plus)\s+|\s+(?:then|lalu|setelah(?:itu)?|kemudian)\s+|\s*(?:->|→|,\s*lalu|;\s*)\s*)';

        // 1. Chart compound — must check BEFORE analyze so "grafik" wins
        //    "X dan buat grafik [pie|batang|batangnya|bar|line|donut|pie 3d|bar 3d]"
        //    "X dan grafik pie" / "X dan grafik pie 3D" / "X dan grafik batang 3D"
        if (preg_match('/^(.+)' . $sep . '(?:buat(?:kan)?\s+)?(?:grafik|chart|diagram|graph)\s*(pie|donut|doughnut|batangnya?|bar|kolom|column|line|garis)?\s*(?:3d|tiga\s*dimensi)?\s*$/ui', $question, $cm)) {
            $primaryQ    = trim($cm[1]);
            $addChart    = true;
            $raw = mb_strtolower(trim($cm[2] ?? '') . ' ' . mb_strtolower($question));
            if (preg_match('/pie|donut|doughnut/', $raw)) {
                $chartSubtype = preg_match('/\b3d\b|tiga\s*dimensi/ui', $question) ? 'pie3d' : 'pie';
            } elseif (preg_match('/line|garis/', $raw))            $chartSubtype = 'line';
            elseif (preg_match('/batangnya?|bar|kolom|column/', $raw))
                $chartSubtype = preg_match('/\b3d\b|tiga\s*dimensi/ui', $question) ? 'bar3d' : 'bar';
            else                                                    $chartSubtype = 'bar';

        // 2. Analyze compound
        } elseif (preg_match('/^(.+)' . $sep . '(?:analisa|analisis|analiz[ei]|analyze|analysis|ringkasan|summary)(?:\s+(?:nya|ini|tabel(?:nya)?|hasilnya))?\s*$/ui', $question, $cm)) {
            $primaryQ   = trim($cm[1]);
            $addAnalysis = true;

        // 3. Standards check compound
        //    "X dan cek standar"  /  "X beserta analisa standar nya"  /  "X dan compare standard"
        } elseif (preg_match('/^(.+)' . $sep . '(?:(?:analisa|analisis|analyze?)\s+)?(?:(?:cek|check)\s+(?:standar|standard)|(?:bandingkan|compare|dibandingkan)\s+(?:dengan\s+)?(?:standar|standard|norm|baku|benchmark)(?:\s*nya)?|(?:analisa|analisis)\s+(?:standar|standard)(?:\s*nya)?|(?:standar|standard)(?:\s*nya)?)\s*$/ui', $question, $cm)) {
            $primaryQ    = trim($cm[1]);
            $addAnalysis = true;
            $_addStandards = true;
        }

        $answer = agro_resolve($db, $primaryQ);

        // ── Semantic fallback ─────────────────────────────────────────────────
        // When the 38 deterministic regex rules do not recognise the question,
        // route through the semantic interpreter which maps the question to a
        // known agro_resolve() route via semantic entity+operation matching.
        // All data still comes from existing agro_resolve() functions — no new SQL.
        if ($answer['type'] === 'unknown') {
            require_once 'includes/qna_semantic_fallback.php';
            $answer = agro_qsf_resolve($db, $primaryQ, $history);
        }


        // Attach last answer as source for chart, analyze, and standards_check.
        // Walk back through history to find the nearest real data table, so that
        // "buat grafik pie" after "buat grafik batang" reuses the same source table.
        if (in_array($answer['type'], ['chart_request', 'analyze_request', 'standards_check'], true) && !empty($history)) {
            $metaTypes   = ['chart_request', 'analyze_request', 'standards_check',
                            'unknown', 'not_found', 'standards_list', ''];
            $sourceAnswer = null;
            foreach ($history as $prevTurn) {
                $prevAns  = (array)($prevTurn['answer'] ?? []);
                $prevType = (string)($prevAns['type'] ?? '');
                if (!in_array($prevType, $metaTypes, true)) {
                    // Found a real data answer — use it directly
                    $sourceAnswer = $prevAns;
                    break;
                }
                if ($prevType === 'chart_request' && !empty($prevAns['source_answer'])) {
                    // Unwrap one level: chart → its original table
                    $sourceAnswer = (array)$prevAns['source_answer'];
                    break;
                }
            }
            if ($sourceAnswer !== null) {
                $answer['source_answer'] = $sourceAnswer;
            }
        }
        array_unshift($history, ['q' => $primaryQ, 'answer' => $answer]);

        // Chain chart_request
        if ($addChart && !in_array($answer['type'], ['unknown', 'not_found'], true)) {
            $chartQ = '📊 Grafik ' . ($chartSubtype === 'pie3d' ? 'Pie 3D' : ($chartSubtype === 'pie' ? 'Pie' : ($chartSubtype === 'line' ? 'Garis' : 'Batang'))) . ' otomatis';
            $chainAnswer = [
                'type'          => 'chart_request',
                'question'      => $chartQ,
                'subtype'       => $chartSubtype,
                'source_answer' => $answer,
            ];
            array_unshift($history, ['q' => $chartQ, 'answer' => $chainAnswer]);
        }

        // Chain analyze_request or standards_check
        if ($addAnalysis && !in_array($answer['type'], ['unknown', 'not_found'], true)) {
            $chainType = $_addStandards ? 'standards_check' : 'analyze_request';
            $chainQ    = $_addStandards ? '📏 Cek standar otomatis' : '📋 Analisis otomatis';
            $chainAnswer = [
                'type'          => $chainType,
                'question'      => $chainQ,
                'source_answer' => $answer,
            ];
            array_unshift($history, ['q' => $chainQ, 'answer' => $chainAnswer]);
        }

        array_splice($history, 20);
    }
    $question = '';
}

// ─────────────────────────────────────────────────────────────────────────────
// Analysis helper — pure PHP stats on any table-type answer
// ─────────────────────────────────────────────────────────────────────────────

function agro_render_analysis(array $src): string
{
    $type  = (string)($src['type'] ?? '');
    $scope = htmlspecialchars((string)($src['scope'] ?? $src['division'] ?? $src['business_unit'] ?? ''));
    $lines = [];   // each element: plain HTML string for one finding
    $warns = [];   // warning strings

    // ── helpers ──────────────────────────────────────────────────────────────
    $pct = fn(float $part, float $total): string =>
        $total > 0 ? number_format($part / $total * 100, 1) . '%' : '—';
    $fmt = fn(float $v, int $dp = 2): string => number_format($v, $dp);
    $esc = fn(string $s): string => htmlspecialchars($s);

    // ── per answer type ───────────────────────────────────────────────────────
    switch ($type) {

        // ── Area by Division ──────────────────────────────────────────────────
        case 'area_by_division':
            $rows     = array_map(fn($r) => (array)$r, (array)($src['rows'] ?? []));
            $grandHa  = (float)($src['grand_ha']     ?? array_sum(array_column($rows, 'total_ha')));
            $grandTm  = (float)($src['grand_tm']     ?? array_sum(array_column($rows, 'tm_ha')));
            $grandTbm = (float)($src['grand_tbm']    ?? array_sum(array_column($rows, 'tbm_ha')));
            $n        = count($rows);
            if ($n === 0) { return '<span class="text-muted">Tidak ada data.</span>'; }

            // Sort for max/min
            usort($rows, fn($a, $b) => (float)$b['total_ha'] <=> (float)$a['total_ha']);
            $largest  = $rows[0];
            $smallest = $rows[$n - 1];
            $avgHa    = $grandHa / $n;
            $tmPct    = $pct($grandTm, $grandHa);
            $tbmPct   = $pct($grandTbm, $grandHa);

            $lines[] = "Total luas kebun <strong>{$scope}</strong>: <strong>{$fmt($grandHa)} ha</strong> di {$n} divisi.";
            $lines[] = "Divisi terluas: <strong>{$esc((string)$largest['division_name'])}</strong> — {$fmt((float)$largest['total_ha'])} ha ({$pct((float)$largest['total_ha'], $grandHa)}).";
            $lines[] = "Divisi terkecil: <strong>{$esc((string)$smallest['division_name'])}</strong> — {$fmt((float)$smallest['total_ha'])} ha.";
            $lines[] = "Rata-rata luas per divisi: <strong>{$fmt($avgHa)} ha</strong>.";
            $lines[] = "TM (Tanaman Menghasilkan): <strong>{$fmt($grandTm)} ha ({$tmPct})</strong> dari total luas.";
            $lines[] = "TBM (Belum Menghasilkan): <strong>{$fmt($grandTbm)} ha ({$tbmPct})</strong>.";

            $unproductive = array_filter($rows, fn($r) => (float)$r['tm_ha'] === 0.0);
            if (count($unproductive) > 0) {
                $names = implode(', ', array_map(fn($r) => $esc((string)$r['division_name']), array_slice($unproductive, 0, 3)));
                $warns[] = count($unproductive) . " divisi belum ada TM: {$names}" . (count($unproductive) > 3 ? ', …' : '') . '.';
            }
            break;

        // ── Plant Density ─────────────────────────────────────────────────────
        case 'plant_density':
            $rows       = array_map(fn($r) => (array)$r, (array)($src['rows'] ?? []));
            $grandPlants= (int)($src['grand_plants'] ?? array_sum(array_column($rows, 'total_plants')));
            $grandDead  = (int)($src['grand_dead']   ?? array_sum(array_column($rows, 'dead_plants')));
            $grandAbnorm= (int)($src['grand_abnorm'] ?? array_sum(array_column($rows, 'abnormal_plants')));
            $grandNormal= (int)($src['grand_normal'] ?? array_sum(array_column($rows, 'normal_plants')));
            $n          = count($rows);
            if ($n === 0) { return '<span class="text-muted">Tidak ada data.</span>'; }

            $normalPct = $pct($grandNormal, $grandPlants);
            $deadPct   = $pct($grandDead,   $grandPlants);
            $abnPct    = $pct($grandAbnorm, $grandPlants);

            usort($rows, fn($a, $b) => (float)($b['actual_density'] ?? 0) <=> (float)($a['actual_density'] ?? 0));

            $lines[] = "Total populasi tanaman <strong>{$scope}</strong>: <strong>" . number_format($grandPlants) . " pohon</strong> di {$n} divisi.";
            $lines[] = "Tanaman normal: <strong>" . number_format($grandNormal) . " ({$normalPct})</strong>.";
            $lines[] = "Abnormal: <strong>" . number_format($grandAbnorm) . " ({$abnPct})</strong>.";
            $lines[] = "Mati: <strong>" . number_format($grandDead) . " ({$deadPct})</strong>.";
            if (!empty($rows[0])) {
                $r = $rows[0];
                $actD = $r['actual_density'] !== null ? $fmt((float)$r['actual_density'], 1) : '—';
                $lines[] = "Divisi dengan kerapatan aktual tertinggi: <strong>{$esc((string)$r['division_name'])}</strong> — {$actD} pohon/ha.";
            }
            if ($grandDead / max($grandPlants, 1) > 0.05) {
                $warns[] = "Tingkat kematian tanaman di atas 5% ({$deadPct}) — perlu perhatian agronomis.";
            }
            if ($grandAbnorm / max($grandPlants, 1) > 0.10) {
                $warns[] = "Proporsi tanaman abnormal tinggi ({$abnPct}) — evaluasi kondisi lahan dan nutrisi.";
            }
            break;

        // ── Bridge Count ──────────────────────────────────────────────────────
        case 'bridge_count':
            $rows     = array_map(fn($r) => (array)$r, (array)($src['rows'] ?? []));
            $grandLen = (float)($src['grand_length_m'] ?? array_sum(array_column($rows, 'bridge_length_m')));
            $n        = count($rows);
            if ($n === 0 || $grandLen === 0.0) {
                return '<span class="text-muted">Data panjang jembatan belum diisi.</span>';
            }
            usort($rows, fn($a, $b) => (float)$b['bridge_length_m'] <=> (float)$a['bridge_length_m']);
            $longest = $rows[0];
            $avgLen  = $grandLen / $n;

            $lines[] = "Total panjang jembatan &amp; gorong-gorong <strong>{$scope}</strong>: <strong>" . number_format($grandLen, 0) . " m</strong> di {$n} divisi.";
            $lines[] = "Divisi dengan jembatan terpanjang: <strong>{$esc((string)$longest['division_name'])}</strong> — " . number_format((float)$longest['bridge_length_m'], 0) . " m ({$pct((float)$longest['bridge_length_m'], $grandLen)}).";
            $lines[] = "Rata-rata panjang per divisi: <strong>" . number_format($avgLen, 0) . " m</strong>.";

            $noData = array_filter($rows, fn($r) => (int)$r['has_length'] === 0);
            if (count($noData)) {
                $warns[] = count($noData) . " divisi belum memiliki data panjang jembatan.";
            }
            break;

        // ── Road by Type ──────────────────────────────────────────────────────
        case 'road_by_type':
            $roadTypes   = (array)($src['road_types']    ?? []);
            $grandByType = (array)($src['grand_by_type'] ?? []);
            if (empty($roadTypes)) {
                return '<span class="text-muted">Data jalan per jenis belum tersedia.</span>';
            }

            $grandTotal = array_sum(array_column($grandByType, 'length_m'));
            $lines[] = "Total panjang jalan <strong>{$scope}</strong>: <strong>" . number_format($grandTotal, 0) . " m</strong> dalam " . count($roadTypes) . " jenis.";

            // Sort types by total length descending
            $sortedTypes = $roadTypes;
            usort($sortedTypes, fn($a, $b) => (float)($grandByType[$b]['length_m'] ?? 0) <=> (float)($grandByType[$a]['length_m'] ?? 0));
            foreach ($sortedTypes as $t) {
                $len = (float)($grandByType[$t]['length_m'] ?? 0);
                $lines[] = "• <strong>{$esc($t)}</strong>: " . number_format($len, 0) . " m ({$pct($len, $grandTotal)}).";
            }
            if (!empty($sortedTypes)) {
                $dom = $sortedTypes[0];
                $lines[] = "Jenis jalan dominan: <strong>{$esc($dom)}</strong> dengan " . $pct((float)($grandByType[$dom]['length_m'] ?? 0), $grandTotal) . " dari total panjang.";
            }
            break;

        // ── Infrastructure Summary (Road + Bridge) ────────────────────────────
        case 'infrastructure_summary': {
            $roadTypes     = (array)($src['road_types']      ?? []);
            $roadGrandType = (array)($src['road_grand_type'] ?? []);
            $grandRoadM    = (float)($src['grand_road_m']    ?? 0);
            $grandBridgeM  = (float)($src['grand_bridge_m']  ?? 0);
            $grandBridgeN  = (int)  ($src['grand_bridge_n']  ?? 0);
            $roadEmpty     = !empty($src['road_empty']);
            $bridgeEmpty   = !empty($src['bridge_empty']);

            if ($roadEmpty && $bridgeEmpty) {
                return '<span class="text-muted">Data infrastruktur belum tersedia untuk wilayah ini.</span>';
            }

            $lines[] = "Ringkasan infrastruktur <strong>{$scope}</strong>:";

            // Road section
            if (!$roadEmpty && $grandRoadM > 0) {
                $lines[] = "🛣️ <strong>Jaringan Jalan:</strong> total <strong>" . number_format($grandRoadM, 0) . " m</strong> dalam " . count($roadTypes) . " jenis.";
                $sorted = $roadTypes;
                usort($sorted, fn($a, $b) => (float)($roadGrandType[$b]['length_m'] ?? 0) <=> (float)($roadGrandType[$a]['length_m'] ?? 0));
                foreach ($sorted as $t) {
                    $len = (float)($roadGrandType[$t]['length_m'] ?? 0);
                    if ($len > 0) {
                        $lines[] = "&nbsp;&nbsp;• <strong>{$esc($t)}</strong>: " . number_format($len, 0) . " m ({$pct($len, $grandRoadM)}).";
                    }
                }
                if (!empty($sorted)) {
                    $dom = $sorted[0];
                    $lines[] = "&nbsp;&nbsp;Jenis dominan: <strong>{$esc($dom)}</strong> — {$pct((float)($roadGrandType[$dom]['length_m'] ?? 0), $grandRoadM)} dari total jaringan.";
                }
                // Maintenance hint: if total road > 50 km flag it
                if ($grandRoadM > 50000) {
                    $warns[] = "Jaringan jalan melebihi 50 km — pertimbangkan jadwal pemeliharaan rutin dan inspeksi kondisi jalan.";
                }
            } else {
                $warns[] = "Data jalan belum tersedia atau belum diisi di modul Komponen Luas Blok.";
            }

            // Bridge section
            if (!$bridgeEmpty && $grandBridgeM > 0) {
                $lines[] = "🌉 <strong>Jembatan &amp; Gorong-Gorong:</strong> total <strong>" . number_format($grandBridgeM, 0) . " m</strong>"
                         . ($grandBridgeN > 0 ? ", <strong>{$grandBridgeN} unit</strong>." : ".");
                // Ratio: road length per bridge length as density indicator
                if ($grandRoadM > 0 && $grandBridgeM > 0) {
                    $ratio = $grandRoadM / $grandBridgeM;
                    $lines[] = "&nbsp;&nbsp;Rasio panjang jalan per jembatan: <strong>" . number_format($ratio, 0) . " m jalan / m jembatan</strong>.";
                    if ($ratio > 500) {
                        $warns[] = "Rasio jalan:jembatan tinggi (" . number_format($ratio, 0) . ") — pastikan tidak ada kebutuhan jembatan baru yang tertunda.";
                    }
                }
            } else {
                $warns[] = "Data jembatan belum tersedia atau belum diisi di modul Komponen Luas Blok.";
            }

            // Bridge count check per division
            $bridgeRows = array_map(fn($r) => (array)$r, (array)($src['bridge_rows'] ?? []));
            $noData = array_filter($bridgeRows, fn($r) => (int)($r['has_length'] ?? 0) === 0 && ((float)($r['bridge_length_m'] ?? 0)) === 0.0);
            if (count($noData) > 0) {
                $warns[] = count($noData) . " divisi belum memiliki data jembatan — update di <a href='block_area_components.php'>Komponen Luas Blok</a>.";
            }
            break;
        }

        // ── Harvest Summary (bare/scoped) ─────────────────────────────────────
        case 'harvest_summary':
            $kg      = (float)($src['total_kg'] ?? 0);
            $bunches = (int)  ($src['bunches']  ?? 0);
            $records = (int)  ($src['records']  ?? 0);
            $scope2  = htmlspecialchars((string)($src['scope'] ?? 'Semua Kebun'));
            if ($kg === 0.0) {
                return '<span class="text-muted">Belum ada data panen untuk dianalisis.</span>';
            }
            $avgKgPerBunch = $bunches > 0 ? $kg / $bunches : 0;
            $lines[] = "Ringkasan panen <strong>{$scope2}</strong>: <strong>" . agro_fmt_kg($kg) . "</strong> dari {$records} realisasi.";
            $lines[] = "Total janjang: <strong>" . number_format($bunches) . "</strong>.";
            if ($avgKgPerBunch > 0) $lines[] = "Rata-rata berat per janjang: <strong>" . number_format($avgKgPerBunch, 2) . " kg/janjang</strong>.";
            $byDiv = array_map(fn($r) => (array)$r, (array)($src['by_division'] ?? []));
            if (!empty($byDiv)) {
                $top = array_slice($byDiv, 0, 3);
                $topNames = array_map(fn($r) => "<strong>" . htmlspecialchars($r['division_name']) . "</strong> (" . agro_fmt_kg((float)$r['total_kg']) . ")", $top);
                $lines[] = "Divisi teratas: " . implode(', ', $topNames) . ".";
            }
            break;

        // ── Harvest Total ─────────────────────────────────────────────────────
        case 'harvest_total':
            $kg      = (float)($src['total_kg']  ?? 0);
            $bunches = (int)  ($src['bunches']   ?? 0);
            $records = (int)  ($src['records']   ?? 0);
            $scope2  = htmlspecialchars((string)($src['scope'] ?? ''));
            if ($kg === 0.0) {
                return '<span class="text-muted">Belum ada data panen untuk dianalisis.</span>';
            }
            $avgKgPerRecord = $records > 0 ? $kg / $records : 0;
            $avgKgPerBunch  = $bunches > 0 ? $kg / $bunches : 0;
            $lines[] = "Total panen <strong>{$scope2}</strong>: <strong>" . agro_fmt_kg($kg) . "</strong> dari {$records} realisasi.";
            $lines[] = "Total janjang: <strong>" . number_format($bunches) . "</strong>.";
            $lines[] = "Rata-rata per realisasi: <strong>" . agro_fmt_kg($avgKgPerRecord) . "</strong>.";
            $lines[] = "Rata-rata berat per janjang: <strong>" . number_format($avgKgPerBunch, 2) . " kg/janjang</strong>.";
            break;

        // ── Top Blocks ────────────────────────────────────────────────────────
        case 'top_blocks':
            $blocks = array_map(fn($b) => (array)$b, (array)($src['blocks'] ?? []));
            if (empty($blocks)) {
                return '<span class="text-muted">Belum ada data blok untuk dianalisis.</span>';
            }
            $grandKg = array_sum(array_column($blocks, 'total_kg'));
            $top     = $blocks[0];
            $lines[] = "Total panen 10 blok teratas: <strong>" . agro_fmt_kg($grandKg) . "</strong>.";
            $lines[] = "Blok terbaik: <strong>{$esc((string)$top['block_name'])}</strong> ({$esc((string)($top['block_code'] ?? ''))}) — " . agro_fmt_kg((float)$top['total_kg']) . ".";
            $lines[] = "Blok teratas menyumbang <strong>{$pct((float)$top['total_kg'], $grandKg)}</strong> dari total 10 blok.";
            $avgKg = $grandKg / count($blocks);
            $belowAvg = array_filter($blocks, fn($b) => (float)$b['total_kg'] < $avgKg);
            if (count($belowAvg) > 0) {
                $warns[] = count($belowAvg) . " dari 10 blok di bawah rata-rata (" . agro_fmt_kg($avgKg) . ").";
            }
            break;

        // ── Count Blocks ──────────────────────────────────────────────────────
        case 'count_blocks':
            $count = (int)  ($src['count']    ?? 0);
            $ha    = (float)($src['total_ha'] ?? 0);
            $s     = htmlspecialchars((string)($src['scope'] ?? ''));
            $lines[] = "Divisi <strong>{$s}</strong> memiliki <strong>{$count} blok</strong> dengan total luas <strong>{$fmt($ha)} ha</strong>.";
            if ($count > 0) {
                $lines[] = "Rata-rata luas per blok: <strong>" . $fmt($ha / $count) . " ha</strong>.";
            }
            break;

        // ── Chemicals by Division (pivot) ─────────────────────────────────────
        case 'chemicals_by_division': {
            $ctypes   = (array)($src['ctypes']      ?? []);
            $pivot    = (array)($src['pivot']       ?? []);
            $grandTots= (array)($src['grand_totals']?? []);
            $grandTotal=(float)($src['grand_total'] ?? 0);
            $divCount = (int)  ($src['div_count']   ?? 0);
            $hasParaquat = !empty($src['has_paraquat']);

            if ($grandTotal <= 0) {
                return '<span class="text-muted">Belum ada data bahan kimia per divisi untuk dianalisis.</span>';
            }

            $lines[] = "Total bahan kimia diaplikasikan: <strong>" . number_format($grandTotal, 2) . " unit</strong>"
                     . " di <strong>{$divCount} divisi</strong>.";

            // Top type by qty
            $sortedT = $grandTots; arsort($sortedT); reset($sortedT);
            $topType = key($sortedT); $topQty = current($sortedT);
            $lines[] = "Tipe terbanyak: <strong>" . htmlspecialchars((string)$topType) . "</strong>"
                     . " — " . number_format($topQty, 2)
                     . ($grandTotal > 0 ? " (" . number_format($topQty / $grandTotal * 100, 1) . "% dari total)" : '') . ".";

            // Top 3 divisions by total usage
            $divTotals = [];
            foreach ($pivot as $dkey => $ddata) { $divTotals[$dkey] = array_sum((array)$ddata); }
            arsort($divTotals);
            $metaD = (array)($src['meta'] ?? []);
            $top3D = array_slice($divTotals, 0, 3, true);
            $topDivNames = [];
            foreach ($top3D as $dk => $qty) {
                $dm = (array)($metaD[$dk] ?? []);
                $topDivNames[] = '<strong>' . htmlspecialchars((string)($dm['division'] ?? $dk)) . '</strong> (' . number_format($qty, 2) . ')';
            }
            if (!empty($topDivNames)) {
                $lines[] = "Divisi dengan penggunaan terbanyak: " . implode(', ', $topDivNames) . ".";
            }

            // Type diversity
            $typeCount = count($ctypes);
            if ($typeCount >= 3) {
                $lines[] = "Program pengendalian mencakup <strong>{$typeCount} tipe bahan kimia</strong> — cakupan PHT yang komprehensif.";
            } elseif ($typeCount === 1) {
                $warns[] = "Hanya <strong>1 tipe</strong> bahan kimia digunakan — evaluasi kelengkapan program pengendalian hama terpadu (PHT).";
            }

            // Paraquat warning
            if ($hasParaquat) {
                $warns[] = "Paraquat / Gramoxone terdeteksi — dilarang di kebun bersertifikasi <strong>RSPO/HHP</strong>. Segera ganti dengan alternatif yang diizinkan.";
            }
            break;
        }

        // ── Chemicals by Block (pivot) ────────────────────────────────────────
        case 'chemicals_by_block': {
            $ctypes     = (array)($src['ctypes']      ?? []);
            $pivot      = (array)($src['pivot']       ?? []);
            $grandTots  = (array)($src['grand_totals']?? []);
            $grandTotal = (float)($src['grand_total'] ?? 0);
            $blockCount = (int)  ($src['block_count'] ?? 0);
            $hasParaquat= !empty($src['has_paraquat']);

            if ($grandTotal <= 0) {
                return '<span class="text-muted">Belum ada data bahan kimia per blok untuk dianalisis.</span>';
            }

            $lines[] = "Total bahan kimia diaplikasikan: <strong>" . number_format($grandTotal, 2) . " unit</strong>"
                     . " di <strong>{$blockCount} blok</strong>.";

            // Top type by qty
            $sorted = $grandTots; arsort($sorted); reset($sorted);
            $topType = key($sorted); $topQty = current($sorted);
            $lines[] = "Tipe terbanyak: <strong>" . htmlspecialchars((string)$topType) . "</strong>"
                     . " — " . number_format($topQty, 2)
                     . ($grandTotal > 0 ? " (" . number_format($topQty / $grandTotal * 100, 1) . "% dari total)" : '') . ".";

            // Blocks with highest total usage
            $blockTotals = [];
            foreach ($pivot as $bkey => $bdata) { $blockTotals[$bkey] = array_sum((array)$bdata); }
            arsort($blockTotals);
            $metaCB = (array)($src['meta'] ?? []);
            $top3   = array_slice($blockTotals, 0, 3, true);
            $topNames = [];
            foreach ($top3 as $bk => $qty) {
                $bm = (array)($metaCB[$bk] ?? []);
                $topNames[] = '<strong>' . htmlspecialchars((string)($bm['block_name'] ?? $bk)) . '</strong> (' . number_format($qty, 2) . ')';
            }
            if (!empty($topNames)) {
                $lines[] = "Blok dengan penggunaan terbanyak: " . implode(', ', $topNames) . ".";
            }

            // Type diversity
            $typeCount = count($ctypes);
            if ($typeCount >= 3) {
                $lines[] = "Program pengendalian mencakup <strong>{$typeCount} tipe bahan kimia</strong> — cakupan lengkap (herbisida, insektisida, fungisida).";
            } elseif ($typeCount === 1) {
                $warns[] = "Hanya <strong>1 tipe</strong> bahan kimia digunakan — evaluasi kelengkapan program pengendalian hama terpadu (PHT).";
            }

            // Paraquat warning
            if ($hasParaquat) {
                $warns[] = "Paraquat / Gramoxone terdeteksi — dilarang di kebun bersertifikasi <strong>RSPO/HHP</strong>. Ganti dengan herbisida yang diizinkan.";
            }
            break;
        }

        // ── Pest & Disease by Block (pivot) — auto-analysis ───────────────────
        case 'pest_by_block': {
            $ptypes      = (array)($src['ptypes']      ?? []);
            $grandTots   = (array)($src['grand_totals']?? []);
            $grandTotal  = (int)  ($src['grand_total'] ?? 0);
            $blockCount  = (int)  ($src['block_count'] ?? 0);
            $grandHiSev  = (int)  ($src['grand_high_sev'] ?? 0);
            $metaPB      = (array)($src['meta']        ?? []);
            if ($grandTotal <= 0) {
                return '<span class="text-muted">Belum ada data hama &amp; penyakit per blok untuk dianalisis.</span>';
            }

            $lines[] = "Total <strong>" . number_format($grandTotal) . " catatan</strong> hama &amp; penyakit"
                     . " dari <strong>{$blockCount} blok</strong>.";

            // Top pest type
            arsort($grandTots);
            reset($grandTots);
            $topPt  = key($grandTots);
            $topCnt = current($grandTots);
            $lines[] = "Jenis hama/penyakit terbanyak: <strong>" . htmlspecialchars((string)$topPt) . "</strong>"
                     . " — " . number_format($topCnt) . " catatan"
                     . ($grandTotal > 0 ? " (" . number_format($topCnt / $grandTotal * 100, 1) . "% dari total)" : '') . ".";

            // High/Critical severity alert
            if ($grandHiSev > 0) {
                $sevPct = $grandTotal > 0 ? number_format($grandHiSev / $grandTotal * 100, 1) : 0;
                $warns[] = "<strong>{$grandHiSev}</strong> catatan dengan tingkat serangan <strong>High/Critical</strong>"
                         . " ({$sevPct}% dari total) — prioritaskan penanganan segera.";
            }

            // Blocks with most records
            $blkTotals = [];
            foreach ($src['pivot'] ?? [] as $bk => $bd) { $blkTotals[$bk] = array_sum((array)$bd); }
            arsort($blkTotals);
            $top3PB = array_slice($blkTotals, 0, 3, true);
            $topBlkNames = [];
            foreach ($top3PB as $bk => $cnt) {
                $bm = (array)($metaPB[$bk] ?? []);
                $topBlkNames[] = '<strong>' . htmlspecialchars((string)($bm['block_name'] ?? $bk)) . '</strong> (' . number_format($cnt) . ' catatan)';
            }
            if (!empty($topBlkNames)) {
                $lines[] = "Blok dengan serangan terbanyak: " . implode(', ', $topBlkNames) . ".";
            }

            // Pest type diversity
            $ptCount = count($ptypes);
            if ($ptCount >= 4) {
                $warns[] = "Ditemukan <strong>{$ptCount} jenis</strong> hama &amp; penyakit — pengendalian terpadu (PHT) diperlukan untuk mencegah resistensi.";
            }
            break;
        }

        // ── Pest & Disease by Division (pivot) — auto-analysis ────────────────
        case 'pest_by_division': {
            $ptypesPD    = (array)($src['ptypes']      ?? []);
            $grandTsPD   = (array)($src['grand_totals']?? []);
            $grandTotPD  = (int)  ($src['grand_total'] ?? 0);
            $divCntPD    = (int)  ($src['div_count']   ?? 0);
            $grandHiPD   = (int)  ($src['grand_high_sev'] ?? 0);
            $metaPD      = (array)($src['meta']        ?? []);

            if ($grandTotPD <= 0) {
                return '<span class="text-muted">Belum ada data hama &amp; penyakit per divisi untuk dianalisis.</span>';
            }

            $lines[] = "Total <strong>" . number_format($grandTotPD) . " catatan</strong> hama &amp; penyakit"
                     . " dari <strong>{$divCntPD} divisi</strong>.";

            // Top pest type
            arsort($grandTsPD);
            reset($grandTsPD);
            $topPtPD  = key($grandTsPD);
            $topCntPD = current($grandTsPD);
            $lines[] = "Jenis hama/penyakit terbanyak: <strong>" . htmlspecialchars((string)$topPtPD) . "</strong>"
                     . " — " . number_format($topCntPD) . " catatan"
                     . ($grandTotPD > 0 ? " (" . number_format($topCntPD / $grandTotPD * 100, 1) . "% dari total)" : '') . ".";

            // High/Critical severity alert
            if ($grandHiPD > 0) {
                $sevPctPD = $grandTotPD > 0 ? number_format($grandHiPD / $grandTotPD * 100, 1) : 0;
                $warns[] = "<strong>{$grandHiPD}</strong> catatan serangan <strong>High/Critical</strong>"
                         . " ({$sevPctPD}% dari total) — identifikasi divisi prioritas penanganan.";
            }

            // Divisions with most records
            $divTotsPD = [];
            foreach ($src['pivot'] ?? [] as $dk => $dd) { $divTotsPD[$dk] = array_sum((array)$dd); }
            arsort($divTotsPD);
            $top3PD = array_slice($divTotsPD, 0, 3, true);
            $topDivNamesPD = [];
            foreach ($top3PD as $dk => $cnt) {
                $dm = (array)($metaPD[$dk] ?? []);
                $topDivNamesPD[] = '<strong>' . htmlspecialchars((string)($dm['division'] ?? $dk)) . '</strong> (' . number_format($cnt) . ' catatan)';
            }
            if (!empty($topDivNamesPD)) {
                $lines[] = "Divisi dengan serangan terbanyak: " . implode(', ', $topDivNamesPD) . ".";
            }

            // Divisions with High/Critical severity
            $hiDivs = array_filter($metaPD, fn($d) => ($d['high_sev'] ?? 0) > 0);
            if (!empty($hiDivs)) {
                $hiNames = [];
                foreach ($hiDivs as $d) { $hiNames[] = htmlspecialchars((string)($d['division'] ?? '')); }
                $warns[] = "Divisi dengan serangan High/Critical: <strong>" . implode(', ', $hiNames) . "</strong> — prioritas pengendalian segera.";
            }

            // Pest type diversity
            $ptCntPD = count($ptypesPD);
            if ($ptCntPD >= 4) {
                $warns[] = "Ditemukan <strong>{$ptCntPD} jenis</strong> hama &amp; penyakit — terapkan Pengendalian Hama Terpadu (PHT) untuk mencegah resistensi.";
            }
            break;
        }

        // ── Pest & Disease by Planting Year (pivot) — auto-analysis ──────────
        case 'pest_by_planting_year': {
            $ptypesPY2   = (array)($src['ptypes']       ?? []);
            $grandTsPY2  = (array)($src['grand_totals'] ?? []);
            $grandTotPY2 = (int)  ($src['grand_total']  ?? 0);
            $yearCntPY2  = (int)  ($src['year_count']   ?? 0);
            $grandHiPY2  = (int)  ($src['grand_high_sev'] ?? 0);
            $metaPY2b    = (array)($src['meta']         ?? []);

            if ($grandTotPY2 <= 0) {
                return '<span class="text-muted">Belum ada data hama &amp; penyakit per tahun tanam untuk dianalisis.</span>';
            }

            $lines[] = "Total <strong>" . number_format($grandTotPY2) . " catatan</strong> hama &amp; penyakit"
                     . " dari <strong>{$yearCntPY2} tahun tanam</strong>.";

            // Top pest type
            arsort($grandTsPY2);
            reset($grandTsPY2);
            $topPtPY2  = key($grandTsPY2);
            $topCntPY2 = current($grandTsPY2);
            $lines[] = "Jenis hama/penyakit terbanyak: <strong>" . htmlspecialchars((string)$topPtPY2) . "</strong>"
                     . " — " . number_format($topCntPY2) . " catatan"
                     . ($grandTotPY2 > 0 ? " (" . number_format($topCntPY2 / $grandTotPY2 * 100, 1) . "% dari total)" : '') . ".";

            // High/Critical severity alert
            if ($grandHiPY2 > 0) {
                $sevPctPY2 = $grandTotPY2 > 0 ? number_format($grandHiPY2 / $grandTotPY2 * 100, 1) : 0;
                $warns[] = "<strong>{$grandHiPY2}</strong> catatan serangan <strong>High/Critical</strong>"
                         . " ({$sevPctPY2}% dari total) — identifikasi tahun tanam rentan.";
            }

            // Planting years with most pest records
            $yrTotsPY2 = [];
            foreach ($src['pivot'] ?? [] as $yr => $yd) { $yrTotsPY2[$yr] = array_sum((array)$yd); }
            arsort($yrTotsPY2);
            $top3PY2 = array_slice($yrTotsPY2, 0, 3, true);
            $topYrNamesPY2 = [];
            foreach ($top3PY2 as $yr => $cnt) {
                $topYrNamesPY2[] = '<strong>TT ' . htmlspecialchars((string)$yr) . '</strong> (' . number_format($cnt) . ' catatan)';
            }
            if (!empty($topYrNamesPY2)) {
                $lines[] = "Tahun tanam dengan serangan terbanyak: " . implode(', ', $topYrNamesPY2) . ".";
            }

            // TBM vs TM susceptibility note
            $allYrs = array_keys($metaPY2b);
            if (!empty($allYrs)) {
                sort($allYrs);
                $youngest = min($allYrs);
                $oldest   = max($allYrs);
                $ageY = date('Y') - $youngest;
                $ageO = date('Y') - $oldest;
                if ($ageY <= 3 && ($metaPY2b[$youngest]['high_sev'] ?? 0) > 0) {
                    $warns[] = "Tanaman muda TT {$youngest} (±{$ageY} thn / TBM) menunjukkan serangan High/Critical — bibit rentan, tingkatkan pengawasan.";
                }
                if ($ageO >= 4 && ($metaPY2b[$oldest]['high_sev'] ?? 0) > 0) {
                    $lines[] = "Tanaman tua TT {$oldest} (±{$ageO} thn / TM) terdampak — kondisi kanopi lebat meningkatkan risiko penyakit.";
                }
            }

            // Pest diversity
            $ptCntPY2 = count($ptypesPY2);
            if ($ptCntPY2 >= 4) {
                $warns[] = "Ditemukan <strong>{$ptCntPY2} jenis</strong> hama &amp; penyakit — terapkan PHT (Pengendalian Hama Terpadu) secara menyeluruh.";
            }
            break;
        }

        // ── Chemicals by Planting Year (pivot) ───────────────────────────────
        case 'chemicals_by_planting_year': {
            $ctypes      = (array)($src['ctypes']      ?? []);
            $pivot       = (array)($src['pivot']       ?? []);
            $grandTots   = (array)($src['grand_totals']?? []);
            $grandTotal  = (float)($src['grand_total'] ?? 0);
            $yearCount   = (int)  ($src['year_count']  ?? 0);
            $hasParaquat = !empty($src['has_paraquat']);

            if ($grandTotal <= 0) {
                return '<span class="text-muted">Belum ada data bahan kimia per tahun tanam untuk dianalisis.</span>';
            }

            $lines[] = "Total bahan kimia diaplikasikan: <strong>" . number_format($grandTotal, 2) . " unit</strong>"
                     . " di <strong>{$yearCount} tahun tanam</strong>.";

            // Top chemical type
            $sortedTPY = $grandTots; arsort($sortedTPY); reset($sortedTPY);
            $topTypePY = key($sortedTPY); $topQtyPY = current($sortedTPY);
            $lines[] = "Tipe terbanyak: <strong>" . htmlspecialchars((string)$topTypePY) . "</strong>"
                     . " — " . number_format($topQtyPY, 2)
                     . ($grandTotal > 0 ? " (" . number_format($topQtyPY / $grandTotal * 100, 1) . "% dari total)" : '') . ".";

            // Year with highest total usage
            $yearTotals = [];
            foreach ($pivot as $yr => $ydata) { $yearTotals[$yr] = array_sum((array)$ydata); }
            arsort($yearTotals);
            $topYears = array_slice($yearTotals, 0, 3, true);
            $topYrNames = [];
            foreach ($topYears as $yr => $qty) {
                $topYrNames[] = '<strong>TT ' . $yr . '</strong> (' . number_format($qty, 2) . ')';
            }
            if (!empty($topYrNames)) {
                $lines[] = "Tahun tanam dengan penggunaan terbanyak: " . implode(', ', $topYrNames) . ".";
            }

            // Trend insight: oldest vs newest year
            if ($yearCount >= 2) {
                $years = array_keys($yearTotals);
                $minYr = min($years); $maxYr = max($years);
                $minQ  = $yearTotals[$minYr] ?? 0;
                $maxQ  = $yearTotals[$maxYr] ?? 0;
                if ($minQ > 0) {
                    $delta = round(($maxQ - $minQ) / $minQ * 100, 1);
                    $dir   = $delta >= 0 ? 'meningkat' : 'menurun';
                    $lines[] = "Penggunaan TT {$maxYr} vs TT {$minYr}: <strong>" . abs($delta) . "% {$dir}</strong>.";
                }
            }

            // Type diversity
            $typeCount = count($ctypes);
            if ($typeCount >= 3) {
                $lines[] = "Program pengendalian mencakup <strong>{$typeCount} tipe bahan kimia</strong> — cakupan PHT komprehensif.";
            } elseif ($typeCount === 1) {
                $warns[] = "Hanya <strong>1 tipe</strong> bahan kimia digunakan — evaluasi kelengkapan program PHT.";
            }

            // Paraquat warning
            if ($hasParaquat) {
                $warns[] = "Paraquat / Gramoxone terdeteksi — dilarang di kebun bersertifikasi <strong>RSPO/HHP</strong>. Segera ganti dengan alternatif yang diizinkan.";
            }
            break;
        }

        // ── Chemicals Used ────────────────────────────────────────────────────
        case 'chemicals_used':
            $chems    = array_map(fn($c) => (array)$c, (array)($src['chemicals'] ?? []));
            $grandQty = (float)($src['grand_qty']  ?? 0);
            $grandApp = (int)  ($src['grand_apps'] ?? 0);
            $n        = count($chems);
            if ($n === 0) {
                return '<span class="text-muted">Belum ada data bahan kimia untuk dianalisis.</span>';
            }
            usort($chems, fn($a, $b) => (float)($b['total_qty'] ?? 0) <=> (float)($a['total_qty'] ?? 0));
            $top = $chems[0];

            $lines[] = "Total <strong>{$n} produk</strong> bahan kimia digunakan di <strong>{$scope}</strong> dalam <strong>{$grandApp}</strong> kali aplikasi.";
            $lines[] = "Produk terbanyak digunakan: <strong>" . htmlspecialchars((string)$top['pesticide_name']) . "</strong> ("
                     . htmlspecialchars((string)($top['pesticide_type'] ?? '')) . ") — "
                     . number_format((float)$top['total_qty'], 2) . ' ' . htmlspecialchars((string)($top['unit'] ?? ''))
                     . " (" . ($grandQty > 0 ? number_format((float)$top['total_qty'] / $grandQty * 100, 1) . '%' : '—') . " dari total).";

            // Count by type
            $byType = [];
            foreach ($chems as $c) {
                $t = (string)($c['pesticide_type'] ?? 'Other');
                $byType[$t] = ($byType[$t] ?? 0) + 1;
            }
            arsort($byType);
            $typeSummary = [];
            foreach ($byType as $t => $cnt) {
                $typeSummary[] = "<strong>{$cnt}</strong> " . htmlspecialchars($t);
            }
            $lines[] = "Komposisi jenis: " . implode(', ', $typeSummary) . ".";

            if ((float)($src['grand_cost'] ?? 0) > 0) {
                $lines[] = "Total biaya pengendalian: <strong>Rp " . number_format((float)$src['grand_cost'], 0, ',', '.') . "</strong>.";
            }

            // Warn if paraquat (Gramoxone) detected — restricted under RSPO
            $hasParaquat = array_filter($chems, fn($c) => stripos((string)($c['pesticide_name'] ?? ''), 'gramoxone') !== false
                || stripos((string)($c['pesticide_name'] ?? ''), 'paraquat') !== false);
            if (!empty($hasParaquat)) {
                $warns[] = "Produk yang mengandung paraquat (Gramoxone) terdeteksi — dilarang digunakan di kebun bersertifikasi RSPO/HHP.";
            }
            break;

        // ── Plantation Analysis ───────────────────────────────────────────────
        // ── Sustainability Analysis ───────────────────────────────────────────
        case 'sustainability_analysis': {
            $scope         = htmlspecialchars((string)($src['scope']          ?? ''));
            $totalAreaHa   = (float)($src['total_area_ha']    ?? 0);
            $plantedHa     = (float)($src['planted_ha']       ?? 0);
            $nonPlantedHa  = (float)($src['non_planted_ha']   ?? 0);
            $nonPlantedPct = (float)($src['non_planted_pct']  ?? 0);
            $conservHa     = (float)($src['conserv_ha']       ?? 0);
            $conservRatio  = $src['conserv_ratio_pct'] ?? null;
            $waterHa       = (float)($src['water_ha']         ?? 0);
            $swampHa       = (float)($src['swamp_ha']         ?? 0);
            $hasWater      = !empty($src['has_water_data']);
            $carbonTon     = (float)($src['total_carbon_ton'] ?? 0);
            $compRows      = array_map(fn($r) => (array)$r, (array)($src['components'] ?? []));

            if ($totalAreaHa === 0.0) {
                return '<span class="text-muted">Belum ada data area keberlanjutan untuk dianalisis.</span>';
            }

            $lines[] = "Total area HGU: <strong>" . number_format($totalAreaHa, 0, ',', '.') . " ha</strong>, tertanam "
                . "<strong>" . number_format($plantedHa, 0, ',', '.') . " ha</strong> ("
                . number_format($plantedHa / $totalAreaHa * 100, 1) . "%).";

            // Conservation ratio vs ISPO ≥20%
            if ($conservRatio !== null) {
                $std = agro_std_get('conservation_ratio');
                $st  = $std ? agro_std_check((float)$conservRatio, $std) : 'fail';
                if ($st === 'pass') {
                    $lines[] = "Area konservasi <strong>{$conservRatio}%</strong> dari HGU — memenuhi ketentuan ISPO 2020 (≥20%).";
                } elseif ($st === 'warn') {
                    $warns[] = "Area konservasi <strong>{$conservRatio}%</strong> di bawah 20% — risiko temuan audit ISPO. Pertimbangkan designasi kawasan lindung tambahan.";
                } else {
                    $warns[] = "Area konservasi <strong>{$conservRatio}%</strong> sangat kurang (&lt;10%) — tidak memenuhi persyaratan minimum ISPO 2020 dan RSPO P&C 2018.";
                }
            } elseif ($totalAreaHa > 0 && $conservHa === 0.0) {
                $warns[] = "Belum ada area konservasi tercatat (CONSERVATION/WATER/SWAMP = 0 ha). Input kawasan konservasi di Komponen Luas Blok untuk memenuhi ISPO.";
            }

            // Buffer zone — direct width data not in DB
            if ($hasWater) {
                $lines[] = "Area badan air/rawa tercatat: <strong>" . number_format($waterHa + $swampHa, 2) . " ha</strong>. "
                    . "Lebar buffer zone tidak dapat dihitung otomatis — pastikan buffer ≥50 m dari badan air telah diimplementasikan (RSPO P&C 2018).";
            } else {
                $warns[] = "Tidak ada data area badan air (WATER/SWAMP). Input sempadan sungai di Komponen Luas Blok untuk verifikasi kepatuhan buffer zone RSPO.";
            }

            // Carbon stock
            if ($carbonTon > 0) {
                $lines[] = "Stok karbon tercatat: <strong>" . number_format($carbonTon, 1) . " ton C</strong>.";
            } else {
                $lines[] = "Data stok karbon belum diisi di blok. Isi kolom <em>carbon_stock_ton</em> pada manajemen blok.";
            }

            break;
        }

        case 'plantation_analysis': {
            $scope          = htmlspecialchars((string)($src['scope']           ?? ''));
            $totalPlantedHa = (float)($src['total_planted_ha']  ?? 0);
            $totalAreaHa    = (float)($src['total_area_ha']     ?? 0);
            $tmAreaHa       = (float)($src['tm_area_ha']        ?? 0);
            $tmRatioPct     = (float)($src['tm_ratio_pct']      ?? 0);
            $plantedRatio   = (float)($src['planted_ratio_pct'] ?? 0);
            $totalBlocks    = (int)  ($src['total_blocks']      ?? 0);
            $totalDivs      = (int)  ($src['total_divisions']   ?? 0);
            $avgBlockHa     = (float)($src['avg_block_ha']      ?? 0);
            $avgDivHa       = (float)($src['avg_div_ha']        ?? 0);
            $avgSph         = (float)($src['avg_sph']           ?? 0);
            $totalPlants    = (int)  ($src['total_plants']      ?? 0);
            $normalRatio    = $src['normal_ratio_pct']  ?? null;
            $abnRatio       = $src['abnormal_ratio_pct']?? null;
            $deadRatio      = $src['dead_ratio_pct']    ?? null;
            $sisipRatio     = $src['sisip_ratio_pct']   ?? null;
            $avgAbw         = (float)($src['avg_abw']           ?? 0);
            $yieldPerHaTm   = $src['yield_per_ha_tm'] ?? null;
            $totalKg        = (float)($src['total_kg']          ?? 0);
            $harvRecords    = (int)  ($src['harvest_records']   ?? 0);

            if ($totalPlantedHa === 0.0 && $totalBlocks === 0) {
                return '<span class="text-muted">Belum ada data perkebunan untuk dianalisis.</span>';
            }

            $lines[] = "Perkebunan <strong>{$scope}</strong> mencakup <strong>" . number_format($totalPlantedHa, 0, ',', '.') . " ha</strong> tertanam "
                . "dalam <strong>{$totalBlocks}</strong> blok"
                . ($totalDivs > 0 ? " di <strong>{$totalDivs}</strong> divisi/afdeling." : ".");

            // TM ratio
            if ($tmRatioPct > 0) {
                $std = agro_std_get('tm_ratio');
                $st  = $std ? agro_std_check($tmRatioPct, $std) : 'pass';
                if ($st === 'pass') {
                    $lines[] = "Rasio TM: <strong>{$tmRatioPct}%</strong> — komposisi lahan produktif baik (standar GAPKI ≥70%).";
                } elseif ($st === 'warn') {
                    $warns[] = "Rasio TM <strong>{$tmRatioPct}%</strong> di bawah target GAPKI (≥70%) — sebagian besar lahan masih TBM.";
                } else {
                    $warns[] = "Rasio TM <strong>{$tmRatioPct}%</strong> sangat rendah — kebun didominasi TBM, produksi sangat terbatas.";
                }
            }

            // Planted / total ratio
            if ($plantedRatio > 0 && $totalAreaHa > 0) {
                $std = agro_std_get('planted_ratio');
                $st  = $std ? agro_std_check($plantedRatio, $std) : 'pass';
                if ($st !== 'pass') {
                    $warns[] = "Utilisasi lahan <strong>{$plantedRatio}%</strong> — "
                        . ($st === 'warn' ? "di bawah 75%, periksa area belum tertanam." : "sangat rendah (&lt;60%), risiko HGU.");
                }
            }

            // SPH check
            if ($avgSph > 0) {
                $std = agro_std_get('sph_aktual');
                $st  = $std ? agro_std_check($avgSph, $std) : 'pass';
                if ($st === 'pass') {
                    $lines[] = "Kerapatan rata-rata <strong>" . number_format($avgSph, 0) . " pohon/ha</strong> — sesuai standar PPKS (136–148).";
                } elseif ($st === 'warn') {
                    $warns[] = "Kerapatan rata-rata <strong>" . number_format($avgSph, 0) . " pohon/ha</strong> di luar rentang optimal (136–148) — evaluasi program sisip.";
                } else {
                    $warns[] = "Kerapatan rata-rata <strong>" . number_format($avgSph, 0) . " pohon/ha</strong> jauh dari standar — diperlukan program sisip menyeluruh.";
                }
            }

            // Average block size
            if ($avgBlockHa > 0) {
                $std = agro_std_get('block_size');
                $st  = $std ? agro_std_check($avgBlockHa, $std) : 'pass';
                if ($st !== 'pass') {
                    $warns[] = "Rata-rata luas blok <strong>" . number_format($avgBlockHa, 1) . " ha</strong> "
                        . "— " . ($st === 'warn' ? "di luar rentang optimal (20–35 ha)" : "jauh dari standar") . ", pertimbangkan subdivisi blok.";
                }
            }

            // Average division size
            if ($avgDivHa > 0) {
                $std = agro_std_get('division_size');
                $st  = $std ? agro_std_check($avgDivHa, $std) : 'pass';
                if ($st !== 'pass') {
                    $warns[] = "Rata-rata luas afdeling <strong>" . number_format($avgDivHa, 0) . " ha</strong> "
                        . "— " . ($st === 'warn' ? "di luar rentang optimal (300–800 ha)" : "jauh dari ideal") . ", evaluasi organisasi afdeling.";
                }
            }

            // Population ratios
            if ($normalRatio !== null) {
                $std = agro_std_get('normal_plant_ratio');
                $st  = $std ? agro_std_check((float)$normalRatio, $std) : 'pass';
                if ($st === 'pass') {
                    $lines[] = "Proporsi tanaman normal <strong>{$normalRatio}%</strong> — memenuhi standar GAPKI (≥92%).";
                } elseif ($st === 'warn') {
                    $warns[] = "Proporsi tanaman normal <strong>{$normalRatio}%</strong> di bawah 92% — identifikasi penyebab kelainan.";
                } else {
                    $warns[] = "Proporsi tanaman normal <strong>{$normalRatio}%</strong> sangat rendah — audit agronomi menyeluruh diperlukan.";
                }
            }
            if ($deadRatio !== null && (float)$deadRatio > 0) {
                $std = agro_std_get('dead_plant_ratio');
                $st  = $std ? agro_std_check((float)$deadRatio, $std) : 'pass';
                if ($st !== 'pass') {
                    $warns[] = "Tingkat kematian tanaman <strong>{$deadRatio}%</strong> "
                        . ($st === 'warn' ? "di atas 2% — investigasi penyebab (hama, kekeringan)." : "sangat tinggi (&gt;5%) — penanganan darurat diperlukan.");
                }
            }
            if ($sisipRatio !== null && (float)$sisipRatio > 0) {
                $std = agro_std_get('sisip_ratio');
                $st  = $std ? agro_std_check((float)$sisipRatio, $std) : 'pass';
                if ($st !== 'pass') {
                    $warns[] = "Tanaman perlu sisip <strong>{$sisipRatio}%</strong> "
                        . ($st === 'warn' ? "— rencanakan program sisip dalam 1 musim tanam." : "sangat tinggi — program sisip darurat diperlukan.");
                }
            }

            // ABW
            if ($avgAbw > 0) {
                $std = agro_std_get('abw_mature');
                $st  = $std ? agro_std_check($avgAbw, $std) : 'pass';
                if ($st === 'pass') {
                    $lines[] = "ABW rata-rata <strong>" . number_format($avgAbw, 1) . " kg/janjang</strong> — normal untuk TM dewasa (15–25 kg).";
                } else {
                    $warns[] = "ABW rata-rata <strong>" . number_format($avgAbw, 1) . " kg/janjang</strong> "
                        . ($st === 'warn' ? "di luar rentang optimal (15–25 kg) — evaluasi pemupukan." : "sangat di luar standar — cek kecukupan nutrisi dan teknik panen.");
                }
            }

            // Yield per ha TM (as cumulative — note to user if partial year)
            if ($yieldPerHaTm !== null && (float)$yieldPerHaTm > 0) {
                $std = agro_std_get('yield_per_ha_tm');
                $st  = $std ? agro_std_check((float)$yieldPerHaTm, $std) : 'pass';
                if ($st === 'pass') {
                    $lines[] = "Produktivitas kumulatif <strong>" . number_format((float)$yieldPerHaTm, 1) . " ton/ha</strong> per Ha TM.";
                } elseif ($st === 'warn') {
                    $warns[] = "Produktivitas <strong>" . number_format((float)$yieldPerHaTm, 1) . " ton/ha</strong> TM di bawah rata-rata nasional GAPKI (≥20) — evaluasi pemupukan &amp; PHT.";
                }
            } elseif ($harvRecords === 0) {
                $lines[] = "Belum ada data realisasi panen — ketik <em>\"Analisa Panen\"</em> untuk melihat produktivitas TBS.";
            }

            break;
        }

        // ── Weed (Gulma) Analysis ─────────────────────────────────────────────
        case 'weed_analysis': {
            $total     = (int)  ($src['total_records']  ?? 0);
            $highCnt   = (int)  ($src['high_count']     ?? 0);
            $critCnt   = (int)  ($src['critical_count'] ?? 0);
            $totalHa   = (float)($src['total_area_ha']  ?? 0);
            $totalCost = (float)($src['total_cost']     ?? 0);
            $manualCnt = (int)  ($src['manual_count']   ?? 0);
            $sprayCnt  = (int)  ($src['spray_count']    ?? 0);
            $herbCnt   = (int)  ($src['herbicide_count']?? 0);
            $paraquat  = (int)  ($src['paraquat_count'] ?? 0);
            $topWeeds  = array_map(fn($r) => (array)$r, (array)($src['top_weeds']     ?? []));
            $effRows   = array_map(fn($r) => (array)$r, (array)($src['effectiveness'] ?? []));
            $scope     = htmlspecialchars((string)($src['scope'] ?? ''));

            if ($total === 0) {
                return '<span class="text-muted">Belum ada data pengendalian gulma untuk dianalisis.</span>';
            }

            $lines[] = "Total <strong>{$total}</strong> catatan pengendalian gulma di <strong>{$scope}</strong>, mencakup <strong>" . number_format($totalHa, 1) . " ha</strong>.";

            // Severity distribution
            if ($highCnt + $critCnt > 0) {
                $sevPct = round(($highCnt + $critCnt) / $total * 100, 1);
                $warns[] = "<strong>{$sevPct}%</strong> kasus tergolong infestasi Tinggi/Kritis — rotasi pengendalian perlu dipercepat di bawah standar 60 hari (GAPKI 2020).";
            } else {
                $lines[] = "Tingkat keparahan infestasi terkendali — tidak ada kasus Tinggi/Kritis.";
            }

            // Dominant weed species
            if (!empty($topWeeds)) {
                $tw = $topWeeds[0];
                $lines[] = "Gulma dominan: <strong>" . htmlspecialchars((string)($tw['pest_name'] ?? '—'))
                         . "</strong> — " . (int)$tw['record_count'] . " kejadian, cakupan "
                         . number_format((float)($tw['total_area_ha'] ?? 0), 1) . " ha.";
            }

            // Manual vs chemical ratio
            if ($total > 0) {
                $manualPct = round($manualCnt / $total * 100, 0);
                if ($manualPct >= 40) {
                    $lines[] = "Proporsi pengendalian manual: <strong>{$manualPct}%</strong> — kombinasi manual-kimia sehat.";
                } else {
                    $warns[] = "Dominasi herbisida kimia (<strong>" . round($herbCnt/$total*100,0) . "%</strong> aplikasi) — pertimbangkan peningkatan rotasi manual untuk mengurangi resistensi gulma.";
                }
            }

            // Paraquat alert (RSPO non-conformity)
            if ($paraquat > 0) {
                $warns[] = "⚠ <strong>{$paraquat} aplikasi Gramoxone/Paraquat</strong> terdeteksi — Paraquat dilarang di kebun RSPO (Kriteria 4.6) dan masuk daftar HHP. Segera eliminasi dari stok.";
            }

            // Cost
            if ($totalCost > 0) {
                $costPerHa = $totalHa > 0 ? round($totalCost / $totalHa, 0) : 0;
                $lines[] = "Biaya pengendalian: <strong>Rp " . number_format($totalCost, 0, ',', '.') . "</strong>"
                         . ($costPerHa > 0 ? " (Rp " . number_format($costPerHa, 0, ',', '.') . "/ha)" : '') . ".";
            }

            // Effectiveness
            $effTotal = array_sum(array_column($effRows, 'record_count'));
            if ($effTotal > 0) {
                $effGood = 0;
                foreach ($effRows as $e) {
                    $v = strtolower((string)($e['effectiveness'] ?? ''));
                    if (in_array($v, ['good','excellent','baik','sangat baik'], true)) $effGood += (int)$e['record_count'];
                }
                $effPct = round($effGood / $effTotal * 100, 1);
                if ($effPct < 60) {
                    $warns[] = "Efektivitas pengendalian gulma hanya <strong>{$effPct}%</strong> — evaluasi jenis herbisida, dosis, dan timing aplikasi.";
                } else {
                    $lines[] = "Efektivitas pengendalian: <strong>{$effPct}%</strong> perlakuan dinilai Baik/Sangat Baik.";
                }
            }
            break;
        }

        // ── Weed by Division / Block / Planting Year (pivot) — auto-analysis ──
        case 'weed_by_division':
        case 'weed_by_block':
        case 'weed_by_planting_year': {
            $wtypesW   = (array)($src['wtypes']       ?? []);
            $grandTsW  = (array)($src['grand_totals'] ?? []);
            $grandTotW = (int)  ($src['grand_total']  ?? 0);
            $grandHiW  = (int)  ($src['grand_high_sev'] ?? 0);
            $metaW     = (array)($src['meta']         ?? []);
            $dimLabel  = $type === 'weed_by_planting_year' ? 'tahun tanam' : ($type === 'weed_by_division' ? 'divisi' : 'blok');
            $dimCount  = $type === 'weed_by_planting_year' ? (int)($src['year_count'] ?? 0) : ($type === 'weed_by_division' ? (int)($src['div_count'] ?? 0) : (int)($src['block_count'] ?? 0));

            if ($grandTotW <= 0) {
                return '<span class="text-muted">Belum ada data gulma per ' . $dimLabel . ' untuk dianalisis.</span>';
            }

            $lines[] = "Total <strong>" . number_format($grandTotW) . " catatan</strong> pengendalian gulma dari <strong>{$dimCount} {$dimLabel}</strong>.";

            // Top weed species
            $sortedGW = $grandTsW; arsort($sortedGW); reset($sortedGW);
            $topWn = key($sortedGW); $topWcnt = current($sortedGW);
            $lines[] = "Gulma dominan: <strong>" . htmlspecialchars((string)$topWn) . "</strong>"
                     . " — " . number_format($topWcnt) . " catatan"
                     . ($grandTotW > 0 ? " (" . number_format($topWcnt / $grandTotW * 100, 1) . "% dari total)" : '') . ".";

            // High/Critical severity
            if ($grandHiW > 0) {
                $sevPctW = $grandTotW > 0 ? number_format($grandHiW / $grandTotW * 100, 1) : 0;
                $warns[] = "<strong>{$grandHiW}</strong> catatan infestasi <strong>High/Critical</strong>"
                         . " ({$sevPctW}% dari total) — percepat rotasi pengendalian (standar GAPKI: 60 hari).";
            } else {
                $lines[] = "Tidak ditemukan infestasi High/Critical — intensitas gulma terkendali.";
            }

            // Top 3 dimensions with most records
            $dimTotalsW = [];
            foreach ($src['pivot'] ?? [] as $dk => $yd) { $dimTotalsW[$dk] = array_sum((array)$yd); }
            arsort($dimTotalsW);
            $top3W = array_slice($dimTotalsW, 0, 3, true);
            $topDimNamesW = [];
            foreach ($top3W as $dk => $cnt) {
                $dm = (array)($metaW[$dk] ?? []);
                $lbl = $type === 'weed_by_planting_year' ? 'TT ' . htmlspecialchars((string)$dk)
                     : htmlspecialchars((string)($dm['division'] ?? $dm['block_name'] ?? $dk));
                $topDimNamesW[] = '<strong>' . $lbl . '</strong> (' . number_format($cnt) . ' catatan)';
            }
            if (!empty($topDimNamesW)) {
                $lines[] = ucfirst($dimLabel) . " dengan infestasi terbanyak: " . implode(', ', $topDimNamesW) . ".";
            }

            // Species diversity
            $specCountW = count($wtypesW);
            if ($specCountW >= 5) {
                $warns[] = "Terdeteksi <strong>{$specCountW} spesies</strong> gulma — diversitas tinggi, perlu program pengendalian terpadu (PHT Gulma).";
            } elseif ($specCountW >= 2) {
                $lines[] = "Terdeteksi <strong>{$specCountW} spesies</strong> gulma — pantau perkembangan spesies invasif.";
            }
            break;
        }

        // ── Pest & Disease Analysis ───────────────────────────────────────────
        case 'pest_analysis': {
            $total    = (int)  ($src['total_records']  ?? 0);
            $critCnt  = (int)  ($src['critical_count'] ?? 0);
            $highCnt  = (int)  ($src['high_count']     ?? 0);
            $medCnt   = (int)  ($src['medium_count']   ?? 0);
            $lowCnt   = (int)  ($src['low_count']      ?? 0);
            $totalHa  = (float)($src['total_area_ha']  ?? 0);
            $totalCost= (float)($src['total_cost']     ?? 0);
            $byDiv    = array_map(fn($r) => (array)$r, (array)($src['by_division']     ?? []));
            $topPests = array_map(fn($r) => (array)$r, (array)($src['top_pests']       ?? []));
            $effRows  = array_map(fn($r) => (array)$r, (array)($src['effectiveness']   ?? []));

            if ($total === 0) {
                return '<span class="text-muted">Belum ada data hama & penyakit untuk dianalisis.</span>';
            }

            $lines[] = "Total <strong>{$total}</strong> catatan pengendalian hama &amp; penyakit di <strong>{$scope}</strong>.";

            // Severity distribution
            $sevParts = [];
            if ($critCnt > 0) $sevParts[] = "<span style='color:#dc2626'><strong>Critical: {$critCnt}</strong></span>";
            if ($highCnt > 0) $sevParts[] = "<span style='color:#d97706'><strong>High: {$highCnt}</strong></span>";
            if ($medCnt  > 0) $sevParts[] = "<strong>Medium: {$medCnt}</strong>";
            if ($lowCnt  > 0) $sevParts[] = "Low: {$lowCnt}";
            if (!empty($sevParts)) $lines[] = "Distribusi tingkat keparahan: " . implode(', ', $sevParts) . ".";

            if ($critCnt + $highCnt > 0) {
                $sevPct = round(($critCnt + $highCnt) / $total * 100, 1);
                if ($sevPct >= 30) {
                    $warns[] = "<strong>{$sevPct}%</strong> serangan termasuk kategori High/Critical — diperlukan tindakan intensif dan monitoring ketat.";
                } elseif ($sevPct > 0) {
                    $warns[] = "<strong>" . ($critCnt + $highCnt) . "</strong> kasus High/Critical terdeteksi — pastikan tindak lanjut sudah dilakukan.";
                }
            }

            // Most common pest name
            if (!empty($topPests)) {
                $tp = $topPests[0];
                $lines[] = "OPT paling sering dijumpai: <strong>" . htmlspecialchars((string)($tp['pest_name'] ?? '—'))
                         . "</strong> (" . htmlspecialchars((string)($tp['pest_type'] ?? '')) . ") — "
                         . (int)$tp['record_count'] . " kali, cakupan "
                         . number_format((float)($tp['total_area_ha'] ?? 0), 2) . " ha.";
            }

            // Coverage and cost
            if ($totalHa  > 0) $lines[] = "Total area yang ditangani: <strong>" . number_format($totalHa, 2) . " ha</strong>.";
            if ($totalCost > 0) $lines[] = "Total biaya pengendalian: <strong>Rp " . number_format($totalCost, 0, ',', '.') . "</strong>.";

            // Effectiveness
            $effTotal = array_sum(array_column($effRows, 'record_count'));
            if ($effTotal > 0) {
                $effEffective = 0;
                foreach ($effRows as $e) {
                    $v = mb_strtolower((string)($e['effectiveness'] ?? ''));
                    if (str_contains($v, 'effective') || str_contains($v, 'good') || $v === 'high') {
                        $effEffective += (int)$e['record_count'];
                    }
                }
                if ($effEffective > 0) {
                    $effPct = round($effEffective / $effTotal * 100, 1);
                    if ($effPct < 50) {
                        $warns[] = "Efektivitas pengendalian tergolong rendah ({$effPct}% perlakuan dinilai efektif) — evaluasi jenis pestisida dan dosis yang digunakan.";
                    } else {
                        $lines[] = "Efektivitas pengendalian: <strong>{$effPct}%</strong> perlakuan dinilai efektif.";
                    }
                }
            }

            // Hotspot divisions (Critical/High cases)
            $hotspots = array_filter($byDiv, fn($r) => ((int)($r['critical_count'] ?? 0) + (int)($r['high_count'] ?? 0)) > 0);
            if (!empty($hotspots)) {
                $names = implode(', ', array_map(
                    fn($r) => htmlspecialchars((string)$r['division_name']),
                    array_slice($hotspots, 0, 3)
                ));
                $warns[] = "Divisi dengan kasus High/Critical: <strong>{$names}</strong>" . (count($hotspots) > 3 ? ', …' : '') . " — prioritaskan monitoring di wilayah ini.";
            }
            break;
        }

        // ── Fertilization Used ────────────────────────────────────────────────
        case 'fertilization_used':
            $ferts    = array_map(fn($f) => (array)$f, (array)($src['fertilizers'] ?? []));
            $grandQty = (float)($src['grand_qty']  ?? 0);
            $grandApp = (int)  ($src['grand_apps'] ?? 0);
            $n        = count($ferts);
            if ($n === 0) {
                return '<span class="text-muted">Belum ada data pemupukan untuk dianalisis.</span>';
            }
            usort($ferts, fn($a, $b) => (float)($b['total_qty_kg'] ?? 0) <=> (float)($a['total_qty_kg'] ?? 0));
            $top = $ferts[0];

            $lines[] = "Total <strong>{$n} jenis pupuk</strong> digunakan di <strong>{$scope}</strong> dalam <strong>{$grandApp}</strong> kali aplikasi.";
            $lines[] = "Pupuk terbanyak digunakan: <strong>" . htmlspecialchars((string)$top['fertilizer_type'])
                     . ($top['fertilizer_grade'] ? ' (' . htmlspecialchars((string)$top['fertilizer_grade']) . ')' : '')
                     . "</strong> — " . number_format((float)$top['total_qty_kg'], 0) . " kg"
                     . ($grandQty > 0 ? " (" . number_format((float)$top['total_qty_kg'] / $grandQty * 100, 1) . "% dari total)" : '') . ".";

            if ((float)($src['grand_area'] ?? 0) > 0) {
                $kgPerHa = $grandQty / (float)$src['grand_area'];
                $lines[] = "Rata-rata penggunaan pupuk: <strong>" . number_format($kgPerHa, 1) . " kg/ha</strong>.";
            }
            if ((float)($src['grand_cost'] ?? 0) > 0) {
                $lines[] = "Total biaya pemupukan: <strong>Rp " . number_format((float)$src['grand_cost'], 0, ',', '.') . "</strong>.";
            }

            // Group by fertilizer type
            $byType = [];
            foreach ($ferts as $f) {
                $t = (string)($f['fertilizer_type'] ?? 'Lainnya');
                $byType[$t] = ($byType[$t] ?? 0) + (float)$f['total_qty_kg'];
            }
            arsort($byType);
            $typeLines = [];
            foreach ($byType as $t => $qty) {
                $typeLines[] = "<strong>" . htmlspecialchars($t) . "</strong>: " . number_format($qty, 0) . " kg";
            }
            $lines[] = "Komposisi jenis pupuk: " . implode(', ', $typeLines) . ".";

            // Warn if N/K/P/Mg balance looks skewed (K should typically be highest)
            $nQty = 0; $kQty = 0;
            foreach ($ferts as $f) {
                $t = mb_strtolower((string)($f['fertilizer_type'] ?? ''));
                if (str_contains($t, 'urea') || str_contains($t, ' n ') || str_ends_with($t, 'n') || str_contains($t, 'nitrogen')) $nQty += (float)$f['total_qty_kg'];
                if (str_contains($t, 'mop') || str_contains($t, 'kcl') || str_contains($t, 'kalium') || str_contains($t, 'k2o') || str_contains($t, 'potash')) $kQty += (float)$f['total_qty_kg'];
            }
            if ($kQty > 0 && $nQty > 0 && $nQty > $kQty * 1.5) {
                $warns[] = "Penggunaan Nitrogen (N) jauh melebihi Kalium (K) — rekomendasi PPKS: K adalah hara terbesar untuk kelapa sawit.";
            }
            break;

        // ── Fertilization by Block (pivot) ───────────────────────────────────
        case 'fertilization_by_block':
            $ftypes     = (array)($src['ftypes']      ?? []);
            $pivot      = (array)($src['pivot']       ?? []);
            $grandTots  = (array)($src['grand_totals']?? []);
            $grandTotal = (float)($src['grand_total'] ?? 0);
            $blockCount = (int)  ($src['block_count'] ?? 0);
            if ($grandTotal <= 0) {
                return '<span class="text-muted">Belum ada data pemupukan per blok untuk dianalisis.</span>';
            }
            // Top fertilizer by volume
            arsort($grandTots);
            reset($grandTots);
            $topFt  = key($grandTots);
            $topKg  = current($grandTots);
            $lines[] = "Total pupuk yang diaplikasikan: <strong>" . number_format($grandTotal, 0) . " Kg</strong>"
                     . " dari <strong>{$blockCount} blok</strong>.";
            $lines[] = "Jenis pupuk terbanyak: <strong>" . htmlspecialchars((string)$topFt) . "</strong>"
                     . " — " . number_format($topKg, 0) . " Kg"
                     . ($grandTotal > 0 ? " (" . number_format($topKg / $grandTotal * 100, 1) . "% dari total)" : '') . ".";

            // Blocks with highest total fertilizer usage
            $blockTotals = [];
            foreach ($pivot as $bkey => $bdata) {
                $blockTotals[$bkey] = array_sum((array)$bdata);
            }
            arsort($blockTotals);
            $meta = (array)($src['meta'] ?? []);
            $top3 = array_slice($blockTotals, 0, 3, true);
            $topNames = [];
            foreach ($top3 as $bk => $kg) {
                $bm = (array)($meta[$bk] ?? []);
                $topNames[] = '<strong>' . htmlspecialchars((string)($bm['block_name'] ?? $bk)) . '</strong> (' . number_format($kg, 0) . ' Kg)';
            }
            if (!empty($topNames)) {
                $lines[] = "Blok dengan penggunaan pupuk terbanyak: " . implode(', ', $topNames) . ".";
            }

            // Diversity: how many fertilizer types are used
            $fCount = count($ftypes);
            if ($fCount >= 4) {
                $lines[] = "Program pemupukan menggunakan <strong>{$fCount} jenis pupuk</strong> — cukup lengkap mencakup unsur makro dan mikro.";
            } elseif ($fCount <= 2) {
                $warns[] = "Hanya <strong>{$fCount} jenis pupuk</strong> yang digunakan — pertimbangkan penambahan unsur hara lain sesuai rekomendasi PPKS.";
            }

            // Check N/K balance
            $nKg = 0; $kKg = 0;
            foreach ($grandTots as $ft => $kg) {
                $tl = mb_strtolower((string)$ft);
                if (str_contains($tl, 'urea') || str_contains($tl, 'nitrogen')) $nKg += (float)$kg;
                if (str_contains($tl, 'mop') || str_contains($tl, 'kcl') || str_contains($tl, 'kalium') || str_contains($tl, 'potash')) $kKg += (float)$kg;
            }
            if ($kKg > 0 && $nKg > 0 && $nKg > $kKg * 1.5) {
                $warns[] = "Penggunaan Nitrogen (N) jauh melebihi Kalium (K) — rekomendasi PPKS: K adalah hara terbesar untuk kelapa sawit.";
            }
            break;

        // ── Fertilization by Division (pivot) ─────────────────────────────────
        case 'fertilization_by_division': {
            $ftypes     = (array)($src['ftypes']      ?? []);
            $grandTots  = (array)($src['grand_totals']?? []);
            $grandTotal = (float)($src['grand_total'] ?? 0);
            $divCount   = (int)  ($src['div_count']   ?? 0);
            $metaD      = (array)($src['meta']        ?? []);
            if ($grandTotal <= 0) {
                return '<span class="text-muted">Belum ada data pemupukan per divisi untuk dianalisis.</span>';
            }

            // Top fertilizer by volume
            arsort($grandTots);
            reset($grandTots);
            $topFt = key($grandTots);
            $topKg = current($grandTots);

            $lines[] = "Total pupuk yang diaplikasikan: <strong>" . number_format($grandTotal, 0) . " Kg</strong>"
                     . " mencakup <strong>{$divCount} divisi</strong>.";
            $lines[] = "Jenis pupuk terbanyak: <strong>" . htmlspecialchars((string)$topFt) . "</strong>"
                     . " — " . number_format($topKg, 0) . " Kg"
                     . ($grandTotal > 0 ? " (" . number_format($topKg / $grandTotal * 100, 1) . "% dari total)" : '') . ".";

            // Divisions with highest total fertilizer usage
            $divTotals = [];
            foreach ($src['pivot'] ?? [] as $dkey => $ddata) {
                $divTotals[$dkey] = array_sum((array)$ddata);
            }
            arsort($divTotals);
            $top3 = array_slice($divTotals, 0, 3, true);
            $topDivNames = [];
            foreach ($top3 as $dk => $kg) {
                $dm = (array)($metaD[$dk] ?? []);
                $topDivNames[] = '<strong>' . htmlspecialchars((string)($dm['division'] ?? $dk)) . '</strong> (' . number_format($kg, 0) . ' Kg)';
            }
            if (!empty($topDivNames)) {
                $lines[] = "Divisi dengan penggunaan pupuk terbanyak: " . implode(', ', $topDivNames) . ".";
            }

            // Fertilizer type diversity
            $fCount = count($ftypes);
            if ($fCount >= 4) {
                $lines[] = "Program pemupukan menggunakan <strong>{$fCount} jenis pupuk</strong> — cukup lengkap mencakup unsur makro dan mikro.";
            } elseif ($fCount <= 2) {
                $warns[] = "Hanya <strong>{$fCount} jenis pupuk</strong> yang digunakan — pertimbangkan penambahan unsur hara lain sesuai rekomendasi PPKS.";
            }

            // N/K balance check
            $nKgD = 0.0; $kKgD = 0.0;
            foreach ($grandTots as $ft => $kg) {
                $tl = mb_strtolower((string)$ft);
                if (str_contains($tl, 'urea') || str_contains($tl, 'nitrogen')) $nKgD += (float)$kg;
                if (str_contains($tl, 'mop') || str_contains($tl, 'kcl') || str_contains($tl, 'kalium') || str_contains($tl, 'potash')) $kKgD += (float)$kg;
            }
            if ($kKgD > 0 && $nKgD > 0 && $nKgD > $kKgD * 1.5) {
                $warns[] = "Penggunaan Nitrogen (N) jauh melebihi Kalium (K) — rekomendasi PPKS: K adalah hara terbesar untuk kelapa sawit.";
            }

            // Cost and coverage if available
            $totalHaAll = array_sum(array_column($metaD, 'total_ha'));
            $totalCostAll = array_sum(array_column($metaD, 'total_cost'));
            if ($totalHaAll > 0 && $grandTotal > 0) {
                $lines[] = "Rata-rata penggunaan pupuk: <strong>" . number_format($grandTotal / $totalHaAll, 1) . " Kg/ha</strong>.";
            }
            if ($totalCostAll > 0) {
                $lines[] = "Total biaya pemupukan: <strong>Rp " . number_format($totalCostAll, 0, ',', '.') . "</strong>.";
            }
            break;
        }

        // ── Fertilization by Planting Year (pivot) ───────────────────────────
        case 'fertilization_by_planting_year': {
            $ftypesPY   = (array)($src['ftypes']      ?? []);
            $grandTsPY  = (array)($src['grand_totals']?? []);
            $grandTotPY = (float)($src['grand_total'] ?? 0);
            $yearCntPY  = (int)  ($src['year_count']  ?? 0);
            $metaPY     = (array)($src['meta']        ?? []);
            if ($grandTotPY <= 0) {
                return '<span class="text-muted">Belum ada data pemupukan per tahun tanam untuk dianalisis.</span>';
            }

            // Top fertilizer by volume
            arsort($grandTsPY);
            reset($grandTsPY);
            $topFtPY = key($grandTsPY);
            $topKgPY = current($grandTsPY);

            $lines[] = "Total pupuk yang diaplikasikan: <strong>" . number_format($grandTotPY, 0) . " Kg</strong>"
                     . " mencakup <strong>{$yearCntPY} tahun tanam</strong>.";
            $lines[] = "Jenis pupuk terbanyak: <strong>" . htmlspecialchars((string)$topFtPY) . "</strong>"
                     . " — " . number_format($topKgPY, 0) . " Kg"
                     . ($grandTotPY > 0 ? " (" . number_format($topKgPY / $grandTotPY * 100, 1) . "% dari total)" : '') . ".";

            // Planting years with highest fertilizer usage
            $yrTotals = [];
            foreach ($src['pivot'] ?? [] as $yr => $ydata) {
                $yrTotals[$yr] = array_sum((array)$ydata);
            }
            arsort($yrTotals);
            $top3PY = array_slice($yrTotals, 0, 3, true);
            $topYrNames = [];
            foreach ($top3PY as $yr => $kg) {
                $topYrNames[] = '<strong>TT ' . htmlspecialchars((string)$yr) . '</strong> (' . number_format($kg, 0) . ' Kg)';
            }
            if (!empty($topYrNames)) {
                $lines[] = "Tahun tanam dengan pupuk terbanyak: " . implode(', ', $topYrNames) . ".";
            }

            // Young vs old planting years — N-heavy for young, K-heavy for mature
            $sortedYrs = array_keys($yrTotals);
            sort($sortedYrs);
            if (count($sortedYrs) >= 2) {
                $youngest = min($sortedYrs);
                $oldest   = max($sortedYrs);
                $age = date('Y') - $oldest;
                if ($age >= 4) {
                    $lines[] = "Tanaman tertua (TT {$oldest}, ±{$age} tahun) termasuk kategori TM — butuh dosis K dan Mg lebih tinggi sesuai rekomendasi PPKS.";
                }
                $ageY = date('Y') - $youngest;
                if ($ageY <= 3) {
                    $lines[] = "Tanaman termuda (TT {$youngest}, ±{$ageY} tahun) masih TBM — dosis pupuk lebih ringan, fokus N dan P.";
                }
            }

            // Fertilizer diversity
            $fCntPY = count($ftypesPY);
            if ($fCntPY >= 4) {
                $lines[] = "Program pemupukan menggunakan <strong>{$fCntPY} jenis pupuk</strong> — cukup lengkap mencakup unsur makro dan mikro.";
            } elseif ($fCntPY <= 2) {
                $warns[] = "Hanya <strong>{$fCntPY} jenis pupuk</strong> yang digunakan — pertimbangkan penambahan unsur hara lain sesuai rekomendasi PPKS.";
            }

            // N/K balance
            $nKgPY = 0.0; $kKgPY = 0.0;
            foreach ($grandTsPY as $ft => $kg) {
                $tl = mb_strtolower((string)$ft);
                if (str_contains($tl, 'urea') || str_contains($tl, 'nitrogen')) $nKgPY += (float)$kg;
                if (str_contains($tl, 'mop') || str_contains($tl, 'kcl') || str_contains($tl, 'kalium') || str_contains($tl, 'potash')) $kKgPY += (float)$kg;
            }
            if ($kKgPY > 0 && $nKgPY > 0 && $nKgPY > $kKgPY * 1.5) {
                $warns[] = "Penggunaan Nitrogen (N) jauh melebihi Kalium (K) — rekomendasi PPKS: K adalah hara terbesar untuk kelapa sawit.";
            }

            // Cost and Kg/ha
            $totalHaAllPY2  = array_sum(array_column($metaPY, 'total_ha'));
            $totalCostAllPY2 = array_sum(array_column($metaPY, 'total_cost'));
            if ($totalHaAllPY2 > 0 && $grandTotPY > 0) {
                $lines[] = "Rata-rata penggunaan pupuk: <strong>" . number_format($grandTotPY / $totalHaAllPY2, 1) . " Kg/ha</strong>.";
            }
            if ($totalCostAllPY2 > 0) {
                $lines[] = "Total biaya pemupukan: <strong>Rp " . number_format($totalCostAllPY2, 0, ',', '.') . "</strong>.";
            }
            break;
        }

        // ── Harvest + Transport ───────────────────────────────────────────────
        case 'harvest_transport':
            $grandHKg  = (float)($src['grand_harvest_kg']  ?? 0);
            $grandDKg  = (float)($src['grand_deliv_kg']    ?? 0);
            $grandBun  = (int)  ($src['grand_bunches']     ?? 0);
            $grandHCnt = (int)  ($src['grand_harvest_cnt'] ?? 0);
            $grandDCnt = (int)  ($src['grand_deliv_cnt']   ?? 0);
            $rejected  = (int)  ($src['grand_rejected']    ?? 0);
            $unloaded  = (int)  ($src['grand_unloaded']    ?? 0);
            $ratio     = $src['transport_ratio'] ?? null;
            $abw       = $src['avg_abw']         ?? null;

            if ($grandHKg === 0.0 && $grandDKg === 0.0) {
                return '<span class="text-muted">Belum ada data panen atau pengangkutan untuk dianalisis.</span>';
            }

            $lines[] = "Total panen <strong>{$scope}</strong>: <strong>" . agro_fmt_kg($grandHKg) . "</strong> dari {$grandHCnt} realisasi.";
            if ($grandBun > 0) {
                $lines[] = "Total tandan: <strong>" . number_format($grandBun) . " janjang</strong>.";
            }
            if ($abw !== null) {
                $lines[] = "Rata-rata berat janjang (ABW): <strong>" . number_format((float)$abw, 2) . " kg/janjang</strong>.";
            }
            if ($grandDKg > 0) {
                $lines[] = "Total TBS terkirim ke pabrik: <strong>" . agro_fmt_kg($grandDKg) . "</strong> dalam {$grandDCnt} pengiriman.";
                if ($ratio !== null) {
                    $effLabel = $ratio >= 95 ? 'baik ✅' : ($ratio >= 85 ? 'perlu perhatian ⚠️' : 'rendah ❌');
                    $lines[] = "Efisiensi pengangkutan (terkirim / dipanen): <strong>{$ratio}%</strong> — {$effLabel}.";
                }
                if ($unloaded > 0 || $grandDCnt > 0) {
                    $unloadPct = $grandDCnt > 0 ? number_format($unloaded / $grandDCnt * 100, 1) . '%' : '—';
                    $lines[] = "Pengiriman selesai (Unloaded): <strong>{$unloaded}</strong> dari {$grandDCnt} ({$unloadPct}).";
                }
                if ($rejected > 0) {
                    $warns[] = "Terdapat <strong>{$rejected}</strong> pengiriman ditolak (Rejected) — periksa kualitas TBS dan kondisi pengangkutan.";
                }
            } else {
                $warns[] = "Belum ada data pengangkutan (FFB Delivery) yang terhubung dengan panen ini.";
            }

            // Grade distribution
            $grades = array_map(fn($g) => (array)$g, (array)($src['grade_breakdown'] ?? []));
            if (!empty($grades)) {
                $topGrade = $grades[0];
                $grandGKg = array_sum(array_column($grades, 'total_kg'));
                $pct = $grandGKg > 0 ? number_format((float)$topGrade['total_kg'] / $grandGKg * 100, 1) . '%' : '—';
                $lines[] = "Grade dominan pengiriman: <strong>" . htmlspecialchars((string)$topGrade['quality_grade']) . "</strong> ({$pct} dari total TBS terkirim).";
            }
            break;

        // ── Nursery / Pembibitan ──────────────────────────────────────────────
        case 'nursery_summary':
            $seeds    = (int)($src['total_seeds']   ?? 0);
            $sprouts  = (int)($src['total_sprouts'] ?? 0);
            $polybag  = (int)($src['total_polybag'] ?? 0);
            $ready    = (int)($src['total_ready']   ?? 0);
            $germRate = $src['germ_rate']      !== null ? (float)$src['germ_rate']      : null;
            $preRate  = $src['pre_nurs_rate']  !== null ? (float)$src['pre_nurs_rate']  : null;
            $mainRate = $src['main_nurs_rate'] !== null ? (float)$src['main_nurs_rate'] : null;

            if ($seeds === 0 && $sprouts === 0) {
                return '<span class="text-muted">Belum ada data pembibitan untuk dianalisis.</span>';
            }

            $lines[] = "Total benih: <strong>" . number_format($seeds) . " benih</strong> dalam " . (int)($src['batches'] ?? 0) . " batch.";
            if ($sprouts > 0) $lines[] = "Kecambah berhasil: <strong>" . number_format($sprouts) . "</strong>.";
            if ($polybag > 0) $lines[] = "Bibit di polybag: <strong>" . number_format($polybag) . "</strong>.";
            if ($ready   > 0) $lines[] = "Siap salur ke lapangan: <strong>" . number_format($ready) . "</strong>.";

            // Germination rate vs SNI 8171:2015 (pass ≥80%, warn ≥70%)
            if ($germRate !== null) {
                $lines[] = "Daya kecambah: <strong>" . number_format($germRate, 1) . "%</strong>.";
                if ($germRate < 70.0) {
                    $warns[] = "Daya kecambah <strong>" . number_format($germRate, 1) . "%</strong> di bawah batas minimum SNI 8171:2015 (≥70%) — evaluasi kualitas benih dan kondisi penyimpanan.";
                } elseif ($germRate < 80.0) {
                    $warns[] = "Daya kecambah <strong>" . number_format($germRate, 1) . "%</strong> di bawah target SNI 8171:2015 (≥80%) — periksa lot benih dan teknik perkecambahan.";
                } else {
                    $lines[] = "Daya kecambah <strong>" . number_format($germRate, 1) . "%</strong> memenuhi standar SNI 8171:2015 (≥80%) ✅.";
                }
            }

            // Pre-nursery survival (pass ≥90%, warn ≥80%)
            if ($preRate !== null) {
                $lines[] = "Daya hidup pre-nursery (benih→polybag): <strong>" . number_format($preRate, 1) . "%</strong>.";
                if ($preRate < 80.0) {
                    $warns[] = "Daya hidup pre-nursery <strong>" . number_format($preRate, 1) . "%</strong> di bawah minimum PPKS (≥80%) — periksa media tanam, penyiraman, dan penaungan.";
                } elseif ($preRate < 90.0) {
                    $warns[] = "Daya hidup pre-nursery <strong>" . number_format($preRate, 1) . "%</strong> di bawah target PPKS (≥90%) — evaluasi prosedur pre-nursery.";
                } else {
                    $lines[] = "Daya hidup pre-nursery <strong>" . number_format($preRate, 1) . "%</strong> memenuhi standar PPKS (≥90%) ✅.";
                }
            }

            // Main-nursery survival (pass ≥90%, warn ≥82%)
            if ($mainRate !== null) {
                $lines[] = "Daya hidup main-nursery (polybag→siap salur): <strong>" . number_format($mainRate, 1) . "%</strong>.";
                if ($mainRate < 82.0) {
                    $warns[] = "Daya hidup main-nursery <strong>" . number_format($mainRate, 1) . "%</strong> di bawah minimum PPKS (≥82%) — identifikasi penyakit bibit (busuk pangkal, blight) dan evaluasi input.";
                } elseif ($mainRate < 90.0) {
                    $warns[] = "Daya hidup main-nursery <strong>" . number_format($mainRate, 1) . "%</strong> di bawah target PPKS (≥90%) — audit prosedur pembibitan dan fungisida.";
                } else {
                    $lines[] = "Daya hidup main-nursery <strong>" . number_format($mainRate, 1) . "%</strong> memenuhi standar PPKS (≥90%) ✅.";
                }
            }
            break;

        // ── Nursery by Batch ──────────────────────────────────────────────────
        case 'nursery_by_batch': {
            $batches = array_map(fn($b) => (array)$b, (array)($src['batches'] ?? []));
            $totals  = (array)($src['totals'] ?? []);
            $count   = (int)($src['count'] ?? 0);
            if ($count === 0 || empty($totals['seeds'])) {
                return '<span class="text-muted">Belum ada data pembibitan per batch untuk dianalisis.</span>';
            }

            $gtSeeds  = (int)  ($totals['seeds']   ?? 0);
            $gtReady  = (int)  ($totals['ready']   ?? 0);
            $gRate    = $totals['germ_rate']  !== null ? (float)$totals['germ_rate']  : null;
            $pRate    = $totals['pre_rate']   !== null ? (float)$totals['pre_rate']   : null;
            $mRate    = $totals['main_rate']  !== null ? (float)$totals['main_rate']  : null;

            $lines[] = "Total <strong>{$count} batch</strong> pembibitan dengan <strong>" . number_format($gtSeeds) . " benih</strong>.";
            if ($gtReady > 0) {
                $lines[] = "Bibit siap salur ke lapangan: <strong>" . number_format($gtReady) . "</strong>.";
            }

            // Germination rate vs SNI 8171:2015
            if ($gRate !== null) {
                if ($gRate < 70.0) {
                    $warns[] = "Daya kecambah rata-rata <strong>" . number_format($gRate, 1) . "%</strong> di bawah batas minimum SNI 8171:2015 (≥70%) — evaluasi kualitas benih dan kondisi penyimpanan.";
                } elseif ($gRate < 80.0) {
                    $warns[] = "Daya kecambah rata-rata <strong>" . number_format($gRate, 1) . "%</strong> di bawah target SNI 8171:2015 (≥80%) — periksa lot benih dan teknik perkecambahan.";
                } else {
                    $lines[] = "Daya kecambah rata-rata <strong>" . number_format($gRate, 1) . "%</strong> memenuhi standar SNI 8171:2015 (≥80%) ✅.";
                }
            }

            // Find best & worst germination batch
            $sortedGerm = array_filter($batches, fn($b) => $b['germ_rate'] !== null);
            if (count($sortedGerm) >= 2) {
                usort($sortedGerm, fn($a, $b) => (float)$b['germ_rate'] <=> (float)$a['germ_rate']);
                $best  = $sortedGerm[0];
                $worst = end($sortedGerm);
                $lines[] = "Batch terbaik (daya kecambah): <strong>" . htmlspecialchars((string)$best['batch_number']) . "</strong>"
                         . " — " . number_format((float)$best['germ_rate'], 1) . "%.";
                if ((float)$worst['germ_rate'] < 70.0) {
                    $warns[] = "Batch terendah: <strong>" . htmlspecialchars((string)$worst['batch_number']) . "</strong>"
                             . " — daya kecambah hanya " . number_format((float)$worst['germ_rate'], 1) . "% (di bawah SNI minimum).";
                }
            }

            // Pre-nursery survival
            if ($pRate !== null) {
                if ($pRate < 80.0) {
                    $warns[] = "Daya hidup pre-nursery rata-rata <strong>" . number_format($pRate, 1) . "%</strong> di bawah minimum PPKS (≥80%) — periksa media tanam dan penaungan.";
                } elseif ($pRate < 90.0) {
                    $warns[] = "Daya hidup pre-nursery rata-rata <strong>" . number_format($pRate, 1) . "%</strong> di bawah target PPKS (≥90%).";
                } else {
                    $lines[] = "Daya hidup pre-nursery rata-rata <strong>" . number_format($pRate, 1) . "%</strong> memenuhi standar PPKS (≥90%) ✅.";
                }
            }

            // Main-nursery survival
            if ($mRate !== null) {
                if ($mRate < 82.0) {
                    $warns[] = "Daya hidup main-nursery rata-rata <strong>" . number_format($mRate, 1) . "%</strong> di bawah minimum PPKS (≥82%) — evaluasi fungisida dan prosedur pembibitan.";
                } elseif ($mRate < 90.0) {
                    $warns[] = "Daya hidup main-nursery rata-rata <strong>" . number_format($mRate, 1) . "%</strong> di bawah target PPKS (≥90%).";
                } else {
                    $lines[] = "Daya hidup main-nursery rata-rata <strong>" . number_format($mRate, 1) . "%</strong> memenuhi standar PPKS (≥90%) ✅.";
                }
            }

            // Status breakdown
            $statusCounts = [];
            foreach ($batches as $b) {
                $st = (string)($b['status'] ?? 'Unknown');
                $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
            }
            if (!empty($statusCounts)) {
                $stParts = [];
                foreach ($statusCounts as $st => $cnt) { $stParts[] = "<strong>{$cnt}</strong> {$st}"; }
                $lines[] = "Status batch: " . implode(', ', $stParts) . ".";
            }
            break;
        }

        // ── Rendemen CPO & PK ─────────────────────────────────────────────────
        case 'rendemen':
            $ffbKg    = (float)($src['ffb_kg']     ?? 0);
            $cpoKg    = (float)($src['cpo_kg']     ?? 0);
            $kerKg    = (float)($src['kernel_kg']  ?? 0);
            $oer      = (float)($src['oer']        ?? ($ffbKg > 0 ? $cpoKg / $ffbKg * 100 : 0));
            $ker      = (float)($src['ker']        ?? ($ffbKg > 0 ? $kerKg  / $ffbKg * 100 : 0));
            $minOer   = (float)($src['min_oer']    ?? 0);
            $maxOer   = (float)($src['max_oer']    ?? 0);
            $minKer   = (float)($src['min_ker']    ?? 0);
            $maxKer   = (float)($src['max_ker']    ?? 0);
            $avgFfa   = isset($src['avg_ffa'])      && $src['avg_ffa']      !== null ? (float)$src['avg_ffa']      : null;
            $avgMoist = isset($src['avg_moisture']) && $src['avg_moisture']  !== null ? (float)$src['avg_moisture'] : null;

            if ($ffbKg === 0.0) {
                return '<span class="text-muted">Belum ada data produksi mill untuk dianalisis.</span>';
            }

            $lines[] = "Total FFB diproses: <strong>" . agro_fmt_kg($ffbKg) . "</strong>.";
            $lines[] = "CPO diproduksi: <strong>" . agro_fmt_kg($cpoKg) . "</strong> — "
                     . "Rendemen CPO (OER): <strong>" . number_format($oer, 2) . "%</strong>.";
            $lines[] = "Palm Kernel diproduksi: <strong>" . agro_fmt_kg($kerKg) . "</strong> — "
                     . "Rendemen PK (KER): <strong>" . number_format($ker, 2) . "%</strong>.";

            // OER evaluation vs PPKS standard (pass ≥22%, warn ≥20%)
            if ($oer < 20.0) {
                $warns[] = "OER <strong>" . number_format($oer, 2) . "%</strong> di bawah standar minimum PPKS/GAPKI (≥20%) — perlu investigasi kualitas TBS dan efisiensi ekstraksi.";
            } elseif ($oer < 22.0) {
                $warns[] = "OER <strong>" . number_format($oer, 2) . "%</strong> di bawah target optimal PPKS/GAPKI (≥22%) — ada ruang peningkatan proses.";
            } else {
                $lines[] = "OER <strong>" . number_format($oer, 2) . "%</strong> memenuhi target PPKS/GAPKI (≥22%) ✅.";
            }

            // KER evaluation vs standard (pass ≥4.5%, warn ≥4.0%)
            if ($ker < 4.0) {
                $warns[] = "KER <strong>" . number_format($ker, 2) . "%</strong> di bawah standar minimum GAPKI (≥4%) — periksa efisiensi pemipilan dan pemisahan inti.";
            } elseif ($ker < 4.5) {
                $warns[] = "KER <strong>" . number_format($ker, 2) . "%</strong> di bawah target GAPKI (≥4.5%) — evaluasi proses kernel.";
            } else {
                $lines[] = "KER <strong>" . number_format($ker, 2) . "%</strong> memenuhi target GAPKI (≥4.5%) ✅.";
            }

            // FFA evaluation vs SNI 7182:2015 (pass ≤3.5%, warn ≤5.0%)
            if ($avgFfa !== null) {
                $lines[] = "Kadar FFA rata-rata: <strong>" . number_format($avgFfa, 2) . "%</strong>.";
                if ($avgFfa > 5.0) {
                    $warns[] = "FFA <strong>" . number_format($avgFfa, 2) . "%</strong> jauh di atas batas SNI 7182:2015 (≤3.5%) — penalti harga dan risiko penolakan pembeli, percepat pengolahan buah.";
                } elseif ($avgFfa > 3.5) {
                    $warns[] = "FFA <strong>" . number_format($avgFfa, 2) . "%</strong> melebihi batas SNI 7182:2015 (≤3.5%) — perbaiki kematangan panen dan kurangi waktu antri TBS.";
                } else {
                    $lines[] = "FFA <strong>" . number_format($avgFfa, 2) . "%</strong> memenuhi standar SNI 7182:2015 (≤3.5%) ✅.";
                }
            }

            // Moisture evaluation vs SNI 7182:2015 (pass ≤0.15%, warn ≤0.25%)
            if ($avgMoist !== null) {
                $lines[] = "Kadar air CPO rata-rata: <strong>" . number_format($avgMoist, 3) . "%</strong>.";
                if ($avgMoist > 0.25) {
                    $warns[] = "Kadar air <strong>" . number_format($avgMoist, 3) . "%</strong> jauh di atas SNI 7182:2015 (≤0.15%) — perbaiki proses pengeringan segera.";
                } elseif ($avgMoist > 0.15) {
                    $warns[] = "Kadar air <strong>" . number_format($avgMoist, 3) . "%</strong> di atas batas SNI 7182:2015 (≤0.15%) — evaluasi proses vacuum drying.";
                } else {
                    $lines[] = "Kadar air <strong>" . number_format($avgMoist, 3) . "%</strong> memenuhi SNI 7182:2015 (≤0.15%) ✅.";
                }
            }

            // Variability warnings
            if ($maxOer > 0 && ($maxOer - $minOer) > 2.0) {
                $warns[] = "Rentang OER cukup lebar: " . number_format($minOer, 2) . "% – " . number_format($maxOer, 2)
                         . "% (selisih " . number_format($maxOer - $minOer, 2) . "%) — konsistensi proses perlu dijaga.";
            }
            if ($maxKer > 0 && ($maxKer - $minKer) > 1.0) {
                $warns[] = "Rentang KER: " . number_format($minKer, 2) . "% – " . number_format($maxKer, 2)
                         . "% — perlu konsistensi proses kernel.";
            }

            // ── Kernel stock insights ──────────────────────────────────────
            $ks = (array)($src['kernel_stock'] ?? []);
            if (!empty($ks)) {
                $ksIn        = (float)($ks['total_in']      ?? 0);
                $ksOut       = (float)($ks['total_out']     ?? 0);
                $ksCurrent   = (float)($ks['current_stock'] ?? 0);
                $ksByStorage = array_map(fn($r) => (array)$r, (array)($ks['by_storage'] ?? []));

                if ($ksIn > 0 || $ksCurrent > 0) {
                    $lines[] = "🌰 <strong>Stok Kernel:</strong> masuk <strong>" . agro_fmt_kg($ksIn) . "</strong>, keluar <strong>" . agro_fmt_kg($ksOut) . "</strong>, stok saat ini <strong>" . agro_fmt_kg($ksCurrent) . "</strong>.";
                }

                // Kernel penerimaan gudang vs produksi
                if ($kerKg > 0 && $ksIn > 0) {
                    $absorbPct = round($ksIn / $kerKg * 100, 1);
                    $lines[] = "Rasio masuk gudang vs kernel diproduksi: <strong>{$absorbPct}%</strong>"
                             . ($absorbPct < 90.0 ? ' — sebagian kernel mungkin langsung dijual atau belum tercatat.' : ' ✅');
                }

                // Per-storage utilization checks
                $highUtil = [];
                $totalCap = 0.0;
                foreach ($ksByStorage as $ksRow) {
                    $cap  = (float)($ksRow['capacity_kg']     ?? 0);
                    $stk  = (float)($ksRow['current_stock_kg'] ?? 0);
                    $totalCap += $cap;
                    if ($cap > 0 && ($stk / $cap) >= 0.90) {
                        $highUtil[] = htmlspecialchars((string)($ksRow['storage_code'] ?? ''));
                    }
                }
                if (!empty($highUtil)) {
                    $warns[] = "Kapasitas gudang kernel mendekati penuh (≥90%): <strong>" . implode(', ', $highUtil) . "</strong> — pertimbangkan penjualan atau penambahan kapasitas.";
                }

                if ($totalCap > 0) {
                    $overallUtil = round($ksCurrent / $totalCap * 100, 1);
                    $lines[] = "Utilisasi gudang kernel keseluruhan: <strong>{$overallUtil}%</strong> dari total kapasitas " . agro_fmt_kg($totalCap) . ".";
                    if ($overallUtil < 20.0 && $ksCurrent > 0) {
                        $warns[] = "Utilisasi gudang kernel sangat rendah ({$overallUtil}%) — pastikan data transaksi sudah lengkap.";
                    }
                }
            }
            break;

        // ── Financial Summary ─────────────────────────────────────────────────
        case 'financial_summary':
            $rev   = (float)($src['revenue']      ?? 0);
            $gp    = (float)($src['gross_profit']  ?? 0);
            $opPrf = (float)($src['op_profit']     ?? 0);
            $net   = (float)($src['net_profit']    ?? 0);
            $assets= (float)($src['total_assets']  ?? 0);
            $liab  = (float)($src['total_liab']    ?? 0);
            $eq    = (float)($src['total_equity']  ?? 0);
            $gm    = $src['gross_margin']  ?? null;
            $opm   = $src['op_margin']     ?? null;
            $nm    = $src['net_margin']    ?? null;
            $cr    = $src['current_ratio'] ?? null;
            $der   = $src['de_ratio']      ?? null;
            $roa   = $src['roa']           ?? null;
            $roe   = $src['roe']           ?? null;
            $dl    = htmlspecialchars((string)($src['date_label'] ?? ''));

            if ($rev == 0 && $assets == 0) {
                return '<span class="text-muted">Belum ada data jurnal keuangan untuk dianalisis.</span>';
            }

            $rpFmt = fn(float $v): string => ($v < 0 ? 'Rp -' : 'Rp ') . number_format(abs($v), 0, ',', '.');

            if ($rev > 0) {
                $lines[] = "Pendapatan (<em>{$dl}</em>): <strong>{$rpFmt($rev)}</strong>.";
                $lines[] = "Laba Kotor: <strong>{$rpFmt($gp)}</strong>" . ($gm !== null ? " (Gross Margin: <strong>" . number_format((float)$gm, 1) . "%</strong>)" : "") . ".";
                $lines[] = "Laba Operasional: <strong>{$rpFmt($opPrf)}</strong>" . ($opm !== null ? " (Op. Margin: " . number_format((float)$opm, 1) . "%)" : "") . ".";
                $lines[] = "Laba Bersih: <strong>{$rpFmt($net)}</strong>" . ($nm !== null ? " (Net Margin: <strong>" . number_format((float)$nm, 1) . "%</strong>)" : "") . ".";
            }
            if ($assets > 0) {
                $lines[] = "Total Aset: <strong>{$rpFmt($assets)}</strong> | Liabilitas: <strong>{$rpFmt($liab)}</strong> | Ekuitas: <strong>{$rpFmt($eq)}</strong>.";
            }
            if ($cr !== null) {
                $crVal = (float)$cr;
                if ($crVal < 1.0)       $warns[] = "Current Ratio <strong>" . number_format($crVal, 2) . "x</strong> di bawah 1 — risiko likuiditas jangka pendek.";
                elseif ($crVal < 1.5)   $warns[] = "Current Ratio <strong>" . number_format($crVal, 2) . "x</strong> cukup ketat, pantau arus kas.";
                else                    $lines[] = "Current Ratio: <strong>" . number_format($crVal, 2) . "x</strong> — likuiditas memadai ✅.";
            }
            if ($der !== null) {
                $derVal = (float)$der;
                if ($derVal > 2.0)      $warns[] = "Debt-to-Equity <strong>" . number_format($derVal, 2) . "x</strong> tinggi — evaluasi struktur modal.";
                elseif ($derVal > 1.0)  $warns[] = "Debt-to-Equity <strong>" . number_format($derVal, 2) . "x</strong> di atas 1 — pantau beban hutang.";
                else                    $lines[] = "Debt-to-Equity: <strong>" . number_format($derVal, 2) . "x</strong> — leverage terkendali ✅.";
            }
            if ($roa !== null) $lines[] = "ROA: <strong>" . number_format((float)$roa, 1) . "%</strong>" . ($roa < 0 ? " — aset tidak menghasilkan return positif." : ".") ;
            if ($roe !== null) $lines[] = "ROE: <strong>" . number_format((float)$roe, 1) . "%</strong>" . ($roe < 0 ? " — ekuitas belum menghasilkan return." : ".") ;
            if ($net < 0)      $warns[] = "Perusahaan mencatat <strong>rugi bersih " . $rpFmt(abs($net)) . "</strong> — perlu evaluasi pendapatan dan efisiensi biaya.";
            break;

        default:
            return '<span class="text-muted small">Analisis tidak tersedia untuk tipe jawaban ini '
                 . '(<code>' . htmlspecialchars($type) . '</code>).'
                 . ' Coba tanya tabel yang didukung seperti "Tabel luas area di ANP" terlebih dahulu.</span>';
    }

    // ── Rule-based output (always shown) ──────────────────────────────────────
    $out = '<ul class="mb-1 ps-3" style="line-height:1.9">';
    foreach ($lines as $l) {
        $out .= '<li>' . $l . '</li>';
    }
    $out .= '</ul>';

    if (!empty($warns)) {
        $out .= '<div class="alert alert-warning py-1 px-2 mt-1 mb-0 small">';
        foreach ($warns as $w) {
            $out .= '⚠️ ' . $w . '<br>';
        }
        $out = rtrim($out, '<br>') . '</div>';
    }

    // ── LLM enrichment (optional, appended below rule-based output) ───────────
    if (agro_ai_available()) {
        $statsText = strip_tags(implode("\n", $lines));
        if (!empty($warns)) {
            $statsText .= "\nWarnings:\n" . implode("\n", $warns);
        }

        $ai_lang_instr = ($qna_lang === 'en')
            ? 'Respond in English.'
            : 'Respond in Bahasa Indonesia.';
        $system = <<<PROMPT
You are an expert agronomist and plantation management consultant specializing in Indonesian oil palm (kelapa sawit) estates.
You receive computed statistics about a plantation dataset and provide a concise, actionable narrative interpretation. {$ai_lang_instr}
Rules:
- 3–5 short paragraphs maximum
- Reference Indonesian/GAPKI/PPKS industry standards where relevant (e.g. SPH design 136-148 pohon/ha, optimal TM age ≥3 years, normal plant ratio >92%, dead plant <2%, Jalan Produksi density ~100-150 m/ha)
- Highlight the most important finding first
- End with 1-2 concrete recommendations
- Do NOT repeat the raw numbers already shown — interpret them
- Plain text only, no markdown headers or bullet symbols
PROMPT;

        $userPrompt = "Data type: {$type}\nScope: {$scope}\n\nComputed statistics:\n{$statsText}";

        $llmReply = agro_ai_chat($system, $userPrompt);

        if ($llmReply !== null) {
            $safeReply = nl2br(htmlspecialchars(trim($llmReply), ENT_QUOTES, 'UTF-8'));
            $out .= '<div class="mt-2 p-2 rounded" style="background:#f0f9ff;border:1px solid #bae6fd;font-size:.85rem;line-height:1.7">'
                  .   '<span style="font-size:.72rem;font-weight:600;color:#0369a1;letter-spacing:.4px">🤖 AI INSIGHT</span>'
                  .   '<div class="mt-1">' . $safeReply . '</div>'
                  . '</div>';
        }
    }

    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Standards compliance check — hardcoded GAPKI/PPKS benchmarks + optional LLM verdict
// ─────────────────────────────────────────────────────────────────────────────

function agro_render_standards_check(array $src): string
{
    $type  = (string)($src['type'] ?? '');
    $scope = htmlspecialchars((string)($src['scope'] ?? $src['division'] ?? $src['business_unit'] ?? ''));

    // ── helper: build one check row from standards library ────────────────────
    // Returns ['label','actual','standard','unit','status','source','note']
    $checks = [];
    $chk = function(string $stdId, float $value) use (&$checks): void {
        $std = agro_std_get($stdId);
        if (!$std) return;
        $status = agro_std_check($value, $std);
        $note   = agro_std_note($value, $std);
        $checks[] = [
            $std['param'],
            number_format($value, 1),
            $std['display'],
            $std['unit'],
            $status,
            $std['source'] . ' (' . $std['source_year'] . ')',
            $note,
        ];
    };

    switch ($type) {

        // ── Plant Density ─────────────────────────────────────────────────────
        case 'plant_density':
            $rows         = array_map(fn($r) => (array)$r, (array)($src['rows'] ?? []));
            $grandPlants  = (int)  ($src['grand_plants']  ?? 0);
            $grandPlanted = (float)($src['grand_planted']  ?? array_sum(array_column($rows, 'planted_ha')));
            $grandDead    = (int)  ($src['grand_dead']    ?? 0);
            $grandAbnorm  = (int)  ($src['grand_abnorm']  ?? 0);
            $grandNormal  = (int)  ($src['grand_normal']  ?? 0);

            $actualSph = $grandPlanted > 0 ? round($grandPlants / $grandPlanted, 1) : null;
            if ($actualSph !== null)          $chk('sph_aktual',         $actualSph);
            if ($grandPlants > 0) {
                $chk('normal_plant_ratio',    round($grandNormal  / $grandPlants * 100, 1));
                $chk('dead_plant_ratio',      round($grandDead    / $grandPlants * 100, 1));
                $chk('abnormal_plant_ratio',  round($grandAbnorm  / $grandPlants * 100, 1));
                // Derived: sisip = dead + abnormal
                $chk('sisip_ratio', round(($grandDead + $grandAbnorm) / $grandPlants * 100, 1));
            }
            break;

        // ── Area by Division ──────────────────────────────────────────────────
        case 'area_by_division':
            $rows     = array_map(fn($r) => (array)$r, (array)($src['rows'] ?? []));
            $grandHa  = (float)($src['grand_ha']     ?? 0);
            $grandTm  = (float)($src['grand_tm']     ?? 0);
            $grandBlk = (int)  ($src['grand_blocks'] ?? count($rows));
            $grandPlt = (int)  ($src['grand_plants'] ?? 0);

            if ($grandHa > 0) {
                if ($grandTm > 0)   $chk('tm_ratio',      round($grandTm / $grandHa * 100, 1));
            }
            $n = count($rows);
            if ($n > 0)             $chk('division_size',  round($grandHa / $n, 0));
            if ($grandBlk > 0)      $chk('block_size',     round($grandHa / $grandBlk, 1));
            break;

        // ── Road by Type ──────────────────────────────────────────────────────
        case 'road_by_type':
            $grandByType = (array)($src['grand_by_type'] ?? []);
            $prodLen = (float)($grandByType['Jalan Produksi']['length_m'] ?? 0);
            $mainLen = (float)($grandByType['Jalan Poros']['length_m']    ?? 0);
            $accLen  = (float)($grandByType['Jalan Akses']['length_m']    ?? 0);

            if ($prodLen > 0 && $mainLen > 0) $chk('road_prod_main_ratio',  round($prodLen / $mainLen, 1));
            if ($accLen  > 0 && $mainLen > 0) $chk('road_access_main_ratio', round($accLen  / $mainLen, 1));
            break;

        // ── Infrastructure Summary (road + bridge combined) ───────────────────
        case 'infrastructure_summary':
            $roadGrandType = (array) ($src['road_grand_type'] ?? []);
            $grandRoadM    = (float) ($src['grand_road_m']    ?? 0);
            $grandBridgeM  = (float) ($src['grand_bridge_m']  ?? 0);
            $grandBridgeN  = (int)   ($src['grand_bridge_n']  ?? 0);

            // Road type ratios — same checks as road_by_type
            $prodLen = (float)($roadGrandType['Jalan Produksi']['length_m'] ?? 0);
            $mainLen = (float)($roadGrandType['Jalan Poros']['length_m']    ?? 0);
            $accLen  = (float)($roadGrandType['Jalan Akses']['length_m']    ?? 0);
            if ($prodLen > 0 && $mainLen > 0) $chk('road_prod_main_ratio',   round($prodLen / $mainLen, 1));
            if ($accLen  > 0 && $mainLen > 0) $chk('road_access_main_ratio', round($accLen  / $mainLen, 1));

            // Bridge density: units per km of total road
            if ($grandBridgeN > 0 && $grandRoadM > 0) {
                $chk('bridge_per_km_road', round($grandBridgeN / ($grandRoadM / 1000), 2));
            } elseif ($grandBridgeM > 0 && $grandRoadM > 0) {
                // Fallback: use bridge length in lieu of count
                $chk('bridge_per_km_road', round($grandBridgeM / ($grandRoadM / 1000), 2));
            }
            break;

        // ── Harvest Summary ───────────────────────────────────────────────────
        case 'harvest_summary':
        // ── Harvest Total ─────────────────────────────────────────────────────
        case 'harvest_total':
            $kg      = (float)($src['total_kg'] ?? 0);
            $bunches = (int)  ($src['bunches']  ?? 0);
            if ($bunches > 0) $chk('abw_mature', round($kg / $bunches, 2));
            break;

        // ── Nursery / Pembibitan ──────────────────────────────────────────────
        case 'nursery_summary':
            $germRate = $src['germ_rate']      !== null ? (float)$src['germ_rate']      : null;
            $preRate  = $src['pre_nurs_rate']  !== null ? (float)$src['pre_nurs_rate']  : null;
            $mainRate = $src['main_nurs_rate'] !== null ? (float)$src['main_nurs_rate'] : null;
            if ($germRate  !== null) $chk('nursery_germination_rate',        $germRate);
            if ($preRate   !== null) $chk('nursery_pre_nursery_survival',    $preRate);
            if ($mainRate  !== null) $chk('nursery_main_nursery_survival',   $mainRate);
            break;

        // ── Nursery by Batch — standards checks ───────────────────────────────
        case 'nursery_by_batch': {
            $totalsNB = (array)($src['totals'] ?? []);
            $gRateNB  = isset($totalsNB['germ_rate'])  && $totalsNB['germ_rate']  !== null ? (float)$totalsNB['germ_rate']  : null;
            $pRateNB  = isset($totalsNB['pre_rate'])   && $totalsNB['pre_rate']   !== null ? (float)$totalsNB['pre_rate']   : null;
            $mRateNB  = isset($totalsNB['main_rate'])  && $totalsNB['main_rate']  !== null ? (float)$totalsNB['main_rate']  : null;
            if ($gRateNB === null && $pRateNB === null && $mRateNB === null) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data pembibitan. Isi data di <a href="nursery_stock.php">Nursery Stock</a> terlebih dahulu.</div>';
            }
            if ($gRateNB !== null) $chk('nursery_germination_rate',      $gRateNB);
            if ($pRateNB !== null) $chk('nursery_pre_nursery_survival',  $pRateNB);
            if ($mRateNB !== null) $chk('nursery_main_nursery_survival', $mRateNB);
            break;
        }

        // ── Mill Production ───────────────────────────────────────────────────
        case 'mill_production':
        case 'rendemen':
            $ffbKg    = (float)($src['ffb_kg']    ?? 0);
            $cpoKg    = (float)($src['cpo_kg']    ?? 0);
            $kernelKg = (float)($src['kernel_kg'] ?? 0);
            if ($ffbKg > 0) {
                if ($cpoKg    > 0) $chk('oer_target', round($cpoKg    / $ffbKg * 100, 2));
                if ($kernelKg > 0) $chk('ker_target', round($kernelKg / $ffbKg * 100, 2));
            }
            if (isset($src['avg_ffa'])      && $src['avg_ffa']      !== null) $chk('cpo_ffa',      (float)$src['avg_ffa']);
            if (isset($src['avg_moisture']) && $src['avg_moisture']  !== null) $chk('cpo_moisture', (float)$src['avg_moisture']);
            break;

        // ── Bridge Count ──────────────────────────────────────────────────────
        case 'bridge_count':
            $rows      = array_map(fn($r) => (array)$r, (array)($src['rows'] ?? []));
            $grandLen  = (float)($src['grand_length_m'] ?? 0);
            $grandCnt  = (int)  ($src['grand_count']    ?? 0);
            // Only check bridge/road ratio if we also have road data in scope
            // (bridge_count doesn't carry road length — flag the calculation as unavailable)
            if ($grandLen === 0.0) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Data panjang jembatan (meter) belum diisi. Isi di Komponen Luas Blok terlebih dahulu.</div>';
            }
            // Approximate: every 500 m of bridge infrastructure per 5 divisi is a rough check
            $divCount = count(array_filter($rows, fn($r) => (float)$r['bridge_length_m'] > 0));
            if ($divCount > 0) $chk('bridge_per_km_road', round($grandLen / 1000 / max($divCount, 1), 2));
            break;

        // ── Count Blocks ──────────────────────────────────────────────────────
        case 'count_blocks':
            $count = (int)  ($src['count']    ?? 0);
            $ha    = (float)($src['total_ha'] ?? 0);
            if ($count > 0 && $ha > 0) $chk('block_size', round($ha / $count, 1));
            break;

        // ── Top Blocks ────────────────────────────────────────────────────────
        case 'top_blocks':
            $blocks  = array_map(fn($b) => (array)$b, (array)($src['blocks'] ?? []));
            $grandKg = array_sum(array_column($blocks, 'total_kg'));
            // No per-block area available — can only check ABW if bunches exist
            foreach (array_slice($blocks, 0, 3) as $b) {
                if (!empty($b['harvests']) && (int)$b['harvests'] > 0 && (float)$b['total_kg'] > 0) {
                    // crude ABW: total_kg / harvests is not ABW, skip
                }
            }
            return '<div class="alert alert-info py-2 px-3 small mb-0">'
                 . 'Cek standar untuk top blok panen memerlukan data ABW dan luas blok. '
                 . 'Coba "Kerapatan tanaman di ANP" atau "Total panen Afdeling A", kemudian "Apakah sesuai standar?"'
                 . '</div>';

        // ── Plantation Analysis — Standards Check ─────────────────────────────
        // ── Sustainability — Standards Check ──────────────────────────────────
        case 'sustainability_analysis': {
            $conservRatio = $src['conserv_ratio_pct'] ?? null;
            // conservation_ratio: check if we have data
            if ($conservRatio !== null) {
                $chk('conservation_ratio', (float)$conservRatio);
            }
            // hcv_buffer: no direct numeric value in DB — we cannot auto-check width
            // If has_water_data → surface a note; otherwise skip silently
            // (The standards_check table will show the standard row but won't pass/fail it)
            break;
        }

        case 'plantation_analysis': {
            $avgSph       = (float)($src['avg_sph']           ?? 0);
            $tmRatioPct   = (float)($src['tm_ratio_pct']      ?? 0);
            $plantedRatio = (float)($src['planted_ratio_pct'] ?? 0);
            $avgBlockHa   = (float)($src['avg_block_ha']      ?? 0);
            $avgDivHa     = (float)($src['avg_div_ha']        ?? 0);
            $normalRatio  = $src['normal_ratio_pct']  ?? null;
            $abnRatio     = $src['abnormal_ratio_pct']?? null;
            $deadRatio    = $src['dead_ratio_pct']    ?? null;
            $sisipRatio   = $src['sisip_ratio_pct']   ?? null;
            $avgAbw       = (float)($src['avg_abw']           ?? 0);
            $yield        = $src['yield_per_ha_tm'] ?? null;

            if ($avgSph          > 0) $chk('sph_aktual',         $avgSph);
            if ($tmRatioPct      > 0) $chk('tm_ratio',           $tmRatioPct);
            if ($plantedRatio    > 0) $chk('planted_ratio',      $plantedRatio);
            if ($avgBlockHa      > 0) $chk('block_size',         $avgBlockHa);
            if ($avgDivHa        > 0) $chk('division_size',      $avgDivHa);
            if ($normalRatio    !== null && (float)$normalRatio > 0) $chk('normal_plant_ratio', (float)$normalRatio);
            if ($abnRatio       !== null && (float)$abnRatio    > 0) $chk('abnormal_plant_ratio', (float)$abnRatio);
            if ($deadRatio      !== null && (float)$deadRatio   > 0) $chk('dead_plant_ratio',   (float)$deadRatio);
            if ($sisipRatio     !== null && (float)$sisipRatio  > 0) $chk('sisip_ratio',        (float)$sisipRatio);
            if ($avgAbw          > 0) $chk('abw_mature',         $avgAbw);
            if ($yield          !== null && (float)$yield > 0)       $chk('yield_per_ha_tm',    (float)$yield);
            break;
        }

        // ── Weed by Division / Block / Planting Year — standards checks ───────
        case 'weed_by_division':
        case 'weed_by_block':
        case 'weed_by_planting_year': {
            $grandTotWS  = (int)  ($src['grand_total']    ?? 0);
            $grandHiWS   = (int)  ($src['grand_high_sev'] ?? 0);
            $paraquatWS  = 0;  // weed pivot doesn't track paraquat per row; check globally via meta
            $totalHaWS   = array_sum(array_column((array)($src['meta'] ?? []), 'total_ha'));
            $totalCostWS = array_sum(array_column((array)($src['meta'] ?? []), 'total_cost'));

            if ($grandTotWS > 0) {
                $highPctWS = round($grandHiWS / $grandTotWS * 100, 1);
                // Proxy checks — same logic as weed_analysis
                $chk('weed_circle_clean_pct',   max(0, 100 - $highPctWS * 1.5));
                $chk('weed_gawangan_clean_pct',  max(0, 100 - $highPctWS));
                if ($totalHaWS > 0 && $totalCostWS > 0) {
                    $herbPerHaWS = round($totalCostWS / $totalHaWS / 50000, 2);
                    if ($herbPerHaWS > 0) $chk('chem_herbicide_ai_per_ha', min($herbPerHaWS, 5.0));
                }
            }
            break;
        }

        // ── Pest & Disease Analysis ───────────────────────────────────────────
        case 'pest_analysis':
            $critCntS = (int)($src['critical_count'] ?? 0);
            $highCntS = (int)($src['high_count']     ?? 0);
            $totS     = (int)($src['total_records']  ?? 0);
            $totalHaS = (float)($src['total_area_ha'] ?? 0);
            $byDivS   = array_map(fn($r) => (array)$r, (array)($src['by_division'] ?? []));

            if ($totS > 0) {
                // Critical/High severity ratio
                $sevPctS = $totS > 0 ? round(($critCntS + $highCntS) / $totS * 100, 1) : 0;
                $chk('pest_high_critical_ratio', $sevPctS);

                // Coverage density: ha treated per record (proxy for spread)
                if ($totalHaS > 0) {
                    $chk('pest_coverage_density', round($totalHaS / $totS, 2));
                }

                // Average Critical+High per division
                $divCount = count($byDivS);
                if ($divCount > 0) {
                    $avgCritHigh = round(($critCntS + $highCntS) / $divCount, 1);
                    $chk('pest_avg_critical_per_div', $avgCritHigh);
                }
            }
            break;

        // ── Weed (Gulma) — Standards Check ───────────────────────────────────
        case 'weed_analysis': {
            $totW      = (int)  ($src['total_records']   ?? 0);
            $highW     = (int)  ($src['high_count']      ?? 0);
            $critW     = (int)  ($src['critical_count']  ?? 0);
            $manualW   = (int)  ($src['manual_count']    ?? 0);
            $paraquatW = (int)  ($src['paraquat_count']  ?? 0);
            $totalHaW  = (float)($src['total_area_ha']   ?? 0);
            $totalCostW= (float)($src['total_cost']      ?? 0);

            if ($totW > 0) {
                // High+Critical infestation ratio vs weed_rotation_days proxy
                $highPctW = round(($highW + $critW) / $totW * 100, 1);
                $chk('weed_circle_clean_pct',   max(0, 100 - $highPctW * 1.5)); // proxy: high infestation → low piringan clean
                $chk('weed_gawangan_clean_pct', max(0, 100 - $highPctW));       // proxy: high pct → low gawangan bersih

                // Paraquat compliance check (1 = free, 0 = not free)
                if ($paraquatW > 0) {
                    $chk('chem_paraquat_free', 0.0); // fail
                } else {
                    $chk('chem_paraquat_free', 1.0); // pass
                }

                // Herbicide load proxy: cost per ha as fraction of benchmark
                if ($totalHaW > 0 && $totalCostW > 0) {
                    $herbPerHa = round($totalCostW / $totalHaW / 50000, 2); // normalise to kg a.i. proxy
                    if ($herbPerHa > 0) $chk('chem_herbicide_ai_per_ha', min($herbPerHa, 5.0));
                }
            }
            break;
        }

        // ── Fertilization by Block (pivot) ───────────────────────────────────
        case 'fertilization_by_block': {
            $gtots     = (array)($src['grand_totals'] ?? []);
            $metaBlks  = (array)($src['meta']         ?? []);
            $pivot     = (array)($src['pivot']        ?? []);
            $blockCount= (int)  ($src['block_count']  ?? 0);

            if (empty($gtots) || array_sum($gtots) <= 0) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data pemupukan. Isi data di <a href="fertilization.php">Pemupukan</a> terlebih dahulu.</div>';
            }

            // Total trees across all blocks in meta
            $totalTrees = 0;
            foreach ($metaBlks as $bm) {
                // app_count per block is a proxy; we need trees but don't store it in pivot meta
                // Use app_count as a minimal proxy for rounds
                $totalTrees += (int)($bm['app_count'] ?? 0); // will use separately below
            }

            // Aggregate Kg per nutrient category from grand_totals
            $nKg = 0.0; $pKg = 0.0; $kKg = 0.0; $mgKg = 0.0;
            $totalAppCount = 0;
            foreach ($metaBlks as $bm) {
                $totalAppCount += (int)($bm['app_count'] ?? 0);
            }
            foreach ($gtots as $ft => $kg) {
                $tl = mb_strtolower((string)$ft);
                if (str_contains($tl, 'urea') || str_contains($tl, 'nitrogen'))
                    $nKg  += (float)$kg;
                elseif (str_contains($tl, 'tsp') || str_contains($tl, 'phosphat') || str_contains($tl, 'fosfat') || str_contains($tl, 'rock phosphate') || str_contains($tl, 'sp-36'))
                    $pKg  += (float)$kg;
                elseif (str_contains($tl, 'mop') || str_contains($tl, 'kcl') || str_contains($tl, 'kalium') || str_contains($tl, 'potash') || str_contains($tl, 'k2o'))
                    $kKg  += (float)$kg;
                elseif (str_contains($tl, 'kieserit') || str_contains($tl, 'mgo') || str_contains($tl, 'magnesium'))
                    $mgKg += (float)$kg;
            }

            // Dosage per tree = total Kg nutrient / total app_count
            // (app_count in meta = number of fertilization records per block — proxy for trees × rounds)
            // Better proxy: sum of dosage_per_tree is not stored here.
            // Use Kg/block as kg/pohon proxy if we have block count.
            if ($blockCount > 0) {
                if ($nKg  > 0) $chk('fert_n_tm_dose',  round($nKg  / $blockCount, 3));
                if ($pKg  > 0) $chk('fert_p_tm_dose',  round($pKg  / $blockCount, 3));
                if ($kKg  > 0) $chk('fert_k_tm_dose',  round($kKg  / $blockCount, 3));
                if ($mgKg > 0) $chk('fert_mg_tm_dose', round($mgKg / $blockCount, 3));
            }

            // Timing rounds: total app records / block count = avg rounds per block
            if ($blockCount > 0 && $totalAppCount > 0) {
                $avgRounds = round($totalAppCount / $blockCount, 1);
                if ($avgRounds >= 1 && $avgRounds <= 10) {
                    $chk('fert_timing_rounds', $avgRounds);
                }
            }
            break;
        }

        // ── Fertilization by Division (pivot) — standards checks ─────────────
        case 'fertilization_by_division': {
            $gtotsFD    = (array)($src['grand_totals'] ?? []);
            $metaFD     = (array)($src['meta']         ?? []);
            $divCountFD = (int)  ($src['div_count']    ?? 0);

            if (empty($gtotsFD) || array_sum($gtotsFD) <= 0) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data pemupukan. Isi data di <a href="fertilization.php">Pemupukan</a> terlebih dahulu.</div>';
            }

            // N, P, K, Mg categorisation
            $nKgFD = 0.0; $pKgFD = 0.0; $kKgFD = 0.0; $mgKgFD = 0.0;
            foreach ($gtotsFD as $ft => $kg) {
                $tl = mb_strtolower((string)$ft);
                if (str_contains($tl, 'urea') || str_contains($tl, 'nitrogen'))
                    $nKgFD  += (float)$kg;
                elseif (str_contains($tl, 'tsp') || str_contains($tl, 'phosphat') || str_contains($tl, 'fosfat') || str_contains($tl, 'sp-36'))
                    $pKgFD  += (float)$kg;
                elseif (str_contains($tl, 'mop') || str_contains($tl, 'kcl') || str_contains($tl, 'kalium') || str_contains($tl, 'potash') || str_contains($tl, 'k2o'))
                    $kKgFD  += (float)$kg;
                elseif (str_contains($tl, 'kieserit') || str_contains($tl, 'mgo') || str_contains($tl, 'magnesium'))
                    $mgKgFD += (float)$kg;
            }

            $totalHaFD = array_sum(array_column($metaFD, 'total_ha'));
            if ($totalHaFD > 0) {
                if ($nKgFD  > 0) $chk('fert_n_tm_dose',  round($nKgFD  / $totalHaFD, 3));
                if ($pKgFD  > 0) $chk('fert_p_tm_dose',  round($pKgFD  / $totalHaFD, 3));
                if ($kKgFD  > 0) $chk('fert_k_tm_dose',  round($kKgFD  / $totalHaFD, 3));
                if ($mgKgFD > 0) $chk('fert_mg_tm_dose', round($mgKgFD / $totalHaFD, 3));
            }

            // App rounds: total app_count / div count as proxy
            $totalAppsFD = array_sum(array_column($metaFD, 'app_count'));
            if ($divCountFD > 0 && $totalAppsFD > 0) {
                $avgRoundsFD = round($totalAppsFD / $divCountFD, 1);
                if ($avgRoundsFD >= 1 && $avgRoundsFD <= 10) {
                    $chk('fert_timing_rounds', $avgRoundsFD);
                }
            }
            break;
        }

        // ── Fertilization by Planting Year (pivot) — standards checks ────────
        case 'fertilization_by_planting_year': {
            $gtotsPY2    = (array)($src['grand_totals'] ?? []);
            $metaPY2     = (array)($src['meta']         ?? []);
            $yearCntPY2  = (int)  ($src['year_count']   ?? 0);

            if (empty($gtotsPY2) || array_sum($gtotsPY2) <= 0) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data pemupukan. Isi data di <a href="fertilization.php">Pemupukan</a> terlebih dahulu.</div>';
            }

            // N, P, K, Mg categorisation
            $nPY2 = 0.0; $pPY2 = 0.0; $kPY2 = 0.0; $mgPY2 = 0.0;
            foreach ($gtotsPY2 as $ft => $kg) {
                $tl = mb_strtolower((string)$ft);
                if (str_contains($tl, 'urea') || str_contains($tl, 'nitrogen'))
                    $nPY2  += (float)$kg;
                elseif (str_contains($tl, 'tsp') || str_contains($tl, 'phosphat') || str_contains($tl, 'fosfat') || str_contains($tl, 'sp-36'))
                    $pPY2  += (float)$kg;
                elseif (str_contains($tl, 'mop') || str_contains($tl, 'kcl') || str_contains($tl, 'kalium') || str_contains($tl, 'potash') || str_contains($tl, 'k2o'))
                    $kPY2  += (float)$kg;
                elseif (str_contains($tl, 'kieserit') || str_contains($tl, 'mgo') || str_contains($tl, 'magnesium'))
                    $mgPY2 += (float)$kg;
            }

            $totalHaPY2 = array_sum(array_column($metaPY2, 'total_ha'));
            if ($totalHaPY2 > 0) {
                if ($nPY2  > 0) $chk('fert_n_tm_dose',  round($nPY2  / $totalHaPY2, 3));
                if ($pPY2  > 0) $chk('fert_p_tm_dose',  round($pPY2  / $totalHaPY2, 3));
                if ($kPY2  > 0) $chk('fert_k_tm_dose',  round($kPY2  / $totalHaPY2, 3));
                if ($mgPY2 > 0) $chk('fert_mg_tm_dose', round($mgPY2 / $totalHaPY2, 3));
            }

            // App rounds: total app_count / year count as proxy
            $totalAppsPY2 = array_sum(array_column($metaPY2, 'app_count'));
            if ($yearCntPY2 > 0 && $totalAppsPY2 > 0) {
                $avgRoundsPY2 = round($totalAppsPY2 / $yearCntPY2, 1);
                if ($avgRoundsPY2 >= 1 && $avgRoundsPY2 <= 10) {
                    $chk('fert_timing_rounds', $avgRoundsPY2);
                }
            }
            break;
        }

        // ── Fertilization Used ────────────────────────────────────────────────
        case 'fertilization_used':
            $ferts    = array_map(fn($f) => (array)$f, (array)($src['fertilizers'] ?? []));
            $grandApps = (int)($src['grand_apps'] ?? 0);
            if (empty($ferts)) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data pemupukan. Isi data di <a href="fertilization.php">Pemupukan</a> terlebih dahulu.</div>';
            }

            // Aggregate dosage per fertilizer type (kg per application count ≈ proxy for kg/tree/round)
            // Sum dosages and divide by total applications to get avg dosage per tree per application
            $ureaKg = 0.0; $tspKg = 0.0; $mopKg = 0.0; $kieserKg = 0.0;
            $ureaApps = 0;  $tspApps = 0;  $mopApps = 0;  $kieserApps = 0;
            foreach ($ferts as $f) {
                $t   = mb_strtolower((string)($f['fertilizer_type'] ?? ''));
                $qty = (float)$f['total_qty_kg'];
                $app = (int)$f['application_count'];
                if (str_contains($t, 'urea') || ($t === 'n') || str_contains($t, 'nitrogen')) {
                    $ureaKg += $qty; $ureaApps += $app;
                } elseif (str_contains($t, 'tsp') || str_contains($t, 'p2o5') || str_contains($t, 'phosphat') || str_contains($t, 'fosfat')) {
                    $tspKg += $qty; $tspApps += $app;
                } elseif (str_contains($t, 'mop') || str_contains($t, 'kcl') || str_contains($t, 'kalium') || str_contains($t, 'k2o') || str_contains($t, 'potash') || str_contains($t, 'muriate')) {
                    $mopKg += $qty; $mopApps += $app;
                } elseif (str_contains($t, 'kieserit') || str_contains($t, 'mgo') || str_contains($t, 'magnesium') || str_contains($t, 'kieseri')) {
                    $kieserKg += $qty; $kieserApps += $app;
                }
            }

            // Use avg dosage_per_tree from records if available, otherwise note lack of per-tree data
            // For now check dosage_per_tree averaged per type
            $avgDosageN  = $ureaApps   > 0 ? array_sum(array_column(array_filter($ferts, fn($f) => str_contains(mb_strtolower((string)($f['fertilizer_type'] ?? '')), 'urea') || str_contains(mb_strtolower((string)($f['fertilizer_type'] ?? '')), 'nitrogen')), 'sum_dosage')) / $ureaApps : null;
            $avgDosageP  = $tspApps    > 0 ? array_sum(array_column(array_filter($ferts, fn($f) => str_contains(mb_strtolower((string)($f['fertilizer_type'] ?? '')), 'tsp')    || str_contains(mb_strtolower((string)($f['fertilizer_type'] ?? '')), 'fosfat')),  'sum_dosage')) / $tspApps   : null;
            $avgDosageK  = $mopApps    > 0 ? array_sum(array_column(array_filter($ferts, fn($f) => str_contains(mb_strtolower((string)($f['fertilizer_type'] ?? '')), 'mop')    || str_contains(mb_strtolower((string)($f['fertilizer_type'] ?? '')), 'kcl')),     'sum_dosage')) / $mopApps   : null;
            $avgDosageMg = $kieserApps > 0 ? array_sum(array_column(array_filter($ferts, fn($f) => str_contains(mb_strtolower((string)($f['fertilizer_type'] ?? '')), 'kieser') || str_contains(mb_strtolower((string)($f['fertilizer_type'] ?? '')), 'mgo')),      'sum_dosage')) / $kieserApps: null;

            if ($avgDosageN  !== null && $avgDosageN  > 0) $chk('fert_n_tm_dose',  round($avgDosageN,  3));
            if ($avgDosageP  !== null && $avgDosageP  > 0) $chk('fert_p_tm_dose',  round($avgDosageP,  3));
            if ($avgDosageK  !== null && $avgDosageK  > 0) $chk('fert_k_tm_dose',  round($avgDosageK,  3));
            if ($avgDosageMg !== null && $avgDosageMg > 0) $chk('fert_mg_tm_dose', round($avgDosageMg, 3));

            // Check application frequency (rounds per year)
            // Count distinct application months / 12 × 12 ≈ rounds per year
            // Approximate: grand_apps / distinct blocks (can't compute without block count here)
            // Use grand_apps as proxy for rounds if ≥1
            if ($grandApps >= 1 && $grandApps <= 20) {
                $chk('fert_timing_rounds', (float)$grandApps);
            }

            break;

        // ── Chemicals by Division (pivot) ─────────────────────────────────────
        case 'chemicals_by_division': {
            $gtots      = (array)($src['grand_totals'] ?? []);
            $metaD2     = (array)($src['meta']         ?? []);
            $divCount   = (int)  ($src['div_count']    ?? 0);
            $hasParaquat= !empty($src['has_paraquat']);

            if (empty($gtots) || array_sum($gtots) <= 0) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data bahan kimia. Isi data di <a href="pest_control.php">Pengendalian Hama</a>.</div>';
            }

            $chk('chem_paraquat_free', $hasParaquat ? 0.0 : 1.0);

            $herbQtyD = 0.0; $insectQtyD = 0.0; $totalHaD = 0.0;
            foreach ($gtots as $ct => $qty) {
                $tl = mb_strtolower((string)$ct);
                if (str_contains($tl, 'herbicid') || str_contains($tl, 'herbisid')) $herbQtyD   += (float)$qty;
                if (str_contains($tl, 'insecticid') || str_contains($tl, 'insektisid')) $insectQtyD += (float)$qty;
            }
            foreach ($metaD2 as $dm) { $totalHaD += (float)($dm['total_ha'] ?? 0); }
            if ($totalHaD > 0) {
                if ($herbQtyD   > 0) $chk('chem_herbicide_ai_per_ha',   round($herbQtyD   / $totalHaD, 3));
                if ($insectQtyD > 0) $chk('chem_insecticide_ai_per_ha', round($insectQtyD / $totalHaD, 3));
            }
            break;
        }

        // ── Chemicals by Block (pivot) ────────────────────────────────────────
        case 'chemicals_by_block': {
            $gtots      = (array)($src['grand_totals'] ?? []);
            $metaCB2    = (array)($src['meta']         ?? []);
            $blockCount = (int)  ($src['block_count']  ?? 0);
            $hasParaquat= !empty($src['has_paraquat']);

            if (empty($gtots) || array_sum($gtots) <= 0) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data bahan kimia. Isi data di <a href="pest_control.php">Pengendalian Hama</a>.</div>';
            }

            // Paraquat — always checked (binary)
            $chk('chem_paraquat_free', $hasParaquat ? 0.0 : 1.0);

            // Herbicide and insecticide loading per ha
            $herbQtyCB = 0.0; $insectQtyCB = 0.0; $totalHaCB = 0.0;
            foreach ($gtots as $ct => $qty) {
                $tl = mb_strtolower((string)$ct);
                if (str_contains($tl, 'herbicid') || str_contains($tl, 'herbisid') || str_contains($tl, 'weed')) $herbQtyCB   += (float)$qty;
                if (str_contains($tl, 'insecticid') || str_contains($tl, 'insektisid'))                           $insectQtyCB += (float)$qty;
            }
            foreach ($metaCB2 as $bm) { $totalHaCB += (float)($bm['total_ha'] ?? 0); }
            if ($totalHaCB > 0) {
                if ($herbQtyCB   > 0) $chk('chem_herbicide_ai_per_ha',   round($herbQtyCB   / $totalHaCB, 3));
                if ($insectQtyCB > 0) $chk('chem_insecticide_ai_per_ha', round($insectQtyCB / $totalHaCB, 3));
            }
            break;
        }

        // ── Pest & Disease by Block (pivot) — standards checks ────────────────
        case 'pest_by_block': {
            $gtotsPB2   = (array)($src['grand_totals'] ?? []);
            $metaPB2    = (array)($src['meta']         ?? []);
            $totalHaPB2 = array_sum(array_column($metaPB2, 'total_ha'));

            if (empty($gtotsPB2) || array_sum($gtotsPB2) <= 0) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data hama &amp; penyakit. Isi data di <a href="pest_control.php">Pengendalian Hama</a>.</div>';
            }

            // Map pest types to chemical standard checks where applicable
            $herbQtyPB = 0.0; $insectQtyPB = 0.0;
            foreach ($gtotsPB2 as $pt => $cnt) {
                $tl = mb_strtolower((string)$pt);
                // pest_type records are counts, not qty — only check paraquat-free if weed type present
                if (str_contains($tl, 'weed') || str_contains($tl, 'gulma')) $herbQtyPB += (float)$cnt;
                if (str_contains($tl, 'insect') || str_contains($tl, 'serangga')) $insectQtyPB += (float)$cnt;
            }
            // No direct numeric dosage data in pest_by_block — skip dosage checks
            // Only surface the paraquat-free check if any herbicide record exists
            // (we cannot determine paraquat use from pest_type alone — skip)
            break;
        }

        // ── Pest & Disease by Division (pivot) — standards checks ─────────────
        case 'pest_by_division': {
            $gtotsPD3 = (array)($src['grand_totals'] ?? []);
            if (empty($gtotsPD3) || array_sum($gtotsPD3) <= 0) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data hama &amp; penyakit. Isi data di <a href="pest_control.php">Pengendalian Hama</a>.</div>';
            }
            // Records are counts — no dosage to check; structural placeholder only
            break;
        }

        // ── Pest & Disease by Planting Year — standards checks ────────────────
        case 'pest_by_planting_year': {
            $gtotsPPY = (array)($src['grand_totals'] ?? []);
            if (empty($gtotsPPY) || array_sum($gtotsPPY) <= 0) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data hama &amp; penyakit. Isi data di <a href="pest_control.php">Pengendalian Hama</a>.</div>';
            }
            // Records are counts — no dosage to check; structural placeholder only
            break;
        }

        // ── Chemicals by Planting Year (pivot) ───────────────────────────────
        case 'chemicals_by_planting_year': {
            $gtots      = (array)($src['grand_totals'] ?? []);
            $metaPY     = (array)($src['meta']         ?? []);
            $hasParaquat= !empty($src['has_paraquat']);

            if (empty($gtots) || array_sum($gtots) <= 0) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data bahan kimia. Isi data di <a href="pest_control.php">Pengendalian Hama</a>.</div>';
            }

            $chk('chem_paraquat_free', $hasParaquat ? 0.0 : 1.0);

            $herbQtyPY = 0.0; $insectQtyPY = 0.0; $totalHaPY = 0.0;
            foreach ($gtots as $ct => $qty) {
                $tl = mb_strtolower((string)$ct);
                if (str_contains($tl, 'herbicid') || str_contains($tl, 'herbisid')) $herbQtyPY   += (float)$qty;
                if (str_contains($tl, 'insecticid') || str_contains($tl, 'insektisid')) $insectQtyPY += (float)$qty;
            }
            foreach ($metaPY as $ym) { $totalHaPY += (float)($ym['total_ha'] ?? 0); }
            if ($totalHaPY > 0) {
                if ($herbQtyPY   > 0) $chk('chem_herbicide_ai_per_ha',   round($herbQtyPY   / $totalHaPY, 3));
                if ($insectQtyPY > 0) $chk('chem_insecticide_ai_per_ha', round($insectQtyPY / $totalHaPY, 3));
            }
            break;
        }

        // ── Harvest + Transport ───────────────────────────────────────────────
        case 'harvest_transport':
            $grandHKg = (float)($src['grand_harvest_kg'] ?? 0);
            $grandBun = (int)  ($src['grand_bunches']    ?? 0);
            $grandDKg = (float)($src['grand_deliv_kg']   ?? 0);
            $grandHCnt= (int)  ($src['grand_harvest_cnt']?? 0);

            if ($grandHKg === 0.0) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data panen. Isi data di <a href="harvest_realizations.php">Realisasi Panen</a> terlebih dahulu.</div>';
            }

            // ABW — average bunch weight
            if ($grandBun > 0)  $chk('abw_mature', round($grandHKg / $grandBun, 2));

            // Harvest losses: (harvested - delivered) / harvested — if delivery data exists
            if ($grandDKg > 0 && $grandHKg > 0) {
                $lossRatio = round(($grandHKg - $grandDKg) / $grandHKg * 100, 2);
                if ($lossRatio >= 0 && $lossRatio <= 100) {
                    $chk('losses_ratio', $lossRatio);
                }
            }
            break;

        // ── Chemicals / Pest Control Used ────────────────────────────────────
        case 'chemicals_used':
            $chems     = array_map(fn($c) => (array)$c, (array)($src['chemicals'] ?? []));
            $grandArea = (float)($src['grand_area'] ?? 0);

            if (empty($chems)) {
                return '<div class="alert alert-info py-2 px-3 small mb-0">'
                     . 'Belum ada data bahan kimia. Isi data di <a href="pest_control.php">Pengendalian Hama</a> terlebih dahulu.</div>';
            }

            // Aggregate qty by broad pesticide category
            $herbQty = 0.0; $insectQty = 0.0; $hasParaquat = false;
            foreach ($chems as $c) {
                $tRaw = mb_strtolower((string)($c['pesticide_type'] ?? '') . ' ' . (string)($c['pesticide_name'] ?? ''));
                $qty  = (float)($c['total_qty'] ?? 0);
                if (str_contains($tRaw, 'paraquat') || str_contains($tRaw, 'gramoxone')) {
                    $hasParaquat = true;
                }
                if (str_contains($tRaw, 'herbisida') || str_contains($tRaw, 'herbicide') || str_contains($tRaw, 'weed')) {
                    $herbQty += $qty;
                } elseif (str_contains($tRaw, 'insektisida') || str_contains($tRaw, 'insecticide') || str_contains($tRaw, 'insect')) {
                    $insectQty += $qty;
                }
            }

            // Check paraquat status first (binary: 0 = used, 1 = free)
            $chk('chem_paraquat_free', $hasParaquat ? 0.0 : 1.0);

            // Check herbicide and insecticide loading (kg a.i./ha) only if area is known
            if ($grandArea > 0) {
                if ($herbQty   > 0) $chk('chem_herbicide_ai_per_ha',   round($herbQty   / $grandArea, 3));
                if ($insectQty > 0) $chk('chem_insecticide_ai_per_ha', round($insectQty / $grandArea, 3));
            } else {
                // No area data — still flag paraquat; note that loading checks need area
                $checks[] = [
                    'Beban Herbisida / Insektisida per Ha',
                    '—', '<2.0 / <0.5', 'kg a.i./ha/thn',
                    'warn',
                    'RSPO P&C 2018',
                    'Data luas cakupan (ha) belum tersedia — tidak dapat menghitung beban bahan aktif per ha.',
                ];
            }
            break;

        // ── Financial Summary ─────────────────────────────────────────────────
        case 'financial_summary':
            $gm  = $src['gross_margin']  ?? null;
            $opm = $src['op_margin']     ?? null;
            $nm  = $src['net_margin']    ?? null;
            $cr  = $src['current_ratio'] ?? null;
            $der = $src['de_ratio']      ?? null;
            $dr  = $src['debt_ratio']    ?? null;
            $roa = $src['roa']           ?? null;
            $roe = $src['roe']           ?? null;

            if ($gm  !== null) $chk('fin_gross_margin',   (float)$gm);
            if ($opm !== null) $chk('fin_op_margin',      (float)$opm);
            if ($nm  !== null) $chk('fin_net_margin',     (float)$nm);
            if ($cr  !== null) $chk('fin_current_ratio',  (float)$cr);
            if ($der !== null) $chk('fin_de_ratio',       (float)$der);
            if ($roa !== null) $chk('fin_roa',            (float)$roa);
            if ($roe !== null) $chk('fin_roe',            (float)$roe);
            break;

        default:
            return '<div class="alert alert-info py-2 px-3 small mb-0">'
                 . '📋 Cek standar belum tersedia untuk tipe data ini (<code>' . htmlspecialchars($type) . '</code>). '
                 . 'Coba: "Kerapatan tanaman di ANP", "Tabel luas area di ANP", "Panjang jalan di ANP berdasarkan jenisnya", "Produksi pabrik", "Pupuk yang digunakan di ANP", "Pemberantasan hama dan penyakit di ANP", atau "Data panen dan pengangkutan di ANP", kemudian ketik "Apakah sesuai standar?"'
                 . '</div>';
    }

    if (empty($checks)) {
        // ── Fallback: show static benchmark reference table for financial_summary ─
        if ($type === 'financial_summary') {
            $finStds = [
                ['Gross Profit Margin',       '≥30%',   '%',  'GAPKI / Industri Kelapa Sawit Indonesia (2023)'],
                ['Operating Profit Margin',   '≥15%',   '%',  'GAPKI / Industri Kelapa Sawit Indonesia (2023)'],
                ['Net Profit Margin',         '≥10%',   '%',  'GAPKI / Bank Indonesia BI Rate Benchmark (2023)'],
                ['Current Ratio (Rasio Lancar)', '≥1.5x', 'x', 'OJK / Bank Indonesia — Kesehatan Keuangan (2020)'],
                ['Debt-to-Equity Ratio',      '≤1.0x',  'x',  'OJK / Bank Indonesia — Rasio Leverage Wajar (2020)'],
                ['Return on Assets (ROA)',    '≥5%',    '%',  'GAPKI / Industri Perkebunan Indonesia (2023)'],
                ['Return on Equity (ROE)',    '≥10%',   '%',  'GAPKI / Bank Indonesia BI Rate + Risk Premium (2023)'],
            ];
            $out  = '<div class="alert alert-info py-2 px-3 small mb-2">Nilai aktual tidak tersedia untuk cek otomatis — menampilkan referensi standar industri.</div>';
            $out .= '<div class="table-responsive"><table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
            $out .= '<thead class="table-light"><tr>'
                  . '<th>Parameter</th><th class="text-center">Standar Industri</th>'
                  . '<th>Sumber</th>'
                  . '</tr></thead><tbody>';
            foreach ($finStds as [$param, $std, $unit, $source]) {
                $out .= '<tr>'
                      . '<td class="fw-semibold">' . htmlspecialchars($param) . '</td>'
                      . '<td class="text-center text-muted">' . htmlspecialchars($std) . ' ' . htmlspecialchars($unit) . '</td>'
                      . '<td class="text-muted small">' . htmlspecialchars($source) . '</td>'
                      . '</tr>';
            }
            $out .= '</tbody></table></div>';
            $out .= '<p class="text-muted small mb-0 mt-1">Referensi: GAPKI · OJK · Bank Indonesia · Industri Perkebunan Kelapa Sawit Indonesia</p>';
            return $out;
        }
        return '<span class="text-muted small">Tidak cukup data untuk melakukan cek standar.</span>';
    }

    // ── Count pass/warn/fail ──────────────────────────────────────────────────
    $nPass = count(array_filter($checks, fn($c) => $c[4] === 'pass'));
    $nWarn = count(array_filter($checks, fn($c) => $c[4] === 'warn'));
    $nFail = count(array_filter($checks, fn($c) => $c[4] === 'fail'));
    $total = count($checks);

    $verdictColor = $nFail > 0 ? '#dc2626' : ($nWarn > 0 ? '#d97706' : '#16a34a');
    $verdictIcon  = $nFail > 0 ? '❌' : ($nWarn > 0 ? '⚠️' : '✅');
    $verdictText  = $nFail > 0 ? 'Tidak Memenuhi Standar' : ($nWarn > 0 ? 'Sebagian Perlu Perhatian' : 'Sesuai Standar');

    // ── Render compliance table ───────────────────────────────────────────────
    $statusIcon = ['pass' => '✅', 'warn' => '⚠️', 'fail' => '❌'];
    $statusBg   = ['pass' => '#f0fdf4', 'warn' => '#fffbeb', 'fail' => '#fef2f2'];
    $statusTxt  = ['pass' => '#15803d', 'warn' => '#92400e', 'fail' => '#991b1b'];

    $out  = '<div class="mb-2 p-2 rounded d-inline-block" style="background:' . $verdictColor . '1a;border:1px solid ' . $verdictColor . '4d">';
    $out .= '<strong style="color:' . $verdictColor . '">' . $verdictIcon . ' ' . htmlspecialchars($verdictText) . '</strong>';
    $out .= ' &mdash; <span style="font-size:.8rem">' . $nPass . ' lulus / ' . $nWarn . ' perhatian / ' . $nFail . ' tidak lulus</span>';
    $out .= '</div>';

    $out .= '<div class="table-responsive"><table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
    $out .= '<thead class="table-light"><tr>'
          . '<th>Parameter</th><th class="text-center">Aktual</th>'
          . '<th class="text-center">Standar</th><th>Status</th>'
          . '<th>Sumber</th><th>Keterangan</th>'
          . '</tr></thead><tbody>';

    foreach ($checks as $c) {
        [$label, $actual, $standard, $unit, $status, $source, $note] = $c;
        $bg  = $statusBg[$status]  ?? '#fff';
        $col = $statusTxt[$status] ?? '#000';
        $icon = $statusIcon[$status] ?? '—';
        $unitStr = $unit ? ' ' . htmlspecialchars($unit) : '';
        $out .= '<tr style="background:' . $bg . '">'
              . '<td class="fw-semibold">' . htmlspecialchars($label) . '</td>'
              . '<td class="text-center fw-bold" style="color:' . $col . '">' . htmlspecialchars((string)$actual) . $unitStr . '</td>'
              . '<td class="text-center text-muted">' . htmlspecialchars($standard) . $unitStr . '</td>'
              . '<td class="text-center">' . $icon . '</td>'
              . '<td class="text-muted small">' . htmlspecialchars($source) . '</td>'
              . '<td class="small">' . htmlspecialchars($note) . '</td>'
              . '</tr>';
    }
    $out .= '</tbody></table></div>';

    // ── LLM verdict (focused compliance prompt, different from analyze) ───────
    if (agro_ai_available()) {
        $checksText = implode("\n", array_map(
            fn($c) => "- {$c[0]}: actual={$c[1]}{$c[3]}, standard={$c[2]}{$c[3]}, status={$c[4]}, note={$c[6]}",
            $checks
        ));
        $ai_lang_instr2 = ($qna_lang === 'en')
            ? 'Respond in English. Use terms: compliant / partially non-compliant / non-compliant.'
            : 'Respond in Bahasa Indonesia. Gunakan: sesuai / sebagian tidak sesuai / tidak sesuai standar.';
        $system = <<<PROMPT
You are an Indonesian plantation compliance auditor specializing in GAPKI, PPKS, and Ministry of Agriculture standards for oil palm (kelapa sawit).
Given compliance check results, provide a SHORT verdict. {$ai_lang_instr2}
- For each FAIL or WARN item: one-sentence action recommendation
- Reference specific Indonesian standards (SNI, Permentan, PPKS) by number if applicable
- Max 4 sentences total. Plain text only, no bullet symbols, no markdown.
PROMPT;
        $userPrompt = "Scope: {$scope}\nData type: {$type}\n\nCompliance results:\n{$checksText}";
        $llmReply = agro_ai_chat($system, $userPrompt);
        if ($llmReply !== null) {
            $safeReply = nl2br(htmlspecialchars(trim($llmReply), ENT_QUOTES, 'UTF-8'));
            $out .= '<div class="mt-2 p-2 rounded" style="background:#f0f9ff;border:1px solid #bae6fd;font-size:.85rem;line-height:1.7">'
                  . '<span style="font-size:.72rem;font-weight:600;color:#0369a1;letter-spacing:.4px">🤖 AI VERDICT</span>'
                  . '<div class="mt-1">' . $safeReply . '</div>'
                  . '</div>';
        }
    }

    $out .= '<p class="text-muted small mb-0 mt-1">Referensi: GAPKI · PPKS Medan · SNI 8171 · Permentan RI</p>';
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
// Shared Highcharts + highcharts-3d loader (emitted once per page)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the shared window._agroHcReady() bootstrap script on the first call;
 * returns '' on every subsequent call (already emitted).
 *
 * window._agroHcReady(cb) — guarantees cb() is called only after both
 * highcharts.js and highcharts-3d.js are fully loaded, even when multiple
 * chart instances on the same page call it concurrently.
 */
function agro_highcharts_loader_once(): string
{
    static $emitted = false;
    if ($emitted) { return ''; }
    $emitted = true;

    return <<<'JS'
<script>
(function(){
  // Serialised loader: loads highcharts.js then highcharts-3d.js exactly once.
  // All chart callbacks queue here and fire only after both scripts are ready.
  if (window._agroHcReady) return; // already defined on this page

  var _q = [];          // pending callbacks
  var _st = 'idle';     // idle | loadingHC | loadingHC3D | ready

  function _flush() {
    var q = _q.splice(0);
    for (var i = 0; i < q.length; i++) { q[i](); }
  }

  function _addScript(src, onOk, onErr) {
    var s = document.createElement('script');
    s.src = src;
    s.onload  = onOk;
    s.onerror = onErr || onOk; // if CDN also fails, just call ok anyway (best-effort)
    document.head.appendChild(s);
  }

  function _load3d() {
    _st = 'loadingHC3D';
    _addScript(
      'js/highcharts-3d.js',
      function() { _st = 'ready'; _flush(); },
      function() {
        _addScript(
          'https://cdn.jsdelivr.net/npm/highcharts@11/highcharts-3d.js',
          function() { _st = 'ready'; _flush(); }
        );
      }
    );
  }

  function _loadHC() {
    _st = 'loadingHC';
    _addScript(
      'js/highcharts.js',
      _load3d,
      function() {
        _addScript(
          'https://cdn.jsdelivr.net/npm/highcharts@11/highcharts.js',
          _load3d
        );
      }
    );
  }

  window._agroHcReady = function(cb) {
    if (_st === 'ready') { cb(); return; }
    _q.push(cb);
    if (_st === 'idle') {
      // Always load both scripts in sequence — even if Highcharts happens
      // to already be on the window, the 3D module may not be applied yet.
      _loadHC();
    }
  };
})();
</script>
JS;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3-D Pie chart helper — uses Highcharts + highcharts-3d (loaded on demand via CDN)
// Returns the full HTML block (container div + inline script).
//
// $chartId       : unique DOM id string
// $labels        : PHP array of label strings
// $data          : PHP array of numeric values (same order as $labels)
// $colors        : PHP array of hex colour strings
// $tooltipSuffix : unit appended in tooltip, e.g. " ha" or " kg" or ""
// ─────────────────────────────────────────────────────────────────────────────

function agro_pie3d_html(string $chartId, array $labels, array $data, array $colors, string $tooltipSuffix = ''): string
{
    $seriesData = [];
    foreach ($labels as $i => $lbl) {
        $seriesData[] = ['name' => $lbl, 'y' => (float)($data[$i] ?? 0), 'color' => $colors[$i] ?? '#60a5fa'];
    }
    $seriesJson  = json_encode($seriesData);
    $suffixJson  = json_encode($tooltipSuffix);
    $idJson      = json_encode($chartId);

    $loader = agro_highcharts_loader_once();

    return <<<HTML
{$loader}<div id="{$chartId}" style="width:100%;max-width:520px;height:380px;margin-top:.75rem"></div>
<script>
(function(){
  var containerId = {$idJson};
  var seriesData  = {$seriesJson};
  var suffix      = {$suffixJson};

  function drawChart() {
    Highcharts.chart(containerId, {
      chart: {
        type: 'pie',
        options3d: { enabled: true, alpha: 45, beta: 0 },
        backgroundColor: 'transparent',
        margin: [0, 0, 60, 0],
        spacing: [10, 10, 10, 10]
      },
      title: { text: '' },
      credits: { enabled: false },
      tooltip: {
        pointFormatter: function() {
          return '<b>' + this.y.toLocaleString('id-ID') + suffix + '</b><br/>' + this.percentage.toFixed(1) + '%';
        }
      },
      plotOptions: {
        pie: {
          depth: 45,
          allowPointSelect: true,
          cursor: 'pointer',
          dataLabels: {
            enabled: true,
            formatter: function() {
              return this.percentage > 2
                ? '<b>' + this.point.name + '</b><br/>' + this.percentage.toFixed(1) + '%'
                : null;
            },
            style: { fontSize: '11px' }
          },
          showInLegend: true
        }
      },
      legend: {
        enabled: true,
        layout: 'horizontal',
        align: 'center',
        verticalAlign: 'bottom',
        itemStyle: { fontSize: '11px' }
      },
      series: [{ name: 'Data', colorByPoint: true, data: seriesData }]
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ window._agroHcReady(drawChart); });
  } else {
    window._agroHcReady(drawChart);
  }
})();
</script>
HTML;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3-D Bar/Column chart helper — uses Highcharts + highcharts-3d (loaded on demand via CDN)
// Returns the full HTML block (container div + inline script).
//
// $chartId       : unique DOM id string
// $categories    : PHP array of X-axis label strings
// $datasets      : PHP array of ['name'=>string, 'data'=>float[], 'color'=>string]
// $yTitle        : Y-axis label, e.g. "Hektar (ha)"
// $tooltipSuffix : unit appended per value in tooltip, e.g. " ha" or " kg"
// ─────────────────────────────────────────────────────────────────────────────

function agro_bar3d_html(string $chartId, array $categories, array $datasets, string $yTitle = '', string $tooltipSuffix = ''): string
{
    $catJson      = json_encode(array_values($categories));
    $dsJson       = json_encode($datasets);
    $yTitleJson   = json_encode($yTitle);
    $suffixJson   = json_encode($tooltipSuffix);
    $idJson       = json_encode($chartId);

    $loader = agro_highcharts_loader_once();

    return <<<HTML
{$loader}<div id="{$chartId}" style="width:100%;max-width:680px;height:380px;margin-top:.75rem"></div>
<script>
(function(){
  var containerId = {$idJson};
  var categories  = {$catJson};
  var datasets    = {$dsJson};
  var yTitle      = {$yTitleJson};
  var suffix      = {$suffixJson};

  function buildSeries() {
    return datasets.map(function(ds) {
      return { name: ds.name, data: ds.data, color: ds.color };
    });
  }

  function drawChart() {
    Highcharts.chart(containerId, {
      chart: {
        type: 'column',
        options3d: { enabled: true, alpha: 10, beta: 15, depth: 50, viewDistance: 25 },
        backgroundColor: 'transparent',
        spacing: [10, 10, 15, 10]
      },
      title: { text: '' },
      credits: { enabled: false },
      xAxis: {
        categories: categories,
        labels: { rotation: -35, style: { fontSize: '11px' } }
      },
      yAxis: {
        title: { text: yTitle },
        min: 0
      },
      tooltip: {
        formatter: function() {
          return '<b>' + this.x + '</b><br/>' + this.series.name + ': <b>' +
            Highcharts.numberFormat(this.y, 0, ',', '.') + suffix + '</b>';
        }
      },
      plotOptions: {
        column: {
          depth: 25,
          grouping: true,
          dataLabels: { enabled: false }
        }
      },
      legend: {
        enabled: datasets.length > 1,
        itemStyle: { fontSize: '11px' }
      },
      series: buildSeries()
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ window._agroHcReady(drawChart); });
  } else {
    window._agroHcReady(drawChart);
  }
})();
</script>
HTML;
}

// ─────────────────────────────────────────────────────────────────────────────
// Render answer helper
// ─────────────────────────────────────────────────────────────────────────────

function agro_render_answer(array $answer): string
{
    $type = (string)($answer['type'] ?? 'unknown');
    ob_start();

    switch ($type) {

        case 'harvest_summary': {
            $scopeHs   = !empty($answer['scope']) ? htmlspecialchars($answer['scope']) : 'Semua Kebun';
            $grandKg   = (float)($answer['total_kg'] ?? 0);
            $grandBun  = (int)  ($answer['bunches']  ?? 0);
            $grandRec  = (int)  ($answer['records']  ?? 0);
            $byDiv     = (array)($answer['by_division'] ?? []);

            echo '<span class="qna-tag tag-harvest">🌿 Harvest</span> <strong>Harvest Summary — ' . $scopeHs . '</strong>';

            if (!empty($answer['first_date'])) {
                echo ' <span class="badge bg-secondary ms-1">'
                   . htmlspecialchars((string)$answer['first_date']) . ' – '
                   . htmlspecialchars((string)$answer['last_date'])
                   . '</span>';
            }

            if ($grandKg === 0.0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data panen untuk wilayah ini. '
                   . 'Isi data di <a href="harvest_realizations.php">Realisasi Panen</a>.</p>';
                break;
            }

            $grandAbw = $grandBun > 0 ? $grandKg / $grandBun : null;

            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . agro_fmt_kg($grandKg) . '</div><div class="qna-stat-lbl">Total FFB</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandBun) . '</div><div class="qna-stat-lbl">Bunches</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandRec) . '</div><div class="qna-stat-lbl">Records</div></div>';
            if ($grandAbw !== null) {
                echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandAbw, 2) . ' kg</div><div class="qna-stat-lbl">ABW</div></div>';
            }
            echo '</div>';

            if (!empty($byDiv)) {
                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead class="table-success"><tr>'
                   . '<th>Division</th>'
                   . '<th class="text-end">Records</th>'
                   . '<th class="text-end">Total FFB (kg)</th>'
                   . '<th class="text-end">Bunches</th>'
                   . '<th class="text-end">ABW (kg)</th>'
                   . '</tr></thead><tbody>';
                foreach ($byDiv as $r) {
                    $r       = (array)$r;
                    $rKg     = (float)$r['total_kg'];
                    $rBun    = (int)  $r['total_bunches'];
                    $rRec    = (int)  $r['harvest_count'];
                    $rAbw    = $rBun > 0 ? number_format($rKg / $rBun, 2) : '—';
                    echo '<tr>'
                       . '<td class="fw-semibold">' . htmlspecialchars((string)$r['division_name']) . '</td>'
                       . '<td class="text-end">' . number_format($rRec) . '</td>'
                       . '<td class="text-end fw-bold">' . number_format($rKg, 0) . '</td>'
                       . '<td class="text-end">' . number_format($rBun) . '</td>'
                       . '<td class="text-end">' . $rAbw . '</td>'
                       . '</tr>';
                }
                echo '<tr class="table-dark fw-bold">'
                   . '<td>TOTAL</td>'
                   . '<td class="text-end">' . number_format($grandRec) . '</td>'
                   . '<td class="text-end">' . number_format($grandKg, 0) . '</td>'
                   . '<td class="text-end">' . number_format($grandBun) . '</td>'
                   . '<td class="text-end">' . ($grandAbw !== null ? number_format($grandAbw, 2) : '—') . '</td>'
                   . '</tr>';
                echo '</tbody></table></div>';
                echo '<p class="text-muted small mb-0">ABW = Average Bunch Weight &bull; Data from <a href="harvest_realizations.php">Harvest Realizations</a></p>';
            }

            // Auto-analysis: triggered when intent was "Analisa Hasil Panen [scope]"
            if (!empty($answer['auto_analyze']) && $grandKg > 0) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        case 'harvest_total':
            $scope = htmlspecialchars((string)($answer['scope'] ?? ''));
            $kg    = agro_fmt_kg((float)($answer['total_kg'] ?? 0));
            echo '<span class="qna-tag tag-harvest">🌿 Harvest</span> ';
            echo '<strong>' . $scope . '</strong>';
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . $kg . '</div><div class="qna-stat-lbl">Total FFB</div></div>';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . number_format((int)($answer['bunches'] ?? 0)) . '</div><div class="qna-stat-lbl">Bunches</div></div>';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . (int)($answer['records'] ?? 0) . '</div><div class="qna-stat-lbl">Records</div></div>';
            echo '</div>';
            if (!empty($answer['first_date'])) {
                echo '<p class="text-muted small mt-1 mb-0">Periode: ' . htmlspecialchars((string)$answer['first_date']) . ' – ' . htmlspecialchars((string)$answer['last_date']) . '</p>';
            }
            if ((float)($answer['total_kg'] ?? 0) === 0.0) {
                echo '<p class="text-muted small mt-1 mb-0">Belum ada data panen untuk wilayah ini.</p>';
            }

            // Auto-analysis: triggered when intent was "Analisa Hasil Panen [scope]"
            if (!empty($answer['auto_analyze']) && (float)($answer['total_kg'] ?? 0) > 0) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;

        case 'blocks_in_division':
            $blocks     = (array)($answer['blocks'] ?? []);
            $count      = (int)($answer['count'] ?? 0);
            $showDiv    = !empty($answer['show_division']);
            echo '<span class="qna-tag tag-block">🌱 Blok</span> ';
            echo '<strong>' . htmlspecialchars((string)($answer['division'] ?? '')) . '</strong>';
            echo ' &mdash; <span class="text-muted">' . $count . ' blok</span>';
            if ($count === 0) {
                echo '<p class="text-muted mb-0 mt-1 small">Belum ada data blok.</p>';
            } elseif ($showDiv) {
                // Group by division_name
                $byDiv = [];
                foreach ($blocks as $b) {
                    $b = (array)$b;
                    $byDiv[(string)($b['division_name'] ?? '—')][] = $b;
                }
                foreach ($byDiv as $divName => $divBlocks) {
                    echo '<p class="mb-1 mt-2 small fw-semibold text-muted">' . htmlspecialchars($divName) . '</p>';
                    echo '<div class="qna-grid">';
                    foreach ($divBlocks as $b) {
                        $label = htmlspecialchars((string)$b['block_name']);
                        $code  = htmlspecialchars((string)($b['block_code'] ?? ''));
                        $ha    = number_format((float)($b['area'] ?? 0), 2);
                        $stat  = htmlspecialchars((string)($b['status'] ?? ''));
                        $yr    = htmlspecialchars((string)($b['planting_year'] ?? ''));
                        echo '<span class="qna-chip">' . $label . ' <small class="text-muted">(' . $code . ') ' . $ha . ' ha &bull; ' . $stat . ' &bull; TH ' . $yr . '</small></span>';
                    }
                    echo '</div>';
                }
            } else {
                echo '<div class="qna-grid mt-2">';
                foreach ($blocks as $b) {
                    $b      = (array)$b;
                    $label  = htmlspecialchars((string)$b['block_name']);
                    $code   = htmlspecialchars((string)($b['block_code'] ?? ''));
                    $ha     = number_format((float)($b['area'] ?? 0), 2);
                    $stat   = htmlspecialchars((string)($b['status'] ?? ''));
                    $yr     = htmlspecialchars((string)($b['planting_year'] ?? ''));
                    echo '<span class="qna-chip">' . $label . ' <small class="text-muted">(' . $code . ') ' . $ha . ' ha &bull; ' . $stat . ' &bull; TH ' . $yr . '</small></span>';
                }
                echo '</div>';
            }
            break;

        case 'divisions_in_bu':
            $divs      = (array)($answer['divisions'] ?? []);
            $count     = (int)($answer['count'] ?? 0);
            $groupedBu = !empty($answer['grouped_by_bu']);
            echo '<span class="qna-tag tag-division">🏢 Division</span> ';
            echo '<strong>' . htmlspecialchars((string)($answer['business_unit'] ?? '')) . '</strong>';
            echo ' &mdash; <span class="text-muted">' . $count . ' divisi</span>';
            if ($count === 0) {
                echo '<p class="text-muted mb-0 mt-1 small">Belum ada data divisi.</p>';
            } elseif ($groupedBu) {
                // Group chips by business unit
                $byBu = [];
                foreach ($divs as $d) {
                    $d = (array)$d;
                    $buLabel = (string)($d['business_unit'] ?? '—');
                    $byBu[$buLabel][] = $d;
                }
                foreach ($byBu as $buName => $buDivs) {
                    echo '<p class="mb-1 mt-2 small fw-semibold text-muted">' . htmlspecialchars($buName) . '</p>';
                    echo '<div class="qna-grid">';
                    foreach ($buDivs as $d) {
                        $name  = htmlspecialchars((string)$d['division_name']);
                        $ha    = number_format((float)($d['total_area_ha'] ?? 0), 2);
                        $type_ = htmlspecialchars((string)($d['division_type'] ?? ''));
                        echo '<span class="qna-chip qna-chip-div">' . $name . ' <small class="text-muted">' . ($type_ ? $type_ . ' &bull; ' : '') . $ha . ' ha</small></span>';
                    }
                    echo '</div>';
                }
            } else {
                echo '<div class="qna-grid mt-2">';
                foreach ($divs as $d) {
                    $d     = (array)$d;
                    $name  = htmlspecialchars((string)$d['division_name']);
                    $ha    = number_format((float)($d['total_area_ha'] ?? 0), 2);
                    $type_ = htmlspecialchars((string)($d['division_type'] ?? ''));
                    echo '<span class="qna-chip qna-chip-div">' . $name . ' <small class="text-muted">' . ($type_ ? $type_ . ' &bull; ' : '') . $ha . ' ha</small></span>';
                }
                echo '</div>';
            }
            break;

        case 'bus_in_company':
            $units = (array)($answer['units'] ?? []);
            $count = (int)($answer['count'] ?? 0);
            echo '<span class="qna-tag tag-company">🏭 Business Unit</span> ';
            echo '<strong>' . htmlspecialchars((string)($answer['company'] ?? '')) . '</strong>';
            echo ' &mdash; <span class="text-muted">' . $count . ' unit</span>';
            if ($count === 0) {
                echo '<p class="text-muted mb-0 mt-1 small">Belum ada data.</p>';
            } else {
                echo '<div class="qna-grid mt-2">';
                foreach ($units as $u) {
                    $u    = (array)$u;
                    $name = htmlspecialchars((string)$u['unit_name']);
                    $type_= htmlspecialchars((string)($u['unit_type'] ?? ''));
                    $prov = htmlspecialchars((string)($u['province'] ?? ''));
                    echo '<span class="qna-chip qna-chip-div">' . $name . ' <small class="text-muted">(' . $type_ . ($prov ? ', ' . $prov : '') . ')</small></span>';
                }
                echo '</div>';
            }
            break;

        case 'count_blocks':
            echo '<span class="qna-tag tag-block">🌱 Block</span> In ';
            echo '<strong>' . htmlspecialchars((string)($answer['scope'] ?? '')) . '</strong>';
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . (int)($answer['count'] ?? 0) . '</div><div class="qna-stat-lbl">Blocks</div></div>';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . number_format((float)($answer['total_ha'] ?? 0), 2) . '</div><div class="qna-stat-lbl">Hectares</div></div>';
            echo '</div>';
            break;

        case 'area':
            $scopeType  = htmlspecialchars((string)($answer['scope_type'] ?? ''));
            $breakdown  = (array)($answer['breakdown'] ?? []);
            $showCounts = !empty($answer['show_counts']);
            echo '<span class="qna-tag tag-division">📐 Luas</span> ';
            if ($scopeType) echo '<span class="text-muted small">' . $scopeType . '</span> ';
            echo '<strong>' . htmlspecialchars((string)($answer['scope'] ?? '')) . '</strong>';
            echo '<div class="mt-2"><span class="qna-count-badge">' . number_format((float)($answer['ha'] ?? 0), 2) . '</span> <span class="text-muted">hectares (ha)</span></div>';
            if (!empty($breakdown)) {
                if ($showCounts) {
                    // ── Company summary table ─────────────────────────────────
                    $grandHa  = (float)($answer['ha'] ?? 0);
                    $detailBU  = array_map(fn($r) => (array)$r, (array)($answer['detail_bu']  ?? []));
                    $detailDiv = array_map(fn($r) => (array)$r, (array)($answer['detail_div'] ?? []));

                    // Index divisions by bu_id for quick lookup
                    $divByBU = [];
                    foreach ($detailDiv as $dv) {
                        $divByBU[(int)$dv['business_unit_id']][] = $dv;
                    }

                    echo '<div class="table-responsive mt-2">';
                    echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                    echo '<thead class="table-success"><tr>'
                        . '<th>Company / Business Unit / Division</th>'
                        . '<th class="text-end">Luas Tertanam (ha)</th>'
                        . '<th class="text-end">Non-Planted (ha)</th>'
                        . '<th class="text-end">Total (ha)</th>'
                        . '<th class="text-end">%</th>'
                        . '<th class="text-end">Blok</th>'
                        . '</tr></thead><tbody>';

                    $grandNpHa = array_sum(array_column($breakdown, 'non_planted_ha'));
                    $grandTotalHa = $grandHa + $grandNpHa;

                    foreach ($breakdown as $bk) {
                        $bk       = (array)$bk;
                        $coId     = (int)($bk['company_id'] ?? 0);
                        $coHa     = (float)$bk['ha'];
                        $coNpHa   = (float)($bk['non_planted_ha'] ?? 0);
                        $coTot    = $coHa + $coNpHa;
                        $coPct    = $grandTotalHa > 0 ? number_format($coTot / $grandTotalHa * 100, 1) : '—';
                        // Company row
                        echo '<tr class="table-secondary fw-bold">'
                            . '<td>🏢 ' . htmlspecialchars((string)$bk['unit_name']) . '</td>'
                            . '<td class="text-end">' . number_format($coHa, 2) . '</td>'
                            . '<td class="text-end text-warning-emphasis">' . ($coNpHa > 0 ? number_format($coNpHa, 2) : '—') . '</td>'
                            . '<td class="text-end">' . number_format($coTot, 2) . '</td>'
                            . '<td class="text-end">' . $coPct . '%</td>'
                            . '<td class="text-end">' . (int)($bk['block_count'] ?? 0) . '</td>'
                            . '</tr>';

                        // BU rows under this company
                        $buRows = array_filter($detailBU, fn($r) => (int)$r['company_id'] === $coId);
                        foreach ($buRows as $bu) {
                            $bu    = (array)$bu;
                            $buId  = (int)$bu['business_unit_id'];
                            $buHa  = (float)$bu['ha'];
                            $buNpHa= (float)($bu['non_planted_ha'] ?? 0);
                            $buTot = $buHa + $buNpHa;
                            $buPct = $grandTotalHa > 0 ? number_format($buTot / $grandTotalHa * 100, 1) : '—';
                            echo '<tr class="table-light">'
                                . '<td style="padding-left:1.5rem">🌴 ' . htmlspecialchars((string)$bu['unit_name']) . '</td>'
                                . '<td class="text-end">' . number_format($buHa, 2) . '</td>'
                                . '<td class="text-end text-warning-emphasis">' . ($buNpHa > 0 ? number_format($buNpHa, 2) : '—') . '</td>'
                                . '<td class="text-end">' . number_format($buTot, 2) . '</td>'
                                . '<td class="text-end text-muted">' . $buPct . '%</td>'
                                . '<td class="text-end">' . (int)($bu['block_count'] ?? 0) . '</td>'
                                . '</tr>';

                            // Division rows under this BU
                            foreach ($divByBU[$buId] ?? [] as $dv) {
                                $dv    = (array)$dv;
                                $dvHa  = (float)$dv['ha'];
                                $dvNpHa= (float)($dv['non_planted_ha'] ?? 0);
                                $dvTot = $dvHa + $dvNpHa;
                                $dvPct = $grandTotalHa > 0 ? number_format($dvTot / $grandTotalHa * 100, 1) : '—';
                                echo '<tr>'
                                    . '<td style="padding-left:3rem;color:#57606a">📍 ' . htmlspecialchars((string)$dv['division_name']) . '</td>'
                                    . '<td class="text-end" style="color:#57606a">' . number_format($dvHa, 2) . '</td>'
                                    . '<td class="text-end" style="color:#92400e">' . ($dvNpHa > 0 ? number_format($dvNpHa, 2) : '—') . '</td>'
                                    . '<td class="text-end" style="color:#57606a">' . number_format($dvTot, 2) . '</td>'
                                    . '<td class="text-end text-muted">' . $dvPct . '%</td>'
                                    . '<td class="text-end text-muted">' . (int)($dv['block_count'] ?? 0) . '</td>'
                                    . '</tr>';
                            }
                        }
                    }
                    echo '<tr class="table-dark fw-bold">'
                        . '<td>TOTAL</td>'
                        . '<td class="text-end">' . number_format($grandHa, 2) . '</td>'
                        . '<td class="text-end">' . ($grandNpHa > 0 ? number_format($grandNpHa, 2) : '—') . '</td>'
                        . '<td class="text-end">' . number_format($grandTotalHa, 2) . '</td>'
                        . '<td></td><td></td>'
                        . '</tr>';
                    echo '</tbody></table></div>';
                    if ($grandNpHa === 0.0) {
                        echo '<p class="text-muted small mt-1 mb-0">Non-Planted: belum ada data di <a href="block_area_components.php">Komponen Luas Blok</a>.</p>';
                    } else {
                        // ── Non-planted detail table per division per category ──────
                        $npDetail     = array_map(fn($r) => (array)$r, (array)($answer['np_detail']     ?? []));
                        $npCategories = (array)($answer['np_categories'] ?? []);

                        if (!empty($npDetail) && !empty($npCategories)) {
                            // Pivot: index rows by "company|bu|division" → [category_code => ha]
                            $npPivot = [];
                            $npRowKeys = []; // ordered list of row keys
                            foreach ($npDetail as $nr) {
                                $rk = $nr['company_name'] . '||' . $nr['unit_name'] . '||' . $nr['division_name'];
                                if (!isset($npPivot[$rk])) {
                                    $npPivot[$rk]  = ['company' => $nr['company_name'], 'bu' => $nr['unit_name'], 'div' => $nr['division_name'], 'cats' => []];
                                    $npRowKeys[] = $rk;
                                }
                                $npPivot[$rk]['cats'][$nr['category_code']] = (float)$nr['ha'];
                            }

                            $catCodes = array_keys($npCategories);
                            echo '<p class="fw-semibold small mt-3 mb-1" style="color:#92400e">🌿 Non-Planted Area Breakdown by Division</p>';
                            echo '<div class="table-responsive">';
                            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                            echo '<thead class="table-warning"><tr>'
                                . '<th>Company</th><th>Business Unit</th><th>Division</th>';
                            foreach ($npCategories as $code => $name) {
                                echo '<th class="text-end">' . htmlspecialchars($name) . '</th>';
                            }
                            echo '<th class="text-end fw-bold">Total (ha)</th></tr></thead><tbody>';

                            $catTotals = array_fill_keys($catCodes, 0.0);
                            $grandNpTotal = 0.0;
                            foreach ($npRowKeys as $rk) {
                                $row    = $npPivot[$rk];
                                $rowTot = array_sum($row['cats']);
                                $grandNpTotal += $rowTot;
                                echo '<tr>'
                                    . '<td>' . htmlspecialchars($row['company']) . '</td>'
                                    . '<td>' . htmlspecialchars($row['bu'])      . '</td>'
                                    . '<td class="fw-semibold">' . htmlspecialchars($row['div']) . '</td>';
                                foreach ($catCodes as $cc) {
                                    $v = $row['cats'][$cc] ?? 0.0;
                                    $catTotals[$cc] += $v;
                                    echo '<td class="text-end">' . ($v > 0 ? number_format($v, 2) : '—') . '</td>';
                                }
                                echo '<td class="text-end fw-bold">' . number_format($rowTot, 2) . '</td></tr>';
                            }
                            // Totals row
                            echo '<tr class="table-dark fw-bold"><td colspan="3">TOTAL</td>';
                            foreach ($catCodes as $cc) {
                                echo '<td class="text-end">' . ($catTotals[$cc] > 0 ? number_format($catTotals[$cc], 2) : '—') . '</td>';
                            }
                            echo '<td class="text-end">' . number_format($grandNpTotal, 2) . '</td></tr>';
                            echo '</tbody></table></div>';
                        }
                    }
                } else {
                    echo '<div class="qna-grid mt-2">';
                    foreach ($breakdown as $bk) {
                        $bk = (array)$bk;
                        echo '<span class="qna-chip qna-chip-div">'
                            . htmlspecialchars((string)$bk['unit_name'])
                            . ' <small class="text-muted">' . number_format((float)$bk['ha'], 2) . ' ha</small></span>';
                    }
                    echo '</div>';
                }
            }
            break;

        case 'nursery_summary': {
            $seeds    = (int)($answer['total_seeds']   ?? 0);
            $sprouts  = (int)($answer['total_sprouts'] ?? 0);
            $polybag  = (int)($answer['total_polybag'] ?? 0);
            $ready    = (int)($answer['total_ready']   ?? 0);
            $germRate = $answer['germ_rate']      !== null ? (float)$answer['germ_rate']      : null;
            $preRate  = $answer['pre_nurs_rate']  !== null ? (float)$answer['pre_nurs_rate']  : null;
            $mainRate = $answer['main_nurs_rate'] !== null ? (float)$answer['main_nurs_rate'] : null;

            $nursScope = !empty($answer['scope']) ? ' — ' . htmlspecialchars($answer['scope']) : '';
            echo '<span class="qna-tag tag-harvest">🌱 Nursery</span> <strong>Nursery Summary' . $nursScope . '</strong>';
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($seeds)   . '</div><div class="qna-stat-lbl">Seeds</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($sprouts) . '</div><div class="qna-stat-lbl">Sprouts</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($polybag) . '</div><div class="qna-stat-lbl">In Polybag</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($ready)   . '</div><div class="qna-stat-lbl">Ready to Plant</div></div>';
            if ($germRate !== null) {
                $gc = $germRate >= 80 ? '#16a34a' : ($germRate >= 70 ? '#d97706' : '#dc2626');
                echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . $gc . '">' . number_format($germRate, 1) . '%</div><div class="qna-stat-lbl">Germination Rate</div></div>';
            }
            if ($preRate !== null) {
                $pc = $preRate >= 90 ? '#16a34a' : ($preRate >= 80 ? '#d97706' : '#dc2626');
                echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . $pc . '">' . number_format($preRate, 1) . '%</div><div class="qna-stat-lbl">Survival Pre-Nursery</div></div>';
            }
            if ($mainRate !== null) {
                $mc = $mainRate >= 90 ? '#16a34a' : ($mainRate >= 82 ? '#d97706' : '#dc2626');
                echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . $mc . '">' . number_format($mainRate, 1) . '%</div><div class="qna-stat-lbl">Survival Main-Nursery</div></div>';
            }
            echo '</div>';

            // Per-status breakdown
            $byStatus = (array)($answer['by_status'] ?? []);
            if (!empty($byStatus)) {
                echo '<div class="d-flex flex-wrap gap-2 mt-2">';
                foreach ($byStatus as $st) {
                    $st = (array)$st;
                    echo '<span class="badge bg-secondary">' . htmlspecialchars((string)$st['status']) . ': ' . number_format((int)$st['batches']) . ' batch / ' . number_format((int)$st['seeds']) . ' benih</span>';
                }
                echo '</div>';
            }

            // Per-variety table
            $byVariety = (array)($answer['by_variety'] ?? []);
            if (!empty($byVariety)) {
                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead class="table-success"><tr>'
                   . '<th>Varietas</th>'
                   . '<th class="text-end">Benih</th>'
                   . '<th class="text-end">Kecambah</th>'
                   . '<th class="text-end">Daya Kecambah</th>'
                   . '<th class="text-end">Polybag</th>'
                   . '<th class="text-end">Siap Salur</th>'
                   . '<th class="text-end">Hidup Main-Nursery</th>'
                   . '</tr></thead><tbody>';
                foreach ($byVariety as $v) {
                    $v    = (array)$v;
                    $vS   = (int)$v['seeds'];
                    $vSp  = (int)$v['sprouts'];
                    $vPb  = (int)$v['polybag'];
                    $vRd  = (int)$v['ready'];
                    $vGr  = $vS  > 0 ? number_format($vSp / $vS  * 100, 1) . '%' : '–';
                    $vMn  = $vPb > 0 ? number_format($vRd / $vPb * 100, 1) . '%' : '–';
                    echo '<tr>'
                       . '<td class="fw-semibold">' . htmlspecialchars((string)$v['variety_name']) . ' <small class="text-muted">(' . htmlspecialchars((string)$v['variety_code']) . ')</small></td>'
                       . '<td class="text-end">' . number_format($vS)  . '</td>'
                       . '<td class="text-end">' . number_format($vSp) . '</td>'
                       . '<td class="text-end text-primary fw-bold">' . $vGr . '</td>'
                       . '<td class="text-end">' . number_format($vPb) . '</td>'
                       . '<td class="text-end">' . number_format($vRd) . '</td>'
                       . '<td class="text-end fw-bold" style="color:#16a34a">' . $vMn . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }
            if (!empty($answer['first_date'])) {
                echo '<p class="text-muted small mt-1 mb-0">Periode germination: '
                   . htmlspecialchars((string)$answer['first_date']) . ' – '
                   . htmlspecialchars((string)$answer['last_date'])
                   . ' &nbsp;|&nbsp; ' . (int)($answer['batches'] ?? 0) . ' batch</p>';
            }

            // Auto-analysis: triggered when intent was "Analisa Pembibitan [scope]"
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        // ── Nursery by Batch — HTML table ─────────────────────────────────────
        case 'nursery_by_batch': {
            $scope   = htmlspecialchars((string)($answer['scope']   ?? ''));
            $batches = array_map(fn($b) => (array)$b, (array)($answer['batches'] ?? []));
            $totals  = (array)($answer['totals'] ?? []);
            $count   = (int)($answer['count'] ?? 0);
            $isEmpty = !empty($answer['empty']);

            // Status colour map
            $stColors = [
                'Germination' => 'primary',
                'Sprout'      => 'info',
                'Polybag'     => 'warning',
                'Ready'       => 'success',
                'Distributed' => 'secondary',
            ];

            echo '<span class="qna-tag tag-harvest">🌱 Pembibitan per Batch</span> ';
            echo '<strong>' . $scope . '</strong>';
            echo ' &mdash; <span class="text-muted">' . $count . ' batch</span>';

            // DB error banner
            if (!empty($answer['db_error'])) {
                echo '<div class="alert alert-warning py-2 px-3 mt-2 mb-0" style="font-size:.8rem">'
                   . '<strong>⚠ Query error:</strong> ' . htmlspecialchars((string)$answer['db_error'])
                   . '</div>';
                break;
            }

            if ($isEmpty || $count === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data pembibitan.'
                   . ' Isi data di <a href="nursery_stock.php">Nursery Stock</a>.</p>';
                break;
            }

            // Grand-total stat badges
            $gtS  = (int)  ($totals['seeds']    ?? 0);
            $gtSp = (int)  ($totals['sprouts']  ?? 0);
            $gtPb = (int)  ($totals['polybag']  ?? 0);
            $gtRd = (int)  ($totals['ready']    ?? 0);
            $tgR  = $totals['germ_rate']  !== null ? (float)$totals['germ_rate']  : null;
            $tpR  = $totals['pre_rate']   !== null ? (float)$totals['pre_rate']   : null;
            $tmR  = $totals['main_rate']  !== null ? (float)$totals['main_rate']  : null;

            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . $count . '</div><div class="qna-stat-lbl">Batch</div></div>';
            if ($gtS  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($gtS)  . '</div><div class="qna-stat-lbl">Seeds</div></div>';
            if ($gtSp > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($gtSp) . '</div><div class="qna-stat-lbl">Sprouts</div></div>';
            if ($gtPb > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($gtPb) . '</div><div class="qna-stat-lbl">Polybag</div></div>';
            if ($gtRd > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($gtRd) . '</div><div class="qna-stat-lbl">Ready to Plant</div></div>';
            if ($tgR  !== null) {
                $gc = $tgR >= 80 ? '#16a34a' : ($tgR >= 70 ? '#d97706' : '#dc2626');
                echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . $gc . '">' . number_format($tgR, 1) . '%</div><div class="qna-stat-lbl">Germination Rate</div></div>';
            }
            if ($tmR !== null) {
                $mc = $tmR >= 90 ? '#16a34a' : ($tmR >= 82 ? '#d97706' : '#dc2626');
                echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . $mc . '">' . number_format($tmR, 1) . '%</div><div class="qna-stat-lbl">Survival Main-Nursery</div></div>';
            }
            echo '</div>';

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';
            echo '<thead class="table-dark"><tr>'
               . '<th>Batch</th>'
               . '<th>Estate</th>'
               . '<th>Varietas</th>'
               . '<th>Sumber Benih</th>'
               . '<th class="text-center">Tgl Kecambah</th>'
               . '<th class="text-center">Status</th>'
               . '<th class="text-end">Benih</th>'
               . '<th class="text-end">Kecambah</th>'
               . '<th class="text-end text-warning">Daya Kecambah</th>'
               . '<th class="text-end">Polybag</th>'
               . '<th class="text-end">Siap Salur</th>'
               . '<th class="text-end text-success">Hidup Main</th>'
               . '</tr></thead><tbody>';

            foreach ($batches as $b) {
                $bS   = (int)$b['seeds'];
                $bSp  = (int)$b['sprouts'];
                $bPb  = (int)$b['polybag'];
                $bRd  = (int)$b['ready'];
                $bGr  = $b['germ_rate']  !== null ? (float)$b['germ_rate']  : null;
                $bPr  = $b['pre_rate']   !== null ? (float)$b['pre_rate']   : null;
                $bMr  = $b['main_rate']  !== null ? (float)$b['main_rate']  : null;
                $stBadge = $stColors[$b['status']] ?? 'secondary';

                $grColor = $bGr === null ? '' : ($bGr >= 80 ? 'color:#16a34a' : ($bGr >= 70 ? 'color:#d97706' : 'color:#dc2626'));
                $mrColor = $bMr === null ? '' : ($bMr >= 90 ? 'color:#16a34a' : ($bMr >= 82 ? 'color:#d97706' : 'color:#dc2626'));

                echo '<tr>'
                   . '<td class="fw-semibold text-nowrap">' . htmlspecialchars((string)$b['batch_number']) . '</td>'
                   . '<td class="text-nowrap">'  . htmlspecialchars((string)$b['estate'])       . '</td>'
                   . '<td class="text-nowrap">'  . htmlspecialchars((string)$b['variety'])
                   .   ($b['variety_code'] ? ' <small class="text-muted">(' . htmlspecialchars((string)$b['variety_code']) . ')</small>' : '')
                   . '</td>'
                   . '<td class="text-muted small">'  . htmlspecialchars((string)$b['seed_source']) . '</td>'
                   . '<td class="text-center text-muted small">' . htmlspecialchars((string)$b['germination_date']) . '</td>'
                   . '<td class="text-center"><span class="badge text-bg-' . $stBadge . '">' . htmlspecialchars((string)$b['status']) . '</span></td>'
                   . '<td class="text-end">'  . number_format($bS)  . '</td>'
                   . '<td class="text-end">'  . number_format($bSp) . '</td>'
                   . '<td class="text-end fw-bold" style="' . $grColor . '">' . ($bGr !== null ? number_format($bGr, 1) . '%' : '—') . '</td>'
                   . '<td class="text-end">'  . number_format($bPb) . '</td>'
                   . '<td class="text-end">'  . number_format($bRd) . '</td>'
                   . '<td class="text-end fw-bold" style="' . $mrColor . '">' . ($bMr !== null ? number_format($bMr, 1) . '%' : '—') . '</td>'
                   . '</tr>';
            }

            // Grand total row
            echo '<tr class="table-success fw-bold">'
               . '<th colspan="6" class="text-end">Grand Total</th>'
               . '<th class="text-end">' . number_format($gtS)  . '</th>'
               . '<th class="text-end">' . number_format($gtSp) . '</th>'
               . '<th class="text-end">' . ($tgR !== null ? number_format($tgR, 1) . '%' : '—') . '</th>'
               . '<th class="text-end">' . number_format($gtPb) . '</th>'
               . '<th class="text-end">' . number_format($gtRd) . '</th>'
               . '<th class="text-end">' . ($tmR !== null ? number_format($tmR, 1) . '%' : '—') . '</th>'
               . '</tr>';

            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">'
               . 'Daya Kecambah = Kecambah/Benih &bull; Hidup Main = Siap Salur/Polybag'
               . ' &bull; SNI 8171:2015: Daya Kecambah ≥80%, PPKS: Hidup Main ≥90%'
               . ' &bull; <a href="nursery_stock.php">Lihat detail →</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        case 'rendemen': {
            $oer      = (float)($answer['oer'] ?? 0);
            $ker      = (float)($answer['ker'] ?? 0);
            $avgFfa   = isset($answer['avg_ffa'])      ? (float)$answer['avg_ffa']      : null;
            $avgMoist = isset($answer['avg_moisture'])  ? (float)$answer['avg_moisture'] : null;
            $minOer   = (float)($answer['min_oer'] ?? 0);
            $maxOer   = (float)($answer['max_oer'] ?? 0);
            $minKer   = (float)($answer['min_ker'] ?? 0);
            $maxKer   = (float)($answer['max_ker'] ?? 0);

            $rDateLbl = htmlspecialchars((string)($answer['date_label'] ?? ''));
            echo '<span class="qna-tag tag-mill">⚙️ Mill</span> <strong>Rendemen CPO &amp; PK</strong>';
            if ($rDateLbl !== '') {
                echo ' <span class="badge bg-secondary ms-1">' . $rDateLbl . '</span>';
            }
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val" style="color:#0369a1">' . number_format($oer, 2) . '%</div><div class="qna-stat-lbl">CPO Extraction (OER)</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val" style="color:#7c5cd8">' . number_format($ker, 2) . '%</div><div class="qna-stat-lbl">PK Extraction (KER)</div></div>';
            if ($avgFfa !== null) {
                $ffaColor = $avgFfa <= 3.5 ? '#16a34a' : ($avgFfa <= 5.0 ? '#d97706' : '#dc2626');
                echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . $ffaColor . '">' . number_format($avgFfa, 2) . '%</div><div class="qna-stat-lbl">FFA Content (avg)</div></div>';
            }
            if ($avgMoist !== null) {
                $moistColor = $avgMoist <= 0.15 ? '#16a34a' : ($avgMoist <= 0.25 ? '#d97706' : '#dc2626');
                echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . $moistColor . '">' . number_format($avgMoist, 3) . '%</div><div class="qna-stat-lbl">CPO Moisture (avg)</div></div>';
            }
            echo '<div class="qna-stat"><div class="qna-stat-val">' . agro_fmt_kg((float)($answer['ffb_kg'] ?? 0)) . '</div><div class="qna-stat-lbl">FFB Processed</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . agro_fmt_kg((float)($answer['cpo_kg'] ?? 0)) . '</div><div class="qna-stat-lbl">CPO Produced</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . agro_fmt_kg((float)($answer['kernel_kg'] ?? 0)) . '</div><div class="qna-stat-lbl">Kernel Produced</div></div>';
            echo '</div>';

            // Range summary line
            $rangeParts = [];
            if ($minOer > 0 || $maxOer > 0)
                $rangeParts[] = 'OER: ' . number_format($minOer,2) . '% – ' . number_format($maxOer,2) . '%';
            if ($minKer > 0 || $maxKer > 0)
                $rangeParts[] = 'KER: ' . number_format($minKer,2) . '% – ' . number_format($maxKer,2) . '%';
            if ($answer['min_ffa'] !== null && $answer['max_ffa'] !== null)
                $rangeParts[] = 'FFA: ' . number_format((float)$answer['min_ffa'],2) . '% – ' . number_format((float)$answer['max_ffa'],2) . '%';
            if ($answer['min_moisture'] !== null && $answer['max_moisture'] !== null)
                $rangeParts[] = 'Air: ' . number_format((float)$answer['min_moisture'],3) . '% – ' . number_format((float)$answer['max_moisture'],3) . '%';
            if (!empty($rangeParts))
                echo '<p class="text-muted small mt-2 mb-1">Rentang: ' . implode(' &nbsp;|&nbsp; ', $rangeParts) . '</p>';

            // Monthly breakdown table
            $monthly = (array)($answer['monthly'] ?? []);
            if (!empty($monthly)) {
                $hasFfa   = !empty(array_filter($monthly, fn($r) => $r['avg_ffa'] !== null));
                $hasMoist = !empty(array_filter($monthly, fn($r) => $r['avg_moisture'] !== null));
                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead class="table-primary"><tr>'
                   . '<th>Bulan</th>'
                   . '<th class="text-end">FFB (kg)</th>'
                   . '<th class="text-end">CPO (kg)</th>'
                   . '<th class="text-end">OER %</th>'
                   . '<th class="text-end">PK (kg)</th>'
                   . '<th class="text-end">KER %</th>'
                   . ($hasFfa   ? '<th class="text-end">FFA %</th>'  : '')
                   . ($hasMoist ? '<th class="text-end">Air %</th>'  : '')
                   . '</tr></thead><tbody>';
                foreach ($monthly as $row) {
                    $rFfb   = (float)$row['ffb_kg'];
                    $rCpo   = (float)$row['cpo_kg'];
                    $rKer   = (float)$row['kernel_kg'];
                    $rOer   = $rFfb > 0 ? number_format($rCpo / $rFfb * 100, 2) : '–';
                    $rKr    = $rFfb > 0 ? number_format($rKer / $rFfb * 100, 2) : '–';
                    $rFfa   = $row['avg_ffa']      !== null ? number_format((float)$row['avg_ffa'], 2)      : '–';
                    $rMoist = $row['avg_moisture']  !== null ? number_format((float)$row['avg_moisture'], 3) : '–';
                    echo '<tr>'
                       . '<td class="fw-semibold">' . htmlspecialchars((string)$row['bulan']) . '</td>'
                       . '<td class="text-end">' . number_format($rFfb, 0, ',', '.') . '</td>'
                       . '<td class="text-end">' . number_format($rCpo, 0, ',', '.') . '</td>'
                       . '<td class="text-end text-primary fw-bold">' . $rOer . '%</td>'
                       . '<td class="text-end">' . number_format($rKer, 0, ',', '.') . '</td>'
                       . '<td class="text-end fw-bold" style="color:#7c5cd8">' . $rKr . '%</td>'
                       . ($hasFfa   ? '<td class="text-end">' . $rFfa   . '%</td>' : '')
                       . ($hasMoist ? '<td class="text-end">' . $rMoist . '%</td>' : '')
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }
            if (!empty($answer['first_date'])) {
                echo '<p class="text-muted small mt-1 mb-0">Periode: '
                   . htmlspecialchars((string)$answer['first_date']) . ' – '
                   . htmlspecialchars((string)$answer['last_date'])
                   . ' &nbsp;|&nbsp; ' . (int)($answer['batches'] ?? 0) . ' batch</p>';
            }

            // ── Kernel Stock Section ─────────────────────────────────────────
            $ks = (array)($answer['kernel_stock'] ?? []);
            if (!empty($ks) && (($ks['total_in'] ?? 0) > 0 || ($ks['current_stock'] ?? 0) > 0)) {
                $ksIn      = (float)($ks['total_in']      ?? 0);
                $ksOut     = (float)($ks['total_out']     ?? 0);
                $ksCurrent = (float)($ks['current_stock'] ?? 0);
                $ksTxCount = (int)  ($ks['tx_count']      ?? 0);
                $ksLastDate= htmlspecialchars((string)($ks['last_tx_date'] ?? ''));
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-mill" style="background:#bc5420">🌰 Kernel Stock</span>';
                echo '<div class="mt-2 d-flex flex-wrap gap-3">';
                echo '<div class="qna-stat"><div class="qna-stat-val text-success">'  . agro_fmt_kg($ksIn)      . '</div><div class="qna-stat-lbl">Total In</div></div>';
                echo '<div class="qna-stat"><div class="qna-stat-val text-danger">'   . agro_fmt_kg($ksOut)     . '</div><div class="qna-stat-lbl">Total Out</div></div>';
                echo '<div class="qna-stat"><div class="qna-stat-val" style="color:#bc5420">' . agro_fmt_kg($ksCurrent) . '</div><div class="qna-stat-lbl">Current Stock</div></div>';
                if ($ksTxCount > 0) {
                    echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($ksTxCount) . '</div><div class="qna-stat-lbl">Transactions</div></div>';
                }
                echo '</div>';

                // Per-storage breakdown
                $ksByStorage = (array)($ks['by_storage'] ?? []);
                if (!empty($ksByStorage)) {
                    echo '<div class="table-responsive mt-2">';
                    echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                    echo '<thead style="background:#bc5420;color:#fff"><tr>'
                       . '<th>Gudang</th>'
                       . '<th class="text-end">Kapasitas (kg)</th>'
                       . '<th class="text-end">Stok (kg)</th>'
                       . '<th class="text-end">Utilisasi</th>'
                       . '</tr></thead><tbody>';
                    foreach ($ksByStorage as $ksRow) {
                        $ksRow    = (array)$ksRow;
                        $cap      = (float)($ksRow['capacity_kg']    ?? 0);
                        $stk      = (float)($ksRow['current_stock_kg']?? 0);
                        $util     = $cap > 0 ? round($stk / $cap * 100, 1) : null;
                        $utilCls  = $util === null ? '' : ($util >= 90 ? 'text-danger fw-bold' : ($util >= 70 ? 'text-warning' : 'text-success'));
                        echo '<tr>'
                           . '<td class="fw-semibold">' . htmlspecialchars((string)($ksRow['storage_code'] ?? '')) . '</td>'
                           . '<td class="text-end">'    . number_format($cap, 0, ',', '.') . '</td>'
                           . '<td class="text-end">'    . number_format($stk, 0, ',', '.') . '</td>'
                           . '<td class="text-end ' . $utilCls . '">' . ($util !== null ? $util . '%' : '—') . '</td>'
                           . '</tr>';
                    }
                    echo '</tbody></table></div>';
                }
                if ($ksLastDate !== '') {
                    echo '<p class="text-muted small mt-1 mb-0">Transaksi terakhir: ' . $ksLastDate . '</p>';
                }
            }

            // Auto-analysis + standards check (triggered by "Analisa Pabrik")
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        case 'mill_production':
            echo '<span class="qna-tag tag-mill">⚙️ Mill</span> Ringkasan Produksi Pabrik';
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . agro_fmt_kg((float)($answer['ffb_kg'] ?? 0)) . '</div><div class="qna-stat-lbl">FFB Processed</div></div>';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . agro_fmt_kg((float)($answer['cpo_kg'] ?? 0)) . '</div><div class="qna-stat-lbl">CPO Produced</div></div>';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . agro_fmt_kg((float)($answer['kernel_kg'] ?? 0)) . '</div><div class="qna-stat-lbl">Kernel Produced</div></div>';
            echo '</div>';
            if (!empty($answer['first_date'])) {
                echo '<p class="text-muted small mt-1 mb-0">Periode: ' . htmlspecialchars((string)$answer['first_date']) . ' – ' . htmlspecialchars((string)$answer['last_date']) . '</p>';
            }
            break;

        case 'cpo_stock':
            echo '<span class="qna-tag tag-mill">🛢 Stok CPO</span> ';
            echo '<div class="mt-2"><span class="qna-count-badge">' . agro_fmt_kg((float)($answer['total_kg'] ?? 0)) . '</span></div>';
            if (!empty($answer['last_date'])) {
                echo '<p class="text-muted small mt-1 mb-0">Update terakhir: ' . htmlspecialchars((string)$answer['last_date']) . '</p>';
            }
            break;

        case 'harvest_plans':
            $plans = (array)($answer['plans'] ?? []);
            $count = (int)($answer['count'] ?? 0);
            echo '<span class="qna-tag tag-harvest">📋 Rencana Panen</span> ';
            echo '<strong>' . htmlspecialchars((string)($answer['block'] ?? '')) . '</strong>';
            echo ' &mdash; <span class="text-muted">' . $count . ' rencana</span>';
            if ($count === 0) {
                echo '<p class="text-muted mb-0 mt-1 small">Belum ada rencana panen.</p>';
            } else {
                echo '<div class="table-responsive mt-2"><table class="table table-sm table-striped mb-0" style="font-size:.8rem">';
                echo '<thead><tr><th>No. Rencana</th><th>Tgl Rencana</th><th>Estimasi (kg)</th><th>Status</th></tr></thead><tbody>';
                foreach ($plans as $p) {
                    $p = (array)$p;
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars((string)$p['plan_number']) . '</td>';
                    echo '<td>' . htmlspecialchars((string)$p['plan_date']) . '</td>';
                    echo '<td>' . number_format((float)($p['estimated_quantity_kg'] ?? 0), 0) . '</td>';
                    $st = htmlspecialchars((string)($p['status'] ?? ''));
                    $stCls = match($st) { 'Completed' => 'text-success', 'In Progress' => 'text-warning', 'Cancelled' => 'text-danger', default => '' };
                    echo '<td class="' . $stCls . '">' . $st . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
            break;

        case 'find_block':
            $blocks = (array)($answer['blocks'] ?? []);
            $count  = (int)($answer['count'] ?? 0);
            echo '<span class="qna-tag tag-block">🔍 Cari Blok</span> ';
            echo '<span class="text-muted">' . $count . ' hasil</span>';
            echo '<div class="qna-grid mt-2">';
            foreach ($blocks as $b) {
                $b   = (array)$b;
                $nm  = htmlspecialchars((string)$b['block_name']);
                $cd  = htmlspecialchars((string)($b['block_code'] ?? ''));
                $div = htmlspecialchars((string)($b['division_name'] ?? ''));
                $bu  = htmlspecialchars((string)($b['business_unit'] ?? ''));
                $ha  = number_format((float)($b['area'] ?? 0), 2);
                $st  = htmlspecialchars((string)($b['status'] ?? ''));
                $yr  = htmlspecialchars((string)($b['planting_year'] ?? ''));
                echo '<span class="qna-chip">' . $nm . ' <small class="text-muted">(' . $cd . ') ' . $ha . ' ha &bull; ' . $st . ' &bull; TH ' . $yr . ' &mdash; ' . $div . ', ' . $bu . '</small></span>';
            }
            echo '</div>';
            break;

        case 'companies':
            $cos   = (array)($answer['companies'] ?? []);
            $count = (int)($answer['count'] ?? 0);
            echo '<span class="qna-tag tag-company">🏛 Perusahaan</span> ';
            echo '<span class="text-muted">' . $count . ' perusahaan</span>';
            echo '<div class="qna-grid mt-2">';
            foreach ($cos as $c) {
                $c    = (array)$c;
                $name = htmlspecialchars((string)$c['company_name']);
                $prov = htmlspecialchars((string)($c['province'] ?? ''));
                $bu   = (int)($c['bu_count'] ?? 0);
                echo '<span class="qna-chip qna-chip-div">' . $name . ' <small class="text-muted">' . ($prov ? $prov . ' &bull; ' : '') . $bu . ' unit</small></span>';
            }
            echo '</div>';
            break;

        case 'plant_density':
            $divRows      = (array)($answer['rows']          ?? []);
            $scope        = htmlspecialchars((string)($answer['scope']         ?? ''));
            $grandHa      = (float)($answer['grand_ha']      ?? 0);
            $grandPlanted = (float)($answer['grand_planted']  ?? 0);
            $grandPlants  = (int)  ($answer['grand_plants']   ?? 0);
            $grandNormal  = (int)  ($answer['grand_normal']   ?? 0);
            $grandAbnorm  = (int)  ($answer['grand_abnorm']   ?? 0);
            $grandDead    = (int)  ($answer['grand_dead']     ?? 0);
            $grandActual  = $answer['grand_actual'] !== null ? (float)$answer['grand_actual'] : null;

            echo '<span class="qna-tag tag-harvest">🌱 Kerapatan Tanaman</span> ';
            echo '<strong>' . $scope . '</strong>';

            if (empty($divRows)) {
                echo '<p class="text-muted mt-1 mb-0 small">Belum ada data.</p>';
                break;
            }

            // Group by BU
            $byBu = [];
            foreach ($divRows as $r) {
                $r = (array)$r;
                $byBu[(string)($r['business_unit'] ?? '—')][] = $r;
            }
            $multibu = count($byBu) > 1;

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
            echo '<thead class="table-success"><tr>'
                . '<th>Division</th>'
                . '<th class="text-end">Blocks</th>'
                . '<th class="text-end">Area (ha)</th>'
                . '<th class="text-end">Planted (ha)</th>'
                . '<th class="text-end">Population</th>'
                . '<th class="text-end">Normal</th>'
                . '<th class="text-end">Abnormal</th>'
                . '<th class="text-end">Dead</th>'
                . '<th class="text-end">Design SPH</th>'
                . '<th class="text-end">Actual SPH</th>'
                . '</tr></thead><tbody>';

            foreach ($byBu as $buName => $buRows) {
                if ($multibu) {
                    echo '<tr class="table-light"><td colspan="10" class="fw-semibold small text-muted">' . htmlspecialchars($buName) . '</td></tr>';
                }
                $buPlants = 0; $buBlk = 0;
                foreach ($buRows as $r) {
                    $r = (array)$r;
                    $buPlants += (int)$r['total_plants'];
                    $buBlk    += (int)$r['block_count'];
                    $actDen    = ($r['actual_density'] !== null && $r['actual_density'] !== '')
                                 ? number_format((float)$r['actual_density'], 1) : '—';
                    $desDen    = ($r['design_density'] && (float)$r['design_density'] > 0)
                                 ? number_format((float)$r['design_density'], 1) : '—';
                    $normPct   = (int)$r['total_plants'] > 0
                                 ? ' <small class="text-muted">(' . round((int)$r['normal_plants'] / (int)$r['total_plants'] * 100) . '%)</small>' : '';
                    echo '<tr>'
                        . '<td>' . htmlspecialchars((string)$r['division_name']) . '</td>'
                        . '<td class="text-end">' . (int)$r['block_count'] . '</td>'
                        . '<td class="text-end">' . number_format((float)$r['total_ha'], 2) . '</td>'
                        . '<td class="text-end">' . number_format((float)$r['planted_ha'], 2) . '</td>'
                        . '<td class="text-end fw-semibold">' . number_format((int)$r['total_plants']) . '</td>'
                        . '<td class="text-end text-success">' . number_format((int)$r['normal_plants']) . $normPct . '</td>'
                        . '<td class="text-end text-warning">' . number_format((int)$r['abnormal_plants']) . '</td>'
                        . '<td class="text-end text-danger">'  . number_format((int)$r['dead_plants'])    . '</td>'
                        . '<td class="text-end text-muted">'   . $desDen . '</td>'
                        . '<td class="text-end fw-semibold">'  . $actDen . '</td>'
                        . '</tr>';
                }
            }

            // Grand total
            $grandActualFmt = $grandActual !== null ? number_format($grandActual, 1) : '—';
            $grandNormPct   = $grandPlants > 0 ? ' <small>(' . round($grandNormal / $grandPlants * 100) . '%)</small>' : '';
            echo '<tr class="table-dark fw-bold">'
                . '<td>TOTAL</td>'
                . '<td class="text-end">' . count($divRows) . '</td>'
                . '<td class="text-end">' . number_format($grandHa, 2) . '</td>'
                . '<td class="text-end">' . number_format($grandPlanted, 2) . '</td>'
                . '<td class="text-end">' . number_format($grandPlants) . '</td>'
                . '<td class="text-end">' . number_format($grandNormal) . $grandNormPct . '</td>'
                . '<td class="text-end">' . number_format($grandAbnorm) . '</td>'
                . '<td class="text-end">' . number_format($grandDead) . '</td>'
                . '<td class="text-end">—</td>'
                . '<td class="text-end">' . $grandActualFmt . '</td>'
                . '</tr>';
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">SPH = Stands Per Hectare (plants/ha) &bull; Normal = healthy plants</p>';
            break;

        case 'top_blocks':
            $blocks = (array)($answer['blocks'] ?? []);
            echo '<span class="qna-tag tag-harvest">🏆 Top Blok Panen</span>';
            if (empty($blocks)) {
                echo '<p class="text-muted mt-1 mb-0 small">Belum ada data panen.</p>';
            } else {
                echo '<div class="table-responsive mt-2"><table class="table table-sm table-striped mb-0" style="font-size:.8rem">';
                echo '<thead><tr><th>#</th><th>Block</th><th>Division</th><th>Total (ton)</th><th>Harvests</th></tr></thead><tbody>';
                foreach ($blocks as $i => $b) {
                    $b    = (array)$b;
                    $nm   = htmlspecialchars((string)$b['block_name']);
                    $cd   = htmlspecialchars((string)($b['block_code'] ?? ''));
                    $div  = htmlspecialchars((string)($b['division_name'] ?? ''));
                    $ton  = number_format((float)($b['total_kg'] ?? 0) / 1000, 2);
                    $hvs  = (int)($b['harvests'] ?? 0);
                    echo '<tr><td>' . ($i + 1) . '</td><td>' . $nm . ' <small class="text-muted">(' . $cd . ')</small></td>';
                    echo '<td>' . $div . '</td><td>' . $ton . '</td><td>' . $hvs . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            break;

        case 'bridge_count':
            $bridgeRows    = (array)($answer['rows']           ?? []);
            $scope         = htmlspecialchars((string)($answer['scope']          ?? ''));
            $grandLengthM  = (float)($answer['grand_length_m'] ?? 0);
            $grandCount    = (int)  ($answer['grand_count']    ?? 0);
            $missingLength = !empty($answer['missing_length']);

            echo '<span class="qna-tag tag-block">🌉 Jembatan &amp; Gorong-Gorong</span> ';
            echo '<strong>' . $scope . '</strong>';

            // Grand-total badge: primary = length (m), secondary = count if filled
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandLengthM, 0) . ' m</div><div class="qna-stat-lbl">Total Length</div></div>';
            if ($grandCount > 0) {
                echo '<div class="qna-stat"><div class="qna-stat-val">' . $grandCount . '</div><div class="qna-stat-lbl">Unit Count</div></div>';
            }
            echo '</div>';

            // Warning when LENGTH has never been filled in
            if ($missingLength) {
                echo '<div class="alert alert-warning py-1 px-2 mt-2 mb-1 small">'
                    . '⚠️ <strong>Data panjang jembatan (meter) belum diisi.</strong> '
                    . 'Silakan isi kolom <em>Bridge Length</em> di halaman '
                    . '<a href="block_area_components.php">Komponen Luas Blok</a> untuk setiap blok.'
                    . '</div>';
            }

            if (empty($bridgeRows)) {
                echo '<p class="text-muted mt-1 mb-0 small">Belum ada data jembatan.</p>';
            } else {
                // Group by BU
                $byBu = [];
                foreach ($bridgeRows as $r) {
                    $r = (array)$r;
                    $byBu[(string)($r['business_unit'] ?? '—')][] = $r;
                }
                $multibu = count($byBu) > 1;

                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead class="table-info"><tr>'
                    . '<th>Division</th>'
                    . '<th class="text-end">Length (m)</th>'
                    . '<th class="text-end">Unit Count</th>'
                    . '</tr></thead><tbody>';

                foreach ($byBu as $buName => $buRows) {
                    if ($multibu) {
                        echo '<tr class="table-light"><td colspan="3" class="fw-semibold small text-muted">'
                            . htmlspecialchars($buName) . '</td></tr>';
                    }
                    $buLen = 0; $buCnt = 0;
                    foreach ($buRows as $r) {
                        $r      = (array)$r;
                        $lenM   = (float)$r['bridge_length_m'];
                        $cnt    = (int)  $r['bridge_count'];
                        $hasLen = (int)  $r['has_length'];
                        $buLen += $lenM;
                        $buCnt += $cnt;
                        $lenCell = $hasLen
                            ? '<td class="text-end fw-semibold">' . number_format($lenM, 0) . '</td>'
                            : '<td class="text-end text-danger">⚠ belum diisi</td>';
                        echo '<tr>'
                            . '<td>' . htmlspecialchars((string)$r['division_name']) . '</td>'
                            . $lenCell
                            . '<td class="text-end text-muted">' . ($cnt > 0 ? $cnt : '—') . '</td>'
                            . '</tr>';
                    }
                    if ($multibu) {
                        echo '<tr class="fw-semibold" style="background:#e0f2fe">'
                            . '<td class="text-end text-muted small">Subtotal ' . htmlspecialchars($buName) . '</td>'
                            . '<td class="text-end">' . number_format($buLen, 0) . ' m</td>'
                            . '<td class="text-end">' . ($buCnt > 0 ? $buCnt : '—') . '</td>'
                            . '</tr>';
                    }
                }

                echo '<tr class="table-dark fw-bold">'
                    . '<td>TOTAL</td>'
                    . '<td class="text-end">' . number_format($grandLengthM, 0) . ' m</td>'
                    . '<td class="text-end">' . ($grandCount > 0 ? $grandCount : '—') . '</td>'
                    . '</tr>';
                echo '</tbody></table></div>';
                echo '<p class="text-muted small mb-0">Length = total length of bridges &amp; culverts &bull; Unit Count = optional</p>';
            }
            break;

        case 'road_by_type':
            $roadTypes   = (array) ($answer['road_types']    ?? []);
            $divMap      = (array) ($answer['div_map']       ?? []);
            $grandByType = (array) ($answer['grand_by_type'] ?? []);
            $scope       = htmlspecialchars((string)($answer['scope'] ?? ''));
            $isEmpty     = !empty($answer['empty']);
            $colCount    = 1 + count($roadTypes) * 2; // Divisi + (Panjang + Luas) × types

            echo '<span class="qna-tag tag-block">🛣️ Jalan per Jenis</span> ';
            echo '<strong>' . $scope . '</strong>';

            if ($isEmpty || empty($roadTypes)) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data jalan per jenis. '
                    . 'Isi data di <a href="block_area_components.php">Komponen Luas Blok</a>.</p>';
                break;
            }

            // Grand-total stat badges
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            foreach ($roadTypes as $t) {
                $tSafe  = htmlspecialchars($t);
                $totLen = number_format((float)($grandByType[$t]['length_m'] ?? 0), 0);
                $totHa  = number_format((float)($grandByType[$t]['area_ha']  ?? 0), 2);
                echo '<div class="qna-stat">'
                    . '<div class="qna-stat-val">' . $totLen . ' m</div>'
                    . '<div class="qna-stat-lbl">' . $tSafe . '</div>'
                    . '</div>';
            }
            echo '</div>';

            // Pivot table
            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';

            // Header row 1: Divisi + road-type colspan headers
            echo '<thead>';
            echo '<tr class="table-success"><th rowspan="2">Division</th>';
            foreach ($roadTypes as $t) {
                echo '<th colspan="2" class="text-center">' . htmlspecialchars($t) . '</th>';
            }
            echo '</tr>';

            // Header row 2: Panjang / Luas per type
            echo '<tr class="table-success">';
            foreach ($roadTypes as $t) {
                echo '<th class="text-end">Length (m)</th><th class="text-end">Area (ha)</th>';
            }
            echo '</tr></thead><tbody>';

            // Group rows by BU
            $byBu = [];
            foreach ($divMap as $divName => $info) {
                $byBu[(string)$info['bu']][$divName] = $info;
            }
            $multibu = count($byBu) > 1;

            foreach ($byBu as $buName => $buDivs) {
                if ($multibu) {
                    echo '<tr class="table-light"><td colspan="' . $colCount . '" class="fw-semibold small text-muted">'
                        . htmlspecialchars($buName) . '</td></tr>';
                }
                $buTotals = array_fill_keys($roadTypes, ['length_m' => 0, 'area_ha' => 0]);
                foreach ($buDivs as $divName => $info) {
                    echo '<tr><td>' . htmlspecialchars($divName) . '</td>';
                    foreach ($roadTypes as $t) {
                        if (isset($info['types'][$t])) {
                            $lenM  = (float)$info['types'][$t]['length_m'];
                            $areaH = (float)$info['types'][$t]['area_ha'];
                            $buTotals[$t]['length_m'] += $lenM;
                            $buTotals[$t]['area_ha']  += $areaH;
                            echo '<td class="text-end fw-semibold">' . number_format($lenM, 0) . '</td>'
                               . '<td class="text-end text-muted">'  . number_format($areaH, 2) . '</td>';
                        } else {
                            echo '<td class="text-end text-muted">—</td><td class="text-end text-muted">—</td>';
                        }
                    }
                    echo '</tr>';
                }
                if ($multibu) {
                    echo '<tr class="fw-semibold" style="background:#f0fdf4"><td class="text-muted small">Subtotal ' . htmlspecialchars($buName) . '</td>';
                    foreach ($roadTypes as $t) {
                        echo '<td class="text-end">' . number_format($buTotals[$t]['length_m'], 0) . '</td>'
                           . '<td class="text-end">'  . number_format($buTotals[$t]['area_ha'],  2) . '</td>';
                    }
                    echo '</tr>';
                }
            }

            // Grand total row
            echo '<tr class="table-dark fw-bold"><td>TOTAL</td>';
            foreach ($roadTypes as $t) {
                echo '<td class="text-end">' . number_format((float)($grandByType[$t]['length_m'] ?? 0), 0) . ' m</td>'
                   . '<td class="text-end">'  . number_format((float)($grandByType[$t]['area_ha']  ?? 0), 2) . ' ha</td>';
            }
            echo '</tr>';

            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">Length in metres (m) &bull; Area in hectares (ha)</p>';

            // Auto-analysis
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
            }
            break;

        case 'infrastructure_summary': {
            $scope         = htmlspecialchars((string)($answer['scope']          ?? 'Semua Kebun'));
            $roadTypes     = (array) ($answer['road_types']      ?? []);
            $roadDivMap    = (array) ($answer['road_div_map']    ?? []);
            $roadGrandType = (array) ($answer['road_grand_type'] ?? []);
            $grandRoadM    = (float) ($answer['grand_road_m']    ?? 0);
            $bridgeRows    = (array) ($answer['bridge_rows']     ?? []);
            $grandBridgeHa = (float) ($answer['grand_bridge_ha'] ?? 0);
            $grandBridgeN  = (int)   ($answer['grand_bridge_n']  ?? 0);
            $roadEmpty     = !empty($answer['road_empty']);
            $bridgeEmpty   = !empty($answer['bridge_empty']);

            echo '<span class="qna-tag tag-block">🛣️ Infrastruktur</span> <strong>' . $scope . '</strong>';

            // ── Grand-total stat badges ───────────────────────────────────────
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            // Road: show km when ≥ 1000 m, otherwise metres
            if ($grandRoadM >= 1000) {
                echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandRoadM / 1000, 2) . ' km</div><div class="qna-stat-lbl">Total Roads</div></div>';
            } else {
                echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandRoadM, 0) . ' m</div><div class="qna-stat-lbl">Total Roads</div></div>';
            }
            // Bridge: unit count primary, area secondary
            if ($grandBridgeN > 0) {
                echo '<div class="qna-stat"><div class="qna-stat-val">' . $grandBridgeN . '</div><div class="qna-stat-lbl">Bridge Units</div></div>';
            }
            if ($grandBridgeHa > 0) {
                echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandBridgeHa, 2) . ' ha</div><div class="qna-stat-lbl">Bridge Area</div></div>';
            }
            echo '</div>';

            // ── SECTION 1: Road by type pivot ─────────────────────────────────
            echo '<h6 class="mt-3 mb-1 fw-semibold" style="color:#166534">🛣️ Jalan per Jenis</h6>';
            if ($roadEmpty || empty($roadTypes)) {
                echo '<p class="text-muted small mb-2">Belum ada data jalan. Isi di <a href="block_area_components.php">Komponen Luas Blok</a>.</p>';
            } else {
                $colCount = 1 + count($roadTypes) * 2;
                echo '<div class="table-responsive">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';
                echo '<thead>';
                echo '<tr class="table-success"><th rowspan="2">Division</th>';
                foreach ($roadTypes as $t) {
                    echo '<th colspan="2" class="text-center">' . htmlspecialchars($t) . '</th>';
                }
                echo '</tr><tr class="table-success">';
                foreach ($roadTypes as $t) {
                    echo '<th class="text-end">Length (m)</th><th class="text-end">Area (ha)</th>';
                }
                echo '</tr></thead><tbody>';

                // Group by BU
                $byBu = [];
                foreach ($roadDivMap as $divName => $info) {
                    $byBu[(string)$info['bu']][$divName] = $info;
                }
                $multibu = count($byBu) > 1;
                foreach ($byBu as $buName => $buDivs) {
                    if ($multibu) {
                        echo '<tr class="table-light"><td colspan="' . $colCount . '" class="fw-semibold small text-muted">'
                            . htmlspecialchars($buName) . '</td></tr>';
                    }
                    $buTotals = array_fill_keys($roadTypes, ['length_m' => 0, 'area_ha' => 0]);
                    foreach ($buDivs as $divName => $info) {
                        echo '<tr><td>' . htmlspecialchars($divName) . '</td>';
                        foreach ($roadTypes as $t) {
                            if (isset($info['types'][$t])) {
                                $lenM  = (float)$info['types'][$t]['length_m'];
                                $areaH = (float)$info['types'][$t]['area_ha'];
                                $buTotals[$t]['length_m'] += $lenM;
                                $buTotals[$t]['area_ha']  += $areaH;
                                echo '<td class="text-end fw-semibold">' . number_format($lenM, 0) . '</td>'
                                   . '<td class="text-end text-muted">'  . number_format($areaH, 2) . '</td>';
                            } else {
                                echo '<td class="text-end text-muted">—</td><td class="text-end text-muted">—</td>';
                            }
                        }
                        echo '</tr>';
                    }
                    if ($multibu) {
                        echo '<tr class="fw-semibold" style="background:#f0fdf4"><td class="text-muted small">Subtotal ' . htmlspecialchars($buName) . '</td>';
                        foreach ($roadTypes as $t) {
                            echo '<td class="text-end">' . number_format($buTotals[$t]['length_m'], 0) . '</td>'
                               . '<td class="text-end">'  . number_format($buTotals[$t]['area_ha'],  2) . '</td>';
                        }
                        echo '</tr>';
                    }
                }
                echo '<tr class="table-dark fw-bold"><td>TOTAL</td>';
                foreach ($roadTypes as $t) {
                    echo '<td class="text-end">' . number_format((float)($roadGrandType[$t]['length_m'] ?? 0), 0) . ' m</td>'
                       . '<td class="text-end">'  . number_format((float)($roadGrandType[$t]['area_ha']  ?? 0), 2) . ' ha</td>';
                }
                echo '</tr>';
                echo '</tbody></table></div>';
            }

            // ── SECTION 2: Bridges per division ──────────────────────────────
            echo '<h6 class="mt-3 mb-1 fw-semibold" style="color:#1e40af">🌉 Jembatan &amp; Gorong-Gorong</h6>';
            // Filter out divisions that have neither count nor length data
            $bridgeRowsFiltered = array_values(array_filter($bridgeRows, fn($r) =>
                (float)($r['bridge_length_m'] ?? 0) > 0 || (int)($r['bridge_count'] ?? 0) > 0
            ));
            if ($bridgeEmpty || empty($bridgeRowsFiltered)) {
                echo '<p class="text-muted small mb-2">Belum ada data jembatan. Isi di <a href="block_area_components.php">Komponen Luas Blok</a>.</p>';
            } else {
                // Group by BU
                $byBuB = [];
                foreach ($bridgeRowsFiltered as $r) {
                    $r = (array)$r;
                    $byBuB[(string)($r['business_unit'] ?? '—')][] = $r;
                }
                $multibuB = count($byBuB) > 1;
                echo '<div class="table-responsive">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';
                echo '<thead class="table-info"><tr><th>Division</th><th class="text-end">Unit Count</th><th class="text-end">Length (m)</th></tr></thead><tbody>';
                foreach ($byBuB as $buName => $buRows) {
                    if ($multibuB) {
                        echo '<tr class="table-light"><td colspan="3" class="fw-semibold small text-muted">'
                            . htmlspecialchars($buName) . '</td></tr>';
                    }
                    $buLen = 0; $buCnt = 0;
                    foreach ($buRows as $r) {
                        $r      = (array)$r;
                        $lenM   = (float)$r['bridge_length_m'];
                        $cnt    = (int)  $r['bridge_count'];
                        $hasLen = (int)  $r['has_length'];
                        $buLen += $lenM;
                        $buCnt += $cnt;
                        $lenCell = $hasLen
                            ? '<td class="text-end text-muted">' . number_format($lenM, 0) . '</td>'
                            : '<td class="text-end text-muted">—</td>';
                        echo '<tr>'
                            . '<td>' . htmlspecialchars((string)$r['division_name']) . '</td>'
                            . '<td class="text-end fw-semibold">' . ($cnt > 0 ? $cnt : '—') . '</td>'
                            . $lenCell
                            . '</tr>';
                    }
                    if ($multibuB) {
                        echo '<tr class="fw-semibold" style="background:#e0f2fe">'
                            . '<td class="text-muted small">Subtotal ' . htmlspecialchars($buName) . '</td>'
                            . '<td class="text-end">' . ($buCnt > 0 ? $buCnt : '—') . '</td>'
                            . '<td class="text-end">' . ($buLen > 0 ? number_format($buLen, 0) . ' m' : '—') . '</td>'
                            . '</tr>';
                    }
                }
                echo '<tr class="table-dark fw-bold"><td>TOTAL</td>'
                    . '<td class="text-end">' . ($grandBridgeN > 0 ? $grandBridgeN : '—') . '</td>'
                    . '<td class="text-end">' . ($grandBridgeM > 0 ? number_format($grandBridgeM, 0) . ' m' : '—') . '</td>'
                    . '</tr>';
                echo '</tbody></table></div>';
            }

            echo '<p class="text-muted small mb-0">Data bersumber dari <a href="block_area_components.php">Komponen Luas Blok</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        case 'seed_varieties':
            $varieties   = (array) ($answer['varieties']    ?? []);
            $scope       = htmlspecialchars((string)($answer['scope']        ?? ''));
            $count       = (int)   ($answer['count']        ?? 0);
            $grandBlocks = (int)   ($answer['grand_blocks'] ?? 0);
            $grandPlants = (int)   ($answer['grand_plants'] ?? 0);
            $grandHa     = (float) ($answer['grand_ha']     ?? 0);
            $isEmpty     = !empty($answer['empty']);

            echo '<span class="qna-tag tag-harvest">🌱 Bibit / Varietas</span> ';
            echo '<strong>' . $scope . '</strong>';
            echo ' &mdash; <span class="text-muted">' . $count . ' varietas</span>';

            if ($isEmpty || $count === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data bibit/varietas. '
                    . 'Pastikan varietas telah ditautkan ke blok di halaman '
                    . '<a href="blocks.php">Manajemen Blok</a>.</p>';
                break;
            }

            // Grand-total stat badges
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . $count . '</div><div class="qna-stat-lbl">Varieties</div></div>';
            if ($grandBlocks > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . $grandBlocks . '</div><div class="qna-stat-lbl">Blocks</div></div>';
            if ($grandPlants > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandPlants) . '</div><div class="qna-stat-lbl">Population</div></div>';
            if ($grandHa    > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandHa, 2) . '</div><div class="qna-stat-lbl">Ha (total)</div></div>';
            echo '</div>';

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
            echo '<thead class="table-success"><tr>'
                . '<th>#</th>'
                . '<th>Code</th>'
                . '<th>Variety Name</th>'
                . '<th>Category</th>'
                . '<th>Clone / Origin</th>'
                . '<th class="text-end">Blocks</th>'
                . '<th class="text-end">Population</th>'
                . '<th class="text-end">Area (ha)</th>'
                . '<th class="text-end">Yield Est. (ton/ha/yr)</th>'
                . '<th class="text-end">Maturity Age (yr)</th>'
                . '</tr></thead><tbody>';

            foreach ($varieties as $i => $v) {
                $v      = (array)$v;
                $clone  = trim(($v['clone_name'] ?? '') . ($v['origin'] ? ' / ' . $v['origin'] : ''));
                $yield  = ($v['avg_yield']      !== null && (float)$v['avg_yield']      > 0)
                          ? number_format((float)$v['avg_yield'],      2) : '—';
                $mature = ($v['maturity_age']   !== null && (int)$v['maturity_age']     > 0)
                          ? (int)$v['maturity_age'] : '—';
                $plants = (int)$v['total_plants'] > 0 ? number_format((int)$v['total_plants']) : '—';
                $ha     = (float)$v['total_ha']  > 0 ? number_format((float)$v['total_ha'], 2) : '—';
                echo '<tr>'
                    . '<td class="text-muted">' . ($i + 1) . '</td>'
                    . '<td class="fw-semibold">' . htmlspecialchars((string)$v['variety_code']) . '</td>'
                    . '<td>' . htmlspecialchars((string)$v['variety_name']) . '</td>'
                    . '<td class="text-muted small">' . htmlspecialchars((string)($v['category'] ?? '')) . '</td>'
                    . '<td class="text-muted small">' . htmlspecialchars($clone ?: '—') . '</td>'
                    . '<td class="text-end">' . (int)$v['block_count'] . '</td>'
                    . '<td class="text-end">' . $plants . '</td>'
                    . '<td class="text-end">' . $ha . '</td>'
                    . '<td class="text-end">' . $yield . '</td>'
                    . '<td class="text-end">' . $mature . '</td>'
                    . '</tr>';
            }

            // Grand total row
            echo '<tr class="table-dark fw-bold">'
                . '<td colspan="5">TOTAL</td>'
                . '<td class="text-end">' . $grandBlocks . '</td>'
                . '<td class="text-end">' . ($grandPlants > 0 ? number_format($grandPlants) : '—') . '</td>'
                . '<td class="text-end">' . ($grandHa    > 0 ? number_format($grandHa, 2) : '—') . '</td>'
                . '<td class="text-end">—</td>'
                . '<td class="text-end">—</td>'
                . '</tr>';

            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">Population = total plants per variety across linked blocks</p>';
            break;

        case 'area_by_division':
            $divRows      = (array)($answer['rows']          ?? []);
            $scope        = htmlspecialchars((string)($answer['scope']         ?? ''));
            $grandHa      = (float)($answer['grand_ha']      ?? 0);
            $grandTm      = (float)($answer['grand_tm']      ?? 0);
            $grandTbm     = (float)($answer['grand_tbm']     ?? 0);
            $grandBlk     = (int)  ($answer['grand_blocks']  ?? 0);
            $grandPlt     = (int)  ($answer['grand_plants']  ?? 0);
            $grandNp      = (float)($answer['grand_np']      ?? 0);
            $npDetail     = (array)($answer['np_detail']     ?? []);
            $npCategories = (array)($answer['np_categories'] ?? []);
            $hasNp        = $grandNp > 0;

            echo '<span class="qna-tag tag-division">📊 Area by Division</span> ';
            echo '<strong>' . $scope . '</strong>';

            if (empty($divRows)) {
                echo '<p class="text-muted mt-1 mb-0 small">Belum ada data.</p>';
                break;
            }

            // Group rows by business unit for section headers
            $byBu = [];
            foreach ($divRows as $r) {
                $r = (array)$r;
                $byBu[(string)($r['business_unit'] ?? '—')][] = $r;
            }
            $multibu = count($byBu) > 1;
            $colSpan = $hasNp ? 7 : 6;

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
            echo '<thead class="table-success"><tr>'
                . '<th>Division</th>'
                . '<th class="text-end">Blocks</th>'
                . '<th class="text-end">Total (ha)</th>'
                . '<th class="text-end">TM (ha)</th>'
                . '<th class="text-end">TBM (ha)</th>'
                . ($hasNp ? '<th class="text-end">Non-Planted (ha)</th>' : '')
                . '<th class="text-end">Plants</th>'
                . '</tr></thead><tbody>';

            foreach ($byBu as $buName => $buRows) {
                if ($multibu) {
                    echo '<tr class="table-light"><td colspan="' . $colSpan . '" class="fw-semibold small text-muted">'
                        . htmlspecialchars($buName) . '</td></tr>';
                }
                $buHa = 0; $buTm = 0; $buTbm = 0; $buBlk = 0; $buPlt = 0; $buNp = 0;
                foreach ($buRows as $r) {
                    $r    = (array)$r;
                    $buHa  += (float)$r['total_ha'];
                    $buTm  += (float)$r['tm_ha'];
                    $buTbm += (float)$r['tbm_ha'];
                    $buBlk += (int)$r['block_count'];
                    $buPlt += (int)$r['total_plants'];
                    $buNp  += (float)($r['non_planted_ha'] ?? 0);
                    $dType  = htmlspecialchars((string)($r['division_type'] ?? ''));
                    echo '<tr>'
                        . '<td>' . htmlspecialchars((string)$r['division_name'])
                            . ($dType ? ' <small class="text-muted">(' . $dType . ')</small>' : '') . '</td>'
                        . '<td class="text-end">' . (int)$r['block_count'] . '</td>'
                        . '<td class="text-end fw-semibold">' . number_format((float)$r['total_ha'], 2) . '</td>'
                        . '<td class="text-end text-success">' . number_format((float)$r['tm_ha'], 2) . '</td>'
                        . '<td class="text-end text-warning">' . number_format((float)$r['tbm_ha'], 2) . '</td>'
                        . ($hasNp ? '<td class="text-end text-secondary">' . number_format((float)($r['non_planted_ha'] ?? 0), 2) . '</td>' : '')
                        . '<td class="text-end">' . number_format((int)$r['total_plants']) . '</td>'
                        . '</tr>';
                }
                if ($multibu) {
                    echo '<tr class="fw-semibold" style="background:#f0fdf4">'
                        . '<td class="text-end text-muted small">Subtotal ' . htmlspecialchars($buName) . '</td>'
                        . '<td class="text-end">' . $buBlk . '</td>'
                        . '<td class="text-end">' . number_format($buHa, 2) . '</td>'
                        . '<td class="text-end text-success">' . number_format($buTm, 2) . '</td>'
                        . '<td class="text-end text-warning">' . number_format($buTbm, 2) . '</td>'
                        . ($hasNp ? '<td class="text-end text-secondary">' . number_format($buNp, 2) . '</td>' : '')
                        . '<td class="text-end">' . number_format($buPlt) . '</td>'
                        . '</tr>';
                }
            }

            // Grand total row
            echo '<tr class="table-dark fw-bold">'
                . '<td>TOTAL</td>'
                . '<td class="text-end">' . $grandBlk . '</td>'
                . '<td class="text-end">' . number_format($grandHa, 2) . '</td>'
                . '<td class="text-end">' . number_format($grandTm, 2) . '</td>'
                . '<td class="text-end">' . number_format($grandTbm, 2) . '</td>'
                . ($hasNp ? '<td class="text-end">' . number_format($grandNp, 2) . '</td>' : '')
                . '<td class="text-end">' . number_format($grandPlt) . '</td>'
                . '</tr>';
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">TM = Mature/Bearing &bull; TBM = Immature/Pre-bearing'
               . ($hasNp ? ' &bull; Non-Planted = Unplanted area (HCV, river, road, etc.)' : '')
               . '</p>';

            // ── Non-planted breakdown table (per division × category) ────────────
            if ($hasNp && !empty($npCategories) && !empty($npDetail)) {
                $npPivot = [];
                foreach ($npDetail as $r) {
                    $r = (array)$r;
                    $npPivot[(string)$r['division_name']][(string)$r['category_code']] = (float)$r['ha'];
                }
                $catCodes = array_keys($npCategories);
                echo '<div class="mt-2">';
                echo '<p class="text-muted small mb-1 fw-semibold">📋 Non-Planted Area Detail by Division</p>';
                echo '<div class="table-responsive">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';
                echo '<thead class="table-secondary"><tr><th>Division</th>';
                foreach ($catCodes as $cc) {
                    echo '<th class="text-end">' . htmlspecialchars((string)$npCategories[$cc]) . '</th>';
                }
                echo '<th class="text-end fw-bold">Total (ha)</th></tr></thead><tbody>';
                $grandByCode = array_fill_keys($catCodes, 0.0);
                foreach ($npPivot as $divName => $catMap) {
                    $rowTotal = array_sum($catMap);
                    echo '<tr><td>' . htmlspecialchars($divName) . '</td>';
                    foreach ($catCodes as $cc) {
                        $v = $catMap[$cc] ?? 0.0;
                        $grandByCode[$cc] += $v;
                        echo '<td class="text-end">' . ($v > 0 ? number_format($v, 2) : '<span class="text-muted">—</span>') . '</td>';
                    }
                    echo '<td class="text-end fw-semibold">' . number_format($rowTotal, 2) . '</td></tr>';
                }
                echo '<tr class="table-dark fw-bold"><td>TOTAL</td>';
                foreach ($catCodes as $cc) {
                    echo '<td class="text-end">' . number_format($grandByCode[$cc], 2) . '</td>';
                }
                echo '<td class="text-end">' . number_format($grandNp, 2) . '</td></tr>';
                echo '</tbody></table></div></div>';
            }
            break;

        case 'chart_request':
            $src      = (array)($answer['source_answer'] ?? []);
            $srcType  = (string)($src['type'] ?? '');
            $subtype  = (string)($answer['subtype'] ?? 'bar'); // 'bar' | 'bar3d' | 'pie' | 'pie3d' | 'line'
            $chartId  = 'chart-' . substr(md5(json_encode($src) . $subtype), 0, 8);

            if ($srcType === 'area_by_division' && !empty($src['rows'])) {
                $rows    = (array)$src['rows'];
                $labels  = []; $totalHa = []; $tmHa = []; $tbmHa = [];
                // Palette for pie slices — enough colours for many divisions
                $palette = ['#4ade80','#86efac','#fbbf24','#fde68a','#60a5fa','#93c5fd',
                            '#f472b6','#f9a8d4','#a78bfa','#c4b5fd','#34d399','#6ee7b7'];
                foreach ($rows as $r) {
                    $r = (array)$r;
                    $labels[]  = $r['division_name'];
                    $totalHa[] = round((float)$r['total_ha'], 2);
                    $tmHa[]    = round((float)$r['tm_ha'],    2);
                    $tbmHa[]   = round((float)$r['tbm_ha'],   2);
                }
                $labelsJson  = json_encode($labels);
                $totalJson   = json_encode($totalHa);
                $tmJson      = json_encode($tmHa);
                $tbmJson     = json_encode($tbmHa);
                $paletteJson = json_encode(array_slice(array_merge($palette, $palette), 0, count($labels)));
                $scope       = htmlspecialchars((string)($src['scope'] ?? ''));
                $typeLabel   = match($subtype) {
                    'pie3d'  => 'Pie 3D',
                    'pie'    => 'Pie',
                    'bar3d'  => 'Bar 3D',
                    default  => 'Bar',
                };

                echo '<span class="qna-tag tag-division">📊 ' . $typeLabel . ' Chart: Area by Division</span> <strong>' . $scope . '</strong>';

                if ($subtype === 'pie3d') {
                    $pie3dColors = array_slice(array_merge(
                        ['#4ade80','#86efac','#fbbf24','#fde68a','#60a5fa','#93c5fd','#f472b6','#f9a8d4','#a78bfa','#c4b5fd','#34d399','#6ee7b7'],
                        ['#4ade80','#86efac','#fbbf24','#fde68a','#60a5fa','#93c5fd','#f472b6','#f9a8d4','#a78bfa','#c4b5fd','#34d399','#6ee7b7']
                    ), 0, count($labels));
                    echo agro_pie3d_html($chartId, $labels, $totalHa, $pie3dColors, ' ha');

                } elseif ($subtype === 'pie') {
                    // Pie: show total_ha per division
                    echo '<div style="position:relative;max-width:480px;margin-top:.75rem">';
                    echo   '<canvas id="' . $chartId . '"></canvas>';
                    echo '</div>';
                    echo '<script>
(function(){
  var el = document.getElementById(' . json_encode($chartId) . ');
  if (!el) return;
  function drawChart() {
    new Chart(el, {
      type: "pie",
      data: {
        labels: ' . $labelsJson . ',
        datasets: [{
          data: ' . $totalJson . ',
          backgroundColor: ' . $paletteJson . ',
          borderColor: "#fff", borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: "right" },
          tooltip: { callbacks: { label: function(ctx){
            var total = ctx.dataset.data.reduce(function(a,b){return a+b;},0);
            var pct   = total > 0 ? (ctx.parsed / total * 100).toFixed(1) : 0;
            return ctx.label + ": " + ctx.parsed + " ha (" + pct + "%)";
          }}}
        }
      }
    });
  }
  if (typeof Chart !== "undefined") { drawChart(); }
  else { var s=document.createElement("script"); s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"; s.onload=drawChart; document.head.appendChild(s); }
})();
</script>';

                } elseif ($subtype === 'bar3d') {
                    $bar3dDs = [
                        ['name' => 'TM (ha)',  'data' => array_values($tmHa),    'color' => '#22c55e'],
                        ['name' => 'TBM (ha)', 'data' => array_values($tbmHa),   'color' => '#eab308'],
                    ];
                    echo agro_bar3d_html($chartId, $labels, $bar3dDs, 'Hektar (ha)', ' ha');
                } else {
                    // Bar (default): TM + TBM stacked + Total line
                    echo '<div style="position:relative;max-width:680px;margin-top:.75rem">';
                    echo   '<canvas id="' . $chartId . '" height="300"></canvas>';
                    echo '</div>';
                    echo '<script>
(function(){
  var el = document.getElementById(' . json_encode($chartId) . ');
  if (!el) return;
  function drawChart() {
    new Chart(el, {
      type: "bar",
      data: {
        labels: ' . $labelsJson . ',
        datasets: [
          { label: "TM (ha)",    data: ' . $tmJson  . ', backgroundColor: "rgba(34,197,94,.75)",  borderColor: "rgba(22,163,74,1)",  borderWidth:1 },
          { label: "TBM (ha)",   data: ' . $tbmJson . ', backgroundColor: "rgba(234,179,8,.75)",  borderColor: "rgba(202,138,4,1)",  borderWidth:1 },
          { label: "Total (ha)", data: ' . $totalJson . ', backgroundColor: "rgba(59,130,246,.25)", borderColor: "rgba(37,99,235,.8)", borderWidth:1, type:"line", fill:false, tension:.3, pointRadius:4 }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position:"top" },
          tooltip: { callbacks: { label: function(ctx){ return ctx.dataset.label + ": " + ctx.parsed.y + " ha"; } } }
        },
        scales: {
          x: { stacked: true, ticks:{ maxRotation:45 } },
          y: { stacked: false, beginAtZero: true, title:{ display:true, text:"Hektar (ha)" } }
        }
      }
    });
  }
  if (typeof Chart !== "undefined") { drawChart(); }
  else { var s=document.createElement("script"); s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"; s.onload=drawChart; document.head.appendChild(s); }
})();
</script>';
                }

            } elseif ($srcType === 'harvest_total') {
                // Bar chart: bunches vs kg for a single scope
                $scope    = htmlspecialchars((string)($src['scope'] ?? ''));
                $kg       = round((float)($src['total_kg']  ?? 0) / 1000, 2);
                $bunches  = (int)($src['bunches'] ?? 0);
                echo '<span class="qna-tag tag-harvest">📊 Harvest Chart</span> <strong>' . $scope . '</strong>';
                echo '<div style="max-width:400px;margin-top:.75rem"><canvas id="' . $chartId . '" height="220"></canvas></div>';
                echo '<script>
(function(){
  var el = document.getElementById(' . json_encode($chartId) . ');
  function draw(){
    new Chart(el,{type:"bar",data:{labels:["FFB (ton)","Janjang"],datasets:[{data:[' . $kg . ',' . $bunches . '],backgroundColor:["rgba(34,197,94,.75)","rgba(59,130,246,.75)"],borderWidth:1}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';

            } elseif ($srcType === 'top_blocks') {
                $blocks = (array)($src['blocks'] ?? []);
                $labels = array_map(fn($b) => $b['block_name'] ?? '', $blocks);
                $vals   = array_map(fn($b) => round((float)($b['total_kg'] ?? 0) / 1000, 2), $blocks);
                $labelsJson = json_encode(array_values($labels));
                $valsJson   = json_encode(array_values($vals));
                echo '<span class="qna-tag tag-harvest">📊 Top Harvest Blocks Chart</span>';
                echo '<div style="position:relative;max-width:680px;margin-top:.75rem"><canvas id="' . $chartId . '" height="280"></canvas></div>';
                echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  function draw(){
    new Chart(el,{type:"bar",data:{labels:' . $labelsJson . ',datasets:[{label:"Total Panen (ton)",data:' . $valsJson . ',backgroundColor:"rgba(34,197,94,.75)",borderWidth:1}]},options:{indexAxis:"y",plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,title:{display:true,text:"Ton"}}}}});
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';

            } elseif ($srcType === 'road_by_type' && !empty($src['road_types'])) {
                // ── Road by type chart ────────────────────────────────────────
                $roadTypes   = (array)$src['road_types'];
                $grandByType = (array)($src['grand_by_type'] ?? []);
                $divMap      = (array)($src['div_map'] ?? []);
                $scope       = htmlspecialchars((string)($src['scope'] ?? ''));

                $palette = ['#4ade80','#60a5fa','#fbbf24','#f472b6','#a78bfa',
                            '#34d399','#fb923c','#38bdf8','#e879f9','#facc15'];

                if (in_array($subtype, ['pie', 'pie3d'])) {
                    // Pie / Pie3D: total panjang per jenis jalan
                    $labels = []; $vals = []; $colors = [];
                    foreach ($roadTypes as $i => $t) {
                        $len = (float)($grandByType[$t]['length_m'] ?? 0);
                        if ($len > 0) {
                            $labels[] = $t;
                            $vals[]   = round($len);
                            $colors[] = $palette[$i % count($palette)];
                        }
                    }
                    $labelsJson = json_encode($labels);
                    $valsJson   = json_encode($vals);
                    $colorsJson = json_encode($colors);

                    if ($subtype === 'pie3d') {
                        echo '<span class="qna-tag tag-block">📊 Pie 3D Chart: Roads by Type</span> <strong>' . $scope . '</strong>';
                        echo agro_pie3d_html($chartId, $labels, $vals, $colors, ' m');
                    } else {
                        echo '<span class="qna-tag tag-block">📊 Pie Chart: Roads by Type</span> <strong>' . $scope . '</strong>';
                        echo '<div style="position:relative;max-width:480px;margin-top:.75rem"><canvas id="' . $chartId . '"></canvas></div>';
                        echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"pie",
      data:{
        labels:' . $labelsJson . ',
        datasets:[{data:' . $valsJson . ',backgroundColor:' . $colorsJson . ',borderColor:"#fff",borderWidth:2}]
      },
      options:{
        responsive:true,
        plugins:{
          legend:{position:"right"},
          tooltip:{callbacks:{label:function(ctx){
            var tot=ctx.dataset.data.reduce(function(a,b){return a+b;},0);
            var pct=tot>0?(ctx.parsed/tot*100).toFixed(1):0;
            return ctx.label+": "+ctx.parsed.toLocaleString()+" m ("+pct+"%)";
          }}}
        }
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                    }
                } elseif ($subtype === 'bar3d') {
                    $bar3dDs = [];
                    foreach ($roadTypes as $i => $t) {
                        $vals3d = [];
                        foreach (array_keys($divMap) as $div) {
                            $vals3d[] = round((float)($divMap[$div]['types'][$t]['length_m'] ?? 0));
                        }
                        $bar3dDs[] = ['name' => $t, 'data' => $vals3d, 'color' => $palette[$i % count($palette)]];
                    }
                    echo '<span class="qna-tag tag-block">📊 Bar 3D Chart: Roads by Division</span> <strong>' . $scope . '</strong>';
                    echo agro_bar3d_html($chartId, array_keys($divMap), $bar3dDs, 'Length (m)', ' m');
                } else {
                    // Bar (default): length (m) per road-type per division,
                    // + area (ha) as a secondary line on a right Y-axis.
                    $divNames  = array_keys($divMap);
                    $divLabels = json_encode(array_values($divNames));
                    $datasets  = [];
                    // Bars — panjang (m) per road type, left axis
                    foreach ($roadTypes as $i => $t) {
                        $data = [];
                        foreach ($divNames as $div) {
                            $data[] = round((float)($divMap[$div]['types'][$t]['length_m'] ?? 0));
                        }
                        $rgb = $palette[$i % count($palette)];
                        $datasets[] = '{label:' . json_encode($t . ' — Length (m)') . ','
                                    . 'data:' . json_encode($data) . ','
                                    . 'backgroundColor:"' . $rgb . '88",'
                                    . 'borderColor:"' . $rgb . '",'
                                    . 'borderWidth:1,yAxisID:"yL"}';
                    }
                    // Lines — luas (ha) per road type, right axis
                    $areaShown = false;
                    foreach ($roadTypes as $i => $t) {
                        $anyArea = false;
                        $data = [];
                        foreach ($divNames as $div) {
                            $v = round((float)($divMap[$div]['types'][$t]['area_ha'] ?? 0), 2);
                            $data[] = $v;
                            if ($v > 0) $anyArea = true;
                        }
                        if ($anyArea) {
                            $rgb = $palette[$i % count($palette)];
                            $datasets[] = '{label:' . json_encode($t . ' — Area (ha)') . ','
                                        . 'data:' . json_encode($data) . ','
                                        . 'type:"line",fill:false,tension:.3,pointRadius:4,'
                                        . 'borderColor:"' . $rgb . '",'
                                        . 'backgroundColor:"' . $rgb . '44",'
                                        . 'borderDash:[4,3],borderWidth:2,yAxisID:"yR"}';
                            $areaShown = true;
                        }
                    }
                    $datasetsJson  = '[' . implode(',', $datasets) . ']';
                    $rightAxisJson = $areaShown
                        ? ',"yR":{type:"linear",position:"right",beginAtZero:true,title:{display:true,text:"Area (ha)"},grid:{drawOnChartArea:false}}'
                        : '';

                    echo '<span class="qna-tag tag-block">📊 Bar Chart: Roads by Division</span> <strong>' . $scope . '</strong>';
                    echo '<p class="text-muted small mb-1">Bar = Length (m) · Line = Area (ha)</p>';
                    echo '<div style="position:relative;max-width:760px;margin-top:.5rem"><canvas id="' . $chartId . '" height="340"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"bar",
      data:{labels:' . $divLabels . ',datasets:' . $datasetsJson . '},
      options:{
        responsive:true,
        plugins:{
          legend:{position:"top"},
          tooltip:{callbacks:{label:function(ctx){
            var u=ctx.dataset.yAxisID==="yR"?" ha":" m";
            return ctx.dataset.label+": "+ctx.parsed.y.toLocaleString()+u;
          }}}
        },
        scales:{
          x:{ticks:{maxRotation:45}},
          yL:{type:"linear",position:"left",beginAtZero:true,title:{display:true,text:"Length (m)"}}
          ' . $rightAxisJson . '
        }
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                }

            } elseif ($srcType === 'seed_varieties' && !empty($src['varieties'])) {
                // ── Seed varieties chart ──────────────────────────────────────
                $varieties = (array)$src['varieties'];
                $scope     = htmlspecialchars((string)($src['scope'] ?? ''));
                $labels = []; $blockCounts = []; $plantCounts = []; $colors = [];
                $palette = ['#4ade80','#60a5fa','#fbbf24','#f472b6','#a78bfa',
                            '#34d399','#fb923c','#38bdf8','#e879f9','#facc15'];
                foreach ($varieties as $i => $v) {
                    $v = (array)$v;
                    $labels[]      = $v['variety_name'] ?? $v['variety_code'];
                    $blockCounts[] = (int)$v['block_count'];
                    $plantCounts[] = (int)$v['total_plants'];
                    $colors[]      = $palette[$i % count($palette)];
                }
                $labelsJson      = json_encode($labels);
                $blockCountsJson = json_encode($blockCounts);
                $plantCountsJson = json_encode($plantCounts);
                $colorsJson      = json_encode($colors);

                if ($subtype === 'pie3d') {
                    echo '<span class="qna-tag tag-harvest">📊 Pie 3D Chart: Seeds by Variety</span> <strong>' . $scope . '</strong>';
                    echo agro_pie3d_html($chartId, $labels, $blockCounts, $colors, ' blocks');
                } elseif ($subtype === 'pie') {
                    // Pie: share of blocks per variety
                    echo '<span class="qna-tag tag-harvest">📊 Pie Chart: Seeds by Variety</span> <strong>' . $scope . '</strong>';
                    echo '<div style="position:relative;max-width:480px;margin-top:.75rem"><canvas id="' . $chartId . '"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"pie",
      data:{labels:' . $labelsJson . ',datasets:[{data:' . $blockCountsJson . ',backgroundColor:' . $colorsJson . ',borderColor:"#fff",borderWidth:2}]},
      options:{responsive:true,plugins:{legend:{position:"right"},tooltip:{callbacks:{label:function(ctx){
        var tot=ctx.dataset.data.reduce(function(a,b){return a+b;},0);
        var pct=tot>0?(ctx.parsed/tot*100).toFixed(1):0;
        return ctx.label+": "+ctx.parsed+" blok ("+pct+"%)";
      }}}}}
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                } elseif ($subtype === 'bar3d') {
                    $bar3dDs = [['name' => 'Block Count', 'data' => array_values($blockCounts), 'color' => '#22c55e']];
                    echo '<span class="qna-tag tag-harvest">📊 Bar 3D Chart: Seeds by Variety</span> <strong>' . $scope . '</strong>';
                    echo agro_bar3d_html($chartId, $labels, $bar3dDs, 'Block Count', ' blocks');
                } else {
                    // Bar: blok count per variety, with plant count as line
                    $hasPlants     = array_sum($plantCounts) > 0;
                    $plantDatasets = $hasPlants
                        ? ',{label:"Populasi",data:' . $plantCountsJson . ',type:"line",fill:false,tension:.3,pointRadius:4,borderColor:"rgba(59,130,246,.9)",backgroundColor:"rgba(59,130,246,.3)",borderWidth:2,yAxisID:"yR"}'
                        : '';
                    $rightAxis = $hasPlants
                        ? ',"yR":{type:"linear",position:"right",beginAtZero:true,title:{display:true,text:"Populasi"},grid:{drawOnChartArea:false}}'
                        : '';

                    echo '<span class="qna-tag tag-harvest">📊 Bar Chart: Seeds by Variety</span> <strong>' . $scope . '</strong>';
                    echo '<p class="text-muted small mb-1">Bar = Block Count · Line = Plant Population</p>';
                    echo '<div style="position:relative;max-width:720px;margin-top:.5rem"><canvas id="' . $chartId . '" height="320"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"bar",
      data:{labels:' . $labelsJson . ',datasets:[
        {label:"Block Count",data:' . $blockCountsJson . ',backgroundColor:' . $colorsJson . ',borderWidth:1,yAxisID:"yL"}
        ' . $plantDatasets . '
      ]},
      options:{
        responsive:true,
        plugins:{legend:{position:"top"},tooltip:{callbacks:{label:function(ctx){
          return ctx.dataset.label+": "+ctx.parsed.y.toLocaleString()+(ctx.dataset.yAxisID==="yR"?" pohon":" blok");
        }}}},
        scales:{
          x:{ticks:{maxRotation:45}},
          yL:{type:"linear",position:"left",beginAtZero:true,title:{display:true,text:"Block Count"}}
          ' . $rightAxis . '
        }
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                }

            } elseif ($srcType === 'plant_density' && !empty($src['rows'])) {
                // ── Plant density chart ───────────────────────────────────────
                $rows   = (array)$src['rows'];
                $scope  = htmlspecialchars((string)($src['scope'] ?? ''));
                $labels = []; $actDens = []; $normalPct = [];
                foreach ($rows as $r) {
                    $r = (array)$r;
                    $labels[]    = $r['division_name'];
                    $actDens[]   = (float)($r['actual_density'] ?? 0);
                    $plants      = (int)($r['total_plants'] ?? 0);
                    $normal      = (int)($r['normal_plants'] ?? 0);
                    $normalPct[] = $plants > 0 ? round($normal / $plants * 100, 1) : 0;
                }
                $labelsJson    = json_encode($labels);
                $actDensJson   = json_encode($actDens);
                $normalPctJson = json_encode($normalPct);

                echo '<span class="qna-tag tag-harvest">📊 Plant Density Chart</span> <strong>' . $scope . '</strong>';
                echo '<div style="position:relative;max-width:720px;margin-top:.75rem"><canvas id="' . $chartId . '" height="300"></canvas></div>';
                echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"bar",
      data:{
        labels:' . $labelsJson . ',
        datasets:[
          {label:"SPH Aktual (pohon/ha)",data:' . $actDensJson . ',backgroundColor:"rgba(34,197,94,.75)",borderColor:"rgba(22,163,74,1)",borderWidth:1,yAxisID:"y"},
          {label:"% Normal",data:' . $normalPctJson . ',backgroundColor:"rgba(59,130,246,.3)",borderColor:"rgba(37,99,235,.8)",borderWidth:1,type:"line",fill:false,tension:.3,pointRadius:4,yAxisID:"y2"}
        ]
      },
      options:{
        responsive:true,
        plugins:{legend:{position:"top"}},
        scales:{
          x:{ticks:{maxRotation:45}},
          y:{beginAtZero:false,position:"left",title:{display:true,text:"SPH (pohon/ha)"}},
          y2:{beginAtZero:false,position:"right",title:{display:true,text:"% Normal"},grid:{drawOnChartArea:false}}
        }
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';

            } elseif ($srcType === 'bridge_count' && !empty($src['rows'])) {
                // ── Bridge count chart ────────────────────────────────────────
                $rows  = (array)$src['rows'];
                $scope = htmlspecialchars((string)($src['scope'] ?? ''));
                $labels = []; $lengths = [];
                foreach ($rows as $r) {
                    $r = (array)$r;
                    if ((float)$r['bridge_length_m'] > 0) {
                        $labels[]  = $r['division_name'];
                        $lengths[] = round((float)$r['bridge_length_m']);
                    }
                }
                $labelsJson  = json_encode($labels);
                $lengthsJson = json_encode($lengths);

                if ($subtype === 'pie3d') {
                    $bridgeColors = ['#60a5fa','#4ade80','#fbbf24','#f472b6','#a78bfa','#34d399','#fb923c','#38bdf8','#e879f9','#facc15'];
                    echo '<span class="qna-tag tag-block">📊 Pie 3D Chart: Bridges by Division</span> <strong>' . $scope . '</strong>';
                    echo agro_pie3d_html($chartId, $labels, $lengths, array_slice(array_merge($bridgeColors, $bridgeColors), 0, count($labels)), ' m');
                } elseif ($subtype === 'bar3d') {
                    $bridgeColors3d = ['#60a5fa','#4ade80','#fbbf24','#f472b6','#a78bfa','#34d399','#fb923c','#38bdf8','#e879f9','#facc15'];
                    echo '<span class="qna-tag tag-block">📊 Bar 3D Chart: Bridges by Division</span> <strong>' . $scope . '</strong>';
                    echo agro_bar3d_html($chartId, $labels, [['name' => 'Length (m)', 'data' => array_values($lengths), 'color' => '#60a5fa']], 'Metres (m)', ' m');
                } else {
                echo '<span class="qna-tag tag-block">📊 Bridge Length by Division Chart</span> <strong>' . $scope . '</strong>';
                echo '<div style="position:relative;max-width:680px;margin-top:.75rem"><canvas id="' . $chartId . '" height="280"></canvas></div>';
                echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"' . ($subtype === 'pie' ? 'pie' : 'bar') . '",
      data:{labels:' . $labelsJson . ',datasets:[{label:"Bridge Length (m)",data:' . $lengthsJson . ',backgroundColor:"rgba(96,165,250,.75)",borderColor:"rgba(37,99,235,.8)",borderWidth:1}]},
      options:{
        responsive:true,
        plugins:{legend:{display:' . ($subtype === 'pie' ? 'true' : 'false') . '},tooltip:{callbacks:{label:function(ctx){return (ctx.label||ctx.dataset.label)+": "+ctx.parsed' . ($subtype === 'pie' ? '' : '.y') . '.toLocaleString()+" m";}}}},
        ' . ($subtype !== 'pie' ? 'scales:{x:{ticks:{maxRotation:45}},y:{beginAtZero:true,title:{display:true,text:"Meter (m)"}}}' : '') . '
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                } // end pie3d else

            } elseif ($srcType === 'chemicals_used' && !empty($src['chemicals'])) {
                // ── Chemicals used chart ──────────────────────────────────────
                $chems     = (array)$src['chemicals'];
                $scope     = htmlspecialchars((string)($src['scope'] ?? ''));
                $labels    = []; $qtys = []; $apps = []; $colors = [];
                $palette   = ['#f472b6','#fbbf24','#4ade80','#94a3b8','#60a5fa',
                              '#fb923c','#34d399','#e879f9','#facc15','#38bdf8'];
                $typeColor = ['Insecticide' => '#f87171', 'Herbicide' => '#fbbf24',
                              'Fungicide'   => '#4ade80', 'Rodenticide' => '#94a3b8', 'Other' => '#60a5fa'];
                foreach ($chems as $i => $c) {
                    $c        = (array)$c;
                    $labels[] = $c['pesticide_name'] ?? ('Produk ' . ($i + 1));
                    $qtys[]   = round((float)($c['total_qty']           ?? 0), 2);
                    $apps[]   = (int)($c['application_count']           ?? 0);
                    $colors[] = $typeColor[(string)($c['pesticide_type'] ?? 'Other')] ?? $palette[$i % count($palette)];
                }
                $labelsJson = json_encode($labels);
                $qtysJson   = json_encode($qtys);
                $appsJson   = json_encode($apps);
                $colorsJson = json_encode($colors);

                if ($subtype === 'pie3d') {
                    echo '<span class="qna-tag tag-block">📊 Pie 3D Chart: Agro Chemical Usage</span> <strong>' . $scope . '</strong>';
                    echo agro_pie3d_html($chartId, $labels, $qtys, $colors, '');
                } elseif ($subtype === 'pie') {
                    echo '<span class="qna-tag tag-block">📊 Pie Chart: Agro Chemical Usage</span> <strong>' . $scope . '</strong>';
                    echo '<div style="position:relative;max-width:480px;margin-top:.75rem"><canvas id="' . $chartId . '"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"pie",
      data:{labels:' . $labelsJson . ',datasets:[{data:' . $qtysJson . ',backgroundColor:' . $colorsJson . ',borderColor:"#fff",borderWidth:2}]},
      options:{responsive:true,plugins:{legend:{position:"right"},tooltip:{callbacks:{label:function(ctx){
        var tot=ctx.dataset.data.reduce(function(a,b){return a+b;},0);
        var pct=tot>0?(ctx.parsed/tot*100).toFixed(1):0;
        return ctx.label+": "+ctx.parsed.toLocaleString()+" ("+pct+"%)";
      }}}}}
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                } elseif ($subtype === 'bar3d') {
                    echo '<span class="qna-tag tag-block">📊 Bar 3D Chart: Agro Chemical Usage</span> <strong>' . $scope . '</strong>';
                    echo agro_bar3d_html($chartId, $labels, [['name' => 'Qty Total', 'data' => array_values($qtys), 'color' => '#f472b6']], 'Qty Total', '');
                } else {
                    // Bar: qty per chemical, applications as line
                    $hasApps = array_sum($apps) > 0;
                    $appDataset = $hasApps
                        ? ',{label:"Applications",data:' . $appsJson . ',type:"line",fill:false,tension:.3,pointRadius:4,borderColor:"rgba(99,102,241,.9)",backgroundColor:"rgba(99,102,241,.3)",borderWidth:2,yAxisID:"yR"}'
                        : '';
                    $rightAxis = $hasApps
                        ? ',"yR":{type:"linear",position:"right",beginAtZero:true,title:{display:true,text:"No. of Applications"},grid:{drawOnChartArea:false}}'
                        : '';

                    echo '<span class="qna-tag tag-block">📊 Bar Chart: Agro Chemical Usage</span> <strong>' . $scope . '</strong>';
                    echo '<p class="text-muted small mb-1">Bar = Total Qty · Line = Applications</p>';
                    echo '<div style="position:relative;max-width:760px;margin-top:.5rem"><canvas id="' . $chartId . '" height="340"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"bar",
      data:{labels:' . $labelsJson . ',datasets:[
        {label:"Qty Total",data:' . $qtysJson . ',backgroundColor:' . $colorsJson . ',borderWidth:1,yAxisID:"yL"}
        ' . $appDataset . '
      ]},
      options:{
        responsive:true,
        plugins:{legend:{position:"top"},tooltip:{callbacks:{label:function(ctx){
          return ctx.dataset.label+": "+ctx.parsed.y.toLocaleString()+(ctx.dataset.yAxisID==="yR"?" aplikasi":" unit");
        }}}},
        scales:{
          x:{ticks:{maxRotation:50}},
          yL:{type:"linear",position:"left",beginAtZero:true,title:{display:true,text:"Qty Total"}}
          ' . $rightAxis . '
        }
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                }

            } elseif (in_array($srcType, ['chemicals_by_division', 'chemicals_by_block', 'chemicals_by_planting_year'], true) && !empty($src['pivot'])) {
                // ── Chemicals by Division / Block / Planting Year chart ────────
                $ctypesCh   = (array)$src['ctypes'];
                $pivotCh    = (array)$src['pivot'];
                $metaCh     = (array)($src['meta'] ?? []);
                $sortedCh   = (array)($src['sorted_keys'] ?? []);
                $grandTotCh = (array)($src['grand_totals'] ?? []);
                $scopeCh    = htmlspecialchars((string)($src['scope'] ?? ''));
                $isDivision = ($srcType === 'chemicals_by_division');
                $isPY       = ($srcType === 'chemicals_by_planting_year');

                $labels = []; $datasets = [];
                $palette = ['#f87171','#fbbf24','#4ade80','#94a3b8','#60a5fa','#fb923c','#a78bfa','#34d399'];

                // For bar: one dataset per chemical type, x-axis = division/block/year
                foreach ($sortedCh as $key) {
                    $m      = (array)($metaCh[$key] ?? []);
                    if ($isPY) {
                        $labels[] = 'TT ' . htmlspecialchars((string)$key);
                    } elseif ($isDivision) {
                        $labels[] = htmlspecialchars((string)($m['division']   ?? $key));
                    } else {
                        $labels[] = htmlspecialchars((string)($m['block_name'] ?? $m['block_code'] ?? $key));
                    }
                }
                $labelsJson = json_encode($labels);

                foreach ($ctypesCh as $i => $ct) {
                    $data = [];
                    foreach ($sortedCh as $key) {
                        $data[] = round((float)(($pivotCh[$key] ?? [])[$ct] ?? 0), 2);
                    }
                    $rgb = $palette[$i % count($palette)];
                    $datasets[] = '{label:' . json_encode((string)$ct) . ',data:' . json_encode($data)
                                . ',backgroundColor:"' . $rgb . '88",borderColor:"' . $rgb . '",borderWidth:1}';
                }
                $datasetsJson = '[' . implode(',', $datasets) . ']';

                $titleLabel = $isPY ? 'Chemicals by Planting Year' : ($isDivision ? 'Chemicals by Division' : 'Chemicals by Block');

                if ($subtype === 'pie' || $subtype === 'pie3d') {
                    // Pie: total qty per chemical type
                    $pieLabels = []; $pieVals = []; $pieColors = [];
                    foreach ($ctypesCh as $i => $ct) {
                        $pieLabels[] = $ct;
                        $pieVals[]   = round((float)($grandTotCh[$ct] ?? 0), 2);
                        $pieColors[] = $palette[$i % count($palette)];
                    }
                    $pieLabelsJson = json_encode($pieLabels);
                    $pieValsJson   = json_encode($pieVals);
                    $pieColorsJson = json_encode($pieColors);

                    if ($subtype === 'pie3d') {
                        echo '<span class="qna-tag tag-block">📊 Pie 3D Chart: ' . $titleLabel . '</span> <strong>' . $scopeCh . '</strong>';
                        echo agro_pie3d_html($chartId, $pieLabels, $pieVals, $pieColors, '');
                    } else {
                        echo '<span class="qna-tag tag-block">📊 Pie Chart: ' . $titleLabel . '</span> <strong>' . $scopeCh . '</strong>';
                        echo '<div style="position:relative;max-width:480px;margin-top:.75rem"><canvas id="' . $chartId . '"></canvas></div>';
                        echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"pie",
      data:{labels:' . $pieLabelsJson . ',datasets:[{data:' . $pieValsJson . ',backgroundColor:' . $pieColorsJson . ',borderColor:"#fff",borderWidth:2}]},
      options:{responsive:true,plugins:{legend:{position:"right"},tooltip:{callbacks:{label:function(ctx){
        var tot=ctx.dataset.data.reduce(function(a,b){return a+b;},0);
        var pct=tot>0?(ctx.parsed/tot*100).toFixed(1):0;
        return ctx.label+": "+ctx.parsed.toLocaleString()+" ("+pct+"%)";
      }}}}}
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                    }
                } elseif ($subtype === 'bar3d') {
                    $bar3dDsCh = [];
                    foreach ($ctypesCh as $i => $ct) {
                        $data = [];
                        foreach ($sortedCh as $key) {
                            $data[] = round((float)(($pivotCh[$key] ?? [])[$ct] ?? 0), 2);
                        }
                        $bar3dDsCh[] = ['name' => (string)$ct, 'data' => $data, 'color' => $palette[$i % count($palette)]];
                    }
                    echo '<span class="qna-tag tag-block">📊 Bar 3D Chart: ' . $titleLabel . '</span> <strong>' . $scopeCh . '</strong>';
                    echo agro_bar3d_html($chartId, $labels, $bar3dDsCh, 'Total Qty', '');
                } else {
                    // Default bar: grouped by division/block, stacked by chem type
                    echo '<span class="qna-tag tag-block">📊 Bar Chart: ' . $titleLabel . '</span> <strong>' . $scopeCh . '</strong>';
                    echo '<div style="position:relative;max-width:760px;margin-top:.5rem"><canvas id="' . $chartId . '" height="340"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"bar",
      data:{labels:' . $labelsJson . ',datasets:' . $datasetsJson . '},
      options:{
        responsive:true,
        plugins:{legend:{position:"top"},tooltip:{callbacks:{label:function(ctx){
          return ctx.dataset.label+": "+ctx.parsed.y.toLocaleString();
        }}}},
        scales:{
          x:{ticks:{maxRotation:50}},
          y:{beginAtZero:true,title:{display:true,text:"Qty Total"}}
        }
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                }

            } elseif ($srcType === 'fertilization_used' && !empty($src['fertilizers'])) {
                // ── Fertilizer used chart ─────────────────────────────────────
                $ferts    = (array)$src['fertilizers'];
                $scope    = htmlspecialchars((string)($src['scope'] ?? ''));
                $labels   = []; $qtys = []; $apps = []; $colors = [];
                $palette  = ['#fbbf24','#4ade80','#60a5fa','#fb923c','#a78bfa',
                             '#34d399','#f472b6','#94a3b8','#facc15','#38bdf8'];
                foreach ($ferts as $i => $f) {
                    $f        = (array)$f;
                    $label    = ($f['fertilizer_type'] ?? ('Pupuk ' . ($i + 1)));
                    if (!empty($f['fertilizer_grade'])) $label .= ' (' . $f['fertilizer_grade'] . ')';
                    $labels[] = $label;
                    $qtys[]   = round((float)($f['total_qty_kg']      ?? 0), 0);
                    $apps[]   = (int)($f['application_count']          ?? 0);
                    $colors[] = $palette[$i % count($palette)];
                }
                $labelsJson = json_encode($labels);
                $qtysJson   = json_encode($qtys);
                $appsJson   = json_encode($apps);
                $colorsJson = json_encode($colors);

                if ($subtype === 'pie3d') {
                    echo '<span class="qna-tag tag-block">📊 Pie 3D Chart: Fertilizer Usage</span> <strong>' . $scope . '</strong>';
                    echo agro_pie3d_html($chartId, $labels, $qtys, $colors, ' kg');
                } elseif ($subtype === 'pie') {
                    echo '<span class="qna-tag tag-block">📊 Pie Chart: Fertilizer Usage</span> <strong>' . $scope . '</strong>';
                    echo '<div style="position:relative;max-width:480px;margin-top:.75rem"><canvas id="' . $chartId . '"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"pie",
      data:{labels:' . $labelsJson . ',datasets:[{data:' . $qtysJson . ',backgroundColor:' . $colorsJson . ',borderColor:"#fff",borderWidth:2}]},
      options:{responsive:true,plugins:{legend:{position:"right"},tooltip:{callbacks:{label:function(ctx){
        var tot=ctx.dataset.data.reduce(function(a,b){return a+b;},0);
        var pct=tot>0?(ctx.parsed/tot*100).toFixed(1):0;
        return ctx.label+": "+ctx.parsed.toLocaleString()+" kg ("+pct+"%)";
      }}}}}
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                } elseif ($subtype === 'bar3d') {
                    echo '<span class="qna-tag tag-block">📊 Bar 3D Chart: Fertilizer Usage</span> <strong>' . $scope . '</strong>';
                    echo agro_bar3d_html($chartId, $labels, [['name' => 'Qty Total (kg)', 'data' => array_values($qtys), 'color' => '#fbbf24']], 'Qty (kg)', ' kg');
                } else {
                    $hasApps = array_sum($apps) > 0;
                    $appDataset = $hasApps
                        ? ',{label:"Applications",data:' . $appsJson . ',type:"line",fill:false,tension:.3,pointRadius:4,borderColor:"rgba(239,68,68,.9)",backgroundColor:"rgba(239,68,68,.3)",borderWidth:2,yAxisID:"yR"}'
                        : '';
                    $rightAxis = $hasApps
                        ? ',"yR":{type:"linear",position:"right",beginAtZero:true,title:{display:true,text:"No. of Applications"},grid:{drawOnChartArea:false}}'
                        : '';

                    echo '<span class="qna-tag tag-block">📊 Bar Chart: Fertilizer Usage</span> <strong>' . $scope . '</strong>';
                    echo '<p class="text-muted small mb-1">Bar = Total Qty (kg) · Line = Applications</p>';
                    echo '<div style="position:relative;max-width:760px;margin-top:.5rem"><canvas id="' . $chartId . '" height="340"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"bar",
      data:{labels:' . $labelsJson . ',datasets:[
        {label:"Qty Total (kg)",data:' . $qtysJson . ',backgroundColor:' . $colorsJson . ',borderWidth:1,yAxisID:"yL"}
        ' . $appDataset . '
      ]},
      options:{
        responsive:true,
        plugins:{legend:{position:"top"},tooltip:{callbacks:{label:function(ctx){
          return ctx.dataset.label+": "+ctx.parsed.y.toLocaleString()+(ctx.dataset.yAxisID==="yR"?" applications":" kg");
        }}}},
        scales:{
          x:{ticks:{maxRotation:50}},
          yL:{type:"linear",position:"left",beginAtZero:true,title:{display:true,text:"Qty (kg)"}}
          ' . $rightAxis . '
        }
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                }

            } elseif ($srcType === 'harvest_transport' && !empty($src['harvest_by_div'])) {
                // ── Harvest + Transport chart ─────────────────────────────────
                $hDivs   = (array)$src['harvest_by_div'];
                $dDivMap = [];
                foreach ((array)($src['delivery_by_div'] ?? []) as $d) {
                    $d = (array)$d;
                    $dDivMap[(string)$d['division_name']] = (float)$d['total_net_kg'];
                }
                $scope    = htmlspecialchars((string)($src['scope'] ?? ''));
                $labels   = []; $harvKg = []; $delivKg = [];
                foreach ($hDivs as $h) {
                    $h        = (array)$h;
                    $div      = (string)$h['division_name'];
                    $labels[] = $div;
                    $harvKg[] = round((float)$h['total_kg'], 0);
                    $delivKg[]= round($dDivMap[$div] ?? 0, 0);
                }
                $labelsJson  = json_encode($labels);
                $harvJson    = json_encode($harvKg);
                $delivJson   = json_encode($delivKg);

                $harvestColors = ['#4ade80','#60a5fa','#fbbf24','#fb923c','#a78bfa','#34d399','#f472b6','#94a3b8','#facc15','#38bdf8'];
                $harvestColorsSliced = array_slice(array_merge($harvestColors, $harvestColors), 0, count($labels));
                $harvestColorsJson = json_encode($harvestColorsSliced);
                if ($subtype === 'pie3d') {
                    echo '<span class="qna-tag tag-harvest">📊 Pie 3D Chart: Harvest</span> <strong>' . $scope . '</strong>';
                    echo agro_pie3d_html($chartId, $labels, $harvKg, $harvestColorsSliced, ' kg');
                } elseif ($subtype === 'pie') {
                    echo '<span class="qna-tag tag-harvest">📊 Pie Chart: Harvest</span> <strong>' . $scope . '</strong>';
                    echo '<div style="position:relative;max-width:480px;margin-top:.75rem"><canvas id="' . $chartId . '"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"pie",
      data:{labels:' . $labelsJson . ',datasets:[{label:"Total Harvest (kg)",data:' . $harvJson . ',backgroundColor:' . $harvestColorsJson . ',borderColor:"#fff",borderWidth:2}]},
      options:{responsive:true,plugins:{legend:{position:"right"},tooltip:{callbacks:{label:function(ctx){
        var tot=ctx.dataset.data.reduce(function(a,b){return a+b;},0);
        var pct=tot>0?(ctx.parsed/tot*100).toFixed(1):0;
        return ctx.label+": "+ctx.parsed.toLocaleString()+" kg ("+pct+"%)";
      }}}}}
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                } elseif ($subtype === 'bar3d') {
                    $bar3dDs = [['name' => 'Total Harvest (kg)', 'data' => array_values($harvKg), 'color' => '#4ade80']];
                    if (array_sum($delivKg) > 0) {
                        $bar3dDs[] = ['name' => 'FFB Delivered (kg)', 'data' => array_values($delivKg), 'color' => '#60a5fa'];
                    }
                    echo '<span class="qna-tag tag-harvest">📊 Bar 3D Chart: Harvest &amp; Transport</span> <strong>' . $scope . '</strong>';
                    echo agro_bar3d_html($chartId, $labels, $bar3dDs, 'kg', ' kg');
                } else {
                    $hasDeliv = array_sum($delivKg) > 0;
                    $delivDataset = $hasDeliv
                        ? ',{label:"FFB Delivered (kg)",data:' . $delivJson . ',backgroundColor:"rgba(96,165,250,.6)",borderColor:"rgba(37,99,235,.8)",borderWidth:1}'
                        : '';
                    echo '<span class="qna-tag tag-harvest">📊 Harvest &amp; Transport Chart</span> <strong>' . $scope . '</strong>';
                    echo '<p class="text-muted small mb-1">Green = Total Harvest · Blue = FFB Delivered to Mill</p>';
                    echo '<div style="position:relative;max-width:760px;margin-top:.5rem"><canvas id="' . $chartId . '" height="340"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"bar",
      data:{labels:' . $labelsJson . ',datasets:[
        {label:"Total Harvest (kg)",data:' . $harvJson . ',backgroundColor:"rgba(74,222,128,.7)",borderColor:"rgba(22,163,74,.8)",borderWidth:1}
        ' . $delivDataset . '
      ]},
      options:{
        responsive:true,
        plugins:{legend:{position:"top"},tooltip:{callbacks:{label:function(ctx){
          return ctx.dataset.label+": "+ctx.parsed.y.toLocaleString()+" kg";
        }}}},
        scales:{x:{ticks:{maxRotation:45}},y:{beginAtZero:true,title:{display:true,text:"kg"}}}
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                }

            } elseif (in_array($srcType, ['pest_by_planting_year', 'pest_by_division', 'pest_by_block'], true) && !empty($src['pivot'])) {
                // ── Pest & Disease by Planting Year / Division / Block chart ──────
                $ptypesPch  = (array)$src['ptypes'];
                $pivotPch   = (array)$src['pivot'];
                $metaPch    = (array)($src['meta'] ?? []);
                $sortedPch  = (array)($src['sorted_keys'] ?? []);
                $grandTsPch = (array)($src['grand_totals'] ?? []);
                $scopePch   = htmlspecialchars((string)($src['scope'] ?? ''));
                $isPY       = ($srcType === 'pest_by_planting_year');
                $isDiv      = ($srcType === 'pest_by_division');

                // Build x-axis labels
                $labels = [];
                foreach ($sortedPch as $key) {
                    $m = (array)($metaPch[$key] ?? []);
                    if ($isPY) {
                        $labels[] = 'TT ' . htmlspecialchars((string)$key);
                    } elseif ($isDiv) {
                        $labels[] = htmlspecialchars((string)($m['division'] ?? $key));
                    } else {
                        $labels[] = htmlspecialchars((string)($m['block_name'] ?? $m['block_code'] ?? $key));
                    }
                }
                $labelsJson = json_encode($labels);

                $palette = ['#f87171','#fbbf24','#4ade80','#94a3b8','#60a5fa','#fb923c','#a78bfa','#34d399'];
                $titlePch = $isPY ? 'Pest & Disease by Planting Year' : ($isDiv ? 'Pest & Disease by Division' : 'Pest & Disease by Block');

                if ($subtype === 'pie' || $subtype === 'pie3d') {
                    // Pie: total records per pest type
                    $pieLabels = []; $pieVals = []; $pieColors = [];
                    foreach ($ptypesPch as $i => $pt) {
                        $pieLabels[] = (string)$pt;
                        $pieVals[]   = (int)($grandTsPch[$pt] ?? 0);
                        $pieColors[] = $palette[$i % count($palette)];
                    }
                    $pieLabelsJson = json_encode($pieLabels);
                    $pieValsJson   = json_encode($pieVals);
                    $pieColorsJson = json_encode($pieColors);

                    if ($subtype === 'pie3d') {
                        echo '<span class="qna-tag tag-block">📊 Pie 3D Chart: ' . $titlePch . '</span> <strong>' . $scopePch . '</strong>';
                        echo agro_pie3d_html($chartId, $pieLabels, $pieVals, $pieColors, ' records');
                    } else {
                        echo '<span class="qna-tag tag-block">📊 Pie Chart: ' . $titlePch . '</span> <strong>' . $scopePch . '</strong>';
                        echo '<div style="position:relative;max-width:480px;margin-top:.75rem"><canvas id="' . $chartId . '"></canvas></div>';
                        echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"pie",
      data:{labels:' . $pieLabelsJson . ',datasets:[{data:' . $pieValsJson . ',backgroundColor:' . $pieColorsJson . ',borderColor:"#fff",borderWidth:2}]},
      options:{responsive:true,plugins:{legend:{position:"right"},tooltip:{callbacks:{label:function(ctx){
        var tot=ctx.dataset.data.reduce(function(a,b){return a+b;},0);
        var pct=tot>0?(ctx.parsed/tot*100).toFixed(1):0;
        return ctx.label+": "+ctx.parsed.toLocaleString()+" records ("+pct+"%)";
      }}}}}
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                    }
                } elseif ($subtype === 'bar3d') {
                    $bar3dDsPch = [];
                    foreach ($ptypesPch as $i => $pt) {
                        $data = [];
                        foreach ($sortedPch as $key) {
                            $data[] = (int)(($pivotPch[$key] ?? [])[$pt] ?? 0);
                        }
                        $bar3dDsPch[] = ['name' => (string)$pt, 'data' => $data, 'color' => $palette[$i % count($palette)]];
                    }
                    echo '<span class="qna-tag tag-block">📊 Bar 3D Chart: ' . $titlePch . '</span> <strong>' . $scopePch . '</strong>';
                    echo agro_bar3d_html($chartId, $labels, $bar3dDsPch, 'Record Count', ' records');
                } else {
                    // Default stacked bar: x = year/division/block, datasets = pest types
                    $datasets = [];
                    foreach ($ptypesPch as $i => $pt) {
                        $data = [];
                        foreach ($sortedPch as $key) {
                            $data[] = (int)(($pivotPch[$key] ?? [])[$pt] ?? 0);
                        }
                        $rgb = $palette[$i % count($palette)];
                        $datasets[] = '{label:' . json_encode((string)$pt) . ',data:' . json_encode($data)
                                    . ',backgroundColor:"' . $rgb . '88",borderColor:"' . $rgb . '",borderWidth:1,stack:"pest"}';
                    }
                    $datasetsJson = '[' . implode(',', $datasets) . ']';
                    echo '<span class="qna-tag tag-block">📊 Bar Chart: ' . $titlePch . '</span> <strong>' . $scopePch . '</strong>';
                    echo '<p class="text-muted small mb-1">Stacked bars per pest/disease type · Value = attack records</p>';
                    echo '<div style="position:relative;max-width:760px;margin-top:.5rem"><canvas id="' . $chartId . '" height="340"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"bar",
      data:{labels:' . $labelsJson . ',datasets:' . $datasetsJson . '},
      options:{
        responsive:true,
        plugins:{legend:{position:"top"},tooltip:{callbacks:{label:function(ctx){
          return ctx.dataset.label+": "+ctx.parsed.y.toLocaleString()+" records";
        }}}},
        scales:{
          x:{stacked:true,ticks:{maxRotation:50}},
          y:{stacked:true,beginAtZero:true,title:{display:true,text:"Record Count"}}
        }
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                }

            } elseif (in_array($srcType, ['fertilization_by_division', 'fertilization_by_block', 'fertilization_by_planting_year'], true) && !empty($src['pivot'])) {
                // ── Fertilization by Division / Block / Planting Year chart ──────
                $ftypesFch  = (array)$src['ftypes'];
                $pivotFch   = (array)$src['pivot'];
                $metaFch    = (array)($src['meta'] ?? []);
                $sortedFch  = (array)($src['sorted_keys'] ?? []);
                $grandTsFch = (array)($src['grand_totals'] ?? []);
                $scopeFch   = htmlspecialchars((string)($src['scope'] ?? ''));
                $isFPY      = ($srcType === 'fertilization_by_planting_year');
                $isFDiv     = ($srcType === 'fertilization_by_division');

                $labels = [];
                foreach ($sortedFch as $key) {
                    $m = (array)($metaFch[$key] ?? []);
                    if ($isFPY) {
                        $labels[] = 'TT ' . htmlspecialchars((string)$key);
                    } elseif ($isFDiv) {
                        $labels[] = htmlspecialchars((string)($m['division'] ?? $key));
                    } else {
                        $labels[] = htmlspecialchars((string)($m['block_name'] ?? $m['block_code'] ?? $key));
                    }
                }
                $labelsJson = json_encode($labels);

                $paletteFch = ['#fbbf24','#4ade80','#60a5fa','#fb923c','#a78bfa','#34d399','#f472b6','#94a3b8'];
                $titleFch   = $isFPY ? 'Fertilization by Planting Year' : ($isFDiv ? 'Fertilization by Division' : 'Fertilization by Block');

                if ($subtype === 'pie' || $subtype === 'pie3d') {
                    $pieLabels = []; $pieVals = []; $pieColors = [];
                    foreach ($ftypesFch as $i => $ft) {
                        $pieLabels[] = (string)$ft;
                        $pieVals[]   = round((float)($grandTsFch[$ft] ?? 0), 0);
                        $pieColors[] = $paletteFch[$i % count($paletteFch)];
                    }
                    $pieLabelsJson = json_encode($pieLabels);
                    $pieValsJson   = json_encode($pieVals);
                    $pieColorsJson = json_encode($pieColors);

                    if ($subtype === 'pie3d') {
                        echo '<span class="qna-tag tag-block">📊 Pie 3D Chart: ' . $titleFch . '</span> <strong>' . $scopeFch . '</strong>';
                        echo agro_pie3d_html($chartId, $pieLabels, $pieVals, $pieColors, ' kg');
                    } else {
                        echo '<span class="qna-tag tag-block">📊 Pie Chart: ' . $titleFch . '</span> <strong>' . $scopeFch . '</strong>';
                        echo '<div style="position:relative;max-width:480px;margin-top:.75rem"><canvas id="' . $chartId . '"></canvas></div>';
                        echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"pie",
      data:{labels:' . $pieLabelsJson . ',datasets:[{data:' . $pieValsJson . ',backgroundColor:' . $pieColorsJson . ',borderColor:"#fff",borderWidth:2}]},
      options:{responsive:true,plugins:{legend:{position:"right"},tooltip:{callbacks:{label:function(ctx){
        var tot=ctx.dataset.data.reduce(function(a,b){return a+b;},0);
        var pct=tot>0?(ctx.parsed/tot*100).toFixed(1):0;
        return ctx.label+": "+ctx.parsed.toLocaleString()+" kg ("+pct+"%)";
      }}}}}
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                    }
                } elseif ($subtype === 'bar3d') {
                    $bar3dDsFch = [];
                    foreach ($ftypesFch as $i => $ft) {
                        $data = [];
                        foreach ($sortedFch as $key) {
                            $data[] = round((float)(($pivotFch[$key] ?? [])[$ft] ?? 0), 0);
                        }
                        $bar3dDsFch[] = ['name' => (string)$ft, 'data' => $data, 'color' => $paletteFch[$i % count($paletteFch)]];
                    }
                    echo '<span class="qna-tag tag-block">📊 Bar 3D Chart: ' . $titleFch . '</span> <strong>' . $scopeFch . '</strong>';
                    echo agro_bar3d_html($chartId, $labels, $bar3dDsFch, 'Qty (kg)', ' kg');
                } else {
                    $datasets = [];
                    foreach ($ftypesFch as $i => $ft) {
                        $data = [];
                        foreach ($sortedFch as $key) {
                            $data[] = round((float)(($pivotFch[$key] ?? [])[$ft] ?? 0), 0);
                        }
                        $rgb = $paletteFch[$i % count($paletteFch)];
                        $datasets[] = '{label:' . json_encode((string)$ft) . ',data:' . json_encode($data)
                                    . ',backgroundColor:"' . $rgb . '88",borderColor:"' . $rgb . '",borderWidth:1,stack:"fert"}';
                    }
                    $datasetsJson = '[' . implode(',', $datasets) . ']';
                    echo '<span class="qna-tag tag-block">📊 Bar Chart: ' . $titleFch . '</span> <strong>' . $scopeFch . '</strong>';
                    echo '<p class="text-muted small mb-1">Stacked bars per fertilizer type · Value = Qty (kg)</p>';
                    echo '<div style="position:relative;max-width:760px;margin-top:.5rem"><canvas id="' . $chartId . '" height="340"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"bar",
      data:{labels:' . $labelsJson . ',datasets:' . $datasetsJson . '},
      options:{
        responsive:true,
        plugins:{legend:{position:"top"},tooltip:{callbacks:{label:function(ctx){
          return ctx.dataset.label+": "+ctx.parsed.y.toLocaleString()+" kg";
        }}}},
        scales:{
          x:{stacked:true,ticks:{maxRotation:50}},
          y:{stacked:true,beginAtZero:true,title:{display:true,text:"Qty (kg)"}}
        }
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                }

            } elseif (in_array($srcType, ['weed_by_division', 'weed_by_block', 'weed_by_planting_year'], true) && !empty($src['pivot'])) {
                // ── Weed pivot chart (species breakdown per division / block / planting year) ──
                $wtypesWch  = (array)$src['wtypes'];
                $pivotWch   = (array)$src['pivot'];
                $metaWch    = (array)($src['meta'] ?? []);
                $sortedWch  = (array)($src['sorted_keys'] ?? []);
                $grandTsWch = (array)($src['grand_totals'] ?? []);
                $scopeWch   = htmlspecialchars((string)($src['scope'] ?? ''));
                $isWPY      = ($srcType === 'weed_by_planting_year');
                $isWDiv     = ($srcType === 'weed_by_division');

                $labels = [];
                foreach ($sortedWch as $wk) {
                    $wm = (array)($metaWch[$wk] ?? []);
                    if ($isWPY)       $labels[] = 'TT ' . htmlspecialchars((string)$wk);
                    elseif ($isWDiv)  $labels[] = htmlspecialchars((string)($wm['division']   ?? $wk));
                    else              $labels[] = htmlspecialchars((string)($wm['block_name']  ?? $wm['block_code'] ?? $wk));
                }
                $labelsJson = json_encode($labels);

                $paletteW  = ['#4ade80','#86efac','#fbbf24','#fde68a','#f472b6','#fb923c','#a78bfa','#94a3b8'];
                $titleWch  = $isWPY ? 'Weed Control by Planting Year' : ($isWDiv ? 'Weed Control by Division' : 'Weed Control by Block');

                if ($subtype === 'pie' || $subtype === 'pie3d') {
                    // Pie: total records per weed species
                    $pieLabels = []; $pieVals = []; $pieColors = [];
                    foreach ($wtypesWch as $i => $wt) {
                        $pieLabels[] = (string)$wt;
                        $pieVals[]   = (int)($grandTsWch[$wt] ?? 0);
                        $pieColors[] = $paletteW[$i % count($paletteW)];
                    }
                    $pieLabelsJson = json_encode($pieLabels);
                    $pieValsJson   = json_encode($pieVals);
                    $pieColorsJson = json_encode($pieColors);

                    if ($subtype === 'pie3d') {
                        echo '<span class="qna-tag tag-block">📊 Pie 3D Chart: ' . $titleWch . '</span> <strong>' . $scopeWch . '</strong>';
                        echo agro_pie3d_html($chartId, $pieLabels, $pieVals, $pieColors, ' records');
                    } else {
                        echo '<span class="qna-tag tag-block">📊 Pie Chart: ' . $titleWch . '</span> <strong>' . $scopeWch . '</strong>';
                        echo '<div style="position:relative;max-width:480px;margin-top:.75rem"><canvas id="' . $chartId . '"></canvas></div>';
                        echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"pie",
      data:{labels:' . $pieLabelsJson . ',datasets:[{data:' . $pieValsJson . ',backgroundColor:' . $pieColorsJson . ',borderColor:"#fff",borderWidth:2}]},
      options:{responsive:true,plugins:{legend:{position:"right"},tooltip:{callbacks:{label:function(ctx){
        var tot=ctx.dataset.data.reduce(function(a,b){return a+b;},0);
        var pct=tot>0?(ctx.parsed/tot*100).toFixed(1):0;
        return ctx.label+": "+ctx.parsed.toLocaleString()+" records ("+pct+"%)";
      }}}}}
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                    }
                } elseif ($subtype === 'bar3d') {
                    $bar3dDsWch = [];
                    foreach ($wtypesWch as $i => $wt) {
                        $data = [];
                        foreach ($sortedWch as $wk) { $data[] = (int)(($pivotWch[$wk] ?? [])[$wt] ?? 0); }
                        $bar3dDsWch[] = ['name' => (string)$wt, 'data' => $data, 'color' => $paletteW[$i % count($paletteW)]];
                    }
                    echo '<span class="qna-tag tag-block">📊 Bar 3D Chart: ' . $titleWch . '</span> <strong>' . $scopeWch . '</strong>';
                    echo agro_bar3d_html($chartId, $labels, $bar3dDsWch, 'Record Count', ' records');
                } else {
                    // Default stacked bar: species per dimension
                    $datasets = [];
                    foreach ($wtypesWch as $i => $wt) {
                        $data = [];
                        foreach ($sortedWch as $wk) { $data[] = (int)(($pivotWch[$wk] ?? [])[$wt] ?? 0); }
                        $rgb = $paletteW[$i % count($paletteW)];
                        $datasets[] = '{label:' . json_encode((string)$wt) . ',data:' . json_encode($data)
                                    . ',backgroundColor:"' . $rgb . '88",borderColor:"' . $rgb . '",borderWidth:1,stack:"weed"}';
                    }
                    $datasetsJson = '[' . implode(',', $datasets) . ']';
                    echo '<span class="qna-tag tag-block">📊 Bar Chart: ' . $titleWch . '</span> <strong>' . $scopeWch . '</strong>';
                    echo '<p class="text-muted small mb-1">Stacked bars per weed species · Value = control records</p>';
                    echo '<div style="position:relative;max-width:760px;margin-top:.5rem"><canvas id="' . $chartId . '" height="340"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"bar",
      data:{labels:' . $labelsJson . ',datasets:' . $datasetsJson . '},
      options:{
        responsive:true,
        plugins:{legend:{position:"top"},tooltip:{callbacks:{label:function(ctx){
          return ctx.dataset.label+": "+ctx.parsed.y.toLocaleString()+" records";
        }}}},
        scales:{
          x:{stacked:true,ticks:{maxRotation:50}},
          y:{stacked:true,beginAtZero:true,title:{display:true,text:"Record Count"}}
        }
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                }

            } elseif ($srcType === 'pest_analysis' && !empty($src['by_type_severity'])) {
                // ── Pest analysis chart (severity breakdown per pest type) ────────
                $byTypeSev = (array)$src['by_type_severity'];
                $scopePA   = htmlspecialchars((string)($src['scope'] ?? ''));

                // Aggregate: pivot by pest_type × severity count
                $typeMap = []; $sevSet = [];
                foreach ($byTypeSev as $r) {
                    $r   = (array)$r;
                    $typ = (string)($r['pest_type'] ?: 'Other');
                    $sev = (string)($r['severity']  ?: 'Unknown');
                    $typeMap[$typ][$sev] = ((int)($r['record_count'] ?? 0));
                    $sevSet[$sev]        = true;
                }
                // Order severities: Critical → High → Medium → Low → others
                $sevOrder = ['Critical','High','Medium','Low'];
                $sevs = array_keys($sevSet);
                usort($sevs, function($a, $b) use ($sevOrder) {
                    $ia = array_search($a, $sevOrder); $ib = array_search($b, $sevOrder);
                    $ia = $ia === false ? 99 : $ia;    $ib = $ib === false ? 99 : $ib;
                    return $ia <=> $ib;
                });
                $types = array_keys($typeMap);
                $labelsPA = json_encode(array_values($types));
                $paletteSev = ['Critical'=>'#dc2626','High'=>'#f97316','Medium'=>'#eab308','Low'=>'#22c55e','Unknown'=>'#94a3b8'];

                if ($subtype === 'pie' || $subtype === 'pie3d') {
                    // Pie: total records per pest type
                    $pieLab = []; $pieVal = []; $pieCols = [];
                    $piePal = ['#f87171','#fbbf24','#4ade80','#60a5fa','#a78bfa','#34d399','#fb923c','#94a3b8'];
                    foreach ($types as $i => $typ) {
                        $pieLab[]  = $typ;
                        $pieVal[]  = array_sum($typeMap[$typ]);
                        $pieCols[] = $piePal[$i % count($piePal)];
                    }
                    if ($subtype === 'pie3d') {
                        echo '<span class="qna-tag tag-block">📊 Grafik Pie 3D Hama &amp; Penyakit</span> <strong>' . $scopePA . '</strong>';
                        echo agro_pie3d_html($chartId, $pieLab, $pieVal, $pieCols, ' catatan');
                    } else {
                        echo '<span class="qna-tag tag-block">📊 Grafik Pie Hama &amp; Penyakit</span> <strong>' . $scopePA . '</strong>';
                        echo '<div style="position:relative;max-width:480px;margin-top:.75rem"><canvas id="' . $chartId . '"></canvas></div>';
                        echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"pie",
      data:{labels:' . json_encode($pieLab) . ',datasets:[{data:' . json_encode($pieVal) . ',backgroundColor:' . json_encode($pieCols) . ',borderColor:"#fff",borderWidth:2}]},
      options:{responsive:true,plugins:{legend:{position:"right"},tooltip:{callbacks:{label:function(ctx){
        var tot=ctx.dataset.data.reduce(function(a,b){return a+b;},0);
        var pct=tot>0?(ctx.parsed/tot*100).toFixed(1):0;
        return ctx.label+": "+ctx.parsed.toLocaleString()+" catatan ("+pct+"%)";
      }}}}}
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                    }
                } elseif ($subtype === 'bar3d') {
                    $bar3dDsPA = [];
                    foreach ($sevs as $sev) {
                        $data = [];
                        foreach ($types as $typ) { $data[] = (int)($typeMap[$typ][$sev] ?? 0); }
                        $bar3dDsPA[] = ['name' => $sev, 'data' => $data, 'color' => $paletteSev[$sev] ?? '#94a3b8'];
                    }
                    echo '<span class="qna-tag tag-block">📊 Grafik Batang 3D Hama &amp; Penyakit</span> <strong>' . $scopePA . '</strong>';
                    echo agro_bar3d_html($chartId, $types, $bar3dDsPA, 'Catatan', ' catatan');
                } else {
                    $datasetsPA = [];
                    foreach ($sevs as $sev) {
                        $data = [];
                        foreach ($types as $typ) { $data[] = (int)($typeMap[$typ][$sev] ?? 0); }
                        $rgb = $paletteSev[$sev] ?? '#94a3b8';
                        $datasetsPA[] = '{label:' . json_encode($sev) . ',data:' . json_encode($data)
                                      . ',backgroundColor:"' . $rgb . '88",borderColor:"' . $rgb . '",borderWidth:1,stack:"sev"}';
                    }
                    echo '<span class="qna-tag tag-block">📊 Grafik Hama &amp; Penyakit per Jenis &amp; Severity</span> <strong>' . $scopePA . '</strong>';
                    echo '<p class="text-muted small mb-1">Batang bertumpuk per tingkat keparahan · Nilai = jumlah catatan</p>';
                    echo '<div style="position:relative;max-width:760px;margin-top:.5rem"><canvas id="' . $chartId . '" height="340"></canvas></div>';
                    echo '<script>
(function(){
  var el=document.getElementById(' . json_encode($chartId) . ');
  if(!el)return;
  function draw(){
    new Chart(el,{
      type:"bar",
      data:{labels:' . $labelsPA . ',datasets:[' . implode(',', $datasetsPA) . ']},
      options:{
        responsive:true,
        plugins:{legend:{position:"top"},tooltip:{callbacks:{label:function(ctx){
          return ctx.dataset.label+": "+ctx.parsed.y.toLocaleString()+" catatan";
        }}}},
        scales:{
          x:{stacked:true,ticks:{maxRotation:50}},
          y:{stacked:true,beginAtZero:true,title:{display:true,text:"Jumlah Catatan"}}
        }
      }
    });
  }
  if(typeof Chart!=="undefined")draw();
  else{var s=document.createElement("script");s.src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";s.onload=draw;document.head.appendChild(s);}
})();
</script>';
                }

            } else {
                echo '<span class="qna-tag tag-unknown">📊 Grafik</span> ';
                echo '<span class="text-muted">Grafik tidak tersedia untuk jawaban sebelumnya.'
                   . ($srcType ? ' (tipe: <code>' . htmlspecialchars($srcType) . '</code>)' : ' Belum ada jawaban sebelumnya.')
                   . '</span>';
                echo '<br><span class="text-muted small">Coba tanya tabel terlebih dahulu, lalu ketik "Tampilkan grafik".</span>';
            }
            break;

        case 'sustainability_analysis': {
            $scope          = htmlspecialchars((string)($answer['scope']           ?? ''));
            $totalAreaHa    = (float)($answer['total_area_ha']    ?? 0);
            $plantedHa      = (float)($answer['planted_ha']       ?? 0);
            $nonPlantedHa   = (float)($answer['non_planted_ha']   ?? 0);
            $nonPlantedPct  = (float)($answer['non_planted_pct']  ?? 0);
            $conservHa      = (float)($answer['conserv_ha']       ?? 0);
            $conservRatio   = $answer['conserv_ratio_pct'] ?? null;
            $waterHa        = (float)($answer['water_ha']         ?? 0);
            $swampHa        = (float)($answer['swamp_ha']         ?? 0);
            $hcvHa          = (float)($answer['hcv_ha']           ?? 0);
            $hasWater       = !empty($answer['has_water_data']);
            $carbonTon      = (float)($answer['total_carbon_ton'] ?? 0);
            $blocksCarbon   = (int)  ($answer['blocks_with_carbon'] ?? 0);
            $totalBlocks    = (int)  ($answer['total_blocks']     ?? 0);
            $compRows       = (array)($answer['components']       ?? []);
            $isEmpty        = !empty($answer['empty']);

            echo '<span class="qna-tag tag-block" style="background:#064e3b;color:#fff">🌱 Analisa Keberlanjutan</span> ';
            echo '<strong>' . $scope . '</strong>';

            if ($isEmpty) {
                echo '<div class="mt-2 text-muted small">'
                   . 'Belum ada data keberlanjutan di <strong>' . $scope . '</strong>. '
                   . 'Isi data area konservasi di menu <a href="blocks.php">Komponen Luas Blok</a>.</div>';
                break;
            }

            // ── Stat Cards ────────────────────────────────────────────────────
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            if ($totalAreaHa > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalAreaHa, 0, ',', '.') . '</div><div class="qna-stat-lbl">Total HGU (ha)</div></div>';
            if ($plantedHa   > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($plantedHa,   0, ',', '.') . '</div><div class="qna-stat-lbl">Planted (ha)</div></div>';
            if ($nonPlantedHa> 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($nonPlantedHa,0, ',', '.') . '</div><div class="qna-stat-lbl">Non-Planted (ha)</div></div>';
            if ($conservHa   > 0) {
                $conCls = ($conservRatio !== null && (float)$conservRatio >= 20) ? 'text-success' : (((float)($conservRatio??0) >= 10) ? 'text-warning' : 'text-danger');
                echo '<div class="qna-stat"><div class="qna-stat-val ' . $conCls . '">' . number_format($conservHa, 1) . ' ha</div><div class="qna-stat-lbl">Conservation Area</div></div>';
                echo '<div class="qna-stat"><div class="qna-stat-val ' . $conCls . '">' . ($conservRatio !== null ? number_format((float)$conservRatio,1).'%' : '—') . '</div><div class="qna-stat-lbl">Conservation Ratio</div></div>';
            }
            if ($carbonTon  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($carbonTon, 0, ',', '.') . '</div><div class="qna-stat-lbl">Carbon (ton)</div></div>';
            echo '</div>';

            // ── Standards compliance table ────────────────────────────────────
            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
            echo '<thead style="background:#064e3b;color:#fff"><tr>'
               . '<th>Parameter Keberlanjutan</th><th class="text-center">Nilai</th>'
               . '<th class="text-center">Standar</th><th>Status</th><th>Sumber</th>'
               . '</tr></thead><tbody>';

            // Row 1: Conservation ratio
            $cRatioVal  = $conservRatio !== null ? number_format((float)$conservRatio, 2) . '%' : '—';
            $cStd       = agro_std_get('conservation_ratio');
            $cStatus    = ($conservRatio !== null && $cStd) ? agro_std_check((float)$conservRatio, $cStd) : ($conservHa === 0.0 ? 'fail' : 'warn');
            $cLbl       = ['pass' => '✅ Lulus', 'warn' => '⚠️ Perhatian', 'fail' => '❌ Tidak lulus'];
            $cCls       = ['pass' => 'success', 'warn' => 'warning', 'fail' => 'danger'];
            $cNote      = ($conservRatio === null || $conservHa === 0.0)
                ? 'Belum ada data area konservasi. Input via Komponen Luas Blok → kategori CONSERVATION/WATER/SWAMP.'
                : ($cStd ? agro_std_note((float)$conservRatio, $cStd) : '');
            echo '<tr>'
               . '<td class="fw-semibold">Rasio Area Konservasi terhadap HGU</td>'
               . '<td class="text-center fw-bold">' . $cRatioVal . '</td>'
               . '<td class="text-center text-muted">≥20%</td>'
               . '<td><span class="badge text-bg-' . $cCls[$cStatus] . '">' . $cLbl[$cStatus] . '</span><br>'
               . '<span class="small text-muted">' . htmlspecialchars($cNote) . '</span></td>'
               . '<td class="small text-muted">ISPO 2020 / Permentan No.11/2015</td>'
               . '</tr>';

            // Row 2: Buffer zone (hcv_buffer)
            $bStatus = $hasWater ? 'warn' : 'fail';
            $bNote   = $hasWater
                ? 'Area badan air tercatat ' . number_format($waterHa + $swampHa, 2) . ' ha. Lebar buffer tidak tersimpan di DB — verifikasi lapangan ≥50 m dari tepi sungai.'
                : 'Tidak ada data WATER/SWAMP di Komponen Luas Blok. Input sempadan sungai untuk verifikasi kepatuhan RSPO.';
            echo '<tr>'
               . '<td class="fw-semibold">Buffer Zone Sungai (riparian)</td>'
               . '<td class="text-center fw-bold">' . ($hasWater ? number_format($waterHa + $swampHa, 2) . ' ha' : '—') . '</td>'
               . '<td class="text-center text-muted">≥50 m</td>'
               . '<td><span class="badge text-bg-' . $cCls[$bStatus] . '">' . $cLbl[$bStatus] . '</span><br>'
               . '<span class="small text-muted">' . htmlspecialchars($bNote) . '</span></td>'
               . '<td class="small text-muted">RSPO P&amp;C 2018 / Permentan No.11/2015</td>'
               . '</tr>';

            echo '</tbody></table></div>';

            // ── Component breakdown table ─────────────────────────────────────
            if (!empty($compRows)) {
                // Friendly label map
                $catLabelsMap = [
                    'CONSERVATION' => '🌿 Conservation Area',
                    'WATER'        => '💧 Water Body / River',
                    'SWAMP'        => '🌊 Swamp / Wetland',
                    'HTKH'         => '🌳 Protected Forest (HTKh)',
                    'OTHER'        => '⬜ Other Non-Planted',
                ];
                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';
                echo '<thead style="background:#065f46;color:#fff"><tr>'
                   . '<th>Non-Planted Area Category</th>'
                   . '<th class="text-end">Area (ha)</th>'
                   . '<th class="text-end">% of HGU</th>'
                   . '<th class="text-end">Blocks</th>'
                   . '</tr></thead><tbody>';
                foreach ($compRows as $row) {
                    $row   = (array)$row;
                    $code  = strtoupper((string)$row['category_code']);
                    $lbl   = $catLabelsMap[$code] ?? htmlspecialchars((string)$row['category_name']);
                    $ha    = (float)$row['area_ha'];
                    $pct   = $totalAreaHa > 0 ? number_format($ha / $totalAreaHa * 100, 2) : '—';
                    $bc    = (int)$row['block_count'];
                    $isConserv = in_array($code, ['CONSERVATION','WATER','SWAMP','HTKH'], true);
                    echo '<tr' . ($isConserv ? ' style="background:#f0fdf4"' : '') . '>'
                       . '<td>' . $lbl . '</td>'
                       . '<td class="text-end fw-semibold">' . number_format($ha, 2) . '</td>'
                       . '<td class="text-end text-muted">' . $pct . '%</td>'
                       . '<td class="text-end text-muted">' . $bc . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }

            // ── Carbon stock note ─────────────────────────────────────────────
            if ($carbonTon > 0) {
                echo '<p class="text-muted small mt-1 mb-0">🌳 Stok karbon: <strong>' . number_format($carbonTon, 1) . ' ton C</strong>'
                   . ($totalBlocks > 0 && $blocksCarbon > 0 ? ' dari <strong>' . $blocksCarbon . '/' . $totalBlocks . '</strong> blok berdata' : '') . '.</p>';
            } else {
                echo '<p class="text-muted small mt-1 mb-0">Data stok karbon belum diisi. Tambahkan nilai <em>carbon_stock_ton</em> pada tiap blok untuk pelaporan ISPO / RSPO.</p>';
            }

            echo '<p class="text-muted small mt-1 mb-0" style="font-size:.72rem">Referensi: ISPO 2020 · RSPO P&amp;C 2018 · Permentan No.11/2015 · PP No.57/2016</p>';

            // Auto-analysis
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        case 'plantation_analysis': {
            $scope          = htmlspecialchars((string)($answer['scope']           ?? ''));
            $totalPlantedHa = (float)($answer['total_planted_ha']  ?? 0);
            $totalAreaHa    = (float)($answer['total_area_ha']     ?? 0);
            $tmAreaHa       = (float)($answer['tm_area_ha']        ?? 0);
            $tmRatioPct     = (float)($answer['tm_ratio_pct']      ?? 0);
            $plantedRatio   = (float)($answer['planted_ratio_pct'] ?? 0);
            $totalBlocks    = (int)  ($answer['total_blocks']      ?? 0);
            $tmBlocks       = (int)  ($answer['tm_blocks']         ?? 0);
            $totalDivs      = (int)  ($answer['total_divisions']   ?? 0);
            $avgBlockHa     = (float)($answer['avg_block_ha']      ?? 0);
            $avgDivHa       = (float)($answer['avg_div_ha']        ?? 0);
            $avgSph         = (float)($answer['avg_sph']           ?? 0);
            $totalPlants    = (int)  ($answer['total_plants']      ?? 0);
            $normalPlants   = (int)  ($answer['normal_plants']     ?? 0);
            $abnPlants      = (int)  ($answer['abnormal_plants']   ?? 0);
            $deadPlants     = (int)  ($answer['dead_plants']       ?? 0);
            $normalRatio    = $answer['normal_ratio_pct']  ?? null;
            $abnRatio       = $answer['abnormal_ratio_pct']?? null;
            $deadRatio      = $answer['dead_ratio_pct']    ?? null;
            $sisipRatio     = $answer['sisip_ratio_pct']   ?? null;
            $avgAbw         = (float)($answer['avg_abw']           ?? 0);
            $yieldPerHaTm   = $answer['yield_per_ha_tm'] ?? null;
            $totalKg        = (float)($answer['total_kg']          ?? 0);
            $totalBunches   = (int)  ($answer['total_bunches']     ?? 0);
            $harvRecords    = (int)  ($answer['harvest_records']   ?? 0);
            $divRows        = (array)($answer['divisions']         ?? []);
            $isEmpty        = !empty($answer['empty']);

            echo '<span class="qna-tag tag-block" style="background:#14532d;color:#fff">🌿 Analisa Perkebunan</span> ';
            echo '<strong>' . $scope . '</strong>';

            if ($isEmpty || ($totalPlantedHa === 0.0 && $totalBlocks === 0)) {
                echo '<div class="mt-2" style="font-size:.83rem">';
                echo '<p class="text-muted mb-1">Belum ada data blok/tanaman di <strong>' . $scope . '</strong>.</p>';
                echo '<p class="mb-0">Tambahkan data blok dan divisi melalui menu <a href="blocks.php">Manajemen Blok</a>.</p>';
                echo '</div>';
                break;
            }

            // ── Stat Cards ────────────────────────────────────────────────────
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            if ($totalPlantedHa > 0)  echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalPlantedHa, 0, ',', '.') . '</div><div class="qna-stat-lbl">Planted (ha)</div></div>';
            if ($totalBlocks    > 0)  echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalBlocks) . '</div><div class="qna-stat-lbl">Blocks</div></div>';
            if ($totalDivs      > 0)  echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalDivs)  . '</div><div class="qna-stat-lbl">Divisions</div></div>';
            if ($tmRatioPct     > 0)  echo '<div class="qna-stat"><div class="qna-stat-val ' . ($tmRatioPct >= 70 ? 'text-success' : ($tmRatioPct >= 50 ? 'text-warning' : 'text-danger')) . '">' . number_format($tmRatioPct, 1) . '%</div><div class="qna-stat-lbl">TM Ratio</div></div>';
            if ($tmAreaHa       > 0)  echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($tmAreaHa, 0, ',', '.') . '</div><div class="qna-stat-lbl">TM (ha)</div></div>';
            if ($avgSph         > 0)  echo '<div class="qna-stat"><div class="qna-stat-val ' . ($avgSph >= 136 && $avgSph <= 148 ? 'text-success' : 'text-warning') . '">' . number_format($avgSph, 0) . '</div><div class="qna-stat-lbl">Avg SPH</div></div>';
            if ($totalPlants    > 0)  echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalPlants) . '</div><div class="qna-stat-lbl">Total Plants</div></div>';
            if ($avgAbw         > 0)  echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($avgAbw, 1) . ' kg</div><div class="qna-stat-lbl">Avg ABW</div></div>';
            if ($harvRecords    > 0)  echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalKg / 1000, 1) . ' ton</div><div class="qna-stat-lbl">FFB (cumulative)</div></div>';
            echo '</div>';

            // ── Population health table ───────────────────────────────────────
            if ($totalPlants > 0 && ($normalPlants > 0 || $abnPlants > 0 || $deadPlants > 0)) {
                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead style="background:#14532d;color:#fff"><tr>'
                   . '<th>Status Tanaman</th><th class="text-end">Jumlah Pohon</th>'
                   . '<th class="text-end">Proporsi</th><th>Standar GAPKI</th></tr></thead><tbody>';

                $popRows = [
                    ['Normal',    $normalPlants, $normalRatio, '≥92%',  'success'],
                    ['Abnormal',  $abnPlants,    $abnRatio,    '<5%',   'warning'],
                    ['Mati',      $deadPlants,   $deadRatio,   '<2%',   'danger'],
                ];
                foreach ($popRows as [$lbl, $cnt, $pct, $stdD, $cls]) {
                    if ($cnt === 0 && (float)($pct ?? 0) === 0.0) continue;
                    echo '<tr>'
                       . '<td class="fw-semibold">' . $lbl . '</td>'
                       . '<td class="text-end">' . number_format($cnt) . '</td>'
                       . '<td class="text-end"><span class="badge text-bg-' . $cls . '">' . ($pct !== null ? $pct . '%' : '—') . '</span></td>'
                       . '<td class="text-muted small">' . $stdD . '</td>'
                       . '</tr>';
                }
                if ($sisipRatio !== null) {
                    $sisCls = (float)$sisipRatio < 5 ? 'success' : ((float)$sisipRatio < 10 ? 'warning' : 'danger');
                    echo '<tr class="table-light fw-semibold">'
                       . '<td>Perlu Sisip (Mati+Abnormal)</td>'
                       . '<td class="text-end">' . number_format($abnPlants + $deadPlants) . '</td>'
                       . '<td class="text-end"><span class="badge text-bg-' . $sisCls . '">' . $sisipRatio . '%</span></td>'
                       . '<td class="text-muted small">&lt;5%</td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }

            // ── Structure metrics row ─────────────────────────────────────────
            if ($avgBlockHa > 0 || $avgDivHa > 0 || $plantedRatio > 0) {
                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead class="table-success"><tr><th>Metrik Struktural</th><th class="text-center">Nilai</th><th class="text-center">Standar</th><th>Status</th></tr></thead><tbody>';

                $structMetrics = [
                    ['Rata-rata Luas Blok',    $avgBlockHa,   'ha',   'block_size',    '20–35 ha'],
                    ['Rata-rata Luas Afdeling',$avgDivHa,     'ha',   'division_size', '300–800 ha'],
                    ['Utilisasi Lahan (Tertanam/Total)', $plantedRatio, '%', 'planted_ratio', '≥75%'],
                ];
                foreach ($structMetrics as [$lbl, $val, $unit, $stdId, $stdDisp]) {
                    if ($val <= 0) continue;
                    $stdEntry = agro_std_get($stdId);
                    $st       = $stdEntry ? agro_std_check($val, $stdEntry) : 'pass';
                    $stLbl    = ['pass' => '✅ Lulus', 'warn' => '⚠️ Perhatian', 'fail' => '❌ Tidak lulus'];
                    $stCls    = ['pass' => 'success',  'warn' => 'warning',       'fail' => 'danger'];
                    echo '<tr>'
                       . '<td>' . $lbl . '</td>'
                       . '<td class="text-center fw-bold">' . number_format($val, 1) . ' ' . $unit . '</td>'
                       . '<td class="text-center text-muted">' . $stdDisp . '</td>'
                       . '<td><span class="badge text-bg-' . $stCls[$st] . '">' . $stLbl[$st] . '</span></td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }

            // ── Per-division table ────────────────────────────────────────────
            if (!empty($divRows)) {
                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';
                echo '<thead style="background:#14532d;color:#fff"><tr>'
                   . '<th>Division / Afdeling</th>'
                   . '<th class="text-end">Planted (ha)</th>'
                   . '<th class="text-end">TM (ha)</th>'
                   . '<th class="text-end">TBM (ha)</th>'
                   . '<th class="text-end">Blocks</th>'
                   . '<th class="text-end">Plants</th>'
                   . '</tr></thead><tbody>';
                foreach ($divRows as $d) {
                    $d         = (array)$d;
                    $divHa     = (float)($d['total_planted_area_ha'] ?? 0);
                    $dTmHa     = (float)($d['tm_area']   ?? 0);
                    $dTbmHa    = (float)($d['tbm_area']  ?? 0);
                    $dBlocks   = (int)  ($d['total_blocks'] ?? 0);
                    $dPlants   = (int)  ($d['total_plants'] ?? 0);
                    $dTmRatio  = $divHa > 0 ? round($dTmHa / $divHa * 100) : 0;
                    $rowCls    = $dTmRatio >= 70 ? '' : ($dTmRatio >= 50 ? 'table-warning' : 'table-danger');
                    echo '<tr class="' . $rowCls . '">'
                       . '<td class="fw-semibold">' . htmlspecialchars((string)$d['division_name']) . '</td>'
                       . '<td class="text-end">' . ($divHa > 0 ? number_format($divHa, 0, ',', '.') : '—') . '</td>'
                       . '<td class="text-end">' . ($dTmHa > 0 ? number_format($dTmHa, 0, ',', '.') . ' <span class="text-muted">(' . $dTmRatio . '%)</span>' : '—') . '</td>'
                       . '<td class="text-end">' . ($dTbmHa > 0 ? number_format($dTbmHa, 0, ',', '.') : '—') . '</td>'
                       . '<td class="text-end">' . ($dBlocks > 0 ? $dBlocks : '—') . '</td>'
                       . '<td class="text-end">' . ($dPlants > 0 ? number_format($dPlants) : '—') . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }

            // ── Harvest summary line ──────────────────────────────────────────
            if ($harvRecords > 0) {
                echo '<p class="text-muted small mt-1 mb-1">🌾 Panen kumulatif: <strong>'
                   . number_format($totalKg / 1000, 1) . ' ton TBS</strong>'
                   . ($totalBunches > 0 ? ' / <strong>' . number_format($totalBunches) . ' janjang</strong>' : '')
                   . ($avgAbw > 0 ? ' / ABW rata-rata <strong>' . number_format($avgAbw, 1) . ' kg/janjang</strong>' : '')
                   . ($yieldPerHaTm !== null ? ' / Produktivitas <strong>' . number_format((float)$yieldPerHaTm, 1) . ' ton/ha TM</strong>' : '')
                   . '.</p>';
                if (!empty($answer['first_date'])) {
                    echo '<p class="text-muted small mt-0 mb-0">Periode panen: '
                       . htmlspecialchars((string)$answer['first_date']) . ' – '
                       . htmlspecialchars((string)$answer['last_date']) . '</p>';
                }
            }

            echo '<p class="text-muted small mt-1 mb-0" style="font-size:.72rem">Referensi: PPKS Medan / GAPKI / SNI 8171:2015 / Ditjenbun 2020</p>';

            // Auto-analysis
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        case 'weed_by_division':
        case 'weed_by_block':
        case 'weed_by_planting_year': {
            $wScope     = htmlspecialchars((string)($answer['scope']       ?? ''));
            $wDateLbl   = htmlspecialchars((string)($answer['date_label']  ?? ''));
            $wTypes     = (array)($answer['wtypes']       ?? []);
            $wPivot     = (array)($answer['pivot']        ?? []);
            $wMeta      = (array)($answer['meta']         ?? []);
            $wSortedKeys= (array)($answer['sorted_keys']  ?? []);
            $wGrandTots = (array)($answer['grand_totals'] ?? []);
            $wGrandTotal= (int)  ($answer['grand_total']  ?? 0);
            $wGrandHiSev= (int)  ($answer['grand_high_sev'] ?? 0);
            $wIsEmpty   = !empty($answer['empty']);
            $wIsPY      = ($answer['type'] === 'weed_by_planting_year');
            $wIsDiv     = ($answer['type'] === 'weed_by_division');
            $wDimCount  = $wIsPY ? (int)($answer['year_count'] ?? 0) : ($wIsDiv ? (int)($answer['div_count'] ?? 0) : (int)($answer['block_count'] ?? 0));
            $wDimLabel  = $wIsPY ? 'Tahun Tanam' : ($wIsDiv ? 'Divisi' : 'Blok');

            $wTypeBadge = ['Alang-alang'=>'danger','Mikania'=>'warning','Teki'=>'success','Other'=>'secondary'];

            echo '<span class="qna-tag tag-block" style="background:#166534;color:#fff">🌿 Gulma per ' . $wDimLabel . '</span> ';
            echo '<strong>' . $wScope . '</strong>';
            if ($wDateLbl !== '') echo ' <span class="badge bg-secondary ms-1">' . $wDateLbl . '</span>';
            echo ' &mdash; <span class="text-muted">' . $wDimCount . ' ' . strtolower($wDimLabel) . '</span>';

            if (!empty($answer['db_error'])) {
                echo '<div class="alert alert-warning py-2 px-3 mt-2 mb-0" style="font-size:.8rem">'
                   . '<strong>⚠ Query error:</strong> ' . htmlspecialchars((string)$answer['db_error']) . '</div>';
                break;
            }

            if ($wIsEmpty || $wGrandTotal === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data pengendalian gulma per ' . strtolower($wDimLabel)
                   . ($wDateLbl !== '' ? ' untuk periode <strong>' . $wDateLbl . '</strong>' : '') . '. '
                   . 'Pastikan data Pengendalian Hama memiliki Jenis OPT = Gulma. '
                   . '<a href="pest_control.php">Input data →</a></p>';
                break;
            }

            // High severity alert banner
            if ($wGrandHiSev > 0) {
                $wSevPct = $wGrandTotal > 0 ? number_format($wGrandHiSev / $wGrandTotal * 100, 1) : 0;
                echo '<div class="alert alert-danger py-1 px-2 mt-2 mb-0 small">⚠️ <strong>' . $wGrandHiSev . '</strong> catatan infestasi <strong>High/Critical</strong> ('
                   . $wSevPct . '% dari total) — percepat rotasi pengendalian segera.</div>';
            }

            // Summary stat cards
            $wTotalHaAll  = array_sum(array_column($wMeta, 'total_ha'));
            $wTotalCostAll= array_sum(array_column($wMeta, 'total_cost'));
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . $wDimCount . '</div><div class="qna-stat-lbl">' . $wDimLabel . '</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($wGrandTotal) . '</div><div class="qna-stat-lbl">Weed Records</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val" style="color:#dc2626">' . $wGrandHiSev . '</div><div class="qna-stat-lbl">High/Critical</div></div>';
            if ($wTotalHaAll  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($wTotalHaAll, 1) . '</div><div class="qna-stat-lbl">Ha Treated</div></div>';
            if ($wTotalCostAll> 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($wTotalCostAll / 1e6, 1) . ' M</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            // Pivot table
            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';
            echo '<thead class="table-dark"><tr>'
               . '<th class="text-nowrap">' . $wDimLabel . '</th>';
            foreach ($wTypes as $wt) {
                echo '<th class="text-center text-nowrap"><span class="badge text-bg-' . ($wTypeBadge[$wt] ?? 'secondary') . '">' . htmlspecialchars((string)$wt) . '</span></th>';
            }
            echo '<th class="text-center text-nowrap">Total</th>'
               . '<th class="text-end text-nowrap">Ha</th>'
               . '<th class="text-end text-nowrap" style="color:#dc2626">High/Crit</th>'
               . '<th class="text-end text-nowrap">Last</th>'
               . '</tr><tr><th style="background:#2b3035;"></th>';
            foreach ($wTypes as $wt) {
                echo '<th class="text-center small fw-normal" style="background:#2b3035;color:#adb5bd">records</th>';
            }
            echo '<th style="background:#2b3035;"></th><th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th><th style="background:#2b3035;"></th>'
               . '</tr></thead><tbody>';

            foreach ($wSortedKeys as $wk) {
                $wm     = (array)($wMeta[$wk] ?? []);
                $wydata = (array)($wPivot[$wk] ?? []);
                $wRowTot= array_sum($wydata);
                $wHiSev = (int)  ($wm['high_sev']  ?? 0);
                $wHaRow = (float)($wm['total_ha']   ?? 0);

                if ($wIsPY) {
                    $wAge   = date('Y') - (int)$wk;
                    $wAgeCls= $wAge <= 3 ? 'text-info' : ($wAge >= 25 ? 'text-secondary' : '');
                    $wLabel = '<span class="fw-semibold ' . $wAgeCls . '">TT ' . htmlspecialchars((string)$wk)
                            . ' <small class="text-muted fw-normal">(±' . $wAge . 'thn)</small></span>';
                } elseif ($wIsDiv) {
                    $wLabel = '<span class="fw-semibold">' . htmlspecialchars((string)($wm['division'] ?? $wk)) . '</span>';
                } else {
                    $wLabel = '<span class="fw-semibold">' . htmlspecialchars((string)($wm['block_name'] ?? $wk))
                            . ' <small class="text-muted">' . htmlspecialchars((string)($wm['block_code'] ?? '')) . '</small></span>'
                            . '<br><small class="text-muted">' . htmlspecialchars((string)($wm['division'] ?? '')) . '</small>';
                }

                $rowCls = $wHiSev > 0 ? ' class="table-danger"' : '';
                echo "<tr{$rowCls}><td class=\"text-nowrap\">{$wLabel}</td>";
                foreach ($wTypes as $wt) {
                    $cnt = (int)($wydata[$wt] ?? 0);
                    echo '<td class="text-end">' . ($cnt > 0 ? '<strong>' . $cnt . '</strong>' : '<span class="text-muted">-</span>') . '</td>';
                }
                echo '<td class="text-end fw-bold text-success">' . $wRowTot . '</td>';
                echo '<td class="text-end text-muted">' . ($wHaRow > 0 ? number_format($wHaRow, 1) : '—') . '</td>';
                echo '<td class="text-end fw-bold" style="color:#dc2626">' . ($wHiSev > 0 ? $wHiSev : '—') . '</td>';
                echo '<td class="text-end small text-muted">' . htmlspecialchars((string)($wm['last_date'] ?? '')) . '</td>';
                echo '</tr>';
            }

            // Grand total row
            echo '<tr class="table-success fw-bold"><th class="text-end">Grand Total</th>';
            foreach ($wTypes as $wt) {
                echo '<th class="text-end">' . number_format((int)($wGrandTots[$wt] ?? 0)) . '</th>';
            }
            echo '<th class="text-end">' . number_format($wGrandTotal) . '</th>';
            echo '<th class="text-end">' . ($wTotalHaAll > 0 ? number_format($wTotalHaAll, 1) : '—') . '</th>';
            echo '<th class="text-end" style="color:#dc2626">' . ($wGrandHiSev > 0 ? $wGrandHiSev : '—') . '</th>';
            echo '<th></th></tr>';
            echo '</tbody></table></div>';

            $wFootNote = $wIsPY
                ? 'TT = Tahun Tanam &bull; Nilai = jumlah catatan per spesies gulma &bull; Usia TBM ≤3thn (biru)'
                : 'Nilai = jumlah catatan per spesies gulma';
            echo '<p class="text-muted small mb-0">' . $wFootNote
               . ' &bull; Baris merah = ada infestasi High/Critical &bull; Data dari <a href="pest_control.php">Pengendalian Hama →</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        case 'pest_analysis': {
            $scope       = htmlspecialchars((string)($answer['scope']          ?? ''));
            $total       = (int)  ($answer['total_records']   ?? 0);
            $critCnt     = (int)  ($answer['critical_count']  ?? 0);
            $highCnt     = (int)  ($answer['high_count']      ?? 0);
            $medCnt      = (int)  ($answer['medium_count']    ?? 0);
            $lowCnt      = (int)  ($answer['low_count']       ?? 0);
            $totalHa     = (float)($answer['total_area_ha']   ?? 0);
            $totalCost   = (float)($answer['total_cost']      ?? 0);
            $byTypeSev   = (array)($answer['by_type_severity'] ?? []);
            $topPests    = (array)($answer['top_pests']        ?? []);
            $effRows     = (array)($answer['effectiveness']    ?? []);
            $byDiv       = (array)($answer['by_division']      ?? []);
            $isEmpty     = !empty($answer['empty']);

            // Severity badge colours
            $sevBadge = [
                'Critical' => 'danger',
                'High'     => 'warning',
                'Medium'   => 'primary',
                'Low'      => 'success',
            ];

            echo '<span class="qna-tag tag-block" style="background:#7f1d1d;color:#fff">🐛 Hama &amp; Penyakit</span> ';
            echo '<strong>' . $scope . '</strong>';

            // ── DB error surfaced from catch ──────────────────────────────────
            if (!empty($answer['db_error'])) {
                echo '<div class="alert alert-warning py-2 px-3 mt-2 mb-0" style="font-size:.8rem">';
                echo '<strong>⚠ Tabel pest_control_records belum siap.</strong> ';
                echo 'Jalankan migration SQL terlebih dahulu:<br>';
                echo '<code>database/migrate_pest_control.sql</code> — buka di phpMyAdmin → Import, atau jalankan via CLI.<br>';
                echo '<span class="text-muted">Detail: ' . htmlspecialchars($answer['db_error']) . '</span>';
                echo '</div>';
                break;
            }

            if ($isEmpty || $total === 0) {
                echo '<div class="mt-2" style="font-size:.83rem">';
                echo '<p class="text-muted mb-1">Belum ada data pengendalian hama &amp; penyakit di <strong>' . $scope . '</strong>.</p>';
                echo '<p class="mb-1">Ada 2 cara untuk mengisi data:</p>';
                echo '<ol class="mb-1">';
                echo '<li>Input manual: <a href="pest_control.php" class="fw-semibold">Pengendalian Hama &amp; Penyakit</a> → klik <em>Catat Pengendalian</em></li>';
                echo '<li>Import data contoh: jalankan <code>database/seed_pest_control.sql</code> di phpMyAdmin → SQL</li>';
                echo '</ol>';
                echo '</div>';
                break;
            }

            // ── Summary stat cards ────────────────────────────────────────────
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($total) . '</div><div class="qna-stat-lbl">Total Records</div></div>';
            if ($critCnt > 0) echo '<div class="qna-stat"><div class="qna-stat-val text-danger">'  . number_format($critCnt) . '</div><div class="qna-stat-lbl">Critical</div></div>';
            if ($highCnt > 0) echo '<div class="qna-stat"><div class="qna-stat-val text-warning">' . number_format($highCnt) . '</div><div class="qna-stat-lbl">High</div></div>';
            if ($medCnt  > 0) echo '<div class="qna-stat"><div class="qna-stat-val text-primary">' . number_format($medCnt)  . '</div><div class="qna-stat-lbl">Medium</div></div>';
            if ($lowCnt  > 0) echo '<div class="qna-stat"><div class="qna-stat-val text-success">' . number_format($lowCnt)  . '</div><div class="qna-stat-lbl">Low</div></div>';
            if ($totalHa   > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalHa, 2)                    . '</div><div class="qna-stat-lbl">Ha Treated</div></div>';
            if ($totalCost > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalCost / 1e6, 1) . ' M'    . '</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            // ── Top Pest Names table ──────────────────────────────────────────
            if (!empty($topPests)) {
                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead style="background:#7f1d1d;color:#fff"><tr>'
                   . '<th>#</th><th>Pest / Disease Name</th><th>Type</th>'
                   . '<th class="text-end">Occurrences</th>'
                   . '<th class="text-end">Coverage (ha)</th>'
                   . '<th>Severity</th>'
                   . '</tr></thead><tbody>';
                foreach ($topPests as $i => $p) {
                    $p       = (array)$p;
                    $minSev  = (string)($p['min_severity'] ?? '');
                    $maxSev  = (string)($p['max_severity'] ?? $minSev);
                    $sevDisp = $minSev === $maxSev ? $maxSev : "{$minSev} – {$maxSev}";
                    $sevBg   = $sevBadge[$maxSev] ?? 'secondary';
                    $area    = (float)($p['total_area_ha'] ?? 0);
                    echo '<tr>'
                       . '<td class="text-muted">' . ($i + 1) . '</td>'
                       . '<td class="fw-semibold">' . htmlspecialchars((string)($p['pest_name'] ?? '—')) . '</td>'
                       . '<td><span class="badge text-bg-' . ($p['pest_type'] === 'Insect' ? 'danger' : ($p['pest_type'] === 'Disease' ? 'warning' : ($p['pest_type'] === 'Weed' ? 'success' : 'secondary'))) . '">'
                       . htmlspecialchars((string)($p['pest_type'] ?? '')) . '</span></td>'
                       . '<td class="text-end fw-bold">' . (int)$p['record_count'] . '</td>'
                       . '<td class="text-end">' . ($area > 0 ? number_format($area, 2) : '—') . '</td>'
                       . '<td><span class="badge text-bg-' . $sevBg . '">' . htmlspecialchars($sevDisp) . '</span></td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }

            // ── By Division table ─────────────────────────────────────────────
            if (!empty($byDiv)) {
                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead class="table-danger"><tr>'
                   . '<th>Division</th>'
                   . '<th class="text-end">Records</th>'
                   . '<th class="text-end">Critical</th>'
                   . '<th class="text-end">High</th>'
                   . '<th class="text-end">Ha</th>'
                   . '<th class="text-end">Cost (Rp)</th>'
                   . '</tr></thead><tbody>';
                foreach ($byDiv as $d) {
                    $d       = (array)$d;
                    $dCrit   = (int)($d['critical_count'] ?? 0);
                    $dHigh   = (int)($d['high_count']     ?? 0);
                    $rowCls  = ($dCrit > 0) ? 'table-danger' : ($dHigh > 0 ? 'table-warning' : '');
                    $dHa     = (float)($d['total_area_ha'] ?? 0);
                    $dCost   = (float)($d['total_cost']    ?? 0);
                    echo '<tr class="' . $rowCls . '">'
                       . '<td class="fw-semibold">' . htmlspecialchars((string)$d['division_name']) . '</td>'
                       . '<td class="text-end">'    . (int)$d['record_count'] . '</td>'
                       . '<td class="text-end">'    . ($dCrit > 0 ? '<span class="text-danger fw-bold">' . $dCrit . '</span>' : '—') . '</td>'
                       . '<td class="text-end">'    . ($dHigh > 0 ? '<span class="text-warning fw-bold">' . $dHigh . '</span>' : '—') . '</td>'
                       . '<td class="text-end">'    . ($dHa   > 0 ? number_format($dHa, 2) : '—') . '</td>'
                       . '<td class="text-end small">' . ($dCost > 0 ? 'Rp ' . number_format($dCost, 0, ',', '.') : '—') . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }

            // ── Effectiveness breakdown ───────────────────────────────────────
            if (!empty($effRows)) {
                $effTotal = array_sum(array_column($effRows, 'record_count'));
                $effParts = [];
                foreach ($effRows as $e) {
                    $e    = (array)$e;
                    $lbl  = htmlspecialchars((string)($e['effectiveness'] ?? ''));
                    $cnt  = (int)$e['record_count'];
                    $pct  = $effTotal > 0 ? number_format($cnt / $effTotal * 100, 1) : '—';
                    $effParts[] = "<strong>{$lbl}</strong>: {$cnt} ({$pct}%)";
                }
                echo '<p class="text-muted small mt-1 mb-0">Efektivitas: ' . implode(' &nbsp;|&nbsp; ', $effParts) . '</p>';
            }

            if (!empty($answer['first_date'])) {
                echo '<p class="text-muted small mt-1 mb-0">Periode: '
                   . htmlspecialchars((string)$answer['first_date']) . ' – '
                   . htmlspecialchars((string)$answer['last_date']) . '</p>';
            }

            // Auto-analysis
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        case 'weed_analysis': {
            $scope      = htmlspecialchars((string)($answer['scope']         ?? ''));
            $total      = (int)  ($answer['total_records']  ?? 0);
            $highCnt    = (int)  ($answer['high_count']     ?? 0);
            $critCnt    = (int)  ($answer['critical_count'] ?? 0);
            $totalHa    = (float)($answer['total_area_ha']  ?? 0);
            $totalCost  = (float)($answer['total_cost']     ?? 0);
            $manualCnt  = (int)  ($answer['manual_count']   ?? 0);
            $sprayCnt   = (int)  ($answer['spray_count']    ?? 0);
            $herbCnt    = (int)  ($answer['herbicide_count']?? 0);
            $paraquat   = (int)  ($answer['paraquat_count'] ?? 0);
            $topWeeds   = (array)($answer['top_weeds']      ?? []);
            $byMethod   = (array)($answer['by_method']      ?? []);
            $effRows    = (array)($answer['effectiveness']  ?? []);
            $byDiv      = (array)($answer['by_division']    ?? []);
            $isEmpty    = !empty($answer['empty']);

            echo '<span class="qna-tag tag-block" style="background:#166534;color:#fff">🌿 Pengendalian Gulma</span> ';
            echo '<strong>' . $scope . '</strong>';

            if (!empty($answer['db_error'])) {
                echo '<div class="alert alert-warning py-2 px-3 mt-2 mb-0" style="font-size:.8rem">';
                echo '<strong>⚠ Error query gulma.</strong> Detail: ' . htmlspecialchars($answer['db_error']);
                echo '</div>';
                break;
            }

            if ($isEmpty || $total === 0) {
                echo '<div class="mt-2" style="font-size:.83rem">';
                echo '<p class="text-muted mb-1">Belum ada data pengendalian gulma di <strong>' . $scope . '</strong>.</p>';
                echo '<ol class="mb-1">';
                echo '<li>Input manual: <a href="pest_control.php" class="fw-semibold">Pengendalian Hama &amp; Penyakit</a> → pilih Jenis OPT = <em>Gulma</em></li>';
                echo '<li>Import data contoh: jalankan <code>database/seed_weed_control.sql</code> di phpMyAdmin</li>';
                echo '</ol>';
                echo '</div>';
                break;
            }

            // ── Summary stat cards ────────────────────────────────────────────
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">'  . number_format($total)   . '</div><div class="qna-stat-lbl">Total Records</div></div>';
            if ($highCnt > 0) echo '<div class="qna-stat"><div class="qna-stat-val text-warning">' . number_format($highCnt) . '</div><div class="qna-stat-lbl">High Infestation</div></div>';
            if ($critCnt > 0) echo '<div class="qna-stat"><div class="qna-stat-val text-danger">'  . number_format($critCnt) . '</div><div class="qna-stat-lbl">Critical</div></div>';
            if ($totalHa > 0)   echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalHa, 1)              . '</div><div class="qna-stat-lbl">Ha Treated</div></div>';
            if ($manualCnt > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($manualCnt)               . '</div><div class="qna-stat-lbl">Manual</div></div>';
            if ($sprayCnt  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($sprayCnt)                . '</div><div class="qna-stat-lbl">Spray</div></div>';
            if ($totalCost > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalCost/1e6, 1) . ' M'. '</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            // ── Paraquat alert ────────────────────────────────────────────────
            if ($paraquat > 0) {
                echo '<div class="alert alert-danger py-2 mt-2 mb-0" style="font-size:.8rem">';
                echo '🚫 <strong>' . $paraquat . ' aplikasi Gramoxone/Paraquat</strong> terdeteksi — ';
                echo 'Paraquat dilarang di kebun RSPO (HHP / Kriteria 4.6). Segera eliminasi dari program semprot.';
                echo '</div>';
            }

            // ── Top Weed Species table ────────────────────────────────────────
            if (!empty($topWeeds)) {
                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead style="background:#166534;color:#fff"><tr>'
                   . '<th>#</th><th>Nama Gulma</th>'
                   . '<th class="text-end">Kejadian</th>'
                   . '<th class="text-end">Cakupan (ha)</th>'
                   . '<th>Keparahan Maks</th>'
                   . '</tr></thead><tbody>';
                $sevBadgeW = ['Critical'=>'danger','High'=>'warning','Medium'=>'primary','Low'=>'success'];
                foreach ($topWeeds as $i => $w) {
                    $w    = (array)$w;
                    $sev  = (string)($w['max_severity'] ?? 'Low');
                    $bc   = $sevBadgeW[$sev] ?? 'secondary';
                    $area = (float)($w['total_area_ha'] ?? 0);
                    echo '<tr>'
                       . '<td class="text-muted">' . ($i + 1) . '</td>'
                       . '<td class="fw-semibold">' . htmlspecialchars((string)($w['pest_name'] ?? '—')) . '</td>'
                       . '<td class="text-end fw-bold">' . (int)$w['record_count'] . '</td>'
                       . '<td class="text-end">' . ($area > 0 ? number_format($area, 1) : '—') . '</td>'
                       . '<td><span class="badge text-bg-' . $bc . '">' . htmlspecialchars($sev) . '</span></td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }

            // ── Method breakdown ──────────────────────────────────────────────
            if (!empty($byMethod)) {
                $methodLabels = [
                    'Spraying'    => 'Semprot Herbisida',
                    'Manual'      => 'Manual (Dongkel/Garuk)',
                    'Baiting'     => 'Pengumpanan',
                    'Biopesticide'=> 'Biopestisida',
                    'Other'       => 'Lainnya',
                ];
                echo '<div class="table-responsive mt-1">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead class="table-success"><tr>'
                   . '<th>Method</th><th>Type</th>'
                   . '<th class="text-end">Applications</th>'
                   . '<th class="text-end">Area (ha)</th>'
                   . '<th class="text-end">Cost (Rp)</th>'
                   . '</tr></thead><tbody>';
                foreach ($byMethod as $mth) {
                    $mth   = (array)$mth;
                    $mKey  = (string)($mth['application_method'] ?? '');
                    $mLbl  = $methodLabels[$mKey] ?? $mKey;
                    $mCost = (float)($mth['total_cost'] ?? 0);
                    $mHa   = (float)($mth['total_area_ha'] ?? 0);
                    echo '<tr>'
                       . '<td class="fw-semibold">' . htmlspecialchars($mLbl) . '</td>'
                       . '<td><span class="badge text-bg-' . ($mth['pesticide_type'] === 'Herbicide' ? 'warning' : ($mth['pesticide_type'] === 'Other' ? 'secondary' : 'info')) . '">'
                       . htmlspecialchars((string)($mth['pesticide_type'] ?? '')) . '</span></td>'
                       . '<td class="text-end">' . (int)$mth['record_count'] . '</td>'
                       . '<td class="text-end">' . ($mHa > 0 ? number_format($mHa, 1) : '—') . '</td>'
                       . '<td class="text-end small">' . ($mCost > 0 ? 'Rp ' . number_format($mCost, 0, ',', '.') : '—') . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }

            // ── By Division ───────────────────────────────────────────────────
            if (!empty($byDiv)) {
                echo '<div class="table-responsive mt-1">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead class="table-success"><tr>'
                   . '<th>Division</th>'
                   . '<th class="text-end">Records</th>'
                   . '<th class="text-end">High ⚠</th>'
                   . '<th class="text-end">Ha</th>'
                   . '<th class="text-end">Manual</th>'
                   . '<th class="text-end">Spray</th>'
                   . '</tr></thead><tbody>';
                foreach ($byDiv as $dv) {
                    $dv    = (array)$dv;
                    $dHigh = (int)($dv['high_count']  ?? 0);
                    $dHa   = (float)($dv['total_area_ha'] ?? 0);
                    echo '<tr class="' . ($dHigh > 0 ? 'table-warning' : '') . '">'
                       . '<td class="fw-semibold">' . htmlspecialchars((string)$dv['division_name']) . '</td>'
                       . '<td class="text-end">' . (int)$dv['record_count'] . '</td>'
                       . '<td class="text-end">' . ($dHigh > 0 ? '<span class="text-warning fw-bold">'.$dHigh.'</span>' : '—') . '</td>'
                       . '<td class="text-end">' . ($dHa > 0 ? number_format($dHa, 1) : '—') . '</td>'
                       . '<td class="text-end">' . ((int)($dv['manual_count'] ?? 0) ?: '—') . '</td>'
                       . '<td class="text-end">' . ((int)($dv['spray_count']  ?? 0) ?: '—') . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }

            // ── Effectiveness + period ────────────────────────────────────────
            if (!empty($effRows)) {
                $effTot = array_sum(array_column($effRows, 'record_count'));
                $effParts = [];
                foreach ($effRows as $e) {
                    $e   = (array)$e;
                    $lbl = htmlspecialchars((string)($e['effectiveness'] ?? ''));
                    $cnt = (int)$e['record_count'];
                    $pct = $effTot > 0 ? number_format($cnt / $effTot * 100, 1) : '—';
                    $effParts[] = "<strong>{$lbl}</strong>: {$cnt} ({$pct}%)";
                }
                echo '<p class="text-muted small mt-1 mb-0">Efektivitas: ' . implode(' &nbsp;|&nbsp; ', $effParts) . '</p>';
            }
            if (!empty($answer['first_date'])) {
                echo '<p class="text-muted small mt-1 mb-0">Periode: '
                   . htmlspecialchars((string)$answer['first_date']) . ' – '
                   . htmlspecialchars((string)$answer['last_date']) . '</p>';
            }
            echo '<p class="text-muted small mb-0">Standar: GAPKI 2020 · Rotasi 60–120 hari · Piringan ≥85% bersih · Gawangan ≥80% bersih · Bebas Paraquat (RSPO)</p>';

            // ── Auto-analysis ─────────────────────────────────────────────────
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        case 'chemicals_by_division':
            $scope      = htmlspecialchars((string)($answer['scope']      ?? ''));
            $dateLabel  = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $ctypes     = (array)($answer['ctypes']      ?? []);
            $pivot      = (array)($answer['pivot']       ?? []);
            $meta       = (array)($answer['meta']        ?? []);
            $sortedKeys = (array)($answer['sorted_keys'] ?? []);
            $grandTots  = (array)($answer['grand_totals']?? []);
            $grandTotal = (float)($answer['grand_total'] ?? 0);
            $divCount   = (int)  ($answer['div_count']   ?? 0);
            $isEmpty    = !empty($answer['empty']);
            $hasParaquat= !empty($answer['has_paraquat']);

            // Colour map for type badges
            $typeBadgeCD = ['Herbicide'=>'warning','Insecticide'=>'danger','Fungicide'=>'success','Rodenticide'=>'secondary','Other'=>'light'];

            echo '<span class="qna-tag tag-block">🧪 Bahan Kimia per Divisi</span> ';
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel !== '') echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';
            echo ' &mdash; <span class="text-muted">' . $divCount . ' divisi</span>';

            if ($hasParaquat) {
                echo '<div class="alert alert-danger py-1 px-2 mt-2 mb-0 small">⚠️ <strong>Paraquat / Gramoxone</strong> terdeteksi — dilarang di kebun bersertifikasi RSPO/HHP.</div>';
            }

            if ($isEmpty || $divCount === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data bahan kimia'
                    . ($dateLabel !== '' ? ' untuk periode <strong>' . $dateLabel . '</strong>' : '') . '. '
                    . 'Isi data di <a href="pest_control.php">Pengendalian Hama</a>.</p>';
                break;
            }

            // Grand-total stat badges
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . $divCount . '</div><div class="qna-stat-lbl">Divisions</div></div>';
            if ($grandTotal > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandTotal, 2) . '</div><div class="qna-stat-lbl">Total Qty</div></div>';
            $totalHaAll = array_sum(array_column($meta, 'total_ha'));
            if ($totalHaAll > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalHaAll, 2) . '</div><div class="qna-stat-lbl">Coverage (ha)</div></div>';
            $totalCostAll = array_sum(array_column($meta, 'total_cost'));
            if ($totalCostAll > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalCostAll / 1e6, 1) . ' M</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';

            // Header
            $nCols = 3 + count($ctypes) + 3; // Estate | Division | [types...] | Total | Ha | Cost
            echo '<thead class="table-dark"><tr>'
               . '<th class="text-nowrap">Estate</th>'
               . '<th class="text-nowrap">Division</th>';
            foreach ($ctypes as $ct) {
                $badge = $typeBadgeCD[$ct] ?? 'light';
                echo '<th class="text-center text-nowrap"><span class="badge text-bg-' . $badge . '">' . htmlspecialchars((string)$ct) . '</span></th>';
            }
            echo '<th class="text-center text-nowrap">Total Qty</th>'
               . '<th class="text-end text-nowrap">Ha</th>'
               . '<th class="text-end text-nowrap">Cost (Rp)</th>'
               . '</tr></thead><tbody>';

            $prevEstatCD = null;
            foreach ($sortedKeys as $dkey) {
                $m      = (array)($meta[$dkey] ?? []);
                $ddata  = (array)($pivot[$dkey] ?? []);
                $rowTot = array_sum($ddata);
                $mEst   = (string)($m['estate']   ?? '');
                $mDiv   = (string)($m['division']  ?? '');
                $mHa    = (float) ($m['total_ha']  ?? 0);
                $mCost  = (float) ($m['total_cost']?? 0);

                $showEstate = ($mEst !== $prevEstatCD);
                if ($showEstate) {
                    $eSpanCD = count(array_filter($sortedKeys, fn($k) => ((array)($meta[$k] ?? []))['estate'] ?? '' === $mEst));
                }
                $prevEstatCD = $mEst;

                echo '<tr>';
                if ($showEstate) {
                    echo '<td rowspan="' . ($eSpanCD ?? 1) . '" class="align-middle fw-semibold text-nowrap" style="background:#f8f9fa">'
                       . htmlspecialchars($mEst) . '</td>';
                }
                echo '<td class="text-nowrap">' . htmlspecialchars($mDiv) . '</td>';
                foreach ($ctypes as $ct) {
                    $qty = (float)($ddata[$ct] ?? 0);
                    echo '<td class="text-end">'
                       . ($qty > 0 ? number_format($qty, 2) : '<span class="text-muted">-</span>')
                       . '</td>';
                }
                echo '<td class="text-end fw-bold text-success">' . number_format($rowTot, 2) . '</td>';
                echo '<td class="text-end text-muted">' . ($mHa   > 0 ? number_format($mHa,   2) : '—') . '</td>';
                echo '<td class="text-end small">'      . ($mCost > 0 ? 'Rp ' . number_format($mCost, 0, ',', '.') : '—') . '</td>';
                echo '</tr>';
            }

            // Grand total row
            echo '<tr class="table-success fw-bold">'
               . '<th colspan="2" class="text-end">Grand Total</th>';
            foreach ($ctypes as $ct) {
                echo '<th class="text-end">' . number_format((float)($grandTots[$ct] ?? 0), 2) . '</th>';
            }
            echo '<th class="text-end">' . number_format($grandTotal, 2) . '</th>';
            echo '<th class="text-end">' . ($totalHaAll   > 0 ? number_format($totalHaAll,   2) : '—') . '</th>';
            echo '<th class="text-end small">' . ($totalCostAll > 0 ? 'Rp ' . number_format($totalCostAll, 0, ',', '.') : '—') . '</th>';
            echo '</tr>';
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">Qty = total penggunaan per tipe &bull; Ha = luas cakupan aplikasi &bull; Data dari modul <a href="pest_control.php">Pengendalian Hama</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;

        case 'chemicals_by_planting_year':
            $scope      = htmlspecialchars((string)($answer['scope']      ?? ''));
            $dateLabel  = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $ctypes     = (array)($answer['ctypes']      ?? []);
            $pivot      = (array)($answer['pivot']       ?? []);
            $meta       = (array)($answer['meta']        ?? []);
            $sortedKeys = (array)($answer['sorted_keys'] ?? []);
            $grandTots  = (array)($answer['grand_totals']?? []);
            $grandTotal = (float)($answer['grand_total'] ?? 0);
            $yearCount  = (int)  ($answer['year_count']  ?? 0);
            $isEmpty    = !empty($answer['empty']);
            $hasParaquat= !empty($answer['has_paraquat']);

            $typeBadgePY = ['Herbicide'=>'warning','Insecticide'=>'danger','Fungicide'=>'success','Rodenticide'=>'secondary','Other'=>'light'];

            echo '<span class="qna-tag tag-block">🧪 Chemicals by Planting Year</span> ';
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel !== '') echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';
            if ($yearCount > 0) echo ' &mdash; <span class="text-muted">' . $yearCount . ' tahun tanam</span>';

            // DB error banner
            if (!empty($answer['db_error'])) {
                echo '<div class="alert alert-warning py-2 px-3 mt-2 mb-0" style="font-size:.8rem">'
                   . '<strong>⚠ Query error:</strong> ' . htmlspecialchars((string)$answer['db_error'])
                   . '</div>';
                break;
            }

            if ($hasParaquat) {
                echo '<div class="alert alert-danger py-1 px-2 mt-2 mb-0 small">⚠️ <strong>Paraquat / Gramoxone</strong> terdeteksi — dilarang di kebun bersertifikasi RSPO/HHP.</div>';
            }

            if ($isEmpty || $yearCount === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data bahan kimia per tahun tanam'
                    . ($dateLabel !== '' ? ' untuk periode <strong>' . $dateLabel . '</strong>' : '')
                    . ' di <strong>' . $scope . '</strong>. '
                    . 'Pastikan data Pengendalian Hama memiliki Blok terhubung ke Tahun Tanam. '
                    . '<a href="pest_control.php">Input data →</a></p>';
                break;
            }

            // Grand-total stat badges
            $totalHaAllPY   = array_sum(array_column($meta, 'total_ha'));
            $totalCostAllPY = array_sum(array_column($meta, 'total_cost'));
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . $yearCount . '</div><div class="qna-stat-lbl">Planting Years</div></div>';
            if ($grandTotal > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandTotal, 2) . '</div><div class="qna-stat-lbl">Total Qty</div></div>';
            if ($totalHaAllPY   > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalHaAllPY, 2)       . '</div><div class="qna-stat-lbl">Coverage (ha)</div></div>';
            if ($totalCostAllPY > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalCostAllPY / 1e6, 1) . ' M</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';

            // Header: Planting Year | [type cols] | Total | Ha | Blocks | Cost
            echo '<thead class="table-dark"><tr>'
               . '<th class="text-nowrap">Planting Year</th>';
            foreach ($ctypes as $ct) {
                $badge = $typeBadgePY[$ct] ?? 'light';
                echo '<th class="text-center text-nowrap"><span class="badge text-bg-' . $badge . '">' . htmlspecialchars((string)$ct) . '</span></th>';
            }
            echo '<th class="text-center text-nowrap">Total Qty</th>'
               . '<th class="text-end text-nowrap">Ha</th>'
               . '<th class="text-end text-nowrap">Blocks</th>'
               . '<th class="text-end text-nowrap">Cost (Rp)</th>'
               . '</tr></thead><tbody>';

            foreach ($sortedKeys as $yr) {
                $m      = (array)($meta[$yr] ?? []);
                $ydata  = (array)($pivot[$yr] ?? []);
                $rowTot = array_sum($ydata);
                $mHa    = (float)($m['total_ha']    ?? 0);
                $mCost  = (float)($m['total_cost']  ?? 0);
                $mBlk   = (int)  ($m['block_count'] ?? 0);

                echo '<tr>';
                echo '<td class="fw-semibold text-nowrap">TT ' . htmlspecialchars((string)$yr) . '</td>';
                foreach ($ctypes as $ct) {
                    $qty = (float)($ydata[$ct] ?? 0);
                    echo '<td class="text-end">'
                       . ($qty > 0 ? number_format($qty, 2) : '<span class="text-muted">-</span>')
                       . '</td>';
                }
                echo '<td class="text-end fw-bold text-success">' . number_format($rowTot, 2) . '</td>';
                echo '<td class="text-end text-muted">' . ($mHa   > 0 ? number_format($mHa,   2) : '—') . '</td>';
                echo '<td class="text-end text-muted">' . ($mBlk  > 0 ? number_format($mBlk)     : '—') . '</td>';
                echo '<td class="text-end small">'      . ($mCost > 0 ? 'Rp ' . number_format($mCost, 0, ',', '.') : '—') . '</td>';
                echo '</tr>';
            }

            // Grand total row
            echo '<tr class="table-success fw-bold">'
               . '<th class="text-end">Grand Total</th>';
            foreach ($ctypes as $ct) {
                echo '<th class="text-end">' . number_format((float)($grandTots[$ct] ?? 0), 2) . '</th>';
            }
            echo '<th class="text-end">' . number_format($grandTotal, 2) . '</th>';
            echo '<th class="text-end">' . ($totalHaAllPY   > 0 ? number_format($totalHaAllPY,   2) : '—') . '</th>';
            echo '<th class="text-end">—</th>';
            echo '<th class="text-end small">' . ($totalCostAllPY > 0 ? 'Rp ' . number_format($totalCostAllPY, 0, ',', '.') : '—') . '</th>';
            echo '</tr>';
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">TT = Tahun Tanam &bull; Qty = total penggunaan per tipe &bull; Ha = luas cakupan aplikasi &bull; Data dari <a href="pest_control.php">Pengendalian Hama</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;

        case 'chemicals_by_block':
            $scope      = htmlspecialchars((string)($answer['scope']      ?? ''));
            $dateLabel  = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $ctypes     = (array)($answer['ctypes']      ?? []);
            $pivot      = (array)($answer['pivot']       ?? []);
            $meta       = (array)($answer['meta']        ?? []);
            $sortedKeys = (array)($answer['sorted_keys'] ?? []);
            $grandTots  = (array)($answer['grand_totals']?? []);
            $grandTotal = (float)($answer['grand_total'] ?? 0);
            $blockCount = (int)  ($answer['block_count'] ?? 0);
            $isEmpty    = !empty($answer['empty']);
            $hasParaquat= !empty($answer['has_paraquat']);

            // Colour map for type badges
            $typeBadgeCB = ['Herbicide'=>'warning','Insecticide'=>'danger','Fungicide'=>'success','Rodenticide'=>'secondary','Other'=>'light'];

            echo '<span class="qna-tag tag-block">🧪 Bahan Kimia per Blok</span> ';
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel !== '') echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';
            echo ' &mdash; <span class="text-muted">' . $blockCount . ' blok</span>';

            if ($hasParaquat) {
                echo '<div class="alert alert-danger py-1 px-2 mt-2 mb-0 small">⚠️ <strong>Paraquat / Gramoxone</strong> terdeteksi — dilarang di kebun bersertifikasi RSPO/HHP.</div>';
            }

            if ($isEmpty || $blockCount === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data bahan kimia'
                    . ($dateLabel !== '' ? ' untuk periode <strong>' . $dateLabel . '</strong>' : '') . '. '
                    . 'Isi data di <a href="pest_control.php">Pengendalian Hama</a>.</p>';
                break;
            }

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';

            // Header row 1
            echo '<thead class="table-dark"><tr>'
               . '<th rowspan="2" class="align-middle">Estate</th>'
               . '<th rowspan="2" class="align-middle">Division</th>'
               . '<th rowspan="2" class="align-middle">Block</th>';
            foreach ($ctypes as $ct) {
                $badge = $typeBadgeCB[$ct] ?? 'light';
                echo '<th class="text-center text-nowrap"><span class="badge text-bg-' . $badge . '">' . htmlspecialchars((string)$ct) . '</span></th>';
            }
            echo '<th class="text-center text-nowrap">Total</th></tr>';
            // Header row 2
            echo '<tr>';
            foreach ($ctypes as $ct) {
                echo '<th class="text-center small fw-normal" style="background:#2b3035;color:#adb5bd">qty</th>';
            }
            echo '<th style="background:#2b3035;"></th></tr></thead><tbody>';

            $prevEstate = null; $prevDiv = null;
            foreach ($sortedKeys as $bkey) {
                $m      = (array)($meta[$bkey] ?? []);
                $bdata  = (array)($pivot[$bkey] ?? []);
                $rowTot = array_sum($bdata);

                $showEstate = ($m['estate']   !== $prevEstate);
                $showDiv    = ($m['division'] !== $prevDiv || $showEstate);

                if ($showEstate) {
                    $eSpan = count(array_filter($sortedKeys, fn($k) => ((array)($meta[$k] ?? []))['estate'] ?? '' === ($m['estate'] ?? '')));
                }
                if ($showDiv) {
                    $dSpan = count(array_filter($sortedKeys, fn($k) =>
                        (((array)($meta[$k] ?? []))['estate']   ?? '') === ($m['estate']   ?? '') &&
                        (((array)($meta[$k] ?? []))['division'] ?? '') === ($m['division'] ?? '')));
                }
                $prevEstate = $m['estate']; $prevDiv = $m['division'];

                echo '<tr>';
                if ($showEstate) {
                    echo '<td rowspan="' . ($eSpan ?? 1) . '" class="align-middle fw-semibold text-nowrap" style="background:#f8f9fa">'
                       . htmlspecialchars((string)($m['estate'] ?? '')) . '</td>';
                }
                if ($showDiv) {
                    echo '<td rowspan="' . ($dSpan ?? 1) . '" class="align-middle text-nowrap" style="background:#f8f9fa">'
                       . htmlspecialchars((string)($m['division'] ?? '')) . '</td>';
                }
                echo '<td class="text-nowrap"><span class="badge bg-secondary me-1">'
                   . htmlspecialchars((string)($m['block_code'] ?? '')) . '</span>'
                   . htmlspecialchars((string)($m['block_name'] ?? '')) . '</td>';
                foreach ($ctypes as $ct) {
                    $qty = (float)($bdata[$ct] ?? 0);
                    echo '<td class="text-end">'
                       . ($qty > 0 ? number_format($qty, 2) : '<span class="text-muted">-</span>')
                       . '</td>';
                }
                echo '<td class="text-end fw-bold text-success">' . number_format($rowTot, 2) . '</td>';
                echo '</tr>';
            }

            // Grand total row
            echo '<tr class="table-success fw-bold">'
               . '<th colspan="3" class="text-end">Grand Total</th>';
            foreach ($ctypes as $ct) {
                echo '<th class="text-end">' . number_format((float)($grandTots[$ct] ?? 0), 2) . '</th>';
            }
            echo '<th class="text-end">' . number_format($grandTotal, 2) . '</th></tr>';
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">Qty = total penggunaan per tipe &bull; Data dari modul Pengendalian Hama'
               . ' &bull; <a href="pest_control.php">Lihat detail →</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;

        // ── Pest & Disease by Block (pivot) — HTML table ──────────────────────
        case 'pest_by_block': {
            $scope      = htmlspecialchars((string)($answer['scope']      ?? ''));
            $dateLabel  = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $ptypes     = (array)($answer['ptypes']      ?? []);
            $pivot      = (array)($answer['pivot']       ?? []);
            $meta       = (array)($answer['meta']        ?? []);
            $sortedKeys = (array)($answer['sorted_keys'] ?? []);
            $grandTots  = (array)($answer['grand_totals']?? []);
            $grandTotal = (int)  ($answer['grand_total'] ?? 0);
            $grandHiSev = (int)  ($answer['grand_high_sev'] ?? 0);
            $blockCount = (int)  ($answer['block_count'] ?? 0);
            $isEmpty    = !empty($answer['empty']);

            // Badge colours per pest type
            $typeBadgePB = ['Insect'=>'danger','Insecticide'=>'warning','Disease'=>'info','Fungal'=>'success','Weed'=>'secondary','Rat'=>'dark','Vertebrate'=>'dark','Other'=>'light'];

            echo '<span class="qna-tag tag-block">🐛 Hama &amp; Penyakit per Blok</span> ';
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel !== '') echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';
            echo ' &mdash; <span class="text-muted">' . $blockCount . ' blok</span>';

            if (!empty($answer['db_error'])) {
                echo '<div class="alert alert-warning py-2 px-3 mt-2 mb-0" style="font-size:.8rem">'
                   . '<strong>⚠ Query error:</strong> ' . htmlspecialchars((string)$answer['db_error']) . '</div>';
                break;
            }

            if ($isEmpty || $blockCount === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data hama &amp; penyakit'
                   . ($dateLabel !== '' ? ' untuk periode <strong>' . $dateLabel . '</strong>' : '') . '. '
                   . 'Isi data di <a href="pest_control.php">Pengendalian Hama</a>.</p>';
                break;
            }

            // High severity alert banner
            if ($grandHiSev > 0) {
                $sevPct = $grandTotal > 0 ? number_format($grandHiSev / $grandTotal * 100, 1) : 0;
                echo '<div class="alert alert-danger py-1 px-2 mt-2 mb-0 small">⚠️ <strong>' . $grandHiSev . '</strong> catatan serangan <strong>High/Critical</strong> ('
                   . $sevPct . '% dari total) — perlu penanganan segera.</div>';
            }

            // Summary stat badges
            $totalHaAllPB  = array_sum(array_column($meta, 'total_ha'));
            $totalCostAllPB = array_sum(array_column($meta, 'total_cost'));
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . $blockCount . '</div><div class="qna-stat-lbl">Blocks</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandTotal) . '</div><div class="qna-stat-lbl">Pest Records</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val" style="color:#dc2626">' . $grandHiSev . '</div><div class="qna-stat-lbl">High/Critical</div></div>';
            if ($totalHaAllPB  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalHaAllPB, 1) . '</div><div class="qna-stat-lbl">Ha Affected</div></div>';
            if ($totalCostAllPB > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalCostAllPB / 1e6, 1) . ' M</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';

            // Header row 1
            echo '<thead class="table-dark"><tr>'
               . '<th rowspan="2" class="align-middle">Estate</th>'
               . '<th rowspan="2" class="align-middle">Division</th>'
               . '<th rowspan="2" class="align-middle">Block</th>';
            foreach ($ptypes as $pt) {
                $badge = $typeBadgePB[$pt] ?? 'secondary';
                echo '<th class="text-center text-nowrap"><span class="badge text-bg-' . $badge . '">' . htmlspecialchars((string)$pt) . '</span></th>';
            }
            echo '<th class="text-center text-nowrap">Total</th>'
               . '<th class="text-end text-nowrap">Ha</th>'
               . '<th class="text-end text-nowrap" style="color:#dc2626">High/Crit</th>'
               . '<th class="text-end text-nowrap">Last</th>'
               . '</tr><tr>';
            foreach ($ptypes as $pt) {
                echo '<th class="text-center small fw-normal" style="background:#2b3035;color:#adb5bd">records</th>';
            }
            echo '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '</tr></thead><tbody>';

            $prevEstate = null; $prevDiv = null;
            foreach ($sortedKeys as $bkey) {
                $m      = (array)($meta[$bkey] ?? []);
                $bdata  = (array)($pivot[$bkey] ?? []);
                $rowTot = array_sum($bdata);
                $hiSev  = (int)($m['high_sev'] ?? 0);
                $mHa    = (float)($m['total_ha'] ?? 0);

                $showEstate = ($m['estate']   !== $prevEstate);
                $showDiv    = ($m['division'] !== $prevDiv || $showEstate);
                if ($showEstate) {
                    $eSpan = count(array_filter($sortedKeys, fn($k) => ((array)($meta[$k] ?? []))['estate'] ?? '' === ($m['estate'] ?? '')));
                }
                if ($showDiv) {
                    $dSpan = count(array_filter($sortedKeys, fn($k) =>
                        (((array)($meta[$k] ?? []))['estate']   ?? '') === ($m['estate']   ?? '') &&
                        (((array)($meta[$k] ?? []))['division'] ?? '') === ($m['division'] ?? '')));
                }
                $prevEstate = $m['estate']; $prevDiv = $m['division'];

                $rowCls = $hiSev > 0 ? ' class="table-danger"' : '';
                echo "<tr{$rowCls}>";
                if ($showEstate) {
                    echo '<td rowspan="' . ($eSpan ?? 1) . '" class="align-middle fw-semibold text-nowrap" style="background:#f8f9fa">'
                       . htmlspecialchars((string)($m['estate'] ?? '')) . '</td>';
                }
                if ($showDiv) {
                    echo '<td rowspan="' . ($dSpan ?? 1) . '" class="align-middle text-nowrap" style="background:#f8f9fa">'
                       . htmlspecialchars((string)($m['division'] ?? '')) . '</td>';
                }
                echo '<td class="text-nowrap"><span class="badge bg-secondary me-1">'
                   . htmlspecialchars((string)($m['block_code'] ?? '')) . '</span>'
                   . htmlspecialchars((string)($m['block_name'] ?? '')) . '</td>';
                foreach ($ptypes as $pt) {
                    $cnt = (int)($bdata[$pt] ?? 0);
                    echo '<td class="text-end">'
                       . ($cnt > 0 ? '<strong>' . $cnt . '</strong>' : '<span class="text-muted">-</span>')
                       . '</td>';
                }
                echo '<td class="text-end fw-bold text-primary">' . $rowTot . '</td>';
                echo '<td class="text-end text-muted">' . ($mHa > 0 ? number_format($mHa, 2) : '—') . '</td>';
                echo '<td class="text-end fw-bold" style="color:#dc2626">' . ($hiSev > 0 ? $hiSev : '—') . '</td>';
                echo '<td class="text-end small text-muted">' . htmlspecialchars((string)($m['last_date'] ?? '')) . '</td>';
                echo '</tr>';
            }

            // Grand total row
            echo '<tr class="table-primary fw-bold">'
               . '<th colspan="3" class="text-end">Grand Total</th>';
            foreach ($ptypes as $pt) {
                echo '<th class="text-end">' . number_format((int)($grandTots[$pt] ?? 0)) . '</th>';
            }
            echo '<th class="text-end">' . number_format($grandTotal) . '</th>';
            echo '<th class="text-end">' . ($totalHaAllPB  > 0 ? number_format($totalHaAllPB, 2) : '—') . '</th>';
            echo '<th class="text-end" style="color:#dc2626">' . ($grandHiSev > 0 ? $grandHiSev : '—') . '</th>';
            echo '<th></th>';
            echo '</tr>';
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">Nilai = jumlah catatan serangan &bull; Baris merah = ada serangan High/Critical'
               . ' &bull; Ha = luas area terserang &bull; Data dari <a href="pest_control.php">Pengendalian Hama &rarr;</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        // ── Pest & Disease by Division (pivot) — HTML table ───────────────────
        case 'pest_by_division': {
            $scope      = htmlspecialchars((string)($answer['scope']      ?? ''));
            $dateLabel  = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $ptypes     = (array)($answer['ptypes']      ?? []);
            $pivot      = (array)($answer['pivot']       ?? []);
            $meta       = (array)($answer['meta']        ?? []);
            $sortedKeys = (array)($answer['sorted_keys'] ?? []);
            $grandTots  = (array)($answer['grand_totals']?? []);
            $grandTotal = (int)  ($answer['grand_total'] ?? 0);
            $grandHiSev = (int)  ($answer['grand_high_sev'] ?? 0);
            $divCount   = (int)  ($answer['div_count']   ?? 0);
            $isEmpty    = !empty($answer['empty']);

            $typeBadgePD = ['Insect'=>'danger','Insecticide'=>'warning','Disease'=>'info','Fungal'=>'success','Weed'=>'secondary','Rat'=>'dark','Vertebrate'=>'dark','Other'=>'light'];

            echo '<span class="qna-tag tag-division">🐛 Hama &amp; Penyakit per Divisi</span> ';
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel !== '') echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';
            echo ' &mdash; <span class="text-muted">' . $divCount . ' divisi</span>';

            if (!empty($answer['db_error'])) {
                echo '<div class="alert alert-warning py-2 px-3 mt-2 mb-0" style="font-size:.8rem">'
                   . '<strong>⚠ Query error:</strong> ' . htmlspecialchars((string)$answer['db_error']) . '</div>';
                break;
            }

            if ($isEmpty || $divCount === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data hama &amp; penyakit'
                   . ($dateLabel !== '' ? ' untuk periode <strong>' . $dateLabel . '</strong>' : '') . '. '
                   . 'Isi data di <a href="pest_control.php">Pengendalian Hama</a>.</p>';
                break;
            }

            // High severity alert banner
            if ($grandHiSev > 0) {
                $sevPct = $grandTotal > 0 ? number_format($grandHiSev / $grandTotal * 100, 1) : 0;
                echo '<div class="alert alert-danger py-1 px-2 mt-2 mb-0 small">⚠️ <strong>' . $grandHiSev . '</strong> catatan serangan <strong>High/Critical</strong> ('
                   . $sevPct . '% dari total) — identifikasi dan prioritaskan penanganan segera.</div>';
            }

            // Summary stat badges
            $totalHaAllPD  = array_sum(array_column($meta, 'total_ha'));
            $totalCostAllPD = array_sum(array_column($meta, 'total_cost'));
            $totalBlkAllPD = array_sum(array_column($meta, 'block_count'));
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . $divCount . '</div><div class="qna-stat-lbl">Divisions</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandTotal) . '</div><div class="qna-stat-lbl">Pest Records</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val" style="color:#dc2626">' . $grandHiSev . '</div><div class="qna-stat-lbl">High/Critical</div></div>';
            if ($totalBlkAllPD > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . $totalBlkAllPD . '</div><div class="qna-stat-lbl">Blocks Affected</div></div>';
            if ($totalHaAllPD  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalHaAllPD, 1) . '</div><div class="qna-stat-lbl">Ha Affected</div></div>';
            if ($totalCostAllPD > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalCostAllPD / 1e6, 1) . ' M</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';

            // Header row 1
            echo '<thead class="table-dark"><tr>'
               . '<th rowspan="2" class="align-middle">Estate</th>'
               . '<th rowspan="2" class="align-middle">Division</th>';
            foreach ($ptypes as $pt) {
                $badge = $typeBadgePD[$pt] ?? 'secondary';
                echo '<th class="text-center text-nowrap"><span class="badge text-bg-' . $badge . '">' . htmlspecialchars((string)$pt) . '</span></th>';
            }
            echo '<th class="text-center text-nowrap">Total</th>'
               . '<th class="text-end text-nowrap">Blocks</th>'
               . '<th class="text-end text-nowrap">Ha</th>'
               . '<th class="text-end text-nowrap" style="color:#dc2626">High/Crit</th>'
               . '<th class="text-end text-nowrap">Last</th>'
               . '</tr><tr>';
            foreach ($ptypes as $pt) {
                echo '<th class="text-center small fw-normal" style="background:#2b3035;color:#adb5bd">records</th>';
            }
            echo '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '</tr></thead><tbody>';

            $prevEstate = null;
            foreach ($sortedKeys as $dkey) {
                $m      = (array)($meta[$dkey]  ?? []);
                $ddata  = (array)($pivot[$dkey] ?? []);
                $rowTot = array_sum($ddata);
                $hiSev  = (int)  ($m['high_sev']    ?? 0);
                $mBlk   = (int)  ($m['block_count'] ?? 0);
                $mHa    = (float)($m['total_ha']    ?? 0);

                $showEstate = ($m['estate'] !== $prevEstate);
                if ($showEstate) {
                    $eSpan = count(array_filter($sortedKeys, fn($k) => ((array)($meta[$k] ?? []))['estate'] ?? '' === ($m['estate'] ?? '')));
                }
                $prevEstate = $m['estate'];

                $rowCls = $hiSev > 0 ? ' class="table-danger"' : '';
                echo "<tr{$rowCls}>";
                if ($showEstate) {
                    echo '<td rowspan="' . ($eSpan ?? 1) . '" class="align-middle fw-semibold text-nowrap" style="background:#f8f9fa">'
                       . htmlspecialchars((string)($m['estate'] ?? '')) . '</td>';
                }
                echo '<td class="text-nowrap fw-semibold">' . htmlspecialchars((string)($m['division'] ?? '')) . '</td>';
                foreach ($ptypes as $pt) {
                    $cnt = (int)($ddata[$pt] ?? 0);
                    echo '<td class="text-end">'
                       . ($cnt > 0 ? '<strong>' . $cnt . '</strong>' : '<span class="text-muted">-</span>')
                       . '</td>';
                }
                echo '<td class="text-end fw-bold text-primary">' . $rowTot . '</td>';
                echo '<td class="text-end text-muted">' . ($mBlk > 0 ? $mBlk : '—') . '</td>';
                echo '<td class="text-end text-muted">' . ($mHa  > 0 ? number_format($mHa, 2) : '—') . '</td>';
                echo '<td class="text-end fw-bold" style="color:#dc2626">' . ($hiSev > 0 ? $hiSev : '—') . '</td>';
                echo '<td class="text-end small text-muted">' . htmlspecialchars((string)($m['last_date'] ?? '')) . '</td>';
                echo '</tr>';
            }

            // Grand total row
            echo '<tr class="table-primary fw-bold">'
               . '<th colspan="2" class="text-end">Grand Total</th>';
            foreach ($ptypes as $pt) {
                echo '<th class="text-end">' . number_format((int)($grandTots[$pt] ?? 0)) . '</th>';
            }
            echo '<th class="text-end">' . number_format($grandTotal) . '</th>';
            echo '<th class="text-end">' . ($totalBlkAllPD > 0 ? $totalBlkAllPD : '—') . '</th>';
            echo '<th class="text-end">' . ($totalHaAllPD  > 0 ? number_format($totalHaAllPD, 2) : '—') . '</th>';
            echo '<th class="text-end" style="color:#dc2626">' . ($grandHiSev > 0 ? $grandHiSev : '—') . '</th>';
            echo '<th></th>';
            echo '</tr>';
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">Nilai = jumlah catatan serangan &bull; Baris merah = ada serangan High/Critical'
               . ' &bull; Ha = luas area terserang &bull; Data dari <a href="pest_control.php">Pengendalian Hama &rarr;</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        // ── Pest & Disease by Planting Year (pivot) — HTML table ─────────────
        case 'pest_by_planting_year': {
            $scope      = htmlspecialchars((string)($answer['scope']      ?? ''));
            $dateLabel  = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $ptypes     = (array)($answer['ptypes']      ?? []);
            $pivot      = (array)($answer['pivot']       ?? []);
            $meta       = (array)($answer['meta']        ?? []);
            $sortedKeys = (array)($answer['sorted_keys'] ?? []);
            $grandTots  = (array)($answer['grand_totals']?? []);
            $grandTotal = (int)  ($answer['grand_total'] ?? 0);
            $grandHiSev = (int)  ($answer['grand_high_sev'] ?? 0);
            $yearCount  = (int)  ($answer['year_count']  ?? 0);
            $isEmpty    = !empty($answer['empty']);

            $typeBadgePPY = ['Insect'=>'danger','Insecticide'=>'warning','Disease'=>'info','Fungal'=>'success','Weed'=>'secondary','Rat'=>'dark','Vertebrate'=>'dark','Other'=>'light'];

            echo '<span class="qna-tag tag-block">🐛 Pest &amp; Disease by Planting Year</span> ';
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel !== '') echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';
            echo ' &mdash; <span class="text-muted">' . $yearCount . ' tahun tanam</span>';

            if (!empty($answer['db_error'])) {
                echo '<div class="alert alert-warning py-2 px-3 mt-2 mb-0" style="font-size:.8rem">'
                   . '<strong>⚠ Query error:</strong> ' . htmlspecialchars((string)$answer['db_error']) . '</div>';
                break;
            }

            if ($isEmpty || $yearCount === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data hama &amp; penyakit per tahun tanam'
                   . ($dateLabel !== '' ? ' untuk periode <strong>' . $dateLabel . '</strong>' : '') . '. '
                   . 'Pastikan data Pengendalian Hama memiliki Blok yang terhubung ke Tahun Tanam. '
                   . '<a href="pest_control.php">Input data →</a></p>';
                break;
            }

            // High severity alert banner
            if ($grandHiSev > 0) {
                $sevPct = $grandTotal > 0 ? number_format($grandHiSev / $grandTotal * 100, 1) : 0;
                echo '<div class="alert alert-danger py-1 px-2 mt-2 mb-0 small">⚠️ <strong>' . $grandHiSev . '</strong> catatan serangan <strong>High/Critical</strong> ('
                   . $sevPct . '% dari total) — identifikasi tahun tanam rentan dan prioritaskan penanganan.</div>';
            }

            // Summary stat badges
            $totalHaAllPPY  = array_sum(array_column($meta, 'total_ha'));
            $totalCostAllPPY = array_sum(array_column($meta, 'total_cost'));
            $totalBlkAllPPY = array_sum(array_column($meta, 'block_count'));
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . $yearCount . '</div><div class="qna-stat-lbl">Planting Years</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandTotal) . '</div><div class="qna-stat-lbl">Pest Records</div></div>';
            echo '<div class="qna-stat"><div class="qna-stat-val" style="color:#dc2626">' . $grandHiSev . '</div><div class="qna-stat-lbl">High/Critical</div></div>';
            if ($totalBlkAllPPY > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . $totalBlkAllPPY . '</div><div class="qna-stat-lbl">Blocks Affected</div></div>';
            if ($totalHaAllPPY  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalHaAllPPY, 1) . '</div><div class="qna-stat-lbl">Ha Affected</div></div>';
            if ($totalCostAllPPY > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalCostAllPPY / 1e6, 1) . ' M</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';

            // Header row 1
            echo '<thead class="table-dark"><tr>'
               . '<th class="text-nowrap">Planting Year</th>';
            foreach ($ptypes as $pt) {
                $badge = $typeBadgePPY[$pt] ?? 'secondary';
                echo '<th class="text-center text-nowrap"><span class="badge text-bg-' . $badge . '">' . htmlspecialchars((string)$pt) . '</span></th>';
            }
            echo '<th class="text-center text-nowrap">Total</th>'
               . '<th class="text-end text-nowrap">Blocks</th>'
               . '<th class="text-end text-nowrap">Ha</th>'
               . '<th class="text-end text-nowrap" style="color:#dc2626">High/Crit</th>'
               . '<th class="text-end text-nowrap">Last</th>'
               . '</tr><tr>'
               . '<th style="background:#2b3035;"></th>';
            foreach ($ptypes as $pt) {
                echo '<th class="text-center small fw-normal" style="background:#2b3035;color:#adb5bd">records</th>';
            }
            echo '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '</tr></thead><tbody>';

            foreach ($sortedKeys as $yr) {
                $m      = (array)($meta[$yr] ?? []);
                $ydata  = (array)($pivot[$yr] ?? []);
                $rowTot = array_sum($ydata);
                $hiSev  = (int)  ($m['high_sev']    ?? 0);
                $mBlk   = (int)  ($m['block_count'] ?? 0);
                $mHa    = (float)($m['total_ha']    ?? 0);
                $mAge   = date('Y') - (int)$yr;
                $ageCls = $mAge <= 3 ? 'text-info' : ($mAge >= 25 ? 'text-secondary' : '');

                $rowCls = $hiSev > 0 ? ' class="table-danger"' : '';
                echo "<tr{$rowCls}>";
                echo '<td class="fw-semibold text-nowrap ' . $ageCls . '">TT ' . htmlspecialchars((string)$yr)
                   . ' <small class="text-muted fw-normal">(±' . $mAge . 'thn)</small></td>';
                foreach ($ptypes as $pt) {
                    $cnt = (int)($ydata[$pt] ?? 0);
                    echo '<td class="text-end">'
                       . ($cnt > 0 ? '<strong>' . $cnt . '</strong>' : '<span class="text-muted">-</span>')
                       . '</td>';
                }
                echo '<td class="text-end fw-bold text-primary">' . $rowTot . '</td>';
                echo '<td class="text-end text-muted">' . ($mBlk > 0 ? $mBlk : '—') . '</td>';
                echo '<td class="text-end text-muted">' . ($mHa  > 0 ? number_format($mHa, 2) : '—') . '</td>';
                echo '<td class="text-end fw-bold" style="color:#dc2626">' . ($hiSev > 0 ? $hiSev : '—') . '</td>';
                echo '<td class="text-end small text-muted">' . htmlspecialchars((string)($m['last_date'] ?? '')) . '</td>';
                echo '</tr>';
            }

            // Grand total row
            echo '<tr class="table-primary fw-bold">'
               . '<th class="text-end">Grand Total</th>';
            foreach ($ptypes as $pt) {
                echo '<th class="text-end">' . number_format((int)($grandTots[$pt] ?? 0)) . '</th>';
            }
            echo '<th class="text-end">' . number_format($grandTotal) . '</th>';
            echo '<th class="text-end">' . ($totalBlkAllPPY > 0 ? $totalBlkAllPPY : '—') . '</th>';
            echo '<th class="text-end">' . ($totalHaAllPPY  > 0 ? number_format($totalHaAllPPY, 2) : '—') . '</th>';
            echo '<th class="text-end" style="color:#dc2626">' . ($grandHiSev > 0 ? $grandHiSev : '—') . '</th>';
            echo '<th></th>';
            echo '</tr>';
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">TT = Tahun Tanam &bull; Nilai = jumlah catatan serangan &bull; Baris merah = ada serangan High/Critical'
               . ' &bull; Usia TBM ≤3thn (biru), TM lama ≥25thn (abu) &bull; Data dari <a href="pest_control.php">Pengendalian Hama &rarr;</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        case 'chemicals_used':
            $chemicals  = (array) ($answer['chemicals']   ?? []);
            $scope      = htmlspecialchars((string)($answer['scope']      ?? ''));
            $count      = (int)   ($answer['count']       ?? 0);
            $grandQty   = (float) ($answer['grand_qty']   ?? 0);
            $grandArea  = (float) ($answer['grand_area']  ?? 0);
            $grandCost  = (float) ($answer['grand_cost']  ?? 0);
            $grandApps  = (int)   ($answer['grand_apps']  ?? 0);
            $isEmpty    = !empty($answer['empty']);

            echo '<span class="qna-tag tag-block">🧪 Agro Chemical Usage</span> ';
            echo '<strong>' . $scope . '</strong>';
            echo ' &mdash; <span class="text-muted">' . $count . ' produk</span>';

            if ($isEmpty || $count === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data bahan kimia. '
                    . 'Isi data di <a href="pest_control.php">Pengendalian Hama</a>.</p>';
                break;
            }

            // Summary stat badges
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . $count . '</div><div class="qna-stat-lbl">Products</div></div>';
            if ($grandApps > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandApps) . '</div><div class="qna-stat-lbl">Applications</div></div>';
            if ($grandArea > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandArea, 2) . '</div><div class="qna-stat-lbl">Coverage (ha)</div></div>';
            if ($grandCost > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandCost / 1e6, 1) . ' M</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            // Type badge colour map
            $typeBadge = [
                'Insecticide'  => 'danger',
                'Herbicide'    => 'warning',
                'Fungicide'    => 'success',
                'Rodenticide'  => 'secondary',
                'Other'        => 'light',
            ];

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
            echo '<thead class="table-success"><tr>'
                . '<th>#</th>'
                . '<th>Product Name</th>'
                . '<th>Type</th>'
                . '<th class="text-end">Applications</th>'
                . '<th class="text-end">Total Qty</th>'
                . '<th class="text-end">Coverage (ha)</th>'
                . '<th class="text-end">Cost (Rp)</th>'
                . '<th>Period</th>'
                . '</tr></thead><tbody>';

            foreach ($chemicals as $i => $c) {
                $c        = (array)$c;
                $qty      = (float)$c['total_qty']     > 0 ? number_format((float)$c['total_qty'],     2) . ' ' . htmlspecialchars((string)($c['unit'] ?? '')) : '—';
                $area     = (float)$c['total_area_ha'] > 0 ? number_format((float)$c['total_area_ha'], 2) : '—';
                $cost     = (float)$c['total_cost']    > 0 ? 'Rp ' . number_format((float)$c['total_cost'], 0, ',', '.') : '—';
                $period   = ($c['first_date'] ?? '') !== '' ? htmlspecialchars((string)$c['first_date']) . ' – ' . htmlspecialchars((string)$c['last_date']) : '—';
                $typeKey  = (string)($c['pesticide_type'] ?? 'Other');
                $badge    = $typeBadge[$typeKey] ?? 'light';
                echo '<tr>'
                    . '<td class="text-muted">' . ($i + 1) . '</td>'
                    . '<td class="fw-semibold">' . htmlspecialchars((string)$c['pesticide_name']) . '</td>'
                    . '<td><span class="badge text-bg-' . $badge . '">' . htmlspecialchars($typeKey) . '</span></td>'
                    . '<td class="text-end">' . (int)$c['application_count'] . '</td>'
                    . '<td class="text-end">' . $qty . '</td>'
                    . '<td class="text-end">' . $area . '</td>'
                    . '<td class="text-end small">' . $cost . '</td>'
                    . '<td class="small text-muted">' . $period . '</td>'
                    . '</tr>';
            }

            // Grand total row
            echo '<tr class="table-dark fw-bold">'
                . '<td colspan="3">TOTAL</td>'
                . '<td class="text-end">' . number_format($grandApps) . '</td>'
                . '<td class="text-end">' . number_format($grandQty, 2) . '</td>'
                . '<td class="text-end">' . ($grandArea > 0 ? number_format($grandArea, 2) : '—') . '</td>'
                . '<td class="text-end small">' . ($grandCost > 0 ? 'Rp ' . number_format($grandCost, 0, ',', '.') : '—') . '</td>'
                . '<td></td>'
                . '</tr>';

            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">Qty = jumlah total per produk &bull; Ha = area pengendalian yang dicakup &bull; Data dari modul Pengendalian Hama</p>';

            // Auto-analysis: triggered when intent was "Analisa Bahan Kimia [scope]"
            if (!empty($answer['auto_analyze']) && !$isEmpty && $count > 0) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;

        case 'fertilization_used':
            $fertilizers = (array) ($answer['fertilizers'] ?? []);
            $scope       = htmlspecialchars((string)($answer['scope']      ?? ''));
            $dateLabel   = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $count       = (int)   ($answer['count']       ?? 0);
            $grandQty    = (float) ($answer['grand_qty']   ?? 0);
            $grandArea   = (float) ($answer['grand_area']  ?? 0);
            $grandCost   = (float) ($answer['grand_cost']  ?? 0);
            $grandApps   = (int)   ($answer['grand_apps']  ?? 0);
            $isEmpty     = !empty($answer['empty']);

            echo '<span class="qna-tag tag-block">🌾 Fertilizer Usage</span> ';
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel !== '') {
                echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';
            }
            echo ' &mdash; <span class="text-muted">' . $count . ' jenis pupuk</span>';

            if ($isEmpty || $count === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data pemupukan'
                    . ($dateLabel !== '' ? ' untuk periode <strong>' . $dateLabel . '</strong>' : '') . '. '
                    . 'Isi data di <a href="fertilization.php">Pemupukan</a>.</p>';
                break;
            }

            // Summary stat badges
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . $count . '</div><div class="qna-stat-lbl">Fertilizer Types</div></div>';
            if ($grandApps > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandApps) . '</div><div class="qna-stat-lbl">Applications</div></div>';
            if ($grandQty  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandQty / 1000, 1) . ' ton</div><div class="qna-stat-lbl">Total Qty</div></div>';
            if ($grandArea > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandArea, 2) . '</div><div class="qna-stat-lbl">Coverage (ha)</div></div>';
            if ($grandCost > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandCost / 1e6, 1) . ' M</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
            echo '<thead class="table-warning"><tr>'
                . '<th>#</th>'
                . '<th>Fertilizer Type</th>'
                . '<th>Grade / Formula</th>'
                . '<th>Application Method</th>'
                . '<th class="text-end">Applications</th>'
                . '<th class="text-end">Total Qty (kg)</th>'
                . '<th class="text-end">Coverage (ha)</th>'
                . '<th class="text-end">Dose/Plant (kg)</th>'
                . '<th class="text-end">Cost (Rp)</th>'
                . '<th>Period</th>'
                . '</tr></thead><tbody>';

            foreach ($fertilizers as $i => $f) {
                $f         = (array)$f;
                $qty       = number_format((float)$f['total_qty_kg'], 0);
                $area      = (float)$f['total_area_ha'] > 0 ? number_format((float)$f['total_area_ha'], 2) : '—';
                $avgDose   = (float)$f['application_count'] > 0 && (float)$f['sum_dosage'] > 0
                             ? number_format((float)$f['sum_dosage'] / (float)$f['application_count'], 3)
                             : '—';
                $cost      = (float)$f['total_cost'] > 0 ? 'Rp ' . number_format((float)$f['total_cost'], 0, ',', '.') : '—';
                $period    = ($f['first_date'] ?? '') !== '' ? htmlspecialchars((string)$f['first_date']) . ' – ' . htmlspecialchars((string)$f['last_date']) : '—';
                echo '<tr>'
                    . '<td class="text-muted">' . ($i + 1) . '</td>'
                    . '<td class="fw-semibold">' . htmlspecialchars((string)$f['fertilizer_type']) . '</td>'
                    . '<td>' . htmlspecialchars((string)($f['fertilizer_grade'] ?? '—')) . '</td>'
                    . '<td>' . htmlspecialchars((string)($f['application_method'] ?? '—')) . '</td>'
                    . '<td class="text-end">' . (int)$f['application_count'] . '</td>'
                    . '<td class="text-end fw-semibold">' . $qty . '</td>'
                    . '<td class="text-end">' . $area . '</td>'
                    . '<td class="text-end">' . $avgDose . '</td>'
                    . '<td class="text-end small">' . $cost . '</td>'
                    . '<td class="small text-muted">' . $period . '</td>'
                    . '</tr>';
            }

            // Grand total row
            echo '<tr class="table-dark fw-bold">'
                . '<td colspan="4">TOTAL</td>'
                . '<td class="text-end">' . number_format($grandApps) . '</td>'
                . '<td class="text-end">' . number_format($grandQty, 0) . '</td>'
                . '<td class="text-end">' . ($grandArea > 0 ? number_format($grandArea, 2) : '—') . '</td>'
                . '<td></td>'
                . '<td class="text-end small">' . ($grandCost > 0 ? 'Rp ' . number_format($grandCost, 0, ',', '.') : '—') . '</td>'
                . '<td></td>'
                . '</tr>';

            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">Qty = jumlah total per jenis pupuk &bull; Dosis/Pohon = rata-rata dosis per aplikasi &bull; Data dari modul Pemupukan'
                . ' &bull; <a href="fertilization.php">Lihat detail →</a></p>';

            // Auto-analysis: triggered when intent was "Analisa Pemupukan [scope]"
            if (!empty($answer['auto_analyze']) && !$isEmpty && $count > 0) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;

        case 'fertilization_by_block':
            $scope      = htmlspecialchars((string)($answer['scope']      ?? ''));
            $dateLabel  = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $ftypes     = (array)($answer['ftypes']      ?? []);
            $pivot      = (array)($answer['pivot']       ?? []);
            $meta       = (array)($answer['meta']        ?? []);
            $sortedKeys = (array)($answer['sorted_keys'] ?? []);
            $grandTots  = (array)($answer['grand_totals']?? []);
            $grandTotal = (float)($answer['grand_total'] ?? 0);
            $blockCount = (int)  ($answer['block_count'] ?? 0);
            $isEmpty    = !empty($answer['empty']);

            echo '<span class="qna-tag tag-block">🌾 Pemupukan per Blok</span> ';
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel !== '') {
                echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';
            }
            echo ' &mdash; <span class="text-muted">' . $blockCount . ' blok</span>';

            if ($isEmpty || $blockCount === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data pemupukan'
                    . ($dateLabel !== '' ? ' untuk periode <strong>' . $dateLabel . '</strong>' : '') . '. '
                    . 'Isi data di <a href="fertilization.php">Pemupukan</a>.</p>';
                break;
            }

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';

            // Header row 1
            echo '<thead class="table-dark"><tr>'
               . '<th rowspan="2" class="align-middle">Estate</th>'
               . '<th rowspan="2" class="align-middle">Division</th>'
               . '<th rowspan="2" class="align-middle">Block</th>';
            foreach ($ftypes as $ft) {
                echo '<th class="text-center text-nowrap">' . htmlspecialchars((string)$ft) . '</th>';
            }
            echo '<th class="text-center text-nowrap">Total (Kg)</th></tr>';
            // Header row 2
            echo '<tr>';
            foreach ($ftypes as $ft) {
                echo '<th class="text-center small fw-normal" style="background:#2b3035;color:#adb5bd">Kg</th>';
            }
            echo '<th style="background:#2b3035;"></th></tr></thead><tbody>';

            $prevEstate = null; $prevDiv = null;
            foreach ($sortedKeys as $bkey) {
                $m      = (array)($meta[$bkey] ?? []);
                $bdata  = (array)($pivot[$bkey] ?? []);
                $rowTot = array_sum($bdata);

                $showEstate = ($m['estate']   !== $prevEstate);
                $showDiv    = ($m['division'] !== $prevDiv || $showEstate);

                if ($showEstate) {
                    $eSpan = count(array_filter($sortedKeys, fn($k) => (array)($meta[$k] ?? [])['estate'] ?? '' === ($m['estate'] ?? '')));
                }
                if ($showDiv) {
                    $dSpan = count(array_filter($sortedKeys, fn($k) =>
                        ((array)($meta[$k] ?? [])['estate']   ?? '') === ($m['estate']   ?? '') &&
                        ((array)($meta[$k] ?? [])['division'] ?? '') === ($m['division'] ?? '')));
                }
                $prevEstate = $m['estate'];
                $prevDiv    = $m['division'];

                echo '<tr>';
                if ($showEstate) {
                    echo '<td rowspan="' . ($eSpan ?? 1) . '" class="align-middle fw-semibold text-nowrap" style="background:#f8f9fa">'
                       . htmlspecialchars((string)($m['estate'] ?? '')) . '</td>';
                }
                if ($showDiv) {
                    echo '<td rowspan="' . ($dSpan ?? 1) . '" class="align-middle text-nowrap" style="background:#f8f9fa">'
                       . htmlspecialchars((string)($m['division'] ?? '')) . '</td>';
                }
                echo '<td class="text-nowrap"><span class="badge bg-secondary me-1">'
                   . htmlspecialchars((string)($m['block_code'] ?? '')) . '</span>'
                   . htmlspecialchars((string)($m['block_name'] ?? '')) . '</td>';
                foreach ($ftypes as $ft) {
                    $qty = (float)($bdata[$ft] ?? 0);
                    echo '<td class="text-end">'
                       . ($qty > 0 ? number_format($qty, 0) : '<span class="text-muted">-</span>')
                       . '</td>';
                }
                echo '<td class="text-end fw-bold text-success">' . number_format($rowTot, 0) . '</td>';
                echo '</tr>';
            }

            // Grand total row
            echo '<tr class="table-success fw-bold">'
               . '<th colspan="3" class="text-end">Grand Total</th>';
            foreach ($ftypes as $ft) {
                echo '<th class="text-end">' . number_format((float)($grandTots[$ft] ?? 0), 0) . '</th>';
            }
            echo '<th class="text-end">' . number_format($grandTotal, 0) . '</th></tr>';
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">Qty dalam Kg &bull; Data dari modul Pemupukan'
               . ' &bull; <a href="fertilization.php">Lihat detail →</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;

        // ── Fertilization by Division (pivot) ────────────────────────────────
        case 'fertilization_by_division':
            $scope      = htmlspecialchars((string)($answer['scope']      ?? ''));
            $dateLabel  = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $ftypes     = (array)($answer['ftypes']      ?? []);
            $pivot      = (array)($answer['pivot']       ?? []);
            $meta       = (array)($answer['meta']        ?? []);
            $sortedKeys = (array)($answer['sorted_keys'] ?? []);
            $grandTots  = (array)($answer['grand_totals']?? []);
            $grandTotal = (float)($answer['grand_total'] ?? 0);
            $divCount   = (int)  ($answer['div_count']   ?? 0);
            $isEmpty    = !empty($answer['empty']);

            echo '<span class="qna-tag tag-division">🌿 Pemupukan per Divisi</span> ';
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel !== '') {
                echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';
            }
            echo ' &mdash; <span class="text-muted">' . $divCount . ' divisi</span>';

            if ($isEmpty || $divCount === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data pemupukan'
                    . ($dateLabel !== '' ? ' untuk periode <strong>' . $dateLabel . '</strong>' : '') . '. '
                    . 'Isi data di <a href="fertilization.php">Pemupukan</a>.</p>';
                break;
            }

            // Summary stat badges
            $grandCostD  = array_sum(array_column($meta, 'total_cost'));
            $grandHaD    = array_sum(array_column($meta, 'total_ha'));
            $grandAppsD  = array_sum(array_column($meta, 'app_count'));
            $grandBlkD   = array_sum(array_column($meta, 'block_count'));
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . $divCount . '</div><div class="qna-stat-lbl">Divisions</div></div>';
            if ($grandTotal  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandTotal / 1000, 1) . ' ton</div><div class="qna-stat-lbl">Total Fertilizer</div></div>';
            if ($grandHaD    > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandHaD, 1) . '</div><div class="qna-stat-lbl">Coverage (ha)</div></div>';
            if ($grandAppsD  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandAppsD) . '</div><div class="qna-stat-lbl">Applications</div></div>';
            if ($grandCostD  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandCostD / 1e6, 1) . ' M</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';

            // Header: multi-column — one col per fertilizer type + meta cols
            echo '<thead class="table-dark"><tr>'
               . '<th rowspan="2" class="align-middle">Estate</th>'
               . '<th rowspan="2" class="align-middle">Division</th>';
            foreach ($ftypes as $ft) {
                echo '<th class="text-center text-nowrap">' . htmlspecialchars((string)$ft) . '</th>';
            }
            echo '<th class="text-center text-nowrap">Total (Kg)</th>'
               . '<th class="text-center text-nowrap">Coverage (ha)</th>'
               . '<th class="text-center text-nowrap">Applications</th>'
               . '<th class="text-center text-nowrap">Cost (Rp)</th>'
               . '</tr><tr>';
            foreach ($ftypes as $ft) {
                echo '<th class="text-center small fw-normal" style="background:#2b3035;color:#adb5bd">Kg</th>';
            }
            echo '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '</tr></thead><tbody>';

            $prevEstate = null;
            foreach ($sortedKeys as $dkey) {
                $m      = (array)($meta[$dkey]  ?? []);
                $ddata  = (array)($pivot[$dkey] ?? []);
                $rowTot = array_sum($ddata);

                $showEstate = ($m['estate'] !== $prevEstate);
                if ($showEstate) {
                    $eSpan = count(array_filter($sortedKeys, fn($k) => (array)($meta[$k] ?? [])['estate'] ?? '' === ($m['estate'] ?? '')));
                }
                $prevEstate = $m['estate'];

                echo '<tr>';
                if ($showEstate) {
                    echo '<td rowspan="' . ($eSpan ?? 1) . '" class="align-middle fw-semibold text-nowrap" style="background:#f8f9fa">'
                       . htmlspecialchars((string)($m['estate'] ?? '')) . '</td>';
                }
                echo '<td class="text-nowrap">' . htmlspecialchars((string)($m['division'] ?? '')) . '</td>';
                foreach ($ftypes as $ft) {
                    $qty = (float)($ddata[$ft] ?? 0);
                    echo '<td class="text-end">'
                       . ($qty > 0 ? number_format($qty, 0) : '<span class="text-muted">-</span>')
                       . '</td>';
                }
                $ha   = (float)($m['total_ha']   ?? 0);
                $apps = (int)  ($m['app_count']  ?? 0);
                $cost = (float)($m['total_cost'] ?? 0);
                echo '<td class="text-end fw-bold text-success">' . number_format($rowTot, 0) . '</td>';
                echo '<td class="text-end">'  . ($ha   > 0 ? number_format($ha,   2) : '—') . '</td>';
                echo '<td class="text-end">'  . ($apps > 0 ? number_format($apps)    : '—') . '</td>';
                echo '<td class="text-end small">' . ($cost > 0 ? 'Rp ' . number_format($cost, 0, ',', '.') : '—') . '</td>';
                echo '</tr>';
            }

            // Grand total row
            echo '<tr class="table-success fw-bold">'
               . '<th colspan="2" class="text-end">Grand Total</th>';
            foreach ($ftypes as $ft) {
                echo '<th class="text-end">' . number_format((float)($grandTots[$ft] ?? 0), 0) . '</th>';
            }
            echo '<th class="text-end">' . number_format($grandTotal, 0) . '</th>';
            echo '<th class="text-end">' . ($grandHaD   > 0 ? number_format($grandHaD,  2) : '—') . '</th>';
            echo '<th class="text-end">' . ($grandAppsD > 0 ? number_format($grandAppsD)   : '—') . '</th>';
            echo '<th class="text-end">' . ($grandCostD > 0 ? 'Rp ' . number_format($grandCostD, 0, ',', '.') : '—') . '</th>';
            echo '</tr>';
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">Qty dalam Kg &bull; Baris = satu divisi, kolom = jenis pupuk &bull; Data dari modul Pemupukan'
               . ' &bull; <a href="fertilization.php">Lihat detail →</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;

        // ── Fertilization by Planting Year (pivot) HTML table ────────────────
        case 'fertilization_by_planting_year':
            $scope      = htmlspecialchars((string)($answer['scope']      ?? ''));
            $dateLabel  = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $ftypes     = (array)($answer['ftypes']      ?? []);
            $pivot      = (array)($answer['pivot']       ?? []);
            $meta       = (array)($answer['meta']        ?? []);
            $sortedKeys = (array)($answer['sorted_keys'] ?? []);
            $grandTots  = (array)($answer['grand_totals']?? []);
            $grandTotal = (float)($answer['grand_total'] ?? 0);
            $yearCount  = (int)  ($answer['year_count']  ?? 0);
            $isEmpty    = !empty($answer['empty']);

            echo '<span class="qna-tag tag-block">🌱 Fertilization by Planting Year</span> ';
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel !== '') {
                echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';
            }
            echo ' &mdash; <span class="text-muted">' . $yearCount . ' tahun tanam</span>';

            // DB error banner
            if (!empty($answer['db_error'])) {
                echo '<div class="alert alert-warning py-2 px-3 mt-2 mb-0" style="font-size:.8rem">'
                   . '<strong>⚠ Query error:</strong> ' . htmlspecialchars((string)$answer['db_error'])
                   . '</div>';
                break;
            }

            if ($isEmpty || $yearCount === 0) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data pemupukan per tahun tanam'
                    . ($dateLabel !== '' ? ' untuk periode <strong>' . $dateLabel . '</strong>' : '') . '. '
                    . 'Pastikan data Pemupukan memiliki Blok yang terhubung ke Tahun Tanam. '
                    . '<a href="fertilization.php">Input data →</a></p>';
                break;
            }

            // Summary stat badges
            $totalHaAllPYR  = array_sum(array_column($meta, 'total_ha'));
            $totalCostAllPYR = array_sum(array_column($meta, 'total_cost'));
            $totalAppsPYR   = array_sum(array_column($meta, 'app_count'));
            $totalBlkPYR    = array_sum(array_column($meta, 'block_count'));
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo '<div class="qna-stat"><div class="qna-stat-val">' . $yearCount . '</div><div class="qna-stat-lbl">Planting Years</div></div>';
            if ($grandTotal   > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandTotal / 1000, 1) . ' ton</div><div class="qna-stat-lbl">Total Fertilizer</div></div>';
            if ($totalHaAllPYR > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalHaAllPYR, 1) . '</div><div class="qna-stat-lbl">Coverage (ha)</div></div>';
            if ($totalAppsPYR  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalAppsPYR) . '</div><div class="qna-stat-lbl">Applications</div></div>';
            if ($totalCostAllPYR > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($totalCostAllPYR / 1e6, 1) . ' M</div><div class="qna-stat-lbl">Cost (Rp)</div></div>';
            echo '</div>';

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-1" style="font-size:.78rem">';

            // Header: Planting Year | [fert type cols] | Total Kg | Ha | Blocks | Cost
            echo '<thead class="table-dark"><tr>'
               . '<th class="text-nowrap">Planting Year</th>';
            foreach ($ftypes as $ft) {
                echo '<th class="text-center text-nowrap">' . htmlspecialchars((string)$ft) . '</th>';
            }
            echo '<th class="text-center text-nowrap">Total (Kg)</th>'
               . '<th class="text-end text-nowrap">Ha</th>'
               . '<th class="text-end text-nowrap">Blocks</th>'
               . '<th class="text-end text-nowrap">Cost (Rp)</th>'
               . '</tr><tr>'
               . '<th style="background:#2b3035;"></th>';
            foreach ($ftypes as $ft) {
                echo '<th class="text-center small fw-normal" style="background:#2b3035;color:#adb5bd">Kg</th>';
            }
            echo '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '<th style="background:#2b3035;"></th>'
               . '</tr></thead><tbody>';

            foreach ($sortedKeys as $yr) {
                $m      = (array)($meta[$yr] ?? []);
                $ydata  = (array)($pivot[$yr] ?? []);
                $rowTot = array_sum($ydata);
                $mHa    = (float)($m['total_ha']    ?? 0);
                $mCost  = (float)($m['total_cost']  ?? 0);
                $mBlk   = (int)  ($m['block_count'] ?? 0);
                $mAge   = date('Y') - (int)$yr;
                $ageCls = $mAge <= 3 ? 'text-info' : ($mAge >= 25 ? 'text-secondary' : '');

                echo '<tr>';
                echo '<td class="fw-semibold text-nowrap ' . $ageCls . '">TT ' . htmlspecialchars((string)$yr)
                   . ' <small class="text-muted fw-normal">(±' . $mAge . 'thn)</small></td>';
                foreach ($ftypes as $ft) {
                    $qty = (float)($ydata[$ft] ?? 0);
                    echo '<td class="text-end">'
                       . ($qty > 0 ? number_format($qty, 0) : '<span class="text-muted">-</span>')
                       . '</td>';
                }
                echo '<td class="text-end fw-bold text-success">' . number_format($rowTot, 0) . '</td>';
                echo '<td class="text-end text-muted">' . ($mHa   > 0 ? number_format($mHa,   2) : '—') . '</td>';
                echo '<td class="text-end text-muted">' . ($mBlk  > 0 ? number_format($mBlk)     : '—') . '</td>';
                echo '<td class="text-end small">'      . ($mCost > 0 ? 'Rp ' . number_format($mCost, 0, ',', '.') : '—') . '</td>';
                echo '</tr>';
            }

            // Grand total row
            echo '<tr class="table-success fw-bold">'
               . '<th class="text-end">Grand Total</th>';
            foreach ($ftypes as $ft) {
                echo '<th class="text-end">' . number_format((float)($grandTots[$ft] ?? 0), 0) . '</th>';
            }
            echo '<th class="text-end">' . number_format($grandTotal, 0) . '</th>';
            echo '<th class="text-end">' . ($totalHaAllPYR   > 0 ? number_format($totalHaAllPYR,   2) : '—') . '</th>';
            echo '<th class="text-end">' . ($totalBlkPYR     > 0 ? number_format($totalBlkPYR)        : '—') . '</th>';
            echo '<th class="text-end">' . ($totalCostAllPYR > 0 ? 'Rp ' . number_format($totalCostAllPYR, 0, ',', '.') : '—') . '</th>';
            echo '</tr>';
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-0">TT = Tahun Tanam &bull; Qty dalam Kg &bull; Usia dihitung dari tahun saat ini &bull; Data dari modul Pemupukan'
               . ' &bull; <a href="fertilization.php">Lihat detail →</a></p>';

            // Auto-analysis + standards check
            if (!empty($answer['auto_analyze'])) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;

        case 'harvest_transport':
            $scope        = htmlspecialchars((string)($answer['scope']            ?? ''));
            $harvestByDiv = (array)($answer['harvest_by_div']  ?? []);
            $delivByDiv   = (array)($answer['delivery_by_div'] ?? []);
            $gradeBdown   = (array)($answer['grade_breakdown']  ?? []);
            $grandHKg     = (float)($answer['grand_harvest_kg'] ?? 0);
            $grandHCnt    = (int)  ($answer['grand_harvest_cnt']?? 0);
            $grandBun     = (int)  ($answer['grand_bunches']    ?? 0);
            $grandDKg     = (float)($answer['grand_deliv_kg']   ?? 0);
            $grandDCnt    = (int)  ($answer['grand_deliv_cnt']  ?? 0);
            $grandRej     = (int)  ($answer['grand_rejected']   ?? 0);
            $grandUnl     = (int)  ($answer['grand_unloaded']   ?? 0);
            $trRatio      = $answer['transport_ratio'] ?? null;
            $avgAbw       = $answer['avg_abw']         ?? null;
            $isEmpty      = !empty($answer['empty']);

            echo '<span class="qna-tag tag-harvest">🚛 Harvest &amp; Transport</span> ';
            echo '<strong>' . $scope . '</strong>';

            if ($isEmpty) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data panen atau pengangkutan. '
                    . 'Isi data di <a href="harvest_realizations.php">Realisasi Panen</a> dan <a href="ffb_delivery.php">Pengiriman FFB</a>.</p>';
                break;
            }

            // ── Summary stat badges ──────────────────────────────────────────
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            if ($grandHKg  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . agro_fmt_kg($grandHKg)  . '</div><div class="qna-stat-lbl">Total Harvest</div></div>';
            if ($grandHCnt > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandHCnt) . '</div><div class="qna-stat-lbl">Records</div></div>';
            if ($grandBun  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandBun)  . '</div><div class="qna-stat-lbl">Bunches</div></div>';
            if ($avgAbw !== null) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format((float)$avgAbw, 1) . ' kg</div><div class="qna-stat-lbl">ABW</div></div>';
            if ($grandDKg  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . agro_fmt_kg($grandDKg)  . '</div><div class="qna-stat-lbl">Delivered to Mill</div></div>';
            if ($grandDCnt > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . number_format($grandDCnt) . '</div><div class="qna-stat-lbl">Deliveries</div></div>';
            if ($trRatio !== null) {
                $trColor = $trRatio >= 95 ? '#15803d' : ($trRatio >= 85 ? '#92400e' : '#991b1b');
                echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . $trColor . '">' . $trRatio . '%</div><div class="qna-stat-lbl">Transport Efficiency</div></div>';
            }
            if ($grandRej > 0) echo '<div class="qna-stat"><div class="qna-stat-val text-danger">' . $grandRej . '</div><div class="qna-stat-lbl">Rejected</div></div>';
            echo '</div>';

            // ── Harvest by Division table ───────────────────────────────────
            if (!empty($harvestByDiv)) {
                echo '<h6 class="mt-3 mb-1 fw-semibold" style="font-size:.82rem;color:#166534">🌿 Harvest Records by Division</h6>';
                echo '<div class="table-responsive">';
                echo '<table class="table table-sm table-bordered mb-2" style="font-size:.8rem">';
                echo '<thead class="table-success"><tr>'
                    . '<th>Division</th>'
                    . '<th class="text-end">Records</th>'
                    . '<th class="text-end">Total FFB (kg)</th>'
                    . '<th class="text-end">Bunches</th>'
                    . '<th class="text-end">ABW (kg)</th>'
                    . '<th>Periode</th>'
                    . '</tr></thead><tbody>';
                foreach ($harvestByDiv as $h) {
                    $h       = (array)$h;
                    $bunches = (int)$h['total_bunches'];
                    $tkg     = (float)$h['total_kg'];
                    $abwCell = $bunches > 0 ? number_format($tkg / $bunches, 2) : '—';
                    $period  = ($h['first_harvest'] ?? '') !== ''
                             ? htmlspecialchars((string)$h['first_harvest']) . ' – ' . htmlspecialchars((string)$h['last_harvest'])
                             : '—';
                    echo '<tr>'
                        . '<td class="fw-semibold">' . htmlspecialchars((string)$h['division_name']) . '</td>'
                        . '<td class="text-end">' . (int)$h['harvest_count'] . '</td>'
                        . '<td class="text-end fw-bold">' . number_format($tkg, 0) . '</td>'
                        . '<td class="text-end">' . number_format($bunches) . '</td>'
                        . '<td class="text-end">' . $abwCell . '</td>'
                        . '<td class="small text-muted">' . $period . '</td>'
                        . '</tr>';
                }
                echo '<tr class="table-dark fw-bold">'
                    . '<td>TOTAL</td>'
                    . '<td class="text-end">' . number_format($grandHCnt) . '</td>'
                    . '<td class="text-end">' . number_format($grandHKg, 0) . '</td>'
                    . '<td class="text-end">' . number_format($grandBun) . '</td>'
                    . '<td class="text-end">' . ($grandBun > 0 ? number_format($grandHKg / $grandBun, 2) : '—') . '</td>'
                    . '<td></td>'
                    . '</tr>';
                echo '</tbody></table></div>';
            }

            // ── Delivery by Division table ─────────────────────────────────
            if (!empty($delivByDiv)) {
                echo '<h6 class="mt-2 mb-1 fw-semibold" style="font-size:.82rem;color:#1e40af">🚛 FFB Delivery by Division</h6>';
                echo '<div class="table-responsive">';
                echo '<table class="table table-sm table-bordered mb-2" style="font-size:.8rem">';
                echo '<thead class="table-primary"><tr>'
                    . '<th>Division</th>'
                    . '<th class="text-end">Deliveries</th>'
                    . '<th class="text-end">Net Weight (kg)</th>'
                    . '<th class="text-end">Avg Transit Time (hr)</th>'
                    . '<th class="text-end">Rejected</th>'
                    . '<th class="text-end">Completed (Unloaded)</th>'
                    . '<th>Periode</th>'
                    . '</tr></thead><tbody>';
                foreach ($delivByDiv as $d) {
                    $d        = (array)$d;
                    $rejected = (int)$d['rejected_count'];
                    $unloaded = (int)$d['unloaded_count'];
                    $trvl     = (float)$d['avg_travel_hrs'] > 0 ? number_format((float)$d['avg_travel_hrs'], 1) : '—';
                    $period   = ($d['first_delivery'] ?? '') !== ''
                              ? htmlspecialchars((string)$d['first_delivery']) . ' – ' . htmlspecialchars((string)$d['last_delivery'])
                              : '—';
                    echo '<tr>'
                        . '<td class="fw-semibold">' . htmlspecialchars((string)$d['division_name']) . '</td>'
                        . '<td class="text-end">' . (int)$d['delivery_count'] . '</td>'
                        . '<td class="text-end fw-bold">' . number_format((float)$d['total_net_kg'], 0) . '</td>'
                        . '<td class="text-end">' . $trvl . '</td>'
                        . '<td class="text-end' . ($rejected > 0 ? ' text-danger' : '') . '">' . ($rejected > 0 ? $rejected : '—') . '</td>'
                        . '<td class="text-end">' . ($unloaded > 0 ? $unloaded : '—') . '</td>'
                        . '<td class="small text-muted">' . $period . '</td>'
                        . '</tr>';
                }
                echo '<tr class="table-dark fw-bold">'
                    . '<td>TOTAL</td>'
                    . '<td class="text-end">' . number_format($grandDCnt) . '</td>'
                    . '<td class="text-end">' . number_format($grandDKg, 0) . '</td>'
                    . '<td></td>'
                    . '<td class="text-end' . ($grandRej > 0 ? ' text-danger' : '') . '">' . ($grandRej > 0 ? $grandRej : '—') . '</td>'
                    . '<td class="text-end">' . ($grandUnl > 0 ? $grandUnl : '—') . '</td>'
                    . '<td></td>'
                    . '</tr>';
                echo '</tbody></table></div>';
            }

            // ── Quality grade breakdown ─────────────────────────────────────
            if (!empty($gradeBdown)) {
                $grandGKg = array_sum(array_column($gradeBdown, 'total_kg'));
                echo '<h6 class="mt-2 mb-1 fw-semibold" style="font-size:.82rem;color:#854d0e">🏅 Grade Kualitas TBS Terkirim</h6>';
                echo '<div class="d-flex flex-wrap gap-2 mb-2">';
                foreach ($gradeBdown as $g) {
                    $g = (array)$g;
                    $pct = $grandGKg > 0 ? number_format((float)$g['total_kg'] / $grandGKg * 100, 1) . '%' : '—';
                    $badge = match ((string)$g['quality_grade']) {
                        'Premium' => 'success', 'Grade A' => 'primary', 'Grade B' => 'info',
                        'Grade C' => 'warning', 'Reject'  => 'danger', default   => 'secondary',
                    };
                    echo '<div class="qna-stat">'
                        . '<div class="qna-stat-val"><span class="badge text-bg-' . $badge . '">' . htmlspecialchars((string)$g['quality_grade']) . '</span></div>'
                        . '<div class="qna-stat-lbl">' . (int)$g['count'] . ' kirim · ' . $pct . '</div>'
                        . '</div>';
                }
                echo '</div>';
            }

            if ($grandDKg === 0.0 && $grandHKg > 0) {
                echo '<p class="text-warning small mt-1 mb-0">⚠️ Belum ada data pengiriman FFB yang terhubung. '
                    . 'Pastikan realisasi panen sudah ditautkan ke <a href="ffb_delivery.php">Pengiriman FFB</a>.</p>';
            }
            echo '<p class="text-muted small mt-2 mb-0">Data dari modul Realisasi Panen dan Pengiriman FFB. '
                . 'Ketik "Apakah sesuai standar?" untuk cek ABW & harvest losses.</p>';
            break;

        case 'not_found':
            $suggestions = (array)($answer['suggestions'] ?? []);
            $searched    = htmlspecialchars((string)($answer['searched'] ?? ''));
            echo '<span class="qna-tag tag-notfound">Tidak Ditemukan</span> ';
            echo 'Tidak ada hasil untuk <strong>&ldquo;' . $searched . '&rdquo;</strong>.';
            if (!empty($suggestions)) {
                echo '<br><span class="text-muted small">Maksud Anda: </span>';
                foreach ($suggestions as $i => $sug) {
                    $sugText = htmlspecialchars((string)$sug);
                    $newQ    = htmlspecialchars(str_ireplace(
                        (string)($answer['searched'] ?? ''),
                        (string)$sug,
                        (string)($answer['question'] ?? $sug)
                    ));
                    echo ($i > 0 ? ', ' : '');
                    echo '<button class="qna-suggestion" data-q="' . $newQ . '">' . $sugText . '</button>';
                }
                echo '?';
            }
            break;

        case 'map_link':
            $scope      = htmlspecialchars((string)($answer['scope']      ?? ''));
            $mapUrl     = htmlspecialchars((string)($answer['map_url']    ?? 'blocks_map.php'));
            $totalBlk   = (int)($answer['total_blocks'] ?? 0);
            $geoBlk     = (int)($answer['geo_blocks']   ?? 0);

            echo '<span class="qna-tag tag-block">🗺️ Peta Blok</span> ';
            echo '<strong>' . $scope . '</strong>';
            echo '<div class="mt-2 d-flex flex-wrap gap-3">';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . $totalBlk . '</div><div class="qna-stat-lbl">Total Blok</div></div>';
            echo   '<div class="qna-stat"><div class="qna-stat-val">' . $geoBlk . '</div><div class="qna-stat-lbl">Blok di Peta</div></div>';
            echo '</div>';
            if ($geoBlk === 0) {
                echo '<p class="text-warning small mt-2 mb-1">⚠️ Belum ada blok dengan data GeoJSON. Tambahkan koordinat blok terlebih dahulu.</p>';
            }
            echo '<div class="mt-2">';
            echo   '<a href="' . $mapUrl . '" class="btn btn-success btn-sm" target="_blank">'
                .    '<i class="bi bi-map"></i> Buka Peta Blok'
                .  '</a>';
            echo '</div>';
            break;

        case 'standards_list':
            $cats = agro_std_categories();
            $catLabels = [
                'plantation'     => '🌿 Perkebunan',
                'mill'           => '⚙️ Pabrik',
                'infrastructure' => '🛣️ Infrastruktur',
                'sustainability' => '🌱 Keberlanjutan',
                'finance'        => '💰 Keuangan',
                'nursery'        => '🌱 Pembibitan',
                'fertilization'  => '🧪 Pemupukan',
                'weed_control'   => '🌾 Pengendalian Gulma',
                'pest_disease'   => '🐛 Hama & Penyakit (PHT)',
                'agrochemical'   => '⚗️ Bahan Kimia / Pestisida',
            ];
            $statusBg  = ['pass' => '#f0fdf4', 'warn' => '#fffbeb', 'fail' => '#fef2f2'];
            $statusTxt = ['pass' => '#15803d', 'warn' => '#92400e', 'fail' => '#991b1b'];
            $statusLbl = ['pass' => '✅ Lulus',  'warn' => '⚠️ Perhatian', 'fail' => '❌ Tidak lulus'];

            // ── Flash messages ────────────────────────────────────────────────
            if (!empty($_SESSION['qna_std_saved'])) {
                echo '<div class="alert alert-success py-2 px-3 mb-2" style="font-size:.82rem">'
                   . $_SESSION['qna_std_saved'] . '</div>';
                unset($_SESSION['qna_std_saved']);
            }
            if (!empty($_SESSION['qna_std_error'])) {
                echo '<div class="alert alert-danger py-2 px-3 mb-2" style="font-size:.82rem">'
                   . $_SESSION['qna_std_error'] . '</div>';
                unset($_SESSION['qna_std_error']);
            }

            echo '<div class="d-flex align-items-center justify-content-between mb-1">';
            echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📚 Standar GAPKI/PPKS/SNI</span>';
            echo '<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalAddStandard">'
               . '➕ Tambah Standar Baru</button>';
            echo '</div>';
            echo '<p class="text-muted small mt-0 mb-2">Referensi: GAPKI · PPKS Medan · SNI 8171 · SNI 7182 · Permentan RI · RSPO P&amp;C · ISPO 2020</p>';

            foreach ($cats as $cat) {
                $catStds = agro_std_by_category($cat);
                $catLabel = htmlspecialchars($catLabels[$cat] ?? ucfirst($cat));
                echo '<h6 class="mt-3 mb-1 fw-semibold d-flex align-items-center gap-2" style="color:#1e40af">'
                   . $catLabel;
                if ($cat === 'pest_disease') {
                    echo ' <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" '
                       . 'style="font-size:.7rem;line-height:1.4" data-bs-toggle="modal" data-bs-target="#modalAddStandard" '
                       . 'onclick="document.getElementById(\'cs_category\').value=\'pest_disease\'">'
                       . '+ Standar PHT</button>';
                }
                echo '</h6>';
                echo '<div class="table-responsive">';
                echo '<table class="table table-sm table-bordered mb-2" style="font-size:.78rem">';
                echo '<thead class="table-light"><tr>'
                    . '<th>Parameter</th><th class="text-center">Satuan</th>'
                    . '<th class="text-center">Standar</th><th class="text-center">Rentang Perhatian</th>'
                    . '<th>Deskripsi</th><th>Sumber</th><th></th>'
                    . '</tr></thead><tbody>';
                foreach ($catStds as $std) {
                    $isCustom  = !empty($std['_custom']);
                    $warnRange = '';
                    if ($std['warn_min'] !== null && $std['warn_max'] !== null)  $warnRange = number_format($std['warn_min'], 0) . '–' . number_format($std['warn_max'], 0);
                    elseif ($std['warn_min'] !== null)                           $warnRange = '≥' . number_format($std['warn_min'], 0);
                    elseif ($std['warn_max'] !== null)                           $warnRange = '&lt;' . number_format($std['warn_max'], 0);
                    $customBadge = $isCustom
                        ? ' <span class="badge" style="background:#fef3c7;color:#92400e;font-size:.65rem">Kustom</span>' : '';
                    $deleteBtn = $isCustom
                        ? '<form method="post" action="qna.php" style="display:inline" '
                        . 'onsubmit="return confirm(\'Hapus standar ini?\')">'
                        . '<input type="hidden" name="delete_custom_standard" value="1">'
                        . '<input type="hidden" name="cs_delete_id" value="' . htmlspecialchars($std['id']) . '">'
                        . '<button type="submit" class="btn btn-xs btn-outline-danger py-0 px-1" '
                        . 'style="font-size:.65rem;line-height:1.4" title="Hapus standar kustom ini">🗑</button>'
                        . '</form>'
                        : '';
                    echo '<tr' . ($isCustom ? ' style="background:#fffbeb"' : '') . '>'
                        . '<td class="fw-semibold">' . htmlspecialchars($std['param']) . $customBadge . '</td>'
                        . '<td class="text-center text-muted">' . htmlspecialchars($std['unit']) . '</td>'
                        . '<td class="text-center fw-bold" style="color:#1e40af">' . htmlspecialchars($std['display']) . '</td>'
                        . '<td class="text-center text-muted">' . ($warnRange ?: '—') . '</td>'
                        . '<td class="small">' . htmlspecialchars($std['description']) . '</td>'
                        . '<td class="small text-muted">' . htmlspecialchars($std['source']) . ' <span class="text-muted">(' . htmlspecialchars($std['source_year']) . ')</span></td>'
                        . '<td class="text-center">' . $deleteBtn . '</td>'
                        . '</tr>';
                }
                echo '</tbody></table></div>';
            }
            $customCount = count(agro_load_custom_standards());
            echo '<p class="text-muted small mt-1 mb-0">Total: <strong>' . agro_std_count() . ' parameter</strong> dalam '
                . count($cats) . ' kategori'
                . ($customCount > 0 ? ' (termasuk <strong>' . $customCount . ' kustom</strong>)' : '') . '. '
                . 'Ketik "Apakah sesuai standar?" setelah menampilkan tabel data untuk cek otomatis.</p>';

            // ── Modal: Tambah Standar Baru ─────────────────────────────────────
            echo <<<'MODAL'
<div class="modal fade" id="modalAddStandard" tabindex="-1" aria-labelledby="modalAddStandardLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="qna.php">
        <input type="hidden" name="add_custom_standard" value="1">
        <div class="modal-header" style="background:#7f1d1d;color:#fff">
          <h5 class="modal-title" id="modalAddStandardLabel">🐛 Tambah Standar PHT / Kustom</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="font-size:.85rem">
          <div class="alert alert-info py-2 px-3 mb-3" style="font-size:.78rem">
            Standar yang dibuat di sini akan muncul di daftar standar dan digunakan oleh fitur
            <em>Cek Standar Otomatis</em>. Gunakan format ID: huruf kecil, angka, dan garis bawah
            (contoh: <code>pest_rat_damage_pct</code>).
          </div>
          <div class="row g-2">
            <div class="col-md-5">
              <label class="form-label fw-semibold mb-1">ID Standar <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm" name="cs_std_id" id="cs_std_id"
                     placeholder="pest_rat_damage_pct" pattern="[a-z0-9_]+" required
                     oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9_]/g,'')">
              <div class="form-text">Unik, snake_case. Jika ID sama akan menimpa yang lama.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold mb-1">Kategori</label>
              <select class="form-select form-select-sm" name="cs_category" id="cs_category">
                <option value="pest_disease" selected>🐛 Hama & Penyakit (PHT)</option>
                <option value="plantation">🌿 Perkebunan</option>
                <option value="weed_control">🌾 Pengendalian Gulma</option>
                <option value="agrochemical">⚗️ Agrochemical</option>
                <option value="fertilization">🧪 Pemupukan</option>
                <option value="nursery">🌱 Pembibitan</option>
                <option value="sustainability">🌱 Keberlanjutan</option>
                <option value="mill">⚙️ Pabrik</option>
                <option value="finance">💰 Keuangan</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold mb-1">Satuan</label>
              <input type="text" class="form-control form-control-sm" name="cs_unit" value="%" placeholder="%, ha, kasus, dll">
            </div>
          </div>
          <div class="row g-2 mt-1">
            <div class="col-12">
              <label class="form-label fw-semibold mb-1">Nama Parameter <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm" name="cs_param" required
                     placeholder="Serangan Tikus (Rattus tiomanicus) — % tanaman terserang">
            </div>
          </div>
          <div class="row g-2 mt-1">
            <div class="col-md-6">
              <label class="form-label fw-semibold mb-1">Deskripsi</label>
              <textarea class="form-control form-control-sm" name="cs_description" rows="2"
                        placeholder="Persentase tanaman yang menunjukkan tanda serangan tikus per sensus blok..."></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold mb-1">Tampilan Standar</label>
              <input type="text" class="form-control form-control-sm" name="cs_display"
                     placeholder="<5% (kosongkan → otomatis dari pass_min/max)">
              <div class="form-text">Contoh: &lt;5%, ≥90%, 1–3</div>
            </div>
          </div>
          <hr class="my-2">
          <p class="fw-semibold mb-1" style="font-size:.8rem">Batas Nilai (kosongkan = tidak ada batas)</p>
          <div class="row g-2">
            <div class="col-6 col-md-3">
              <label class="form-label mb-1 text-success">Pass Min (≥)</label>
              <input type="number" step="any" class="form-control form-control-sm" name="cs_pass_min" placeholder="contoh: 90">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label mb-1 text-success">Pass Max (≤)</label>
              <input type="number" step="any" class="form-control form-control-sm" name="cs_pass_max" placeholder="contoh: 5">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label mb-1 text-warning">Warn Min</label>
              <input type="number" step="any" class="form-control form-control-sm" name="cs_warn_min" placeholder="contoh: 80">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label mb-1 text-warning">Warn Max</label>
              <input type="number" step="any" class="form-control form-control-sm" name="cs_warn_max" placeholder="contoh: 10">
            </div>
          </div>
          <div class="alert alert-light border py-2 px-3 mt-2 mb-0" style="font-size:.75rem">
            <strong>Logika:</strong> Jika nilai ≥ pass_min <em>dan</em> ≤ pass_max → <span class="text-success">Lulus</span>.
            Jika di luar pass tapi dalam warn → <span class="text-warning">Perhatian</span>.
            Selain itu → <span class="text-danger">Tidak Lulus</span>.
            Contoh hama: pass_max = 5 (serangan ≤5% = lulus), warn_max = 10 (5–10% = perhatian, >10% = tidak lulus).
          </div>
          <hr class="my-2">
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label fw-semibold mb-1">Sumber / Referensi</label>
              <input type="text" class="form-control form-control-sm" name="cs_source"
                     value="GAPKI — Panduan PHT Kelapa Sawit" placeholder="GAPKI / PPKS / SNI / dll">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold mb-1">Tahun</label>
              <input type="text" class="form-control form-control-sm" name="cs_source_year" value="2020" placeholder="2020">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold mb-1">Catatan Lulus</label>
              <input type="text" class="form-control form-control-sm" name="cs_pass_note"
                     value="Memenuhi standar" placeholder="Serangan terkendali sesuai standar PHT">
            </div>
          </div>
          <div class="row g-2 mt-1">
            <div class="col-md-6">
              <label class="form-label fw-semibold mb-1 text-warning">Catatan Perhatian</label>
              <input type="text" class="form-control form-control-sm" name="cs_warn_note"
                     value="Perlu perhatian" placeholder="Serangan meningkat, tingkatkan monitoring">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold mb-1 text-danger">Catatan Tidak Lulus</label>
              <input type="text" class="form-control form-control-sm" name="cs_fail_note"
                     value="Tidak memenuhi standar" placeholder="Serangan di atas ambang, tindakan segera">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-danger btn-sm">💾 Simpan Standar</button>
        </div>
      </form>
    </div>
  </div>
</div>
MODAL;
            break;

        case 'standards_check':
            $src     = (array)($answer['source_answer'] ?? []);
            $srcType = (string)($src['type'] ?? '');

            if (empty($src) || $srcType === '') {
                echo '<span class="qna-tag tag-unknown">📋 Cek Standar</span> ';
                echo '<span class="text-muted">Belum ada tabel untuk dicek. Tanya terlebih dahulu, misalnya '
                   . '"Kerapatan tanaman di ANP", lalu ketik "Apakah sesuai standar?"</span>';
                break;
            }

            echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
            echo agro_render_standards_check($src);
            break;

        case 'financial_summary': {
            $scope     = htmlspecialchars((string)($answer['scope']      ?? 'Semua Kebun'));
            $dateLabel = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $isEmpty   = !empty($answer['empty']);
            $bsFocus   = !empty($answer['bs_focus']);
            $rev       = (float)($answer['revenue']      ?? 0);
            $gp        = (float)($answer['gross_profit']  ?? 0);
            $opPrf     = (float)($answer['op_profit']     ?? 0);
            $net       = (float)($answer['net_profit']    ?? 0);
            $assets    = (float)($answer['total_assets']  ?? 0);
            $liab      = (float)($answer['total_liab']    ?? 0);
            $eq        = (float)($answer['total_equity']  ?? 0);
            $cogs      = (float)($answer['cogs']          ?? 0);
            $opex      = (float)($answer['opex']          ?? 0);
            $curAssets = (float)($answer['current_assets'] ?? 0);
            $curLiab   = (float)($answer['current_liab']   ?? 0);
            $gm        = $answer['gross_margin']  ?? null;
            $opm       = $answer['op_margin']     ?? null;
            $nm        = $answer['net_margin']    ?? null;
            $cr        = $answer['current_ratio'] ?? null;
            $der       = $answer['de_ratio']      ?? null;
            $roa       = $answer['roa']           ?? null;
            $roe       = $answer['roe']           ?? null;

            $rpFmt  = fn(float $v): string => ($v < 0 ? 'Rp -' : 'Rp ') . number_format(abs($v), 0, ',', '.');
            $pctFmt = fn($v): string => $v !== null ? number_format((float)$v, 1) . '%' : '—';
            $ratFmt = fn($v): string => $v !== null ? number_format((float)$v, 2) . 'x' : '—';

            if ($bsFocus) {
                echo '<span class="qna-tag tag-block" style="background:#dbeafe;color:#1e40af">📊 Neraca / Balance Sheet</span> ';
            } else {
                echo '<span class="qna-tag tag-block" style="background:#ede9fe;color:#5b21b6">💰 Analisa Keuangan</span> ';
            }
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel) echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';

            if ($isEmpty || ($rev == 0 && $assets == 0)) {
                echo '<p class="text-muted mt-2 mb-0 small">Belum ada data jurnal keuangan. '
                   . 'Isi data di <a href="journal_entries.php">Jurnal</a> terlebih dahulu.</p>';
                break;
            }

            if ($bsFocus) {
                // ── Balance Sheet Focus: show BS table first, then P&L summary ─────
                if ($assets == 0) {
                    echo '<p class="text-muted mt-2 mb-0 small">Belum ada data neraca (akun aset/liabilitas/ekuitas). '
                       . 'Pastikan akun neraca sudah dikonfigurasi di <a href="gl_accounts.php">Akun GL</a> '
                       . 'dan jurnal sudah di-posting.</p>';
                }
                if ($assets > 0) {
                    // BS stat badges
                    echo '<div class="mt-2 d-flex flex-wrap gap-3">';
                    echo '<div class="qna-stat"><div class="qna-stat-val">' . $rpFmt($assets) . '</div><div class="qna-stat-lbl">Total Aset</div></div>';
                    echo '<div class="qna-stat"><div class="qna-stat-val">' . $rpFmt($liab)   . '</div><div class="qna-stat-lbl">Total Liabilitas</div></div>';
                    echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ($eq >= 0 ? '#16a34a' : '#dc2626') . '">' . $rpFmt($eq) . '</div><div class="qna-stat-lbl">Total Ekuitas</div></div>';
                    if ($cr  !== null) echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ((float)$cr >= 1.5 ? '#16a34a' : ((float)$cr >= 1.0 ? '#d97706' : '#dc2626')) . '">' . $ratFmt($cr)  . '</div><div class="qna-stat-lbl">Current Ratio</div></div>';
                    if ($der !== null) echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ((float)$der <= 1.0 ? '#16a34a' : ((float)$der <= 2.0 ? '#d97706' : '#dc2626')) . '">' . $ratFmt($der) . '</div><div class="qna-stat-lbl">D/E Ratio</div></div>';
                    if ($roa !== null) echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ((float)$roa >= 5.0 ? '#16a34a' : ((float)$roa >= 0 ? '#d97706' : '#dc2626')) . '">' . $pctFmt($roa) . '</div><div class="qna-stat-lbl">ROA</div></div>';
                    if ($roe !== null) echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ((float)$roe >= 10.0? '#16a34a' : ((float)$roe >= 0 ? '#d97706' : '#dc2626')) . '">' . $pctFmt($roe) . '</div><div class="qna-stat-lbl">ROE</div></div>';
                    echo '</div>';

                    // BS table
                    echo '<div class="table-responsive mt-2">';
                    echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                    echo '<thead class="table-info"><tr>'
                       . '<th colspan="2">Neraca (Balance Sheet) — per ' . $dateLabel . '</th>'
                       . '<th class="text-end">Jumlah (Rp)</th>'
                       . '</tr></thead><tbody>';

                    $nonCurAssets = $assets - $curAssets;
                    $nonCurLiab   = $liab   - $curLiab;
                    $bsRows = [
                        ['section' => 'ASET',                 'bold' => true,  'val' => null],
                        ['section' => 'Aset Lancar',          'bold' => false, 'val' => $curAssets > 0 ? $curAssets : null],
                        ['section' => 'Aset Tidak Lancar',    'bold' => false, 'val' => $nonCurAssets > 0 ? $nonCurAssets : null],
                        ['section' => 'Total Aset',           'bold' => true,  'val' => $assets],
                        ['section' => 'LIABILITAS & EKUITAS', 'bold' => true,  'val' => null],
                        ['section' => 'Liabilitas Jangka Pendek', 'bold' => false, 'val' => $curLiab > 0 ? $curLiab : null],
                        ['section' => 'Liabilitas Jangka Panjang','bold' => false, 'val' => $nonCurLiab > 0 ? $nonCurLiab : null],
                        ['section' => 'Total Liabilitas',     'bold' => true,  'val' => $liab],
                        ['section' => 'Total Ekuitas',        'bold' => true,  'val' => $eq],
                        ['section' => 'Total Liabilitas + Ekuitas', 'bold' => true, 'val' => $liab + $eq],
                    ];
                    foreach ($bsRows as $row) {
                        if ($row['val'] === null && !$row['bold']) continue;
                        $colorStyle = '';
                        if ($row['val'] !== null) {
                            if (in_array($row['section'], ['Total Ekuitas']) && $row['val'] < 0) $colorStyle = ' style="color:#dc2626"';
                            elseif ($row['bold'] && $row['val'] > 0) $colorStyle = ' style="color:#1e40af"';
                        }
                        echo '<tr' . ($row['bold'] ? ' class="fw-semibold table-light"' : '') . '>'
                           . '<td colspan="2">' . htmlspecialchars($row['section']) . '</td>'
                           . '<td class="text-end"' . $colorStyle . '>' . ($row['val'] !== null ? $rpFmt($row['val']) : '') . '</td>'
                           . '</tr>';
                    }
                    echo '</tbody></table></div>';
                }

                // P&L summary (secondary, collapsed)
                if ($rev > 0) {
                    echo '<details class="mt-2"><summary class="small text-muted" style="cursor:pointer">📄 Lihat ringkasan Laba Rugi</summary>';
                    echo '<div class="table-responsive mt-1">';
                    echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                    echo '<thead class="table-primary"><tr>'
                       . '<th colspan="2">Laba Rugi (' . $dateLabel . ')</th>'
                       . '<th class="text-end">Jumlah (Rp)</th>'
                       . '<th class="text-end">Margin</th>'
                       . '</tr></thead><tbody>';
                    $plRows = [
                        ['Pendapatan',            $rev,    null],
                        ['Harga Pokok Penjualan', -$cogs,  null],
                        ['Laba Kotor',            $gp,     $gm],
                        ['Beban Operasional',     -$opex,  null],
                        ['Laba Operasional',      $opPrf,  $opm],
                        ['Laba Bersih',           $net,    $nm],
                    ];
                    foreach ($plRows as [$label, $val, $margin]) {
                        $isBold = in_array($label, ['Laba Kotor','Laba Operasional','Laba Bersih']);
                        $color  = $val < 0 ? '#dc2626' : ($isBold && $val > 0 ? '#16a34a' : '');
                        $style  = $color ? ' style="color:' . $color . '"' : '';
                        echo '<tr' . ($isBold ? ' class="fw-semibold"' : '') . '>'
                           . '<td>' . htmlspecialchars($label) . '</td>'
                           . '<td></td>'
                           . '<td class="text-end"' . $style . '>' . $rpFmt($val) . '</td>'
                           . '<td class="text-end text-muted">' . ($margin !== null ? $pctFmt($margin) : '—') . '</td>'
                           . '</tr>';
                    }
                    echo '</tbody></table></div></details>';
                }

            } else {
                // ── Default (P&L Focus): Income Statement first, BS badges below ──
                echo '<div class="mt-2 d-flex flex-wrap gap-3">';
                if ($rev  > 0) echo '<div class="qna-stat"><div class="qna-stat-val">' . $rpFmt($rev)   . '</div><div class="qna-stat-lbl">Pendapatan</div></div>';
                if ($gp   != 0) echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ($gp  >= 0 ? '#16a34a' : '#dc2626') . '">' . $rpFmt($gp)   . '</div><div class="qna-stat-lbl">Laba Kotor</div></div>';
                if ($opPrf!= 0) echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ($opPrf >= 0 ? '#16a34a' : '#dc2626') . '">' . $rpFmt($opPrf) . '</div><div class="qna-stat-lbl">Laba Operasional</div></div>';
                if ($net  != 0) echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ($net  >= 0 ? '#16a34a' : '#dc2626') . '">' . $rpFmt($net)   . '</div><div class="qna-stat-lbl">Laba Bersih</div></div>';
                echo '</div>';

                echo '<div class="table-responsive mt-2">';
                echo '<table class="table table-sm table-bordered mb-1" style="font-size:.8rem">';
                echo '<thead class="table-primary"><tr>'
                   . '<th colspan="2">Laba Rugi (' . $dateLabel . ')</th>'
                   . '<th class="text-end">Jumlah (Rp)</th>'
                   . '<th class="text-end">Margin</th>'
                   . '</tr></thead><tbody>';

                $plRows = [
                    ['Pendapatan',            $rev,    null],
                    ['Harga Pokok Penjualan', -$cogs,  null],
                    ['Laba Kotor',            $gp,     $gm],
                    ['Beban Operasional',     -$opex,  null],
                    ['Laba Operasional',      $opPrf,  $opm],
                    ['Laba Bersih',           $net,    $nm],
                ];
                foreach ($plRows as [$label, $val, $margin]) {
                    $isBold  = in_array($label, ['Laba Kotor','Laba Operasional','Laba Bersih']);
                    $color   = $val < 0 ? '#dc2626' : ($isBold && $val > 0 ? '#16a34a' : '');
                    $style   = $color ? ' style="color:' . $color . '"' : '';
                    echo '<tr' . ($isBold ? ' class="fw-semibold"' : '') . '>'
                       . '<td>' . htmlspecialchars($label) . '</td>'
                       . '<td></td>'
                       . '<td class="text-end"' . $style . '>' . $rpFmt($val) . '</td>'
                       . '<td class="text-end text-muted">' . ($margin !== null ? $pctFmt($margin) : '—') . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';

                if ($assets > 0) {
                    echo '<div class="mt-2 d-flex flex-wrap gap-3">';
                    echo '<div class="qna-stat"><div class="qna-stat-val">' . $rpFmt($assets) . '</div><div class="qna-stat-lbl">Total Aset</div></div>';
                    echo '<div class="qna-stat"><div class="qna-stat-val">' . $rpFmt($liab)   . '</div><div class="qna-stat-lbl">Liabilitas</div></div>';
                    echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ($eq >= 0 ? '#16a34a':'#dc2626') . '">' . $rpFmt($eq) . '</div><div class="qna-stat-lbl">Ekuitas</div></div>';
                    if ($cr  !== null) echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ((float)$cr >= 1.5 ? '#16a34a' : ((float)$cr >= 1.0 ? '#d97706' : '#dc2626')) . '">' . $ratFmt($cr)  . '</div><div class="qna-stat-lbl">Current Ratio</div></div>';
                    if ($der !== null) echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ((float)$der <= 1.0 ? '#16a34a' : ((float)$der <= 2.0 ? '#d97706' : '#dc2626')) . '">' . $ratFmt($der) . '</div><div class="qna-stat-lbl">D/E Ratio</div></div>';
                    if ($roa !== null) echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ((float)$roa >= 5.0 ? '#16a34a' : ((float)$roa >= 0 ? '#d97706' : '#dc2626')) . '">' . $pctFmt($roa) . '</div><div class="qna-stat-lbl">ROA</div></div>';
                    if ($roe !== null) echo '<div class="qna-stat"><div class="qna-stat-val" style="color:' . ((float)$roe >= 10.0? '#16a34a' : ((float)$roe >= 0 ? '#d97706' : '#dc2626')) . '">' . $pctFmt($roe) . '</div><div class="qna-stat-lbl">ROE</div></div>';
                    echo '</div>';
                }
            }

            echo '<p class="text-muted small mt-1 mb-0">Data dari <a href="financial_reports.php">Laporan Keuangan</a> &bull; Jurnal status <em>posted</em></p>';

            // Auto-analysis block
            if (!empty($answer['auto_analyze']) && ($rev > 0 || $assets > 0)) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
                echo agro_render_analysis($answer);
                $stdHtml = agro_render_standards_check($answer);
                if ($stdHtml !== '') {
                    echo '<hr class="my-2">';
                    echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                    echo $stdHtml;
                }
            }
            break;
        }

        case 'analyze_request':
            $src     = (array)($answer['source_answer'] ?? []);
            $srcType = (string)($src['type'] ?? '');

            if (empty($src) || $srcType === '') {
                echo '<span class="qna-tag tag-unknown">📋 Analisis</span> ';
                echo '<span class="text-muted">Belum ada tabel untuk dianalisis. Tanya tabel terlebih dahulu, lalu ketik "Analisa tabel ini".</span>';
                break;
            }

            echo '<span class="qna-tag tag-division">🔍 Analisis</span> ';
            echo agro_render_analysis($src);

            // Standards check is always appended after analysis
            $stdHtml = agro_render_standards_check($src);
            if ($stdHtml !== '') {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag tag-division" style="background:#fef3c7;color:#92400e">📏 Standards Compliance</span> ';
                echo $stdHtml;
            }
            break;

        // ── Analisa Keseluruhan / Executive Summary ─────────────────────────
        case 'comprehensive_analysis': {
            $domains     = (array)($answer['domains']      ?? []);
            $scope       = htmlspecialchars((string)($answer['scope']      ?? 'Semua Kebun'));
            $dateLabel   = htmlspecialchars((string)($answer['date_label'] ?? ''));
            $domainCount = (int)($answer['domain_count']   ?? count($domains));
            $execMode    = !empty($answer['exec_mode']);

            // Domain metadata: label, emoji, GAPKI weight (%), type key
            $domainMeta = [
                'plantation'     => ['label' => 'Perkebunan',       'icon' => '🌿', 'weight' => 20, 'color' => '#16a34a'],
                'harvest'        => ['label' => 'Hasil Panen',       'icon' => '🌾', 'weight' => 20, 'color' => '#ca8a04'],
                'fertilization'  => ['label' => 'Pemupukan',         'icon' => '🧪', 'weight' => 15, 'color' => '#7c3aed'],
                'chemicals'      => ['label' => 'Bahan Kimia',        'icon' => '⚗️', 'weight' => 10, 'color' => '#dc2626'],
                'pest'           => ['label' => 'Hama & Penyakit',   'icon' => '🐛', 'weight' => 10, 'color' => '#b45309'],
                'weed'           => ['label' => 'Gulma',              'icon' => '🌱', 'weight' => 5,  'color' => '#15803d'],
                'nursery'        => ['label' => 'Pembibitan',         'icon' => '🪴', 'weight' => 5,  'color' => '#0891b2'],
                'infrastructure' => ['label' => 'Infrastruktur',      'icon' => '🛤️', 'weight' => 5,  'color' => '#6b7280'],
                'mill'           => ['label' => 'Pabrik / Mill',      'icon' => '🏭', 'weight' => 5,  'color' => '#9333ea'],
                'financial'      => ['label' => 'Keuangan',           'icon' => '💰', 'weight' => 5,  'color' => '#0369a1'],
            ];

            if ($execMode) {
                echo '<span class="qna-tag" style="background:#1e3a5f;color:#e0f2fe;font-size:.75rem">📋 Executive Summary</span> ';
            } else {
                echo '<span class="qna-tag" style="background:#e0f2fe;color:#0369a1;font-size:.75rem">🔬 Analisa Keseluruhan</span> ';
            }
            echo '<strong>' . $scope . '</strong>';
            if ($dateLabel !== '') {
                echo ' <span class="badge bg-secondary ms-1">' . $dateLabel . '</span>';
            }
            echo ' <span class="text-muted small">&mdash; ' . $domainCount . ' domain tersedia</span>';
            if ($execMode) {
                $subLabel = 'Ringkasan Analisis'
                          . ($scope !== 'Semua Kebun' ? ' ' . $scope : '')
                          . ($dateLabel !== '' ? ' Tahun ' . $dateLabel : '');
                echo '<div class="text-muted small mt-1" style="font-style:italic">' . htmlspecialchars($subLabel) . '</div>';
            }

            if ($domainCount === 0) {
                echo '<div class="alert alert-warning py-2 px-3 small mt-2 mb-0">'
                   . 'Tidak ada data untuk dianalisis. Pastikan data sudah diinput di setiap modul.</div>';
                break;
            }

            // ── GAPKI Composite Score ─────────────────────────────────────────
            // For each domain present, call agro_render_standards_check internally
            // and tally pass/warn/fail to produce a weighted score
            $gapkiRows   = [];  // [domain_key, label, icon, weight, status, note]
            $totalWeight = 0;
            $weightedScore = 0.0;

            foreach ($domainMeta as $dKey => $dMeta) {
                if (!isset($domains[$dKey])) continue;
                $dData   = (array)$domains[$dKey];
                $dType   = $dData['type'] ?? '';
                $weight  = $dMeta['weight'];
                $totalWeight += $weight;

                // Quick status from key metrics available in the data
                $status = 'info';   // no standard available for this domain
                $note   = '';

                switch ($dKey) {
                    case 'harvest':
                        $yld = (float)($dData['yield_per_ha_tm'] ?? $dData['yield_per_ha'] ?? 0);
                        // Derive yield/ha from total_kg ÷ TM area (from plantation domain if present)
                        if ($yld <= 0) {
                            $totalKg  = (float)($dData['total_kg'] ?? 0);
                            $tmAreaHa = (float)($domains['plantation']['tm_area_ha'] ?? 0);
                            if ($totalKg > 0 && $tmAreaHa > 0) {
                                $yld = $totalKg / $tmAreaHa;  // kg/ha TM
                            }
                        }
                        if ($yld > 0) {
                            if ($yld >= 20000)      { $status = 'pass'; $note = number_format($yld/1000,1).' ton/ha TM (≥20 ton — GAPKI Optimal)'; }
                            elseif ($yld >= 15000)  { $status = 'warn'; $note = number_format($yld/1000,1).' ton/ha TM (15–20 ton — di bawah GAPKI Optimal)'; }
                            else                    { $status = 'fail'; $note = number_format($yld/1000,1).' ton/ha TM (<15 ton — tidak sesuai GAPKI)'; }
                            $pct = $status === 'pass' ? 100 : ($status === 'warn' ? 65 : 30);
                            $weightedScore += $weight * $pct / 100;
                        } elseif ((int)($dData['records'] ?? 0) > 0) {
                            // Harvest records exist but TM area not available — show tonnage
                            $totalTon = (float)($dData['total_kg'] ?? 0) / 1000;
                            $note = number_format($totalTon, 1) . ' ton TBS (luas TM tidak tersedia untuk hitung yield/ha)';
                            $weightedScore += $weight * 0.5;
                        } else {
                            $note = 'Belum ada data panen pada periode ini';
                            $weightedScore += $weight * 0.5;
                        }
                        break;
                    case 'plantation':
                        $tm = (float)($dData['tm_ratio_pct'] ?? 0);
                        $sph = (float)($dData['avg_sph'] ?? 0);
                        if ($tm > 0) {
                            if ($tm >= 70)      { $status = 'pass'; $note = "TM {$tm}% (≥70% — GAPKI)"; }
                            elseif ($tm >= 50)  { $status = 'warn'; $note = "TM {$tm}% (50–70% — perlu optimasi)"; }
                            else                { $status = 'fail'; $note = "TM {$tm}% (<50% — dominasi TBM)"; }
                            $pct = $status === 'pass' ? 100 : ($status === 'warn' ? 60 : 25);
                            if ($sph > 0 && $sph < 120) { $status = $status === 'pass' ? 'warn' : $status; $note .= '; SPH '.$sph.' <120 (rendah)'; $pct = min($pct, 65); }
                            $weightedScore += $weight * $pct / 100;
                        } else {
                            $note = 'Data TM/TBM belum tersedia';
                            $weightedScore += $weight * 0.5;
                        }
                        break;
                    case 'fertilization':
                        $apps = (int)($dData['grand_apps'] ?? 0);
                        $fertCount = (int)($dData['count'] ?? 0);
                        if ($apps > 0) {
                            $status = $apps >= 2 ? 'pass' : 'warn';
                            $note   = "{$fertCount} jenis pupuk, {$apps} aplikasi";
                            $note  .= $apps >= 2 ? ' (memenuhi jadwal GAPKI)' : ' (< 2 rotasi — kurang dari GAPKI)';
                            $weightedScore += $weight * ($status === 'pass' ? 1.0 : 0.6);
                        } else {
                            $status = 'fail'; $note = 'Belum ada data pemupukan';
                            $weightedScore += 0;
                        }
                        break;
                    case 'chemicals':
                        $hasParaquat = false;
                        foreach ((array)($dData['chemicals'] ?? []) as $c) {
                            $c = (array)$c;
                            if (stripos((string)($c['pesticide_name'] ?? ''), 'paraquat') !== false
                             || stripos((string)($c['pesticide_name'] ?? ''), 'gramoxone') !== false) {
                                $hasParaquat = true; break;
                            }
                        }
                        $n = (int)($dData['count'] ?? 0);
                        if ($n > 0) {
                            $status = $hasParaquat ? 'fail' : 'pass';
                            $note   = "{$n} produk" . ($hasParaquat ? ' — Paraquat/Gramoxone terdeteksi (dilarang RSPO)' : ' — bebas paraquat (RSPO compliant)');
                            $weightedScore += $weight * ($hasParaquat ? 0.0 : 1.0);
                        } else {
                            $note = 'Belum ada data bahan kimia';
                            $weightedScore += $weight * 0.5;
                        }
                        break;
                    case 'pest':
                        $totalRec = (int)($dData['total_records'] ?? array_sum(array_column((array)($dData['by_type_severity'] ?? []), 'record_count')));
                        $critical = 0;
                        foreach ((array)($dData['by_type_severity'] ?? []) as $r) {
                            $r = (array)$r;
                            if (in_array(strtolower((string)($r['severity'] ?? '')), ['critical', 'high'], true)) {
                                $critical += (int)($r['record_count'] ?? 0);
                            }
                        }
                        if ($totalRec > 0) {
                            $critPct = $totalRec > 0 ? round($critical / $totalRec * 100) : 0;
                            if ($critPct <= 10)     { $status = 'pass'; $note = "Critical+High {$critPct}% dari total (≤10% — terkendali)"; }
                            elseif ($critPct <= 30) { $status = 'warn'; $note = "Critical+High {$critPct}% — perlu intensifikasi PHT"; }
                            else                    { $status = 'fail'; $note = "Critical+High {$critPct}% — serangan OPT berat"; }
                            $weightedScore += $weight * ($status === 'pass' ? 1.0 : ($status === 'warn' ? 0.6 : 0.2));
                        } else {
                            $note = 'Belum ada data hama & penyakit';
                            $weightedScore += $weight * 0.5;
                        }
                        break;
                    case 'weed':
                        $wRecords = (int)($dData['total_records'] ?? 0);
                        if ($wRecords > 0) {
                            $status = 'pass'; $note = "{$wRecords} catatan pengendalian gulma";
                            $weightedScore += $weight * 1.0;
                        } else {
                            $note = 'Belum ada data gulma'; $weightedScore += $weight * 0.5;
                        }
                        break;
                    case 'nursery':
                        // agro_nursery_summary returns total_seeds / total_sprouts / total_polybag / total_ready
                        $nTotal = (int)($dData['total_seedlings']
                                    ?? $dData['total']
                                    ?? ((int)($dData['total_seeds'] ?? 0)
                                      + (int)($dData['total_sprouts'] ?? 0)
                                      + (int)($dData['total_polybag'] ?? 0)
                                      + (int)($dData['total_ready']   ?? 0)));
                        if ($nTotal <= 0) {
                            // Also check batches — master data may exist even if qty = 0
                            $nTotal = (int)($dData['batches'] ?? 0);
                        }
                        if ($nTotal > 0) {
                            $seeds   = (int)($dData['total_seeds']  ?? 0);
                            $ready   = (int)($dData['total_ready']  ?? 0);
                            $batches = (int)($dData['batches']       ?? 0);
                            $note    = "{$batches} batch" . ($seeds > 0 ? ', ' . number_format($seeds) . ' benih' : '')
                                     . ($ready > 0 ? ', ' . number_format($ready) . ' siap tanam' : '');
                            $status = 'pass';
                            $weightedScore += $weight * 1.0;
                        } else {
                            $note = 'Belum ada data pembibitan'; $weightedScore += $weight * 0.5;
                        }
                        break;
                    case 'infrastructure':
                        // agro_infrastructure_summary returns grand_road_m (not total_road_length_m / grand_length_m)
                        $roadLen = (float)($dData['grand_road_m'] ?? $dData['total_road_length_m'] ?? $dData['grand_length_m'] ?? 0);
                        if ($roadLen > 0) {
                            $bridgeN = (int)($dData['grand_bridge_n'] ?? 0);
                            $note    = number_format($roadLen/1000,1) . ' km jalan'
                                     . ($bridgeN > 0 ? ', ' . $bridgeN . ' jembatan' : '');
                            $status = 'pass';
                            $weightedScore += $weight * 1.0;
                        } elseif (!empty($dData['bridge_rows'])) {
                            // No road data but division/bridge records exist — infra data is present
                            $bridgeN = (int)($dData['grand_bridge_n'] ?? 0);
                            $note    = 'Data jalan belum ada' . ($bridgeN > 0 ? ', ' . $bridgeN . ' jembatan' : '');
                            $status  = 'warn';
                            $weightedScore += $weight * 0.5;
                        } else {
                            $note = 'Belum ada data infrastruktur'; $weightedScore += $weight * 0.5;
                        }
                        break;
                    case 'mill':
                        $oer = (float)($dData['avg_oer'] ?? $dData['oer'] ?? 0);
                        $ker = (float)($dData['avg_ker'] ?? $dData['ker'] ?? 0);
                        if ($oer > 0) {
                            if ($oer >= 23)      { $status = 'pass'; $note = "OER {$oer}% (≥23% — GAPKI); KER {$ker}%"; }
                            elseif ($oer >= 20)  { $status = 'warn'; $note = "OER {$oer}% (20–23% — di bawah optimal)"; }
                            else                 { $status = 'fail'; $note = "OER {$oer}% (<20% — tidak efisien)"; }
                            $weightedScore += $weight * ($status === 'pass' ? 1.0 : ($status === 'warn' ? 0.6 : 0.2));
                        } else {
                            $note = 'Data OER/KER belum tersedia'; $weightedScore += $weight * 0.5;
                        }
                        break;
                    case 'financial':
                        $gm = (float)($dData['gross_margin'] ?? 0);
                        $nm = (float)($dData['net_margin']   ?? 0);
                        if ($gm > 0) {
                            if ($gm >= 30 && $nm >= 10) { $status = 'pass'; $note = "GM {$gm}%, NM {$nm}% (sesuai GAPKI)"; }
                            elseif ($gm >= 15)          { $status = 'warn'; $note = "GM {$gm}%, NM {$nm}% (di bawah standar GAPKI)"; }
                            else                        { $status = 'fail'; $note = "GM {$gm}% (<15% — tidak sesuai)"; }
                            $weightedScore += $weight * ($status === 'pass' ? 1.0 : ($status === 'warn' ? 0.6 : 0.2));
                        } else {
                            $note = 'Data keuangan belum tersedia'; $weightedScore += $weight * 0.5;
                        }
                        break;
                    default:
                        $note = 'Data tersedia'; $weightedScore += $weight * 0.7;
                }

                $gapkiRows[] = [
                    'key'    => $dKey,
                    'label'  => $dMeta['label'],
                    'icon'   => $dMeta['icon'],
                    'weight' => $weight,
                    'status' => $status,
                    'note'   => $note,
                    'color'  => $dMeta['color'],
                ];
            }

            // Normalise score to 0–100 based on total weight assigned
            $compositeScore = $totalWeight > 0 ? round($weightedScore / $totalWeight * 100) : 0;
            $scoreColor  = $compositeScore >= 75 ? '#16a34a' : ($compositeScore >= 50 ? '#d97706' : '#dc2626');
            $scoreLabel  = $compositeScore >= 75 ? 'Baik' : ($compositeScore >= 50 ? 'Cukup' : 'Perlu Perbaikan');
            $scoreIcon   = $compositeScore >= 75 ? '✅' : ($compositeScore >= 50 ? '⚠️' : '❌');
            $nPass = count(array_filter($gapkiRows, fn($r) => $r['status'] === 'pass'));
            $nWarn = count(array_filter($gapkiRows, fn($r) => $r['status'] === 'warn'));
            $nFail = count(array_filter($gapkiRows, fn($r) => $r['status'] === 'fail'));
            $nInfo = count(array_filter($gapkiRows, fn($r) => $r['status'] === 'info'));

            // ── Header scorecard ───────────────────────────────────────────────
            echo '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:.5rem;padding:.75rem 1rem;margin-top:.75rem">';
            echo '<div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">';
            echo   '<div style="text-align:center;min-width:90px">';
            echo     '<div style="font-size:2.4rem;font-weight:800;color:' . $scoreColor . ';line-height:1">' . $compositeScore . '</div>';
            echo     '<div style="font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Skor GAPKI</div>';
            echo     '<div style="font-size:.8rem;font-weight:600;color:' . $scoreColor . '">' . $scoreIcon . ' ' . htmlspecialchars($scoreLabel) . '</div>';
            echo   '</div>';
            echo   '<div style="flex:1;min-width:200px">';
            echo     '<div style="font-size:.8rem;color:#374151;margin-bottom:.35rem"><strong>' . $domainCount . ' domain</strong> dianalisis | Bobot total: ' . $totalWeight . '%</div>';
            echo     '<div style="display:flex;gap:.4rem;flex-wrap:wrap">';
            if ($nPass) echo '<span style="background:#dcfce7;color:#166534;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:600">✅ ' . $nPass . ' Sesuai</span>';
            if ($nWarn) echo '<span style="background:#fef9c3;color:#854d0e;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:600">⚠️ ' . $nWarn . ' Perhatian</span>';
            if ($nFail) echo '<span style="background:#fee2e2;color:#991b1b;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:600">❌ ' . $nFail . ' Tidak Sesuai</span>';
            if ($nInfo) echo '<span style="background:#e0f2fe;color:#0369a1;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:600">ℹ️ ' . $nInfo . ' Data Kurang</span>';
            echo     '</div>';
            // GAPKI progress bar
            echo     '<div style="margin-top:.4rem;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden">';
            echo       '<div style="height:100%;width:' . $compositeScore . '%;background:' . $scoreColor . ';transition:width .3s"></div>';
            echo     '</div>';
            echo   '</div>';
            echo '</div>';
            echo '</div>'; // end scorecard

            // ── GAPKI domain score table ───────────────────────────────────────
            $stIco = ['pass' => '✅', 'warn' => '⚠️', 'fail' => '❌', 'info' => 'ℹ️'];
            $stBg  = ['pass' => '#f0fdf4', 'warn' => '#fffbeb', 'fail' => '#fef2f2', 'info' => '#f0f9ff'];
            $stTxt = ['pass' => '#15803d', 'warn' => '#92400e', 'fail' => '#991b1b', 'info' => '#0369a1'];

            echo '<div class="table-responsive mt-2">';
            echo '<table class="table table-sm table-bordered mb-0" style="font-size:.8rem">';
            echo '<thead class="table-dark"><tr>'
               . '<th style="width:2rem">#</th>'
               . '<th>Domain</th>'
               . '<th class="text-center" style="width:5rem">Bobot</th>'
               . '<th class="text-center" style="width:5rem">Status</th>'
               . '<th>Temuan / Catatan</th>'
               . '</tr></thead><tbody>';
            foreach ($gapkiRows as $i => $row) {
                $stKey = $row['status'];
                echo '<tr style="background:' . ($stBg[$stKey] ?? '#fff') . '">'
                   . '<td class="text-muted">' . ($i + 1) . '</td>'
                   . '<td><span style="margin-right:.3rem">' . $row['icon'] . '</span>'
                   . '<strong>' . htmlspecialchars($row['label']) . '</strong></td>'
                   . '<td class="text-center text-muted">' . $row['weight'] . '%</td>'
                   . '<td class="text-center" style="color:' . ($stTxt[$stKey] ?? '#374151') . ';font-weight:600">'
                   . ($stIco[$stKey] ?? '—') . '</td>'
                   . '<td class="small" style="color:' . ($stTxt[$stKey] ?? '#374151') . '">' . htmlspecialchars($row['note']) . '</td>'
                   . '</tr>';
            }
            echo '</tbody></table></div>';
            echo '<p class="text-muted small mb-2 mt-1">Bobot berdasarkan kriteria GAPKI 2020 · Skor 0–100</p>';

            // ── Per-domain expandable sections (both exec and full mode) ──────────
            foreach ($domainMeta as $dKey => $dMeta) {
                if (!isset($domains[$dKey])) continue;
                $dData = (array)$domains[$dKey];

                echo '<hr class="my-2">';
                echo '<div>';
                echo '<span class="qna-tag" style="background:' . $dMeta['color'] . '22;color:' . $dMeta['color'] . ';font-size:.72rem">'
                   . $dMeta['icon'] . ' ' . htmlspecialchars($dMeta['label']) . '</span> ';

                $analysisHtml = agro_render_analysis($dData);
                if ($analysisHtml !== '') echo $analysisHtml;

                $stdHtml = agro_render_standards_check($dData);
                if ($stdHtml !== '') {
                    echo '<div style="margin-top:.4rem">';
                    echo '<span class="qna-tag" style="background:#fef3c7;color:#92400e;font-size:.7rem">📏 Standar</span> ';
                    echo $stdHtml;
                    echo '</div>';
                }
                echo '</div>';
            }

            // ── Actionable recommendations (both modes) ───────────────────────
            $recs = [];
            foreach ($gapkiRows as $row) {
                if ($row['status'] === 'fail') {
                    $recs[] = '❌ <strong>' . htmlspecialchars($row['label']) . ':</strong> ' . htmlspecialchars($row['note']);
                } elseif ($row['status'] === 'warn') {
                    $recs[] = '⚠️ <strong>' . htmlspecialchars($row['label']) . ':</strong> ' . htmlspecialchars($row['note']);
                }
            }
            if (!empty($recs)) {
                echo '<hr class="my-2">';
                echo '<span class="qna-tag" style="background:#fef3c7;color:#92400e;font-size:.72rem">🎯 Rekomendasi Prioritas</span>';
                echo '<ul class="small mb-0 mt-1 ps-3">';
                foreach ($recs as $rec) echo '<li class="mb-1">' . $rec . '</li>';
                echo '</ul>';
            }


            break;
        }

        // ── Semantic clarification request ────────────────────────────────────
        case 'semantic_clarify': {
            $smsg = (string)($answer['message'] ?? '');
            $ssem = $answer['semantic'] ?? [];
            echo '<span class="qna-tag" style="background:#dbeafe;color:#1e40af;font-size:.72rem">🔍 Perlu Klarifikasi</span>';
            echo '<p class="mb-1 mt-2">' . nl2br(htmlspecialchars($smsg)) . '</p>';
            if (!empty($ssem['missing_context'])) {
                echo '<ul class="small mb-0 ps-3">';
                foreach ((array)$ssem['missing_context'] as $mc) {
                    echo '<li>' . htmlspecialchars((string)$mc) . '</li>';
                }
                echo '</ul>';
            }
            break;
        }

        // ── Semantic fallback — understood but no route available ──────────────
        case 'semantic_no_route': {
            $smsg    = (string)($answer['message']            ?? 'Pertanyaan dipahami namun belum dapat dijawab secara langsung.');
            $sop     = (string)($answer['semantic_operation'] ?? '');
            $sdomain = (string)($answer['semantic_domain']    ?? '');
            $sentity = (string)($answer['semantic_entity']    ?? '');
            $sctx    = (string)($answer['context_summary']    ?? '');
            $sresolved = (array)($answer['resolved_from']     ?? []);

            echo '<span class="qna-tag" style="background:#fef9c3;color:#854d0e;font-size:.72rem">💡 Interpretasi Semantik</span>';
            echo '<p class="mb-2 mt-2">' . nl2br(htmlspecialchars($smsg)) . '</p>';

            // Show what was understood
            $tags = array_filter([
                $sop     ? 'Operasi: <strong>' . htmlspecialchars($sop) . '</strong>'     : '',
                $sdomain ? 'Domain: <strong>' . htmlspecialchars($sdomain) . '</strong>'   : '',
                $sentity ? 'Entitas: <strong>' . htmlspecialchars($sentity) . '</strong>'  : '',
                $sctx    ? 'Konteks: <strong>' . htmlspecialchars($sctx) . '</strong>'     : '',
            ]);
            if (!empty($tags)) {
                echo '<p class="small text-muted mb-1">' . implode(' &middot; ', $tags) . '</p>';
            }

            // Show context resolution chain if available
            if (!empty($sresolved)) {
                echo '<details class="mt-1"><summary class="small text-muted" style="cursor:pointer">Resolusi konteks</summary>';
                echo '<ul class="small mb-0 mt-1 ps-3">';
                foreach ($sresolved as $res) {
                    $key = htmlspecialchars((string)($res['key']   ?? ''));
                    $val = htmlspecialchars((string)($res['value'] ?? ''));
                    $src = htmlspecialchars((string)($res['source'] ?? ''));
                    echo '<li><code>' . $key . '</code> = <strong>' . $val . '</strong>' . ($src ? ' <em>(' . $src . ')</em>' : '') . '</li>';
                }
                echo '</ul></details>';
            }
            break;
        }


        default:
            echo '<span class="qna-tag tag-unknown">?</span> ';
            echo htmlspecialchars((string)($answer['message'] ?? 'Pertanyaan tidak dikenali.'));
            break;
    }

    return (string)ob_get_clean();
}

// ─────────────────────────────────────────────────────────────────────────────
// Page output
// ─────────────────────────────────────────────────────────────────────────────
$page_title = 'Analysis Report — Q&A';
require_once 'includes/header.php';
?>

<style>
#qna-wrap     { max-width: 820px; }
#qna-form     { display: flex; gap: .5rem; margin-bottom: 1.25rem; }
#qna-input    { flex: 1; font-size: .95rem; }

/* ── Turn bubbles ─────────────────────────────────────────────────── */
.qna-turn     { margin-bottom: 1.5rem; }
.qna-q        { background: var(--primary-color, #2e7d32); color: #fff;
                border-radius: .5rem .5rem .5rem 0;
                padding: .5rem 1rem; font-size: .9rem; display: inline-block;
                max-width: 92%; margin-bottom: .45rem; }
.qna-answer   { background: #f9fafb; border: 1px solid #dee2e6;
                border-radius: 0 .5rem .5rem .5rem;
                padding: .75rem 1rem; font-size: .875rem; }

/* ── Tags ─────────────────────────────────────────────────────────── */
.qna-tag        { display: inline-block; font-size: .72rem; padding: 1px 8px;
                  border-radius: 20px; margin-bottom: .35rem; font-weight: 600; }
.tag-harvest    { background: #dcfce7; color: #166534; }
.tag-block      { background: #d1fae5; color: #065f46; }
.tag-division   { background: #dbeafe; color: #1e40af; }
.tag-company    { background: #fef9c3; color: #854d0e; }
.tag-mill       { background: #f3e8ff; color: #6d28d9; }
.tag-notfound   { background: #fee2e2; color: #991b1b; }
.tag-unknown    { background: #f1f5f9; color: #475569; }

/* ── Chips & grid ─────────────────────────────────────────────────── */
.qna-grid     { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .4rem; }
.qna-chip     { background: #e2e8f0; border-radius: .3rem; padding: 3px 10px;
                font-size: .8rem; color: #334155; }
.qna-chip-div { background: #dcfce7; color: #166534; }

/* ── Stat boxes ───────────────────────────────────────────────────── */
.qna-stat       { text-align: center; min-width: 90px; }
.qna-stat-val   { font-size: 1.35rem; font-weight: 700; color: var(--primary-color, #2e7d32); }
.qna-stat-lbl   { font-size: .72rem; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
.qna-count-badge{ font-size: 2rem; font-weight: 700; color: var(--primary-color, #2e7d32); }

/* ── Suggestion buttons ───────────────────────────────────────────── */
.qna-suggestion { cursor: pointer; text-decoration: underline; color: #1d4ed8;
                  background: none; border: none; padding: 0; font-size: .875rem; }
.qna-suggestion:hover { color: #1e40af; }

/* ── Example pills ────────────────────────────────────────────────── */
.examples-wrap { margin-bottom: 1.1rem; }
.examples-bar  { display: flex; align-items: center; gap: .5rem; margin-bottom: .4rem; }
.examples-label{ font-size: .75rem; color: #94a3b8; font-weight: 600; letter-spacing: .3px; text-transform: uppercase; }
.examples      { display: flex; flex-wrap: wrap; gap: .35rem; }
.ex-pill-wrap  { display: inline-flex; align-items: center; }
.ex-pill       { cursor: pointer; border: 1px solid #cbd5e1; border-radius: 999px 0 0 999px;
                 padding: .2rem .6rem .2rem .85rem; font-size: .78rem; color: #334155;
                 background: #f8fafc; transition: background .15s; border-right: none; }
.ex-pill:hover { background: #e2e8f0; }
.ex-pill-del   { cursor: pointer; border: 1px solid #cbd5e1; border-radius: 0 999px 999px 0;
                 padding: .2rem .5rem; font-size: .7rem; color: #94a3b8; background: #f8fafc;
                 transition: color .15s, background .15s; border-left: none; line-height: 1; }
.ex-pill-del:hover { color: #dc2626; background: #fee2e2; border-color: #fca5a5; }
.btn-examples-reset { font-size: .72rem; color: #94a3b8; background: none; border: none;
                      cursor: pointer; padding: 0; text-decoration: underline; }
.btn-examples-reset:hover { color: #334155; }

/* ── Empty state ──────────────────────────────────────────────────── */
.qna-empty    { text-align: center; padding: 2.5rem 1rem; color: #94a3b8; }
.qna-empty p  { margin-bottom: .4rem; }

/* ── Delete / pin controls ────────────────────────────────────────── */
.qna-turn-header { display: flex; justify-content: flex-start; align-items: flex-start; gap: .3rem; }
.qna-turn-header .qna-q { flex: 1; }
.btn-del-turn,
.btn-pin-turn  { flex-shrink: 0; background: none; border: none; padding: 0 .25rem;
                 font-size: .95rem; line-height: 1; cursor: pointer;
                 border-radius: .25rem; transition: color .15s, background .15s;
                 margin-top: .15rem; }
.btn-del-turn  { color: #94a3b8; }
.btn-del-turn:hover { color: #dc2626; background: #fee2e2; }
.btn-pin-turn  { color: #94a3b8; }
.btn-pin-turn.pinned   { color: #d97706; }
.btn-pin-turn:hover    { color: #d97706; background: #fef3c7; }
.qna-turn.pinned-turn  { border-left: 3px solid #f59e0b; padding-left: .6rem; }

/* ── Pinned questions bar ─────────────────────────────────────────── */
#qna-pinned-bar        { display:none; margin-bottom:.9rem; }
.pinned-bar-label      { font-size:.72rem; color:#d97706; font-weight:700;
                         letter-spacing:.3px; text-transform:uppercase;
                         display:flex; align-items:center; gap:.35rem; margin-bottom:.35rem; }
.pinned-pill           { display:inline-flex; align-items:center; gap:0;
                         border-radius:999px; overflow:hidden; margin:.15rem; }
.pinned-pill-text      { cursor:pointer; background:#fef3c7; color:#92400e;
                         border:1px solid #fcd34d; border-right:none;
                         border-radius:999px 0 0 999px;
                         padding:.22rem .75rem .22rem .9rem;
                         font-size:.78rem; font-weight:600;
                         transition:background .15s; white-space:nowrap; }
.pinned-pill-text:hover{ background:#fde68a; }
.pinned-pill-unpin     { cursor:pointer; background:#fef3c7; color:#b45309;
                         border:1px solid #fcd34d; border-left:none;
                         border-radius:0 999px 999px 0;
                         padding:.22rem .5rem; font-size:.65rem; line-height:1;
                         transition:color .15s, background .15s; }
.pinned-pill-unpin:hover{ background:#fee2e2; color:#dc2626; border-color:#fca5a5; }

/* ── AI config panel ──────────────────────────────────────────────── */
#ai-panel     { display:none; margin-bottom:1rem; }
#ai-panel.open{ display:block; }
.ai-status-on  { color:#0369a1; font-size:.75rem; font-weight:600; }
.ai-status-off { color:#94a3b8; font-size:.75rem; }

/* ── Suggested-questions panel ────────────────────────────────────── */
#qna-suggested-panel {
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: .5rem;
    padding: .85rem 1rem;
    margin-bottom: 1.1rem;
}
.sqp-label {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: #166534;
    margin-bottom: .6rem;
    display: flex;
    align-items: center;
    gap: .4rem;
}
.sqp-list { display: flex; flex-wrap: wrap; gap: .45rem; }
.sqp-btn {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .35rem 1rem;
    font-size: .83rem;
    font-weight: 500;
    color: #166534;
    background: #fff;
    border: 1px solid #86efac;
    border-radius: 999px;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
    transition: background .15s, box-shadow .15s, color .15s;
    white-space: nowrap;
}
.sqp-btn:hover  { background: #dcfce7; box-shadow: 0 2px 6px rgba(0,100,0,.12); color: #14532d; }
.sqp-btn:active { background: #bbf7d0; }
</style>

<div id="qna-wrap">

    <div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-2">
        <div>
            <?php
            $_h1_icon  = (!empty($_analisis_key) && isset($_analisis_icon_map[$_analisis_key]))
                         ? $_analisis_icon_map[$_analisis_key]
                         : 'bi bi-chat-dots';
            $_h1_title = (!empty($_analisis_key) && isset($_analisis_map[$_analisis_key]))
                         ? htmlspecialchars($_analisis_map[$_analisis_key])
                         : $_t['default_h1'];
            ?>
            <h1><i class="<?= $_h1_icon ?>"></i> <?= $_h1_title ?></h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($_t['subtitle']) ?></p>
        </div>
        <div class="d-flex align-items-center gap-2 mt-1">
            <?php if (agro_ai_available()): ?>
                <span class="ai-status-on"><i class="bi bi-stars"></i> <?= htmlspecialchars($_t['ai_on']) ?> &mdash; <?= htmlspecialchars(agro_ai_model()) ?></span>
            <?php else: ?>
                <span class="ai-status-off"><i class="bi bi-stars"></i> <?= htmlspecialchars($_t['ai_off']) ?></span>
            <?php endif; ?>
            <!-- Language toggle -->
            <a href="qna.php?<?= $_analisis_key ? 'analisis=' . urlencode($_analisis_key) . '&' : '' ?>lang=<?= $_t['lang_switch'] ?>"
               class="btn btn-outline-secondary btn-sm" title="Switch language">
                <?= $_t['lang_btn'] ?>
            </a>
            <form method="post" action="<?= htmlspecialchars($_qna_self, ENT_QUOTES) ?>" style="display:inline">
                <input type="hidden" name="qna_clear" value="1">
                <input type="hidden" name="_analisis_key" value="<?= htmlspecialchars($_analisis_key, ENT_QUOTES) ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm"
                        title="<?= htmlspecialchars($_t['clear_title']) ?>"
                        onclick="return confirm('<?= htmlspecialchars($_t['clear_confirm'], ENT_QUOTES) ?>')">
                    <i class="bi bi-trash"></i> <?= htmlspecialchars($_t['clear_btn']) ?>
                </button>
            </form>
            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="toggleAiPanel()" title="<?= htmlspecialchars($_t['ai_cfg_title']) ?>">
                <i class="bi bi-gear"></i>
            </button>
        </div>
    </div>

    <!-- AI config panel -->
    <div id="ai-panel" class="card mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background:#f0f9ff;border-color:#bae6fd">
            <span style="font-weight:600;color:#0369a1"><i class="bi bi-stars"></i> <?= htmlspecialchars($_t['ai_cfg_title']) ?></span>
            <button type="button" class="btn-close btn-sm" onclick="toggleAiPanel()"></button>
        </div>
        <div class="card-body py-2">
            <form method="post" action="<?= htmlspecialchars($_qna_self, ENT_QUOTES) ?>" class="row g-2 align-items-end">
                <input type="hidden" name="agro_ai_save" value="1">
                <input type="hidden" name="_analisis_key" value="<?= htmlspecialchars($_analisis_key, ENT_QUOTES) ?>">
                <div class="col-md-4">
                    <label class="form-label mb-1 small fw-semibold">API Key</label>
                    <input type="password" name="agro_ai_key" class="form-control form-control-sm"
                           placeholder="sk-… or gsk_…"
                           value="<?= htmlspecialchars($_SESSION['agro_ai_key'] ?? '', ENT_QUOTES) ?>">
                    <div class="form-text" style="font-size:.72rem">
                        Groq (free): <a href="https://console.groq.com/keys" target="_blank">console.groq.com/keys</a>
                        &nbsp;|&nbsp; OpenAI: <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1 small fw-semibold">Endpoint</label>
                    <input type="text" name="agro_ai_endpoint" class="form-control form-control-sm"
                           placeholder="https://api.groq.com/openai/v1"
                           value="<?= htmlspecialchars($_SESSION['agro_ai_endpoint'] ?? agro_ai_endpoint(), ENT_QUOTES) ?>">
                    <div class="form-text" style="font-size:.72rem">
                        Groq · OpenAI · Together · Ollama (http://localhost:11434/v1)
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1 small fw-semibold">Model</label>
                    <input type="text" name="agro_ai_model" class="form-control form-control-sm"
                           placeholder="llama-3.3-70b-versatile"
                           value="<?= htmlspecialchars($_SESSION['agro_ai_model'] ?? agro_ai_model(), ENT_QUOTES) ?>">
                    <div class="form-text" style="font-size:.72rem">
                        Groq: llama-3.3-70b-versatile · OpenAI: gpt-4o-mini
                    </div>
                </div>
                <div class="col-md-1 text-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><?= htmlspecialchars($_t['ai_save_btn']) ?></button>
                </div>
            </form>
        </div>
    </div>


    <!-- Suggested-questions panel (shown when ?analisis=X is present) -->
    <?php if (!empty($_analisis_topic_questions)): ?>
    <div id="qna-suggested-panel">
        <div class="sqp-label">
            <i class="bi bi-lightbulb-fill" style="color:#16a34a"></i>
            <?= htmlspecialchars($_t['suggest_label']) ?>
        </div>
        <div class="sqp-list">
            <?php foreach ($_analisis_topic_questions as $sq): ?>
            <button type="button" class="sqp-btn"
                    data-q="<?= htmlspecialchars($sq['q'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-search"></i>
                <?= htmlspecialchars($sq['label'], ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Example pills -->
    <div class="examples-wrap">
    </div>

    <!-- Pinned questions bar (rendered by JS from localStorage) -->
    <div id="qna-pinned-bar">
        <div class="pinned-bar-label"><?= htmlspecialchars($_t['pinned_label']) ?></div>
        <div id="qna-pinned-pills"></div>
    </div>

    <!-- Question form -->
    <form method="post" action="<?= htmlspecialchars($_qna_self, ENT_QUOTES) ?>" id="qna-form">
        <input type="hidden" name="_analisis_key" value="<?= htmlspecialchars($_analisis_key, ENT_QUOTES) ?>">
        <input type="text" id="qna-input" name="q" class="form-control"
               placeholder="<?= htmlspecialchars($_t['input_placeholder']) ?>"
               value="<?= htmlspecialchars($question, ENT_QUOTES, 'UTF-8') ?>"
               data-auto-q="<?= htmlspecialchars($qna_auto_question, ENT_QUOTES, 'UTF-8') ?>"
               autocomplete="off" autofocus>
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-search"></i> <?= htmlspecialchars($_t['ask_btn']) ?>
        </button>
    </form>

    <!-- History -->
    <?php if (empty($history)): ?>
        <div class="qna-empty">
            <p><?= htmlspecialchars($_t['empty_line1']) ?></p>
            <span class="text-muted small"><?= htmlspecialchars($_t['empty_line2']) ?></span>
        </div>
    <?php else: ?>
        <?php foreach ($history as $idx => $turn): ?>
            <div class="qna-turn" data-idx="<?= $idx ?>">
                <div class="qna-turn-header">
                    <span class="qna-q"><?= htmlspecialchars((string)$turn['q'], ENT_QUOTES, 'UTF-8') ?></span>
                    <button type="button" class="btn-pin-turn" data-idx="<?= $idx ?>"
                            title="<?= htmlspecialchars($_t['pin_title']) ?>">&#128204;</button>
                    <form method="post" action="<?= htmlspecialchars($_qna_self, ENT_QUOTES) ?>" style="display:inline" class="form-del-turn">
                        <input type="hidden" name="qna_delete_idx" value="<?= $idx ?>">
                        <input type="hidden" name="_analisis_key" value="<?= htmlspecialchars($_analisis_key, ENT_QUOTES) ?>">
                        <button type="submit" class="btn-del-turn" title="<?= htmlspecialchars($_t['del_title']) ?>">&#x2715;</button>
                    </form>
                </div>
                <div class="qna-answer">
                    <?= agro_render_answer((array)$turn['answer']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<script>
// ── bfcache guard: if page is restored from cache, force a fresh reload ───
window.addEventListener('pageshow', function(e) {
    if (e.persisted) { location.reload(); }
});

// ── Analysis shortcut auto-submit ─────────────────────────────────────────
// When the page is loaded with ?analisis=X, pre-fill the input and submit.
(function() {
    var inp = document.getElementById('qna-input');
    if (!inp) return;
    var autoQ = inp.getAttribute('data-auto-q') || '';
    if (autoQ === '') return;
    // Only auto-submit on a fresh GET load (not after a POST result)
    var isGet = (window.performance && window.performance.getEntriesByType('navigation').length > 0)
        ? window.performance.getEntriesByType('navigation')[0].type !== 'reload'
        : true;
    if (document.querySelectorAll('.qna-turn').length === 0 && isGet) {
        inp.value = autoQ;
        document.getElementById('qna-form').submit();
    }
})();

// ── Pins helper ───────────────────────────────────────────────────────────
var QNA_LS_PINS = 'qna_pinned_turns_v2';   // v2 = invalidates all pre-refactor data

// On first load with v2 key, wipe any leftover legacy keys
(function() {
    var legacy = ['qna_pinned_turns'];
    legacy.forEach(function(k) { localStorage.removeItem(k); });
})();

function qnaGetPins() {
    try { return JSON.parse(localStorage.getItem(QNA_LS_PINS) || '[]'); }
    catch(e) { return []; }
}
function qnaSavePins(arr) { localStorage.setItem(QNA_LS_PINS, JSON.stringify(arr)); }

// ── On page load: sync pins hidden field (separate from history) ──────────
(function() {
    var pf = document.getElementById('qna-pins-field');
    if (pf) pf.value = JSON.stringify(qnaGetPins());
    var apf = document.getElementById('ai-pins-field');
    if (apf) apf.value = JSON.stringify(qnaGetPins());
})();

// ── Pin buttons ───────────────────────────────────────────────────────────
(function() {
    // Pins are stored by question text so they survive history reorder/re-index.
    // Structure: array of question strings.
    function getPinnedTexts() {
        try { return JSON.parse(localStorage.getItem('qna_pinned_texts') || '[]'); }
        catch(e) { return []; }
    }
    function savePinnedTexts(arr) {
        try { localStorage.setItem('qna_pinned_texts', JSON.stringify(arr)); } catch(e) {}
    }

    // ── Render the pinned-questions bar above the form ────────────────────────
    function renderPinnedBar() {
        var bar       = document.getElementById('qna-pinned-bar');
        var container = document.getElementById('qna-pinned-pills');
        var input     = document.getElementById('qna-input');
        var form      = document.getElementById('qna-form');
        if (!bar || !container) return;

        var texts = getPinnedTexts();
        container.innerHTML = '';

        if (texts.length === 0) {
            bar.style.display = 'none';
            return;
        }
        bar.style.display = 'block';

        texts.forEach(function(txt) {
            var pill = document.createElement('span');
            pill.className = 'pinned-pill';

            // Clickable text — fills input only (user can edit before submitting)
            var span = document.createElement('button');
            span.type = 'button';
            span.className = 'pinned-pill-text';
            span.title = 'Isi pertanyaan: ' + txt;
            span.textContent = txt;
            span.addEventListener('click', function() {
                if (input) {
                    input.value = txt;
                    input.focus();
                    // Move cursor to end
                    var len = input.value.length;
                    input.setSelectionRange(len, len);
                }
            });

            // Unpin button (×)
            var unpin = document.createElement('button');
            unpin.type = 'button';
            unpin.className = 'pinned-pill-unpin';
            unpin.title = 'Hapus dari daftar tersimpan';
            unpin.innerHTML = '&#x2715;';
            unpin.addEventListener('click', function(e) {
                e.stopPropagation();
                var arr = getPinnedTexts();
                var i   = arr.indexOf(txt);
                if (i !== -1) arr.splice(i, 1);
                savePinnedTexts(arr);
                // Also unpin the matching turn in the DOM
                document.querySelectorAll('.qna-turn').forEach(function(turn) {
                    var tq = turn.querySelector('.qna-q');
                    if (tq && tq.textContent.trim() === txt) {
                        turn.classList.remove('pinned-turn');
                        var pb = turn.querySelector('.btn-pin-turn');
                        var df = turn.querySelector('.form-del-turn');
                        var db = turn.querySelector('.btn-del-turn');
                        if (pb) { pb.classList.remove('pinned'); pb.title = 'Pin — pertanyaan ini tidak akan terhapus'; }
                        if (df) df.removeEventListener('submit', blockSubmit);
                        if (db) { db.disabled = false; db.title = 'Hapus pertanyaan ini'; db.style.opacity = ''; }
                    }
                });
                renderPinnedBar();
            });

            pill.appendChild(span);
            pill.appendChild(unpin);
            container.appendChild(pill);
        });
    }

    // Apply pinned state + block delete for pinned turns on load
    var pinnedTexts = getPinnedTexts();
    document.querySelectorAll('.qna-turn').forEach(function(turn) {
        var qText = turn.querySelector('.qna-q') ? turn.querySelector('.qna-q').textContent.trim() : '';
        var pinBtn = turn.querySelector('.btn-pin-turn');
        var delForm = turn.querySelector('.form-del-turn');
        var delBtn  = turn.querySelector('.btn-del-turn');

        if (pinnedTexts.indexOf(qText) !== -1) {
            turn.classList.add('pinned-turn');
            if (pinBtn) { pinBtn.classList.add('pinned'); pinBtn.title = 'Unpin pertanyaan ini'; }
            // Block delete on pinned turns
            if (delForm) delForm.addEventListener('submit', function(e) { e.preventDefault(); });
            if (delBtn)  { delBtn.disabled = true; delBtn.title = 'Tidak bisa dihapus (di-pin)'; delBtn.style.opacity = '.3'; }
        }

        if (!pinBtn) return;
        pinBtn.addEventListener('click', function() {
            var texts = getPinnedTexts();
            var idx   = texts.indexOf(qText);
            if (idx === -1) {
                // Pin it
                texts.push(qText);
                savePinnedTexts(texts);
                turn.classList.add('pinned-turn');
                pinBtn.classList.add('pinned');
                pinBtn.title = 'Unpin pertanyaan ini';
                if (delForm) delForm.addEventListener('submit', blockSubmit);
                if (delBtn)  { delBtn.disabled = true; delBtn.title = 'Tidak bisa dihapus (di-pin)'; delBtn.style.opacity = '.3'; }
            } else {
                // Unpin it
                texts.splice(idx, 1);
                savePinnedTexts(texts);
                turn.classList.remove('pinned-turn');
                pinBtn.classList.remove('pinned');
                pinBtn.title = 'Pin — pertanyaan ini tidak akan terhapus';
                if (delForm) delForm.removeEventListener('submit', blockSubmit);
                if (delBtn)  { delBtn.disabled = false; delBtn.title = 'Hapus pertanyaan ini'; delBtn.style.opacity = ''; }
            }
            renderPinnedBar(); // refresh bar after every toggle
        });
    });

    // Render bar on page load
    renderPinnedBar();

    function blockSubmit(e) { e.preventDefault(); }

    // "Hapus Semua" — only delete unpinned turns
    var clearForm = document.querySelector('form input[name="qna_clear"]');
    if (clearForm) {
        clearForm.closest('form').addEventListener('submit', function(e) {
            var pinnedTexts = getPinnedTexts();
            if (pinnedTexts.length > 0) {
                e.preventDefault();
                if (confirm('Ada ' + pinnedTexts.length + ' pertanyaan yang di-pin dan tidak akan dihapus. Hapus yang lainnya?')) {
                    // Remove pins that no longer exist after clear, but keep the list
                    // so pinned turns (which survive) still show as pinned after reload.
                    // We just submit with a flag to delete only unpinned.
                    // Simplest: store pinned texts in hidden field and let PHP skip them.
                    var inp = document.createElement('input');
                    inp.type = 'hidden'; inp.name = 'qna_keep_pinned';
                    inp.value = JSON.stringify(pinnedTexts);
                    this.appendChild(inp);
                    this.submit();
                }
            }
        });
    }
})();

// ── Example pills: delete & restore via localStorage ─────────────────────
(function() {
    var LS_KEY = 'qna_hidden_pills';
    var container = document.getElementById('qna-examples');
    var resetBtn  = document.getElementById('btn-examples-reset');
    if (!container) return;

    // Read set of hidden pill texts from localStorage
    function getHidden() {
        try { return new Set(JSON.parse(localStorage.getItem(LS_KEY) || '[]')); }
        catch(e) { return new Set(); }
    }
    function saveHidden(set) {
        try { localStorage.setItem(LS_KEY, JSON.stringify([...set])); } catch(e) {}
    }

    // Wrap every plain <span class="ex-pill"> into pill-wrap + add delete btn
    container.querySelectorAll('span.ex-pill').forEach(function(pill) {
        var text = pill.textContent.trim();

        // Wrap
        var wrap = document.createElement('span');
        wrap.className = 'ex-pill-wrap';

        // Delete button
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'ex-pill-del';
        del.title = 'Sembunyikan contoh ini';
        del.innerHTML = '&#x2715;';
        del.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();
            wrap.remove();
            var hidden = getHidden();
            hidden.add(text);
            saveHidden(hidden);
            updateResetBtn();
        });

        pill.parentNode.insertBefore(wrap, pill);
        wrap.appendChild(pill);
        wrap.appendChild(del);
    });

    // Apply saved hidden state on load
    var hidden = getHidden();
    if (hidden.size > 0) {
        container.querySelectorAll('span.ex-pill').forEach(function(pill) {
            if (hidden.has(pill.textContent.trim())) {
                pill.closest('.ex-pill-wrap').remove();
            }
        });
    }

    function updateResetBtn() {
        if (!resetBtn) return;
        resetBtn.style.display = getHidden().size > 0 ? 'inline' : 'none';
    }
    updateResetBtn();

    window.qnaResetExamples = function() {
        localStorage.removeItem(LS_KEY);
        location.reload();
    };
})();

// Example pill → fill input
document.querySelectorAll('.ex-pill').forEach(function(pill) {
    pill.addEventListener('click', function() {
        document.getElementById('qna-input').value = pill.textContent.trim();
        document.getElementById('qna-input').focus();
    });
});
// "Did you mean" suggestion → fill + submit
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('qna-suggestion')) {
        document.getElementById('qna-input').value = e.target.dataset.q;
        document.getElementById('qna-form').submit();
    }
});
// AI config panel toggle
function toggleAiPanel() {
    var panel = document.getElementById('ai-panel');
    if (panel) panel.classList.toggle('open');
}

// ── Suggested-questions panel: clicking a question fills input & submits ──
document.querySelectorAll('.sqp-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var q = btn.getAttribute('data-q');
        if (!q) return;
        var inp  = document.getElementById('qna-input');
        var form = document.getElementById('qna-form');
        if (inp && form) {
            inp.value = q;
            form.submit();
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
