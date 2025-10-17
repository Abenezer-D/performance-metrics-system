<?php
include 'config.php';
session_start();

// Check if user is admin (replace with your actual admin check)
$is_admin = true; 

if (!$is_admin) {
    header('Location: upload_targets.php?error=Unauthorized');
    exit;
}

// Function to get quarter dates based on selected quarter
function getQuarterDates($quarter, $year) {
    $quarters = [
        1 => ['start' => "$year-07-01", 'end' => "$year-09-30"],
        2 => ['start' => "$year-10-01", 'end' => "$year-12-31"],
        3 => ['start' => ($year+1)."-01-01", 'end' => ($year+1)."-03-31"],
        4 => ['start' => ($year+1)."-04-01", 'end' => ($year+1)."-06-30"]
    ];
    return isset($quarters[$quarter]) ? $quarters[$quarter] : null;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $staff_name = trim($_POST['staff_name']);
    $kpi_id = intval($_POST['kpi_id']);
    $daily_target = !empty($_POST['daily_target']) ? floatval($_POST['daily_target']) : NULL;
    $weekly_target = !empty($_POST['weekly_target']) ? floatval($_POST['weekly_target']) : NULL;
    $monthly_target = !empty($_POST['monthly_target']) ? floatval($_POST['monthly_target']) : NULL;
    $quarter_target = floatval($_POST['quarter_target']);
    $quarter_number = intval($_POST['quarter_number']);
    $quarter_year = intval($_POST['quarter_year']);
    
    // Validate required fields
    if (empty($staff_name) || empty($kpi_id) || empty($quarter_target) || empty($quarter_number) || empty($quarter_year)) {
        header('Location: upload_targets.php?error=Please fill all required fields');
        exit;
    }
    
    // Get quarter dates
    $quarter_dates = getQuarterDates($quarter_number, $quarter_year);
    if (!$quarter_dates) {
        header('Location: upload_targets.php?error=Invalid quarter selected');
        exit;
    }
    
    $quarter_start = $quarter_dates['start'];
    $quarter_end = $quarter_dates['end'];
    
    // Check if target already exists for this staff, KPI and quarter
    $check_query = "SELECT id FROM staff_targets WHERE staff_name = ? AND kpi_id = ? AND quarter_start = ? AND quarter_end = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("siss", $staff_name, $kpi_id, $quarter_start, $quarter_end);
    $stmt->execute();
    $check_result = $stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Update existing target
        $update_query = "UPDATE staff_targets SET daily_target = ?, weekly_target = ?, 
                        monthly_target = ?, quarter_target = ?, updated_at = NOW() 
                        WHERE staff_name = ? AND kpi_id = ? AND quarter_start = ? AND quarter_end = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("ddddsiss", $daily_target, $weekly_target, $monthly_target, 
                         $quarter_target, $staff_name, $kpi_id, $quarter_start, $quarter_end);
    } else {
        // Insert new target
        $insert_query = "INSERT INTO staff_targets (staff_name, kpi_id, daily_target, 
                        weekly_target, monthly_target, quarter_target, quarter_start, quarter_end) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("siddddss", $staff_name, $kpi_id, $daily_target, 
                         $weekly_target, $monthly_target, $quarter_target, $quarter_start, $quarter_end);
    }
    
    if ($stmt->execute()) {
        header('Location: upload_targets.php?message=Target added successfully');
    } else {
        header('Location: upload_targets.php?error=Error adding target: ' . $stmt->error);
    }
} else {
    header('Location: upload_targets.php?error=Invalid request');
}
?>