<?php
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: dashboard.php"); exit;
}

$success     = "";
$error       = "";
$patient     = null;
$doctor_name = $_SESSION['full_name'] ?? 'Doctor';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_order'])) {
    $patient_id = intval($_POST['patient_id']);
    $tests      = $_POST['tests'] ?? [];

    if ($patient_id <= 0 || empty($tests)) {
        $error = "Please select a patient and at least one test.";
    } else {
        // WORKFLOW: Block duplicate active orders for same patient
        $dup_check = $conn->prepare("SELECT COUNT(*) FROM lab_requests WHERE patient_id = ? AND status IN ('Pending','In Progress')");
        $dup_check->bind_param("i", $patient_id);
        $dup_check->execute();
        $dup_check->bind_result($dup_count);
        $dup_check->fetch();
        $dup_check->close();

        if ($dup_count > 0) {
            $error = "This patient already has an active request in progress. Complete it first before ordering new tests.";
        } else {
            // Insert lab_request
            $stmt = $conn->prepare("INSERT INTO lab_requests (patient_id, requested_by, status, payment_status) VALUES (?, ?, 'Pending', 'Unpaid')");
            $stmt->bind_param("is", $patient_id, $doctor_name);
            if ($stmt->execute()) {
                $rid = $conn->insert_id;
                $uid = intval($_SESSION['id'] ?? 1);
                $s2  = $conn->prepare("INSERT INTO test_results (request_id, test_id, result_value, entered_by) VALUES (?, ?, 'Pending', ?)");
                foreach ($tests as $tid) {
                    $tid = intval($tid);
                    $s2->bind_param("iii", $rid, $tid, $uid);
                    $s2->execute();
                }
                $s2->close();
                // Re-fetch patient for notification
                $stmt_pf = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
                $stmt_pf->bind_param("i", $patient_id);
                $stmt_pf->execute();
                $patient = $stmt_pf->get_result()->fetch_assoc();
                $stmt_pf->close();
                $pat_name  = $conn->real_escape_string($patient['full_name'] ?? "Patient #$patient_id");
                $notif_msg = $conn->real_escape_string("New Order: Request #$rid for $pat_name");
                $notif_lnk = $conn->real_escape_string("enter_results.php?manage_id=$rid");
                $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('LabTech', '$notif_msg', '$notif_lnk', 0, NOW())");
                $success = "Lab Request #$rid submitted! Lab Tech notified.";
            } else {
                $error = "DB Error: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

if (isset($_GET['search_opd']) && !isset($_POST['submit_order'])) {
    $opd  = trim($_GET['search_opd']);
    $stmt = $conn->prepare("SELECT * FROM patients WHERE opd_number = ?");
    $stmt->bind_param("s", $opd);
    $stmt->execute();
    $res     = $stmt->get_result();
    $patient = $res->num_rows > 0 ? $res->fetch_assoc() : null;
    if (!$patient) $error = "Patient '" . htmlspecialchars($opd) . "' not found.";
    $stmt->close();
}

$tests_raw  = $conn->query("SELECT * FROM lab_tests ORDER BY test_category, test_name");
$categories = [];
if ($tests_raw) while ($r = $tests_raw->fetch_assoc()) $categories[$r['test_category']][] = $r;

// Only show this doctor's own completed reports
$reports_result = $conn->prepare("SELECT r.request_id, p.full_name, p.opd_number, r.request_date FROM lab_requests r JOIN patients p ON r.patient_id = p.patient_id WHERE r.status = 'Completed' AND r.requested_by = ? ORDER BY r.request_date DESC LIMIT 10");
$reports_result->bind_param("s", $doctor_name);
$reports_result->execute();
$reports_result = $reports_result->get_result();

$page_title = "Doctor's Console - Yala LIMS";
include 'includes/header.php';
?>
<style>
.glass-card{background:rgba(255,255,255,0.97);border:none;border-radius:14px;box-shadow:0 4px 20px rgba(31,38,135,0.09)}
.category-header{font-size:.78rem;font-weight:800;color:#1565c0;text-transform:uppercase;letter-spacing:1.2px;margin:16px 0 8px;padding-bottom:4px;border-bottom:2px solid #e3f2fd}
.test-card{border:1px solid #eef0f3;border-radius:8px;cursor:pointer;transition:all 0.15s;user-select:none;background:#fff}
.test-card:hover{background:#f8f9fa;transform:translateY(-2px)}
.test-card.selected{background:#e3f2fd;border-color:#1976d2}
.cost-ticker{background:linear-gradient(135deg,#1565C0,#0288D1);border-radius:12px;color:white;padding:12px 18px}
.patient-banner{background:linear-gradient(135deg,#1565C0,#0288D1);border-radius:14px 14px 0 0;color:white}
.section-title{font-size:.7rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#90a4ae}
</style>
<?php if ($success): ?>
<div class="alert alert-success border-0 shadow-sm rounded-3 mb-3 d-flex align-items-center justify-content-between">
    <span><i class="fa-solid fa-circle-check me-2"></i><?php echo htmlspecialchars($success); ?></span>
    <a href="request_test.php" class="btn btn-sm btn-outline-success rounded-pill px-3">New Request</a>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger border-0 shadow-sm rounded-3 mb-3">
    <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>
<div class="row g-3">
<div class="col-md-4">
    <div class="glass-card p-3 mb-3">
        <p class="section-title mb-2">Find Patient</p>
        <form method="get" action="request_test.php">
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" name="search_opd" class="form-control" placeholder="OPD / File Number"
                       value="<?php echo isset($_GET['search_opd']) ? htmlspecialchars($_GET['search_opd']) : ''; ?>" required>
                <button class="btn btn-primary" type="submit">Search</button>
            </div>
        </form>
    </div>
    <?php if ($patient): ?>
    <div class="cost-ticker mb-3">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <div class="small opacity-75 fw-bold">ESTIMATED BILL</div>
                <h3 class="fw-bold mb-0">KES <span id="totalCost">0</span></h3>
            </div>
            <div class="text-end">
                <div class="small opacity-75"><span id="testCount">0</span> test(s)</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="glass-card">
        <div class="card-header bg-success text-white p-3 border-0" style="border-radius:14px 14px 0 0">
            <h6 class="mb-0 fw-bold"><i class="fa-solid fa-file-medical me-2"></i>Recent Completed</h6>
        </div>
        <div class="p-2" style="max-height:340px;overflow-y:auto">
            <?php if ($reports_result && $reports_result->num_rows > 0): while ($rep = $reports_result->fetch_assoc()): ?>
            <div class="d-flex justify-content-between align-items-center border-bottom py-2 px-1">
                <div style="min-width:0">
                    <div class="fw-bold text-dark text-truncate" style="font-size:.85rem"><?php echo htmlspecialchars($rep['full_name']); ?></div>
                    <small class="text-muted" style="font-size:.73rem"><?php echo htmlspecialchars($rep['opd_number']); ?> &bull; <?php echo date('d M H:i', strtotime($rep['request_date'])); ?></small>
                </div>
                <a href="print_report.php?id=<?php echo $rep['request_id']; ?>" target="_blank" class="btn btn-sm btn-outline-danger rounded-pill ms-2" style="font-size:.75rem"><i class="fa-solid fa-print"></i></a>
            </div>
            <?php endwhile; else: ?>
            <div class="text-center py-4 text-muted small">No completed reports yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="col-md-8">
    <?php if ($patient): ?>
    <div class="glass-card">
        <div class="patient-banner p-3 d-flex align-items-center gap-3">
            <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:52px;height:52px">
                <i class="fa-solid fa-user-injured fa-lg"></i>
            </div>
            <div class="flex-grow-1">
                <h5 class="mb-0 fw-bold"><?php echo htmlspecialchars($patient['full_name']); ?></h5>
                <div class="d-flex flex-wrap gap-2 mt-1">
                    <span class="badge bg-white text-primary">OPD: <?php echo htmlspecialchars($patient['opd_number']); ?></span>
                    <span class="badge bg-primary-subtle border border-light text-white"><?php echo htmlspecialchars($patient['age']); ?> yrs / <?php echo htmlspecialchars($patient['gender']); ?></span>
                </div>
            </div>
            <span class="badge bg-white text-primary px-3 py-2">Dr. <?php echo htmlspecialchars($doctor_name); ?></span>
        </div>
        <div class="card-body p-4">
            <form method="post" action="request_test.php" id="labOrderForm">
                <input type="hidden" name="patient_id" value="<?php echo $patient['patient_id']; ?>">
                <?php foreach ($categories as $cat_name => $tests): ?>
                <div class="category-header"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($cat_name); ?></div>
                <div class="row g-2 mb-2">
                    <?php foreach ($tests as $test): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="test-card p-2 h-100" onclick="toggleTest('t<?php echo $test['test_id']; ?>', <?php echo (float)$test['cost']; ?>)">
                            <div class="d-flex justify-content-between align-items-center">
                                <input class="d-none" type="checkbox" name="tests[]" value="<?php echo $test['test_id']; ?>" id="t<?php echo $test['test_id']; ?>" data-cost="<?php echo (float)$test['cost']; ?>">
                                <span class="small fw-medium text-dark pe-1"><?php echo htmlspecialchars($test['test_name']); ?></span>
                                <span class="badge bg-light text-secondary border flex-shrink-0"><?php echo number_format($test['cost']); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                    <span class="text-muted small"><span id="selectedCount" class="fw-bold text-dark">0</span> test(s) — KES <span id="totalCostBottom" class="fw-bold text-primary">0</span></span>
                    <button type="submit" name="submit_order" value="1" id="submitOrderBtn" class="btn btn-primary px-5 shadow-sm rounded-pill">
                        <i class="fa-solid fa-paper-plane me-2"></i>Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="glass-card text-center p-5 text-muted" style="min-height:420px">
        <div class="bg-light rounded-circle p-4 mb-3 d-inline-block"><i class="fa-solid fa-user-doctor fa-4x text-secondary opacity-25"></i></div>
        <h5 class="fw-bold">No Patient Selected</h5>
        <p class="mb-0">Enter an OPD number on the left and click Search.</p>
    </div>
    <?php endif; ?>
</div>
</div>
<script>
let totalCost = 0;
function toggleTest(id, cost) {
    const cb = document.getElementById(id);
    const card = cb.closest('.test-card');
    cb.checked = !cb.checked;
    card.classList.toggle('selected', cb.checked);
    totalCost += cb.checked ? cost : -cost;
    const checked = document.querySelectorAll('input[name="tests[]"]:checked').length;
    const fmt = Math.round(totalCost).toLocaleString();
    const tc = document.getElementById('totalCost'); if (tc) tc.textContent = fmt;
    const sc = document.getElementById('selectedCount'); if (sc) sc.textContent = checked;
    const tcb = document.getElementById('totalCostBottom'); if (tcb) tcb.textContent = fmt;
    const tn = document.getElementById('testCount'); if (tn) tn.textContent = checked;
}
</script>
<?php include 'includes/footer.php'; ?>