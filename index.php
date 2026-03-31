<?php
ob_start();
session_start();
require_once 'includes/db_connect.php';

// Redirect if already logged in
if (isset($_SESSION['loggedin'])) {
    ob_end_clean();
    header("location: dashboard.php");
    exit;
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $sql = "SELECT user_id, username, password, role, full_name FROM users WHERE username = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows == 1) {
            $stmt->bind_result($id, $u_name, $hashed_password, $role, $full_name);
            $stmt->fetch();

            if (password_verify($password, $hashed_password)) {
                $_SESSION['loggedin']  = true;
                $_SESSION['id']        = $id;
                $_SESSION['username']  = $u_name;
                $_SESSION['role']      = $role;
                $_SESSION['full_name'] = $full_name;
                ob_end_clean();
                header("location: dashboard.php");
                exit;
            } else {
                $error = "Incorrect password. Please try again.";
            }
        } else {
            $error = "No account found with that username.";
        }
        $stmt->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Yala Sub-County Hospital LIMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f4f8;
        }

        /* LEFT PANEL */
        .left-panel {
            width: 55%;
            background: linear-gradient(160deg, #1565C0 0%, #0288D1 60%, #4CAF50 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 50px;
            position: relative;
            overflow: hidden;
        }
        .left-panel::before {
            content: '';
            position: absolute;
            width: 400px; height: 400px;
            border-radius: 50%;
            background: rgba(255,255,255,0.05);
            top: -100px; left: -100px;
        }
        .left-panel::after {
            content: '';
            position: absolute;
            width: 300px; height: 300px;
            border-radius: 50%;
            background: rgba(255,255,255,0.05);
            bottom: -80px; right: -80px;
        }
        .left-panel .logo-wrap {
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            width: 120px; height: 120px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 28px;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255,255,255,0.3);
        }
        .left-panel h1 {
            color: white;
            font-size: 1.8rem;
            font-weight: 800;
            text-align: center;
            line-height: 1.3;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        .left-panel p {
            color: rgba(255,255,255,0.8);
            text-align: center;
            font-size: 0.95rem;
            margin-bottom: 40px;
            line-height: 1.6;
        }
        .feature-item {
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255,255,255,0.9);
            font-size: 0.88rem;
            margin-bottom: 14px;
            width: 100%;
            max-width: 320px;
        }
        .feature-icon {
            width: 36px; height: 36px;
            background: rgba(255,255,255,0.2);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            font-size: 0.9rem;
        }

        /* RIGHT PANEL */
        .right-panel {
            width: 45%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 50px;
            background: white;
        }
        .login-box {
            width: 100%;
            max-width: 380px;
        }
        .login-box h2 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1a1a2e;
            margin-bottom: 6px;
        }
        .login-box .subtitle {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 36px;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #333;
            margin-bottom: 6px;
        }
        .form-control {
            border: 1.5px solid #e0e0e0;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fafafa;
        }
        .form-control:focus {
            border-color: #1565C0;
            box-shadow: 0 0 0 3px rgba(21,101,192,0.1);
            background: white;
        }
        .input-group-text {
            border: 1.5px solid #e0e0e0;
            border-right: none;
            background: #fafafa;
            border-radius: 10px 0 0 10px;
            color: #888;
            padding: 12px 14px;
        }
        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }
        .input-group .form-control:focus {
            border-left: none;
        }
        .btn-login {
            background: linear-gradient(135deg, #1565C0, #0288D1);
            border: none;
            border-radius: 10px;
            padding: 13px;
            font-size: 1rem;
            font-weight: 700;
            color: white;
            width: 100%;
            transition: opacity 0.2s, transform 0.1s;
            letter-spacing: 0.3px;
        }
        .btn-login:hover { opacity: 0.92; transform: translateY(-1px); }
        .btn-login:active { transform: translateY(0); }

        .divider {
            display: flex; align-items: center; gap: 12px;
            color: #bbb; font-size: 0.8rem; margin: 24px 0;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1; height: 1px; background: #eee;
        }

        .role-badges { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; }
        .role-badge {
            background: #f0f4ff;
            color: #1565C0;
            border: 1px solid #c8d8ff;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .footer-note {
            color: #aaa;
            font-size: 0.78rem;
            text-align: center;
            margin-top: 32px;
        }

        .alert-danger {
            background: #fff0f0;
            border: 1.5px solid #ffcdd2;
            border-radius: 10px;
            color: #c62828;
            font-size: 0.88rem;
            padding: 10px 14px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            body { flex-direction: column; }
            .left-panel { width: 100%; min-height: 220px; padding: 30px 24px; }
            .left-panel h1 { font-size: 1.3rem; }
            .left-panel p, .feature-item { display: none; }
            .right-panel { width: 100%; padding: 30px 24px; }
        }
    </style>
</head>
<body>

    <!-- LEFT PANEL -->
    <div class="left-panel">
        <div class="logo-wrap">
            <?php if(file_exists('logo.png')): ?>
                <img src="logo.png" height="70" alt="Logo">
            <?php else: ?>
                <i class="fa-solid fa-hospital fa-3x text-white"></i>
            <?php endif; ?>
        </div>
        <h1>Yala Sub-County Hospital</h1>
        <p>Laboratory Information Management System<br>Siaya County, Kenya</p>

        <div class="feature-item">
            <div class="feature-icon"><i class="fa-solid fa-flask"></i></div>
            <span>End-to-end lab test management</span>
        </div>
        <div class="feature-item">
            <div class="feature-icon"><i class="fa-solid fa-file-waveform"></i></div>
            <span>Instant PDF reports & receipts</span>
        </div>
        <div class="feature-item">
            <div class="feature-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <span>Role-based secure access</span>
        </div>
        <div class="feature-item">
            <div class="feature-icon"><i class="fa-solid fa-chart-line"></i></div>
            <span>Real-time analytics & reporting</span>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="right-panel">
        <div class="login-box">
            <h2>Welcome back</h2>
            <p class="subtitle">Sign in to access the LIMS portal</p>

            <?php if(!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <form action="index.php" method="post">
                <div class="mb-4">
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                        <input type="text" name="username" class="form-control"
                               placeholder="Enter your username" required
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="password" id="passwordInput"
                               class="form-control" placeholder="Enter your password" required>
                        <button type="button" class="btn btn-outline-secondary"
                                style="border:1.5px solid #e0e0e0;border-left:none;border-radius:0 10px 10px 0;background:#fafafa;"
                                onclick="togglePassword()">
                            <i class="fa-solid fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fa-solid fa-right-to-bracket me-2"></i>Sign In
                </button>
            </form>

            <div class="divider">Access Levels</div>

            <div class="role-badges">
                <span class="role-badge"><i class="fa-solid fa-user-doctor me-1"></i>Doctor</span>
                <span class="role-badge"><i class="fa-solid fa-id-card me-1"></i>Receptionist</span>
                <span class="role-badge"><i class="fa-solid fa-microscope me-1"></i>Lab Tech</span>
                <span class="role-badge"><i class="fa-solid fa-shield-halved me-1"></i>Admin</span>
            </div>

            <p class="footer-note">
                <i class="fa-solid fa-circle-info me-1"></i>
                For account issues contact IT Support
            </p>
        </div>
    </div>

</body>
<script>
function togglePassword() {
    const input = document.getElementById('passwordInput');
    const icon  = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-solid fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-solid fa-eye';
    }
}
</script>
</html>