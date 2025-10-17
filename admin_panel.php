<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Handle Delete (Users)
if (isset($_GET['delete_user'])) {
    $id = $_GET['delete_user'];
    $conn->query("DELETE FROM users WHERE id=$id");
    header("Location: admin_panel.php");
    exit();
}

// Handle Delete (KPIs)
if (isset($_GET['delete_kpi'])) {
    $id = $_GET['delete_kpi'];
    $conn->query("DELETE FROM kpis WHERE id=$id");
    header("Location: admin_panel.php");
    exit();
}

// Fetch users
$users = $conn->query("SELECT * FROM users");

// Fetch KPIs
$kpis = $conn->query("SELECT * FROM kpis");

// Get unique districts and branches for filters
$districts_result = $conn->query("SELECT DISTINCT district FROM users WHERE district IS NOT NULL AND district != ''");
$branches_result = $conn->query("SELECT DISTINCT branch FROM users WHERE branch IS NOT NULL AND branch != ''");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Panel | System Management</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --primary: #4361ee;
    --primary-light: #4895ef;
    --secondary: #3f37c9;
    --success: #4cc9f0;
    --district: #06d6a0;
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
.stats-card.kpis { border-top: 4px solid #4cc9f0; }
.stats-card.district { border-top: 4px solid #06d6a0; }

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

.btn-success {
    background: linear-gradient(to right, #4cc9f0, #3a86ff);
    box-shadow: 0 4px 10px rgba(76, 201, 240, 0.3);
}

.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(76, 201, 240, 0.4);
}

.btn-danger {
    background: linear-gradient(to right, #e63946, #d00000);
    box-shadow: 0 4px 10px rgba(230, 57, 70, 0.3);
}

.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(230, 57, 70, 0.4);
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

/* Modals */
.modal-content {
    border: none;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
}

.modal-header {
    background: linear-gradient(to right, var(--primary), var(--secondary));
    color: white;
    border-radius: 12px 12px 0 0;
    padding: 15px 20px;
}

.modal-title {
    font-weight: 600;
}

.modal-body {
    padding: 20px;
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

/* Action buttons */
.action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* Badges */
.badge {
    padding: 6px 10px;
    border-radius: 6px;
    font-weight: 500;
}

.badge-admin {
    background: linear-gradient(to right, #4361ee, #3a0ca3);
    color: white;
}

.badge-manager {
    background: linear-gradient(to right, #4cc9f0, #3a86ff);
    color: white;
}

.badge-user {
    background: linear-gradient(to right, #f72585, #b5179e);
    color: white;
}

.badge-district {
    background: linear-gradient(to right, #06d6a0, #048a81);
    color: white;
}

.badge-financial {
    background: linear-gradient(to right, #06d6a0, #048a81);
    color: white;
}

.badge-non-financial {
    background: linear-gradient(to right, #ff9e00, #ff5400);
    color: white;
}

/* Edit mode styling */
.edit-mode {
    background-color: #fff9e6 !important;
}

.edit-input {
    border: 1px solid #e1e5ee;
    border-radius: 6px;
    padding: 6px 10px;
    width: 100%;
    font-size: 0.875rem;
}

.edit-select {
    border: 1px solid #e1e5ee;
    border-radius: 6px;
    padding: 6px 10px;
    width: 100%;
    font-size: 0.875rem;
    background-color: white;
}

/* District-specific styling */
.district-field {
    background-color: rgba(6, 214, 160, 0.1);
    border-left: 3px solid var(--district);
}

.branch-field {
    background-color: rgba(67, 97, 238, 0.1);
    border-left: 3px solid var(--primary);
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
        <li><a href="#users" class="active"><i class="fas fa-users"></i> <span>Manage Users</span></a></li>
        <li><a href="#kpis"><i class="fas fa-chart-line"></i> <span>Manage KPIs</span></a></li>
        <li><a href="admin_performance.php"><i class="fas fa-chart-bar"></i> <span>Performance Overview</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
    </ul>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="page-header">
        <h2>Dashboard Overview</h2>
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

    <!-- Stats Cards -->
    <div class="row">
        <div class="col-md-3">
            <div class="stats-card users fade-in">
                <i class="fas fa-users"></i>
                <h3><?php echo $users->num_rows; ?></h3>
                <p>Total Users</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card kpis fade-in">
                <i class="fas fa-chart-bar"></i>
                <h3><?php echo $kpis->num_rows; ?></h3>
                <p>Total KPIs</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card district fade-in">
                <i class="fas fa-map-marked-alt"></i>
                <h3>
                    <?php 
                    $district_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'district'");
                    echo $district_users->fetch_assoc()['count'];
                    ?>
                </h3>
                <p>District Staff</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card fade-in" style="border-top: 4px solid #f72585;">
                <i class="fas fa-user-shield"></i>
                <h3>Admin</h3>
                <p>Role</p>
            </div>
        </div>
    </div>

    <!-- Users Section -->
    <div class="section-divider" id="users">
        <h4><i class="fas fa-users me-2"></i>User Management</h4>
    </div>

    <!-- User Filters -->
    <div class="filter-section">
        <div class="row g-3 align-items-center">
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" id="userSearch" class="form-control" placeholder="Search users...">
                </div>
            </div>
            <div class="col-md-2">
                <select id="roleFilter" class="form-select">
                    <option value="">All Roles</option>
                    <option value="admin">Admin</option>
                    <option value="manager">Manager</option>
                    <option value="district">District</option>
                    <option value="user">User</option>
                </select>
            </div>
            <div class="col-md-2">
                <select id="branchFilter" class="form-select">
                    <option value="">All Branches</option>
                    <?php while($branch = $branches_result->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($branch['branch']) ?>"><?= htmlspecialchars($branch['branch']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select id="districtFilter" class="form-select">
                    <option value="">All Districts</option>
                    <?php 
                    $districts_result->data_seek(0); // Reset pointer
                    while($district = $districts_result->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($district['district']) ?>"><?= htmlspecialchars($district['district']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-3">
                <div class="dropdown">
                    <button class="btn btn-primary w-100 dropdown-toggle" type="button" id="addDropdown" data-bs-toggle="dropdown">
                        <i class="fas fa-plus me-1"></i> Add User
                    </button>
                    <ul class="dropdown-menu" aria-labelledby="addDropdown">
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fas fa-user-plus me-2"></i>Add New User</a></li>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addKpiModal"><i class="fas fa-chart-line me-2"></i>Add New KPI</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- User Table -->
    <div class="table-container">
        <table class="table table-hover" id="usersTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Position</th>
                    <th>Branch</th>
                    <th>District</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $users->data_seek(0); // Reset pointer
                while($u = $users->fetch_assoc()) { 
                ?>
                <tr data-id="<?php echo $u['id']; ?>">
                    <td class="user-id"><?php echo $u['id']; ?></td>
                    <td class="username-text"><?php echo htmlspecialchars($u['username']); ?></td>
                    <td class="email-text"><?php echo htmlspecialchars($u['email']); ?></td>
                    <td>
                        <span class="badge badge-<?php echo $u['role']; ?>">
                            <?php echo $u['role']; ?>
                        </span>
                    </td>
                    <td class="position-text"><?php echo htmlspecialchars($u['position']); ?></td>
                    <td class="branch-text"><?php echo htmlspecialchars($u['branch']); ?></td>
                    <td class="district-text"><?php echo htmlspecialchars($u['district'] ?? 'N/A'); ?></td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-sm btn-primary edit-btn"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-success save-btn" style="display:none;"><i class="fas fa-check"></i></button>
                            <a href="?delete_user=<?php echo $u['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this user?');">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <!-- KPIs Section -->
    <div class="section-divider" id="kpis">
        <h4><i class="fas fa-chart-line me-2"></i>KPI Management</h4>
    </div>

    <!-- KPI Filters -->
    <div class="filter-section">
        <div class="row g-3 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" id="kpiSearch" class="form-control" placeholder="Search KPIs...">
                </div>
            </div>
            <div class="col-md-4">
                <select id="categoryFilter" class="form-select">
                    <option value="">All Categories</option>
                    <option value="Financial">Financial</option>
                    <option value="Non-Financial">Non-Financial</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addKpiModal">
                    <i class="fas fa-plus me-1"></i> Add KPI
                </button>
            </div>
        </div>
    </div>

    <!-- KPI Table -->
    <div class="table-container">
        <table class="table table-hover" id="kpiTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Category</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $kpis->data_seek(0); // Reset pointer
                while($k = $kpis->fetch_assoc()) { 
                ?>
                <tr data-id="<?php echo $k['id']; ?>">
                    <td class="kpi-id"><?php echo $k['id']; ?></td>
                    <td>
                        <span class="badge badge-<?php echo strtolower(str_replace('-', '', $k['kpi_category'])); ?>">
                            <?php echo htmlspecialchars($k['kpi_category']); ?>
                        </span>
                    </td>
                    <td class="kpi-name-text"><?php echo htmlspecialchars($k['kpi_name']); ?></td>
                    <td class="kpi-desc-text"><?php echo htmlspecialchars($k['kpi_description']); ?></td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-sm btn-primary edit-kpi-btn"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-success save-kpi-btn" style="display:none;"><i class="fas fa-check"></i></button>
                            <a href="?delete_kpi=<?php echo $k['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this KPI?');">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addUserForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" name="username" class="form-control" placeholder="Enter username" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" placeholder="Enter email" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select name="role" class="form-select" id="roleSelect" required>
                                    <option value="">Select Role</option>
                                    <option value="admin">Admin</option>
                                    <option value="manager">Manager</option>
                                    <option value="district">District Staff</option>
                                    <option value="user">User</option>
                                </select>
                            </div>
                            <div class="mb-3" id="positionField">
                                <label class="form-label">Position</label>
                                <input type="text" name="position" class="form-control" placeholder="Enter position">
                            </div>
                            <div class="mb-3 branch-field" id="branchField">
                                <label class="form-label">Branch</label>
                                <input type="text" name="branch" class="form-control" placeholder="Enter branch">
                            </div>
                            <div class="mb-3 district-field" id="districtField" style="display: none;">
                                <label class="form-label">District <span class="text-danger">*</span></label>
                                <input type="text" name="district" class="form-control" placeholder="Enter district" id="districtInput">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add KPI Modal -->
<div class="modal fade" id="addKpiModal" tabindex="-1" aria-labelledby="addKpiModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-chart-line me-2"></i>Add New KPI</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addKpiForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">KPI Name <span class="text-danger">*</span></label>
                        <input type="text" name="kpi_name" class="form-control" placeholder="Enter KPI name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category <span class="text-danger">*</span></label>
                        <select name="kpi_category" class="form-select" required>
                            <option value="">Select Category</option>
                            <option value="Financial">Financial</option>
                            <option value="Non-Financial">Non-Financial</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="kpi_description" class="form-control" placeholder="Enter description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add KPI</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Role-based field visibility
document.getElementById('roleSelect').addEventListener('change', function() {
    const role = this.value;
    const branchField = document.getElementById('branchField');
    const districtField = document.getElementById('districtField');
    const districtInput = document.getElementById('districtInput');
    const positionField = document.getElementById('positionField');

    // Reset and hide/show fields based on role
    branchField.style.display = 'block';
    districtField.style.display = 'none';
    
    if (role === 'district') {
        branchField.style.display = 'none';
        districtField.style.display = 'block';
        districtInput.required = true;
        // Auto-fill position for district staff
        document.querySelector('input[name="position"]').value = 'District Staff';
    } else {
        districtInput.required = false;
        if (role === 'user') {
            document.querySelector('input[name="position"]').value = 'Staff';
        } else if (role === 'manager') {
            document.querySelector('input[name="position"]').value = 'Branch Manager';
        } else if (role === 'admin') {
            document.querySelector('input[name="position"]').value = 'Administrator';
        }
    }
});

// Filter Logic
function filterUsers() {
    var searchValue = $("#userSearch").val().toLowerCase();
    var roleValue = $("#roleFilter").val().toLowerCase();
    var branchValue = $("#branchFilter").val().toLowerCase();
    var districtValue = $("#districtFilter").val().toLowerCase();

    $("#usersTable tbody tr").filter(function() {
        var text = $(this).text().toLowerCase();
        var role = $(this).find(".badge").text().toLowerCase();
        var branch = $(this).find(".branch-text").text().toLowerCase();
        var district = $(this).find(".district-text").text().toLowerCase();
        
        var match = text.indexOf(searchValue) > -1 &&
                    (roleValue === "" || role === roleValue) &&
                    (branchValue === "" || branch === branchValue) &&
                    (districtValue === "" || district === districtValue);
        $(this).toggle(match);
    });
}

$("#userSearch, #roleFilter, #branchFilter, #districtFilter").on("keyup change", filterUsers);

function filterKpis() {
    var searchValue = $("#kpiSearch").val().toLowerCase();
    var categoryValue = $("#categoryFilter").val().toLowerCase();

    $("#kpiTable tbody tr").filter(function() {
        var text = $(this).text().toLowerCase();
        var category = $(this).find(".badge").text().toLowerCase();
        var match = text.indexOf(searchValue) > -1 &&
                    (categoryValue === "" || category === categoryValue);
        $(this).toggle(match);
    });
}

$("#kpiSearch, #categoryFilter").on("keyup change", filterKpis);

// Persist Filters
function saveFilters() {
    const filters = {
        userSearch: $("#userSearch").val(),
        roleFilter: $("#roleFilter").val(),
        branchFilter: $("#branchFilter").val(),
        districtFilter: $("#districtFilter").val(),
        kpiSearch: $("#kpiSearch").val(),
        categoryFilter: $("#categoryFilter").val()
    };
    localStorage.setItem("adminFilters", JSON.stringify(filters));
}

// Role-based field visibility for Add User Modal
document.getElementById('roleSelect').addEventListener('change', function() {
    const role = this.value;
    const branchField = document.getElementById('branchField');
    const districtField = document.getElementById('districtField');
    const districtInput = document.getElementById('districtInput');
    const positionInput = document.querySelector('input[name="position"]');

    // Reset fields
    branchField.style.display = 'block';
    districtField.style.display = 'none';
    districtInput.required = false;
    
    if (role === 'district') {
        // Hide branch field, show district field for district staff
        branchField.style.display = 'none';
        districtField.style.display = 'block';
        districtInput.required = true;
        
        // Auto-set position for district staff
        positionInput.value = 'District Staff';
    } else {
        // Auto-set positions for other roles
        switch (role) {
            case 'admin':
                positionInput.value = 'Administrator';
                break;
            case 'manager':
                positionInput.value = 'Branch Manager';
                break;
            case 'user':
                positionInput.value = 'Staff';
                break;
        }
    }
});

// Add User AJAX
$('#addUserForm').on('submit', function(e){
    e.preventDefault();

    // Get form data
    const formData = $(this).serialize();
    
    $.ajax({
        url: 'add_user_ajax.php',
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function(res){
            alert(res.message);
            if(res.status === 'success'){
                $('#addUserModal').modal('hide');
                location.reload();
            }
        },
        error: function(xhr,status,error){
            alert("AJAX Error: " + error);
        }
    });
});

// Add User AJAX
$('#addUserForm').on('submit', function(e){
    e.preventDefault();

    // Get form data
    const formData = $(this).serialize();
    
    $.ajax({
        url: 'add_user_ajax.php',
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function(res){
            alert(res.message);
            if(res.status === 'success'){
                $('#addUserModal').modal('hide');
                location.reload();
            }
        },
        error: function(xhr,status,error){
            alert("AJAX Error: " + error);
        }
    });
});
function loadFilters() {
    const filters = JSON.parse(localStorage.getItem("adminFilters"));
    if (filters) {
        $("#userSearch").val(filters.userSearch);
        $("#roleFilter").val(filters.roleFilter);
        $("#branchFilter").val(filters.branchFilter);
        $("#districtFilter").val(filters.districtFilter);
        $("#kpiSearch").val(filters.kpiSearch);
        $("#categoryFilter").val(filters.categoryFilter);
        filterUsers();
        filterKpis();
    }
}

$(document).ready(function() {
    loadFilters();
    
    // Mobile menu toggle
    $('.mobile-menu-btn').click(function() {
        $('.sidebar').toggleClass('active');
    });
});

$("#userSearch, #roleFilter, #branchFilter, #districtFilter, #kpiSearch, #categoryFilter").on("keyup change", saveFilters);

// Add User AJAX
$('#addUserForm').on('submit', function(e){
    e.preventDefault();

    $.ajax({
        url: 'add_user_ajax.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(res){
            alert(res.message);
            if(res.status === 'success'){
                $('#addUserModal').modal('hide');
                location.reload();
            }
        },
        error: function(xhr,status,error){
            alert("AJAX Error: " + error);
        }
    });
});

// Add KPI AJAX
$('#addKpiForm').on('submit', function(e){
    e.preventDefault();

    $.ajax({
        url: 'add_kpi_ajax.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(res){
            alert(res.message);
            if(res.status === 'success'){
                $('#addKpiModal').modal('hide');
                location.reload();
            }
        },
        error: function(xhr,status,error){
            console.log(xhr.responseText);
            alert("AJAX Error: " + error);
        }
    });
});

// User Edit/Save functionality
document.addEventListener("DOMContentLoaded", () => {
    // Handle Edit button click for Users
    document.querySelectorAll(".edit-btn").forEach(button => {
        button.addEventListener("click", function () {
            const row = this.closest("tr");
            row.classList.add("edit-mode");

            // Convert cells to input/select fields
            row.querySelectorAll("td").forEach(td => {
                if (td.classList.contains("username-text")) {
                    td.innerHTML = `<input type="text" class="form-control edit-input username" value="${td.textContent.trim()}">`;
                }
                if (td.classList.contains("email-text")) {
                    td.innerHTML = `<input type="email" class="form-control edit-input email" value="${td.textContent.trim()}">`;
                }
                // Role cell
                if (td.querySelector(".badge")) {
                    const role = td.textContent.trim();
                    td.innerHTML = `
                        <select class="form-select edit-select role-select">
                            <option value="admin" ${role==="admin"?"selected":""}>Admin</option>
                            <option value="manager" ${role==="manager"?"selected":""}>Manager</option>
                            <option value="district" ${role==="district"?"selected":""}>District</option>
                            <option value="user" ${role==="user"?"selected":""}>User</option>
                        </select>`;
                }
                if (td.classList.contains("position-text")) {
                    td.innerHTML = `<input type="text" class="form-control edit-input position" value="${td.textContent.trim()}">`;
                }
                if (td.classList.contains("branch-text")) {
                    td.innerHTML = `<input type="text" class="form-control edit-input branch" value="${td.textContent.trim()}">`;
                }
                if (td.classList.contains("district-text")) {
                    td.innerHTML = `<input type="text" class="form-control edit-input district" value="${td.textContent.trim()}">`;
                }
            });

            // Toggle buttons
            row.querySelector(".edit-btn").style.display = "none";
            row.querySelector(".save-btn").style.display = "inline-block";
        });
    });

    // Handle Save button click for Users
    document.querySelectorAll(".save-btn").forEach(button => {
        button.addEventListener("click", function () {
            const row = this.closest("tr");
            const id = row.dataset.id;
            const username = row.querySelector(".username").value.trim();
            const email = row.querySelector(".email").value.trim();
            const role = row.querySelector(".role-select").value;
            const position = row.querySelector(".position").value.trim();
            const branch = row.querySelector(".branch").value.trim();
            const district = row.querySelector(".district").value.trim();

            fetch("edit_user.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ id, username, email, role, position, branch, district })
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.status === "success") {
                    // Convert back to normal text with badges
                    row.querySelector(".username-text").textContent = username;
                    row.querySelector(".email-text").textContent = email;
                    // Update role cell with badge
                    const roleCell = row.querySelector('td:nth-child(4)');
                    roleCell.innerHTML = `<span class="badge badge-${role}">${role}</span>`;
                    row.querySelector(".position-text").textContent = position;
                    row.querySelector(".branch-text").textContent = branch;
                    row.querySelector(".district-text").textContent = district;

                    // Toggle buttons
                    row.querySelector(".edit-btn").style.display = "inline-block";
                    row.querySelector(".save-btn").style.display = "none";

                    row.classList.remove("edit-mode");
                    row.style.backgroundColor = "#d1e7dd";
                    setTimeout(() => row.style.backgroundColor = "", 1500);
                } else {
                    row.style.backgroundColor = "#f8d7da";
                }
            })
            .catch(err => alert("Error: " + err.message));
        });
    });

    // KPI Edit/Save functionality
    document.querySelectorAll(".edit-kpi-btn").forEach(button => {
        button.addEventListener("click", function () {
            const row = this.closest("tr");
            row.classList.add("edit-mode");

            row.querySelectorAll("td").forEach(td => {
                // Category cell
                if (td.querySelector(".badge-financial, .badge-non-financial")) {
                    const category = td.textContent.trim();
                    td.innerHTML = `
                        <select class="form-select edit-select kpi-category-select">
                            <option value="Financial" ${category==="Financial"?"selected":""}>Financial</option>
                            <option value="Non-Financial" ${category==="Non-Financial"?"selected":""}>Non-Financial</option>
                        </select>`;
                }
                if (td.classList.contains("kpi-name-text")) {
                    td.innerHTML = `<input type="text" class="form-control edit-input kpi-name" value="${td.textContent.trim()}">`;
                }
                if (td.classList.contains("kpi-desc-text")) {
                    td.innerHTML = `<input type="text" class="form-control edit-input kpi-desc" value="${td.textContent.trim()}">`;
                }
            });

            row.querySelector(".edit-kpi-btn").style.display = "none";
            row.querySelector(".save-kpi-btn").style.display = "inline-block";
        });
    });

    // KPI Save button click
    document.querySelectorAll(".save-kpi-btn").forEach(button => {
        button.addEventListener("click", function () {
            const row = this.closest("tr");
            const id = row.dataset.id;
            const category = row.querySelector(".kpi-category-select").value;
            const name = row.querySelector(".kpi-name").value.trim();
            const description = row.querySelector(".kpi-desc").value.trim();

            fetch("edit_kpi.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ id, category, name, description })
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.status === "success") {
                    // Update category cell with badge
                    const categoryCell = row.querySelector('td:nth-child(2)');
                    const badgeClass = category.toLowerCase().replace('-', '');
                    categoryCell.innerHTML = `<span class="badge badge-${badgeClass}">${category}</span>`;
                    row.querySelector(".kpi-name-text").textContent = name;
                    row.querySelector(".kpi-desc-text").textContent = description;

                    row.querySelector(".edit-kpi-btn").style.display = "inline-block";
                    row.querySelector(".save-kpi-btn").style.display = "none";

                    row.classList.remove("edit-mode");
                    row.style.backgroundColor = "#d1e7dd";
                    setTimeout(() => row.style.backgroundColor = "", 1500);
                } else {
                    row.style.backgroundColor = "#f8d7da";
                }
            })
            .catch(err => alert("Error: " + err.message));
        });
    });
});
</script>
</body>
</html>