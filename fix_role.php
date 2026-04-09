<?php
// fix_role.php — TEMPORARY, delete after use
session_start();
require_once 'includes/db_connect.php';

// Only accessible if already logged in as Admin, OR via direct DB query below
$msg = '';

// Show all users and their roles
$users = $conn->query("SELECT user_id, full_name, username, role FROM users ORDER BY full_name");
echo "<style>body{font-family:sans-serif;padding:20px;max-width:700px}
table{width:100%;border-collapse:collapse}td,th{border:1px solid #ddd;padding:8px;text-align:left}
th{background:#f3f4f6}tr:hover{background:#f9fafb}
.form-row{display:flex;gap:10px;margin-top:20px;align-items:flex-end}
input,select{padding:8px;border:1px solid #ddd;border-radius:6px;font-size:14px}
button{padding:9px 18px;background:#1d4ed8;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px}
.ok{color:green;font-weight:bold}.empty{color:red;font-weight:bold}
</style>";

// Handle role update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['new_role'])) {
    $uid  = intval($_POST['user_id']);
    $role = $_POST['new_role'];
    $allowed = ['Doctor','Receptionist','Cashier','LabTech','Admin'];
    if ($uid > 0 && in_array($role, $allowed)) {
        $stmt = $conn->prepare("UPDATE users SET role=? WHERE user_id=?");
        $stmt->bind_param("si", $role, $uid);
        if ($stmt->execute()) {
            $msg = "✓ Role updated to '$role' for user ID $uid. Please log out and log back in.";
        } else {
            $msg = "Error: " . $conn->error;
        }
        $stmt->close();
    }
}

if ($msg) echo "<p style='background:#f0fdf4;border:1px solid #86efac;padding:10px 14px;border-radius:8px;color:#166534'>$msg</p>";

echo "<h2 style='margin-bottom:12px'>All Users — Current Roles</h2>";
echo "<table><tr><th>ID</th><th>Name</th><th>Username</th><th>Role (raw)</th><th>Status</th></tr>";
while ($u = $users->fetch_assoc()) {
    $empty = empty(trim($u['role']));
    echo "<tr>
        <td>{$u['user_id']}</td>
        <td>" . htmlspecialchars($u['full_name']) . "</td>
        <td><code>" . htmlspecialchars($u['username']) . "</code></td>
        <td>" . ($empty ? "<span class='empty'>EMPTY/NULL</span>" : "<span class='ok'>" . htmlspecialchars($u['role']) . "</span>") . "</td>
        <td>" . ($empty ? "⚠ Needs fix" : "OK") . "</td>
    </tr>";
}
echo "</table>";

echo "<h3 style='margin-top:24px'>Fix a User's Role</h3>";
echo "<form method='post'>
<div class='form-row'>
    <div><label style='display:block;font-size:13px;margin-bottom:4px'>User ID</label>
    <input type='number' name='user_id' placeholder='e.g. 5' required></div>
    <div><label style='display:block;font-size:13px;margin-bottom:4px'>New Role</label>
    <select name='new_role'>
        <option value='Doctor'>Doctor</option>
        <option value='Receptionist'>Receptionist</option>
        <option value='Cashier' selected>Cashier</option>
        <option value='LabTech'>Lab Technician</option>
        <option value='Admin'>Admin</option>
    </select></div>
    <button type='submit'>Update Role</button>
</div>
</form>";

echo "<p style='margin-top:24px;font-size:13px;color:#dc2626'><strong>Delete this file from your server when done.</strong></p>";
