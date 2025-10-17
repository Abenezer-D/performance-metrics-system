<?php
session_start();
require_once 'db.php';

// Ensure admin login
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Fetch KPI names
$kpi_names = [];
$kpi_query = $conn->query("SELECT id, kpi_name FROM kpis");
while($k = $kpi_query->fetch_assoc()) {
    $kpi_names[$k['id']] = $k['kpi_name'];
}

// Fetch approved performance from user_submissions
$data = [];
$sql = "
    SELECT 
        us.user_id, 
        u.username, 
        u.branch,
        us.submission_date,
        k.kpi_name,
        us.value
    FROM user_submissions us
    JOIN users u ON us.user_id = u.id
    JOIN kpis k ON us.kpi_id = k.id
    WHERE us.status = 'approved'
    ORDER BY us.branch, u.username, us.submission_date DESC
";
$res = $conn->query($sql);

// Check for query errors
if (!$res) {
    die("SQL Error: " . $conn->error);
}

// Create a pivoted array to avoid KPI redundancy
$pivoted_data = [];
$branches = [];
$users = [];
$dates = [];

while ($row = $res->fetch_assoc()) {
    $branch = $row['branch'];
    $user = $row['username'];
    $date = $row['submission_date'];
    $kpi = $row['kpi_name'];
    $value = $row['value'];
    
    // Initialize user-date entry if not exists
    if (!isset($pivoted_data[$branch][$user][$date])) {
        $pivoted_data[$branch][$user][$date] = [];
    }
    
    // Add KPI value
    $pivoted_data[$branch][$user][$date][$kpi] = $value;
    
    // Collect unique values for filters
    if (!in_array($branch, $branches)) {
        $branches[] = $branch;
    }
    if (!in_array($user, $users)) {
        $users[] = $user;
    }
    if (!in_array($date, $dates)) {
        $dates[] = $date;
    }
}

// Sort filter options
sort($branches);
sort($users);
rsort($dates); // Most recent first

// Get min and max dates for date range
$min_date = !empty($dates) ? min($dates) : date('Y-m-d');
$max_date = !empty($dates) ? max($dates) : date('Y-m-d');

// Get performance statistics for cards
$stats_sql = "
    SELECT 
        COUNT(DISTINCT us.user_id) as total_users,
        COUNT(DISTINCT u.branch) as total_branches,
        COUNT(DISTINCT us.kpi_id) as total_kpis,
        COUNT(us.id) as total_submissions
    FROM user_submissions us
    JOIN users u ON us.user_id = u.id
    WHERE us.status = 'approved'
";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Performance Overview - Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<style>
:root {
    --primary: #4361ee;
    --primary-light: #4895ef;
    --secondary: #3f37c9;
    --success: #4cc9f0;
    --dark: #2b2d42;
    --light: #f8f9fa;
    --danger: #e63946;
    --gray: #adb5bd;
    --sidebar-width: 250px;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background-color: #f5f7fb;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    display: flex;
    min-height: 100vh;
}

/* Sidebar Styles */
.sidebar {
    width: var(--sidebar-width);
    background: linear-gradient(180deg, var(--primary) 0%, var(--secondary) 100%);
    color: white;
    padding: 0;
    position: fixed;
    height: 100vh;
    overflow-y: auto;
    transition: all 0.3s;
    box-shadow: 3px 0 10px rgba(0, 0, 0, 0.1);
    z-index: 1000;
}

.sidebar-header {
    padding: 25px 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    text-align: center;
}

.sidebar-header h4 {
    margin: 0;
    font-weight: 600;
    font-size: 1.4rem;
}

.sidebar-menu {
    list-style: none;
    padding: 20px 0;
}

.sidebar-menu li {
    margin-bottom: 5px;
}

.sidebar-menu a {
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    padding: 12px 20px;
    transition: all 0.3s;
    border-left: 3px solid transparent;
}

.sidebar-menu a:hover, .sidebar-menu a.active {
    background-color: rgba(255, 255, 255, 0.1);
    border-left-color: white;
}

.sidebar-menu i {
    width: 25px;
    font-size: 1.1rem;
    margin-right: 10px;
}

/* Main Content */
.main-content {
    flex: 1;
    margin-left: var(--sidebar-width);
    padding: 20px;
    transition: all 0.3s;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eaeaea;
}

.page-header h2 {
    color: var(--dark);
    font-weight: 600;
    margin: 0;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
}

/* Cards */
.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    transition: transform 0.3s, box-shadow 0.3s;
    margin-bottom: 20px;
    overflow: hidden;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
}

.card-header {
    background: white;
    border-bottom: 1px solid #eaeaea;
    padding: 15px 20px;
    font-weight: 600;
    color: var(--dark);
}

.card-body {
    padding: 20px;
}

.stats-card {
    text-align: center;
    padding: 25px 15px;
    border-radius: 12px;
    background: white;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    transition: all 0.3s;
    margin-bottom: 20px;
}

.stats-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
}

.stats-card i {
    font-size: 2.5rem;
    margin-bottom: 15px;
    color: var(--primary);
}

.stats-card h3 {
    font-size: 2rem;
    font-weight: 700;
    margin: 10px 0;
    color: var(--dark);
}

.stats-card p {
    color: var(--gray);
    margin: 0;
}

.stats-card.users { border-top: 4px solid #4361ee; }
.stats-card.branches { border-top: 4px solid #4cc9f0; }
.stats-card.kpis { border-top: 4px solid #3a0ca3; }
.stats-card.submissions { border-top: 4px solid #06d6a0; }

/* Tables */
.table-container {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    margin-bottom: 30px;
}

.table {
    margin: 0;
}

.table thead th {
    background-color: var(--primary);
    color: white;
    border: none;
    padding: 15px 12px;
    font-weight: 600;
    position: sticky;
    top: 0;
    z-index: 10;
}

.table tbody td {
    padding: 12px;
    vertical-align: middle;
    border-color: #f0f0f0;
}

.table tbody tr:hover {
    background-color: #f8f9ff;
}

/* Buttons */
.btn {
    border-radius: 6px;
    font-weight: 500;
    padding: 8px 16px;
    transition: all 0.3s;
    border: none;
}

.btn-primary {
    background: linear-gradient(to right, var(--primary), var(--secondary));
    box-shadow: 0 4px 10px rgba(67, 97, 238, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(67, 97, 238, 0.4);
}

.btn-sm {
    padding: 6px 12px;
    font-size: 0.875rem;
}

/* Filters */
.filter-section {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    margin-bottom: 25px;
}

.form-control, .form-select {
    border-radius: 8px;
    border: 1px solid #e1e5ee;
    padding: 10px 15px;
    transition: all 0.3s;
}

.form-control:focus, .form-select:focus {
    border-color: var(--primary-light);
    box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.fade-in {
    animation: fadeIn 0.5s ease forwards;
}

/* Responsive */
@media (max-width: 992px) {
    .sidebar {
        width: 70px;
        overflow: visible;
    }
    
    .sidebar-header h4, .sidebar-menu span {
        display: none;
    }
    
    .sidebar-menu a {
        justify-content: center;
        padding: 15px;
    }
    
    .sidebar-menu i {
        margin-right: 0;
        font-size: 1.3rem;
    }
    
    .main-content {
        margin-left: 70px;
    }
    
    .date-range-filters {
        flex-direction: column;
    }
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 15px;
    }
    
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.active {
        transform: translateX(0);
    }
    
    .mobile-menu-btn {
        display: block;
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1100;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 6px;
        width: 40px;
        height: 40px;
        font-size: 1.2rem;
    }
}

/* Section dividers */
.section-divider {
    margin: 40px 0 30px;
    position: relative;
    text-align: center;
}

.section-divider:before {
    content: "";
    position: absolute;
    top: 50%;
    left: 0;
    right: 0;
    height: 1px;
    background: #eaeaea;
    z-index: 1;
}

.section-divider h4 {
    display: inline-block;
    background: #f5f7fb;
    padding: 0 20px;
    position: relative;
    z-index: 2;
    color: var(--dark);
    font-weight: 600;
}

/* Badges */
.badge {
    padding: 6px 10px;
    border-radius: 6px;
    font-weight: 500;
}

.badge-primary {
    background: linear-gradient(to right, var(--primary), var(--secondary));
    color: white;
}

/* Performance value styling */
.performance-value {
    font-weight: 600;
    color: var(--dark);
}

.performance-value.high {
    color: #06d6a0;
}

.performance-value.medium {
    color: #ffd166;
}

.performance-value.low {
    color: #ef476f;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--gray);
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    color: #e1e5ee;
}

/* Export button */
.export-btn {
    background: linear-gradient(to right, #06d6a0, #048a81);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s;
    box-shadow: 0 4px 10px rgba(6, 214, 160, 0.3);
}

.export-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(6, 214, 160, 0.4);
    color: white;
}

/* Advanced search styling */
.advanced-search {
    background: #f8f9ff;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    border-left: 4px solid var(--primary);
}

.advanced-search h6 {
    color: var(--primary);
    margin-bottom: 15px;
    font-weight: 600;
}

/* Table row highlighting */
.branch-row {
    background-color: #f0f4ff !important;
    font-weight: 600;
}

.user-row {
    background-color: #f8f9ff !important;
}

/* Search highlight */
.highlight {
    background-color: #fff3cd;
    padding: 2px 4px;
    border-radius: 3px;
}

/* Results counter */
.results-counter {
    background: var(--primary);
    color: white;
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 500;
    display: inline-block;
    margin-bottom: 15px;
}

/* Date range filters */
.date-range-filters {
    display: flex;
    gap: 10px;
    align-items: center;
}

.date-range-filters .form-control {
    flex: 1;
}

/* KPI columns styling */
.kpi-column {
    min-width: 120px;
    text-align: center;
}

.kpi-value {
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 4px;
    display: inline-block;
    min-width: 60px;
}

.kpi-value.high {
    background-color: rgba(6, 214, 160, 0.1);
    color: #06d6a0;
}

.kpi-value.medium {
    background-color: rgba(255, 209, 102, 0.1);
    color: #ff9e00;
}

.kpi-value.low {
    background-color: rgba(239, 71, 111, 0.1);
    color: #ef476f;
}

/* Sticky columns */
.sticky-column {
    position: sticky;
    left: 0;
    background: white;
    z-index: 5;
    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
}

.sticky-column-2 {
    position: sticky;
    left: 150px;
    background: white;
    z-index: 5;
    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
}

/* Compact table */
.compact-table th, .compact-table td {
    padding: 8px 10px;
    font-size: 0.9rem;
}
</style>
</head>
<body>
<!-- Mobile Menu Button -->
<button class="mobile-menu-btn d-lg-none">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <h4><i class="fas fa-crown me-2"></i>Admin Panel</h4>
    </div>
    <ul class="sidebar-menu">
        <li><a href="admin_panel.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
        <li><a href="admin_panel.php"><i class="fas fa-users"></i> <span>Manage Users</span></a></li>
        <li><a href="admin_panel.php"><i class="fas fa-chart-line"></i> <span>Manage KPIs</span></a></li>
        <li><a href="admin_performance.php" class="active"><i class="fas fa-chart-bar"></i> <span>Performance Overview</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
    </ul>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="page-header">
        <h2>Performance Overview</h2>
        <div class="user-info">
            <div class="user-avatar">
                <?php 
                    $username = $_SESSION['username'];
                    echo strtoupper(substr($username, 0, 1)); 
                ?>
            </div>
            <span>Welcome, <strong><?php echo $username; ?></strong></span>
        </div>
    </div>

    <!-- Performance Stats Cards -->
    <div class="row">
        <div class="col-md-3">
            <div class="stats-card users fade-in">
                <i class="fas fa-users"></i>
                <h3><?php echo $stats['total_users'] ?? 0; ?></h3>
                <p>Active Users</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card branches fade-in">
                <i class="fas fa-building"></i>
                <h3><?php echo $stats['total_branches'] ?? 0; ?></h3>
                <p>Branches</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card kpis fade-in">
                <i class="fas fa-chart-line"></i>
                <h3><?php echo $stats['total_kpis'] ?? 0; ?></h3>
                <p>Tracked KPIs</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card submissions fade-in">
                <i class="fas fa-clipboard-check"></i>
                <h3><?php echo $stats['total_submissions'] ?? 0; ?></h3>
                <p>Total Submissions</p>
            </div>
        </div>
    </div>

    <!-- Advanced Search Section -->
    <div class="filter-section">
        <div class="advanced-search">
            <h6><i class="fas fa-search me-2"></i>Advanced Search</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="globalSearch" class="form-control" placeholder="Search across all data...">
                    </div>
                </div>
                <div class="col-md-2">
                    <select id="branchFilter" class="form-select">
                        <option value="">All Branches</option>
                        <?php foreach($branches as $branch): ?>
                            <option value="<?= htmlspecialchars($branch) ?>"><?= htmlspecialchars($branch) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="userFilter" class="form-select">
                        <option value="">All Users</option>
                        <?php foreach($users as $user): ?>
                            <option value="<?= htmlspecialchars($user) ?>"><?= htmlspecialchars($user) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="kpiFilter" class="form-select">
                        <option value="">All KPIs</option>
                        <?php foreach($kpi_names as $kpi): ?>
                            <option value="<?= htmlspecialchars($kpi) ?>"><?= htmlspecialchars($kpi) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" id="exportBtn" title="Export Data">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
            </div>
            
            <!-- Date Range Filters -->
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label fw-semibold">Date Range</label>
                    <div class="date-range-filters">
                        <input type="date" id="dateFrom" class="form-control" value="<?= $min_date ?>" min="<?= $min_date ?>" max="<?= $max_date ?>">
                        <span class="text-muted">to</span>
                        <input type="date" id="dateTo" class="form-control" value="<?= $max_date ?>" min="<?= $min_date ?>" max="<?= $max_date ?>">
                        <button class="btn btn-outline-primary" id="applyDateRange">
                            <i class="fas fa-filter me-1"></i> Apply
                        </button>
                        <button class="btn btn-outline-secondary" id="resetDateRange">
                            <i class="fas fa-times me-1"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="d-flex justify-content-between align-items-center mt-3">
            <div id="resultsCounter" class="results-counter">
                Showing data for <?= count($users) ?> users across <?= count($branches) ?> branches
            </div>
            <div>
                <button class="btn btn-outline-primary btn-sm" id="resetFilters">
                    <i class="fas fa-refresh me-1"></i> Reset All Filters
                </button>
            </div>
        </div>
    </div>

    <!-- Performance Data Table -->
    <div class="table-container">
        <div class="table-responsive" style="max-height: 600px; overflow: auto;">
            <table class="table table-striped table-hover compact-table" id="performanceTable">
                <thead>
                    <tr>
                        <th class="sticky-column">Branch</th>
                        <th class="sticky-column-2">User</th>
                        <th>Date</th>
                        <?php foreach($kpi_names as $kpi): ?>
                            <th class="kpi-column"><?= htmlspecialchars($kpi) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($pivoted_data)): ?>
                        <?php 
                        $current_branch = '';
                        $current_user = '';
                        foreach ($pivoted_data as $branch => $users_data): 
                            foreach ($users_data as $user => $dates_data):
                                foreach ($dates_data as $date => $kpis_data):
                                    $is_new_branch = $current_branch !== $branch;
                                    $is_new_user = $current_user !== $user;
                                    
                                    $current_branch = $branch;
                                    $current_user = $user;
                        ?>
                            <tr class="<?= $is_new_branch ? 'branch-row' : ($is_new_user ? 'user-row' : '') ?>">
                                <td class="fw-semibold sticky-column"><?= $is_new_branch ? htmlspecialchars($branch) : '' ?></td>
                                <td class="fw-semibold sticky-column-2"><?= $is_new_user ? htmlspecialchars($user) : '' ?></td>
                                <td><?= htmlspecialchars($date) ?></td>
                                <?php foreach($kpi_names as $kpi): 
                                    $value = $kpis_data[$kpi] ?? '-';
                                    $value_class = '';
                                    if ($value !== '-' && is_numeric($value)) {
                                        if ($value >= 80) $value_class = 'high';
                                        elseif ($value >= 60) $value_class = 'medium';
                                        else $value_class = 'low';
                                    }
                                ?>
                                    <td class="kpi-column">
                                        <?php if ($value !== '-'): ?>
                                            <span class="kpi-value <?= $value_class ?>"><?= htmlspecialchars($value) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php 
                                endforeach;
                            endforeach;
                        endforeach; 
                        ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= count($kpi_names) + 3 ?>" class="text-center py-4">
                                <div class="empty-state">
                                    <i class="fas fa-chart-bar"></i>
                                    <h4>No Performance Data Available</h4>
                                    <p>There are no approved performance submissions to display.</p>
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

    // Initialize variables
    let allData = <?php echo json_encode($pivoted_data); ?>;
    let allKpis = <?php echo json_encode(array_values($kpi_names)); ?>;
    
    // Filter functionality
    $('#globalSearch, #branchFilter, #userFilter, #kpiFilter').on('keyup change', function() {
        filterPerformanceData();
    });
    
    // Date range functionality
    $('#applyDateRange').click(function() {
        filterPerformanceData();
    });
    
    // Reset date range
    $('#resetDateRange').click(function() {
        $('#dateFrom').val('<?= $min_date ?>');
        $('#dateTo').val('<?= $max_date ?>');
        filterPerformanceData();
    });
    
    // Reset all filters
    $('#resetFilters').click(function() {
        $('#globalSearch').val('');
        $('#branchFilter').val('');
        $('#userFilter').val('');
        $('#kpiFilter').val('');
        $('#dateFrom').val('<?= $min_date ?>');
        $('#dateTo').val('<?= $max_date ?>');
        filterPerformanceData();
    });

    // Export button functionality
    $('#exportBtn').click(function() {
        alert('Export functionality would be implemented here. This would generate a CSV/PDF report of all performance data.');
        // In a real implementation, this would trigger a server-side export
    });

    function filterPerformanceData() {
        const searchValue = $('#globalSearch').val().toLowerCase();
        const branchValue = $('#branchFilter').val();
        const userValue = $('#userFilter').val();
        const kpiValue = $('#kpiFilter').val();
        const dateFrom = $('#dateFrom').val();
        const dateTo = $('#dateTo').val();
        
        let filteredData = {};
        let userCount = 0;
        let branchCount = 0;
        let totalRows = 0;

        // Loop through all data and apply filters
        Object.keys(allData).forEach(branch => {
            // Apply branch filter
            if (branchValue && branch !== branchValue) {
                return;
            }

            if (!filteredData[branch]) {
                filteredData[branch] = {};
                branchCount++;
            }

            Object.keys(allData[branch]).forEach(user => {
                // Apply user filter
                if (userValue && user !== userValue) {
                    return;
                }

                if (!filteredData[branch][user]) {
                    filteredData[branch][user] = {};
                    userCount++;
                }

                Object.keys(allData[branch][user]).forEach(date => {
                    // Apply date range filter
                    if (dateFrom && date < dateFrom) {
                        return;
                    }
                    if (dateTo && date > dateTo) {
                        return;
                    }

                    const kpisData = allData[branch][user][date];
                    
                    // Apply KPI filter
                    if (kpiValue) {
                        // Check if this row has the selected KPI with a value
                        let hasKpiValue = false;
                        Object.keys(kpisData).forEach(kpiName => {
                            if (kpiName === kpiValue && kpisData[kpiName] !== '-') {
                                hasKpiValue = true;
                            }
                        });
                        if (!hasKpiValue) {
                            return;
                        }
                    }

                    // Apply global search filter
                    if (searchValue) {
                        let hasMatch = false;
                        
                        // Check branch, user, date
                        if (branch.toLowerCase().includes(searchValue) || 
                            user.toLowerCase().includes(searchValue) || 
                            date.includes(searchValue)) {
                            hasMatch = true;
                        }
                        
                        // Check KPI names and values
                        if (!hasMatch) {
                            Object.keys(kpisData).forEach(kpiName => {
                                const value = kpisData[kpiName];
                                if (kpiName.toLowerCase().includes(searchValue) || 
                                    (value !== '-' && value.toString().toLowerCase().includes(searchValue))) {
                                    hasMatch = true;
                                }
                            });
                        }
                        
                        if (!hasMatch) {
                            return;
                        }
                    }

                    // If we passed all filters, add to filtered data
                    if (!filteredData[branch][user][date]) {
                        filteredData[branch][user][date] = {};
                    }
                    filteredData[branch][user][date] = {...kpisData};
                    totalRows++;
                });

                // Remove empty users
                if (Object.keys(filteredData[branch][user]).length === 0) {
                    delete filteredData[branch][user];
                    userCount--;
                }
            });

            // Remove empty branches
            if (Object.keys(filteredData[branch]).length === 0) {
                delete filteredData[branch];
                branchCount--;
            }
        });

        // Update table with filtered data
        updateTable(filteredData);
        
        // Update results counter
        $('#resultsCounter').text(`Showing ${totalRows} records for ${userCount} users across ${branchCount} branches`);
    }
    
    function updateTable(filteredData) {
        const tbody = $('#performanceTable tbody');
        tbody.empty();
        
        if (Object.keys(filteredData).length === 0) {
            tbody.append(`
                <tr>
                    <td colspan="${allKpis.length + 3}" class="text-center py-4">
                        <div class="empty-state">
                            <i class="fas fa-search"></i>
                            <h4>No Results Found</h4>
                            <p>Try adjusting your search criteria or filters.</p>
                        </div>
                    </td>
                </tr>
            `);
            return;
        }
        
        const searchValue = $('#globalSearch').val().toLowerCase();
        const kpiValue = $('#kpiFilter').val();
        
        // Helper function to highlight search terms
        const highlightText = (text) => {
            if (!searchValue || !text) return text;
            const regex = new RegExp(`(${searchValue.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
            return text.toString().replace(regex, '<mark class="highlight">$1</mark>');
        };
        
        let currentBranch = '';
        let currentUser = '';
        let rowCount = 0;

        Object.keys(filteredData).forEach(branch => {
            Object.keys(filteredData[branch]).forEach(user => {
                Object.keys(filteredData[branch][user]).forEach(date => {
                    const isNewBranch = currentBranch !== branch;
                    const isNewUser = currentUser !== user;
                    
                    currentBranch = branch;
                    currentUser = user;
                    
                    const rowClass = isNewBranch ? 'branch-row' : (isNewUser ? 'user-row' : '');
                    
                    // Build KPI columns
                    let kpiColumns = '';
                    allKpis.forEach(kpi => {
                        const value = filteredData[branch][user][date][kpi] || '-';
                        let valueClass = '';
                        let displayValue = value;
                        
                        if (value !== '-' && !isNaN(value)) {
                            const numValue = parseFloat(value);
                            if (numValue >= 80) valueClass = 'high';
                            else if (numValue >= 60) valueClass = 'medium';
                            else valueClass = 'low';
                            
                            // Apply KPI filter highlighting
                            if (kpiValue && kpi === kpiValue) {
                                valueClass += ' highlight-kpi';
                                displayValue = `<strong>${value}</strong>`;
                            }
                        }
                        
                        // Apply search highlighting to values
                        if (searchValue && value !== '-' && value.toString().toLowerCase().includes(searchValue)) {
                            displayValue = highlightText(value.toString());
                        } else if (value !== '-') {
                            displayValue = value;
                        }
                        
                        kpiColumns += `
                            <td class="kpi-column">
                                ${value !== '-' ? 
                                    `<span class="kpi-value ${valueClass}">${displayValue}</span>` : 
                                    '<span class="text-muted">-</span>'
                                }
                            </td>
                        `;
                    });
                    
                    const branchDisplay = isNewBranch ? highlightText(branch) : '';
                    const userDisplay = isNewUser ? highlightText(user) : '';
                    const dateDisplay = highlightText(date);
                    
                    tbody.append(`
                        <tr class="${rowClass}">
                            <td class="fw-semibold sticky-column">${branchDisplay}</td>
                            <td class="fw-semibold sticky-column-2">${userDisplay}</td>
                            <td>${dateDisplay}</td>
                            ${kpiColumns}
                        </tr>
                    `);
                    
                    rowCount++;
                });
            });
        });
    }
    
    // Add CSS for KPI highlight
    $('head').append(`
        <style>
            .highlight-kpi {
                border: 2px solid #4361ee !important;
                background-color: rgba(67, 97, 238, 0.1) !important;
            }
            mark.highlight {
                background-color: #fff3cd;
                padding: 2px 4px;
                border-radius: 3px;
                font-weight: bold;
            }
        </style>
    `);

    // Initialize with current data
    filterPerformanceData();
    
    // Add real-time search for better UX
    let searchTimer;
    $('#globalSearch').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            filterPerformanceData();
        }, 300);
    });
    
    // Add enter key support
    $('#globalSearch').on('keypress', function(e) {
        if (e.which === 13) {
            filterPerformanceData();
        }
    });
});
</script>
</body>
</html>