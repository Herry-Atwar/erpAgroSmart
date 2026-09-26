<?php
/**
 * Semantic Fallback Resolver for Agro Q&A
 *
 * Called when the 38 deterministic regex rules in agro_resolve() all fail
 * (type === 'unknown').  Uses keyword + entity matching to map free-form
 * questions to existing agro_resolve() routes — no new SQL queries.
 *
 * Entry point: agro_qsf_resolve(PDO $db, string $q, array $history): array
 */

/**
 * Attempt to resolve an unrecognised question via semantic fallback.
 *
 * Strategy (in priority order):
 *  1. Detect the primary domain keyword (panen, luas, jalan, jembatan, …)
 *  2. Extract a scope name (company / BU / division)
 *  3. Route to the closest agro_resolve() call and return its result
 *  4. If no domain matches, return a friendly "not_found" with did-you-mean
 *
 * @param PDO    $db
 * @param string $q       raw question text (already confirmed as unknown)
 * @param array  $history current session history (for context reuse)
 * @return array          same shape as agro_resolve() return values
 */
function agro_qsf_resolve(PDO $db, string $q, array $history = []): array
{
    $norm = mb_strtolower(trim($q));

    // ── 1. Helper: strip common Indonesian stop-words / filler ───────────────
    $stopWords = [
        'tampilkan','tampil','lihat','show','display','buat','buatkan','berikan',
        'data','informasi','info','laporan','rekap','rekapitulasi','ringkasan',
        'detail','lengkap','semua','seluruh','apa','saja','yang','ada','di','in',
        'pada','untuk','dari','dan','atau','dengan','ke','nya','lah','kah',
        'bagaimana','berapa','total','jumlah','summary','report','statement',
        'the','of','for','and','or','a','an','is','are','its',
    ];

    // ── 2. Extract scope (try to peel off a scope name at the end) ───────────
    // Remove domain/stop words and see if something remains as a scope name.
    $scopeGuess = _qsf_extract_scope($norm, $stopWords);

    // ── 3. Domain routing ─────────────────────────────────────────────────────

    // Harvest
    if (preg_match('/\b(?:panen|harvest|ffb|tbs|hasil\s+panen|realisasi\s+panen)\b/ui', $norm)) {
        if ($scopeGuess !== '') {
            return agro_harvest_summary($db, $q, $scopeGuess);
        }
        return agro_harvest_summary($db, $q, '');
    }

    // Area / luas
    if (preg_match('/\b(?:luas|area|hektar|ha)\b/ui', $norm)) {
        if (preg_match('/\b(?:divisi|afdeling|division)\b/ui', $norm)) {
            return agro_area_by_division($db, $scopeGuess, $q);
        }
        return agro_area($db, $scopeGuess, $q);
    }

    // Roads
    if (preg_match('/\b(?:jalan|road|roads)\b/ui', $norm)) {
        return agro_road_by_type($db, $scopeGuess, $q);
    }

    // Bridges / culverts
    if (preg_match('/\b(?:jembatan|bridge|bridges|culvert|gorong)\b/ui', $norm)) {
        return agro_bridge_count($db, $scopeGuess, $q);
    }

    // Infrastructure (catch-all road+bridge)
    if (preg_match('/\b(?:infrastruktur|infrastructure)\b/ui', $norm)) {
        return agro_infrastructure_summary($db, $q, $scopeGuess);
    }

    // Plant density
    if (preg_match('/\b(?:kerapatan|density|populasi|population|spjp|spp)\b/ui', $norm)) {
        if ($scopeGuess !== '') {
            return agro_plant_density($db, $scopeGuess, $q);
        }
    }

    // Nursery / pembibitan
    if (preg_match('/\b(?:pembibitan|nursery|bibit|persemaian|kecambah)\b/ui', $norm)) {
        return agro_nursery_summary($db, $q, $scopeGuess);
    }

    // Seeds / varieties
    if (preg_match('/\b(?:varietas|variety|varieties|benih|seed)\b/ui', $norm)) {
        if ($scopeGuess !== '') {
            return agro_seed_varieties($db, $scopeGuess, $q);
        }
    }

    // Fertilizer / pemupukan
    if (preg_match('/\b(?:pupuk|pemupukan|fertiliz|fertilizer)\b/ui', $norm)) {
        return agro_fertilization_used($db, $scopeGuess, $q);
    }

    // Weed / gulma
    if (preg_match('/\b(?:gulma|weed|herbisida|herbicide)\b/ui', $norm)) {
        return agro_weed_analysis($db, $scopeGuess, $q);
    }

    // Pest & disease
    if (preg_match('/\b(?:hama|pest|penyakit|disease|opt)\b/ui', $norm)) {
        return agro_pest_analysis($db, $scopeGuess, $q);
    }

    // Chemicals
    if (preg_match('/\b(?:kimia|pestisida|pesticide|fungisida|insektisida|chemical)\b/ui', $norm)) {
        return agro_chemicals_used($db, $scopeGuess, $q);
    }

    // Mill / production / CPO / kernel
    if (preg_match('/\b(?:pabrik|mill|produksi|production|cpo|kernel|rendemen|oer|ker)\b/ui', $norm)) {
        [, $df, $dt, $dl] = agro_extract_date_filter($norm);
        return agro_rendemen($db, $q, $df, $dt, $dl);
    }

    // Stock
    if (preg_match('/\b(?:stok|stock|persediaan|inventory)\b/ui', $norm)) {
        return agro_cpo_stock($db, $q);
    }

    // Financial
    if (preg_match('/\b(?:keuangan|finansial|financial|finance|pendapatan|revenue|laba|profit|loss|income|neraca|balance\s+sheet|anggaran|budget)\b/ui', $norm)) {
        return agro_financial_summary($db, $q, $scopeGuess);
    }

    // Sustainability / ISPO / RSPO
    if (preg_match('/\b(?:keberlanjutan|sustainability|ispo|rspo|lingkungan|konservasi|hcv|karbon|carbon)\b/ui', $norm)) {
        return agro_sustainability_analysis($db, $scopeGuess, $q);
    }

    // Plantation / perkebunan general
    if (preg_match('/\b(?:perkebunan|plantation|kebun)\b/ui', $norm)) {
        return agro_plantation_analysis($db, $scopeGuess, $q);
    }

    // Block lookup
    if (preg_match('/\b(?:blok|block|blk)\b/ui', $norm)) {
        $block = _qsf_extract_block_code($norm);
        if ($block !== '') {
            return agro_find_block($db, $block, $q);
        }
    }

    // Division / afdeling
    if (preg_match('/\b(?:divisi|afdeling|division)\b/ui', $norm)) {
        if ($scopeGuess !== '') {
            return agro_divisions_in_bu($db, $scopeGuess, $q);
        }
    }

    // Business unit / estate
    if (preg_match('/\b(?:estate|kebun|business\s+unit)\b/ui', $norm)) {
        if ($scopeGuess !== '') {
            return agro_bus_in_company($db, $scopeGuess, $q);
        }
    }

    // Companies
    if (preg_match('/\b(?:perusahaan|company|companies)\b/ui', $norm)) {
        return agro_companies($db, $q);
    }

    // ── 4. Context-aware fallback: reuse last answer's scope ─────────────────
    // If the question looks like a follow-up (very short, or begins with "bagaimana",
    // "apa", "kenapa", etc.) and there is a recent answer with a scope, try to
    // re-run that same analysis type with the same scope.
    if (!empty($history)) {
        $lastAns  = (array)($history[0]['answer'] ?? []);
        $lastType = (string)($lastAns['type'] ?? '');
        $lastScope = (string)($lastAns['scope'] ?? '');
        if ($lastScope !== '' && !in_array($lastType, ['unknown','not_found','','standards_list'], true)) {
            // Re-dispatch to agro_resolve with the last scope appended
            $augmented = agro_resolve($db, $lastAns['question'] ?? $q);
            if ($augmented['type'] !== 'unknown') {
                return $augmented;
            }
        }
    }

    // ── 5. Hard fallback — did-you-mean on scope ─────────────────────────────
    if ($scopeGuess !== '') {
        return agro_did_you_mean($db, 'company', $scopeGuess, $q);
    }

    return [
        'type'    => 'not_found',
        'question' => $q,
        'entity'   => '',
        'searched' => $norm,
        'suggestions' => [],
        'message'  => 'Pertanyaan tidak dikenali. Coba: "Analisa Infrastruktur", "Panen ANP", "Luas area", "Tabel luas per divisi", "Panjang jalan", "Jumlah jembatan", atau ketik nama estate/divisi secara langsung.',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Internal helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Attempt to extract a scope name (estate / company / division) from
 * a normalised question string by stripping known domain & stop words.
 */
function _qsf_extract_scope(string $norm, array $stopWords): string
{
    // Remove date phrases first so they don't become fake scope names
    $cleaned = preg_replace(
        '/\b(?:tanggal|bulan|tahun|januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember'
        . '|january|february|march|april|may|june|july|august|september|october|november|december'
        . '|jan|feb|mar|apr|jun|jul|aug|sep|oct|nov|dec'
        . ')\s+\d{1,4}\b|\b\d{4}\b|\b\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}\b/ui',
        ' ', $norm
    );

    // Remove domain keywords
    $domainKw = [
        'infrastruktur','infrastructure','jalan','road','roads','jembatan','bridge','bridges',
        'panen','harvest','ffb','tbs','luas','area','hektar','ha',
        'pembibitan','nursery','bibit','benih','seed','varietas','variety',
        'pupuk','pemupukan','fertiliz','fertilizer',
        'gulma','weed','herbisida','herbicide',
        'hama','pest','penyakit','disease','opt','kimia','pestisida','pesticide',
        'fungisida','insektisida','chemical',
        'pabrik','mill','produksi','production','cpo','kernel','rendemen','oer','ker',
        'stok','stock','persediaan','inventory',
        'keuangan','finansial','financial','finance','pendapatan','revenue','laba','profit',
        'loss','income','neraca','balance','sheet','anggaran','budget',
        'keberlanjutan','sustainability','ispo','rspo','lingkungan','konservasi','hcv',
        'perkebunan','plantation','kebun',
        'divisi','afdeling','division','blok','block','estate','perusahaan','company',
        'density','kerapatan','populasi','population','spjp',
        'analisa','analisis','analiz','analyze','analysis',
    ];

    foreach ($domainKw as $kw) {
        $cleaned = preg_replace('/\b' . preg_quote($kw, '/') . '\b/ui', ' ', $cleaned ?? '');
    }

    // Remove stop words
    foreach ($stopWords as $sw) {
        $cleaned = preg_replace('/\b' . preg_quote($sw, '/') . '\b/ui', ' ', $cleaned ?? '');
    }

    // Collapse whitespace
    $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned ?? '') ?? '');

    // Must be at least 2 chars and not purely numeric to count as a scope name
    if (strlen($cleaned) >= 2 && !is_numeric($cleaned)) {
        return $cleaned;
    }

    return '';
}

/**
 * Try to extract a block code from the question
 * (e.g. "BLK-01", "Block A1", "01A", …).
 */
function _qsf_extract_block_code(string $norm): string
{
    if (preg_match('/\b(?:blok|block|blk)\s+([A-Za-z0-9][\w\-\.\/]{0,15})/ui', $norm, $m)) {
        return trim($m[1]);
    }
    // Bare alphanumeric code that looks like a block code
    if (preg_match('/\b([A-Z]\d{1,3}(?:-\d{1,3})?|[A-Z]{2,}\d+)\b/u', strtoupper($norm), $m)) {
        return $m[1];
    }
    return '';
}
