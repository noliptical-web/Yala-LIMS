<?php
// includes/audit.php
// Logs every important action to the audit_log table.
// Usage: audit_log($conn, 'create_patient', 'patients', $new_id, 'OPD OP-2025-001');

function audit_log(mysqli $conn, string $action, string $table, int $row_id, string $detail = ''): void {
    $user_id  = intval($_SESSION['id']      ?? 0);
    $username = $_SESSION['username']        ?? 'system';
    $role     = $_SESSION['role']            ?? '';
    $ip       = $_SERVER['REMOTE_ADDR']      ?? '';
    $action   = $conn->real_escape_string($action);
    $table    = $conn->real_escape_string($table);
    $detail   = $conn->real_escape_string(substr($detail, 0, 500));
    $username = $conn->real_escape_string($username);
    $role     = $conn->real_escape_string($role);
    $ip       = $conn->real_escape_string($ip);
    $conn->query("INSERT INTO audit_log (user_id, username, role, action, affected_table, affected_row_id, detail, ip_address, created_at)
                  VALUES ($user_id, '$username', '$role', '$action', '$table', $row_id, '$detail', '$ip', NOW())");
}
