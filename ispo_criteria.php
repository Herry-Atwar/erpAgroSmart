<?php
require_once 'config/database.php';
require_once 'config/standards.php';
require_once 'includes/functions.php';

$db = getDB();

$page_title = "ISPO Criteria";
require_once 'includes/header.php';

// Filters
$principle_filter = (int)get('principle', 0);
$level_filter     = get('level', '');
$search           = get('search', '');

$sql    = "SELECT * FROM ispo_criteria WHERE is_active = TRUE";
$params = [];

if ($principle_filter > 0) {
    $sql     .= " AND principle_no = ?";
    $params[] = $principle_filter;
}
if ($level_filter) {
    $sql     .= " AND level = ?";
    $params[] = $level_filter;
}
if ($search) {
    $sql     .= " AND (title ILIKE ? OR description ILIKE ? OR criteria_no ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY principle_no, criteria_no, COALESCE(indicator_no, '99')";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$criteria = $stmt->fetchAll();

// Summary counts
$total_stmt = $db->query("SELECT COUNT(*) as cnt, level FROM ispo_criteria WHERE is_active = TRUE GROUP BY level");
$counts = [];
foreach ($total_stmt->fetchAll() as $row) {
    $counts[$row['level']] = $row['cnt'];
}
$total_all = array_sum($counts);

// Principle labels
$principles = [
    1 => 'Legal Compliance',
    2 => 'Economic Viability & Best Management Practices',
    3 => 'Environmental Management',
    4 => 'Social Responsibility & Community Empowerment',
    5 => 'Occupational Health, Safety & Environment (K3)',
    6 => 'Transparency',
    7 => 'Empowerment of Smallholders (Plasma/Kemitraan)',
];

// Principle colors for badges
$principle_colors = [
    1 => '#dc2626', // red — legal
    2 => '#2563eb', // blue — economic
    3 => '#16a34a', // green — environmental
    4 => '#9333ea', // purple — social
    5 => '#ea580c', // orange — HSE
    6 => '#0891b2', // teal — transparency
    7 => '#65a30d', // lime — smallholder
];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color: #f59e0b;"><i class="bi bi-patch-check-fill" style="color: #f59e0b;"></i> ISPO Criteria</h1>
            <p class="text-muted">Indonesian Sustainable Palm Oil — PP No.44/2020 &amp; Permentan No.38/2020</p>
        </div>
        <div class="col-auto">
            <a href="ispo_assessment.php" class="btn btn-ispo">
                <i class="bi bi-clipboard2-check"></i> Start Assessment
            </a>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card-ispo">
            <div class="card-body text-center">
                <h2 style="color:#f59e0b;"><?php echo $total_all; ?></h2>
                <p class="mb-0"><i class="bi bi-list-check"></i> Total Items</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card-ispo">
            <div class="card-body text-center">
                <h2 style="color:#2563eb;"><?php echo $counts['criteria'] ?? 0; ?></h2>
                <p class="mb-0"><i class="bi bi-check2-square"></i> Criteria</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card-ispo">
            <div class="card-body text-center">
                <h2 style="color:#16a34a;"><?php echo $counts['indicator'] ?? 0; ?></h2>
                <p class="mb-0"><i class="bi bi-dot"></i> Indicators</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card-ispo">
            <div class="card-body text-center">
                <h2 style="color:#dc2626;"><?php echo $counts['principle'] ?? 0; ?></h2>
                <p class="mb-0"><i class="bi bi-bookmark-star"></i> Principles</p>
            </div>
        </div>
    </div>
</div>

<!-- Principles Quick Navigation -->
<div class="card mb-4">
    <div class="card-header" style="background-color: #f59e0b; color: white;">
        <i class="bi bi-bookmark-star-fill"></i> ISPO 2020 — 7 Principles (PP No.44/2020)
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($principles as $no => $label): ?>
            <div class="col-md-6 mb-2">
                <a href="?principle=<?php echo $no; ?>" class="text-decoration-none">
                    <div class="d-flex align-items-start p-2 rounded" style="border-left: 4px solid <?php echo $principle_colors[$no]; ?>; background:#fafafa;">
                        <span class="badge me-2" style="background:<?php echo $principle_colors[$no]; ?>; min-width:28px;">P<?php echo $no; ?></span>
                        <small><?php echo htmlspecialchars($label); ?></small>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="Search criteria title or number..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="principle">
                    <option value="0">All Principles</option>
                    <?php foreach ($principles as $no => $label): ?>
                        <option value="<?php echo $no; ?>" <?php echo $principle_filter == $no ? 'selected' : ''; ?>>
                            P<?php echo $no; ?> — <?php echo substr($label, 0, 30); ?>...
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="level">
                    <option value="">All Levels</option>
                    <option value="principle" <?php echo $level_filter == 'principle' ? 'selected' : ''; ?>>Principle</option>
                    <option value="criteria"  <?php echo $level_filter == 'criteria'  ? 'selected' : ''; ?>>Criteria</option>
                    <option value="indicator" <?php echo $level_filter == 'indicator' ? 'selected' : ''; ?>>Indicator</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-ispo"><i class="bi bi-search"></i> Filter</button>
                <a href="ispo_criteria.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                <a href="ispo_gap_dashboard.php" class="btn btn-outline-warning ms-2">
                    <i class="bi bi-bar-chart-steps"></i> Gap Dashboard
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Criteria Table -->
<div class="card">
    <div class="card-header" style="background-color: #f59e0b; color: white;">
        <i class="bi bi-list-check"></i> ISPO Criteria &#40;<?php echo count($criteria); ?> items&#41;
    </div>
    <div class="card-body p-0">
        <?php if (empty($criteria)): ?>
            <div class="text-center text-muted p-4">
                <i class="bi bi-database-x" style="font-size:2rem;"></i>
                <p class="mt-2">No criteria found. Have you run <code>database/schema_ispo.sql</code> and <code>database/seed_ispo_criteria.sql</code>?</p>
                <a href="ispo_criteria.php" class="btn btn-secondary">Reset Filters</a>
            </div>
        <?php else: ?>
        <?php
        $current_principle = null;
        foreach ($criteria as $c):
            // Print principle header row when principle changes
            if ($c['level'] === 'principle' && $c['principle_no'] !== $current_principle):
                $current_principle = $c['principle_no'];
                $pcolor = $principle_colors[$current_principle] ?? '#666';
        ?>
            <div class="px-3 pt-3 pb-1">
                <h6 style="color: <?php echo $pcolor; ?>; border-bottom: 2px solid <?php echo $pcolor; ?>; padding-bottom: 4px;">
                    <span class="badge me-2" style="background:<?php echo $pcolor; ?>;">Principle <?php echo $current_principle; ?></span>
                    <?php echo htmlspecialchars($c['title']); ?>
                    <?php if ($c['is_mandatory']): ?>
                        <span class="badge bg-danger ms-1" style="font-size:0.65rem;">Mandatory</span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-1" style="font-size:0.65rem;">Optional</span>
                    <?php endif; ?>
                    <span class="badge bg-light text-dark ms-1" style="font-size:0.65rem;">Weight: <?php echo $c['weight']; ?></span>
                </h6>
                <?php if ($c['description']): ?>
                    <p class="text-muted small mb-2"><?php echo htmlspecialchars($c['description']); ?></p>
                <?php endif; ?>
            </div>
        <?php
            elseif ($c['level'] === 'criteria'):
        ?>
            <div class="px-4 py-2" style="background:#fefce8; border-bottom: 1px solid #fde68a;">
                <div class="d-flex align-items-start">
                    <span class="badge me-2 mt-1" style="background:<?php echo $principle_colors[$c['principle_no']] ?? '#666'; ?>; font-size:0.75rem; min-width: 38px;">
                        <?php echo htmlspecialchars($c['criteria_no']); ?>
                    </span>
                    <div class="flex-grow-1">
                        <strong><?php echo htmlspecialchars($c['title']); ?></strong>
                        <?php if ($c['is_mandatory']): ?>
                            <span class="badge bg-danger ms-1" style="font-size:0.6rem;">Mandatory</span>
                        <?php else: ?>
                            <span class="badge bg-secondary ms-1" style="font-size:0.6rem;">Optional</span>
                        <?php endif; ?>
                        <span class="badge bg-light text-dark ms-1" style="font-size:0.6rem;">W:<?php echo $c['weight']; ?></span>
                        <?php if ($c['description']): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($c['description']); ?></small>
                        <?php endif; ?>
                        <?php if ($c['reference_doc']): ?>
                            <br><small class="text-info"><i class="bi bi-link-45deg"></i> <?php echo htmlspecialchars($c['reference_doc']); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php
            elseif ($c['level'] === 'indicator'):
        ?>
            <div class="px-5 py-1" style="border-bottom: 1px solid #f3f4f6;">
                <div class="d-flex align-items-start">
                    <span class="text-muted me-2 mt-1" style="font-size:0.75rem; min-width:42px;">
                        <i class="bi bi-dot"></i><?php echo htmlspecialchars($c['indicator_no']); ?>
                    </span>
                    <div class="flex-grow-1">
                        <small><?php echo htmlspecialchars($c['title']); ?></small>
                        <?php if ($c['is_mandatory']): ?>
                            <span class="badge bg-danger ms-1" style="font-size:0.55rem;">M</span>
                        <?php endif; ?>
                        <?php if ($c['description']): ?>
                            <br><small class="text-muted" style="font-size:0.75rem;"><?php echo htmlspecialchars($c['description']); ?></small>
                        <?php endif; ?>
                        <?php if ($c['reference_doc']): ?>
                            <br><small class="text-info" style="font-size:0.7rem;"><i class="bi bi-bookmark"></i> <?php echo htmlspecialchars($c['reference_doc']); ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="ms-2 text-end" style="min-width: 60px;">
                        <small class="text-muted">W: <?php echo $c['weight']; ?></small>
                    </div>
                </div>
            </div>
        <?php
            endif;
        endforeach;
        ?>
        <?php endif; ?>
    </div>
</div>

<style>
.btn-ispo {
    background-color: #d97706;
    border-color: #d97706;
    color: white;
}
.btn-ispo:hover {
    background-color: #b45309;
    border-color: #b45309;
    color: white;
}
.stat-card-ispo {
    border-left: 4px solid #f59e0b;
}
</style>

<?php require_once 'includes/footer.php'; ?>
