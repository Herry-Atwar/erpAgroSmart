<?php
require_once 'config/database.php';
require_once 'config/standards.php';
require_once 'includes/functions.php';

$db = getDB();

// ── Handle POST: Create new assessment ──────────────────────────────────────
if (is_post()) {
    $action = post('action');

    if ($action === 'create_assessment') {
        try {
            // Count total active criteria
            $cnt = $db->query("SELECT COUNT(*) FROM ispo_criteria WHERE is_active = TRUE AND level IN ('criteria','indicator')")->fetchColumn();

            $stmt = $db->prepare("
                INSERT INTO ispo_assessments
                  (assessment_date, assessment_type, period_year, assessor_name,
                   status, total_criteria, notes, created_by)
                VALUES (?, ?, ?, ?, 'draft', ?, ?, ?)
            ");
            $stmt->execute([
                post('assessment_date'),
                post('assessment_type'),
                (int)post('period_year'),
                post('assessor_name'),
                (int)$cnt,
                post('notes'),
                'admin',
            ]);
            $new_id = $db->lastInsertId();
            set_message('success', "Assessment created (ID #{$new_id}). Score your criteria below.");
            redirect("ispo_assessment.php?id={$new_id}");
        } catch (PDOException $e) {
            set_message('error', 'Error creating assessment: ' . $e->getMessage());
        }
    }

    elseif ($action === 'save_scores') {
        $assessment_id = (int)post('assessment_id');
        $scores        = post('scores', []);   // array [criteria_id => compliance]
        $notes_arr     = post('score_notes', []); // array [criteria_id => notes]

        try {
            $upsert = $db->prepare("
                INSERT INTO ispo_assessment_scores
                  (assessment_id, criteria_id, compliance, notes, assessed_by, assessed_at)
                VALUES (?, ?, ?, ?, 'admin', NOW())
                ON CONFLICT (assessment_id, criteria_id)
                DO UPDATE SET
                  compliance  = EXCLUDED.compliance,
                  notes       = EXCLUDED.notes,
                  assessed_by = EXCLUDED.assessed_by,
                  assessed_at = EXCLUDED.assessed_at,
                  updated_at  = NOW()
            ");

            foreach ($scores as $criteria_id => $compliance) {
                if (!in_array($compliance, ['compliant','partial','non_compliant','not_applicable','not_assessed'])) continue;
                $upsert->execute([
                    $assessment_id,
                    (int)$criteria_id,
                    $compliance,
                    $notes_arr[$criteria_id] ?? null,
                ]);
            }

            // Recalculate summary stats
            $stats = $db->prepare("
                SELECT
                    COUNT(*) FILTER (WHERE compliance IN ('compliant','partial','non_compliant','not_applicable','not_assessed')) AS assessed,
                    COUNT(*) FILTER (WHERE compliance = 'compliant')    AS compliant,
                    COUNT(*) FILTER (WHERE compliance != 'not_assessed') AS total_done
                FROM ispo_assessment_scores
                WHERE assessment_id = ?
            ");
            $stats->execute([$assessment_id]);
            $s = $stats->fetch();

            // Score: compliant=100, partial=50, non_compliant=0, not_applicable=skip
            $score_stmt = $db->prepare("
                SELECT SUM(CASE
                    WHEN s.compliance = 'compliant'     THEN c.weight * 100
                    WHEN s.compliance = 'partial'       THEN c.weight * 50
                    WHEN s.compliance = 'non_compliant' THEN 0
                    ELSE NULL END) AS weighted_score,
                    SUM(CASE WHEN s.compliance != 'not_assessed' AND s.compliance != 'not_applicable'
                        THEN c.weight ELSE NULL END) AS total_weight
                FROM ispo_assessment_scores s
                JOIN ispo_criteria c ON s.criteria_id = c.criteria_id
                WHERE s.assessment_id = ?
            ");
            $score_stmt->execute([$assessment_id]);
            $sc = $score_stmt->fetch();
            $score_pct = ($sc['total_weight'] > 0)
                ? round($sc['weighted_score'] / $sc['total_weight'], 2)
                : 0;

            $upd = $db->prepare("
                UPDATE ispo_assessments
                SET assessed_count = ?, compliant_count = ?, score_pct = ?, updated_at = NOW()
                WHERE assessment_id = ?
            ");
            $upd->execute([$s['total_done'], $s['compliant'], $score_pct, $assessment_id]);

            set_message('success', "Scores saved. Overall score: {$score_pct}%");
            redirect("ispo_assessment.php?id={$assessment_id}");
        } catch (PDOException $e) {
            set_message('error', 'Error saving scores: ' . $e->getMessage());
        }
    }

    elseif ($action === 'complete_assessment') {
        $assessment_id = (int)post('assessment_id');
        $db->prepare("UPDATE ispo_assessments SET status = 'completed', updated_at = NOW() WHERE assessment_id = ?")->execute([$assessment_id]);
        set_message('success', 'Assessment marked as completed.');
        redirect("ispo_gap_dashboard.php?id={$assessment_id}");
    }

    elseif ($action === 'delete_assessment') {
        $assessment_id = (int)post('assessment_id');
        $db->prepare("DELETE FROM ispo_assessments WHERE assessment_id = ?")->execute([$assessment_id]);
        set_message('success', 'Assessment deleted.');
        redirect('ispo_assessment.php');
    }
}

// ── Load specific assessment for scoring ────────────────────────────────────
$current_id = (int)get('id', 0);
$current    = null;
$criteria   = [];
$saved_scores = [];

if ($current_id > 0) {
    $stmt = $db->prepare("SELECT * FROM ispo_assessments WHERE assessment_id = ?");
    $stmt->execute([$current_id]);
    $current = $stmt->fetch();

    if ($current) {
        $criteria = $db->query("SELECT * FROM ispo_criteria WHERE is_active = TRUE ORDER BY principle_no, criteria_no, COALESCE(indicator_no,'99')")->fetchAll();

        $sc_stmt = $db->prepare("SELECT * FROM ispo_assessment_scores WHERE assessment_id = ?");
        $sc_stmt->execute([$current_id]);
        foreach ($sc_stmt->fetchAll() as $sc) {
            $saved_scores[$sc['criteria_id']] = $sc;
        }
    }
}

// ── Assessments list ─────────────────────────────────────────────────────────
$assessments = $db->query("SELECT * FROM ispo_assessments ORDER BY assessment_date DESC, assessment_id DESC")->fetchAll();

$page_title = "ISPO Assessments";
require_once 'includes/header.php';

$score_colors = [
    'compliant'     => 'success',
    'partial'       => 'warning',
    'non_compliant' => 'danger',
    'not_applicable'=> 'secondary',
    'not_assessed'  => 'light',
];
$score_labels = [
    'compliant'     => 'Compliant',
    'partial'       => 'Partial',
    'non_compliant' => 'Non-Compliant',
    'not_applicable'=> 'N/A',
    'not_assessed'  => 'Not Assessed',
];
$principle_colors = [
    1 => '#dc2626', 2 => '#2563eb', 3 => '#16a34a',
    4 => '#9333ea', 5 => '#ea580c', 6 => '#0891b2', 7 => '#65a30d',
];
$principle_names = [
    1=>'Legal Compliance', 2=>'Economic Viability & BMP', 3=>'Environmental Management',
    4=>'Social Responsibility', 5=>'K3/HSE', 6=>'Transparency', 7=>'Smallholder Empowerment',
];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color:#f59e0b;"><i class="bi bi-clipboard2-check-fill" style="color:#f59e0b;"></i> ISPO Assessments</h1>
            <p class="text-muted">Self-assessment and audit scoring against PP No.44/2020 criteria</p>
        </div>
        <div class="col-auto">
            <?php if ($current): ?>
                <a href="ispo_assessment.php" class="btn btn-secondary me-2"><i class="bi bi-arrow-left"></i> All Assessments</a>
            <?php endif; ?>
            <button type="button" class="btn btn-ispo" data-bs-toggle="modal" data-bs-target="#newAssessmentModal">
                <i class="bi bi-plus-circle"></i> New Assessment
            </button>
        </div>
    </div>
</div>

<?php if (!$current): ?>
<!-- ── Assessments List ──────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header" style="background:#f59e0b; color:white;">
        <i class="bi bi-clipboard2-check"></i> Assessment Records (<?php echo count($assessments); ?>)
    </div>
    <div class="card-body p-0">
        <?php if (empty($assessments)): ?>
            <div class="text-center text-muted p-5">
                <i class="bi bi-clipboard2-x" style="font-size:2.5rem;"></i>
                <p class="mt-3">No assessments yet. Click <strong>New Assessment</strong> to begin.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Year</th>
                        <th>Type</th>
                        <th>Assessor</th>
                        <th>Progress</th>
                        <th>Score</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assessments as $a): ?>
                    <tr>
                        <td>#<?php echo $a['assessment_id']; ?></td>
                        <td><?php echo format_date($a['assessment_date']); ?></td>
                        <td><?php echo $a['period_year']; ?></td>
                        <td><span class="badge bg-info"><?php echo ucfirst($a['assessment_type']); ?></span></td>
                        <td><?php echo htmlspecialchars($a['assessor_name'] ?? '-'); ?></td>
                        <td>
                            <?php
                            $done = (int)$a['assessed_count'];
                            $total = (int)$a['total_criteria'];
                            $pct = $total > 0 ? round($done / $total * 100) : 0;
                            ?>
                            <div class="progress" style="height:6px; min-width:80px;">
                                <div class="progress-bar bg-info" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                            <small class="text-muted"><?php echo $done; ?>/<?php echo $total; ?></small>
                        </td>
                        <td>
                            <?php
                            $sp = round((float)$a['score_pct'], 1);
                            $sc = $sp >= 80 ? 'success' : ($sp >= 60 ? 'warning' : 'danger');
                            ?>
                            <span class="badge bg-<?php echo $sc; ?>"><?php echo $sp; ?>%</span>
                        </td>
                        <td>
                            <span class="badge bg-<?php
                                echo $a['status'] === 'completed'   ? 'success' :
                                    ($a['status'] === 'certified'   ? 'primary' :
                                    ($a['status'] === 'in_progress' ? 'info'    : 'secondary'));
                            ?>">
                                <?php echo ucfirst(str_replace('_',' ',$a['status'])); ?>
                            </span>
                        </td>
                        <td>
                            <a href="?id=<?php echo $a['assessment_id']; ?>" class="btn btn-sm btn-ispo" title="Score"><i class="bi bi-pencil-square"></i></a>
                            <a href="ispo_gap_dashboard.php?id=<?php echo $a['assessment_id']; ?>" class="btn btn-sm btn-outline-warning" title="Gap Dashboard"><i class="bi bi-bar-chart-steps"></i></a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this assessment?');">
                                <input type="hidden" name="action" value="delete_assessment">
                                <input type="hidden" name="assessment_id" value="<?php echo $a['assessment_id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ── Scoring Form ──────────────────────────────────────────────────────────── -->
<div class="alert alert-info d-flex align-items-center mb-3">
    <i class="bi bi-info-circle me-2"></i>
    <div>
        <strong>Assessment #<?php echo $current['assessment_id']; ?></strong>
        (<?php echo format_date($current['assessment_date']); ?> &middot; <?php echo ucfirst($current['assessment_type']); ?> &middot; <?php echo $current['period_year']; ?>)
        &mdash; Score: <strong><?php echo round((float)$current['score_pct'],1); ?>%</strong>
        &mdash; Status: <span class="badge bg-info"><?php echo ucfirst(str_replace('_',' ',$current['status'])); ?></span>
    </div>
    <?php if ($current['status'] !== 'completed'): ?>
    <form method="POST" class="ms-auto">
        <input type="hidden" name="action" value="complete_assessment">
        <input type="hidden" name="assessment_id" value="<?php echo $current['assessment_id']; ?>">
        <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Mark as completed?');">
            <i class="bi bi-check2-circle"></i> Complete
        </button>
    </form>
    <?php endif; ?>
</div>

<form method="POST">
    <input type="hidden" name="action" value="save_scores">
    <input type="hidden" name="assessment_id" value="<?php echo $current['assessment_id']; ?>">

    <?php
    $current_principle = null;
    foreach ($criteria as $c):
        if ($c['level'] === 'principle' && $c['principle_no'] !== $current_principle):
            if ($current_principle !== null) echo '</div>'; // close prev card-body
            $current_principle = $c['principle_no'];
            $pcolor = $principle_colors[$current_principle] ?? '#666';
    ?>
    <div class="card mb-3">
        <div class="card-header text-white" style="background:<?php echo $pcolor; ?>;">
            <strong>P<?php echo $current_principle; ?>: <?php echo htmlspecialchars($c['title']); ?></strong>
            <?php if ($c['is_mandatory']): ?>
                <span class="badge bg-light text-dark ms-2">Mandatory</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
    <?php
        elseif ($c['level'] === 'criteria'):
            $pcolor = $principle_colors[$c['principle_no']] ?? '#666';
    ?>
            <div class="px-3 py-2" style="background:#fefce8; border-bottom: 1px solid #fde68a;">
                <div class="row align-items-start">
                    <div class="col-md-7">
                        <span class="badge me-1" style="background:<?php echo $pcolor; ?>;"><?php echo $c['criteria_no']; ?></span>
                        <strong><?php echo htmlspecialchars($c['title']); ?></strong>
                        <?php if ($c['is_mandatory']): ?><span class="badge bg-danger ms-1" style="font-size:0.6rem;">M</span><?php endif; ?>
                        <?php if ($c['description']): ?><br><small class="text-muted"><?php echo htmlspecialchars($c['description']); ?></small><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <?php $saved_c = $saved_scores[$c['criteria_id']] ?? null; ?>
                        <select class="form-select form-select-sm" name="scores[<?php echo $c['criteria_id']; ?>]">
                            <?php foreach ($score_labels as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>" <?php echo ($saved_c && $saved_c['compliance'] == $val) ? 'selected' : ''; ?>>
                                    <?php echo $lbl; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <?php if ($saved_c): ?>
                            <span class="badge bg-<?php echo $score_colors[$saved_c['compliance']] ?? 'secondary'; ?>">
                                <?php echo $score_labels[$saved_c['compliance']] ?? '-'; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($saved_c && $saved_c['notes']): ?>
                    <small class="text-muted"><i class="bi bi-chat-left-text"></i> <?php echo htmlspecialchars($saved_c['notes']); ?></small>
                <?php endif; ?>
            </div>
    <?php
        elseif ($c['level'] === 'indicator'):
    ?>
            <div class="px-4 py-1" style="border-bottom: 1px solid #f3f4f6;">
                <div class="row align-items-start">
                    <div class="col-md-7">
                        <small class="text-muted me-1"><i class="bi bi-dot"></i><?php echo htmlspecialchars($c['indicator_no']); ?></small>
                        <small><?php echo htmlspecialchars($c['title']); ?></small>
                        <?php if ($c['is_mandatory']): ?><span class="badge bg-danger ms-1" style="font-size:0.5rem;">M</span><?php endif; ?>
                        <?php if ($c['description']): ?><br><small class="text-muted" style="font-size:0.7rem;"><?php echo htmlspecialchars($c['description']); ?></small><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <?php $saved_i = $saved_scores[$c['criteria_id']] ?? null; ?>
                        <select class="form-select form-select-sm" name="scores[<?php echo $c['criteria_id']; ?>]">
                            <?php foreach ($score_labels as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>" <?php echo ($saved_i && $saved_i['compliance'] == $val) ? 'selected' : ''; ?>>
                                    <?php echo $lbl; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <?php if ($saved_i && $saved_i['compliance'] !== 'not_assessed'): ?>
                            <span class="badge bg-<?php echo $score_colors[$saved_i['compliance']] ?? 'secondary'; ?>" style="font-size:0.6rem;">
                                <?php echo $score_labels[$saved_i['compliance']] ?? '-'; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
    <?php endif; endforeach; ?>
    <?php if ($current_principle !== null) echo '</div></div>'; // close last card ?>

    <div class="d-flex gap-2 my-3">
        <button type="submit" class="btn btn-ispo">
            <i class="bi bi-save"></i> Save Scores
        </button>
        <a href="ispo_gap_dashboard.php?id=<?php echo $current['assessment_id']; ?>" class="btn btn-outline-warning">
            <i class="bi bi-bar-chart-steps"></i> View Gap Dashboard
        </a>
    </div>
</form>
<?php endif; ?>

<!-- New Assessment Modal -->
<div class="modal fade" id="newAssessmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_assessment">
                <div class="modal-header" style="background:#f59e0b; color:white;">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> New ISPO Assessment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Assessment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="assessment_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Period Year <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="period_year" value="<?php echo date('Y'); ?>" min="2020" max="2035" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Assessment Type</label>
                        <select class="form-select" name="assessment_type">
                            <option value="self">Self-Assessment</option>
                            <option value="internal">Internal Audit</option>
                            <option value="external">External Audit</option>
                            <option value="certification">Certification Audit</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Assessor Name</label>
                        <input type="text" class="form-control" name="assessor_name" placeholder="Auditor or team name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ispo"><i class="bi bi-check2-circle"></i> Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.btn-ispo { background-color:#d97706; border-color:#d97706; color:white; }
.btn-ispo:hover { background-color:#b45309; border-color:#b45309; color:white; }
</style>

<?php require_once 'includes/footer.php'; ?>
