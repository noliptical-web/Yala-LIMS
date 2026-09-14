<?php
// notifications_poll.php
// Real-time asynchronous polling endpoint for header badge updates and clinical panic alerts
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

if (!isset($_SESSION['loggedin'])) {
    echo json_encode(['status' => 'unauthorized', 'unread_count' => 0]);
    exit;
}

require_once 'includes/db_connect.php';
require_once 'includes/notifications_helper.php';

$role   = $_SESSION['role'] ?? '';
$my_uid = intval($_SESSION['id'] ?? ($_SESSION['user_id'] ?? 0));
$action = $_REQUEST['action'] ?? 'poll';

if ($action === 'mark_all_read') {
    if ($role === 'Admin') {
        $conn->query("UPDATE notifications SET is_read = 1");
    } elseif ($role === 'Doctor') {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE target_role = 'Doctor' AND (target_user_id = $my_uid OR target_user_id IS NULL)");
    } else {
        $r_esc = $conn->real_escape_string($role);
        $conn->query("UPDATE notifications SET is_read = 1 WHERE target_role = '$r_esc'");
    }
    echo json_encode(['status' => 'success', 'unread_count' => 0]);
    exit;
}

if ($action === 'mark_single_read') {
    $nid = intval($_REQUEST['id'] ?? 0);
    if ($nid > 0) {
        if ($role === 'Admin') {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ?");
            $stmt->bind_param("i", $nid);
        } elseif ($role === 'Doctor') {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ? AND (target_role = 'Doctor' AND (target_user_id = ? OR target_user_id IS NULL))");
            $stmt->bind_param("ii", $nid, $my_uid);
        } else {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ? AND target_role = ?");
            $stmt->bind_param("is", $nid, $role);
        }
        $stmt->execute();
        $stmt->close();
    }
    $unread_count = get_unread_notifications_count($conn, $role, $my_uid);
    echo json_encode(['status' => 'success', 'unread_count' => $unread_count]);
    exit;
}

// Default action: poll
$last_seen_id = intval($_GET['last_id'] ?? 0);
$unread_count = get_unread_notifications_count($conn, $role, $my_uid);
$list = get_user_notifications($conn, $role, $my_uid, 7, false);

$formatted_list = [];
$has_new_critical = false;
$critical_alerts = [];

foreach ($list as $n) {
    $is_unread = ($n['is_read'] == 0);
    $is_critical = ($n['severity'] === 'Critical');

    if ($is_unread && $is_critical && intval($n['notif_id']) > $last_seen_id) {
        $has_new_critical = true;
        $critical_alerts[] = [
            'id'       => intval($n['notif_id']),
            'message'  => $n['message'],
            'link'     => $n['link'],
            'time_str' => format_notification_time($n['created_at'])
        ];
    }

    $formatted_list[] = [
        'id'         => intval($n['notif_id']),
        'severity'   => $n['severity'] ?? 'Info',
        'category'   => $n['category'] ?? 'General',
        'message'    => $n['message'],
        'link'       => $n['link'],
        'is_read'    => intval($n['is_read']),
        'time_str'   => format_notification_time($n['created_at'])
    ];
}

$latest_id = !empty($list) ? intval($list[0]['notif_id']) : $last_seen_id;

echo json_encode([
    'status'           => 'success',
    'unread_count'     => $unread_count,
    'latest_id'        => $latest_id,
    'has_new_critical' => $has_new_critical,
    'critical_alerts'  => $critical_alerts,
    'notifications'    => $formatted_list
]);
