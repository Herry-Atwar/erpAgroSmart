<?php
require_once 'config/database.php';
require_once 'config/standards.php';
require_once 'includes/functions.php';

$db = getDB();

// ── POST handlers ────────────────────────────────────────────────────────────
if (is_post()) {
    $action = post('action');

    if ($action === 'add_cap') {
        try {
            $stmt = $db->prepare("
                INSERT INTO ispo_corrective_actions
                  (assessment_id, criteria_id, gap_description, action_plan,
                   priority, due_date, assigned_to, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?)
            ");
            $stmt->execute([
                (int)post('assessment_id'),
                (int)post('criteria_id'),
                post('gap_description'),
                post('action_plan'),
                post('priority'),
                post('due_date') ?: null,
                post('assigned_to'),
                'admin',
            ]);
            set_message('success', 'Corrective action plan created.');
            redirect('corrective_actions.php?assessment_id=' . post('assessment_id'));
        } catch (PDOException $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
    }

    elseif ($action === 'update_status') {
        try {
            $new_status  = post('status');
            $action_id   = (int)post('action_id');
            $valid       = ['open','in_progress','completed','verified','overdue'];
            if (!in_array($new_status, $valid)) throw new RuntimeException('Invalid status');

            $fields = "status = ?, updated_at = NOW()";
            $params = [$new_status];
            if ($new_status === 'completed') {
                $fields .= ", completion_date = CURRENT_DATE";
            }
            $params[] = $action_id;
            $db->prepare("UPDATE ispo_corrective_actions SET {$fields} WHERE action_id = ?")->execute($params);
            set_message('success', 'Status updated.');
            redirect('corrective_actions.php?assessment_id=' . post('assessment_id'));
        } catch (Exception $e) {
            set_message('error', 'Error: ' . $e->getMessage());
        }
    }

    elseif ($action === 'delete_cap') {
        $db->prepare("DELETE FROM ispo_corrective_actions WHERE action_id = ?")->execute([(int)post('action_id')]);
        set_message('success', 'CAP deleted.');
        redirect('corrective_actions.php?assessment_id=' . post('assessment_id'));
    }
}

// ── Filters ──────────────────────────────────────────────────────────────────
$assessment_id  = (int)get('assessment_id', 0);
$status_filter  = get('status', '');
$priority_filter = get('priority', '');

$assessments = $db->query("SELECT assessment_id, assessment_date, period_year FROM ispo_assessments ORDER BY assessment_date DESC")->fetchAll();

$sql    = "
    SELECT ca.*,
           c.criteria_no, c.indicator_no, c.title AS criteria_title,
           c.principle_no, c.is_mandatory,
           a.period_year, a.assessment_date
    FROM ispo_corrective_actions ca
    JOIN ispo_criteria c     ON ca.criteria_id    = c.criteria_id
    JOIN ispo_assessments a  ON ca.assessment_id  = a.assessment_id
    WHERE 1=1
";
$params = [];

if ($assessment_id > 0) {
    $sql     .= " AND ca.assessment_id = ?";
    $params[] = $assessment_id;
}
if ($status_filter) {
    $sql     .= " AND ca.status = ?";
    $params[] = $status_filter;
}
if ($priority_filter) {
    $sql     .= " AND ca.priority = ?";
    $params[] = $priority_filter;
}
$sql .= " ORDER BY CASE ca.priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END, ca.due_date ASC NULLS LAST";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$actions = $stmt->fetchAll();

// Criteria for new CAP dropdown
$criteria_list = $db->query("
    SELECT criteria_id, principle_no, criteria_no, indicator_no, title
    FROM ispo_criteria WHERE is_active = TRUE AND level IN ('criteria','indicator')
    ORDER BY principle_no, criteria_no, COALESCE(indicator_no,'99')
")->fetchAll();

$page_title = "Corrective Action Plans (CAP)";
require_once 'includes/header.php';

$priority_colors = ['critical'=>'danger','high'=>'warning','medium'=>'info','low'=>'secondary'];
$status_colors   = ['open'=>'secondary','in_progress'=>'info','completed'=>'success','verified'=>'primary','overdue'=>'danger'];
$principle_colors = [
    1=>'#dc2626', 2=>'#2563eb', 3=>'#16a34a',
    4=>'#9333ea', 5=>'#ea580c', 6=>'#0891b2', 7=>'#65a30d',
];

// Summary counts
$open     = count(array_filter($actions, fn($a) => $a['status'] === 'open'));
$in_prog  = count(array_filter($actions, fn($a) => $a['status'] === 'in_progress'));
$done     = count(array_filter($actions, fn($a) => in_array($a['status'], ['completed','verified'])));
$overdue  = count(array_filter($actions, fn($a) => $a['status'] === 'overdue' || ($a['due_date'] && $a['due_date'] < date('Y-m-d') && !in_array($a['status'], ['completed','verified']))));
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 style="color:#f59e0b;"><i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b;"></i> Corrective Action Plans</h1>
            <p class="text-muted">ISPO non-compliance gap remediation tracker</p>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-ispo" data-bs-toggle="modal" data-bs-target="#addCAPModal">
                <i class="bi bi-plus-circle"></i> New CAP
            </button>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card-ispo text-center">
            <div class="card-body">
                <h3 class="text-secondary"><?php echo $open; ?></h3>
                <p><i class="bi bi-circle"></i> Open</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card-ispo text-center">
            <div class="card-body">
                <h3 class="text-info"><?php echo $in_prog; ?></h3>
                <p><i class="bi bi-arrow-repeat"></i> In Progress</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card-ispo text-center">
            <div class="card-body">
                <h3 class="text-success"><?php echo $done; ?></h3>
                <p><i class="bi bi-check-circle-fill text-success"></i> Completed/Verified</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card-ispo text-center">
            <div class="card-body">
                <h3 class="text-danger"><?php echo $overdue; ?></h3>
                <p><i class="bi bi-alarm-fill text-danger"></i> Overdue</p>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <select class="form-select" name="assessment_id">
                    <option value="0">All Assessments</option>
                    <?php foreach ($assessments as $a): ?>
                        <option value="<?php echo $a['assessment_id']; ?>" <?php echo $assessment_id == $a['assessment_id'] ? 'selected' : ''; ?>>
                            #<?php echo $a['assessment_id']; ?> — <?php echo $a['period_year']; ?> (<?php echo format_date($a['assessment_date']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="priority">
                    <option value="">All Priorities</option>
                    <?php foreach (['critical','high','medium','low'] as $p): ?>
                        <option value="<?php echo $p; ?>" <?php echo $priority_filter == $p ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach (['open','in_progress','completed','verified','overdue'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $status_filter == $s ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_',' ',$s)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-ispo"><i class="bi bi-search"></i> Filter</button>
                <a href="corrective_actions.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- CAP Table -->
<div class="card">
    <div class="card-header" style="background:#f59e0b; color:white;">
        <i class="bi bi-list-task"></i> Action Plans (<?php echo count($actions); ?>)
    </div>
    <div class="card-body p-0">
        <?php if (empty($actions)): ?>
            <div class="text-center text-muted p-4">
                <i class="bi bi-clipboard2-check" style="font-size:2rem;"></i>
                <p class="mt-2">No corrective action plans found.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th>Criteria</th>
                        <th>Gap</th>
                        <th>Action Plan</th>
                        <th>Priority</th>
                        <th>Assigned To</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Evidence</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($actions as $cap): ?>
                    <?php
                    $is_overdue = $cap['due_date'] && $cap['due_date'] < date('Y-m-d')
                                  && !in_array($cap['status'], ['completed','verified']);
                    $pcolor = $principle_colors[$cap['principle_no']] ?? '#666';
                    ?>
                    <tr class="<?php echo $is_overdue ? 'table-danger' : ''; ?>">
                        <td>
                            <span class="badge me-1" style="background:<?php echo $pcolor; ?>; font-size:0.6rem;">P<?php echo $cap['principle_no']; ?></span>
                            <small><?php echo htmlspecialchars($cap['indicator_no'] ?: $cap['criteria_no']); ?></small>
                            <?php if ($cap['is_mandatory']): ?><span class="badge bg-dark" style="font-size:0.5rem;">M</span><?php endif; ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars(mb_substr($cap['criteria_title'], 0, 50)); ?></small>
                        </td>
                        <td style="max-width:150px;"><small><?php echo nl2br(htmlspecialchars(mb_substr($cap['gap_description'], 0, 120))); ?></small></td>
                        <td style="max-width:150px;"><small><?php echo nl2br(htmlspecialchars(mb_substr($cap['action_plan'], 0, 120))); ?></small></td>
                        <td><span class="badge bg-<?php echo $priority_colors[$cap['priority']] ?? 'secondary'; ?>"><?php echo ucfirst($cap['priority']); ?></span></td>
                        <td><small><?php echo htmlspecialchars($cap['assigned_to'] ?? '-'); ?></small></td>
                        <td>
                            <small class="<?php echo $is_overdue ? 'text-danger fw-bold' : ''; ?>">
                                <?php echo $cap['due_date'] ? format_date($cap['due_date']) : '-'; ?>
                                <?php if ($is_overdue): ?><br><span class="badge bg-danger" style="font-size:0.55rem;">Overdue</span><?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="action_id" value="<?php echo $cap['action_id']; ?>">
                                <input type="hidden" name="assessment_id" value="<?php echo $cap['assessment_id']; ?>">
                                <select class="form-select form-select-sm" name="status" onchange="this.form.submit()" style="min-width:100px;">
                                    <?php foreach (['open','in_progress','completed','verified','overdue'] as $s): ?>
                                        <option value="<?php echo $s; ?>" <?php echo $cap['status'] == $s ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_',' ',$s)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td>
                            <?php if ($cap['evidence_path']): ?>
                                <a href="<?php echo htmlspecialchars($cap['evidence_path']); ?>" target="_blank" class="btn btn-sm btn-outline-success" title="View evidence">
                                    <i class="bi bi-file-earmark-check"></i>
                                </a>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick="openEvidenceUpload(<?php echo $cap['action_id']; ?>)"
                                    title="Upload evidence">
                                    <i class="bi bi-upload"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this CAP?');">
                                <input type="hidden" name="action" value="delete_cap">
                                <input type="hidden" name="action_id" value="<?php echo $cap['action_id']; ?>">
                                <input type="hidden" name="assessment_id" value="<?php echo $cap['assessment_id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
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

<!-- Add CAP Modal -->
<div class="modal fade" id="addCAPModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_cap">
                <div class="modal-header" style="background:#f59e0b; color:white;">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> New Corrective Action Plan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Assessment <span class="text-danger">*</span></label>
                            <select class="form-select" name="assessment_id" required>
                                <option value="">Select...</option>
                                <?php foreach ($assessments as $a): ?>
                                    <option value="<?php echo $a['assessment_id']; ?>" <?php echo $assessment_id == $a['assessment_id'] ? 'selected' : ''; ?>>
                                        #<?php echo $a['assessment_id']; ?> — <?php echo $a['period_year']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ISPO Criteria / Indicator <span class="text-danger">*</span></label>
                            <select class="form-select" name="criteria_id" required>
                                <option value="">Select...</option>
                                <?php
                                $cur_p = null;
                                foreach ($criteria_list as $c):
                                    if ($c['principle_no'] !== $cur_p):
                                        if ($cur_p !== null) echo '</optgroup>';
                                        echo '<optgroup label="Principle ' . $c['principle_no'] . '">';
                                        $cur_p = $c['principle_no'];
                                    endif;
                                ?>
                                    <option value="<?php echo $c['criteria_id']; ?>">
                                        <?php echo htmlspecialchars(($c['indicator_no'] ?: $c['criteria_no']) . ': ' . mb_substr($c['title'], 0, 55)); ?>
                                    </option>
                                <?php endforeach; if ($cur_p !== null) echo '</optgroup>'; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Gap Description <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="gap_description" rows="2" required placeholder="Describe the specific gap or non-compliance found..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Action Plan <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="action_plan" rows="2" required placeholder="Steps to close the gap..."></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Priority</label>
                            <select class="form-select" name="priority">
                                <option value="medium">Medium</option>
                                <option value="critical">Critical</option>
                                <option value="high">High</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Assigned To</label>
                            <input type="text" class="form-control" name="assigned_to" placeholder="Person or team">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ispo"><i class="bi bi-check2"></i> Create CAP</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Evidence Upload Modal -->
<div class="modal fade" id="evidenceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:#16a34a; color:white;">
                <h5 class="modal-title"><i class="bi bi-upload"></i> Upload Evidence</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="uploadResult" class="mb-2" style="display:none;"></div>
                <div class="mb-3">
                    <label class="form-label">Evidence File (PDF, Word, Excel, Image — max 10 MB)</label>
                    <input type="file" class="form-control" id="evidenceFile"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" id="evidenceDescription" placeholder="Brief description of the evidence">
                </div>
                <input type="hidden" id="evidenceActionId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" onclick="submitEvidence()">
                    <i class="bi bi-cloud-upload"></i> Upload
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.btn-ispo { background-color:#d97706; border-color:#d97706; color:white; }
.btn-ispo:hover { background-color:#b45309; border-color:#b45309; color:white; }
.stat-card-ispo { border-left: 4px solid #f59e0b; }
</style>

<script>
function openEvidenceUpload(actionId) {
    document.getElementById('evidenceActionId').value = actionId;
    document.getElementById('uploadResult').style.display = 'none';
    document.getElementById('evidenceFile').value = '';
    var modal = new bootstrap.Modal(document.getElementById('evidenceModal'));
    modal.show();
}

function submitEvidence() {
    var fileInput = document.getElementById('evidenceFile');
    var desc      = document.getElementById('evidenceDescription').value;
    var actionId  = document.getElementById('evidenceActionId').value;
    var resultDiv = document.getElementById('uploadResult');

    if (!fileInput.files.length) {
        resultDiv.innerHTML = '<div class="alert alert-warning">Please select a file.</div>';
        resultDiv.style.display = 'block';
        return;
    }

    var fd = new FormData();
    fd.append('evidence_file', fileInput.files[0]);
    fd.append('action_id', actionId);
    fd.append('description', desc);

    fetch('ajax/upload_evidence.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                resultDiv.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle"></i> ' + data.message + '</div>';
                resultDiv.style.display = 'block';
                setTimeout(() => location.reload(), 1200);
            } else {
                resultDiv.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
                resultDiv.style.display = 'block';
            }
        })
        .catch(e => {
            resultDiv.innerHTML = '<div class="alert alert-danger">Upload failed: ' + e.message + '</div>';
            resultDiv.style.display = 'block';
        });
}
</script>

<?php require_once 'includes/footer.php'; ?>
