<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

// Only logged-in users
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'user') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// Only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method. Use POST.']);
    exit();
}

// Get session info
$user_id = $_SESSION['user_id'];

// Fetch branch from users table
$user_stmt = $conn->prepare("SELECT branch FROM users WHERE id = ?");
if (!$user_stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
    exit();
}

$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();

if ($user_result->num_rows === 0) {
    $user_stmt->close();
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit();
}

$user_data = $user_result->fetch_assoc();
$branch = $user_data['branch'] ?? '';
$user_stmt->close();

if (empty($branch)) {
    echo json_encode(['status'=>'error','message'=>'Branch not found for user']);
    exit();
}

// Collect submitted KPI values
$values = $_POST['kpi'] ?? [];

if (empty($values)) {
    echo json_encode(['status' => 'error', 'message' => 'No KPI values submitted']);
    exit();
}

$submission_date = date('Y-m-d');
$status = 'pending';
$results = [];

// Process each KPI individually
foreach ($values as $kpi_id => $value) {
    $value = trim($value);
    $kpi_id_int = (int) $kpi_id;

    // Skip empty values
    if ($value === '') {
        $results[] = [
            'kpi_id' => $kpi_id_int,
            'status' => 'skipped',
            'message' => 'Empty value'
        ];
        continue;
    }

    // Validate numeric value
    if (!is_numeric($value)) {
        $results[] = [
            'kpi_id' => $kpi_id_int,
            'status' => 'error',
            'message' => 'Non-numeric value'
        ];
        continue;
    }

    $value_float = (float) $value;

    // Prepare individual statement for each KPI
    $stmt = $conn->prepare("INSERT INTO user_submissions (user_id, kpi_id, branch, value, submission_date, status) VALUES (?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        $results[] = [
            'kpi_id' => $kpi_id_int,
            'status' => 'error',
            'message' => 'Database preparation failed'
        ];
        continue;
    }

    // Bind parameters and execute
    if ($stmt->bind_param("iisdss", $user_id, $kpi_id_int, $branch, $value_float, $submission_date, $status)) {
        if ($stmt->execute()) {
            $results[] = [
                'kpi_id' => $kpi_id_int,
                'status' => 'success',
                'message' => 'Submitted successfully',
                'submission_id' => $stmt->insert_id
            ];
        } else {
            $results[] = [
                'kpi_id' => $kpi_id_int,
                'status' => 'error',
                'message' => 'Database execution failed: ' . $stmt->error
            ];
        }
    } else {
        $results[] = [
            'kpi_id' => $kpi_id_int,
            'status' => 'error',
            'message' => 'Parameter binding failed'
        ];
    }

    $stmt->close();
}

$conn->close();

// Count results
$success_count = count(array_filter($results, function($r) { return $r['status'] === 'success'; }));
$error_count = count(array_filter($results, function($r) { return $r['status'] === 'error'; }));
$skipped_count = count(array_filter($results, function($r) { return $r['status'] === 'skipped'; }));

// Prepare response
$response = [
    'summary' => [
        'total' => count($results),
        'success' => $success_count,
        'error' => $error_count,
        'skipped' => $skipped_count
    ],
    'details' => $results
];

// Add overall status message
if ($success_count > 0 && $error_count === 0 && $skipped_count === 0) {
    $response['status'] = 'success';
    $response['message'] = 'All ' . $success_count . ' KPI values submitted successfully and pending approval.';
} elseif ($success_count > 0 && ($error_count > 0 || $skipped_count > 0)) {
    $response['status'] = 'warning';
    $response['message'] = $success_count . ' KPIs submitted successfully, ' . $error_count . ' failed, ' . $skipped_count . ' skipped.';
} elseif ($success_count === 0 && $error_count > 0) {
    $response['status'] = 'error';
    $response['message'] = 'All KPI submissions failed. Please check your inputs.';
} else {
    $response['status'] = 'info';
    $response['message'] = 'No KPIs were submitted (all values were empty).';
}

echo json_encode($response);
?>