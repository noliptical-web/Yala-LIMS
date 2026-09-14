<?php
ob_start();
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin'])) { header("location: index.php"); exit; }
$role=$_SESSION['role'];
$patient=null; $history=[]; $error=""; $flag_success="";

if ($_SERVER["REQUEST_METHOD"]=="POST"&&isset($_POST['submit_flag'])) {
    csrf_verify();
    $flag_pid=intval($_POST['flag_patient_id']); $flag_rid=intval($_POST['flag_request_id']??0);
    $flag_note=trim($_POST['flag_note']); $flag_type=$_POST['flag_type']??'Warning';
    $flagged_by=$_SESSION['full_name']??'Doctor';
    $allowed=['Warning','Allergy','Critical','Info'];
    if($flag_pid>0&&!empty($flag_note)&&in_array($flag_type,$allowed)){
        $stmt=$conn->prepare("INSERT INTO clinical_flags (patient_id,request_id,flagged_by,flag_note,flag_type) VALUES (?,?,?,?,?)");
        $null_rid=$flag_rid>0?$flag_rid:null;
        $stmt->bind_param("iisss",$flag_pid,$null_rid,$flagged_by,$flag_note,$flag_type);
        if($stmt->execute()){
            $pn=$conn->prepare("SELECT full_name FROM patients WHERE patient_id=?");
            $pn->bind_param("i",$flag_pid); $pn->execute();
            $pr=$pn->get_result()->fetch_assoc(); $pn->close();
            $pname=$conn->real_escape_string($pr['full_name']??"#$flag_pid");
            $nm=$conn->real_escape_string("Flag [$flag_type]: $pname — ".substr($flag_note,0,50));
            $conn->query("INSERT INTO notifications (target_role,message,link,is_read,created_at) VALUES ('LabTech','$nm','enter_results.php',0,NOW())");
            audit_log($conn,'add_flag','clinical_flags',$flag_pid,$flag_type.': '.$flag_note);
            $flag_success="Flag sent.";
        }
        $stmt->close();
    } else { $error="Please fill in the flag note."; }
}

if (isset($_GET['resolve'])&&intval($_GET['resolve'])>0&&($role=='Doctor'||$role=='Admin')) {
    $fid=intval($_GET['resolve']);
    $stmt=$conn->prepare("UPDATE clinical_flags SET is_resolved=1 WHERE flag_id=?");
    $stmt->bind_param("i",$fid); $stmt->execute(); $stmt->close();
    header("Location: patient_history.php?patient_id=".intval($_GET['patient_id']??0)); exit;
}

if (isset($_GET['search'])&&trim($_GET['search'])!=='') {
    $q=trim($_GET['search']); $like="%$q%";
    $stmt=$conn->prepare("SELECT * FROM patients WHERE opd_number LIKE ? OR full_name LIKE ? ORDER BY registered_at DESC LIMIT 20");
    $stmt->bind_param("ss",$like,$like); $stmt->execute();
    $search_results=$stmt->get_result(); $stmt->close();
} else { $search_results=null; }

if (isset($_GET['patient_id'])&&intval($_GET['patient_id'])>0) {
    $pid=intval($_GET['patient_id']);
    $stmt=$conn->prepare("SELECT * FROM patients WHERE patient_id=?");
    $stmt->bind_param("i",$pid); $stmt->execute();
    $patient=$stmt->get_result()->fetch_assoc(); $stmt->close();

    if($patient){
        $stmt=$conn->prepare(
            "SELECT r.request_id,r.requested_by,r.request_date,r.status,r.payment_status,
                    COALESCE((SELECT SUM(lt.cost) FROM test_results tr JOIN lab_tests lt ON tr.test_id=lt.test_id WHERE tr.request_id=r.request_id),0) as total_cost,
                    (SELECT COUNT(*) FROM test_results tr WHERE tr.request_id=r.request_id) as test_count
             FROM lab_requests r WHERE r.patient_id=? ORDER BY r.request_date DESC"
        );
        $stmt->bind_param("i",$pid); $stmt->execute();
        $reqs=$stmt->get_result(); $stmt->close();

        while($req=$reqs->fetch_assoc()){
            $s2=$conn->prepare("SELECT t.test_name,t.units,t.normal_range,tr.result_value,tr.technician_remarks FROM test_results tr JOIN lab_tests t ON tr.test_id=t.test_id WHERE tr.request_id=? ORDER BY t.test_name");
            $s2->bind_param("i",$req['request_id']); $s2->execute();
            $req['tests']=$s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();
            $s3=$conn->prepare("SELECT amount_paid,payment_method,reference_no,payment_date FROM payments WHERE request_id=? LIMIT 1");
            $s3->bind_param("i",$req['request_id']); $s3->execute();
            $req['payment']=$s3->get_result()->fetch_assoc(); $s3->close();
            $history[]=$req;
        }
    }
}

$total_visits=count($history);
$total_spent=array_sum(array_column($history,'total_cost'));
$completed=count(array_filter($history,fn($r)=>$r['status']==='Completed'));

$page_title="Patient History";
include 'includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Serif+Display&display=swap');
.pw{width:100%;padding:0 0 48px;font-family:'DM Sans',sans-serif}
.ph h1{font-family:'DM Serif Display',serif;font-size:1.85rem;color:#0f172a;margin:0 0 3px;letter-spacing:-.4px}
.ph p{font-size:.8rem;color:#94a3b8;margin:0 0 22px}
.layout{display:grid;grid-template-columns:280px 1fr;gap:18px;align-items:start}
.panel{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:14px}
.phead{padding:13px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.ptitle{font-size:.62rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8}
.search-form{padding:14px 16px;border-bottom:1px solid #f9fafb}
.si{display:flex;gap:6px}
.sinp{flex:1;border:1.5px solid #e2e8f0;border-radius:7px;padding:9px 11px;font-size:.84rem;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .15s;min-width:0}
.sinp:focus{border-color:#3b82f6}
.sbtn{background:#1d4ed8;color:#fff;border:none;border-radius:7px;padding:9px 13px;font-size:.82rem;font-weight:600;cursor:pointer}
.sr-item{display:block;padding:11px 16px;border-bottom:1px solid #f9fafb;text-decoration:none;color:inherit;transition:background .1s}
.sr-item:hover,.sr-item.active{background:#eff6ff}
.sri-name{font-size:.86rem;font-weight:600;color:#0f172a;margin-bottom:2px}
.sri-opd{font-size:.71rem;color:#94a3b8}
.pat-ban{background:linear-gradient(135deg,#1e40af,#1d4ed8);padding:18px 20px;border-radius:14px;color:#fff;margin-bottom:14px;display:flex;align-items:center;gap:14px}
.pb-av{width:46px;height:46px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:700;font-family:'DM Serif Display',serif;border:2px solid rgba(255,255,255,.25);flex-shrink:0}
.pbn{font-size:1rem;font-weight:700;margin:0 0 5px}
.pbt{font-size:.68rem;font-weight:600;background:rgba(255,255,255,.18);color:rgba(255,255,255,.92);border-radius:20px;padding:2px 9px;display:inline-block;margin-right:4px;margin-bottom:2px}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
.scard{background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 14px}
.sc-n{font-size:1.4rem;font-weight:700;color:#0f172a;font-family:'DM Serif Display',serif;line-height:1;margin-bottom:2px}
.sc-l{font-size:.68rem;color:#94a3b8;font-weight:500;letter-spacing:.3px}
.req-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:12px}
.rc-head{padding:13px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.rc-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.rc-id{font-size:.88rem;font-weight:700;color:#0f172a}
.status-pill{font-size:.67rem;font-weight:700;border-radius:20px;padding:3px 9px}
.sp-Completed{background:#dcfce7;color:#166534}
.sp-Pending{background:#fef3c7;color:#92400e}
.sp-Progress,.sp-In_Progress{background:#dbeafe;color:#1d4ed8}
.sp-Rejected{background:#fee2e2;color:#991b1b}
.pay-pill{font-size:.67rem;font-weight:700;border-radius:20px;padding:3px 9px}
.pp-Paid{background:#dcfce7;color:#166534}
.pp-Unpaid{background:#fee2e2;color:#991b1b}
.rc-meta{font-size:.75rem;color:#94a3b8;padding:8px 16px;border-bottom:1px solid #f9fafb;display:flex;gap:14px;flex-wrap:wrap}
.rc-actions{display:flex;gap:6px}
.btn-sm-ico{font-size:.72rem;font-weight:600;border-radius:7px;padding:5px 10px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s}
.btn-sm-ico:hover{border-color:#3b82f6;color:#1d4ed8}
.btn-sm-ico.flag-btn:hover{border-color:#f59e0b;color:#92400e}
table.ht{width:100%;border-collapse:collapse}
table.ht thead th{padding:9px 16px;font-size:.62rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#94a3b8;text-align:left;border-bottom:1px solid #f1f5f9}
table.ht tbody tr{border-bottom:1px solid #f9fafb;transition:background .1s}
table.ht tbody tr:last-child{border-bottom:none}
table.ht td{padding:9px 16px;font-size:.82rem;vertical-align:middle}
.r-normal{color:#16a34a;font-weight:600}.r-abnormal{color:#dc2626;font-weight:700}.r-pending{color:#94a3b8;font-style:italic}
.pay-row{padding:10px 16px;background:#f8fafc;border-top:1px solid #f9fafb;font-size:.75rem;color:#64748b;display:flex;align-items:center;gap:6px}
.flag-collapse{display:none;border-top:1px solid #fef3c7;padding:14px 16px;background:#fffbeb}
.flag-collapse.open{display:block}
.f{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.f label{font-size:.7rem;font-weight:600;color:#374151}
.f input,.f select{border:1.5px solid #e2e8f0;border-radius:7px;padding:8px 10px;font-size:.82rem;font-family:'DM Sans',sans-serif;outline:none;background:#fafafa;transition:border-color .15s}
.f input:focus,.f select:focus{border-color:#3b82f6;background:#fff}
.btn-flag-send{background:#f59e0b;color:#fff;border:none;border-radius:7px;padding:8px 16px;font-size:.82rem;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer}
.empty-s{background:#fff;border:1.5px dashed #e2e8f0;border-radius:14px;text-align:center;padding:56px 20px}
.empty-s-ic{width:64px;height:64px;background:#f3f4f6;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.5rem;color:#d1d5db}
.empty-s h5{font-size:.95rem;font-weight:600;color:#374151;margin:0 0 5px}
.empty-s p{font-size:.82rem;color:#94a3b8;margin:0}
.toast-s{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:8px;font-size:.82rem;font-weight:500;margin-bottom:14px}
.toast-s.success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.toast-s.error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
@media(max-width:768px){.layout{grid-template-columns:1fr}}
</style>

<div class="pw">
<div class="ph"><h1>Patient History</h1><p>Search records and view lab results</p></div>

<?php if($flag_success): ?><div class="toast-s success"><i class="fa-solid fa-check"></i><?php echo htmlspecialchars($flag_success); ?></div><?php endif; ?>
<?php if($error):        ?><div class="toast-s error"><i class="fa-solid fa-xmark"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<div class="layout">
<!-- SIDEBAR -->
<div>
<div class="panel">
    <div class="phead"><span class="ptitle">Search Patient</span></div>
    <div class="search-form">
        <form method="get">
            <div class="si">
                <input type="text" name="search" class="sinp" placeholder="Name or OPD number…"
                       value="<?php echo isset($_GET['search'])?htmlspecialchars($_GET['search']):''; ?>">
                <button type="submit" class="sbtn"><i class="fa-solid fa-magnifying-glass"></i></button>
            </div>
        </form>
    </div>
    <?php if($search_results): ?>
        <?php if($search_results->num_rows>0):
            while($sr=$search_results->fetch_assoc()):
                $isActive=isset($_GET['patient_id'])&&$_GET['patient_id']==$sr['patient_id'];
        ?>
        <a href="patient_history.php?patient_id=<?php echo $sr['patient_id']; ?>" class="sr-item <?php echo $isActive?'active':''; ?>">
            <div class="sri-name"><?php echo htmlspecialchars($sr['full_name']); ?></div>
            <div class="sri-opd"><?php echo htmlspecialchars($sr['opd_number']); ?> · <?php echo $sr['age']; ?> yrs · <?php echo $sr['gender']; ?></div>
        </a>
        <?php endwhile; else: ?>
        <div style="padding:20px 16px;font-size:.82rem;color:#94a3b8;text-align:center">No patients found.</div>
        <?php endif; ?>
    <?php elseif(!isset($_GET['patient_id'])): ?>
    <div style="padding:20px 16px;font-size:.78rem;color:#94a3b8;text-align:center;line-height:1.6">Search by patient name<br>or OPD / file number.</div>
    <?php endif; ?>
</div>
</div>

<!-- MAIN -->
<div>
<?php if($patient): ?>

<div class="pat-ban">
    <div class="pb-av"><?php echo strtoupper(substr($patient['full_name'],0,1)); ?></div>
    <div style="flex:1">
        <p class="pbn"><?php echo htmlspecialchars($patient['full_name']); ?></p>
        <span class="pbt">OPD: <?php echo htmlspecialchars($patient['opd_number']); ?></span>
        <span class="pbt"><?php echo $patient['age']; ?> yrs · <?php echo $patient['gender']; ?></span>
        <?php if($patient['phone_number']): ?><span class="pbt"><?php echo $patient['phone_number']; ?></span><?php endif; ?>
    </div>
    <a href="print_routing_slip.php?patient_id=<?php echo $patient['patient_id']; ?>" target="_blank" class="btn btn-sm btn-light rounded-pill px-3 fw-bold text-primary shadow-sm" style="text-decoration:none;font-size:.78rem;display:inline-flex;align-items:center;gap:6px;white-space:nowrap">
        <i class="fa-solid fa-print"></i> Print OPD Visit Slip
    </a>
</div>

<div class="stats-row">
    <div class="scard"><div class="sc-n"><?php echo $total_visits; ?></div><div class="sc-l">Total Visits</div></div>
    <div class="scard"><div class="sc-n"><?php echo $completed; ?></div><div class="sc-l">Completed</div></div>
    <div class="scard"><div class="sc-n">KES <?php echo number_format($total_spent); ?></div><div class="sc-l">Total Billed</div></div>
</div>

<?php if(empty($history)): ?>
<div class="empty-s"><div class="empty-s-ic"><i class="fa-solid fa-flask"></i></div><h5>No lab requests yet</h5><p>This patient has no visit history.</p></div>
<?php else: foreach($history as $req):
    $sc='sp-'.str_replace(' ','_',$req['status']);
    $pc='pp-'.$req['payment_status'];
?>
<div class="req-card">
    <div class="rc-head">
        <div class="rc-left">
            <span class="rc-id">Request #<?php echo $req['request_id']; ?></span>
            <span class="status-pill <?php echo $sc; ?>"><?php echo $req['status']; ?></span>
            <span class="pay-pill <?php echo $pc; ?>"><?php echo $req['payment_status']; ?></span>
        </div>
        <div class="rc-actions">
            <?php if($role=='Doctor'||$role=='Admin'): ?>
            <button class="btn-sm-ico flag-btn" onclick="toggleFlag('ff<?php echo $req['request_id']; ?>')">
                <i class="fa-solid fa-flag"></i> Flag
            </button>
            <?php endif; ?>
            <?php if($req['status']==='Completed'): ?>
            <a href="print_report.php?id=<?php echo $req['request_id']; ?>" target="_blank" class="btn-sm-ico">
                <i class="fa-solid fa-file-pdf"></i> PDF
            </a>
            <?php endif; ?>
            <?php if($req['payment']&&$req['payment_status']==='Paid'): ?>
            <a href="print_receipt.php?id=<?php echo $req['request_id']; ?>" target="_blank" class="btn-sm-ico">
                <i class="fa-solid fa-receipt"></i> Receipt
            </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="rc-meta">
        <span><i class="fa-regular fa-calendar" style="margin-right:4px"></i><?php echo date('d M Y H:i',strtotime($req['request_date'])); ?></span>
        <span><i class="fa-solid fa-user-doctor" style="margin-right:4px"></i>Dr. <?php echo htmlspecialchars($req['requested_by']); ?></span>
        <span><i class="fa-solid fa-vial" style="margin-right:4px"></i><?php echo $req['test_count']; ?> test<?php echo $req['test_count']!=1?'s':''; ?></span>
        <span>KES <?php echo number_format($req['total_cost']); ?></span>
    </div>

    <?php if(!empty($req['tests'])): ?>
    <table class="ht">
        <thead><tr><th>Test</th><th>Result</th><th>Reference</th><th>Remarks</th></tr></thead>
        <tbody>
        <?php foreach($req['tests'] as $t):
            $v=$t['result_value']; $isPend=$v==='Pending';
            $isAbn=!$isPend&&(stripos($v,'pos')!==false||stripos($v,'high')!==false||stripos($v,'elev')!==false);
            $rc=$isPend?'r-pending':($isAbn?'r-abnormal':'r-normal');
        ?>
        <tr>
            <td style="font-weight:500;color:#0f172a"><?php echo htmlspecialchars($t['test_name']); ?><?php if($t['units']): ?> <small style="color:#94a3b8;font-weight:400">(<?php echo $t['units']; ?>)</small><?php endif; ?></td>
            <td class="<?php echo $rc; ?>"><?php if($isAbn): ?><i class="fa-solid fa-triangle-exclamation" style="font-size:.65rem;margin-right:3px"></i><?php endif; ?><?php echo htmlspecialchars($v); ?></td>
            <td style="color:#94a3b8;font-size:.75rem"><?php echo htmlspecialchars($t['normal_range']?:'—'); ?></td>
            <td style="color:#94a3b8;font-size:.75rem"><?php echo htmlspecialchars($t['technician_remarks']?:'—'); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if($req['payment']): ?>
    <div class="pay-row">
        <i class="fa-solid fa-money-bill-wave" style="color:#16a34a"></i>
        KES <?php echo number_format($req['payment']['amount_paid'],2); ?> via <?php echo htmlspecialchars($req['payment']['payment_method']); ?>
        &nbsp;·&nbsp; Ref: <code><?php echo htmlspecialchars($req['payment']['reference_no']); ?></code>
        &nbsp;·&nbsp; <?php echo date('d M Y H:i',strtotime($req['payment']['payment_date'])); ?>
    </div>
    <?php endif; ?>

    <?php if($role=='Doctor'||$role=='Admin'): ?>
    <div class="flag-collapse" id="ff<?php echo $req['request_id']; ?>">
        <form method="post" action="patient_history.php?patient_id=<?php echo $patient['patient_id']; ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="flag_patient_id" value="<?php echo $patient['patient_id']; ?>">
            <input type="hidden" name="flag_request_id" value="<?php echo $req['request_id']; ?>">
            <div style="display:grid;grid-template-columns:140px 1fr auto;gap:10px;align-items:end">
                <div class="f" style="margin:0">
                    <label>Flag Type</label>
                    <select name="flag_type">
                        <option value="Warning">Warning</option><option value="Allergy">Allergy</option>
                        <option value="Critical">Critical</option><option value="Info">Info</option>
                    </select>
                </div>
                <div class="f" style="margin:0">
                    <label>Note for Lab Tech</label>
                    <input type="text" name="flag_note" placeholder="e.g. Patient is diabetic — monitor glucose" required>
                </div>
                <button type="submit" name="submit_flag" class="btn-flag-send"><i class="fa-solid fa-paper-plane"></i> Send</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; endif; ?>

<?php elseif(!isset($_GET['search'])&&!isset($_GET['patient_id'])): ?>
<div class="empty-s">
    <div class="empty-s-ic"><i class="fa-solid fa-magnifying-glass"></i></div>
    <h5>Search for a Patient</h5>
    <p>Enter a name or OPD number to view history.</p>
</div>
<?php endif; ?>
</div>
</div>
</div>

<script>
function toggleFlag(id){
    const el=document.getElementById(id);
    el.classList.toggle('open');
}
</script>
<?php include 'includes/footer.php'; ?>
