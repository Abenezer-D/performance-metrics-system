<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $position = trim($_POST['position']);
    $branch = trim($_POST['branch'] ?? '');
    $district = trim($_POST['district'] ?? '');

    // Validate required fields
    if (empty($username) || empty($email) || empty($password) || empty($role)) {
        echo json_encode(['status' => 'error', 'message' => 'All required fields must be filled']);
        exit();
    }

    // For district staff, district is required
    if ($role === 'district' && empty($district)) {
        echo json_encode(['status' => 'error', 'message' => 'District is required for district staff']);
        exit();
    }

    // Check if email already exists
    $check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check_email->bind_param("s", $email);
    $check_email->execute();
    $check_email->store_result();

    if ($check_email->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Email already exists']);
        exit();
    }

    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Auto-set position based on role if empty
    if (empty($position)) {
        switch ($role) {
            case 'admin':
                $position = 'Administrator';
                break;
            case 'manager':
                $position = 'Branch Manager';
                break;
            case 'district':
                $position = 'District Staff';
                break;
            case 'user':
                $position = 'Staff';
                break;
            default:
                $position = 'Staff';
        }
    }

    // For district staff, clear branch field
    if ($role === 'district') {
        $branch = '';
    }

    // Insert user
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, position, branch, district) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $username, $email, $hashed_password, $role, $position, $branch, $district);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'User added successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to add user: ' . $conn->error]);
    }

    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
?>