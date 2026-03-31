<?php
ob_start();
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$role    = $_SESSION['role'];
$patient = null;
$history = [];
$error   = "";
$flag_success = "";

// --- HANDLE FLAG SUBMISSION ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_flag'])) {
    $flag_pid   = intval($_POST['flag_patient_id']);
    $flag_rid   = intval($_POST['flag_request_id'] ?? 0);
    $flag_note  = trim($_POST['flag_note']);
    $flag_type  = $_POST['flag_type'] ?? 'Warning';
    $flagged_by = $_SESSION['full_name'] ?? 'Doctor';
    $allowed_types = ['Warning','Allergy','Critical','Info'];

    if ($flag_pid > 0 && !empty($flag_note) && in_array($flag_type, $allowed_types)) {
        $stmt = $conn->prepare("INSERT INTO clinical_flags (patient_id, request_id, flagged_by, flag_note, flag_type) VALUES (?, ?, ?, ?, ?)");
        $null_rid = $flag_rid > 0 ? $flag_rid : null;
        $stmt->bind_param("iisss", $flag_pid, $null_rid, $flagged_by, $flag_note, $flag_type);
        if ($stmt->execute()) {
            // Notify LabTech
            $pat_stmt = $conn->prepare("SELECT full_name FROM patients WHERE patient_id = ?");
            $pat_stmt->bind_param("i", $flag_pid);
            $pat_stmt->execute();
            $pat_row  = $pat_stmt->get_result()->fetch_assoc();
            $pat_name = $conn->real_escape_string($pat_row['full_name'] ?? "Patient #$flag_pid");
            $pat_stmt->close();
            $notif_msg = $conn->real_escape_string("⚠ Clinical Flag [$flag_type]: $pat_name — " . substr($flag_note, 0, 60));
            $notif_lnk = $conn->real_escape_string("enter_results.php");
            $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('LabTech', '$notif_msg', '$notif_lnk', 0, NOW())");
            $flag_success = "Flag sent to Lab Tech successfully.";
        }
        $stmt->close();
    } else {
        $error = "Please fill in the flag note.";
    }
}

// --- RESOLVE FLAG ---
if (isset($_GET['resolve']) && intval($_GET['resolve']) > 0 && ($role == 'Doctor' || $role == 'Admin')) {
    $fid  = intval($_GET['resolve']);
    $stmt = $conn->prepare("UPDATE clinical_flags SET is_resolved = 1 WHERE flag_id = ?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $stmt->close();
    // Redirect back cleanly
    $back_pid = intval($_GET['patient_id'] ?? 0);
    header("Location: patient_history.php?patient_id=$back_pid");
    exit;
}

// --- SEARCH ---
if (isset($_GET['search']) && trim($_GET['search']) !== '') {
    $q    = trim($_GET['search']);
    $stmt = $conn->prepare(
        "SELECT * FROM patients
         WHERE opd_number LIKE ? OR full_name LIKE ?
         ORDER BY registered_at DESC LIMIT 20"
    );
    $like = "%$q%";
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $search_results = $stmt->get_result();
    $stmt->close();
} else {
    $search_results = null;
}

// --- LOAD PATIENT HISTORY ---
if (isset($_GET['patient_id']) && intval($_GET['patient_id']) > 0) {
    $pid = intval($_GET['patient_id']);

    // Patient details
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($patient) {
        // All requests for this patient
        $stmt = $conn->prepare(
            "SELECT r.request_id, r.requested_by, r.request_date, r.status,
                    r.payment_status,
                    COALESCE((SELECT SUM(lt.cost) FROM test_results tr
                               JOIN lab_tests lt ON tr.test_id = lt.test_id
                               WHERE tr.request_id = r.request_id), 0) as total_cost,
                    (SELECT COUNT(*) FROM test_results tr WHERE tr.request_id = r.request_id) as test_count
             FROM lab_requests r
             WHERE r.patient_id = ?
             ORDER BY r.request_date DESC"
        );
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $requests = $stmt->get_result();
        $stmt->close();

        while ($req = $requests->fetch_assoc()) {
            // Fetch tests for this request
            $stmt2 = $conn->prepare(
                "SELECT t.test_name, t.units, t.normal_range, tr.result_value, tr.technician_remarks
                 FROM test_results tr
                 JOIN lab_tests t ON tr.test_id = t.test_id
                 WHERE tr.request_id = ?
                 ORDER BY t.test_name"
            );
            $stmt2->bind_param("i", $req['request_id']);
            $stmt2->execute();
            $req['tests'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt2->close();

            // Payment info
            $stmt3 = $conn->prepare(
                "SELECT amount_paid, payment_method, reference_no, payment_date
                 FROM payments WHERE request_id = ? LIMIT 1"
            );
            $stmt3->bind_param("i", $req['request_id']);
            $stmt3->execute();
            $req['payment'] = $stmt3->get_result()->fetch_assoc();
            $stmt3->close();

            $history[] = $req;
        }
    }
}

// Summary stats for patient
$total_visits   = count($history);
$total_spent    = array_sum(array_column($history, 'total_cost'));
$completed      = count(array_filter($history, fn($r) => $r['status'] === 'Completed'));
$last_visit     = $history[0]['request_date'] ?? null;

$page_title = "Patient History - Yala LIMS";
include 'includes/header.php';
?>

<style>
    .glass-card { background:rgba(255,255,255,0.97); border:none; border-radius:14px; box-shadow:0 4px 20px rgba(31,38,135,0.09); }
    .section-title { font-size:.7rem; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#90a4ae; }
    .patient-banner { background:linear-gradient(135deg,#1565C0,#0288D1); border-radius:14px; color:white; }
    .stat-pill { background:rgba(255,255,255,0.15); border-radius:10px; padding:10px 16px; text-align:center; }
    .search-result-item { border-radius:10px; border:1px solid #eee; padding:12px 16px; cursor:pointer; transition:all .15s; text-decoration:none; display:block; color:inherit; }
    .search-result-item:hover { background:#f0f4ff; border-color:#1565C0; color:inherit; }
    .timeline-item { border-left:3px solid #e0e0e0; padding-left:20px; margin-bottom:0; position:relative; }
    .timeline-item::before { content:''; position:absolute; left:-7px; top:16px; width:12px; height:12px; border-radius:50%; background:#e0e0e0; border:2px solid white; }
    .timeline-item.completed::before { background:#198754; }
    .timeline-item.pending::before { background:#ffc107; }
    .timeline-item.progress::before { background:#0d6efd; }
    .test-result-normal { color:#198754; font-weight:600; }
    .test-result-abnormal { color:#dc3545; font-weight:700; }
    .badge-status-Completed { background:#d4edda; color:#155724; }
    .badge-status-Pending   { background:#fff3cd; color:#856404; }
    .badge-status-In.Progress { background:#cce5ff; color:#004085; }
</style>

<!-- PAGE HEADER -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="fw-bold text-dark mb-0">
            <i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>Patient History
        </h4>
        <small class="text-muted">Search and view complete patient records</small>
    </div>
</div>

<!-- SEARCH BAR -->
<div class="glass-card p-3 mb-4">
    <form method="get" action="patient_history.php" class="d-flex gap-2">
        <div class="input-group">
            <span class="input-group-text bg-white">
                <i class="fa-solid fa-magnifying-glass text-muted"></i>
            </span>
            <input type="text" name="search" class="form-control form-control-lg"
                   placeholder="Search by patient name or OPD number..."
                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                   autofocus>
            <button class="btn btn-primary px-4" type="submit">Search</button>
            <?php if(isset($_GET['search']) || isset($_GET['patient_id'])): ?>
            <a href="patient_history.php" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- SEARCH RESULTS LIST -->
<?php if ($search_results !== null): ?>
    <?php if ($search_results->num_rows > 0): ?>
    <div class="glass-card p-3 mb-4">
        <p class="section-title mb-3">Search Results — <?php echo $search_results->num_rows; ?> found</p>
        <div class="row g-2">
            <?php while ($row = $search_results->fetch_assoc()): ?>
            <div class="col-md-6">
                <a href="patient_history.php?patient_id=<?php echo $row['patient_id']; ?>" class="search-result-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['full_name']); ?></div>
                            <small class="text-muted">
                                <i class="fa-solid fa-id-card me-1"></i><?php echo htmlspecialchars($row['opd_number']); ?>
                                &bull; <?php echo $row['age']; ?> yrs / <?php echo $row['gender']; ?>
                                <?php if($row['phone_number']): ?>
                                &bull; <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($row['phone_number']); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="text-end">
                            <small class="text-muted d-block"><?php echo date('d M Y', strtotime($row['registered_at'])); ?></small>
                            <i class="fa-solid fa-chevron-right text-muted"></i>
                        </div>
                    </div>
                </a>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="glass-card p-4 text-center text-muted mb-4">
        <i class="fa-solid fa-user-slash fa-2x mb-2 opacity-25 d-block"></i>
        No patients found matching "<?php echo htmlspecialchars($_GET['search']); ?>"
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- PATIENT PROFILE + HISTORY -->
<?php if ($patient): ?>

<!-- Patient Banner -->
<div class="patient-banner p-4 mb-4">
    <div class="row align-items-center g-3">
        <div class="col-auto">
            <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center"
                 style="width:64px;height:64px;font-size:1.5rem;">
                <i class="fa-solid fa-user-injured"></i>
            </div>
        </div>
        <div class="col">
            <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($patient['full_name']); ?></h4>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-white text-primary">OPD: <?php echo htmlspecialchars($patient['opd_number']); ?></span>
                <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25">
                    <?php echo $patient['age']; ?> yrs / <?php echo $patient['gender']; ?>
                </span>
                <?php if($patient['phone_number']): ?>
                <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25">
                    <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($patient['phone_number']); ?>
                </span>
                <?php endif; ?>
                <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25">
                    Registered: <?php echo date('d M Y', strtotime($patient['registered_at'])); ?>
                </span>
            </div>
        </div>
        <!-- Summary Stats -->
        <div class="col-auto d-none d-md-flex gap-3">
            <div class="stat-pill">
                <div class="fw-bold fs-4"><?php echo $total_visits; ?></div>
                <div style="font-size:.75rem;opacity:.8;">Visits</div>
            </div>
            <div class="stat-pill">
                <div class="fw-bold fs-4"><?php echo $completed; ?></div>
                <div style="font-size:.75rem;opacity:.8;">Completed</div>
            </div>
            <div class="stat-pill">
                <div class="fw-bold fs-5">KES <?php echo number_format($total_spent); ?></div>
                <div style="font-size:.75rem;opacity:.8;">Total Spent</div>
            </div>
        </div>
    </div>
</div>

<?php
// Fetch existing flags for this patient
$flags_result = $conn->prepare("SELECT * FROM clinical_flags WHERE patient_id = ? AND is_resolved = 0 ORDER BY created_at DESC");
$flags_result->bind_param("i", $patient['patient_id']);
$flags_result->execute();
$active_flags = $flags_result->get_result()->fetch_all(MYSQLI_ASSOC);
$flags_result->close();
?>

<?php if (!empty($active_flags)): ?>
<div class="mb-4">
    <p class="section-title mb-2">Active Clinical Flags</p>
    <?php foreach ($active_flags as $flag):
        $flag_colors = ['Warning'=>'warning','Allergy'=>'danger','Critical'=>'danger','Info'=>'info'];
        $flag_icons  = ['Warning'=>'fa-triangle-exclamation','Allergy'=>'fa-allergies','Critical'=>'fa-circle-exclamation','Info'=>'fa-circle-info'];
        $fc = $flag_colors[$flag['flag_type']] ?? 'warning';
        $fi = $flag_icons[$flag['flag_type']] ?? 'fa-triangle-exclamation';
    ?>
    <div class="alert alert-<?php echo $fc; ?> border-0 shadow-sm rounded-3 d-flex justify-content-between align-items-start gap-3 mb-2">
        <div class="d-flex gap-3 align-items-start">
            <i class="fa-solid <?php echo $fi; ?> fa-lg mt-1"></i>
            <div>
                <div class="fw-bold"><?php echo htmlspecialchars($flag['flag_type']); ?> — flagged by Dr. <?php echo htmlspecialchars($flag['flagged_by']); ?></div>
                <div><?php echo htmlspecialchars($flag['flag_note']); ?></div>
                <small class="opacity-75"><?php echo date('d M Y H:i', strtotime($flag['created_at'])); ?></small>
            </div>
        </div>
        <?php if ($role == 'Doctor' || $role == 'Admin'): ?>
        <a href="patient_history.php?patient_id=<?php echo $patient['patient_id']; ?>&resolve=<?php echo $flag['flag_id']; ?>"
           class="btn btn-sm btn-outline-<?php echo $fc; ?> rounded-pill flex-shrink-0">Resolve</a>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($flag_success): ?>
<div class="alert alert-success border-0 shadow-sm rounded-3 mb-3">
    <i class="fa-solid fa-circle-check me-2"></i><?php echo $flag_success; ?>
</div>
<?php endif; ?>

<?php if (empty($history)): ?>
<div class="glass-card p-5 text-center text-muted">
    <i class="fa-solid fa-folder-open fa-2x mb-2 opacity-25 d-block"></i>
    No lab requests found for this patient.
</div>
<?php else: ?>

<!-- HISTORY TIMELINE -->
<div class="glass-card p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <p class="section-title mb-0">Visit History — <?php echo $total_visits; ?> request<?php echo $total_visits != 1 ? 's' : ''; ?></p>
        <?php if ($completed > 0): ?>
        <a href="print_report.php?id=<?php echo $history[0]['request_id']; ?>" target="_blank"
           class="btn btn-sm btn-outline-danger rounded-pill px-3">
            <i class="fa-solid fa-print me-1"></i>Latest Report
        </a>
        <?php endif; ?>
    </div>

    <?php foreach ($history as $i => $req):
        $statusClass = $req['status'] === 'Completed' ? 'completed' : ($req['status'] === 'In Progress' ? 'progress' : 'pending');
        $statusColors = ['Completed'=>'success','Pending'=>'warning','In Progress'=>'primary'];
        $statusColor  = $statusColors[$req['status']] ?? 'secondary';
        $isLast = ($i === count($history) - 1);
    ?>
    <div class="timeline-item <?php echo $statusClass; ?> <?php echo $isLast ? 'pb-0' : 'pb-4'; ?>">
        <!-- Request Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="fw-bold text-dark">Request #<?php echo $req['request_id']; ?></span>
                    <span class="badge bg-<?php echo $statusColor; ?> bg-opacity-15 text-<?php echo $statusColor; ?> border border-<?php echo $statusColor; ?>" style="border-opacity:.3;">
                        <?php echo $req['status']; ?>
                    </span>
                    <?php if($req['payment_status'] === 'Paid'): ?>
                    <span class="badge bg-success bg-opacity-10 text-success border border-success" style="border-opacity:.3;">Paid</span>
                    <?php else: ?>
                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger" style="border-opacity:.3;">Unpaid</span>
                    <?php endif; ?>
                </div>
                <small class="text-muted">
                    <i class="fa-regular fa-calendar me-1"></i><?php echo date('d M Y H:i', strtotime($req['request_date'])); ?>
                    &bull; <i class="fa-solid fa-user-doctor me-1"></i>Dr. <?php echo htmlspecialchars($req['requested_by']); ?>
                    &bull; <i class="fa-solid fa-vial me-1"></i><?php echo $req['test_count']; ?> test<?php echo $req['test_count'] != 1 ? 's' : ''; ?>
                    &bull; KES <?php echo number_format($req['total_cost']); ?>
                </small>
            </div>
            <div class="d-flex gap-2">
                <?php if ($req['status'] === 'Completed'): ?>
                <a href="print_report.php?id=<?php echo $req['request_id']; ?>" target="_blank"
                   class="btn btn-sm btn-outline-danger rounded-pill px-3">
                    <i class="fa-solid fa-print me-1"></i>PDF
                </a>
                <?php endif; ?>
                <?php if ($req['payment'] && $req['payment_status'] === 'Paid'): ?>
                <a href="print_receipt.php?id=<?php echo $req['request_id']; ?>" target="_blank"
                   class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                    <i class="fa-solid fa-receipt me-1"></i>Receipt
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- FLAG FOR LAB BUTTON (Doctors only) -->
        <?php if ($role == 'Doctor' || $role == 'Admin'): ?>
        <div class="mb-3">
            <button class="btn btn-sm btn-outline-warning rounded-pill px-3"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#flagForm<?php echo $req['request_id']; ?>">
                <i class="fa-solid fa-flag me-1"></i>Flag for Lab Tech
            </button>
            <div class="collapse mt-2" id="flagForm<?php echo $req['request_id']; ?>">
                <div class="card card-body border-warning border-opacity-50 bg-warning bg-opacity-10 rounded-3">
                    <form method="post" action="patient_history.php?patient_id=<?php echo $patient['patient_id']; ?>">
                        <input type="hidden" name="flag_patient_id" value="<?php echo $patient['patient_id']; ?>">
                        <input type="hidden" name="flag_request_id" value="<?php echo $req['request_id']; ?>">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold mb-1">Flag Type</label>
                                <select name="flag_type" class="form-select form-select-sm">
                                    <option value="Warning">⚠ Warning</option>
                                    <option value="Allergy">🚫 Allergy</option>
                                    <option value="Critical">🔴 Critical</option>
                                    <option value="Info">ℹ Info</option>
                                </select>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label small fw-bold mb-1">Note for Lab Tech</label>
                                <input type="text" name="flag_note" class="form-control form-control-sm"
                                       placeholder="e.g. Patient is diabetic, previous high glucose — handle with care"
                                       required>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="submit_flag" class="btn btn-warning btn-sm w-100 rounded-pill">
                                    <i class="fa-solid fa-paper-plane me-1"></i>Send
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Test Results Table -->
        <?php if (!empty($req['tests'])): ?>
        <div class="table-responsive mb-2">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem;">
                <thead class="table-light">
                    <tr>
                        <th>Test</th>
                        <th>Result</th>
                        <th>Reference Range</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($req['tests'] as $test):
                        $val     = $test['result_value'];
                        $isPend  = ($val === 'Pending');
                        $isAbnorm= !$isPend && (stripos($val,'pos')!==false || stripos($val,'high')!==false || stripos($val,'elev')!==false);
                    ?>
                    <tr>
                        <td class="fw-medium">
                            <?php echo htmlspecialchars($test['test_name']); ?>
                            <?php if($test['units']): ?>
                                <small class="text-muted">(<?php echo htmlspecialchars($test['units']); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="<?php echo $isPend ? 'text-muted fst-italic' : ($isAbnorm ? 'test-result-abnormal' : 'test-result-normal'); ?>">
                            <?php if($isAbnorm): ?>
                                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($val); ?>
                        </td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($test['normal_range'] ?: '—'); ?></small></td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($test['technician_remarks'] ?: '—'); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Payment Info -->
        <?php if ($req['payment']): ?>
        <div class="d-flex align-items-center gap-2 mt-1">
            <i class="fa-solid fa-money-bill-wave text-success small"></i>
            <small class="text-muted">
                Paid KES <?php echo number_format($req['payment']['amount_paid'], 2); ?>
                via <?php echo htmlspecialchars($req['payment']['payment_method']); ?>
                &bull; Ref: <code><?php echo htmlspecialchars($req['payment']['reference_no']); ?></code>
                &bull; <?php echo date('d M Y H:i', strtotime($req['payment']['payment_date'])); ?>
            </small>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>
<?php endif; ?>

<!-- EMPTY STATE - no search yet -->
<?php if (!isset($_GET['search']) && !isset($_GET['patient_id'])): ?>
<div class="glass-card p-5 text-center text-muted">
    <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px;height:80px;">
        <i class="fa-solid fa-magnifying-glass fa-2x text-secondary opacity-50"></i>
    </div>
    <h5 class="fw-bold">Search for a Patient</h5>
    <p class="mb-0">Enter a patient name or OPD number above to view their full history.</p>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>