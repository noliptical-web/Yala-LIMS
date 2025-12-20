<?php
// reset_admin.php
require_once 'includes/db_connect.php';

$new_password = "12345";
// This function creates a secure hash using your server's algorithms
$new_hash = password_hash($new_password, PASSWORD_DEFAULT); 

$sql = "UPDATE users SET password = ? WHERE username = 'admin'";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("s", $new_hash);
    $stmt->execute();
    echo "<h1>Success!</h1>";
    echo "<p>Admin password has been reset to: <strong>12345</strong></p>";
    echo "<p>Generated Hash: " . $new_hash . "</p>"; // Good for debugging
    echo "<br><a href='index.php'>Go to Login</a>";
    $stmt->close();
} else {
    echo "Error updating record: " . $conn->error;
}

$conn->close();
?>