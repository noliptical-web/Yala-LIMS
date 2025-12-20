<?php
// print_receipt.php
session_start();

define('FPDF_FONTPATH','fpdf/font/');
require('fpdf/fpdf.php');
require_once 'includes/db_connect.php';

// Check if ID exists
if (!isset($_GET['id'])) { die("Error: No ID specified."); }
$req_id = $_GET['id'];

// FETCH BILL DETAILS
$sql = "SELECT p.full_name, p.opd_number, r.request_date, pay.amount_paid, pay.payment_method, pay.reference_no
        FROM lab_requests r
        JOIN patients p ON r.patient_id = p.patient_id
        JOIN payments pay ON r.request_id = pay.request_id
        WHERE r.request_id = $req_id";
$meta = $conn->query($sql)->fetch_assoc();

if(!$meta) { die("Error: Payment not found for this request."); }

// FETCH ITEMS
$sql_items = "SELECT t.test_name, t.cost 
              FROM test_results tr 
              JOIN lab_tests t ON tr.test_id = t.test_id 
              WHERE tr.request_id = $req_id";
$items = $conn->query($sql_items);

// START PDF (A5 Format for Receipt)
$pdf = new FPDF('P','mm','A5');
$pdf->AddPage();

// --- 1. LOGO SECTION ---
if(file_exists('logo.png')) {
    // Image(filename, x, y, width)
    $pdf->Image('logo.png', 10, 8, 20); 
}

// --- 2. HEADER TEXT ---
// Move cursor to the right (25mm) so text doesn't hit the logo
$pdf->Cell(20); 

$pdf->SetFont('Arial','B',12);
$pdf->Cell(0, 6,'YALA SUB-COUNTY HOSPITAL', 0, 1, 'C');

$pdf->Cell(20); // Move right again for next line
$pdf->SetFont('Arial','',9);
$pdf->Cell(0, 5,'Department of Laboratory Services', 0, 1, 'C');

$pdf->Cell(20); // Move right again
$pdf->SetFont('Arial','B',10);
$pdf->Cell(0, 5,'OFFICIAL RECEIPT', 0, 1, 'C');

$pdf->Ln(8); // Space before details

// --- 3. RECEIPT DETAILS ---
$pdf->SetFont('Arial','',10);

// Helper function to make rows aligned
function addRow($pdf, $label, $value) {
    $pdf->Cell(30, 6, $label, 0, 0); 
    $pdf->Cell(0, 6, $value, 0, 1);
}

addRow($pdf, 'Date:', date('d-M-Y H:i'));
addRow($pdf, 'Patient:', $meta['full_name']);
addRow($pdf, 'OPD No:', $meta['opd_number']);
addRow($pdf, 'Payment:', $meta['payment_method'] . ' (' . $meta['reference_no'] . ')');

$pdf->Ln(3);
$pdf->Line(10, $pdf->GetY(), 138, $pdf->GetY());
$pdf->Ln(2);

// --- 4. ITEMS LIST ---
$pdf->SetFont('Arial','B',10);
$pdf->Cell(85, 7, 'Description', 0, 0);
$pdf->Cell(35, 7, 'Amount', 0, 1, 'R');

$pdf->SetFont('Arial','',10);
$total = 0;
while($row = $items->fetch_assoc()) {
    $pdf->Cell(85, 7, $row['test_name'], 0, 0);
    $pdf->Cell(35, 7, number_format($row['cost'], 2), 0, 1, 'R');
    $total += $row['cost'];
}

$pdf->Ln(2);
$pdf->Line(10, $pdf->GetY(), 138, $pdf->GetY());
$pdf->Ln(2);

// --- 5. TOTAL & FOOTER ---
$pdf->SetFont('Arial','B',12);
$pdf->Cell(85, 8, 'TOTAL PAID:', 0, 0);
$pdf->Cell(35, 8, 'Ksh '.number_format($total, 2), 0, 1, 'R');

$pdf->Ln(10);
$pdf->SetFont('Arial','I',8);
$pdf->Cell(0, 5, 'You were served by: ' . (isset($_SESSION['username']) ? $_SESSION['username'] : 'System'), 0, 1, 'C');
$pdf->Cell(0, 5, 'Get Well Soon.', 0, 1, 'C');

$pdf->Output();
?>