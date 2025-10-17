<?php
session_start();
require_once 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'user' && $_SESSION['role'] !== 'manager')) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$branch = $_SESSION['branch'] ?? 'Unknown';
$role = $_SESSION['role'];

// Get filter parameters
$search_kpi = $_GET['search_kpi'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$target_period = $_GET['target_period'] ?? 'daily';
$quarter_filter = $_GET['quarter'] ?? '';
$month_filter = $_GET['month'] ?? '';
$week_filter = $_GET['week'] ?? '';
$year_filter = $_GET['year'] ?? date('Y');

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    exportToExcel($user_id, $username, $conn, $date_from, $date_to, $quarter_filter, $month_filter, $week_filter, $year_filter, $target_period);
    exit();
}

// Get available years from submissions
$years_sql = "SELECT DISTINCT YEAR(submission_date) as year FROM user_submissions WHERE user_id = $user_id ORDER BY year DESC";
$years_result = $conn->query($years_sql);
$available_years = [];
while ($row = $years_result->fetch_assoc()) {
    $available_years[] = $row['year'];
}
if (empty($available_years)) {
    $available_years[] = date('Y');
}

// Custom Quarter System
function getQuarterDates($quarter, $year) {
    switch ($quarter) {
        case 'Q1':
            return [
                'start' => "$year-07-01",
                'end' => "$year-09-30",
                'display' => "Q1 (Jul-Sep $year)"
            ];
        case 'Q2':
            return [
                'start' => "$year-10-01",
                'end' => "$year-12-31",
                'display' => "Q2 (Oct-Dec $year)"
            ];
        case 'Q3':
            $next_year = $year + 1;
            return [
                'start' => "$next_year-01-01",
                'end' => "$next_year-03-31",
                'display' => "Q3 (Jan-Mar $next_year)"
            ];
        case 'Q4':
            $next_year = $year + 1;
            return [
                'start' => "$next_year-04-01",
                'end' => "$next_year-06-30",
                'display' => "Q4 (Apr-Jun $next_year)"
            ];
        default:
            return null;
    }
}

// Get available quarters based on data
function getAvailableQuarters($user_id, $conn) {
    $quarters = [];
    $sql = "SELECT DISTINCT YEAR(submission_date) as year, MONTH(submission_date) as month 
            FROM user_submissions 
            WHERE user_id = $user_id 
            ORDER BY year, month";
    $result = $conn->query($sql);
    
    $quarter_years = [];
    while ($row = $result->fetch_assoc()) {
        $year = $row['year'];
        $month = $row['month'];
        
        // Determine quarter based on custom system
        if ($month >= 7 && $month <= 9) {
            $quarter_years[$year]['Q1'] = true;
        } elseif ($month >= 10 && $month <= 12) {
            $quarter_years[$year]['Q2'] = true;
        } elseif ($month >= 1 && $month <= 3) {
            $quarter_years[$year-1]['Q3'] = true; // Q3 is in next calendar year
        } elseif ($month >= 4 && $month <= 6) {
            $quarter_years[$year-1]['Q4'] = true; // Q4 is in next calendar year
        }
    }
    
    // Build quarters array
    foreach ($quarter_years as $year => $quarts) {
        foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $q) {
            if (isset($quarts[$q])) {
                $quarter_info = getQuarterDates($q, $year);
                $quarters["$q-$year"] = $quarter_info['display'];
            }
        }
    }
    
    return $quarters;
}

// Excel Export Function
function exportToExcel($user_id, $username, $conn, $date_from, $date_to, $quarter_filter, $month_filter, $week_filter, $year_filter, $target_period) {
    // Apply filters same as main function
    if (!empty($quarter_filter)) {
        list($quarter, $year) = explode('-', $quarter_filter);
        $quarter_dates = getQuarterDates($quarter, $year);
        if ($quarter_dates) {
            $date_from = $quarter_dates['start'];
            $date_to = $quarter_dates['end'];
        }
    }

    if (!empty($month_filter) && !empty($year_filter)) {
        $date_from = "$year_filter-$month_filter-01";
        $date_to = date("$year_filter-$month_filter-t", strtotime($date_from));
    }

    if (!empty($week_filter) && !empty($year_filter)) {
        $week_start = new DateTime();
        $week_start->setISODate($year_filter, $week_filter);
        $date_from = $week_start->format('Y-m-d');
        
        $week_end = clone $week_start;
        $week_end->modify('+6 days');
        $date_to = $week_end->format('Y-m-d');
    }

    // Get performance data for export
    $performance_data = [];
    $submissions_sql = "SELECT * FROM user_submissions WHERE user_id = $user_id AND status = 'approved'";

    if (!empty($date_from)) {
        $submissions_sql .= " AND submission_date >= '$date_from'";
    }
    if (!empty($date_to)) {
        $submissions_sql .= " AND submission_date <= '$date_to'";
    }

    $submissions_result = $conn->query($submissions_sql);

    if ($submissions_result && $submissions_result->num_rows > 0) {
        $kpi_aggregation = [];
        
        while($submission = $submissions_result->fetch_assoc()) {
            $kpi_id = $submission['kpi_id'];
            
            $kpi_sql = "SELECT * FROM kpis WHERE id = $kpi_id";
            $kpi_result = $conn->query($kpi_sql);
            $kpi = $kpi_result->fetch_assoc();
            
            if (!isset($kpi_aggregation[$kpi_id])) {
                $kpi_aggregation[$kpi_id] = [
                    'kpi_name' => $kpi['kpi_name'] ?? 'Unknown',
                    'kpi_category' => $kpi['kpi_category'] ?? 'Unknown',
                    'total_actual' => 0,
                    'submission_count' => 0,
                    'earliest_date' => $submission['submission_date'],
                    'latest_date' => $submission['submission_date'],
                ];
            }
            
            $kpi_aggregation[$kpi_id]['total_actual'] += $submission['value'];
            $kpi_aggregation[$kpi_id]['submission_count']++;
            
            if ($submission['submission_date'] < $kpi_aggregation[$kpi_id]['earliest_date']) {
                $kpi_aggregation[$kpi_id]['earliest_date'] = $submission['submission_date'];
            }
            if ($submission['submission_date'] > $kpi_aggregation[$kpi_id]['latest_date']) {
                $kpi_aggregation[$kpi_id]['latest_date'] = $submission['submission_date'];
            }
        }
        
        foreach ($kpi_aggregation as $kpi_id => $agg_data) {
            $target_sql = "SELECT * FROM staff_targets 
                          WHERE staff_name = '$username' AND kpi_id = $kpi_id 
                          ORDER BY quarter_start DESC 
                          LIMIT 1";
            $target_result = $conn->query($target_sql);
            $target = $target_result->fetch_assoc();
            
            $target_value = 0;
            if ($target) {
                switch ($target_period) {
                    case 'daily': 
                        $target_value = $target['daily_target'] ?? 0; 
                        if ($agg_data['submission_count'] > 1) {
                            $days_covered = ceil((strtotime($agg_data['latest_date']) - strtotime($agg_data['earliest_date'])) / (60 * 60 * 24)) + 1;
                            $target_value = ($target['daily_target'] ?? 0) * $days_covered;
                        }
                        break;
                    case 'weekly': 
                        $target_value = $target['weekly_target'] ?? 0; 
                        if ($agg_data['submission_count'] > 1) {
                            $weeks_covered = ceil((strtotime($agg_data['latest_date']) - strtotime($agg_data['earliest_date'])) / (60 * 60 * 24 * 7));
                            $target_value = ($target['weekly_target'] ?? 0) * max(1, $weeks_covered);
                        }
                        break;
                    case 'monthly': 
                        $target_value = $target['monthly_target'] ?? 0; 
                        break;
                    case 'quarterly': 
                        $target_value = $target['quarter_target'] ?? 0; 
                        break;
                    default: 
                        $target_value = $target['daily_target'] ?? 0;
                }
            }
            
            $actual_value = $agg_data['total_actual'];
            $achievement_rate = $target_value > 0 ? ($actual_value / $target_value) * 100 : 0;
            
            $display_date = $agg_data['earliest_date'];
            $period_display = 'Single Day';
            
            if ($agg_data['submission_count'] > 1) {
                if (!empty($quarter_filter)) {
                    $quarter_dates = getQuarterDates($quarter, $year);
                    $period_display = "Quarter " . strtoupper(str_replace('-', ' ', $quarter_filter));
                    $display_date = $quarter_dates['display'] ?? $quarter_filter;
                } elseif (!empty($month_filter)) {
                    $period_display = date('F Y', strtotime("$year_filter-$month_filter-01"));
                    $display_date = $period_display;
                } elseif (!empty($week_filter)) {
                    $period_display = "Week $week_filter of $year_filter";
                    $display_date = $period_display;
                } else {
                    $period_display = "Multiple Days";
                    $display_date = $agg_data['earliest_date'] . ' to ' . $agg_data['latest_date'];
                }
            }
            
            $performance_data[] = [
                'display_date' => $display_date,
                'period_display' => $period_display,
                'kpi_name' => $agg_data['kpi_name'],
                'kpi_category' => $agg_data['kpi_category'],
                'actual_value' => $actual_value,
                'target_value' => $target_value,
                'achievement_rate' => $achievement_rate,
                'achievement_status' => $achievement_rate >= 100 ? 'Above Target' : ($achievement_rate >= 70 ? 'On Track' : 'Needs Attention'),
                'submission_count' => $agg_data['submission_count'],
                'date_range' => $agg_data['earliest_date'] . ' to ' . $agg_data['latest_date']
            ];
        }
    }

    // Generate Excel file
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="performance_report_' . date('Y-m-d') . '.xls"');
    
    echo "<table border='1'>";
    echo "<tr><th colspan='8' style='background-color: #667eea; color: white; font-size: 16px; padding: 10px;'>Performance Report - " . htmlspecialchars($username) . "</th></tr>";
    echo "<tr><th colspan='8'>Generated on: " . date('Y-m-d H:i:s') . "</th></tr>";
    
    if (!empty($quarter_filter) || !empty($month_filter) || !empty($week_filter)) {
        $filter_info = "Filter: ";
        if (!empty($quarter_filter)) {
            $quarter_dates = getQuarterDates($quarter, $year);
            $filter_info .= $quarter_dates['display'] ?? $quarter_filter;
        } elseif (!empty($month_filter)) {
            $filter_info .= date('F Y', strtotime("$year_filter-$month_filter-01"));
        } elseif (!empty($week_filter)) {
            $filter_info .= "Week $week_filter of $year_filter";
        }
        echo "<tr><th colspan='8'>$filter_info</th></tr>";
    }
    
    echo "<tr style='background-color: #f8f9fa;'>";
    echo "<th>Period</th>";
    echo "<th>KPI Name</th>";
    echo "<th>Category</th>";
    echo "<th>Total Actual</th>";
    echo "<th>Target</th>";
    echo "<th>Submissions</th>";
    echo "<th>Achievement Rate (%)</th>";
    echo "<th>Status</th>";
    echo "</tr>";
    
    if (!empty($performance_data)) {
        foreach ($performance_data as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['display_date']) . "</td>";
            echo "<td>" . htmlspecialchars($row['kpi_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['kpi_category']) . "</td>";
            echo "<td>" . htmlspecialchars($row['actual_value']) . "</td>";
            echo "<td>" . htmlspecialchars($row['target_value']) . "</td>";
            echo "<td>" . $row['submission_count'] . "</td>";
            echo "<td>" . round($row['achievement_rate'], 1) . "%</td>";
            echo "<td>" . $row['achievement_status'] . "</td>";
            echo "</tr>";
        }
        
        // Add summary row
        $total_kpis = count($performance_data);
        $above_target = array_filter($performance_data, function($row) { return $row['achievement_rate'] >= 100; });
        $on_track = array_filter($performance_data, function($row) { return $row['achievement_rate'] >= 70 && $row['achievement_rate'] < 100; });
        $below_target = array_filter($performance_data, function($row) { return $row['achievement_rate'] < 70; });
        
        echo "<tr style='background-color: #e9ecef; font-weight: bold;'>";
        echo "<td colspan='3'>Summary</td>";
        echo "<td>Total KPIs: $total_kpis</td>";
        echo "<td>Above Target: " . count($above_target) . "</td>";
        echo "<td>On Track: " . count($on_track) . "</td>";
        echo "<td>Below Target: " . count($below_target) . "</td>";
        echo "<td></td>";
        echo "</tr>";
    } else {
        echo "<tr><td colspan='8' style='text-align: center;'>No performance data found</td></tr>";
    }
    
    echo "</table>";
}

$available_quarters = getAvailableQuarters($user_id, $conn);

// Apply quarter filter
if (!empty($quarter_filter)) {
    list($quarter, $year) = explode('-', $quarter_filter);
    $quarter_dates = getQuarterDates($quarter, $year);
    if ($quarter_dates) {
        $date_from = $quarter_dates['start'];
        $date_to = $quarter_dates['end'];
    }
}

// Apply month filter
if (!empty($month_filter) && !empty($year_filter)) {
    $date_from = "$year_filter-$month_filter-01";
    $date_to = date("$year_filter-$month_filter-t", strtotime($date_from));
}

// Apply week filter
if (!empty($week_filter) && !empty($year_filter)) {
    $week_start = new DateTime();
    $week_start->setISODate($year_filter, $week_filter);
    $date_from = $week_start->format('Y-m-d');
    
    $week_end = clone $week_start;
    $week_end->modify('+6 days');
    $date_to = $week_end->format('Y-m-d');
}

// Performance data with aggregation
$performance_data = [];
$aggregated_data = [];

// Build the base query
$submissions_sql = "SELECT * FROM user_submissions WHERE user_id = $user_id AND status = 'approved'";

// Add date filters if set
if (!empty($date_from)) {
    $submissions_sql .= " AND submission_date >= '$date_from'";
}
if (!empty($date_to)) {
    $submissions_sql .= " AND submission_date <= '$date_to'";
}

$submissions_result = $conn->query($submissions_sql);

if ($submissions_result && $submissions_result->num_rows > 0) {
    // First, aggregate data by KPI
    $kpi_aggregation = [];
    
    while($submission = $submissions_result->fetch_assoc()) {
        $kpi_id = $submission['kpi_id'];
        
        // Get KPI details
        $kpi_sql = "SELECT * FROM kpis WHERE id = $kpi_id";
        $kpi_result = $conn->query($kpi_sql);
        $kpi = $kpi_result->fetch_assoc();
        
        // Initialize aggregation for this KPI if not exists
        if (!isset($kpi_aggregation[$kpi_id])) {
            $kpi_aggregation[$kpi_id] = [
                'kpi_name' => $kpi['kpi_name'] ?? 'Unknown',
                'kpi_category' => $kpi['kpi_category'] ?? 'Unknown',
                'total_actual' => 0,
                'submission_count' => 0,
                'earliest_date' => $submission['submission_date'],
                'latest_date' => $submission['submission_date'],
                'submission_dates' => []
            ];
        }
        
        // Aggregate data
        $kpi_aggregation[$kpi_id]['total_actual'] += $submission['value'];
        $kpi_aggregation[$kpi_id]['submission_count']++;
        $kpi_aggregation[$kpi_id]['submission_dates'][] = $submission['submission_date'];
        
        // Update date range
        if ($submission['submission_date'] < $kpi_aggregation[$kpi_id]['earliest_date']) {
            $kpi_aggregation[$kpi_id]['earliest_date'] = $submission['submission_date'];
        }
        if ($submission['submission_date'] > $kpi_aggregation[$kpi_id]['latest_date']) {
            $kpi_aggregation[$kpi_id]['latest_date'] = $submission['submission_date'];
        }
    }
    
    // Process aggregated data
    foreach ($kpi_aggregation as $kpi_id => $agg_data) {
        // Get target for this KPI (most recent target)
        $target_sql = "SELECT * FROM staff_targets 
                      WHERE staff_name = '$username' AND kpi_id = $kpi_id 
                      ORDER BY quarter_start DESC 
                      LIMIT 1";
        $target_result = $conn->query($target_sql);
        $target = $target_result->fetch_assoc();
        
        // Calculate target value based on period and aggregation
        $target_value = 0;
        if ($target) {
            switch ($target_period) {
                case 'daily': 
                    $target_value = $target['daily_target'] ?? 0; 
                    // For daily view with multiple submissions, multiply target by number of days
                    if ($agg_data['submission_count'] > 1) {
                        $days_covered = ceil((strtotime($agg_data['latest_date']) - strtotime($agg_data['earliest_date'])) / (60 * 60 * 24)) + 1;
                        $target_value = ($target['daily_target'] ?? 0) * $days_covered;
                    }
                    break;
                case 'weekly': 
                    $target_value = $target['weekly_target'] ?? 0; 
                    // For weekly view, check how many weeks are covered
                    if ($agg_data['submission_count'] > 1) {
                        $weeks_covered = ceil((strtotime($agg_data['latest_date']) - strtotime($agg_data['earliest_date'])) / (60 * 60 * 24 * 7));
                        $target_value = ($target['weekly_target'] ?? 0) * max(1, $weeks_covered);
                    }
                    break;
                case 'monthly': 
                    $target_value = $target['monthly_target'] ?? 0; 
                    break;
                case 'quarterly': 
                    $target_value = $target['quarter_target'] ?? 0; 
                    break;
                default: 
                    $target_value = $target['daily_target'] ?? 0;
            }
        }
        
        // Calculate achievement
        $actual_value = $agg_data['total_actual'];
        $achievement_rate = $target_value > 0 ? ($actual_value / $target_value) * 100 : 0;
        
        // Determine display date based on period and aggregation
        $display_date = $agg_data['earliest_date'];
        $period_display = 'Single Day';
        
        if ($agg_data['submission_count'] > 1) {
            if (!empty($quarter_filter)) {
                $period_display = "Quarter " . strtoupper(str_replace('-', ' ', $quarter_filter));
                $display_date = $quarter_dates['display'] ?? $quarter_filter;
            } elseif (!empty($month_filter)) {
                $period_display = date('F Y', strtotime("$year_filter-$month_filter-01"));
                $display_date = $period_display;
            } elseif (!empty($week_filter)) {
                $period_display = "Week $week_filter of $year_filter";
                $display_date = $period_display;
            } else {
                $period_display = "Multiple Days";
                $display_date = $agg_data['earliest_date'] . ' to ' . $agg_data['latest_date'];
            }
        } else {
            // Single submission - use appropriate period display
            if (!empty($quarter_filter)) {
                $period_display = "Quarter " . strtoupper(str_replace('-', ' ', $quarter_filter));
            } elseif (!empty($month_filter)) {
                $period_display = date('F Y', strtotime("$year_filter-$month_filter-01"));
            } elseif (!empty($week_filter)) {
                $period_display = "Week $week_filter of $year_filter";
            }
        }
        
        $performance_data[] = [
            'display_date' => $display_date,
            'period_display' => $period_display,
            'kpi_id' => $kpi_id,
            'kpi_name' => $agg_data['kpi_name'],
            'kpi_category' => $agg_data['kpi_category'],
            'actual_value' => $actual_value,
            'target_value' => $target_value,
            'achievement_rate' => $achievement_rate,
            'achievement_class' => $achievement_rate >= 100 ? 'high' : ($achievement_rate >= 70 ? 'medium' : 'low'),
            'submission_count' => $agg_data['submission_count'],
            'date_range' => [
                'start' => $agg_data['earliest_date'],
                'end' => $agg_data['latest_date']
            ]
        ];
    }
}

// Calculate summary statistics
$summary_stats = [
    'total_kpis' => count($performance_data),
    'above_target' => 0,
    'on_track' => 0,
    'below_target' => 0,
    'total_achievement' => 0
];

foreach ($performance_data as $row) {
    $summary_stats['total_achievement'] += $row['achievement_rate'];
    
    if ($row['achievement_rate'] >= 100) {
        $summary_stats['above_target']++;
    } elseif ($row['achievement_rate'] >= 70) {
        $summary_stats['on_track']++;
    } else {
        $summary_stats['below_target']++;
    }
}

$summary_stats['average_achievement'] = $summary_stats['total_kpis'] > 0 ? 
    round($summary_stats['total_achievement'] / $summary_stats['total_kpis'], 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance vs Targets | <?= htmlspecialchars($username) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1f2937;
            --dark-light: #374151;
            --gray-light: #e5e7eb;
        }
        
        .performance-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        .stats-card {
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: none;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .achievement-high { color: var(--success); }
        .achievement-medium { color: var(--warning); }
        .achievement-low { color: var(--danger); }
        
        .progress {
            height: 8px;
            border-radius: 10px;
        }
        
        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .kpi-card {
            border-left: 4px solid var(--primary);
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .kpi-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--dark);
        }
        
        .badge-performance {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .nav-link.active {
            background: var(--primary) !important;
            color: white !important;
            border-color: var(--primary) !important;
        }
        
        .debug-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .period-badge {
            background: var(--primary-light);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
        }
        
        .quarter-system-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 0.9em;
        }
        
        .aggregation-badge {
            font-size: 0.7em;
            margin-left: 5px;
        }
        
        .date-range {
            font-size: 0.85em;
            color: #6c757d;
        }
        
        .export-btn {
            background: #10b981;
            border: none;
            color: white;
        }
        
        .export-btn:hover {
            background: #0da271;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chart-line me-2"></i>Performance Metrics
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="user_dashboard.php">
                    <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                </a>
                <a class="nav-link active" href="performance_view.php">
                    <i class="fas fa-bullseye me-1"></i>Performance vs Targets
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Header -->
    <div class="performance-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 fw-bold">
                        <i class="fas fa-bullseye me-2"></i>Performance vs Targets
                    </h1>
                    <p class="lead mb-0">Track your performance against set targets and goals</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="bg-white bg-opacity-10 p-3 rounded">
                        <h5 class="mb-1">Welcome, <?= htmlspecialchars($username) ?></h5>
                        <small class="opacity-75"><?= ucfirst($role) ?> • <?= htmlspecialchars($branch) ?> Branch</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Quarter System Info -->
        <div class="quarter-system-info">
            <h6><i class="fas fa-info-circle me-2"></i>Custom Fiscal Year Quarters</h6>
            <div class="row">
                <div class="col-md-3">
                    <strong>Q1:</strong> July - September
                </div>
                <div class="col-md-3">
                    <strong>Q2:</strong> October - December
                </div>
                <div class="col-md-3">
                    <strong>Q3:</strong> January - March
                </div>
                <div class="col-md-3">
                    <strong>Q4:</strong> April - June
                </div>
            </div>
            <small class="text-muted">Note: Q3 and Q4 span across calendar years</small>
        </div>

        <!-- Debug Information -->
        <div class="debug-info">
            <h6><i class="fas fa-bug me-2"></i>System Information</h6>
            <p class="mb-1">User ID: <?= $user_id ?></p>
            <p class="mb-1">Username: <?= htmlspecialchars($username) ?></p>
            <p class="mb-1">Found: <?= count($performance_data) ?> aggregated performance records</p>
            <?php if (!empty($quarter_filter)): ?>
                <?php 
                    list($quarter, $year) = explode('-', $quarter_filter);
                    $quarter_info = getQuarterDates($quarter, $year);
                ?>
                <p class="mb-0">Active Filter: <?= $quarter_info['display'] ?? $quarter_filter ?> (Aggregated View)</p>
            <?php elseif (!empty($month_filter)): ?>
                <p class="mb-0">Active Filter: <?= date('F Y', strtotime("$year_filter-$month_filter-01")) ?> (Aggregated View)</p>
            <?php elseif (!empty($week_filter)): ?>
                <p class="mb-0">Active Filter: Week <?= $week_filter ?> of <?= $year_filter ?> (Aggregated View)</p>
            <?php elseif (!empty($date_from) || !empty($date_to)): ?>
                <p class="mb-0">Active Filter: Custom Date Range (Aggregated View)</p>
            <?php else: ?>
                <p class="mb-0">Active Filter: All Time (Individual Submissions)</p>
            <?php endif; ?>
        </div>

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-primary bg-opacity-10 text-primary rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-bullseye fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1"><?= $summary_stats['total_kpis'] ?></h3>
                    <p class="text-muted mb-0">Total KPIs Tracked</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-success bg-opacity-10 text-success rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-trophy fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1"><?= $summary_stats['above_target'] ?></h3>
                    <p class="text-muted mb-0">Above Target</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-warning bg-opacity-10 text-warning rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-chart-line fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1"><?= $summary_stats['on_track'] ?></h3>
                    <p class="text-muted mb-0">On Track</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-danger bg-opacity-10 text-danger rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-exclamation-triangle fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1"><?= $summary_stats['below_target'] ?></h3>
                    <p class="text-muted mb-0">Needs Attention</p>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filter-section">
            <form id="performanceFilter" method="GET">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-search me-2"></i>Search KPIs
                        </label>
                        <input type="text" 
                               class="form-control" 
                               name="search_kpi" 
                               placeholder="Search by KPI name or category..."
                               value="<?= htmlspecialchars($search_kpi) ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-calendar me-2"></i>Year
                        </label>
                        <select class="form-select" name="year">
                            <?php foreach ($available_years as $year): ?>
                                <option value="<?= $year ?>" <?= $year == $year_filter ? 'selected' : '' ?>><?= $year ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-calendar-alt me-2"></i>Quarter
                        </label>
                        <select class="form-select" name="quarter">
                            <option value="">All Quarters</option>
                            <?php foreach ($available_quarters as $quarter_key => $quarter_display): ?>
                                <option value="<?= $quarter_key ?>" <?= $quarter_filter === $quarter_key ? 'selected' : '' ?>><?= $quarter_display ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-calendar-day me-2"></i>Month
                        </label>
                        <select class="form-select" name="month">
                            <option value="">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= sprintf('%02d', $m) ?>" <?= $month_filter == sprintf('%02d', $m) ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-chart-bar me-2"></i>Target Period
                        </label>
                        <select class="form-select" name="target_period">
                            <option value="daily" <?= $target_period === 'daily' ? 'selected' : '' ?>>Daily</option>
                            <option value="weekly" <?= $target_period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                            <option value="monthly" <?= $target_period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                            <option value="quarterly" <?= $target_period === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                        </select>
                    </div>
                </div>
                
                <!-- Custom Date Range -->
                <div class="row mt-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-calendar me-2"></i>Custom Date Range
                        </label>
                    </div>
                    <div class="col-md-2">
                        <input type="date" 
                               class="form-control" 
                               name="date_from" 
                               value="<?= htmlspecialchars($date_from) ?>"
                               placeholder="From Date">
                    </div>
                    <div class="col-md-2">
                        <input type="date" 
                               class="form-control" 
                               name="date_to" 
                               value="<?= htmlspecialchars($date_to) ?>"
                               placeholder="To Date">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100 me-2">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <a href="performance_view.php" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-times me-2"></i>Clear All
                        </a>
                    </div>
                </div>
                
                <!-- Active Filters Display -->
                <?php if (!empty($quarter_filter) || !empty($month_filter) || !empty($week_filter)): ?>
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="alert alert-info py-2">
                            <small>
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Active Period Filters:</strong>
                                <?php if (!empty($quarter_filter)): ?>
                                    <?php 
                                        list($quarter, $year) = explode('-', $quarter_filter);
                                        $quarter_info = getQuarterDates($quarter, $year);
                                    ?>
                                    <span class="period-badge"><?= $quarter_info['display'] ?? $quarter_filter ?> (Aggregated)</span>
                                <?php endif; ?>
                                <?php if (!empty($month_filter)): ?>
                                    <span class="period-badge"><?= date('F Y', strtotime("$year_filter-$month_filter-01")) ?> (Aggregated)</span>
                                <?php endif; ?>
                                <?php if (!empty($week_filter)): ?>
                                    <span class="period-badge">Week <?= $week_filter ?> of <?= $year_filter ?> (Aggregated)</span>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Performance Display Tabs -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <ul class="nav nav-pills" id="performanceTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="table-tab" data-bs-toggle="pill" data-bs-target="#table" type="button" role="tab">
                        <i class="fas fa-table me-2"></i>Table View
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="cards-tab" data-bs-toggle="pill" data-bs-target="#cards" type="button" role="tab">
                        <i class="fas fa-grip-vertical me-2"></i>Card View
                    </button>
                </li>
            </ul>
            
            <!-- Export Button -->
            <a href="?export=excel&<?= http_build_query($_GET) ?>" class="btn export-btn">
                <i class="fas fa-file-excel me-2"></i>Export to Excel
            </a>
        </div>

        <div class="tab-content" id="performanceTabsContent">
            <!-- Table View -->
            <div class="tab-pane fade show active" id="table" role="tabpanel">
                <div class="card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-table me-2"></i>Performance Details
                            <?php if (!empty($quarter_filter)): ?>
                                <?php 
                                    list($quarter, $year) = explode('-', $quarter_filter);
                                    $quarter_info = getQuarterDates($quarter, $year);
                                ?>
                                <span class="badge bg-primary ms-2"><?= $quarter_info['display'] ?? $quarter_filter ?> (Aggregated)</span>
                            <?php elseif (!empty($month_filter)): ?>
                                <span class="badge bg-primary ms-2"><?= date('F Y', strtotime("$year_filter-$month_filter-01")) ?> (Aggregated)</span>
                            <?php elseif (!empty($week_filter)): ?>
                                <span class="badge bg-primary ms-2">Week <?= $week_filter ?> of <?= $year_filter ?> (Aggregated)</span>
                            <?php endif; ?>
                        </h5>
                        <div class="table-controls">
                            <input type="text" id="tableSearch" class="form-control form-control-sm" placeholder="Search table...">
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="performanceTable">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Period</th>
                                        <th>KPI Name</th>
                                        <th>Category</th>
                                        <th>Total Actual</th>
                                        <th>Target</th>
                                        <th>Submissions</th>
                                        <th>Achievement</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($performance_data)): ?>
                                        <?php foreach ($performance_data as $row): ?>
                                            <tr>
                                                <td class="fw-semibold">
                                                    <?= htmlspecialchars($row['display_date']) ?>
                                                    <?php if ($row['submission_count'] > 1): ?>
                                                        <br><small class="date-range"><?= $row['submission_count'] ?> submissions aggregated</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($row['kpi_name']) ?></td>
                                                <td>
                                                    <span class="badge bg-light text-dark">
                                                        <?= htmlspecialchars($row['kpi_category']) ?>
                                                    </span>
                                                </td>
                                                <td class="fw-bold text-primary"><?= htmlspecialchars($row['actual_value']) ?></td>
                                                <td class="fw-semibold"><?= htmlspecialchars($row['target_value']) ?></td>
                                                <td>
                                                    <span class="badge bg-info"><?= $row['submission_count'] ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span class="fw-bold me-2 achievement-<?= $row['achievement_class'] ?>">
                                                            <?= round($row['achievement_rate'], 1) ?>%
                                                        </span>
                                                        <div class="progress flex-grow-1" style="width: 100px;">
                                                            <div class="progress-bar 
                                                                <?= $row['achievement_rate'] >= 100 ? 'bg-success' : 
                                                                   ($row['achievement_rate'] >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                                                style="width: <?= min($row['achievement_rate'], 100) ?>%">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($row['achievement_rate'] >= 100): ?>
                                                        <span class="badge-performance bg-success bg-opacity-10 text-success">
                                                            <i class="fas fa-check-circle me-1"></i>Above Target
                                                        </span>
                                                    <?php elseif ($row['achievement_rate'] >= 70): ?>
                                                        <span class="badge-performance bg-warning bg-opacity-10 text-warning">
                                                            <i class="fas fa-clock me-1"></i>On Track
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge-performance bg-danger bg-opacity-10 text-danger">
                                                            <i class="fas fa-exclamation-triangle me-1"></i>Needs Attention
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-5">
                                                <div class="text-muted">
                                                    <i class="fas fa-chart-bar fa-3x mb-3"></i>
                                                    <h4>No Performance Data Found</h4>
                                                    <p>No approved performance data matches your current filters.</p>
                                                    <?php if (!empty($quarter_filter) || !empty($month_filter) || !empty($week_filter)): ?>
                                                        <a href="performance_view.php" class="btn btn-primary mt-2">
                                                            <i class="fas fa-eye me-2"></i>View All Data
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card View -->
            <div class="tab-pane fade" id="cards" role="tabpanel">
                <div class="row" id="performanceCards">
                    <?php if (!empty($performance_data)): ?>
                        <?php foreach ($performance_data as $row): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card kpi-card h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <h6 class="card-title mb-0"><?= htmlspecialchars($row['kpi_name']) ?></h6>
                                            <span class="badge bg-light text-dark"><?= htmlspecialchars($row['kpi_category']) ?></span>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <small class="text-muted">Period: <?= htmlspecialchars($row['display_date']) ?></small>
                                            <?php if ($row['submission_count'] > 1): ?>
                                                <br><small class="text-info"><i class="fas fa-layer-group me-1"></i><?= $row['submission_count'] ?> submissions aggregated</small>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="row text-center mb-3">
                                            <div class="col-6">
                                                <div class="border-end">
                                                    <h4 class="text-primary mb-1"><?= htmlspecialchars($row['actual_value']) ?></h4>
                                                    <small class="text-muted">Total Actual</small>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <h4 class="text-dark mb-1"><?= htmlspecialchars($row['target_value']) ?></h4>
                                                <small class="text-muted">Target</small>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <small class="text-muted">Achievement Rate</small>
                                                <small class="fw-bold achievement-<?= $row['achievement_class'] ?>">
                                                    <?= round($row['achievement_rate'], 1) ?>%
                                                </small>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar 
                                                    <?= $row['achievement_rate'] >= 100 ? 'bg-success' : 
                                                       ($row['achievement_rate'] >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                                    style="width: <?= min($row['achievement_rate'], 100) ?>%">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center">
                                            <?php if ($row['achievement_rate'] >= 100): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success">
                                                    <i class="fas fa-trophy me-1"></i>Above Target
                                                </span>
                                            <?php elseif ($row['achievement_rate'] >= 70): ?>
                                                <span class="badge bg-warning bg-opacity-10 text-warning">
                                                    <i class="fas fa-chart-line me-1"></i>On Track
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger">
                                                    <i class="fas fa-exclamation-triangle me-1"></i>Needs Attention
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="text-center py-5">
                                <i class="fas fa-chart-bar fa-4x text-muted mb-3"></i>
                                <h4 class="text-muted">No Performance Data Found</h4>
                                <p class="text-muted">No approved performance data matches your current filters.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-dark text-light mt-5 py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">&copy; 2024 Performance Metrics System. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0">User: <?= htmlspecialchars($username) ?> | Role: <?= ucfirst($role) ?></p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
    $(document).ready(function() {
        // Simple table search functionality
        const $rows = $('#performanceTable tbody tr');
        $('#tableSearch').on('keyup', function() {
            const value = $(this).val().toLowerCase();
            $rows.filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
            });
        });

        // Auto-submit form when period filters change
        $('select[name="quarter"], select[name="month"], select[name="year"]').on('change', function() {
            $('#performanceFilter').submit();
        });
    });
    </script>
</body>
</html>