<?php
session_start();
require_once 'includes/db_connect.php';
if (!isset($_SESSION['loggedin'])||$_SESSION['role']!='Admin') { header("location: dashboard.php"); exit; }

$date_from=isset($_GET['date_from'])?date('Y-m-d',strtotime($_GET['date_from'])):date('Y-m-01');
$date_to  =isset($_GET['date_to'])  ?date('Y-m-d',strtotime($_GET['date_to']))  :date('Y-m-d');

$stmt=$conn->prepare("SELECT COUNT(*) as c FROM patients WHERE DATE(registered_at) BETWEEN ? AND ?");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $total_patients=$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$stmt=$conn->prepare("SELECT COUNT(*) as c FROM lab_requests WHERE DATE(request_date) BETWEEN ? AND ?");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $total_requests=$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$stmt=$conn->prepare("SELECT COUNT(*) as c FROM lab_requests WHERE status='Completed' AND DATE(request_date) BETWEEN ? AND ?");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $total_completed=$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$stmt=$conn->prepare("SELECT COUNT(*) as c FROM lab_requests WHERE status='Pending' AND DATE(request_date) BETWEEN ? AND ?");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $total_pending=$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$stmt=$conn->prepare("SELECT COALESCE(SUM(amount_paid),0) as t FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $total_revenue=$stmt->get_result()->fetch_assoc()['t']; $stmt->close();

$stmt=$conn->prepare("SELECT COUNT(*) as c FROM sample_rejections WHERE DATE(rejection_date) BETWEEN ? AND ?");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $total_rejections=$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$completion_rate=$total_requests>0?round(($total_completed/$total_requests)*100):0;
$rejection_rate=$total_requests>0?round(($total_rejections/$total_requests)*100,1):0;

$daily_trend=[];
for($i=6;$i>=0;$i--){
    $day=date('Y-m-d',strtotime("-{$i} days",strtotime($date_to)));
    $stmt=$conn->prepare("SELECT COUNT(*) as reqs, COALESCE((SELECT SUM(amount_paid) FROM payments WHERE DATE(payment_date)=?),0) as rev FROM lab_requests WHERE DATE(request_date)=?");
    $stmt->bind_param("ss",$day,$day); $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    $daily_trend[]=['date'=>date('d M',strtotime($day)),'requests'=>(int)$row['reqs'],'revenue'=>(float)$row['rev']];
}

$stmt=$conn->prepare("SELECT t.test_name,COUNT(*) as cnt,SUM(t.cost) as rev FROM test_results tr JOIN lab_tests t ON tr.test_id=t.test_id JOIN lab_requests r ON tr.request_id=r.request_id WHERE DATE(r.request_date) BETWEEN ? AND ? GROUP BY t.test_id ORDER BY cnt DESC LIMIT 8");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $top_tests=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stmt=$conn->prepare("SELECT r.requested_by as name,COUNT(*) as cnt FROM lab_requests r WHERE r.status='Completed' AND DATE(r.request_date) BETWEEN ? AND ? GROUP BY r.requested_by ORDER BY cnt DESC LIMIT 5");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $staff_perf=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stmt=$conn->prepare("SELECT p.full_name,pay.amount_paid,pay.payment_method,pay.reference_no,pay.payment_date FROM payments pay JOIN lab_requests r ON pay.request_id=r.request_id JOIN patients p ON r.patient_id=p.patient_id WHERE DATE(pay.payment_date) BETWEEN ? AND ? ORDER BY pay.payment_date DESC LIMIT 10");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $recent_txns=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stmt=$conn->prepare("SELECT p.gender,COUNT(DISTINCT r.request_id) as cnt FROM lab_requests r JOIN patients p ON r.patient_id=p.patient_id WHERE DATE(r.request_date) BETWEEN ? AND ? GROUP BY p.gender");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $gender_rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$g_labels=array_column($gender_rows,'gender'); $g_counts=array_column($gender_rows,'cnt');

$page_title="Reports & Analytics";
include 'includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Serif+Display&display=swap');
.pw{width:100%;padding:0 0 48px;font-family:'DM Sans',sans-serif}
.ph{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:22px;flex-wrap:wrap;gap:12px}
.ph h1{font-family:'DM Serif Display',serif;font-size:1.85rem;color:#0f172a;margin:0 0 3px;letter-spacing:-.4px}
.ph p{font-size:.8rem;color:#94a3b8;margin:0}
.filter-bar{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:22px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.ff{display:flex;flex-direction:column;gap:4px}
.ff label{font-size:.67rem;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#94a3b8}
.ff input{border:1.5px solid #e2e8f0;border-radius:7px;padding:8px 10px;font-size:.83rem;font-family:'DM Sans',sans-serif;outline:none;background:#fafafa;transition:border-color .15s;width:130px}
.ff input:focus{border-color:#3b82f6;background:#fff}
.btn-apply{background:#1d4ed8;color:#fff;border:none;border-radius:7px;padding:9px 16px;font-size:.83rem;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;align-self:flex-end;transition:background .15s}
.btn-apply:hover{background:#1e40af}
.btn-quick{background:#fff;color:#64748b;border:1.5px solid #e2e8f0;border-radius:7px;padding:9px 12px;font-size:.78rem;font-family:'DM Sans',sans-serif;cursor:pointer;text-decoration:none;align-self:flex-end;transition:all .15s;display:inline-block}
.btn-quick:hover{border-color:#3b82f6;color:#1d4ed8}
.btn-print{background:#fff;color:#64748b;border:1.5px solid #e2e8f0;border-radius:7px;padding:9px 12px;font-size:.78rem;font-family:'DM Sans',sans-serif;cursor:pointer;align-self:flex-end}
.stats{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:22px}
.sc{border-radius:12px;padding:14px 16px;color:#fff;position:relative;overflow:hidden}
.sc-n{font-size:1.6rem;font-weight:700;font-family:'DM Serif Display',serif;line-height:1;margin-bottom:2px}
.sc-l{font-size:.68rem;opacity:.85;font-weight:500}
.sc-ico{position:absolute;right:-5px;bottom:-5px;font-size:3.5rem;opacity:.12}
.panels2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
.panels3{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:18px}
.panel{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
.phead{padding:13px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.ptitle{font-size:.62rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#94a3b8}
.pbody{padding:18px}
.chart-wrap{padding:16px 18px;height:240px}
table.rt{width:100%;border-collapse:collapse}
table.rt thead th{padding:9px 16px;font-size:.62rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#94a3b8;text-align:left;border-bottom:1px solid #f1f5f9;white-space:nowrap}
table.rt tbody tr{border-bottom:1px solid #f9fafb;transition:background .1s}
table.rt tbody tr:hover{background:#fafafa}
table.rt td{padding:10px 16px;font-size:.83rem;vertical-align:middle}
.bar-wrap{height:7px;background:#f1f5f9;border-radius:20px;overflow:hidden}
.bar-fill{height:100%;border-radius:20px;background:#1d4ed8}
.mref{font-size:.7rem;font-weight:600;background:#fef3c7;color:#92400e;border-radius:20px;padding:2px 8px}
.cnt-badge{font-size:.68rem;font-weight:700;background:#dbeafe;color:#1d4ed8;border-radius:20px;padding:2px 8px}
.rev-val{font-weight:600;color:#16a34a;font-size:.83rem}
.staff-row{display:flex;flex-direction:column;gap:10px}
.sr-item{display:flex;flex-direction:column;gap:4px}
.sr-top{display:flex;justify-content:space-between;font-size:.8rem}
.sr-name{font-weight:500;color:#374151}
.sr-cnt{font-size:.72rem;font-weight:700;color:#1d4ed8}
.mcode{font-size:.75rem;font-family:monospace;color:#94a3b8}
.mpay{font-size:.75rem;font-weight:600;background:#eff6ff;color:#1d4ed8;border-radius:20px;padding:2px 8px}
@media print{.filter-bar,.no-print{display:none!important}}
@media(max-width:900px){.stats{grid-template-columns:repeat(3,1fr)}.panels2,.panels3{grid-template-columns:1fr}}
@media(max-width:600px){.stats{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="pw">
<div class="ph">
    <div><h1>Reports</h1><p><?php echo date('d M Y',strtotime($date_from)); ?> — <?php echo date('d M Y',strtotime($date_to)); ?></p></div>
    <div style="display:flex;gap:8px;align-items:center">
        <a href="export_moh.php?date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="btn-print no-print" style="background:#16a34a;text-decoration:none">
            <i class="fa-solid fa-file-excel me-1"></i> Export MOH 240 (CSV)
        </a>
        <button onclick="window.print()" class="btn-print no-print"><i class="fa-solid fa-print me-1"></i> Print</button>
    </div>
</div>

<form method="get" class="filter-bar no-print">
    <div class="ff"><label>From</label><input type="text" name="date_from" id="dfrom" value="<?php echo $date_from; ?>"></div>
    <div class="ff"><label>To</label><input type="text" name="date_to" id="dto" value="<?php echo $date_to; ?>"></div>
    <button type="submit" class="btn-apply">Apply</button>
    <a href="?date_from=<?php echo date('Y-m-d'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn-quick">Today</a>
    <a href="?date_from=<?php echo date('Y-m-d',strtotime('monday this week')); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn-quick">This Week</a>
    <a href="?date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn-quick">This Month</a>
    <a href="reports.php" class="btn-quick">Reset</a>
</form>

<div class="stats">
    <div class="sc" style="background:linear-gradient(135deg,#0f766e,#14b8a6)">
        <div class="sc-n"><?php echo $total_patients; ?></div><div class="sc-l">New Patients</div><i class="fa-solid fa-users sc-ico"></i>
    </div>
    <div class="sc" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6)">
        <div class="sc-n"><?php echo $total_requests; ?></div><div class="sc-l">Total Requests</div><i class="fa-solid fa-flask sc-ico"></i>
    </div>
    <div class="sc" style="background:linear-gradient(135deg,#16a34a,#22c55e)">
        <div class="sc-n"><?php echo $total_completed; ?></div><div class="sc-l">Completed</div><i class="fa-solid fa-check-circle sc-ico"></i>
    </div>
    <div class="sc" style="background:linear-gradient(135deg,#b45309,#f59e0b)">
        <div class="sc-n"><?php echo $completion_rate; ?>%</div><div class="sc-l">Completion Rate</div><i class="fa-solid fa-chart-line sc-ico"></i>
    </div>
    <div class="sc" style="background:linear-gradient(135deg,#9f1239,#e11d48)">
        <div class="sc-n">KES <?php echo number_format($total_revenue,0); ?></div><div class="sc-l">Revenue</div><i class="fa-solid fa-sack-dollar sc-ico"></i>
    </div>
    <div class="sc" style="background:linear-gradient(135deg,#7f1d1d,#b91c1c)">
        <div class="sc-n"><?php echo $total_rejections; ?> (<?php echo $rejection_rate; ?>%)</div><div class="sc-l">Rejections (Target &lt;2%)</div><i class="fa-solid fa-ban sc-ico"></i>
    </div>
</div>

<!-- Charts row -->
<div class="panels3">
    <div class="panel"><div class="phead"><span class="ptitle">Daily Trend (Last 7 days)</span></div>
        <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
    </div>
    <div class="panel"><div class="phead"><span class="ptitle">By Gender</span></div>
        <div class="chart-wrap" style="display:flex;align-items:center;justify-content:center"><canvas id="genderChart" style="max-height:200px"></canvas></div>
    </div>
</div>

<!-- Tables row -->
<div class="panels2">
    <div class="panel">
        <div class="phead"><span class="ptitle">Top Tests</span><span style="font-size:.7rem;color:#94a3b8"><?php echo count($top_tests); ?> tests</span></div>
        <?php if($top_tests): $max=max(array_column($top_tests,'cnt')); ?>
        <table class="rt">
            <thead><tr><th>Test</th><th>Count</th><th>Revenue</th><th style="width:22%"></th></tr></thead>
            <tbody>
            <?php foreach($top_tests as $t): $pct=$max>0?round($t['cnt']/$max*100):0; ?>
            <tr>
                <td style="font-weight:500;color:#0f172a"><?php echo htmlspecialchars($t['test_name']); ?></td>
                <td><span class="cnt-badge"><?php echo $t['cnt']; ?></span></td>
                <td class="rev-val"><?php echo number_format($t['rev']); ?></td>
                <td><div class="bar-wrap"><div class="bar-fill" style="width:<?php echo $pct; ?>%"></div></div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?><div style="padding:32px;text-align:center;color:#94a3b8;font-size:.83rem">No data.</div><?php endif; ?>
    </div>

    <div class="panel">
        <div class="phead"><span class="ptitle">Doctor Activity</span></div>
        <div class="pbody">
        <?php if($staff_perf): $sp_max=max(array_column($staff_perf,'cnt')); ?>
        <div class="staff-row">
        <?php foreach($staff_perf as $sp): $pct=$sp_max>0?round($sp['cnt']/$sp_max*100):0; ?>
        <div class="sr-item">
            <div class="sr-top"><span class="sr-name">Dr. <?php echo htmlspecialchars($sp['name']); ?></span><span class="sr-cnt"><?php echo $sp['cnt']; ?></span></div>
            <div class="bar-wrap"><div class="bar-fill" style="width:<?php echo $pct; ?>%"></div></div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?><div style="text-align:center;color:#94a3b8;font-size:.83rem;padding:20px 0">No data.</div><?php endif; ?>
        </div>
    </div>
</div>

<!-- Transactions -->
<div class="panel">
    <div class="phead">
        <span class="ptitle">Recent Transactions</span>
        <a href="billing.php" class="no-print" style="font-size:.75rem;color:#1d4ed8;text-decoration:none;font-weight:500">View Billing →</a>
    </div>
    <table class="rt">
        <thead><tr><th>Patient</th><th>Amount (KES)</th><th>Method</th><th>Reference</th><th>Date</th></tr></thead>
        <tbody>
        <?php if($recent_txns): foreach($recent_txns as $txn): ?>
        <tr>
            <td style="font-weight:600;color:#0f172a"><?php echo htmlspecialchars($txn['full_name']); ?></td>
            <td class="rev-val">KES <?php echo number_format($txn['amount_paid'],2); ?></td>
            <td><span class="mpay"><?php echo htmlspecialchars($txn['payment_method']); ?></span></td>
            <td class="mcode"><?php echo htmlspecialchars($txn['reference_no']); ?></td>
            <td style="color:#94a3b8;font-size:.78rem;white-space:nowrap"><?php echo date('d M Y H:i',strtotime($txn['payment_date'])); ?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="5" style="text-align:center;padding:32px;color:#94a3b8;font-size:.83rem">No transactions in this period.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
flatpickr("#dfrom",{dateFormat:"Y-m-d"});
flatpickr("#dto",{dateFormat:"Y-m-d"});

const tl=<?php echo json_encode(array_column($daily_trend,'date')); ?>;
const tr2=<?php echo json_encode(array_column($daily_trend,'requests')); ?>;
const trv=<?php echo json_encode(array_column($daily_trend,'revenue')); ?>;

new Chart(document.getElementById('trendChart'),{
    type:'bar',
    data:{labels:tl,datasets:[
        {label:'Requests',data:tr2,backgroundColor:'rgba(29,78,216,.7)',borderRadius:5,yAxisID:'y'},
        {label:'Revenue (KES)',data:trv,type:'line',borderColor:'#16a34a',backgroundColor:'rgba(22,163,74,.08)',borderWidth:2,pointBackgroundColor:'#16a34a',tension:.4,fill:true,yAxisID:'y1'}
    ]},
    options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
        plugins:{legend:{position:'top',labels:{font:{size:11}}}},
        scales:{y:{position:'left',beginAtZero:true,ticks:{font:{size:10}}},y1:{position:'right',beginAtZero:true,grid:{drawOnChartArea:false},ticks:{font:{size:10}}}}}
});

const gl=<?php echo json_encode($g_labels); ?>;
const gc=<?php echo json_encode($g_counts); ?>;
new Chart(document.getElementById('genderChart'),{
    type:'doughnut',
    data:{labels:gl,datasets:[{data:gc,backgroundColor:['#1d4ed8','#e11d48','#7c3aed'],borderWidth:0,hoverOffset:6}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{size:11}}},},cutout:'65%'}
});
</script>
<?php include 'includes/footer.php'; ?>
