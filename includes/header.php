<?php
// Ensure session is started BEFORE anything else
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// REQUIRED SECURITY & UTILITY INCLUDES
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications_helper.php';

// Initialize variables
$notif_count = 0;
$my_role     = $_SESSION['role'] ?? '';
$my_uid      = intval($_SESSION['id'] ?? ($_SESSION['user_id'] ?? 0));

// Fetch notification count using centralized helper
if ($my_role && isset($conn)) {
    $notif_count = get_unread_notifications_count($conn, $my_role, $my_uid);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Yala Hospital LIMS'; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body {
            background: linear-gradient(135deg,#E3F2FD 0%,#90CAF9 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
        }
        .main-content { flex:1; }
        .glass-card {
            background: rgba(255,255,255,0.95);
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(31,38,135,0.15);
            transition: transform 0.3s ease;
        }
        .navbar-custom {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 0 0 20px 20px;
        }
        .ecitizen-header {
            background:#D32F2F;
            color:white;
            padding:15px;
            border-radius:15px 15px 0 0;
        }
        .notif-bell-icon {
            transition: transform 0.2s ease, color 0.2s ease;
        }
        .notif-bell-icon:hover {
            color: #1d4ed8 !important;
            transform: scale(1.1);
        }
        .notif-item-hover:hover {
            background-color: #f1f5f9 !important;
        }
        .pulse-critical {
            animation: pulse-red 1.8s infinite;
        }
        @keyframes pulse-red {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(220, 38, 38, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
        }
    </style>
</head>

<body>

<nav class="navbar navbar-expand-lg navbar-custom px-4 py-3 mb-4 mx-3 mt-3">
    <div class="container-fluid">

        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <?php if(file_exists('logo.png')): ?>
                <img src="logo.png" height="40" class="me-3" alt="Logo">
            <?php else: ?>
                <i class="fa-solid fa-hospital fa-2x text-primary me-3"></i>
            <?php endif; ?>
            <div>
                <h5 class="mb-0 text-primary fw-bold">YALA SUB-COUNTY HOSPITAL</h5>
                <small class="text-muted">Laboratory Management System</small>
            </div>
        </a>

        <div class="ms-auto d-flex align-items-center">

            <?php if(isset($_SESSION['loggedin'])): ?>

                <!-- NOTIFICATIONS DROPDOWN -->
                <div class="dropdown me-3">
                    <a href="#" class="text-secondary position-relative notif-bell-icon d-inline-block" data-bs-toggle="dropdown" id="notifDropdownLink" title="Hospital Alerts">
                        <i class="fa-solid fa-bell fa-xl" id="headerBellIcon"></i>

                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= ($notif_count > 0) ? '' : 'd-none' ?>" id="headerNotifBadge">
                            <?php echo $notif_count; ?>
                        </span>
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="width:360px;border-radius:12px;overflow:hidden;" id="notifDropdownMenu">
                        <li class="dropdown-header py-2 px-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark" style="font-size:0.88rem;">
                                <i class="fa-solid fa-bell me-1 text-primary"></i> Notifications
                                <span class="badge bg-danger ms-1 <?= ($notif_count > 0) ? '' : 'd-none' ?>" id="headerNotifHeaderCount"><?php echo $notif_count; ?> new</span>
                            </span>
                            <button class="btn btn-link btn-sm text-decoration-none p-0 fw-semibold text-primary" style="font-size:0.75rem;" onclick="markAllNavRead(event)">
                                Mark all read
                            </button>
                        </li>

                        <div id="notifDropdownList" style="max-height:360px;overflow-y:auto;">
                        <?php
                        $list = get_user_notifications($conn, $my_role, $my_uid, 5, false);
                        $latest_id_seed = 0;

                        if (!empty($list)):
                            $latest_id_seed = intval($list[0]['notif_id'] ?? 0);
                            foreach ($list as $note):
                                $isUnread = ($note['is_read'] == 0);
                                $bg_class = $isUnread ? 'bg-light' : 'bg-white';
                                $sev = $note['severity'] ?? 'Info';

                                $icon_html = '<i class="fa-solid fa-bell text-primary"></i>';
                                if ($sev === 'Critical') {
                                    $icon_html = '<i class="fa-solid fa-triangle-exclamation text-danger"></i>';
                                } elseif ($sev === 'Warning') {
                                    $icon_html = '<i class="fa-solid fa-circle-exclamation text-warning"></i>';
                                } elseif ($sev === 'Success') {
                                    $icon_html = '<i class="fa-solid fa-circle-check text-success"></i>';
                                }
                        ?>
                            <li>
                                <a class="dropdown-item <?php echo $bg_class; ?> notif-item-hover py-2 px-3 border-bottom d-flex align-items-start gap-2 text-wrap"
                                   href="mark_read.php?id=<?php echo (int)$note['notif_id']; ?>&link=<?php echo urlencode($note['link']); ?>"
                                   style="white-space:normal;">

                                    <div class="mt-1" style="width:18px;text-align:center;">
                                        <?= $icon_html ?>
                                    </div>

                                    <div style="flex:1;min-width:0;">
                                        <div class="text-dark <?= $isUnread ? 'fw-bold' : '' ?>" style="font-size:0.83rem;line-height:1.35;">
                                            <?php echo htmlspecialchars($note['message']); ?>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 mt-1">
                                            <small class="text-muted" style="font-size:0.7rem;">
                                                <i class="fa-regular fa-clock me-1"></i><?= format_notification_time($note['created_at']) ?>
                                            </small>
                                            <?php if ($sev === 'Critical'): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-1 py-0" style="font-size:0.65rem;">CRITICAL</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($isUnread): ?>
                                        <span class="mt-1" style="width:7px;height:7px;border-radius:50%;background:#dc3545;flex-shrink:0;"></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; else: ?>
                            <li class="p-4 text-center text-muted small" id="notifEmptyItem">
                                <i class="fa-solid fa-bell-slash fa-2x mb-2 opacity-50"></i>
                                <div>No new notifications</div>
                            </li>
                        <?php endif; ?>
                        </div>

                        <li class="text-center py-2 bg-light border-top">
                            <a href="notifications.php" class="dropdown-item py-1 small fw-bold text-primary text-center">
                                <i class="fa-solid fa-list me-1"></i> Open Notification Portal &rarr;
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- USER INFO -->
                <div class="text-end me-3 d-none d-md-block">
                    <span class="d-block fw-bold text-dark">
                        <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Staff User'); ?>
                    </span>
                    <span class="badge bg-primary rounded-pill">
                        <?php echo htmlspecialchars($_SESSION['role'] ?? 'Staff'); ?>
                    </span>
                </div>

                <?php if(basename($_SERVER['PHP_SELF']) != 'dashboard.php'): ?>
                    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 me-2">
                        <i class="fa-solid fa-arrow-left"></i> Dashboard
                    </a>
                <?php endif; ?>

                <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">Logout</a>

            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- REAL-TIME CLINICAL ALERT TOAST -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1095;">
    <div id="liveAlertToast" class="toast border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" style="border-radius:12px;overflow:hidden;">
        <div class="toast-header text-white" id="liveToastHeader" style="background:#dc2626;">
            <i class="fa-solid fa-triangle-exclamation me-2 fs-5" id="liveToastIcon"></i>
            <strong class="me-auto" id="liveToastTitle">CRITICAL CLINICAL ALERT</strong>
            <small id="liveToastTime" class="text-white-50">Just now</small>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body bg-white p-3" style="font-size:0.86rem;">
            <div id="liveToastMessage" class="fw-semibold text-dark mb-2"></div>
            <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                <button type="button" class="btn btn-sm btn-light border rounded-pill px-3" data-bs-dismiss="toast">Dismiss</button>
                <a href="#" id="liveToastAction" class="btn btn-sm btn-danger rounded-pill px-3 fw-bold">
                    Review Alert &rarr;
                </a>
            </div>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['loggedin'])): ?>
<script>
let lastSeenNotifId = <?= intval($latest_id_seed ?? 0) ?>;

function markAllNavRead(e) {
    if (e) e.preventDefault();
    fetch('notifications_poll.php?action=mark_all_read')
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const b = document.getElementById('headerNotifBadge');
                const hc = document.getElementById('headerNotifHeaderCount');
                if (b) { b.textContent = '0'; b.classList.add('d-none'); }
                if (hc) { hc.textContent = '0 new'; hc.classList.add('d-none'); }
                document.querySelectorAll('#notifDropdownList .bg-light').forEach(el => {
                    el.classList.remove('bg-light');
                    el.classList.add('bg-white');
                });
                document.querySelectorAll('#notifDropdownList .fw-bold').forEach(el => {
                    el.classList.remove('fw-bold');
                });
            }
        }).catch(err => console.error(err));
}

function pollNotifications() {
    fetch(`notifications_poll.php?action=poll&last_id=${lastSeenNotifId}`)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const badge = document.getElementById('headerNotifBadge');
                const hc = document.getElementById('headerNotifHeaderCount');
                const bell = document.getElementById('headerBellIcon');

                if (badge) {
                    if (data.unread_count > 0) {
                        badge.textContent = data.unread_count;
                        badge.classList.remove('d-none');
                        if (hc) { hc.textContent = data.unread_count + ' new'; hc.classList.remove('d-none'); }
                    } else {
                        badge.classList.add('d-none');
                        if (hc) hc.classList.add('d-none');
                    }
                }

                // If new critical alert arrived, pop live toast
                if (data.has_new_critical && data.critical_alerts && data.critical_alerts.length > 0) {
                    const topAlert = data.critical_alerts[0];
                    document.getElementById('liveToastMessage').textContent = topAlert.message;
                    document.getElementById('liveToastAction').href = `mark_read.php?id=${topAlert.id}&link=${encodeURIComponent(topAlert.link || 'notifications.php')}`;
                    document.getElementById('liveToastTime').textContent = topAlert.time_str || 'Just now';

                    const toastEl = document.getElementById('liveAlertToast');
                    if (toastEl && window.bootstrap) {
                        const toast = new bootstrap.Toast(toastEl, { delay: 10000 });
                        toast.show();
                    }
                }

                if (data.latest_id > lastSeenNotifId) {
                    lastSeenNotifId = data.latest_id;
                }
            }
        })
        .catch(err => console.error('Notif poll error:', err));
}

// Poll every 12 seconds
setInterval(pollNotifications, 12000);
</script>
<?php endif; ?>

<div class="container main-content">
