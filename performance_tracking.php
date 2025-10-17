<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include your database configuration
require_once 'config.php';

// Check if user is logged in as district user
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'district') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];

// Debug: Check if we're getting any data
echo "<!-- Debug: Session user_id = $user_id, role = $role -->\n";

// Get filter parameters with safe defaults
$search_kpi = $_GET['search_kpi'] ?? '';
$search_staff = $_GET['search_staff'] ?? '';
$search_branch = $_GET['search_branch'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$target_period = $_GET['target_period'] ?? 'monthly';
$view_type = $_GET['view_type'] ?? 'district';
$quarter_filter = $_GET['quarter'] ?? '';
$quarter_year_filter = $_GET['quarter_year'] ?? date('Y');

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

// Apply quarter filter if selected
if (!empty($quarter_filter) && !empty($quarter_year_filter)) {
    $quarter_dates = getQuarterDates($quarter_filter, $quarter_year_filter);
    if ($quarter_dates) {
        $date_from = $quarter_dates['start'];
        $date_to = $quarter_dates['end'];
    }
}

// Debug output
echo "<!-- Debug: date_from = $date_from, date_to = $date_to -->\n";

// Build WHERE conditions for queries
$where_conditions = ["us.status = 'approved'"];
$params = [];
$types = '';

// Add search filters
if (!empty($search_kpi)) {
    $where_conditions[] = "k.kpi_name LIKE ?";
    $params[] = "%$search_kpi%";
    $types .= 's';
}

if (!empty($search_staff)) {
    $where_conditions[] = "u.username LIKE ?";
    $params[] = "%$search_staff%";
    $types .= 's';
}

if (!empty($search_branch)) {
    $where_conditions[] = "us.branch LIKE ?";
    $params[] = "%$search_branch%";
    $types .= 's';
}

// Add date filters
if (!empty($date_from)) {
    $where_conditions[] = "us.submission_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = "us.submission_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

// Combine all conditions
$where_sql = implode(' AND ', $where_conditions);

// Debug the SQL conditions
echo "<!-- Debug: WHERE SQL = $where_sql -->\n";

// Performance data arrays
$district_performance_data = [];
$branch_performance_data = [];
$staff_performance_data = [];

// Get all branches with performance data
$branches_query = "
    SELECT DISTINCT us.branch 
    FROM user_submissions us 
    WHERE us.branch IS NOT NULL AND us.branch != ''
    ORDER BY us.branch
";
$branches_result = $conn->query($branches_query);
$all_branches = [];
if ($branches_result) {
    while ($row = $branches_result->fetch_assoc()) {
        $all_branches[] = $row['branch'];
    }
} else {
    echo "<!-- Debug: Branches query failed: " . $conn->error . " -->\n";
}

// Get all staff with performance data
$staff_query = "
    SELECT DISTINCT u.id, u.username, us.branch
    FROM user_submissions us
    JOIN users u ON us.user_id = u.id
    JOIN kpis k ON us.kpi_id = k.id
    WHERE $where_sql
    ORDER BY us.branch, u.username
";

$all_staff = [];
if ($stmt = $conn->prepare($staff_query)) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $all_staff[$row['id']] = $row;
        }
    } else {
        echo "<!-- Debug: Staff query failed -->\n";
    }
    $stmt->close();
} else {
    echo "<!-- Debug: Staff query prepare failed: " . $conn->error . " -->\n";
}

// Function to get target value
// Function to get target value - IMPROVED VERSION
function getTargetValue($staff_name, $kpi_id, $target_period, $quarter_start, $quarter_end, $conn) {
    $target_column = '';
    switch ($target_period) {
        case 'daily':
            $target_column = 'daily_target';
            break;
        case 'weekly':
            $target_column = 'weekly_target';
            break;
        case 'monthly':
            $target_column = 'monthly_target';
            break;
        case 'quarterly':
            $target_column = 'quarter_target';
            break;
        default:
            $target_column = 'daily_target';
    }
    
    echo "<!-- Debug: Looking for target - Staff: $staff_name, KPI: $kpi_id, Period: $target_period -->\n";
    
    // First try exact match with quarter dates
    $target_query = "
        SELECT $target_column as target_value 
        FROM staff_targets 
        WHERE staff_name = ? AND kpi_id = ? 
        AND quarter_start = ? AND quarter_end = ?
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($target_query);
    if ($stmt) {
        $stmt->bind_param("siss", $staff_name, $kpi_id, $quarter_start, $quarter_end);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $target_value = $row['target_value'] ?? 0;
            $stmt->close();
            echo "<!-- Debug: Found exact target: $target_value -->\n";
            return $target_value;
        }
        $stmt->close();
    }
    
    // Fallback: get any target for this staff and KPI (most recent)
    $fallback_query = "
        SELECT $target_column as target_value 
        FROM staff_targets 
        WHERE staff_name = ? AND kpi_id = ? 
        ORDER BY quarter_start DESC 
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($fallback_query);
    if ($stmt) {
        $stmt->bind_param("si", $staff_name, $kpi_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $target_value = $row['target_value'] ?? 0;
            $stmt->close();
            echo "<!-- Debug: Found fallback target: $target_value -->\n";
            return $target_value;
        }
        $stmt->close();
    }
    
    echo "<!-- Debug: No target found, using default 0 -->\n";
    return 0;
}

// BRANCH PERFORMANCE DATA
if ($view_type === 'district' || $view_type === 'branch') {
    $branch_perf_query = "
        SELECT 
            us.branch,
            k.id as kpi_id,
            k.kpi_name,
            k.kpi_category,
            COUNT(us.id) as submission_count,
            AVG(us.value) as avg_actual,
            COUNT(DISTINCT us.user_id) as staff_count,
            u.username as staff_name
        FROM user_submissions us
        JOIN kpis k ON us.kpi_id = k.id
        JOIN users u ON us.user_id = u.id
        WHERE $where_sql
        GROUP BY us.branch, k.id, k.kpi_name, k.kpi_category, u.username
        ORDER BY us.branch, k.kpi_name
    ";
    
    if ($stmt = $conn->prepare($branch_perf_query)) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $branch_perf_result = $stmt->get_result();
        
        if ($branch_perf_result && $branch_perf_result->num_rows > 0) {
            echo "<!-- Debug: Found " . $branch_perf_result->num_rows . " branch performance records -->\n";
            
            while ($row = $branch_perf_result->fetch_assoc()) {
                $branch = $row['branch'];
                $kpi_id = $row['kpi_id'];
                $staff_name = $row['staff_name'];
                
                if (!isset($branch_performance_data[$branch])) {
                    $branch_performance_data[$branch] = [
                        'kpis' => [],
                        'summary' => [
                            'total_kpis' => 0,
                            'above_target' => 0,
                            'on_track' => 0,
                            'below_target' => 0,
                            'total_achievement' => 0,
                            'total_staff' => $row['staff_count'],
                            'total_submissions' => 0
                        ]
                    ];
                }
                
                $target_value = getTargetValue($staff_name, $kpi_id, $target_period, $date_from, $date_to, $conn);
                $actual_value = round($row['avg_actual'], 2);
                
                $achievement_rate = 0;
                if ($target_value > 0) {
                    $achievement_rate = min(round(($actual_value / $target_value) * 100, 2), 150);
                }
                
                $achievement_class = $achievement_rate >= 100 ? 'high' : ($achievement_rate >= 70 ? 'medium' : 'low');
                
                $branch_performance_data[$branch]['kpis'][] = [
                    'kpi_id' => $kpi_id,
                    'kpi_name' => $row['kpi_name'],
                    'kpi_category' => $row['kpi_category'],
                    'avg_actual' => $actual_value,
                    'target_value' => $target_value,
                    'submission_count' => $row['submission_count'],
                    'staff_name' => $staff_name,
                    'achievement_rate' => $achievement_rate,
                    'achievement_class' => $achievement_class
                ];
                
                // Update branch summary
                $branch_performance_data[$branch]['summary']['total_kpis']++;
                $branch_performance_data[$branch]['summary']['total_submissions'] += $row['submission_count'];
                $branch_performance_data[$branch]['summary']['total_achievement'] += $achievement_rate;
                
                if ($achievement_rate >= 100) {
                    $branch_performance_data[$branch]['summary']['above_target']++;
                } elseif ($achievement_rate >= 70) {
                    $branch_performance_data[$branch]['summary']['on_track']++;
                } else {
                    $branch_performance_data[$branch]['summary']['below_target']++;
                }
            }
            
            // Calculate average achievement for each branch
            foreach ($branch_performance_data as $branch => $data) {
                $summary = &$branch_performance_data[$branch]['summary'];
                $summary['average_achievement'] = $summary['total_kpis'] > 0 ? 
                    round($summary['total_achievement'] / $summary['total_kpis'], 1) : 0;
            }
        } else {
            echo "<!-- Debug: No branch performance data found -->\n";
        }
        $stmt->close();
    } else {
        echo "<!-- Debug: Branch performance query prepare failed: " . $conn->error . " -->\n";
    }
}

// STAFF PERFORMANCE DATA
// STAFF PERFORMANCE DATA - CORRECTED QUERY
if ($view_type === 'district' || $view_type === 'staff') {
    $staff_perf_query = "
        SELECT 
            u.id as staff_id,
            u.username,
            us.branch,
            k.id as kpi_id,
            k.kpi_name,
            k.kpi_category,
            us.submission_date,
            us.value as actual_value,
            us.kpi_id,
            COUNT(us.id) as submission_count
        FROM user_submissions us
        JOIN users u ON us.user_id = u.id
        JOIN kpis k ON us.kpi_id = k.id
        WHERE $where_sql
        GROUP BY u.id, u.username, us.branch, k.id, k.kpi_name, k.kpi_category, us.submission_date, us.value
        ORDER BY us.branch, u.username, k.kpi_name
    ";
    
    echo "<!-- Debug: Staff Query = $staff_perf_query -->\n";
    echo "<!-- Debug: Query Params = " . print_r($params, true) . " -->\n";
    
    if ($stmt = $conn->prepare($staff_perf_query)) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $staff_perf_result = $stmt->get_result();
        
        if ($staff_perf_result) {
            echo "<!-- Debug: Staff result num_rows = " . $staff_perf_result->num_rows . " -->\n";
            
            if ($staff_perf_result->num_rows > 0) {
                while ($row = $staff_perf_result->fetch_assoc()) {
                    echo "<!-- Debug: Processing staff row - " . $row['username'] . " - " . $row['kpi_name'] . " -->\n";
                    
                    $staff_id = $row['staff_id'];
                    $kpi_id = $row['kpi_id'];
                    $staff_name = $row['username'];
                    
                    if (!isset($staff_performance_data[$staff_id])) {
                        $staff_performance_data[$staff_id] = [
                            'staff_info' => [
                                'username' => $staff_name,
                                'branch' => $row['branch'],
                                'user_id' => $staff_id
                            ],
                            'kpis' => [],
                            'summary' => [
                                'total_kpis' => 0,
                                'above_target' => 0,
                                'on_track' => 0,
                                'below_target' => 0,
                                'total_achievement' => 0,
                                'total_submissions' => 0
                            ]
                        ];
                    }
                    
                    // Get target value for this staff and KPI
                    $target_value = getTargetValue($staff_name, $kpi_id, $target_period, $date_from, $date_to, $conn);
                    $actual_value = round($row['actual_value'], 2);
                    
                    // Calculate achievement rate
                    $achievement_rate = 0;
                    if ($target_value > 0) {
                        $achievement_rate = min(round(($actual_value / $target_value) * 100, 2), 150);
                    }
                    
                    $achievement_class = $achievement_rate >= 100 ? 'high' : ($achievement_rate >= 70 ? 'medium' : 'low');
                    
                    $staff_performance_data[$staff_id]['kpis'][] = [
                        'kpi_id' => $kpi_id,
                        'kpi_name' => $row['kpi_name'],
                        'kpi_category' => $row['kpi_category'],
                        'submission_date' => $row['submission_date'],
                        'actual_value' => $actual_value,
                        'target_value' => $target_value,
                        'achievement_rate' => $achievement_rate,
                        'achievement_class' => $achievement_class,
                        'submission_count' => $row['submission_count']
                    ];
                    
                    // Update staff summary
                    $staff_performance_data[$staff_id]['summary']['total_kpis']++;
                    $staff_performance_data[$staff_id]['summary']['total_submissions'] += $row['submission_count'];
                    $staff_performance_data[$staff_id]['summary']['total_achievement'] += $achievement_rate;
                    
                    if ($achievement_rate >= 100) {
                        $staff_performance_data[$staff_id]['summary']['above_target']++;
                    } elseif ($achievement_rate >= 70) {
                        $staff_performance_data[$staff_id]['summary']['on_track']++;
                    } else {
                        $staff_performance_data[$staff_id]['summary']['below_target']++;
                    }
                }
                
                // Calculate average achievement for each staff
                foreach ($staff_performance_data as $staff_id => $data) {
                    $summary = &$staff_performance_data[$staff_id]['summary'];
                    $summary['average_achievement'] = $summary['total_kpis'] > 0 ? 
                        round($summary['total_achievement'] / $summary['total_kpis'], 1) : 0;
                    
                    echo "<!-- Debug: Staff $staff_name - Avg Achievement: " . $summary['average_achievement'] . "% -->\n";
                }
            } else {
                echo "<!-- Debug: No staff performance records found -->\n";
            }
        } else {
            echo "<!-- Debug: Staff query execution failed: " . $stmt->error . " -->\n";
        }
        $stmt->close();
    } else {
        echo "<!-- Debug: Staff performance query prepare failed: " . $conn->error . " -->\n";
    }
}

// Calculate district summary
$district_summary = [
    'total_branches' => count($all_branches),
    'total_staff' => count($all_staff),
    'total_kpis_tracked' => 0,
    'overall_achievement' => 0,
    'above_target' => 0,
    'on_track' => 0,
    'below_target' => 0
];

// Update district summary from branch data
foreach ($branch_performance_data as $branch_data) {
    $district_summary['total_kpis_tracked'] += $branch_data['summary']['total_kpis'];
    $district_summary['above_target'] += $branch_data['summary']['above_target'];
    $district_summary['on_track'] += $branch_data['summary']['on_track'];
    $district_summary['below_target'] += $branch_data['summary']['below_target'];
    $district_summary['overall_achievement'] += $branch_data['summary']['average_achievement'];
}

$district_summary['average_achievement'] = count($branch_performance_data) > 0 ? 
    round($district_summary['overall_achievement'] / count($branch_performance_data), 1) : 0;

// Debug output
echo "<!-- Debug: District summary - Branches: " . $district_summary['total_branches'] . ", Staff: " . $district_summary['total_staff'] . " -->\n";
echo "<!-- Debug: Branch performance data count: " . count($branch_performance_data) . " -->\n";
echo "<!-- Debug: Staff performance data count: " . count($staff_performance_data) . " -->\n";

// Get available years for quarter selection
$available_years = [];
$current_year = date('Y');
for ($year = $current_year - 2; $year <= $current_year + 1; $year++) {
    $available_years[] = $year;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Tracking | <?= htmlspecialchars($username) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Your existing CSS styles remain the same */
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
        
        .period-badge {
            background: var(--primary-light);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
        }
        
        .district-card, .branch-card, .staff-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .district-card-header, .branch-card-header, .staff-card-header {
            background: #f8f9fa;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            cursor: pointer;
        }
        
        .district-card-body, .branch-card-body, .staff-card-body {
            padding: 1.5rem;
        }
        
        .view-type-badge {
            font-size: 0.7em;
            margin-left: 0.5rem;
        }
        
        .search-highlight {
            background-color: #fff3cd;
            font-weight: bold;
            padding: 2px 4px;
            border-radius: 3px;
        }
        
        .stats-icon {
            width: 60px;
            height: 60px;
            line-height: 60px;
            border-radius: 50%;
            margin: 0 auto 1rem;
        }
        
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .target-comparison {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .user-id-badge {
            font-size: 0.7rem;
            background: #e9ecef;
            color: #495057;
        }
        
        .quarter-display {
            background: var(--primary-light);
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        .no-data {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        
        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chart-line me-2"></i>District Performance Tracking
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="district_dashboard.php">
                    <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                </a>
                <a class="nav-link active" href="performance_tracking.php">
                    <i class="fas fa-chart-line me-1"></i>Performance Tracking
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
                        <i class="fas fa-chart-line me-2"></i>District Performance Tracking
                    </h1>
                    <p class="lead mb-0">Monitor district-wide performance metrics vs targets</p>
                    <?php if (!empty($quarter_filter)): ?>
                        <?php 
                        $quarter_dates = getQuarterDates($quarter_filter, $quarter_year_filter);
                        if ($quarter_dates): 
                        ?>
                        <div class="quarter-display mt-2">
                            <i class="fas fa-calendar me-2"></i>
                            <?= $quarter_dates['display'] ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="bg-white bg-opacity-10 p-3 rounded">
                        <h5 class="mb-1">Welcome, <?= htmlspecialchars($username) ?></h5>
                        <small class="opacity-75"><?= ucfirst($role) ?> • District Management</small>
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
                                    <span class="badge bg-primary"><?= 
                                        $view_type === 'district' ? 'District Overview' : 
                                        ($view_type === 'branch' ? 'Branch Performance' : 'Staff Performance')
                                    ?></span>
                                </h5>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <a href="?view_type=district&<?= http_build_query(array_diff_key($_GET, ['view_type' => ''])) ?>" 
                                       class="btn btn-<?= $view_type === 'district' ? 'primary' : 'outline-primary' ?>">
                                        <i class="fas fa-globe me-1"></i>District
                                    </a>
                                    <a href="?view_type=branch&<?= http_build_query(array_diff_key($_GET, ['view_type' => ''])) ?>" 
                                       class="btn btn-<?= $view_type === 'branch' ? 'primary' : 'outline-primary' ?>">
                                        <i class="fas fa-building me-1"></i>Branch
                                    </a>
                                    <a href="?view_type=staff&<?= http_build_query(array_diff_key($_GET, ['view_type' => ''])) ?>" 
                                       class="btn btn-<?= $view_type === 'staff' ? 'primary' : 'outline-primary' ?>">
                                        <i class="fas fa-users me-1"></i>Staff
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
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-search me-2"></i>Search KPI
                        </label>
                        <input type="text" 
                               class="form-control" 
                               name="search_kpi" 
                               placeholder="KPI name..."
                               value="<?= htmlspecialchars($search_kpi) ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-user me-2"></i>Search Staff
                        </label>
                        <input type="text" 
                               class="form-control" 
                               name="search_staff" 
                               placeholder="Staff name..."
                               value="<?= htmlspecialchars($search_staff) ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-building me-2"></i>Search Branch
                        </label>
                        <input type="text" 
                               class="form-control" 
                               name="search_branch" 
                               placeholder="Branch name..."
                               value="<?= htmlspecialchars($search_branch) ?>">
                    </div>
                    
                    <!-- Quarter Selection -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-calendar-alt me-2"></i>Select Quarter
                        </label>
                        <div class="row g-2">
                            <div class="col">
                                <select class="form-select" name="quarter">
                                    <option value="">Select Quarter</option>
                                    <option value="Q1" <?= $quarter_filter === 'Q1' ? 'selected' : '' ?>>Q1 (Jul-Sep)</option>
                                    <option value="Q2" <?= $quarter_filter === 'Q2' ? 'selected' : '' ?>>Q2 (Oct-Dec)</option>
                                    <option value="Q3" <?= $quarter_filter === 'Q3' ? 'selected' : '' ?>>Q3 (Jan-Mar)</option>
                                    <option value="Q4" <?= $quarter_filter === 'Q4' ? 'selected' : '' ?>>Q4 (Apr-Jun)</option>
                                </select>
                            </div>
                            <div class="col">
                                <select class="form-select" name="quarter_year">
                                    <option value="">Select Year</option>
                                    <?php foreach ($available_years as $year): ?>
                                        <option value="<?= $year ?>" <?= $quarter_year_filter == $year ? 'selected' : '' ?>><?= $year ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-bullseye me-2"></i>Target Period
                        </label>
                        <select class="form-select" name="target_period">
                            <option value="daily" <?= $target_period === 'daily' ? 'selected' : '' ?>>Daily</option>
                            <option value="weekly" <?= $target_period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                            <option value="monthly" <?= $target_period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                            <option value="quarterly" <?= $target_period === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                        </select>
                    </div>
                </div>
                
                <!-- Date Range (hidden when quarter is selected) -->
                <div class="row mt-3 <?= !empty($quarter_filter) ? 'd-none' : '' ?>" id="dateRangeSection">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-calendar me-2"></i>Custom Date Range
                        </label>
                    </div>
                    <div class="col-md-2">
                        <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
                    </div>
                    <div class="col-md-2">
                        <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                        <a href="performance_tracking.php?view_type=<?= $view_type ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Clear Filters
                        </a>
                    </div>
                    <div class="col-md-6 text-end">
                        <span class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php if (!empty($quarter_filter)): ?>
                                Quarter: <?= $quarter_filter ?> <?= $quarter_year_filter ?>
                            <?php else: ?>
                                Target Period: <?= strtoupper($target_period) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </form>
        </div>

        <!-- District Overview View -->
        <?php if ($view_type === 'district'): ?>
        
        <!-- District Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fas fa-building fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1"><?= $district_summary['total_branches'] ?></h3>
                    <p class="text-muted mb-0">Total Branches</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-success bg-opacity-10 text-success">
                        <i class="fas fa-users fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1"><?= $district_summary['total_staff'] ?></h3>
                    <p class="text-muted mb-0">Active Staff</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-chart-line fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1"><?= $district_summary['total_kpis_tracked'] ?></h3>
                    <p class="text-muted mb-0">KPIs Tracked</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card bg-white p-4 text-center">
                    <div class="stats-icon bg-info bg-opacity-10 text-info">
                        <i class="fas fa-trophy fs-4"></i>
                    </div>
                    <h3 class="text-dark mb-1"><?= $district_summary['average_achievement'] ?>%</h3>
                    <p class="text-muted mb-0">Avg vs Target</p>
                </div>
            </div>
        </div>

        <!-- Branch Performance -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-building me-2"></i>
                            Branch Performance Summary
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($branch_performance_data)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Branch</th>
                                            <th>Staff Count</th>
                                            <th>KPIs Tracked</th>
                                            <th>Above Target</th>
                                            <th>On Track</th>
                                            <th>Below Target</th>
                                            <th>Avg Achievement</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($branch_performance_data as $branch => $data): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($branch) ?></strong></td>
                                                <td><?= $data['summary']['total_staff'] ?></td>
                                                <td><?= $data['summary']['total_kpis'] ?></td>
                                                <td class="text-success"><?= $data['summary']['above_target'] ?></td>
                                                <td class="text-warning"><?= $data['summary']['on_track'] ?></td>
                                                <td class="text-danger"><?= $data['summary']['below_target'] ?></td>
                                                <td>
                                                    <span class="fw-bold achievement-<?= 
                                                        $data['summary']['average_achievement'] >= 100 ? 'high' : 
                                                        ($data['summary']['average_achievement'] >= 70 ? 'medium' : 'low')
                                                    ?>">
                                                        <?= $data['summary']['average_achievement'] ?>%
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($data['summary']['average_achievement'] >= 100): ?>
                                                        <span class="badge bg-success">Excellent</span>
                                                    <?php elseif ($data['summary']['average_achievement'] >= 70): ?>
                                                        <span class="badge bg-warning">Good</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Needs Improvement</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-chart-bar text-muted"></i>
                                <h4>No Performance Data Found</h4>
                                <p class="text-muted">No branch performance data available for the selected filters.</p>
                                <a href="performance_tracking.php?view_type=district" class="btn btn-primary">
                                    <i class="fas fa-refresh me-2"></i>Reset Filters
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($view_type === 'branch'): ?>
        
        <!-- Branch Performance View -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-building me-2"></i>
                            Detailed Branch Performance
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($branch_performance_data)): ?>
                            <?php foreach ($branch_performance_data as $branch => $data): ?>
                                <div class="branch-card">
                                    <div class="branch-card-header" data-bs-toggle="collapse" data-bs-target="#branch-<?= md5($branch) ?>">
                                        <h6 class="mb-0">
                                            <i class="fas fa-building me-2"></i>
                                            <?= htmlspecialchars($branch) ?>
                                            <span class="badge bg-primary ms-2"><?= $data['summary']['total_staff'] ?> staff</span>
                                            <span class="badge bg-secondary"><?= $data['summary']['total_kpis'] ?> KPIs</span>
                                        </h6>
                                    </div>
                                    <div class="collapse show" id="branch-<?= md5($branch) ?>">
                                        <div class="branch-card-body">
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>KPI Name</th>
                                                            <th>Staff</th>
                                                            <th>Actual</th>
                                                            <th>Target</th>
                                                            <th>Achievement</th>
                                                            <th>Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($data['kpis'] as $kpi): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($kpi['kpi_name']) ?></td>
                                                                <td><?= htmlspecialchars($kpi['staff_name']) ?></td>
                                                                <td><?= $kpi['avg_actual'] ?></td>
                                                                <td><?= $kpi['target_value'] ?></td>
                                                                <td>
                                                                    <span class="fw-bold achievement-<?= $kpi['achievement_class'] ?>">
                                                                        <?= $kpi['achievement_rate'] ?>%
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <?php if ($kpi['achievement_rate'] >= 100): ?>
                                                                        <span class="badge bg-success">Above Target</span>
                                                                    <?php elseif ($kpi['achievement_rate'] >= 70): ?>
                                                                        <span class="badge bg-warning">On Track</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-danger">Below Target</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-building text-muted"></i>
                                <h4>No Branch Data Found</h4>
                                <p class="text-muted">No branch performance data available for the selected filters.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
<?php elseif ($view_type === 'staff'): ?>

<!-- Staff Performance View -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-users me-2"></i>
                    Staff Performance Details
                    <span class="badge bg-light text-dark ms-2"><?= count($staff_performance_data) ?> staff found</span>
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($staff_performance_data)): ?>
                    <?php foreach ($staff_performance_data as $staff_id => $data): ?>
                        <div class="staff-card">
                            <div class="staff-card-header" data-bs-toggle="collapse" data-bs-target="#staff-<?= $staff_id ?>">
                                <h6 class="mb-0">
                                    <i class="fas fa-user me-2"></i>
                                    <?= htmlspecialchars($data['staff_info']['username']) ?>
                                    <span class="badge bg-primary ms-2"><?= $data['staff_info']['branch'] ?></span>
                                    <span class="badge bg-success">KPIs: <?= $data['summary']['total_kpis'] ?></span>
                                    <span class="badge bg-info">Submissions: <?= $data['summary']['total_submissions'] ?></span>
                                    <span class="badge bg-warning">Avg: <?= $data['summary']['average_achievement'] ?>%</span>
                                </h6>
                            </div>
                            <div class="collapse show" id="staff-<?= $staff_id ?>">
                                <div class="staff-card-body">
                                    <?php if (!empty($data['kpis'])): ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>KPI Name</th>
                                                        <th>Category</th>
                                                        <th>Date</th>
                                                        <th>Actual Value</th>
                                                        <th>Target Value</th>
                                                        <th>Achievement Rate</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($data['kpis'] as $kpi): ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?= htmlspecialchars($kpi['kpi_name']) ?></strong>
                                                                <br><small class="text-muted">ID: <?= $kpi['kpi_id'] ?></small>
                                                            </td>
                                                            <td><?= htmlspecialchars($kpi['kpi_category']) ?></td>
                                                            <td><?= date('M j, Y', strtotime($kpi['submission_date'])) ?></td>
                                                            <td><strong><?= $kpi['actual_value'] ?></strong></td>
                                                            <td><?= $kpi['target_value'] > 0 ? $kpi['target_value'] : '<span class="text-muted">No target</span>' ?></td>
                                                            <td>
                                                                <span class="fw-bold achievement-<?= $kpi['achievement_class'] ?>">
                                                                    <?= $kpi['achievement_rate'] ?>%
                                                                </span>
                                                                <?php if ($kpi['target_value'] > 0): ?>
                                                                    <div class="progress mt-1" style="height: 5px;">
                                                                        <div class="progress-bar bg-<?= $kpi['achievement_class'] == 'high' ? 'success' : ($kpi['achievement_class'] == 'medium' ? 'warning' : 'danger') ?>" 
                                                                             style="width: <?= min($kpi['achievement_rate'], 100) ?>%">
                                                                        </div>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($kpi['target_value'] == 0): ?>
                                                                    <span class="badge bg-secondary">No Target Set</span>
                                                                <?php elseif ($kpi['achievement_rate'] >= 100): ?>
                                                                    <span class="badge bg-success">Above Target</span>
                                                                <?php elseif ($kpi['achievement_rate'] >= 70): ?>
                                                                    <span class="badge bg-warning">On Track</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-danger">Below Target</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <!-- Staff Summary -->
                                        <div class="row mt-3">
                                            <div class="col-md-3">
                                                <div class="card bg-success bg-opacity-10">
                                                    <div class="card-body text-center p-2">
                                                        <h6 class="mb-0 text-success"><?= $data['summary']['above_target'] ?></h6>
                                                        <small>Above Target</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="card bg-warning bg-opacity-10">
                                                    <div class="card-body text-center p-2">
                                                        <h6 class="mb-0 text-warning"><?= $data['summary']['on_track'] ?></h6>
                                                        <small>On Track</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="card bg-danger bg-opacity-10">
                                                    <div class="card-body text-center p-2">
                                                        <h6 class="mb-0 text-danger"><?= $data['summary']['below_target'] ?></h6>
                                                        <small>Below Target</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="card bg-primary bg-opacity-10">
                                                    <div class="card-body text-center p-2">
                                                        <h6 class="mb-0 text-primary"><?= $data['summary']['average_achievement'] ?>%</h6>
                                                        <small>Avg Achievement</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-warning mb-0">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            No KPI data found for this staff member with the current filters.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-users text-muted"></i>
                        <h4>No Staff Data Found</h4>
                        <p class="text-muted">No staff performance data available for the selected filters.</p>
                        <div class="mt-3">
                            <p><strong>Possible reasons:</strong></p>
                            <ul class="text-start">
                                <li>No approved submissions in the selected date range</li>
                                <li>Staff targets not set for the selected period</li>
                                <li>Search filters are too restrictive</li>
                            </ul>
                        </div>
                        <a href="performance_tracking.php?view_type=staff" class="btn btn-primary">
                            <i class="fas fa-refresh me-2"></i>Reset Filters
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

        <!-- Debug Information (remove in production) -->
        <div class="mt-4">
            <details>
                <summary class="text-muted">Debug Information</summary>
                <pre class="bg-light p-3 small"><?php
                    echo "Date Range: $date_from to $date_to\n";
                    echo "View Type: $view_type\n";
                    echo "Target Period: $target_period\n";
                    echo "Branches Found: " . count($all_branches) . "\n";
                    echo "Staff Found: " . count($all_staff) . "\n";
                    echo "Branch Performance Data: " . count($branch_performance_data) . " branches\n";
                    echo "Staff Performance Data: " . count($staff_performance_data) . " staff\n";
                ?></pre>
            </details>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
    $(document).ready(function() {
        // Auto-submit form when filters change
        $('select[name="target_period"], select[name="quarter"], select[name="quarter_year"]').on('change', function() {
            $('#performanceFilter').submit();
        });

        // Show/hide date range based on quarter selection
        function toggleDateRange() {
            const quarterSelected = $('select[name="quarter"]').val() !== '' && $('select[name="quarter_year"]').val() !== '';
            if (quarterSelected) {
                $('#dateRangeSection').addClass('d-none');
            } else {
                $('#dateRangeSection').removeClass('d-none');
            }
        }

        // Initialize on page load
        toggleDateRange();

        // Toggle when quarter selection changes
        $('select[name="quarter"], select[name="quarter_year"]').on('change', toggleDateRange);

        // Real-time search functionality
        let searchTimeout;
        $('input[name="search_kpi"], input[name="search_staff"], input[name="search_branch"]').on('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                $('#performanceFilter').submit();
            }, 500);
        });

        // Initialize all collapsible elements
        $('.collapse').collapse();
    });
    </script>
</body>
</html>