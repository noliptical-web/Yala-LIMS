<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

$allowed = ['Receptionist', 'Admin'];
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], $allowed)) {
    header("location: dashboard.php"); exit;
}

$myId    = intval($_SESSION['id'] ?? 0);
$success = $error = "";

// ── RECORD PAYMENT ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    csrf_verify();

    $paymentId   = intval($_POST['payment_id']   ?? 0);
    $requestId   = intval($_POST['request_id']   ?? 0);
    $method      = trim($_POST['payment_method'] ?? '');
    $amountPaid  = floatval($_POST['amount_paid'] ?? 0);
    $mpesaCode   = trim($_POST['mpesa_code']     ?? '');
    $cashRef     = trim($_POST['cash_ref']       ?? '');
    $insurerName = trim($_POST['insurer_name']   ?? '');
    $memberNo    = trim($_POST['insurance_member_no'] ?? '');
    $claimRef    = trim($_POST['claim_ref']      ?? '');
    $copay       = floatval($_POST['copay_amount'] ?? 0);
    $waiverReason  = trim($_POST['waiver_reason']  ?? '');
    $waiverOfficer = trim($_POST['waiver_officer'] ?? '');
    $receivedBy  = $_SESSION['full_name'] ?? 'Cashier';

    if (!$paymentId || !$method) {
        $error = "Missing payment ID or method.";
    } else {
        // Map method to payment_type enum (Cash / Insurance / Split)
        $pay_type = 'Cash';
        if ($method === 'insurance') $pay_type = 'Insurance';
        if ($method === 'waiver')    $pay_type = 'Cash'; // waiver clears as Cash with 0 amount

        $ref = '';
        if ($method === 'mpesa')    $ref = $mpesaCode;
        if ($method === 'cash')     $ref = $cashRef;
        if ($method === 'insurance') $ref = $claimRef;

        $actual_amount = ($method === 'waiver') ? 0 : $amountPaid;

        $sql = "UPDATE payments SET
                    amount_paid       = ?,
                    payment_method    = ?,
                    payment_type      = ?,
                    reference_no      = ?,
                    insurer_name      = ?,
                    insurance_member_no = ?,
                    insurance_claim_no  = ?,
                    patient_copay     = ?,
                    copay_amount      = ?,
                    waiver_reason     = ?,
                    received_by       = ?,
                    payment_date      = CURRENT_TIMESTAMP
                WHERE payment_id = ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $error = "Query error: " . $conn->error;
        } else {
            $stmt->bind_param(
                "dssssssddssi",
                $actual_amount, $method, $pay_type, $ref,
                $insurerName, $memberNo, $claimRef,
                $copay, $copay,
                $waiverReason, $receivedBy,
                $paymentId
            );
            if ($stmt->execute()) {
                // Mark lab_request as paid so lab can proceed
                if ($requestId > 0) {
                    $conn->query("UPDATE lab_requests SET payment_status='Paid' WHERE request_id=$requestId");
                }
                audit_log($conn, 'record_payment', 'payments', $paymentId, "Req #$requestId via $method KES $actual_amount");
                $success = "Payment recorded successfully.";
            } else {
                $error = "Save failed: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// ── FETCH PENDING BILLS ───────────────────────────────────────────────────────
// Your payments table has NO payment_status column.
// "Pending" bills = payment_method = 'Pending' (set when request is created)
// OR amount_paid = 0 AND payment_date is recent (auto-inserted timestamp)
// We identify unpaid rows by payment_method = 'Pending'
// which is what request_test.php now sets on insert.
//
// For existing old rows that are missing (requests 25,26,27,28),
// they show as MISSING in debug — run fix_missing_payments.php first.

$sql = "
    SELECT
        pay.payment_id,
        pay.request_id,
        pay.insurer_amount   AS total_cost,
        pay.payment_method,
        pay.payment_type,
        pay.insurance_provider,
        pay.insurance_member_no,
        pay.payment_date,
        pt.patient_id,
        pt.full_name,
        pt.age,
        pt.gender,
        r.request_date,
        r.requested_by,
        r.status             AS req_status
    FROM payments pay
    JOIN lab_requests r  ON pay.request_id  = r.request_id
    JOIN patients     pt ON r.patient_id    = pt.patient_id
    WHERE pay.payment_method = 'Pending'
    ORDER BY r.request_date DESC
";

$res   = $conn->query($sql);
$bills = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// For each bill fetch its test names
foreach ($bills as &$b) {
    $rid = intval($b['request_id']);
    $tr  = $conn->query("SELECT t.test_name, t.cost
        FROM test_results tr
        JOIN lab_tests t ON tr.test_id = t.test_id
        WHERE tr.request_id = $rid");
    $b['tests']      = [];
    $b['test_names'] = [];
    if ($tr) while ($row = $tr->fetch_assoc()) {
        $b['tests'][]      = $row;
        $b['test_names'][] = $row['test_name'];
    }
    $b['tests_str'] = implode(', ', $b['test_names']);
}
unset($b);

// ── Insurance providers ───────────────────────────────────────────────────────
$providers = [];
$pr = $conn->query("SELECT name FROM insurance_providers WHERE active=1 ORDER BY name");
if ($pr) while ($row = $pr->fetch_assoc()) $providers[] = $row['name'];
if (empty($providers)) {
    $providers = ['NHIF / SHA','AAR Insurance','Jubilee Insurance','Britam',
                  'Madison Insurance','CIC Insurance','Sanlam','Equity Afia','Other'];
}

$csrf = csrf_token();
$page_title = 'Cashier — Yala LIMS';
include 'includes/header.php';
?>

<style>
.bill-amount { font-weight: 700; color: #dc2626; font-size: 1rem; }
.ins-badge   { background: #ede9fe; color: #6b21a8; font-size: .72rem; font-weight: 600;
               padding: 2px 8px; border-radius: 12px; }
.pay-tabs    { display: flex; border: 1.5px solid #dee2e6; border-radius: 8px; overflow: hidden; margin-bottom: 18px; }
.pay-tab     { flex: 1; padding: 9px 4px; text-align: center; font-size: .82rem; font-weight: 600;
               cursor: pointer; border: none; background: #f8f9fa; color: #6c757d; transition: all .15s; }
.pay-tab.active { background: #0d6efd; color: #fff; }
.pay-panel   { display: none; }
.pay-panel.active { display: block; }
.patient-banner { background: linear-gradient(135deg, #0f172a, #1d4ed8);
    color: #fff; border-radius: 10px; padding: 14px 18px; margin-bottom: 18px; }
.pb-name   { font-weight: 700; font-size: 1rem; }
.pb-meta   { font-size: .82rem; opacity: .85; margin-top: 2px; }
.pb-amount { font-size: 1.4rem; font-weight: 700; margin-top: 6px; }
.pb-tests  { font-size: .78rem; opacity: .75; margin-top: 4px; }
.ins-notice { background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px;
    padding: 10px 14px; margin-bottom: 14px; font-size: .83rem; color: #166534; }
.ins-breakdown { background: #faf5ff; border: 1px solid #e9d5ff;
    border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; }
.ins-row { display: flex; justify-content: space-between; font-size: .85rem;
    color: #4c1d95; padding: 4px 0; }
.ins-row.total { font-weight: 700; border-top: 1px solid #d8b4fe;
    margin-top: 6px; padding-top: 6px; }
.paybill-info { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;
    padding: 14px; margin-bottom: 14px; font-size: .85rem; color: #166534; }
.paybill-info .code { font-size: 1.3rem; font-weight: 700; letter-spacing: .05em; }
</style>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="fa-solid fa-cash-register me-2 text-primary"></i>Cashier</h4>
        <small class="text-muted"><?= count($bills) ?> pending bill<?= count($bills) != 1 ? 's' : '' ?></small>
    </div>
</div>

<div class="glass-card p-0">
    <?php if (empty($bills)): ?>
    <div class="text-center py-5 text-muted">
        <i class="fa-solid fa-circle-check fa-3x text-success mb-3"></i>
        <h5 class="text-success">All bills settled</h5>
        <p class="small">No pending payments at this time.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Patient</th>
                    <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Tests</th>
                    <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Date</th>
                    <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Status</th>
                    <th class="text-end" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Amount (KES)</th>
                    <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bills as $b):
                $isIns = !empty($b['insurance_provider']) || $b['payment_type'] === 'Insurance';
            ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($b['full_name']) ?></div>
                        <small class="text-muted">
                            <?= htmlspecialchars($b['gender'] ?? '') ?>
                            <?php if (!empty($b['age'])): ?>&bull; <?= $b['age'] ?>y<?php endif; ?>
                        </small>
                        <?php if ($isIns): ?>
                        <br><span class="ins-badge mt-1 d-inline-block">
                            <i class="fa-solid fa-hospital me-1"></i><?= htmlspecialchars($b['insurance_provider'] ?: 'Insurance') ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td><small class="text-muted"><?= htmlspecialchars($b['tests_str'] ?: '—') ?></small></td>
                    <td><small><?= date('d M Y', strtotime($b['request_date'])) ?></small></td>
                    <td>
                        <?php if ($isIns): ?>
                            <span class="badge" style="background:#ede9fe;color:#6b21a8">
                                <i class="fa-solid fa-hospital me-1"></i>Insurance
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger">Unpaid</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end bill-amount"><?= number_format($b['total_cost'], 2) ?></td>
                    <td>
                        <button class="btn btn-primary btn-sm"
                            onclick="openPay(<?= htmlspecialchars(json_encode($b)) ?>)">
                            <i class="fa-solid fa-credit-card me-1"></i>Pay
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── PAYMENT MODAL ──────────────────────────────────────────────────────── -->
<div class="modal fade" id="payModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">
    <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold">
            <i class="fa-solid fa-cash-register me-2"></i>Record Payment
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
    <div class="modal-body pt-2">
        <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
        <input type="hidden" name="payment_id"  id="mPayId">
        <input type="hidden" name="request_id"  id="mReqId">
        <input type="hidden" name="amount_paid" id="mAmountPaid">

        <div class="patient-banner">
            <div class="pb-name"   id="mName"></div>
            <div class="pb-meta"   id="mMeta"></div>
            <div class="pb-amount" id="mAmount"></div>
            <div class="pb-tests"  id="mTests"></div>
        </div>

        <div class="ins-notice d-none" id="insNotice">
            <i class="fa-solid fa-hospital me-2"></i>
            <strong id="insNoticeName"></strong> — insurance patient.
        </div>

        <div class="pay-tabs">
            <button type="button" class="pay-tab active" onclick="switchTab('mpesa',this)">
                <i class="fa-solid fa-mobile-screen me-1"></i>M-Pesa
            </button>
            <button type="button" class="pay-tab" onclick="switchTab('cash',this)">
                <i class="fa-solid fa-money-bill me-1"></i>Cash
            </button>
            <button type="button" class="pay-tab" onclick="switchTab('insurance',this)">
                <i class="fa-solid fa-hospital me-1"></i>Insurance
            </button>
            <button type="button" class="pay-tab" onclick="switchTab('waiver',this)">
                <i class="fa-solid fa-file-circle-xmark me-1"></i>Waiver
            </button>
        </div>
        <input type="hidden" name="payment_method" id="methodField" value="mpesa">

        <!-- M-PESA -->
        <div class="pay-panel active" id="panel-mpesa">
            <div class="paybill-info">
                <p class="fw-semibold mb-1">Instruct patient to pay via M-Pesa:</p>
                <div class="code">Paybill: 222222</div>
                <ol class="mt-2 mb-0 small ps-3">
                    <li>M-Pesa &rarr; Lipa na M-Pesa &rarr; Pay Bill</li>
                    <li>Business No: <strong>222222</strong></li>
                    <li>Account No: patient OPD number</li>
                    <li>Enter amount, PIN &amp; confirm</li>
                </ol>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">M-Pesa Confirmation Code</label>
                <input name="mpesa_code" id="mpesaCode" class="form-control"
                    placeholder="e.g. QGL7ABCDEF" style="font-family:monospace;letter-spacing:.05em">
            </div>
        </div>

        <!-- CASH -->
        <div class="pay-panel" id="panel-cash">
            <div class="mb-3">
                <label class="form-label small fw-semibold">Receipt / Reference No.</label>
                <input name="cash_ref" class="form-control" placeholder="e.g. CASH-001">
            </div>
        </div>

        <!-- INSURANCE -->
        <div class="pay-panel" id="panel-insurance">
            <div class="ins-breakdown" id="insBreakdown">
                <div class="ins-row"><span>Total Bill</span><span id="ibTotal"></span></div>
                <div class="ins-row"><span>Insurer Pays (est. 80%)</span><span id="ibCover"></span></div>
                <div class="ins-row total"><span>Patient Copay</span><span id="ibCopay"></span></div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Insurance Provider</label>
                <select name="insurer_name" id="insurerSelect" class="form-select">
                    <option value="">— Select insurer —</option>
                    <?php foreach ($providers as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Member / Card Number</label>
                <input name="insurance_member_no" id="memberNoField" class="form-control" placeholder="e.g. SHA-1234567890">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Pre-auth / Claim Reference</label>
                <input name="claim_ref" class="form-control" placeholder="Auth code (if available)">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Copay Collected from Patient (KES)</label>
                <input name="copay_amount" id="copayField" type="number" step="0.01" min="0" value="0" class="form-control">
            </div>
        </div>

        <!-- WAIVER -->
        <div class="pay-panel" id="panel-waiver">
            <div class="alert alert-warning small">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                Fee waivers require authorisation from a senior officer.
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Reason for Waiver</label>
                <textarea name="waiver_reason" class="form-control" rows="3" placeholder="State the reason clearly…"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Authorising Officer</label>
                <input name="waiver_officer" class="form-control" placeholder="Full name and designation">
            </div>
        </div>
    </div>
    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button name="record_payment" class="btn btn-success px-4">
            <i class="fa-solid fa-circle-check me-1"></i>Confirm Payment
        </button>
    </div>
    </form>
</div>
</div>
</div>

<script>
function openPay(b) {
    document.getElementById('mPayId').value      = b.payment_id;
    document.getElementById('mReqId').value      = b.request_id;
    document.getElementById('mAmountPaid').value = b.total_cost;
    document.getElementById('mName').textContent = b.full_name;
    document.getElementById('mMeta').textContent =
        (b.gender || '') + (b.age ? ' · ' + b.age + 'y' : '');
    document.getElementById('mAmount').textContent = 'KES ' + parseFloat(b.total_cost).toFixed(2);
    document.getElementById('mTests').textContent  = b.tests_str || '';
    document.getElementById('mpesaCode').value     = '';

    const isIns = b.payment_type === 'Insurance' || (b.insurance_provider && b.insurance_provider !== '');
    const notice = document.getElementById('insNotice');

    if (isIns) {
        notice.classList.remove('d-none');
        document.getElementById('insNoticeName').textContent = b.insurance_provider || 'Insurance';
        switchTab('insurance', document.querySelector('.pay-tab:nth-child(3)'));

        const sel = document.getElementById('insurerSelect');
        for (let o of sel.options) {
            if (o.value === b.insurance_provider) { sel.value = o.value; break; }
        }
        if (b.insurance_member_no) {
            document.getElementById('memberNoField').value = b.insurance_member_no;
        }
        const total = parseFloat(b.total_cost);
        const cover = Math.round(total * 0.80 * 100) / 100;
        const copay = Math.round((total - cover) * 100) / 100;
        document.getElementById('ibTotal').textContent  = 'KES ' + total.toFixed(2);
        document.getElementById('ibCover').textContent  = 'KES ' + cover.toFixed(2);
        document.getElementById('ibCopay').textContent  = 'KES ' + copay.toFixed(2);
        document.getElementById('copayField').value     = copay.toFixed(2);
        document.getElementById('mAmountPaid').value    = copay.toFixed(2);
    } else {
        notice.classList.add('d-none');
        switchTab('mpesa', document.querySelector('.pay-tab:nth-child(1)'));
    }

    new bootstrap.Modal(document.getElementById('payModal')).show();
}

function switchTab(method, btn) {
    document.querySelectorAll('.pay-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.pay-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('panel-' + method).classList.add('active');
    document.getElementById('methodField').value = method;
}
</script>

<?php include 'includes/footer.php'; ?>