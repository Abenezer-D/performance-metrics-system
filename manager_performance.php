<?php
session_start();
require_once 'db.php';

// Ensure user is logged in as manager
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
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
$staff_filter = $_GET['staff'] ?? '';
$view_type = $_GET['view_type'] ?? 'staff'; // staff or branch

// Get available years from submissions
$years_sql = "SELECT DISTINCT YEAR(submission_date) as year FROM user_submissions ORDER BY year DESC";
$years_result = $conn->query($years_sql);
$available_years = [];
while ($row = $years_result->fetch_assoc()) {
    $available_years[] = $row['year'];
}
if (empty($available_years)) {
    $available_years[] = date('Y');
}

// Get staff members for this branch
$staff_sql = "SELECT u.id, u.username, u.branch 
              FROM users u 
              WHERE u.role = 'user' AND u.branch = '$branch' 
              ORDER BY u.username";
$staff_result = $conn->query($staff_sql);
$staff_members = [];
while ($row = $staff_result->fetch_assoc()) {
    $staff_members[$row['id']] = $row;
}

// Get all branches for branch view
$branches_sql = "SELECT DISTINCT branch FROM users WHERE branch IS NOT NULL AND branch != '' ORDER BY branch";
$branches_result = $conn->query($branches_sql);
$all_branches = [];
while ($row = $branches_result->fetch_assoc()) {
    $all_branches[] = $row['branch'];
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

// Performance data arrays
$staff_performance_data = [];
$branch_performance_data = [];

if ($view_type === 'staff') {
    // Get performance data for staff members
    foreach ($staff_members as $staff_id => $staff) {
        $staff_username = $staff['username'];
        
        // Build the base query for this staff member
        $submissions_sql = "SELECT * FROM user_submissions WHERE user_id = $staff_id AND status = 'approved'";
        
        // Add date filters if set
        if (!empty($date_from)) {
            $submissions_sql .= " AND submission_date >= '$date_from'";
        }
        if (!empty($date_to)) {
            $submissions_sql .= " AND submission_date <= '$date_to'";
        }
        
        $submissions_result = $conn->query($submissions_sql);
        $staff_kpis = [];
        $kpi_aggregation = []; // For aggregating data by KPI

        if ($submissions_result && $submissions_result->num_rows > 0) {
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
                        'latest_date' => $submission['submission_date']
                    ];
                }
                
                // Aggregate data
                $kpi_aggregation[$kpi_id]['total_actual'] += $submission['value'];
                $kpi_aggregation[$kpi_id]['submission_count']++;
                
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
                              WHERE staff_name = '$staff_username' AND kpi_id = $kpi_id 
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
                
                // Determine display date based on period
                $display_date = $agg_data['earliest_date'];
                if ($agg_data['earliest_date'] != $agg_data['latest_date']) {
                    $display_date = $agg_data['earliest_date'] . ' to ' . $agg_data['latest_date'];
                }
                
                $staff_kpis[] = [
                    'display_date' => $display_date,
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
        
        // Calculate staff summary
        $staff_summary = [
            'total_kpis' => count($staff_kpis),
            'above_target' => 0,
            'on_track' => 0,
            'below_target' => 0,
            'total_achievement' => 0
        ];
        
        foreach ($staff_kpis as $kpi) {
            $staff_summary['total_achievement'] += $kpi['achievement_rate'];
            
            if ($kpi['achievement_rate'] >= 100) {
                $staff_summary['above_target']++;
            } elseif ($kpi['achievement_rate'] >= 70) {
                $staff_summary['on_track']++;
            } else {
                $staff_summary['below_target']++;
            }
        }
        
        $staff_summary['average_achievement'] = $staff_summary['total_kpis'] > 0 ? 
            round($staff_summary['total_achievement'] / $staff_summary['total_kpis'], 1) : 0;
        
        $staff_performance_data[$staff_id] = [
            'staff_info' => $staff,
            'kpis' => $staff_kpis,
            'summary' => $staff_summary
        ];
    }
} else {
    // Branch performance view with aggregation
    foreach ($all_branches as $branch_name) {
        // Get all staff in this branch
        $branch_staff_sql = "SELECT id, username FROM users WHERE branch = '$branch_name' AND role = 'user'";
        $branch_staff_result = $conn->query($branch_staff_sql);
        $branch_staff_ids = [];
        while ($staff = $branch_staff_result->fetch_assoc()) {
            $branch_staff_ids[] = $staff['id'];
        }
        
        if (empty($branch_staff_ids)) continue;
        
        $staff_ids_str = implode(',', $branch_staff_ids);
        
        // Get branch submissions
        $branch_sql = "SELECT us.*, k.kpi_name, k.kpi_category 
                      FROM user_submissions us 
                      JOIN kpis k ON us.kpi_id = k.id 
                      WHERE us.user_id IN ($staff_ids_str) AND us.status = 'approved'";
        
        // Add date filters if set
        if (!empty($date_from)) {
            $branch_sql .= " AND us.submission_date >= '$date_from'";
        }
        if (!empty($date_to)) {
            $branch_sql .= " AND us.submission_date <= '$date_to'";
        }
        
        $branch_result = $conn->query($branch_sql);
        $branch_kpis = [];
        $kpi_aggregation = [];

        if ($branch_result && $branch_result->num_rows > 0) {
            while($submission = $branch_result->fetch_assoc()) {
                $kpi_id = $submission['kpi_id'];
                
                if (!isset($kpi_aggregation[$kpi_id])) {
                    $kpi_aggregation[$kpi_id] = [
                        'kpi_name' => $submission['kpi_name'],
                        'kpi_category' => $submission['kpi_category'],
                        'total_actual' => 0,
                        'submission_count' => 0,
                        'earliest_date' => $submission['submission_date'],
                        'latest_date' => $submission['submission_date']
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
            
            // Calculate branch achievements with aggregated data
            foreach ($kpi_aggregation as $kpi_id => $agg_data) {
                // Get average target for this KPI across all staff in branch
                $avg_target_sql = "SELECT AVG(
                    CASE 
                        WHEN '$target_period' = 'daily' THEN daily_target
                        WHEN '$target_period' = 'weekly' THEN weekly_target
                        WHEN '$target_period' = 'monthly' THEN monthly_target
                        WHEN '$target_period' = 'quarterly' THEN quarter_target
                        ELSE daily_target
                    END
                ) as avg_target
                FROM staff_targets st
                JOIN users u ON st.staff_name = u.username
                WHERE u.branch = '$branch_name' AND st.kpi_id = $kpi_id";
                
                $avg_target_result = $conn->query($avg_target_sql);
                $avg_target_row = $avg_target_result->fetch_assoc();
                $avg_target = $avg_target_row['avg_target'] ?? 0;
                
                // Adjust target for multiple submissions
                $adjusted_target = $avg_target;
                if ($target_period === 'daily' && $agg_data['submission_count'] > 1) {
                    $days_covered = ceil((strtotime($agg_data['latest_date']) - strtotime($agg_data['earliest_date'])) / (60 * 60 * 24)) + 1;
                    $adjusted_target = $avg_target * $days_covered;
                } elseif ($target_period === 'weekly' && $agg_data['submission_count'] > 1) {
                    $weeks_covered = ceil((strtotime($agg_data['latest_date']) - strtotime($agg_data['earliest_date'])) / (60 * 60 * 24 * 7));
                    $adjusted_target = $avg_target * max(1, $weeks_covered);
                }
                
                $achievement_rate = $adjusted_target > 0 ? ($agg_data['total_actual'] / $adjusted_target) * 100 : 0;
                
                $branch_kpis[] = [
                    'kpi_id' => $kpi_id,
                    'kpi_name' => $agg_data['kpi_name'],
                    'kpi_category' => $agg_data['kpi_category'],
                    'total_actual' => $agg_data['total_actual'],
                    'avg_target' => $adjusted_target,
                    'submission_count' => $agg_data['submission_count'],
                    'achievement_rate' => $achievement_rate,
                    'achievement_class' => $achievement_rate >= 100 ? 'high' : ($achievement_rate >= 70 ? 'medium' : 'low'),
                    'date_range' => $agg_data['earliest_date'] . ' to ' . $agg_data['latest_date']
                ];
            }
        }
        
        // Calculate branch summary
        $branch_summary = [
            'total_kpis' => count($branch_kpis),
            'above_target' => 0,
            'on_track' => 0,
            'below_target' => 0,
            'total_achievement' => 0,
            'total_staff' => count($branch_staff_ids)
        ];
        
        foreach ($branch_kpis as $kpi) {
            $branch_summary['total_achievement'] += $kpi['achievement_rate'];
            
            if ($kpi['achievement_rate'] >= 100) {
                $branch_summary['above_target']++;
            } elseif ($kpi['achievement_rate'] >= 70) {
                $branch_summary['on_track']++;
            } else {
                $branch_summary['below_target']++;
            }
        }
        
        $branch_summary['average_achievement'] = $branch_summary['total_kpis'] > 0 ? 
            round($branch_summary['total_achievement'] / $branch_summary['total_kpis'], 1) : 0;
        
        $branch_performance_data[$branch_name] = [
            'kpis' => $branch_kpis,
            'summary' => $branch_summary
        ];
    }
}

// Calculate overall summary for the view
$overall_summary = [
    'total_staff' => count($staff_members),
    'total_branches' => count($all_branches),
    'total_kpis_tracked' => 0,
    'overall_achievement' => 0
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Performance Dashboard | <?= htmlspecialchars($username) ?></title>
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
        
        .staff-card, .branch-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .staff-card-header, .branch-card-header {
            background: #f8f9fa;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            cursor: pointer;
        }
        
        .staff-card-body, .branch-card-body {
            padding: 1.5rem;
        }
        
        .view-type-badge {
            font-size: 0.7em;
            margin-left: 0.5rem;
        }
        
        .date-range {
            font-size: 0.85em;
            color: #6c757d;
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
                <a class="nav-link" href="manager_panel.php">
                    <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                </a>
                <a class="nav-link active" href="manager_performance.php">
                    <i class="fas fa-users me-1"></i>Team Performance
                </a>
                <a class="nav-link" href="manager_performance.php">
                    <i class="fas fa-user me-1"></i>My Performance
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
                        <i class="fas fa-users me-2"></i>Team Performance Dashboard
                    </h1>
                    <p class="lead mb-0">Monitor staff and branch performance metrics</p>
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
        <!-- View Type Selector -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-bar me-2"></i>
                                    Performance View: 
                                    <span class="badge bg-primary"><?= $view_type === 'staff' ? 'Staff Performance' : 'Branch Performance' ?></span>
                                </h5>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <a href="?view_type=staff<?= !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['view_type' => ''])) : '' ?>" 
                                       class="btn btn-<?= $view_type === 'staff' ? 'primary' : 'outline-primary' ?>">
                                        <i class="fas fa-user me-1"></i>Staff View
                                    </a>
                                    <a href="?view_type=branch<?= !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['view_type' => ''])) : '' ?>" 
                                       class="btn btn-<?= $view_type === 'branch' ? 'primary' : 'outline-primary' ?>">
                                        <i class="fas fa-building me-1"></i>Branch View
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filter-section">
            <form id="performanceFilter" method="GET">
                <input type="hidden" name="view_type" value="<?= $view_type ?>">
                
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
                    
                    <?php if ($view_type === 'staff'): ?>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-user me-2"></i>Staff Member
                        </label>
                        <select class="form-select" name="staff">
                            <option value="">All Staff</option>
                            <?php foreach ($staff_members as $staff_id => $staff): ?>
                                <option value="<?= $staff_id ?>" <?= $staff_filter == $staff_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($staff['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
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
                        <a href="manager_performance.php?view_type=<?= $view_type ?>" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-times me-2"></i>Clear All
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Staff Performance View -->
        <?php if ($view_type === 'staff'): ?>
        
        <!-- Staff Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-primary bg-opacity-10 text-primary rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-users fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1"><?= count($staff_members) ?></h3>
                    <p class="text-muted mb-0">Total Staff</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-success bg-opacity-10 text-success rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-trophy fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1">
                        <?= array_sum(array_column(array_column($staff_performance_data, 'summary'), 'above_target')) ?>
                    </h3>
                    <p class="text-muted mb-0">Above Target KPIs</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-warning bg-opacity-10 text-warning rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-chart-line fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1">
                        <?= array_sum(array_column(array_column($staff_performance_data, 'summary'), 'on_track')) ?>
                    </h3>
                    <p class="text-muted mb-0">On Track KPIs</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-danger bg-opacity-10 text-danger rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-exclamation-triangle fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1">
                        <?= array_sum(array_column(array_column($staff_performance_data, 'summary'), 'below_target')) ?>
                    </h3>
                    <p class="text-muted mb-0">Needs Attention</p>
                </div>
            </div>
        </div>

        <!-- Staff Performance Cards -->
        <div class="row">
            <div class="col-12">
                <h4 class="mb-3">
                    <i class="fas fa-user-check me-2"></i>Staff Performance
                    <?php if ($staff_filter && isset($staff_members[$staff_filter])): ?>
                        <span class="badge bg-info"><?= htmlspecialchars($staff_members[$staff_filter]['username']) ?></span>
                    <?php endif; ?>
                </h4>
                
                <?php if (empty($staff_performance_data)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No performance data found for the selected filters.
                    </div>
                <?php else: ?>
                    <?php foreach ($staff_performance_data as $staff_id => $staff_data): ?>
                        <?php if ($staff_filter && $staff_filter != $staff_id) continue; ?>
                        
                        <div class="staff-card">
                            <div class="staff-card-header" data-bs-toggle="collapse" data-bs-target="#staff-<?= $staff_id ?>">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <h6 class="mb-0">
                                            <i class="fas fa-user me-2"></i>
                                            <?= htmlspecialchars($staff_data['staff_info']['username']) ?>
                                            <span class="badge bg-secondary view-type-badge"><?= htmlspecialchars($staff_data['staff_info']['branch']) ?></span>
                                        </h6>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <span class="badge bg-light text-dark me-2">
                                            <?= $staff_data['summary']['total_kpis'] ?> KPIs
                                        </span>
                                        <span class="badge bg-success me-2">
                                            <?= $staff_data['summary']['above_target'] ?> Above
                                        </span>
                                        <span class="badge bg-warning me-2">
                                            <?= $staff_data['summary']['on_track'] ?> On Track
                                        </span>
                                        <span class="badge bg-danger">
                                            <?= $staff_data['summary']['below_target'] ?> Needs Attention
                                        </span>
                                        <span class="badge bg-primary ms-2">
                                            Avg: <?= $staff_data['summary']['average_achievement'] ?>%
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="collapse show" id="staff-<?= $staff_id ?>">
                                <div class="staff-card-body">
                                    <?php if (empty($staff_data['kpis'])): ?>
                                        <p class="text-muted mb-0">No approved submissions found for this staff member.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover">
                                                <thead>
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
                                                    <?php foreach ($staff_data['kpis'] as $kpi): ?>
                                                        <tr>
                                                            <td class="fw-semibold">
                                                                <?= htmlspecialchars($kpi['display_date']) ?>
                                                                <?php if ($kpi['submission_count'] > 1): ?>
                                                                    <br><small class="date-range"><?= $kpi['submission_count'] ?> submissions aggregated</small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?= htmlspecialchars($kpi['kpi_name']) ?></td>
                                                            <td>
                                                                <span class="badge bg-light text-dark">
                                                                    <?= htmlspecialchars($kpi['kpi_category']) ?>
                                                                </span>
                                                            </td>
                                                            <td class="fw-bold text-primary"><?= htmlspecialchars($kpi['actual_value']) ?></td>
                                                            <td class="fw-semibold"><?= htmlspecialchars($kpi['target_value']) ?></td>
                                                            <td>
                                                                <span class="badge bg-info"><?= $kpi['submission_count'] ?></span>
                                                            </td>
                                                            <td>
                                                                <div class="d-flex align-items-center">
                                                                    <span class="fw-bold me-2 achievement-<?= $kpi['achievement_class'] ?>">
                                                                        <?= round($kpi['achievement_rate'], 1) ?>%
                                                                    </span>
                                                                    <div class="progress flex-grow-1" style="width: 80px;">
                                                                        <div class="progress-bar 
                                                                            <?= $kpi['achievement_rate'] >= 100 ? 'bg-success' : 
                                                                               ($kpi['achievement_rate'] >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                                                            style="width: <?= min($kpi['achievement_rate'], 100) ?>%">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <?php if ($kpi['achievement_rate'] >= 100): ?>
                                                                    <span class="badge-performance bg-success bg-opacity-10 text-success">
                                                                        <i class="fas fa-check-circle me-1"></i>Above Target
                                                                    </span>
                                                                <?php elseif ($kpi['achievement_rate'] >= 70): ?>
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
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Branch Performance View -->
        <?php else: ?>

        <!-- Branch Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-primary bg-opacity-10 text-primary rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-building fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1"><?= count($all_branches) ?></h3>
                    <p class="text-muted mb-0">Total Branches</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-success bg-opacity-10 text-success rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-trophy fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1">
                        <?= array_sum(array_column(array_column($branch_performance_data, 'summary'), 'above_target')) ?>
                    </h3>
                    <p class="text-muted mb-0">Above Target KPIs</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-warning bg-opacity-10 text-warning rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-chart-line fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1">
                        <?= array_sum(array_column(array_column($branch_performance_data, 'summary'), 'on_track')) ?>
                    </h3>
                    <p class="text-muted mb-0">On Track KPIs</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-danger bg-opacity-10 text-danger rounded-circle mx-auto mb-3" style="width: 60px; height: 60px; line-height: 60px;">
                        <i class="fas fa-exclamation-triangle fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1">
                        <?= array_sum(array_column(array_column($branch_performance_data, 'summary'), 'below_target')) ?>
                    </h3>
                    <p class="text-muted mb-0">Needs Attention</p>
                </div>
            </div>
        </div>

        <!-- Branch Performance Cards -->
        <div class="row">
            <div class="col-12">
                <h4 class="mb-3"><i class="fas fa-building me-2"></i>Branch Performance</h4>
                
                <?php if (empty($branch_performance_data)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No branch performance data found for the selected filters.
                    </div>
                <?php else: ?>
                    <?php foreach ($branch_performance_data as $branch_name => $branch_data): ?>
                        <div class="branch-card">
                            <div class="branch-card-header" data-bs-toggle="collapse" data-bs-target="#branch-<?= md5($branch_name) ?>">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <h6 class="mb-0">
                                            <i class="fas fa-building me-2"></i>
                                            <?= htmlspecialchars($branch_name) ?>
                                            <span class="badge bg-secondary view-type-badge"><?= $branch_data['summary']['total_staff'] ?> staff</span>
                                        </h6>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <span class="badge bg-light text-dark me-2">
                                            <?= $branch_data['summary']['total_kpis'] ?> KPIs
                                        </span>
                                        <span class="badge bg-success me-2">
                                            <?= $branch_data['summary']['above_target'] ?> Above
                                        </span>
                                        <span class="badge bg-warning me-2">
                                            <?= $branch_data['summary']['on_track'] ?> On Track
                                        </span>
                                        <span class="badge bg-danger">
                                            <?= $branch_data['summary']['below_target'] ?> Needs Attention
                                        </span>
                                        <span class="badge bg-primary ms-2">
                                            Avg: <?= $branch_data['summary']['average_achievement'] ?>%
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="collapse show" id="branch-<?= md5($branch_name) ?>">
                                <div class="branch-card-body">
                                    <?php if (empty($branch_data['kpis'])): ?>
                                        <p class="text-muted mb-0">No performance data found for this branch.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>KPI Name</th>
                                                        <th>Category</th>
                                                        <th>Total Actual</th>
                                                        <th>Avg Target</th>
                                                        <th>Submissions</th>
                                                        <th>Achievement</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($branch_data['kpis'] as $kpi): ?>
                                                        <tr>
                                                            <td class="fw-bold"><?= htmlspecialchars($kpi['kpi_name']) ?></td>
                                                            <td>
                                                                <span class="badge bg-light text-dark">
                                                                    <?= htmlspecialchars($kpi['kpi_category']) ?>
                                                                </span>
                                                            </td>
                                                            <td class="fw-bold text-primary"><?= htmlspecialchars($kpi['total_actual']) ?></td>
                                                            <td class="fw-semibold"><?= round($kpi['avg_target'], 2) ?></td>
                                                            <td>
                                                                <span class="badge bg-info"><?= $kpi['submission_count'] ?></span>
                                                            </td>
                                                            <td>
                                                                <div class="d-flex align-items-center">
                                                                    <span class="fw-bold me-2 achievement-<?= $kpi['achievement_class'] ?>">
                                                                        <?= round($kpi['achievement_rate'], 1) ?>%
                                                                    </span>
                                                                    <div class="progress flex-grow-1" style="width: 80px;">
                                                                        <div class="progress-bar 
                                                                            <?= $kpi['achievement_rate'] >= 100 ? 'bg-success' : 
                                                                               ($kpi['achievement_rate'] >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                                                            style="width: <?= min($kpi['achievement_rate'], 100) ?>%">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <?php if ($kpi['achievement_rate'] >= 100): ?>
                                                                    <span class="badge-performance bg-success bg-opacity-10 text-success">
                                                                        <i class="fas fa-check-circle me-1"></i>Above Target
                                                                    </span>
                                                                <?php elseif ($kpi['achievement_rate'] >= 70): ?>
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
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <footer class="bg-dark text-light mt-5 py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">&copy; 2024 Performance Metrics System. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0">Manager: <?= htmlspecialchars($username) ?> | Branch: <?= htmlspecialchars($branch) ?></p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
    $(document).ready(function() {
        // Auto-submit form when filters change
        $('select[name="staff"], select[name="month"], select[name="year"], select[name="target_period"]').on('change', function() {
            $('#performanceFilter').submit();
        });

        // Expand/collapse all staff/branch cards
        $('.expand-all').on('click', function() {
            $('.collapse').collapse('show');
        });

        $('.collapse-all').on('click', function() {
            $('.collapse').collapse('hide');
        });
    });
    </script>
</body>
</html>