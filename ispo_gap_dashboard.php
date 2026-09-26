<?php
require_once 'config/database.php';
require_once 'config/standards.php';
require_once 'includes/functions.php';

$db = getDB();

// ── Load assessment ──────────────────────────────────────────────────────────
$assessment_id = (int)get('id', 0);
$assessment    = null;

// If no ID, show the most recent completed/in-progress assessment
if ($assessment_id === 0) {
    $a_stmt = $db->query("SELECT * FROM ispo_assessments ORDER BY assessment_date DESC, assessment_id DESC LIMIT 1");
    $assessment = $a_stmt->fetch();
    if ($assessment) $assessment_id = (int)$assessment['assessment_id'];
} else {
    $stmt = $db->prepare("SELECT * FROM ispo_assessments WHERE assessment_id = ?");
    $stmt->execute([$assessment_id]);
    $assessment = $stmt->fetch();
}

$all_assessments = $db->query("SELECT assessment_id, assessment_date, period_year, assessment_type FROM ispo_assessments ORDER BY assessment_date DESC")->fetchAll();

$page_title = "ISPO Gap Dashboard";
require_once 'includes/header.php';

$principle_names = [
    1=>'Legal Compliance', 2=>'Economic Viability & BMP', 3=>'Environmental Management',
    4=>'Social Responsibility', 5=>'K3 / HSE', 6=>'Transparency', 7=>'Smallholder Empowerment',
];
$principle_colors = [
    1 => '#dc2626', 2 => '#2563eb', 3 => '#16a34a',
    4 => '#9333ea', 5 => '#ea580c', 6 => '#0891b2', 7 => '#65a30d',
];

$gap_data = [];
$overall_score = 0;
$non_compliant_list = [];
$partial_list = [];

if ($assessment && $assessment_id > 0) {
    // Per-principle breakdown
    $sql = "
        SELECT
            c.principle_no,
            COUNT(*) FILTER (WHERE c.level IN ('criteria','indicator'))                     AS total_items,
            COUNT(*) FILTER (WHERE s.compliance = 'compliant'     AND c.level IN ('criteria','indicator')) AS compliant,
            COUNT(*) FILTER (WHERE s.compliance = 'partial'       AND c.level IN ('criteria','indicator')) AS partial,
            COUNT(*) FILTER (WHERE s.compliance = 'non_compliant' AND c.level IN ('criteria','indicator')) AS non_compliant,
            COUNT(*) FILTER (WHERE s.compliance = 'not_applicable' AND c.level IN ('criteria','indicator')) AS not_applicable,
            COUNT(*) FILTER (WHERE (s.compliance IS NULL OR s.compliance = 'not_assessed') AND c.level IN ('criteria','indicator')) AS not_assessed,
            SUM(CASE WHEN s.compliance = 'compliant' AND c.level IN ('criteria','indicator') THEN c.weight * 100
                     WHEN s.compliance = 'partial'   AND c.level IN ('criteria','indicator') THEN c.weight * 50
                     WHEN s.compliance = 'non_compliant' AND c.level IN ('criteria','indicator') THEN 0
                     ELSE NULL END) AS weighted_score,
            SUM(CASE WHEN s.compliance IN ('compliant','partial','non_compliant') AND c.level IN ('criteria','indicator')
                     THEN c.weight ELSE NULL END) AS total_weight
        FROM ispo_criteria c
        LEFT JOIN ispo_assessment_scores s
               ON s.criteria_id = c.criteria_id AND s.assessment_id = ?
        WHERE c.is_active = TRUE
        GROUP BY c.principle_no
        ORDER BY c.principle_no
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$assessment_id]);
    $gap_data = $stmt->fetchAll();

    // Non-compliant / partial items
    $nc_stmt = $db->prepare("
        SELECT c.criteria_no, c.indicator_no, c.level, c.title, c.is_mandatory,
               c.principle_no, c.reference_doc, c.weight,
               s.compliance, s.notes
        FROM ispo_assessment_scores s
        JOIN ispo_criteria c ON s.criteria_id = c.criteria_id
        WHERE s.assessment_id = ?
          AND s.compliance IN ('non_compliant','partial')
          AND c.level IN ('criteria','indicator')
        ORDER BY c.is_mandatory DESC, c.principle_no, c.criteria_no
    ");
    $nc_stmt->execute([$assessment_id]);
    foreach ($nc_stmt->fetchAll() as $row) {
        if ($row['compliance'] === 'non_compliant') $non_compliant_list[] = $row;
        else $partial_list[] = $row;
    }

    // Overall score
    $overall_score = round((float)$assessment['score_pct'], 1);
}

// Score band
$score_band = $overall_score >= 80 ? ['ISPO Ready', 'success', 'bi-patch-check-fill'] :
             ($overall_score >= 60 ? ['Needs Improvement', 'warning', 'bi-exclamation-triangle-fill'] :
                                     ['Significant Gaps', 'danger', 'bi-x-circle-fill']);

// Prepare chart data
$chart_labels = [];
$chart_compliant = [];
$chart_partial = [];
$chart_nc = [];
$chart_na = [];

foreach ($gap_data as $row) {
    $chart_labels[]    = 'P'.$row['principle_no'];
    $chart_compliant[] = (int)$row['compliant'];
    $chart_partial[]   = (int)$row['partial'];
    $chart_nc[]        = (int)$row['non_compliant'];
    $chart_na[]        = (int)$row['not_assessed'] + (int)$row['not_applicable'];
}
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color:#f59e0b;"><i class="bi bi-bar-chart-steps" style="color:#f59e0b;"></i> ISPO Gap Dashboard</h1>
            <p class="text-muted">Visual gap analysis &amp; scoring by principle — PP No.44/2020</p>
        </div>
        <div class="col-auto d-flex gap-2">
            <select class="form-select form-select-sm" onchange="location='?id='+this.value" style="min-width:200px;">
                <option value="">— Select Assessment —</option>
                <?php foreach ($all_assessments as $a): ?>
                    <option value="<?php echo $a['assessment_id']; ?>" <?php echo $a['assessment_id'] == $assessment_id ? 'selected' : ''; ?>>
                        #<?php echo $a['assessment_id']; ?> — <?php echo $a['period_year']; ?> (<?php echo format_date($a['assessment_date']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($assessment_id): ?>
            <a href="ispo_assessment.php?id=<?php echo $assessment_id; ?>" class="btn btn-ispo btn-sm">
                <i class="bi bi-pencil-square"></i> Score
            </a>
            <a href="corrective_actions.php?assessment_id=<?php echo $assessment_id; ?>" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-exclamation-triangle"></i> CAP
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$assessment): ?>
<div class="text-center py-5 text-muted">
    <i class="bi bi-clipboard2-x" style="font-size:3rem;"></i>
    <p class="mt-3 fs-5">No assessment data. <a href="ispo_assessment.php">Create an assessment</a> first.</p>
</div>
<?php else: ?>

<!-- Overall Score Card -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center h-100" style="border: 3px solid var(--bs-<?php echo $score_band[1]; ?>);">
            <div class="card-body d-flex flex-column justify-content-center">
                <i class="bi <?php echo $score_band[2]; ?> text-<?php echo $score_band[1]; ?>" style="font-size:2.5rem;"></i>
                <h1 class="mt-2 text-<?php echo $score_band[1]; ?>"><?php echo $overall_score; ?>%</h1>
                <h6 class="text-<?php echo $score_band[1]; ?>"><?php echo $score_band[0]; ?></h6>
                <small class="text-muted">Assessment #<?php echo $assessment['assessment_id']; ?> &middot; <?php echo $assessment['period_year']; ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-9">
        <div class="row h-100">
            <?php
            $total_assessed = 0; $total_c = 0; $total_p = 0; $total_nc = 0;
            foreach ($gap_data as $r) {
                $total_c  += (int)$r['compliant'];
                $total_p  += (int)$r['partial'];
                $total_nc += (int)$r['non_compliant'];
                $total_assessed += (int)$r['compliant'] + (int)$r['partial'] + (int)$r['non_compliant'];
            }
            ?>
            <div class="col-md-3">
                <div class="card stat-card-ispo text-center h-100">
                    <div class="card-body">
                        <h3 class="text-success"><?php echo $total_c; ?></h3>
                        <p><i class="bi bi-check-circle-fill text-success"></i> Compliant</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card-ispo text-center h-100">
                    <div class="card-body">
                        <h3 class="text-warning"><?php echo $total_p; ?></h3>
                        <p><i class="bi bi-exclamation-circle-fill text-warning"></i> Partial</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card-ispo text-center h-100">
                    <div class="card-body">
                        <h3 class="text-danger"><?php echo $total_nc; ?></h3>
                        <p><i class="bi bi-x-circle-fill text-danger"></i> Non-Compliant</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card-ispo text-center h-100">
                    <div class="card-body">
                        <h3 class="text-muted"><?php echo $total_assessed; ?></h3>
                        <p><i class="bi bi-clipboard2-check"></i> Assessed</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Per-Principle Score Chart -->
<div class="card mb-4">
    <div class="card-header" style="background:#f59e0b; color:white;">
        <i class="bi bi-bar-chart-fill"></i> Compliance by Principle
    </div>
    <div class="card-body">
        <canvas id="principleChart" height="90"></canvas>
    </div>
</div>

<!-- Per-Principle Score Table -->
<div class="card mb-4">
    <div class="card-header" style="background:#f59e0b; color:white;">
        <i class="bi bi-table"></i> Principle-level Score Breakdown
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Principle</th>
                    <th>Compliant</th>
                    <th>Partial</th>
                    <th>Non-Compliant</th>
                    <th>Not Assessed</th>
                    <th>Score</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gap_data as $row):
                    $tw = (float)$row['total_weight'];
                    $ws = (float)$row['weighted_score'];
                    $p_score = $tw > 0 ? round($ws / $tw, 1) : 0;
                    $p_band  = $p_score >= 80 ? 'success' : ($p_score >= 60 ? 'warning' : 'danger');
                    $pcolor  = $principle_colors[$row['principle_no']] ?? '#666';
                ?>
                <tr>
                    <td>
                        <span class="badge me-2" style="background:<?php echo $pcolor; ?>;">P<?php echo $row['principle_no']; ?></span>
                        <?php echo htmlspecialchars($principle_names[$row['principle_no']] ?? ''); ?>
                    </td>
                    <td><span class="badge bg-success"><?php echo $row['compliant']; ?></span></td>
                    <td><span class="badge bg-warning"><?php echo $row['partial']; ?></span></td>
                    <td><span class="badge bg-danger"><?php echo $row['non_compliant']; ?></span></td>
                    <td><span class="badge bg-secondary"><?php echo (int)$row['not_assessed'] + (int)$row['not_applicable']; ?></span></td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <div class="progress flex-grow-1" style="height:8px; min-width:80px;">
                                <div class="progress-bar bg-<?php echo $p_band; ?>" style="width:<?php echo $p_score; ?>%"></div>
                            </div>
                            <small><?php echo $p_score; ?>%</small>
                        </div>
                    </td>
                    <td><span class="badge bg-<?php echo $p_band; ?>"><?php
                        echo $p_score >= 80 ? 'Ready' : ($p_score >= 60 ? 'Needs Work' : 'Gap');
                    ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Non-Compliance Gaps List -->
<?php if (!empty($non_compliant_list) || !empty($partial_list)): ?>
<div class="card mb-4">
    <div class="card-header bg-danger text-white">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Identified Gaps — <?php echo count($non_compliant_list); ?> Non-Compliant, <?php echo count($partial_list); ?> Partial
    </div>
    <div class="card-body p-0">
        <?php if (!empty($non_compliant_list)): ?>
        <div class="px-3 pt-3 pb-1"><h6 class="text-danger"><i class="bi bi-x-circle-fill"></i> Non-Compliant Items</h6></div>
        <?php foreach ($non_compliant_list as $item): ?>
        <div class="px-3 py-2" style="border-left: 4px solid #dc2626; border-bottom: 1px solid #fee2e2; background:#fff5f5;">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="badge bg-danger me-1"><?php echo htmlspecialchars($item['indicator_no'] ?: $item['criteria_no']); ?></span>
                    <?php if ($item['is_mandatory']): ?><span class="badge bg-dark me-1" style="font-size:0.6rem;">Mandatory</span><?php endif; ?>
                    <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                    <?php if ($item['notes']): ?>
                        <br><small class="text-muted"><i class="bi bi-chat-left-text"></i> <?php echo htmlspecialchars($item['notes']); ?></small>
                    <?php endif; ?>
                    <?php if ($item['reference_doc']): ?>
                        <br><small class="text-info" style="font-size:0.7rem;"><i class="bi bi-bookmark"></i> <?php echo htmlspecialchars($item['reference_doc']); ?></small>
                    <?php endif; ?>
                </div>
                <a href="corrective_actions.php?assessment_id=<?php echo $assessment_id; ?>&criteria_id=<?php echo $item['criteria_no']; ?>"
                   class="btn btn-sm btn-outline-danger ms-2" title="Create CAP">
                    <i class="bi bi-plus-circle"></i> CAP
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($partial_list)): ?>
        <div class="px-3 pt-3 pb-1"><h6 class="text-warning"><i class="bi bi-exclamation-circle-fill"></i> Partial Compliance Items</h6></div>
        <?php foreach ($partial_list as $item): ?>
        <div class="px-3 py-2" style="border-left: 4px solid #f59e0b; border-bottom: 1px solid #fef3c7; background:#fffbeb;">
            <span class="badge bg-warning text-dark me-1"><?php echo htmlspecialchars($item['indicator_no'] ?: $item['criteria_no']); ?></span>
            <?php if ($item['is_mandatory']): ?><span class="badge bg-dark me-1" style="font-size:0.6rem;">Mandatory</span><?php endif; ?>
            <strong><?php echo htmlspecialchars($item['title']); ?></strong>
            <?php if ($item['notes']): ?>
                <br><small class="text-muted"><i class="bi bi-chat-left-text"></i> <?php echo htmlspecialchars($item['notes']); ?></small>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Recommendations -->
<div class="card mb-4">
    <div class="card-header" style="background:#1e40af; color:white;">
        <i class="bi bi-lightbulb-fill"></i> Recommended Next Steps
    </div>
    <div class="card-body">
        <?php
        $mandatory_nc = array_filter($non_compliant_list, fn($i) => $i['is_mandatory']);
        $cnt_mnc      = count($mandatory_nc);
        ?>
        <?php if ($cnt_mnc > 0): ?>
        <div class="alert alert-danger">
            <strong><i class="bi bi-exclamation-triangle-fill"></i> Critical: <?php echo $cnt_mnc; ?> mandatory criteria are non-compliant.</strong>
            These must be resolved before ISPO certification can be issued.
            <a href="corrective_actions.php?assessment_id=<?php echo $assessment_id; ?>" class="btn btn-sm btn-danger ms-2">
                <i class="bi bi-list-task"></i> Create CAPs Now
            </a>
        </div>
        <?php endif; ?>
        <ul class="mb-0">
            <?php if ($overall_score < 80): ?>
            <li>Overall score is <?php echo $overall_score; ?>% — target ≥80% for ISPO certification readiness.</li>
            <?php endif; ?>
            <?php if (!empty($non_compliant_list)): ?>
            <li>Address <?php echo count($non_compliant_list); ?> non-compliant item(s) with corrective action plans and evidence upload.</li>
            <?php endif; ?>
            <?php if (!empty($partial_list)): ?>
            <li>Improve <?php echo count($partial_list); ?> partial item(s) to achieve full compliance.</li>
            <?php endif; ?>
            <?php if ($assessment['assessed_count'] < $assessment['total_criteria']): ?>
            <li><?php echo ($assessment['total_criteria'] - $assessment['assessed_count']); ?> criteria have not been assessed yet — complete the scoring.</li>
            <?php endif; ?>
            <li>Schedule the next assessment within 12 months to track improvement progress.</li>
        </ul>
    </div>
</div>

<?php endif; // $assessment ?>

<style>
.btn-ispo { background-color:#d97706; border-color:#d97706; color:white; }
.btn-ispo:hover { background-color:#b45309; border-color:#b45309; color:white; }
.stat-card-ispo { border-left: 4px solid #f59e0b; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
const ctx = document.getElementById('principleChart');
if (ctx) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [
                { label: 'Compliant',     data: <?php echo json_encode($chart_compliant); ?>, backgroundColor: '#16a34a' },
                { label: 'Partial',       data: <?php echo json_encode($chart_partial); ?>,   backgroundColor: '#f59e0b' },
                { label: 'Non-Compliant', data: <?php echo json_encode($chart_nc); ?>,        backgroundColor: '#dc2626' },
                { label: 'Not Assessed',  data: <?php echo json_encode($chart_na); ?>,        backgroundColor: '#d1d5db' },
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            scales: { x: { stacked: true }, y: { stacked: true, ticks: { stepSize: 1 } } }
        }
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
