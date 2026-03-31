<?php
// manage_users.php
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['role'] != 'Admin') {
    header("location: dashboard.php"); exit;
}

$success = "";
$error   = "";

// --- ADD NEW USER ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $name = trim($_POST['full_name']);
    $user = trim($_POST['username']);
    $role = $_POST['role'];
    $pass = $_POST['password'];

    // Validate password length
    if (strlen($pass) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        // Check username with prepared statement
        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $check->bind_param("s", $user);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Username '" . htmlspecialchars($user) . "' already exists.";
        } else {
            $hashed = password_hash($pass, PASSWORD_DEFAULT);
            $stmt   = $conn->prepare("INSERT INTO users (full_name, username, role, password) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $name, $user, $role, $hashed);
            if ($stmt->execute()) {
                $success = "Staff account for <strong>" . htmlspecialchars($name) . "</strong> created successfully.";
            } else {
                $error = "Database error: " . $stmt->error;
            }
            $stmt->close();
        }
        $check->close();
    }
}

// --- RESET PASSWORD ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset_password'])) {
    $uid      = intval($_POST['user_id']);
    $new_pass = trim($_POST['new_password']);

    if ($uid <= 0) {
        $error = "Invalid user.";
    } elseif (strlen($new_pass) < 6) {
        $error = "New password must be at least 6 characters.";
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt   = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bind_param("si", $hashed, $uid);
        if ($stmt->execute()) {
            $success = "Password reset successfully.";
        } else {
            $error = "Failed to reset password: " . $stmt->error;
        }
        $stmt->close();
    }
}

// --- CHANGE ROLE ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_role'])) {
    $uid      = intval($_POST['user_id']);
    $new_role = $_POST['new_role'];
    $allowed  = ['Doctor', 'Receptionist', 'LabTech', 'Admin'];

    if ($uid == $_SESSION['id']) {
        $error = "You cannot change your own role.";
    } elseif (!in_array($new_role, $allowed)) {
        $error = "Invalid role selected.";
    } else {
        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ?");
        $stmt->bind_param("si", $new_role, $uid);
        if ($stmt->execute()) {
            $success = "Role updated successfully.";
        } else {
            $error = "Failed to update role: " . $stmt->error;
        }
        $stmt->close();
    }
}

// --- DELETE USER ---
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']); // FIX: cast to int
    if ($del_id == $_SESSION['id']) {
        $error = "You cannot delete your own account.";
    } elseif ($del_id > 0) {
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute()) {
            $success = "User deleted successfully.";
        } else {
            $error = "Failed to delete user: " . $stmt->error;
        }
        $stmt->close();
    }
}

// --- FETCH ALL USERS ---
$users = $conn->query("SELECT * FROM users ORDER BY role, full_name");
$user_rows = [];
if ($users) while ($r = $users->fetch_assoc()) $user_rows[] = $r;

// Stats
$total_users  = count($user_rows);
$role_counts  = array_count_values(array_column($user_rows, 'role'));

$page_title = "Manage Staff - Yala LIMS";
include 'includes/header.php';
?>

<style>
    .glass-card { background:rgba(255,255,255,0.97); border:none; border-radius:14px; box-shadow:0 4px 20px rgba(31,38,135,0.09); }
    .role-badge-Doctor      { background:#dc3545; color:white; }
    .role-badge-Receptionist{ background:#0dcaf0; color:#000; }
    .role-badge-LabTech     { background:#198754; color:white; }
    .role-badge-Admin       { background:#212529; color:white; }
    .role-badge             { border-radius:50px; padding:3px 10px; font-size:.75rem; font-weight:600; }
    .stat-mini { border-radius:10px; padding:10px 14px; color:white; text-align:center; }
    .user-row:hover { background:#f8f9fa; }
    .section-title { font-size:.7rem; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#90a4ae; }
</style>

<!-- PAGE HEADER -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-users-gear me-2 text-primary"></i>Staff Management</h4>
        <small class="text-muted"><?php echo $total_users; ?> staff accounts</small>
    </div>
    <span class="badge bg-dark p-2"><i class="fa-solid fa-shield-halved me-2"></i>Admin Panel</span>
</div>

<!-- ALERTS -->
<?php if ($success): ?>
<div class="alert alert-success border-0 shadow-sm rounded-3 mb-3 d-flex align-items-center justify-content-between">
    <span><i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?></span>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger border-0 shadow-sm rounded-3 mb-3">
    <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error; ?>
</div>
<?php endif; ?>

<!-- ROLE STATS -->
<div class="row g-2 mb-4">
    <?php
    $stats = [
        'Doctor'       => ['#dc3545', 'fa-user-doctor'],
        'Receptionist' => ['#0dcaf0', 'fa-id-card'],
        'LabTech'      => ['#198754', 'fa-microscope'],
        'Admin'        => ['#212529', 'fa-shield-halved'],
    ];
    foreach ($stats as $r => [$color, $icon]):
        $cnt = $role_counts[$r] ?? 0;
    ?>
    <div class="col-6 col-md-3">
        <div class="stat-mini" style="background:<?php echo $color; ?>">
            <i class="fa-solid <?php echo $icon; ?> mb-1"></i>
            <div class="fw-bold fs-4"><?php echo $cnt; ?></div>
            <div class="small opacity-75"><?php echo $r; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">

    <!-- ADD USER FORM -->
    <div class="col-md-4">
        <div class="glass-card mb-3">
            <div class="card-header bg-primary text-white p-3 border-0" style="border-radius:14px 14px 0 0">
                <h6 class="mb-0 fw-bold"><i class="fa-solid fa-user-plus me-2"></i>Add New Staff</h6>
            </div>
            <div class="card-body p-4">
                <form method="post" action="manage_users.php">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Full Name</label>
                        <input type="text" name="full_name" class="form-control" required placeholder="e.g. Dr. Jane Doe">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Username</label>
                        <input type="text" name="username" class="form-control" required placeholder="Login username">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Role</label>
                        <select name="role" class="form-select">
                            <option value="Doctor">Doctor</option>
                            <option value="Receptionist">Receptionist</option>
                            <option value="LabTech">Lab Technician</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Password <span class="text-muted fw-normal">(min 6 chars)</span></label>
                        <div class="input-group">
                            <input type="password" name="password" id="newPass" class="form-control" required minlength="6">
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePass('newPass', this)">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" name="add_user" class="btn btn-primary w-100 rounded-pill">
                        <i class="fa-solid fa-user-plus me-2"></i>Create Account
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- STAFF LIST -->
    <div class="col-md-8">
        <div class="glass-card">
            <div class="card-header bg-white p-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark">Current Staff</h6>
                <input type="text" id="staffSearch" class="form-control form-control-sm w-auto"
                       placeholder="Search staff..." onkeyup="filterStaff()" style="max-width:200px">
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="staffTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Role</th>
                            <th>Username</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($user_rows as $row): ?>
                        <tr class="user-row">
                            <td class="ps-3">
                                <span class="fw-bold text-dark"><?php echo htmlspecialchars($row['full_name']); ?></span>
                                <?php if ($row['user_id'] == $_SESSION['id']): ?>
                                    <span class="badge bg-primary ms-1" style="font-size:.65rem">You</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="role-badge role-badge-<?php echo $row['role']; ?>">
                                    <?php echo htmlspecialchars($row['role']); ?>
                                </span>
                            </td>
                            <td><code><?php echo htmlspecialchars($row['username']); ?></code></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <?php if ($row['user_id'] != $_SESSION['id']): ?>

                                    <!-- Change Role -->
                                    <button class="btn btn-sm btn-outline-primary rounded-pill"
                                            onclick="showRoleModal(<?php echo $row['user_id']; ?>, '<?php echo htmlspecialchars($row['full_name']); ?>', '<?php echo $row['role']; ?>')">
                                        <i class="fa-solid fa-user-tag"></i>
                                    </button>

                                    <!-- Reset Password -->
                                    <button class="btn btn-sm btn-outline-warning rounded-pill"
                                            onclick="showPassModal(<?php echo $row['user_id']; ?>, '<?php echo htmlspecialchars($row['full_name']); ?>')">
                                        <i class="fa-solid fa-key"></i>
                                    </button>

                                    <!-- Delete -->
                                    <button class="btn btn-sm btn-outline-danger rounded-pill"
                                            onclick="confirmDelete(<?php echo $row['user_id']; ?>, '<?php echo htmlspecialchars($row['full_name']); ?>')">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>

                                    <?php else: ?>
                                    <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- CHANGE ROLE MODAL -->
<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white border-0">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-user-tag me-2"></i>Change Role</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="manage_users.php">
                <div class="modal-body">
                    <p class="mb-3">Changing role for: <strong id="roleModalName"></strong></p>
                    <input type="hidden" name="user_id" id="roleModalId">
                    <select name="new_role" id="roleModalSelect" class="form-select">
                        <option value="Doctor">Doctor</option>
                        <option value="Receptionist">Receptionist</option>
                        <option value="LabTech">Lab Technician</option>
                        <option value="Admin">Admin</option>
                    </select>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="change_role" class="btn btn-primary btn-sm px-4">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- RESET PASSWORD MODAL -->
<div class="modal fade" id="passModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning border-0">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-key me-2"></i>Reset Password</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="manage_users.php">
                <div class="modal-body">
                    <p class="mb-3">Reset password for: <strong id="passModalName"></strong></p>
                    <input type="hidden" name="user_id" id="passModalId">
                    <div class="input-group">
                        <input type="password" name="new_password" id="resetPass" class="form-control"
                               placeholder="New password" required minlength="6">
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePass('resetPass', this)">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <small class="text-muted">Minimum 6 characters.</small>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reset_password" class="btn btn-warning btn-sm px-4">Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white border-0">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-trash me-2"></i>Delete User</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteModalName"></strong>?</p>
                <p class="text-danger small mb-0"><i class="fa-solid fa-triangle-exclamation me-1"></i>This cannot be undone.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="deleteModalBtn" class="btn btn-danger btn-sm px-4">Delete</a>
            </div>
        </div>
    </div>
</div>

<script>
function showRoleModal(id, name, currentRole) {
    document.getElementById('roleModalId').value   = id;
    document.getElementById('roleModalName').textContent = name;
    document.getElementById('roleModalSelect').value = currentRole;
    new bootstrap.Modal(document.getElementById('roleModal')).show();
}

function showPassModal(id, name) {
    document.getElementById('passModalId').value   = id;
    document.getElementById('passModalName').textContent = name;
    document.getElementById('resetPass').value = '';
    new bootstrap.Modal(document.getElementById('passModal')).show();
}

function confirmDelete(id, name) {
    document.getElementById('deleteModalName').textContent = name;
    document.getElementById('deleteModalBtn').href = 'manage_users.php?delete=' + id;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function togglePass(inputId, btn) {
    const input = document.getElementById(inputId);
    const isPass = input.type === 'password';
    input.type = isPass ? 'text' : 'password';
    btn.innerHTML = isPass ? '<i class="fa-solid fa-eye-slash"></i>' : '<i class="fa-solid fa-eye"></i>';
}

function filterStaff() {
    const q    = document.getElementById('staffSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#staffTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>

<?php include 'includes/footer.php'; ?>