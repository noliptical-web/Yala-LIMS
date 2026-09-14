<?php
// appointments.php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$role       = $_SESSION['role'] ?? '';
$my_user    = $_SESSION['full_name'] ?? 'Staff';
$success    = $error = "";
$patient    = null;
$search_results = null;

$isAdmin     = ($role === 'Admin');
$isDoctor    = ($role === 'Doctor');
$isReception = ($role === 'Receptionist');
$isLabTech   = ($role === 'LabTech' || $role === 'Lab Technician' || $role === 'LabTechnician');

if ($isLabTech && !$isAdmin) {
    header("location: dashboard.php");
    exit;
}

// ── SEARCH PATIENT FOR SCHEDULING ──────────────────────────────────────────
if (isset($_GET['search_opd']) && trim($_GET['search_opd']) !== '') {
    $search = trim($_GET['search_opd']);
    $likeSearch = "%" . $search . "%";
    
    // Smart numeric suffix matching (e.g. matching "003" or "3" from "OP-2023-003")
    $digits_raw = '';
    $digits_int = '';
    preg_match_all('/\d+/', $search, $num_matches);
    if (!empty($num_matches[0])) {
        $last_num = end($num_matches[0]);
        $digits_raw = "%" . $last_num . "%";
        $digits_int = "%" . intval($last_num);
    }

    if ($digits_raw !== '' && $digits_int !== '') {
        $stmt = $conn->prepare("
            SELECT * FROM patients 
            WHERE opd_number LIKE ? 
               OR full_name LIKE ? 
               OR opd_number LIKE ? 
               OR opd_number LIKE ? 
            ORDER BY registered_at DESC LIMIT 15
        ");
        $stmt->bind_param("ssss", $likeSearch, $likeSearch, $digits_raw, $digits_int);
    } else {
        $stmt = $conn->prepare("
            SELECT * FROM patients 
            WHERE opd_number LIKE ? 
               OR full_name LIKE ? 
            ORDER BY registered_at DESC LIMIT 15
        ");
        $stmt->bind_param("ss", $likeSearch, $likeSearch);
    }
    
    $stmt->execute();
    $search_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($search_results)) {
        $error = "No patient found matching search: " . htmlspecialchars($search);
    } elseif (count($search_results) === 1) {
        $patient = $search_results[0];
        $search_results = null;
    }
}

// Select specific patient by ID from search list
if (isset($_GET['patient_id']) && intval($_GET['patient_id']) > 0) {
    $pid = intval($_GET['patient_id']);
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ── BOOK APPOINTMENT ────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['book_appt'])) {
    csrf_verify();
    $pat_id = intval($_POST['patient_id'] ?? 0);
    $doc    = trim($_POST['doctor_name'] ?? '');
    $date   = trim($_POST['appt_date'] ?? '');
    $time   = trim($_POST['appt_time'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $notes  = trim($_POST['notes'] ?? '');

    if ($pat_id <= 0 || !$doc || !$date || !$time) {
        $error = "Please fill in patient, doctor, date, and time.";
    } else {
        $stmt = $conn->prepare("INSERT INTO appointments (patient_id, doctor_name, appt_date, appt_time, reason, notes, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'Scheduled', ?)");
        $stmt->bind_param("issssss", $pat_id, $doc, $date, $time, $reason, $notes, $my_user);
        if ($stmt->execute()) {
            $success = "Appointment successfully scheduled with Dr. $doc.";
            audit_log($conn, 'schedule_appointment', 'appointments', $conn->insert_id, "Scheduled with Dr. $doc on $date $time");
            // Clear patient search
            $patient = null;
        } else {
            $error = "Failed to schedule appointment: " . $conn->error;
        }
        $stmt->close();
    }
}

// ── UPDATE APPOINTMENT STATUS ────────────────────────────────────────────────
if (isset($_POST['update_status'])) {
    csrf_verify();
    $appt_id   = intval($_POST['appt_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');
    $valid     = ['Scheduled', 'Completed', 'Cancelled', 'No-show'];

    if ($appt_id > 0 && in_array($newStatus, $valid)) {
        // Lab Techs cannot update appointment status
        if ($isLabTech) {
            $error = "Access denied: Lab Technicians cannot update appointment status.";
        } else {
            $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE appt_id = ?");
            $stmt->bind_param("si", $newStatus, $appt_id);
            if ($stmt->execute()) {
                $success = "Appointment status updated to $newStatus.";
                audit_log($conn, 'update_appointment_status', 'appointments', $appt_id, "Status updated to $newStatus");
            } else {
                $error = "Update failed: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// ── FETCH APPOINTMENTS ───────────────────────────────────────────────────────
$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$sql = "SELECT a.*, p.full_name, p.opd_number, p.phone_number
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.appt_date = ?
        ORDER BY a.appt_time ASC";
$stmt_load = $conn->prepare($sql);
$stmt_load->bind_param("s", $filter_date);
$stmt_load->execute();
$appts = $stmt_load->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_load->close();

// Fetch doctors for scheduling dropdown
$docs = [];
$res_docs = $conn->query("SELECT full_name FROM users WHERE role = 'Doctor' ORDER BY full_name");
if ($res_docs) {
    while ($r = $res_docs->fetch_assoc()) $docs[] = $r['full_name'];
}
if (empty($docs)) {
    $docs = ['Dr. Vincent Otieno', 'Dr. Michael Otieno', 'Dr. John Kamau', 'Dr. Emily Waweru'];
}

$page_title = "Appointments Scheduling";
include 'includes/header.php';
$csrf = csrf_token();
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap');
.aw{width:100%;max-width:1100px;margin:0 auto 48px;font-family:'DM Sans',sans-serif}
.ah{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.ah h1{font-family:'DM Serif Display',serif;font-size:1.85rem;color:#0f172a;margin:0 0 3px;letter-spacing:-.4px}
.ah p{font-size:.8rem;color:#94a3b8;margin:0}
.crumb{display:flex;align-items:center;gap:5px;font-size:.72rem;color:#94a3b8;margin-bottom:18px}
.crumb a{color:#94a3b8;text-decoration:none}.crumb a:hover{color:#1d4ed8}
.layout{display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start}
.panel{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
.phead{padding:13px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.ptitle{font-size:.62rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8}
.pbody{padding:18px}
.f{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.f label{font-size:.73rem;font-weight:600;color:#374151}
.fiw{position:relative}
.fiw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#cbd5e1;font-size:.8rem;pointer-events:none}
.fiw input,.f input,.f select,.f textarea{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:.84rem;font-family:'DM Sans',sans-serif;color:#0f172a;background:#fafafa;outline:none;box-sizing:border-box;transition:border-color .15s}
.fiw input{padding-left:32px}
.fiw input:focus,.f input:focus,.f select:focus,.f textarea:focus{border-color:#3b82f6;background:#fff}
.pf{display:flex;align-items:center;gap:10px;padding:12px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;margin-bottom:16px}
.pfav{width:36px;height:36px;border-radius:50%;background:#eff6ff;color:#1d4ed8;display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:700;font-family:'DM Serif Display',serif}
.pfn{font-size:.84rem;font-weight:600;color:#0f172a;margin:0 0 2px}
.pftags{display:flex;gap:4px;font-size:.68rem;color:#64748b}
.status-badge{font-size:.68rem;font-weight:700;padding:3px 9px;border-radius:12px;text-transform:uppercase}
.sb-Scheduled{background:#dbeafe;color:#1d4ed8}
.sb-Completed{background:#dcfce7;color:#166534}
.sb-Cancelled{background:#fee2e2;color:#991b1b}
.sb-No-show{background:#fef3c7;color:#92400e}
@media(max-width:900px){.layout{grid-template-columns:1fr}}
</style>

<div class="aw">
    <div class="crumb"><a href="dashboard.php"><i class="fa-solid fa-house-chimney"></i></a><span>&rsaquo;</span><span>Appointments</span></div>

    <div class="ah">
        <div>
            <h1>Appointments Scheduling</h1>
            <p>Consultations and sample collections queue manager</p>
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

    <div class="layout">
        <!-- LEFT PANEL: Booking -->
        <div>
            <?php if ($isAdmin || $isReception || $isDoctor): ?>
            <div class="panel shadow-sm mb-4">
                <div class="phead"><span class="ptitle">1. Find Patient</span></div>
                <div class="pbody">
                    <form method="get" action="appointments.php">
                        <div class="f">
                            <label>OPD / File Number</label>
                            <div class="fiw">
                                <i class="fa-solid fa-id-card"></i>
                                <input type="text" name="search_opd" required placeholder="e.g. OPD-2026-001"
                                       value="<?php echo isset($_GET['search_opd']) ? htmlspecialchars($_GET['search_opd']) : ''; ?>">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold py-2">
                            <i class="fa-solid fa-magnifying-glass me-1"></i> Check Patient Record
                        </button>
                    </form>
                </div>
            </div>

            <?php if (!empty($search_results)): ?>
            <div class="panel shadow-sm mb-4">
                <div class="phead"><span class="ptitle">Select Patient</span></div>
                <div class="list-group list-group-flush" style="max-height: 250px; overflow-y: auto;">
                    <?php foreach ($search_results as $sr): ?>
                    <a href="appointments.php?patient_id=<?= $sr['patient_id'] ?>&search_opd=<?= urlencode($_GET['search_opd'] ?? '') ?>" 
                       class="list-group-item list-group-item-action d-flex align-items-center justify-content-between py-2 px-3">
                        <div>
                            <div class="fw-semibold text-dark" style="font-size: .82rem;"><?= htmlspecialchars($sr['full_name']) ?></div>
                            <small class="text-muted" style="font-size: .72rem;"><?= htmlspecialchars($sr['opd_number']) ?> &middot; <?= htmlspecialchars($sr['gender']) ?></small>
                        </div>
                        <span class="btn btn-outline-primary btn-sm rounded-pill py-0 px-2 fw-bold" style="font-size: .7rem;">Select</span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($patient): ?>
            <div class="panel shadow-sm">
                <div class="phead"><span class="ptitle">2. Schedule Details</span></div>
                <div class="pbody">
                    <div class="pf">
                        <div class="pfav"><?= strtoupper(substr($patient['full_name'],0,1)) ?></div>
                        <div>
                            <p class="pfn"><?= htmlspecialchars($patient['full_name']) ?></p>
                            <div class="pftags">
                                <span><?= htmlspecialchars($patient['opd_number']) ?></span>&middot;
                                <span><?= $patient['age'] ?> Yrs</span>&middot;
                                <span><?= $patient['gender'] ?></span>
                            </div>
                        </div>
                    </div>

                    <form method="post" action="appointments.php">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="patient_id" value="<?= $patient['patient_id'] ?>">

                        <div class="f">
                            <label>Assigned Clinician / Doctor</label>
                            <select name="doctor_name" required>
                                <option value="">Select doctor...</option>
                                <?php foreach ($docs as $d): ?>
                                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="f">
                            <label>Appointment Date</label>
                            <input type="date" name="appt_date" required value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="f">
                            <label>Appointment Time</label>
                            <input type="time" name="appt_time" required value="09:00">
                        </div>

                        <div class="f">
                            <label>Reason for Visit</label>
                            <input type="text" name="reason" placeholder="e.g. Lab draw, consultation..." required>
                        </div>

                        <div class="f">
                            <label>Additional Notes</label>
                            <textarea name="notes" rows="2" placeholder="Clinical directives..."></textarea>
                        </div>

                        <button type="submit" name="book_appt" class="btn btn-success btn-sm w-100 fw-bold py-2 mt-2">
                            <i class="fa-solid fa-calendar-check me-1"></i> Book Appointment
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="panel p-3 text-center text-muted small shadow-sm">
                <i class="fa-solid fa-lock fa-2x mb-2 d-block opacity-25"></i>
                Only Recepetionists, Doctors, and Admins can book appointments.
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT PANEL: Appointments List -->
        <div>
            <div class="panel shadow-sm">
                <div class="phead">
                    <span class="ptitle">Appointments List</span>
                    <form method="get" class="d-flex align-items-center gap-2 m-0">
                        <input type="date" name="filter_date" class="form-control form-control-sm py-1 px-2" style="width:135px;font-size:.78rem;"
                               value="<?= htmlspecialchars($filter_date) ?>" onchange="this.form.submit()">
                    </form>
                </div>
                
                <?php if (empty($appts)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-calendar-xmark fa-3x mb-3 opacity-25"></i>
                    <p class="small mb-0">No appointments scheduled for <?= date('d M Y', strtotime($filter_date)) ?>.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Time</th>
                                <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Patient</th>
                                <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Clinician</th>
                                <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Reason / Notes</th>
                                <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Status</th>
                                <?php if (!$isLabTech): ?>
                                <th style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appts as $a): ?>
                            <tr>
                                <td class="fw-bold text-primary" style="font-size:.84rem"><?= date('H:i', strtotime($a['appt_time'])) ?></td>
                                <td>
                                    <div class="fw-semibold" style="font-size:.82rem"><?= htmlspecialchars($a['full_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($a['opd_number']) ?></small>
                                </td>
                                <td style="font-size:.8rem">Dr. <?= htmlspecialchars($a['doctor_name']) ?></td>
                                <td style="font-size:.78rem;max-width:200px;" class="text-truncate">
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($a['reason']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($a['notes'] ?: '—') ?></small>
                                </td>
                                <td>
                                    <span class="status-badge sb-<?= $a['status'] ?>"><?= $a['status'] ?></span>
                                </td>
                                <?php if (!$isLabTech): ?>
                                <td>
                                    <form method="post" action="appointments.php" class="d-flex align-items-center gap-1">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="appt_id" value="<?= $a['appt_id'] ?>">
                                        <select name="status" class="form-select form-select-sm" style="font-size:.75rem;padding:3px 6px;width:110px;" onchange="this.form.submit()">
                                            <option value="">Update...</option>
                                            <option value="Scheduled" <?= $a['status']=='Scheduled'?'disabled':'' ?>>Scheduled</option>
                                            <option value="Completed" <?= $a['status']=='Completed'?'disabled':'' ?>>Completed</option>
                                            <option value="Cancelled" <?= $a['status']=='Cancelled'?'disabled':'' ?>>Cancelled</option>
                                            <option value="No-show" <?= $a['status']=='No-show'?'disabled':'' ?>>No-show</option>
                                        </select>
                                        <input type="hidden" name="update_status" value="1">
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
