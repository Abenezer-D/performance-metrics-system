<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? 0;
if(!$user_id){
    echo json_encode(['status'=>'error','message'=>'Unauthorized']);
    exit();
}

$performance_date = $_POST['performance_date'] ?? '';
if(!$performance_date){
    echo json_encode(['status'=>'error','message'=>'Select date']);
    exit();
}

foreach($_POST as $key=>$value){
    if(strpos($key,'kpi_')===0){
        $kpi_id = intval(str_replace('kpi_','',$key));
        $performance_value = floatval($value);
        $stmt = $conn->prepare("INSERT INTO user_performance (user_id,kpi_id,performance_value,performance_date) VALUES (?,?,?,?)");
        $stmt->bind_param("iids",$user_id,$kpi_id,$performance_value,$performance_date);
        $stmt->execute();
        $stmt->close();
    }
}

$conn->close();
echo json_encode(['status'=>'success','message'=>'Performance submitted successfully']);
exit();
