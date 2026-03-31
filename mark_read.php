<?php
ob_start();
session_start();
include('includes/db_connect.php');

if (!isset($_SESSION['loggedin'])) {
    ob_end_clean();
    header("Location: index.php");
    exit();
}

if (isset($_GET['id']) && isset($_GET['link'])) {
    $notif_id = intval($_GET['id']); // Safe integer cast

    // Whitelist allowed pages to prevent open redirect
    $allowed = ['enter_results.php','print_report.php','request_test.php','billing.php','dashboard.php','manage_users.php'];
    $parsed  = parse_url($_GET['link'], PHP_URL_PATH);
    $page    = basename($parsed);
    $query   = parse_url($_GET['link'], PHP_URL_QUERY);
    $target  = in_array($page, $allowed) ? $page . ($query ? '?'.$query : '') : 'dashboard.php';

    // Mark as read
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ?");
    $stmt->bind_param("i", $notif_id);
    $stmt->execute();
    $stmt->close();

    ob_end_clean();
    header("Location: " . $target);
    exit();
} else {
    ob_end_clean();
    header("Location: dashboard.php");
    exit();
}
?>