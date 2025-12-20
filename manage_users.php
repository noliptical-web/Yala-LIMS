<?php
// manage_users.php
session_start();
require_once 'includes/db_connect.php';

// SECURITY: Only Admin can see this
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] != 'Admin') {
    header("location: dashboard.php");
    exit;
}

$message = "";

// HANDLE ADD NEW USER
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $name = $_POST['full_name'];
    $user = $_POST['username'];
    $role = $_POST['role'];
    $pass = $_POST['password'];
    
    // Check if username exists
    $check = $conn->query("SELECT user_id FROM users WHERE username='$user'");
    if ($check->num_rows > 0) {
        $message = "<div class='alert alert-danger'>Error: Username '$user' already exists.</div>";
    } else {
        // Hash the password for security
        $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
        
        $sql = "INSERT INTO users (full_name, username, role, password) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $name, $user, $role, $hashed_pass);
        
        if ($stmt->execute()) {
            $message = "<div class='alert alert-success'>New staff member <strong>$name</strong> added!</div>";
        } else {
            $message = "<div class='alert alert-danger'>Database Error.</div>";
        }
    }
}

// HANDLE DELETE USER
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    if ($id != $_SESSION['id']) { // Don't let Admin delete themselves
        $conn->query("DELETE FROM users WHERE user_id=$id");
        $message = "<div class='alert alert-warning'>User deleted.</div>";
    }
}

// FETCH ALL USERS
$users = $conn->query("SELECT * FROM users ORDER BY role, full_name");

// --- PAGE CONFIGURATION ---
$page_title = "Manage Staff - Yala LIMS";
include 'includes/header.php'; 
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold text-primary mb-0"><i class="fa-solid fa-users-gear me-2"></i>Staff Management</h4>
    <span class="badge bg-dark p-2"><i class="fa-solid fa-shield-halved me-2"></i>Admin Panel</span>
</div>

<div class="row">
    
    <div class="col-md-4">
        <div class="card glass-card mb-4">
            <div class="card-header bg-primary text-white p-3 rounded-top">
                <h5 class="mb-0"><i class="fa-solid fa-user-plus"></i> Add New Staff</h5>
            </div>
            <div class="card-body">
                <?php echo $message; ?>
                <form method="post">
                    <div class="mb-3">
                        <label>Full Name</label>
                        <input type="text" name="full_name" class="form-control" required placeholder="e.g. Dr. John Doe">
                    </div>
                    <div class="mb-3">
                        <label>Username (Login)</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Role</label>
                        <select name="role" class="form-select">
                            <option value="Doctor">Doctor</option>
                            <option value="Receptionist">Receptionist</option>
                            <option value="LabTech">Lab Technician</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" name="add_user" class="btn btn-primary w-100">Create Account</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card glass-card">
            <div class="card-header bg-white p-3">
                <h5 class="mb-0 text-secondary">Current Staff List</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Username</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $users->fetch_assoc()): ?>
                        <tr>
                            <td class="fw-bold"><?php echo $row['full_name']; ?></td>
                            <td>
                                <?php 
                                $badge = 'secondary';
                                if($row['role']=='Doctor') $badge='danger';
                                if($row['role']=='Receptionist') $badge='info';
                                if($row['role']=='LabTech') $badge='success';
                                ?>
                                <span class="badge bg-<?php echo $badge; ?>"><?php echo $row['role']; ?></span>
                            </td>
                            <td><?php echo $row['username']; ?></td>
                            <td>
                                <?php if($row['user_id'] != $_SESSION['id']): ?>
                                <a href="?delete=<?php echo $row['user_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this user?');">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php include 'includes/footer.php'; ?>