<?php
// blood_bank.php
// Blood Bank Transfusion & Crossmatch Management Console
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$role = $_SESSION['role'] ?? '';
$my_user = $_SESSION['full_name'] ?? 'Blood Bank Tech';
$isAdmin = ($role === 'Admin');
$isLabTech = ($role === 'LabTech' || $role === 'Lab Technician' || $role === 'LabTechnician');

// Strict RBAC: Lab Technologists and Administrators ONLY
if (!$isAdmin && !$isLabTech) {
    header("location: dashboard.php"); exit;
}

$success = $error = "";

// ── ACTION: RECEIVE NEW BLOOD UNIT ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_blood_unit'])) {
    csrf_verify();
    $bag     = trim($_POST['bag_number'] ?? '');
    $grp     = trim($_POST['blood_group'] ?? '');
    $comp    = trim($_POST['component_type'] ?? 'Whole Blood');
    $vol     = intval($_POST['volume_ml'] ?? 450);
    $cDate   = trim($_POST['collection_date'] ?? date('Y-m-d'));
    $eDate   = trim($_POST['expiry_date'] ?? date('Y-m-d', strtotime('+35 days')));
    $donor   = trim($_POST['donor_code'] ?? 'KNBTS');
    $tti     = $_POST['tti_screening'] ?? 'Non-Reactive (Passed)';
    $fridge  = trim($_POST['storage_fridge'] ?? 'Blood Bank Fridge 2-6°C');

    if (empty($bag) || empty($grp) || empty($eDate)) {
        $error = "Please provide bag number, blood group, and expiry date.";
    } else {
        $stmt = $conn->prepare("INSERT INTO blood_inventory (bag_number, blood_group, component_type, volume_ml, collection_date, expiry_date, donor_code, tti_screening, storage_fridge, status) VALUES (?,?,?,?,?,?,?,?,?, 'Available')");
        $stmt->bind_param("sssisssss", $bag, $grp, $comp, $vol, $cDate, $eDate, $donor, $tti, $fridge);
        if ($stmt->execute()) {
            audit_log($conn, 'receive_blood', 'blood_inventory', $conn->insert_id, "Received Bag $bag ($grp $comp)");
            $success = "Blood unit '$bag' ($grp) registered into blood bank.";
        } else {
            $error = "Failed to register unit: " . $conn->error;
        }
        $stmt->close();
    }
}

// ── ACTION: RECORD PATIENT CROSSMATCH & TRANSFUSION ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_crossmatch'])) {
    csrf_verify();
    $bagId   = intval($_POST['bag_id'] ?? 0);
    $patId   = intval($_POST['patient_id'] ?? 0);
    $docName = trim($_POST['doctor_name'] ?? 'Clinician');
    $result  = $_POST['crossmatch_result'] ?? 'Compatible';
    $notes   = trim($_POST['notes'] ?? '');
    $markTransfused = isset($_POST['mark_transfused']);

    if ($bagId <= 0 || $patId <= 0) {
        $error = "Please select both a blood unit and a valid patient.";
    } else {
        $stmt = $conn->prepare("INSERT INTO blood_crossmatches (bag_id, patient_id, doctor_name, crossmatch_result, crossmatched_by, notes) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("iissss", $bagId, $patId, $docName, $result, $my_user, $notes);
        if ($stmt->execute()) {
            $newStatus = ($result === 'Compatible') ? ($markTransfused ? 'Transfused' : 'Reserved') : 'Available';
            $conn->query("UPDATE blood_inventory SET status = '$newStatus' WHERE bag_id = $bagId");
            audit_log($conn, 'crossmatch_blood', 'blood_crossmatches', $conn->insert_id, "Bag #$bagId to Patient #$patId ($result - $newStatus)");
            $success = "Crossmatch recorded ($result). Unit status set to $newStatus.";
        } else {
            $error = "Crossmatch failed: " . $conn->error;
        }
        $stmt->close();
    }
}

// ── METRICS & GROUP TALLIES ──────────────────────────────────────────────────
$total_available = $conn->query("SELECT COUNT(*) FROM blood_inventory WHERE status = 'Available' AND expiry_date >= CURDATE()")->fetch_row()[0] ?? 0;
$o_neg_stock     = $conn->query("SELECT COUNT(*) FROM blood_inventory WHERE blood_group = 'O-' AND status = 'Available' AND expiry_date >= CURDATE()")->fetch_row()[0] ?? 0;
$expiring_soon   = $conn->query("SELECT COUNT(*) FROM blood_inventory WHERE status = 'Available' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetch_row()[0] ?? 0;
$total_crossmatched = $conn->query("SELECT COUNT(*) FROM blood_crossmatches")->fetch_row()[0] ?? 0;

// ABO/Rh tallies
$groups = ['O+', 'A+', 'B+', 'AB+', 'O-', 'A-', 'B-', 'AB-'];
$group_counts = [];
foreach ($groups as $g) {
    $q = $conn->query("SELECT COUNT(*) FROM blood_inventory WHERE blood_group = '$g' AND status = 'Available' AND expiry_date >= CURDATE()");
    $group_counts[$g] = $q ? $q->fetch_row()[0] : 0;
}

// Fetch available units
$inv_res = $conn->query("SELECT * FROM blood_inventory WHERE status IN ('Available', 'Reserved') ORDER BY (expiry_date < CURDATE()) DESC, expiry_date ASC");
$inventory = $inv_res ? $inv_res->fetch_all(MYSQLI_ASSOC) : [];

// Fetch crossmatch history (last 15)
$cm_res = $conn->query("
    SELECT cm.*, bi.bag_number, bi.blood_group, bi.component_type, p.opd_number, p.full_name AS patient_name
    FROM blood_crossmatches cm
    JOIN blood_inventory bi ON cm.bag_id = bi.bag_id
    JOIN patients p ON cm.patient_id = p.patient_id
    ORDER BY cm.crossmatch_id DESC LIMIT 20
");
$crossmatches = $cm_res ? $cm_res->fetch_all(MYSQLI_ASSOC) : [];

// Patients for crossmatch dropdown
$p_res = $conn->query("SELECT patient_id, opd_number, full_name, age, gender FROM patients ORDER BY patient_id DESC LIMIT 30");
$patient_list = $p_res ? $p_res->fetch_all(MYSQLI_ASSOC) : [];

$page_title = "Blood Bank & Transfusion Management";
include 'includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');
body { font-family: 'DM Sans', sans-serif; }
.card-kpi { border-radius: 12px; padding: 18px 20px; color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
.group-badge { font-size: 1.1rem; font-weight: 800; font-family: monospace; border-radius: 8px; padding: 4px 10px; }
.bg-o-neg { background: #fee2e2; color: #991b1b; border: 1.5px solid #ef4444; }
.badge-status { font-size: 0.72rem; font-weight: 700; border-radius: 20px; padding: 3px 10px; }
.badge-avail { background: #dcfce7; color: #15803d; }
.badge-res { background: #fef3c7; color: #b45309; }
.badge-trans { background: #dbeafe; color: #1d4ed8; }
</style>

<div class="container py-3">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="fa-solid fa-droplet text-danger me-2"></i>Blood Bank &amp; Transfusion Management</h2>
            <p class="text-muted mb-0" style="font-size:0.88rem;">ABO/Rh inventory tracking, cold chain storage (2-6°C), donor screening, and inpatient crossmatch records</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addBloodModal">
                <i class="fa-solid fa-plus me-1"></i> Receive Blood Unit
            </button>
            <button class="btn btn-outline-primary rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#crossmatchModal">
                <i class="fa-solid fa-code-fork me-1"></i> Issue Crossmatch
            </button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card-kpi" style="background:linear-gradient(135deg,#b91c1c,#ef4444)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:800;"><?= $total_available ?></div>
                        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.9">Units Available</div>
                    </div>
                    <i class="fa-solid fa-droplet fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-kpi" style="background:linear-gradient(135deg,#7f1d1d,#991b1b)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:800;"><?= $o_neg_stock ?></div>
                        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.9">O- Negative (Emergency)</div>
                    </div>
                    <i class="fa-solid fa-heart-pulse fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-kpi" style="background:linear-gradient(135deg,#d97706,#f59e0b)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:800;"><?= $expiring_soon ?></div>
                        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.9">Expiring in &le; 7 Days</div>
                    </div>
                    <i class="fa-solid fa-clock-rotate-left fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card-kpi" style="background:linear-gradient(135deg,#1e40af,#3b82f6)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:800;"><?= $total_crossmatched ?></div>
                        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.9">Total Crossmatches</div>
                    </div>
                    <i class="fa-solid fa-check-double fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Blood Group Tally Grid -->
    <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
        <div class="text-uppercase text-muted fw-bold small mb-2 ps-2">Current Stock by ABO / Rhesus Group:</div>
        <div class="row g-2 text-center">
            <?php foreach ($group_counts as $grp => $cnt): ?>
            <div class="col-3 col-md-3 col-lg">
                <div style="border:1.5px solid #e2e8f0;border-radius:10px;padding:10px 4px;background:#f8fafc;">
                    <div style="font-size:1.15rem;font-weight:900;color:<?= str_contains($grp,'-')?'#991b1b':'#0f172a' ?>"><?= $grp ?></div>
                    <div style="font-size:1.25rem;font-weight:800;color:<?= $cnt>0?'#15803d':'#94a3b8' ?>"><?= $cnt ?></div>
                    <small class="text-muted" style="font-size:0.65rem;">Units</small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tables: Inventory & Crossmatches -->
    <ul class="nav nav-pills mb-3" id="bloodTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active rounded-pill px-4 fw-bold" id="inv-tab" data-bs-toggle="tab" data-bs-target="#inv-content" type="button">
                <i class="fa-solid fa-box-archive me-1"></i> Available Inventory (<?= count($inventory) ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link rounded-pill px-4 fw-bold" id="cm-tab" data-bs-toggle="tab" data-bs-target="#cm-content" type="button">
                <i class="fa-solid fa-clipboard-user me-1"></i> Patient Crossmatches (<?= count($crossmatches) ?>)
            </button>
        </li>
    </ul>

    <div class="tab-content" id="bloodTabsContent">
        <!-- TAB 1: Inventory -->
        <div class="tab-pane fade show active" id="inv-content">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Bag Number</th>
                                <th>Group</th>
                                <th>Component</th>
                                <th>Volume</th>
                                <th>Expiry Date</th>
                                <th>TTI Screening</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($inventory)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">No blood bags in stock currently.</td></tr>
                            <?php else: foreach ($inventory as $b): 
                                $days_left = round((strtotime($b['expiry_date']) - time()) / 86400);
                                $is_crit = ($days_left <= 7);
                            ?>
                            <tr>
                                <td class="ps-4 fw-bold font-monospace text-primary"><?= htmlspecialchars($b['bag_number']) ?></td>
                                <td>
                                    <span class="badge bg-danger-subtle text-danger fw-bold px-2 py-1" style="font-size:0.85rem;">
                                        <?= htmlspecialchars($b['blood_group']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($b['component_type']) ?></td>
                                <td><?= $b['volume_ml'] ?> ml</td>
                                <td>
                                    <div class="<?= $is_crit?'text-danger fw-bold':'text-dark' ?>">
                                        <?= date('d M Y', strtotime($b['expiry_date'])) ?>
                                    </div>
                                    <small class="<?= $is_crit?'text-danger fw-bold':'text-muted' ?>" style="font-size:0.68rem;">
                                        <?= $days_left ?> days remaining
                                    </small>
                                </td>
                                <td><span class="badge bg-success-subtle text-success fw-bold"><i class="fa-solid fa-shield-virus me-1"></i><?= htmlspecialchars($b['tti_screening']) ?></span></td>
                                <td>
                                    <span class="badge-status <?= $b['status']==='Available'?'badge-avail':'badge-res' ?>">
                                        <?= htmlspecialchars($b['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if ($b['status'] === 'Available'): ?>
                                    <button class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" onclick="openCrossmatchWithBag(<?= $b['bag_id'] ?>, '<?= htmlspecialchars($b['bag_number']) ?>', '<?= htmlspecialchars($b['blood_group']) ?>')">
                                        Crossmatch
                                    </button>
                                    <?php else: ?>
                                    <span class="text-muted small">Reserved</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: Crossmatches -->
        <div class="tab-pane fade" id="cm-content">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Date</th>
                                <th>Patient Name</th>
                                <th>OPD Number</th>
                                <th>Blood Unit</th>
                                <th>Group</th>
                                <th>Result</th>
                                <th>Ordering Doctor</th>
                                <th class="pe-4">Crossmatched By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($crossmatches)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">No patient crossmatch records logged yet.</td></tr>
                            <?php else: foreach ($crossmatches as $cm): ?>
                            <tr>
                                <td class="ps-4 text-muted small font-monospace"><?= date('d M Y H:i', strtotime($cm['crossmatch_date'])) ?></td>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($cm['patient_name']) ?></td>
                                <td><span class="badge bg-light text-primary font-monospace"><?= htmlspecialchars($cm['opd_number']) ?></span></td>
                                <td class="font-monospace fw-bold"><?= htmlspecialchars($cm['bag_number']) ?></td>
                                <td><span class="badge bg-danger text-white"><?= htmlspecialchars($cm['blood_group']) ?></span></td>
                                <td>
                                    <span class="badge <?= $cm['crossmatch_result']==='Compatible'?'bg-success':'bg-danger' ?> fw-bold">
                                        <?= htmlspecialchars($cm['crossmatch_result']) ?>
                                    </span>
                                </td>
                                <td>Dr. <?= htmlspecialchars($cm['doctor_name']) ?></td>
                                <td class="pe-4"><small class="text-muted"><?= htmlspecialchars($cm['crossmatched_by']) ?></small></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Add Blood Unit -->
<div class="modal fade" id="addBloodModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-danger text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-droplet me-2"></i>Receive Donor Blood Unit</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="add_blood_unit" value="1">
                <div class="modal-body p-4">
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Bag Number / Barcode</label>
                            <input type="text" name="bag_number" class="form-control" required placeholder="e.g. KNBTS-2026-9901">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Blood Group</label>
                            <select name="blood_group" class="form-select fw-bold text-danger" required>
                                <option value="">Select Group…</option>
                                <option value="O+">O Positive (O+)</option>
                                <option value="O-">O Negative (O-) [Universal]</option>
                                <option value="A+">A Positive (A+)</option>
                                <option value="A-">A Negative (A-)</option>
                                <option value="B+">B Positive (B+)</option>
                                <option value="B-">B Negative (B-)</option>
                                <option value="AB+">AB Positive (AB+)</option>
                                <option value="AB-">AB Negative (AB-)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Component Type</label>
                            <select name="component_type" class="form-select">
                                <option value="Whole Blood">Whole Blood</option>
                                <option value="Packed Red Blood Cells">Packed Red Blood Cells</option>
                                <option value="Fresh Frozen Plasma">Fresh Frozen Plasma</option>
                                <option value="Platelets">Platelets</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Volume (mL)</label>
                            <input type="number" name="volume_ml" class="form-control" value="450" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Collection Date</label>
                            <input type="date" name="collection_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Expiry Date (35 Days)</label>
                            <input type="date" name="expiry_date" class="form-control" value="<?= date('Y-m-d', strtotime('+35 days')) ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Donor Code / Regional Hub</label>
                        <input type="text" name="donor_code" class="form-control" value="KNBTS-Siaya Satellite">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-bold small text-muted text-uppercase">TTI Screening (Infectious Diseases)</label>
                        <select name="tti_screening" class="form-select">
                            <option value="Non-Reactive (Passed)">Non-Reactive / Passed (HIV, HBV, HCV, VDRL Negative)</option>
                            <option value="Reactive (Failed)">Reactive / Failed (Quarantine &amp; Discard)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">Accept Unit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Record Patient Crossmatch -->
<div class="modal fade" id="crossmatchModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-code-fork me-2"></i>Record Patient Crossmatch</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="record_crossmatch" value="1">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Patient (Recipient)</label>
                        <select name="patient_id" class="form-select" required>
                            <option value="">Select Recipient Patient…</option>
                            <?php foreach ($patient_list as $p): ?>
                            <option value="<?= $p['patient_id'] ?>">
                                <?= htmlspecialchars($p['opd_number']) ?> &mdash; <?= htmlspecialchars($p['full_name']) ?> (<?= $p['gender'] ?>, <?= $p['age'] ?>Y)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Blood Bag Unit</label>
                        <select name="bag_id" id="cmBagSelect" class="form-select font-monospace fw-bold" required>
                            <option value="">Select Blood Unit…</option>
                            <?php foreach ($inventory as $b): if ($b['status'] === 'Available'): ?>
                            <option value="<?= $b['bag_id'] ?>">
                                <?= htmlspecialchars($b['bag_number']) ?> &mdash; Group <?= htmlspecialchars($b['blood_group']) ?> (Exp: <?= date('d M', strtotime($b['expiry_date'])) ?>)
                            </option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Ordering Clinician</label>
                            <input type="text" name="doctor_name" class="form-control" value="Dr. Vincent" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Compatibility Result</label>
                            <select name="crossmatch_result" class="form-select fw-bold text-success" required>
                                <option value="Compatible">COMPATIBLE (No Agglutination)</option>
                                <option value="Incompatible">INCOMPATIBLE (Agglutination Seen)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="mark_transfused" id="txCheck">
                        <label class="form-check-label small" for="txCheck">
                            Mark unit as immediately <strong>Transfused to Inpatient</strong>
                        </label>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-bold small text-muted text-uppercase">Crossmatch Notes / Ward</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. Female Ward Bed 4, Post-partum hemorrhage">
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Confirm Crossmatch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openCrossmatchWithBag(bagId, bagNum, bagGrp) {
    const sel = document.getElementById('cmBagSelect');
    if (sel) {
        sel.value = bagId;
    }
    const modal = new bootstrap.Modal(document.getElementById('crossmatchModal'));
    modal.show();
}
</script>

<?php include 'includes/footer.php'; ?>
