<?php
session_start();
require_once 'db.php';

// Only admin can access
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo "Access denied.";
    exit;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Add New User</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="styles.css">
<style>
body { min-height: 100vh; display: flex; font-family: 'Segoe UI', sans-serif; background: #f4f6f7; }
.sidebar { width: 220px; background: #2c3e50; color: white; }
.sidebar a { color: white; text-decoration: none; display: block; padding: 15px 20px; font-weight: 500; }
.sidebar a:hover { background: #34495e; }
.sidebar h4 { text-align: center; padding: 20px 0; border-bottom: 1px solid #4b5c6b; margin-bottom: 10px; }
.main { flex: 1; padding: 40px 20px; }
.card { border-radius: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.card-header { background: #2c3e50; color: white; font-weight: 600; font-size: 1.2rem; border-radius: 15px 15px 0 0; }
.btn-primary { background: #2c3e50; border: none; }
.btn-primary:hover { background: #34495e; }
</style>
</head>
<body>

<div class="sidebar">
    <h4>Admin Panel</h4>
    <a href="admin_panel.php">Dashboard</a>
    <a href="register.php">Add New User</a>
    <a href="logout.php">Logout</a>
</div>

<div class="main container">
    <div class="card mx-auto" style="max-width: 600px;">
        <div class="card-header text-center">Add New User</div>
        <div class="card-body">
            <?php if(isset($_GET['error'])): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php elseif(isset($_GET['success'])): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>

            <form action="process_register.php" method="post">
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="fullname" class="form-control" placeholder="John Doe" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="example@email.com" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="At least 6 characters" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-select" required>
                        <option value="">Select Role</option>
                        <option value="user">User / Staff</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
