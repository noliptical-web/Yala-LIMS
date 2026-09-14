<?php
// notifications.php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$role   = $_SESSION['role'] ?? '';
$my_uid = intval($_SESSION['id'] ?? 0);
$success = $error = "";

// Mark single as read
if (isset($_GET['read']) && intval($_GET['read']) > 0) {
    $nid = intval($_GET['read']);
    // Check permission: only mark if it belongs to role or user, or if admin
    if ($role === 'Admin') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ?");
        $stmt->bind_param("i", $nid);
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ? AND (target_role = ? OR target_user_id = ?)");
        $stmt->bind_param("isi", $nid, $role, $my_uid);
    }
    if ($stmt->execute()) {
        $success = "Alert marked as read.";
    }
    $stmt->close();
}

// Mark all as read
if (isset($_POST['mark_all_read'])) {
    csrf_verify();
    if ($role === 'Admin') {
        $conn->query("UPDATE notifications SET is_read = 1");
    } elseif ($role === 'Doctor') {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE target_role = 'Doctor' AND (target_user_id = $my_uid OR target_user_id IS NULL)");
    } else {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE target_role = '$role'");
    }
    $success = "All alerts marked as read.";
}

// Load notifications
if ($role === 'Admin') {
    $sql = "SELECT * FROM notifications ORDER BY created_at DESC LIMIT 200";
    $stmt_load = $conn->prepare($sql);
} elseif ($role === 'Doctor') {
    $sql = "SELECT * FROM notifications WHERE target_role = 'Doctor' AND (target_user_id = ? OR target_user_id IS NULL) ORDER BY created_at DESC LIMIT 200";
    $stmt_load = $conn->prepare($sql);
    $stmt_load->bind_param("i", $my_uid);
} else {
    $sql = "SELECT * FROM notifications WHERE target_role = ? ORDER BY created_at DESC LIMIT 200";
    $stmt_load = $conn->prepare($sql);
    $stmt_load->bind_param("s", $role);
}

$stmt_load->execute();
$notifs = $stmt_load->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_load->close();

$page_title = "Notifications Portal";
include 'includes/header.php';
$csrf = csrf_token();
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Serif+Display&display=swap');
.nw{max-width:850px;margin:0 auto;padding:0 0 48px;font-family:'DM Sans',sans-serif}
.nh{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.nh h1{font-family:'DM Serif Display',serif;font-size:1.85rem;color:#0f172a;margin:0 0 3px;letter-spacing:-.4px}
.nh p{font-size:.8rem;color:#94a3b8;margin:0}
.crumb{display:flex;align-items:center;gap:5px;font-size:.72rem;color:#94a3b8;margin-bottom:18px}
.crumb a{color:#94a3b8;text-decoration:none}.crumb a:hover{color:#1d4ed8}
.panel{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
.phead{padding:14px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.ptitle{font-size:.63rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8}
.nlist{padding:0;margin:0;list-style:none}
.ni{padding:16px 20px;border-bottom:1px solid #f8fafc;display:flex;gap:16px;align-items:flex-start;transition:all .15s}
.ni:last-child{border-bottom:none}
.ni:hover{background:#fafafa}
.ni.unread{background:#f8fafc;border-left:3.5px solid #1d4ed8}
.ni-dot{width:8px;height:8px;border-radius:50%;background:#1d4ed8;margin-top:6px;flex-shrink:0}
.ni-dot.read{background:#cbd5e1}
.ni-body{flex:1;min-width:0}
.ni-msg{font-size:.88rem;color:#0f172a;font-weight:500;margin:0 0 4px}
.ni.unread .ni-msg{font-weight:600}
.ni-meta{font-size:.75rem;color:#94a3b8;display:flex;align-items:center;gap:12px}
.ni-time{display:inline-flex;align-items:center;gap:4px}
.ni-role{text-transform:uppercase;font-size:.62rem;font-weight:700;letter-spacing:.5px;background:#e0f2fe;color:#0369a1;padding:2px 7px;border-radius:10px}
.ni-action{display:flex;gap:8px}
.btn-sm-action{font-size:.75rem;font-weight:600;padding:4px 10px;border-radius:6px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;border:1px solid #e2e8f0;background:#fff;color:#64748b;transition:all .15s}
.btn-sm-action:hover{border-color:#1d4ed8;color:#1d4ed8;background:#eff6ff}
.btn-sm-action.btn-go{background:#1d4ed8;color:#fff;border-color:#1d4ed8}
.btn-sm-action.btn-go:hover{background:#1e40af;color:#fff}
.n-empty{text-align:center;padding:56px 20px;color:#94a3b8}
.n-empty-ico{font-size:2.5rem;opacity:.3;margin-bottom:14px}
</style>

<div class="nw">
    <div class="crumb"><a href="dashboard.php"><i class="fa-solid fa-house-chimney"></i></a><span>&rsaquo;</span><span>Notifications</span></div>
    
    <div class="nh">
        <div>
            <h1>Notifications Portal</h1>
            <p>System alerts and job triggers for role: <strong><?= htmlspecialchars($role) ?></strong></p>
        </div>
        <?php if (!empty($notifs)): ?>
        <form method="post" action="notifications.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button type="submit" name="mark_all_read" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold">
                <i class="fa-solid fa-circle-check me-1"></i> Mark All as Read
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" style="border-radius:10px">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="panel shadow-sm">
        <div class="phead">
            <span class="ptitle">Notification Inbox (Showing last 200)</span>
            <span class="badge bg-primary rounded-pill"><?= count(array_filter($notifs, fn($n) => !$n['is_read'])) ?> Unread</span>
        </div>

        <?php if (empty($notifs)): ?>
        <div class="n-empty">
            <div class="n-empty-ico"><i class="fa-solid fa-bell-slash"></i></div>
            <h5>All clear!</h5>
            <p class="small mb-0">No notifications on record for your role.</p>
        </div>
        <?php else: ?>
        <ul class="nlist">
            <?php foreach ($notifs as $n):
                $isUnread = !$n['is_read'];
            ?>
            <li class="ni <?= $isUnread ? 'unread' : '' ?>">
                <div class="ni-dot <?= $isUnread ? '' : 'read' ?>"></div>
                <div class="ni-body">
                    <p class="ni-msg"><?= htmlspecialchars($n['message']) ?></p>
                    <div class="ni-meta">
                        <span class="ni-time"><i class="fa-regular fa-clock"></i> <?= date('d M Y · H:i', strtotime($n['created_at'])) ?></span>
                        <span class="ni-role"><?= htmlspecialchars($n['target_role']) ?></span>
                    </div>
                </div>
                <div class="ni-action">
                    <?php if ($isUnread): ?>
                        <a href="notifications.php?read=<?= $n['notif_id'] ?>" class="btn-sm-action" title="Mark as Read">
                            <i class="fa-solid fa-check"></i>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($n['link'])): ?>
                        <a href="<?= htmlspecialchars($n['link']) ?>" class="btn-sm-action btn-go">
                            Action <i class="fa-solid fa-angle-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
