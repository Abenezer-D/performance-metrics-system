<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/php_errors.log');
error_reporting(E_ALL);

header('Content-Type: application/json');
session_start();
require_once 'db.php';

// TEMP: force admin for testing
if (!isset($_SESSION['role'])) $_SESSION['role'] = 'admin';

// Check role
if ($_SESSION['role'] !== 'admin') {
    echo json_encode(['status'=>'error','message'=>'Unauthorized']);
    exit();
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status'=>'error','message'=>'Invalid request']);
    exit();
}

// Validate DB
if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['status'=>'error','message'=>'Database connection failed']);
    exit();
}

// Collect POST data
$kpi_name = trim($_POST['kpi_name'] ?? '');
$kpi_category = trim($_POST['kpi_category'] ?? '');
$kpi_description = trim($_POST['kpi_description'] ?? '');

// Validate required fields
if (!$kpi_name || !$kpi_category) {
    echo json_encode(['status'=>'error','message'=>'Please fill all required fields']);
    exit();
}

// Check duplicate
$stmt = $conn->prepare("SELECT id FROM kpis WHERE kpi_name=?");
if(!$stmt){
    echo json_encode(['status'=>'error','message'=>'Prepare failed: '.$conn->error]);
    exit();
}
$stmt->bind_param("s",$kpi_name);
$stmt->execute();
$stmt->store_result();
if($stmt->num_rows>0){
    echo json_encode(['status'=>'error','message'=>'KPI already exists']);
    $stmt->close();
    exit();
}
$stmt->close();

// Insert KPI
$stmt = $conn->prepare("INSERT INTO kpis (kpi_name,kpi_category,kpi_description,created_at) VALUES (?,?,?,NOW())");
if(!$stmt){
    echo json_encode(['status'=>'error','message'=>'Prepare insert failed: '.$conn->error]);
    exit();
}
$stmt->bind_param("sss",$kpi_name,$kpi_category,$kpi_description);

if($stmt->execute()){
    echo json_encode(['status'=>'success','message'=>'KPI added successfully']);
} else {
    echo json_encode(['status'=>'error','message'=>'Insert failed: '.$stmt->error]);
}

$stmt->close();
$conn->close();
exit();
