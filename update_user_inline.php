<?php
include 'db.php';

$id = intval($_POST['id']);
$column = $_POST['column'];
$value = trim($_POST['value']);

$allowed = ['fullname', 'position', 'branch', 'email', 'role'];
if (!in_array($column, $allowed)) {
    echo "❌ Invalid column!";
    exit;
}

$stmt = $mysqli->prepare("UPDATE users SET $column=? WHERE id=?");
$stmt->bind_param("si", $value, $id);
if ($stmt->execute()) {
    echo "✅ Updated successfully!";
} else {
    echo "❌ Update failed!";
}
?>
