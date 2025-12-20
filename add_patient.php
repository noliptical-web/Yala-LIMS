<?php
// add_patient.php
session_start();
require_once 'includes/db_connect.php';

// Security: Redirect if not logged in
if (!isset($_SESSION['loggedin'])) {
    header("location: index.php");
    exit;
}

// OPTIONAL: Add Role Security (Best Practice)
// if ($_SESSION['role'] != 'Receptionist' && $_SESSION['role'] != 'Admin') {
//    die("Access Denied");
// }

$message = "";
$message_type = "";

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $opd = trim($_POST['opd_number']);
    $name = trim($_POST['full_name']);
    $age = $_POST['age'];
    $gender = $_POST['gender'];
    $phone = $_POST['phone'];

    // 1. Check if Patient already exists
    $check_sql = "SELECT patient_id FROM patients WHERE opd_number = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("s", $opd);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $message = "Error: A patient with this OPD Number already exists!";
        $message_type = "danger"; 
    } else {
        // 2. Insert New Patient
        $insert_sql = "INSERT INTO patients (opd_number, full_name, age, gender, phone_number) VALUES (?, ?, ?, ?, ?)";
        $stmt2 = $conn->prepare($insert_sql);
        $stmt2->bind_param("ssiss", $opd, $name, $age, $gender, $phone);

        if ($stmt2->execute()) {
            $message = "Patient Registered Successfully!";
            $message_type = "success"; 
        } else {
            $message = "Database Error: " . $conn->error;
            $message_type = "danger";
        }
        $stmt2->close();
    }
    $stmt->close();
}

// --- PAGE CONFIGURATION ---
$page_title = "Reception - Register Patient";
include 'includes/header.php'; 
?>

<style>
    .input-group-text { background-color: #f8f9fa; border-right: none; }
    .form-control, .form-select { border-left: none; }
    .form-control:focus, .form-select:focus { border-left: 1px solid #86b7fe; }
</style>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-7">
        
        <div class="card glass-card shadow-lg">
            <div class="card-header bg-transparent border-bottom p-4 text-center">
                <div class="mb-2">
                    <span class="d-inline-block bg-primary text-white rounded-circle p-3 shadow-sm">
                        <i class="fa-solid fa-hospital-user fa-2x"></i>
                    </span>
                </div>
                <h4 class="mb-0 text-primary fw-bold">New Patient Registration</h4>
                <p class="text-muted small">Enter patient details to create a file</p>
            </div>
            
            <div class="card-body p-4">
                
                <?php if(!empty($message)): ?>
                    <div class="alert alert-<?php echo $message_type; ?> shadow-sm rounded-3 border-0 mb-4">
                        <?php if($message_type == 'success'): ?>
                            <i class="fa-solid fa-circle-check me-2"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-circle-exclamation me-2"></i>
                        <?php endif; ?>
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <form action="" method="post">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">OPD Number / File No.</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-id-card text-secondary"></i></span>
                                <input type="text" name="opd_number" class="form-control" required placeholder="e.g. OP-2023-001">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-user text-secondary"></i></span>
                                <input type="text" name="full_name" class="form-control" required placeholder="Surname First Name">
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Age (Years)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-cake-candles text-secondary"></i></span>
                                <input type="number" name="age" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gender</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-venus-mars text-secondary"></i></span>
                                <select name="gender" class="form-select" required>
                                    <option value="" selected disabled>Select...</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-phone text-secondary"></i></span>
                                <input type="text" name="phone" class="form-control" placeholder="07...">
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg shadow">
                            <i class="fa-solid fa-floppy-disk me-2"></i> Save Patient Record
                        </button>
                        <a href="dashboard.php" class="btn btn-light text-muted">
                            Cancel
                        </a>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>