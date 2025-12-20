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

// A. HANDLE MANUAL VERIFICATION (Simulating eCitizen Callback)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_payment'])) {
    $req_id = $_POST['request_id'];
    $mpesa_code = strtoupper(trim($_POST['mpesa_code']));
    $amount = $_POST['amount'];

    // In a real system, we would query the eCitizen API here.
    // For this project, we accept the code if it looks valid (10 chars)
    if (strlen($mpesa_code) >= 10) {
        // 1. Record Payment
        $sql = "INSERT INTO payments (request_id, amount_paid, payment_method, reference_no) VALUES (?, ?, 'eCitizen-222222', ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ids", $req_id, $amount, $mpesa_code);
        
        if ($stmt->execute()) {
            // 2. Mark Request as Paid
            $conn->query("UPDATE lab_requests SET payment_status = 'Paid' WHERE request_id = $req_id");
            $success = "Payment Verified! <a href='print_receipt.php?id=$req_id' target='_blank' class='btn btn-sm btn-dark'>Print Receipt</a>";
        }
    } else {
        $error = "Invalid M-Pesa Code format.";
    }
}

// B. FETCH UNPAID BILLS
$sql_bills = "SELECT r.request_id, p.full_name, p.opd_number, r.request_date, 
              (SELECT SUM(t.cost) FROM test_results tr JOIN lab_tests t ON tr.test_id = t.test_id WHERE tr.request_id = r.request_id) as total_bill
              FROM lab_requests r
              JOIN patients p ON r.patient_id = p.patient_id
              WHERE r.payment_status = 'Unpaid'
              ORDER BY r.request_date DESC";
$bills = $conn->query($sql_bills);

// --- PAGE CONFIGURATION ---
$page_title = "Government Billing - Yala LIMS";
include 'includes/header.php'; 
?>

<style>
    .ecitizen-header {
        background: #D32F2F; /* eCitizen Red */
        color: white;
        padding: 15px;
        border-radius: 15px 15px 0 0;
    }
    .paybill-box {
        background: #fff;
        border: 2px dashed #D32F2F;
        padding: 15px;
        border-radius: 10px;
        text-align: center;
        margin-bottom: 20px;
    }
    .instruction-step { font-size: 0.9rem; margin-bottom: 5px; text-align: left; }
</style>

<div class="d-flex align-items-center justify-content-end mb-3 me-3">
    <?php if(file_exists('ecitizen.png')): ?> 
        <img src="ecitizen.png" height="30" class="me-2"> 
    <?php endif; ?>
    <span class="badge bg-dark">eCitizen Agent Mode</span>
</div>

<div class="row justify-content-center">
    <div class="col-md-10">
        <div class="card shadow border-0 rounded-4">
            <div class="card-header bg-white border-bottom p-4">
                <h4 class="mb-0 text-dark">Pending Government Payments</h4>
            </div>
            <div class="card-body p-4">
                <?php if ($success): ?> <div class="alert alert-success"><?php echo $success; ?></div> <?php endif; ?>
                <?php if ($error): ?> <div class="alert alert-danger"><?php echo $error; ?></div> <?php endif; ?>

                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Patient Details</th>
                            <th>Bill Amount</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($bills->num_rows > 0): ?>
                            <?php while($row = $bills->fetch_assoc()): ?>
                            <?php $acc_no = "YALA-" . str_pad($row['request_id'], 4, '0', STR_PAD_LEFT); ?>
                            
                            <tr>
                                <td>
                                    <span class="fw-bold"><?php echo $row['full_name']; ?></span><br>
                                    <span class="badge bg-secondary"><?php echo $row['opd_number']; ?></span>
                                </td>
                                <td>
                                    <h5 class="text-danger mb-0">KES <?php echo number_format($row['total_bill']); ?></h5>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-4 fw-bold" 
                                            data-bs-toggle="modal" data-bs-target="#payModal<?php echo $row['request_id']; ?>">
                                        Process Payment
                                    </button>

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
                                                            <h3 class="mt-3">Amount: KES <?php echo number_format($row['total_bill']); ?></h3>
                                                        </div>

                                                        <div class="alert alert-light border">
                                                            <div class="instruction-step">1. Go to M-PESA menu</div>
                                                            <div class="instruction-step">2. Select Lipa na M-PESA -> Paybill</div>
                                                            <div class="instruction-step">3. Enter Business No: <strong>222222</strong></div>
                                                            <div class="instruction-step">4. Enter Account No: <strong><?php echo $acc_no; ?></strong></div>
                                                            <div class="instruction-step">5. Enter Amount: <strong><?php echo $row['total_bill']; ?></strong></div>
                                                        </div>

                                                        <div class="mb-3 mt-4">
                                                            <label class="form-label fw-bold">Enter M-Pesa Transaction Code</label>
                                                            <div class="input-group">
                                                                <input type="text" id="code_<?php echo $row['request_id']; ?>" name="mpesa_code" 
                                                                       class="form-control form-control-lg text-uppercase" 
                                                                       placeholder="e.g. QBH5..." required minlength="10" maxlength="10">
                                                                       
                                                                <button type="button" class="btn btn-outline-secondary" onclick="generateCode(<?php echo $row['request_id']; ?>)">
                                                                    <i class="fa-solid fa-magic"></i> Auto
                                                                </button>
                                                            </div>
                                                            <div class="form-text">Ask the patient for the message code (e.g., QBH52...).</div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-light">
                                                        <button type="submit" name="verify_payment" class="btn btn-danger w-100 fw-bold">
                                                            VERIFY PAYMENT
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="text-center p-5 text-muted">No pending bills.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
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