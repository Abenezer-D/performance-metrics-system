<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

// ✅ Allow only manager role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

// ✅ Ensure required data is received
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id']) || !isset($_POST['status'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit();
}

$id = intval($_POST['id']);
$status = $_POST['status'] === 'approved' ? 'approved' : 'rejected';
$manager_branch = $_SESSION['branch'] ?? '';

// ✅ Validate DB connection
if (!isset($conn) || !$conn) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection error']);
    exit();
}

// ✅ Prepare update query ensuring branch restriction
$query = "
    UPDATE user_submissions us
    JOIN users u ON us.user_id = u.id
    SET us.status = ?
    WHERE us.id = ? AND u.branch = ?
";

$stmt = $conn->prepare($query);

if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("sis", $status, $id, $manager_branch);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            'status' => 'success',
            'message' => "Submission has been successfully {$status}."
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'No matching submission found or not from your branch.'
        ]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Query execution failed.']);
}

$stmt->close();
$conn->close();
?>
