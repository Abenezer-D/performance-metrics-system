<?php
include 'config.php';
session_start();

header('Content-Type: application/json');

// Check if user is admin
$is_admin = true; // Replace with actual admin check

if (!$is_admin) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['target_id'])) {
    $target_id = intval($_POST['target_id']);
    
    $delete_query = "DELETE FROM staff_targets WHERE id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("i", $target_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>