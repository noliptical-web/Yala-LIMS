<?php
// print_barcode.php
// Standalone thermal label printer for specimen collection tubes
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$rid = intval($_GET['id'] ?? 0);
if ($rid <= 0) die("Invalid Request ID.");

$stmt = $conn->prepare("
    SELECT r.request_id, r.request_date, r.requested_by,
           p.opd_number, p.full_name, p.age, p.gender
    FROM lab_requests r
    JOIN patients p ON r.patient_id = p.patient_id
    WHERE r.request_id = ?
");
$stmt->bind_param("i", $rid);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$req) die("Specimen request not found.");

// Fetch tests for this request
$stmt_t = $conn->prepare("
    SELECT t.test_name, t.test_category 
    FROM test_results tr 
    JOIN lab_tests t ON tr.test_id = t.test_id 
    WHERE tr.request_id = ?
");
$stmt_t->bind_param("i", $rid);
$stmt_t->execute();
$tests_res = $stmt_t->get_result();
$tests = [];
$categories = [];
while ($row = $tests_res->fetch_assoc()) {
    $tests[] = $row['test_name'];
    if ($row['test_category']) $categories[] = $row['test_category'];
}
$stmt_t->close();

// Determine recommended tube type based on test category
$tube_type = "EDTA / Whole Blood";
$all_cats = implode(' ', $categories);
if (stripos($all_cats, 'chem') !== false || stripos($all_cats, 'liver') !== false || stripos($all_cats, 'lipid') !== false) {
    $tube_type = "Plain / Gel (Serum)";
} elseif (stripos($all_cats, 'urin') !== false || stripos($all_cats, 'renal') !== false) {
    $tube_type = "Urine Container";
} elseif (stripos($all_cats, 'stool') !== false) {
    $tube_type = "Stool Container";
}

$barcode_text = sprintf("REQ-%06d", $rid);

// Simple SVG Code 128 / Barcode generator
function generate_barcode_svg($code) {
    $len = strlen($code);
    $svg = '<svg viewBox="0 0 200 45" style="width:100%;height:38px;display:block;" preserveAspectRatio="none">';
    $x = 10;
    // Generate distinct variable-width bars
    for ($i = 0; $i < $len; $i++) {
        $charVal = ord($code[$i]);
        $w1 = ($charVal % 3) + 1.2;
        $w2 = (($charVal * 2) % 3) + 1.2;
        $svg .= "<rect x='{$x}' y='0' width='{$w1}' height='35' fill='#000'/>";
        $x += $w1 + 1.5;
        $svg .= "<rect x='{$x}' y='0' width='{$w2}' height='35' fill='#000'/>";
        $x += $w2 + 2;
    }
    $svg .= "<rect x='{$x}' y='0' width='2.5' height='35' fill='#000'/>";
    $svg .= '</svg>';
    return $svg;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Tube Label — <?php echo htmlspecialchars($req['opd_number']); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
body {
    background: #f1f5f9;
    padding: 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
}

/* Thermal Sticker Label standard dimensions (approx 50mm x 30mm) */
.label-container {
    width: 240px;
    height: 145px;
    background: #fff;
    border: 1.5px dashed #64748b;
    border-radius: 6px;
    padding: 6px 10px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
}

.lab-head {
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
    text-align: center;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #000;
    padding-bottom: 2px;
    margin-bottom: 2px;
}

.pat-name {
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.pat-meta {
    font-size: 9px;
    font-weight: 600;
    display: flex;
    justify-content: space-between;
    margin-top: 1px;
}

.barcode-box {
    text-align: center;
    margin: 2px 0;
}

.barcode-text {
    font-size: 8px;
    font-family: monospace;
    font-weight: 700;
    letter-spacing: 1.5px;
}

.tests-list {
    font-size: 8px;
    font-weight: 600;
    color: #111;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.tube-tag {
    font-size: 7.5px;
    font-weight: 700;
    text-transform: uppercase;
    border-top: 0.8px solid #000;
    padding-top: 2px;
    display: flex;
    justify-content: space-between;
}

.actions {
    margin-top: 15px;
    display: flex;
    gap: 10px;
}

.btn {
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
}
.btn-print { background: #1d4ed8; color: #fff; }
.btn-close { background: #e2e8f0; color: #334155; }

@media print {
    body { background: #fff; padding: 0; }
    .actions { display: none !important; }
    .label-container {
        border: none;
        box-shadow: none;
        width: 100%;
        height: 100%;
        padding: 4px;
        page-break-inside: avoid;
    }
    @page {
        size: 55mm 32mm;
        margin: 0;
    }
}
</style>
</head>
<body>

<div class="label-container" id="printableLabel">
    <div>
        <div class="lab-head">Yala Sub-County Hospital Lab</div>
        <div class="pat-name"><?php echo htmlspecialchars($req['full_name']); ?></div>
        <div class="pat-meta">
            <span><?php echo htmlspecialchars($req['opd_number']); ?></span>
            <span><?php echo $req['gender']; ?>, <?php echo $req['age']; ?>Y</span>
        </div>
    </div>

    <div class="barcode-box">
        <?php echo generate_barcode_svg($barcode_text); ?>
        <div class="barcode-text">*<?php echo $barcode_text; ?>*</div>
    </div>

    <div>
        <div class="tests-list"><?php echo htmlspecialchars(implode(', ', array_slice($tests, 0, 3))); ?><?php if(count($tests)>3) echo '...'; ?></div>
        <div class="tube-tag">
            <span><?php echo htmlspecialchars($tube_type); ?></span>
            <span><?php echo date('d-M-Y H:i', strtotime($req['request_date'])); ?></span>
        </div>
    </div>
</div>

<div class="actions">
    <button class="btn btn-print" onclick="window.print()">Print Label (Thermal)</button>
    <button class="btn btn-close" onclick="window.close()">Close Window</button>
</div>

<script>
// Auto print prompt on load
window.addEventListener('load', () => {
    if (window.location.search.includes('autoprint=1')) {
        setTimeout(() => window.print(), 300);
    }
});
</script>
</body>
</html>
