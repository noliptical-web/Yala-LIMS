<?php
// lims_debug.php
// TEMPORARY DIAGNOSTIC FILE - DELETE AFTER FIXING
session_start();
require_once 'includes/db_connect.php';

echo "<style>
    body { font-family: monospace; padding: 20px; background:#f5f5f5; }
    .box { background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px; margin-bottom:16px; }
    .ok  { color: green; font-weight:bold; }
    .err { color: red;   font-weight:bold; }
    .warn{ color: orange; font-weight:bold; }
    table { border-collapse:collapse; width:100%; font-size:13px; }
    th,td { border:1px solid #ccc; padding:6px 10px; text-align:left; }
    th { background:#eee; }
</style>";

echo "<h2>🔬 Yala LIMS — Lab Queue Debugger</h2>";

// ── 1. DB CONNECTION ─────────────────────────────────────────────────────────
echo "<div class='box'><h3>1. Database Connection</h3>";
if ($conn->connect_error) {
    echo "<span class='err'>FAILED: " . $conn->connect_error . "</span>";
} else {
    echo "<span class='ok'>✔ Connected to database successfully</span>";
}
echo "</div>";

// ── 2. ALL lab_requests ROWS ─────────────────────────────────────────────────
echo "<div class='box'><h3>2. All rows in <code>lab_requests</code></h3>";
$r = $conn->query("SELECT * FROM lab_requests ORDER BY request_id DESC LIMIT 20");
if (!$r) {
    echo "<span class='err'>Query failed: " . $conn->error . "</span>";
} elseif ($r->num_rows == 0) {
    echo "<span class='warn'>⚠ Table is EMPTY — no requests have been saved at all.</span>";
} else {
    echo "<table><tr><th>request_id</th><th>patient_id</th><th>requested_by</th>
          <th>status</th><th>payment_status</th><th>request_date</th></tr>";
    while ($row = $r->fetch_assoc()) {
        $statusColor = $row['status'] == 'Pending' ? 'ok' : '';
        echo "<tr>
            <td>{$row['request_id']}</td>
            <td>{$row['patient_id']}</td>
            <td>{$row['requested_by']}</td>
            <td><span class='{$statusColor}'>{$row['status']}</span></td>
            <td>{$row['payment_status']}</td>
            <td>{$row['request_date']}</td>
        </tr>";
    }
    echo "</table>";
}
echo "</div>";

// ── 3. PENDING REQUESTS SPECIFICALLY ────────────────────────────────────────
echo "<div class='box'><h3>3. Rows where <code>status = 'Pending'</code></h3>";
$r2 = $conn->query("SELECT COUNT(*) as c FROM lab_requests WHERE status = 'Pending'");
if (!$r2) {
    echo "<span class='err'>Query failed: " . $conn->error . "</span>";
} else {
    $cnt = $r2->fetch_assoc()['c'];
    if ($cnt == 0) {
        echo "<span class='err'>✘ ZERO pending requests found. Check the exact value of the status column above — it may be spelled differently (e.g. 'pending' vs 'Pending').</span>";
    } else {
        echo "<span class='ok'>✔ $cnt pending request(s) found</span>";
    }
}
echo "</div>";

// ── 4. DISTINCT STATUS VALUES ────────────────────────────────────────────────
echo "<div class='box'><h3>4. Actual <code>status</code> values in the table</h3>";
$r3 = $conn->query("SELECT DISTINCT status, COUNT(*) as count FROM lab_requests GROUP BY status");
if (!$r3) {
    echo "<span class='err'>Query failed: " . $conn->error . "</span>";
} else {
    echo "<table><tr><th>status value (exact)</th><th>count</th></tr>";
    while ($row = $r3->fetch_assoc()) {
        echo "<tr><td><code>" . htmlspecialchars($row['status']) . "</code></td><td>{$row['count']}</td></tr>";
    }
    echo "</table><small class='text-muted'>If you see 'pending' (lowercase) instead of 'Pending', that's the bug.</small>";
}
echo "</div>";

// ── 5. THE EXACT QUEUE QUERY ─────────────────────────────────────────────────
echo "<div class='box'><h3>5. The exact queue query used in <code>enter_results.php</code></h3>";
$queue_sql = "SELECT r.request_id, p.full_name, p.opd_number, r.request_date, r.payment_status,
                     (SELECT COUNT(*) FROM test_results tr WHERE tr.request_id = r.request_id) as test_count
              FROM lab_requests r
              JOIN patients p ON r.patient_id = p.patient_id
              WHERE r.status = 'Pending'
              ORDER BY r.request_date ASC";

echo "<pre style='background:#f9f9f9;padding:10px;border:1px solid #eee;'>" . htmlspecialchars($queue_sql) . "</pre>";

$qr = $conn->query($queue_sql);
if (!$qr) {
    echo "<span class='err'>✘ QUERY FAILED: " . $conn->error . "</span>";
} elseif ($qr->num_rows == 0) {
    echo "<span class='warn'>⚠ Query ran OK but returned 0 rows.</span>";
} else {
    echo "<span class='ok'>✔ Query returned {$qr->num_rows} row(s)</span><br><br>";
    echo "<table><tr><th>request_id</th><th>full_name</th><th>opd_number</th><th>request_date</th><th>payment_status</th><th>test_count</th></tr>";
    while ($row = $qr->fetch_assoc()) {
        echo "<tr>
            <td>{$row['request_id']}</td>
            <td>{$row['full_name']}</td>
            <td>{$row['opd_number']}</td>
            <td>{$row['request_date']}</td>
            <td>{$row['payment_status']}</td>
            <td>{$row['test_count']}</td>
        </tr>";
    }
    echo "</table>";
}
echo "</div>";

// ── 6. patients TABLE CHECK ───────────────────────────────────────────────────
echo "<div class='box'><h3>6. <code>patients</code> table (last 5)</h3>";
$rp = $conn->query("SELECT patient_id, opd_number, full_name FROM patients ORDER BY patient_id DESC LIMIT 5");
if (!$rp) {
    echo "<span class='err'>Query failed: " . $conn->error . "</span>";
} elseif ($rp->num_rows == 0) {
    echo "<span class='warn'>⚠ No patients found in the table.</span>";
} else {
    echo "<table><tr><th>patient_id</th><th>opd_number</th><th>full_name</th></tr>";
    while ($row = $rp->fetch_assoc()) {
        echo "<tr><td>{$row['patient_id']}</td><td>{$row['opd_number']}</td><td>{$row['full_name']}</td></tr>";
    }
    echo "</table>";
}
echo "</div>";

// ── 7. SESSION CHECK ──────────────────────────────────────────────────────────
echo "<div class='box'><h3>7. Current Session</h3>";
echo "<pre>" . print_r($_SESSION, true) . "</pre>";
echo "</div>";

echo "<div class='box' style='background:#fff8dc;'>
    <strong>⚠ Remember:</strong> Delete <code>lims_debug.php</code> from your server once you've fixed the issue.
</div>";
?>