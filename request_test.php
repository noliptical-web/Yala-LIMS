<?php
// request_test.php
session_start();
require_once 'includes/db_connect.php';

// --- 1. SECURITY & ACCESS CONTROL ---
if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] != 'Doctor' && $_SESSION['role'] != 'Admin')) {
    header("location: dashboard.php");
    exit;
}

// Initialize variables
$patient = null;
$error = "";
$success = "";
$doctor_name = $_SESSION['full_name'] ?? 'Unknown Doctor';

// --- 2. LOGIC: SEARCH PATIENT ---
if (isset($_GET['search_opd'])) {
    $opd = trim($_GET['search_opd']);
    // Use prepared statement
    $stmt = $conn->prepare("SELECT * FROM patients WHERE opd_number = ?");
    $stmt->bind_param("s", $opd);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $patient = $result->fetch_assoc();
    } else {
        $error = "Patient with OPD Number 'htmlspecialchars($opd)' not found.";
    }
    $stmt->close();
}

// --- 3. LOGIC: SUBMIT LAB ORDER ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_order'])) {
    
    // Validate inputs
    $patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
    $tests_selected = isset($_POST['tests']) ? $_POST['tests'] : [];

    if ($patient_id > 0 && !empty($tests_selected)) {
        
        // Start Transaction (Ensures all data saves, or none of it does)
        $conn->begin_transaction();

        try {
            // A. Create Main Request Header
            $stmt = $conn->prepare("INSERT INTO lab_requests (patient_id, requested_by, request_date, status) VALUES (?, ?, NOW(), 'Pending')");
            $stmt->bind_param("is", $patient_id, $doctor_name);
            
            if (!$stmt->execute()) {
                throw new Exception("Error creating request header.");
            }
            $request_id = $conn->insert_id;
            $stmt->close();

            // B. Insert Individual Tests
            $stmt_test = $conn->prepare("INSERT INTO test_results (request_id, test_id, result_value) VALUES (?, ?, 'Pending')");
            foreach ($tests_selected as $test_id) {
                $test_id = intval($test_id);
                $stmt_test->bind_param("ii", $request_id, $test_id);
                if (!$stmt_test->execute()) {
                    throw new Exception("Error adding test ID: $test_id");
                }
            }
            $stmt_test->close();

            // C. Create Notification for Lab Tech
            // Fetch patient name safely for the notification
            $pat_name_safe = $patient ? $patient['full_name'] : "Patient #$patient_id";
            $notif_msg = "New Order: Request #$request_id for $pat_name_safe";
            $notif_link = "/YALA_LIMS/enter_results.php?manage_id=$request_id";
            
            $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('LabTech', '$notif_msg', '$notif_link', 0, NOW())");

            // Commit Transaction
            $conn->commit();
            
            $success = "Lab Request #$request_id submitted successfully with " . count($tests_selected) . " tests.";
            
            // Clear patient data effectively resetting the form view to prevent double submission
            $patient = null; 

        } catch (Exception $e) {
            $conn->rollback(); // Undo changes if error occurs
            $error = "System Error: " . $e->getMessage();
        }
    } else {
        $error = "Please select a patient and at least one test.";
    }
}

// --- 4. LOGIC: FETCH DATA FOR VIEW ---

// A. Fetch All Tests & Group by Category
$raw_tests = $conn->query("SELECT * FROM lab_tests ORDER BY test_category, test_name");
$categories = []; 
if ($raw_tests) {
    while($row = $raw_tests->fetch_assoc()) {
        $categories[$row['test_category']][] = $row;
    }
}

// B. Fetch Recent Reports (Last 10 completed)
$recent_sql = "SELECT r.request_id, p.full_name, r.request_date 
               FROM lab_requests r 
               JOIN patients p ON r.patient_id = p.patient_id 
               WHERE r.status = 'Completed' 
               ORDER BY r.request_date DESC LIMIT 10";
$reports_result = $conn->query($recent_sql);

// --- PAGE START ---
$page_title = "Doctor's Console - Yala LIMS";
include 'includes/header.php'; 
?>

<style>
    /* Custom Styling for the Test Grid */
    .category-header { 
        font-size: 0.85rem; 
        font-weight: 800; 
        color: #1976d2; 
        text-transform: uppercase; 
        letter-spacing: 1px; 
        margin-top: 15px; 
        margin-bottom: 10px; 
        border-bottom: 2px solid #e3f2fd; 
        padding-bottom: 5px; 
    }
    .test-card { 
        transition: all 0.2s ease; 
        cursor: pointer; 
        border: 1px solid #f0f0f0; 
        user-select: none;
    }
    .test-card:hover { 
        background-color: #f8f9fa; 
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    }
    .test-card.selected {
        background-color: #e3f2fd;
        border-color: #2196f3;
    }
    .glass-card {
        background: rgba(255, 255, 255, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.07);
        backdrop-filter: blur(4px);
        border-radius: 10px;
    }
</style>

<div class="row">
    
    <div class="col-md-4 mb-4">
        
        <div class="card glass-card mb-4">
            <div class="card-header bg-transparent border-bottom p-3">
                <h5 class="text-primary mb-0"><i class="fa-solid fa-magnifying-glass me-2"></i> Find Patient</h5>
            </div>
            <div class="card-body">
                <form method="get" action="request_test.php">
                    <div class="input-group mb-3">
                        <input type="text" name="search_opd" class="form-control" placeholder="Enter OPD / File No." value="<?php echo isset($_GET['search_opd']) ? htmlspecialchars($_GET['search_opd']) : ''; ?>" required>
                        <button class="btn btn-primary" type="submit">Search</button>
                    </div>
                </form>
                
                <?php if ($error): ?> 
                    <div class="alert alert-danger small p-2 shadow-sm border-0"><i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo $error; ?></div> 
                <?php endif; ?>
                <?php if ($success): ?> 
                    <div class="alert alert-success small p-2 shadow-sm border-0"><i class="fa-solid fa-check-circle me-1"></i> <?php echo $success; ?></div> 
                <?php endif; ?>
            </div>
        </div>

        <div class="card glass-card">
            <div class="card-header bg-success text-white p-3" style="border-radius: 10px 10px 0 0;">
                <h5 class="mb-0"><i class="fa-solid fa-file-medical me-2"></i> Recent Completed</h5>
            </div>
            <div class="list-group list-group-flush p-2">
                <?php if ($reports_result && $reports_result->num_rows > 0): ?>
                    <?php while($rep = $reports_result->fetch_assoc()): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center border-bottom py-2">
                            <div>
                                <h6 class="mb-0 fw-bold text-dark" style="font-size: 0.9rem;">
                                    <?php echo htmlspecialchars($rep['full_name']); ?>
                                </h6>
                                <small class="text-muted" style="font-size: 0.75rem;">
                                    <i class="fa-regular fa-clock me-1"></i><?php echo date('d M - H:i', strtotime($rep['request_date'])); ?>
                                </small>
                            </div>
                            <a href="print_report.php?id=<?php echo $rep['request_id']; ?>" target="_blank" class="btn btn-sm btn-outline-danger rounded-pill px-3" style="font-size: 0.75rem;">
                                <i class="fa-solid fa-print"></i> PDF
                            </a>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center p-4 text-muted small">
                        <i class="fa-solid fa-inbox fa-2x mb-2 text-light"></i><br>
                        No completed reports found.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-8 mb-4">
        <?php if ($patient): ?>
        <div class="card glass-card border-0 shadow-lg">
            <div class="card-header bg-primary text-white p-3 border-0" style="border-radius: 10px 10px 0 0;">
                <div class="d-flex align-items-center">
                    <div class="bg-white text-primary rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                        <i class="fa-solid fa-user-injured fa-xl"></i>
                    </div>
                    <div>
                        <h4 class="mb-0"><?php echo htmlspecialchars($patient['full_name']); ?></h4>
                        <div class="mt-1">
                            <span class="badge bg-white text-primary me-1">OPD: <?php echo htmlspecialchars($patient['opd_number']); ?></span>
                            <span class="badge bg-primary-subtle border border-light text-white">
                                <?php echo htmlspecialchars($patient['age']); ?> Yrs / <?php echo htmlspecialchars($patient['gender']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card-body p-4">
                <form method="post" id="labOrderForm">
                    <input type="hidden" name="patient_id" value="<?php echo $patient['patient_id']; ?>">
                    
                    <div class="mb-5">
                        <?php foreach($categories as $cat_name => $tests): ?>
                            <div class="category-header">
                                <i class="fa-solid fa-tag me-1"></i> <?php echo htmlspecialchars($cat_name); ?>
                            </div>
                            <div class="row g-2 mb-3">
                                <?php foreach($tests as $test): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card test-card h-100" onclick="toggleCheckbox('t<?php echo $test['test_id']; ?>')">
                                        <div class="card-body py-2 px-3">
                                            <div class="form-check p-0">
                                                <input class="form-check-input d-none" type="checkbox" name="tests[]" 
                                                       value="<?php echo $test['test_id']; ?>" 
                                                       id="t<?php echo $test['test_id']; ?>"
                                                       data-cost="<?php echo $test['cost']; ?>">
                                                
                                                <div class="d-flex justify-content-between align-items-center w-100">
                                                    <span class="fw-medium small text-break pe-2"><?php echo htmlspecialchars($test['test_name']); ?></span>
                                                    <span class="badge bg-light text-secondary border">
                                                        <?php echo number_format($test['cost']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4 pt-3 border-top sticky-bottom bg-white d-flex justify-content-between align-items-center">
                        <div class="text-muted small">
                            <span id="selectedCount">0</span> tests selected
                        </div>
                        <button type="submit" name="submit_order" class="btn btn-primary btn-lg shadow px-5">
                            <i class="fa-solid fa-paper-plane me-2"></i> Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
            <div class="card glass-card text-center p-5 text-muted h-100 d-flex flex-column justify-content-center align-items-center">
                <div class="bg-light rounded-circle p-4 mb-3">
                    <i class="fa-solid fa-user-doctor fa-4x text-secondary opacity-25"></i>
                </div>
                <h4>Waiting for Patient</h4>
                <p>Enter an OPD number on the left to start a lab request.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
function toggleCheckbox(id) {
    const checkbox = document.getElementById(id);
    const card = checkbox.closest('.test-card');
    
    // Toggle the checkbox
    checkbox.checked = !checkbox.checked;
    
    // Update Visuals
    if (checkbox.checked) {
        card.classList.add('selected');
    } else {
        card.classList.remove('selected');
    }

    // Update Counter (Optional UX)
    updateCount();
}

function updateCount() {
    const checked = document.querySelectorAll('input[name="tests[]"]:checked').length;
    const counter = document.getElementById('selectedCount');
    if(counter) counter.innerText = checked;
}
</script>

<?php include 'includes/footer.php'; ?>