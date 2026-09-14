<?php
// billing.php
// Hospital Cashier & Billing Management Console — Yala Sub-County Hospital
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

// ── RECORD PAYMENT PROCEDURE ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    csrf_verify();

    $paymentId     = intval($_POST['payment_id']   ?? 0);
    $requestId     = intval($_POST['request_id']   ?? 0);
    $method_input  = strtolower(trim($_POST['payment_method'] ?? ''));
    $amountPaid    = floatval($_POST['amount_paid'] ?? 0);
    $mpesaCode     = strtoupper(trim($_POST['mpesa_code'] ?? ''));
    $cashRef       = trim($_POST['cash_ref'] ?? '');
    $insurerName   = trim($_POST['insurer_name'] ?? '');
    $memberNo      = trim($_POST['insurance_member_no'] ?? '');
    $claimRef      = trim($_POST['claim_ref'] ?? '');
    $copay         = floatval($_POST['copay_amount'] ?? 0);
    $waiverReason  = trim($_POST['waiver_reason'] ?? '');
    $waiverOfficer = trim($_POST['waiver_officer'] ?? '');
    $receivedBy    = $_SESSION['full_name'] ?? 'Cashier';

    if (!$paymentId || !$requestId || !$method_input) {
        $error = "Missing payment order information or payment method.";
    } else {
        // Fetch patient details
        $q_p = $conn->query("SELECT p.patient_id, p.full_name, p.opd_number FROM lab_requests r JOIN patients p ON r.patient_id=p.patient_id WHERE r.request_id = $requestId");
        $pat_row = $q_p ? $q_p->fetch_assoc() : null;
        $pat_id = $pat_row['patient_id'] ?? 0;
        $pat_name = $pat_row['full_name'] ?? 'Patient';

        // Map method and determine amounts
        $pay_type = 'Cash';
        $pay_method = 'Cash';
        $ref = '';
        $actual_amount = $amountPaid;
        $billed_to_ins = 0.00;

        if ($method_input === 'mpesa') {
            $pay_method = 'M-Pesa';
            $pay_type = 'Cash';
            $ref = $mpesaCode ?: ('MPE-' . strtoupper(substr(md5(uniqid()), 0, 8)));
            $actual_amount = $amountPaid;
        } elseif ($method_input === 'cash') {
            $pay_method = 'Cash';
            $pay_type = 'Cash';
            $ref = $cashRef ?: ('CASH-' . date('Y') . '-' . rand(10000, 99999));
            $actual_amount = $amountPaid;
        } elseif ($method_input === 'insurance') {
            $pay_method = 'Insurance';
            $pay_type = ($copay > 0) ? 'Split' : 'Insurance';
            if (empty($insurerName)) $insurerName = 'SHA';
            $cl_prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $insurerName), 0, 4)) ?: 'SHA';
            $ref = $claimRef ?: ("CLM-$cl_prefix-" . date('Y') . "-$requestId");
            
            // Patient pays copay at counter; insurer covers the balance
            $actual_amount = $copay;
            $billed_to_ins = max(0, $amountPaid - $copay);
        } elseif ($method_input === 'waiver') {
            $pay_method = 'Waiver';
            $pay_type = 'Cash';
            $actual_amount = 0.00;
            $ref = 'WAIVER-' . date('Ymd') . "-$requestId";
            $waiverReason = trim($waiverReason . ($waiverOfficer ? " (Authorized by: $waiverOfficer)" : ""));
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

            $ins_amount_val = ($method_input === 'insurance') ? $billed_to_ins : $amountPaid;

            $stmt->bind_param(
                "dssssssssdddssi",
                $actual_amount, $pay_method, $pay_type, $ref,
                $insurerName, $insurerName, $memberNo, $ref, $ref,
                $ins_amount_val, $copay, $copay,
                $waiverReason, $receivedBy,
                $paymentId
            );

            if (!$stmt->execute()) throw new Exception("Payment record failed: " . $stmt->error);
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
                    $stmt_c = $conn->prepare("INSERT INTO insurance_claims (request_id, patient_id, insurer_name, insurance_number, claim_ref, billed_amount, approved_amount, copay_amount, status, notes) VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'Submitted', 'Claim lodged at cashier counter.')");
                    $stmt_c->bind_param("iisssdd", $requestId, $pat_id, $insurerName, $memberNo, $ref, $billed_to_ins, $copay);
                    $stmt_c->execute();
                    $stmt_c->close();
                }
            }

            // 3. Mark lab_request as Paid so lab tech can enter results
            $conn->query("UPDATE lab_requests SET payment_status = 'Paid' WHERE request_id = $requestId");

            // 4. Dispatch notification to Lab Technologists
            $notif_msg = $conn->real_escape_string("Payment Cleared: Req #$requestId ($pat_name) — KES " . number_format($actual_amount, 2) . " via $pay_method ($ref).");
            $notif_link = $conn->real_escape_string("enter_results.php?manage_id=$requestId");
            $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('LabTech', '$notif_msg', '$notif_link', 0, NOW())");

            // 5. Audit Log
            audit_log($conn, 'record_payment', 'payments', $paymentId, "Req #$requestId via $pay_method ($ref) KES $actual_amount");

            $conn->commit();

            // 6. Send SMS Receipt Notification to patient
            sms_payment_receipt($conn, $requestId, $actual_amount, $pay_method, $ref);

            $receipt_req_id = $requestId;
            $success = "Payment cleared successfully for Request #$requestId ($pat_name) via $pay_method ($ref).";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Payment failed: " . $e->getMessage();
        }
    }
}

// ── CASHIER SUMMARY METRICS (TODAY) ──────────────────────────────────────────
$r = $conn->query("SELECT COUNT(*) FROM lab_requests WHERE payment_status = 'Unpaid'");
$unpaid_bills_count = $r ? $r->fetch_row()[0] : 0;

$r = $conn->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE payment_method = 'Cash' AND DATE(payment_date) = CURDATE()");
$cash_today = $r ? $r->fetch_row()[0] : 0;

$r = $conn->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE payment_method = 'M-Pesa' AND DATE(payment_date) = CURDATE()");
$mpesa_today = $r ? $r->fetch_row()[0] : 0;

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
    $providers = ['SHA (Social Health Authority)', 'Linda Mama (MOH Free Maternity)', 'NHIF-Legacy', 'AAR Insurance Kenya', 'Jubilee Health Insurance', 'CIC General Insurance', 'Britam Health', 'Madison Insurance'];
}

$csrf = csrf_token();
$page_title = 'Cashier & Billing — Yala LIMS';
include 'includes/header.php';
?>

<div class="container-fluid px-4 py-3">

    <!-- Header Banner -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"
         style="background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 60%,#2563eb 100%);padding:22px 28px;border-radius:14px;color:#fff;">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h3 class="fw-bold mb-0 text-white"><i class="fa-solid fa-cash-register me-2"></i>Hospital Cashier &amp; Billing</h3>
                <span class="badge bg-primary-subtle text-primary border px-2 py-1" style="font-size:0.75rem;">MOH POS Console</span>
            </div>
            <p class="mb-0 text-white-50" style="font-size:0.88rem;">
                Process cash, Safaricom M-Pesa STK, SHA / NHIF claims, private medical cover, and indigent waivers.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="insurance_claims.php" class="btn btn-light fw-bold text-primary rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-shield-halved me-1"></i> Claims Manager
            </a>
            <a href="dashboard.php" class="btn btn-outline-light rounded-pill px-3">
                <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Alert banners -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center justify-content-between gap-3 shadow-sm" role="alert">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-circle-check fs-5"></i>
            <div><?= htmlspecialchars($success) ?></div>
        </div>
        <div>
            <?php if ($receipt_req_id > 0): ?>
            <a href="print_receipt.php?id=<?= $receipt_req_id ?>" target="_blank" class="btn btn-success btn-sm rounded-pill px-3 fw-bold shadow-sm">
                <i class="fa-solid fa-print me-1"></i> Print Official Receipt
            </a>
            <?php endif; ?>
            <button type="button" class="btn-close ms-2" data-bs-dismiss="alert"></button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm" role="alert">
        <i class="fa-solid fa-circle-exclamation fs-5"></i>
        <div><?= htmlspecialchars($error) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- KPI Metric Cards (Today) -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#fef2f2;border-left:4px solid #dc2626 !important;">
                <div class="text-muted fw-semibold small text-uppercase">Unpaid Bills in Queue</div>
                <h2 class="fw-bold my-1 text-danger"><?= $unpaid_bills_count ?></h2>
                <small class="text-muted">Awaiting cashier settlement</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#f0fdf4;border-left:4px solid #16a34a !important;">
                <div class="text-muted fw-semibold small text-uppercase">M-Pesa Today</div>
                <h2 class="fw-bold my-1 text-success">KES <?= number_format($mpesa_today, 0) ?></h2>
                <small class="text-muted">Lipa na M-Pesa collections</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#eff6ff;border-left:4px solid #2563eb !important;">
                <div class="text-muted fw-semibold small text-uppercase">Cash Today</div>
                <h2 class="fw-bold my-1 text-primary">KES <?= number_format($cash_today, 0) ?></h2>
                <small class="text-muted">Physical cash at counter</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#faf5ff;border-left:4px solid #9333ea !important;">
                <div class="text-muted fw-semibold small text-uppercase">Insurance Billed Today</div>
                <h2 class="fw-bold my-1 text-purple" style="color:#7e22ce;">KES <?= number_format($insurance_today, 0) ?></h2>
                <small class="text-muted">SHA / Corporate claims logged</small>
            </div>
        </div>
    </div>

    <!-- MAIN TABS: PENDING QUEUE vs SETTLED LOG -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-0">
            <ul class="nav nav-pills card-header-pills" id="billingTab" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active fw-bold px-3" id="pending-tab" data-bs-toggle="pill" data-bs-target="#pending-content" type="button">
                        <i class="fa-solid fa-clock me-1"></i> Pending Bills Queue
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
                    <small class="text-muted">Select an unpaid or insurance order to collect payment and clear the laboratory request.</small>
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
                    <h5 class="fw-bold text-dark">All Caught Up!</h5>
                    <p class="small text-muted mb-0">No pending payments require clearance at this time.</p>
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
                                <th>Billing Type</th>
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
                                </td>
                                <td>
                                    <span class="small text-muted"><i class="fa-solid fa-user-doctor me-1"></i><?= htmlspecialchars($b['requested_by'] ?: 'Clinician') ?></span>
                                </td>
                                <td>
                                    <span class="small d-inline-block text-truncate" style="max-width:320px;" title="<?= htmlspecialchars($b['tests_str']) ?>">
                                        <?= htmlspecialchars($b['tests_str'] ?: 'No tests listed') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isIns): ?>
                                    <span class="badge bg-purple-subtle text-purple border px-2 py-1" style="background:#ede9fe;color:#6b21a8;">
                                        <i class="fa-solid fa-shield-halved me-1"></i><?= htmlspecialchars($b['insurance_provider']) ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                        Cash / Out-of-Pocket
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold text-danger fs-6">
                                    <?= number_format($b['total_cost'], 2) ?>
                                </td>
                                <td class="text-end pe-3">
                                    <button class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" onclick="openPay(<?= htmlspecialchars(json_encode($b)) ?>)">
                                        <i class="fa-solid fa-credit-card me-1"></i> Clear Bill
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
                    <small class="text-muted">Recent settled transactions, M-Pesa confirmation codes, insurance vouchers, and official receipt reprinting.</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:0.87rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Payment Date</th>
                                <th>Req # / Patient</th>
                                <th>Method</th>
                                <th>Reference / Code</th>
                                <th>Cashier</th>
                                <th class="text-end">Paid (KES)</th>
                                <th class="text-end">Insurer / Copay</th>
                                <th class="text-end pe-3">Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($settled_rows)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No settled transactions found.</td></tr>
                        <?php else:
                            foreach ($settled_rows as $s):
                                $pm = strtolower($s['payment_method']);
                                $b_cls = ($pm === 'm-pesa') ? 'bg-success' : (($pm === 'insurance') ? 'bg-purple' : (($pm === 'waiver') ? 'bg-warning text-dark' : 'bg-primary'));
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
                                    <span class="badge <?= $b_cls ?> px-2 py-1" style="<?= ($pm==='insurance')?'background:#6b21a8;':'' ?>">
                                        <?= htmlspecialchars($s['payment_method']) ?>
                                    </span>
                                </td>
                                <td>
                                    <code class="text-dark fw-bold"><?= htmlspecialchars($s['reference_no'] ?: 'N/A') ?></code>
                                </td>
                                <td class="small text-muted">
                                    <i class="fa-solid fa-user-check me-1"></i><?= htmlspecialchars($s['received_by'] ?: 'Cashier') ?>
                                </td>
                                <td class="text-end fw-bold text-success">
                                    <?= number_format($s['amount_paid'], 2) ?>
                                </td>
                                <td class="text-end small">
                                    <?php if ($s['insurer_amount'] > 0): ?>
                                    <div>Ins: KES <?= number_format($s['insurer_amount'], 2) ?></div>
                                    <?php endif; ?>
                                    <?php if ($s['patient_copay'] > 0): ?>
                                    <div class="text-warning-emphasis fw-bold">Copay: KES <?= number_format($s['patient_copay'], 2) ?></div>
                                    <?php endif; ?>
                                    <?php if ($s['insurer_amount'] == 0 && $s['patient_copay'] == 0): ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <a href="print_receipt.php?id=<?= $s['request_id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-1" style="font-size:0.75rem;">
                                        <i class="fa-solid fa-print me-1"></i> Receipt
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

<!-- ── PAYMENT MODAL ──────────────────────────────────────────────────────── -->
<div class="modal fade" id="payModal" tabindex="-1" aria-labelledby="payModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:15px;">
            <div class="modal-header bg-light border-0 pb-0 pt-3 px-4">
                <h5 class="modal-title fw-bold" id="payModalLabel">
                    <i class="fa-solid fa-cash-register me-2 text-primary"></i> Process Hospital Payment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4 pt-2">
                    <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
                    <input type="hidden" name="payment_id"  id="mPayId">
                    <input type="hidden" name="request_id"  id="mReqId">
                    <input type="hidden" name="amount_paid" id="mAmountPaid">

                    <!-- Patient & Bill Banner -->
                    <div class="p-3 mb-3 text-white rounded-3 shadow-sm" style="background:linear-gradient(135deg, #0f172a, #1d4ed8);">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="fw-bold mb-0" id="mName"></h5>
                                <div class="small opacity-75" id="mMeta"></div>
                            </div>
                            <div class="text-end">
                                <div class="small text-uppercase opacity-75">Billed Total</div>
                                <h3 class="fw-bold mb-0 text-warning" id="mAmount"></h3>
                            </div>
                        </div>
                        <div class="small mt-2 pt-2 border-top border-white border-opacity-25" id="mTests"></div>
                    </div>

                    <div class="alert alert-info py-2 small mb-3 d-none d-flex align-items-center gap-2" id="insNotice">
                        <i class="fa-solid fa-shield-halved"></i>
                        <div>Patient is enrolled in <strong id="insNoticeName"></strong>.</div>
                    </div>

                    <!-- Payment Method Tabs -->
                    <div class="btn-group w-100 mb-3" role="group">
                        <input type="radio" class="btn-check" name="payment_method_tab" id="tab_mpesa" value="mpesa" checked onchange="switchTab('mpesa')">
                        <label class="btn btn-outline-primary fw-bold btn-sm py-2" for="tab_mpesa"><i class="fa-solid fa-mobile-screen me-1"></i> M-Pesa</label>

                        <input type="radio" class="btn-check" name="payment_method_tab" id="tab_cash" value="cash" onchange="switchTab('cash')">
                        <label class="btn btn-outline-primary fw-bold btn-sm py-2" for="tab_cash"><i class="fa-solid fa-money-bill me-1"></i> Cash</label>

                        <input type="radio" class="btn-check" name="payment_method_tab" id="tab_insurance" value="insurance" onchange="switchTab('insurance')">
                        <label class="btn btn-outline-primary fw-bold btn-sm py-2" for="tab_insurance"><i class="fa-solid fa-hospital me-1"></i> Insurance</label>

                        <input type="radio" class="btn-check" name="payment_method_tab" id="tab_waiver" value="waiver" onchange="switchTab('waiver')">
                        <label class="btn btn-outline-primary fw-bold btn-sm py-2" for="tab_waiver"><i class="fa-solid fa-file-signature me-1"></i> Waiver</label>
                    </div>

                    <input type="hidden" name="payment_method" id="methodField" value="mpesa">

                    <!-- M-PESA PANEL -->
                    <div class="pay-panel" id="panel-mpesa">
                        <div class="p-3 bg-light border rounded mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold text-success"><i class="fa-solid fa-satellite-dish me-1"></i> Lipa na M-Pesa Paybill</span>
                                <span class="badge bg-success">Active</span>
                            </div>
                            <div class="fs-5 fw-bold text-dark">Business No: 222222</div>
                            <small class="text-muted">Account No: Patient OPD Number</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">M-Pesa Transaction Code</label>
                            <input name="mpesa_code" id="mpesaCode" class="form-control form-control-lg font-monospace text-uppercase"
                                   placeholder="e.g. QHJ82K91LP" maxlength="12" style="letter-spacing:1px;">
                            <small class="text-muted">Verify 10-character alphanumeric M-Pesa SMS confirmation code.</small>
                        </div>
                    </div>

                    <!-- CASH PANEL -->
                    <div class="pay-panel d-none" id="panel-cash">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Cash Tendered by Patient (KES)</label>
                            <input type="number" step="10" id="cashTendered" class="form-control form-control-lg" placeholder="Enter cash received…" oninput="calcChange()">
                        </div>
                        <div class="p-2 mb-3 bg-light border rounded d-flex justify-content-between align-items-center">
                            <span class="small fw-bold text-muted text-uppercase">Change Due to Patient:</span>
                            <span class="fs-5 fw-bold text-success" id="lblChange">KES 0.00</span>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Receipt / Serial Voucher (Optional)</label>
                            <input name="cash_ref" class="form-control" placeholder="Auto-generated if left blank">
                        </div>
                    </div>

                    <!-- INSURANCE PANEL -->
                    <div class="pay-panel d-none" id="panel-insurance">
                        <div class="p-3 bg-light border rounded mb-3" style="font-size:0.85rem;">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Gross Bill:</span>
                                <span class="fw-bold" id="ibTotal">KES 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Insurance Covers:</span>
                                <span class="fw-bold text-purple" style="color:#7e22ce;" id="ibCover">KES 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between pt-1 border-top fw-bold">
                                <span>Patient Copay Due:</span>
                                <span class="text-warning-emphasis" id="ibCopay">KES 0.00</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Insurance Scheme</label>
                            <select name="insurer_name" id="insurerSelect" class="form-select">
                                <option value="">— Select Insurer —</option>
                                <?php foreach ($providers as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Member / Policy Card Number</label>
                            <input name="insurance_member_no" id="memberNoField" class="form-control" placeholder="e.g. SHA-123456789">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Pre-Authorization / Claim Ref Code</label>
                            <input name="claim_ref" id="claimRefField" class="form-control" placeholder="e.g. CLM-SHA-2026-9021 (Auto-generated if empty)">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Patient Copayment Collected (KES)</label>
                            <input name="copay_amount" id="copayField" type="number" step="10" min="0" value="0" class="form-control" oninput="updateInsSplit()">
                            <small class="text-muted">Leave 0 if service is 100% covered under Linda Mama or Primary Care.</small>
                        </div>
                    </div>

                    <!-- WAIVER PANEL -->
                    <div class="pay-panel d-none" id="panel-waiver">
                        <div class="alert alert-warning small mb-3">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            Fee exemptions and waivers require verification by Medical Superintendent or Sub-County Health Officer.
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Reason for Fee Waiver</label>
                            <textarea name="waiver_reason" class="form-control" rows="2" placeholder="State exemption criteria (e.g. Indigent patient, abandoned child, SGBV survivor)..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase">Authorizing Officer</label>
                            <input name="waiver_officer" class="form-control" placeholder="Dr. Edwin Ngwawe (Medical Superintendent)">
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-light border-0 pt-0 px-4 pb-3">
                    <button type="button" class="btn btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="record_payment" class="btn btn-success rounded-pill px-4 fw-bold">
                        <i class="fa-solid fa-circle-check me-1"></i> Confirm &amp; Issue Clearance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let curTotal = 0;

function openPay(b) {
    document.getElementById('mPayId').value      = b.payment_id;
    document.getElementById('mReqId').value      = b.request_id;
    document.getElementById('mAmountPaid').value = b.total_cost;
    document.getElementById('mName').textContent = b.full_name;
    document.getElementById('mMeta').textContent =
        (b.gender || '') + (b.age ? ' · ' + b.age + 'y' : '') + ' · OPD: ' + (b.opd_number || '');
    
    curTotal = parseFloat(b.total_cost);
    document.getElementById('mAmount').textContent = 'KES ' + curTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('mTests').textContent  = 'Tests: ' + (b.tests_str || 'Standard Panel');
    document.getElementById('mpesaCode').value     = '';
    document.getElementById('cashTendered').value  = '';
    document.getElementById('lblChange').textContent = 'KES 0.00';

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

        // Determine initial copay estimate
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
        switchTab('mpesa');
    }

    new bootstrap.Modal(document.getElementById('payModal')).show();
}

function switchTab(method) {
    document.querySelectorAll('.pay-panel').forEach(p => p.classList.add('d-none'));
    const p = document.getElementById('panel-' + method);
    if (p) p.classList.remove('d-none');
    document.getElementById('methodField').value = method;

    if (method === 'insurance') {
        updateInsSplit();
    } else {
        document.getElementById('mAmountPaid').value = curTotal;
    }
}

function calcChange() {
    const tendered = parseFloat(document.getElementById('cashTendered').value) || 0;
    const diff = tendered - curTotal;
    const lbl = document.getElementById('lblChange');
    if (diff >= 0) {
        lbl.textContent = 'KES ' + diff.toFixed(2);
        lbl.className = 'fs-5 fw-bold text-success';
    } else {
        lbl.textContent = 'Short by KES ' + Math.abs(diff).toFixed(2);
        lbl.className = 'fs-5 fw-bold text-danger';
    }
}

function updateInsSplit() {
    const copay = parseFloat(document.getElementById('copayField').value) || 0;
    const cover = Math.max(0, curTotal - copay);
    document.getElementById('ibTotal').textContent = 'KES ' + curTotal.toFixed(2);
    document.getElementById('ibCover').textContent = 'KES ' + cover.toFixed(2);
    document.getElementById('ibCopay').textContent = 'KES ' + copay.toFixed(2);
    document.getElementById('mAmountPaid').value   = curTotal;
}
</script>

<?php include 'includes/footer.php'; ?>
