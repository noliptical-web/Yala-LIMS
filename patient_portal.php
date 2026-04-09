<?php
// patient_portal.php — standalone, no staff session required
session_start();
require_once 'includes/db_connect.php';

$error = ''; $patient = null; $history = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $opd   = trim($_POST['opd_number'] ?? '');
    $phone = preg_replace('/\D/', '', trim($_POST['phone'] ?? ''));

    if ($opd && $phone) {
        $stmt = $conn->prepare("SELECT * FROM patients WHERE opd_number = ?");
        $stmt->bind_param("s", $opd);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($patient) {
            // Match last 9 digits of phone (handles 07xx vs +2547xx)
            $stored = preg_replace('/\D/', '', $patient['phone_number']);
            if (substr($phone, -9) !== substr($stored, -9)) {
                $patient = null;
                $error = "OPD number and phone number do not match.";
            } else {
                // Load completed results only
                $rs = $conn->prepare(
                    "SELECT r.request_id, r.request_date, r.requested_by,
                            r.payment_status, r.status
                     FROM lab_requests r
                     WHERE r.patient_id = ? AND r.status = 'Completed'
                     ORDER BY r.request_date DESC LIMIT 10"
                );
                $rs->bind_param("i", $patient['patient_id']);
                $rs->execute();
                $reqs = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
                $rs->close();

                foreach ($reqs as &$req) {
                    $ts = $conn->prepare(
                        "SELECT t.test_name, t.units, t.normal_range, tr.result_value
                         FROM test_results tr JOIN lab_tests t ON tr.test_id=t.test_id
                         WHERE tr.request_id = ? AND tr.result_value != 'Pending'"
                    );
                    $ts->bind_param("i", $req['request_id']);
                    $ts->execute();
                    $req['tests'] = $ts->get_result()->fetch_all(MYSQLI_ASSOC);
                    $ts->close();
                    $history[] = $req;
                }
            }
        } else {
            $error = "No patient found with that OPD number.";
        }
    } else {
        $error = "Please enter both OPD number and phone number.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Patient Results — Yala Sub-County Hospital</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Serif+Display&display=swap');
body{background:#f0f4f8;font-family:'DM Sans',sans-serif;min-height:100vh;padding:40px 16px}
.portal-wrap{max-width:680px;margin:0 auto}
.portal-head{text-align:center;margin-bottom:32px}
.portal-head h1{font-family:'DM Serif Display',serif;font-size:1.9rem;color:#0f172a;margin-bottom:6px}
.portal-head p{color:#64748b;font-size:.88rem}
.login-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:28px 30px;margin-bottom:20px}
.f{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.f label{font-size:.73rem;font-weight:600;color:#374151}
.fiw{position:relative}
.fiw i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#cbd5e1;font-size:.82rem;pointer-events:none}
.fiw input{width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:11px 12px 11px 34px;font-size:.88rem;font-family:'DM Sans',sans-serif;color:#0f172a;background:#fafafa;outline:none;transition:border-color .15s;box-sizing:border-box}
.fiw input:focus{border-color:#3b82f6;background:#fff;box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.btn-lookup{width:100%;background:#1d4ed8;color:#fff;border:none;border-radius:9px;padding:12px;font-size:.9rem;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .15s}
.btn-lookup:hover{background:#1e40af}
.toast-err{background:#fef2f2;border:1px solid #fca5a5;border-radius:9px;padding:11px 15px;color:#991b1b;font-size:.84rem;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.pat-banner{background:linear-gradient(135deg,#1e40af,#1d4ed8);border-radius:14px;padding:18px 22px;color:#fff;margin-bottom:18px;display:flex;align-items:center;gap:14px}
.pat-av{width:46px;height:46px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:700;font-family:'DM Serif Display',serif;flex-shrink:0;border:2px solid rgba(255,255,255,.25)}
.pat-name{font-size:1rem;font-weight:700;margin:0 0 4px}
.pbadge{font-size:.68rem;font-weight:600;background:rgba(255,255,255,.2);color:rgba(255,255,255,.95);border-radius:20px;padding:2px 9px;display:inline-block;margin-right:5px}
.result-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:14px}
.rc-head{padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.rc-title{font-size:.85rem;font-weight:600;color:#0f172a}
.rc-meta{font-size:.75rem;color:#94a3b8}
table.rt{width:100%;border-collapse:collapse}
table.rt thead th{padding:9px 16px;font-size:.63rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#94a3b8;text-align:left;border-bottom:1px solid #f1f5f9}
table.rt tbody tr{border-bottom:1px solid #f9fafb}
table.rt td{padding:9px 16px;font-size:.83rem}
.rn{color:#16a34a;font-weight:600}.ra{color:#dc2626;font-weight:700}
.btn-pdf{font-size:.75rem;font-weight:600;background:#f8fafc;color:#374151;border:1px solid #e2e8f0;border-radius:6px;padding:4px 10px;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
.btn-pdf:hover{border-color:#1d4ed8;color:#1d4ed8}
.disclaimer{font-size:.72rem;color:#94a3b8;text-align:center;margin-top:20px;line-height:1.6}
</style>
</head>
<body>
<div class="portal-wrap">
<div class="portal-head">
    <?php if(file_exists('logo.png')): ?><img src="logo.png" height="60" style="margin-bottom:12px"><br><?php endif; ?>
    <h1>Your Lab Results</h1>
    <p>Yala Sub-County Hospital · Department of Laboratory Services</p>
</div>

<?php if(!$patient): ?>
<div class="login-card">
    <?php if($error): ?>
    <div class="toast-err"><i class="fa-solid fa-circle-exclamation"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="f"><label>OPD / File Number</label>
            <div class="fiw"><i class="fa-solid fa-id-card"></i><input type="text" name="opd_number" required placeholder="e.g. OP-2025-001"></div>
        </div>
        <div class="f"><label>Phone Number</label>
            <div class="fiw"><i class="fa-solid fa-phone"></i><input type="text" name="phone" required placeholder="07XX XXX XXX"></div>
        </div>
        <button type="submit" class="btn-lookup"><i class="fa-solid fa-magnifying-glass me-2"></i>View My Results</button>
    </form>
</div>
<p class="disclaimer">Your OPD number is on your hospital file card. Only you (with your registered phone number) can access these results.</p>

<?php else: ?>
<div class="pat-banner">
    <div class="pat-av"><?php echo strtoupper(substr($patient['full_name'],0,1)); ?></div>
    <div>
        <p class="pat-name"><?php echo htmlspecialchars($patient['full_name']); ?></p>
        <span class="pbadge"><?php echo htmlspecialchars($patient['opd_number']); ?></span>
        <span class="pbadge"><?php echo $patient['age']; ?> yrs · <?php echo $patient['gender']; ?></span>
    </div>
</div>

<?php if(empty($history)): ?>
<div style="text-align:center;padding:40px 20px;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px">
    <i class="fa-solid fa-flask fa-2x" style="color:#cbd5e1;margin-bottom:12px;display:block"></i>
    <p style="color:#64748b;font-size:.88rem;margin:0">No completed lab results on record yet.</p>
</div>
<?php else: foreach($history as $req): ?>
<div class="result-card">
    <div class="rc-head">
        <div>
            <div class="rc-title">Request #<?php echo $req['request_id']; ?></div>
            <div class="rc-meta"><?php echo date('d M Y H:i',strtotime($req['request_date'])); ?> · Dr. <?php echo htmlspecialchars($req['requested_by']); ?></div>
        </div>
        <?php if($req['payment_status']==='Paid'): ?>
        <a href="print_report.php?id=<?php echo $req['request_id']; ?>" target="_blank" class="btn-pdf"><i class="fa-solid fa-file-pdf"></i> PDF</a>
        <?php endif; ?>
    </div>
    <?php if(!empty($req['tests'])): ?>
    <table class="rt">
        <thead><tr><th>Test</th><th>Result</th><th>Reference</th></tr></thead>
        <tbody>
        <?php foreach($req['tests'] as $t):
            $ab=stripos($t['result_value'],'pos')!==false||stripos($t['result_value'],'high')!==false||stripos($t['result_value'],'elev')!==false;
        ?>
        <tr>
            <td><?php echo htmlspecialchars($t['test_name']); ?><?php if($t['units']): ?> <small style="color:#94a3b8">(<?php echo $t['units']; ?>)</small><?php endif; ?></td>
            <td class="<?php echo $ab?'ra':'rn'; ?>"><?php if($ab): ?><i class="fa-solid fa-triangle-exclamation" style="font-size:.65rem;margin-right:3px"></i><?php endif; ?><?php echo htmlspecialchars($t['result_value']); ?></td>
            <td style="color:#94a3b8;font-size:.75rem"><?php echo htmlspecialchars($t['normal_range']?:'—'); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endforeach; endif; ?>
<div style="text-align:center;margin-top:16px">
    <a href="patient_portal.php" style="font-size:.78rem;color:#94a3b8;text-decoration:none"><i class="fa-solid fa-arrow-left me-1"></i>Search again</a>
</div>
<p class="disclaimer">These results are for informational purposes only. Please consult your doctor for interpretation and advice.</p>
<?php endif; ?>
</div>
</body>
</html>
