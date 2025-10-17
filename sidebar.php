<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
    <div class="sidebar-header">
        <h3>My Dashboard</h3>
    </div>
    <ul class="sidebar-menu">
        <li class="<?= ($current_page == 'user_dashboard.php') ? 'active' : '' ?>">
            <a href="user_dashboard.php">🏠 Dashboard</a>
        </li>
        <li class="<?= ($current_page == 'submit_performance.php') ? 'active' : '' ?>">
            <a href="submit_performance.php">📝 Submit Performance</a>
        </li>
        <li class="<?= ($current_page == 'view_performance.php') ? 'active' : '' ?>">
            <a href="view_performance.php">📊 View Data</a>
        </li>
        <li class="logout">
             <a href="logout.php">🚪 Logout</a>
        </li>

    </ul>
</div>

<style>
.sidebar {
    width: 220px;
    position: fixed;
    height: 100%;
    background: #2d3e50;
    color: #fff;
    padding-top: 20px;
    transition: width 0.3s;
    overflow-y: auto;
}

.sidebar-header {
    text-align: center;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.sidebar-header h3 {
    font-size: 20px;
    margin: 0;
    color: #fff;
}

.sidebar-menu {
    list-style: none;
    padding: 0;
    margin: 20px 0 0 0;
}

.sidebar-menu li {
    margin: 10px 0;
}

.sidebar-menu li a {
    text-decoration: none;
    color: #fff;
    padding: 10px 20px;
    display: block;
    border-radius: 8px;
    transition: background 0.3s;
}

.sidebar-menu li a:hover {
    background: #1c2b3a;
}

.sidebar-menu li.active a {
    background: #007bff;
    color: #fff;
}
.sidebar-menu li.logout a {
    background: #dc3545;
    color: #fff;
    margin-top: 20px;
}

.sidebar-menu li.logout a:hover {
    background: #c82333;
}

@media (max-width: 768px) {
    .sidebar {
        width: 60px;
        text-align: center;
    }
    .sidebar-menu li a {
        padding: 10px 5px;
    }
    .sidebar-header h3 {
        font-size: 16px;
    }
}
</style>
