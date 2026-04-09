<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'Admin') {
    header("location: dashboard.php"); exit;
}

$success = $error = "";
$validRoles = ['Doctor', 'Receptionist', 'LabTech', 'Admin'];
$myId = intval($_SESSION['id'] ?? 0); // login sets $_SESSION['id']

// ── Detect which optional columns exist in users table ───────────────────────
function users_col($conn, $col) {
    $r = $conn->query("SELECT COUNT(*) as n FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='$col'");
    return $r && $r->fetch_assoc()['n'] > 0;
}
$has_email      = users_col($conn, 'email');
$has_created_at = users_col($conn, 'created_at');

// ── ADD USER ─────────────────────────────────────────────────────────────────
if (isset($_POST['add_user'])) {
    csrf_verify();
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username']  ?? '');
    $password  = trim($_POST['password']  ?? '');
    $newRole   = trim($_POST['role']      ?? '');
    $email     = trim($_POST['email']     ?? '');

    if (!$full_name || !$username || !$password || !in_array($newRole, $validRoles)) {
        $error = "All fields are required and role must be valid.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $chk = $conn->prepare("SELECT user_id FROM users WHERE username=?");
        $chk->bind_param("s", $username);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = "Username '$username' is already taken.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            // Only insert email if the column exists
            if ($has_email) {
                $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role, email) VALUES (?,?,?,?,?)");
                $stmt->bind_param("sssss", $full_name, $username, $hash, $newRole, $email);
            } else {
                $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role) VALUES (?,?,?,?)");
                $stmt->bind_param("ssss", $full_name, $username, $hash, $newRole);
            }
            if ($stmt->execute()) {
                audit_log($conn, 'add_user', 'users', $conn->insert_id, "Added: $username ($newRole)");
                $success = "User '$full_name' added with role '$newRole'.";
            } else {
                $error = "Failed to add user: " . $conn->error;
            }
            $stmt->close();
        }
        $chk->close();
    }
}

// ── CHANGE ROLE ──────────────────────────────────────────────────────────────
if (isset($_POST['change_role'])) {
    csrf_verify();
    $uid     = intval($_POST['user_id'] ?? 0);
    $newRole = trim($_POST['new_role']  ?? '');
    if ($uid > 0 && in_array($newRole, $validRoles)) {
        $stmt = $conn->prepare("UPDATE users SET role=? WHERE user_id=?");
        $stmt->bind_param("si", $newRole, $uid);
        if ($stmt->execute()) {
            audit_log($conn, 'change_role', 'users', $uid, "User ID $uid → $newRole");
            $success = "Role updated to '$newRole'.";
        } else {
            $error = "Role update failed.";
        }
        $stmt->close();
    } else {
        $error = "Invalid user or role.";
    }
}

// ── RESET PASSWORD ───────────────────────────────────────────────────────────
if (isset($_POST['reset_password'])) {
    csrf_verify();
    $uid  = intval($_POST['user_id']    ?? 0);
    $pass = trim($_POST['new_password'] ?? '');
    if ($uid > 0 && strlen($pass) >= 6) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE user_id=?");
        $stmt->bind_param("si", $hash, $uid);
        if ($stmt->execute()) {
            audit_log($conn, 'reset_password', 'users', $uid, "Password reset for user ID $uid");
            $success = "Password reset.";
        } else {
            $error = "Reset failed.";
        }
        $stmt->close();
    } else {
        $error = "Password must be at least 6 characters.";
    }
}

// ── DELETE USER ──────────────────────────────────────────────────────────────
if (isset($_POST['delete_user'])) {
    csrf_verify();
    $uid = intval($_POST['user_id'] ?? 0);
    if ($uid > 0 && $uid !== $myId) {
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id=?");
        $stmt->bind_param("i", $uid);
        if ($stmt->execute()) {
            audit_log($conn, 'delete_user', 'users', $uid, "Deleted user ID $uid");
            $success = "User deleted.";
        } else {
            $error = "Delete failed.";
        }
        $stmt->close();
    } else {
        $error = "Cannot delete your own account.";
    }
}

// ── FETCH USERS — only select columns that exist ─────────────────────────────
$select_cols = "user_id, full_name, username, role";
if ($has_email)      $select_cols .= ", email";
if ($has_created_at) $select_cols .= ", created_at";

$users = [];
$res = $conn->query("SELECT $select_cols FROM users ORDER BY role, full_name");
if ($res) {
    $users = $res->fetch_all(MYSQLI_ASSOC);
} else {
    // Absolute fallback — bare minimum columns guaranteed to exist
    $res2 = $conn->query("SELECT user_id, full_name, username, role FROM users ORDER BY role, full_name");
    if ($res2) $users = $res2->fetch_all(MYSQLI_ASSOC);
}

$roleCounts = array_fill_keys($validRoles, 0);
foreach ($users as $u) {
    if (isset($roleCounts[$u['role']])) $roleCounts[$u['role']]++;
}

$csrf = csrf_token();
$page_title = 'Manage Users — Yala LIMS';
include 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="fa-solid fa-users me-2 text-primary"></i>Manage Users</h4>
        <small class="text-muted"><?= count($users) ?> staff account<?= count($users) != 1 ? 's' : '' ?></small>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="alert alert-info border-0 py-2 mb-3 small">
    <i class="fa-solid fa-circle-info me-2"></i>
    <strong>Receptionist</strong> = patient registration + cashier/billing. &nbsp;
    <strong>Doctor</strong> = test requesting + reports. &nbsp;
    <strong>LabTech</strong> = enter results. &nbsp;
    <strong>Admin</strong> = full access.
</div>

<!-- Role summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="glass-card p-3 text-center">
            <div class="fw-bold fs-3 text-primary"><?= $roleCounts['Admin'] ?></div>
            <small class="text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.06em">Admins</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="glass-card p-3 text-center">
            <div class="fw-bold fs-3 text-success"><?= $roleCounts['Doctor'] ?></div>
            <small class="text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.06em">Doctors</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="glass-card p-3 text-center">
            <div class="fw-bold fs-3 text-warning"><?= $roleCounts['Receptionist'] ?></div>
            <small class="text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.06em">Receptionists</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="glass-card p-3 text-center">
            <div class="fw-bold fs-3" style="color:#7c3aed"><?= $roleCounts['LabTech'] ?></div>
            <small class="text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.06em">Lab Techs</small>
        </div>
    </div>
</div>

<div class="row g-4">

    <!-- Add User Form -->
    <div class="col-md-4">
        <div class="glass-card p-4">
            <h6 class="text-uppercase fw-bold text-muted mb-3" style="font-size:.72rem;letter-spacing:.08em">
                <i class="fa-solid fa-user-plus me-1"></i>Add New Staff
            </h6>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Full Name</label>
                    <input name="full_name" class="form-control" placeholder="e.g. Dr. Jane Otieno" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Username</label>
                    <input name="username" class="form-control" placeholder="login username" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Role</label>
                    <select name="role" class="form-select" required>
                        <option value="">— Select role —</option>
                        <option value="Doctor">Doctor</option>
                        <option value="Receptionist">Receptionist (+ Cashier)</option>
                        <option value="LabTech">Lab Technician</option>
                        <option value="Admin">Admin</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Password</label>
                    <input name="password" type="password" class="form-control"
                        placeholder="min 6 characters" required minlength="6">
                </div>
                <?php if ($has_email): ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Email <span class="text-muted">(optional)</span></label>
                    <input name="email" type="email" class="form-control" placeholder="staff@hospital.go.ke">
                </div>
                <?php endif; ?>
                <button name="add_user" class="btn btn-primary w-100">
                    <i class="fa-solid fa-plus me-1"></i>Add Staff Member
                </button>
            </form>
        </div>
    </div>

    <!-- Users Table -->
    <div class="col-md-8">
        <div class="glass-card p-4">
            <div class="mb-3">
                <input type="text" id="searchInput" class="form-control"
                    placeholder="🔍 Search by name, username, or role…"
                    oninput="filterTable()">
            </div>

            <?php if (empty($users)): ?>
            <div class="text-center py-4 text-muted">
                <i class="fa-solid fa-users fa-2x mb-2 opacity-25"></i>
                <p class="small">No staff accounts found. Add the first one using the form on the left.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="usersTable">
                    <thead class="table-light">
                        <tr>
                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Name</th>
                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Role</th>
                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Username</th>
                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u):
                        $isSelf     = ($u['user_id'] == $myId);
                        $badgeClass = match($u['role'] ?? '') {
                            'Admin'        => 'bg-primary',
                            'Doctor'       => 'bg-success',
                            'Receptionist' => 'bg-warning text-dark',
                            'LabTech'      => 'bg-purple',
                            default        => 'bg-danger',
                        };
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold">
                                    <?= htmlspecialchars($u['full_name']) ?>
                                    <?php if ($isSelf): ?><span class="badge bg-secondary ms-1" style="font-size:.62rem">You</span><?php endif; ?>
                                </div>
                                <?php if (!empty($u['email'])): ?>
                                <small class="text-muted"><?= htmlspecialchars($u['email']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($u['role'])): ?>
                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($u['role']) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-danger">
                                        <i class="fa-solid fa-triangle-exclamation me-1"></i>No Role
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted">@<?= htmlspecialchars($u['username']) ?></small></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-outline-primary btn-sm" title="Change Role"
                                        onclick="openRole(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['role'] ?? '') ?>', '<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">
                                        <i class="fa-solid fa-user-tag"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm" title="Reset Password"
                                        onclick="openPwd(<?= $u['user_id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">
                                        <i class="fa-solid fa-key"></i>
                                    </button>
                                    <?php if (!$isSelf): ?>
                                    <form method="POST" style="display:inline"
                                        onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($u['full_name'])) ?>? This cannot be undone.')">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="user_id"    value="<?= $u['user_id'] ?>">
                                        <button name="delete_user" class="btn btn-outline-danger btn-sm" title="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- CHANGE ROLE MODAL -->
<div class="modal fade" id="roleModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">
    <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-tag me-2"></i>Change Role</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="user_id"    id="rUserId">
        <p class="fw-semibold mb-3 text-secondary" id="rUserName"></p>
        <div class="mb-3">
            <label class="form-label small fw-semibold">New Role</label>
            <select name="new_role" id="rNewRole" class="form-select" required>
                <option value="Doctor">Doctor</option>
                <option value="Receptionist">Receptionist (+ Cashier)</option>
                <option value="LabTech">Lab Technician</option>
                <option value="Admin">Admin</option>
            </select>
        </div>
    </div>
    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button name="change_role" class="btn btn-primary">Save Role</button>
    </div>
    </form>
</div></div></div>

<!-- RESET PASSWORD MODAL -->
<div class="modal fade" id="pwdModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">
    <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-key me-2"></i>Reset Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
    <div class="modal-body">
        <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
        <input type="hidden" name="user_id"     id="pUserId">
        <p class="fw-semibold mb-3 text-secondary" id="pUserName"></p>
        <div class="mb-3">
            <label class="form-label small fw-semibold">New Password</label>
            <input name="new_password" type="password" class="form-control"
                placeholder="Minimum 6 characters" required minlength="6">
        </div>
    </div>
    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button name="reset_password" class="btn btn-primary">Reset Password</button>
    </div>
    </form>
</div></div></div>

<script>
function openRole(uid, role, name) {
    document.getElementById('rUserId').value = uid;
    document.getElementById('rUserName').textContent = 'Changing role for: ' + name;
    document.getElementById('rNewRole').value = role || 'Receptionist';
    new bootstrap.Modal(document.getElementById('roleModal')).show();
}
function openPwd(uid, name) {
    document.getElementById('pUserId').value = uid;
    document.getElementById('pUserName').textContent = 'Resetting password for: ' + name;
    new bootstrap.Modal(document.getElementById('pwdModal')).show();
}
function filterTable() {
    var q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#usersTable tbody tr').forEach(function(tr) {
        tr.style.display = tr.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
    });
}
</script>

<?php include 'includes/footer.php'; ?>