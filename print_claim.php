<?php
// print_claim.php
// Official Ministry of Health / Siaya County Insurance Claim Invoice Voucher
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$claim_id = intval($_GET['id'] ?? 0);
if ($claim_id <= 0) {
    die("Error: Invalid Claim ID specified.");
}

$sql = "SELECT c.*, r.request_date, r.requested_by, r.status AS lab_status,
               p.full_name, p.opd_number, p.age, p.gender, p.phone_number, p.insurance_provider, p.insurance_number,
               pay.received_by, pay.payment_date, pay.reference_no AS pay_ref
        FROM insurance_claims c
        JOIN lab_requests r ON c.request_id = r.request_id
        JOIN patients p     ON c.patient_id = p.patient_id
        LEFT JOIN payments pay ON c.request_id = pay.request_id
        WHERE c.claim_id = $claim_id";

$res = $conn->query($sql);
$claim = $res ? $res->fetch_assoc() : null;

if (!$claim) {
    die("Error: Claim record not found.");
}

$rid = intval($claim['request_id']);
$tests_q = $conn->query("
    SELECT t.test_name, t.test_category, t.cost, tr.result_value
    FROM test_results tr
    JOIN lab_tests t ON tr.test_id = t.test_id
    WHERE tr.request_id = $rid
");
$tests = $tests_q ? $tests_q->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Insurance Claim Voucher #<?= htmlspecialchars($claim['claim_ref'] ?: $claim['claim_id']) ?> &mdash; Yala Hospital</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f1f5f9;
        margin: 0;
        padding: 20px;
        color: #0f172a;
    }
    .sheet {
        background: #fff;
        max-width: 800px;
        margin: 0 auto;
        padding: 36px 40px;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        border: 1px solid #cbd5e1;
    }
    .header-table {
        width: 100%;
        border-bottom: 2px solid #0f172a;
        padding-bottom: 12px;
        margin-bottom: 18px;
    }
    .badge-claim {
        display: inline-block;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 12px;
        text-transform: uppercase;
    }
    .b-Approved { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .b-Submitted { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
    .b-Partial { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .b-Rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    .meta-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        background: #f8fafc;
        padding: 14px 18px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        margin-bottom: 20px;
        font-size: 0.86rem;
    }
    .mg-item span:first-child {
        color: #64748b;
        font-size: 0.75rem;
        text-transform: uppercase;
        font-weight: 600;
        display: block;
    }
    .mg-item span:last-child {
        font-weight: 600;
        color: #0f172a;
    }

    table.items-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
        font-size: 0.88rem;
    }
    table.items-table th {
        background: #f1f5f9;
        text-align: left;
        padding: 9px 12px;
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #475569;
        border-bottom: 1px solid #cbd5e1;
    }
    table.items-table td {
        padding: 9px 12px;
        border-bottom: 1px solid #f1f5f9;
    }
    table.items-table tr:last-child td {
        border-bottom: 2px solid #cbd5e1;
    }

    .summary-box {
        margin-left: auto;
        width: 300px;
        margin-bottom: 30px;
        font-size: 0.88rem;
    }
    .sb-row {
        display: flex;
        justify-content: space-between;
        padding: 4px 0;
    }
    .sb-row.total {
        font-weight: 700;
        font-size: 1.05rem;
        border-top: 1px solid #cbd5e1;
        padding-top: 6px;
        margin-top: 4px;
    }

    .signatures {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 20px;
        margin-top: 40px;
        padding-top: 20px;
        border-top: 1px dashed #cbd5e1;
        font-size: 0.78rem;
        text-align: center;
    }
    .sig-line {
        border-top: 1px solid #0f172a;
        margin-top: 35px;
        padding-top: 4px;
        font-weight: 600;
    }

    .print-bar {
        max-width: 800px;
        margin: 0 auto 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    @media print {
        body { background: #fff; padding: 0; }
        .sheet { box-shadow: none; border: none; padding: 0; }
        .print-bar { display: none !important; }
    }
</style>
</head>
<body>

<div class="print-bar">
    <a href="insurance_claims.php" style="text-decoration:none;font-weight:600;color:#2563eb;">&larr; Back to Claims</a>
    <button onclick="window.print()" style="background:#0f172a;color:#fff;border:none;padding:8px 18px;border-radius:6px;font-weight:600;cursor:pointer;">
        <i class="fa-solid fa-print"></i> Print Official Claim Voucher
    </button>
</div>

<div class="sheet">
    <table class="header-table">
        <tr>
            <td style="width:70px;vertical-align:middle;">
                <?php if (file_exists('logo.png')): ?>
                    <img src="logo.png" height="55" alt="Hospital Logo">
                <?php else: ?>
                    <i class="fa-solid fa-hospital fa-3x" style="color:#1e3a8a;"></i>
                <?php endif; ?>
            </td>
            <td style="vertical-align:middle;">
                <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:1px;">COUNTY GOVERNMENT OF SIAYA &bull; DEPARTMENT OF HEALTH</div>
                <div style="font-size:1.25rem;font-weight:800;color:#0f172a;">YALA SUB-COUNTY HOSPITAL (LEVEL 4)</div>
                <div style="font-size:0.8rem;color:#475569;">P.O. Box 28 - 40101, Yala, Kenya &bull; Medical Insurance Billing &amp; Claims Desk</div>
            </td>
            <td style="text-align:right;vertical-align:middle;">
                <span class="badge-claim b-<?= htmlspecialchars($claim['status']) ?>">
                    <?= htmlspecialchars($claim['status']) ?>
                </span>
                <div style="font-size:0.75rem;font-weight:700;margin-top:6px;">
                    Claim ID: #<?= $claim['claim_id'] ?>
                </div>
            </td>
        </tr>
    </table>

    <div style="text-align:center;margin-bottom:16px;">
        <h4 style="margin:0;font-size:1.05rem;font-weight:800;letter-spacing:0.5px;text-transform:uppercase;">
            MEDICAL OUTPATIENT INSURANCE CLAIM INVOICE
        </h4>
        <small style="color:#64748b;">Under SHA / Social Health Authority &amp; Commercial Insurance Provider Framework</small>
    </div>

    <!-- Metadata Grid -->
    <div class="meta-grid">
        <div class="mg-item">
            <span>Patient Full Name</span>
            <span><?= htmlspecialchars($claim['full_name']) ?></span>
        </div>
        <div class="mg-item">
            <span>Hospital OPD Number</span>
            <span><?= htmlspecialchars($claim['opd_number']) ?></span>
        </div>
        <div class="mg-item">
            <span>Insurance Scheme / Payer</span>
            <span><?= htmlspecialchars($claim['insurer_name']) ?></span>
        </div>
        <div class="mg-item">
            <span>Member / Card Policy No</span>
            <span><?= htmlspecialchars($claim['insurance_number'] ?: 'N/A') ?></span>
        </div>
        <div class="mg-item">
            <span>Pre-Auth / Claim Ref</span>
            <span style="font-family:monospace;"><?= htmlspecialchars($claim['claim_ref'] ?: 'N/A') ?></span>
        </div>
        <div class="mg-item">
            <span>Invoice / Order Date</span>
            <span><?= date('d-M-Y H:i', strtotime($claim['request_date'])) ?></span>
        </div>
        <div class="mg-item">
            <span>Ordering Clinician</span>
            <span>Dr. <?= htmlspecialchars($claim['requested_by'] ?: 'Physician') ?></span>
        </div>
        <div class="mg-item">
            <span>Claim Resolution Date</span>
            <span><?= $claim['resolved_at'] ? date('d-M-Y H:i', strtotime($claim['resolved_at'])) : 'Pending Settlement' ?></span>
        </div>
    </div>

    <!-- Itemized Services Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th>Diagnostic Test Description</th>
                <th>Category</th>
                <th style="text-align:right;">Charge (KES)</th>
            </tr>
        </thead>
        <tbody>
        <?php $n=1; foreach ($tests as $t): ?>
            <tr>
                <td><?= $n++ ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars($t['test_name']) ?></td>
                <td style="color:#64748b;font-size:0.8rem;"><?= htmlspecialchars($t['test_category'] ?: 'Laboratory') ?></td>
                <td style="text-align:right;font-weight:600;"><?= number_format($t['cost'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Financial Breakdown -->
    <div class="summary-box">
        <div class="sb-row">
            <span style="color:#64748b;">Gross Billed Amount:</span>
            <span style="font-weight:600;">KES <?= number_format($claim['billed_amount'], 2) ?></span>
        </div>
        <div class="sb-row">
            <span style="color:#64748b;">Patient Copay Paid:</span>
            <span style="font-weight:600;color:#d97706;">KES <?= number_format($claim['copay_amount'], 2) ?></span>
        </div>
        <div class="sb-row total">
            <span>Net Insurer Claim:</span>
            <span style="color:#15803d;">KES <?= number_format($claim['approved_amount'] > 0 ? $claim['approved_amount'] : ($claim['billed_amount'] - $claim['copay_amount']), 2) ?></span>
        </div>
    </div>

    <?php if (!empty($claim['notes'])): ?>
    <div style="background:#f8fafc;padding:10px 14px;border-radius:6px;border:1px solid #e2e8f0;font-size:0.82rem;margin-bottom:20px;">
        <strong>Audit Notes:</strong> <?= htmlspecialchars($claim['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- Certification & Signatures -->
    <div class="signatures">
        <div>
            <div>Patient / Guardian</div>
            <div class="sig-line">Signature &amp; Thumbprint</div>
        </div>
        <div>
            <div>Attending Doctor</div>
            <div class="sig-line">MOH Reg No. &amp; Stamp</div>
        </div>
        <div>
            <div>Hospital Accounts Officer</div>
            <div class="sig-line">Verified for Submission</div>
        </div>
    </div>

    <div style="text-align:center;margin-top:25px;font-size:0.75rem;color:#94a3b8;">
        Official Document &bull; Yala Sub-County Hospital &bull; Generated: <?= date('d M Y, H:i') ?>
    </div>
</div>

</body>
</html>
