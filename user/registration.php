<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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
$registration_success = false;
$errors = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize the email so we can use it for verification
    $email = isset($_POST['email']) ? filter_var($_POST['email'], FILTER_SANITIZE_EMAIL) : '';
    
    // Verify email first
    if (empty($_SESSION['verification'])) {
        $errors[] = "Please verify your email first";
    } elseif ($_SESSION['verification']['email'] !== $email) {
        $errors[] = "Email verification doesn't match";
    } elseif (!isset($_POST['verificationCode']) || $_SESSION['verification']['code'] !== $_POST['verificationCode']) {
        $errors[] = "Invalid verification code";
    } elseif ($_SESSION['verification']['expires'] < time()) {
        $errors[] = "Verification code has expired";
    }
    
    // Only proceed with registration if verification passes
    if (empty($errors)) {
        // Server-side validation
        if (empty($_POST['firstName']) || empty($_POST['lastName']) || empty($_POST['gender']) || 
            empty($_POST['birthdate']) || empty($_POST['contactNumber']) || empty($_POST['password'])) {
            $errors[] = "All fields are required";
        } elseif (strlen($_POST['password']) < 8) {
            $errors[] = "Password must be at least 8 characters long";
        }
        
        if (empty($errors)) {
            try {
                // Check if email already exists using prepared statement
                $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $errors[] = "Email address is already registered.";
                } else {
                    // Hash the password
                    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $verified = 1; // Setting to 1 since email verification is done
                    
                    // Insert new user with prepared statement
                    $insert_stmt = $conn->prepare("INSERT INTO users (first_name, last_name, gender, birthdate, contact_number, email, password, is_verified, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    if (!$insert_stmt) {
                        throw new Exception("Database error: " . $conn->error);
                    }
                    
                    $insert_stmt->bind_param("sssssssi", 
                        $_POST['firstName'],
                        $_POST['lastName'],
                        $_POST['gender'],
                        $_POST['birthdate'],
                        $_POST['contactNumber'],
                        $email,
                        $password,
                        $verified
                    );
                    
                    if ($insert_stmt->execute()) {
                        $registration_success = true;
                        // Clear verification session after successful registration
                        unset($_SESSION['verification']);
                    } else {
                        throw new Exception("Registration error: " . $insert_stmt->error);
                    }
                    
                    $insert_stmt->close();
                }
                
                $stmt->close();
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">

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
        
        /* Navbar Styling */
        .navbar {
            background-color: var(--dark);
            padding: 1rem 0;
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.4rem;
            color: var(--primary) !important;
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
        
        .form-control, .form-select {
            padding: 0.75rem;
            border-radius: 5px;
            border: 1px solid #ced4da;
        }
        
        .form-control:focus, .form-select:focus {
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
        
        .verification-section {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }
        
        .terms-container {
            max-height: 150px;
            overflow-y: auto;
            padding: 1rem;
            background-color: #f8f9fa;
            border: 1px solid #ced4da;
            border-radius: 5px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        
        footer {
            margin-top: 2rem;
            text-align: center;
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .required-field::after {
            content: " *";
            color: red;
        }
        
        /* Password strength indicators */
        .password-strength {
            display: flex;
            margin-top: 0.5rem;
        }
        
        .strength-bar {
            height: 5px;
            flex-grow: 1;
            margin-right: 3px;
            background-color: #dee2e6;
            border-radius: 2px;
        }
        
        .strength-bar.active {
            background-color: red;
        }
        
        .strength-bar.active.medium {
            background-color: orange;
        }
        
        .strength-bar.active.strong {
            background-color: green;
        }
        
        .strength-text {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        
        .alert {
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <?php if ($registration_success): ?>
                    <div class="alert alert-success">
                        <h4 class="alert-heading">Registration Successful!</h4>
                        <p>Your account has been created successfully. You can now <a href="login.php" class="alert-link">login</a> to your account.</p>
                    </div>
                <?php elseif (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h4 class="alert-heading">Registration Failed</h4>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-user-plus me-2"></i>Create an Account</h4>
                    </div>
                    <div class="card-body p-4">
                        <form id="registrationForm" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="firstName" class="form-label required-field">First Name</label>
                                    <input type="text" class="form-control" id="firstName" name="firstName" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="lastName" class="form-label required-field">Last Name</label>
                                    <input type="text" class="form-control" id="lastName" name="lastName" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="gender" class="form-label required-field">Gender</label>
                                    <select class="form-select" id="gender" name="gender" required>
                                        <option value="" selected disabled>Select Gender</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Prefer not to say</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="birthdate" class="form-label required-field">Birthdate</label>
                                    <input type="date" class="form-control" id="birthdate" name="birthdate" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="contactNumber" class="form-label required-field">Contact Number</label>
                                <input type="tel" class="form-control" id="contactNumber" name="contactNumber" placeholder="e.g. 09123456789" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label required-field">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" placeholder="your.email@example.com" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label required-field">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                                <div class="password-strength">
                                    <div class="strength-bar"></div>
                                    <div class="strength-bar"></div>
                                    <div class="strength-bar"></div>
                                    <div class="strength-bar"></div>
                                </div>
                                <div class="strength-text">Password should be at least 8 characters with letters, numbers, and special characters</div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="confirmPassword" class="form-label required-field">Confirm Password</label>
                                <input type="password" class="form-control" id="confirmPassword" required>
                            </div>
                            
                            <div class="verification-section mb-4">
                                <label class="form-label fw-bold required-field">Email Verification</label>
                                <div class="input-group mb-3">
                                    <input type="text" class="form-control" name="verificationCode" placeholder="Enter verification code" disabled required>
                                    <button class="btn btn-outline-secondary" type="button" id="sendVerificationBtn">
                                        <i class="fas fa-paper-plane me-2"></i>Send Verification Code
                                    </button>
                                </div>
                                <small class="text-muted">We'll send a verification code to your email address</small>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label fw-bold">Terms and Conditions</label>
                                <div class="terms-container">
                                    <h5>ISAT-U Ceres Bus Ticket System Terms of Service</h5>
                                    <p>By creating an account and using the ISAT-U Ceres Bus Ticket System, you agree to the following terms and conditions:</p>
                                    
                                    <h6>1. Account Registration</h6>
                                    <p>When you register for an account, you must provide accurate, complete, and current information. You are responsible for maintaining the confidentiality of your account credentials and for all activities that occur under your account.</p>
                                    
                                    <h6>2. Booking and Cancellation</h6>
                                    <p>All bus ticket bookings are subject to availability. Cancellations must be made at least 24 hours before the scheduled departure time to be eligible for a refund. A cancellation fee may apply depending on how early the cancellation is made.</p>
                                    
                                    <h6>3. User Conduct</h6>
                                    <p>You agree not to use the service for any illegal or unauthorized purpose. You must not attempt to interfere with the proper functioning of the system.</p>
                                    
                                    <h6>4. Privacy Policy</h6>
                                    <p>Your personal information will be collected, stored, and used in accordance with our Privacy Policy, which is available on our website.</p>
                                    
                                    <h6>5. Limitation of Liability</h6>
                                    <p>ISAT-U Ceres Bus Ticket System shall not be liable for any indirect, incidental, special, consequential, or punitive damages resulting from your use or inability to use the service.</p>
                                    
                                    <h6>6. Changes to Terms</h6>
                                    <p>We reserve the right to modify these terms at any time. Your continued use of the service following the posting of revised terms constitutes your acceptance of the changes.</p>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="termsCheck" required>
                                    <label class="form-check-label" for="termsCheck">
                                        I have read and agree to the Terms and Conditions
                                    </label>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-user-plus me-2"></i>Register
                                </button>
                                <a href="../index.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Home
                                </a>
                            </div>
                            
                            <div class="text-center mt-3">
                                <p>Already have an account? <a href="login.php" class="text-decoration-none">Login here</a></p>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password strength indicator
            const passwordInput = document.getElementById('password');
            const strengthBars = document.querySelectorAll('.strength-bar');
            const strengthText = document.querySelector('.strength-text');
            const confirmPassword = document.getElementById('confirmPassword');
            const sendVerificationBtn = document.getElementById('sendVerificationBtn');
            const verificationCodeInput = document.getElementById('verificationCode');

            // Password strength function
            passwordInput.addEventListener('input', function() {
                const value = passwordInput.value;
                let strength = 0;
                
                if (value.length >= 8) strength++;
                if (/[A-Z]/.test(value)) strength++;
                if (/[0-9]/.test(value)) strength++;
                if (/[^A-Za-z0-9]/.test(value)) strength++;
                
                strengthBars.forEach((bar, index) => {
                    if (index < strength) {
                        bar.classList.add('active');
                        if (strength >= 3) bar.classList.add('strong');
                        else if (strength >= 2) bar.classList.add('medium');
                    } else {
                        bar.classList.remove('active', 'medium', 'strong');
                    }
                });
                
                if (strength === 0) {
                    strengthText.textContent = 'Password should be at least 8 characters with letters, numbers, and special characters';
                } else if (strength === 1) {
                    strengthText.textContent = 'Weak password';
                } else if (strength === 2) {
                    strengthText.textContent = 'Fair password';
                } else if (strength === 3) {
                    strengthText.textContent = 'Good password';
                } else {
                    strengthText.textContent = 'Strong password';
                }
            });
            
            // Password confirmation
            confirmPassword.addEventListener('input', function() {
                if (passwordInput.value !== confirmPassword.value) {
                    confirmPassword.setCustomValidity('Passwords do not match');
                } else {
                    confirmPassword.setCustomValidity('');
                }
            });
            
            // Verification code sending
            sendVerificationBtn.addEventListener('click', function() {
                const email = document.getElementById('email').value;
                
                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    alert('Please enter a valid email address first');
                    return;
                }
                
                // Disable button to prevent multiple clicks
                sendVerificationBtn.disabled = true;
                sendVerificationBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending...';
                
                // Send AJAX request to send verification code
                fetch('../backend/connections/send_email_verification.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `email=${encodeURIComponent(email)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Verification code sent to ' + email);
                        document.querySelector('input[name="verificationCode"]').disabled = false;
                        sendVerificationBtn.textContent = 'Resend Code';
                    } else {
                        alert('Error: ' + (data.error || 'Failed to send verification code'));
                    }
                })
                .catch(error => {
                    alert('Failed to send verification code. Please try again.');
                    console.error('Error:', error);
                })
                .finally(() => {
                    sendVerificationBtn.disabled = false;
                });
            });
            
            // Form submission
            const form = document.getElementById('registrationForm');
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                
                form.classList.add('was-validated');
            });
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>