<?php
// FETCH NOTIFICATIONS LOGIC
// We assume session_start() and db_connect.php are already loaded in the parent file
$notif_count = 0;
$my_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

if ($my_role && isset($conn)) {
    // Admins see everything, others see only their role's messages
    $sql_n = ($my_role == 'Admin') 
        ? "SELECT COUNT(*) as c FROM notifications WHERE is_read = 0" 
        : "SELECT COUNT(*) as c FROM notifications WHERE target_role = '$my_role' AND is_read = 0";
    
    // Check if query exists to avoid crash on first run
    $res_n = $conn->query($sql_n);
    if($res_n) {
        $notif_count = $res_n->fetch_assoc()['c'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : 'Yala Hospital LIMS'; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* GLOBAL THEME & LAYOUT (FLEXBOX FIX) */
        body {
            background: linear-gradient(135deg, #E3F2FD 0%, #90CAF9 100%);
            min-height: 100vh;
            display: flex; /* Sticky Footer Requirement */
            flex-direction: column;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* Forces the content to grow and push footer down */
        .main-content {
            flex: 1;
        }

        /* GLASSMORPHISM CARD */
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
            transition: transform 0.3s ease;
        }

        /* NAVBAR STYLES */
        .navbar-custom {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 0 0 20px 20px;
        }
        
        /* ECITIZEN STYLES */
        .ecitizen-header { background: #D32F2F; color: white; padding: 15px; border-radius: 15px 15px 0 0; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-custom px-4 py-3 mb-4 mx-3 mt-3">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <?php if(file_exists('logo.png')): ?>
                    <img src="logo.png" height="40" class="me-3"> 
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
                    
                    <div class="dropdown me-3">
                        <a href="#" class="text-secondary position-relative" id="notifDropdown" data-bs-toggle="dropdown">
                            <i class="fa-solid fa-bell fa-xl"></i>
                            <?php if($notif_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                    <?php echo $notif_count; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="width: 300px;">
                            <li class="dropdown-header fw-bold">Notifications</li>
                            
                            <?php
                            // Fetch the actual messages
                            $sql_list = ($my_role == 'Admin') 
                                ? "SELECT * FROM notifications ORDER BY notif_id DESC LIMIT 5" 
                                : "SELECT * FROM notifications WHERE target_role = '$my_role' ORDER BY notif_id DESC LIMIT 5";
                                
                            $list = $conn->query($sql_list);
                            
                            if($list && $list->num_rows > 0):
                                while($note = $list->fetch_assoc()):
                                    $bg_class = $note['is_read'] ? '' : 'bg-light';
                            ?>
                                <li>
                                    <a class="dropdown-item <?php echo $bg_class; ?> p-3 border-bottom" href="mark_read.php?id=<?php echo $note['notif_id']; ?>&link=<?php echo urlencode($note['link']); ?>">
                                        <small class="d-block text-muted mb-1"><?php echo date('H:i', strtotime($note['created_at'])); ?></small>
                                        <span class="d-block text-wrap text-dark" style="white-space: normal;"><?php echo $note['message']; ?></span>
                                    </a>
                                </li>
                            <?php endwhile; else: ?>
                                <li class="p-3 text-center text-muted small">No new notifications</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="text-end me-3 d-none d-md-block">
                        <span class="d-block fw-bold text-dark"><?php echo $_SESSION['full_name']; ?></span>
                        <span class="badge bg-primary rounded-pill"><?php echo $_SESSION['role']; ?></span>
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

    <div class="container main-content"></div>