<?php
$role     = trim($_SESSION['role'] ?? '');
$fullName = $_SESSION['full_name'] ?? 'User';
$userId   = $_SESSION['user_id']   ?? 0;
$initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', trim($fullName)), 0, 2)));

$isAdmin     = ($role === 'Admin');
$isReception = ($role === 'Receptionist');
$isLabTech   = ($role === 'LabTech' || $role === 'Lab Technician' || $role === 'LabTechnician');
$isDoctor    = ($role === 'Doctor');

// Unread notifications
$unread = 0;
$nr = $conn->query("SELECT COUNT(*) FROM notifications WHERE is_read=0 AND (user_id=$userId OR user_id IS NULL)");
if ($nr) $unread = $nr->fetch_row()[0];
?>
<style>
.nav{background:#0f172a;position:sticky;top:0;z-index:900;
    display:flex;align-items:center;padding:0 24px;height:56px;gap:0}
.nav-brand{font-family:'DM Serif Display',serif;color:#fff;font-size:1.1rem;
    margin-right:32px;text-decoration:none;white-space:nowrap}
.nav-brand span{color:#60a5fa}
.nav-links{display:flex;align-items:center;gap:2px;flex:1}
.nav-link{color:#94a3b8;text-decoration:none;font-size:.82rem;font-weight:500;
    padding:6px 12px;border-radius:6px;transition:all .15s;white-space:nowrap}
.nav-link:hover{color:#fff;background:rgba(255,255,255,.08)}
.nav-link.active{color:#fff;background:rgba(255,255,255,.12)}
.nav-right{display:flex;align-items:center;gap:12px;margin-left:auto}
.notif-btn{position:relative;background:none;border:none;cursor:pointer;
    color:#94a3b8;font-size:1.1rem;padding:6px;line-height:1;transition:color .15s}
.notif-btn:hover{color:#fff}
.notif-badge{position:absolute;top:2px;right:2px;background:#ef4444;color:#fff;
    border-radius:50%;width:14px;height:14px;font-size:.6rem;font-weight:700;
    display:flex;align-items:center;justify-content:center;line-height:1}
.avatar{background:#1d4ed8;color:#fff;border-radius:8px;width:32px;height:32px;
    display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700}
.role-chip{background:rgba(255,255,255,.1);color:#cbd5e1;border-radius:6px;
    padding:4px 10px;font-size:.75rem;font-weight:600;white-space:nowrap}
.logout-btn{color:#94a3b8;text-decoration:none;font-size:.82rem;padding:6px 10px;
    border-radius:6px;transition:all .15s;white-space:nowrap}
.logout-btn:hover{color:#f87171;background:rgba(239,68,68,.1)}
</style>

<nav class="nav">
  <a href="dashboard.php" class="nav-brand">Yala <span>LIMS</span></a>
  <div class="nav-links">
    <?php if($isAdmin || $isReception || $isDoctor): ?>
    <a href="add_patient.php" class="nav-link">Register Patient</a>
    <?php endif; ?>

    <?php if($isAdmin || $isReception): ?>
    <a href="billing.php" class="nav-link">Cashier</a>
    <?php endif; ?>

    <?php if($isAdmin || $isReception || $isDoctor): ?>
    <a href="request_test.php" class="nav-link">Request Test</a>
    <?php endif; ?>

    <?php if($isAdmin || $isLabTech): ?>
    <a href="enter_results.php" class="nav-link">Enter Results</a>
    <?php endif; ?>

    <?php if($isAdmin || $isReception || $isDoctor || $isLabTech): ?>
    <a href="patient_history.php" class="nav-link">Patient History</a>
    <?php endif; ?>

    <?php if($isAdmin || $isDoctor): ?>
    <a href="reports.php" class="nav-link">Reports</a>
    <?php endif; ?>

    <?php if($isAdmin): ?>
    <a href="manage_users.php" class="nav-link">Users</a>
    <a href="manage_tests.php" class="nav-link">Tests</a>
    <a href="audit_view.php" class="nav-link">Audit</a>
    <a href="backup.php" class="nav-link">Backup</a>
    <?php endif; ?>
  </div>

  <div class="nav-right">
    <?php if($unread > 0): ?>
    <button class="notif-btn" onclick="window.location='notifications.php'" title="Notifications">
      🔔<span class="notif-badge"><?=$unread?></span>
    </button>
    <?php endif; ?>
    <div class="avatar" title="<?=htmlspecialchars($fullName)?>"><?=htmlspecialchars($initials)?></div>
    <span class="role-chip"><?=htmlspecialchars($role)?></span>
    <a href="logout.php" class="logout-btn">Logout</a>
  </div>
</nav>