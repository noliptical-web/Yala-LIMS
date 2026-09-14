<?php
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$role     = trim($_SESSION['role'] ?? '');
$fullName = $_SESSION['full_name'] ?? 'User';
$userId   = intval($_SESSION['id'] ?? 0);  // NOTE: login stores $_SESSION['id'] not 'user_id'

/*
 * ROLE PERMISSIONS — who does what:
 *   Admin        — everything
 *   Doctor       — register patient, request test, patient history, reports
 *   Receptionist — register patient, cashier/billing, patient history
 *   LabTech      — enter results, patient history
 */
$isAdmin     = ($role === 'Admin');
$isDoctor    = ($role === 'Doctor');
$isReception = ($role === 'Receptionist');
$isLabTech   = ($role === 'LabTech' || $role === 'Lab Technician' || $role === 'LabTechnician');

// ─── Stats ─────────────────────────────────────────────────────────────────
$r = $conn->query("SELECT COUNT(*) FROM patients WHERE DATE(registered_at)=CURDATE()");
$patients_today = $r ? $r->fetch_row()[0] : 0;

$r = $conn->query("SELECT COUNT(*) FROM lab_requests WHERE payment_status='Unpaid'");
$unpaid = $r ? $r->fetch_row()[0] : 0;

$r = $conn->query("SELECT COUNT(*) FROM lab_requests WHERE status IN ('Pending','In Progress')");
$pending_tests = $r ? $r->fetch_row()[0] : 0;

$r = $conn->query("SELECT COUNT(*) FROM patients");
$total_patients = $r ? $r->fetch_row()[0] : 0;

$r = $conn->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE payment_method != 'Pending' AND DATE(payment_date)=CURDATE()");
$revenue_today = $r ? $r->fetch_row()[0] : 0;

$hour      = (int)date('H');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim($fullName))[0];

$page_title = 'Dashboard — Yala LIMS';
include 'includes/header.php';
?>

<style>
.dash-welcome {
    background: linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);
    color:#fff; border-radius:15px; padding:28px 32px; margin-bottom:24px;
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;
}
.dash-welcome h2 { font-size:1.7rem; font-weight:700; margin-bottom:4px; }
.dash-welcome p  { opacity:.8; font-size:.9rem; margin:0; }
.clock-badge {
    background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
    border-radius:10px; padding:8px 18px; font-size:.9rem; font-weight:600; white-space:nowrap;
}
.stat-card-d { border-radius:12px; padding:20px; box-shadow:0 2px 12px rgba(0,0,0,.07); margin-bottom:16px; }
.stat-number { font-size:2.2rem; font-weight:700; line-height:1; }
.stat-lbl    { font-size:.72rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase; margin-top:6px; opacity:.8; }
.mod-card {
    background:#fff; border:1.5px solid #e2e8f0; border-radius:14px;
    padding:22px 20px; text-decoration:none; color:#1e293b;
    display:flex; flex-direction:column; gap:8px; transition:all .2s; height:100%;
}
.mod-card:hover {
    border-color:#2563eb; transform:translateY(-3px);
    box-shadow:0 6px 20px rgba(37,99,235,.12); text-decoration:none; color:#1e293b;
}
.mod-icon  { font-size:1.8rem; line-height:1; }
.mod-title { font-weight:700; font-size:1rem; color:#0f172a; }
.mod-desc  { font-size:.8rem; color:#64748b; line-height:1.45; }
.mod-badge {
    display:inline-block; background:#fee2e2; color:#991b1b;
    border-radius:20px; padding:2px 10px; font-size:.7rem; font-weight:700;
    margin-top:auto; align-self:flex-start;
}
.section-label { font-size:.72rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#94a3b8; margin-bottom:14px; }
</style>

<!-- Welcome Banner -->
<div class="dash-welcome">
    <div>
        <h2><?= $greeting ?>, <?= htmlspecialchars($firstName) ?> 👋</h2>
        <p><?= htmlspecialchars($role) ?> &mdash; Yala Sub-County Hospital Laboratory</p>
    </div>
    <div class="clock-badge" id="dashClock"><?= date('D, d M Y · H:i') ?></div>
</div>

<!-- Stats — shown per role -->
<div class="row mb-4">
    <?php if ($isAdmin || $isReception || $isDoctor): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card-d" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#1e40af">
            <div class="stat-number"><?= $patients_today ?></div>
            <div class="stat-lbl">Patients Today</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin || $isReception): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card-d" style="background:linear-gradient(135deg,#fee2e2,#fecaca);color:#991b1b">
            <div class="stat-number"><?= $unpaid ?></div>
            <div class="stat-lbl">Unpaid Bills</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin || $isLabTech || $isDoctor): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card-d" style="background:linear-gradient(135deg,#fef9c3,#fde68a);color:#92400e">
            <div class="stat-number"><?= $pending_tests ?></div>
            <div class="stat-lbl">Pending Tests</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin || $isReception): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card-d" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);color:#166534">
            <div class="stat-number" style="font-size:1.4rem">KES <?= number_format($revenue_today, 0) ?></div>
            <div class="stat-lbl">Today's Revenue</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-6 col-md-3">
        <div class="stat-card-d" style="background:linear-gradient(135deg,#f3e8ff,#e9d5ff);color:#6b21a8">
            <div class="stat-number"><?= $total_patients ?></div>
            <div class="stat-lbl">Total Patients</div>
        </div>
    </div>
</div>

<!-- Module Cards -->
<div class="section-label">Your Modules</div>
<div class="row g-3 mb-5">

    <!-- Register Patient: Admin, Doctor, Receptionist -->
    <?php if ($isAdmin || $isDoctor || $isReception): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="add_patient.php" class="mod-card">
            <div class="mod-icon">🧑‍⚕️</div>
            <div class="mod-title">Register Patient</div>
            <div class="mod-desc">Add new patient records and insurance details</div>
        </a>
    </div>
    <?php endif; ?>

    <!-- Cashier / Billing: Admin, Receptionist ONLY -->
    <?php if ($isAdmin || $isReception): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="billing.php" class="mod-card">
            <div class="mod-icon">💳</div>
            <div class="mod-title">Cashier / Billing</div>
            <div class="mod-desc">Process payments — M-Pesa, cash, insurance, waivers</div>
            <?php if ($unpaid > 0): ?>
            <span class="mod-badge"><?= $unpaid ?> pending</span>
            <?php endif; ?>
        </a>
    </div>
    <?php endif; ?>

    <!-- Request Test: Admin, Doctor ONLY (not Receptionist) -->
    <?php if ($isAdmin || $isDoctor): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="request_test.php" class="mod-card">
            <div class="mod-icon">🧪</div>
            <div class="mod-title">Request Test</div>
            <div class="mod-desc">Order lab tests for a patient</div>
        </a>
    </div>
    <?php endif; ?>

    <!-- Enter Results: Admin, LabTech ONLY -->
    <?php if ($isAdmin || $isLabTech): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="enter_results.php" class="mod-card">
            <div class="mod-icon">📋</div>
            <div class="mod-title">Enter Results</div>
            <div class="mod-desc">Record and submit laboratory test results</div>
            <?php if ($pending_tests > 0): ?>
            <span class="mod-badge"><?= $pending_tests ?> pending</span>
            <?php endif; ?>
        </a>
    </div>
    <?php endif; ?>

    <!-- Patient History: All roles -->
    <?php if ($isAdmin || $isDoctor || $isReception || $isLabTech): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="patient_history.php" class="mod-card">
            <div class="mod-icon">📁</div>
            <div class="mod-title">Patient History</div>
            <div class="mod-desc">Search and view patient records and past results</div>
        </a>
    </div>
    <?php endif; ?>

    <!-- Appointments: Admin, Doctor, Receptionist -->
    <?php if ($isAdmin || $isDoctor || $isReception): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="appointments.php" class="mod-card">
            <div class="mod-icon">📅</div>
            <div class="mod-title">Appointments</div>
            <div class="mod-desc">Schedule patient visits and test bookings</div>
        </a>
    </div>
    <?php endif; ?>

    <!-- Insurance Claims: Admin, Receptionist -->
    <?php if ($isAdmin || $isReception): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="insurance_claims.php" class="mod-card">
            <div class="mod-icon">🛡️</div>
            <div class="mod-title">Insurance Claims</div>
            <div class="mod-desc">Manage SHA / NHIF and private claims</div>
        </a>
    </div>
    <?php endif; ?>

    <!-- Reports: Admin, Doctor ONLY -->
    <?php if ($isAdmin || $isDoctor): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="reports.php" class="mod-card">
            <div class="mod-icon">📊</div>
            <div class="mod-title">Reports</div>
            <div class="mod-desc">Analytics, trends, and lab performance reports</div>
        </a>
    </div>
    <?php endif; ?>

    <!-- Admin-only modules -->
    <?php if ($isAdmin): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="manage_users.php" class="mod-card">
            <div class="mod-icon">👥</div>
            <div class="mod-title">Manage Users</div>
            <div class="mod-desc">Add, edit, and manage staff accounts</div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="manage_tests.php" class="mod-card">
            <div class="mod-icon">🔬</div>
            <div class="mod-title">Test Catalogue</div>
            <div class="mod-desc">Manage tests, pricing, and insurance coverage</div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="audit_view.php" class="mod-card">
            <div class="mod-icon">🛡️</div>
            <div class="mod-title">Audit Log</div>
            <div class="mod-desc">Track all system actions and user activity</div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="import_patients.php" class="mod-card">
            <div class="mod-icon">📤</div>
            <div class="mod-title">Bulk Import</div>
            <div class="mod-desc">Import patient records from CSV</div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="backup.php" class="mod-card">
            <div class="mod-icon">💾</div>
            <div class="mod-title">Database Backup</div>
            <div class="mod-desc">Download and manage database backups</div>
        </a>
    </div>
    <?php endif; ?>

</div>

<script>
setInterval(function() {
    var n = new Date();
    var days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    var months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function pad(x){ return String(x).padStart(2,'0'); }
    var el = document.getElementById('dashClock');
    if(el) el.textContent = days[n.getDay()]+', '+pad(n.getDate())+' '+months[n.getMonth()]+' '+n.getFullYear()+' · '+pad(n.getHours())+':'+pad(n.getMinutes());
}, 1000);
</script>

<?php include 'includes/footer.php'; ?>