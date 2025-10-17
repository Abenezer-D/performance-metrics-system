<?php
session_start();
require_once 'db.php';

// Debug session information
error_log("Session data: " . print_r($_SESSION, true));

// CSRF Protection - Generate token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ensure user is logged in with proper session validation
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || ($_SESSION['role'] !== 'user' && $_SESSION['role'] !== 'manager')) {
    error_log("User not properly authenticated. Redirecting to login.");
    header("Location: login.php");
    exit();
}

// Get session info with proper validation and default values
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$branch = $_SESSION['branch'] ?? 'Unknown Branch';
$role = $_SESSION['role'] ?? 'user';

// Log session info for debugging
error_log("User ID: $user_id, Username: $username, Role: $role, Branch: $branch");

// Audit Logging Class
class AuditLogger {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    public function log($user_id, $action, $details = '', $ip_address = null, $user_agent = null) {
        $ip_address = $ip_address ?: $_SERVER['REMOTE_ADDR'];
        $user_agent = $user_agent ?: $_SERVER['HTTP_USER_AGENT'];
        
        $stmt = $this->conn->prepare("
            INSERT INTO audit_log (user_id, action, details, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("issss", $user_id, $action, $details, $ip_address, $user_agent);
            return $stmt->execute();
        }
        return false;
    }
}

// Input Validation Class
class InputValidator {
    private $errors = [];
    
    public function validateKPIValue($kpi_id, $value) {
        if ($value === '' || $value === null) {
            $this->errors[] = "KPI value cannot be empty";
            return false;
        }
        
        if (!is_numeric($value)) {
            $this->errors[] = "KPI value must be a number";
            return false;
        }
        
        $value = floatval($value);
        
        if ($value < 0) {
            $this->errors[] = "KPI value cannot be negative";
            return false;
        }
        
        return true;
    }
    
    public function validateDate($date, $format = 'Y-m-d') {
        if (empty($date)) {
            $this->errors[] = "Date is required";
            return false;
        }
        
        $d = DateTime::createFromFormat($format, $date);
        if ($d && $d->format($format) === $date) {
            $today = new DateTime();
            $inputDate = new DateTime($date);
            if ($inputDate > $today) {
                $this->errors[] = "Date cannot be in the future";
                return false;
            }
            return true;
        }
        $this->errors[] = "Invalid date format. Use YYYY-MM-DD";
        return false;
    }
    
    public function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    public function getErrors() {
        return $this->errors;
    }
    
    public function hasErrors() {
        return !empty($this->errors);
    }
    
    public function clearErrors() {
        $this->errors = [];
    }
}

// Initialize classes
$auditLogger = new AuditLogger($conn);
$inputValidator = new InputValidator();

// Log page access
$auditLogger->log($user_id, 'PAGE_ACCESS', 'Accessed user dashboard');

// Set default dates
$default_from = date('Y-m-01');
$default_to = date('Y-m-d');

// Get and sanitize filter parameters
$approved_search = $inputValidator->sanitizeInput($_GET['approved_search'] ?? '');
$approved_from = $inputValidator->sanitizeInput($_GET['approved_from'] ?? $default_from);
$approved_to = $inputValidator->sanitizeInput($_GET['approved_to'] ?? $default_to);
$pending_search = $inputValidator->sanitizeInput($_GET['pending_search'] ?? '');
$pending_from = $inputValidator->sanitizeInput($_GET['pending_from'] ?? $default_from);
$pending_to = $inputValidator->sanitizeInput($_GET['pending_to'] ?? $default_to);

// Enhanced period and date filtering
$target_period = $inputValidator->sanitizeInput($_GET['target_period'] ?? 'monthly');
$kpi_overview_date = $inputValidator->sanitizeInput($_GET['kpi_overview_date'] ?? '');

// Validate date parameters
if (!empty($approved_from) && !$inputValidator->validateDate($approved_from)) {
    $approved_from = $default_from;
}
if (!empty($approved_to) && !$inputValidator->validateDate($approved_to)) {
    $approved_to = $default_to;
}
if (!empty($pending_from) && !$inputValidator->validateDate($pending_from)) {
    $pending_from = $default_from;
}
if (!empty($pending_to) && !$inputValidator->validateDate($pending_to)) {
    $pending_to = $default_to;
}
if (!empty($kpi_overview_date) && !$inputValidator->validateDate($kpi_overview_date)) {
    $kpi_overview_date = '';
}

// Fetch KPI definitions
$kpi_names = [];
$kpi_categories = [];
$kpi_query = $conn->prepare("SELECT id, kpi_name, kpi_category FROM kpis");
if ($kpi_query && $kpi_query->execute()) {
    $result = $kpi_query->get_result();
    while($k = $result->fetch_assoc()) {
        $kpi_names[$k['id']] = $k['kpi_name'];
        $kpi_categories[$k['id']] = $k['kpi_category'];
    }
    $kpi_query->close();
} else {
    error_log("Error fetching KPI definitions: " . $conn->error);
    die("Error loading KPI definitions. Please try again later.");
}

// KPI overview data
$agg_data = [];
$kpis_result = $conn->query("SELECT id, kpi_name FROM kpis");
if ($kpis_result) {
    while($kpi = $kpis_result->fetch_assoc()) {
        // Simplified performance calculation
        $performance = 0;
        if (!empty($kpi_overview_date)) {
            $query = $conn->prepare("
                SELECT COALESCE(SUM(value), 0) as total 
                FROM user_submissions 
                WHERE user_id = ? AND kpi_id = ? AND status = 'approved' 
                AND submission_date = ?
            ");
            $query->bind_param("iis", $user_id, $kpi['id'], $kpi_overview_date);
            if ($query->execute()) {
                $result = $query->get_result();
                $row = $result->fetch_assoc();
                $performance = $row['total'] ?? 0;
            }
            $query->close();
        }
        
        $agg_data[] = [
            'kpi_name' => $kpi['kpi_name'],
            'kpi_id' => $kpi['id'],
            'total_val' => $performance,
            'target' => 0, // Simplified for now
            'period' => $target_period
        ];
    }
}

// Approved performance with filtering
$approved_data = [];
$approved_sql = "
    SELECT us.submission_date, k.kpi_name, k.id as kpi_id, us.value, us.status
    FROM user_submissions us
    JOIN kpis k ON us.kpi_id = k.id
    WHERE us.user_id = ? AND us.status = 'approved'
";

if (!empty($approved_from)) {
    $approved_sql .= " AND us.submission_date >= ?";
}
if (!empty($approved_to)) {
    $approved_sql .= " AND us.submission_date <= ?";
}

$approved_sql .= " ORDER BY us.submission_date DESC, us.id DESC";

$approved_query = $conn->prepare($approved_sql);
if ($approved_query) {
    if (empty($approved_from) && empty($approved_to)) {
        $approved_query->bind_param("i", $user_id);
    } elseif (!empty($approved_from) && empty($approved_to)) {
        $approved_query->bind_param("is", $user_id, $approved_from);
    } elseif (empty($approved_from) && !empty($approved_to)) {
        $approved_query->bind_param("is", $user_id, $approved_to);
    } else {
        $approved_query->bind_param("iss", $user_id, $approved_from, $approved_to);
    }
    
    if ($approved_query->execute()) {
        $approved_result = $approved_query->get_result();
        while($row = $approved_result->fetch_assoc()) {
            $approved_data[] = $row;
        }
    }
    $approved_query->close();
}

// Pending submissions with filtering
$pending_data = [];
$pending_sql = "
    SELECT us.submission_date, k.kpi_name, k.id as kpi_id, us.value, us.status
    FROM user_submissions us
    JOIN kpis k ON us.kpi_id = k.id
    WHERE us.user_id = ? AND us.status = 'pending'
";

if (!empty($pending_from)) {
    $pending_sql .= " AND us.submission_date >= ?";
}
if (!empty($pending_to)) {
    $pending_sql .= " AND us.submission_date <= ?";
}

$pending_sql .= " ORDER BY us.submission_date DESC, us.id DESC";

$pending_query = $conn->prepare($pending_sql);
if ($pending_query) {
    if (empty($pending_from) && empty($pending_to)) {
        $pending_query->bind_param("i", $user_id);
    } elseif (!empty($pending_from) && empty($pending_to)) {
        $pending_query->bind_param("is", $user_id, $pending_from);
    } elseif (empty($pending_from) && !empty($pending_to)) {
        $pending_query->bind_param("is", $user_id, $pending_to);
    } else {
        $pending_query->bind_param("iss", $user_id, $pending_from, $pending_to);
    }
    
    if ($pending_query->execute()) {
        $pending_result = $pending_query->get_result();
        while($row = $pending_result->fetch_assoc()) {
            $pending_data[] = $row;
        }
    }
    $pending_query->close();
}

// Get stats for cards
$stats = [
    'approved_count' => 0,
    'pending_count' => 0,
    'submission_days' => 0,
    'last_submission' => null
];

try {
    $stats_query = $conn->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_count,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
            COUNT(DISTINCT submission_date) as submission_days,
            MAX(submission_date) as last_submission
        FROM user_submissions 
        WHERE user_id = ?
    ");
    
    if ($stats_query) {
        $stats_query->bind_param("i", $user_id);
        if ($stats_query->execute()) {
            $stats_result = $stats_query->get_result();
            if ($stats_row = $stats_result->fetch_assoc()) {
                $stats = array_merge($stats, $stats_row);
            }
        }
        $stats_query->close();
    }
} catch (Exception $e) {
    error_log("Exception in stats query: " . $e->getMessage());
}

// Handle form submission if POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_kpi'])) {
    error_log("FORM SUBMISSION DETECTED");
    
    // Enhanced CSRF validation
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $auditLogger->log($user_id, 'CSRF_FAILURE', 'CSRF token validation failed');
        $_SESSION['validation_errors'] = ['Security validation failed. Please refresh the page and try again.'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    $validation_errors = [];
    $submission_data = [];
    
    $submission_date = $inputValidator->sanitizeInput($_POST['submission_date'] ?? '');
    error_log("Submission date: " . $submission_date);
    
    // Validate submission date
    if (empty($submission_date)) {
        $validation_errors[] = "Submission date is required";
    } elseif (!$inputValidator->validateDate($submission_date)) {
        $validation_errors = array_merge($validation_errors, $inputValidator->getErrors());
    }
    
    // Validate KPI values - More lenient validation
    $empty_kpis = [];
    foreach ($_POST['kpi'] ?? [] as $kpi_id => $value) {
        $value = trim($value);
        
        if ($value === '') {
            // Get KPI name for better error message
            $kpi_name = $kpi_names[$kpi_id] ?? "KPI ID $kpi_id";
            $empty_kpis[] = $kpi_name;
            continue; // Skip this KPI but don't break the loop
        }
        
        if (!$inputValidator->validateKPIValue($kpi_id, $value)) {
            $kpi_name = $kpi_names[$kpi_id] ?? "KPI ID $kpi_id";
            $validation_errors[] = "\"$kpi_name\": " . implode(', ', $inputValidator->getErrors());
            $inputValidator->clearErrors();
        } else {
            $submission_data[$kpi_id] = floatval($value);
        }
    }
    
    // Check for empty KPIs
    if (!empty($empty_kpis)) {
        if (count($empty_kpis) === 1) {
            $validation_errors[] = "The following KPI is required: " . $empty_kpis[0];
        } else {
            $validation_errors[] = "The following KPIs are required: " . implode(', ', $empty_kpis);
        }
    }
    
    // Check if we have any KPIs to submit
    if (empty($submission_data)) {
        $validation_errors[] = "No valid KPI data to submit";
    }
    
    // Check for duplicate submission
    if (empty($validation_errors)) {
        $duplicate_check = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM user_submissions 
            WHERE user_id = ? AND submission_date = ? AND status IN ('pending', 'approved')
        ");
        
        if ($duplicate_check) {
            $duplicate_check->bind_param("is", $user_id, $submission_date);
            if ($duplicate_check->execute()) {
                $duplicate_result = $duplicate_check->get_result();
                $duplicate_row = $duplicate_result->fetch_assoc();
                
                if ($duplicate_row['count'] > 0) {
                    $validation_errors[] = "You have already submitted KPIs for date: $submission_date. Please edit your existing submission or choose a different date.";
                }
            } else {
                error_log("Duplicate check execution failed: " . $duplicate_check->error);
                $validation_errors[] = "System error. Please try again.";
            }
            $duplicate_check->close();
        } else {
            error_log("Duplicate check preparation failed: " . $conn->error);
            $validation_errors[] = "System error. Please try again.";
        }
    }
    
    // If no validation errors, insert data
    if (empty($validation_errors)) {
        error_log("No validation errors, proceeding with insertion. KPIs to insert: " . count($submission_data));
        $insert_success = true;
        $insert_errors = [];
        
        // Use transaction for multiple inserts
        $conn->begin_transaction();
        
        try {
            foreach ($submission_data as $kpi_id => $value) {
                $insert_stmt = $conn->prepare("
                    INSERT INTO user_submissions (user_id, kpi_id, value, submission_date, status) 
                    VALUES (?, ?, ?, ?, 'pending')
                ");
                
                if ($insert_stmt) {
                    $insert_stmt->bind_param("iids", $user_id, $kpi_id, $value, $submission_date);
                    if (!$insert_stmt->execute()) {
                        $insert_errors[] = "Failed to insert KPI $kpi_id: " . $insert_stmt->error;
                        $insert_success = false;
                        error_log("Insert failed for KPI $kpi_id: " . $insert_stmt->error);
                        break;
                    }
                    $insert_stmt->close();
                    error_log("Successfully inserted KPI $kpi_id with value $value");
                } else {
                    $insert_errors[] = "Failed to prepare insert statement for KPI $kpi_id: " . $conn->error;
                    $insert_success = false;
                    error_log("Prepare failed for KPI $kpi_id: " . $conn->error);
                    break;
                }
            }
            
            if ($insert_success) {
                $conn->commit();
                $auditLogger->log($user_id, 'KPI_SUBMISSION', 
                    "Submitted " . count($submission_data) . " KPIs for date: $submission_date");
                
                $_SESSION['success_message'] = "KPI data submitted successfully for $submission_date! Waiting for approval.";
                error_log("Submission successful for user $user_id on date $submission_date");
                
                // Clear form data
                unset($_SESSION['form_data']);
                unset($_SESSION['submission_date']);
                unset($_SESSION['validation_errors']);
                
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $conn->rollback();
                $validation_errors[] = "Failed to submit KPI data. Please try again.";
                error_log("Insert errors: " . implode(", ", $insert_errors));
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $validation_errors[] = "Database error occurred. Please try again.";
            error_log("Transaction error: " . $e->getMessage());
        }
    }
    
    // If there are validation errors, store them and form data for repopulation
    if (!empty($validation_errors)) {
        $_SESSION['validation_errors'] = $validation_errors;
        $_SESSION['form_data'] = $_POST['kpi'] ?? [];
        $_SESSION['submission_date'] = $submission_date;
        error_log("Validation errors: " . implode(', ', $validation_errors));
        
        // Redirect to show errors
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Helper functions
function getDateRangeDisplay($from, $to) {
    if (!empty($from) && !empty($to)) {
        return " ({$from} to {$to})";
    } elseif (!empty($from)) {
        return " (from {$from})";
    } elseif (!empty($to)) {
        return " (until {$to})";
    }
    return "";
}

function getPeriodDisplayName($period) {
    $periods = [
        'daily' => 'Daily',
        'weekly' => 'Weekly', 
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly'
    ];
    return $periods[$period] ?? ucfirst($period);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $role === 'manager' ? 'Manager' : 'User' ?> Dashboard | Performance Metrics</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<style>
:root {
    --primary: #6366f1;
    --primary-light: #818cf8;
    --primary-dark: #4f46e5;
    --primary-gradient: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    --secondary: #ec4899;
    --secondary-gradient: linear-gradient(135deg, #ec4899 0%, #f59e0b 100%);
    --success: #10b981;
    --success-gradient: linear-gradient(135deg, #10b981 0%, #34d399 100%);
    --warning: #f59e0b;
    --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
    --danger: #ef4444;
    --danger-gradient: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
    --dark: #1e293b;
    --dark-light: #475569;
    --light: #f8fafc;
    --light-gray: #f1f5f9;
    --border: #e2e8f0;
    --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    --radius: 12px;
    --radius-sm: 8px;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: var(--dark);
    line-height: 1.6;
    min-height: 100vh;
}

/* Alert Container */
.alert-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    min-width: 400px;
    max-width: 500px;
}

.alert {
    border-radius: var(--radius-sm);
    border: none;
    padding: 16px 20px;
    border-left: 4px solid;
    box-shadow: var(--shadow-lg);
    background: white;
    margin-bottom: 10px;
    transition: var(--transition);
}

.alert-success {
    border-left-color: var(--success);
    background: linear-gradient(to right, rgba(16, 185, 129, 0.05), white);
}

.alert-danger {
    border-left-color: var(--danger);
    background: linear-gradient(to right, rgba(239, 68, 68, 0.05), white);
}

/* Sidebar */
.sidebar {
    width: 280px;
    background: var(--primary-gradient);
    color: white;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    z-index: 1000;
    transition: var(--transition);
    box-shadow: var(--shadow-xl);
}

.sidebar-header {
    padding: 25px 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    text-align: center;
    background: rgba(255, 255, 255, 0.05);
}

.sidebar-header h4 {
    font-weight: 700;
    font-size: 1.3rem;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.sidebar-menu {
    list-style: none;
    padding: 20px 0;
    flex-grow: 1;
}

.sidebar-menu li {
    margin-bottom: 4px;
}

.sidebar-menu a {
    display: flex;
    align-items: center;
    padding: 14px 25px;
    color: rgba(255, 255, 255, 0.9);
    text-decoration: none;
    transition: var(--transition);
    font-weight: 500;
    border-left: 4px solid transparent;
    gap: 12px;
}

.sidebar-menu a:hover {
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border-left-color: rgba(255, 255, 255, 0.3);
}

.sidebar-menu a.active {
    background: rgba(255, 255, 255, 0.15);
    color: white;
    border-left-color: white;
    box-shadow: inset 0 0 20px rgba(255, 255, 255, 0.1);
}

.sidebar-footer {
    padding: 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    text-align: center;
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.7);
}

/* Main Content */
.main-content {
    margin-left: 280px;
    padding: 0;
    transition: var(--transition);
}

/* Header */
.page-header {
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    padding: 20px 40px;
    box-shadow: var(--shadow);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 100;
    border-bottom: 1px solid var(--border);
}

.page-header h2 {
    font-weight: 700;
    color: var(--dark);
    margin: 0;
    font-size: 1.8rem;
    background: var(--primary-gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.user-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--secondary-gradient);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.2rem;
    box-shadow: var(--shadow);
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 24px;
    margin: 30px 40px;
}

.stats-card {
    background: white;
    border-radius: var(--radius);
    padding: 30px;
    box-shadow: var(--shadow);
    transition: var(--transition);
    border: 1px solid var(--border);
    position: relative;
    overflow: hidden;
}

.stats-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--primary-gradient);
}

.stats-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-xl);
}

.stats-icon {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    font-size: 1.8rem;
    color: white;
    box-shadow: var(--shadow-lg);
}

.stats-card.approved .stats-icon { background: var(--success-gradient); }
.stats-card.pending .stats-icon { background: var(--warning-gradient); }
.stats-card.days .stats-icon { background: var(--primary-gradient); }
.stats-card.last .stats-icon { background: var(--secondary-gradient); }

.stats-card h3 {
    font-size: 2.5rem;
    font-weight: 800;
    margin-bottom: 8px;
    background: var(--primary-gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.stats-card p {
    color: var(--dark-light);
    font-weight: 500;
    margin: 0;
    font-size: 0.95rem;
}

/* Section Headers */
.section-header {
    margin: 50px 40px 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid var(--border);
}

.section-title {
    font-weight: 700;
    color: var(--dark);
    display: flex;
    align-items: center;
    font-size: 1.5rem;
    gap: 12px;
}

.section-title i {
    color: var(--primary);
    background: var(--primary-gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* Filter Card */
.filter-card {
    background: white;
    border-radius: var(--radius);
    padding: 30px;
    margin: 0 40px 30px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
}

.filter-row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 20px;
    align-items: end;
}

.form-control {
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    padding: 12px 16px;
    height: 48px;
    transition: var(--transition);
    font-size: 0.95rem;
}

.form-control:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    transform: translateY(-1px);
}

.date-filters {
    display: flex;
    gap: 16px;
    align-items: end;
}

.date-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.date-group label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--dark-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-actions {
    display: flex;
    gap: 12px;
}

.btn {
    border-radius: var(--radius-sm);
    padding: 12px 24px;
    font-weight: 600;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    height: 48px;
    border: none;
    font-size: 0.95rem;
    gap: 8px;
}

.btn-primary {
    background: var(--primary-gradient);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-success {
    background: var(--success-gradient);
    color: white;
}

.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-outline {
    background: transparent;
    border: 2px solid var(--border);
    color: var(--dark-light);
}

.btn-outline:hover {
    border-color: var(--primary);
    color: var(--primary);
    transform: translateY(-2px);
}

/* KPI Grid */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
    margin: 0 40px 50px;
}

.kpi-card {
    background: white;
    border-radius: var(--radius);
    padding: 30px;
    box-shadow: var(--shadow);
    transition: var(--transition);
    border: 1px solid var(--border);
    position: relative;
    overflow: hidden;
}

.kpi-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--primary-gradient);
}

.kpi-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-xl);
}

.kpi-header {
    display: flex;
    justify-content: between;
    align-items: flex-start;
    margin-bottom: 20px;
}

.kpi-name {
    font-weight: 600;
    color: var(--dark);
    font-size: 1.1rem;
    flex: 1;
}

.kpi-value {
    font-size: 3rem;
    font-weight: 800;
    margin-bottom: 20px;
    text-align: center;
    background: var(--primary-gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* Table Cards */
.table-card {
    background: white;
    border-radius: var(--radius);
    margin: 0 40px 50px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    overflow: hidden;
}

.table {
    margin: 0;
    border-radius: var(--radius);
    overflow: hidden;
}

.table thead th {
    background: var(--primary-gradient);
    color: white;
    font-weight: 600;
    padding: 20px 16px;
    border: none;
    font-size: 0.95rem;
}

.table tbody td {
    padding: 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    transition: var(--transition);
}

.table tbody tr:hover td {
    background: rgba(99, 102, 241, 0.02);
}

.badge {
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.8rem;
    border: 1px solid;
}

.badge-success {
    background: rgba(16, 185, 129, 0.1);
    color: var(--success);
    border-color: rgba(16, 185, 129, 0.2);
}

.badge-warning {
    background: rgba(245, 158, 11, 0.1);
    color: var(--warning);
    border-color: rgba(245, 158, 11, 0.2);
}

/* Form Cards */
.form-card {
    background: white;
    border-radius: var(--radius);
    padding: 40px;
    margin: 0 40px 50px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
}

.form-title {
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 30px;
    display: flex;
    align-items: center;
    font-size: 1.4rem;
    gap: 12px;
}

.form-title i {
    background: var(--primary-gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.date-card {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(236, 72, 153, 0.05));
    border-radius: var(--radius-sm);
    padding: 24px;
    margin-bottom: 30px;
    border-left: 4px solid var(--primary);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
}

.kpi-input-card {
    background: var(--light-gray);
    border-radius: var(--radius-sm);
    padding: 24px;
    border: 1px solid var(--border);
    transition: var(--transition);
    position: relative;
}

.kpi-input-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--primary-gradient);
    border-radius: var(--radius-sm) var(--radius-sm) 0 0;
}

.kpi-input-card:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.kpi-input-card label {
    font-weight: 600;
    color: var(--dark);
    margin-bottom: 8px;
    display: block;
    font-size: 1rem;
}

.kpi-category {
    font-size: 0.85rem;
    color: var(--dark-light);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.kpi-category i {
    color: var(--primary);
}

/* Empty States */
.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: var(--dark-light);
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.3;
    background: var(--primary-gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.empty-state h4 {
    font-weight: 700;
    margin-bottom: 12px;
    color: var(--dark);
    font-size: 1.3rem;
}

.empty-state p {
    font-size: 1rem;
    max-width: 400px;
    margin: 0 auto;
    line-height: 1.6;
}

/* Mobile Menu */
.mobile-menu-btn {
    display: none;
    position: fixed;
    top: 20px;
    left: 20px;
    z-index: 1100;
    background: var(--primary-gradient);
    color: white;
    border: none;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    box-shadow: var(--shadow-lg);
    font-size: 1.2rem;
    transition: var(--transition);
}

.mobile-menu-btn:hover {
    transform: scale(1.1);
}

/* Responsive Design */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 992px) {
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.active {
        transform: translateX(0);
    }
    
    .main-content {
        margin-left: 0;
    }
    
    .mobile-menu-btn {
        display: block;
    }
    
    .filter-row {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .kpi-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
        padding: 20px;
    }
    
    .section-header, .filter-card, .table-card, .form-card, .kpi-grid {
        margin-left: 20px;
        margin-right: 20px;
    }
    
    .date-filters {
        flex-direction: column;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-card {
        padding: 30px 20px;
    }
    
    .stats-card {
        padding: 24px;
    }
}

/* Custom Scrollbar */
::-webkit-scrollbar {
    width: 6px;
}

::-webkit-scrollbar-track {
    background: var(--light-gray);
}

::-webkit-scrollbar-thumb {
    background: var(--primary);
    border-radius: 3px;
}

::-webkit-scrollbar-thumb:hover {
    background: var(--primary-dark);
}

/* Animation Classes */
.fade-in {
    animation: fadeIn 0.5s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.slide-in {
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from { transform: translateX(-100%); }
    to { transform: translateX(0); }
}
</style>
</head>
<body>
<!-- Mobile Menu Button -->
<button class="mobile-menu-btn d-lg-none">
    <i class="fas fa-bars"></i>
</button>

<!-- Alert Container -->
<div class="alert-container">
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show fade-in" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?= $_SESSION['success_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['validation_errors'])): ?>
        <div class="alert alert-danger alert-dismissible fade show fade-in" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Unable to Submit Data:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($_SESSION['validation_errors'] as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['validation_errors']); ?>
    <?php endif; ?>
</div>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <h4><i class="fas fa-chart-line"></i>Performance Dashboard</h4>
    </div>
    <ul class="sidebar-menu">
        <li><a href="#overview" class="active"><i class="fas fa-tachometer-alt"></i> <span>Dashboard Overview</span></a></li>
        <li><a href="#approved"><i class="fas fa-check-circle"></i> <span>Approved KPIs</span></a></li>
        <li><a href="#submit"><i class="fas fa-plus-circle"></i> <span>Submit Data</span></a></li>
        <li><a href="#pending"><i class="fas fa-clock"></i> <span>Pending Review</span></a></li>
        <?php if ($role === 'manager'): ?>
        <li><a href="#staff-performance"><i class="fas fa-users"></i> <span>Team Performance</span></a></li>
        <?php endif; ?>
        <li><a href="performance_view.php"><i class="fas fa-bullseye"></i> <span>Performance vs Targets</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
    </ul>
    <div class="sidebar-footer">
        <p>Performance Metrics System v2.0</p>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    <!-- Header -->
    <div class="page-header">
        <h2>Performance Analytics Dashboard</h2>
        <div class="user-info">
            <div class="user-avatar">
                <?= strtoupper(substr($username, 0, 1)) ?>
            </div>
            <div>
                <div class="fw-semibold">Welcome, <?= htmlspecialchars($username) ?></div>
                <small class="text-muted"><?= ucfirst($role) ?> • <?= htmlspecialchars($branch) ?></small>
            </div>
        </div>
    </div>

    <!-- Stats Overview -->
    <div class="stats-grid">
        <div class="stats-card approved fade-in">
            <div class="stats-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3><?= $stats['approved_count'] ?? 0 ?></h3>
            <p>Approved Submissions</p>
        </div>
        <div class="stats-card pending fade-in">
            <div class="stats-icon">
                <i class="fas fa-clock"></i>
            </div>
            <h3><?= $stats['pending_count'] ?? 0 ?></h3>
            <p>Pending Reviews</p>
        </div>
        <div class="stats-card days fade-in">
            <div class="stats-icon">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <h3><?= $stats['submission_days'] ?? 0 ?></h3>
            <p>Active Days</p>
        </div>
        <div class="stats-card last fade-in">
            <div class="stats-icon">
                <i class="fas fa-history"></i>
            </div>
            <h3>
                <?php 
                if (isset($stats['last_submission']) && !empty($stats['last_submission'])) {
                    echo date('M j', strtotime($stats['last_submission']));
                } else {
                    echo 'N/A';
                }
                ?>
            </h3>
            <p>Last Submission</p>
        </div>
    </div>

    <!-- Quick Date Filters -->
    <div class="filter-card fade-in">
        <div class="filter-row">
            <div class="date-filters">
                <div class="date-group">
                    <label><i class="fas fa-calendar-day me-2"></i>Specific Date</label>
                    <input type="date" id="kpi_overview_date" name="kpi_overview_date" class="form-control" 
                           value="<?= htmlspecialchars($kpi_overview_date) ?>" 
                           max="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="filter-actions">
                <button class="btn btn-primary" onclick="applySpecificFilters()">
                    <i class="fas fa-filter"></i> Apply Filter
                </button>
                <?php if (!empty($kpi_overview_date)): ?>
                <button class="btn btn-outline" onclick="clearSpecificFilters()">
                    <i class="fas fa-times"></i> Clear
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- KPI Performance Overview -->
    <div class="section-header" id="overview">
        <div class="section-title">
            <i class="fas fa-chart-bar"></i>
            KPI Performance Overview
            <small class="text-muted ms-2">
                <?php
                if (!empty($kpi_overview_date)) {
                    echo "• " . date('M j, Y', strtotime($kpi_overview_date));
                } else {
                    echo "• " . getPeriodDisplayName($target_period);
                }
                ?>
            </small>
        </div>
    </div>

    <div class="kpi-grid">
        <?php if (!empty($agg_data)): ?>
            <?php foreach($agg_data as $row): 
                $value = round($row['total_val'], 2);
                $performance_class = $value > 0 ? 'high' : 'low';
            ?>
                <div class="kpi-card fade-in">
                    <div class="kpi-header">
                        <div class="kpi-name"><?= htmlspecialchars($row['kpi_name']) ?></div>
                    </div>
                    <div class="kpi-value <?= $performance_class ?>"><?= $value ?></div>
                    <div class="target-section">
                        <div class="target-label text-muted">
                            Current Performance
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-chart-bar"></i>
                <h4>No Performance Data Available</h4>
                <p>Submit your KPI data to see performance overview.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Approved KPIs Section -->
    <div class="section-header" id="approved">
        <div class="section-title">
            <i class="fas fa-check-circle"></i>
            Approved Performance Records
            <small class="text-muted ms-2"><?= getDateRangeDisplay($approved_from, $approved_to) ?></small>
        </div>
    </div>

    <div class="table-card fade-in">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="approvedTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>KPI Name</th>
                        <th>Value</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($approved_data)): ?>
                        <?php foreach($approved_data as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= $row['submission_date'] ?></td>
                                <td><?= htmlspecialchars($row['kpi_name']) ?></td>
                                <td><?= $row['value'] ?></td>
                                <td><span class="badge badge-success">Approved</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center py-5">
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <h4>No Approved Submissions</h4>
                                    <p>Your approved KPI submissions will appear here once they are reviewed and approved.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Submit KPI Section -->
    <div class="section-header" id="submit">
        <div class="section-title">
            <i class="fas fa-plus-circle"></i>
            Submit New KPI Data
        </div>
    </div>

    <div class="form-card fade-in">
        <div class="form-title">
            <i class="fas fa-upload"></i> Enter Performance Metrics
        </div>
        
        <form id="kpiSubmissionForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="submit_kpi" value="1">
            
            <div class="date-card">
                <label for="submission_date" class="form-label fw-semibold">
                    <i class="fas fa-calendar-day me-2"></i>Select Submission Date
                </label>
                <input type="date" 
                       id="submission_date" 
                       name="submission_date" 
                       class="form-control w-auto <?= isset($_SESSION['validation_errors']) && isset($_SESSION['submission_date']) ? 'is-invalid' : '' ?>" 
                       value="<?= htmlspecialchars($_SESSION['submission_date'] ?? date('Y-m-d')) ?>" 
                       max="<?= date('Y-m-d') ?>" 
                       required>
                <div class="form-text mt-2">
                    <i class="fas fa-info-circle me-1"></i> 
                    Select the date for which you are submitting performance data.
                </div>
            </div>
            
            <div class="form-grid">
                <?php
                $kpis_form = $conn->query("SELECT * FROM kpis");
                if ($kpis_form && $kpis_form->num_rows > 0) {
                    while($k = $kpis_form->fetch_assoc()): 
                        $previous_value = $_SESSION['form_data'][$k['id']] ?? '';
                        $has_error = isset($_SESSION['validation_errors']) && isset($_SESSION['form_data'][$k['id']]);
                    ?>
                        <div class="kpi-input-card">
                            <label for="kpi_<?= $k['id'] ?>">
                                <?= htmlspecialchars($k['kpi_name']) ?>
                            </label>
                            <div class="kpi-category">
                                <i class="fas fa-tag"></i> <?= htmlspecialchars($k['kpi_category']) ?>
                            </div>
                            <input type="number" 
                                   id="kpi_<?= $k['id'] ?>"
                                   step="0.01" 
                                   min="0" 
                                   name="kpi[<?= $k['id'] ?>]" 
                                   class="form-control kpi-field <?= $has_error ? 'is-invalid' : '' ?>" 
                                   placeholder="Enter value (0 or above)..." 
                                   value="<?= htmlspecialchars($previous_value) ?>"
                                   required
                                   oninput="validateKPIValue(this)">
                            <?php if ($has_error): ?>
                                <div class="invalid-feedback d-block">
                                    Please enter a valid non-negative number
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile;
                } else {
                    echo '<div class="col-12"><div class="alert alert-warning">No KPIs defined in the system. Please contact administrator.</div></div>';
                }
                ?>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i> All submissions require manager approval. One submission per date allowed.
                </small>
                <button type="submit" class="btn btn-success" id="submitBtn">
                    <i class="fas fa-paper-plane me-2"></i> Submit Performance Data
                </button>
            </div>
        </form>
    </div>

    <!-- Pending Submissions Section -->
    <div class="section-header" id="pending">
        <div class="section-title">
            <i class="fas fa-clock"></i>
            Pending Submissions
            <small class="text-muted ms-2"><?= getDateRangeDisplay($pending_from, $pending_to) ?></small>
        </div>
    </div>

    <div class="table-card fade-in">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="pendingTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>KPI Name</th>
                        <th>Value</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($pending_data)): ?>
                        <?php foreach($pending_data as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= $row['submission_date'] ?></td>
                                <td><?= htmlspecialchars($row['kpi_name']) ?></td>
                                <td><?= $row['value'] ?></td>
                                <td><span class="badge badge-warning">Pending Review</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center py-5">
                                <div class="empty-state">
                                    <i class="fas fa-clock"></i>
                                    <h4>No Pending Submissions</h4>
                                    <p>All your submissions have been processed. New submissions will appear here while awaiting approval.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Mobile menu toggle
    $('.mobile-menu-btn').click(function() {
        $('.sidebar').toggleClass('active');
    });

    // Smooth scrolling for navigation
    $('.sidebar-menu a').on('click', function(e) {
        if (this.hash !== "") {
            e.preventDefault();
            const hash = this.hash;
            $('html, body').animate({
                scrollTop: $(hash).offset().top - 100
            }, 800);
            
            $('.sidebar-menu a').removeClass('active');
            $(this).addClass('active');
            
            if ($(window).width() < 992) {
                $('.sidebar').removeClass('active');
            }
        }
    });

    // Form validation
    $('#kpiSubmissionForm').on('submit', function(e) {
        e.preventDefault();
        
        console.log("Form submission started");
        
        let isValid = true;
        const today = new Date().toISOString().split('T')[0];
        const submissionDate = $('#submission_date').val();
        
        // Clear previous errors
        $('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').remove();
        
        console.log("Submission date:", submissionDate);
        
        // Validate submission date
        if (!submissionDate) {
            showValidationError('#submission_date', 'Submission date is required');
            isValid = false;
        } else if (submissionDate > today) {
            showValidationError('#submission_date', 'Submission date cannot be in the future');
            isValid = false;
        }
        
        // Validate KPI values
        let emptyFields = 0;
        $('.kpi-field').each(function() {
            const value = $(this).val().trim();
            const fieldName = $(this).closest('.kpi-input-card').find('label').text().trim();
            
            console.log(`KPI Field: ${fieldName}, Value: ${value}`);
            
            if (value === '') {
                showValidationError(this, `"${fieldName}" is required`);
                isValid = false;
                emptyFields++;
            } else if (isNaN(value) || parseFloat(value) < 0) {
                showValidationError(this, `"${fieldName}" must be a valid non-negative number`);
                isValid = false;
            }
        });
        
        if (emptyFields > 0) {
            showNotification(`Please fill in all ${emptyFields} required KPI fields`, 'error');
        }
        
        if (!isValid) {
            console.log("Form validation failed");
            // Scroll to the first error
            $('html, body').animate({
                scrollTop: $('.is-invalid').first().offset().top - 100
            }, 500);
            return false;
        }
        
        console.log("Form validation passed, submitting...");
        
        // Show loading state
        const submitBtn = $('#submitBtn');
        const originalText = submitBtn.html();
        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i> Submitting...');
        
        // Submit the form programmatically
        const formData = new FormData(this);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.redirected) {
                window.location.href = response.url;
            } else {
                return response.text();
            }
        })
        .catch(error => {
            console.error('Form submission error:', error);
            showNotification('Submission failed. Please try again.', 'error');
            submitBtn.prop('disabled', false).html(originalText);
        });
    });

    function showValidationError(selector, message) {
        $(selector).addClass('is-invalid');
        if (!$(selector).next('.invalid-feedback').length) {
            $(selector).after(`<div class="invalid-feedback d-block">${message}</div>`);
        } else {
            $(selector).next('.invalid-feedback').text(message);
        }
    }

    function validateKPIValue(input) {
        const value = $(input).val().trim();
        const fieldName = $(input).closest('.kpi-input-card').find('label').text().trim();
        
        if (value === '') {
            showValidationError(input, `"${fieldName}" is required`);
        } else if (isNaN(value) || parseFloat(value) < 0) {
            showValidationError(input, `"${fieldName}" must be a valid non-negative number`);
        } else {
            $(input).removeClass('is-invalid');
            $(input).next('.invalid-feedback').remove();
        }
    }

    // Real-time validation on input
    $('.kpi-field').on('input', function() {
        validateKPIValue(this);
    });

    function showNotification(message, type = 'info') {
        // You can implement a custom notification system here
        console.log(`${type.toUpperCase()}: ${message}`);
    }

    // Filter functions
    window.applySpecificFilters = function() {
        const specificDate = $('#kpi_overview_date').val();
        const urlParams = new URLSearchParams(window.location.search);
        
        if (specificDate) {
            urlParams.set('kpi_overview_date', specificDate);
        } else {
            urlParams.delete('kpi_overview_date');
        }
        
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    };

    window.clearSpecificFilters = function() {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.delete('kpi_overview_date');
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    };

    // Auto-hide alerts
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);

    // Add loading animation to buttons
    $('.btn').on('click', function() {
        const btn = $(this);
        if (!btn.hasClass('disabled')) {
            btn.css('transform', 'scale(0.95)');
            setTimeout(() => {
                btn.css('transform', 'scale(1)');
            }, 150);
        }
    });

    // Add intersection observer for fade-in animations
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('fade-in');
            }
        });
    }, observerOptions);

    // Observe all cards and sections
    document.querySelectorAll('.stats-card, .kpi-card, .table-card, .form-card').forEach(el => {
        observer.observe(el);
    });
});
</script>
</body>
</html>