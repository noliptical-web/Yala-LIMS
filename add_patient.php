<?php
ob_start();
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin'])) { header("location: index.php"); exit; }

$message = $message_type = "";
$new_patient_id = 0;
$registered_patient = null;

// Check which insurance columns actually exist in patients table
function ap_col($conn, $col) {
    $r = $conn->query("SELECT COUNT(*) as n FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='patients' AND COLUMN_NAME='$col'");
    return $r && $r->fetch_assoc()['n'] > 0;
}
$has_ins_provider = ap_col($conn, 'insurance_provider');
$has_ins_member   = ap_col($conn, 'insurance_member_no');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_verify();
    $opd    = trim($_POST['opd_number']);
    $name   = trim($_POST['full_name']);
    $age    = intval($_POST['age']);
    $gender = $_POST['gender'] ?? '';
    $phone  = trim($_POST['phone']);
    $ins_p  = trim($_POST['insurance_provider'] ?? '');
    $ins_m  = trim($_POST['insurance_member_no'] ?? '');

    $chk = $conn->prepare("SELECT patient_id FROM patients WHERE opd_number=?");
    $chk->bind_param("s", $opd); $chk->execute(); $chk->store_result();

    if ($chk->num_rows > 0) {
        $message = "OPD number already exists. Please choose or verify the OPD number.";
        $message_type = "error";
    } else {
        // Build INSERT only with columns that exist
        if ($has_ins_provider && $has_ins_member) {
            $s2 = $conn->prepare("INSERT INTO patients (opd_number,full_name,age,gender,phone_number,insurance_provider,insurance_member_no) VALUES (?,?,?,?,?,?,?)");
            if ($s2) $s2->bind_param("ssissss", $opd, $name, $age, $gender, $phone, $ins_p, $ins_m);
        } elseif ($has_ins_provider) {
            $s2 = $conn->prepare("INSERT INTO patients (opd_number,full_name,age,gender,phone_number,insurance_provider) VALUES (?,?,?,?,?,?)");
            if ($s2) $s2->bind_param("ssisss", $opd, $name, $age, $gender, $phone, $ins_p);
        } else {
            $s2 = $conn->prepare("INSERT INTO patients (opd_number,full_name,age,gender,phone_number) VALUES (?,?,?,?,?)");
            if ($s2) $s2->bind_param("ssiss", $opd, $name, $age, $gender, $phone);
        }

        if (!isset($s2) || !$s2) {
            $message = "DB Error: " . $conn->error; $message_type = "error";
        } elseif ($s2->execute()) {
            $new_patient_id = $conn->insert_id;
            audit_log($conn, 'register_patient', 'patients', $new_patient_id, $opd.' — '.$name);
            $message = "Patient registered successfully!";
            $message_type = "success";

            // Fetch details for instant on-screen routing confirmation
            $pst = $conn->prepare("SELECT * FROM patients WHERE patient_id=?");
            $pst->bind_param("i", $new_patient_id);
            $pst->execute();
            $registered_patient = $pst->get_result()->fetch_assoc();
            $pst->close();
        } else {
            $message = "Error: " . $conn->error; $message_type = "error";
        }
        if (isset($s2) && $s2) $s2->close();
    }
    $chk->close();
}

// Auto-calculate Next Suggested OPD Number
$year = date('Y');
$suggested_opd = "OPD-$year-001";
$opd_res = $conn->query("SELECT opd_number FROM patients ORDER BY patient_id DESC LIMIT 1");
if ($opd_res && $lr = $opd_res->fetch_assoc()) {
    if (preg_match('/(\d+)$/', $lr['opd_number'], $m)) {
        $next_seq = intval($m[1]) + 1;
        $suggested_opd = sprintf("OPD-%s-%03d", $year, $next_seq);
    }
}

// Fetch Recently Registered Patients (Last 6)
$recent_patients = [];
$r_res = $conn->query("SELECT patient_id, opd_number, full_name, age, gender, insurance_provider, registered_at FROM patients ORDER BY patient_id DESC LIMIT 6");
if ($r_res) {
    $recent_patients = $r_res->fetch_all(MYSQLI_ASSOC);
}

// Insurance providers list
$ins_names = [];
$tbl_check = $conn->query("SHOW TABLES LIKE 'insurance_providers'");
if ($tbl_check && $tbl_check->num_rows > 0) {
    $il = $conn->query("SELECT name FROM insurance_providers WHERE active=1 ORDER BY name");
    if ($il) while ($r = $il->fetch_assoc()) $ins_names[] = $r['name'];
}
if (empty($ins_names)) {
    $ins_names = ['NHIF / SHA','AAR Insurance','Jubilee Insurance','Britam','Madison Insurance','CIC Insurance','Sanlam','Equity Afia','Other'];
}

$page_title = "Register Patient";
include 'includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Serif+Display&display=swap');
.rw{max-width:860px;margin:0 auto;padding:0 0 48px;font-family:'DM Sans',sans-serif}
.rh{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.rh h1{font-family:'DM Serif Display',serif;font-size:1.85rem;color:#0f172a;margin:0 0 3px;letter-spacing:-.4px}
.rh p{font-size:.8rem;color:#94a3b8;margin:0}
.crumb{display:flex;align-items:center;gap:5px;font-size:.72rem;color:#94a3b8;margin-bottom:18px}
.crumb a{color:#94a3b8;text-decoration:none}.crumb a:hover{color:#1d4ed8}
.toast{display:flex;align-items:center;gap:10px;padding:11px 15px;border-radius:9px;margin-bottom:22px;font-size:.84rem;font-weight:500}
.toast.success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.toast.error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.tic{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0}
.toast.success .tic{background:#dcfce7;color:#16a34a}.toast.error .tic{background:#fee2e2;color:#dc2626}
.card-rp{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;overflow:hidden;width:100%}
.sec{padding:22px 28px;border-bottom:1px solid #f1f5f9}.sec:last-of-type{border-bottom:none}
.sec-lbl{font-size:.63rem;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:#94a3b8;margin-bottom:14px;display:flex;align-items:center;gap:6px}
.sec-badge{font-size:.58rem;font-weight:700;background:#f0fdf4;color:#16a34a;border:1px solid #86efac;border-radius:20px;padding:1px 7px}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.f{display:flex;flex-direction:column;gap:5px}
.f label{font-size:.73rem;font-weight:600;color:#374151}
.fiw{position:relative}
.fiw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#cbd5e1;font-size:.8rem;pointer-events:none}
.fiw input{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:10px 12px 10px 32px;font-size:.86rem;font-family:'DM Sans',sans-serif;color:#0f172a;background:#fafafa;outline:none;box-sizing:border-box;transition:border-color .15s}
.fiw input:focus{border-color:#3b82f6;background:#fff}
.fiw input::placeholder{color:#cbd5e1}
.f input,.f select{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-size:.86rem;font-family:'DM Sans',sans-serif;color:#0f172a;background:#fafafa;outline:none;box-sizing:border-box;transition:border-color .15s;appearance:none}
.f input:focus,.f select:focus{border-color:#3b82f6;background:#fff}
.grow{display:flex;gap:8px}
.gopt{display:none}
.glbl{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.8rem;font-weight:500;color:#64748b;cursor:pointer;background:#fafafa;transition:all .15s}
.glbl:hover{border-color:#93c5fd;color:#1d4ed8;background:#eff6ff}
.gopt:checked+.glbl{border-color:#3b82f6;border-width:2px;background:#eff6ff;color:#1d4ed8;font-weight:600}
.ins-toggle{display:flex;align-items:center;gap:8px;font-size:.82rem;color:#374151;cursor:pointer;user-select:none;padding:10px 0}
.ins-toggle input{width:16px;height:16px;accent-color:#7c3aed;cursor:pointer;flex-shrink:0}
.ins-fields{display:none}
.ins-fields.open{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px}
.act{display:flex;gap:10px;padding:18px 28px;background:#f8fafc;border-top:1px solid #f1f5f9}
.bsave{flex:1;background:#1d4ed8;color:#fff;border:none;border-radius:8px;padding:11px 20px;font-size:.88rem;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:background .15s}
.bsave:hover{background:#1e40af}
.bback{background:#fff;color:#64748b;border:1.5px solid #e2e8f0;border-radius:8px;padding:11px 18px;font-size:.84rem;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .15s}
.bback:hover{background:#f8fafc;color:#374151}
@media(max-width:640px){.fg2,.ins-fields.open{grid-template-columns:1fr}.sec{padding:18px 16px}.act{padding:14px 16px;flex-direction:column-reverse}}
</style>

<div class="rw">
<div class="crumb"><a href="dashboard.php"><i class="fa-solid fa-house-chimney"></i></a><span>›</span><span>Register Patient</span></div>
<div class="rh">
    <div><h1>New Patient</h1><p>Create an OPD file record and generate consultation routing slip</p></div>
    <span style="font-size:.7rem;font-weight:700;background:#f0fdf4;color:#16a34a;border:1px solid #86efac;border-radius:20px;padding:4px 11px">
        <i class="fa-solid fa-circle-dot" style="font-size:.55rem;margin-right:3px"></i>Reception Open
    </span>
</div>

<?php if ($registered_patient): ?>
<!-- Immediate Confirmation & Print Slip Banner -->
<div style="background:#f0fdf4;border:2px solid #86efac;border-radius:14px;padding:20px 24px;margin-bottom:28px;box-shadow:0 4px 15px rgba(22,163,74,0.08)">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:16px">
        <div style="display:flex;align-items:center;gap:12px">
            <div style="width:44px;height:44px;background:#dcfce7;color:#16a34a;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0">
                <i class="fa-solid fa-check-double"></i>
            </div>
            <div>
                <h3 style="font-size:1.1rem;font-weight:800;color:#166534;margin:0 0 2px">Patient Registered Successfully!</h3>
                <p style="font-size:.8rem;color:#15803d;margin:0">OPD Consultation Card is generated and ready to hand to the patient</p>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="print_routing_slip.php?patient_id=<?php echo $registered_patient['patient_id']; ?>&autoprint=1" target="_blank" class="btn btn-success fw-bold px-3 py-2 rounded-pill shadow-sm" style="display:inline-flex;align-items:center;gap:7px;font-size:.84rem;text-decoration:none">
                <i class="fa-solid fa-print"></i> Print OPD Slip (For Doctor)
            </a>
            <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'Doctor' || $_SESSION['role'] === 'Admin')): ?>
            <a href="request_test.php?search=<?php echo urlencode($registered_patient['opd_number']); ?>" class="btn btn-primary fw-bold px-3 py-2 rounded-pill shadow-sm" style="display:inline-flex;align-items:center;gap:7px;font-size:.84rem;text-decoration:none">
                <i class="fa-solid fa-microscope"></i> Order Tests (CPOE)
            </a>
            <?php else: ?>
            <a href="appointments.php?search_opd=<?php echo urlencode($registered_patient['opd_number']); ?>" class="btn btn-outline-primary fw-bold px-3 py-2 rounded-pill shadow-sm" style="display:inline-flex;align-items:center;gap:7px;font-size:.84rem;text-decoration:none">
                <i class="fa-solid fa-calendar-plus"></i> Book Appointment
            </a>
            <a href="add_patient.php" class="btn btn-outline-secondary fw-bold px-3 py-2 rounded-pill shadow-sm" style="display:inline-flex;align-items:center;gap:7px;font-size:.84rem;text-decoration:none">
                <i class="fa-solid fa-user-plus"></i> Register Next
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div style="background:#fff;border:1px solid #bbf7d0;border-radius:10px;padding:12px 18px;display:grid;grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));gap:12px">
        <div>
            <span style="font-size:.65rem;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.5px;display:block">OPD File No</span>
            <span style="font-size:1rem;font-weight:800;color:#0f172a"><?php echo htmlspecialchars($registered_patient['opd_number']); ?></span>
        </div>
        <div>
            <span style="font-size:.65rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block">Patient Name</span>
            <span style="font-size:.95rem;font-weight:700;color:#0f172a"><?php echo htmlspecialchars($registered_patient['full_name']); ?></span>
        </div>
        <div>
            <span style="font-size:.65rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block">Age / Gender</span>
            <span style="font-size:.88rem;font-weight:600;color:#334155"><?php echo $registered_patient['age']; ?> Y · <?php echo $registered_patient['gender']; ?></span>
        </div>
        <div>
            <span style="font-size:.65rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block">Coverage / Scheme</span>
            <span style="font-size:.88rem;font-weight:600;color:#334155"><?php echo htmlspecialchars($registered_patient['insurance_provider'] ?: 'Cash Paying'); ?></span>
        </div>
    </div>
</div>
<?php elseif ($message): ?>
<div class="toast <?php echo $message_type; ?>">
    <div class="tic"><i class="fa-solid <?php echo $message_type==='success'?'fa-check':'fa-xmark'; ?>"></i></div>
    <span><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>

<form method="post">
<?php csrf_field(); ?>
<div class="card-rp">

    <div class="sec">
        <div class="sec-lbl">Identity</div>
        <div class="fg2">
            <div class="f"><label>OPD / File Number (Auto-Suggested)</label>
                <div class="fiw"><i class="fa-solid fa-id-card"></i>
                    <input type="text" name="opd_number" required value="<?php echo htmlspecialchars($suggested_opd); ?>">
                </div>
            </div>
            <div class="f"><label>Full Name</label>
                <div class="fiw"><i class="fa-solid fa-user"></i>
                    <input type="text" name="full_name" required placeholder="Surname First Name" autofocus>
                </div>
            </div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-lbl">Demographics</div>
        <div class="fg2" style="margin-bottom:14px">
            <div class="f"><label>Age (Years)</label>
                <div class="fiw"><i class="fa-solid fa-cake-candles"></i>
                    <input type="number" name="age" required min="0" max="120" placeholder="e.g. 35">
                </div>
            </div>
            <div class="f"><label>Phone Number</label>
                <div class="fiw"><i class="fa-solid fa-phone"></i>
                    <input type="text" name="phone" placeholder="07XX XXX XXX">
                </div>
            </div>
        </div>
        <div class="f"><label>Gender</label>
            <div class="grow">
                <input type="radio" class="gopt" name="gender" id="gm" value="Male" required>
                <label class="glbl" for="gm"><i class="fa-solid fa-mars"></i> Male</label>
                <input type="radio" class="gopt" name="gender" id="gf" value="Female">
                <label class="glbl" for="gf"><i class="fa-solid fa-venus"></i> Female</label>
                <input type="radio" class="gopt" name="gender" id="go" value="Other">
                <label class="glbl" for="go"><i class="fa-solid fa-genderless"></i> Other</label>
            </div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-lbl">Insurance <span class="sec-badge">Optional</span></div>
        <label class="ins-toggle">
            <input type="checkbox" id="insToggle" onchange="document.getElementById('insFields').classList.toggle('open',this.checked)">
            Patient has insurance cover (SHA / NHIF / Private)
        </label>
        <div class="ins-fields" id="insFields">
            <div class="f"><label>Insurance Provider</label>
                <select name="insurance_provider">
                    <option value="">Select provider…</option>
                    <?php foreach ($ins_names as $ins): ?>
                    <option value="<?php echo htmlspecialchars($ins); ?>"><?php echo htmlspecialchars($ins); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="f"><label>Member / Card Number</label>
                <input type="text" name="insurance_member_no" placeholder="e.g. SHA-1234567890">
            </div>
        </div>
    </div>

    <div class="act">
        <a href="dashboard.php" class="bback"><i class="fa-solid fa-arrow-left"></i> Cancel</a>
        <button type="submit" class="bsave"><i class="fa-solid fa-floppy-disk"></i> Save &amp; Generate Consultation Slip</button>
    </div>
</div>
</form>

<!-- Recently Registered Patients Queue -->
<?php if (!empty($recent_patients)): ?>
<div style="margin-top:36px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <h3 style="font-size:.95rem;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:7px">
            <i class="fa-solid fa-clock-rotate-left" style="color:#3b82f6"></i> Recently Registered Patients
        </h3>
        <span style="font-size:.72rem;color:#94a3b8">Latest Check-ins</span>
    </div>

    <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:.7rem;text-transform:uppercase;letter-spacing:.8px;text-align:left">
                    <th style="padding:10px 16px">OPD Number</th>
                    <th style="padding:10px 16px">Patient Name</th>
                    <th style="padding:10px 16px">Age / Gender</th>
                    <th style="padding:10px 16px">Coverage</th>
                    <th style="padding:10px 16px">Time</th>
                    <th style="padding:10px 16px;text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_patients as $rp): ?>
                <tr style="border-bottom:1px solid #f1f5f9;transition:background .1s" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                    <td style="padding:10px 16px;font-weight:700;font-family:monospace;color:#1d4ed8"><?php echo htmlspecialchars($rp['opd_number']); ?></td>
                    <td style="padding:10px 16px;font-weight:700;color:#0f172a"><?php echo htmlspecialchars($rp['full_name']); ?></td>
                    <td style="padding:10px 16px;color:#475569"><?php echo $rp['age']; ?> Y · <?php echo $rp['gender']; ?></td>
                    <td style="padding:10px 16px"><span style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:20px;padding:2px 8px;font-size:.72rem;font-weight:600;color:#475569"><?php echo htmlspecialchars($rp['insurance_provider'] ?: 'Cash'); ?></span></td>
                    <td style="padding:10px 16px;color:#94a3b8;font-size:.75rem"><?php echo date('d M H:i', strtotime($rp['registered_at'])); ?></td>
                    <td style="padding:10px 16px;text-align:right;white-space:nowrap">
                        <a href="print_routing_slip.php?patient_id=<?php echo $rp['patient_id']; ?>" target="_blank" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;border-radius:6px;padding:4px 9px;text-decoration:none;font-size:.72rem;font-weight:700;display:inline-flex;align-items:center;gap:4px;margin-right:4px">
                            <i class="fa-solid fa-print"></i> Slip
                        </a>
                        <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'Doctor' || $_SESSION['role'] === 'Admin')): ?>
                        <a href="request_test.php?search=<?php echo urlencode($rp['opd_number']); ?>" style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;border-radius:6px;padding:4px 9px;text-decoration:none;font-size:.72rem;font-weight:700;display:inline-flex;align-items:center;gap:4px">
                            <i class="fa-solid fa-flask"></i> Order
                        </a>
                        <?php else: ?>
                        <a href="appointments.php?search_opd=<?php echo urlencode($rp['opd_number']); ?>" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;border-radius:6px;padding:4px 9px;text-decoration:none;font-size:.72rem;font-weight:700;display:inline-flex;align-items:center;gap:4px">
                            <i class="fa-solid fa-calendar-plus"></i> Appt
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

</div>
<?php include 'includes/footer.php'; ?>