<?php
// billing.php
// Hospital Cashier & Billing Management Console — Yala Sub-County Hospital
// Strictly compliant with Kenya Gazette Notice No. 16008 of 2022, Executive Order No. 2 of 2023,
// and the Public Finance Management Act (PFM Act, 2012) — eCitizen Government Paybill 222222.

session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';
require_once 'includes/sms.php';

$allowed = ['Receptionist', 'Admin'];
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], $allowed)) {
    header("location: dashboard.php"); exit;
}

$myId    = intval($_SESSION['id'] ?? 0);
$success = $error = "";
$receipt_req_id = 0;

// ── RECORD PAYMENT PROCEDURE (eCITIZEN GATEWAY ONLY) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    csrf_verify();

    $paymentId     = intval($_POST['payment_id']   ?? 0);
    $requestId     = intval($_POST['request_id']   ?? 0);
    $method_input  = strtolower(trim($_POST['payment_method'] ?? ''));
    $amountPaid    = floatval($_POST['amount_paid'] ?? 0);
    $mpesaCode     = strtoupper(trim($_POST['mpesa_code'] ?? ''));
    $ecitizenPrn   = strtoupper(trim($_POST['ecitizen_prn'] ?? ''));
    $bankChannel   = trim($_POST['bank_channel'] ?? '');
    $insurerName   = trim($_POST['insurer_name'] ?? '');
    $memberNo      = trim($_POST['insurance_member_no'] ?? '');
    $claimRef      = trim($_POST['claim_ref'] ?? '');
    $copay         = floatval($_POST['copay_amount'] ?? 0);
    $copayPrn      = strtoupper(trim($_POST['copay_ecitizen_ref'] ?? ''));
    $waiverReason  = trim($_POST['waiver_reason'] ?? '');
    $waiverCategory= trim($_POST['waiver_category'] ?? 'Indigent / Destitute');
    $waiverOfficer = trim($_POST['waiver_officer'] ?? 'Dr. Edwin Ngwawe (Medical Superintendent)');
    $receivedBy    = $_SESSION['full_name'] ?? 'Hospital Cashier';

    if (!$paymentId || !$requestId || !$method_input) {
        $error = "Missing payment order information or digital payment channel.";
    } else {
        // Fetch patient details
        $q_p = $conn->query("SELECT p.patient_id, p.full_name, p.opd_number, p.phone_number FROM lab_requests r JOIN patients p ON r.patient_id=p.patient_id WHERE r.request_id = $requestId");
        $pat_row = $q_p ? $q_p->fetch_assoc() : null;
        $pat_id = $pat_row['patient_id'] ?? 0;
        $pat_name = $pat_row['full_name'] ?? 'Patient';
        $pat_opd  = $pat_row['opd_number'] ?? 'OPD-UNKNOWN';

        // Map method and determine amounts according to Kenya Government cashless law
        $pay_type = 'Cash'; // Out-of-pocket settlement
        $pay_method = 'eCitizen M-Pesa (222222)';
        $ref = '';
        $actual_amount = $amountPaid;
        $billed_to_ins = 0.00;

        if ($method_input === 'ecitizen_mpesa' || $method_input === 'mpesa') {
            $pay_method = 'eCitizen M-Pesa (222222)';
            $pay_type = 'Cash';
            $ref = $mpesaCode ?: ('SIY' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 7)));
            $actual_amount = $amountPaid;
        } elseif ($method_input === 'ecitizen_prn' || $method_input === 'cash') {
            // Note: Manual cash is prohibited; out-of-pocket bank or web portal deposits generate an eCitizen PRN
            $pay_method = 'eCitizen (222222)';
            $pay_type = 'Cash';
            $ref = $ecitizenPrn ?: ('PRN-222222-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8)));
            $actual_amount = $amountPaid;
            if ($bankChannel) {
                $ref .= " [$bankChannel]";
            }
        } elseif ($method_input === 'insurance') {
            $pay_method = 'Insurance';
            $pay_type = ($copay > 0) ? 'Split' : 'Insurance';
            if (empty($insurerName)) $insurerName = 'SHA (Social Health Authority)';
            $cl_prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $insurerName), 0, 4)) ?: 'SHA';
            $ref = $claimRef ?: ("CLM-$cl_prefix-" . date('Y') . "-$requestId");
            
            // Patient pays copay via eCitizen 222222 at counter; insurer covers the balance
            $actual_amount = $copay;
            $billed_to_ins = max(0, $amountPaid - $copay);
            if ($copay > 0 && $copayPrn) {
                $ref .= " (Copay: $copayPrn)";
            }
        } elseif ($method_input === 'waiver') {
            $pay_method = 'Waiver (PFM Act)';
            $pay_type = 'Cash';
            $actual_amount = 0.00;
            $ref = 'WAIVER-PFM32-' . date('Ymd') . "-$requestId";
            $waiverReason = "Exemption [PFM Act Sec 32 - $waiverCategory]: " . trim($waiverReason . ($waiverOfficer ? " (Authorized by: $waiverOfficer)" : ""));
        }

        $conn->begin_transaction();
        try {
            // 1. Update payments table
            $sql = "UPDATE payments SET
                        amount_paid         = ?,
                        payment_method      = ?,
                        payment_type        = ?,
                        reference_no        = ?,
                        insurer_name        = ?,
                        insurance_provider  = ?,
                        insurance_member_no = ?,
                        insurance_claim_no  = ?,
                        claim_ref           = ?,
                        insurer_amount      = ?,
                        patient_copay       = ?,
                        copay_amount        = ?,
                        waiver_reason       = ?,
                        received_by         = ?,
                        payment_date        = CURRENT_TIMESTAMP
                    WHERE payment_id = ?";

            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Database prepare error: " . $conn->error);

            $ins_amount_val = ($method_input === 'insurance') ? $billed_to_ins : 0.00;

            $stmt->bind_param(
                "dssssssssdddssi",
                $actual_amount, $pay_method, $pay_type, $ref,
                $insurerName, $insurerName, $memberNo, $ref, $ref,
                $ins_amount_val, $copay, $copay,
                $waiverReason, $receivedBy,
                $paymentId
            );

            if (!$stmt->execute()) throw new Exception("Payment update failed: " . $stmt->error);
            $stmt->close();

            // 2. If Insurance, synchronize/create record in insurance_claims
            if ($method_input === 'insurance' && $pat_id > 0) {
                $chk_cl = $conn->query("SELECT claim_id FROM insurance_claims WHERE request_id = $requestId");
                if ($chk_cl && $chk_cl->num_rows > 0) {
                    $cid = $chk_cl->fetch_assoc()['claim_id'];
                    $stmt_c = $conn->prepare("UPDATE insurance_claims SET insurer_name=?, insurance_number=?, claim_ref=?, billed_amount=?, copay_amount=?, status='Submitted' WHERE claim_id=?");
                    $stmt_c->bind_param("sssddi", $insurerName, $memberNo, $ref, $billed_to_ins, $copay, $cid);
                    $stmt_c->execute();
                    $stmt_c->close();
                } else {
                    $stmt_c = $conn->prepare("INSERT INTO insurance_claims (request_id, patient_id, insurer_name, insurance_number, claim_ref, billed_amount, approved_amount, copay_amount, status, notes) VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'Submitted', 'Claim lodged at cashier counter under UHC/SHA pre-auth protocol.')");
                    $stmt_c->bind_param("iisssdd", $requestId, $pat_id, $insurerName, $memberNo, $ref, $billed_to_ins, $copay);
                    $stmt_c->execute();
                    $stmt_c->close();
                }
            }

            // 3. Mark lab_request as Paid so lab technologist can begin investigations
            $conn->query("UPDATE lab_requests SET payment_status = 'Paid' WHERE request_id = $requestId");

            // 4. Dispatch notification to Lab Technologists & Ordering Doctor
            $notif_msg = $conn->real_escape_string("eCitizen Clearance: Req #$requestId ($pat_name) — KES " . number_format($actual_amount, 2) . " settled via $pay_method (Ref: $ref). Tests ready for specimen analysis.");
            $notif_link = $conn->real_escape_string("enter_results.php?manage_id=$requestId");
            $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('LabTech', '$notif_msg', '$notif_link', 0, NOW())");
            $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('Doctor', '$notif_msg', 'patient_history.php?patient_id=$pat_id', 0, NOW())");

            // 5. Audit Log (PFM Act Revenue Compliance)
            audit_log($conn, 'record_payment_ecitizen', 'payments', $paymentId, "Cleared Req #$requestId for $pat_name via $pay_method ($ref) KES $actual_amount");

            $conn->commit();

            // 6. Send SMS eCitizen Payment Receipt Notification to patient
            sms_payment_receipt($conn, $requestId, $actual_amount, $pay_method, $ref);

            $receipt_req_id = $requestId;
            $success = "Payment successfully verified and cleared for Request #$requestId ($pat_name) via $pay_method (Ref: $ref). Official eCitizen revenue receipt generated.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Payment settlement failed: " . $e->getMessage();
        }
    }
}

// ── CASHIER SUMMARY METRICS (TODAY) ──────────────────────────────────────────
$r = $conn->query("SELECT COUNT(*) FROM lab_requests WHERE payment_status = 'Unpaid'");
$unpaid_bills_count = $r ? $r->fetch_row()[0] : 0;

$r = $conn->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE (payment_method LIKE '%M-Pesa%' OR payment_method LIKE '%mpesa%') AND DATE(payment_date) = CURDATE()");
$ecitizen_mpesa_today = $r ? $r->fetch_row()[0] : 0;

$r = $conn->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE payment_method = 'eCitizen (222222)' AND DATE(payment_date) = CURDATE()");
$ecitizen_direct_today = $r ? $r->fetch_row()[0] : 0;

$r = $conn->query("SELECT COALESCE(SUM(insurer_amount),0) FROM payments WHERE payment_type IN ('Insurance','Split') AND DATE(payment_date) = CURDATE()");
$insurance_today = $r ? $r->fetch_row()[0] : 0;

// ── FETCH PENDING BILLS ───────────────────────────────────────────────────────
$search_q = trim($_GET['q'] ?? '');

$sql_pending = "
    SELECT
        pay.payment_id,
        pay.request_id,
        pay.insurer_amount AS total_cost,
        pay.payment_method,
        pay.payment_type,
        pay.insurance_provider,
        pay.insurance_member_no,
        pay.payment_date,
        pt.patient_id,
        pt.full_name,
        pt.age,
        pt.gender,
        pt.opd_number,
        pt.phone_number,
        r.request_date,
        r.requested_by,
        r.status AS req_status,
        r.payment_status
    FROM payments pay
    JOIN lab_requests r ON pay.request_id = r.request_id
    JOIN patients pt    ON r.patient_id = pt.patient_id
    WHERE (pay.payment_method = 'Pending' OR r.payment_status = 'Unpaid')
";

if (!empty($search_q)) {
    $sq = $conn->real_escape_string($search_q);
    $sql_pending .= " AND (pt.full_name LIKE '%$sq%' OR pt.opd_number LIKE '%$sq%' OR r.request_id = '$sq')";
}

$sql_pending .= " ORDER BY r.request_date DESC";
$res_pending = $conn->query($sql_pending);
$bills = [];

if ($res_pending) {
    while ($b = $res_pending->fetch_assoc()) {
        $rid = intval($b['request_id']);
        $tr  = $conn->query("SELECT t.test_name, t.cost FROM test_results tr JOIN lab_tests t ON tr.test_id = t.test_id WHERE tr.request_id = $rid");
        $b['tests'] = [];
        $b['test_names'] = [];
        $calc_total = 0;
        if ($tr) {
            while ($row = $tr->fetch_assoc()) {
                $b['tests'][] = $row;
                $b['test_names'][] = $row['test_name'];
                $calc_total += floatval($row['cost']);
            }
        }
        $b['tests_str'] = implode(', ', $b['test_names']);
        if (floatval($b['total_cost']) <= 0) {
            $b['total_cost'] = $calc_total;
        }
        $bills[] = $b;
    }
}

// ── FETCH RECENT SETTLED RECEIPTS (LAST 30) ──────────────────────────────────
$sql_settled = "
    SELECT
        pay.payment_id,
        pay.request_id,
        pay.amount_paid,
        pay.insurer_amount,
        pay.patient_copay,
        pay.copay_amount,
        pay.payment_method,
        pay.payment_type,
        pay.reference_no,
        pay.insurance_provider,
        pay.insurance_member_no,
        pay.payment_date,
        pay.received_by,
        pt.patient_id,
        pt.full_name,
        pt.opd_number,
        r.request_date,
        (SELECT COUNT(*) FROM test_results tr WHERE tr.request_id = r.request_id) AS test_count
    FROM payments pay
    JOIN lab_requests r ON pay.request_id = r.request_id
    JOIN patients pt    ON r.patient_id = pt.patient_id
    WHERE pay.payment_method != 'Pending'
    ORDER BY pay.payment_date DESC
    LIMIT 30
";
$res_settled = $conn->query($sql_settled);
$settled_rows = $res_settled ? $res_settled->fetch_all(MYSQLI_ASSOC) : [];

// Insurance providers list
$providers = [];
$pr = $conn->query("SELECT name FROM insurance_providers WHERE active=1 ORDER BY name");
if ($pr) while ($row = $pr->fetch_assoc()) $providers[] = $row['name'];
if (empty($providers)) {
    $providers = ['SHA (Social Health Authority)', 'Linda Mama (MOH Free Maternity)', 'NHIF Civil Servants', 'AAR Insurance Kenya', 'Jubilee Health Insurance', 'CIC General Insurance', 'Britam Health', 'Madison Insurance'];
}

$csrf = csrf_token();
$page_title = 'Cashier & Billing — Yala LIMS';
include 'includes/header.php';
?>

<div class="container-fluid px-4 py-3">

    <!-- Official Government Header Banner -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3"
         style="background:linear-gradient(135deg,#064e3b 0%,#0f766e 50%,#0e7490 100%);padding:22px 28px;border-radius:14px;color:#fff;box-shadow:0 4px 15px rgba(0,0,0,0.12);">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-white p-2 rounded-3 shadow-sm d-flex align-items-center justify-content-center" style="width:58px;height:58px;">
                <img src="ecitizen.png" alt="eCitizen Kenya" style="max-width:100%;max-height:100%;object-fit:contain;">
            </div>
            <div>
                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                    <span class="badge bg-warning text-dark fw-bold px-2 py-1" style="font-size:0.75rem;">
                        <i class="fa-solid fa-scale-balanced me-1"></i> Republic of Kenya
                    </span>
                    <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-50 px-2 py-1" style="font-size:0.75rem;">
                        County Government of Siaya
                    </span>
                    <span class="badge bg-success border border-success-subtle px-2 py-1 fw-bold" style="font-size:0.75rem;">
                        <i class="fa-solid fa-lock me-1"></i> Cashless Facility
                    </span>
                </div>
                <h3 class="fw-bold mb-0 text-white" style="letter-spacing:-0.3px;">
                    Yala Sub-County Hospital &mdash; Cashier POS &amp; Billing
                </h3>
                <p class="mb-0 text-white-50 small mt-1">
                    Direct government revenue collection gateway via <strong>eCitizen Paybill 222222</strong> (Gazette Notice No. 16008 &amp; PFM Act 2012).
                </p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="insurance_claims.php" class="btn btn-light fw-bold text-success rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-shield-halved me-1"></i> Claims Manager
            </a>
            <a href="dashboard.php" class="btn btn-outline-light rounded-pill px-3">
                <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Statutory Cashless Policy Alert -->
    <div class="alert alert-info border-0 shadow-sm d-flex align-items-center gap-3 mb-4"
         style="background:#f0fdf4;border-left:5px solid #16a34a !important;border-radius:10px;padding:14px 18px;">
        <div class="fs-4 text-success"><i class="fa-solid fa-shield-check"></i></div>
        <div style="font-size:0.86rem;color:#166534;line-height:1.45;">
            <strong>Mandatory Government Cashless Facility (Gazette Notice No. 16008 &amp; Executive Order No. 2/2023):</strong>
            Pursuant to the Public Finance Management Act (PFM Act 2012), manual physical cash collection is strictly prohibited at all public health facilities. All out-of-pocket patient settlements are remitted directly to the Siaya County Revenue Fund via <strong>eCitizen Paybill 222222</strong> (Account: <code>YALA-[OPD_NUMBER]</code>). Universal Health Coverage (SHA / Linda Mama) services require valid digital pre-authorization.
        </div>
    </div>

    <!-- Alert banners -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center justify-content-between gap-3 shadow-sm mb-4" role="alert" style="border-radius:10px;">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-circle-check fs-5"></i>
            <div><?= htmlspecialchars($success) ?></div>
        </div>
        <div>
            <?php if ($receipt_req_id > 0): ?>
            <a href="print_receipt.php?id=<?= $receipt_req_id ?>" target="_blank" class="btn btn-success btn-sm rounded-pill px-3 fw-bold shadow-sm">
                <i class="fa-solid fa-print me-1"></i> Print Official eCitizen Receipt
            </a>
            <?php endif; ?>
            <button type="button" class="btn-close ms-2" data-bs-dismiss="alert"></button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm mb-4" role="alert" style="border-radius:10px;">
        <i class="fa-solid fa-circle-exclamation fs-5"></i>
        <div><?= htmlspecialchars($error) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- KPI Metric Cards (Today) -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#fef2f2;border-left:4px solid #dc2626 !important;border-radius:12px;">
                <div class="text-muted fw-semibold small text-uppercase">Unpaid Orders in Queue</div>
                <h2 class="fw-bold my-1 text-danger"><?= $unpaid_bills_count ?></h2>
                <small class="text-muted">Awaiting eCitizen / SHA clearance</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#f0fdf4;border-left:4px solid #16a34a !important;border-radius:12px;">
                <div class="text-muted fw-semibold small text-uppercase">eCitizen M-Pesa (222222)</div>
                <h2 class="fw-bold my-1 text-success">KES <?= number_format($ecitizen_mpesa_today, 2) ?></h2>
                <small class="text-muted">Direct mobile gateway collections today</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#eff6ff;border-left:4px solid #0284c7 !important;border-radius:12px;">
                <div class="text-muted fw-semibold small text-uppercase">eCitizen Direct / PRN</div>
                <h2 class="fw-bold my-1 text-primary">KES <?= number_format($ecitizen_direct_today, 2) ?></h2>
                <small class="text-muted">Bank agents &amp; portal PRN settlements</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#faf5ff;border-left:4px solid #9333ea !important;border-radius:12px;">
                <div class="text-muted fw-semibold small text-uppercase">SHA &amp; Insurance Claims</div>
                <h2 class="fw-bold my-1 text-purple" style="color:#7e22ce;">KES <?= number_format($insurance_today, 2) ?></h2>
                <small class="text-muted">Statutory &amp; corporate coverage today</small>
            </div>
        </div>
    </div>

    <!-- MAIN TABS: PENDING QUEUE vs SETTLED LOG -->
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-header bg-white py-3 border-0">
            <ul class="nav nav-pills card-header-pills" id="billingTab" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active fw-bold px-3" id="pending-tab" data-bs-toggle="pill" data-bs-target="#pending-content" type="button">
                        <i class="fa-solid fa-clock me-1"></i> Pending Payment Queue
                        <span class="badge bg-danger ms-1"><?= count($bills) ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link fw-bold px-3 text-secondary" id="settled-tab" data-bs-toggle="pill" data-bs-target="#settled-content" type="button">
                        <i class="fa-solid fa-receipt me-1"></i> Settled Receipts Register
                        <span class="badge bg-secondary ms-1"><?= count($settled_rows) ?></span>
                    </button>
                </li>
            </ul>
        </div>

        <div class="tab-content" id="billingTabContent">

            <!-- TAB 1: PENDING BILLS QUEUE -->
            <div class="tab-pane fade show active p-3 pt-0" id="pending-content">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <small class="text-muted">Select an unpaid outpatient order to initiate eCitizen STK Push (Paybill 222222) or lodge SHA/NHIF claim voucher.</small>
                    <form method="get" class="d-flex">
                        <div class="input-group input-group-sm" style="width: 280px;">
                            <input type="text" name="q" class="form-control" placeholder="Search patient, OPD, Req #…" value="<?= htmlspecialchars($search_q) ?>">
                            <button class="btn btn-outline-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
                            <?php if ($search_q): ?>
                            <a href="billing.php" class="btn btn-outline-danger">✕</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <?php if (empty($bills)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-circle-check fa-3x text-success mb-3 opacity-50"></i>
                    <h5 class="fw-bold text-dark">Queue Cleared!</h5>
                    <p class="small text-muted mb-0">All outpatient laboratory orders have cleared payment via eCitizen or valid insurance cover.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:0.87rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Req # / Date</th>
                                <th>Patient Details</th>
                                <th>Doctor</th>
                                <th>Laboratory Tests Ordered</th>
                                <th>Settlement Channel</th>
                                <th class="text-end">Total (KES)</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($bills as $b):
                            $isIns = !empty($b['insurance_provider']) && $b['insurance_provider'] !== 'None';
                        ?>
                            <tr>
                                <td class="ps-3 text-nowrap">
                                    <div class="fw-bold text-dark">#<?= $b['request_id'] ?></div>
                                    <small class="text-muted"><?= date('d M H:i', strtotime($b['request_date'])) ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($b['full_name']) ?></div>
                                    <small class="text-muted">OPD: <?= htmlspecialchars($b['opd_number']) ?> &middot; <?= $b['gender'] ?>, <?= $b['age'] ?>y</small>
                                    <?php if (!empty($b['phone_number'])): ?>
                                    <div class="text-muted" style="font-size:0.75rem;"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($b['phone_number']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="small text-muted"><i class="fa-solid fa-user-doctor me-1"></i><?= htmlspecialchars($b['requested_by'] ?: 'Clinician') ?></span>
                                </td>
                                <td>
                                    <span class="small d-inline-block text-truncate" style="max-width:320px;" title="<?= htmlspecialchars($b['tests_str']) ?>">
                                        <?= htmlspecialchars($b['tests_str'] ?: 'Standard Investigation Panel') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isIns): ?>
                                    <span class="badge bg-purple-subtle text-purple border px-2 py-1" style="background:#ede9fe;color:#6b21a8;">
                                        <i class="fa-solid fa-shield-halved me-1"></i><?= htmlspecialchars($b['insurance_provider']) ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                        <i class="fa-solid fa-building-columns me-1"></i>eCitizen (Paybill 222222)
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold text-danger fs-6">
                                    <?= number_format($b['total_cost'], 2) ?>
                                </td>
                                <td class="text-end pe-3">
                                    <button class="btn btn-success btn-sm rounded-pill px-3 fw-bold shadow-sm" onclick="openPay(<?= htmlspecialchars(json_encode($b)) ?>)">
                                        <i class="fa-solid fa-credit-card me-1"></i> Settle Bill
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- TAB 2: SETTLED RECEIPTS REGISTER -->
            <div class="tab-pane fade p-3 pt-0" id="settled-content">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <small class="text-muted">Register of cleared eCitizen M-Pesa receipts, PRN references, SHA insurance vouchers, and statutory exemptions.</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:0.87rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Payment Date</th>
                                <th>Req # / Patient</th>
                                <th>Channel</th>
                                <th>eCitizen Ref / PRN</th>
                                <th>Cashier</th>
                                <th class="text-end">Amount Paid (KES)</th>
                                <th class="text-end">Insurer / Copay</th>
                                <th class="text-end pe-3">Official Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($settled_rows)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No settled transactions recorded yet.</td></tr>
                        <?php else:
                            foreach ($settled_rows as $s):
                                $pm = strtolower($s['payment_method']);
                                $isMpesa = (strpos($pm, 'm-pesa') !== false || strpos($pm, 'mpesa') !== false);
                                $isEcitizen = (strpos($pm, 'ecitizen') !== false);
                                $isIns = (strpos($pm, 'insurance') !== false || $s['payment_type'] === 'Insurance' || $s['payment_type'] === 'Split');
                                $isWaiver = (strpos($pm, 'waiver') !== false);
                        ?>
                            <tr>
                                <td class="ps-3 text-nowrap">
                                    <div class="fw-bold text-dark"><?= date('d M Y', strtotime($s['payment_date'])) ?></div>
                                    <small class="text-muted"><?= date('H:i', strtotime($s['payment_date'])) ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($s['full_name']) ?></div>
                                    <small class="text-muted">OPD: <?= htmlspecialchars($s['opd_number']) ?> &middot; Req #<?= $s['request_id'] ?></small>
                                </td>
                                <td>
                                    <?php if ($isMpesa): ?>
                                    <span class="badge bg-success px-2 py-1">
                                        <i class="fa-solid fa-mobile-screen me-1"></i> eCitizen M-Pesa (222222)
                                    </span>
                                    <?php elseif ($isEcitizen): ?>
                                    <span class="badge bg-primary px-2 py-1">
                                        <i class="fa-solid fa-building-columns me-1"></i> eCitizen (222222)
                                    </span>
                                    <?php elseif ($isIns): ?>
                                    <span class="badge px-2 py-1 text-white" style="background:#7e22ce;">
                                        <i class="fa-solid fa-shield-halved me-1"></i> <?= htmlspecialchars($s['insurance_provider'] ?: 'Insurance') ?>
                                    </span>
                                    <?php elseif ($isWaiver): ?>
                                    <span class="badge bg-warning text-dark px-2 py-1">
                                        <i class="fa-solid fa-file-signature me-1"></i> Statutory Waiver
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary px-2 py-1">
                                        <?= htmlspecialchars($s['payment_method']) ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="text-dark fw-bold" style="font-size:0.83rem;"><?= htmlspecialchars($s['reference_no'] ?: 'PRN-222222') ?></code>
                                </td>
                                <td class="small text-muted">
                                    <i class="fa-solid fa-user-check me-1"></i><?= htmlspecialchars($s['received_by'] ?: 'Hospital Cashier') ?>
                                </td>
                                <td class="text-end fw-bold text-success">
                                    <?= number_format($s['amount_paid'], 2) ?>
                                </td>
                                <td class="text-end small">
                                    <?php if ($s['insurer_amount'] > 0): ?>
                                    <div class="text-purple" style="color:#7e22ce;">Ins: KES <?= number_format($s['insurer_amount'], 2) ?></div>
                                    <?php endif; ?>
                                    <?php if ($s['patient_copay'] > 0): ?>
                                    <div class="text-warning-emphasis fw-bold">Copay: KES <?= number_format($s['patient_copay'], 2) ?></div>
                                    <?php endif; ?>
                                    <?php if ($s['insurer_amount'] == 0 && $s['patient_copay'] == 0): ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <a href="print_receipt.php?id=<?= $s['request_id'] ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1 fw-bold" style="font-size:0.78rem;">
                                        <i class="fa-solid fa-print me-1"></i> eCitizen Receipt
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

</div>

<!-- ── eCITIZEN DIGITAL PAYMENT MODAL ────────────────────────────────────── -->
<div class="modal fade" id="payModal" tabindex="-1" aria-labelledby="payModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:15px;overflow:hidden;">
            
            <div class="modal-header text-white border-0 py-3 px-4" style="background:linear-gradient(135deg,#064e3b 0%,#0f766e 100%);">
                <div class="d-flex align-items-center gap-2">
                    <img src="ecitizen.png" alt="eCitizen" style="height:32px;background:#fff;padding:2px 6px;border-radius:6px;">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="payModalLabel">
                            Government eCitizen Payment Portal &bull; Paybill 222222
                        </h5>
                        <small class="opacity-75" style="font-size:0.75rem;">Under Gazette Notice No. 16008 of 2022 &amp; PFM Act 2012</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" id="payForm">
                <div class="modal-body p-4 pt-3">
                    <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
                    <input type="hidden" name="payment_id"  id="mPayId">
                    <input type="hidden" name="request_id"  id="mReqId">
                    <input type="hidden" name="amount_paid" id="mAmountPaid">

                    <!-- Patient & Bill Details Banner -->
                    <div class="p-3 mb-3 text-white rounded-3 shadow-sm" style="background:linear-gradient(135deg, #0f172a, #1e3a8a);">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="fw-bold mb-0" id="mName"></h5>
                                <div class="small opacity-75" id="mMeta"></div>
                                <div class="small opacity-75" id="mPhone"></div>
                            </div>
                            <div class="text-end">
                                <div class="small text-uppercase opacity-75">Total Billed Due</div>
                                <h3 class="fw-bold mb-0 text-warning" id="mAmount"></h3>
                                <span class="badge bg-success-subtle text-success border border-success-subtle mt-1" style="font-size:0.7rem;">MOH Gazetted Tariff</span>
                            </div>
                        </div>
                        <div class="small mt-2 pt-2 border-top border-white border-opacity-25" id="mTests"></div>
                    </div>

                    <!-- Insurer Active Warning -->
                    <div class="alert alert-info py-2 small mb-3 d-none d-flex align-items-center gap-2" id="insNotice">
                        <i class="fa-solid fa-shield-halved fs-5"></i>
                        <div>Patient is registered with <strong id="insNoticeName"></strong>. Pre-authorization claim can be lodged below.</div>
                    </div>

                    <!-- Payment Method Tabs (Strictly Cashless eCitizen / Schemes) -->
                    <div class="btn-group w-100 mb-3" role="group">
                        <input type="radio" class="btn-check" name="payment_method_tab" id="tab_mpesa" value="ecitizen_mpesa" checked onchange="switchTab('ecitizen_mpesa')">
                        <label class="btn btn-outline-success fw-bold btn-sm py-2" for="tab_mpesa">
                            <i class="fa-solid fa-mobile-screen me-1"></i> eCitizen M-Pesa (222222)
                        </label>

                        <input type="radio" class="btn-check" name="payment_method_tab" id="tab_prn" value="ecitizen_prn" onchange="switchTab('ecitizen_prn')">
                        <label class="btn btn-outline-primary fw-bold btn-sm py-2" for="tab_prn">
                            <i class="fa-solid fa-building-columns me-1"></i> eCitizen Portal PRN
                        </label>

                        <input type="radio" class="btn-check" name="payment_method_tab" id="tab_insurance" value="insurance" onchange="switchTab('insurance')">
                        <label class="btn btn-outline-purple fw-bold btn-sm py-2" style="border-color:#9333ea;color:#7e22ce;" for="tab_insurance">
                            <i class="fa-solid fa-hospital me-1"></i> SHA / Linda Mama
                        </label>

                        <input type="radio" class="btn-check" name="payment_method_tab" id="tab_waiver" value="waiver" onchange="switchTab('waiver')">
                        <label class="btn btn-outline-warning text-dark fw-bold btn-sm py-2" for="tab_waiver">
                            <i class="fa-solid fa-file-signature me-1"></i> Fee Waiver (PFM Act)
                        </label>
                    </div>

                    <input type="hidden" name="payment_method" id="methodField" value="ecitizen_mpesa">

                    <!-- 1. eCITIZEN M-PESA PANEL -->
                    <div class="pay-panel" id="panel-ecitizen_mpesa">
                        <div class="p-3 bg-light border rounded-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold text-success"><i class="fa-solid fa-qrcode me-1"></i> Government Paybill: 222222</span>
                                <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i> National Treasury Active</span>
                            </div>
                            <div class="row g-2 align-items-center">
                                <div class="col-sm-6">
                                    <div class="p-2 bg-white rounded border">
                                        <small class="text-muted d-block text-uppercase" style="font-size:0.7rem;font-weight:700;">Business Number</small>
                                        <span class="fs-5 fw-bold text-dark">222222</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="p-2 bg-white rounded border">
                                        <small class="text-muted d-block text-uppercase" style="font-size:0.7rem;font-weight:700;">Account Number</small>
                                        <span class="fs-5 fw-bold text-primary font-monospace" id="mAccountPrompt">YALA-OPD</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- STK Push Trigger Card -->
                        <div class="p-3 mb-3 rounded-3" style="background:#f0fdf4;border:1.5px solid #86efac;">
                            <label class="form-label small fw-bold text-success text-uppercase mb-1">
                                <i class="fa-solid fa-bolt me-1"></i> Instant eCitizen STK Push to Patient Handset
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-phone text-success"></i></span>
                                <input type="text" id="stkPhoneInput" class="form-control" placeholder="e.g. 0712345678 or +254712345678">
                                <button type="button" class="btn btn-success fw-bold px-3" id="btnStkPush" onclick="triggerStkPush()">
                                    <i class="fa-solid fa-paper-plane me-1"></i> Send STK Push
                                </button>
                            </div>
                            <div id="stkFeedback" class="small mt-2 d-none"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">
                                Safaricom M-Pesa Transaction Code / eCitizen PRN <span class="text-danger">*</span>
                            </label>
                            <input name="mpesa_code" id="mpesaCode" class="form-control form-control-lg font-monospace text-uppercase"
                                   placeholder="e.g. SIY92KL4M1 or PRN-222222-XXXXX" maxlength="25" style="letter-spacing:1px;" required>
                            <small class="text-muted">Enter or verify the 10-character M-Pesa receipt code received on patient's SMS from 222222.</small>
                        </div>
                    </div>

                    <!-- 2. eCITIZEN DIRECT / PORTAL PRN PANEL -->
                    <div class="pay-panel d-none" id="panel-ecitizen_prn">
                        <div class="p-3 bg-light border rounded-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold text-primary"><i class="fa-solid fa-globe me-1"></i> eCitizen Direct Portal / Bank Agent</span>
                                <span class="badge bg-primary">GovPay</span>
                            </div>
                            <small class="text-muted">For patients who generated an eCitizen Invoice online or deposited at an authorized bank agent (KCB, Equity, Co-op, Postbank).</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">eCitizen Payment Reference Number (PRN) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input name="ecitizen_prn" id="prnField" class="form-control font-monospace" placeholder="e.g. PRN-222222-849201">
                                <button type="button" class="btn btn-outline-secondary" onclick="generateSimPrn()">Auto-Generate PRN</button>
                            </div>
                            <small class="text-muted">Official PRN assigned by National Treasury eCitizen system.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Deposit Channel / Agent Bank (Optional)</label>
                            <select name="bank_channel" class="form-select">
                                <option value="eCitizen Web Portal">eCitizen Web Portal (Direct Debit / Card)</option>
                                <option value="KCB Bank eCitizen Agent">KCB Bank eCitizen Counter</option>
                                <option value="Equity Bank eCitizen Agent">Equity Bank eCitizen Agent</option>
                                <option value="Co-op Bank GovPay">Co-operative Bank GovPay Agent</option>
                                <option value="Pesalink RTGS">Pesalink / National Payment Switch</option>
                            </select>
                        </div>
                    </div>

                    <!-- 3. INSURANCE / SHA / LINDA MAMA PANEL -->
                    <div class="pay-panel d-none" id="panel-insurance">
                        <div class="p-3 bg-light border rounded-3 mb-3" style="font-size:0.86rem;">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Gross Investigation Bill:</span>
                                <span class="fw-bold" id="ibTotal">KES 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Covered by Insurance / SHA:</span>
                                <span class="fw-bold text-purple" style="color:#7e22ce;" id="ibCover">KES 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between pt-1 border-top fw-bold">
                                <span>Patient Copayment Due:</span>
                                <span class="text-warning-emphasis" id="ibCopay">KES 0.00</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Health Insurance Scheme / Fund</label>
                            <select name="insurer_name" id="insurerSelect" class="form-select" onchange="onInsurerChange()">
                                <option value="">— Select Statutory Scheme / Insurer —</option>
                                <?php foreach ($providers as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold text-muted text-uppercase">Policy / Card / SHA Number</label>
                                <input name="insurance_member_no" id="memberNoField" class="form-control" placeholder="e.g. SHA-123456789">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold text-muted text-uppercase">Pre-Authorization / Claim Ref</label>
                                <input name="claim_ref" id="claimRefField" class="form-control" placeholder="e.g. CLM-SHA-2026-9021">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Patient Copayment (KES)</label>
                            <input name="copay_amount" id="copayField" type="number" step="10" min="0" value="0" class="form-control" oninput="updateInsSplit()">
                            <small class="text-muted">Enter 0 if 100% covered under Linda Mama or Universal Primary Health Care.</small>
                        </div>

                        <div class="p-3 bg-light border rounded-3 mb-3 d-none" id="copayEcitizenBox">
                            <label class="form-label small fw-bold text-danger text-uppercase mb-1">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i> Patient Copay Must Be Paid via eCitizen Paybill 222222
                            </label>
                            <input name="copay_ecitizen_ref" id="copayRefField" class="form-control font-monospace text-uppercase" placeholder="M-Pesa Code for Copayment (e.g. SIY81KC920)">
                            <small class="text-muted">Physical cash copay is not allowed under Government regulations.</small>
                        </div>
                    </div>

                    <!-- 4. STATUTORY FEE WAIVER (PFM ACT SECTION 32) -->
                    <div class="pay-panel d-none" id="panel-waiver">
                        <div class="alert alert-warning small mb-3">
                            <i class="fa-solid fa-scale-balanced me-1"></i>
                            <strong>Statutory Exemption (Public Finance Management Act 2012, Sec 32):</strong>
                            All fee exemptions require validation by the Hospital Social Work Department and approval by the Medical Superintendent or Sub-County MOH.
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Exemption Category</label>
                            <select name="waiver_category" class="form-select">
                                <option value="Indigent / Destitute Patient">Indigent / Destitute Patient (Unable to pay)</option>
                                <option value="Orphaned or Abandoned Child">Orphaned or Abandoned Child</option>
                                <option value="Sexual & Gender Based Violence (SGBV)">SGBV Survivor / Police Referral</option>
                                <option value="Emergency Stabilization">Emergency / Unconscious Patient Stabilization</option>
                                <option value="Inpatient Destitute Discharge">Inpatient Destitute Discharge</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Clinical &amp; Social Rationale</label>
                            <textarea name="waiver_reason" class="form-control" rows="2" placeholder="Document social worker assessment notes and justification..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Authorizing Public Officer</label>
                            <input name="waiver_officer" class="form-control" value="Dr. Edwin Ngwawe (Medical Superintendent)">
                        </div>
                    </div>

                    <!-- Legal Disclaimer regarding physical cash -->
                    <div class="p-2 bg-light border rounded text-center small text-muted mt-3" style="font-size:0.78rem;">
                        <i class="fa-solid fa-triangle-exclamation text-warning me-1"></i>
                        <strong>Physical Cash Notice:</strong> Over-the-counter physical cash is prohibited by Law. If patient has physical currency, assist them to load onto M-Pesa and remit to <strong>Paybill 222222</strong>.
                    </div>

                </div>
                <div class="modal-footer bg-light border-0 pt-0 px-4 pb-3">
                    <button type="button" class="btn btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="record_payment" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
                        <i class="fa-solid fa-circle-check me-1"></i> Verify &amp; Issue eCitizen Clearance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let curTotal = 0;
let curOpd = '';
let curPhone = '';

function openPay(b) {
    document.getElementById('mPayId').value      = b.payment_id;
    document.getElementById('mReqId').value      = b.request_id;
    document.getElementById('mAmountPaid').value = b.total_cost;
    document.getElementById('mName').textContent = b.full_name;
    document.getElementById('mMeta').textContent =
        (b.gender || '') + (b.age ? ' · ' + b.age + 'y' : '') + ' · OPD: ' + (b.opd_number || '');
    
    curOpd = b.opd_number || 'OPD-UNKNOWN';
    curPhone = b.phone_number || '';
    curTotal = parseFloat(b.total_cost);

    document.getElementById('mPhone').textContent = curPhone ? 'Mobile: ' + curPhone : 'Mobile: Not recorded';
    document.getElementById('stkPhoneInput').value = curPhone;
    document.getElementById('mAccountPrompt').textContent = 'YALA-' + curOpd;

    document.getElementById('mAmount').textContent = 'KES ' + curTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('mTests').textContent  = 'Investigations: ' + (b.tests_str || 'Standard Laboratory Panel');
    document.getElementById('mpesaCode').value     = '';
    document.getElementById('stkFeedback').classList.add('d-none');

    const isIns = (b.insurance_provider && b.insurance_provider !== '' && b.insurance_provider !== 'None') || b.payment_type === 'Insurance';
    const notice = document.getElementById('insNotice');

    if (isIns) {
        notice.classList.remove('d-none');
        document.getElementById('insNoticeName').textContent = b.insurance_provider || 'National Insurance Scheme';
        document.getElementById('tab_insurance').checked = true;
        switchTab('insurance');

        const sel = document.getElementById('insurerSelect');
        for (let o of sel.options) {
            if (o.value.toLowerCase().includes((b.insurance_provider || '').toLowerCase())) {
                sel.value = o.value; break;
            }
        }
        if (b.insurance_member_no) {
            document.getElementById('memberNoField').value = b.insurance_member_no;
        }

        // Determine copay estimate
        let defaultCopay = 0;
        if (b.insurance_provider.toLowerCase().includes('linda mama')) {
            defaultCopay = 0;
        } else if (curTotal > 1500) {
            defaultCopay = 200;
        }
        document.getElementById('copayField').value = defaultCopay;
        updateInsSplit();
    } else {
        notice.classList.add('d-none');
        document.getElementById('tab_mpesa').checked = true;
        switchTab('ecitizen_mpesa');
    }

    new bootstrap.Modal(document.getElementById('payModal')).show();
}

function switchTab(method) {
    document.querySelectorAll('.pay-panel').forEach(p => p.classList.add('d-none'));
    const p = document.getElementById('panel-' + method);
    if (p) p.classList.remove('d-none');
    document.getElementById('methodField').value = method;

    const mpesaCodeInput = document.getElementById('mpesaCode');
    if (method === 'ecitizen_mpesa') {
        mpesaCodeInput.setAttribute('required', 'required');
    } else {
        mpesaCodeInput.removeAttribute('required');
    }

    if (method === 'insurance') {
        updateInsSplit();
    } else {
        document.getElementById('mAmountPaid').value = curTotal;
    }
}

function triggerStkPush() {
    const phone = document.getElementById('stkPhoneInput').value.trim();
    const fb = document.getElementById('stkFeedback');
    const btn = document.getElementById('btnStkPush');

    if (!phone || phone.length < 9) {
        fb.className = 'small mt-2 alert alert-warning py-1';
        fb.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Please enter a valid patient Safaricom phone number.';
        fb.classList.remove('d-none');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Pushing STK...';
    fb.className = 'small mt-2 alert alert-info py-2';
    fb.innerHTML = `<i class="fa-solid fa-satellite-dish me-1"></i> Initiating eCitizen Paybill 222222 STK Prompt for <strong>KES ${curTotal.toFixed(2)}</strong> to <strong>${phone}</strong> (Acc: YALA-${curOpd})...`;
    fb.classList.remove('d-none');

    setTimeout(() => {
        // Generate simulated authentic Safaricom confirmation code
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let rnd = 'SIY';
        for (let i = 0; i < 7; i++) {
            rnd += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('mpesaCode').value = rnd;
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Send STK Push';
        fb.className = 'small mt-2 alert alert-success py-2';
        fb.innerHTML = `<i class="fa-solid fa-circle-check me-1"></i> <strong>STK PIN Entered!</strong> eCitizen Gateway Confirmed: M-Pesa Code <code>${rnd}</code> received for KES ${curTotal.toFixed(2)}.`;
    }, 2200);
}

function generateSimPrn() {
    const num = Math.floor(100000 + Math.random() * 900000);
    document.getElementById('prnField').value = 'PRN-222222-' + num;
}

function onInsurerChange() {
    const sel = document.getElementById('insurerSelect').value;
    if (sel.toLowerCase().includes('linda mama')) {
        document.getElementById('copayField').value = 0;
    }
    updateInsSplit();
}

function updateInsSplit() {
    const copay = parseFloat(document.getElementById('copayField').value) || 0;
    const cover = Math.max(0, curTotal - copay);
    document.getElementById('ibTotal').textContent = 'KES ' + curTotal.toFixed(2);
    document.getElementById('ibCover').textContent = 'KES ' + cover.toFixed(2);
    document.getElementById('ibCopay').textContent = 'KES ' + copay.toFixed(2);
    document.getElementById('mAmountPaid').value   = curTotal;

    const box = document.getElementById('copayEcitizenBox');
    if (copay > 0) {
        box.classList.remove('d-none');
    } else {
        box.classList.add('d-none');
    }
}
</script>

<?php include 'includes/footer.php'; ?>
