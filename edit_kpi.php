<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id']);
    $category = trim($_POST['category']);
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);

    if (!$id || !$name || !$category) {
        echo json_encode(["status" => "error", "message" => "Missing required fields."]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE kpis SET kpi_category=?, kpi_name=?, kpi_description=? WHERE id=?");
    $stmt->bind_param("sssi", $category, $name, $description, $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "KPI updated successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Database update failed."]);
    }

    $stmt->close();
    $conn->close();
}
?>
