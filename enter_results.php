<?php
// enter_results.php
ob_start();
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] != 'LabTech' && $_SESSION['role'] != 'Admin')) {
    header("location: dashboard.php"); exit;
}

$success = "";
$error   = "";

// Pick up success message from redirect
$saved_id = 0;
if (isset($_GET['saved']) && intval($_GET['saved']) > 0) {
    $saved_id = intval($_GET['saved']);
    $success  = "Results saved for Request #$saved_id. Doctor has been notified.";
}

// --- HANDLE RESULT SUBMISSION ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_results'])) {
    $req_id = intval($_POST['request_id'] ?? 0);

    if ($req_id > 0 && isset($_POST['results']) && is_array($_POST['results'])) {
        $conn->begin_transaction();
        try {
            // A. Update test results
            $stmt = $conn->prepare("UPDATE test_results SET result_value = ?, technician_remarks = ?, entered_by = ? WHERE result_id = ?");
            $uid  = intval($_SESSION['id'] ?? 1);
            foreach ($_POST['results'] as $result_id => $value) {
                $val     = trim($value);
                $comment = trim($_POST['comments'][$result_id] ?? "");
                $rid     = intval($result_id);
                $stmt->bind_param("ssii", $val, $comment, $uid, $rid);
                if (!$stmt->execute()) throw new Exception("Failed to update result ID: $rid");
            }
            $stmt->close();

            // B. Mark request as Completed
            $stmt_up = $conn->prepare("UPDATE lab_requests SET status = 'Completed' WHERE request_id = ?");
            $stmt_up->bind_param("i", $req_id);
            if (!$stmt_up->execute()) throw new Exception("Failed to update request status.");
            $stmt_up->close();

            // C. Auto-notify Doctor — WORKFLOW: results ready
            $stmt_pat = $conn->prepare(
                "SELECT p.full_name, r.requested_by
                 FROM lab_requests r
                 JOIN patients p ON r.patient_id = p.patient_id
                 WHERE r.request_id = ?"
            );
            $stmt_pat->bind_param("i", $req_id);
            $stmt_pat->execute();
            $pat_row  = $stmt_pat->get_result()->fetch_assoc();
            $pat_name = $pat_row['full_name'] ?? "Unknown Patient";
            $stmt_pat->close();

            $notif_msg  = $conn->real_escape_string("✓ Results: $pat_name #$req_id");
            $notif_link = $conn->real_escape_string("print_report.php?id=$req_id");
            // Notify the specific doctor who ordered — get their user_id by full_name
            $requested_by = $conn->real_escape_string($pat_row['requested_by'] ?? '');
            $dr_res = $conn->query("SELECT user_id FROM users WHERE full_name = '$requested_by' AND role = 'Doctor' LIMIT 1");
            if ($dr_res && $dr_res->num_rows > 0) {
                $dr_id = $dr_res->fetch_assoc()['user_id'];
                $conn->query("INSERT INTO notifications (target_role, target_user_id, message, link, is_read, created_at)
                              VALUES ('Doctor', $dr_id, '$notif_msg', '$notif_link', 0, NOW())");
            } else {
                // Fallback: notify all doctors if specific doctor not found
                $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at)
                              VALUES ('Doctor', '$notif_msg', '$notif_link', 0, NOW())");
            }

            $conn->commit();

            // Redirect to clear manage_id — forces queue to refresh without completed request
            ob_end_clean();
            header("Location: enter_results.php?saved=$req_id");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "No result data found.";
    }
}

// --- QUEUE: show Pending AND In Progress ---
$queue_search = trim($_GET['q'] ?? '');
$queue_sql = "SELECT r.request_id, p.full_name, p.opd_number, r.request_date,
                     r.payment_status, r.status,
                     (SELECT COUNT(*) FROM test_results tr WHERE tr.request_id = r.request_id) as test_count
              FROM lab_requests r
              JOIN patients p ON r.patient_id = p.patient_id
              WHERE r.status IN ('Pending', 'In Progress')";

if ($queue_search !== '') {
    $qs = $conn->real_escape_string($queue_search);
    $queue_sql .= " AND (p.full_name LIKE '%$qs%' OR p.opd_number LIKE '%$qs%')";
}
$queue_sql .= " ORDER BY
                CASE r.status WHEN 'In Progress' THEN 0 ELSE 1 END,
                r.request_date ASC";

$queue_result = $conn->query($queue_sql);
$queue_rows   = [];
if ($queue_result) while ($qrow = $queue_result->fetch_assoc()) $queue_rows[] = $qrow;
$queue_total  = count($queue_rows);

// Count overdue (Pending/In Progress > 2 hours old)
$overdue_count = 0;
foreach ($queue_rows as $qr) {
    if ((time() - strtotime($qr['request_date'])) > 7200) $overdue_count++;
}

// --- FETCH SELECTED REQUEST & AUTO-MARK IN PROGRESS ---
$selected_request = null;
$request_tests    = null;

if (isset($_GET['manage_id'])) {
    $manage_id = intval($_GET['manage_id']);

    $stmt_p = $conn->prepare(
        "SELECT r.request_id, p.full_name, p.opd_number, p.age, p.gender,
                r.payment_status, r.request_date, r.requested_by, r.status
         FROM lab_requests r
         JOIN patients p ON r.patient_id = p.patient_id
         WHERE r.request_id = ?"
    );
    $stmt_p->bind_param("i", $manage_id);
    $stmt_p->execute();
    $selected_request = $stmt_p->get_result()->fetch_assoc();
    $stmt_p->close();

    // WORKFLOW: Auto-mark as In Progress when lab tech opens it
    if ($selected_request && $selected_request['status'] === 'Pending' && $selected_request['payment_status'] === 'Paid') {
        $conn->query("UPDATE lab_requests SET status = 'In Progress' WHERE request_id = $manage_id");
        $selected_request['status'] = 'In Progress';
        // Refresh queue to reflect status change
        $queue_rows = array_map(function($r) use ($manage_id) {
            if ($r['request_id'] == $manage_id) $r['status'] = 'In Progress';
            return $r;
        }, $queue_rows);
    }

    if ($selected_request) {
        $stmt_t = $conn->prepare(
            "SELECT tr.result_id, t.test_name, t.units, t.normal_range,
                    tr.result_value, tr.technician_remarks
             FROM test_results tr
             JOIN lab_tests t ON tr.test_id = t.test_id
             WHERE tr.request_id = ?
             ORDER BY t.test_category, t.test_name"
        );
        $stmt_t->bind_param("i", $manage_id);
        $stmt_t->execute();
        $request_tests = $stmt_t->get_result();
        $stmt_t->close();
    }
}

$page_title = "Lab Workbench - Yala LIMS";
include 'includes/header.php';
?>

<style>
    .glass-card { background:rgba(255,255,255,0.97); border:none; border-radius:14px; box-shadow:0 4px 20px rgba(31,38,135,0.09); }
    .queue-item { background:#fff; border-radius:10px; border:1px solid #e9ecef; padding:10px 14px; margin-bottom:8px; cursor:pointer; transition:all 0.18s ease; text-decoration:none; display:block; color:inherit; }
    .queue-item:hover  { border-color:#198754; background:#f0fff4; transform:translateX(4px); color:inherit; }
    .queue-item.active-item   { border-left:4px solid #198754; background:#e8f5e9; }
    .queue-item.unpaid-item   { border-left:4px solid #dc3545; }
    .queue-item.progress-item { border-left:4px solid #0d6efd; background:#f0f4ff; }
    .queue-item.overdue-item  { border-left:4px solid #fd7e14; background:#fff8f0; }
    .result-row.is-abnormal td { background:#fff8f0 !important; }
    .result-row.is-abnormal .result-input { border-color:#fd7e14 !important; color:#c0392b; font-weight:700; }
    .result-input { transition:border-color 0.2s; }
    .status-pip { width:8px; height:8px; border-radius:50%; display:inline-block; }
</style>

<?php if ($success): ?>
<div class="alert alert-success border-0 shadow-sm rounded-3 mb-3 d-flex align-items-center justify-content-between">
    <span><i class="fa-solid fa-check-circle fa-lg me-2"></i><?php echo htmlspecialchars($success); ?></span>
    <div class="d-flex align-items-center gap-2">
        <?php if ($saved_id > 0): ?>
        <a href="print_report.php?id=<?php echo $saved_id; ?>" target="_blank" class="btn btn-sm btn-dark rounded-pill px-3">
            <i class="fa-solid fa-print me-1"></i>Print Report
        </a>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger border-0 shadow-sm rounded-3 mb-3">
    <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if ($overdue_count > 0): ?>
<div class="alert alert-warning border-0 shadow-sm rounded-3 mb-3 d-flex align-items-center gap-2">
    <i class="fa-solid fa-clock fa-lg text-warning"></i>
    <span><strong><?php echo $overdue_count; ?> overdue request<?php echo $overdue_count > 1 ? 's' : ''; ?></strong> waiting over 2 hours — please prioritise.</span>
</div>
<?php endif; ?>

<div class="row g-3">

    <!-- QUEUE -->
    <div class="col-md-4">
        <div class="glass-card h-100 p-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="text-success fw-bold mb-0"><i class="fa-solid fa-list-ul me-1"></i>Queue</h6>
                <div class="d-flex gap-1">
                    <span class="badge bg-success rounded-pill"><?php echo $queue_total; ?></span>
                    <?php if ($overdue_count > 0): ?>
                    <span class="badge bg-warning text-dark rounded-pill"><?php echo $overdue_count; ?> overdue</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Legend -->
            <div class="d-flex gap-3 mb-2" style="font-size:.7rem; color:#666;">
                <span><span class="status-pip me-1" style="background:#198754"></span>Pending</span>
                <span><span class="status-pip me-1" style="background:#0d6efd"></span>In Progress</span>
                <span><span class="status-pip me-1" style="background:#fd7e14"></span>Overdue</span>
            </div>

            <!-- Search -->
            <form method="get" class="mb-3">
                <?php if (isset($_GET['manage_id'])): ?>
                    <input type="hidden" name="manage_id" value="<?php echo intval($_GET['manage_id']); ?>">
                <?php endif; ?>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                    <input type="text" name="q" class="form-control border-start-0"
                           placeholder="Search name or OPD..."
                           value="<?php echo htmlspecialchars($queue_search); ?>"
                           oninput="this.form.submit()">
                    <?php if ($queue_search): ?>
                    <a href="enter_results.php<?php echo isset($_GET['manage_id']) ? '?manage_id='.intval($_GET['manage_id']) : ''; ?>"
                       class="btn btn-outline-secondary btn-sm">✕</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Queue list -->
            <div style="max-height:68vh;overflow-y:auto;">
                <?php if ($queue_total > 0):
                    $position = 1;
                    foreach ($queue_rows as $row):
                        $isActive   = (isset($_GET['manage_id']) && $_GET['manage_id'] == $row['request_id']);
                        $isUnpaid   = ($row['payment_status'] == 'Unpaid');
                        $isProgress = ($row['status'] == 'In Progress');
                        $rowDate    = strtotime($row['request_date']);
                        $ageSeconds = time() - $rowDate;
                        $isOverdue  = ($ageSeconds > 7200 && !$isUnpaid); // 2 hours

                        if ($isActive)   $itemClass = 'active-item';
                        elseif ($isUnpaid)  $itemClass = 'unpaid-item';
                        elseif ($isOverdue) $itemClass = 'overdue-item';
                        elseif ($isProgress)$itemClass = 'progress-item';
                        else               $itemClass = '';

                        $qBase = $queue_search ? '?q='.urlencode($queue_search).'&manage_id=' : '?manage_id=';
                ?>
                <a href="<?php echo $qBase . $row['request_id']; ?>" class="queue-item <?php echo $itemClass; ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div style="min-width:0">
                            <div class="fw-bold text-dark text-truncate" style="font-size:.9rem;">
                                <?php echo htmlspecialchars($row['full_name']); ?>
                            </div>
                            <small class="text-muted">
                                <i class="fa-solid fa-id-card me-1"></i><?php echo htmlspecialchars($row['opd_number']); ?>
                            </small>
                        </div>
                        <div class="text-end ms-2 flex-shrink-0">
                            <?php if ($isUnpaid): ?>
                                <span class="badge bg-danger rounded-pill" style="font-size:.65rem">UNPAID</span>
                            <?php elseif ($isProgress): ?>
                                <span class="badge bg-primary rounded-pill" style="font-size:.65rem">IN PROGRESS</span>
                            <?php else: ?>
                                <span class="badge bg-success rounded-pill" style="font-size:.65rem">PAID</span>
                            <?php endif; ?>
                            <div class="text-muted mt-1" style="font-size:.7rem;">
                                <?php
                                if ($ageSeconds < 3600) echo round($ageSeconds/60) . 'm ago';
                                elseif ($ageSeconds < 86400) echo round($ageSeconds/3600, 1) . 'h ago';
                                else echo date('d M', $rowDate);
                                ?>
                                <?php if ($isOverdue): ?><span class="text-danger fw-bold ms-1">⚠ OVERDUE</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-1 d-flex gap-1 flex-wrap">
                        <span class="badge bg-light text-secondary border" style="font-size:.68rem;">
                            <i class="fa-solid fa-vial me-1"></i><?php echo $row['test_count']; ?> test<?php echo $row['test_count'] != 1 ? 's' : ''; ?>
                        </span>
                        <span class="badge bg-light text-secondary border" style="font-size:.68rem;">
                            #<?php echo $position++; ?> in queue
                        </span>
                    </div>
                </a>
                <?php endforeach; else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-mug-hot fa-2x mb-2 opacity-25"></i><br>
                    <?php echo $queue_search ? 'No results found.' : 'All caught up!'; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RESULT ENTRY -->
    <div class="col-md-8">
        <?php if ($selected_request): ?>

        <!-- Patient Info Bar -->
        <div class="glass-card p-3 mb-3 d-flex flex-wrap gap-3 align-items-center">
            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 text-white"
                 style="width:46px;height:46px;background:<?php echo $selected_request['status']=='In Progress' ? '#0d6efd' : '#198754'; ?>">
                <i class="fa-solid fa-user-injured"></i>
            </div>
            <div class="flex-grow-1">
                <h5 class="mb-0 fw-bold text-dark"><?php echo htmlspecialchars($selected_request['full_name']); ?></h5>
                <div class="d-flex flex-wrap gap-2 mt-1">
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($selected_request['opd_number']); ?></span>
                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($selected_request['age']); ?> yrs / <?php echo htmlspecialchars($selected_request['gender']); ?></span>
                    <span class="badge bg-light text-muted border">Req #<?php echo $selected_request['request_id']; ?></span>
                    <span class="badge bg-light text-muted border"><i class="fa-solid fa-user-doctor me-1"></i>Dr. <?php echo htmlspecialchars($selected_request['requested_by']); ?></span>
                    <span class="badge bg-light text-muted border"><i class="fa-regular fa-clock me-1"></i><?php echo date('d M Y H:i', strtotime($selected_request['request_date'])); ?></span>
                </div>
            </div>
            <div class="d-flex flex-column align-items-end gap-1">
                <?php if ($selected_request['payment_status'] == 'Paid'): ?>
                    <span class="badge bg-success px-3 py-2"><i class="fa-solid fa-circle-check me-1"></i>PAID</span>
                <?php else: ?>
                    <span class="badge bg-danger px-3 py-2"><i class="fa-solid fa-lock me-1"></i>UNPAID</span>
                <?php endif; ?>
                <!-- WORKFLOW: Sample status badge -->
                <?php
                $statusColor = ['Pending'=>'secondary','In Progress'=>'primary','Completed'=>'success'];
                $statusIcon  = ['Pending'=>'fa-hourglass-half','In Progress'=>'fa-flask','Completed'=>'fa-circle-check'];
                $st = $selected_request['status'];
                ?>
                <span class="badge bg-<?php echo $statusColor[$st] ?? 'secondary'; ?> px-3 py-2">
                    <i class="fa-solid <?php echo $statusIcon[$st] ?? 'fa-circle'; ?> me-1"></i><?php echo $st; ?>
                </span>
            </div>
        </div>

        <!-- Sample Status Track -->
        <div class="glass-card p-3 mb-3">
            <div class="d-flex align-items-center justify-content-between">
                <small class="text-muted fw-bold text-uppercase" style="letter-spacing:1px;font-size:.68rem;">Sample Tracking</small>
                <small class="text-muted"><?php echo date('d M Y H:i', strtotime($selected_request['request_date'])); ?></small>
            </div>
            <div class="d-flex align-items-center mt-2 gap-2">
                <?php
                $steps = ['Pending' => 1, 'In Progress' => 2, 'Completed' => 3];
                $current_step = $steps[$selected_request['status']] ?? 1;
                $step_labels = ['Received', 'Processing', 'Done'];
                $step_icons  = ['fa-inbox', 'fa-flask', 'fa-circle-check'];
                foreach ($step_labels as $i => $label):
                    $step_num = $i + 1;
                    $done     = $current_step > $step_num;
                    $active   = $current_step === $step_num;
                ?>
                <div class="text-center flex-grow-1">
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-1"
                         style="width:32px;height:32px;font-size:.8rem;
                                background:<?php echo $done ? '#198754' : ($active ? '#0d6efd' : '#dee2e6'); ?>;
                                color:<?php echo ($done || $active) ? 'white' : '#666'; ?>">
                        <i class="fa-solid <?php echo $done ? 'fa-check' : $step_icons[$i]; ?>"></i>
                    </div>
                    <div style="font-size:.7rem;font-weight:<?php echo $active ? '700' : '400'; ?>;
                                color:<?php echo $active ? '#0d6efd' : ($done ? '#198754' : '#999'); ?>">
                        <?php echo $label; ?>
                    </div>
                </div>
                <?php if ($i < 2): ?>
                <div class="flex-grow-1" style="height:2px;background:<?php echo $current_step > $i+1 ? '#198754' : '#dee2e6'; ?>;margin-bottom:18px;"></div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- CLINICAL FLAGS ALERT -->
        <?php
        $flag_stmt = $conn->prepare("SELECT * FROM clinical_flags WHERE patient_id = (SELECT patient_id FROM lab_requests WHERE request_id = ?) AND is_resolved = 0 ORDER BY FIELD(flag_type,'Critical','Allergy','Warning','Info'), created_at DESC");
        $flag_stmt->bind_param("i", $selected_request['request_id']);
        $flag_stmt->execute();
        $patient_flags = $flag_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $flag_stmt->close();
        ?>
        <?php if (!empty($patient_flags)): ?>
        <div class="mb-3">
            <?php
            $flag_colors = ['Warning'=>'warning','Allergy'=>'danger','Critical'=>'danger','Info'=>'info'];
            $flag_icons  = ['Warning'=>'fa-triangle-exclamation','Allergy'=>'fa-allergies','Critical'=>'fa-circle-exclamation','Info'=>'fa-circle-info'];
            foreach ($patient_flags as $flag):
                $fc = $flag_colors[$flag['flag_type']] ?? 'warning';
                $fi = $flag_icons[$flag['flag_type']] ?? 'fa-triangle-exclamation';
            ?>
            <div class="alert alert-<?php echo $fc; ?> border-0 shadow-sm rounded-3 d-flex align-items-start gap-3 mb-2">
                <i class="fa-solid <?php echo $fi; ?> fa-lg mt-1 flex-shrink-0"></i>
                <div>
                    <div class="fw-bold"><?php echo $flag['flag_type']; ?> — Dr. <?php echo htmlspecialchars($flag['flagged_by']); ?></div>
                    <div><?php echo htmlspecialchars($flag['flag_note']); ?></div>
                    <small class="opacity-75"><?php echo date('d M Y H:i', strtotime($flag['created_at'])); ?></small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Payment Warning -->
        <?php if ($selected_request['payment_status'] == 'Unpaid'): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-3 mb-3 d-flex align-items-center gap-3">
            <i class="fa-solid fa-triangle-exclamation fa-xl text-warning"></i>
            <div>
                <strong>Payment Required</strong> — Result entry is locked until payment is cleared.
                <a href="billing.php" class="btn btn-sm btn-warning ms-2 rounded-pill">Go to Billing</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Results Form -->
        <div class="glass-card">
            <div class="card-header text-white p-3 border-0"
                 style="border-radius:14px 14px 0 0;background:<?php echo $selected_request['status']=='In Progress' ? '#0d6efd' : '#198754'; ?>">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="fa-solid fa-microscope me-2"></i>Enter Test Results</span>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="badge bg-white text-dark" id="abnormalBadge" style="display:none;">
                            <i class="fa-solid fa-triangle-exclamation text-warning me-1"></i><span id="abnormalCount">0</span> Abnormal
                        </span>
                        <span class="badge bg-white text-dark">Req #<?php echo $selected_request['request_id']; ?></span>
                    </div>
                </div>
            </div>

            <div class="card-body p-0">
                <form method="post" id="resultsForm">
                    <input type="hidden" name="request_id" value="<?php echo $selected_request['request_id']; ?>">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3" style="width:28%">Test</th>
                                    <th style="width:28%">Result Value</th>
                                    <th style="width:20%">Reference Range</th>
                                    <th style="width:24%">Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($request_tests && $request_tests->num_rows > 0):
                                while ($test = $request_tests->fetch_assoc()):
                                    $isPaid      = ($selected_request['payment_status'] == 'Paid');
                                    $existingVal = ($test['result_value'] !== 'Pending') ? $test['result_value'] : '';
                            ?>
                            <tr class="result-row">
                                <td class="ps-3">
                                    <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($test['test_name']); ?></span>
                                    <?php if ($test['units']): ?>
                                        <small class="text-muted">(<?php echo htmlspecialchars($test['units']); ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="text"
                                           name="results[<?php echo $test['result_id']; ?>]"
                                           class="form-control result-input"
                                           value="<?php echo htmlspecialchars($existingVal); ?>"
                                           placeholder="Enter result…"
                                           <?php echo $isPaid ? 'required' : 'disabled'; ?>>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-2 py-1 d-block text-center" style="font-size:.78rem;">
                                        <?php echo htmlspecialchars($test['normal_range'] ?: '—'); ?>
                                    </span>
                                </td>
                                <td>
                                    <input type="text"
                                           name="comments[<?php echo $test['result_id']; ?>]"
                                           class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($test['technician_remarks'] ?? ''); ?>"
                                           placeholder="Optional…"
                                           <?php echo $isPaid ? '' : 'disabled'; ?>>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-5">No tests found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center p-3 border-top bg-light" style="border-radius:0 0 14px 14px;">
                        <small class="text-muted"><i class="fa-solid fa-circle-info me-1"></i>Orange highlight = abnormal value detected.</small>
                        <div class="d-flex gap-2">
                            <a href="enter_results.php<?php echo $queue_search ? '?q='.urlencode($queue_search) : ''; ?>"
                               class="btn btn-light border btn-sm rounded-pill px-3">Cancel</a>
                            <?php if ($selected_request['payment_status'] == 'Unpaid'): ?>
                            <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" disabled>
                                <i class="fa-solid fa-lock me-1"></i>Awaiting Payment
                            </button>
                            <?php else: ?>
                            <button type="submit" name="save_results" id="submitBtn"
                                    class="btn btn-sm rounded-pill px-4 shadow-sm text-white"
                                    style="background:<?php echo $selected_request['status']=='In Progress' ? '#0d6efd' : '#198754'; ?>">
                                <i class="fa-solid fa-check-double me-1"></i>Save & Complete
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <div class="glass-card text-center p-5 text-muted d-flex flex-column justify-content-center align-items-center" style="min-height:400px;">
            <div class="bg-light rounded-circle p-4 mb-3">
                <i class="fa-solid fa-vial fa-4x text-success opacity-25"></i>
            </div>
            <h5 class="fw-bold">Select a Request</h5>
            <p class="mb-0">Click a patient from the queue on the left.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const ABNORMAL = ['pos','high','elev','abnorm','react','+'];
function checkAbnormal(input) {
    const row = input.closest('.result-row');
    const val = input.value.trim().toLowerCase();
    const abn = val !== '' && ABNORMAL.some(k => val.includes(k));
    row.classList.toggle('is-abnormal', abn);
    input.classList.toggle('border-warning', abn);
    const count = document.querySelectorAll('.result-row.is-abnormal').length;
    const badge = document.getElementById('abnormalBadge');
    const span  = document.getElementById('abnormalCount');
    if (badge) { span.textContent = count; badge.style.display = count > 0 ? '' : 'none'; }
}
document.querySelectorAll('.result-input').forEach(inp => {
    checkAbnormal(inp);
    inp.addEventListener('input', () => checkAbnormal(inp));
});
const form = document.getElementById('resultsForm');
const btn  = document.getElementById('submitBtn');
if (form && btn) {
    form.addEventListener('submit', () => {
        // Delay disable so the button value is included in POST
        setTimeout(() => {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
        }, 100);
    });
}
</script>

<?php include 'includes/footer.php'; ?>