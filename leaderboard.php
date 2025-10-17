<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['user', 'manager', 'admin', 'district'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$branch = $_SESSION['branch'] ?? 'Unknown';
$role = $_SESSION['role'];
$district = $_SESSION['district'] ?? 'Unknown';

// Database connection check
$db_connected = false;
$db_error = null;
try {
    if ($conn && $conn->ping()) {
        $db_connected = true;
    } else {
        $db_error = "Database connection failed";
    }
} catch (Exception $e) {
    $db_error = "Database connection error: " . $e->getMessage();
    error_log("Database connection failed: " . $e->getMessage());
}

// Get filter parameters
$leaderboard_type = $_GET['type'] ?? 'kpi_wise'; // Default to KPI wise
$time_period = $_GET['period'] ?? 'current_month';
$kpi_id_filter = $_GET['kpi_id'] ?? 'all';
$branch_filter = $_GET['branch'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$specific_month = $_GET['month'] ?? '';
$specific_week = $_GET['week'] ?? '';
$staff_level_filter = $_GET['staff_level'] ?? 'all';
$district_filter = $_GET['district'] ?? 'all';

// Available time periods
$time_periods = [
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'current_week' => 'Current Week',
    'last_week' => 'Last Week',
    'current_month' => 'Current Month',
    'last_month' => 'Last Month', 
    'current_quarter' => 'Current Quarter',
    'last_quarter' => 'Last Quarter',
    'ytd' => 'Year to Date',
    'all_time' => 'All Time',
    'specific_month' => 'Specific Month',
    'specific_week' => 'Specific Week'
];

// Available leaderboard types
$leaderboard_types = [
    'kpi_wise' => 'KPI Performance Matrix',
    'overall' => 'Overall Performance',
    'kpi_champions' => 'KPI Champions',
    'branch' => 'Branch Performance',
    'monthly' => 'Monthly Stars',
    'weekly' => 'Weekly Stars',
    'daily' => 'Daily Top Performers'
];

// Get available KPIs for filter
$all_kpis = ['all' => 'All KPIs'];
$kpis_data = [];
try {
    if ($db_connected) {
        $kpis_sql = "SELECT id, kpi_name FROM kpis ORDER BY kpi_name";
        $kpis_result = $conn->query($kpis_sql);
        if ($kpis_result && $kpis_result->num_rows > 0) {
            while ($row = $kpis_result->fetch_assoc()) {
                $all_kpis[$row['id']] = $row['kpi_name'];
                $kpis_data[$row['id']] = $row;
            }
        }
    }
} catch (Exception $e) {
    error_log("KPI query error: " . $e->getMessage());
}

// Get available branches from leaderboard_summary
$all_branches = ['all' => 'All Branches'];
try {
    if ($db_connected) {
        $branches_sql = "SELECT DISTINCT branch FROM leaderboard_summary WHERE branch IS NOT NULL AND branch != '' ORDER BY branch";
        $branches_result = $conn->query($branches_sql);
        if ($branches_result && $branches_result->num_rows > 0) {
            while ($row = $branches_result->fetch_assoc()) {
                $all_branches[$row['branch']] = $row['branch'];
            }
        }
    }
} catch (Exception $e) {
    error_log("Branches query error: " . $e->getMessage());
}

// Get available staff levels from leaderboard_summary
$all_staff_levels = ['all' => 'All Levels'];
try {
    if ($db_connected) {
        $levels_sql = "SELECT DISTINCT staff_level FROM leaderboard_summary WHERE staff_level IS NOT NULL AND staff_level != '' ORDER BY staff_level";
        $levels_result = $conn->query($levels_sql);
        if ($levels_result && $levels_result->num_rows > 0) {
            while ($row = $levels_result->fetch_assoc()) {
                $all_staff_levels[$row['staff_level']] = $row['staff_level'];
            }
        }
    }
} catch (Exception $e) {
    error_log("Staff levels query error: " . $e->getMessage());
}

// Get available districts from leaderboard_summary
$all_districts = ['all' => 'All Districts'];
try {
    if ($db_connected) {
        $districts_sql = "SELECT DISTINCT district FROM leaderboard_summary WHERE district IS NOT NULL AND district != '' ORDER BY district";
        $districts_result = $conn->query($districts_sql);
        if ($districts_result && $districts_result->num_rows > 0) {
            while ($row = $districts_result->fetch_assoc()) {
                $all_districts[$row['district']] = $row['district'];
            }
        }
    }
} catch (Exception $e) {
    error_log("Districts query error: " . $e->getMessage());
}

// Generate month options
$month_options = [];
for ($i = 12; $i >= 0; $i--) {
    $month_value = date('Y-m', strtotime("-$i months"));
    $month_options[$month_value] = date('F Y', strtotime($month_value . '-01'));
}

// Generate week options for current year
$week_options = [];
$current_year = date('Y');
for ($week = 1; $week <= 52; $week++) {
    $week_start = date('Y-m-d', strtotime($current_year . 'W' . str_pad($week, 2, '0', STR_PAD_LEFT)));
    $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
    $week_options[$week] = "Week $week (" . date('M d', strtotime($week_start)) . " - " . date('M d', strtotime($week_end)) . ")";
}

// Performance classification function
function getPerformanceClass($value, $target = 100) {
    if ($target == 0) return 'average';
    $percentage = ($value / $target) * 100;
    if ($percentage >= 100) return 'excellent';
    if ($percentage >= 80) return 'very-good';
    if ($percentage >= 60) return 'good';
    if ($percentage >= 40) return 'average';
    return 'needs-improvement';
}

// Calculate date range
function getDateRange($period, $specific_month = '', $specific_week = '') {
    $now = new DateTime();
    
    switch ($period) {
        case 'today':
            $start = new DateTime('today');
            $end = new DateTime('today');
            break;
        case 'yesterday':
            $start = new DateTime('yesterday');
            $end = new DateTime('yesterday');
            break;
        case 'current_week':
            $start = new DateTime('monday this week');
            $end = new DateTime('sunday this week');
            break;
        case 'last_week':
            $start = new DateTime('monday last week');
            $end = new DateTime('sunday last week');
            break;
        case 'current_month':
            $start = new DateTime('first day of this month');
            $end = new DateTime('last day of this month');
            break;
        case 'last_month':
            $start = new DateTime('first day of last month');
            $end = new DateTime('last day of last month');
            break;
        case 'current_quarter':
            $month = $now->format('n');
            $quarter = ceil($month / 3);
            $start = new DateTime($now->format('Y') . '-' . (($quarter - 1) * 3 + 1) . '-01');
            $end = clone $start;
            $end->modify('+3 months')->modify('-1 day');
            break;
        case 'last_quarter':
            $month = $now->format('n');
            $quarter = ceil($month / 3) - 1;
            if ($quarter == 0) {
                $quarter = 4;
                $year = $now->format('Y') - 1;
            } else {
                $year = $now->format('Y');
            }
            $start = new DateTime($year . '-' . (($quarter - 1) * 3 + 1) . '-01');
            $end = clone $start;
            $end->modify('+3 months')->modify('-1 day');
            break;
        case 'ytd':
            $start = new DateTime($now->format('Y') . '-01-01');
            $end = clone $now;
            break;
        case 'specific_month':
            if (!empty($specific_month)) {
                $start = new DateTime($specific_month . '-01');
                $end = clone $start;
                $end->modify('last day of this month');
            } else {
                return ['start' => null, 'end' => null, 'display' => 'Specific Month (Not Selected)'];
            }
            break;
        case 'specific_week':
            if (!empty($specific_week)) {
                $year = date('Y');
                $start = new DateTime();
                $start->setISODate($year, $specific_week);
                $end = clone $start;
                $end->modify('+6 days');
            } else {
                return ['start' => null, 'end' => null, 'display' => 'Specific Week (Not Selected)'];
            }
            break;
        case 'all_time':
        default:
            return ['start' => null, 'end' => null, 'display' => 'All Time'];
    }
    
    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'display' => $time_periods[$period] ?? 'Custom Range'
    ];
}

// CORRECTED FUNCTION: Get KPI Performance Matrix with Date Filter using staff_targets
function getKPIPerformanceMatrix($conn, $filters = []) {
    $conditions = ["us.value IS NOT NULL"];
    
    // Apply filters
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $conditions[] = "us.submission_date BETWEEN '{$filters['date_from']}' AND '{$filters['date_to']}'";
    } else {
        // Default to current month if no date range
        $current_month_start = date('Y-m-01');
        $current_month_end = date('Y-m-t');
        $conditions[] = "us.submission_date BETWEEN '$current_month_start' AND '$current_month_end'";
    }
    
    if (!empty($filters['branch']) && $filters['branch'] !== 'all') {
        $conditions[] = "us.branch = '{$filters['branch']}'";
    }

    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $conditions[] = "us.status = '{$filters['status']}'";
    }

    if (!empty($filters['kpi_id']) && $filters['kpi_id'] !== 'all') {
        $conditions[] = "us.kpi_id = '{$filters['kpi_id']}'";
    }

    // Search filter
    if (!empty($filters['search'])) {
        $search = $conn->real_escape_string($filters['search']);
        $conditions[] = "(u.username LIKE '%$search%' OR us.branch LIKE '%$search%' OR us.status LIKE '%$search%' OR k.kpi_name LIKE '%$search%')";
    }

    $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    
    // Get all KPIs first
    $kpis_query = "SELECT id, kpi_name FROM kpis ORDER BY kpi_name";
    $kpis_result = $conn->query($kpis_query);
    $all_kpis = [];
    if ($kpis_result) {
        while ($row = $kpis_result->fetch_assoc()) {
            $all_kpis[$row['id']] = $row;
        }
    }
    
    // Get user performance data grouped by KPI with date filtering
    $query = "
        SELECT 
            us.user_id,
            u.username,
            u.position,
            u.branch,
            u.district,
            us.status,
            us.kpi_id,
            k.kpi_name,
            COALESCE(st.monthly_target, 0) as target_value,
            COUNT(us.id) as total_submissions,
            COALESCE(SUM(us.value), 0) as total_value,
            COALESCE(AVG(us.value), 0) as avg_value
        FROM user_submissions us
        INNER JOIN users u ON us.user_id = u.id
        LEFT JOIN kpis k ON us.kpi_id = k.id
        LEFT JOIN staff_targets st ON (u.username = st.staff_name AND us.kpi_id = st.kpi_id)
        $where_clause
        GROUP BY us.user_id, u.username, u.position, u.branch, u.district, us.status, us.kpi_id, k.kpi_name, st.monthly_target
        HAVING COUNT(us.id) > 0 AND COALESCE(SUM(us.value), 0) > 0
        ORDER BY u.username, k.kpi_name
    ";
    
    try {
        $result = $conn->query($query);
        if ($result) {
            $user_data = [];
            $kpi_totals = []; // Track totals for each KPI
            
            while ($row = $result->fetch_assoc()) {
                $user_id = $row['user_id'];
                $kpi_id = $row['kpi_id'];
                
                if (!isset($user_data[$user_id])) {
                    $user_data[$user_id] = [
                        'user_id' => $row['user_id'],
                        'username' => $row['username'],
                        'position' => $row['position'],
                        'branch' => $row['branch'],
                        'district' => $row['district'],
                        'status' => $row['status'],
                        'total_submissions' => 0,
                        'total_value_all' => 0,
                        'kpis' => []
                    ];
                }
                
                // Add KPI performance data
                $target = $row['target_value'] ?? 0;
                $achievement_percentage = $target > 0 ? ($row['total_value'] / $target) * 100 : 0;
                $performance_class = getPerformanceClass($row['total_value'], $target);
                
                $user_data[$user_id]['kpis'][$kpi_id] = [
                    'kpi_name' => $row['kpi_name'],
                    'total_value' => $row['total_value'],
                    'avg_value' => $row['avg_value'],
                    'submissions' => $row['total_submissions'],
                    'achievement_percentage' => $achievement_percentage,
                    'performance_class' => $performance_class,
                    'target_value' => $target
                ];
                
                // Update user totals
                $user_data[$user_id]['total_submissions'] += $row['total_submissions'];
                $user_data[$user_id]['total_value_all'] += $row['total_value'];
                
                // Update KPI totals
                if (!isset($kpi_totals[$kpi_id])) {
                    $kpi_totals[$kpi_id] = [
                        'total_value' => 0,
                        'total_submissions' => 0,
                        'total_users' => 0,
                        'kpi_name' => $row['kpi_name']
                    ];
                }
                $kpi_totals[$kpi_id]['total_value'] += $row['total_value'];
                $kpi_totals[$kpi_id]['total_submissions'] += $row['total_submissions'];
                $kpi_totals[$kpi_id]['total_users']++;
            }
            
            // Convert to array and calculate ranks based on total value across all KPIs
            $user_data = array_values($user_data);
            usort($user_data, function($a, $b) {
                return $b['total_value_all'] <=> $a['total_value_all'];
            });
            
            // Add ranks
            foreach ($user_data as $index => &$user) {
                $user['rank'] = $index + 1;
            }
            
            return [
                'users' => $user_data,
                'kpis' => $all_kpis,
                'kpi_totals' => $kpi_totals
            ];
        } else {
            return ['error' => 'KPI Matrix query failed: ' . $conn->error];
        }
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// CORRECTED FUNCTION: Get KPI Champions with Date Filter using staff_targets
function getKPIChampions($conn, $filters = []) {
    $conditions = ["us.value IS NOT NULL"];
    
    // Apply filters
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $conditions[] = "us.submission_date BETWEEN '{$filters['date_from']}' AND '{$filters['date_to']}'";
    } else {
        // Default to current month if no date range
        $current_month_start = date('Y-m-01');
        $current_month_end = date('Y-m-t');
        $conditions[] = "us.submission_date BETWEEN '$current_month_start' AND '$current_month_end'";
    }
    
    if (!empty($filters['branch']) && $filters['branch'] !== 'all') {
        $conditions[] = "us.branch = '{$filters['branch']}'";
    }

    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $conditions[] = "us.status = '{$filters['status']}'";
    }

    if (!empty($filters['kpi_id']) && $filters['kpi_id'] !== 'all') {
        $conditions[] = "us.kpi_id = '{$filters['kpi_id']}'";
    }

    // Search filter
    if (!empty($filters['search'])) {
        $search = $conn->real_escape_string($filters['search']);
        $conditions[] = "(u.username LIKE '%$search%' OR us.branch LIKE '%$search%' OR us.status LIKE '%$search%' OR k.kpi_name LIKE '%$search%')";
    }

    $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    
    $query = "
        SELECT 
            kpi_data.*,
            (kpi_data.total_value / NULLIF(kpi_data.target_value, 0) * 100) as achievement_percentage
        FROM (
            SELECT 
                us.kpi_id,
                k.kpi_name,
                COALESCE(st.monthly_target, 0) as target_value,
                us.user_id,
                u.username,
                u.position,
                us.branch,
                us.status,
                COUNT(us.id) as total_submissions,
                COALESCE(SUM(us.value), 0) as total_value,
                COALESCE(AVG(us.value), 0) as avg_value,
                ROW_NUMBER() OVER (PARTITION BY us.kpi_id ORDER BY COALESCE(SUM(us.value), 0) DESC) as rank_per_kpi
            FROM user_submissions us
            INNER JOIN users u ON us.user_id = u.id
            LEFT JOIN kpis k ON us.kpi_id = k.id
            LEFT JOIN staff_targets st ON (u.username = st.staff_name AND us.kpi_id = st.kpi_id)
            $where_clause
            GROUP BY us.kpi_id, k.kpi_name, st.monthly_target, us.user_id, u.username, u.position, us.branch, us.status
            HAVING COUNT(us.id) > 0 AND COALESCE(SUM(us.value), 0) > 0
        ) as kpi_data
        WHERE kpi_data.rank_per_kpi = 1
        ORDER BY kpi_data.kpi_name
    ";
    
    try {
        $result = $conn->query($query);
        if ($result) {
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $target = $row['target_value'] ?? 100;
                $row['performance_class'] = getPerformanceClass($row['total_value'], $target);
                $row['achievement_percentage'] = $row['achievement_percentage'] ?? ($target > 0 ? ($row['total_value'] / $target * 100) : 0);
                $data[] = $row;
            }
            return $data;
        } else {
            return ['error' => 'KPI Champions query failed: ' . $conn->error];
        }
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// Get quick stats from user_submissions with date filter
function getQuickStatsFromSubmissions($conn, $filters = []) {
    $conditions = ["us.value IS NOT NULL"];
    
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $conditions[] = "us.submission_date BETWEEN '{$filters['date_from']}' AND '{$filters['date_to']}'";
    } else {
        // Default to current month if no date range
        $current_month_start = date('Y-m-01');
        $current_month_end = date('Y-m-t');
        $conditions[] = "us.submission_date BETWEEN '$current_month_start' AND '$current_month_end'";
    }
    
    if (!empty($filters['branch']) && $filters['branch'] !== 'all') {
        $conditions[] = "us.branch = '{$filters['branch']}'";
    }
    
    $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    
    $stats = [];
    
    // All stats in one query
    $query = "
        SELECT 
            COUNT(DISTINCT us.user_id) as total_users,
            COUNT(us.id) as total_submissions,
            SUM(CASE WHEN us.status = 'approved' THEN 1 ELSE 0 END) as approved_submissions,
            SUM(CASE WHEN us.status = 'pending' THEN 1 ELSE 0 END) as pending_submissions,
            COALESCE(SUM(us.value), 0) as total_value,
            COALESCE(AVG(us.value), 0) as avg_value
        FROM user_submissions us
        $where_clause
    ";
    
    $result = $conn->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        $stats = [
            'total_users' => $row['total_users'] ?? 0,
            'total_submissions' => $row['total_submissions'] ?? 0,
            'approved_submissions' => $row['approved_submissions'] ?? 0,
            'pending_submissions' => $row['pending_submissions'] ?? 0,
            'total_value' => $row['total_value'] ?? 0,
            'avg_value' => $row['avg_value'] ?? 0
        ];
    }
    
    return $stats;
}

$date_range = getDateRange($time_period, $specific_month, $specific_week);

// Set default filters based on role
if ($role === 'manager' && $branch_filter === 'all') {
    $branch_filter = $branch;
}

// Prepare filters for search
$filters = [
    'date_from' => $date_range['start'],
    'date_to' => $date_range['end'],
    'kpi_id' => $kpi_id_filter,
    'branch' => $branch_filter,
    'status' => $status_filter,
    'staff_level' => $staff_level_filter,
    'district' => $district_filter,
    'search' => $search_query
];

// Get leaderboard data
$leaderboard_data = [];
$using_test_data = false;
$data_error = null;

if ($db_connected) {
    if ($leaderboard_type === 'kpi_wise') {
        $result = getKPIPerformanceMatrix($conn, $filters);
    } elseif ($leaderboard_type === 'kpi_champions') {
        $result = getKPIChampions($conn, $filters);
    } else {
        // For other types, use the existing functions
        $result = ['error' => 'This view focuses on KPI-wise performance. Please use KPI Performance Matrix.'];
    }
    
    if (isset($result['error'])) {
        $data_error = $result['error'];
        $leaderboard_data = [];
    } else {
        $leaderboard_data = $result;
    }
} else {
    $data_error = $db_error;
    $leaderboard_data = [];
}

// Get quick stats
$quick_stats = [];
if ($db_connected) {
    $quick_stats = getQuickStatsFromSubmissions($conn, $filters);
}

$leaderboard_title = $leaderboard_types[$leaderboard_type] ?? 'KPI Performance Matrix';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KPI Performance Matrix | <?= htmlspecialchars($username) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #6366f1;
            --secondary-color: #8b5cf6;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --dark-color: #1f2937;
            --light-color: #f8fafc;
            --border-color: #e5e7eb;
        }
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .dashboard-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            margin: 20px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .navbar-custom {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            margin: 20px;
            padding: 15px 25px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .stats-card {
            background: white;
            border-radius: 15px;
            border: none;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary-color);
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-color);
        }
        
        .leaderboard-card {
            background: white;
            border-radius: 15px;
            border: none;
            box-shadow: 0 4px6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .leaderboard-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px 25px;
        }
        
        .rank-badge {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            color: white;
        }
        
        .rank-1 { background: linear-gradient(135deg, #FFD700, #FFA500); }
        .rank-2 { background: linear-gradient(135deg, #C0C0C0, #A0A0A0); }
        .rank-3 { background: linear-gradient(135deg, #CD7F32, #8B4513); }
        .rank-other { background: linear-gradient(135deg, #6B7280, #4B5563); }
        
        .performance-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .performance-excellent { background: #D1FAE5; color: #065F46; }
        .performance-very-good { background: #DCFCE7; color: #166534; }
        .performance-good { background: #F0FDF4; color: #15803D; }
        .performance-average { background: #FEF3C7; color: #92400E; }
        .performance-needs-improvement { background: #FEE2E2; color: #991B1B; }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: #6B7280;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 15px 12px;
        }
        
        .table td {
            padding: 15px 12px;
            vertical-align: middle;
            border-color: var(--border-color);
        }
        
        .table tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .kpi-column {
            min-width: 150px;
            text-align: center;
            background: #f8fafc;
        }
        
        .kpi-value {
            font-weight: 700;
            font-size: 1rem;
            color: #1f2937;
        }
        
        .kpi-achievement {
            font-size: 0.75rem;
            margin-top: 4px;
        }
        
        .kpi-target {
            font-size: 0.7rem;
            color: #6b7280;
            margin-top: 2px;
        }
        
        .kpi-header {
            background: #e5e7eb !important;
            font-weight: 700 !important;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box .form-control {
            padding-left: 45px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            height: 50px;
        }
        
        .search-box .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6B7280;
            z-index: 5;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.3);
        }
        
        .form-select, .form-control {
            border-radius: 10px;
            border: 1px solid var(--border-color);
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        .current-user-highlight {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe) !important;
            border-left: 4px solid var(--primary-color);
        }
        
        .back-to-dashboard {
            background: linear-gradient(135deg, var(--success-color), #34d399);
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .back-to-dashboard:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.3);
            color: white;
        }
        
        .kpi-total-row {
            background: linear-gradient(135deg, #1f2937, #374151) !important;
            color: white;
            font-weight: 700;
        }
        
        .kpi-total-row td {
            border-color: #4b5563 !important;
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                margin: 10px;
                border-radius: 15px;
            }
            
            .navbar-custom {
                margin: 10px;
                border-radius: 10px;
            }
            
            .stats-card {
                margin-bottom: 15px;
            }
            
            .table-responsive {
                font-size: 0.8rem;
            }
            
            .filter-section .row {
                margin-bottom: 10px;
            }
            
            .filter-section .col {
                margin-bottom: 15px;
            }
            
            .kpi-column {
                min-width: 120px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold text-dark" href="#">
                <i class="fas fa-chart-bar me-2" style="color: var(--primary-color);"></i>
                KPI Performance Matrix
            </a>
            <div class="navbar-nav ms-auto align-items-center">
                <a href="district_dashboard.php" class="back-to-dashboard me-3">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                        <div class="user-avatar me-2">
                            <?= strtoupper(substr($username, 0, 2)) ?>
                        </div>
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($username) ?></div>
                            <small class="text-muted"><?= ucfirst($role) ?></small>
                        </div>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="diatrict_dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="dashboard-container">
        <!-- Quick Stats -->
        <div class="container-fluid py-4">
            <div class="row g-4">
                <div class="col-xl-3 col-md-6">
                    <div class="stats-card p-4">
                        <div class="d-flex align-items-center">
                            <div class="stats-icon me-3">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold"><?= number_format($quick_stats['total_submissions'] ?? 0) ?></h3>
                                <p class="text-muted mb-0">Total Submissions</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stats-card p-4">
                        <div class="d-flex align-items-center">
                            <div class="stats-icon me-3" style="background: linear-gradient(135deg, var(--success-color), #34d399);">
                                <i class="fas fa-users"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold"><?= number_format($quick_stats['total_users'] ?? 0) ?></h3>
                                <p class="text-muted mb-0">Active Users</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stats-card p-4">
                        <div class="d-flex align-items-center">
                            <div class="stats-icon me-3" style="background: linear-gradient(135deg, var(--warning-color), #fbbf24);">
                                <i class="fas fa-target"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold"><?= number_format($quick_stats['total_value'] ?? 0) ?></h3>
                                <p class="text-muted mb-0">Total Value</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stats-card p-4">
                        <div class="d-flex align-items-center">
                            <div class="stats-icon me-3" style="background: linear-gradient(135deg, var(--danger-color), #f87171);">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold"><?= number_format($quick_stats['avg_value'] ?? 0, 1) ?></h3>
                                <p class="text-muted mb-0">Average Value</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Filters Section -->
        <div class="container-fluid">
            <div class="filter-section">
                <form id="leaderboardFilter" method="GET">
                    <input type="hidden" name="type" value="kpi_wise">
                    <div class="row g-3">
                        <!-- Time Period -->
                        <div class="col-xl-2 col-lg-3 col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-calendar me-2"></i>Time Period
                            </label>
                            <select class="form-select" name="period" id="timePeriod">
                                <?php foreach ($time_periods as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $time_period === $value ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Specific Month Selector -->
                        <div class="col-xl-2 col-lg-3 col-md-6" id="specificMonthContainer" style="display: <?= $time_period === 'specific_month' ? 'block' : 'none' ?>;">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-calendar-alt me-2"></i>Select Month
                            </label>
                            <select class="form-select" name="month">
                                <option value="">Select Month</option>
                                <?php foreach ($month_options as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $specific_month === $value ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Specific Week Selector -->
                        <div class="col-xl-2 col-lg-3 col-md-6" id="specificWeekContainer" style="display: <?= $time_period === 'specific_week' ? 'block' : 'none' ?>;">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-calendar-week me-2"></i>Select Week
                            </label>
                            <select class="form-select" name="week">
                                <option value="">Select Week</option>
                                <?php foreach ($week_options as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $specific_week == $value ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- KPI Filter -->
                        <div class="col-xl-2 col-lg-3 col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-filter me-2"></i>KPI
                            </label>
                            <select class="form-select" name="kpi_id">
                                <?php foreach ($all_kpis as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $kpi_id_filter === $value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Branch Filter -->
                        <div class="col-xl-2 col-lg-3 col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-building me-2"></i>Branch
                            </label>
                            <select class="form-select" name="branch">
                                <?php foreach ($all_branches as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $branch_filter === $value ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Status Filter -->
                        <div class="col-xl-2 col-lg-3 col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-info-circle me-2"></i>Status
                            </label>
                            <select class="form-select" name="status">
                                <?php foreach (['all' => 'All Status', 'approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected'] as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $status_filter === $value ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Search Box and Action Buttons -->
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="search-box">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" class="form-control" name="search" placeholder="Search users, branches, or KPIs..." value="<?= htmlspecialchars($search_query) ?>">
                            </div>
                        </div>
                        <div class="col-md-6 text-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-sync me-2"></i>Apply Filters
                            </button>
                            <a href="?type=kpi_wise" class="btn btn-outline-secondary me-2">
                                <i class="fas fa-times me-2"></i>Clear Filters
                            </a>
                            <a href="diatrict_dashboard.php" class="btn btn-success">
                                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- KPI Performance Matrix Display -->
        <div class="container-fluid pb-5">
            <div class="leaderboard-card">
                <div class="leaderboard-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="mb-1 fw-bold">
                                <i class="fas fa-table me-2"></i>
                                KPI Performance Matrix
                            </h4>
                            <p class="mb-0 opacity-75">
                                <?= $date_range['display'] ?>
                                <?php if ($kpi_id_filter !== 'all' && isset($all_kpis[$kpi_id_filter])): ?>
                                    • KPI: <?= htmlspecialchars($all_kpis[$kpi_id_filter]) ?>
                                <?php endif; ?>
                                <?php if ($branch_filter !== 'all'): ?>
                                    • Branch: <?= htmlspecialchars($branch_filter) ?>
                                <?php endif; ?>
                                <?php if ($status_filter !== 'all'): ?>
                                    • Status: <?= ucfirst($status_filter) ?>
                                <?php endif; ?>
                                <?php if (!empty($search_query)): ?>
                                    • Search: "<?= htmlspecialchars($search_query) ?>"
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <span class="badge bg-light text-dark fs-6">
                                <i class="fas fa-database me-1"></i>
                                <?php if (isset($leaderboard_data['users'])): ?>
                                    <?= count($leaderboard_data['users']) ?> Users
                                <?php else: ?>
                                    0 Users
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="card-body p-0">
                    <?php if (!empty($leaderboard_data) && !isset($leaderboard_data['error']) && isset($leaderboard_data['users'])): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th width="80" class="ps-4">Rank</th>
                                        <th>User</th>
                                        <th>Position</th>
                                        <th>Branch</th>
                                        <th>Status</th>
                                        <th class="text-center">Total Submissions</th>
                                        <th class="text-center">Total Value</th>
                                        <?php if (isset($leaderboard_data['kpis'])): ?>
                                            <?php foreach ($leaderboard_data['kpis'] as $kpi_id => $kpi): ?>
                                                <th class="text-center kpi-column kpi-header">
                                                    <div class="fw-semibold" style="font-size: 0.8rem;"><?= htmlspecialchars($kpi['kpi_name']) ?></div>
                                                    <small class="text-muted" style="font-size: 0.7rem;">
                                                        Monthly Target
                                                    </small>
                                                </th>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leaderboard_data['users'] as $user): ?>
                                        <tr class="<?= (isset($user['user_id']) && $user['user_id'] == $user_id) ? 'current-user-highlight' : '' ?>">
                                            <td class="ps-4">
                                                <?php if ($user['rank'] <= 3): ?>
                                                    <div class="rank-badge rank-<?= $user['rank'] ?>">
                                                        <?= $user['rank'] ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="rank-badge rank-other">
                                                        <?= $user['rank'] ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="user-avatar me-3">
                                                        <?= strtoupper(substr($user['username'] ?? 'U', 0, 2)) ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-semibold"><?= htmlspecialchars($user['username'] ?? 'Unknown') ?></div>
                                                        <?php if (isset($user['user_id']) && $user['user_id'] == $user_id): ?>
                                                            <small class="text-primary fw-semibold">You</small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($user['position'] ?? 'N/A') ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($user['branch'] ?? 'N/A') ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?= htmlspecialchars($user['status'] ?? 'N/A') ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-primary"><?= $user['total_submissions'] ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold text-dark"><?= number_format($user['total_value_all']) ?></span>
                                            </td>
                                            <?php if (isset($leaderboard_data['kpis'])): ?>
                                                <?php foreach ($leaderboard_data['kpis'] as $kpi_id => $kpi): ?>
                                                    <td class="text-center kpi-column">
                                                        <?php if (isset($user['kpis'][$kpi_id])): ?>
                                                            <?php $kpi_data = $user['kpis'][$kpi_id]; ?>
                                                            <div class="kpi-value performance-<?= $kpi_data['performance_class'] ?>">
                                                                <?= number_format($kpi_data['total_value'], 1) ?>
                                                            </div>
                                                            <div class="kpi-achievement">
                                                                <span class="performance-badge performance-<?= $kpi_data['performance_class'] ?>" style="font-size: 0.65rem;">
                                                                    <?= number_format($kpi_data['achievement_percentage'], 1) ?>%
                                                                </span>
                                                            </div>
                                                            <div class="kpi-target" style="font-size: 0.7rem;">
                                                                <?= $kpi_data['submissions'] ?> subs
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted" style="font-size: 0.8rem;">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- KPI Totals Row -->
                                    <?php if (isset($leaderboard_data['kpi_totals']) && !empty($leaderboard_data['kpi_totals'])): ?>
                                        <tr class="kpi-total-row">
                                            <td class="ps-4" colspan="6">
                                                <strong>KPI TOTALS</strong>
                                            </td>
                                            <td class="text-center">
                                                <strong><?= number_format(array_sum(array_column($leaderboard_data['kpi_totals'], 'total_value'))) ?></strong>
                                            </td>
                                            <?php if (isset($leaderboard_data['kpis'])): ?>
                                                <?php foreach ($leaderboard_data['kpis'] as $kpi_id => $kpi): ?>
                                                    <td class="text-center">
                                                        <?php if (isset($leaderboard_data['kpi_totals'][$kpi_id])): ?>
                                                            <div class="kpi-value text-white">
                                                                <strong><?= number_format($leaderboard_data['kpi_totals'][$kpi_id]['total_value'], 1) ?></strong>
                                                            </div>
                                                            <div class="kpi-achievement">
                                                                <small class="text-light">
                                                                    <?= $leaderboard_data['kpi_totals'][$kpi_id]['total_users'] ?> users
                                                                </small>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-light">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-table fa-4x text-muted mb-3"></i>
                            <h4 class="text-muted">No KPI Performance Data Found</h4>
                            <p class="text-muted">No data found for the selected filters and time period.</p>
                            <?php if ($data_error): ?>
                                <div class="alert alert-warning mt-3 mx-3">
                                    <small>Error: <?= htmlspecialchars($data_error) ?></small>
                                </div>
                            <?php endif; ?>
                            <div class="mt-4">
                                <a href="?type=kpi_wise" class="btn btn-primary me-2">
                                    <i class="fas fa-refresh me-2"></i>Reset Filters
                                </a>
                                <a href="diatrict_dashboard.php" class="btn btn-success">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Show/hide specific month/week selectors
        const timePeriodSelect = document.getElementById('timePeriod');
        const monthContainer = document.getElementById('specificMonthContainer');
        const weekContainer = document.getElementById('specificWeekContainer');
        
        function toggleSpecificSelectors() {
            const selectedPeriod = timePeriodSelect.value;
            monthContainer.style.display = selectedPeriod === 'specific_month' ? 'block' : 'none';
            weekContainer.style.display = selectedPeriod === 'specific_week' ? 'block' : 'none';
        }
        
        timePeriodSelect.addEventListener('change', toggleSpecificSelectors);
        toggleSpecificSelectors(); // Initial call
        
        // Add loading state to form submission
        const form = document.getElementById('leaderboardFilter');
        const submitBtn = form.querySelector('button[type="submit"]');
        
        form.addEventListener('submit', function() {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';
            submitBtn.disabled = true;
        });
    });
    </script>
</body>
</html>