<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.php');
    exit;
}

$fullname = trim($_POST['fullname']);
$email = trim($_POST['email']);
$password = $_POST['password'];
$role = $_POST['role'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: register.php?error=Invalid email');
    exit;
}
if (strlen($password) < 6) {
    header('Location: register.php?error=Password must be at least 6 characters');
    exit;
}

// Check if email already exists
$stmt = $mysqli->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    header('Location: register.php?error=Email already exists');
    exit;
}
$stmt->close();

// Insert new user
$hashed = password_hash($password, PASSWORD_DEFAULT);
$stmt = $mysqli->prepare("INSERT INTO users (fullname, email, password, role) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $fullname, $email, $hashed, $role);
if ($stmt->execute()) {
    header('Location: register.php?success=User created successfully');
} else {
    header('Location: register.php?error=Failed to create user');
}
$stmt->close();
