<?php
session_start();
require_once 'db.php';

// Enhanced security check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager' || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$branch = $_SESSION['branch'] ?? '';
$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'];

// Validate branch access
if (empty($branch)) {
    header("Location: login.php");
    exit();
}

// Set default dates (first day of current month to today)
$default_from = date('Y-m-01');
$default_to = date('Y-m-d');

// Get filter parameters for each section
$branch_overview_from = $_GET['branch_overview_from'] ?? $default_from;
$branch_overview_to = $_GET['branch_overview_to'] ?? $default_to;
$staff_from = $_GET['staff_from'] ?? $default_from;
$staff_to = $_GET['staff_to'] ?? $default_to;
$pending_from = $_GET['pending_from'] ?? $default_from;
$pending_to = $_GET['pending_to'] ?? $default_to;
$branch_from = $_GET['branch_from'] ?? $default_from;
$branch_to = $_GET['branch_to'] ?? $default_to;

// KPI definitions with prepared statement
$kpi_names = [];
$kpi_query = $conn->prepare("SELECT id, kpi_name FROM kpis ORDER BY id ASC");
if ($kpi_query) {
    $kpi_query->execute();
    $result = $kpi_query->get_result();
    while($k = $result->fetch_assoc()) {
        $kpi_names[$k['id']] = $k['kpi_name'];
    }
    $kpi_query->close();
}

// Pending submissions with date filtering
$pending_sql = "
    SELECT us.id, u.username, u.branch, k.kpi_name, us.value, us.submission_date, us.status
    FROM user_submissions us
    JOIN users u ON us.user_id = u.id
    JOIN kpis k ON us.kpi_id = k.id
    WHERE u.branch = ? AND us.status = 'pending'
";

if (!empty($pending_from)) {
    $pending_sql .= " AND us.submission_date >= ?";
}
if (!empty($pending_to)) {
    $pending_sql .= " AND us.submission_date <= ?";
}
$pending_sql .= " ORDER BY us.submission_date DESC";

$pending_stmt = $conn->prepare($pending_sql);
if ($pending_stmt) {
    if (empty($pending_from) && empty($pending_to)) {
        $pending_stmt->bind_param("s", $branch);
    } elseif (!empty($pending_from) && empty($pending_to)) {
        $pending_stmt->bind_param("ss", $branch, $pending_from);
    } elseif (empty($pending_from) && !empty($pending_to)) {
        $pending_stmt->bind_param("ss", $branch, $pending_to);
    } else {
        $pending_stmt->bind_param("sss", $branch, $pending_from, $pending_to);
    }
    $pending_stmt->execute();
    $pending = $pending_stmt->get_result();
} else {
    // Fallback without filters
    $pending_stmt = $conn->prepare("
        SELECT us.id, u.username, u.branch, k.kpi_name, us.value, us.submission_date, us.status
        FROM user_submissions us
        JOIN users u ON us.user_id = u.id
        JOIN kpis k ON us.kpi_id = k.id
        WHERE u.branch = ? AND us.status = 'pending'
        ORDER BY us.submission_date DESC
    ");
    $pending_stmt->bind_param("s", $branch);
    $pending_stmt->execute();
    $pending = $pending_stmt->get_result();
}

// Staff performance with date filtering
$staff_sql = "
    SELECT u.username, k.kpi_name, SUM(us.value) AS total_value, us.status
    FROM user_submissions us
    JOIN users u ON us.user_id = u.id
    JOIN kpis k ON us.kpi_id = k.id
    WHERE u.branch = ? AND us.status = 'approved'
";

if (!empty($staff_from)) {
    $staff_sql .= " AND us.submission_date >= ?";
}
if (!empty($staff_to)) {
    $staff_sql .= " AND us.submission_date <= ?";
}
$staff_sql .= " GROUP BY u.username, k.kpi_name ORDER BY u.username";

$staff_performance_stmt = $conn->prepare($staff_sql);
if ($staff_performance_stmt) {
    if (empty($staff_from) && empty($staff_to)) {
        $staff_performance_stmt->bind_param("s", $branch);
    } elseif (!empty($staff_from) && empty($staff_to)) {
        $staff_performance_stmt->bind_param("ss", $branch, $staff_from);
    } elseif (empty($staff_from) && !empty($staff_to)) {
        $staff_performance_stmt->bind_param("ss", $branch, $staff_to);
    } else {
        $staff_performance_stmt->bind_param("sss", $branch, $staff_from, $staff_to);
    }
    $staff_performance_stmt->execute();
    $staff_performance = $staff_performance_stmt->get_result();
} else {
    // Fallback without filters
    $staff_performance_stmt = $conn->prepare("
        SELECT u.username, k.kpi_name, SUM(us.value) AS total_value, us.status
        FROM user_submissions us
        JOIN users u ON us.user_id = u.id
        JOIN kpis k ON us.kpi_id = k.id
        WHERE u.branch = ? AND us.status = 'approved'
        GROUP BY u.username, k.kpi_name
        ORDER BY u.username
    ");
    $staff_performance_stmt->bind_param("s", $branch);
    $staff_performance_stmt->execute();
    $staff_performance = $staff_performance_stmt->get_result();
}

$staff_data = [];
while($row = $staff_performance->fetch_assoc()) {
    $staff = $row['username'];
    $kpi = $row['kpi_name'];
    $value = $row['total_value'];
    $staff_data[$staff]['kpis'][$kpi] = $value;
    $staff_data[$staff]['status'] = $row['status'];
}

// Branch KPI overview with date filtering - UPDATED
$branch_sql = "
    SELECT k.kpi_name, SUM(us.value) AS total_value, COUNT(us.id) AS total_submissions
    FROM user_submissions us
    JOIN users u ON us.user_id = u.id
    JOIN kpis k ON us.kpi_id = k.id
    WHERE u.branch = ? AND us.status = 'approved'
";

if (!empty($branch_overview_from)) {
    $branch_sql .= " AND us.submission_date >= ?";
}
if (!empty($branch_overview_to)) {
    $branch_sql .= " AND us.submission_date <= ?";
}
$branch_sql .= " GROUP BY k.kpi_name";

$branch_performance_stmt = $conn->prepare($branch_sql);
if ($branch_performance_stmt) {
    if (empty($branch_overview_from) && empty($branch_overview_to)) {
        $branch_performance_stmt->bind_param("s", $branch);
    } elseif (!empty($branch_overview_from) && empty($branch_overview_to)) {
        $branch_performance_stmt->bind_param("ss", $branch, $branch_overview_from);
    } elseif (empty($branch_overview_from) && !empty($branch_overview_to)) {
        $branch_performance_stmt->bind_param("ss", $branch, $branch_overview_to);
    } else {
        $branch_performance_stmt->bind_param("sss", $branch, $branch_overview_from, $branch_overview_to);
    }
    $branch_performance_stmt->execute();
    $branch_performance = $branch_performance_stmt->get_result();
} else {
    // Fallback without filters
    $branch_performance_stmt = $conn->prepare("
        SELECT k.kpi_name, SUM(us.value) AS total_value, COUNT(us.id) AS total_submissions
        FROM user_submissions us
        JOIN users u ON us.user_id = u.id
        JOIN kpis k ON us.kpi_id = k.id
        WHERE u.branch = ? AND us.status = 'approved'
        GROUP BY k.kpi_name
    ");
    $branch_performance_stmt->bind_param("s", $branch);
    $branch_performance_stmt->execute();
    $branch_performance = $branch_performance_stmt->get_result();
}

// Overall branch performance with date filtering
$overall_sql = "
    SELECT u.branch, k.kpi_name, SUM(us.value) AS total_value
    FROM user_submissions us
    JOIN users u ON us.user_id = u.id
    JOIN kpis k ON us.kpi_id = k.id
    WHERE us.status = 'approved'
";

if (!empty($branch_from)) {
    $overall_sql .= " AND us.submission_date >= ?";
}
if (!empty($branch_to)) {
    $overall_sql .= " AND us.submission_date <= ?";
}
$overall_sql .= " GROUP BY u.branch, k.kpi_name ORDER BY u.branch";

$overall_stmt = $conn->prepare($overall_sql);
if ($overall_stmt) {
    if (!empty($branch_from) && !empty($branch_to)) {
        $overall_stmt->bind_param("ss", $branch_from, $branch_to);
        $overall_stmt->execute();
    } elseif (!empty($branch_from)) {
        $overall_stmt->bind_param("s", $branch_from);
        $overall_stmt->execute();
    } elseif (!empty($branch_to)) {
        $overall_stmt->bind_param("s", $branch_to);
        $overall_stmt->execute();
    } else {
        $overall_stmt->execute();
    }
    $overall_result = $overall_stmt->get_result();
} else {
    $overall_result = $conn->query("
        SELECT u.branch, k.kpi_name, SUM(us.value) AS total_value
        FROM user_submissions us
        JOIN users u ON us.user_id = u.id
        JOIN kpis k ON us.kpi_id = k.id
        WHERE us.status = 'approved'
        GROUP BY u.branch, k.kpi_name
        ORDER BY u.branch
    ");
}

$overall_data = [];
if ($overall_result) {
    while($r = $overall_result->fetch_assoc()) {
        $branch_name = $r['branch'];
        $kpi = $r['kpi_name'];
        $overall_data[$branch_name][$kpi] = $r['total_value'];
    }
}

// Get date range display
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manager Dashboard - KPI Performance</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
  --primary: #4361ee;
  --secondary: #3f37c9;
  --success: #4cc9f0;
  --info: #4895ef;
  --warning: #f72585;
  --light: #f8f9fa;
  --dark: #212529;
  --sidebar-width: 280px;
  --sidebar-collapsed: 80px;
  --border-radius: 12px;
  --shadow: 0 8px 30px rgba(0,0,0,0.08);
  --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
  color: #2d3748;
  line-height: 1.6;
  min-height: 100vh;
}

/* Enhanced Sidebar */
.sidebar {
  position: fixed;
  top: 0;
  left: 0;
  height: 100vh;
  width: var(--sidebar-width);
  background: linear-gradient(180deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
  color: white;
  transition: var(--transition);
  z-index: 1000;
  box-shadow: var(--shadow);
  overflow-y: auto;
}

.sidebar.collapsed {
  width: var(--sidebar-collapsed);
}

.sidebar.collapsed .nav-text,
.sidebar.collapsed .user-info,
.sidebar.collapsed .sidebar-header h4 {
  display: none;
}

.sidebar-header {
  padding: 1.5rem 1.5rem 1rem;
  border-bottom: 1px solid rgba(255,255,255,0.1);
  margin-bottom: 1rem;
}

.sidebar-header h4 {
  font-weight: 700;
  font-size: 1.25rem;
  color: white;
  margin: 0;
}

.user-info {
  padding: 0 1.5rem 1.5rem;
  border-bottom: 1px solid rgba(255,255,255,0.1);
  margin-bottom: 1rem;
}

.user-info p {
  margin: 0.25rem 0;
  font-size: 0.9rem;
  opacity: 0.9;
}

.user-info strong {
  font-size: 1rem;
  color: white;
}

.sidebar-nav {
  padding: 0 1rem;
}

.nav-item {
  margin-bottom: 0.5rem;
}

.nav-link {
  display: flex;
  align-items: center;
  padding: 0.875rem 1rem;
  color: rgba(255,255,255,0.8);
  text-decoration: none;
  border-radius: var(--border-radius);
  transition: var(--transition);
  font-weight: 500;
}

.nav-link:hover {
  background: rgba(255,255,255,0.1);
  color: white;
  transform: translateX(5px);
}

.nav-link.active {
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: white;
  box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
}

.nav-icon {
  font-size: 1.25rem;
  margin-right: 0.75rem;
  width: 24px;
  text-align: center;
}

.toggle-btn {
  position: absolute;
  top: 1.5rem;
  right: -12px;
  background: var(--primary);
  color: white;
  border: none;
  border-radius: 50%;
  width: 24px;
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: var(--transition);
  font-size: 0.75rem;
}

.toggle-btn:hover {
  background: var(--secondary);
  transform: scale(1.1);
}

/* Main Content */
.main {
  margin-left: var(--sidebar-width);
  transition: var(--transition);
  min-height: 100vh;
  padding: 2rem;
}

.main.collapsed {
  margin-left: var(--sidebar-collapsed);
}

/* Welcome Banner */
.welcome-banner {
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: white;
  padding: 2rem;
  border-radius: var(--border-radius);
  margin-bottom: 2rem;
  box-shadow: var(--shadow);
  position: relative;
  overflow: hidden;
}

.welcome-banner::before {
  content: '';
  position: absolute;
  top: 0;
  right: 0;
  width: 200px;
  height: 200px;
  background: rgba(255,255,255,0.1);
  border-radius: 50%;
  transform: translate(60px, -60px);
}

.welcome-banner h1 {
  font-weight: 700;
  margin-bottom: 0.5rem;
  font-size: 2rem;
}

.welcome-banner p {
  opacity: 0.9;
  margin: 0;
}

/* Section Styling */
.section {
  margin-bottom: 3rem;
}

.section-header {
  display: flex;
  align-items: center;
  justify-content: between;
  margin-bottom: 1.5rem;
}

.section-title {
  font-weight: 700;
  color: var(--dark);
  font-size: 1.5rem;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.section-title::before {
  content: '';
  display: block;
  width: 4px;
  height: 24px;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  border-radius: 2px;
}

/* Cards */
.card {
  border: none;
  border-radius: var(--border-radius);
  box-shadow: var(--shadow);
  transition: var(--transition);
  background: white;
  overflow: hidden;
}

.card:hover {
  transform: translateY(-5px);
  box-shadow: 0 12px 40px rgba(0,0,0,0.15);
}

.card-header {
  background: white;
  border-bottom: 1px solid #e9ecef;
  padding: 1.25rem 1.5rem;
  font-weight: 600;
  color: var(--dark);
}

.card-body {
  padding: 1.5rem;
}

/* KPI Cards */
.kpi-card {
  text-align: center;
  padding: 2rem 1rem;
  border-radius: var(--border-radius);
  background: white;
  transition: var(--transition);
  border: 1px solid #e9ecef;
  height: 100%;
}

.kpi-card:hover {
  transform: translateY(-3px);
  box-shadow: var(--shadow);
}

.kpi-icon {
  width: 60px;
  height: 60px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 1rem;
  font-size: 1.5rem;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: white;
}

.kpi-value {
  font-size: 2rem;
  font-weight: 700;
  color: var(--primary);
  margin: 0.5rem 0;
}

.kpi-label {
  color: #6c757d;
  font-size: 0.9rem;
  margin: 0;
}

/* Search and Filter - UPDATED for single line */
.filter-section {
  background: white;
  padding: 1.5rem;
  border-radius: var(--border-radius);
  margin-bottom: 1.5rem;
  box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.search-filter-row {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: nowrap;
}

.search-box {
  position: relative;
  flex: 0 0 300px;
}

.search-box .form-control {
  padding-left: 2.5rem;
  border-radius: 50px;
  border: 1px solid #e2e8f0;
  transition: var(--transition);
}

.search-box .form-control:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
}

.search-icon {
  position: absolute;
  left: 1rem;
  top: 50%;
  transform: translateY(-50%);
  color: #9ca3af;
}

.date-filter-group {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: nowrap;
  flex: 1;
}

.date-input-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex: 0 0 auto;
}

.date-input-group label {
  font-size: 0.875rem;
  font-weight: 500;
  color: #6c757d;
  margin: 0;
  white-space: nowrap;
}

.date-input {
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  padding: 0.5rem 1rem;
  transition: var(--transition);
  min-width: 140px;
}

.date-input:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
}

.filter-active {
  border-color: var(--primary);
  background-color: rgba(67, 97, 238, 0.05);
}

.filter-buttons {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex: 0 0 auto;
}

/* Tables */
.table-container {
  background: white;
  border-radius: var(--border-radius);
  overflow: hidden;
  box-shadow: var(--shadow);
}

.table {
  margin: 0;
  border-collapse: separate;
  border-spacing: 0;
}

.table thead th {
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: white;
  border: none;
  padding: 1rem 1.25rem;
  font-weight: 600;
  font-size: 0.875rem;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.table tbody td {
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
}

.table tbody tr {
  transition: var(--transition);
}

.table tbody tr:hover {
  background-color: #f8fafc;
  transform: scale(1.002);
}

/* Badges */
.badge {
  padding: 0.5rem 1rem;
  border-radius: 50px;
  font-weight: 600;
  font-size: 0.75rem;
}

.badge-pending {
  background: #fff3cd;
  color: #856404;
}

.badge-approved {
  background: #d1edff;
  color: var(--primary);
}

.badge-success {
  background: #d1fae5;
  color: #065f46;
}

/* Buttons */
.btn {
  border-radius: 8px;
  padding: 0.625rem 1.25rem;
  font-weight: 600;
  transition: var(--transition);
  border: none;
}

.btn-primary {
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
}

.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
}

.btn-success, .btn-danger {
  padding: 0.5rem 1rem;
  border-radius: 6px;
}

.btn-success {
  background: linear-gradient(135deg, #10b981, #059669);
}

.btn-danger {
  background: linear-gradient(135deg, #ef4444, #dc2626);
}

/* Loading Spinner */
.loading-spinner {
  display: none;
  width: 16px;
  height: 16px;
  border: 2px solid transparent;
  border-top: 2px solid currentColor;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

/* Empty States */
.empty-state {
  text-align: center;
  padding: 3rem 2rem;
  color: #6b7280;
}

.empty-state i {
  font-size: 3rem;
  margin-bottom: 1rem;
  opacity: 0.5;
}

/* Responsive Design */
@media (max-width: 1200px) {
  .search-filter-row {
    flex-wrap: wrap;
  }
  
  .search-box {
    flex: 0 0 100%;
  }
  
  .date-filter-group {
    flex: 0 0 100%;
    justify-content: flex-start;
  }
}

@media (max-width: 768px) {
  .sidebar {
    width: var(--sidebar-collapsed);
  }
  
  .sidebar .nav-text,
  .sidebar .user-info,
  .sidebar .sidebar-header h4 {
    display: none;
  }
  
  .main {
    margin-left: var(--sidebar-collapsed);
    padding: 1rem;
  }
  
  .welcome-banner h1 {
    font-size: 1.5rem;
  }
  
  .date-filter-group {
    flex-direction: column;
    align-items: stretch;
  }
  
  .date-input-group {
    flex: 1;
  }
  
  .section-title {
    font-size: 1.25rem;
  }
  
  .search-filter-row {
    gap: 0.75rem;
  }
}

/* Custom Scrollbar */
::-webkit-scrollbar {
  width: 6px;
}

::-webkit-scrollbar-track {
  background: #f1f5f9;
}

::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 3px;
}

::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

/* Animation Classes */
.fade-in {
  animation: fadeIn 0.5s ease-in;
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

/* Status Indicators */
.status-indicator {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-right: 8px;
}

.status-active { background: #10b981; }
.status-pending { background: #f59e0b; }
.status-inactive { background: #ef4444; }

/* Progress Bars */
.progress {
  height: 8px;
  border-radius: 4px;
  background: #e5e7eb;
  overflow: hidden;
}

.progress-bar {
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  transition: width 0.6s ease;
}
</style>
</head>

<body>
<div class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <h4>📊 KPI Manager</h4>
  </div>
  
  <button class="toggle-btn" id="toggleSidebar">
    <i class="bi bi-chevron-left"></i>
  </button>
  
  <div class="user-info">
    <p><strong><?= htmlspecialchars($username) ?></strong></p>
    <p class="small">🏢 <?= htmlspecialchars($branch) ?> Branch</p>
  </div>
  
  <nav class="sidebar-nav">
    <div class="nav-item">
      <a href="manager_panel.php" class="nav-link active">
        <i class="bi bi-speedometer2 nav-icon"></i>
        <span class="nav-text">Dashboard Overview</span>
      </a>
    </div>
    <div class="nav-item">
      <a href="manager_performance.php?view_type=staff" class="nav-link">
        <i class="bi bi-people-fill nav-icon"></i>
        <span class="nav-text">Team Performance</span>
      </a>
    </div>
    <div class="nav-item">
      <a href="manager_performance.php?view_type=branch" class="nav-link">
        <i class="bi bi-building nav-icon"></i>
        <span class="nav-text">Branch Performance</span>
      </a>
    </div>
    <div class="nav-item">
      <a href="#pending" class="nav-link">
        <i class="bi bi-clock-history nav-icon"></i>
        <span class="nav-text">Pending Approvals</span>
        <?php 
        // You'll need to update this to get pending count from database
        $pending_count = 0; // Replace with actual count query
        if ($pending_count > 0): ?>
          <span class="badge bg-warning ms-auto"><?= $pending_count ?></span>
        <?php endif; ?>
      </a>
    </div>
    
    <div class="nav-item">
      <a href="manager_performance.php" class="nav-link">
        <i class="bi bi-person-circle nav-icon"></i>
        <span class="nav-text">My Performance</span>
      </a>
    </div>
    <div class="nav-item mt-4">
      <a href="?logout=1" class="nav-link">
        <i class="bi bi-box-arrow-right nav-icon"></i>
        <span class="nav-text">Sign Out</span>
      </a>
    </div>
  </nav>
</div>
</div>

<div class="main" id="main">
  <!-- Welcome Banner -->
  <div class="welcome-banner fade-in">
    <h1>Welcome back, <?= htmlspecialchars($username) ?>! 👋</h1>
    <p>Here's your branch performance overview and management dashboard</p>
  </div>

  <!-- Branch KPI Overview - UPDATED with filters -->
  <section id="branch" class="section fade-in">
    <div class="section-header">
      <h2 class="section-title">
        <i class="bi bi-building"></i>
        Branch Performance Overview<?= getDateRangeDisplay($branch_overview_from, $branch_overview_to) ?>
      </h2>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="filter-section">
          <div class="search-filter-row">
            <div class="date-filter-group">
              <div class="date-input-group">
                <label for="branchOverviewFrom">From:</label>
                <input id="branchOverviewFrom" type="date" class="form-control date-input" value="<?= htmlspecialchars($branch_overview_from) ?>" />
              </div>
              <div class="date-input-group">
                <label for="branchOverviewTo">To:</label>
                <input id="branchOverviewTo" type="date" class="form-control date-input" value="<?= htmlspecialchars($branch_overview_to) ?>" />
              </div>
              <div class="filter-buttons">
                <button id="applyBranchOverviewFilters" class="btn btn-primary">
                  <span class="loading-spinner" id="branchOverviewSpinner"></span>
                  Apply
                </button>
                <button id="clearBranchOverviewFilters" class="btn btn-outline-secondary">Clear</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card-body">
        <div class="row g-4">
          <?php if ($branch_performance->num_rows > 0): ?>
            <?php while ($b = $branch_performance->fetch_assoc()): ?>
              <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="kpi-card">
                  <div class="kpi-icon">
                    <i class="bi bi-graph-up"></i>
                  </div>
                  <h3 class="kpi-value"><?= $b['total_value']; ?></h3>
                  <h5 class="card-title"><?= htmlspecialchars($b['kpi_name']); ?></h5>
                  <p class="kpi-label"><?= $b['total_submissions']; ?> Submissions</p>
                </div>
              </div>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="col-12">
              <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h4>No Data Available</h4>
                <p>No approved KPI data found for the selected date range.</p>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- Staff KPI Table -->
  <section id="staff" class="section fade-in">
    <div class="section-header">
      <h2 class="section-title">
        <i class="bi bi-people-fill"></i>
        Team Performance Analysis<?= getDateRangeDisplay($staff_from, $staff_to) ?>
      </h2>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="filter-section">
          <div class="search-filter-row">
            <div class="search-box">
              <i class="bi bi-search search-icon"></i>
              <input id="staffSearch" class="form-control" placeholder="Search team members or KPIs..." value="<?= htmlspecialchars($_GET['staff_search'] ?? '') ?>"/>
            </div>
            <div class="date-filter-group">
              <div class="date-input-group">
                <label for="staffFrom">From:</label>
                <input id="staffFrom" type="date" class="form-control date-input" value="<?= htmlspecialchars($staff_from) ?>" />
              </div>
              <div class="date-input-group">
                <label for="staffTo">To:</label>
                <input id="staffTo" type="date" class="form-control date-input" value="<?= htmlspecialchars($staff_to) ?>" />
              </div>
              <div class="filter-buttons">
                <button id="applyStaffFilters" class="btn btn-primary">
                  <span class="loading-spinner" id="staffSpinner"></span>
                  Apply
                </button>
                <button id="clearStaffFilters" class="btn btn-outline-secondary">Clear</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="table-container">
        <div class="table-responsive">
          <table class="table table-hover" id="staffTable">
            <thead>
              <tr>
                <th>Team Member</th>
                <?php foreach($kpi_names as $kpi): ?>
                  <th><?= htmlspecialchars($kpi) ?></th>
                <?php endforeach; ?>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($staff_data)): ?>
                <?php foreach($staff_data as $staff => $info): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center">
                        <div class="status-indicator status-active"></div>
                        <strong><?= htmlspecialchars($staff) ?></strong>
                      </div>
                    </td>
                    <?php foreach($kpi_names as $kpi): ?>
                      <td>
                        <span class="fw-bold text-primary"><?= $info['kpis'][$kpi] ?? '-' ?></span>
                      </td>
                    <?php endforeach; ?>
                    <td>
                      <span class="badge badge-success">
                        <i class="bi bi-check-circle-fill me-1"></i>
                        Approved
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="<?= count($kpi_names)+2 ?>" class="text-center py-5">
                    <div class="empty-state">
                      <i class="bi bi-person-x"></i>
                      <h5>No Team Data</h5>
                      <p class="text-muted">No approved performance data found for your team.</p>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <!-- Pending Approvals -->
  <section id="pending" class="section fade-in">
    <div class="section-header">
      <h2 class="section-title">
        <i class="bi bi-clock-history"></i>
        Approval Queue<?= getDateRangeDisplay($pending_from, $pending_to) ?>
        <?php if ($pending->num_rows > 0): ?>
          <span class="badge bg-warning"><?= $pending->num_rows ?> Pending</span>
        <?php endif; ?>
      </h2>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="filter-section">
          <div class="search-filter-row">
            <div class="search-box">
              <i class="bi bi-search search-icon"></i>
              <input id="pendingSearch" class="form-control" placeholder="Search submissions..." value="<?= htmlspecialchars($_GET['pending_search'] ?? '') ?>"/>
            </div>
            <div class="date-filter-group">
              <div class="date-input-group">
                <label for="pendingFrom">From:</label>
                <input id="pendingFrom" type="date" class="form-control date-input" value="<?= htmlspecialchars($pending_from) ?>" />
              </div>
              <div class="date-input-group">
                <label for="pendingTo">To:</label>
                <input id="pendingTo" type="date" class="form-control date-input" value="<?= htmlspecialchars($pending_to) ?>" />
              </div>
              <div class="filter-buttons">
                <button id="applyPendingFilters" class="btn btn-primary">
                  <span class="loading-spinner" id="pendingSpinner"></span>
                  Apply
                </button>
                <button id="clearPendingFilters" class="btn btn-outline-secondary">Clear</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="table-container">
        <div class="table-responsive">
          <table class="table table-hover" id="pendingTable">
            <thead>
              <tr>
                <th>Submitted By</th>
                <th>KPI Metric</th>
                <th>Value</th>
                <th>Date Submitted</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($pending->num_rows > 0): ?>
                <?php while ($row = $pending->fetch_assoc()): ?>
                  <tr data-id="<?= $row['id'] ?>" class="slide-in">
                    <td>
                      <div class="d-flex align-items-center">
                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($row['username']) ?>&background=4361ee&color=fff" class="rounded-circle me-2" width="32" height="32" alt="<?= htmlspecialchars($row['username']) ?>">
                        <div>
                          <div class="fw-bold"><?= htmlspecialchars($row['username']) ?></div>
                          <small class="text-muted"><?= htmlspecialchars($row['branch']) ?></small>
                        </div>
                      </div>
                    </td>
                    <td>
                      <span class="fw-bold"><?= htmlspecialchars($row['kpi_name']) ?></span>
                    </td>
                    <td>
                      <span class="badge bg-light text-dark fs-6"><?= htmlspecialchars($row['value']) ?></span>
                    </td>
                    <td>
                      <small class="text-muted"><?= htmlspecialchars($row['submission_date']) ?></small>
                    </td>
                    <td>
                      <span class="badge badge-pending">
                        <i class="bi bi-clock me-1"></i>
                        Pending Review
                      </span>
                    </td>
                    <td>
                      <div class="btn-group btn-group-sm">
                        <button class="btn btn-success approve-btn" title="Approve Submission">
                          <i class="bi bi-check-lg"></i> Approve
                        </button>
                        <button class="btn btn-danger reject-btn" title="Reject Submission">
                          <i class="bi bi-x-lg"></i> Reject
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="6" class="text-center py-5">
                    <div class="empty-state">
                      <i class="bi bi-check-circle text-success"></i>
                      <h5>All Caught Up! 🎉</h5>
                      <p class="text-muted">No pending submissions requiring approval.</p>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <!-- Overall Branch Comparison -->
  <section id="overall" class="section fade-in">
    <div class="section-header">
      <h2 class="section-title">
        <i class="bi bi-trophy-fill"></i>
        Branch Performance Rankings<?= getDateRangeDisplay($branch_from, $branch_to) ?>
      </h2>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="filter-section">
          <div class="search-filter-row">
            <div class="search-box">
              <i class="bi bi-search search-icon"></i>
              <input id="branchSearch" class="form-control" placeholder="Search branches..." value="<?= htmlspecialchars($_GET['branch_search'] ?? '') ?>"/>
            </div>
            <div class="date-filter-group">
              <div class="date-input-group">
                <label for="branchFrom">From:</label>
                <input id="branchFrom" type="date" class="form-control date-input" value="<?= htmlspecialchars($branch_from) ?>" />
              </div>
              <div class="date-input-group">
                <label for="branchTo">To:</label>
                <input id="branchTo" type="date" class="form-control date-input" value="<?= htmlspecialchars($branch_to) ?>" />
              </div>
              <div class="filter-buttons">
                <button id="applyBranchFilters" class="btn btn-primary">
                  <span class="loading-spinner" id="branchSpinner"></span>
                  Apply
                </button>
                <button id="clearBranchFilters" class="btn btn-outline-secondary">Clear</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="table-container">
        <div class="table-responsive">
          <table class="table table-hover" id="branchTable">
            <thead>
              <tr>
                <th>Branch</th>
                <?php foreach($kpi_names as $kpi): ?>
                  <th class="text-center"><?= htmlspecialchars($kpi) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($overall_data)): ?>
                <?php foreach($overall_data as $branchName => $kpis): ?>
                  <tr class="<?= $branchName === $branch ? 'table-active' : '' ?>">
                    <td>
                      <div class="d-flex align-items-center">
                        <?php if ($branchName === $branch): ?>
                          <span class="badge bg-primary me-2">
                            <i class="bi bi-star-fill"></i> Your Branch
                          </span>
                        <?php endif; ?>
                        <strong><?= htmlspecialchars($branchName) ?></strong>
                      </div>
                    </td>
                    <?php foreach($kpi_names as $kpi): ?>
                      <td class="text-center">
                        <span class="fw-bold <?= $branchName === $branch ? 'text-primary' : 'text-dark' ?>">
                          <?= $kpis[$kpi] ?? '-' ?>
                        </span>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="<?= count($kpi_names)+1 ?>" class="text-center py-5">
                    <div class="empty-state">
                      <i class="bi bi-graph-up"></i>
                      <h5>No Comparison Data</h5>
                      <p class="text-muted">No branch performance data available for the selected period.</p>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
</div>

<script>
$(document).ready(function(){
    // Enhanced Sidebar Toggle
    $('#toggleSidebar').click(function(){
        $('#sidebar').toggleClass('collapsed');
        $('#main').toggleClass('collapsed');
        const icon = $(this).find('i');
        icon.toggleClass('bi-chevron-left bi-chevron-right');
    });

    // Smooth scrolling with offset
    // Only handle smooth scrolling for anchor links, allow page navigation for others
$('.sidebar-nav a').on('click', function(e) {
    const href = $(this).attr('href');
    
    // Allow navigation for actual pages and logout
    if (href.startsWith('?') || !href.startsWith('#')) {
        return true; // Allow normal navigation
    }
    
    // Only prevent default for anchor links on same page
    e.preventDefault();
    const target = $(href);
    if (target.length) {
        $('.sidebar-nav a').removeClass('active');
        $(this).addClass('active');
        
        $('html, body').animate({
            scrollTop: target.offset().top - 100
        }, 800, 'easeInOutCubic');
    }
});

    // Update active nav based on scroll position
    $(window).on('scroll', function() {
        const scrollPos = $(document).scrollTop();
        $('.section').each(function() {
            const sectionTop = $(this).offset().top - 150;
            const sectionBottom = sectionTop + $(this).outerHeight();
            const sectionId = $(this).attr('id');
            
            if (scrollPos >= sectionTop && scrollPos < sectionBottom) {
                $('.sidebar-nav a').removeClass('active');
                $(`.sidebar-nav a[href="#${sectionId}"]`).addClass('active');
            }
        });
    });

    // Enhanced date input styling
    function updateDateInputStyles() {
        $('.date-input').each(function() {
            if ($(this).val()) {
                $(this).addClass('filter-active');
            } else {
                $(this).removeClass('filter-active');
            }
        });
    }
    
    updateDateInputStyles();
    $('.date-input').on('change', updateDateInputStyles);

    // Enhanced Approve/Reject with better UX
    $(".approve-btn, .reject-btn").click(function(){
        let row = $(this).closest("tr");
        let id = row.data("id");
        let isApprove = $(this).hasClass("approve-btn");
        let status = isApprove ? "approved" : "rejected";
        let actionText = isApprove ? "approve" : "reject";
        let actionIcon = isApprove ? "check-circle" : "x-circle";
        let actionColor = isApprove ? "success" : "danger";

        if (confirm(`Are you sure you want to ${actionText} this submission?`)) {
            const buttons = row.find('.btn-group');
            buttons.html(`
                <div class="d-flex align-items-center text-${actionColor}">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                    Processing...
                </div>
            `);

            $.post("update_submission_status.php", { id, status }, function(res) {
                if (res.status === "success") {
                    showAlert(`Submission ${status} successfully!`, actionColor);
                    row.addClass('table-' + actionColor);
                    setTimeout(() => {
                        row.fadeOut(400, function() {
                            $(this).remove();
                            updatePendingCount();
                            if ($('#pendingTable tbody tr').length === 0) {
                                $('#pendingTable tbody').html(`
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <div class="empty-state">
                                                <i class="bi bi-check-circle text-success"></i>
                                                <h5>All Caught Up! 🎉</h5>
                                                <p class="text-muted">No pending submissions requiring approval.</p>
                                            </div>
                                        </td>
                                    </tr>
                                `);
                            }
                        });
                    }, 1000);
                } else {
                    showAlert('Error: ' + res.message, 'danger');
                    buttons.html(`
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-success approve-btn" title="Approve Submission">
                                <i class="bi bi-check-lg"></i> Approve
                            </button>
                            <button class="btn btn-danger reject-btn" title="Reject Submission">
                                <i class="bi bi-x-lg"></i> Reject
                            </button>
                        </div>
                    `);
                }
            }, "json").fail(function() {
                showAlert('Network error. Please try again.', 'danger');
                buttons.html(`
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-success approve-btn" title="Approve Submission">
                            <i class="bi bi-check-lg"></i> Approve
                        </button>
                        <button class="btn btn-danger reject-btn" title="Reject Submission">
                            <i class="bi bi-x-lg"></i> Reject
                        </button>
                    </div>
                `);
            });
        }
    });

    // Update pending count in sidebar
    function updatePendingCount() {
        const pendingCount = $('#pendingTable tbody tr:not(.empty-state)').length;
        const badge = $('a[href="#pending"] .badge');
        if (pendingCount > 0) {
            badge.text(pendingCount).show();
        } else {
            badge.hide();
        }
    }

    // Enhanced search with debouncing - UPDATED for individual tables
    let searchTimeout;
    function searchTable(inputId, tableId) {
        const searchText = $(`#${inputId}`).val().toLowerCase();
        $(`#${tableId} tbody tr`).each(function() {
            const rowText = $(this).text().toLowerCase();
            $(this).toggle(rowText.includes(searchText));
        });
    }

    // Real-time search with debounce - UPDATED for individual tables
    $('#staffSearch').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => searchTable('staffSearch', 'staffTable'), 300);
    });

    $('#pendingSearch').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => searchTable('pendingSearch', 'pendingTable'), 300);
    });

    $('#branchSearch').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => searchTable('branchSearch', 'branchTable'), 300);
    });

    // Date filter application with enhanced UX - UPDATED for individual sections
    function applyDateFilters(section, fromId, toId, spinnerId) {
        const fromDate = $(`#${fromId}`).val();
        const toDate = $(`#${toId}`).val();
        
        // Validate date range
        if (fromDate && toDate && fromDate > toDate) {
            showAlert('Start date cannot be after end date', 'warning');
            return;
        }
        
        // Show loading spinner
        $(`#${spinnerId}`).show();
        $(`#apply${section.charAt(0).toUpperCase() + section.slice(1)}Filters`).prop('disabled', true);
        
        // Build URL with current filters
        const urlParams = new URLSearchParams(window.location.search);
        
        // Set the specific date parameters for this section
        if (section === 'branchOverview') {
            urlParams.set('branch_overview_from', fromDate || '');
            urlParams.set('branch_overview_to', toDate || '');
        } else {
            urlParams.set(`${section}_from`, fromDate || '');
            urlParams.set(`${section}_to`, toDate || '');
        }
        
        // Add loading state to the section
        $(`#${section === 'branchOverview' ? 'branch' : section}`).addClass('loading');
        
        // Reload page with new filters
        setTimeout(() => {
            window.location.href = window.location.pathname + '?' + urlParams.toString();
        }, 500);
    }

    // Apply filter buttons - UPDATED for individual sections
    $('#applyBranchOverviewFilters').click(() => applyDateFilters('branchOverview', 'branchOverviewFrom', 'branchOverviewTo', 'branchOverviewSpinner'));
    $('#applyStaffFilters').click(() => applyDateFilters('staff', 'staffFrom', 'staffTo', 'staffSpinner'));
    $('#applyPendingFilters').click(() => applyDateFilters('pending', 'pendingFrom', 'pendingTo', 'pendingSpinner'));
    $('#applyBranchFilters').click(() => applyDateFilters('branch', 'branchFrom', 'branchTo', 'branchSpinner'));

    // Clear filter functions - UPDATED for individual sections
    function clearFilters(section, searchId, fromId, toId) {
        // Set default dates (first day of current month to today)
        const defaultFrom = '<?= $default_from ?>';
        const defaultTo = '<?= $default_to ?>';
        
        $(`#${searchId}`).val('');
        $(`#${fromId}`).val(defaultFrom);
        $(`#${toId}`).val(defaultTo);
        updateDateInputStyles();
        searchTable(searchId, searchId.replace('Search', 'Table'));
        
        const urlParams = new URLSearchParams(window.location.search);
        
        // Remove the specific date parameters for this section
        if (section === 'branchOverview') {
            urlParams.delete('branch_overview_from');
            urlParams.delete('branch_overview_to');
        } else {
            urlParams.delete(`${section}_from`);
            urlParams.delete(`${section}_to`);
        }
        urlParams.delete(`${section}_search`);
        
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }

    $('#clearBranchOverviewFilters').click(() => clearFilters('branchOverview', 'branchSearch', 'branchOverviewFrom', 'branchOverviewTo'));
    $('#clearStaffFilters').click(() => clearFilters('staff', 'staffSearch', 'staffFrom', 'staffTo'));
    $('#clearPendingFilters').click(() => clearFilters('pending', 'pendingSearch', 'pendingFrom', 'pendingTo'));
    $('#clearBranchFilters').click(() => clearFilters('branch', 'branchSearch', 'branchFrom', 'branchTo'));

    // Enter key support for search and filters - UPDATED
    $('.search-box input, .date-input').on('keypress', function(e) {
        if (e.which === 13) {
            const filterSection = $(this).closest('.filter-section');
            const applyButton = filterSection.find('[id*="apply"]');
            if (applyButton.length) {
                applyButton.click();
            }
        }
    });

    // Enhanced alert system
    function showAlert(message, type) {
        const alertClass = `alert-${type}`;
        const icon = {
            'success': 'bi-check-circle-fill',
            'danger': 'bi-exclamation-triangle-fill',
            'warning': 'bi-exclamation-circle-fill',
            'info': 'bi-info-circle-fill'
        }[type] || 'bi-info-circle-fill';

        const alertHtml = `
            <div class="alert ${alertClass} alert-dismissible fade show position-fixed" 
                 style="top: 20px; right: 20px; z-index: 1050; min-width: 300px; border: none; border-radius: 12px; box-shadow: 0 8px 25px rgba(0,0,0,0.15);">
                <div class="d-flex align-items-center">
                    <i class="bi ${icon} me-2" style="font-size: 1.2rem;"></i>
                    <div class="flex-grow-1">${message}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        `;
        
        $('.alert.position-fixed').remove();
        $('body').append(alertHtml);
        
        setTimeout(() => {
            $('.alert.position-fixed').alert('close');
        }, 5000);
    }

    // Add hover effects to cards
    $('.card, .kpi-card').hover(
        function() { $(this).addClass('shadow-lg'); },
        function() { $(this).removeClass('shadow-lg'); }
    );

    // Initialize tooltips
    $('[title]').tooltip();

    // Auto-collapse sidebar on mobile
    if (window.innerWidth < 768) {
        $('#sidebar').addClass('collapsed');
        $('#main').addClass('collapsed');
        $('#toggleSidebar i').removeClass('bi-chevron-left').addClass('bi-chevron-right');
    }

    // Add loading animation to sections on first load
    $('.section').addClass('fade-in');
});

// Add easing function for smooth scrolling
$.easing.easeInOutCubic = function (x, t, b, c, d) {
    if ((t /= d / 2) < 1) return c / 2 * t * t * t + b;
    return c / 2 * ((t -= 2) * t * t + 2) + b;
};
</script>
</body>
</html>