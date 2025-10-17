<?php
include 'db.php';
session_start();

if ($_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit();
}

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $mysqli->query("DELETE FROM users WHERE id=$id");
}
header("Location: admin_panel.php");
?>
