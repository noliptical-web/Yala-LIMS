<?php
session_start();
echo "<pre>";
echo "Session role: [" . ($_SESSION['role'] ?? 'NOT SET') . "]\n";
echo "Session role length: " . strlen($_SESSION['role'] ?? '') . "\n";
echo "Hex: " . bin2hex($_SESSION['role'] ?? '') . "\n";
echo "Full name: " . ($_SESSION['full_name'] ?? 'NOT SET') . "\n";
echo "Is Cashier (===): " . (($_SESSION['role'] ?? '') === 'Cashier' ? 'YES' : 'NO') . "\n";
echo "Trimmed === Cashier: " . (trim($_SESSION['role'] ?? '') === 'Cashier' ? 'YES' : 'NO') . "\n";
echo "</pre>";
echo "<a href='dashboard.php'>Back to dashboard</a>";