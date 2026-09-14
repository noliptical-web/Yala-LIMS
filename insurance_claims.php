<?php
// insurance_claims.php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$role        = $_SESSION['role'] ?? '';
$my_user     = $_SESSION['full_name'] ?? 'Billing';
$success     = $error = "";

$isAdmin     = ($role === 'Admin');
$isReception = ($role === 'Receptionist');

if (!$isAdmin && !$isReception) {
    header("location: dashboard.php"); exit;
}

// ── PROCESS / RESOLVE CLAIM ──────────────────────────────────────────────────
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
            if ($claim_id > 0) {
                // Update existing claim
                $stmt = $conn->prepare("UPDATE insurance_claims SET status = ?, approved_amount = ?, copay_amount = ?, claim_ref = ?, notes = ?, resolved_at = NOW() WHERE claim_id = ?");
                $stmt->bind_param("sdddsi", $newStatus, $approved, $copay, $ref, $notes, $claim_id);
            } else {
                // Insert new claim
                $stmt = $conn->prepare("INSERT INTO insurance_claims (request_id, patient_id, insurer_name, insurance_number, claim_ref, billed_amount, approved_amount, copay_amount, status, notes, resolved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("iisssddsss", $req_id, $pat_id, $insurer, $ins_num, $ref, $billed, $approved, $copay, $newStatus, $notes);
            }

            if (!$stmt->execute()) {
                throw new Exception("Claims table update failed: " . $stmt->error);
            }
            $stmt->close();

            // Sync with payments table
            $pay_method = ($newStatus === 'Rejected') ? 'Pending' : 'insurance';
            $actual_paid = ($newStatus === 'Rejected') ? 0 : $approved;
            
            $stmt_p = $conn->prepare("UPDATE payments SET amount_paid = ?, patient_copay = ?, copay_amount = ?, insurer_amount = ?, claim_ref = ?, reference_no = ?, payment_method = ?, received_by = ?, payment_date = NOW() WHERE request_id = ?");
            $stmt_p->bind_param("ddddssssi", $actual_paid, $copay, $copay, $billed, $ref, $ref, $pay_method, $my_user, $req_id);
            
            if (!$stmt_p->execute()) {
                throw new Exception("Payments table sync failed: " . $stmt_p->error);
            }
            $stmt_p->close();

            // Update lab request payment status
            $req_pay_status = ($newStatus === 'Approved' || $newStatus === 'Partial') ? 'Paid' : 'Unpaid';
            $conn->query("UPDATE lab_requests SET payment_status = '$req_pay_status' WHERE request_id = $req_id");

            audit_log($conn, 'process_insurance_claim', 'insurance_claims', $req_id, "Claim reference $ref resolved as $newStatus");
            $conn->commit();
            $success = "Insurance claim status updated and synchronized successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error saving claim details: " . $e->getMessage();
        }
    }
}

// ── FETCH INSURANCE CLAIMS ────────────────────────────────────────────────────
$filter_status = $_GET['filter_status'] ?? 'All';
$sql = "SELECT 
            pay.payment_id,
            pay.request_id,
            pay.insurer_amount AS billed_amount,
            pay.insurance_provider AS insurer_name,
            pay.insurance_member_no AS insurance_number,
            pay.payment_date,
            pt.patient_id,
            pt.full_name AS patient_name,
            pt.opd_number,
            c.claim_id,
            c.claim_ref,
            COALESCE(c.approved_amount, 0) AS approved_amount,
            COALESCE(c.copay_amount, 0) AS copay_amount,
            COALESCE(c.status, 'Submitted') AS claim_status,
            c.notes AS claim_notes,
            c.resolved_at
        FROM payments pay
        JOIN lab_requests r ON pay.request_id = r.request_id
        JOIN patients pt ON r.patient_id = pt.patient_id
        LEFT JOIN insurance_claims c ON pay.request_id = c.request_id
        WHERE pay.payment_type = 'Insurance'";

if ($filter_status !== 'All') {
    if ($filter_status === 'Submitted') {
        $sql .= " AND (c.status = 'Submitted' OR c.status IS NULL)";
    } else {
        $sql .= " AND c.status = '$filter_status'";
    }
}
$sql .= " ORDER BY pay.payment_date DESC LIMIT 100";

$res = $conn->query($sql);
$claims = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$page_title = "Insurance Claims Manager";
include 'includes/header.php';
$csrf = csrf_token();
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap');
.icw{width:100%;max-width:1150px;margin:0 auto 48px;font-family:'DM Sans',sans-serif}
.ich{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.ich h1{font-family:'DM Serif Display',serif;font-size:1.85rem;color:#0f172a;margin:0 0 3px;letter-spacing:-.4px}
.ich p{font-size:.8rem;color:#94a3b8;margin:0}
.crumb{display:flex;align-items:center;gap:5px;font-size:.72rem;color:#94a3b8;margin-bottom:18px}
.crumb a{color:#94a3b8;text-decoration:none}.crumb a:hover{color:#1d4ed8}
.panel{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
.phead{padding:13px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.ptitle{font-size:.62rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8}
.claim-badge{font-size:.68rem;font-weight:700;padding:3px 9px;border-radius:12px;text-transform:uppercase}
.cl-Submitted{background:#dbeafe;color:#1d4ed8}
.cl-Approved{background:#dcfce7;color:#166534}
.cl-Rejected{background:#fee2e2;color:#991b1b}
.cl-Partial{background:#fef3c7;color:#92400e}
.f{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.f label{font-size:.73rem;font-weight:600;color:#374151}
.f input,.f select,.f textarea{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:.83rem;font-family:'DM Sans',sans-serif;color:#0f172a;background:#fafafa;outline:none;box-sizing:border-box}
.f input:focus,.f select:focus,.f textarea:focus{border-color:#3b82f6;background:#fff}
</style>

<div class="icw">
    <div class="crumb"><a href="dashboard.php"><i class="fa-solid fa-house-chimney"></i></a><span>&rsaquo;</span><span>Insurance Claims</span></div>

    <div class="ich">
        <div>
            <h1>Insurance Claims Manager</h1>
            <p>SHA / NHIF and private insurance pre-authorizations and invoices</p>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" style="border-radius:10px">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" style="border-radius:10px">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="panel shadow-sm">
        <div class="phead">
            <span class="ptitle">Claims Log</span>
            <form method="get" class="d-flex align-items-center gap-2 m-0">
                <select name="filter_status" class="form-select form-select-sm py-1 px-2" style="width:135px;font-size:.78rem;" onchange="this.form.submit()">
                    <option value="All" <?= $filter_status=='All'?'selected':'' ?>>All claims</option>
                    <option value="Submitted" <?= $filter_status=='Submitted'?'selected':'' ?>>Submitted</option>
                    <option value="Approved" <?= $filter_status=='Approved'?'selected':'' ?>>Approved</option>
                    <option value="Partial" <?= $filter_status=='Partial'?'selected':'' ?>>Partial</option>
                    <option value="Rejected" <?= $filter_status=='Rejected'?'selected':'' ?>>Rejected</option>
                </select>
            </form>
        </div>

        <?php if (empty($claims)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa-solid fa-folder-open fa-3x mb-3 opacity-25"></i>
            <p class="small mb-0">No insurance claims match the selected filter.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Request ID</th>
                        <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Patient</th>
                        <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Insurer / Member No.</th>
                        <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Billed (KES)</th>
                        <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Approved (KES)</th>
                        <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Copay (KES)</th>
                        <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Status</th>
                        <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($claims as $c): ?>
                    <tr>
                        <td class="fw-bold">#<?= $c['request_id'] ?></td>
                        <td>
                            <div class="fw-semibold" style="font-size:.82rem"><?= htmlspecialchars($c['patient_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($c['opd_number']) ?></small>
                        </td>
                        <td>
                            <div class="fw-bold text-dark" style="font-size:.8rem"><?= htmlspecialchars($c['insurer_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($c['insurance_number'] ?: '—') ?></small>
                        </td>
                        <td class="fw-bold text-dark"><?= number_format($c['billed_amount'], 2) ?></td>
                        <td class="fw-bold text-success"><?= number_format($c['approved_amount'], 2) ?></td>
                        <td class="fw-bold text-warning"><?= number_format($c['copay_amount'], 2) ?></td>
                        <td>
                            <span class="claim-badge cl-<?= $c['claim_status'] ?>"><?= $c['claim_status'] ?></span>
                            <?php if ($c['claim_ref']): ?>
                            <div class="small text-muted font-monospace mt-1" style="font-size:.68rem">Ref: <?= htmlspecialchars($c['claim_ref']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-outline-primary btn-sm fw-semibold" style="font-size:.76rem" 
                                    data-bs-toggle="modal" data-bs-target="#claimModal" 
                                    onclick="loadClaim(<?= htmlspecialchars(json_encode($c)) ?>)">
                                <i class="fa-solid fa-sliders me-1"></i> Process
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- PROCESS CLAIM MODAL -->
<div class="modal fade" id="claimModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="insurance_claims.php" class="modal-content" style="border-radius:15px;border:none">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="process_claim" value="1">
            <input type="hidden" name="claim_id" id="c_id">
            <input type="hidden" name="request_id" id="c_rid">
            <input type="hidden" name="patient_id" id="c_pid">
            <input type="hidden" name="insurer_name" id="c_ins">
            <input type="hidden" name="insurance_number" id="c_num">

            <div class="modal-header border-bottom-0 pt-4 px-4">
                <h5 class="fw-bold modal-title" id="modalTitle">Process Insurance Claim</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body px-4 pb-4">
                <div class="alert alert-light border small py-2 mb-3">
                    Billed Invoice Amount: <strong class="text-danger" id="lblBilled">KES 0.00</strong>
                </div>

                <div class="f">
                    <label>Claim Status</label>
                    <select name="status" id="c_status" required onchange="adjClaim(this.value)">
                        <option value="Submitted">Submitted</option>
                        <option value="Approved">Approved</option>
                        <option value="Partial">Partial</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                </div>

                <div class="f">
                    <label>Claim Reference Number</label>
                    <input type="text" name="claim_ref" id="c_ref" placeholder="e.g. AUTH-2912X" required>
                </div>

                <div class="row g-2">
                    <div class="col-6">
                        <div class="f">
                            <label>Approved Amount (KES)</label>
                            <input type="number" step="0.01" name="approved_amount" id="c_app" required>
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
                    <label>Internal Notes</label>
                    <textarea name="notes" id="c_notes" rows="2" placeholder="Audit remarks..."></textarea>
                </div>
            </div>

            <div class="modal-footer border-top-0 pt-0 px-4 pb-4">
                <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold">Update Claim</button>
            </div>
        </form>
    </div>
</div>

<script>
let curBilled = 0;

function loadClaim(c) {
    document.getElementById('c_id').value = c.claim_id ? c.claim_id : 0;
    document.getElementById('c_rid').value = c.request_id;
    document.getElementById('c_pid').value = c.patient_id;
    document.getElementById('c_ins').value = c.insurer_name;
    document.getElementById('c_num').value = c.insurance_number;
    
    curBilled = parseFloat(c.billed_amount);
    document.getElementById('lblBilled').textContent = 'KES ' + curBilled.toLocaleString(undefined, {minimumFractionDigits: 2});
    
    document.getElementById('c_status').value = c.claim_status;
    document.getElementById('c_ref').value = c.claim_ref ? c.claim_ref : '';
    document.getElementById('c_notes').value = c.claim_notes ? c.claim_notes : '';
    
    if (c.claim_id) {
        document.getElementById('c_app').value = parseFloat(c.approved_amount);
        document.getElementById('c_copay').value = parseFloat(c.copay_amount);
    } else {
        // Defaults
        document.getElementById('c_app').value = curBilled;
        document.getElementById('c_copay').value = 0;
    }
}

function adjClaim(status) {
    if (status === 'Approved') {
        document.getElementById('c_app').value = curBilled;
        document.getElementById('c_copay').value = 0;
    } else if (status === 'Rejected') {
        document.getElementById('c_app').value = 0;
        document.getElementById('c_copay').value = 0;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
