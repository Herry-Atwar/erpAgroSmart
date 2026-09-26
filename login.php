<?php
ob_start();
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

require_once 'config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        try {
            $db = getDB();
            
            // Get user from database by email or username
            $stmt = $db->prepare("
                SELECT
                    u.id,
                    u.username,
                    u.full_name,
                    u.email,
                    u.password_hash,
                    u.role,
                    u.is_active,
                    u.company_id,
                    u.business_unit_id,
                    u.division_id,
                    c.company_name,
                    bu.unit_name,
                    d.division_name
                FROM agrosmart_users u
                LEFT JOIN companies c ON u.company_id = c.company_id
                LEFT JOIN business_units bu ON u.business_unit_id = bu.business_unit_id
                LEFT JOIN divisions d ON u.division_id = d.division_id
                WHERE u.email = ? OR u.username = ?
            ");
            $stmt->execute([$email, $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Check if user is active
                if (!$user['is_active']) {
                    $error = 'Your account has been deactivated. Please contact administrator.';
                }
                // Verify password: use bcrypt hash if set, otherwise reject
                elseif (isset($user['password_hash']) && $user['password_hash'] !== null && password_verify($password, $user['password_hash'])) {
                    // Login successful
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['company_id'] = $user['company_id'];
                    $_SESSION['business_unit_id'] = $user['business_unit_id'];
                    $_SESSION['division_id'] = $user['division_id'];
                    $_SESSION['company_name'] = $user['company_name'];
                    $_SESSION['unit_name'] = $user['unit_name'];
                    $_SESSION['division_name'] = $user['division_name'];
                    
                    // Update last login
                    $update_stmt = $db->prepare("UPDATE agrosmart_users SET last_login = NOW() WHERE id = ?");
                    $update_stmt->execute([$user['id']]);
                    
                    // Redirect to dashboard
                    header('Location: index.php');
                    exit();
                } else {
                    $error = 'Invalid email or password';
                }
            } else {
                $error = 'Invalid email or password';
            }
        } catch (Exception $e) {
            $error = 'Login error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>erpAgroSmart - Agrobusiness Solution</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
        :root {
            --primary-green: #2e7d32;
            --secondary-green: #558b2f;
            --light-green: #8bc34a;
            --dark-green: #1b5e20;
        }
        
        body {
            background: url('images/AgroSmart.jpg') center center / cover no-repeat fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(255, 255, 255, 0.82);
            z-index: 0;
            pointer-events: none;
        }
        
        .login-container {
            max-width: 950px;
            width: 100%;
            padding: 20px;
            position: relative;
            z-index: 1;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            display: flex;
            flex-direction: row;
        }
        
        .login-left {
            flex: 1;
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--secondary-green) 100%);
            color: white;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .login-left::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 20%, transparent 20%);
            background-size: 30px 30px;
            animation: float 15s linear infinite;
        }
        
        @keyframes float {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(30px, 30px) rotate(360deg); }
        }
        
        .login-left-content {
            position: relative;
            z-index: 1;
        }
        
        .login-left i.main-icon {
            font-size: 5rem;
            margin-bottom: 20px;
            opacity: 0.9;
        }
        
        .login-left h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 15px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .login-left p {
            font-size: 1.1rem;
            opacity: 0.95;
            margin-bottom: 30px;
        }
        
        .feature-list {
            text-align: left;
            margin-top: 30px;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            font-size: 0.95rem;
        }
        
        .feature-item i {
            font-size: 1.5rem;
            margin-right: 12px;
            color: var(--light-green);
        }
        
        .login-right {
            flex: 1;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .login-right h2 {
            color: var(--primary-green);
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .login-right .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 0.95rem;
        }
        
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        
        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 0.2rem rgba(46, 125, 50, 0.15);
        }
        
        .input-group-text {
            background-color: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-right: none;
            border-radius: 10px 0 0 10px;
            color: var(--primary-green);
        }
        
        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--secondary-green) 100%);
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(46, 125, 50, 0.3);
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(46, 125, 50, 0.4);
            color: white;
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .form-check-input:checked {
            background-color: var(--primary-green);
            border-color: var(--primary-green);
        }
        
        .demo-credentials {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 25px;
        }
        
        .demo-credentials h6 {
            color: #856404;
            margin-bottom: 10px;
            font-weight: 700;
            display: flex;
            align-items: center;
        }
        
        .demo-credentials h6 i {
            margin-right: 8px;
        }
        
        .demo-credentials p {
            margin: 5px 0;
            color: #856404;
            font-size: 0.9rem;
        }
        
        .demo-credentials code {
            background-color: #fff;
            padding: 3px 8px;
            border-radius: 5px;
            color: #d63384;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .login-card {
                flex-direction: column;
            }
            
            .login-left {
                padding: 30px 20px;
            }
            
            .login-left h1 {
                font-size: 1.8rem;
            }
            
            .feature-list {
                display: none;
            }
            
            .login-right {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Left Side - Branding -->
            <div class="login-left">
                <div class="login-left-content">
                    <i class="bi bi-tree main-icon"></i>
                    <h1>erpAgroSmart</h1>
                    <p>AI-Powered Agrobusiness Solution</p>
                    
                    <div class="feature-list">
                        <div class="feature-item">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Complete Estate Management</span>
                        </div>
                        <div class="feature-item">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Real-time Production Tracking</span>
                        </div>
                        <div class="feature-item">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Financial & Budget Control</span>
                        </div>
                        <div class="feature-item">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Harvest & Mill Operations</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Side - Login Form -->
            <div class="login-right">
                <h2><i class="bi bi-box-arrow-in-right"></i> Welcome Back</h2>
                <p class="subtitle">Please login to your account</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Demo Credentials Info -->
                <div class="demo-credentials" style="cursor:pointer" onclick="fillDemo()" title="Click to auto-fill">
                    <h6><i class="bi bi-key-fill"></i> Demo Credentials <small class="fw-normal ms-1">(click to fill)</small></h6>
                    <p><strong>Email:</strong> <code>admin@plantation.com</code></p>
                    <p><strong>Password:</strong> <code>password123</code></p>
                </div>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="email" class="form-label">
                            <i class="bi bi-envelope"></i> Email Address
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-envelope-fill"></i>
                            </span>
                            <input type="text" class="form-control" id="email" name="email"
                                   placeholder="Enter your email" required autofocus
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? 'admin@plantation.com'); ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="bi bi-shield-lock"></i> Password
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-lock-fill"></i>
                            </span>
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="Enter your password" required
                                   value="password123">
                        </div>
                    </div>
                    
                    <div class="mb-4 form-check">
                        <input type="checkbox" class="form-check-input" id="remember">
                        <label class="form-check-label" for="remember">
                            Remember me on this device
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-login">
                        <i class="bi bi-box-arrow-in-right"></i> Login to Dashboard
                    </button>
                </form>
                
                <div class="text-center mt-4">
                    <small class="text-muted">
                        <i class="bi bi-shield-check"></i> Secure & Encrypted Connection
                    </small>
                </div>
            </div>
        </div>
        
        <!-- Footer Credit -->
        <div style="text-align: center; margin-top: 20px; color: #666; font-size: 0.9rem;">
            <p style="margin: 0;">// Made with Bob</p>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function fillDemo() {
            document.getElementById('email').value    = 'admin@plantation.com';
            document.getElementById('password').value = 'password123';
        }
    </script>
</body>
</html>
