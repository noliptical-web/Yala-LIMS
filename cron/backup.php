<?php
// cron/backup.php — run via crontab: 0 2 * * * php /var/www/html/yala_lims/cron/backup.php
define('RUNNING_FROM_CRON', true);
chdir(dirname(__DIR__));
require_once 'includes/db_connect.php';

define('BACKUP_DIR', __DIR__ . '/../backups/');
if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0750, true);

$file = BACKUP_DIR . 'yala_lims_' . date('Y-m-d_His') . '.sql';
$cmd  = "mysqldump --user=" . escapeshellarg(DB_USER)
      . " --password=" . escapeshellarg(DB_PASS)
      . " --host="     . escapeshellarg(DB_HOST)
      . " "            . escapeshellarg(DB_NAME)
      . " > "          . escapeshellarg($file) . " 2>&1";

exec($cmd, $out, $code);

if ($code === 0 && file_exists($file) && filesize($file) > 0) {
    $size = filesize($file); $fn = basename($file);
    $conn->query("INSERT INTO backup_log (filename,size_bytes,triggered_by) VALUES ('$fn',$size,'cron')");
    echo "[OK] Backup: $fn (" . round($size/1024) . " KB)\n";

    // Keep only last 14 backups
    $all = glob(BACKUP_DIR . '*.sql') ?: [];
    rsort($all);
    foreach (array_slice($all, 14) as $old) { unlink($old); echo "[CLEANUP] Removed: " . basename($old) . "\n"; }
} else {
    echo "[ERROR] Backup failed. Output: " . implode("\n", $out) . "\n";
}
