<?php
include 'config.php';
session_start();

/* ==========================
   FETCH BRANCH PERFORMANCE (APPROVED ONLY)
   ========================== */
$branch_query = "
    SELECT branch, 
           ROUND(AVG(value), 2) AS avg_performance
    FROM user_submissions
    WHERE status = 'approved'
    GROUP BY branch
";
$branch_result = $conn->query($branch_query);
if (!$branch_result) {
    die('Branch Query Error: ' . $conn->error);
}
$branch_performance = [];
while ($row = $branch_result->fetch_assoc()) {
    $branch_performance[] = $row;
}

/* ==========================
   FETCH PERFORMANCE TRENDS (APPROVED ONLY)
   ========================== */
$trend_query = "
    SELECT DATE_FORMAT(submission_date, '%b %Y') AS month, 
           ROUND(AVG(value), 2) AS avg_performance
    FROM user_submissions
    WHERE status = 'approved'
    GROUP BY DATE_FORMAT(submission_date, '%Y-%m')
    ORDER BY submission_date ASC
";
$trend_result = $conn->query($trend_query);
if (!$trend_result) {
    die('Trend Query Error: ' . $conn->error);
}
$performance_trends = [];
while ($row = $trend_result->fetch_assoc()) {
    $performance_trends[] = $row;
}

/* ==========================
   FETCH RECENT ACTIVITY (APPROVED ONLY)
   ========================== */
$activity_query = "
    SELECT u.username, us.branch, k.kpi_name, 
           us.value, 
           DATE(us.submission_date) AS submission_date, 
           us.status
    FROM user_submissions us
    JOIN users u ON us.user_id = u.id
    JOIN kpis k ON us.kpi_id = k.id
    WHERE us.status = 'approved'
    ORDER BY us.submission_date DESC
    LIMIT 10
";
$activity_result = $conn->query($activity_query);
if (!$activity_result) {
    die('Activity Query Error: ' . $conn->error);
}
$recent_activity = [];
while ($row = $activity_result->fetch_assoc()) {
    $recent_activity[] = $row;
}

/* ==========================
   FETCH SUMMARY STATISTICS (APPROVED ONLY)
   ========================== */
$stats_query = "
    SELECT 
        COUNT(DISTINCT us.id) as total_submissions,
        COUNT(DISTINCT us.user_id) as active_users,
        COUNT(DISTINCT us.branch) as branches_covered,
        ROUND(AVG(us.value), 2) as overall_performance
    FROM user_submissions us
    WHERE us.status = 'approved'
";
$stats_result = $conn->query($stats_query);
if (!$stats_result) {
    die('Stats Query Error: ' . $conn->error);
}
$summary_stats = $stats_result->fetch_assoc();

/* ==========================
   FETCH KPI PERFORMANCE (APPROVED ONLY)
   ========================== */
$kpi_query = "
    SELECT 
        k.kpi_name,
        ROUND(AVG(us.value), 2) as avg_performance,
        COUNT(us.id) as submission_count
    FROM user_submissions us
    JOIN kpis k ON us.kpi_id = k.id
    WHERE us.status = 'approved'
    GROUP BY k.kpi_name
    ORDER BY avg_performance DESC
";
$kpi_result = $conn->query($kpi_query);
if (!$kpi_result) {
    die('KPI Query Error: ' . $conn->error);
}
$kpi_performance = [];
while ($row = $kpi_result->fetch_assoc()) {
    $kpi_performance[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>District Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --sidebar-width: 260px;
            --header-height: 70px;
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
            display: flex;
            min-height: 100vh;
        }

        /* ============== SIDEBAR ============== */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary), var(--secondary));
            color: white;
            height: 100vh;
            position: fixed;
            transition: var(--transition);
            z-index: 1000;
            box-shadow: var(--box-shadow);
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h2 {
            font-size: 1.4rem;
            font-weight: 600;
        }

        .sidebar ul {
            list-style: none;
            padding: 20px 0;
        }

        .sidebar li {
            margin-bottom: 5px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
        }

        .sidebar a:hover, .sidebar a.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 4px solid white;
        }

        .sidebar i {
            margin-right: 12px;
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        /* ============== MAIN CONTENT ============== */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition);
            width: calc(100% - var(--sidebar-width));
        }

        .header {
            height: var(--header-height);
            background: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            box-shadow: var(--box-shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header h1 {
            font-size: 1.6rem;
            font-weight: 600;
            color: var(--dark);
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.4rem;
            color: var(--gray);
            cursor: pointer;
        }

        /* ============== DASHBOARD ============== */
        .dashboard {
            padding: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
        }

        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 24px;
            transition: var(--transition);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
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

        .full-width {
            grid-column: 1 / -1;
        }

        /* ============== STATS CARDS ============== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            text-align: center;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card i {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--primary);
        }

        .stat-card h4 {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
        }

        /* ============== KPI CARDS ============== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 10px;
        }

        .kpi-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            border-left: 4px solid var(--primary);
        }

        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .kpi-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .kpi-name {
            font-weight: 600;
            color: var(--dark);
            font-size: 1rem;
            margin-bottom: 5px;
        }

        .kpi-submissions {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .kpi-performance {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .performance-bar {
            height: 8px;
            background-color: var(--gray-light);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 10px;
        }

        .performance-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 1s ease-in-out;
        }

        .performance-high {
            background: linear-gradient(90deg, #4cc9f0, #4361ee);
        }

        .performance-medium {
            background: linear-gradient(90deg, #f8961e, #f3722c);
        }

        .performance-low {
            background: linear-gradient(90deg, #f72585, #f94144);
        }

        .performance-label {
            font-size: 0.8rem;
            color: var(--gray);
            display: flex;
            justify-content: space-between;
        }

        /* ============== CHARTS ============== */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* ============== TABLE ============== */
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

        /* ============== BADGES ============== */
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .badge-success {
            background-color: rgba(76, 201, 240, 0.15);
            color: #4cc9f0;
        }

        .badge-warning {
            background-color: rgba(248, 150, 30, 0.15);
            color: #f8961e;
        }

        .badge-primary {
            background-color: rgba(67, 97, 238, 0.15);
            color: var(--primary);
        }

        /* ============== PERFORMANCE INDICATORS ============== */
        .performance-value {
            font-weight: 600;
        }

        .performance-value.high {
            color: #4cc9f0;
        }

        .performance-value.medium {
            color: #f8961e;
        }

        .performance-value.low {
            color: #f72585;
        }

        /* ============== EMPTY STATE ============== */
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

        .text-center {
            text-align: center;
        }

        /* ============== RESPONSIVE DESIGN ============== */
        @media (max-width: 992px) {
            .dashboard {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .mobile-menu-btn {
                display: block;
            }
        }

        @media (max-width: 576px) {
            .dashboard {
                padding: 15px;
                grid-template-columns: 1fr;
            }
            
            .card {
                padding: 20px;
            }
            
            .header {
                padding: 0 20px;
            }
            
            .header h1 {
                font-size: 1.3rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<!-- ============== SIDEBAR ============== -->
<div class="sidebar">
    <div class="sidebar-header">
        <h2><i class="fas fa-chart-line"></i> District Panel</h2>
    </div>
    <ul>
        <!-- In the sidebar section of district_dashboard.php -->
<ul>
    <li><a href="district_dashboard.php" class="active"><i class="fas fa-chart-line"></i> Dashboard</a></li>
    <li><a href="performance_tracking.php"><i class="fas fa-chart-bar"></i> Performance Tracking</a></li>
    <li><a href="leaderboard.php"><i class="fas fa-trophy me-1"></i>Leaderboard</a></li>
    <li><a href="district_analytics.php"><i class="fas fa-chart-pie"></i> Analytics</a></li>
    <li><a href="upload_targets.php"><i class="fas fa-bullseye"></i> Staff Targets</a></li>
    <li><a href="district_profile.php"><i class="fas fa-user"></i> Profile</a></li>
    <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
</ul>
    </ul>
</div>

<!-- ============== MAIN CONTENT ============== -->
<div class="main-content">
    <div class="header">
        <button class="mobile-menu-btn"><i class="fas fa-bars"></i></button>
        <h1>District Dashboard</h1>
        <div></div> <!-- Empty div for flex spacing -->
    </div>

    <div class="dashboard">
        <!-- Summary Statistics -->
        <div class="full-width">
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-file-alt"></i>
                    <h4>Total Submissions</h4>
                    <div class="value"><?= $summary_stats['total_submissions'] ?? 0 ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-users"></i>
                    <h4>Active Users</h4>
                    <div class="value"><?= $summary_stats['active_users'] ?? 0 ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-code-branch"></i>
                    <h4>Branches Covered</h4>
                    <div class="value"><?= $summary_stats['branches_covered'] ?? 0 ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-chart-line"></i>
                    <h4>Overall Performance</h4>
                    <div class="value"><?= $summary_stats['overall_performance'] ?? 0 ?>%</div>
                </div>
            </div>
        </div>

        <!-- KPI Performance -->
        <div class="card full-width">
            <h3><i class="fas fa-chart-bar"></i> District KPI Performance</h3>
            <div class="kpi-grid">
                <?php if (!empty($kpi_performance)): ?>
                    <?php foreach ($kpi_performance as $kpi): 
                        $performance_class = 'performance-low';
                        $performance_width = $kpi['avg_performance'] . '%';
                        
                        if ($kpi['avg_performance'] >= 80) {
                            $performance_class = 'performance-high';
                        } elseif ($kpi['avg_performance'] >= 50) {
                            $performance_class = 'performance-medium';
                        }
                    ?>
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <div>
                                <div class="kpi-name"><?= htmlspecialchars($kpi['kpi_name']) ?></div>
                                <div class="kpi-submissions"><?= $kpi['submission_count'] ?> submissions</div>
                            </div>
                            <div class="kpi-performance <?= $performance_class ?>"><?= $kpi['avg_performance'] ?>%</div>
                        </div>
                        <div class="performance-bar">
                            <div class="performance-fill <?= $performance_class ?>" style="width: <?= $performance_width ?>"></div>
                        </div>
                        <div class="performance-label">
                            <span>0%</span>
                            <span>100%</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-bar"></i>
                        <h4>No KPI data available</h4>
                        <p>No approved KPI submissions found yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Branch Performance -->
        <div class="card">
            <h3><i class="fas fa-building"></i> Branch Performance</h3>
            <div class="chart-container">
                <canvas id="branchPerformanceChart"></canvas>
            </div>
        </div>

        <!-- Performance Trends -->
        <div class="card">
            <h3><i class="fas fa-chart-line"></i> Performance Trends</h3>
            <div class="chart-container">
                <canvas id="performanceTrendsChart"></canvas>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="card full-width">
            <h3><i class="fas fa-history"></i> Recent Approved Activity</h3>
            <div class="table-responsive">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>User</th>
                            <th>Branch</th>
                            <th>KPI</th>
                            <th>Value</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($recent_activity)): ?>
                        <?php foreach ($recent_activity as $activity): 
                            $value_class = 'performance-value';
                            if (is_numeric($activity['value'])) {
                                if ($activity['value'] >= 80) $value_class .= ' high';
                                elseif ($activity['value'] >= 50) $value_class .= ' medium';
                                else $value_class .= ' low';
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($activity['submission_date']) ?></td>
                            <td><?= htmlspecialchars($activity['username']) ?></td>
                            <td><?= htmlspecialchars($activity['branch']) ?></td>
                            <td><?= htmlspecialchars($activity['kpi_name']) ?></td>
                            <td class="<?= $value_class ?>"><?= htmlspecialchars($activity['value']) ?></td>
                            <td>
                                <span class="badge badge-success">Approved</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center">
                                <div class="empty-state">
                                    <i class="fas fa-folder-open"></i>
                                    <h4>No approved activity</h4>
                                    <p>No approved submissions found yet.</p>
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

<!-- ============== JAVASCRIPT ============== -->
<script>
// Branch Performance Chart
const branchPerformanceData = <?= json_encode($branch_performance) ?>;
const branchLabels = branchPerformanceData.map(d => d.branch);
const branchValues = branchPerformanceData.map(d => d.avg_performance);

new Chart(document.getElementById('branchPerformanceChart'), {
    type: 'bar',
    data: {
        labels: branchLabels,
        datasets: [{
            label: 'Average Performance',
            data: branchValues,
            backgroundColor: [
                'rgba(67, 97, 238, 0.8)',
                'rgba(76, 201, 240, 0.8)',
                'rgba(67, 170, 139, 0.8)',
                'rgba(248, 150, 30, 0.8)',
                'rgba(247, 37, 133, 0.8)'
            ],
            borderRadius: 8,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(33, 37, 41, 0.9)',
                titleFont: { size: 14 },
                bodyFont: { size: 13 },
                padding: 10,
                cornerRadius: 8,
                callbacks: {
                    label: function(context) {
                        return `Performance: ${context.parsed.y}%`;
                    }
                }
            }
        },
        scales: { 
            y: { 
                beginAtZero: true,
                max: 100,
                ticks: { 
                    stepSize: 10,
                    callback: function(value) {
                        return value + '%';
                    }
                },
                grid: { color: 'rgba(0, 0, 0, 0.05)' }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});

// Performance Trends Chart
const trendData = <?= json_encode($performance_trends) ?>;
const trendLabels = trendData.map(d => d.month);
const trendValues = trendData.map(d => d.avg_performance);

new Chart(document.getElementById('performanceTrendsChart'), {
    type: 'line',
    data: {
        labels: trendLabels,
        datasets: [{
            label: 'Avg Performance',
            data: trendValues,
            fill: true,
            borderColor: '#4361ee',
            backgroundColor: 'rgba(67, 97, 238, 0.1)',
            tension: 0.4,
            pointRadius: 5,
            pointBackgroundColor: '#4361ee',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointHoverRadius: 7
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(33, 37, 41, 0.9)',
                titleFont: { size: 14 },
                bodyFont: { size: 13 },
                padding: 10,
                cornerRadius: 8,
                callbacks: {
                    label: function(context) {
                        return `Performance: ${context.parsed.y}%`;
                    }
                }
            }
        },
        scales: { 
            y: { 
                beginAtZero: true,
                max: 100,
                ticks: { 
                    callback: function(value) {
                        return value + '%';
                    }
                },
                grid: { color: 'rgba(0, 0, 0, 0.05)' }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});

// Animate KPI performance bars on page load
document.addEventListener('DOMContentLoaded', function() {
    const performanceBars = document.querySelectorAll('.performance-fill');
    performanceBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.width = width;
        }, 300);
    });
});

// Sidebar Toggle for Mobile
$('.mobile-menu-btn').click(() => {
    $('.sidebar').toggleClass('active');
});

// Close sidebar when clicking outside on mobile
$(document).on('click', (e) => {
    if ($(window).width() <= 992) {
        if (!$(e.target).closest('.sidebar').length && !$(e.target).closest('.mobile-menu-btn').length) {
            $('.sidebar').removeClass('active');
        }
    }
});
</script>

</body>
</html>