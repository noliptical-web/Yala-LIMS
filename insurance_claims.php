<?php
// insurance_claims.php
// SHA / NHIF & Private Insurance Pre-Authorization and Claims Manager
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$role        = $_SESSION['role'] ?? '';
$my_user     = $_SESSION['full_name'] ?? 'Claims Officer';
$success     = $error = "";

$isAdmin     = ($role === 'Admin');
$isReception = ($role === 'Receptionist');

if (!$isAdmin && !$isReception) {
    header("location: dashboard.php"); exit;
}

// ── PROCESS / RESOLVE CLAIM PROCEDURE ────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['process_claim'])) {
    csrf_verify();
    $claim_id    = intval($_POST['claim_id'] ?? 0);
    $req_id      = intval($_POST['request_id'] ?? 0);
    $pat_id      = intval($_POST['patient_id'] ?? 0);
    $insurer     = trim($_POST['insurer_name'] ?? '');
    $ins_num     = trim($_POST['insurance_number'] ?? '');
    $ref         = trim($_POST['claim_ref'] ?? '');
    $billed      = floatval($_POST['billed_amount'] ?? 0);
    $approved    = floatval($_POST['approved_amount'] ?? 0);
    $copay       = floatval($_POST['copay_amount'] ?? 0);
    $newStatus   = trim($_POST['status'] ?? 'Submitted');
    $notes       = trim($_POST['notes'] ?? '');
    $valid       = ['Submitted', 'Approved', 'Rejected', 'Partial'];

    if ($req_id <= 0 || $pat_id <= 0 || !in_array($newStatus, $valid)) {
        $error = "Invalid claim data submitted.";
    } else {
        $conn->begin_transaction();
        try {
            // Fetch patient name for notifications
            $pn_q = $conn->query("SELECT full_name FROM patients WHERE patient_id = $pat_id");
            $pat_name = ($pn_q && $prow = $pn_q->fetch_assoc()) ? $prow['full_name'] : "Patient #$pat_id";

            if ($claim_id > 0) {
                // Update existing claim
                $stmt = $conn->prepare("UPDATE insurance_claims SET insurer_name = ?, insurance_number = ?, claim_ref = ?, approved_amount = ?, copay_amount = ?, status = ?, notes = ?, resolved_at = NOW() WHERE claim_id = ?");
                $stmt->bind_param("sssddssi", $insurer, $ins_num, $ref, $approved, $copay, $newStatus, $notes, $claim_id);
            } else {
                // Insert new claim
                $stmt = $conn->prepare("INSERT INTO insurance_claims (request_id, patient_id, insurer_name, insurance_number, claim_ref, billed_amount, approved_amount, copay_amount, status, notes, resolved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("iisssddsss", $req_id, $pat_id, $insurer, $ins_num, $ref, $billed, $approved, $copay, $newStatus, $notes);
            }

            if (!$stmt->execute()) {
                throw new Exception("Claims table update failed: " . $stmt->error);
            }
            if ($claim_id <= 0) $claim_id = $stmt->insert_id;
            $stmt->close();

            // Synchronize with payments and lab_requests table
            if ($newStatus === 'Approved') {
                $stmt_p = $conn->prepare("UPDATE payments SET amount_paid = ?, insurer_amount = ?, patient_copay = 0, copay_amount = 0, claim_ref = ?, reference_no = ?, payment_method = 'Insurance', payment_type = 'Insurance', received_by = ?, payment_date = NOW() WHERE request_id = ?");
                $stmt_p->bind_param("ddsssi", $approved, $approved, $ref, $ref, $my_user, $req_id);
                $stmt_p->execute(); $stmt_p->close();

                $conn->query("UPDATE lab_requests SET payment_status = 'Paid' WHERE request_id = $req_id");

                $notif_msg = $conn->real_escape_string("Claim APPROVED: $pat_name (Req #$req_id) by $insurer for KES " . number_format($approved, 2));
                $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('Doctor', '$notif_msg', 'patient_history.php?patient_id=$pat_id', 0, NOW())");
                $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('Receptionist', '$notif_msg', 'insurance_claims.php', 0, NOW())");

            } elseif ($newStatus === 'Partial') {
                $total_settled = $approved + $copay;
                $stmt_p = $conn->prepare("UPDATE payments SET amount_paid = ?, insurer_amount = ?, patient_copay = ?, copay_amount = ?, claim_ref = ?, reference_no = ?, payment_method = 'Insurance', payment_type = 'Split', received_by = ?, payment_date = NOW() WHERE request_id = ?");
                $stmt_p->bind_param("ddddsssi", $total_settled, $approved, $copay, $copay, $ref, $ref, $my_user, $req_id);
                $stmt_p->execute(); $stmt_p->close();

                $conn->query("UPDATE lab_requests SET payment_status = 'Paid' WHERE request_id = $req_id");

                $notif_msg = $conn->real_escape_string("Claim PARTIAL: $pat_name (Req #$req_id) — Insurer KES " . number_format($approved, 2) . ", Copay KES " . number_format($copay, 2));
                $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('Receptionist', '$notif_msg', 'insurance_claims.php', 0, NOW())");

            } elseif ($newStatus === 'Rejected') {
                // Claim was rejected by insurance — reset bill to Unpaid so Cashier can collect cash/mpesa!
                $conn->query("UPDATE payments SET payment_method = 'Pending', payment_type = 'Cash', amount_paid = 0, insurer_amount = 0 WHERE request_id = $req_id");
                $conn->query("UPDATE lab_requests SET payment_status = 'Unpaid' WHERE request_id = $req_id");

                $alert_msg = $conn->real_escape_string("⚠️ INSURANCE CLAIM REJECTED: $pat_name (Req #$req_id) rejected by $insurer. Reason: $notes. Collect KES " . number_format($billed, 2) . " cash/M-Pesa.");
                $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('Receptionist', '$alert_msg', 'billing.php', 0, NOW())");
                $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('Doctor', '$alert_msg', 'billing.php', 0, NOW())");
                $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('Admin', '$alert_msg', 'billing.php', 0, NOW())");

            } else {
                // Status remains Submitted
                $conn->query("UPDATE payments SET claim_ref = '" . $conn->real_escape_string($ref) . "' WHERE request_id = $req_id");
            }

            audit_log($conn, 'process_insurance_claim', 'insurance_claims', $claim_id, "Req #$req_id for $pat_name marked $newStatus (Approved: $approved, Copay: $copay)");
            $conn->commit();
            $success = "Insurance claim #$claim_id for $pat_name updated to '$newStatus' and synchronized.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error updating claim: " . $e->getMessage();
        }
    }
}

// ── OVERVIEW KPI STATISTICS ──────────────────────────────────────────────────
$total_claims_val = $conn->query("SELECT COALESCE(SUM(billed_amount),0) FROM insurance_claims")->fetch_row()[0] ?? 0;
$approved_val     = $conn->query("SELECT COALESCE(SUM(approved_amount),0) FROM insurance_claims WHERE status IN ('Approved','Partial')")->fetch_row()[0] ?? 0;
$pending_count    = $conn->query("SELECT COUNT(*) FROM insurance_claims WHERE status = 'Submitted'")->fetch_row()[0] ?? 0;
$rejected_count   = $conn->query("SELECT COUNT(*) FROM insurance_claims WHERE status = 'Rejected'")->fetch_row()[0] ?? 0;

// ── FETCH CLAIMS LIST ────────────────────────────────────────────────────────
$filter_status  = $_GET['status'] ?? 'All';
$filter_insurer = $_GET['insurer'] ?? 'All';
$search_q       = trim($_GET['q'] ?? '');

$sql = "SELECT 
            c.claim_id,
            c.request_id,
            c.patient_id,
            c.insurer_name,
            c.insurance_number,
            c.claim_ref,
            c.billed_amount,
            c.approved_amount,
            c.copay_amount,
            c.status AS claim_status,
            c.notes AS claim_notes,
            c.submitted_at,
            c.resolved_at,
            r.request_date,
            r.requested_by,
            r.payment_status AS req_payment_status,
            pt.full_name AS patient_name,
            pt.opd_number,
            pt.gender,
            pt.age,
            pay.payment_method
        FROM insurance_claims c
        JOIN lab_requests r ON c.request_id = r.request_id
        JOIN patients pt ON c.patient_id = pt.patient_id
        LEFT JOIN payments pay ON c.request_id = pay.request_id
        WHERE 1=1";

if ($filter_status !== 'All') {
    $fs = $conn->real_escape_string($filter_status);
    $sql .= " AND c.status = '$fs'";
}

if ($filter_insurer !== 'All') {
    $fi = $conn->real_escape_string($filter_insurer);
    $sql .= " AND c.insurer_name LIKE '%$fi%'";
}

if (!empty($search_q)) {
    $sq = $conn->real_escape_string($search_q);
    $sql .= " AND (pt.full_name LIKE '%$sq%' OR pt.opd_number LIKE '%$sq%' OR c.claim_ref LIKE '%$sq%' OR c.insurance_number LIKE '%$sq%' OR c.request_id = '$sq')";
}

$sql .= " ORDER BY c.submitted_at DESC LIMIT 150";

$res = $conn->query($sql);
$claims = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Insurers for dropdown filter
$ins_list_q = $conn->query("SELECT DISTINCT insurer_name FROM insurance_claims WHERE insurer_name != '' ORDER BY insurer_name");
$ins_options = [];
if ($ins_list_q) while ($row = $ins_list_q->fetch_assoc()) $ins_options[] = $row['insurer_name'];

$page_title = "Insurance Claims Manager — Yala LIMS";
include 'includes/header.php';
$csrf = csrf_token();
?>

<style>
.claim-badge { font-size: .72rem; font-weight: 700; padding: 3px 9px; border-radius: 12px; text-transform: uppercase; }
.cl-Submitted { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
.cl-Approved  { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.cl-Partial   { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.cl-Rejected  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.f { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.f label { font-size: .73rem; font-weight: 700; color: #475569; text-transform: uppercase; }
.f input, .f select, .f textarea { width: 100%; border: 1.5px solid #cbd5e1; border-radius: 7px; padding: 8px 10px; font-size: .84rem; outline: none; }
.f input:focus, .f select:focus, .f textarea:focus { border-color: #2563eb; }
</style>

<div class="container-fluid px-4 py-3">

    <!-- Header Banner -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"
         style="background:linear-gradient(135deg,#3b0764 0%,#6b21a8 60%,#7e22ce 100%);padding:22px 28px;border-radius:14px;color:#fff;">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h3 class="fw-bold mb-0 text-white"><i class="fa-solid fa-shield-halved me-2"></i>Insurance Claims &amp; Pre-Authorizations</h3>
                <span class="badge bg-light text-dark border px-2 py-1" style="font-size:0.75rem;">SHA / NHIF e-Claims Desk</span>
            </div>
            <p class="mb-0 text-white-50" style="font-size:0.88rem;">
                Reconcile outpatient claims, record pre-authorization codes, track insurer approvals, and manage patient copayments.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="billing.php" class="btn btn-light fw-bold text-purple rounded-pill px-3 shadow-sm" style="color:#6b21a8;">
                <i class="fa-solid fa-cash-register me-1"></i> Hospital Cashier
            </a>
            <a href="dashboard.php" class="btn btn-outline-light rounded-pill px-3">
                <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Alert banners -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm" role="alert">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><?= htmlspecialchars($success) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm" role="alert">
        <i class="fa-solid fa-circle-exclamation fs-5"></i>
        <div><?= htmlspecialchars($error) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- KPI Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#faf5ff;border-left:4px solid #7e22ce !important;">
                <div class="text-muted fw-semibold small text-uppercase">Gross Claims Value</div>
                <h2 class="fw-bold my-1" style="color:#6b21a8;">KES <?= number_format($total_claims_val, 0) ?></h2>
                <small class="text-muted">Total outpatient claims billed</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#f0fdf4;border-left:4px solid #16a34a !important;">
                <div class="text-muted fw-semibold small text-uppercase">Approved &amp; Reimbursed</div>
                <h2 class="fw-bold my-1 text-success">KES <?= number_format($approved_val, 0) ?></h2>
                <small class="text-muted">Cleared by insurance schemes</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#eff6ff;border-left:4px solid #2563eb !important;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase">Pending Pre-Auth</div>
                        <h2 class="fw-bold my-1 text-primary"><?= $pending_count ?></h2>
                    </div>
                    <span class="badge bg-primary rounded-circle p-2"><i class="fa-solid fa-clock"></i></span>
                </div>
                <small class="text-muted">Awaiting insurer approval</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#fef2f2;border-left:4px solid #dc2626 !important;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted fw-semibold small text-uppercase">Rejected Claims</div>
                        <h2 class="fw-bold my-1 text-danger"><?= $rejected_count ?></h2>
                    </div>
                    <span class="badge bg-danger rounded-circle p-2"><i class="fa-solid fa-ban"></i></span>
                </div>
                <small class="text-muted">Reverted to patient cash queue</small>
            </div>
        </div>
    </div>

    <!-- CLAIMS REGISTER TABLE -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-0">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <!-- Status Filter Buttons -->
                <div class="btn-group btn-group-sm" role="group">
                    <a href="insurance_claims.php?status=All<?= $search_q ? '&q='.urlencode($search_q) : '' ?><?= $filter_insurer!='All' ? '&insurer='.urlencode($filter_insurer) : '' ?>" class="btn <?= $filter_status=='All'?'btn-dark':'btn-outline-secondary' ?>">All</a>
                    <a href="insurance_claims.php?status=Submitted<?= $search_q ? '&q='.urlencode($search_q) : '' ?><?= $filter_insurer!='All' ? '&insurer='.urlencode($filter_insurer) : '' ?>" class="btn <?= $filter_status=='Submitted'?'btn-primary text-white fw-bold':'btn-outline-secondary' ?>">Submitted</a>
                    <a href="insurance_claims.php?status=Approved<?= $search_q ? '&q='.urlencode($search_q) : '' ?><?= $filter_insurer!='All' ? '&insurer='.urlencode($filter_insurer) : '' ?>" class="btn <?= $filter_status=='Approved'?'btn-success text-white fw-bold':'btn-outline-secondary' ?>">Approved</a>
                    <a href="insurance_claims.php?status=Partial<?= $search_q ? '&q='.urlencode($search_q) : '' ?><?= $filter_insurer!='All' ? '&insurer='.urlencode($filter_insurer) : '' ?>" class="btn <?= $filter_status=='Partial'?'btn-warning text-dark fw-bold':'btn-outline-secondary' ?>">Partial</a>
                    <a href="insurance_claims.php?status=Rejected<?= $search_q ? '&q='.urlencode($search_q) : '' ?><?= $filter_insurer!='All' ? '&insurer='.urlencode($filter_insurer) : '' ?>" class="btn <?= $filter_status=='Rejected'?'btn-danger text-white fw-bold':'btn-outline-secondary' ?>">Rejected</a>
                </div>

                <!-- Insurer Filter & Search Form -->
                <form method="get" class="d-flex align-items-center gap-2">
                    <?php if ($filter_status !== 'All'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
                    <select name="insurer" class="form-select form-select-sm" style="width:160px;" onchange="this.form.submit()">
                        <option value="All">All Insurers</option>
                        <?php foreach ($ins_options as $io): ?>
                        <option value="<?= htmlspecialchars($io) ?>" <?= ($filter_insurer===$io)?'selected':'' ?>><?= htmlspecialchars($io) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="input-group input-group-sm" style="width:230px;">
                        <input type="text" name="q" class="form-control" placeholder="Search patient, OPD, Ref…" value="<?= htmlspecialchars($search_q) ?>">
                        <button class="btn btn-outline-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
                        <?php if ($search_q || $filter_insurer !== 'All'): ?>
                        <a href="insurance_claims.php?status=<?= urlencode($filter_status) ?>" class="btn btn-outline-danger">✕</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if (empty($claims)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa-solid fa-folder-open fa-3x mb-3 opacity-25"></i>
            <h6 class="fw-bold text-dark">No Insurance Claims Found</h6>
            <p class="small text-muted mb-0">No records match your selected filter criteria.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.87rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Req # / Date</th>
                        <th>Patient Details</th>
                        <th>Scheme / Policy Card</th>
                        <th>Claim / Pre-Auth Ref</th>
                        <th class="text-end">Billed (KES)</th>
                        <th class="text-end">Approved (KES)</th>
                        <th class="text-end">Copay (KES)</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($claims as $c): ?>
                    <tr>
                        <td class="ps-3 text-nowrap">
                            <div class="fw-bold text-dark">#<?= $c['request_id'] ?></div>
                            <small class="text-muted"><?= date('d M Y', strtotime($c['submitted_at'])) ?></small>
                        </td>
                        <td>
                            <div class="fw-bold text-dark">
                                <a href="patient_history.php?patient_id=<?= $c['patient_id'] ?>" class="text-decoration-none text-primary">
                                    <?= htmlspecialchars($c['patient_name']) ?>
                                </a>
                            </div>
                            <small class="text-muted">OPD: <?= htmlspecialchars($c['opd_number']) ?> &middot; <?= $c['gender'] ?>, <?= $c['age'] ?>y</small>
                        </td>
                        <td>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($c['insurer_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($c['insurance_number'] ?: '—') ?></small>
                        </td>
                        <td>
                            <code class="text-dark fw-bold"><?= htmlspecialchars($c['claim_ref'] ?: 'Pending') ?></code>
                        </td>
                        <td class="text-end fw-bold text-dark">
                            <?= number_format($c['billed_amount'], 2) ?>
                        </td>
                        <td class="text-end fw-bold text-success">
                            <?= number_format($c['approved_amount'], 2) ?>
                        </td>
                        <td class="text-end fw-bold text-warning-emphasis">
                            <?= number_format($c['copay_amount'], 2) ?>
                        </td>
                        <td>
                            <span class="claim-badge cl-<?= htmlspecialchars($c['claim_status']) ?>">
                                <?= htmlspecialchars($c['claim_status']) ?>
                            </span>
                        </td>
                        <td class="text-end pe-3 text-nowrap">
                            <button class="btn btn-outline-primary btn-sm rounded-pill px-2 py-1 fw-bold" style="font-size:0.75rem;"
                                    data-bs-toggle="modal" data-bs-target="#claimModal"
                                    onclick="loadClaim(<?= htmlspecialchars(json_encode($c)) ?>)">
                                <i class="fa-solid fa-sliders me-1"></i> Process
                            </button>
                            <a href="print_claim.php?id=<?= $c['claim_id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill px-2 py-1 ms-1" style="font-size:0.75rem;">
                                <i class="fa-solid fa-print"></i> Voucher
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- ── PROCESS CLAIM MODAL ────────────────────────────────────────────────── -->
<div class="modal fade" id="claimModal" tabindex="-1" aria-labelledby="claimModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content border-0 shadow-lg" style="border-radius:15px;">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="process_claim" value="1">
            <input type="hidden" name="claim_id" id="c_id">
            <input type="hidden" name="request_id" id="c_rid">
            <input type="hidden" name="patient_id" id="c_pid">
            <input type="hidden" name="billed_amount" id="c_billed">

            <div class="modal-header bg-light border-0 pt-3 px-4">
                <h5 class="fw-bold modal-title" id="claimModalLabel">
                    <i class="fa-solid fa-shield-halved me-2 text-purple" style="color:#7e22ce;"></i> Process Insurance Claim
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-4 pt-2">
                <div class="p-3 mb-3 text-white rounded-3 shadow-sm" style="background:linear-gradient(135deg, #3b0764, #7e22ce);">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h5 class="fw-bold mb-0" id="lblPatName"></h5>
                            <div class="small opacity-75" id="lblPatMeta"></div>
                        </div>
                        <div class="text-end">
                            <div class="small text-uppercase opacity-75">Billed Amount</div>
                            <h4 class="fw-bold mb-0 text-warning" id="lblBilled">KES 0.00</h4>
                        </div>
                    </div>
                </div>

                <div class="f">
                    <label>Claim Resolution Status</label>
                    <select name="status" id="c_status" class="form-select" required onchange="adjClaim(this.value)">
                        <option value="Submitted">Submitted (Pending Insurer Response)</option>
                        <option value="Approved">Approved (Insurer Clears Bill)</option>
                        <option value="Partial">Partial (Partial Coverage with Copay)</option>
                        <option value="Rejected">Rejected (Ineligible / Reverted to Cash)</option>
                    </select>
                </div>

                <div class="f">
                    <label>Insurance Scheme / Payer</label>
                    <input type="text" name="insurer_name" id="c_ins" required>
                </div>

                <div class="f">
                    <label>Member / Card Policy Number</label>
                    <input type="text" name="insurance_number" id="c_num" required>
                </div>

                <div class="f">
                    <label>Pre-Authorization / Claim Ref Code</label>
                    <input type="text" name="claim_ref" id="c_ref" placeholder="e.g. CLM-SHA-2026-9021" required>
                </div>

                <div class="row g-2">
                    <div class="col-6">
                        <div class="f">
                            <label>Approved Amount (KES)</label>
                            <input type="number" step="0.01" name="approved_amount" id="c_app" required oninput="calcCopay()">
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="f">
                            <label>Patient Copay (KES)</label>
                            <input type="number" step="0.01" name="copay_amount" id="c_copay" required>
                        </div>
                    </div>
                </div>

                <div class="f">
                    <label>Internal Audit Remarks / Rejection Reason</label>
                    <textarea name="notes" id="c_notes" rows="2" placeholder="Document authorization notes or insurer rationale..."></textarea>
                </div>

                <div class="p-2 bg-light rounded text-muted small" id="statusHint">
                    <i class="fa-solid fa-info-circle text-primary me-1"></i>
                    Approving sets request to <strong>Paid</strong> and updates hospital financial accounts.
                </div>
            </div>

            <div class="modal-footer bg-light border-0 pt-0 px-4 pb-3">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save &amp; Sync Claim</button>
            </div>
        </form>
    </div>
</div>

<script>
let curBilled = 0;

function loadClaim(c) {
    document.getElementById('c_id').value     = c.claim_id ? c.claim_id : 0;
    document.getElementById('c_rid').value    = c.request_id;
    document.getElementById('c_pid').value    = c.patient_id;
    document.getElementById('c_ins').value    = c.insurer_name;
    document.getElementById('c_num').value    = c.insurance_number || '';
    
    curBilled = parseFloat(c.billed_amount) || 0;
    document.getElementById('c_billed').value = curBilled;
    document.getElementById('lblBilled').textContent = 'KES ' + curBilled.toLocaleString(undefined, {minimumFractionDigits: 2});
    
    document.getElementById('lblPatName').textContent = c.patient_name;
    document.getElementById('lblPatMeta').textContent = 'OPD: ' + (c.opd_number || '') + ' · Req #' + c.request_id;

    document.getElementById('c_status').value = c.claim_status;
    document.getElementById('c_ref').value    = c.claim_ref ? c.claim_ref : '';
    document.getElementById('c_notes').value  = c.claim_notes ? c.claim_notes : '';
    
    if (c.claim_id > 0) {
        document.getElementById('c_app').value   = parseFloat(c.approved_amount) || 0;
        document.getElementById('c_copay').value = parseFloat(c.copay_amount) || 0;
    } else {
        document.getElementById('c_app').value   = curBilled;
        document.getElementById('c_copay').value = 0;
    }
    updateHint(c.claim_status);
}

function adjClaim(status) {
    if (status === 'Approved') {
        document.getElementById('c_app').value   = curBilled;
        document.getElementById('c_copay').value = 0;
    } else if (status === 'Rejected') {
        document.getElementById('c_app').value   = 0;
        document.getElementById('c_copay').value = 0;
    } else if (status === 'Partial') {
        const copay = (curBilled > 1000) ? 200 : 100;
        document.getElementById('c_copay').value = copay;
        document.getElementById('c_app').value   = Math.max(0, curBilled - copay);
    }
    updateHint(status);
}

function calcCopay() {
    const app = parseFloat(document.getElementById('c_app').value) || 0;
    if (app < curBilled) {
        document.getElementById('c_copay').value = (curBilled - app).toFixed(2);
    } else {
        document.getElementById('c_copay').value = 0;
    }
}

function updateHint(status) {
    const hint = document.getElementById('statusHint');
    if (status === 'Rejected') {
        hint.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-danger me-1"></i> Rejecting will reset request to <strong>Unpaid</strong> and alert Cashier to collect cash/M-Pesa.';
        hint.className = 'p-2 bg-danger-subtle text-danger rounded small';
    } else if (status === 'Approved') {
        hint.innerHTML = '<i class="fa-solid fa-circle-check text-success me-1"></i> Approving marks the bill as fully cleared under insurance reimbursement.';
        hint.className = 'p-2 bg-success-subtle text-success rounded small';
    } else if (status === 'Partial') {
        hint.innerHTML = '<i class="fa-solid fa-circle-info text-warning me-1"></i> Patient copay balance will be tracked alongside insurer reimbursement.';
        hint.className = 'p-2 bg-warning-subtle text-dark rounded small';
    } else {
        hint.innerHTML = '<i class="fa-solid fa-info-circle text-primary me-1"></i> Claim lodged and awaiting insurer electronic approval.';
        hint.className = 'p-2 bg-light rounded text-muted small';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
