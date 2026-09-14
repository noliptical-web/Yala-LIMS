<?php
// print_receipt.php
// Republic of Kenya — County Government of Siaya — Department of Health Services
// Yala Sub-County Hospital (Level 4)
// Official Cashless eCitizen Revenue Receipt (Paybill: 222222)
// Issued under Gazette Notice No. 16008 of 2022 & Public Finance Management Act (PFM Act 2012)

if (session_status() === PHP_SESSION_NONE) { session_start(); }
define('FPDF_FONTPATH', 'fpdf/font/');
require('fpdf/fpdf.php');
require_once 'includes/db_connect.php';

if (!isset($_GET['id'])) {
    die("Error: No Lab Request ID specified.");
}
$req_id = intval($_GET['id']);

$sql = "SELECT p.full_name, p.opd_number, p.age, p.gender, p.phone_number,
               r.request_date, r.requested_by,
               pay.amount_paid, pay.payment_method, pay.payment_type, pay.reference_no,
               pay.insurer_name, pay.insurance_provider, pay.insurance_member_no,
               pay.insurance_claim_no, pay.insurer_amount, pay.patient_copay, pay.copay_amount,
               pay.waiver_reason, pay.received_by, pay.payment_date
        FROM lab_requests r
        JOIN patients p   ON r.patient_id   = p.patient_id
        JOIN payments pay ON r.request_id   = pay.request_id
        WHERE r.request_id = $req_id";

$meta = $conn->query($sql)->fetch_assoc();
if (!$meta) {
    die("Error: Settled payment record not found for Request #$req_id.");
}

$items = $conn->query(
    "SELECT t.test_name, t.test_category, t.cost
     FROM test_results tr
     JOIN lab_tests t ON tr.test_id = t.test_id
     WHERE tr.request_id = $req_id"
);

$tests = [];
$total_calc = 0;
if ($items) {
    while ($r = $items->fetch_assoc()) {
        $tests[] = $r;
        $total_calc += floatval($r['cost']);
    }
}

// Custom FPDF class for official Kenyan Government eCitizen styling
class EcitizenReceiptPDF extends FPDF {
    function Header() {
        // Left Logo (Hospital / MOH)
        if (file_exists('logo.png')) {
            $this->Image('logo.png', 10, 8, 16);
        }
        // Right Logo (eCitizen Kenya)
        if (file_exists('ecitizen.png')) {
            $this->Image('ecitizen.png', 116, 9, 22);
        }

        // Official Titles
        $this->SetY(8);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(30, 41, 59);
        $this->Cell(0, 4, 'REPUBLIC OF KENYA', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(0, 4, 'COUNTY GOVERNMENT OF SIAYA', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 7.5);
        $this->Cell(0, 3.5, 'DEPARTMENT OF HEALTH SERVICES', 0, 1, 'C');

        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 5, 'YALA SUB-COUNTY HOSPITAL (LEVEL 4)', 0, 1, 'C');

        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(16, 122, 68); // Kenyan Green
        $this->Cell(0, 4.5, 'OFFICIAL eCITIZEN REVENUE RECEIPT', 0, 1, 'C');

        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 3.5, 'Government Digital Payment Gateway -- Paybill 222222 (Gazette Notice No. 16008)', 0, 1, 'C');

        $this->Ln(3);
        $this->SetDrawColor(16, 122, 68);
        $this->SetLineWidth(0.6);
        $this->Line(10, $this->GetY(), 138, $this->GetY());
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(203, 213, 225);
        $this->Line(10, $this->GetY() + 0.8, 138, $this->GetY() + 0.8);
        $this->Ln(3);
    }

    function Footer() {
        $this->SetY(-22);
        $this->SetDrawColor(203, 213, 225);
        $this->Line(10, $this->GetY(), 138, $this->GetY());
        $this->SetY(-20);

        $this->SetFont('Arial', 'B', 6.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 3, '*** OFFICIAL GOVERNMENT REVENUE RECEIPT -- CASHLESS FACILITY ***', 0, 1, 'C');

        $this->SetFont('Arial', '', 6);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 2.8, 'Issued pursuant to Public Finance Management Act 2012 & Executive Order No. 2 of 2023.', 0, 1, 'C');
        $this->Cell(0, 2.8, 'All revenues are remitted directly to the Siaya County Revenue Fund. Physical cash is strictly prohibited.', 0, 1, 'C');
        $this->Cell(0, 2.8, 'Verify receipt authenticity at: https://ecitizen.go.ke | Support: 222222', 0, 1, 'C');

        $this->SetFont('Arial', 'I', 6.5);
        $this->SetTextColor(16, 122, 68);
        $this->Cell(0, 3, 'Service with Integrity -- Quick Recovery.', 0, 1, 'C');
    }
}

$pdf = new EcitizenReceiptPDF('P', 'mm', 'A5');
$pdf->SetMargins(10, 8, 10);
$pdf->AddPage();

$pdf->SetTextColor(15, 23, 42);

// Top Metadata Box (Two Columns)
$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(226, 232, 240);
$pdf->RoundedRect = function($x, $y, $w, $h, $r) use ($pdf) {
    $pdf->Rect($x, $y, $w, $h, 'DF');
};

$boxY = $pdf->GetY();
$pdf->Rect(10, $boxY, 128, 26, 'DF');

$pdf->SetY($boxY + 2);
$pdf->SetFont('Arial', '', 7.5);

// Left Column (Transaction details)
$pdf->SetX(12);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->Cell(24, 4.5, 'Receipt / PRN:', 0, 0);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetTextColor(16, 122, 68);
$ref_display = $meta['reference_no'] ?: ('PRN-222222-' . rand(100000, 999999));
$pdf->Cell(42, 4.5, substr($ref_display, 0, 22), 0, 0);

// Right Column (Patient Name)
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->Cell(20, 4.5, 'Patient Name:', 0, 0);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(40, 4.5, strtoupper(substr($meta['full_name'], 0, 24)), 0, 1);

// Row 2
$pdf->SetX(12);
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(24, 4.5, 'Date & Time:', 0, 0);
$pdf->SetFont('Arial', '', 7.5);
$dt = !empty($meta['payment_date']) ? date('d-M-Y H:i', strtotime($meta['payment_date'])) : date('d-M-Y H:i');
$pdf->Cell(42, 4.5, $dt . ' EAT', 0, 0);

$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(20, 4.5, 'OPD Number:', 0, 0);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->Cell(40, 4.5, $meta['opd_number'], 0, 1);

// Row 3
$pdf->SetX(12);
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(24, 4.5, 'Lab Request ID:', 0, 0);
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(42, 4.5, '#' . $req_id . ' (MOH 204)', 0, 0);

$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(20, 4.5, 'Age / Gender:', 0, 0);
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(40, 4.5, $meta['age'] . ' Yrs / ' . $meta['gender'], 0, 1);

// Row 4
$pdf->SetX(12);
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(24, 4.5, 'Billing Officer:', 0, 0);
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(42, 4.5, substr($meta['received_by'] ?: 'Station Cashier', 0, 22), 0, 0);

$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(20, 4.5, 'Phone No:', 0, 0);
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(40, 4.5, $meta['phone_number'] ?: 'N/A', 0, 1);

$pdf->SetY($boxY + 28);

// Payment Channel Details Banner
$pdf->SetFillColor(240, 253, 244); // Greenish tint
$pdf->SetDrawColor(187, 247, 208);
$pdf->Rect(10, $pdf->GetY(), 128, 14, 'DF');

$payY = $pdf->GetY() + 1.5;
$pdf->SetY($payY);
$pdf->SetX(12);

$m_raw = $meta['payment_method'] ?? '';
$m_lower = strtolower($m_raw);

$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetTextColor(22, 101, 52);
$pdf->Cell(35, 4, 'Settlement Gateway:', 0, 0);
$pdf->SetFont('Arial', 'B', 8);

if (strpos($m_lower, 'm-pesa') !== false || strpos($m_lower, 'mpesa') !== false) {
    $pdf->Cell(0, 4, 'eCitizen Lipa na M-Pesa (Paybill: 222222, Acc: YALA-' . $meta['opd_number'] . ')', 0, 1);
    $pdf->SetX(12);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(35, 4, 'Transaction Confirmation:', 0, 0);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 4, 'Safaricom Code: ' . ($meta['reference_no'] ?: 'CONFIRMED') . ' (Credited to CRF)', 0, 1);
} elseif (strpos($m_lower, 'insurance') !== false || $meta['payment_type'] === 'Insurance' || $meta['payment_type'] === 'Split') {
    $ins_disp = $meta['insurer_name'] ?: ($meta['insurance_provider'] ?: 'SHA / NHIF');
    $pdf->Cell(0, 4, 'Health Insurance Scheme: ' . substr($ins_disp, 0, 38), 0, 1);
    $pdf->SetX(12);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(35, 4, 'Policy / Claim Ref:', 0, 0);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(15, 23, 42);
    $cl_code = $meta['insurance_claim_no'] ?: ($meta['claim_ref'] ?: $meta['reference_no']);
    $m_no = $meta['insurance_member_no'] ?: 'Card-Verified';
    $pdf->Cell(0, 4, 'Member: ' . $m_no . ' | Pre-Auth Claim: ' . $cl_code, 0, 1);
} elseif (strpos($m_lower, 'waiver') !== false) {
    $pdf->Cell(0, 4, 'Statutory Fee Exemption (PFM Act 2012, Section 32)', 0, 1);
    $pdf->SetX(12);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(35, 4, 'Authority & Justification:', 0, 0);
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 4, substr($meta['waiver_reason'] ?: 'Authorized by Medical Superintendent', 0, 60), 0, 1);
} else {
    // eCitizen Direct / Bank PRN
    $pdf->Cell(0, 4, 'eCitizen Direct Portal / Bank Agent (Paybill: 222222)', 0, 1);
    $pdf->SetX(12);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(35, 4, 'eCitizen PRN Reference:', 0, 0);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 4, ($meta['reference_no'] ?: 'PRN-222222') . ' (Direct County Revenue Remittance)', 0, 1);
}

$pdf->Ln(4);

// Table of Laboratory Investigations Ordered
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(241, 245, 249);
$pdf->Cell(12, 6, '#', 1, 0, 'C', true);
$pdf->Cell(86, 6, 'Investigation / Diagnostic Service (Gazetted Tariff)', 1, 0, 'L', true);
$pdf->Cell(30, 6, 'Tariff (KES)', 1, 1, 'R', true);

$pdf->SetFont('Arial', '', 7.5);
$count = 1;
$subtotal = 0;

if (empty($tests)) {
    $pdf->Cell(128, 6, 'Standard Outpatient Diagnostic Panel', 1, 1, 'L');
    $subtotal = floatval($meta['amount_paid'] ?: 0);
} else {
    foreach ($tests as $t) {
        $pdf->Cell(12, 5.5, $count++, 1, 0, 'C');
        $pdf->Cell(86, 5.5, '  ' . substr($t['test_name'], 0, 46), 1, 0, 'L');
        $cost = floatval($t['cost']);
        $pdf->Cell(30, 5.5, number_format($cost, 2) . '  ', 1, 1, 'R');
        $subtotal += $cost;
    }
}

if ($subtotal == 0 && floatval($meta['amount_paid']) > 0) {
    $subtotal = floatval($meta['amount_paid']);
}

// Financial Summary Box
$pdf->Ln(2);
$sumY = $pdf->GetY();

$pdf->SetFont('Arial', 'B', 8.5);
$pdf->Cell(98, 5.5, 'GROSS BILLED TARIFF:', 0, 0, 'R');
$pdf->Cell(30, 5.5, 'KES ' . number_format($subtotal, 2) . '  ', 0, 1, 'R');

if (strpos($m_lower, 'insurance') !== false || $meta['payment_type'] === 'Insurance' || $meta['payment_type'] === 'Split') {
    $copay_val = floatval($meta['copay_amount'] ?: $meta['patient_copay']);
    $ins_val   = max(0, $subtotal - $copay_val);

    $pdf->SetFont('Arial', '', 7.5);
    $ins_label = 'Covered by ' . substr($meta['insurer_name'] ?: ($meta['insurance_provider'] ?: 'SHA'), 0, 25) . ':';
    $pdf->Cell(98, 4.5, $ins_label, 0, 0, 'R');
    $pdf->Cell(30, 4.5, 'KES ' . number_format($ins_val, 2) . '  ', 0, 1, 'R');

    if ($copay_val > 0) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(194, 65, 12);
        $pdf->Cell(98, 4.5, 'Patient Copay Paid via eCitizen (222222):', 0, 0, 'R');
        $pdf->Cell(30, 4.5, 'KES ' . number_format($copay_val, 2) . '  ', 0, 1, 'R');
    }
} elseif (strpos($m_lower, 'waiver') !== false) {
    $pdf->SetFont('Arial', 'I', 7.5);
    $pdf->SetTextColor(180, 83, 9);
    $pdf->Cell(98, 4.5, 'Statutory Fee Exemption (100% Waived):', 0, 0, 'R');
    $pdf->Cell(30, 4.5, 'KES 0.00  ', 0, 1, 'R');
} else {
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetTextColor(16, 122, 68);
    $pdf->Cell(98, 5, 'TOTAL SETTLED VIA eCITIZEN (222222):', 0, 0, 'R');
    $pdf->Cell(30, 5, 'KES ' . number_format(floatval($meta['amount_paid']), 2) . '  ', 0, 1, 'R');
}

// Outstanding balance line (Always 0.00 on clearance receipt)
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(98, 4.5, 'OUTSTANDING BALANCE DUE:', 0, 0, 'R');
$pdf->SetTextColor(16, 122, 68);
$pdf->Cell(30, 4.5, 'KES 0.00 (CLEARED)  ', 0, 1, 'R');

$pdf->Output('I', 'eCitizen_Receipt_' . $meta['opd_number'] . '.pdf');
