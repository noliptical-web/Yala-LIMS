<?php
// reports.php
session_start();
require_once 'includes/db_connect.php';

// Admin only
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] != 'Admin') {
    header("location: dashboard.php");
    exit;
}

// --- DATE FILTER ---
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01'); // default: 1st of month
$date_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');  // default: today

// Sanitize dates
$date_from = date('Y-m-d', strtotime($date_from));
$date_to   = date('Y-m-d', strtotime($date_to));

// ============================================================
// SECTION 1: SUMMARY STATS
// ============================================================
$total_patients  = $conn->query("SELECT COUNT(*) as c FROM patients WHERE DATE(registered_at) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['c'];
$total_requests  = $conn->query("SELECT COUNT(*) as c FROM lab_requests WHERE DATE(request_date) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['c'];
$total_completed = $conn->query("SELECT COUNT(*) as c FROM lab_requests WHERE status='Completed' AND DATE(request_date) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['c'];
$total_pending   = $conn->query("SELECT COUNT(*) as c FROM lab_requests WHERE status='Pending' AND DATE(request_date) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['c'];
$total_revenue   = $conn->query("SELECT COALESCE(SUM(amount_paid),0) as t FROM payments WHERE DATE(payment_date) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['t'];
$total_unpaid_amt= $conn->query("SELECT COALESCE(SUM(total),0) as t FROM (SELECT r.request_id, SUM(t.cost) as total FROM lab_requests r JOIN test_results tr ON r.request_id=tr.request_id JOIN lab_tests t ON tr.test_id=t.test_id WHERE r.payment_status='Unpaid' AND DATE(r.request_date) BETWEEN '$date_from' AND '$date_to' GROUP BY r.request_id) x")->fetch_assoc()['t'];

// Completion rate
$completion_rate = $total_requests > 0 ? round(($total_completed / $total_requests) * 100) : 0;

// ============================================================
// SECTION 2: DAILY TREND (last 7 days from date_to)
// ============================================================
$daily_trend = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days", strtotime($date_to)));
    $r = $conn->query("SELECT COUNT(*) as reqs, COALESCE(SUM(p.amount_paid),0) as rev
                       FROM lab_requests lr
                       LEFT JOIN payments p ON lr.request_id = p.request_id AND DATE(p.payment_date) = '$day'
                       WHERE DATE(lr.request_date) = '$day'");
    $row = $r->fetch_assoc();
    $daily_trend[] = ['date' => date('d M', strtotime($day)), 'requests' => (int)$row['reqs'], 'revenue' => (float)$row['rev']];
}

// ============================================================
// SECTION 3: TOP TESTS
// ============================================================
$top_tests = $conn->query("
    SELECT t.test_name, COUNT(*) as count, SUM(t.cost) as revenue
    FROM test_results tr
    JOIN lab_tests t ON tr.test_id = t.test_id
    JOIN lab_requests r ON tr.request_id = r.request_id
    WHERE DATE(r.request_date) BETWEEN '$date_from' AND '$date_to'
    GROUP BY t.test_id
    ORDER BY count DESC
    LIMIT 8
");

// ============================================================
// SECTION 4: STAFF PERFORMANCE (LabTechs by completions)
// ============================================================
$staff_perf = $conn->query("
    SELECT r.requested_by as name, COUNT(*) as completed
    FROM lab_requests r
    WHERE r.status = 'Completed' AND DATE(r.request_date) BETWEEN '$date_from' AND '$date_to'
    GROUP BY r.requested_by
    ORDER BY completed DESC
    LIMIT 5
");

// ============================================================
// SECTION 5: RECENT TRANSACTIONS
// ============================================================
$recent_txns = $conn->query("
    SELECT p.full_name, pay.amount_paid, pay.payment_method, pay.reference_no, pay.payment_date
    FROM payments pay
    JOIN lab_requests r ON pay.request_id = r.request_id
    JOIN patients p ON r.patient_id = p.patient_id
    WHERE DATE(pay.payment_date) BETWEEN '$date_from' AND '$date_to'
    ORDER BY pay.payment_date DESC
    LIMIT 10
");

// ============================================================
// SECTION 6: GENDER BREAKDOWN
// ============================================================
$gender_data = $conn->query("
    SELECT p.gender, COUNT(DISTINCT r.request_id) as cnt
    FROM lab_requests r
    JOIN patients p ON r.patient_id = p.patient_id
    WHERE DATE(r.request_date) BETWEEN '$date_from' AND '$date_to'
    GROUP BY p.gender
");
$gender_labels = []; $gender_counts = [];
while ($g = $gender_data->fetch_assoc()) {
    $gender_labels[] = $g['gender'];
    $gender_counts[] = (int)$g['cnt'];
}

// --- PAGE CONFIGURATION ---
$page_title = "Reports & Analytics - Yala LIMS";
include 'includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
    .stat-card { border:none; border-radius:14px; color:white; position:relative; overflow:hidden; transition: transform .2s; }
    .stat-card:hover { transform: translateY(-4px); }
    .stat-card .bg-icon { position:absolute; right:-8px; bottom:-8px; font-size:4.5rem; opacity:.15; }
    .section-title { font-size:.7rem; font-weight:800; letter-spacing:2px; text-transform:uppercase; color:#90a4ae; margin-bottom:12px; }
    .glass-card { background:rgba(255,255,255,.97); border:none; border-radius:14px; box-shadow:0 4px 20px rgba(31,38,135,.09); }
    .table th { font-size:.75rem; text-transform:uppercase; letter-spacing:.8px; color:#607d8b; font-weight:600; }
    .progress { height:8px; border-radius:10px; }
    .filter-bar { background:rgba(255,255,255,.95); border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); }
    .badge-pill { border-radius:50px; padding: 5px 12px; font-size:.75rem; }
    @media print {
        .no-print { display:none !important; }
        .glass-card { box-shadow:none !important; border:1px solid #eee !important; }
    }
</style>

<!-- PAGE HEADER -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-pie me-2 text-primary"></i>Reports & Analytics</h4>
        <small class="text-muted">Period: <?php echo date('d M Y', strtotime($date_from)); ?> &mdash; <?php echo date('d M Y', strtotime($date_to)); ?></small>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
            <i class="fa-solid fa-print me-1"></i> Print
        </button>
        <a href="reports.php" class="btn btn-outline-primary btn-sm rounded-pill px-3">
            <i class="fa-solid fa-rotate-right me-1"></i> Reset
        </a>
    </div>
</div>

<!-- DATE FILTER BAR -->
<div class="filter-bar p-3 mb-4 no-print">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label small fw-bold mb-1">From</label>
            <input type="text" name="date_from" id="date_from" class="form-control form-control-sm" value="<?php echo $date_from; ?>">
        </div>
        <div class="col-auto">
            <label class="form-label small fw-bold mb-1">To</label>
            <input type="text" name="date_to" id="date_to" class="form-control form-control-sm" value="<?php echo $date_to; ?>">
        </div>
        <div class="col-auto d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill">Apply</button>
            <!-- QUICK RANGES -->
            <a href="?date_from=<?php echo date('Y-m-d'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm rounded-pill">Today</a>
            <a href="?date_from=<?php echo date('Y-m-d', strtotime('monday this week')); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm rounded-pill">This Week</a>
            <a href="?date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm rounded-pill">This Month</a>
        </div>
    </form>
</div>

<!-- SUMMARY STAT CARDS -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card p-3 shadow" style="background:linear-gradient(135deg,#11998e,#38ef7d);">
            <p class="mb-1 small opacity-75 fw-bold">Patients</p>
            <h3 class="fw-bold mb-0"><?php echo $total_patients; ?></h3>
            <i class="fa-solid fa-users bg-icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card p-3 shadow" style="background:linear-gradient(135deg,#1e90ff,#6ab8f7);">
            <p class="mb-1 small opacity-75 fw-bold">Requests</p>
            <h3 class="fw-bold mb-0"><?php echo $total_requests; ?></h3>
            <i class="fa-solid fa-flask bg-icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card p-3 shadow" style="background:linear-gradient(135deg,#56ab2f,#a8e063);">
            <p class="mb-1 small opacity-75 fw-bold">Completed</p>
            <h3 class="fw-bold mb-0"><?php echo $total_completed; ?></h3>
            <i class="fa-solid fa-circle-check bg-icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card p-3 shadow" style="background:linear-gradient(135deg,#f7971e,#ffd200);">
            <p class="mb-1 small opacity-75 fw-bold">Pending</p>
            <h3 class="fw-bold mb-0"><?php echo $total_pending; ?></h3>
            <i class="fa-solid fa-hourglass-half bg-icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card p-3 shadow" style="background:linear-gradient(135deg,#2980b9,#6dd5fa);">
            <p class="mb-1 small opacity-75 fw-bold">Revenue</p>
            <h4 class="fw-bold mb-0">KES <?php echo number_format($total_revenue); ?></h4>
            <i class="fa-solid fa-money-bill-wave bg-icon"></i>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card p-3 shadow" style="background:linear-gradient(135deg,#c0392b,#e74c3c);">
            <p class="mb-1 small opacity-75 fw-bold">Unpaid</p>
            <h4 class="fw-bold mb-0">KES <?php echo number_format($total_unpaid_amt); ?></h4>
            <i class="fa-solid fa-file-invoice-dollar bg-icon"></i>
        </div>
    </div>
</div>

<!-- COMPLETION RATE BAR -->
<div class="glass-card p-3 mb-4 d-flex align-items-center gap-3">
    <div class="flex-shrink-0">
        <span class="fw-bold text-dark">Completion Rate</span>
        <span class="badge bg-<?php echo $completion_rate >= 80 ? 'success' : ($completion_rate >= 50 ? 'warning' : 'danger'); ?> ms-2 badge-pill">
            <?php echo $completion_rate; ?>%
        </span>
    </div>
    <div class="flex-grow-1">
        <div class="progress">
            <div class="progress-bar bg-<?php echo $completion_rate >= 80 ? 'success' : ($completion_rate >= 50 ? 'warning' : 'danger'); ?>"
                 style="width:<?php echo $completion_rate; ?>%"></div>
        </div>
    </div>
</div>

<!-- ROW: CHARTS -->
<div class="row g-3 mb-4">

    <!-- DAILY TREND CHART -->
    <div class="col-md-8">
        <div class="glass-card p-4 h-100">
            <p class="section-title">7-Day Activity Trend</p>
            <canvas id="trendChart" height="100"></canvas>
        </div>
    </div>

    <!-- GENDER DONUT -->
    <div class="col-md-4">
        <div class="glass-card p-4 h-100 d-flex flex-column">
            <p class="section-title">Patient Gender Split</p>
            <div class="flex-grow-1 d-flex align-items-center justify-content-center">
                <canvas id="genderChart" style="max-height:200px;"></canvas>
            </div>
        </div>
    </div>

</div>

<!-- ROW: TOP TESTS + STAFF PERFORMANCE -->
<div class="row g-3 mb-4">

    <!-- TOP TESTS -->
    <div class="col-md-7">
        <div class="glass-card p-4 h-100">
            <p class="section-title">Most Requested Tests</p>
            <?php if ($top_tests && $top_tests->num_rows > 0):
                $max_count = null;
                $rows_tt = []; while($r=$top_tests->fetch_assoc()) $rows_tt[]=$r;
                $max_count = max(array_column($rows_tt, 'count'));
            ?>
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Test Name</th><th>Count</th><th>Revenue (KES)</th><th style="width:30%"></th></tr></thead>
                    <tbody>
                    <?php foreach($rows_tt as $t): $pct = $max_count > 0 ? round(($t['count']/$max_count)*100) : 0; ?>
                        <tr>
                            <td class="fw-medium small"><?php echo htmlspecialchars($t['test_name']); ?></td>
                            <td><span class="badge bg-primary rounded-pill"><?php echo $t['count']; ?></span></td>
                            <td class="small text-success fw-bold"><?php echo number_format($t['revenue']); ?></td>
                            <td>
                                <div class="progress">
                                    <div class="progress-bar bg-primary" style="width:<?php echo $pct; ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted text-center py-4">No data for selected period.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- STAFF PERFORMANCE -->
    <div class="col-md-5">
        <div class="glass-card p-4 h-100">
            <p class="section-title">Doctor Activity (Requests Made)</p>
            <?php if ($staff_perf && $staff_perf->num_rows > 0):
                $sp_rows = []; while($r=$staff_perf->fetch_assoc()) $sp_rows[]=$r;
                $sp_max = max(array_column($sp_rows,'completed'));
            ?>
                <div class="d-flex flex-column gap-3">
                <?php foreach($sp_rows as $i => $sp):
                    $pct = $sp_max > 0 ? round(($sp['completed']/$sp_max)*100) : 0;
                    $colors = ['primary','success','warning','info','danger'];
                    $col = $colors[$i % count($colors)];
                ?>
                    <div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small fw-bold text-dark">Dr. <?php echo htmlspecialchars($sp['name']); ?></span>
                            <span class="badge bg-<?php echo $col; ?> badge-pill"><?php echo $sp['completed']; ?></span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar bg-<?php echo $col; ?>" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted text-center py-4">No data for selected period.</p>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- RECENT TRANSACTIONS TABLE -->
<div class="glass-card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="section-title mb-0">Recent Transactions</p>
        <a href="billing.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 no-print">View All Billing</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Patient</th>
                    <th>Amount (KES)</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Date & Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recent_txns && $recent_txns->num_rows > 0):
                    while($txn = $recent_txns->fetch_assoc()): ?>
                <tr>
                    <td class="fw-bold"><?php echo htmlspecialchars($txn['full_name']); ?></td>
                    <td class="text-success fw-bold">KES <?php echo number_format($txn['amount_paid'], 2); ?></td>
                    <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($txn['payment_method']); ?></span></td>
                    <td><code><?php echo htmlspecialchars($txn['reference_no']); ?></code></td>
                    <td class="small text-muted"><?php echo date('d M Y H:i', strtotime($txn['payment_date'])); ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No transactions in this period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Date picker
flatpickr("#date_from", { dateFormat: "Y-m-d" });
flatpickr("#date_to",   { dateFormat: "Y-m-d" });

// --- DAILY TREND CHART ---
const trendLabels  = <?php echo json_encode(array_column($daily_trend, 'date')); ?>;
const trendReqs    = <?php echo json_encode(array_column($daily_trend, 'requests')); ?>;
const trendRev     = <?php echo json_encode(array_column($daily_trend, 'revenue')); ?>;

new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: trendLabels,
        datasets: [
            {
                label: 'Requests',
                data: trendReqs,
                backgroundColor: 'rgba(30,144,255,0.7)',
                borderRadius: 6,
                yAxisID: 'y'
            },
            {
                label: 'Revenue (KES)',
                data: trendRev,
                type: 'line',
                borderColor: '#11998e',
                backgroundColor: 'rgba(17,153,142,0.08)',
                borderWidth: 2,
                pointBackgroundColor: '#11998e',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top' } },
        scales: {
            y:  { position: 'left',  beginAtZero: true, title: { display: true, text: 'Requests' } },
            y1: { position: 'right', beginAtZero: true, title: { display: true, text: 'Revenue (KES)' }, grid: { drawOnChartArea: false } }
        }
    }
});

// --- GENDER DONUT CHART ---
const gLabels = <?php echo json_encode($gender_labels); ?>;
const gCounts = <?php echo json_encode($gender_counts); ?>;

new Chart(document.getElementById('genderChart'), {
    type: 'doughnut',
    data: {
        labels: gLabels,
        datasets: [{
            data: gCounts,
            backgroundColor: ['#1e90ff','#e91e63','#9c27b0'],
            borderWidth: 0,
            hoverOffset: 8
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' }
        },
        cutout: '65%'
    }
});
</script>

<?php include 'includes/footer.php'; ?>