<?php
// qc_log.php
// Laboratory Quality Control (QC), Temperature & Equipment Calibration Log (ISO 15189 / MOH Standards)
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$role = $_SESSION['role'] ?? '';
$my_user = $_SESSION['full_name'] ?? 'Lab Tech';
$isAdmin = ($role === 'Admin');
$isLabTech = ($role === 'LabTech' || $role === 'Lab Technician' || $role === 'LabTechnician');

// Strict RBAC: Lab Technologists and Administrators ONLY
if (!$isAdmin && !$isLabTech) {
    header("location: dashboard.php"); exit;
}

$success = $error = "";

// ── ACTION: ADD QC / TEMPERATURE LOG ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_qc_log'])) {
    csrf_verify();
    $equip    = trim($_POST['equipment_name'] ?? '');
    $param    = trim($_POST['parameter_tested'] ?? '');
    $expRange = trim($_POST['expected_range'] ?? '');
    $val      = trim($_POST['recorded_value'] ?? '');
    $status   = $_POST['status'] ?? 'Compliant';
    $action   = trim($_POST['corrective_action'] ?? '');
    $logDate  = trim($_POST['log_date'] ?? date('Y-m-d'));

    if (empty($equip) || empty($param) || empty($val)) {
        $error = "Please fill in equipment, parameter, and recorded value.";
    } else {
        $stmt = $conn->prepare("INSERT INTO qc_equipment_logs (equipment_name, parameter_tested, expected_range, recorded_value, status, logged_by, corrective_action, log_date) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssssssss", $equip, $param, $expRange, $val, $status, $my_user, $action, $logDate);
        if ($stmt->execute()) {
            audit_log($conn, 'add_qc_log', 'qc_equipment_logs', $conn->insert_id, "$equip ($param = $val: $status)");
            $success = "QC record for '$equip' logged successfully.";
        } else {
            $error = "Error saving QC log: " . $conn->error;
        }
        $stmt->close();
    }
}

// ── METRICS ──────────────────────────────────────────────────────────────────
$today = date('Y-m-d');
$logs_today_cnt = $conn->query("SELECT COUNT(*) FROM qc_equipment_logs WHERE log_date = '$today'")->fetch_row()[0] ?? 0;
$out_of_range_cnt = $conn->query("SELECT COUNT(*) FROM qc_equipment_logs WHERE status != 'Compliant' AND log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_row()[0] ?? 0;
$total_qc_runs = $conn->query("SELECT COUNT(*) FROM qc_equipment_logs")->fetch_row()[0] ?? 0;

// Fetch last 30 logs
$res = $conn->query("SELECT * FROM qc_equipment_logs ORDER BY log_date DESC, log_id DESC LIMIT 40");
$logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$standard_equipment = [
    'Reagent Refrigerator A (2-8°C)' => ['Internal Temperature', '2.0°C - 8.0°C'],
    'Blood Transfusion Bank Fridge (2-6°C)' => ['Internal Temperature', '2.0°C - 6.0°C'],
    'Deep Freezer Unit (-20°C)' => ['Cold Storage Temp', '-18.0°C to -22.0°C'],
    'Mindray BC-3000 Hematology Analyzer' => ['Daily 3-Part Commercial Control', 'PASS (WBC, RBC, PLT within 2SD)'],
    'Clinical Chemistry Humalyzer 3000' => ['Daily Calibration & Water Blank', 'Absorbance within ±0.005'],
    'Olympus CX23 Microscope' => ['Optics Cleanliness & Centering', 'Clean (No fungal/oil residue)'],
    'Clinical Centrifuge (Hematocrit/Urine)' => ['Rotor Speed & Timer Check', '3000 RPM (Timer accurate)']
];

$page_title = "Laboratory Quality Control & Equipment Logs";
include 'includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');
body { font-family: 'DM Sans', sans-serif; }
.card-kpi { border-radius: 12px; padding: 18px 20px; color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
.badge-qc { font-size: 0.72rem; font-weight: 700; border-radius: 20px; padding: 3px 10px; }
.badge-comp { background: #dcfce7; color: #15803d; }
.badge-oor { background: #fee2e2; color: #dc2626; }
.badge-serv { background: #fef3c7; color: #b45309; }
</style>

<div class="container py-3">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="fa-solid fa-clipboard-check text-primary me-2"></i>Quality Control (QC) &amp; Equipment Maintenance Log</h2>
            <p class="text-muted mb-0" style="font-size:0.88rem;">Daily cold storage temperature records, analyzer calibration controls, and ISO 15189 compliance audit trail</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addQcModal">
                <i class="fa-solid fa-plus me-1"></i> Log Daily QC / Temp
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
        <div class="col-6 col-md-4">
            <div class="card-kpi" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:800;"><?= $logs_today_cnt ?></div>
                        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.9">Checks Recorded Today</div>
                    </div>
                    <i class="fa-solid fa-temperature-half fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card-kpi" style="background:linear-gradient(135deg,<?= $out_of_range_cnt>0?'#dc2626,#ef4444':'#16a34a,#22c55e' ?>)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:800;"><?= $out_of_range_cnt ?></div>
                        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.9">Out of Range (Last 7 Days)</div>
                    </div>
                    <i class="fa-solid <?= $out_of_range_cnt>0?'fa-triangle-exclamation':'fa-check-double' ?> fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card-kpi" style="background:linear-gradient(135deg,#0f766e,#14b8a6)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:800;"><?= $total_qc_runs ?></div>
                        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;opacity:0.9">Total Historical QC Records</div>
                    </div>
                    <i class="fa-solid fa-list-check fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white py-3 px-4 d-flex align-items-center justify-content-between">
            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Quality Control &amp; Temperature Audit Log</h5>
            <span class="badge bg-light text-muted border">ISO 15189 Standard</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Date / Time</th>
                        <th>Equipment / Apparatus</th>
                        <th>Parameter Checked</th>
                        <th>Expected Target</th>
                        <th>Recorded Value</th>
                        <th>QC Status</th>
                        <th>Logged By</th>
                        <th class="pe-4">Corrective Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">No QC records logged yet.</td></tr>
                    <?php else: foreach ($logs as $l): 
                        $is_comp = ($l['status'] === 'Compliant');
                        $is_serv = ($l['status'] === 'Service Required');
                    ?>
                    <tr>
                        <td class="ps-4 text-muted fw-bold font-monospace" style="font-size:0.78rem;">
                            <?= date('d M Y', strtotime($l['log_date'])) ?>
                        </td>
                        <td class="fw-bold text-dark"><?= htmlspecialchars($l['equipment_name']) ?></td>
                        <td><span class="text-secondary"><?= htmlspecialchars($l['parameter_tested']) ?></span></td>
                        <td><span class="badge bg-light text-secondary border font-monospace"><?= htmlspecialchars($l['expected_range']) ?></span></td>
                        <td><strong class="<?= $is_comp?'text-success':($is_serv?'text-warning':'text-danger') ?>"><?= htmlspecialchars($l['recorded_value']) ?></strong></td>
                        <td>
                            <?php if ($is_comp): ?>
                                <span class="badge-qc badge-comp"><i class="fa-solid fa-check me-1"></i>Compliant</span>
                            <?php elseif ($is_serv): ?>
                                <span class="badge-qc badge-serv"><i class="fa-solid fa-wrench me-1"></i>Service Req</span>
                            <?php else: ?>
                                <span class="badge-qc badge-oor"><i class="fa-solid fa-triangle-exclamation me-1"></i>Out of Range</span>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?= htmlspecialchars($l['logged_by']) ?></small></td>
                        <td class="pe-4"><small class="text-muted"><?= htmlspecialchars($l['corrective_action'] ?: 'None required') ?></small></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Log Daily QC / Temp Check -->
<div class="modal fade" id="addQcModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-clipboard-check me-2"></i>Record Quality Control / Temp Check</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="add_qc_log" value="1">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Equipment / Instrument</label>
                        <select name="equipment_name" id="eqSelect" class="form-select" required onchange="updateEquipDefaults()">
                            <option value="">Select Equipment…</option>
                            <?php foreach ($standard_equipment as $eq => $details): ?>
                            <option value="<?= htmlspecialchars($eq) ?>" data-param="<?= htmlspecialchars($details[0]) ?>" data-range="<?= htmlspecialchars($details[1]) ?>">
                                <?= htmlspecialchars($eq) ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="Other">Other Equipment / Bench</option>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Parameter Tested</label>
                            <input type="text" name="parameter_tested" id="paramField" class="form-control" required placeholder="e.g. Temperature">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Expected Range / Target</label>
                            <input type="text" name="expected_range" id="rangeField" class="form-control" required placeholder="e.g. 2.0°C - 8.0°C">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Recorded Value</label>
                            <input type="text" name="recorded_value" class="form-control" required placeholder="e.g. 4.1°C or PASS">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">QC Status</label>
                            <select name="status" class="form-select" required>
                                <option value="Compliant">Compliant (PASS)</option>
                                <option value="Out of Range">Out of Range (FAIL)</option>
                                <option value="Service Required">Service Required</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Date of Check</label>
                        <input type="date" name="log_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-bold small text-muted text-uppercase">Corrective Action Taken (If Any)</label>
                        <input type="text" name="corrective_action" class="form-control" placeholder="e.g. Thermostat adjusted, repeat calibration run, lens cleaned">
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save QC Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateEquipDefaults() {
    const sel = document.getElementById('eqSelect');
    const opt = sel.options[sel.selectedIndex];
    const param = opt.getAttribute('data-param') || '';
    const range = opt.getAttribute('data-range') || '';
    if (param) document.getElementById('paramField').value = param;
    if (range) document.getElementById('rangeField').value = range;
}
</script>

<?php include 'includes/footer.php'; ?>
