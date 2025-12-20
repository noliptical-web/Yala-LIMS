<?php
// dashboard.php
session_start();
require_once 'includes/db_connect.php';
if (!isset($_SESSION['loggedin'])) { header("location: index.php"); exit; }

// GET THE USER ROLE
$role = $_SESSION['role'];

// --- SIMPLE COUNTERS ---
$today = $conn->query("SELECT COUNT(*) as c FROM patients WHERE DATE(registered_at)=CURDATE()")->fetch_assoc()['c'];
$pending = $conn->query("SELECT COUNT(*) as c FROM lab_requests WHERE status='Pending'")->fetch_assoc()['c'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Yala Hospital LIMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #E3F2FD 0%, #90CAF9 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
            transition: transform 0.3s ease;
        }
        .glass-card:hover {
            transform: translateY(-5px);
        }
        .navbar-custom {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 0 0 20px 20px;
        }
        .icon-circle {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 15px auto;
        }
        .bg-reception { background-color: #e3f2fd; color: #1976d2; }
        .bg-doctor { background-color: #fce4ec; color: #c2185b; }
        .bg-lab { background-color: #e8f5e9; color: #388e3c; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-custom px-4 py-3 mb-5 mx-3 mt-3">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <?php if(file_exists('logo.png')): ?>
                    <img src="logo.png" alt="Logo" height="50" class="me-3"> 
                <?php else: ?>
                    <i class="fa-solid fa-hospital fa-2x text-primary me-3"></i>
                <?php endif; ?>
                
                <div>
                    <h5 class="mb-0 text-primary fw-bold">YALA SUB-COUNTY HOSPITAL</h5>
                    <small class="text-muted">Laboratory Management System</small>
                </div>
            </a>
            
            <div class="ms-auto d-flex align-items-center">
                <div class="text-end me-3 d-none d-md-block">
                    <span class="d-block fw-bold text-dark"><?php echo $_SESSION['full_name']; ?></span>
                    <span class="badge bg-primary rounded-pill"><?php echo $_SESSION['role']; ?></span>
                </div>
                <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        
        <div class="row mb-5">
            <div class="col-md-6 mb-3">
                <div class="card text-white border-0 shadow" 
                     style="background: linear-gradient(45deg, #11998e, #38ef7d); border-radius: 15px;">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="display-4 fw-bold mb-0"><?php echo $today; ?></h2>
                            <p class="fs-5 mb-0">Patients Today</p>
                        </div>
                        <i class="fa-solid fa-calendar-day fa-4x opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-3">
                <div class="card text-white border-0 shadow" 
                     style="background: linear-gradient(45deg, #ff9966, #ff5e62); border-radius: 15px;">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="display-4 fw-bold mb-0"><?php echo $pending; ?></h2>
                            <p class="fs-5 mb-0">Tests Pending</p>
                        </div>
                        <i class="fa-solid fa-flask fa-4x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <h5 class="text-secondary fw-bold mb-3 ps-2">YOUR MODULES</h5>
        
        <div class="row justify-content-center">
            
            <?php if($role == 'Receptionist' || $role == 'Admin'): ?>
            <div class="col-md-4 mb-4">
                <a href="add_patient.php" class="text-decoration-none">
                    <div class="card glass-card h-100 text-center p-4">
                        <div class="icon-circle bg-reception">
                            <i class="fa-solid fa-id-card"></i>
                        </div>
                        <h4 class="text-dark fw-bold">Reception</h4>
                        <p class="text-muted">Register new patients</p>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if($role == 'Doctor' || $role == 'Admin'): ?>
            <div class="col-md-4 mb-4">
                <a href="request_test.php" class="text-decoration-none">
                    <div class="card glass-card h-100 text-center p-4">
                        <div class="icon-circle bg-doctor">
                            <i class="fa-solid fa-user-doctor"></i>
                        </div>
                        <h4 class="text-dark fw-bold">Doctor's Request</h4>
                        <p class="text-muted">Order lab tests</p>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if($role == 'LabTech' || $role == 'Admin'): ?>
            <div class="col-md-4 mb-4">
                <a href="enter_results.php" class="text-decoration-none">
                    <div class="card glass-card h-100 text-center p-4">
                        <div class="icon-circle bg-lab">
                            <i class="fa-solid fa-microscope"></i>
                        </div>
                        <h4 class="text-dark fw-bold">Lab Bench</h4>
                        <p class="text-muted">Enter results & Print</p>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if($role == 'Admin'): ?>
            <div class="col-md-4 mb-4">
                <a href="manage_users.php" class="text-decoration-none">
                    <div class="card glass-card h-100 text-center p-4">
                        <div class="icon-circle bg-dark text-white">
                            <i class="fa-solid fa-users-gear"></i>
                        </div>
                        <h4 class="text-dark fw-bold">Admin Panel</h4>
                        <p class="text-muted">Create/Delete Staff</p>
                    </div>
                </a>
            </div>
            <?php endif; ?>

              <?php if($role == 'Receptionist' || $role == 'Admin'): ?>
                      <div class="col-md-4 mb-4">
                          <a href="billing.php" class="text-decoration-none">
                              <div class="card glass-card h-100 text-center p-4">
                                <div class="icon-circle bg-success text-white">
                                    <i class="fa-solid fa-cash-register"></i>
                               </div>
                               <h4 class="text-dark fw-bold">Cashier</h4>
                               <p class="text-muted">Receive Payments & Receipts</p>
                           </div>
                      </a>
                   </div>
                <?php endif; ?>

        </div>
    </div>
</body>
</html>