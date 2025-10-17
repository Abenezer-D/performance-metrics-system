<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($username) || empty($password) || empty($role)) {
        $error = "Please fill in all fields";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role, branch, district FROM users WHERE username = ? AND role = ?");
        if (!$stmt) {
            $error = "Database error: " . $conn->error;
        } else {
            $stmt->bind_param("ss", $username, $role);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                
                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    
                    // Plain text password check
                    if ($password === $user['password']) {
                        // Login successful
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['branch'] = $user['branch'] ?? '';
                        $_SESSION['district'] = $user['district'] ?? '';

                        // Remember me functionality
                        if ($remember) {
                            setcookie('remember_username', $username, time() + (30 * 24 * 60 * 60), '/');
                            setcookie('remember_role', $role, time() + (30 * 24 * 60 * 60), '/');
                        }

                        // Redirect based on role
                        switch ($user['role']) {
                            case 'admin':
                                header("Location: admin_panel.php");
                                break;
                            case 'manager':
                                header("Location: manager_panel.php");
                                break;
                            case 'district':
                                header("Location: district_dashboard.php");
                                break;
                            default:
                                header("Location: user_dashboard.php");
                        }
                        exit();
                    } else {
                        $error = "Invalid password. Please check your credentials.";
                    }
                } else {
                    $error = "Username not found or role mismatch";
                }
            } else {
                $error = "Database query failed";
            }
            $stmt->close();
        }
    }
}

// AJAX handler for username suggestions
if (isset($_GET['action']) && $_GET['action'] === 'suggest' && isset($_GET['term'])) {
    $term = trim($_GET['term']) . '%';
    $stmt = $conn->prepare("SELECT username FROM users WHERE username LIKE ? LIMIT 8");
    $stmt->bind_param("s", $term);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $suggestions = [];
    while ($row = $result->fetch_assoc()) {
        $suggestions[] = $row['username'];
    }
    
    header('Content-Type: application/json');
    echo json_encode($suggestions);
    exit();
}

// Check for remember me cookies
$remembered_username = $_COOKIE['remember_username'] ?? '';
$remembered_role = $_COOKIE['remember_role'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | EFD Staff Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #e63946;
            --dark: #2b2d42;
            --light: #f8f9fa;
            --gray: #adb5bd;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        body.dark-mode {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><polygon fill="%23ffffff10" points="0,1000 1000,0 1000,1000"/></svg>');
            background-size: cover;
            animation: float 20s ease-in-out infinite;
        }
        
        body.dark-mode::before {
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><polygon fill="%2300000010" points="0,1000 1000,0 1000,1000"/></svg>');
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(1deg); }
        }
        
        .login-container {
            max-width: 440px;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 
                0 25px 50px rgba(0, 0, 0, 0.15),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            overflow: visible;
            position: relative;
            z-index: 100;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        
        body.dark-mode .login-container {
            background: rgba(30, 30, 46, 0.95);
            box-shadow: 
                0 25px 50px rgba(0, 0, 0, 0.3),
                0 0 0 1px rgba(255, 255, 255, 0.05);
        }
        
        .login-container:hover {
            transform: translateY(-8px);
            box-shadow: 
                0 35px 60px rgba(0, 0, 0, 0.2),
                0 0 0 1px rgba(255, 255, 255, 0.15);
        }
        
        body.dark-mode .login-container:hover {
            box-shadow: 
                0 35px 60px rgba(0, 0, 0, 0.4),
                0 0 0 1px rgba(255, 255, 255, 0.08);
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            animation: shine 6s infinite linear;
        }
        
        @keyframes shine {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }
        
        .logo {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }
        
        .login-header h2 {
            margin: 0;
            font-weight: 700;
            font-size: 1.8rem;
            position: relative;
            z-index: 1;
        }
        
        .login-header p {
            margin: 12px 0 0;
            opacity: 0.9;
            font-size: 1rem;
            position: relative;
            z-index: 1;
            font-weight: 300;
        }
        
        .login-body {
            padding: 40px 35px;
            position: relative;
            z-index: 1;
        }
        
        body.dark-mode .login-body {
            color: #e2e8f0;
        }
        
        .form-group {
            margin-bottom: 1.75rem;
            position: relative;
        }
        
        .form-label {
            font-weight: 600;
            margin-bottom: 12px;
            color: var(--dark);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
        }
        
        body.dark-mode .form-label {
            color: #e2e8f0;
        }
        
        .form-label i {
            margin-right: 8px;
            color: var(--primary);
        }
        
        .form-control {
            padding: 16px 18px;
            border-radius: 14px;
            border: 2px solid #e8ecf4;
            transition: all 0.3s ease;
            font-size: 1rem;
            background: #fafbff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        
        body.dark-mode .form-control {
            background: #2d3748;
            border-color: #4a5568;
            color: #e2e8f0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        
        .form-control:focus {
            border-color: var(--primary-light);
            box-shadow: 
                0 0 0 4px rgba(67, 97, 238, 0.15),
                0 4px 12px rgba(0, 0, 0, 0.08);
            background: white;
            transform: translateY(-2px);
        }
        
        body.dark-mode .form-control:focus {
            background: #374151;
            box-shadow: 
                0 0 0 4px rgba(67, 97, 238, 0.2),
                0 4px 12px rgba(0, 0, 0, 0.3);
        }
        
        .input-group {
            position: relative;
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: 2px solid #e8ecf4;
            border-right: none;
            border-radius: 14px 0 0 14px;
            transition: all 0.3s ease;
        }
        
        body.dark-mode .input-group-text {
            background: #4a5568;
            border-color: #4a5568;
            color: #e2e8f0;
        }
        
        .input-group:focus-within .input-group-text {
            border-color: var(--primary-light);
            background: white;
        }
        
        body.dark-mode .input-group:focus-within .input-group-text {
            background: #374151;
        }
        
        .password-toggle {
            cursor: pointer;
            background: #f8f9fa;
            border: 2px solid #e8ecf4;
            border-left: none;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 18px;
            border-radius: 0 14px 14px 0;
            transition: all 0.3s ease;
            color: var(--gray);
        }
        
        body.dark-mode .password-toggle {
            background: #4a5568;
            border-color: #4a5568;
            color: #e2e8f0;
        }
        
        .password-toggle:hover {
            background: #e9ecef;
            color: var(--dark);
        }
        
        body.dark-mode .password-toggle:hover {
            background: #5a6578;
            color: #ffffff;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            color: white;
            padding: 16px;
            border-radius: 14px;
            font-weight: 600;
            font-size: 1.05rem;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.35);
            position: relative;
            overflow: hidden;
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(67, 97, 238, 0.5);
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .btn-login:active {
            transform: translateY(-1px);
        }
        
        /* Enhanced Autocomplete - UPDATED TO DROP UP */
        .autocomplete-group {
            position: relative;
        }
        
        .autocomplete-items {
            position: absolute;
            border: 2px solid var(--primary-light);
            border-bottom: none;
            border-radius: 14px 14px 0 0;
            background: white;
            z-index: 2000;
            bottom: 100%; /* CHANGED: Position above the input */
            left: 0;
            right: 0;
            max-height: 220px;
            overflow-y: auto;
            box-shadow: 0 -8px 25px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(20px);
            display: none;
        }
        
        body.dark-mode .autocomplete-items {
            background: #2d3748;
            border-color: var(--primary-light);
            color: #e2e8f0;
        }
        
        .autocomplete-item {
            padding: 14px 18px;
            cursor: pointer;
            border-bottom: 1px solid #f1f3f9;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            font-size: 0.95rem;
        }
        
        body.dark-mode .autocomplete-item {
            border-bottom-color: #4a5568;
        }
        
        .autocomplete-item:before {
            content: '👤';
            margin-right: 10px;
            opacity: 0.6;
        }
        
        .autocomplete-item:hover {
            background: linear-gradient(135deg, #f8f9ff, #e8ecff);
            transform: translateX(4px);
            border-left: 3px solid var(--primary);
        }
        
        body.dark-mode .autocomplete-item:hover {
            background: linear-gradient(135deg, #374151, #4a5568);
        }
        
        .autocomplete-item:last-child {
            border-bottom: none;
        }
        
        .autocomplete-active {
            background: var(--primary);
            color: white;
            transform: translateX(4px);
        }
        
        .autocomplete-active:before {
            filter: brightness(0) invert(1);
        }

        /* Username Recommender - UPDATED TO DROP UP STYLE */
        .username-recommender {
            position: absolute;
            bottom: 100%; /* CHANGED: Position above the input */
            left: 0;
            right: 0;
            background: white;
            border: 2px solid var(--primary-light);
            border-bottom: none;
            border-radius: 14px 14px 0 0;
            box-shadow: 0 -8px 25px rgba(0, 0, 0, 0.15);
            z-index: 2000;
            display: none;
            max-height: 200px;
            overflow-y: auto;
            backdrop-filter: blur(20px);
        }

        body.dark-mode .username-recommender {
            background: #2d3748;
            border-color: var(--primary-light);
            color: #e2e8f0;
        }

        .recommender-header {
            padding: 12px 16px;
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05), rgba(67, 97, 238, 0.02));
            border-bottom: 1px solid #e8ecf4;
            display: flex;
            align-items: center;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--primary);
            position: sticky;
            top: 0;
            z-index: 1;
        }

        body.dark-mode .recommender-header {
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.1), rgba(67, 97, 238, 0.05));
            border-bottom-color: #4a5568;
            color: var(--primary-light);
        }

        .recommender-header i {
            margin-right: 8px;
            font-size: 0.9rem;
        }

        .recommender-list {
            padding: 8px 0;
        }

        .recommender-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f1f3f9;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            color: var(--dark);
        }

        body.dark-mode .recommender-item {
            color: #e2e8f0;
            border-bottom-color: #4a5568;
        }

        .recommender-item:before {
            content: '💡';
            margin-right: 10px;
            opacity: 0.7;
            font-size: 0.8rem;
        }

        .recommender-item:hover {
            background: linear-gradient(135deg, #f8f9ff, #e8ecff);
            transform: translateX(4px);
            border-left: 3px solid var(--warning);
            color: var(--dark);
        }

        body.dark-mode .recommender-item:hover {
            background: linear-gradient(135deg, #374151, #4a5568);
            color: #e2e8f0;
        }

        .recommender-item:last-child {
            border-bottom: none;
        }

        .recommender-item.active {
            background: var(--warning);
            color: white;
            transform: translateX(4px);
        }

        .recommender-item.active:before {
            filter: brightness(0) invert(1);
        }
        
        /* Role Selection Styling */
        .role-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 8px;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }
        
        .role-label {
            display: flex;
            align-items: center;
            padding: 16px;
            border: 2px solid #e8ecf4;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            position: relative;
            overflow: hidden;
        }
        
        body.dark-mode .role-label {
            background: #2d3748;
            border-color: #4a5568;
            color: #e2e8f0;
        }
        
        .role-label::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transition: left 0.6s;
        }
        
        body.dark-mode .role-label::after {
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        }
        
        .role-label:hover {
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
        }
        
        body.dark-mode .role-label:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }
        
        .role-label.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05), rgba(67, 97, 238, 0.02));
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.15);
            transform: translateY(-2px);
        }
        
        body.dark-mode .role-label.selected {
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.1), rgba(67, 97, 238, 0.05));
        }
        
        .role-label.selected::after {
            left: 100%;
        }
        
        .role-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 1rem;
            color: white;
            flex-shrink: 0;
        }
        
        .role-info {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        
        .role-name {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
            margin-bottom: 2px;
        }
        
        body.dark-mode .role-name {
            color: #e2e8f0;
        }
        
        .role-desc {
            font-size: 0.75rem;
            color: var(--gray);
            line-height: 1.3;
        }
        
        body.dark-mode .role-desc {
            color: #a0aec0;
        }
        
        .role-admin .role-icon { background: linear-gradient(135deg, var(--danger), #c1121f); }
        .role-manager .role-icon { background: linear-gradient(135deg, var(--warning), #e85d04); }
        .role-district .role-icon { background: linear-gradient(135deg, var(--success), #00b4d8); }
        .role-user .role-icon { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
        
        .alert {
            border-radius: 12px;
            padding: 16px 18px;
            border: none;
            border-left: 4px solid;
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 1;
        }
        
        .alert-danger {
            background: rgba(230, 57, 70, 0.08);
            color: var(--danger);
            border-left-color: var(--danger);
        }
        
        body.dark-mode .alert-danger {
            background: rgba(230, 57, 70, 0.15);
        }
        
        .alert-success {
            background: rgba(76, 201, 240, 0.08);
            color: var(--success);
            border-left-color: var(--success);
        }
        
        body.dark-mode .alert-success {
            background: rgba(76, 201, 240, 0.15);
        }
        
        .login-footer {
            text-align: center;
            padding: 25px;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            color: var(--gray);
            font-size: 0.9rem;
            background: rgba(248, 249, 250, 0.5);
            position: relative;
            z-index: 1;
        }
        
        body.dark-mode .login-footer {
            background: rgba(45, 55, 72, 0.5);
            border-top-color: rgba(255, 255, 255, 0.1);
            color: #a0aec0;
        }
        
        .login-footer a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.2s;
            font-weight: 500;
        }
        
        .login-footer a:hover {
            color: var(--secondary);
        }
        
        /* Floating animation for form elements */
        .form-group {
            animation: slideUp 0.6s ease forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        
        .form-group:nth-child(1) { animation-delay: 0.1s; }
        .form-group:nth-child(2) { animation-delay: 0.2s; }
        .form-group:nth-child(3) { animation-delay: 0.3s; }
        .form-group:nth-child(4) { animation-delay: 0.4s; }
        .form-group:nth-child(5) { animation-delay: 0.5s; }
        
        @keyframes slideUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Remember me styling */
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .form-check-label {
            color: var(--dark);
            font-size: 0.9rem;
        }
        
        body.dark-mode .form-check-label {
            color: #e2e8f0;
        }
        
        /* System status */
        .system-status {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 10;
        }
        
        .dark-mode-toggle {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 10;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .dark-mode-toggle:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }
        
        .language-selector {
            position: absolute;
            top: 20px;
            right: 70px;
            z-index: 10;
        }
        
        .language-selector select {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: 20px;
            padding: 5px 15px;
            backdrop-filter: blur(10px);
        }
        
        .language-selector select option {
            background: #2d3748;
            color: #e2e8f0;
        }
        
        /* Responsive adjustments */
        @media (max-width: 576px) {
            .login-container {
                max-width: 100%;
                border-radius: 20px;
                margin: 10px;
            }
            
            .login-body {
                padding: 30px 25px;
            }
            
            .role-options {
                grid-template-columns: 1fr;
            }
            
            .login-header {
                padding: 30px 25px;
            }
            
            .login-header h2 {
                font-size: 1.6rem;
            }
            
            .system-status, .dark-mode-toggle, .language-selector {
                position: relative;
                top: auto;
                left: auto;
                right: auto;
                margin-bottom: 15px;
            }
            
            .language-selector {
                margin-right: 10px;
            }
        }
        
        /* Loading animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
            margin-right: 10px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Pulse animation for valid form */
        .btn-login.valid {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 8px 25px rgba(67, 97, 238, 0.35); }
            50% { box-shadow: 0 8px 30px rgba(67, 97, 238, 0.5); }
            100% { box-shadow: 0 8px 25px rgba(67, 97, 238, 0.35); }
        }
        
        /* Utility classes */
        .text-sm {
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
<div class="system-status">
    <div class="badge bg-success">
        <i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i>
        System Online
    </div>
</div>

<button class="dark-mode-toggle" id="darkModeToggle" title="Toggle Dark Mode">
    <i class="fas fa-moon"></i>
</button>

<div class="language-selector">
    <select class="form-select form-select-sm">
        <option>English</option>
        <option>Amharic</option>
    </select>
</div>

<div class="login-container">
    <div class="login-header">
        <div class="logo">
            <i class="fas fa-building"></i>
        </div>
        <h2>EFD Staff Portal</h2>
        <p>Access your workspace securely</p>
    </div>
    
    <div class="login-body">
        <?php if(!empty($error)): ?>
            <div class="alert alert-danger mb-4">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success mb-4">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="loginForm">
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-user"></i>Username
                </label>
                <div class="autocomplete-group">
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-user"></i>
                        </span>
                        <input type="text" name="username" id="username" class="form-control" placeholder="Enter your username" required autocomplete="off" value="<?php echo htmlspecialchars($remembered_username); ?>">
                    </div>
                    <div id="autocomplete-list" class="autocomplete-items"></div>
                    <div id="username-recommender" class="username-recommender">
                        <div class="recommender-header">
                            <i class="fas fa-lightbulb"></i>Popular Usernames
                        </div>
                        <div class="recommender-list" id="recommender-list">
                            <!-- Recommended usernames will appear here -->
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-lock"></i>Password
                </label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-key"></i>
                    </span>
                    <input type="password" name="password" id="password" class="form-control" placeholder="Enter your password" required>
                    <span class="password-toggle" id="togglePassword">
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </span>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-user-tag"></i>Select Role
                </label>
                <div class="role-options">
                    <label class="role-label role-admin <?php echo ($remembered_role === 'admin') ? 'selected' : ''; ?>" for="role_admin">
                        <input type="radio" id="role_admin" name="role" value="admin" <?php echo ($remembered_role === 'admin') ? 'checked' : ''; ?>>
                        <div class="role-icon">
                            <i class="fas fa-crown"></i>
                        </div>
                        <div class="role-info">
                            <span class="role-name">Administrator</span>
                            <span class="role-desc">Full system access</span>
                        </div>
                    </label>
                    
                    <label class="role-label role-manager <?php echo ($remembered_role === 'manager') ? 'selected' : ''; ?>" for="role_manager">
                        <input type="radio" id="role_manager" name="role" value="manager" <?php echo ($remembered_role === 'manager') ? 'checked' : ''; ?>>
                        <div class="role-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="role-info">
                            <span class="role-name">Manager</span>
                            <span class="role-desc">Branch management</span>
                        </div>
                    </label>
                    
                    <label class="role-label role-district <?php echo ($remembered_role === 'district') ? 'selected' : ''; ?>" for="role_district">
                        <input type="radio" id="role_district" name="role" value="district" <?php echo ($remembered_role === 'district') ? 'checked' : ''; ?>>
                        <div class="role-icon">
                            <i class="fas fa-map-marked-alt"></i>
                        </div>
                        <div class="role-info">
                            <span class="role-name">District</span>
                            <span class="role-desc">Regional oversight</span>
                        </div>
                    </label>
                    
                    <label class="role-label role-user <?php echo ($remembered_role === 'user') ? 'selected' : ''; ?>" for="role_user">
                        <input type="radio" id="role_user" name="role" value="user" <?php echo ($remembered_role === 'user') ? 'checked' : ''; ?>>
                        <div class="role-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="role-info">
                            <span class="role-name">Staff</span>
                            <span class="role-desc">Daily operations</span>
                        </div>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rememberMe" name="remember" <?php echo $remembered_username ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="rememberMe">
                            <i class="fas fa-remember me-1"></i>Remember me
                        </label>
                    </div>
                    <a href="forgot-password.php" class="text-decoration-none text-sm">
                        <i class="fas fa-key me-1"></i>Forgot password?
                    </a>
                </div>
            </div>
            
            <div class="form-group mt-4">
                <button type="submit" class="btn btn-login w-100">
                    <i class="fas fa-sign-in-alt me-2"></i>Access Dashboard
                </button>
            </div>
        </form>
    </div>
    
    <div class="login-footer">
        <p>Need assistance? <a href="#"><i class="fas fa-headset me-1"></i>Contact Support</a></p>
    </div>
</div>

<script>
    // Password visibility toggle
    document.getElementById('togglePassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.getElementById('toggleIcon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.replace('fa-eye', 'fa-eye-slash');
            this.style.background = 'var(--primary-light)';
            this.style.color = 'white';
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.replace('fa-eye-slash', 'fa-eye');
            this.style.background = '';
            this.style.color = '';
        }
    });
    
    // Role selection styling
    document.querySelectorAll('.role-label').forEach(label => {
        label.addEventListener('click', function() {
            document.querySelectorAll('.role-label').forEach(l => {
                l.classList.remove('selected');
            });
            this.classList.add('selected');
        });
    });
    
    // Dark mode toggle
    document.getElementById('darkModeToggle').addEventListener('click', function() {
        document.body.classList.toggle('dark-mode');
        const icon = this.querySelector('i');
        if (document.body.classList.contains('dark-mode')) {
            icon.classList.replace('fa-moon', 'fa-sun');
            this.title = 'Toggle Light Mode';
        } else {
            icon.classList.replace('fa-sun', 'fa-moon');
            this.title = 'Toggle Dark Mode';
        }
    });

    // Popular usernames for recommendations
    const popularUsernames = [
        'Amanuel Sanbeto Tolosa',
        'Samuel Assefa Fufa', 
        'Hirut Damesa Diriba',
        'Melkamu Mulugeta Minda',
        'Tesfaye Megersa Bodosha',
        'Yoomif Abdisa Wakene',
        'Lina Abrahim Adus',
        'Bereket Biruk Teklemariam'
    ];

    let recommenderFocus = -1;

    // Username recommender functionality - DROP UP STYLE
    function showUsernameRecommender() {
        const recommender = document.getElementById('username-recommender');
        const recommenderList = document.getElementById('recommender-list');
        const autocompleteList = document.getElementById('autocomplete-list');
        const currentInput = document.getElementById('username').value.toLowerCase();
        
        // Hide recommender if autocomplete is showing
        if (autocompleteList.style.display === 'block') {
            recommender.style.display = 'none';
            return;
        }
        
        // Filter popular usernames based on current input
        const filteredUsernames = popularUsernames.filter(username => 
            username.toLowerCase().includes(currentInput) && currentInput.length > 0
        );
        
        // Show top 6 recommendations
        const recommendations = filteredUsernames.slice(0, 6);
        
        if (recommendations.length > 0 && currentInput.length > 0) {
            recommenderList.innerHTML = '';
            recommendations.forEach((username, index) => {
                const item = document.createElement('div');
                item.className = 'recommender-item';
                item.innerHTML = `<i class="fas fa-user me-2"></i>${username}`;
                item.setAttribute('data-index', index);
                
                item.addEventListener('click', function() {
                    document.getElementById('username').value = username;
                    recommender.style.display = 'none';
                    autocompleteList.style.display = 'none';
                    recommenderFocus = -1;
                    
                    // Add visual feedback
                    this.classList.add('active');
                    setTimeout(() => {
                        this.classList.remove('active');
                    }, 200);
                });
                
                recommenderList.appendChild(item);
            });
            recommender.style.display = 'block';
        } else {
            recommender.style.display = 'none';
        }
    }
    
    // Enhanced username autocomplete functionality - DROP UP STYLE
    let currentFocus = -1;
    let debounceTimer;
    
    document.getElementById('username').addEventListener('input', function() {
        const input = this.value;
        const autocompleteList = document.getElementById('autocomplete-list');
        const recommender = document.getElementById('username-recommender');
        
        // Show username recommendations only if no autocomplete results
        if (input.length < 2) {
            showUsernameRecommender();
        }
        
        // Clear previous timer
        clearTimeout(debounceTimer);
        
        // Hide suggestions if input is too short
        if (input.length < 2) {
            autocompleteList.innerHTML = '';
            autocompleteList.style.display = 'none';
            return;
        }
        
        // Debounce requests
        debounceTimer = setTimeout(() => {
            // Show loading state
            autocompleteList.innerHTML = '<div class="autocomplete-item"><i class="fas fa-spinner fa-spin me-2"></i>Searching...</div>';
            autocompleteList.style.display = 'block';
            recommender.style.display = 'none'; // Hide recommender when searching
            
            // Fetch suggestions from server
            fetch(`?action=suggest&term=${encodeURIComponent(input)}`)
                .then(response => response.json())
                .then(suggestions => {
                    autocompleteList.innerHTML = '';
                    
                    if (suggestions.length === 0) {
                        autocompleteList.innerHTML = '<div class="autocomplete-item"><i class="fas fa-search me-2"></i>No users found</div>';
                        // Show recommender if no results
                        showUsernameRecommender();
                        return;
                    }
                    
                    suggestions.forEach(suggestion => {
                        const item = document.createElement('div');
                        item.classList.add('autocomplete-item');
                        item.innerHTML = `<i class="fas fa-user me-2"></i>${suggestion}`;
                        
                        item.addEventListener('click', function() {
                            document.getElementById('username').value = suggestion;
                            autocompleteList.innerHTML = '';
                            autocompleteList.style.display = 'none';
                            recommender.style.display = 'none';
                            currentFocus = -1;
                            
                            // Add visual feedback
                            this.style.background = 'var(--success)';
                            this.style.color = 'white';
                            setTimeout(() => {
                                this.style.background = '';
                                this.style.color = '';
                            }, 200);
                        });
                        
                        autocompleteList.appendChild(item);
                    });
                })
                .catch(error => {
                    console.error('Error fetching suggestions:', error);
                    autocompleteList.innerHTML = '<div class="autocomplete-item"><i class="fas fa-exclamation-triangle me-2"></i>Error loading suggestions</div>';
                    // Show recommender on error
                    showUsernameRecommender();
                });
        }, 300); // 300ms debounce
    });
    
    // Keyboard navigation for autocomplete and recommender
    document.getElementById('username').addEventListener('keydown', function(e) {
        const autocompleteList = document.getElementById('autocomplete-list');
        const recommender = document.getElementById('username-recommender');
        const autocompleteItems = autocompleteList.getElementsByClassName('autocomplete-item');
        const recommenderItems = recommender.getElementsByClassName('recommender-item');
        
        if (e.key === 'ArrowDown') {
            if (autocompleteList.style.display === 'block') {
                currentFocus++;
                addActive(autocompleteItems, 'autocomplete-active');
                e.preventDefault();
            } else if (recommender.style.display === 'block') {
                recommenderFocus++;
                addActive(recommenderItems, 'active');
                e.preventDefault();
            }
        } else if (e.key === 'ArrowUp') {
            if (autocompleteList.style.display === 'block') {
                currentFocus--;
                addActive(autocompleteItems, 'autocomplete-active');
                e.preventDefault();
            } else if (recommender.style.display === 'block') {
                recommenderFocus--;
                addActive(recommenderItems, 'active');
                e.preventDefault();
            }
        } else if (e.key === 'Enter') {
            if (autocompleteList.style.display === 'block' && currentFocus > -1) {
                if (autocompleteItems[currentFocus]) {
                    autocompleteItems[currentFocus].click();
                    e.preventDefault();
                }
            } else if (recommender.style.display === 'block' && recommenderFocus > -1) {
                if (recommenderItems[recommenderFocus]) {
                    recommenderItems[recommenderFocus].click();
                    e.preventDefault();
                }
            }
        } else if (e.key === 'Escape') {
            autocompleteList.innerHTML = '';
            autocompleteList.style.display = 'none';
            recommender.style.display = 'none';
            currentFocus = -1;
            recommenderFocus = -1;
        }
    });
    
    function addActive(items, activeClass) {
        if (!items) return false;
        
        removeActive(items, activeClass);
        
        let currentIndex;
        let maxIndex;
        
        if (activeClass === 'autocomplete-active') {
            currentIndex = currentFocus;
            maxIndex = items.length;
        } else {
            currentIndex = recommenderFocus;
            maxIndex = items.length;
        }
        
        if (currentIndex >= maxIndex) currentIndex = 0;
        if (currentIndex < 0) currentIndex = maxIndex - 1;
        
        if (items[currentIndex]) {
            items[currentIndex].classList.add(activeClass);
            items[currentIndex].scrollIntoView({ block: 'nearest' });
            
            if (activeClass === 'autocomplete-active') {
                currentFocus = currentIndex;
            } else {
                recommenderFocus = currentIndex;
            }
        }
    }
    
    function removeActive(items, activeClass) {
        for (let i = 0; i < items.length; i++) {
            items[i].classList.remove(activeClass);
        }
    }
    
    // Close autocomplete and recommender when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.autocomplete-group')) {
            const autocompleteList = document.getElementById('autocomplete-list');
            const recommender = document.getElementById('username-recommender');
            autocompleteList.innerHTML = '';
            autocompleteList.style.display = 'none';
            recommender.style.display = 'none';
            currentFocus = -1;
            recommenderFocus = -1;
        }
    });
    
    // Form validation enhancement
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const username = document.getElementById('username');
        const password = document.getElementById('password');
        const role = document.querySelector('input[name="role"]:checked');
        const submitBtn = this.querySelector('button[type="submit"]');
        
        let isValid = true;
        
        [username, password].forEach(field => {
            field.classList.remove('is-invalid');
        });
        
        if (!username.value || username.value.length < 2) {
            username.classList.add('is-invalid');
            isValid = false;
        }
        
        if (!password.value) {
            password.classList.add('is-invalid');
            isValid = false;
        }
        
        if (!role) {
            document.querySelector('.role-options').style.border = '2px solid var(--danger)';
            document.querySelector('.role-options').style.borderRadius = '12px';
            isValid = false;
        } else {
            document.querySelector('.role-options').style.border = '';
        }
        
        if (!isValid) {
            e.preventDefault();
        } else {
            // Add loading state to button
            submitBtn.innerHTML = '<div class="loading"></div>Signing in...';
            submitBtn.disabled = true;
        }
    });
    
    // Add focus effects
    document.querySelectorAll('.form-control').forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.parentElement.classList.add('focused');
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.parentElement.classList.remove('focused');
        });
    });
    
    // Real-time form validation for button pulse effect
    function validateForm() {
        const username = document.getElementById('username').value;
        const password = document.getElementById('password').value;
        const role = document.querySelector('input[name="role"]:checked');
        const submitBtn = document.querySelector('.btn-login');
        
        if (username && password && role) {
            submitBtn.classList.add('valid');
        } else {
            submitBtn.classList.remove('valid');
        }
    }
    
    // Add event listeners for real-time validation
    document.getElementById('username').addEventListener('input', validateForm);
    document.getElementById('password').addEventListener('input', validateForm);
    document.querySelectorAll('input[name="role"]').forEach(radio => {
        radio.addEventListener('change', validateForm);
    });
    
    // Initialize validation
    validateForm();

    // Show initial recommendations if username field is empty
    document.getElementById('username').addEventListener('focus', function() {
        if (!this.value) {
            showUsernameRecommender();
        }
    });

    // Hide recommender when autocomplete is shown
    document.getElementById('username').addEventListener('input', function() {
        const autocompleteList = document.getElementById('autocomplete-list');
        const recommender = document.getElementById('username-recommender');
        
        if (autocompleteList.style.display === 'block') {
            recommender.style.display = 'none';
        }
    });
</script>
</body>
</html>