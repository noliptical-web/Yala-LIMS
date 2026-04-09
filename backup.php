<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['role'] != 'Admin') {
    header("location: dashboard.php"); exit;
}

define('BACKUP_DIR', __DIR__ . '/backups/');
if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0750, true);
$htaccess = BACKUP_DIR . '.htaccess';
if (!file_exists($htaccess)) file_put_contents($htaccess, "Deny from all\n");

$success = $error = '';

// ── Read DB credentials ───────────────────────────────────────────────────────
// PHP 7-safe: no chained ternary
$db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
$db_user = defined('DB_USER') ? DB_USER : '';
$db_pass = defined('DB_PASS') ? DB_PASS : '';
$db_name = defined('DB_NAME') ? DB_NAME : '';

// Fallback: parse db_connect.php as text
if (!$db_user || !$db_name) {
    $db_src = @file_get_contents(__DIR__ . '/includes/db_connect.php');
    if ($db_src) {
        if (preg_match('/\$servername\s*=\s*["\']([^"\']+)["\']/', $db_src, $m)) $db_host = $m[1];
        if (preg_match('/\$username\s*=\s*["\']([^"\']+)["\']/',   $db_src, $m)) $db_user = $m[1];
        if (preg_match('/\$password\s*=\s*["\']([^"\']*)[\"\']/',   $db_src, $m)) $db_pass = $m[1];
        if (preg_match('/\$dbname\s*=\s*["\']([^"\']+)["\']/',     $db_src, $m)) $db_name = $m[1];
    }
}

// ── TRIGGER BACKUP ────────────────────────────────────────────────────────────
if (isset($_POST['run_backup'])) {
    if (!$db_user || !$db_name) {
        $error = "Cannot read database credentials from includes/db_connect.php.";
    } else {
        $file     = BACKUP_DIR . 'yala_lims_' . date('Y-m-d_His') . '.sql';
        $cnf_file = tempnam(sys_get_temp_dir(), 'lims_');
        file_put_contents($cnf_file, "[mysqldump]\nuser={$db_user}\npassword={$db_pass}\nhost={$db_host}\n");
        chmod($cnf_file, 0600);

        // Try multiple mysqldump paths (XAMPP + Linux)
        $paths = [
            'C:/xampp/mysql/bin/mysqldump.exe',
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ];

        $worked = false;
        foreach ($paths as $mp) {
            $cmd = escapeshellarg($mp)
                 . ' --defaults-extra-file=' . escapeshellarg($cnf_file)
                 . ' --single-transaction --routines --triggers '
                 . escapeshellarg($db_name)
                 . ' > ' . escapeshellarg($file) . ' 2>&1';
            exec($cmd, $out, $code);
            if ($code === 0 && file_exists($file) && filesize($file) > 500) {
                $worked = true;
                break;
            }
            $out = [];
        }
        @unlink($cnf_file);

        if ($worked) {
            $size = filesize($file);
            $fn   = basename($file);
            $user = $conn->real_escape_string($_SESSION['username'] ?? 'admin');
            $conn->query("INSERT IGNORE INTO backup_log (filename,size_bytes,triggered_by,created_at)
                          VALUES ('$fn',$size,'$user',NOW())");
            audit_log($conn, 'backup', 'backup_log', 0, "Created backup: $fn");
            $success = "Backup created: <strong>$fn</strong> (" . round($size / 1024) . " KB)";
        } else {
            if (file_exists($file)) @unlink($file);
            $error = "mysqldump failed. On XAMPP, make sure <code>C:/xampp/mysql/bin</code> is in your Windows PATH (System Environment Variables), then restart Apache.";
        }
    }
}

// ── DOWNLOAD ──────────────────────────────────────────────────────────────────
if (isset($_GET['dl'])) {
    $fn   = basename($_GET['dl']);
    $path = BACKUP_DIR . $fn;
    if (file_exists($path) && preg_match('/^yala_lims_[\d_]+\.sql$/', $fn)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path); exit;
    }
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if (isset($_GET['del'])) {
    $fn   = basename($_GET['del']);
    $path = BACKUP_DIR . $fn;
    if (file_exists($path) && preg_match('/^yala_lims_[\d_]+\.sql$/', $fn)) {
        unlink($path);
        $success = "Deleted: $fn";
    }
}

$files = glob(BACKUP_DIR . '*.sql');
if (!$files) $files = [];
rsort($files);

$page_title = 'Database Backup — Yala LIMS';
include 'includes/header.php';
?>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fa-solid fa-circle-check me-2"></i><?= $success ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fa-solid fa-circle-exclamation me-2"></i><?= $error ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="fa-solid fa-database me-2 text-primary"></i>Database Backup</h4>
        <small class="text-muted">Create and download full SQL dumps of all LIMS data</small>
    </div>
</div>

<div class="row g-4">

    <!-- Left: Run backup -->
    <div class="col-md-4">
        <div class="glass-card p-4">
            <h6 class="text-uppercase fw-bold text-muted mb-3" style="font-size:.72rem;letter-spacing:.08em">
                <i class="fa-solid fa-play me-1"></i>Manual Backup
            </h6>
            <form method="POST">
                <button type="submit" name="run_backup" class="btn btn-success w-100 fw-bold py-3 mb-3">
                    <i class="fa-solid fa-database me-2"></i>Run Backup Now
                </button>
            </form>

            <?php if ($db_user && $db_name): ?>
            <div class="alert alert-light border small mb-0">
                <strong class="d-block mb-2">Credentials detected:</strong>
                Host: <code><?= htmlspecialchars($db_host) ?></code><br>
                User: <code><?= htmlspecialchars($db_user) ?></code><br>
                Database: <code><?= htmlspecialchars($db_name) ?></code><br><br>
                Backups saved to <code>backups/</code> folder.<br><br>
                <strong>Auto-daily (cron):</strong><br>
                <code style="font-size:.7rem">0 2 * * * cd /var/www/html/yala_lims && php cron/backup.php</code>
            </div>
            <?php else: ?>
            <div class="alert alert-warning small mb-0">
                <strong><i class="fa-solid fa-triangle-exclamation me-1"></i>Cannot read credentials</strong><br>
                Ensure <code>DB_HOST</code>, <code>DB_USER</code>, <code>DB_PASS</code>, <code>DB_NAME</code>
                are defined in <code>includes/db_connect.php</code>.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: Backup files -->
    <div class="col-md-8">
        <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0"><i class="fa-solid fa-folder me-2 text-warning"></i>Backup Files</h6>
                <span class="badge bg-secondary"><?= count($files) ?> file<?= count($files) != 1 ? 's' : '' ?></span>
            </div>

            <?php if (empty($files)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-folder-open fa-3x mb-3 opacity-25"></i>
                <p class="small">No backups yet. Click "Run Backup Now" to create the first one.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Filename</th>
                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Size</th>
                            <th style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Created</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($files as $f):
                        $fn = basename($f);
                        $sz = filesize($f);
                        $mt = filemtime($f);
                    ?>
                        <tr>
                            <td><code style="font-size:.78rem"><?= htmlspecialchars($fn) ?></code></td>
                            <td><small class="text-muted"><?= $sz > 1024 ? round($sz / 1024) . ' KB' : $sz . ' B' ?></small></td>
                            <td><small class="text-muted"><?= date('d M Y H:i', $mt) ?></small></td>
                            <td class="text-end" style="white-space:nowrap">
                                <a href="?dl=<?= urlencode($fn) ?>" class="btn btn-outline-primary btn-sm me-1">
                                    <i class="fa-solid fa-download me-1"></i>Download
                                </a>
                                <a href="?del=<?= urlencode($fn) ?>" class="btn btn-outline-danger btn-sm"
                                    onclick="return confirm('Delete this backup file?')">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
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

<?php include 'includes/footer.php'; ?>