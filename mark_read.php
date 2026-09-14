<?php
// mark_read.php
// Marks a notification as read and redirects safely to the target hospital module or returns JSON for AJAX
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'includes/db_connect.php';

if (!isset($_SESSION['loggedin'])) {
    ob_end_clean();
    if (isset($_REQUEST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'unauthorized']);
        exit;
    }
    header("Location: index.php");
    exit();
}

$role   = $_SESSION['role'] ?? '';
$my_uid = intval($_SESSION['id'] ?? ($_SESSION['user_id'] ?? 0));
$notif_id = intval($_REQUEST['id'] ?? 0);

if ($notif_id > 0) {
    if ($role === 'Admin') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ?");
        $stmt->bind_param("i", $notif_id);
    } elseif ($role === 'Doctor') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ? AND (target_role = 'Doctor' AND (target_user_id = ? OR target_user_id IS NULL))");
        $stmt->bind_param("ii", $notif_id, $my_uid);
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ? AND target_role = ?");
        $stmt->bind_param("is", $notif_id, $role);
    }
    $stmt->execute();
    $stmt->close();
}

// Check for AJAX response
if (isset($_REQUEST['ajax'])) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['status' => 'success', 'notif_id' => $notif_id]);
    exit;
}

// Redirect handling with expanded hospital whitelist
$allowed_pages = [
    'enter_results.php',
    'print_report.php',
    'request_test.php',
    'billing.php',
    'dashboard.php',
    'manage_users.php',
    'patient_history.php',
    'patient_profile.php',
    'insurance_claims.php',
    'sample_rejection.php',
    'blood_bank.php',
    'inventory.php',
    'qc_log.php',
    'notifications.php',
    'reports.php',
    'print_receipt.php',
    'print_routing_slip.php',
    'print_claim.php'
];

$raw_link = trim($_GET['link'] ?? '');
$target = 'dashboard.php';

if (!empty($raw_link)) {
    $parsed_path = parse_url($raw_link, PHP_URL_PATH);
    $page_base   = basename($parsed_path);
    $query_str   = parse_url($raw_link, PHP_URL_QUERY);

    if (in_array($page_base, $allowed_pages)) {
        $target = $page_base . ($query_str ? '?' . $query_str : '');
    }
}

ob_end_clean();
header("Location: " . $target);
exit();
