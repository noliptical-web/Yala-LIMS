<?php
// includes/db_connect.php

$servername = "localhost";
$username   = "root"; // Default XAMPP username
$password   = "";     // Default XAMPP password is empty
$dbname     = "yala_lims_db";

// DEFINE CONSTANTS (must be before connection is created)
define('DB_HOST', $servername);
define('DB_USER', $username);
define('DB_PASS', $password);
define('DB_NAME', $dbname);

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>