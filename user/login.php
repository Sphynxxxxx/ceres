<?php
// Start session at the beginning
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once "../backend/connections/config.php"; 
require_once "../vendor/autoload.php";

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not established");
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle form submission
$login_error = '';
$redirect = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    
    // Validate inputs
    if (empty($email) || empty($password)) {
        $login_error = "Please enter both email and password";
    } else {
        try {
            // Check if user exists
            $stmt = $conn->prepare("SELECT id, first_name, last_name, email, password, is_verified FROM users WHERE email = ?");
            if (!$stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Check if account is verified
                    if ($user['is_verified'] == 1) {
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['logged_in'] = true;
                        
                        // Redirect to dashboard
                        $redirect = true;
                    } else {
                        $login_error = "Please verify your email before logging in";
                    }
                } else {
                    $login_error = "Invalid email or password";
                }
            } else {
                $login_error = "Invalid email or password";
            }
            
            $stmt->close();
            
            if ($redirect) {
                header("Location: dashboard.php");
                exit;
            }
        } catch (Exception $e) {
            $login_error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="css/user.css">
    <style>
        :root {
            --primary: #ffb100;
            --dark: #1d3557;
            --light: #f8f9fa;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
            background-color: #f0f2f5;
            min-height: 100vh;
            padding-top: 2rem;
            padding-bottom: 2rem;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
        }
        
        .card-header {
            background-color: var(--dark);
            color: white;
            font-weight: 600;
            border-radius: 10px 10px 0 0 !important;
            padding: 1.25rem;
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .form-control {
            padding: 0.75rem;
            border-radius: 5px;
            border: 1px solid #ced4da;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(255, 177, 0, 0.25);
        }
        
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
            color: var(--dark);
            font-weight: 600;
            padding: 0.75rem 1.5rem;
        }
        
        .btn-primary:hover {
            background-color: #e0a000;
            border-color: #e0a000;
        }
        
        .btn-outline-secondary {
            color: #495057;
            border-color: #ced4da;
        }
        
        .btn-outline-secondary:hover {
            background-color: #f8f9fa;
            color: #495057;
        }
        
        footer {
            margin-top: 2rem;
            text-align: center;
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .alert {
            margin-bottom: 1rem;
        }
        
        .social-login {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #dee2e6;
        }
        
        .social-btn {
            margin-bottom: 0.75rem;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0.75rem;
            font-weight: 500;
        }
        
        .social-btn i {
            margin-right: 0.75rem;
            font-size: 1.2rem;
        }
        
        .forgot-password {
            font-size: 0.9rem;
            text-align: right;
            margin-top: 0.5rem;
        }
        
        .required-field::after {
            content: " *";
            color: red;
        }
    </style>
</head>
<body>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <?php if (!empty($login_error)): ?>
                    <div class="alert alert-danger">
                        <?php echo $login_error; ?>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-sign-in-alt me-2"></i>Login to Your Account</h4>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <div class="mb-3">
                                <label for="email" class="form-label required-field">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label required-field">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                                <div class="forgot-password">
                                    <a href="forgot_password.php" class="text-decoration-none">Forgot password?</a>
                                </div>
                            </div>
                            
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="rememberMe" name="rememberMe">
                                <label class="form-check-label" for="rememberMe">Remember me</label>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt me-2"></i>Login
                                </button>
                                <a href="../index.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Home
                                </a>
                            </div>
                            
                            <div class="text-center mt-3">
                                <p>Don't have an account? <a href="registration.php" class="text-decoration-none">Register here</a></p>
                            </div>
                            
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer>
        <div class="container">
            <p>&copy; 2025 Ceres Bus - ISAT-U Commuters Ticket System. All rights reserved.</p>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>