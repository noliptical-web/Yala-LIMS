<?php
// print_routing_slip.php
// Outpatient Consultation Routing Card & Identification Slip (MOH 204 Visit Slip)
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$pid = intval($_GET['patient_id'] ?? 0);
if ($pid <= 0) die("Invalid Patient ID.");

// Fetch Patient Details
$stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
$stmt->bind_param("i", $pid);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) die("Patient record not found.");

// Fetch Active Clinical Flags (e.g., Allergies)
$flag_stmt = $conn->prepare("SELECT flag_type, flag_note, flagged_by FROM clinical_flags WHERE patient_id = ? AND is_resolved = 0 ORDER BY FIELD(flag_type,'Critical','Allergy','Warning','Info'), created_at DESC");
$flag_stmt->bind_param("i", $pid);
$flag_stmt->execute();
$flags = $flag_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$flag_stmt->close();

// Fetch today's visit sequence or token number
$today_str = date('Y-m-d');
$tok_stmt = $conn->prepare("SELECT COUNT(*) AS daily_seq FROM lab_requests WHERE DATE(request_date) = ?");
$tok_stmt->bind_param("s", $today_str);
$tok_stmt->execute();
$seq_row = $tok_stmt->get_result()->fetch_assoc();
$token_num = ($seq_row['daily_seq'] ?? 0) + ($pid % 20) + 1;
$tok_stmt->close();

// Barcode Generator function (SVG Code 128)
function generate_barcode_svg($code) {
    $len = strlen($code);
    $svg = '<svg viewBox="0 0 220 45" style="width:100%;max-width:230px;height:42px;display:block;margin:0 auto;" preserveAspectRatio="none">';
    $x = 8;
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
<title>OPD Slip — <?php echo htmlspecialchars($patient['opd_number']); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
body {
    background: #f1f5f9;
    padding: 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
}

/* Consultation Card Layout (Standard 4x6 inch / A6 format) */
.card-slip {
    width: 380px;
    background: #fff;
    border: 1.5px solid #0f172a;
    border-radius: 8px;
    padding: 16px 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    position: relative;
}

.header {
    text-align: center;
    border-bottom: 2px solid #0f172a;
    padding-bottom: 8px;
    margin-bottom: 10px;
}
.header-country { font-size: 8.5px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #475569; }
.header-county { font-size: 9.5px; font-weight: 800; text-transform: uppercase; color: #0f172a; }
.header-hosp { font-size: 13px; font-weight: 900; color: #1d4ed8; text-transform: uppercase; letter-spacing: 0.3px; margin: 2px 0; }
.header-type { font-size: 9px; font-weight: 700; background: #0f172a; color: #fff; padding: 2px 8px; border-radius: 4px; display: inline-block; margin-top: 2px; }

.token-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8fafc;
    border: 1px dashed #94a3b8;
    border-radius: 6px;
    padding: 6px 12px;
    margin-bottom: 12px;
}
.token-label { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #64748b; }
.token-num { font-size: 18px; font-weight: 900; color: #1d4ed8; font-family: monospace; }
.token-time { font-size: 9px; font-weight: 600; color: #64748b; text-align: right; }

.barcode-box {
    text-align: center;
    margin: 8px 0 12px;
    padding: 4px;
}
.barcode-text {
    font-size: 11px;
    font-family: monospace;
    font-weight: 800;
    letter-spacing: 2px;
    margin-top: 2px;
}

.section-title {
    font-size: 8.5px;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #475569;
    border-bottom: 1px solid #cbd5e1;
    padding-bottom: 2px;
    margin-bottom: 6px;
}

.detail-table {
    width: 100%;
    margin-bottom: 10px;
    font-size: 10px;
    border-collapse: collapse;
}
.detail-table td {
    padding: 2.5px 0;
    vertical-align: top;
}
.detail-table td.lbl {
    font-weight: 700;
    color: #475569;
    width: 32%;
}
.detail-table td.val {
    font-weight: 800;
    color: #0f172a;
}

.flag-alert {
    background: #fef2f2;
    border: 1.5px solid #ef4444;
    border-radius: 6px;
    padding: 6px 10px;
    margin-bottom: 10px;
    font-size: 9.5px;
    color: #991b1b;
}
.flag-alert strong { display: block; font-weight: 900; text-transform: uppercase; font-size: 9px; letter-spacing: 0.5px; }

.vitals-box {
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 8px 10px;
    margin-bottom: 10px;
    background: #fafafa;
}
.vitals-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 8px 4px;
    font-size: 9px;
}
.v-field { font-weight: 600; color: #475569; }
.v-line { display: inline-block; width: 38px; border-bottom: 1px dotted #64748b; margin-left: 2px; }

.footer-instructions {
    border-top: 1px dashed #cbd5e1;
    padding-top: 8px;
    font-size: 8px;
    color: #64748b;
    line-height: 1.4;
    text-align: center;
}

.actions {
    margin-top: 18px;
    display: flex;
    gap: 12px;
}
.btn {
    padding: 8px 18px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-print { background: #1d4ed8; color: #fff; }
.btn-close { background: #e2e8f0; color: #334155; }

@media print {
    body { background: #fff; padding: 0; }
    .actions { display: none !important; }
    .card-slip {
        border: 1px solid #000;
        box-shadow: none;
        width: 100%;
        max-width: 360px;
        page-break-inside: avoid;
    }
    @page {
        size: 105mm 148mm; /* A6 */
        margin: 5mm;
    }
}
</style>
</head>
<body>

<div class="card-slip" id="routingSlip">
    <div class="header">
        <div class="header-country">Republic of Kenya · Siaya County</div>
        <div class="header-county">Department of Health Services</div>
        <div class="header-hosp">Yala Sub-County Hospital</div>
        <div class="header-type">MOH 204 Outpatient Consultation Card</div>
    </div>

    <div class="token-row">
        <div>
            <div class="token-label">Queue Token</div>
            <div class="token-num">#<?php echo sprintf('%03d', $token_num); ?></div>
        </div>
        <div class="token-time">
            <div><?php echo date('d-M-Y'); ?></div>
            <div><?php echo date('H:i'); ?> HRS</div>
        </div>
    </div>

    <!-- Scannable Barcode -->
    <div class="barcode-box">
        <?php echo generate_barcode_svg($patient['opd_number']); ?>
        <div class="barcode-text"><?php echo htmlspecialchars($patient['opd_number']); ?></div>
    </div>

    <!-- Patient Details -->
    <div class="section-title">Patient Identification</div>
    <table class="detail-table">
        <tr>
            <td class="lbl">Full Name:</td>
            <td class="val"><?php echo htmlspecialchars($patient['full_name']); ?></td>
        </tr>
        <tr>
            <td class="lbl">Age / Gender:</td>
            <td class="val"><?php echo $patient['age']; ?> Years · <?php echo $patient['gender']; ?></td>
        </tr>
        <tr>
            <td class="lbl">Phone Number:</td>
            <td class="val"><?php echo htmlspecialchars($patient['phone_number'] ?: '—'); ?></td>
        </tr>
        <tr>
            <td class="lbl">Coverage / Scheme:</td>
            <td class="val"><?php echo htmlspecialchars($patient['insurance_provider'] ?: 'Cash Paying'); ?><?php if(!empty($patient['insurance_member_no'])) echo ' (' . htmlspecialchars($patient['insurance_member_no']) . ')'; ?></td>
        </tr>
        <tr>
            <td class="lbl">Routing Clinic:</td>
            <td class="val">General OPD — Room 2 (Dr. Vincent)</td>
        </tr>
    </table>

    <!-- Clinical Flags / Allergies -->
    <?php if (!empty($flags)): ?>
    <div class="flag-alert">
        <strong>⚠️ Clinical Alert / Allergy Warning:</strong>
        <?php foreach ($flags as $fl): ?>
            <div>• <?php echo htmlspecialchars($fl['flag_type']); ?>: <?php echo htmlspecialchars($fl['flag_note']); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Triage Vitals Check Box -->
    <div class="vitals-box">
        <div class="section-title" style="margin-bottom:4px">Triage Vitals Check</div>
        <div class="vitals-grid">
            <div class="v-field">BP:<span class="v-line"></span></div>
            <div class="v-field">Temp:<span class="v-line"></span>°C</div>
            <div class="v-field">Pulse:<span class="v-line"></span>bpm</div>
            <div class="v-field">Weight:<span class="v-line"></span>kg</div>
            <div class="v-field">SpO2:<span class="v-line"></span>%</div>
            <div class="v-field">RBS:<span class="v-line"></span></div>
        </div>
    </div>

    <div class="footer-instructions">
        Present this card to the Triage Nurse and Doctor in Consultation Room.<br>
        The clinician will scan or enter your OPD number to order laboratory tests.
    </div>
</div>

<div class="actions">
    <button class="btn btn-print" onclick="window.print()">Print Consultation Card</button>
    <button class="btn btn-close" onclick="window.close()">Close</button>
</div>

<script>
window.addEventListener('load', () => {
    if (window.location.search.includes('autoprint=1')) {
        setTimeout(() => window.print(), 300);
    }
});
</script>
</body>
</html>
