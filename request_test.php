<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/notifications_helper.php';
if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] !== 'Doctor' && $_SESSION['role'] !== 'Admin')) {
    header("location: dashboard.php");
    exit;
}
$success=$error=""; $patient=null; $doctor_name=$_SESSION['full_name']??'Doctor';

if ($_SERVER["REQUEST_METHOD"]=="POST"&&isset($_POST['submit_order'])) {
    $patient_id=intval($_POST['patient_id']); $tests=$_POST['tests']??[];
    if ($patient_id<=0||empty($tests)) { $error="Select a patient and at least one test."; }
    else {
        $dc=$conn->prepare("SELECT COUNT(*) FROM lab_requests WHERE patient_id=? AND status IN ('Pending','In Progress')");
        $dc->bind_param("i",$patient_id); $dc->execute(); $dc->bind_result($dup); $dc->fetch(); $dc->close();
        if ($dup>0) { $error="Patient already has an active request in progress."; }
        else {
            $stmt=$conn->prepare("INSERT INTO lab_requests (patient_id,requested_by,status,payment_status) VALUES (?,?,'Pending','Unpaid')");
            $stmt->bind_param("is",$patient_id,$doctor_name);
            if ($stmt->execute()) {
                $rid=$conn->insert_id; $uid=intval($_SESSION['id']??1);

                // Insert test_results rows AND calculate total cost
                $total_cost = 0.0;
                $s2=$conn->prepare("INSERT INTO test_results (request_id,test_id,result_value,entered_by) VALUES (?,?,'Pending',?)");
                foreach($tests as $tid){
                    $tid=intval($tid);
                    $s2->bind_param("iii",$rid,$tid,$uid); $s2->execute();
                    $cr=$conn->query("SELECT cost FROM lab_tests WHERE test_id=$tid");
                    if($cr && $row=$cr->fetch_assoc()) $total_cost += floatval($row['cost']);
                }
                $s2->close();

                // Fetch patient to get insurance info
                $sp=$conn->prepare("SELECT * FROM patients WHERE patient_id=?"); $sp->bind_param("i",$patient_id); $sp->execute();
                $patient=$sp->get_result()->fetch_assoc(); $sp->close();

                $ins_provider = $patient['insurance_provider'] ?? '';

                // ── INSERT into payments using YOUR ACTUAL schema ─────────────
                // Your payments table columns (from debug):
                //   payment_id, request_id, amount_paid, payment_method,
                //   payment_type (enum Cash/Insurance/Split), insurer_name,
                //   insurer_amount, patient_copay, claim_ref, reference_no,
                //   payment_date (auto timestamp), insurance_provider,
                //   insurance_member_no, insurance_claim_no, waiver_reason,
                //   copay_amount, excluded_tests, received_by
                //
                // NO total_cost column. NO patient_id column. NO payment_status column.
                // We store the billed amount in insurer_amount for display in billing.php.

                if (!empty($ins_provider)) {
                    $ins_member = $patient['insurance_member_no'] ?? '';
                    $sp2 = $conn->prepare(
                        "INSERT INTO payments
                            (request_id, amount_paid, payment_method, payment_type,
                             insurer_amount, insurance_provider, insurance_member_no, received_by)
                         VALUES (?, 0, 'Pending', 'Insurance', ?, ?, ?, ?)"
                    );
                    if ($sp2) {
                        $sp2->bind_param("idsss", $rid, $total_cost, $ins_provider, $ins_member, $doctor_name);
                        $sp2->execute(); $sp2->close();
                    }
                    // Auto-generate claim entry in insurance_claims
                    $cl_prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $ins_provider), 0, 4)) ?: 'SHA';
                    $auto_ref = "CLM-$cl_prefix-" . date('Y') . "-$rid";
                    $stmt_claim = $conn->prepare("INSERT INTO insurance_claims (request_id, patient_id, insurer_name, insurance_number, claim_ref, billed_amount, approved_amount, copay_amount, status, notes) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 'Submitted', 'Pre-authorization auto-logged upon clinician CPOE order.')");
                    if ($stmt_claim) {
                        $stmt_claim->bind_param("iisssd", $rid, $patient_id, $ins_provider, $ins_member, $auto_ref, $total_cost);
                        $stmt_claim->execute();
                        $stmt_claim->close();
                    }
                    // Insurance patients can proceed immediately (workbench checks if patient has insurance_provider)
                } else {
                    $sp2 = $conn->prepare(
                        "INSERT INTO payments
                            (request_id, amount_paid, payment_method, payment_type,
                             insurer_amount, received_by)
                         VALUES (?, 0, 'Pending', 'Cash', ?, ?)"
                    );
                    if ($sp2) {
                        $sp2->bind_param("ids", $rid, $total_cost, $doctor_name);
                        $sp2->execute(); $sp2->close();
                    }
                }

                $pn = $patient['full_name'] ?? "#$patient_id";
                create_notification($conn, 'LabTech', "New Order: Req #$rid for $pn", "enter_results.php?manage_id=$rid", 'Info', 'Lab Order');
                $success = "Request #$rid submitted — Lab Tech notified.";
            } else { $error="DB Error: ".$stmt->error; }
            $stmt->close();
        }
    }
}

if (isset($_GET['search_opd'])&&!isset($_POST['submit_order'])) {
    $opd=trim($_GET['search_opd']);
    $stmt=$conn->prepare("SELECT * FROM patients WHERE opd_number=?"); $stmt->bind_param("s",$opd); $stmt->execute();
    $res=$stmt->get_result(); $patient=$res->num_rows>0?$res->fetch_assoc():null;
    if(!$patient) $error="No patient found: ".htmlspecialchars($opd);
    $stmt->close();
}

// Detect category column name
$cat_col = 'category';
$chk=$conn->query("SELECT COUNT(*) as n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lab_tests' AND COLUMN_NAME='test_category'");
if ($chk && $chk->fetch_assoc()['n'] > 0) $cat_col = 'test_category';

$tests_raw=$conn->query("SELECT *, $cat_col AS cat_label FROM lab_tests ORDER BY $cat_col, test_name");
$categories=[];
if($tests_raw) while($r=$tests_raw->fetch_assoc()) $categories[$r['cat_label']][]=$r;

$prev_history=[];
$flags=[];
if($patient){
    // Fetch active clinical flags
    $sf=$conn->prepare("SELECT * FROM clinical_flags WHERE patient_id=? AND is_resolved=0 ORDER BY FIELD(flag_type,'Critical','Allergy','Warning','Info'), created_at DESC");
    $sf->bind_param("i",$patient['patient_id']); $sf->execute(); $flags=$sf->get_result()->fetch_all(MYSQLI_ASSOC); $sf->close();

    $hs=$conn->prepare("SELECT r.request_id,r.request_date,r.status,r.requested_by FROM lab_requests r WHERE r.patient_id=? AND r.status='Completed' ORDER BY r.request_date DESC LIMIT 3");
    $hs->bind_param("i",$patient['patient_id']); $hs->execute(); $hr=$hs->get_result(); $hs->close();
    while($h=$hr->fetch_assoc()){
        $nrc=$conn->query("SELECT COUNT(*) as n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lab_tests' AND COLUMN_NAME='normal_range'");
        $nr_col = ($nrc && $nrc->fetch_assoc()['n']>0) ? 't.normal_range' : "'' AS normal_range";
        $ts=$conn->prepare("SELECT t.test_name, t.units, $nr_col, tr.result_value FROM test_results tr JOIN lab_tests t ON tr.test_id=t.test_id WHERE tr.request_id=? AND tr.result_value!='Pending' ORDER BY t.test_name");
        $ts->bind_param("i",$h['request_id']); $ts->execute(); $h['tests']=$ts->get_result()->fetch_all(MYSQLI_ASSOC); $ts->close();
        $prev_history[]=$h;
    }
}

$rr=$conn->prepare("SELECT r.request_id,p.full_name,p.opd_number,r.request_date FROM lab_requests r JOIN patients p ON r.patient_id=p.patient_id WHERE r.status='Completed' AND r.requested_by=? ORDER BY r.request_date DESC LIMIT 10");
$rr->bind_param("s",$doctor_name); $rr->execute(); $reports_result=$rr->get_result();

$page_title="Doctor's Console";
include 'includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Serif+Display&display=swap');
.dc{display:grid;grid-template-columns:260px 1fr;gap:18px;align-items:start;font-family:'DM Sans',sans-serif;width:100%}
.dp{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:14px}
.dph{padding:13px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.dpt{font-size:.62rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8}
.si{display:flex;gap:7px;padding:14px 16px}
.sinp{flex:1;border:1.5px solid #e2e8f0;border-radius:7px;padding:9px 11px;font-size:.84rem;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .15s,box-shadow .15s;min-width:0}
.sinp:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.sbtn{background:#1d4ed8;color:#fff;border:none;border-radius:7px;padding:9px 13px;font-size:.82rem;font-weight:600;cursor:pointer;transition:background .15s}
.sbtn:hover{background:#1e40af}
.pf{padding:13px 16px;border-top:1px solid #f1f5f9;display:flex;align-items:center;gap:10px}
.pfav{width:36px;height:36px;border-radius:50%;background:#eff6ff;color:#1d4ed8;display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:700;font-family:'DM Serif Display',serif;flex-shrink:0}
.pfn{font-size:.86rem;font-weight:600;color:#0f172a;margin:0 0 3px}
.pftags{display:flex;flex-wrap:wrap;gap:4px}
.pft{font-size:.66rem;font-weight:600;background:#f3f4f6;color:#64748b;border-radius:20px;padding:2px 7px}
.pft.pri{background:#eff6ff;color:#1d4ed8}
.rlist{padding:0;margin:0;list-style:none}
.ri{display:flex;align-items:center;gap:8px;padding:9px 16px;border-bottom:1px solid #f9fafb;transition:background .1s}
.ri:last-child{border-bottom:none}.ri:hover{background:#fafafa}
.rdot{width:7px;height:7px;background:#22c55e;border-radius:50%;flex-shrink:0}
.rn{flex:1;font-size:.78rem;font-weight:500;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rl{font-size:.72rem;color:#94a3b8;text-decoration:none;border:1px solid #e2e8f0;border-radius:5px;padding:2px 6px;transition:all .1s;white-space:nowrap}
.rl:hover{border-color:#3b82f6;color:#1d4ed8}
.alert-dc{display:flex;align-items:center;gap:10px;padding:11px 15px;border-radius:9px;margin-bottom:16px;font-size:.84rem;font-weight:500;animation:fu .2s ease;grid-column:1/-1}
.alert-dc.success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.alert-dc.danger{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.dai{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0}
.alert-dc.success .dai{background:#dcfce7;color:#16a34a}.alert-dc.danger .dai{background:#fee2e2;color:#dc2626}
@keyframes fu{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}
.mpanel{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
.mban{background:linear-gradient(135deg,#1e40af,#1d4ed8);padding:16px 20px;display:flex;align-items:center;gap:14px}
.mbav{width:44px;height:44px;background:rgba(255,255,255,.18);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.95rem;font-weight:700;color:#fff;font-family:'DM Serif Display',serif;flex-shrink:0;border:2px solid rgba(255,255,255,.25)}
.mbn{font-size:.95rem;font-weight:700;color:#fff;margin:0 0 4px}
.mbtags{display:flex;flex-wrap:wrap;gap:5px}
.mbt{font-size:.68rem;font-weight:600;background:rgba(255,255,255,.18);color:rgba(255,255,255,.95);border-radius:20px;padding:2px 9px}
.mbt.doc{background:rgba(255,255,255,.92);color:#1d4ed8;margin-left:auto}
.hsec{padding:18px 20px;border-bottom:1px solid #f1f5f9}
.hshd{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.hst{font-size:.62rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#94a3b8}
.hsl{font-size:.73rem;color:#3b82f6;text-decoration:none;font-weight:500;display:flex;align-items:center;gap:3px}
.hsl:hover{color:#1d4ed8}
.vacc{display:flex;flex-direction:column;gap:7px}
.vi{border:1px solid #e2e8f0;border-radius:9px;overflow:hidden}
.vtr{display:flex;align-items:center;gap:8px;padding:9px 13px;cursor:pointer;background:#f9fafb;user-select:none;font-size:.8rem}
.vtr:hover{background:#f3f4f6}
.vrq{font-weight:700;color:#374151}.vdt{color:#94a3b8}.vbg{font-size:.66rem;font-weight:700;background:#dcfce7;color:#16a34a;border-radius:20px;padding:2px 7px}
.vdc{color:#94a3b8;margin-left:auto;font-size:.76rem}.vchv{color:#94a3b8;font-size:.68rem;transition:transform .2s}
.vb{display:none}.vb.open{display:block}
table.vt{width:100%;border-collapse:collapse;font-size:.79rem}
table.vt th{background:#f9fafb;color:#94a3b8;font-weight:700;font-size:.64rem;letter-spacing:.5px;text-transform:uppercase;padding:7px 13px;text-align:left;border-top:1px solid #f3f4f6}
table.vt td{padding:6px 13px;border-top:1px solid #f9fafb;color:#374151}
.rn2{color:#16a34a;font-weight:600}.ra{color:#dc2626;font-weight:700}
.osec{padding:18px 20px}
.catlbl{font-size:.64rem;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:#1d4ed8;margin:16px 0 8px;padding-bottom:4px;border-bottom:2px solid #eff6ff;display:flex;align-items:center;gap:5px}
.catlbl:first-of-type{margin-top:0}
.tgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:7px;margin-bottom:2px}
.ttile{border:1.5px solid #e2e8f0;border-radius:9px;padding:9px 11px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:6px;background:#fafafa;transition:all .15s;user-select:none}
.ttile:hover{background:#eff6ff;border-color:#93c5fd;transform:translateY(-1px)}
.ttile.sel{background:#eff6ff;border-color:#3b82f6;border-width:2px}
.ttile.sel .tck{opacity:1;transform:scale(1)}
.tnm{font-size:.76rem;font-weight:500;color:#374151;line-height:1.3}
.ttile.sel .tnm{color:#1d4ed8}
.tcb{font-size:.65rem;font-weight:700;background:#f3f4f6;color:#64748b;border-radius:5px;padding:2px 5px;white-space:nowrap;flex-shrink:0}
.ttile.sel .tcb{background:#dbeafe;color:#1d4ed8}
.tck{width:15px;height:15px;background:#3b82f6;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.55rem;flex-shrink:0;opacity:0;transform:scale(.4);transition:all .15s;margin-left:1px}
.ofoot{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#f8fafc;border-top:1.5px solid #f1f5f9;gap:14px;flex-wrap:wrap}
.ticker{display:flex;align-items:center;gap:12px}
.tcnt{font-size:.76rem;color:#64748b}.tcnt strong{color:#0f172a;font-weight:700}
.tamt{font-size:1.05rem;font-weight:700;color:#1d4ed8;font-family:'DM Serif Display',serif}
.tamt span{font-size:.72rem;color:#94a3b8;font-weight:400;font-family:'DM Sans',sans-serif}
.bsub{background:#1d4ed8;color:#fff;border:none;border-radius:8px;padding:10px 22px;font-size:.86rem;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:7px;transition:background .15s}
.bsub:hover{background:#1e40af}
.eml{background:#fff;border:1.5px dashed #e2e8f0;border-radius:14px;text-align:center;padding:56px 24px}
.emic{width:72px;height:72px;background:#f3f4f6;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:#d1d5db;margin:0 auto 16px}
.eml h5{font-size:.95rem;font-weight:600;color:#374151;margin:0 0 5px}
.eml p{font-size:.82rem;color:#94a3b8;margin:0}
@media(max-width:768px){.dc{grid-template-columns:1fr}}
</style>

<div class="dc">
<?php if($success): ?>
<div class="alert-dc success"><div class="dai"><i class="fa-solid fa-check"></i></div>
    <div><strong>Submitted</strong> — <?php echo htmlspecialchars($success); ?>&nbsp;·&nbsp;<a href="request_test.php" style="color:#166534;font-weight:600">New Request</a></div>
</div>
<?php endif; ?>
<?php if($error): ?>
<div class="alert-dc danger"><div class="dai"><i class="fa-solid fa-xmark"></i></div><span><?php echo htmlspecialchars($error); ?></span></div>
<?php endif; ?>

<!-- SIDEBAR -->
<div>
<div class="dp">
    <div class="dph"><span class="dpt">Find Patient</span></div>
    <form method="get" action="request_test.php">
        <div class="si">
            <input type="text" name="search_opd" class="sinp" placeholder="OPD number…"
                   value="<?php echo isset($_GET['search_opd'])?htmlspecialchars($_GET['search_opd']):''; ?>" required>
            <button type="submit" class="sbtn"><i class="fa-solid fa-magnifying-glass"></i></button>
        </div>
    </form>
    <?php if($patient): ?>
    <div class="pf">
        <div class="pfav"><?php echo strtoupper(substr($patient['full_name'],0,1)); ?></div>
        <div>
            <p class="pfn"><?php echo htmlspecialchars($patient['full_name']); ?></p>
            <div class="pftags">
                <span class="pft pri"><?php echo htmlspecialchars($patient['opd_number']); ?></span>
                <span class="pft"><?php echo $patient['age']; ?> yrs</span>
                <span class="pft"><?php echo $patient['gender']; ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<div class="dp">
    <div class="dph"><span class="dpt">Recent Reports</span></div>
    <?php if($reports_result&&$reports_result->num_rows>0): ?>
    <ul class="rlist">
        <?php while($row=$reports_result->fetch_assoc()): ?>
        <li class="ri">
            <div class="rdot"></div>
            <span class="rn"><?php echo htmlspecialchars($row['full_name']); ?></span>
            <a href="print_report.php?id=<?php echo $row['request_id']; ?>" target="_blank" class="rl">PDF</a>
        </li>
        <?php endwhile; ?>
    </ul>
    <?php else: ?><div style="padding:14px 16px;font-size:.78rem;color:#94a3b8;text-align:center">No reports yet.</div><?php endif; ?>
</div>
</div>

<!-- MAIN -->
<div>
<?php if($patient): ?>
<div class="mpanel">
    <div class="mban">
        <div class="mbav"><?php echo strtoupper(substr($patient['full_name'],0,1)); ?></div>
        <div style="flex:1">
            <p class="mbn"><?php echo htmlspecialchars($patient['full_name']); ?></p>
            <div class="mbtags">
                <span class="mbt">OPD: <?php echo htmlspecialchars($patient['opd_number']); ?></span>
                <span class="mbt"><?php echo $patient['age']; ?> yrs · <?php echo $patient['gender']; ?></span>
            </div>
        </div>
        <span class="mbt doc"><i class="fa-solid fa-user-doctor" style="margin-right:4px"></i>Dr. <?php echo htmlspecialchars($doctor_name); ?></span>
    </div>

    <?php if(!empty($flags)): ?>
    <div style="padding: 16px 20px 0;">
        <?php foreach($flags as $flag): 
            $f_type = htmlspecialchars($flag['flag_type']);
            $f_note = htmlspecialchars($flag['flag_note']);
            $f_by = htmlspecialchars($flag['flagged_by']);
            $alert_cls = ($f_type === 'Critical' || $f_type === 'Allergy') ? 'danger' : 'warning';
            $icon = ($f_type === 'Critical' || $f_type === 'Allergy') ? 'fa-triangle-exclamation' : 'fa-circle-info';
        ?>
        <div class="alert alert-<?= $alert_cls ?> d-flex align-items-start gap-2 mb-2 p-3 small border-0 shadow-sm" style="border-radius:10px;">
            <i class="fa-solid <?= $icon ?> fa-lg mt-1 text-<?= $alert_cls ?>"></i>
            <div>
                <strong><?= $f_type ?> Alert (by <?= $f_by ?>):</strong>
                <div><?= $f_note ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($prev_history)): ?>
    <div class="hsec">
        <div class="hshd">
            <span class="hst">Previous Visits</span>
            <a href="patient_history.php?patient_id=<?php echo $patient['patient_id']; ?>" target="_blank" class="hsl"><i class="fa-solid fa-clock-rotate-left"></i> Full history</a>
        </div>
        <div class="vacc">
        <?php foreach($prev_history as $vi=>$visit): ?>
        <div class="vi">
            <div class="vtr" onclick="tv('v<?php echo $visit['request_id']; ?>',this)">
                <span class="vrq">#<?php echo $visit['request_id']; ?></span>
                <span class="vdt"><?php echo date('d M Y',strtotime($visit['request_date'])); ?></span>
                <span class="vbg">Completed</span>
                <span class="vdc">Dr. <?php echo htmlspecialchars($visit['requested_by']); ?></span>
                <i class="fa-solid fa-chevron-right vchv"></i>
            </div>
            <div class="vb <?php echo $vi===0?'open':''; ?>" id="v<?php echo $visit['request_id']; ?>">
                <?php if(!empty($visit['tests'])): ?>
                <table class="vt">
                    <thead><tr><th>Test</th><th>Result</th><th>Reference</th></tr></thead>
                    <tbody>
                    <?php foreach($visit['tests'] as $t):
                        $ab=stripos($t['result_value'],'pos')!==false||stripos($t['result_value'],'high')!==false||stripos($t['result_value'],'elev')!==false;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($t['test_name']); ?><?php if(!empty($t['units'])): ?> <small style="color:#94a3b8">(<?php echo $t['units']; ?>)</small><?php endif; ?></td>
                        <td class="<?php echo $ab?'ra':'rn2'; ?>"><?php if($ab): ?><i class="fa-solid fa-triangle-exclamation" style="font-size:.65rem;margin-right:3px"></i><?php endif; ?><?php echo htmlspecialchars($t['result_value']); ?></td>
                        <td style="color:#94a3b8;font-size:.73rem"><?php echo htmlspecialchars($t['normal_range']?:'—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?><p style="padding:10px 13px;font-size:.78rem;color:#94a3b8;margin:0">No results recorded.</p><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <form method="post" action="request_test.php" id="labOrderForm">
    <input type="hidden" name="patient_id" value="<?php echo $patient['patient_id']; ?>">
    <div class="osec">
        <?php if(empty($categories)): ?>
        <div style="text-align:center;padding:30px;color:#94a3b8;font-size:.85rem">
            <i class="fa-solid fa-flask fa-2x mb-2 d-block"></i>
            No tests configured. Ask Admin to add tests in the Test Catalogue.
        </div>
        <?php else: ?>
        <?php foreach($categories as $cat=>$tests): ?>
        <div class="catlbl"><i class="fa-solid fa-tag" style="font-size:.58rem"></i><?php echo htmlspecialchars($cat ?: 'General'); ?></div>
        <div class="tgrid">
            <?php foreach($tests as $test): ?>
            <div class="ttile" onclick="tg('t<?php echo $test['test_id']; ?>',<?php echo (float)$test['cost']; ?>)">
                <input class="d-none" type="checkbox" name="tests[]" value="<?php echo $test['test_id']; ?>" id="t<?php echo $test['test_id']; ?>" data-cost="<?php echo (float)$test['cost']; ?>">
                <span class="tnm"><?php echo htmlspecialchars($test['test_name']); ?></span>
                <span class="tcb"><?php echo number_format($test['cost']); ?></span>
                <div class="tck"><i class="fa-solid fa-check" style="font-size:.5rem"></i></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="ofoot">
        <div class="ticker">
            <span class="tcnt"><strong id="selCnt">0</strong> selected</span>
            <span class="tamt"><span>KES </span><span id="totCost">0</span></span>
        </div>
        <button type="submit" name="submit_order" value="1" class="bsub"><i class="fa-solid fa-paper-plane"></i> Submit Order</button>
    </div>
    </form>
</div>
<?php else: ?>
<div class="eml">
    <div class="emic"><i class="fa-solid fa-user-doctor"></i></div>
    <h5>No Patient Selected</h5>
    <p>Search by OPD number to begin ordering tests.</p>
</div>
<?php endif; ?>
</div>
</div>

<script>
let tot=0;
function tg(id,cost){
    const cb=document.getElementById(id),tile=cb.closest('.ttile');
    cb.checked=!cb.checked; tile.classList.toggle('sel',cb.checked);
    tot+=cb.checked?cost:-cost;
    const n=document.querySelectorAll('input[name="tests[]"]:checked').length;
    document.getElementById('selCnt').textContent=n;
    document.getElementById('totCost').textContent=Math.max(0,Math.round(tot)).toLocaleString();
}
function tv(id,tr){
    const b=document.getElementById(id),ic=tr.querySelector('.vchv'),o=b.classList.toggle('open');
    if(ic)ic.style.transform=o?'rotate(90deg)':'';
}
</script>
<?php include 'includes/footer.php'; ?>