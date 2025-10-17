<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

// basic validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 1) {
    header('Location: login.php?error=' . urlencode('Invalid credentials'));
    exit;
}

$stmt = $mysqli->prepare('SELECT id, fullname, email, password, role FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
if ($user = $result->fetch_assoc()) {
    if (password_verify($password, $user['password'])) {
        // login success
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_fullname'] = $user['fullname'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        header('Location: dashboard.php');
        exit;
    } else {
        header('Location: login.php?error=' . urlencode('Incorrect email or password'));
        exit;
    }
} else {
    header('Location: login.php?error=' . urlencode('No account found with that email'));
    exit;
}
