<?php
// notifications.php
// Hospital Clinical Alerts & Notifications Console — Yala Sub-County Hospital
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';
require_once 'includes/notifications_helper.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php"); exit;
}

$role   = $_SESSION['role'] ?? '';
$my_uid = intval($_SESSION['id'] ?? ($_SESSION['user_id'] ?? 0));
$success = $error = "";

// ── ACTION HANDLERS ─────────────────────────────────────────────────────────

// 1. Mark single as read / unread
if (isset($_GET['toggle_read']) && intval($_GET['toggle_read']) > 0) {
    $nid = intval($_GET['toggle_read']);
    $to_status = intval($_GET['to'] ?? 1);

    if ($role === 'Admin') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = ? WHERE notif_id = ?");
        $stmt->bind_param("ii", $to_status, $nid);
    } elseif ($role === 'Doctor') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = ? WHERE notif_id = ? AND (target_role = 'Doctor' AND (target_user_id = ? OR target_user_id IS NULL))");
        $stmt->bind_param("iii", $to_status, $nid, $my_uid);
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = ? WHERE notif_id = ? AND target_role = ?");
        $stmt->bind_param("iis", $to_status, $nid, $role);
    }

    if ($stmt->execute()) {
        $success = $to_status ? "Alert marked as read." : "Alert marked as unread.";
    }
    $stmt->close();
}

// 2. Mark single as read (legacy link support)
if (isset($_GET['read']) && intval($_GET['read']) > 0) {
    $nid = intval($_GET['read']);
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
    if ($stmt->execute()) {
        $success = "Alert marked as read.";
    }
    $stmt->close();
}

// 3. Mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    csrf_verify();
    if ($role === 'Admin') {
        $conn->query("UPDATE notifications SET is_read = 1");
    } elseif ($role === 'Doctor') {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE target_role = 'Doctor' AND (target_user_id = $my_uid OR target_user_id IS NULL)");
    } else {
        $r_esc = $conn->real_escape_string($role);
        $conn->query("UPDATE notifications SET is_read = 1 WHERE target_role = '$r_esc'");
    }
    audit_log($conn, 'mark_all_read', 'notifications', 0, "Marked all alerts read for $role ($my_uid)");
    $success = "All alerts marked as read.";
}

// 4. Clear/Dismiss all read notifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_read'])) {
    csrf_verify();
    if ($role === 'Admin') {
        $conn->query("DELETE FROM notifications WHERE is_read = 1");
    } elseif ($role === 'Doctor') {
        $conn->query("DELETE FROM notifications WHERE is_read = 1 AND target_role = 'Doctor' AND (target_user_id = $my_uid OR target_user_id IS NULL)");
    } else {
        $r_esc = $conn->real_escape_string($role);
        $conn->query("DELETE FROM notifications WHERE is_read = 1 AND target_role = '$r_esc'");
    }
    $success = "Cleared all previously read alerts from your inbox.";
}

// ── FETCH NOTIFICATIONS & METRICS ───────────────────────────────────────────
$filter_tab = $_GET['tab'] ?? 'all';

// Build filter clause
$role_clause = "";
if ($role === 'Admin') {
    $role_clause = "1=1";
} elseif ($role === 'Doctor') {
    $role_clause = "target_role = 'Doctor' AND (target_user_id = $my_uid OR target_user_id IS NULL)";
} else {
    $r_esc = $conn->real_escape_string($role);
    $role_clause = "target_role = '$r_esc'";
}

// Global counts for tabs
$count_all = $conn->query("SELECT COUNT(*) FROM notifications WHERE $role_clause")->fetch_row()[0] ?? 0;
$count_unread = $conn->query("SELECT COUNT(*) FROM notifications WHERE $role_clause AND is_read = 0")->fetch_row()[0] ?? 0;
$count_critical = $conn->query("SELECT COUNT(*) FROM notifications WHERE $role_clause AND (severity = 'Critical' OR category = 'Panic Value')")->fetch_row()[0] ?? 0;
$count_billing = $conn->query("SELECT COUNT(*) FROM notifications WHERE $role_clause AND category IN ('Billing', 'Claim')")->fetch_row()[0] ?? 0;
$count_lab = $conn->query("SELECT COUNT(*) FROM notifications WHERE $role_clause AND category IN ('Lab Order', 'Lab Result')")->fetch_row()[0] ?? 0;
$count_qa = $conn->query("SELECT COUNT(*) FROM notifications WHERE $role_clause AND category IN ('Specimen QA', 'Blood Bank')")->fetch_row()[0] ?? 0;

$sql = "SELECT * FROM notifications WHERE $role_clause";

if ($filter_tab === 'unread') {
    $sql .= " AND is_read = 0";
} elseif ($filter_tab === 'critical') {
    $sql .= " AND (severity = 'Critical' OR category = 'Panic Value')";
} elseif ($filter_tab === 'billing') {
    $sql .= " AND category IN ('Billing', 'Claim')";
} elseif ($filter_tab === 'lab') {
    $sql .= " AND category IN ('Lab Order', 'Lab Result')";
} elseif ($filter_tab === 'qa') {
    $sql .= " AND category IN ('Specimen QA', 'Blood Bank')";
}

$sql .= " ORDER BY created_at DESC LIMIT 250";
$res_n = $conn->query($sql);
$notifs = $res_n ? $res_n->fetch_all(MYSQLI_ASSOC) : [];

$csrf = csrf_token();
$page_title = "Clinical Alerts & Notifications — Yala LIMS";
include 'includes/header.php';
?>

<div class="container-fluid px-4 py-3" style="max-width:1100px;">

    <!-- Top Banner -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3"
         style="background:linear-gradient(135deg,#0f172a 0%,#1e293b 60%,#334155 100%);padding:22px 28px;border-radius:14px;color:#fff;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h3 class="fw-bold mb-0 text-white"><i class="fa-solid fa-bell me-2 text-warning"></i>Hospital Notifications &amp; Alerts</h3>
                <span class="badge bg-primary-subtle text-primary border px-2 py-1" style="font-size:0.75rem;">Role: <?= htmlspecialchars($role) ?></span>
            </div>
            <p class="mb-0 text-white-50" style="font-size:0.88rem;">
                Real-time clinical panic alerts, eCitizen payment clearances, specimen rejections, and laboratory order triggers.
            </p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <?php if ($count_unread > 0): ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" name="mark_all_read" class="btn btn-light fw-bold text-dark rounded-pill px-3 shadow-sm btn-sm">
                    <i class="fa-solid fa-circle-check text-success me-1"></i> Mark All as Read
                </button>
            </form>
            <?php endif; ?>

            <?php if (($count_all - $count_unread) > 0): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Clear all read notifications from your view?');">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" name="clear_read" class="btn btn-outline-light rounded-pill px-3 btn-sm">
                    <i class="fa-solid fa-broom me-1"></i> Clear Read
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert banners -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4 d-flex align-items-center gap-2" style="border-radius:10px;">
        <i class="fa-solid fa-circle-check fs-5 text-success"></i>
        <div><?= htmlspecialchars($success) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4 d-flex align-items-center gap-2" style="border-radius:10px;">
        <i class="fa-solid fa-circle-exclamation fs-5 text-danger"></i>
        <div><?= htmlspecialchars($error) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- KPI Summary Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#fff;border-left:4px solid #3b82f6 !important;border-radius:12px;">
                <div class="text-muted fw-semibold small text-uppercase">Total Alerts</div>
                <h3 class="fw-bold my-1 text-dark"><?= $count_all ?></h3>
                <small class="text-muted">In your active role queue</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#fff;border-left:4px solid #dc2626 !important;border-radius:12px;">
                <div class="text-muted fw-semibold small text-uppercase">Unread Notifications</div>
                <h3 class="fw-bold my-1 text-danger"><?= $count_unread ?></h3>
                <small class="text-muted">Awaiting action</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#fff;border-left:4px solid #b91c1c !important;border-radius:12px;">
                <div class="text-muted fw-semibold small text-uppercase">Critical &amp; Panic Alerts</div>
                <h3 class="fw-bold my-1 text-danger"><?= $count_critical ?></h3>
                <small class="text-muted">Panic values &amp; rejections</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100" style="background:#fff;border-left:4px solid #16a34a !important;border-radius:12px;">
                <div class="text-muted fw-semibold small text-uppercase">Revenue &amp; Claims</div>
                <h3 class="fw-bold my-1 text-success"><?= $count_billing ?></h3>
                <small class="text-muted">eCitizen &amp; SHA notices</small>
            </div>
        </div>
    </div>

    <!-- MAIN NOTIFICATION CARD -->
    <div class="card border-0 shadow-sm" style="border-radius:14px;overflow:hidden;">
        
        <!-- Header with Filter Pills & Live Search -->
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <ul class="nav nav-pills card-header-pills" id="notifTabs">
                <li class="nav-item">
                    <a class="nav-link <?= ($filter_tab === 'all') ? 'active' : 'text-secondary' ?> fw-bold px-3 btn-sm" href="notifications.php?tab=all">
                        All <span class="badge <?= ($filter_tab === 'all') ? 'bg-white text-primary' : 'bg-light text-dark' ?> ms-1"><?= $count_all ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($filter_tab === 'unread') ? 'active' : 'text-secondary' ?> fw-bold px-3 btn-sm" href="notifications.php?tab=unread">
                        Unread <span class="badge bg-danger ms-1"><?= $count_unread ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($filter_tab === 'critical') ? 'active bg-danger' : 'text-danger' ?> fw-bold px-3 btn-sm" href="notifications.php?tab=critical">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> Critical <span class="badge bg-danger ms-1"><?= $count_critical ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($filter_tab === 'billing') ? 'active bg-success' : 'text-success' ?> fw-bold px-3 btn-sm" href="notifications.php?tab=billing">
                        <i class="fa-solid fa-building-columns me-1"></i> eCitizen &amp; Claims <span class="badge bg-secondary ms-1"><?= $count_billing ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($filter_tab === 'lab') ? 'active' : 'text-secondary' ?> fw-bold px-3 btn-sm" href="notifications.php?tab=lab">
                        <i class="fa-solid fa-flask me-1"></i> Orders &amp; Results <span class="badge bg-secondary ms-1"><?= $count_lab ?></span>
                    </a>
                </li>
                <?php if ($count_qa > 0): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($filter_tab === 'qa') ? 'active bg-warning text-dark' : 'text-secondary' ?> fw-bold px-3 btn-sm" href="notifications.php?tab=qa">
                        <i class="fa-solid fa-vial-circle-check me-1"></i> QA &amp; Blood Bank <span class="badge bg-secondary ms-1"><?= $count_qa ?></span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <!-- Search box -->
            <div class="input-group input-group-sm" style="max-width:260px;">
                <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" id="notifSearchInput" class="form-control border-start-0" placeholder="Filter alerts in real-time..." onkeyup="filterNotificationsLive()">
            </div>
        </div>

        <div class="card-body p-0">
            <?php if (empty($notifs)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-bell-slash fa-3x mb-3 opacity-25"></i>
                <h5 class="fw-bold text-dark">No Notifications Found</h5>
                <p class="small mb-0">There are no alerts matching this filter in your queue.</p>
            </div>
            <?php else: ?>
            <div class="list-group list-group-flush" id="notifItemsContainer">
                <?php foreach ($notifs as $n):
                    $isUnread = ($n['is_read'] == 0);
                    $sev = $n['severity'] ?? 'Info';
                    $cat = $n['category'] ?? 'General';
                    $nid = intval($n['notif_id']);

                    // Card accent styling
                    $border_accent = 'border-start: 4px solid #cbd5e1 !important;';
                    $bg_tone = $isUnread ? '#f8fafc' : '#ffffff';
                    $icon_badge = '<span class="badge bg-primary-subtle text-primary border"><i class="fa-solid fa-bell"></i></span>';

                    if ($sev === 'Critical') {
                        $border_accent = 'border-start: 4px solid #dc2626 !important;';
                        $bg_tone = $isUnread ? '#fef2f2' : '#ffffff';
                        $icon_badge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fa-solid fa-triangle-exclamation"></i></span>';
                    } elseif ($sev === 'Warning') {
                        $border_accent = 'border-start: 4px solid #f59e0b !important;';
                        $bg_tone = $isUnread ? '#fffbeb' : '#ffffff';
                        $icon_badge = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="fa-solid fa-circle-exclamation"></i></span>';
                    } elseif ($sev === 'Success') {
                        $border_accent = 'border-start: 4px solid #16a34a !important;';
                        $bg_tone = $isUnread ? '#f0fdf4' : '#ffffff';
                        $icon_badge = '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-circle-check"></i></span>';
                    }
                ?>
                <div class="list-group-item p-3 notif-row" style="<?= $border_accent ?> background-color: <?= $bg_tone ?>; transition: background-color 0.15s ease;" data-text="<?= strtolower(htmlspecialchars($n['message'] . ' ' . $cat . ' ' . $n['target_role'])) ?>">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        
                        <div class="d-flex align-items-start gap-3 flex-grow-1" style="min-width:280px;">
                            <div class="mt-1">
                                <?= $icon_badge ?>
                            </div>

                            <div style="flex:1;">
                                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                    <span class="badge bg-secondary-subtle text-secondary border" style="font-size:0.7rem;text-transform:uppercase;">
                                        <?= htmlspecialchars($cat) ?>
                                    </span>
                                    <span class="badge bg-light text-dark border" style="font-size:0.7rem;">
                                        Role: <?= htmlspecialchars($n['target_role']) ?>
                                    </span>
                                    <?php if ($sev === 'Critical'): ?>
                                    <span class="badge bg-danger text-white fw-bold pulse-critical" style="font-size:0.68rem;">
                                        EMERGENCY ACTION
                                    </span>
                                    <?php endif; ?>
                                    <small class="text-muted" style="font-size:0.75rem;">
                                        <i class="fa-regular fa-clock me-1"></i><?= format_notification_time($n['created_at']) ?>
                                        <span class="opacity-75">(<?= date('d M Y, H:i', strtotime($n['created_at'])) ?>)</span>
                                    </small>
                                </div>

                                <div class="<?= $isUnread ? 'fw-bold text-dark' : 'text-secondary' ?>" style="font-size:0.9rem;line-height:1.45;">
                                    <?= htmlspecialchars($n['message']) ?>
                                </div>
                            </div>
                        </div>

                        <!-- Action Controls -->
                        <div class="d-flex align-items-center gap-2 flex-shrink-0 ms-auto">
                            <?php if (!empty($n['link'])): ?>
                            <a href="mark_read.php?id=<?= $nid ?>&link=<?= urlencode($n['link']) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">
                                Action <i class="fa-solid fa-arrow-right ms-1"></i>
                            </a>
                            <?php endif; ?>

                            <a href="notifications.php?toggle_read=<?= $nid ?>&to=<?= $isUnread ? 1 : 0 ?>&tab=<?= urlencode($filter_tab) ?>"
                               class="btn btn-sm btn-light border rounded-circle text-muted"
                               style="width:32px;height:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;"
                               title="<?= $isUnread ? 'Mark as Read' : 'Mark as Unread' ?>">
                                <i class="fa-solid <?= $isUnread ? 'fa-check' : 'fa-envelope' ?>"></i>
                            </a>
                        </div>

                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="card-footer bg-light py-2 px-3 border-0 d-flex justify-content-between align-items-center small text-muted">
            <span>Showing up to 250 latest alerts</span>
            <span>Real-time polling active &bull; Interval: 12s</span>
        </div>

    </div>

</div>

<script>
function filterNotificationsLive() {
    const q = document.getElementById('notifSearchInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.notif-row');
    let visibleCount = 0;

    rows.forEach(r => {
        const text = r.getAttribute('data-text') || '';
        if (q === '' || text.includes(q)) {
            r.style.display = '';
            visibleCount++;
        } else {
            r.style.display = 'none';
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>
