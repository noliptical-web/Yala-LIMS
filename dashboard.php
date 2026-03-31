<?php
// dashboard.php
session_start();
require_once 'includes/db_connect.php';
if (!isset($_SESSION['loggedin'])) { header("location: index.php"); exit; }

$role = $_SESSION['role'];

// --- STAT COUNTERS ---
$today       = $conn->query("SELECT COUNT(*) as c FROM patients WHERE DATE(registered_at) = CURDATE()")->fetch_assoc()['c'];
$pending     = $conn->query("SELECT COUNT(*) as c FROM lab_requests WHERE status = 'Pending'")->fetch_assoc()['c'];
$completed   = $conn->query("SELECT COUNT(*) as c FROM lab_requests WHERE status = 'Completed' AND DATE(request_date) = CURDATE()")->fetch_assoc()['c'];
$unpaid      = $conn->query("SELECT COUNT(*) as c FROM lab_requests WHERE payment_status = 'Unpaid'")->fetch_assoc()['c'];
$total_rev   = $conn->query("SELECT COALESCE(SUM(amount_paid),0) as t FROM payments WHERE DATE(payment_date) = CURDATE()")->fetch_assoc()['t'];

// --- PAGE CONFIGURATION ---
$page_title = "Dashboard - Yala LIMS";
include 'includes/header.php';
?>

<style>
    .stat-card {
        border: none;
        border-radius: 16px;
        color: white;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
        overflow: hidden;
        position: relative;
    }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 16px 40px rgba(0,0,0,0.18) !important; }
    .stat-card .bg-icon {
        position: absolute; right: -10px; bottom: -10px;
        font-size: 5rem; opacity: 0.15;
    }
    .module-card {
        background: rgba(255,255,255,0.95);
        border: none;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(31,38,135,0.10);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
        text-decoration: none;
        display: block;
    }
    .module-card:hover { transform: translateY(-6px); box-shadow: 0 12px 35px rgba(31,38,135,0.18); }
    .icon-wrap {
        width: 70px; height: 70px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.8rem; margin: 0 auto 14px;
    }
    .section-title {
        font-size: 0.75rem; font-weight: 700; letter-spacing: 2px;
        text-transform: uppercase; color: #90a4ae;
    }
    .welcome-banner {
        background: linear-gradient(135deg, #1565C0 0%, #0288D1 100%);
        border-radius: 16px; color: white;
        padding: 22px 28px; margin-bottom: 28px;
    }
</style>

<!-- WELCOME BANNER -->
<div class="welcome-banner d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <p class="mb-0 opacity-75 small">Good <?php echo (date('H') < 12) ? 'Morning' : ((date('H') < 17) ? 'Afternoon' : 'Evening'); ?></p>
        <h4 class="mb-0 fw-bold"><?php echo htmlspecialchars($_SESSION['full_name']); ?></h4>
        <small class="opacity-75"><i class="fa-solid fa-circle-dot me-1 text-success"></i><?php echo htmlspecialchars($role); ?> &bull; <?php echo date('l, d F Y'); ?></small>
    </div>
    <div class="text-end">
        <div class="badge bg-white text-primary px-3 py-2 fs-6">
            <i class="fa-solid fa-clock me-1"></i><span id="liveClock"></span>
        </div>
    </div>
</div>

<!-- STAT CARDS (Admin/visible to all but filtered) -->
<?php if ($role == 'Admin' || $role == 'Receptionist' || $role == 'Doctor' || $role == 'LabTech'): ?>
<div class="row g-3 mb-4">

    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 shadow" style="background: linear-gradient(135deg, #11998e, #38ef7d);">
            <p class="mb-1 small opacity-75">Patients Today</p>
            <h2 class="fw-bold mb-0"><?php echo $today; ?></h2>
            <i class="fa-solid fa-calendar-day bg-icon"></i>
        </div>
    </div>

    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 shadow" style="background: linear-gradient(135deg, #f7971e, #ffd200);">
            <p class="mb-1 small opacity-75">Tests Pending</p>
            <h2 class="fw-bold mb-0"><?php echo $pending; ?></h2>
            <i class="fa-solid fa-flask bg-icon"></i>
        </div>
    </div>

    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 shadow" style="background: linear-gradient(135deg, #1e90ff, #a18cd1);">
            <p class="mb-1 small opacity-75">Completed Today</p>
            <h2 class="fw-bold mb-0"><?php echo $completed; ?></h2>
            <i class="fa-solid fa-check-circle bg-icon"></i>
        </div>
    </div>

    <?php if ($role == 'Admin' || $role == 'Receptionist'): ?>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 shadow" style="background: linear-gradient(135deg, #ff5e62, #ff9966);">
            <p class="mb-1 small opacity-75">Unpaid Bills</p>
            <h2 class="fw-bold mb-0"><?php echo $unpaid; ?></h2>
            <i class="fa-solid fa-file-invoice-dollar bg-icon"></i>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

<!-- REVENUE BANNER (Admin only) -->
<?php if ($role == 'Admin'): ?>
<div class="alert border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center justify-content-between"
     style="background: linear-gradient(90deg, #e8f5e9, #f1f8e9);">
    <div>
        <p class="mb-0 text-muted small fw-bold text-uppercase">Today's Revenue</p>
        <h3 class="fw-bold text-success mb-0">KES <?php echo number_format($total_rev, 2); ?></h3>
    </div>
    <a href="reports.php" class="btn btn-success rounded-pill px-4 shadow-sm">
        <i class="fa-solid fa-chart-line me-2"></i>Full Reports
    </a>
</div>
<?php endif; ?>

<!-- MODULES -->
<p class="section-title mb-3">Your Modules</p>

<div class="row g-3 justify-content-center">

    <?php if ($role == 'Receptionist' || $role == 'Admin'): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="add_patient.php" class="module-card text-center p-4">
            <div class="icon-wrap" style="background:#e3f2fd; color:#1976d2;">
                <i class="fa-solid fa-id-card"></i>
            </div>
            <h6 class="text-dark fw-bold mb-1">Reception</h6>
            <small class="text-muted">Register patients</small>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($role == 'Doctor' || $role == 'Admin'): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="request_test.php" class="module-card text-center p-4">
            <div class="icon-wrap" style="background:#fce4ec; color:#c2185b;">
                <i class="fa-solid fa-user-doctor"></i>
            </div>
            <h6 class="text-dark fw-bold mb-1">Doctor's Console</h6>
            <small class="text-muted">Order lab tests</small>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($role == 'LabTech' || $role == 'Admin'): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="enter_results.php" class="module-card text-center p-4">
            <div class="icon-wrap" style="background:#e8f5e9; color:#388e3c;">
                <i class="fa-solid fa-microscope"></i>
            </div>
            <h6 class="text-dark fw-bold mb-1">Lab Bench</h6>
            <small class="text-muted">Enter & print results</small>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($role == 'Receptionist' || $role == 'Admin'): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="billing.php" class="module-card text-center p-4">
            <div class="icon-wrap" style="background:#e8f5e9; color:#2e7d32;">
                <i class="fa-solid fa-cash-register"></i>
            </div>
            <h6 class="text-dark fw-bold mb-1">Cashier</h6>
            <small class="text-muted">Payments & receipts</small>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($role == 'Doctor' || $role == 'Admin' || $role == 'Receptionist'): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="patient_history.php" class="module-card text-center p-4">
            <div class="icon-wrap" style="background:#f3e5f5; color:#7b1fa2;">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <h6 class="text-dark fw-bold mb-1">Patient History</h6>
            <small class="text-muted">Search records & results</small>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($role == 'Admin'): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="reports.php" class="module-card text-center p-4">
            <div class="icon-wrap" style="background:#ede7f6; color:#512da8;">
                <i class="fa-solid fa-chart-pie"></i>
            </div>
            <h6 class="text-dark fw-bold mb-1">Reports</h6>
            <small class="text-muted">Analytics & summaries</small>
        </a>
    </div>

    <div class="col-6 col-md-4 col-lg-3">
        <a href="manage_users.php" class="module-card text-center p-4">
            <div class="icon-wrap" style="background:#263238; color:#eceff1;">
                <i class="fa-solid fa-users-gear"></i>
            </div>
            <h6 class="text-dark fw-bold mb-1">Admin Panel</h6>
            <small class="text-muted">Manage staff accounts</small>
        </a>
    </div>
    <?php endif; ?>

</div>

<script>
// Live clock
function updateClock() {
    const now = new Date();
    document.getElementById('liveClock').textContent =
        now.toLocaleTimeString('en-KE', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
updateClock();
setInterval(updateClock, 1000);
</script>

<?php include 'includes/footer.php'; ?>