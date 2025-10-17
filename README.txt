Role-based Login System (HTML/CSS/JS + PHP + MySQL)
--------------------------------------------------
Files included:
- db.php              : database connection (configure DB params)
- install.php         : creates `users` table and a default admin user (run once)
- login.php           : login form
- authenticate.php    : processes login
- dashboard.php       : redirects users based on role
- admin_panel.php     : admin-only page (CRUD placeholders)
- manager_panel.php   : manager-only page (approve/reject placeholder)
- user_panel.php      : user/staff page (submit/view own data placeholder)
- logout.php          : logout
- styles.css          : basic styles
- assets/             : (empty - for images/icons if needed)
- install_instructions.txt : how to set up & run

Quick setup:
1. Put these files on a PHP-enabled web server with MySQL (e.g., XAMPP, MAMP, LAMP).
2. Edit db.php with your DB credentials.
3. Visit install.php once (e.g., http://localhost/role_based_auth_php/install.php) to create the table and default admin:
   - default admin email: admin@example.com
   - default admin password: Admin@123
4. Delete or secure install.php after running it.
5. Open login.php to use the system.

Security notes:
- Passwords are hashed using PHP password_hash().
- All DB operations use prepared statements.
- This is a starter template; expand validation, CSRF protection, and access controls before production use.
