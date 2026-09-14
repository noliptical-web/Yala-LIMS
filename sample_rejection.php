<?php
// sample_rejection.php
// Specimen Rejection & Phlebotomy Quality Assurance Console (ISO 15189 / MOH Standards)
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';
require_once 'includes/notifications_helper.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$role = $_SESSION['role'] ?? '';
$my_user = $_SESSION['full_name'] ?? 'Lab Tech';
$isAdmin = ($role === 'Admin');
$isLabTech = ($role === 'LabTech' || $role === 'Lab Technician' || $role === 'LabTechnician');
$isDoctor = ($role === 'Doctor');

// Receptionists cannot manage lab quality logs
if (!$isAdmin && !$isLabTech && !$isDoctor) {
    header("location: dashboard.php"); exit;
}

$success = $error = "";

// ── ACTION: LOG NEW REJECTION ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_rejection'])) {
    csrf_verify();
    if ($isDoctor) {
        $error = "Only laboratory staff and administrators can reject specimens.";
    } else {
        $req_id   = intval($_POST['request_id'] ?? 0);
        $reason   = trim($_POST['rejection_reason'] ?? '');
        $sample   = trim($_POST['sample_type'] ?? 'Whole Blood (EDTA)');
        $comments = trim($_POST['comments'] ?? '');

        if ($req_id <= 0 || empty($reason)) {
            $error = "Please specify both a valid Request ID and a rejection reason.";
        } else {
            $q_req = $conn->query("SELECT r.request_id, r.patient_id, p.full_name, r.requested_by FROM lab_requests r JOIN patients p ON r.patient_id=p.patient_id WHERE r.request_id=$req_id");
            if ($q_req && $q_req->num_rows > 0) {
                $row = $q_req->fetch_assoc();
                $pid = intval($row['patient_id']);
                $pat_name = $row['full_name'];

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO sample_rejections (request_id, patient_id, rejection_reason, sample_type, rejected_by, comments, status) VALUES (?,?,?,?,?,?, 'Recollection Pending')");
                    $stmt->bind_param("iissss", $req_id, $pid, $reason, $sample, $my_user, $comments);
                    $stmt->execute();
                    $stmt->close();

                    $conn->query("UPDATE lab_requests SET status = 'Rejected' WHERE request_id = $req_id");
                    $conn->query("UPDATE test_results SET result_value = 'REJECTED: " . $conn->real_escape_string($reason) . "', technician_remarks = '" . $conn->real_escape_string($comments) . "' WHERE request_id = $req_id");

                    // Clinical notification
                    $notif_msg = "⚠️ SPECIMEN REJECTED: $pat_name (Req #$req_id) - Reason: $reason. Recollection requested.";
                    $notif_link = "sample_rejection.php?req_id=$req_id";
                    create_notification($conn, 'Doctor', $notif_msg, $notif_link, 'Warning', 'Specimen QA');
                    create_notification($conn, 'Admin',  $notif_msg, $notif_link, 'Warning', 'Specimen QA');

                    audit_log($conn, 'reject_specimen', 'lab_requests', $req_id, "Specimen rejected: $reason for $pat_name");
                    $conn->commit();
                    $success = "Specimen for Request #$req_id ($pat_name) rejected. Phlebotomist & Clinician alerted.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Error recording rejection: " . $e->getMessage();
                }
            } else {
                $error = "Request #$req_id does not exist.";
            }
        }
    }
}

// ── ACTION: MARK RECOLLECTED (REDRAW RECEIVED) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_recollected'])) {
    csrf_verify();
    if ($isDoctor) {
        $error = "Only laboratory staff can confirm sample redraw receipt.";
    } else {
        $rej_id = intval($_POST['rejection_id'] ?? 0);
        $req_id = intval($_POST['request_id'] ?? 0);

        if ($rej_id > 0 && $req_id > 0) {
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE sample_rejections SET status = 'Recollected' WHERE rejection_id = $rej_id");
                $conn->query("UPDATE lab_requests SET status = 'In Progress' WHERE request_id = $req_id");
                $conn->query("UPDATE test_results SET result_value = 'Pending', technician_remarks = 'Recollection specimen received; undergoing testing' WHERE request_id = $req_id");
                
                $pn_res = $conn->query("SELECT p.full_name FROM lab_requests r JOIN patients p ON r.patient_id=p.patient_id WHERE r.request_id = $req_id");
                $pname_str = ($pn_res && $prow = $pn_res->fetch_assoc()) ? $prow['full_name'] : "Patient";
                create_notification($conn, 'Doctor', "Redraw Received: Req #$req_id ($pname_str) replacement specimen received at lab bench.", "enter_results.php?manage_id=$req_id", 'Info', 'Specimen QA');

                audit_log($conn, 'sample_recollected', 'sample_rejections', $rej_id, "Redraw received for Request #$req_id");
                $conn->commit();
                $success = "Redraw received for Request #$req_id. Test reset to 'In Progress' on workbench.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to update status: " . $e->getMessage();
            }
        }
    }
}

// ── ACTION: CANCEL REQUEST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request'])) {
    csrf_verify();
    $rej_id = intval($_POST['rejection_id'] ?? 0);
    $req_id = intval($_POST['request_id'] ?? 0);
    if ($rej_id > 0 && $req_id > 0) {
        $conn->query("UPDATE sample_rejections SET status = 'Cancelled' WHERE rejection_id = $rej_id");
        audit_log($conn, 'cancel_rejection_redraw', 'sample_rejections', $rej_id, "Cancelled redraw for Req #$req_id");
        $success = "Recollection cancelled for Request #$req_id.";
    }
}

// ── QUALITY INDICATORS & KPIs ───────────────────────────────────────────────
$total_requests  = $conn->query("SELECT COUNT(*) FROM lab_requests")->fetch_row()[0] ?? 1;
if ($total_requests == 0) $total_requests = 1;

$total_rejections = $conn->query("SELECT COUNT(*) FROM sample_rejections")->fetch_row()[0] ?? 0;
$pending_redraws  = $conn->query("SELECT COUNT(*) FROM sample_rejections WHERE status = 'Recollection Pending'")->fetch_row()[0] ?? 0;
$recollected_cnt  = $conn->query("SELECT COUNT(*) FROM sample_rejections WHERE status = 'Recollected'")->fetch_row()[0] ?? 0;

$rejection_rate   = round(($total_rejections / $total_requests) * 100, 2);
$is_rate_optimal  = $rejection_rate <= 2.0;

// Reasons breakdown
$reasons_q = $conn->query("SELECT rejection_reason, COUNT(*) as cnt FROM sample_rejections GROUP BY rejection_reason ORDER BY cnt DESC");
$reasons_breakdown = [];
if ($reasons_q) {
    while ($rb = $reasons_q->fetch_assoc()) {
        $reasons_breakdown[] = $rb;
    }
}

// Search & Filter
$filter_status = trim($_GET['status'] ?? 'all');
$search_q      = trim($_GET['q'] ?? '');

$sql_list = "SELECT sr.*, p.full_name, p.opd_number, p.age, p.gender, r.requested_by, r.request_date
             FROM sample_rejections sr
             JOIN patients p ON sr.patient_id = p.patient_id
             JOIN lab_requests r ON sr.request_id = r.request_id
             WHERE 1=1";

if ($filter_status !== 'all') {
    $fs = $conn->real_escape_string($filter_status);
    $sql_list .= " AND sr.status = '$fs'";
}
if (!empty($search_q)) {
    $sq = $conn->real_escape_string($search_q);
    $sql_list .= " AND (p.full_name LIKE '%$sq%' OR p.opd_number LIKE '%$sq%' OR sr.rejection_reason LIKE '%$sq%' OR sr.request_id = '$sq')";
}
$sql_list .= " ORDER BY sr.rejection_date DESC";
$rejections_res = $conn->query($sql_list);

// List of active pending/in progress requests for the modal
$active_reqs = $conn->query("SELECT r.request_id, p.full_name, p.opd_number FROM lab_requests r JOIN patients p ON r.patient_id=p.patient_id WHERE r.status IN ('Pending','In Progress') ORDER BY r.request_id DESC LIMIT 30");

$page_title = "Specimen Rejection & Phlebotomy QA";
include 'includes/header.php';
?>

<div class="container-fluid px-4 py-3">

    <!-- Header Banner -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"
         style="background:linear-gradient(135deg,#450a0a 0%,#7f1d1d 60%,#991b1b 100%);padding:22px 28px;border-radius:14px;color:#fff;">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h3 class="fw-bold mb-0 text-white">Specimen Rejection &amp; Phlebotomy QA</h3>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1" style="font-size:0.75rem;">ISO 15189:2022</span>
            </div>
            <p class="mb-0 text-white-50" style="font-size:0.88rem;">
                MOH Quality Indicator: Specimen acceptability tracking, pre-analytical error control &amp; redraw management.
            </p>
        </div>
        <div class="d-flex gap-2">
            <?php if (!$isDoctor): ?>
            <button class="btn btn-light fw-bold text-danger px-3 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> Log Specimen Rejection
            </button>
            <?php endif; ?>
            <a href="dashboard.php" class="btn btn-outline-light rounded-pill px-3">
                <i class="fa-solid fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <!-- Alert banners -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><?= htmlspecialchars($success) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
        <i class="fa-solid fa-circle-exclamation fs-5"></i>
        <div><?= htmlspecialchars($error) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- KPI Metric Cards -->
    <div class="row g-3 mb-4">
        <!-- Rejection Rate Indicator -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:<?= $is_rate_optimal ? '#f0fdf4' : '#fef2f2' ?>;border-left:4px solid <?= $is_rate_optimal ? '#16a34a' : '#dc2626' ?> !important;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase">Rejection Rate KPI</div>
                        <h2 class="fw-bold my-1 <?= $is_rate_optimal ? 'text-success' : 'text-danger' ?>"><?= $rejection_rate ?>%</h2>
                    </div>
                    <span class="badge <?= $is_rate_optimal ? 'bg-success' : 'bg-danger' ?> p-2 rounded-circle">
                        <i class="fa-solid <?= $is_rate_optimal ? 'fa-shield-check' : 'fa-triangle-exclamation' ?>"></i>
                    </span>
                </div>
                <small class="<?= $is_rate_optimal ? 'text-success' : 'text-danger' ?> fw-semibold">
                    <?= $is_rate_optimal ? '✓ Within MOH Target (< 2.0%)' : '⚠️ Exceeds MOH Benchmark (> 2.0%)' ?>
                </small>
            </div>
        </div>

        <!-- Pending Recollections -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#fffbeb;border-left:4px solid #f59e0b !important;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase">Redraws Pending</div>
                        <h2 class="fw-bold my-1 text-warning-emphasis"><?= $pending_redraws ?></h2>
                    </div>
                    <span class="badge bg-warning text-dark p-2 rounded-circle">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </span>
                </div>
                <small class="text-muted">Awaiting phlebotomy recollecting</small>
            </div>
        </div>

        <!-- Successfully Recollected -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#eff6ff;border-left:4px solid #2563eb !important;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase">Recollected &amp; Resolved</div>
                        <h2 class="fw-bold my-1 text-primary"><?= $recollected_cnt ?></h2>
                    </div>
                    <span class="badge bg-primary p-2 rounded-circle">
                        <i class="fa-solid fa-rotate"></i>
                    </span>
                </div>
                <small class="text-muted">Specimens re-drawn &amp; tested</small>
            </div>
        </div>

        <!-- Total Rejections / Requests -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#f8fafc;border-left:4px solid #64748b !important;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase">Total Rejections Logged</div>
                        <h2 class="fw-bold my-1 text-dark"><?= $total_rejections ?> <span class="fs-6 text-muted fw-normal">/ <?= $total_requests ?> reqs</span></h2>
                    </div>
                    <span class="badge bg-secondary p-2 rounded-circle">
                        <i class="fa-solid fa-vial-circle-check"></i>
                    </span>
                </div>
                <small class="text-muted">Continuous pre-analytical audit</small>
            </div>
        </div>
    </div>

    <!-- Reason Breakdown & ISO Info -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm p-3 h-100">
                <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-chart-pie me-2 text-danger"></i>Primary Root Causes of Specimen Rejection</h6>
                <?php if (empty($reasons_breakdown)): ?>
                    <p class="text-muted small mb-0">No rejection incidents recorded yet.</p>
                <?php else: ?>
                    <div class="row g-2">
                        <?php foreach ($reasons_breakdown as $rb):
                            $pct = $total_rejections > 0 ? round(($rb['cnt'] / $total_rejections) * 100) : 0;
                        ?>
                        <div class="col-md-6">
                            <div class="p-2 border rounded bg-light">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small fw-bold text-dark text-truncate" title="<?= htmlspecialchars($rb['rejection_reason']) ?>">
                                        <?= htmlspecialchars($rb['rejection_reason']) ?>
                                    </span>
                                    <span class="badge bg-danger rounded-pill"><?= $rb['cnt'] ?> (<?= $pct ?>%)</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm p-3 h-100 bg-light">
                <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-clipboard-check me-2 text-primary"></i>ISO 15189 Quality Protocol</h6>
                <p class="text-muted small mb-2" style="font-size:0.8rem;line-height:1.45;">
                    Per MOH Kenya &amp; ISO 15189 clause 5.4, any specimen compromised during venipuncture, preservation, or transit must be rejected to prevent erroneous reporting that could mislead clinical treatment.
                </p>
                <div class="d-flex flex-column gap-1 small text-secondary">
                    <div><i class="fa-solid fa-circle-xmark text-danger me-1"></i> Gross hemolysis falsifies potassium &amp; LDH.</div>
                    <div><i class="fa-solid fa-circle-xmark text-danger me-1"></i> Clots in EDTA tubes invalidate CBC / Platelets.</div>
                    <div><i class="fa-solid fa-circle-xmark text-danger me-1"></i> QNS prevents accurate reagent:sample dilution.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rejection Register Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-0">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-0">Specimen Rejection Register</h5>
                    <small class="text-muted">Audit log of all compromised specimens and phlebotomy redraw workflows</small>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <!-- Status Filter Tabs -->
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="sample_rejection.php?status=all<?= $search_q ? '&q='.urlencode($search_q) : '' ?>" class="btn <?= $filter_status=='all'?'btn-dark':'btn-outline-secondary' ?>">All</a>
                        <a href="sample_rejection.php?status=Recollection Pending<?= $search_q ? '&q='.urlencode($search_q) : '' ?>" class="btn <?= $filter_status=='Recollection Pending'?'btn-warning text-dark fw-bold':'btn-outline-secondary' ?>">Pending Redraw</a>
                        <a href="sample_rejection.php?status=Recollected<?= $search_q ? '&q='.urlencode($search_q) : '' ?>" class="btn <?= $filter_status=='Recollected'?'btn-success text-white fw-bold':'btn-outline-secondary' ?>">Recollected</a>
                        <a href="sample_rejection.php?status=Cancelled<?= $search_q ? '&q='.urlencode($search_q) : '' ?>" class="btn <?= $filter_status=='Cancelled'?'btn-secondary':'btn-outline-secondary' ?>">Cancelled</a>
                    </div>
                    <!-- Search Box -->
                    <form method="get" class="d-flex">
                        <?php if ($filter_status !== 'all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
                        <div class="input-group input-group-sm">
                            <input type="text" name="q" class="form-control" placeholder="Search patient, OPD, reason…" value="<?= htmlspecialchars($search_q) ?>">
                            <button class="btn btn-outline-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
                            <?php if ($search_q): ?>
                            <a href="sample_rejection.php?status=<?= urlencode($filter_status) ?>" class="btn btn-outline-danger">✕</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.87rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Date &amp; Time</th>
                        <th>Req # / Patient</th>
                        <th>Specimen Type</th>
                        <th>Rejection Reason</th>
                        <th>Technologist Guidance / Notes</th>
                        <th>Rejected By</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rejections_res && $rejections_res->num_rows > 0):
                    while ($r = $rejections_res->fetch_assoc()):
                        $st = $r['status'];
                        $badge_cls = ($st === 'Recollected') ? 'bg-success' : (($st === 'Recollection Pending') ? 'bg-warning text-dark' : 'bg-secondary');
                ?>
                    <tr>
                        <td class="ps-3 text-nowrap">
                            <div class="fw-bold text-dark"><?= date('d M Y', strtotime($r['rejection_date'])) ?></div>
                            <small class="text-muted"><?= date('H:i', strtotime($r['rejection_date'])) ?></small>
                        </td>
                        <td>
                            <div class="fw-bold text-dark">
                                <a href="patient_history.php?patient_id=<?= $r['patient_id'] ?>" class="text-decoration-none text-primary">
                                    <?= htmlspecialchars($r['full_name']) ?>
                                </a>
                            </div>
                            <small class="text-muted">OPD: <?= htmlspecialchars($r['opd_number']) ?> &middot; Req #<?= $r['request_id'] ?></small>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border px-2 py-1"><?= htmlspecialchars($r['sample_type']) ?></span>
                        </td>
                        <td>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 fw-bold">
                                <i class="fa-solid fa-circle-exclamation me-1"></i><?= htmlspecialchars($r['rejection_reason']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="text-secondary small d-inline-block text-truncate" style="max-width:280px;" title="<?= htmlspecialchars($r['comments'] ?? '') ?>">
                                <?= htmlspecialchars($r['comments'] ?: 'None documented.') ?>
                            </span>
                        </td>
                        <td class="text-nowrap small text-muted">
                            <i class="fa-solid fa-user-gear me-1"></i><?= htmlspecialchars($r['rejected_by']) ?>
                        </td>
                        <td>
                            <span class="badge <?= $badge_cls ?> px-2 py-1"><?= htmlspecialchars($st) ?></span>
                        </td>
                        <td class="text-end pe-3 text-nowrap">
                            <?php if ($st === 'Recollection Pending' && !$isDoctor): ?>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('Confirm redraw specimen received? This will reactivate the tests on the workbench.');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="rejection_id" value="<?= $r['rejection_id'] ?>">
                                    <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                                    <button type="submit" name="mark_recollected" class="btn btn-sm btn-success rounded-pill px-2 py-1" style="font-size:0.75rem;">
                                        <i class="fa-solid fa-check me-1"></i> Receive Redraw
                                    </button>
                                </form>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('Cancel this recollection request?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="rejection_id" value="<?= $r['rejection_id'] ?>">
                                    <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                                    <button type="submit" name="cancel_request" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-1" style="font-size:0.75rem;">
                                        Cancel
                                    </button>
                                </form>
                            <?php else: ?>
                                <a href="enter_results.php?manage_id=<?= $r['request_id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-2 py-1" style="font-size:0.75rem;">
                                    View Workbench
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-circle-check fs-1 text-success opacity-50 d-block mb-2"></i>
                            No rejected specimens found under this filter.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- MODAL: LOG SPECIMEN REJECTION -->
<?php if (!$isDoctor): ?>
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold" id="rejectModalLabel">
                    <i class="fa-solid fa-ban me-2"></i> Reject Lab Specimen (ISO 15189)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <?php csrf_field(); ?>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Select Request or Enter ID</label>
                        <select name="request_id" class="form-select" required>
                            <option value="">-- Choose Pending / In Progress Request --</option>
                            <?php if ($active_reqs && $active_reqs->num_rows > 0):
                                while ($ar = $active_reqs->fetch_assoc()):
                            ?>
                                <option value="<?= $ar['request_id'] ?>">
                                    Req #<?= $ar['request_id'] ?> &mdash; <?= htmlspecialchars($ar['full_name']) ?> (OPD: <?= htmlspecialchars($ar['opd_number']) ?>)
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Rejection Reason</label>
                        <select name="rejection_reason" class="form-select" required>
                            <option value="">-- Select Root Cause Reason --</option>
                            <option value="Gross Hemolysis (Pink/Red Serum)">Gross Hemolysis (Pink/Red Serum)</option>
                            <option value="Clotted Specimen (EDTA / Citrate Tube)">Clotted Specimen (EDTA / Citrate Tube)</option>
                            <option value="Quantity Not Sufficient (QNS)">Quantity Not Sufficient (QNS)</option>
                            <option value="Incorrect Container / Wrong Additive">Incorrect Container / Wrong Additive</option>
                            <option value="Mislabeled / Unlabeled Specimen Tube">Mislabeled / Unlabeled Specimen Tube</option>
                            <option value="Specimen Leaking / Broken Container">Specimen Leaking / Broken Container</option>
                            <option value="Lipemic / Severe Turbidity">Lipemic / Severe Turbidity</option>
                            <option value="Transit Delay / Cold Chain Broken">Transit Delay / Cold Chain Broken</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Specimen Type</label>
                        <select name="sample_type" class="form-select">
                            <option value="Whole Blood (EDTA)">Whole Blood (EDTA Tube - Purple)</option>
                            <option value="Serum (Red / SST Gold Top)">Serum (Red / SST Gold Top)</option>
                            <option value="Plasma (Sodium Citrate - Blue)">Plasma (Sodium Citrate - Blue)</option>
                            <option value="Urine (Clean Catch / Random)">Urine (Clean Catch / Random)</option>
                            <option value="Stool Specimen">Stool Specimen</option>
                            <option value="Sputum Specimen">Sputum Specimen</option>
                            <option value="Swab / Body Fluid">Swab / Body Fluid</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Corrective Guidance for Phlebotomy / Doctor</label>
                        <textarea name="comments" rows="3" class="form-control" placeholder="Specify instructions for recollection (e.g., redraw using 21G needle to avoid mechanical hemolysis, mix tube 8 times gently)..."></textarea>
                    </div>

                    <div class="p-3 bg-light rounded text-muted small">
                        <i class="fa-solid fa-info-circle text-primary me-1"></i>
                        Rejecting this sample will mark the order as <strong>Rejected</strong>, inform the doctor via high-priority notification, and flag a redraw request.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="log_rejection" class="btn btn-danger rounded-pill px-4 fw-bold">
                        <i class="fa-solid fa-ban me-1"></i> Confirm Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
