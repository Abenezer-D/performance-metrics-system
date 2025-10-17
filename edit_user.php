<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $position = trim($_POST['position']);
    $branch = trim($_POST['branch'] ?? '');
    $district = trim($_POST['district'] ?? '');

    // Validate required fields
    if (empty($username) || empty($email) || empty($role)) {
        echo json_encode(['status' => 'error', 'message' => 'All required fields must be filled']);
        exit();
    }

    // For district staff, district is required and branch should be empty
    if ($role === 'district') {
        if (empty($district)) {
            echo json_encode(['status' => 'error', 'message' => 'District is required for district staff']);
            exit();
        }
        // Clear branch for district staff
        $branch = '';
        // Auto-set position if empty
        if (empty($position)) {
            $position = 'District Staff';
        }
    } else {
        // For non-district roles, clear district field
        $district = '';
        // Auto-set positions for other roles if empty
        if (empty($position)) {
            switch ($role) {
                case 'admin':
                    $position = 'Administrator';
                    break;
                case 'manager':
                    $position = 'Branch Manager';
                    break;
                case 'user':
                    $position = 'Staff';
                    break;
            }
        }
    }

    // Check if email already exists (excluding current user)
    $check_email = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $check_email->bind_param("si", $email, $id);
    $check_email->execute();
    $check_email->store_result();

    if ($check_email->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Email already exists']);
        exit();
    }

    // Update user
    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, role = ?, position = ?, branch = ?, district = ? WHERE id = ?");
    $stmt->bind_param("ssssssi", $username, $email, $role, $position, $branch, $district, $id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'User updated successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to update user: ' . $conn->error]);
    }

    $stmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
?>