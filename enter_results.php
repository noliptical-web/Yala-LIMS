<?php
// enter_results.php
session_start();
require_once 'includes/db_connect.php';

// --- 1. ACCESS CONTROL ---
if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] != 'LabTech' && $_SESSION['role'] != 'Admin')) {
    header("location: dashboard.php");
    exit;
}

$success = "";
$error = "";

// --- 2. LOGIC: HANDLE RESULT SUBMISSION ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_results'])) {
    
    $req_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
    
    // Check if we actually have results to process
    if ($req_id > 0 && isset($_POST['results']) && is_array($_POST['results'])) {
        
        // Start Transaction
        $conn->begin_transaction();
        
        try {
            // A. Update Individual Test Results
            $stmt = $conn->prepare("UPDATE test_results SET result_value = ?, technician_remarks = ? WHERE result_id = ?");
            
            foreach ($_POST['results'] as $result_id => $value) {
                // Sanitize input
                $val = trim($value); 
                $comment = isset($_POST['comments'][$result_id]) ? trim($_POST['comments'][$result_id]) : "";
                $rid = intval($result_id);
                
                $stmt->bind_param("ssi", $val, $comment, $rid);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update result ID: $rid");
                }
            }
            $stmt->close();
            
            // B. Mark Request as Completed
            $stmt_update = $conn->prepare("UPDATE lab_requests SET status = 'Completed' WHERE request_id = ?");
            $stmt_update->bind_param("i", $req_id);
            if (!$stmt_update->execute()) {
                throw new Exception("Failed to update request status.");
            }
            $stmt_update->close();

            // C. Send Notification to Doctor
            // First, fetch patient name safely for the notification
            $stmt_pat = $conn->prepare("SELECT p.full_name FROM lab_requests r JOIN patients p ON r.patient_id = p.patient_id WHERE r.request_id = ?");
            $stmt_pat->bind_param("i", $req_id);
            $stmt_pat->execute();
            $pat_res = $stmt_pat->get_result();
            $pat_name = ($pat_res->num_rows > 0) ? $pat_res->fetch_assoc()['full_name'] : "Unknown Patient";
            $stmt_pat->close();

            $notif_msg = "Results Ready: Patient $pat_name (Req #$req_id)";
            $notif_link = "/YALA_LIMS/print_report.php?id=$req_id"; 
            
            $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('Doctor', '$notif_msg', '$notif_link', 0, NOW())");

            // Commit Transaction
            $conn->commit();
            $success = "Results saved successfully for Request #$req_id";
            
            // Optional: Redirect to clear post data or stay on page
            // header("Location: enter_results.php"); 
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "System Error: " . $e->getMessage();
        }
        
    } else {
        $error = "Error: No result data found to save.";
    }
}

// --- 3. LOGIC: FETCH PENDING QUEUE ---
$queue_sql = "SELECT r.request_id, p.full_name, r.request_date, r.payment_status 
              FROM lab_requests r 
              JOIN patients p ON r.patient_id = p.patient_id 
              WHERE r.status = 'Pending' 
              ORDER BY r.request_date DESC";
$queue_result = $conn->query($queue_sql);

// --- 4. LOGIC: FETCH SELECTED REQUEST DATA ---
$selected_request = null;
$request_tests = [];

if (isset($_GET['manage_id'])) {
    $manage_id = intval($_GET['manage_id']); // Security: Force integer
    
    // Get Patient Info (Prepared Statement)
    $stmt_p = $conn->prepare("SELECT r.request_id, p.full_name, p.age, p.gender, r.payment_status 
                              FROM lab_requests r 
                              JOIN patients p ON r.patient_id = p.patient_id 
                              WHERE r.request_id = ?");
    $stmt_p->bind_param("i", $manage_id);
    $stmt_p->execute();
    $res_p = $stmt_p->get_result();
    $selected_request = $res_p->fetch_assoc();
    $stmt_p->close();
    
    // Get Tests (Prepared Statement)
    if ($selected_request) {
        $stmt_t = $conn->prepare("SELECT tr.result_id, t.test_name, t.units, t.normal_range, tr.result_value, tr.technician_remarks 
                                  FROM test_results tr 
                                  JOIN lab_tests t ON tr.test_id = t.test_id 
                                  WHERE tr.request_id = ?");
        $stmt_t->bind_param("i", $manage_id);
        $stmt_t->execute();
        $request_tests = $stmt_t->get_result();
        $stmt_t->close();
    }
}

// --- PAGE CONFIGURATION ---
$page_title = "Lab Workbench - Yala LIMS";
include 'includes/header.php'; 
?>

<style>
    .glass-card {
        background: rgba(255, 255, 255, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.07);
        border-radius: 12px;
    }
    .list-group-item { background: transparent; border-color: rgba(0,0,0,0.08); transition: 0.2s; }
    .list-group-item:hover { background: rgba(25, 135, 84, 0.08); transform: translateX(5px); }
    .list-group-item.active-item { background: rgba(25, 135, 84, 0.15); border-left: 4px solid #198754; }
</style>

<div class="row">
    
    <div class="col-md-4 mb-4">
        <div class="card glass-card h-100">
            <div class="card-header bg-transparent border-bottom p-3">
                <h5 class="text-success mb-0"><i class="fa-solid fa-list-ul me-2"></i> Pending Queue</h5>
            </div>
            <div class="list-group list-group-flush p-2" style="max-height: 70vh; overflow-y: auto;">
                <?php if ($queue_result && $queue_result->num_rows > 0): ?>
                    <?php while($row = $queue_result->fetch_assoc()): 
                        $isActive = (isset($_GET['manage_id']) && $_GET['manage_id'] == $row['request_id']) ? 'active-item' : '';
                    ?>
                        <a href="?manage_id=<?php echo $row['request_id']; ?>" class="list-group-item list-group-item-action rounded mb-2 border-0 <?php echo $isActive; ?>">
                            <div class="d-flex w-100 justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1 fw-bold text-dark"><?php echo htmlspecialchars($row['full_name']); ?></h6>
                                    <small class="text-muted"><i class="fa-regular fa-clock"></i> <?php echo date('H:i', strtotime($row['request_date'])); ?></small>
                                </div>
                                <?php if($row['payment_status'] == 'Paid'): ?>
                                    <span class="badge bg-success rounded-pill"><i class="fa-solid fa-check"></i> PAID</span>
                                <?php else: ?>
                                    <span class="badge bg-danger rounded-pill"><i class="fa-solid fa-xmark"></i> UNPAID</span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center p-5 text-muted">
                        <i class="fa-solid fa-mug-hot fa-2x mb-3 opacity-25"></i><br>
                        All caught up!<br>No pending requests.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-8 mb-4">
        
        <?php if ($success): ?> 
            <div class="alert alert-success shadow-sm border-0 rounded-3 mb-3 d-flex align-items-center">
                <i class="fa-solid fa-check-circle fa-xl me-2"></i> <?php echo $success; ?>
            </div> 
        <?php endif; ?>
        
        <?php if ($error): ?> 
            <div class="alert alert-danger shadow-sm border-0 rounded-3 mb-3 d-flex align-items-center">
                <i class="fa-solid fa-triangle-exclamation fa-xl me-2"></i> <?php echo $error; ?>
            </div> 
        <?php endif; ?>

        <?php if ($selected_request): ?>
        
            <?php if ($selected_request['payment_status'] == 'Unpaid'): ?>
                <div class="alert alert-warning shadow-sm border-0 rounded-3 mb-3">
                    <div class="d-flex">
                        <i class="fa-solid fa-lock fa-2x me-3"></i>
                        <div>
                            <h5 class="fw-bold mb-1">Restricted Access</h5>
                            <p class="mb-0">This patient has not cleared their bill. Result entry is disabled until payment is confirmed.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card glass-card shadow-lg">
                <div class="card-header bg-success text-white p-3 border-0" style="border-radius: 12px 12px 0 0;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="bg-white text-success rounded-circle p-2 me-3 d-flex justify-content-center align-items-center" style="width:40px; height:40px;">
                                <i class="fa-solid fa-microscope"></i>
                            </div>
                            <div>
                                <h5 class="mb-0">Enter Results</h5>
                                <small class="text-white-50">Patient: <?php echo htmlspecialchars($selected_request['full_name']); ?></small>
                            </div>
                        </div>
                        <span class="badge bg-white text-success">Req #<?php echo $selected_request['request_id']; ?></span>
                    </div>
                </div>
                
                <div class="card-body p-4">
                    <form method="post">
                        <input type="hidden" name="request_id" value="<?php echo $selected_request['request_id']; ?>">
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th width="30%">Test Name</th>
                                        <th width="30%">Result Value</th>
                                        <th width="15%">Ref. Range</th>
                                        <th width="25%">Tech Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if ($request_tests && $request_tests->num_rows > 0): 
                                        while($test = $request_tests->fetch_assoc()): 
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold text-dark"><?php echo htmlspecialchars($test['test_name']); ?></span>
                                            <?php if($test['units']): ?>
                                                <small class="text-muted d-block">(<?php echo htmlspecialchars($test['units']); ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <input type="text" 
                                                   name="results[<?php echo $test['result_id']; ?>]" 
                                                   class="form-control fw-bold" 
                                                   value="<?php echo htmlspecialchars($test['result_value'] !== 'Pending' ? $test['result_value'] : ''); ?>"
                                                   placeholder="Enter result..."
                                                   <?php echo ($selected_request['payment_status'] == 'Unpaid') ? 'disabled' : 'required'; ?>>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?php echo htmlspecialchars($test['normal_range']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <input type="text" 
                                                   name="comments[<?php echo $test['result_id']; ?>]" 
                                                   class="form-control form-control-sm text-muted" 
                                                   value="<?php echo htmlspecialchars($test['technician_remarks'] ?? ''); ?>"
                                                   placeholder="Optional notes"
                                                   <?php echo ($selected_request['payment_status'] == 'Unpaid') ? 'disabled' : ''; ?>>
                                        </td>
                                    </tr>
                                    <?php endwhile; 
                                    else: ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">No tests found for this request.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end mt-4 pt-3 border-top">
                            <?php if ($selected_request['payment_status'] == 'Unpaid'): ?>
                                <button type="button" class="btn btn-secondary px-4 disabled" disabled>
                                    <i class="fa-solid fa-lock me-2"></i> Awaiting Payment
                                </button>
                            <?php else: ?>
                                <a href="enter_results.php" class="btn btn-light border me-2">Cancel</a>
                                <button type="submit" name="save_results" class="btn btn-success px-5 shadow-sm">
                                    <i class="fa-solid fa-check-double me-2"></i> Save & Complete
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="card glass-card text-center p-5 text-muted h-100 d-flex flex-column justify-content-center align-items-center">
                <div class="bg-light rounded-circle p-4 mb-3">
                    <i class="fa-solid fa-vial fa-4x text-secondary opacity-25"></i>
                </div>
                <h4>Select a Request</h4>
                <p>Choose a patient from the "Pending Queue" on the left to start entering results.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>