<?php
include 'config.php';
session_start();

// Check if user is admin (you'll need to implement proper role checking)
$is_admin = true; // Replace with actual admin check

if (!$is_admin) {
    header('Location: district_dashboard.php');
    exit();
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

$message = '';
$error = '';

// Handle manual form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['staff_name'])) {
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
        $error = 'Please fill all required fields';
    } else {
        // Get quarter dates
        $quarter_dates = getQuarterDates($quarter_number, $quarter_year);
        if (!$quarter_dates) {
            $error = 'Invalid quarter selected';
        } else {
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
                $message = 'Target added successfully';
            } else {
                $error = 'Error adding target: ' . $stmt->error;
            }
        }
    }
}

// Handle CSV file upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['excel_file'])) {
    if ($_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp_path = $_FILES['excel_file']['tmp_name'];
        $file_name = $_FILES['excel_file']['name'];
        $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
        
        // Only allow CSV files
        if (strtolower($file_extension) !== 'csv') {
            $error = 'Please upload a CSV file. Excel files require additional dependencies.';
        } else {
            // Process the CSV file
            $data = processCSVFile($file_tmp_path, $conn);
            
            if ($data['success']) {
                $message = "Successfully imported {$data['imported']} targets. " . 
                          ($data['errors'] > 0 ? "{$data['errors']} errors occurred." : "");
                
                // Show error details if any
                if (!empty($data['error_messages'])) {
                    $message .= "<br><small>Errors: " . implode(', ', array_slice($data['error_messages'], 0, 5)) . "</small>";
                }
            } else {
                $error = $data['message'];
            }
        }
    } else {
        $error = 'Error uploading file. Please try again.';
    }
}

function processCSVFile($file_path, $conn) {
    $imported = 0;
    $errors = 0;
    $error_messages = [];
    
    if (($handle = fopen($file_path, 'r')) !== FALSE) {
        $headers = fgetcsv($handle); // Skip header row
        
        $row_number = 1;
        while (($data = fgetcsv($handle)) !== FALSE) {
            $row_number++;
            if (count($data) >= 8) {
                $result = importTargetRow($data, $conn, $row_number);
                if ($result['success']) {
                    $imported++;
                } else {
                    $errors++;
                    $error_messages[] = "Row $row_number: " . $result['message'];
                }
            } else {
                $errors++;
                $error_messages[] = "Row $row_number: Insufficient columns (expected 8, found " . count($data) . ")";
            }
        }
        fclose($handle);
    } else {
        return [
            'success' => false,
            'message' => 'Could not open CSV file'
        ];
    }
    
    return [
        'success' => true,
        'imported' => $imported,
        'errors' => $errors,
        'error_messages' => $error_messages
    ];
}

function importTargetRow($rowData, $conn, $row_number) {
    // Expected columns: Staff Name, KPI Name, Daily Target, Weekly Target, Monthly Target, Quarter Target, Quarter Number, Quarter Year
    $staff_name = trim($rowData[0]);
    $kpi_name = trim($rowData[1]);
    $daily_target = !empty(trim($rowData[2])) ? floatval($rowData[2]) : NULL;
    $weekly_target = !empty(trim($rowData[3])) ? floatval($rowData[3]) : NULL;
    $monthly_target = !empty(trim($rowData[4])) ? floatval($rowData[4]) : NULL;
    $quarter_target = !empty(trim($rowData[5])) ? floatval($rowData[5]) : NULL;
    $quarter_number = !empty(trim($rowData[6])) ? intval($rowData[6]) : NULL;
    $quarter_year = !empty(trim($rowData[7])) ? intval($rowData[7]) : NULL;
    
    // Validate data
    if (empty($staff_name) || empty($kpi_name)) {
        return ['success' => false, 'message' => 'Missing staff name or KPI name'];
    }
    
    if (empty($quarter_target) || empty($quarter_number) || empty($quarter_year)) {
        return ['success' => false, 'message' => 'Quarter target, quarter number, and quarter year are required'];
    }
    
    // Validate quarter number
    if ($quarter_number < 1 || $quarter_number > 4) {
        return ['success' => false, 'message' => 'Quarter number must be between 1 and 4'];
    }
    
    // Get quarter dates
    $quarter_dates = getQuarterDates($quarter_number, $quarter_year);
    if (!$quarter_dates) {
        return ['success' => false, 'message' => "Invalid quarter: Q$quarter_number $quarter_year"];
    }
    
    // Get KPI ID
    $kpi_query = "SELECT id FROM kpis WHERE kpi_name = ?";
    $stmt = $conn->prepare($kpi_query);
    $stmt->bind_param("s", $kpi_name);
    $stmt->execute();
    $kpi_result = $stmt->get_result();
    
    if ($kpi_result->num_rows === 0) {
        return ['success' => false, 'message' => "KPI '{$kpi_name}' not found"];
    }
    
    $kpi_row = $kpi_result->fetch_assoc();
    $kpi_id = $kpi_row['id'];
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
        return ['success' => true];
    } else {
        return ['success' => false, 'message' => 'Database error: ' . $stmt->error];
    }
}

// Get existing targets for display
$targets_query = "
    SELECT st.*, k.kpi_name 
    FROM staff_targets st 
    JOIN kpis k ON st.kpi_id = k.id 
    ORDER BY st.quarter_start DESC, st.staff_name, k.kpi_name
";
$targets_result = $conn->query($targets_query);
$staff_targets = [];
while ($row = $targets_result->fetch_assoc()) {
    $staff_targets[] = $row;
}

// Function to format quarter display
function formatQuarterDisplay($quarter_start, $quarter_end) {
    $start = date('M Y', strtotime($quarter_start));
    $end = date('M Y', strtotime($quarter_end));
    return "$start - $end";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Staff Targets</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --info: #43aa8b;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --border-radius: 12px;
            --box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fb;
            color: var(--dark);
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 20px 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 1.6rem;
            font-weight: 600;
            color: var(--dark);
        }

        .back-btn {
            background: var(--primary);
            color: white;
            padding: 10px 20px;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: var(--secondary);
        }

        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 24px;
            margin-bottom: 24px;
            transition: var(--transition);
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.12);
        }

        .card h3 {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: var(--dark);
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .card h3 i {
            margin-right: 10px;
            color: var(--primary);
        }

        .upload-area {
            border: 2px dashed var(--primary-light);
            border-radius: var(--border-radius);
            padding: 40px;
            text-align: center;
            background: rgba(67, 97, 238, 0.05);
            margin-bottom: 20px;
            transition: var(--transition);
        }

        .upload-area:hover {
            background: rgba(67, 97, 238, 0.1);
        }

        .upload-area i {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 15px;
        }

        .upload-area h4 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .upload-area p {
            color: var(--gray);
            margin-bottom: 20px;
        }

        .file-input {
            display: none;
        }

        .file-label {
            background: var(--primary);
            color: white;
            padding: 12px 24px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
            display: inline-block;
        }

        .file-label:hover {
            background: var(--secondary);
        }

        .selected-file {
            margin-top: 15px;
            font-size: 0.9rem;
            color: var(--gray);
        }

        .submit-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            font-size: 1rem;
        }

        .submit-btn:hover {
            background: #3ab0d6;
        }

        .submit-btn:disabled {
            background: var(--gray-light);
            color: var(--gray);
            cursor: not-allowed;
        }

        .message {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-weight: 500;
        }

        .message.success {
            background: rgba(76, 201, 240, 0.15);
            color: #4cc9f0;
            border: 1px solid rgba(76, 201, 240, 0.3);
        }

        .message.error {
            background: rgba(247, 37, 133, 0.15);
            color: #f72585;
            border: 1px solid rgba(247, 37, 133, 0.3);
        }

        .template-download {
            background: var(--info);
            color: white;
            padding: 10px 20px;
            border-radius: var(--border-radius);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            margin-top: 15px;
            transition: var(--transition);
        }

        .template-download:hover {
            background: #3a9980;
            color: white;
        }

        .template-download i {
            margin-right: 8px;
        }

        /* Manual Form Styles */
        .form-grid {
            display: grid;
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-row-2 {
            grid-template-columns: 1fr 1fr;
        }

        .form-row-3 {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .form-row-4 {
            grid-template-columns: repeat(4, 1fr);
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-input, .form-select {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        /* Table Styles */
        .styled-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .styled-table thead {
            background-color: var(--primary);
            color: white;
        }

        .styled-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .styled-table td {
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
        }

        .styled-table tbody tr {
            transition: var(--transition);
        }

        .styled-table tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }

        .delete-btn {
            background: var(--danger);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .delete-btn:hover {
            background: #e1156d;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .empty-state h4 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 0.95rem;
        }

        .quarter-info {
            background: rgba(67, 97, 238, 0.1);
            padding: 10px 15px;
            border-radius: var(--border-radius);
            margin-bottom: 15px;
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .upload-area {
                padding: 20px;
            }
            
            .styled-table {
                font-size: 0.8rem;
            }
            
            .styled-table th,
            .styled-table td {
                padding: 10px 5px;
            }
            
            .form-row-2,
            .form-row-3,
            .form-row-4 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-bullseye"></i> Staff Target Assignment</h1>
            <a href="district_dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if ($message): ?>
            <div class="message success">
                <i class="fas fa-check-circle"></i> <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- CSV Upload Card -->
        <div class="card">
            <h3><i class="fas fa-file-upload"></i> Upload CSV File</h3>
            
            <div class="upload-area">
                <i class="fas fa-file-csv"></i>
                <h4>Upload Staff Targets</h4>
                <p>Upload a CSV file with staff target data</p>
                
                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                    <input type="file" name="excel_file" id="excel_file" class="file-input" accept=".csv">
                    <label for="excel_file" class="file-label">
                        <i class="fas fa-upload"></i> Choose CSV File
                    </label>
                    <div class="selected-file" id="selectedFile">No file selected</div>
                    
                    <button type="submit" class="submit-btn" id="submitBtn" disabled>
                        <i class="fas fa-cloud-upload-alt"></i> Upload Targets
                    </button>
                </form>
                
                <a href="download_template.php" class="template-download">
                    <i class="fas fa-download"></i> Download CSV Template
                </a>
            </div>
            
            <div style="margin-top: 20px;">
                <h4>CSV File Format:</h4>
                <p>Your CSV file should have the following columns in order:</p>
                <ul style="color: var(--gray); margin-left: 20px; margin-top: 10px;">
                    <li>Staff Name (required)</li>
                    <li>KPI Name (required)</li>
                    <li>Daily Target (numeric, optional)</li>
                    <li>Weekly Target (numeric, optional)</li>
                    <li>Monthly Target (numeric, optional)</li>
                    <li>Quarter Target (numeric, required)</li>
                    <li>Quarter Number (1-4, required)</li>
                    <li>Quarter Year (e.g., 2024, required)</li>
                </ul>
                <div class="quarter-info">
                    <strong>Quarter Definitions:</strong><br>
                    • Q1: July 1 - September 30<br>
                    • Q2: October 1 - December 31<br>
                    • Q3: January 1 - March 31<br>
                    • Q4: April 1 - June 30
                </div>
                <p style="margin-top: 10px; color: var(--info);">
                    <i class="fas fa-info-circle"></i> 
                    <strong>How to create CSV from Excel:</strong> Open your Excel file, go to File → Save As, 
                    choose "CSV (Comma delimited)" as the file type, and save.
                </p>
            </div>
        </div>

        <!-- Manual Target Entry Form -->
        <div class="card">
            <h3><i class="fas fa-plus-circle"></i> Manual Target Entry</h3>
            <form method="POST">
                <div class="form-grid form-row-2">
                    <div class="form-group">
                        <label class="form-label">Staff Name *</label>
                        <input type="text" name="staff_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">KPI *</label>
                        <select name="kpi_id" class="form-select" required>
                            <option value="">Select KPI</option>
                            <?php
                            $kpis_query = "SELECT id, kpi_name FROM kpis";
                            $kpis_result = $conn->query($kpis_query);
                            while ($kpi = $kpis_result->fetch_assoc()) {
                                echo "<option value='{$kpi['id']}'>{$kpi['kpi_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-grid form-row-3">
                    <div class="form-group">
                        <label class="form-label">Quarter *</label>
                        <select name="quarter_number" class="form-select" required>
                            <option value="">Select Quarter</option>
                            <option value="1">Q1 (July 1 - September 30)</option>
                            <option value="2">Q2 (October 1 - December 31)</option>
                            <option value="3">Q3 (January 1 - March 31)</option>
                            <option value="4">Q4 (April 1 - June 30)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Year *</label>
                        <select name="quarter_year" class="form-select" required>
                            <option value="">Select Year</option>
                            <?php
                            $current_year = date('Y');
                            for ($year = $current_year - 1; $year <= $current_year + 1; $year++) {
                                echo "<option value='$year'>$year</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Quarter Target *</label>
                        <input type="number" step="0.01" name="quarter_target" class="form-input" required placeholder="Required">
                    </div>
                </div>
                
                <div class="form-grid form-row-3">
                    <div class="form-group">
                        <label class="form-label">Daily Target</label>
                        <input type="number" step="0.01" name="daily_target" class="form-input" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Weekly Target</label>
                        <input type="number" step="0.01" name="weekly_target" class="form-input" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Monthly Target</label>
                        <input type="number" step="0.01" name="monthly_target" class="form-input" placeholder="Optional">
                    </div>
                </div>
                
                <button type="submit" class="submit-btn">
                    <i class="fas fa-save"></i> Add Target
                </button>
            </form>
        </div>

        <!-- Current Staff Targets Table -->
        <div class="card">
            <h3><i class="fas fa-list-check"></i> Current Staff Targets</h3>
            
            <?php if (!empty($staff_targets)): ?>
                <div class="table-responsive">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Staff Name</th>
                                <th>KPI</th>
                                <th>Quarter Period</th>
                                <th>Daily Target</th>
                                <th>Weekly Target</th>
                                <th>Monthly Target</th>
                                <th>Quarter Target</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff_targets as $target): ?>
                            <tr>
                                <td><?= htmlspecialchars($target['staff_name']) ?></td>
                                <td><?= htmlspecialchars($target['kpi_name']) ?></td>
                                <td><?= formatQuarterDisplay($target['quarter_start'], $target['quarter_end']) ?></td>
                                <td><?= $target['daily_target'] ?? 'N/A' ?></td>
                                <td><?= $target['weekly_target'] ?? 'N/A' ?></td>
                                <td><?= $target['monthly_target'] ?? 'N/A' ?></td>
                                <td><?= $target['quarter_target'] ?? 'N/A' ?></td>
                                <td>
                                    <button class="delete-btn" onclick="deleteTarget(<?= $target['id'] ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-bullseye"></i>
                    <h4>No targets assigned</h4>
                    <p>Upload a CSV file or use the manual form to assign targets to staff members.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // File selection handling
        document.getElementById('excel_file').addEventListener('change', function(e) {
            const fileInput = e.target;
            const selectedFile = document.getElementById('selectedFile');
            const submitBtn = document.getElementById('submitBtn');
            
            if (fileInput.files.length > 0) {
                selectedFile.textContent = 'Selected: ' + fileInput.files[0].name;
                submitBtn.disabled = false;
            } else {
                selectedFile.textContent = 'No file selected';
                submitBtn.disabled = true;
            }
        });

        // Form submission handling
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            submitBtn.disabled = true;
        });

        // Delete target function
        function deleteTarget(targetId) {
            if (confirm('Are you sure you want to delete this target?')) {
                fetch('delete_target.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'target_id=' + targetId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error deleting target: ' . data.message);
                    }
                })
                .catch(error => {
                    alert('Error deleting target: ' . error);
                });
            }
        }
    </script>
</body>
</html>