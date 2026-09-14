<?php
// includes/notifications_helper.php
// Centralized notification management and clinical alert dispatch library

if (!function_exists('create_notification')) {
    /**
     * Dispatch an in-app alert or clinical notification
     * 
     * @param mysqli $conn
     * @param string $target_role e.g. 'Doctor', 'LabTech', 'Receptionist', 'Admin'
     * @param string $message
     * @param string $link relative page URL e.g. 'enter_results.php?manage_id=10'
     * @param string $severity 'Critical', 'Warning', 'Success', 'Info'
     * @param string $category 'Panic Value', 'Lab Order', 'Lab Result', 'Billing', 'Claim', 'Specimen QA', 'Blood Bank'
     * @param int|null $target_user_id Specific user ID or null for entire role
     * @return bool
     */
    function create_notification(mysqli $conn, string $target_role, string $message, string $link = '', string $severity = 'Info', string $category = 'General', ?int $target_user_id = null): bool {
        $valid_severities = ['Critical', 'Warning', 'Success', 'Info'];
        if (!in_array($severity, $valid_severities)) {
            $severity = 'Info';
        }

        $stmt = $conn->prepare("INSERT INTO notifications (target_role, target_user_id, severity, category, message, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");
        if (!$stmt) return false;

        $stmt->bind_param("sissss", $target_role, $target_user_id, $severity, $category, $message, $link);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }
}

if (!function_exists('get_unread_notifications_count')) {
    /**
     * Get count of unread notifications for the active user session
     */
    function get_unread_notifications_count(mysqli $conn, string $role, ?int $user_id = null): int {
        if ($role === 'Admin') {
            $res = $conn->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0");
            return $res ? intval($res->fetch_row()[0]) : 0;
        } elseif ($role === 'Doctor') {
            $uid = intval($user_id ?? 0);
            $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE target_role = 'Doctor' AND (target_user_id = ? OR target_user_id IS NULL) AND is_read = 0");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $cnt = intval($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();
            return $cnt;
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE target_role = ? AND is_read = 0");
            $stmt->bind_param("s", $role);
            $stmt->execute();
            $cnt = intval($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();
            return $cnt;
        }
    }
}

if (!function_exists('get_user_notifications')) {
    /**
     * Fetch list of notifications for the user
     */
    function get_user_notifications(mysqli $conn, string $role, ?int $user_id = null, int $limit = 10, bool $unread_only = false): array {
        $unread_clause = $unread_only ? "AND is_read = 0" : "";
        if ($role === 'Admin') {
            $sql = "SELECT * FROM notifications WHERE 1=1 $unread_clause ORDER BY created_at DESC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $limit);
        } elseif ($role === 'Doctor') {
            $uid = intval($user_id ?? 0);
            $sql = "SELECT * FROM notifications WHERE target_role = 'Doctor' AND (target_user_id = ? OR target_user_id IS NULL) $unread_clause ORDER BY created_at DESC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $uid, $limit);
        } else {
            $sql = "SELECT * FROM notifications WHERE target_role = ? $unread_clause ORDER BY created_at DESC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $role, $limit);
        }

        if (!$stmt->execute()) return [];
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }
}

if (!function_exists('format_notification_time')) {
    /**
     * Convert timestamp to human-friendly relative time
     */
    function format_notification_time(string $datetime): string {
        $time = strtotime($datetime);
        $diff = time() - $time;

        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = max(1, floor($diff / 60));
            return $mins . 'm ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . 'h ago';
        } elseif ($diff < 172800) {
            return 'Yesterday at ' . date('H:i', $time);
        } else {
            return date('d M Y, H:i', $time);
        }
    }
}
