<?php
session_start();
define('FPDF_FONTPATH','fpdf/font/');
require('fpdf/fpdf.php');
require_once 'includes/db_connect.php';

if (!isset($_GET['id'])) { die("Error: No ID specified."); }
$req_id = intval($_GET['id']);

$sql = "SELECT p.full_name, p.opd_number, r.request_date,
               pay.amount_paid, pay.payment_method, pay.reference_no,
               pay.insurance_provider, pay.insurance_member_no,
               pay.insurance_claim_no, pay.waiver_reason, pay.received_by
        FROM lab_requests r
        JOIN patients p   ON r.patient_id   = p.patient_id
        JOIN payments pay ON r.request_id   = pay.request_id
        WHERE r.request_id = $req_id";
$meta = $conn->query($sql)->fetch_assoc();
if (!$meta) { die("Error: Payment not found."); }

$items = $conn->query(
    "SELECT t.test_name, t.cost
     FROM test_results tr
     JOIN lab_tests t ON tr.test_id = t.test_id
     WHERE tr.request_id = $req_id"
);

$pdf = new FPDF('P','mm','A5');
$pdf->AddPage();

// Logo
if (file_exists('logo.png')) $pdf->Image('logo.png', 10, 8, 20);

// Header
$pdf->Cell(20);
$pdf->SetFont('Arial','B',12);
$pdf->Cell(0,6,'YALA SUB-COUNTY HOSPITAL',0,1,'C');
$pdf->Cell(20);
$pdf->SetFont('Arial','',9);
$pdf->Cell(0,5,'Department of Laboratory Services',0,1,'C');
$pdf->Cell(20);
$pdf->SetFont('Arial','B',10);
$pdf->Cell(0,5,'OFFICIAL RECEIPT',0,1,'C');
$pdf->Ln(6);

// Patient details
$pdf->SetFont('Arial','',10);
function addRow($pdf,$label,$value){$pdf->Cell(35,6,$label,0,0);$pdf->Cell(0,6,$value,0,1);}
addRow($pdf,'Date:',      date('d-M-Y H:i'));
addRow($pdf,'Patient:',   $meta['full_name']);
addRow($pdf,'OPD No:',    $meta['opd_number']);

// Payment method line
$method = $meta['payment_method'];
if ($method === 'Insurance') {
    addRow($pdf,'Payment:',   'Insurance — '.$meta['insurance_provider']);
    addRow($pdf,'Member No:', $meta['insurance_member_no']);
    if ($meta['insurance_claim_no']) addRow($pdf,'Claim No:', $meta['insurance_claim_no']);
} elseif ($method === 'Waiver') {
    addRow($pdf,'Payment:',  'WAIVER — '.$meta['waiver_reason']);
} elseif ($method === 'Cash') {
    addRow($pdf,'Payment:', 'Cash — Ref: '.($meta['reference_no']?:'N/A'));
} else {
    addRow($pdf,'Payment:', 'M-Pesa ('.$meta['reference_no'].')');
}
if ($meta['received_by']) addRow($pdf,'Received by:', $meta['received_by']);

$pdf->Ln(2);
$pdf->Line(10,$pdf->GetY(),138,$pdf->GetY());
$pdf->Ln(2);

// Items
$pdf->SetFont('Arial','B',10);
$pdf->Cell(85,7,'Description',0,0);
$pdf->Cell(35,7,'Amount',0,1,'R');
$pdf->SetFont('Arial','',10);
$total = 0;
while ($row = $items->fetch_assoc()) {
    $pdf->Cell(85,7,$row['test_name'],0,0);
    $cost = ($method === 'Waiver') ? 0 : $row['cost'];
    $pdf->Cell(35,7,number_format($cost,2),0,1,'R');
    $total += $cost;
}

$pdf->Ln(2);
$pdf->Line(10,$pdf->GetY(),138,$pdf->GetY());
$pdf->Ln(2);

$pdf->SetFont('Arial','B',12);
$pdf->Cell(85,8,'TOTAL PAID:',0,0);
$paid = ($method === 'Waiver') ? 0 : $meta['amount_paid'];
$pdf->Cell(35,8,'Ksh '.number_format($paid,2),0,1,'R');

if ($method === 'Insurance') {
    $pdf->SetFont('Arial','I',9);
    $pdf->Cell(0,5,'* Balance to be claimed from '.$meta['insurance_provider'],0,1,'C');
}
if ($method === 'Waiver') {
    $pdf->SetFont('Arial','I',9);
    $pdf->Cell(0,5,'* Fee waived. Authorised by: '.$meta['received_by'],0,1,'C');
}

$pdf->Ln(8);
$pdf->SetFont('Arial','I',8);
$pdf->Cell(0,5,'Get Well Soon.',0,1,'C');

$pdf->Output();
