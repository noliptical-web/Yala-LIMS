<?php
// index.php - The Login Page
session_start();
require_once 'includes/db_connect.php';

$error = '';

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // 1. Prepare the SQL statement (Prevents SQL Injection)
    $sql = "SELECT user_id, username, password, role, full_name FROM users WHERE username = ?";
    
    if ($stmt = $conn->prepare($sql)) {
        // 2. Bind parameters
        $stmt->bind_param("s", $username);
        
        // 3. Execute
        $stmt->execute();
        $stmt->store_result();
        
        // 4. Check if user exists
        if ($stmt->num_rows == 1) {
            $stmt->bind_result($id, $u_name, $hashed_password, $role, $full_name);
            $stmt->fetch();
            
            // 5. Verify Password (Matches the hash we created in SQL)
            if (password_verify($password, $hashed_password)) {
                // Password is correct, start session
                $_SESSION['loggedin'] = true;
                $_SESSION['id'] = $id;
                $_SESSION['username'] = $u_name;
                $_SESSION['role'] = $role;
                $_SESSION['full_name'] = $full_name;
                
                // Redirect to Dashboard
                header("location: dashboard.php");
                exit;
            } else {
                $error = "Invalid password.";
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
    <title>Login - Yala Sub-County Hospital LIMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .login-card { width: 100%; max-width: 400px; padding: 20px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); background: white; }
        .hospital-logo { color: #0d6efd; font-weight: bold; font-size: 24px; text-align: center; margin-bottom: 20px; }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="hospital-logo">🏥 Yala Hospital LIMS</div>
        
        <?php if(!empty($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>
        <div class="text-center mt-3">
            <small class="text-muted">System Support: IT Dept</small>
        </div>
    </div>

</body>
</html>