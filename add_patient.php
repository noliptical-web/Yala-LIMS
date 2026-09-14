<?php
ob_start();
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin'])) { header("location: index.php"); exit; }

$message = $message_type = "";

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
        $message = "OPD number already exists."; $message_type = "error";
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
            $message = "Patient registered successfully."; $message_type = "success";
        } else {
            $message = "Error: " . $conn->error; $message_type = "error";
        }
        if (isset($s2) && $s2) $s2->close();
    }
    $chk->close();
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
    <div><h1>New Patient</h1><p>Create an OPD file record</p></div>
    <span style="font-size:.7rem;font-weight:700;background:#f0fdf4;color:#16a34a;border:1px solid #86efac;border-radius:20px;padding:4px 11px">
        <i class="fa-solid fa-circle-dot" style="font-size:.55rem;margin-right:3px"></i>Reception Open
    </span>
</div>

<?php if ($message): ?>
<div class="toast <?php echo $message_type; ?>" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div style="display:flex;align-items:center;gap:10px">
        <div class="tic"><i class="fa-solid <?php echo $message_type==='success'?'fa-check':'fa-xmark'; ?>"></i></div>
        <span><?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php if(!empty($new_patient_id)): ?>
    <a href="print_routing_slip.php?patient_id=<?php echo $new_patient_id; ?>" target="_blank" class="btn btn-sm btn-success rounded-pill px-3 fw-bold shadow-sm" style="text-decoration:none;font-size:.78rem;display:inline-flex;align-items:center;gap:6px">
        <i class="fa-solid fa-print"></i> Print OPD Slip for Doctor
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="post">
<?php csrf_field(); ?>
<div class="card-rp">

    <div class="sec">
        <div class="sec-lbl">Identity</div>
        <div class="fg2">
            <div class="f"><label>OPD / File Number</label>
                <div class="fiw"><i class="fa-solid fa-id-card"></i>
                    <input type="text" name="opd_number" required placeholder="OP-2025-001">
                </div>
            </div>
            <div class="f"><label>Full Name</label>
                <div class="fiw"><i class="fa-solid fa-user"></i>
                    <input type="text" name="full_name" required placeholder="Surname First Name">
                </div>
            </div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-lbl">Demographics</div>
        <div class="fg2" style="margin-bottom:14px">
            <div class="f"><label>Age (Years)</label>
                <div class="fiw"><i class="fa-solid fa-cake-candles"></i>
                    <input type="number" name="age" required min="0" max="120" placeholder="—">
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
        <button type="submit" class="bsave"><i class="fa-solid fa-floppy-disk"></i> Save Record</button>
    </div>
</div>
</form>
</div>
<?php include 'includes/footer.php'; ?>