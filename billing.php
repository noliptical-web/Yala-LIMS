<?php
// billing.php
session_start();
require_once 'includes/db_connect.php';

// Access Control
if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] != 'Receptionist' && $_SESSION['role'] != 'Admin')) {
    header("location: dashboard.php");
    exit;
}

$success = "";
$error = "";

// A. HANDLE PAYMENT VERIFICATION
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_payment'])) {
    $req_id     = intval($_POST['request_id']);
    $mpesa_code = strtoupper(trim($_POST['mpesa_code']));
    $amount     = floatval($_POST['amount']);

    if (strlen($mpesa_code) >= 10) {
        $sql  = "INSERT INTO payments (request_id, amount_paid, payment_method, reference_no) VALUES (?, ?, 'eCitizen-222222', ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ids", $req_id, $amount, $mpesa_code);

        if ($stmt->execute()) {
            $upd = $conn->prepare("UPDATE lab_requests SET payment_status = 'Paid' WHERE request_id = ?");
            $upd->bind_param("i", $req_id);
            $upd->execute();
            $upd->close();
            $success = "Payment Verified! <a href='print_receipt.php?id=$req_id' target='_blank' class='btn btn-sm btn-dark ms-2'>Print Receipt</a>";
        } else {
            $error = "Database error recording payment.";
        }
        $stmt->close();
    } else {
        $error = "Invalid M-Pesa Code format. Must be at least 10 characters.";
    }
}

// B. FETCH UNPAID BILLS
// COALESCE prevents NULL when test_results have no matching lab_tests rows
$sql_bills = "SELECT r.request_id, p.full_name, p.opd_number, r.request_date,
              COALESCE(
                  (SELECT SUM(lt.cost)
                   FROM test_results tr
                   JOIN lab_tests lt ON tr.test_id = lt.test_id
                   WHERE tr.request_id = r.request_id
                  ), 0
              ) as total_bill
              FROM lab_requests r
              JOIN patients p ON r.patient_id = p.patient_id
              WHERE r.payment_status = 'Unpaid'
              ORDER BY r.request_date ASC";

$bills = $conn->query($sql_bills);

// Fetch into array so we can check count cleanly
$bill_rows = [];
if ($bills) {
    while ($b = $bills->fetch_assoc()) {
        $bill_rows[] = $b;
    }
} else {
    $error = "Query error: " . $conn->error;
}

// --- PAGE CONFIGURATION ---
$page_title = "Government Billing - Yala LIMS";
include 'includes/header.php';
?>

<style>
    .ecitizen-header { background: #D32F2F; color: white; padding: 15px; border-radius: 15px 15px 0 0; }
    .paybill-box { background: #fff; border: 2px dashed #D32F2F; padding: 15px; border-radius: 10px; text-align: center; margin-bottom: 20px; }
    .instruction-step { font-size: 0.9rem; margin-bottom: 5px; text-align: left; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="fw-bold text-dark mb-0">
        <i class="fa-solid fa-cash-register me-2 text-danger"></i>Pending Payments
        <span class="badge bg-danger ms-2"><?php echo count($bill_rows); ?></span>
    </h4>
    <div class="d-flex align-items-center gap-2">
        <?php if(file_exists('ecitizen.png')): ?>
            <img src="ecitizen.png" height="28">
        <?php endif; ?>
        <span class="badge bg-dark">eCitizen Agent Mode</span>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success border-0 shadow-sm rounded-3"><?php echo $success; ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger border-0 shadow-sm rounded-3"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div class="card shadow border-0 rounded-4">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">Patient</th>
                    <th>Request Date</th>
                    <th>Bill Amount</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($bill_rows) > 0):
                    foreach ($bill_rows as $row):
                        $acc_no = "YALA-" . str_pad($row['request_id'], 4, '0', STR_PAD_LEFT);
                ?>
                <tr>
                    <td class="ps-4">
                        <span class="fw-bold d-block"><?php echo htmlspecialchars($row['full_name']); ?></span>
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($row['opd_number']); ?></span>
                        <small class="text-muted ms-1">Req #<?php echo $row['request_id']; ?></small>
                    </td>
                    <td class="text-muted small"><?php echo date('d M Y H:i', strtotime($row['request_date'])); ?></td>
                    <td>
                        <h5 class="text-danger mb-0 fw-bold">
                            KES <?php echo number_format($row['total_bill']); ?>
                        </h5>
                        <?php if ($row['total_bill'] == 0): ?>
                            <small class="text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i>No test costs set</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-4 fw-bold"
                                data-bs-toggle="modal" data-bs-target="#payModal<?php echo $row['request_id']; ?>">
                            <i class="fa-solid fa-money-bill-wave me-1"></i>Process Payment
                        </button>

                        <!-- PAYMENT MODAL -->
                        <div class="modal fade" id="payModal<?php echo $row['request_id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="ecitizen-header text-center">
                                        <h5 class="modal-title fw-bold">GOVERNMENT SERVICES PAYMENT</h5>
                                        <small>Ministry of Health - Yala Sub-County</small>
                                    </div>
                                    <form method="post">
                                        <div class="modal-body p-4">
                                            <input type="hidden" name="request_id" value="<?php echo $row['request_id']; ?>">
                                            <input type="hidden" name="amount" value="<?php echo $row['total_bill']; ?>">

                                            <div class="paybill-box">
                                                <h6 class="text-uppercase text-muted small fw-bold">Payment Instructions</h6>
                                                <h2 class="fw-bold my-2">Paybill: 222 222</h2>
                                                <h4 class="text-primary">Account: <?php echo $acc_no; ?></h4>
                                                <h3 class="mt-2 text-danger">KES <?php echo number_format($row['total_bill']); ?></h3>
                                            </div>

                                            <div class="alert alert-light border small">
                                                <div class="instruction-step">1. Go to M-PESA menu</div>
                                                <div class="instruction-step">2. Select <strong>Lipa na M-PESA &rarr; Paybill</strong></div>
                                                <div class="instruction-step">3. Business No: <strong>222222</strong></div>
                                                <div class="instruction-step">4. Account No: <strong><?php echo $acc_no; ?></strong></div>
                                                <div class="instruction-step">5. Amount: <strong>KES <?php echo number_format($row['total_bill']); ?></strong></div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label fw-bold">M-Pesa Transaction Code</label>
                                                <div class="input-group">
                                                    <input type="text"
                                                           id="code_<?php echo $row['request_id']; ?>"
                                                           name="mpesa_code"
                                                           class="form-control form-control-lg text-uppercase fw-bold"
                                                           placeholder="e.g. QBH52XXXXX"
                                                           required minlength="10" maxlength="10">
                                                    <button type="button" class="btn btn-outline-secondary"
                                                            onclick="generateCode(<?php echo $row['request_id']; ?>)">
                                                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                                                    </button>
                                                </div>
                                                <div class="form-text">Enter the M-Pesa confirmation code from the patient's SMS.</div>
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-light">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="verify_payment" class="btn btn-danger fw-bold px-4">
                                                <i class="fa-solid fa-circle-check me-1"></i>Verify & Record Payment
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="4" class="text-center py-5 text-muted">
                        <i class="fa-solid fa-circle-check fa-2x mb-2 d-block text-success opacity-50"></i>
                        No pending bills — all patients are cleared.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function generateCode(id) {
    const chars = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    let code = "S";
    for (let i = 0; i < 9; i++) {
        code += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('code_' + id).value = code;
}
</script>

<?php include 'includes/footer.php'; ?>