<?php
session_start();
require_once 'includes/db_connect.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] != 'Admin') { header("location: dashboard.php"); exit; }

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$filter_user = trim($_GET['user'] ?? '');
$filter_action = trim($_GET['action'] ?? '');

$where = ["DATE(created_at) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];
$types  = "ss";

if ($filter_user) { $where[] = "username LIKE ?"; $params[] = "%$filter_user%"; $types .= "s"; }
if ($filter_action){ $where[] = "action = ?"; $params[] = $filter_action; $types .= "s"; }

$sql = "SELECT * FROM audit_log WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT 500";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$actions = $conn->query("SELECT DISTINCT action FROM audit_log ORDER BY action");

$page_title = "Audit Log";
include 'includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Serif+Display&display=swap');
.pw{width:100%;padding:0 0 48px;font-family:'DM Sans',sans-serif}
.ph{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.ph h1{font-family:'DM Serif Display',serif;font-size:1.85rem;color:#0f172a;margin:0 0 3px;letter-spacing:-.4px}
.ph p{font-size:.8rem;color:#94a3b8;margin:0}
.filter-bar{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.ff{display:flex;flex-direction:column;gap:4px}
.ff label{font-size:.68rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#94a3b8}
.ff input,.ff select{border:1.5px solid #e2e8f0;border-radius:7px;padding:8px 10px;font-size:.83rem;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .15s;background:#fafafa}
.ff input:focus,.ff select:focus{border-color:#3b82f6;background:#fff}
.btn-f{background:#1d4ed8;color:#fff;border:none;border-radius:7px;padding:9px 16px;font-size:.83rem;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;align-self:flex-end}
.panel{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
table.at{width:100%;border-collapse:collapse}
table.at thead th{padding:10px 16px;font-size:.63rem;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#94a3b8;text-align:left;border-bottom:1px solid #f1f5f9;white-space:nowrap}
table.at tbody tr{border-bottom:1px solid #f9fafb;transition:background .1s}
table.at tbody tr:hover{background:#fafafa}
table.at td{padding:11px 16px;font-size:.83rem;vertical-align:middle}
.act-tag{font-size:.67rem;font-weight:700;border-radius:20px;padding:2px 8px;background:#eff6ff;color:#1d4ed8}
.act-tag.delete{background:#fef2f2;color:#991b1b}
.act-tag.edit{background:#fffbeb;color:#92400e}
.act-tag.create{background:#f0fdf4;color:#166534}
.role-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px}
</style>

<div class="pw">
<div class="ph"><div><h1>Audit Log</h1><p>Full record of staff actions — last 500 entries</p></div></div>

<form method="get" class="filter-bar">
    <div class="ff"><label>From</label><input type="date" name="date_from" value="<?php echo $date_from; ?>"></div>
    <div class="ff"><label>To</label><input type="date" name="date_to" value="<?php echo $date_to; ?>"></div>
    <div class="ff"><label>User</label><input type="text" name="user" value="<?php echo htmlspecialchars($filter_user); ?>" placeholder="username…"></div>
    <div class="ff"><label>Action</label>
        <select name="action">
            <option value="">All actions</option>
            <?php while($a=$actions->fetch_assoc()): ?>
            <option value="<?php echo $a['action']; ?>" <?php echo $filter_action===$a['action']?'selected':''; ?>><?php echo $a['action']; ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <button type="submit" class="btn-f"><i class="fa-solid fa-filter me-1"></i> Filter</button>
</form>

<div class="panel">
<table class="at">
    <thead><tr><th>Time</th><th>User</th><th>Role</th><th>Action</th><th>Table</th><th>Row ID</th><th>Detail</th><th>IP</th></tr></thead>
    <tbody>
    <?php if(empty($logs)): ?>
    <tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8">No log entries for this period.</td></tr>
    <?php else: foreach($logs as $l):
        $ac = $l['action'];
        $cls = str_contains($ac,'delete')||str_contains($ac,'remove') ? 'delete' : (str_contains($ac,'edit')||str_contains($ac,'update') ? 'edit' : 'create');
        $roleColors = ['Admin'=>'#0f172a','Doctor'=>'#dc2626','Receptionist'=>'#0891b2','LabTech'=>'#16a34a'];
        $rc = $roleColors[$l['role']] ?? '#94a3b8';
    ?>
    <tr>
        <td style="white-space:nowrap;color:#64748b;font-size:.78rem"><?php echo date('d M Y H:i:s',strtotime($l['created_at'])); ?></td>
        <td style="font-weight:600;color:#0f172a"><?php echo htmlspecialchars($l['username']); ?></td>
        <td><span class="role-dot" style="background:<?php echo $rc; ?>"></span><span style="font-size:.78rem;color:#64748b"><?php echo $l['role']; ?></span></td>
        <td><span class="act-tag <?php echo $cls; ?>"><?php echo htmlspecialchars($ac); ?></span></td>
        <td style="font-size:.78rem;color:#64748b;font-family:monospace"><?php echo htmlspecialchars($l['affected_table']); ?></td>
        <td style="font-size:.78rem;color:#94a3b8"><?php echo $l['affected_row_id']?:'—'; ?></td>
        <td style="font-size:.78rem;color:#374151;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($l['detail']?:'—'); ?></td>
        <td style="font-size:.72rem;color:#94a3b8;font-family:monospace"><?php echo htmlspecialchars($l['ip_address']); ?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
</div>
<?php include 'includes/footer.php'; ?>
