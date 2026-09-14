<?php
// includes/sms.php
// Africa's Talking SMS integration
// Docs: https://developers.africastalking.com/docs/sms/sending

define('AT_API_KEY',  'YOUR_AFRICASTALKING_API_KEY'); // replace this
define('AT_USERNAME', 'YOUR_AT_USERNAME');             // replace this (use 'sandbox' for testing)
define('AT_SENDER',   'YalaHosp');                     // your registered sender ID

function send_sms(mysqli $conn, int $request_id, string $phone, string $message): bool {
    if (!$phone || strlen($phone) < 9) return false;

    // Normalise phone: 07XX → +2547XX
    $phone = preg_replace('/\D/', '', $phone);
    if (str_starts_with($phone, '0')) $phone = '+254' . substr($phone, 1);
    if (!str_starts_with($phone, '+')) $phone = '+' . $phone;

    // Simulation fallback if API keys are default placeholders
    if (AT_API_KEY === 'YOUR_AFRICASTALKING_API_KEY' || AT_USERNAME === 'YOUR_AT_USERNAME') {
        $status = 'simulated';
        $ref    = 'SIM-AT-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 10));
        $msg_e  = $conn->real_escape_string(substr($message, 0, 500));
        $ph_e   = $conn->real_escape_string($phone);
        $conn->query("INSERT INTO sms_log (request_id,phone,message,status,provider_ref,sent_at)
                      VALUES ($request_id,'$ph_e','$msg_e','$status','$ref',NOW())");
        return true;
    }

    $payload = http_build_query([
        'username' => AT_USERNAME,
        'to'       => $phone,
        'message'  => $message,
        'from'     => AT_SENDER,
    ]);

    $ch = curl_init('https://api.africastalking.com/version1/messaging');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apiKey: ' . AT_API_KEY,
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $ok = !curl_errno($ch);
    curl_close($ch);

    $status = $ok ? 'sent' : 'failed';
    $ref    = $conn->real_escape_string(substr($response ?? '', 0, 100));
    $msg_e  = $conn->real_escape_string(substr($message, 0, 500));
    $ph_e   = $conn->real_escape_string($phone);
    $conn->query("INSERT INTO sms_log (request_id,phone,message,status,provider_ref,sent_at)
                  VALUES ($request_id,'$ph_e','$msg_e','$status','$ref',NOW())");
    return $ok;
}

function sms_results_ready(mysqli $conn, int $request_id): void {
    $stmt = $conn->prepare(
        "SELECT p.full_name, p.phone_number, p.opd_number
         FROM lab_requests r
         JOIN patients p ON r.patient_id = p.patient_id
         WHERE r.request_id = ?"
    );
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['phone_number'])) return;

    $name = $row['full_name'];
    $opd  = $row['opd_number'];
    $msg  = "Dear $name, your lab results (Req #{$request_id}, OPD $opd) are ready. "
          . "Visit Yala Sub-County Hospital or ask your doctor. Thank you.";

    send_sms($conn, $request_id, $row['phone_number'], $msg);
}

function sms_payment_receipt(mysqli $conn, int $request_id, float $amount, string $method, string $ref): void {
    $stmt = $conn->prepare(
        "SELECT p.full_name, p.phone_number, p.opd_number
         FROM lab_requests r
         JOIN patients p ON r.patient_id = p.patient_id
         WHERE r.request_id = ?"
    );
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['phone_number'])) return;

    $name = $row['full_name'];
    $opd  = $row['opd_number'];
    $amt_fmt = number_format($amount, 2);
    $msg = "Dear $name, payment of KES $amt_fmt via $method (Ref: $ref) received at Yala Sub-County Hospital for Lab Req #$request_id (OPD $opd). Receipt cleared.";

    send_sms($conn, $request_id, $row['phone_number'], $msg);
}
