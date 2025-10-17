<?php
$host = "localhost";
$user = "root";
$pass = "";
$db = "role_auth_db"; // your database name

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}
?>
