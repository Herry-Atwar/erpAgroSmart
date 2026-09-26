<?php
/**
 * GAPKI / PPKS / SNI Standards Reference Page
 * Displays the full standards library, filterable by category.
 */
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/lang.php';       // defines __() — must load before using it
require_once 'config/standards.php';

$db = getDB();   // required by auth.php refresh_session() via global $db

$page_title = __('pt_standards');
require_once 'includes/header.php';

$filterCat = trim((string)($_GET['cat'] ?? ''));
$cats      = agro_std_categories();

$catLabels = [
    'agrochemical'   => '🧪 Agro Chemical Usage',
    'fertilization'  => '🌾 Fertilizer Usage',
    'finance'        => '💰 Keuangan',
    'infrastructure' => '🛣️ Infrastruktur',
    'mill'           => '⚙️ Pabrik',
    'nursery'        => '🌱 Pembibitan',
    'pest_disease'   => '🐛 Hama & Penyakit',
    'plantation'     => '🌿 Perkebunan',
    'sustainability' => '♻️ Keberlanjutan',
    'weed_control'   => '🌿 Gulma',
];

// active category list
$activeCats = ($filterCat !== '' && in_array($filterCat, $cats, true))
    ? [$filterCat]
    : $cats;
?>

<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-award"></i> Standar GAPKI / PPKS / SNI</h1>
        <p class="text-muted mb-0">
            Referensi benchmark teknis perkebunan kelapa sawit Indonesia &mdash;
            GAPKI · PPKS Medan · SNI 8171:2015 · SNI 7182:2015 · Permentan RI · RSPO P&amp;C 2018 · ISPO 2020
        </p>
    </div>
    <div>
        <a href="qna.php" class="btn btn-outline-success btn-sm">
            <i class="bi bi-chat-dots"></i> Buka Q&amp;A
        </a>
    </div>
</div>

<!-- Category filter tabs -->
<div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
    <span class="text-muted small fw-semibold me-1">Filter:</span>
    <a href="standards.php"
       class="btn btn-sm <?= $filterCat === '' ? 'btn-success' : 'btn-outline-success' ?>">
        Semua <span class="badge <?= $filterCat === '' ? 'bg-white text-success' : 'bg-success' ?> ms-1"><?= count(AGRO_STANDARDS) ?></span>
    </a>
    <?php foreach ($cats as $cat): ?>
        <?php $cnt = count(agro_std_by_category($cat)); ?>
        <a href="standards.php?cat=<?= urlencode($cat) ?>"
           class="btn btn-sm <?= $filterCat === $cat ? 'btn-success' : 'btn-outline-secondary' ?>">
            <?= htmlspecialchars($catLabels[$cat] ?? ucfirst($cat)) ?>
            <span class="badge <?= $filterCat === $cat ? 'bg-white text-success' : 'bg-secondary' ?> ms-1"><?= $cnt ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- Summary cards -->
<?php if ($filterCat === ''): ?>
<div class="row row-cols-2 row-cols-md-5 g-2 mb-3">
    <?php foreach ($cats as $cat): ?>
        <?php $cnt = count(agro_std_by_category($cat)); ?>
        <div class="col">
            <a href="standards.php?cat=<?= urlencode($cat) ?>" class="text-decoration-none">
                <div class="card text-center p-2 h-100 border-0 shadow-sm" style="background:#f8fffe">
                    <div style="font-size:1.6rem"><?= explode(' ', $catLabels[$cat] ?? '')[0] ?></div>
                    <div class="fw-semibold small text-dark mt-1">
                        <?= htmlspecialchars(implode(' ', array_slice(explode(' ', $catLabels[$cat] ?? ucfirst($cat)), 1))) ?>
                    </div>
                    <div class="text-muted" style="font-size:.75rem"><?= $cnt ?> parameter</div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Standards tables per category -->
<?php foreach ($activeCats as $cat):
    $catStds = agro_std_by_category($cat);
    if (empty($catStds)) continue;
?>
<div class="card mb-3 border-0 shadow-sm">
    <div class="card-header py-2" style="background:#f0fdf4;border-bottom:1px solid #bbf7d0">
        <strong><?= htmlspecialchars($catLabels[$cat] ?? ucfirst($cat)) ?></strong>
        <span class="badge bg-success ms-2"><?= count($catStds) ?> parameter</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <thead class="table-light">
                    <tr>
                        <th style="width:22%">Parameter</th>
                        <th class="text-center" style="width:7%">Satuan</th>
                        <th class="text-center" style="width:8%">Standar ✅</th>
                        <th class="text-center" style="width:8%">Batas ⚠️</th>
                        <th style="width:30%">Deskripsi</th>
                        <th style="width:10%">Sumber</th>
                        <th style="width:15%">Keterangan Lulus</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($catStds as $std): ?>
                        <?php
                        $warnRange = '';
                        if ($std['warn_min'] !== null && $std['warn_max'] !== null) {
                            $warnRange = number_format($std['warn_min'], 0) . '–' . number_format($std['warn_max'], 0);
                        } elseif ($std['warn_min'] !== null) {
                            $warnRange = '≥' . number_format($std['warn_min'], 0);
                        } elseif ($std['warn_max'] !== null) {
                            $warnRange = '<' . number_format($std['warn_max'], 0);
                        }
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($std['param']) ?></td>
                            <td class="text-center text-muted"><?= htmlspecialchars($std['unit']) ?></td>
                            <td class="text-center fw-bold" style="color:#1e40af"><?= htmlspecialchars($std['display']) ?></td>
                            <td class="text-center text-muted"><?= $warnRange !== '' ? htmlspecialchars($warnRange) : '—' ?></td>
                            <td class="small"><?= htmlspecialchars($std['description']) ?></td>
                            <td class="small text-muted">
                                <?= htmlspecialchars($std['source']) ?>
                                <br><span class="badge bg-light text-secondary border"><?= htmlspecialchars($std['source_year']) ?></span>
                            </td>
                            <td class="small text-success"><?= htmlspecialchars($std['pass_note'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Footer note -->
<div class="card border-0 bg-light mb-3">
    <div class="card-body py-2 px-3 small text-muted">
        <strong>Total <?= count(AGRO_STANDARDS) ?> parameter standar</strong> dalam <?= count($cats) ?> kategori. &nbsp;|&nbsp;
        Standar ini digunakan secara otomatis oleh fitur
        <a href="qna.php">Q&amp;A</a> ketika Anda mengetik <strong>"Apakah sesuai standar?"</strong>
        setelah menampilkan tabel kerapatan tanaman, luas area, data pabrik, atau jalan perkebunan.
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
