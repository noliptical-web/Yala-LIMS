<?php
// Ensure session is started BEFORE anything else
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// REQUIRED SECURITY INCLUDES
 require_once __DIR__ . '/csrf.php';
 require_once __DIR__ . '/audit.php';

// Initialize variables
$notif_count = 0;
$my_role     = $_SESSION['role'] ?? '';
$my_uid      = intval($_SESSION['id'] ?? 0);

// Fetch notification count
if ($my_role && isset($conn)) {
    if ($my_role == 'Admin') {
        $res_n = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE is_read = 0");
        if ($res_n) $notif_count = $res_n->fetch_assoc()['c'];
    } elseif ($my_role == 'Doctor') {
        $stmt_nc = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE target_role = 'Doctor' AND target_user_id = ? AND is_read = 0");
        $stmt_nc->bind_param("i", $my_uid);
        $stmt_nc->execute();
        $res_n = $stmt_nc->get_result();
        if ($res_n) $notif_count = $res_n->fetch_assoc()['c'];
        $stmt_nc->close();
    } else {
        $stmt_nc = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE target_role = ? AND is_read = 0");
        $stmt_nc->bind_param("s", $my_role);
        $stmt_nc->execute();
        $res_n = $stmt_nc->get_result();
        if ($res_n) $notif_count = $res_n->fetch_assoc()['c'];
        $stmt_nc->close();
    }
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

                <!-- NOTIFICATIONS -->
                <div class="dropdown me-3">
                    <a href="#" class="text-secondary position-relative" data-bs-toggle="dropdown">
                        <i class="fa-solid fa-bell fa-xl"></i>

                        <?php if($notif_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?php echo $notif_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="width:320px;">
                        <li class="dropdown-header fw-bold">
                            Notifications
                            <?php if($notif_count > 0): ?>
                                <span class="badge bg-danger ms-1"><?php echo $notif_count; ?> new</span>
                            <?php endif; ?>
                        </li>

                        <?php
                        if ($my_role == 'Admin') {
                            $list = $conn->query("SELECT * FROM notifications ORDER BY notif_id DESC LIMIT 5");
                        } elseif ($my_role == 'Doctor') {
                            $stmt_nl = $conn->prepare("SELECT * FROM notifications WHERE target_role = 'Doctor' AND target_user_id = ? ORDER BY notif_id DESC LIMIT 5");
                            $stmt_nl->bind_param("i", $my_uid);
                            $stmt_nl->execute();
                            $list = $stmt_nl->get_result();
                            $stmt_nl->close();
                        } else {
                            $stmt_nl = $conn->prepare("SELECT * FROM notifications WHERE target_role = ? ORDER BY notif_id DESC LIMIT 5");
                            $stmt_nl->bind_param("s", $my_role);
                            $stmt_nl->execute();
                            $list = $stmt_nl->get_result();
                            $stmt_nl->close();
                        }

                        if ($list && $list->num_rows > 0):
                            while ($note = $list->fetch_assoc()):
                                $bg_class = $note['is_read'] ? '' : 'bg-light';
                        ?>

                        <li>
                            <a class="dropdown-item <?php echo $bg_class; ?> py-2 px-3 border-bottom d-flex align-items-center gap-2"
                               href="mark_read.php?id=<?php echo (int)$note['notif_id']; ?>&link=<?php echo urlencode($note['link']); ?>">

                                <?php if(!$note['is_read']): ?>
                                    <span style="width:8px;height:8px;border-radius:50%;background:#dc3545;"></span>
                                <?php else: ?>
                                    <span style="width:8px;height:8px;"></span>
                                <?php endif; ?>

                                <div style="min-width:0;">
                                    <div class="text-dark text-truncate" style="font-size:.85rem;max-width:240px;">
                                        <?php echo htmlspecialchars($note['message']); ?>
                                    </div>
                                    <small class="text-muted" style="font-size:.72rem;">
                                        <?php echo date('d M · H:i', strtotime($note['created_at'])); ?>
                                    </small>
                                </div>
                            </a>
                        </li>

                        <?php endwhile; else: ?>
                            <li class="p-3 text-center text-muted small">No new notifications</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- USER INFO -->
                <div class="text-end me-3 d-none d-md-block">
                    <span class="d-block fw-bold text-dark">
                        <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                    </span>
                    <span class="badge bg-primary rounded-pill">
                        <?php echo htmlspecialchars($_SESSION['role']); ?>
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

<div class="container main-content">