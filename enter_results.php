<?php
ob_start();
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';
require_once 'includes/sms.php';

if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] != 'LabTech' && $_SESSION['role'] != 'Admin')) {
    header("location: dashboard.php"); exit;
}

$success = $error = "";
$saved_id = 0;

if (isset($_GET['saved']) && intval($_GET['saved']) > 0) {
    $saved_id = intval($_GET['saved']);
    if (isset($_GET['panic']) && $_GET['panic'] == '1') {
        $error = "⚠️ CRITICAL PANIC ALERT: Life-threatening results detected! High-priority clinical notification dispatched to doctor.";
    } else {
        $success = "Results saved for Request #$saved_id — doctor notified.";
    }
}

if (isset($_GET['rejected']) && intval($_GET['rejected']) > 0) {
    $rej_id = intval($_GET['rejected']);
    $error = "⚠️ SPECIMEN REJECTED: Request #$rej_id specimen was rejected and marked for recollection. Phlebotomy & Clinician alerted.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_results'])) {
    csrf_verify();
    $req_id = intval($_POST['request_id'] ?? 0);

    if ($req_id > 0 && isset($_POST['results']) && is_array($_POST['results'])) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE test_results SET result_value=?, technician_remarks=?, entered_by=? WHERE result_id=?");
            $uid  = intval($_SESSION['id'] ?? 1);
            $has_panic = false;
            $panic_details = [];

            foreach ($_POST['results'] as $result_id => $value) {
                $val=trim($value); $comment=trim($_POST['comments'][$result_id]??""); $rid=intval($result_id);
                $stmt->bind_param("ssii",$val,$comment,$uid,$rid);
                if(!$stmt->execute()) throw new Exception("Failed to update result ID: $rid");

                // Critical Panic Value Evaluation
                $t_info = $conn->query("SELECT t.test_name FROM test_results tr JOIN lab_tests t ON tr.test_id=t.test_id WHERE tr.result_id=$rid");
                if ($t_info && $t_row = $t_info->fetch_assoc()) {
                    $tname = strtolower($t_row['test_name']);
                    $val_clean = strtolower($val);
                    $num = floatval($val);

                    // 1. Severe Hemoglobin / Anemia (< 6.0 g/dL or > 20.0 g/dL)
                    if (str_contains($tname, 'hemoglobin') || str_contains($tname, 'hb')) {
                        if ($num > 0 && ($num < 6.0 || $num > 20.0)) {
                            $has_panic = true; $panic_details[] = "Hb: $val";
                        }
                    }
                    // 2. Severe Blood Glucose (< 2.5 or > 25.0 mmol/L)
                    if (str_contains($tname, 'glucose') || str_contains($tname, 'rbs') || str_contains($tname, 'fbs')) {
                        if ($num > 0 && ($num < 2.5 || $num > 25.0)) {
                            $has_panic = true; $panic_details[] = "Glucose: $val";
                        }
                    }
                    // 3. Potassium Panic (< 2.8 or > 6.2 mmol/L)
                    if (str_contains($tname, 'potassium')) {
                        if ($num > 0 && ($num < 2.8 || $num > 6.2)) {
                            $has_panic = true; $panic_details[] = "Potassium: $val";
                        }
                    }
                    // 4. Malaria Positive / High density
                    if (str_contains($tname, 'malaria') && (str_contains($val_clean, 'pos') || str_contains($val_clean, '+++') || str_contains($val_clean, 'high'))) {
                        $has_panic = true; $panic_details[] = "Malaria: $val";
                    }
                    // 5. Keyword panic
                    if (str_contains($val_clean, 'panic') || str_contains($val_clean, 'critical') || str_contains($val_clean, 'severe')) {
                        $has_panic = true; $panic_details[] = "{$t_row['test_name']}: $val";
                    }
                }
            }
            $stmt->close();

            $stmt_up=$conn->prepare("UPDATE lab_requests SET status='Completed' WHERE request_id=?");
            $stmt_up->bind_param("i",$req_id);
            if(!$stmt_up->execute()) throw new Exception("Failed to update request status.");
            $stmt_up->close();

            $stmt_pat=$conn->prepare("SELECT p.full_name,r.requested_by,r.patient_id FROM lab_requests r JOIN patients p ON r.patient_id=p.patient_id WHERE r.request_id=?");
            $stmt_pat->bind_param("i",$req_id); $stmt_pat->execute();
            $pat_row=$stmt_pat->get_result()->fetch_assoc();
            $pat_name=$pat_row['full_name']??"Unknown Patient";
            $pid=$pat_row['patient_id']??0;
            $stmt_pat->close();

            if ($has_panic) {
                $panic_str = implode(', ', $panic_details);
                $notif_msg = $conn->real_escape_string("CRITICAL PANIC: $pat_name #$req_id ($panic_str) — IMMEDIATE CLINICAL ACTION NEEDED!");
                $notif_link = $conn->real_escape_string("patient_history.php?patient_id=$pid");
                $conn->query("INSERT INTO notifications (target_role,message,link,is_read,created_at) VALUES ('Doctor','$notif_msg','$notif_link',0,NOW())");
                $conn->query("INSERT INTO notifications (target_role,message,link,is_read,created_at) VALUES ('Admin','$notif_msg','$notif_link',0,NOW())");
            } else {
                $notif_msg=$conn->real_escape_string("Results ready: $pat_name #$req_id");
                $notif_link=$conn->real_escape_string("print_report.php?id=$req_id");
                $req_by=$conn->real_escape_string($pat_row['requested_by']??'');
                $dr=$conn->query("SELECT user_id FROM users WHERE full_name='$req_by' AND role='Doctor' LIMIT 1");
                if($dr&&$dr->num_rows>0){
                    $dr_id=$dr->fetch_assoc()['user_id'];
                    $conn->query("INSERT INTO notifications (target_role,target_user_id,message,link,is_read,created_at) VALUES ('Doctor',$dr_id,'$notif_msg','$notif_link',0,NOW())");
                } else {
                    $conn->query("INSERT INTO notifications (target_role,message,link,is_read,created_at) VALUES ('Doctor','$notif_msg','$notif_link',0,NOW())");
                }
            }

            audit_log($conn,'save_results','lab_requests',$req_id,$has_panic ? "PANIC: $pat_name" : $pat_name);
            $conn->commit();

            sms_results_ready($conn,$req_id);

            ob_end_clean();
            $panic_param = $has_panic ? '&panic=1' : '';
            header("Location: enter_results.php?saved=$req_id$panic_param");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error: " . $e->getMessage();
        }
    } else { $error = "No result data found."; }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reject_sample'])) {
    csrf_verify();
    $req_id  = intval($_POST['request_id'] ?? 0);
    $reason  = trim($_POST['rejection_reason'] ?? '');
    $sample  = trim($_POST['sample_type'] ?? 'Whole Blood (EDTA)');
    $comment = trim($_POST['comments'] ?? '');
    $tech    = $_SESSION['full_name'] ?? 'Lab Technologist';

    if ($req_id > 0 && !empty($reason)) {
        $conn->begin_transaction();
        try {
            $stmt_pat = $conn->prepare("SELECT p.patient_id, p.full_name, r.requested_by FROM lab_requests r JOIN patients p ON r.patient_id=p.patient_id WHERE r.request_id=?");
            $stmt_pat->bind_param("i", $req_id);
            $stmt_pat->execute();
            $p_res = $stmt_pat->get_result()->fetch_assoc();
            $stmt_pat->close();

            if (!$p_res) throw new Exception("Lab request not found.");
            $pid = intval($p_res['patient_id']);
            $pat_name = $p_res['full_name'];

            $stmt_rej = $conn->prepare("INSERT INTO sample_rejections (request_id, patient_id, rejection_reason, sample_type, rejected_by, comments, status) VALUES (?, ?, ?, ?, ?, ?, 'Recollection Pending')");
            $stmt_rej->bind_param("iissss", $req_id, $pid, $reason, $sample, $tech, $comment);
            if (!$stmt_rej->execute()) throw new Exception("Failed to log sample rejection.");
            $stmt_rej->close();

            $stmt_req = $conn->prepare("UPDATE lab_requests SET status='Rejected' WHERE request_id=?");
            $stmt_req->bind_param("i", $req_id);
            if (!$stmt_req->execute()) throw new Exception("Failed to update request status.");
            $stmt_req->close();

            $rej_val = "REJECTED: " . $reason;
            $stmt_tr = $conn->prepare("UPDATE test_results SET result_value=?, technician_remarks=? WHERE request_id=?");
            $stmt_tr->bind_param("ssi", $rej_val, $comment, $req_id);
            $stmt_tr->execute();
            $stmt_tr->close();

            $notif_msg = $conn->real_escape_string("⚠️ SPECIMEN REJECTED: $pat_name (Req #$req_id) - Reason: $reason. Recollection requested.");
            $notif_link = $conn->real_escape_string("sample_rejection.php?req_id=$req_id");
            $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('Doctor', '$notif_msg', '$notif_link', 0, NOW())");
            $conn->query("INSERT INTO notifications (target_role, message, link, is_read, created_at) VALUES ('Admin', '$notif_msg', '$notif_link', 0, NOW())");

            audit_log($conn, 'reject_specimen', 'lab_requests', $req_id, "Rejected ($reason) for $pat_name");
            $conn->commit();

            ob_end_clean();
            header("Location: enter_results.php?rejected=$req_id");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Rejection Error: " . $e->getMessage();
        }
    } else {
        $error = "Please specify a valid rejection reason.";
    }
}

$queue_search = trim($_GET['q'] ?? '');
$queue_sql = "SELECT r.request_id,p.full_name,p.opd_number,r.request_date,r.payment_status,r.status,
                     (SELECT COUNT(*) FROM test_results tr WHERE tr.request_id=r.request_id) as test_count
              FROM lab_requests r JOIN patients p ON r.patient_id=p.patient_id
              WHERE r.status IN ('Pending','In Progress')";
if ($queue_search!='') {
    $qs=$conn->real_escape_string($queue_search);
    $queue_sql.=" AND (p.full_name LIKE '%$qs%' OR p.opd_number LIKE '%$qs%')";
}
$queue_sql.=" ORDER BY CASE r.status WHEN 'In Progress' THEN 0 ELSE 1 END, r.request_date ASC";
$queue_result=$conn->query($queue_sql);
$queue_rows=[];
if($queue_result) while($qrow=$queue_result->fetch_assoc()) $queue_rows[]=$qrow;
$queue_total=count($queue_rows);

$overdue_count=0;
foreach($queue_rows as $qr) if((time()-strtotime($qr['request_date']))>7200) $overdue_count++;

$selected_request=null; $request_tests=null;

if(isset($_GET['manage_id'])){
    $manage_id=intval($_GET['manage_id']);

    // Also pull insurance info if columns exist
    $has_p_ins = $conn->query("SELECT COUNT(*) as n FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='patients' AND COLUMN_NAME='insurance_provider'")->fetch_assoc()['n'] > 0;
    $p_ins_col = $has_p_ins ? "p.insurance_provider" : "'' ";

    $stmt_p=$conn->prepare("SELECT r.request_id,p.full_name,p.opd_number,p.age,p.gender,
        r.payment_status,r.request_date,r.requested_by,r.status,
        $p_ins_col AS insurance_provider
        FROM lab_requests r JOIN patients p ON r.patient_id=p.patient_id WHERE r.request_id=?");
    $stmt_p->bind_param("i",$manage_id); $stmt_p->execute();
    $selected_request=$stmt_p->get_result()->fetch_assoc(); $stmt_p->close();

    // Insurance patients are allowed through without cash payment
    $is_insurance = !empty($selected_request['insurance_provider']);
    $can_proceed  = $selected_request['payment_status']==='Paid' || $is_insurance;

    if($selected_request&&$selected_request['status']==='Pending'&&$can_proceed){
        $conn->query("UPDATE lab_requests SET status='In Progress' WHERE request_id=$manage_id");
        $selected_request['status']='In Progress';
        $queue_rows=array_map(function($r) use($manage_id){if($r['request_id']==$manage_id)$r['status']='In Progress';return $r;},$queue_rows);
    }

    if($selected_request){
        $stmt_t=$conn->prepare("SELECT tr.result_id,t.test_name,t.units,t.normal_range,tr.result_value,tr.technician_remarks FROM test_results tr JOIN lab_tests t ON tr.test_id=t.test_id WHERE tr.request_id=? ORDER BY t.test_category,t.test_name");
        $stmt_t->bind_param("i",$manage_id); $stmt_t->execute();
        $request_tests=$stmt_t->get_result(); $stmt_t->close();
    }
}

$page_title="Lab Workbench";
include 'includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Serif+Display&display=swap');
*{font-family:'DM Sans',sans-serif}
.lw{display:grid;grid-template-columns:280px 1fr;gap:18px;align-items:start;width:100%}
.lw-alert{grid-column:1/-1}

/* Sidebar */
.qpanel{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
.qhead{padding:13px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.qht{font-size:.62rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8}
.qbadge{font-size:.7rem;font-weight:700;background:#dcfce7;color:#16a34a;border-radius:20px;padding:2px 8px}
.qbadge.warn{background:#fef3c7;color:#92400e}

.legend{display:flex;gap:12px;padding:10px 16px;border-bottom:1px solid #f9fafb}
.lpip{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.ltxt{font-size:.68rem;color:#94a3b8;display:flex;align-items:center;gap:5px}

.qsearch{padding:10px 14px;border-bottom:1px solid #f9fafb}
.qsi{display:flex;gap:6px}
.qsinp{flex:1;border:1.5px solid #e2e8f0;border-radius:7px;padding:8px 10px;font-size:.82rem;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .15s;min-width:0}
.qsinp:focus{border-color:#3b82f6}
.qsbtn{background:none;border:1.5px solid #e2e8f0;border-radius:7px;padding:8px 10px;cursor:pointer;color:#64748b;font-size:.8rem;transition:all .15s}
.qsbtn:hover{border-color:#3b82f6;color:#1d4ed8}
.qclr{font-size:.72rem;color:#94a3b8;text-decoration:none;align-self:center;padding:0 4px;transition:color .15s}
.qclr:hover{color:#dc2626}

.qlist{max-height:66vh;overflow-y:auto}
.qi{display:block;padding:11px 14px;border-bottom:1px solid #f9fafb;text-decoration:none;color:inherit;transition:background .1s;border-left:3px solid transparent;cursor:pointer}
.qi:hover{background:#f8fafc}
.qi.active{border-left-color:#16a34a;background:#f0fdf4}
.qi.unpaid{border-left-color:#dc2626;background:#fff}
.qi.inprog{border-left-color:#3b82f6;background:#eff6ff}
.qi.overdue{border-left-color:#f59e0b;background:#fffbeb}
.qiname{font-size:.85rem;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
.qiopd{font-size:.72rem;color:#94a3b8}
.qibadge{font-size:.62rem;font-weight:700;border-radius:20px;padding:2px 7px;white-space:nowrap}
.qibadge.paid{background:#dcfce7;color:#166534}
.qibadge.unpaid{background:#fee2e2;color:#991b1b}
.qibadge.prog{background:#dbeafe;color:#1d4ed8}
.qitime{font-size:.68rem;color:#94a3b8;margin-top:2px}
.qioverdue{font-size:.63rem;font-weight:700;color:#dc2626}
.qitests{font-size:.67rem;color:#94a3b8;margin-top:4px}
.q-empty{padding:48px 20px;text-align:center;color:#94a3b8;font-size:.83rem}

/* Main */
.mpanel{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:14px}
.pat-ban{background:linear-gradient(135deg,#1e40af,#1d4ed8);padding:16px 20px;display:flex;align-items:center;gap:14px}
.pb-av{width:44px;height:44px;background:rgba(255,255,255,.18);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.95rem;font-weight:700;color:#fff;font-family:'DM Serif Display',serif;flex-shrink:0;border:2px solid rgba(255,255,255,.25)}
.pb-av.prog{background:rgba(59,130,246,.4)}
.pbn{font-size:.95rem;font-weight:700;color:#fff;margin:0 0 5px}
.pbtags{display:flex;flex-wrap:wrap;gap:5px}
.pbt{font-size:.67rem;font-weight:600;background:rgba(255,255,255,.18);color:rgba(255,255,255,.92);border-radius:20px;padding:2px 9px}
.pbt.paid{background:rgba(34,197,94,.25);color:#bbf7d0}
.pbt.unpaid{background:rgba(239,68,68,.3);color:#fecaca}

.tracker{padding:16px 20px}
.trk-label{font-size:.6rem;font-weight:700;letter-spacing:1.3px;text-transform:uppercase;color:#94a3b8;margin-bottom:12px}
.trk-steps{display:flex;align-items:center;gap:0}
.trk-step{display:flex;flex-direction:column;align-items:center;flex:1}
.trk-dot{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;margin-bottom:4px;flex-shrink:0}
.trk-dot.done{background:#dcfce7;color:#16a34a}
.trk-dot.active{background:#dbeafe;color:#1d4ed8}
.trk-dot.idle{background:#f1f5f9;color:#94a3b8}
.trk-lbl{font-size:.67rem;color:#94a3b8;font-weight:500}
.trk-lbl.active{color:#1d4ed8;font-weight:700}
.trk-lbl.done{color:#16a34a}
.trk-line{flex:1;height:2px;background:#f1f5f9;margin-bottom:18px}
.trk-line.done{background:#dcfce7}

.flag-banner{margin:0 20px 14px;padding:12px 14px;border-radius:9px;display:flex;align-items:flex-start;gap:10px;font-size:.82rem}
.flag-banner.critical,.flag-banner.allergy{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.flag-banner.warning{background:#fffbeb;border:1px solid #fcd34d;color:#92400e}
.flag-banner.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8}
.flag-type{font-weight:700;margin-bottom:2px}

.pay-warn{margin:0 20px 14px;padding:12px 14px;border-radius:9px;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;font-size:.82rem;display:flex;align-items:center;gap:10px}

/* Results table */
.rtable-head{padding:13px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #f1f5f9}
.rth-title{font-size:.85rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px}
.abn-badge{font-size:.68rem;font-weight:700;background:#fef3c7;color:#92400e;border-radius:20px;padding:3px 9px;display:none}

table.rt{width:100%;border-collapse:collapse}
table.rt thead th{padding:10px 16px;font-size:.63rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:#94a3b8;text-align:left;border-bottom:1px solid #f1f5f9;white-space:nowrap}
table.rt tbody tr{border-bottom:1px solid #f9fafb;transition:background .1s}
table.rt tbody tr.is-abnormal{background:#fffbeb}
table.rt td{padding:10px 16px;vertical-align:middle}

.tname{font-size:.85rem;font-weight:600;color:#0f172a;display:block;margin-bottom:1px}
.tunits{font-size:.72rem;color:#94a3b8}
.rinp{width:100%;border:1.5px solid #e2e8f0;border-radius:7px;padding:8px 10px;font-size:.85rem;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .15s,box-shadow .15s;background:#fafafa}
.rinp:focus{border-color:#3b82f6;background:#fff;box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.rinp.abnormal{border-color:#ef4444;color:#991b1b;font-weight:600;background:#fef2f2}
.rinp.is-low{border-color:#f59e0b;color:#92400e;font-weight:600;background:#fffbeb}
.rinp.is-normal{border-color:#22c55e;color:#166534;background:#f0fdf4}
.rinp:disabled{background:#f8fafc;color:#cbd5e1;cursor:not-allowed}
.rrange{font-size:.72rem;color:#94a3b8;display:inline-block;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:3px 8px}
.remark-inp{width:100%;border:1.5px solid #e2e8f0;border-radius:7px;padding:7px 10px;font-size:.78rem;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .15s;background:#fafafa}
.remark-inp:focus{border-color:#3b82f6;background:#fff}
.remark-inp:disabled{background:#f8fafc;color:#cbd5e1;cursor:not-allowed}

.rform-foot{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#f8fafc;border-top:1px solid #f1f5f9;flex-wrap:wrap;gap:10px}
.rfoot-hint{font-size:.72rem;color:#94a3b8}
.rfoot-actions{display:flex;gap:8px}
.btn-cancel-r{background:#fff;color:#64748b;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 16px;font-size:.82rem;font-family:'DM Sans',sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
.btn-save{background:#16a34a;color:#fff;border:none;border-radius:8px;padding:10px 22px;font-size:.85rem;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:7px;transition:background .15s}
.btn-save:hover{background:#15803d}
.btn-save.prog{background:#1d4ed8}.btn-save.prog:hover{background:#1e40af}
.btn-save:disabled{background:#94a3b8;cursor:not-allowed}
.btn-locked{background:#f1f5f9;color:#94a3b8;border:none;border-radius:8px;padding:10px 22px;font-size:.85rem;font-family:'DM Sans',sans-serif;cursor:not-allowed;display:flex;align-items:center;gap:7px}

.alert-lw{display:flex;align-items:center;gap:10px;padding:11px 15px;border-radius:9px;margin-bottom:14px;font-size:.84rem;font-weight:500;animation:fu .2s ease}
.alert-lw.success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.alert-lw.danger{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.alert-lw.warning{background:#fffbeb;border:1px solid #fcd34d;color:#92400e}
.al-ic{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0}
.alert-lw.success .al-ic{background:#dcfce7;color:#16a34a}
.alert-lw.danger .al-ic{background:#fee2e2;color:#dc2626}
.alert-lw.warning .al-ic{background:#fef3c7;color:#d97706}
.prt-link{font-size:.78rem;font-weight:600;background:#fff;border:1px solid rgba(255,255,255,.5);border-radius:6px;padding:4px 10px;text-decoration:none;color:#166534;margin-left:8px}

.empty-main{background:#fff;border:1.5px dashed #e2e8f0;border-radius:14px;text-align:center;padding:60px 24px}
.empty-main-ic{width:72px;height:72px;background:#f0fdf4;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#86efac;margin:0 auto 14px}
.empty-main h5{font-size:.95rem;font-weight:600;color:#374151;margin:0 0 5px}
.empty-main p{font-size:.82rem;color:#94a3b8;margin:0}
@keyframes fu{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:768px){.lw{grid-template-columns:1fr}}
</style>

<div class="lw">

<?php if($success||$error||$overdue_count>0): ?>
<div class="lw-alert">
    <?php if($success): ?>
    <div class="alert-lw success">
        <div class="al-ic"><i class="fa-solid fa-check"></i></div>
        <div><?php echo htmlspecialchars($success); ?>
            <?php if($saved_id>0): ?><a href="print_report.php?id=<?php echo $saved_id; ?>" target="_blank" class="prt-link"><i class="fa-solid fa-print me-1"></i>Print Report</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if($error): ?>
    <div class="alert-lw danger"><div class="al-ic"><i class="fa-solid fa-xmark"></i></div><span><?php echo htmlspecialchars($error); ?></span></div>
    <?php endif; ?>
    <?php if($overdue_count>0): ?>
    <div class="alert-lw warning"><div class="al-ic"><i class="fa-solid fa-clock"></i></div>
        <span><strong><?php echo $overdue_count; ?> overdue request<?php echo $overdue_count>1?'s':''; ?></strong> — waiting over 2 hours.</span>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- QUEUE -->
<div class="qpanel">
    <div class="qhead">
        <span class="qht">Lab Queue</span>
        <div style="display:flex;gap:5px">
            <span class="qbadge"><?php echo $queue_total; ?></span>
            <?php if($overdue_count>0): ?><span class="qbadge warn"><?php echo $overdue_count; ?> overdue</span><?php endif; ?>
        </div>
    </div>
    <div class="legend">
        <span class="ltxt"><span class="lpip" style="background:#16a34a"></span>Pending</span>
        <span class="ltxt"><span class="lpip" style="background:#3b82f6"></span>In Progress</span>
        <span class="ltxt"><span class="lpip" style="background:#f59e0b"></span>Overdue</span>
        <span class="ltxt"><span class="lpip" style="background:#dc2626"></span>Unpaid</span>
    </div>
    <div class="qsearch">
        <form method="get">
            <?php if(isset($_GET['manage_id'])): ?><input type="hidden" name="manage_id" value="<?php echo intval($_GET['manage_id']); ?>"><?php endif; ?>
            <div class="qsi">
                <input type="text" name="q" class="qsinp" placeholder="Search name or OPD…"
                       value="<?php echo htmlspecialchars($queue_search); ?>" oninput="this.form.submit()">
                <button type="submit" class="qsbtn"><i class="fa-solid fa-magnifying-glass"></i></button>
                <?php if($queue_search): ?><a href="enter_results.php<?php echo isset($_GET['manage_id'])?'?manage_id='.intval($_GET['manage_id']):''; ?>" class="qclr">✕</a><?php endif; ?>
            </div>
        </form>
    </div>
    <div class="qlist">
    <?php if($queue_total>0): $pos=1; foreach($queue_rows as $row):
        $isActive   = isset($_GET['manage_id'])&&$_GET['manage_id']==$row['request_id'];
        $isUnpaid   = $row['payment_status']=='Unpaid';
        $isProgress = $row['status']=='In Progress';
        $age        = time()-strtotime($row['request_date']);
        $isOverdue  = $age>7200&&!$isUnpaid;
        $cls        = $isActive?'active':($isUnpaid?'unpaid':($isOverdue?'overdue':($isProgress?'inprog':'')));
        $qBase      = $queue_search?'?q='.urlencode($queue_search).'&manage_id=':'?manage_id=';
    ?>
    <a href="<?php echo $qBase.$row['request_id']; ?>" class="qi <?php echo $cls; ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px">
            <div style="min-width:0;flex:1">
                <div class="qiname"><?php echo htmlspecialchars($row['full_name']); ?></div>
                <div class="qiopd"><?php echo htmlspecialchars($row['opd_number']); ?></div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <?php if($isUnpaid): ?><span class="qibadge unpaid">UNPAID</span>
                <?php elseif($isProgress): ?><span class="qibadge prog">IN PROGRESS</span>
                <?php else: ?><span class="qibadge paid">PAID</span><?php endif; ?>
                <div class="qitime">
                    <?php if($age<3600) echo round($age/60).'m ago'; elseif($age<86400) echo round($age/3600,1).'h ago'; else echo date('d M',strtotime($row['request_date'])); ?>
                    <?php if($isOverdue): ?><span class="qioverdue"> · OVERDUE</span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="qitests"><i class="fa-solid fa-vial" style="margin-right:3px"></i><?php echo $row['test_count']; ?> test<?php echo $row['test_count']!=1?'s':''; ?> &nbsp;·&nbsp; #<?php echo $pos++; ?> in queue</div>
    </a>
    <?php endforeach; else: ?>
    <div class="q-empty"><i class="fa-solid fa-mug-hot" style="font-size:1.8rem;display:block;margin-bottom:10px;opacity:.3"></i><?php echo $queue_search?'No results found.':'All caught up!'; ?></div>
    <?php endif; ?>
    </div>
</div>

<!-- MAIN -->
<div>
<?php if($selected_request):
    $isPaid = $selected_request['payment_status']==='Paid';
    $isInsurance = !empty($selected_request['insurance_provider']);
    $canEnter = $isPaid || $isInsurance;
    $isInProg = $selected_request['status']=='In Progress';
    $initials = strtoupper(substr($selected_request['full_name'],0,1));
?>

<!-- Patient Banner -->
<div class="mpanel">
    <div class="pat-ban">
        <div class="pb-av <?php echo $isInProg?'prog':''; ?>"><?php echo $initials; ?></div>
        <div style="flex:1">
            <p class="pbn"><?php echo htmlspecialchars($selected_request['full_name']); ?></p>
            <div class="pbtags">
                <span class="pbt">OPD: <?php echo htmlspecialchars($selected_request['opd_number']); ?></span>
                <span class="pbt"><?php echo $selected_request['age']; ?> yrs · <?php echo $selected_request['gender']; ?></span>
                <span class="pbt">Req #<?php echo $selected_request['request_id']; ?></span>
                <span class="pbt">Dr. <?php echo htmlspecialchars($selected_request['requested_by']); ?></span>
                <span class="pbt"><?php echo date('d M Y H:i',strtotime($selected_request['request_date'])); ?></span>
                <?php if($isPaid): ?><span class="pbt paid">PAID</span>
                <?php elseif($isInsurance): ?><span class="pbt" style="background:rgba(167,139,250,.25);color:#c4b5fd">INSURANCE</span>
                <?php else: ?><span class="pbt unpaid">UNPAID</span><?php endif; ?>
            </div>
        </div>
        <a href="print_barcode.php?id=<?php echo $selected_request['request_id']; ?>" target="_blank" class="btn btn-sm btn-light rounded-pill px-3 fw-bold text-primary shadow-sm" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-size:.78rem;white-space:nowrap;margin-left:auto">
            <i class="fa-solid fa-barcode"></i> Print Tube Label
        </a>
    </div>

    <!-- Sample tracker -->
    <div class="tracker">
        <div class="trk-label">Sample Status</div>
        <div class="trk-steps">
        <?php
        $steps_map=['Pending'=>1,'In Progress'=>2,'Completed'=>3];
        $cur=$steps_map[$selected_request['status']]??1;
        $slabels=['Received','Processing','Done'];
        $sicons=['fa-inbox','fa-flask','fa-circle-check'];
        foreach($slabels as $i=>$lbl):
            $sn=$i+1; $done=$cur>$sn; $active=$cur===$sn;
            $dc=$done?'done':($active?'active':'idle');
            $lc=$done?'done':($active?'active':'');
        ?>
        <div class="trk-step">
            <div class="trk-dot <?php echo $dc; ?>"><i class="fa-solid <?php echo $done?'fa-check':$sicons[$i]; ?>"></i></div>
            <div class="trk-lbl <?php echo $lc; ?>"><?php echo $lbl; ?></div>
        </div>
        <?php if($i<2): ?><div class="trk-line <?php echo $cur>$i+1?'done':''; ?>"></div><?php endif; ?>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Clinical flags -->
<?php
$flag_stmt=$conn->prepare("SELECT * FROM clinical_flags WHERE patient_id=(SELECT patient_id FROM lab_requests WHERE request_id=?) AND is_resolved=0 ORDER BY FIELD(flag_type,'Critical','Allergy','Warning','Info'),created_at DESC");
$flag_stmt->bind_param("i",$selected_request['request_id']); $flag_stmt->execute();
$patient_flags=$flag_stmt->get_result()->fetch_all(MYSQLI_ASSOC); $flag_stmt->close();
if(!empty($patient_flags)):
    foreach($patient_flags as $flag):
        $ft=strtolower($flag['flag_type']);
        $fi=['warning'=>'fa-triangle-exclamation','allergy'=>'fa-ban','critical'=>'fa-circle-exclamation','info'=>'fa-circle-info'][$ft]??'fa-triangle-exclamation';
?>
<div class="flag-banner <?php echo $ft; ?>">
    <i class="fa-solid <?php echo $fi; ?>" style="flex-shrink:0;margin-top:1px"></i>
    <div>
        <div class="flag-type"><?php echo $flag['flag_type']; ?> — Dr. <?php echo htmlspecialchars($flag['flagged_by']); ?></div>
        <div><?php echo htmlspecialchars($flag['flag_note']); ?></div>
        <div style="font-size:.7rem;opacity:.7;margin-top:2px"><?php echo date('d M Y H:i',strtotime($flag['created_at'])); ?></div>
    </div>
</div>
<?php endforeach; endif; ?>

<?php if(!$isPaid && !$isInsurance): ?>
<div class="pay-warn">
    <i class="fa-solid fa-lock"></i>
    <div><strong>Payment required</strong> — results locked until cash payment is cleared. <a href="billing.php" style="color:#92400e;font-weight:600">Go to Cashier →</a></div>
</div>
<?php elseif(!$isPaid && $isInsurance): ?>
<div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:9px;padding:11px 14px;margin-bottom:14px;font-size:.82rem;color:#6d28d9;display:flex;align-items:center;gap:8px">
    <i class="fa-solid fa-shield-halved"></i>
    <div><strong>Insurance Patient</strong> — <?php echo htmlspecialchars($selected_request['insurance_provider']); ?>. Results can be entered; billing claim to follow.</div>
</div>
<?php endif; ?>

<!-- Results form -->
<div class="mpanel">
    <div class="rtable-head">
        <div class="rth-title">
            <i class="fa-solid fa-microscope" style="color:#64748b;font-size:.85rem"></i>
            Enter Test Results
            <span class="abn-badge" id="abnBadge"><i class="fa-solid fa-triangle-exclamation" style="margin-right:3px"></i><span id="abnCount">0</span> abnormal</span>
        </div>
        <span style="font-size:.72rem;color:#94a3b8">Req #<?php echo $selected_request['request_id']; ?></span>
    </div>
    <form method="post" id="resultsForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="request_id" value="<?php echo $selected_request['request_id']; ?>">
        <table class="rt">
            <thead><tr><th style="width:26%">Test</th><th style="width:26%">Result</th><th style="width:20%">Reference</th><th>Remarks</th></tr></thead>
            <tbody>
            <?php if($request_tests&&$request_tests->num_rows>0):
                while($test=$request_tests->fetch_assoc()):
                    $ev=$test['result_value']!=='Pending'?$test['result_value']:'';
            ?>
            <tr class="result-row">
                <td>
                    <span class="tname"><?php echo htmlspecialchars($test['test_name']); ?></span>
                    <?php if($test['units']): ?><span class="tunits"><?php echo htmlspecialchars($test['units']); ?></span><?php endif; ?>
                </td>
                <td>
                    <div style="position:relative;display:flex;align-items:center">
                        <input type="text" name="results[<?php echo $test['result_id']; ?>]"
                               class="rinp result-input"
                               data-range="<?php echo htmlspecialchars($test['normal_range']?:''); ?>"
                               value="<?php echo htmlspecialchars($ev); ?>"
                               placeholder="Enter result…"
                               <?php echo $canEnter?'required':'disabled'; ?>>
                        <span class="range-flag" style="display:none;position:absolute;right:8px;font-size:.65rem;font-weight:700;padding:2px 6px;border-radius:10px;pointer-events:none"></span>
                    </div>
                </td>
                <td><span class="rrange"><?php echo htmlspecialchars($test['normal_range']?:'—'); ?></span></td>
                <td>
                    <input type="text" name="comments[<?php echo $test['result_id']; ?>]"
                           class="remark-inp"
                           value="<?php echo htmlspecialchars($test['technician_remarks']??''); ?>"
                           placeholder="Optional…"
                           <?php echo $canEnter?'':'disabled'; ?>>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="4" style="text-align:center;padding:40px;color:#94a3b8;font-size:.83rem">No tests on this request.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <div class="rform-foot">
            <span class="rfoot-hint">Abnormal keywords: Positive, High, Elevated</span>
            <div class="rfoot-actions">
                <a href="enter_results.php<?php echo $queue_search?'?q='.urlencode($queue_search):''; ?>" class="btn-cancel-r">Cancel</a>
                <?php if(!$canEnter): ?>
                <div class="btn-locked"><i class="fa-solid fa-lock"></i> Awaiting Payment</div>
                <?php else: ?>
                <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#rejectModalWorkbench">
                    <i class="fa-solid fa-ban me-1"></i> Reject Specimen
                </button>
                <button type="submit" name="save_results" id="submitBtn" class="btn-save <?php echo $isInProg?'prog':''; ?>">
                    <i class="fa-solid fa-check-double"></i> Save &amp; Complete
                </button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- MODAL: REJECT SPECIMEN (WORKBENCH) -->
<div class="modal fade" id="rejectModalWorkbench" tabindex="-1" aria-labelledby="rejectModalWorkbenchLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold" id="rejectModalWorkbenchLabel">
                    <i class="fa-solid fa-ban me-2"></i> Reject Specimen — Req #<?php echo $selected_request['request_id']; ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="request_id" value="<?php echo $selected_request['request_id']; ?>">
                <div class="modal-body p-4">
                    <div class="alert alert-warning py-2 small mb-3">
                        <strong>Patient:</strong> <?php echo htmlspecialchars($selected_request['full_name']); ?> (OPD: <?php echo htmlspecialchars($selected_request['opd_number']); ?>)
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Rejection Reason (Root Cause)</label>
                        <select name="rejection_reason" class="form-select" required>
                            <option value="">-- Select Root Cause Reason --</option>
                            <option value="Gross Hemolysis (Pink/Red Serum)">Gross Hemolysis (Pink/Red Serum)</option>
                            <option value="Clotted Specimen (EDTA / Citrate Tube)">Clotted Specimen (EDTA / Citrate Tube)</option>
                            <option value="Quantity Not Sufficient (QNS)">Quantity Not Sufficient (QNS)</option>
                            <option value="Incorrect Container / Wrong Additive">Incorrect Container / Wrong Additive</option>
                            <option value="Mislabeled / Unlabeled Specimen Tube">Mislabeled / Unlabeled Specimen Tube</option>
                            <option value="Specimen Leaking / Broken Container">Specimen Leaking / Broken Container</option>
                            <option value="Lipemic / Severe Turbidity">Lipemic / Severe Turbidity</option>
                            <option value="Transit Delay / Cold Chain Broken">Transit Delay / Cold Chain Broken</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Specimen Type</label>
                        <select name="sample_type" class="form-select">
                            <option value="Whole Blood (EDTA)">Whole Blood (EDTA - Purple)</option>
                            <option value="Serum (Red / SST Gold Top)">Serum (Red / SST Gold Top)</option>
                            <option value="Plasma (Sodium Citrate - Blue)">Plasma (Sodium Citrate - Blue)</option>
                            <option value="Urine (Clean Catch / Random)">Urine (Clean Catch / Random)</option>
                            <option value="Stool Specimen">Stool Specimen</option>
                            <option value="Sputum Specimen">Sputum Specimen</option>
                            <option value="Swab / Body Fluid">Swab / Body Fluid</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Phlebotomy Corrective Instructions</label>
                        <textarea name="comments" rows="3" class="form-control" placeholder="Guidance for redraw (e.g. mix 8 times gently, avoid vigorous suction)..."></textarea>
                    </div>

                    <div class="p-2 bg-light rounded text-muted small">
                        <i class="fa-solid fa-triangle-exclamation text-danger me-1"></i>
                        The order will be marked <strong>Rejected</strong> and sent to the QA register. Doctor will receive an urgent recollection notification.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reject_sample" class="btn btn-danger rounded-pill px-4 fw-bold">
                        <i class="fa-solid fa-ban me-1"></i> Confirm Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<div class="empty-main">
    <div class="empty-main-ic"><i class="fa-solid fa-vial"></i></div>
    <h5>No Request Selected</h5>
    <p>Select a patient from the queue to enter results.</p>
</div>
<?php endif; ?>
</div>
</div>

<script>
function evaluateResult(inp) {
    const row = inp.closest('.result-row');
    const valStr = inp.value.trim();
    const flag = row ? row.querySelector('.range-flag') : null;
    const rangeStr = (inp.getAttribute('data-range') || '').trim();
    
    if (row) row.classList.remove('is-abnormal');
    inp.classList.remove('abnormal', 'is-low', 'is-normal');
    if (flag) { flag.style.display = 'none'; flag.textContent = ''; }

    if (!valStr) {
        updateAbnCounter();
        return;
    }

    const valLower = valStr.toLowerCase();
    let status = ''; // 'NORMAL', 'HIGH', 'LOW', 'ABNORMAL'

    // 1. Qualitative check
    const negWords = ['neg', 'normal', 'nil', 'clear', 'non-reactive', 'not seen', 'absent'];
    const posWords = ['pos', 'high', 'elev', 'abnorm', 'react', '+', 'seen', 'cyst', 'ova'];

    if (posWords.some(w => valLower.includes(w))) {
        status = 'ABNORMAL';
    } else if (negWords.some(w => valLower.includes(w))) {
        status = 'NORMAL';
    } else {
        // 2. Numeric range evaluation
        const numMatch = valStr.match(/^[-+]?[0-9]*\.?[0-9]+/);
        if (numMatch && rangeStr && rangeStr !== '—') {
            const num = parseFloat(numMatch[0]);
            
            // Format: "min - max" e.g. "11.0 - 15.0"
            const rangeMatch = rangeStr.match(/([0-9]*\.?[0-9]+)\s*-\s*([0-9]*\.?[0-9]+)/);
            if (rangeMatch) {
                const min = parseFloat(rangeMatch[1]);
                const max = parseFloat(rangeMatch[2]);
                if (num < min) status = 'LOW';
                else if (num > max) status = 'HIGH';
                else status = 'NORMAL';
            } else if (rangeStr.includes('<')) {
                const ltMatch = rangeStr.match(/<\s*([0-9]*\.?[0-9]+)/);
                if (ltMatch) {
                    const threshold = parseFloat(ltMatch[1]);
                    status = num < threshold ? 'NORMAL' : 'HIGH';
                }
            } else if (rangeStr.includes('>')) {
                const gtMatch = rangeStr.match(/>\s*([0-9]*\.?[0-9]+)/);
                if (gtMatch) {
                    const threshold = parseFloat(gtMatch[1]);
                    status = num > threshold ? 'NORMAL' : 'LOW';
                }
            }
        }
    }

    // Apply visual flags
    if (status === 'HIGH' || status === 'ABNORMAL') {
        if (row) row.classList.add('is-abnormal');
        inp.classList.add('abnormal');
        if (flag) {
            flag.textContent = status === 'HIGH' ? 'HIGH' : 'ABN';
            flag.style.background = '#fee2e2';
            flag.style.color = '#dc2626';
            flag.style.border = '1px solid #fca5a5';
            flag.style.display = 'inline-block';
        }
    } else if (status === 'LOW') {
        if (row) row.classList.add('is-abnormal');
        inp.classList.add('is-low');
        if (flag) {
            flag.textContent = 'LOW';
            flag.style.background = '#fef3c7';
            flag.style.color = '#d97706';
            flag.style.border = '1px solid #fcd34d';
            flag.style.display = 'inline-block';
        }
    } else if (status === 'NORMAL') {
        inp.classList.add('is-normal');
        if (flag) {
            flag.textContent = 'NORM';
            flag.style.background = '#dcfce7';
            flag.style.color = '#15803d';
            flag.style.border = '1px solid #86efac';
            flag.style.display = 'inline-block';
        }
    }

    updateAbnCounter();
}

function updateAbnCounter() {
    const totalAbn = document.querySelectorAll('.result-row.is-abnormal').length;
    const badge = document.getElementById('abnBadge');
    if (badge) {
        document.getElementById('abnCount').textContent = totalAbn;
        badge.style.display = totalAbn > 0 ? '' : 'none';
    }
}

document.querySelectorAll('.result-input').forEach(i => {
    evaluateResult(i);
    i.addEventListener('input', () => evaluateResult(i));
});

const form = document.getElementById('resultsForm');
const btn = document.getElementById('submitBtn');
if (form && btn) {
    form.addEventListener('submit', () => {
        setTimeout(() => {
            btn.disabled = true;
            btn.innerHTML = '<span style="width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;display:inline-block;animation:spin .7s linear infinite;margin-right:7px"></span>Saving…';
        }, 80);
    });
}
</script>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
<?php include 'includes/footer.php'; ?>
