<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Redirect users to role-specific panels
$role = $_SESSION['user_role'] ?? 'user';
if ($role === 'admin') {
    header('Location: admin_panel.php');
    exit;
} elseif ($role === 'manager') {
    header('Location: manager_panel.php');
    exit;
} else {
    header('Location: user_panel.php');
    exit;
}
