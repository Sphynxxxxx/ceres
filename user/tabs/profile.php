<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Redirect to login page
    header("Location: ../login.php");
    exit;
}

// Get user info from session
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';

// Database connection
require_once "../../backend/connections/config.php"; 
require_once "../../vendor/autoload.php";

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not established");
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize variables with default values
$user_data = array(
    'id' => $user_id,
    'first_name' => '',
    'last_name' => '',
    'email' => $user_email,
    'contact_number' => '',
    'gender' => '',
    'birthdate' => '',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s')
);
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $new_email = trim($_POST['email'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');
        
        // Validate input
        $errors = array();
        if (empty($first_name)) {
            $errors[] = "First name is required";
        }
        if (empty($last_name)) {
            $errors[] = "Last name is required";
        }
        if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email is required";
        }
        
        if (empty($errors)) {
            // Check if email is already taken by another user
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param("si", $new_email, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error_message = "Email is already taken by another user";
            } else {
                // Update user information
                $update_stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, contact_number = ?, gender = ?, birthdate = ? WHERE id = ?");
                $update_stmt->bind_param("ssssssi", $first_name, $last_name, $new_email, $contact_number, $gender, $birthdate, $user_id);
                
                if ($update_stmt->execute()) {
                    // Update session variables
                    $_SESSION['user_name'] = $first_name . ' ' . $last_name;
                    $_SESSION['user_email'] = $new_email;
                    $user_name = $first_name . ' ' . $last_name;
                    $user_email = $new_email;
                    $success_message = "Profile updated successfully!";
                    
                    // Update local user_data array
                    $user_data['first_name'] = $first_name;
                    $user_data['last_name'] = $last_name;
                    $user_data['email'] = $new_email;
                    $user_data['contact_number'] = $contact_number;
                    $user_data['gender'] = $gender;
                    $user_data['birthdate'] = $birthdate;
                } else {
                    $error_message = "Failed to update profile. Please try again.";
                }
                $update_stmt->close();
            }
            $stmt->close();
        } else {
            $error_message = implode("<br>", $errors);
        }
    } elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate password change
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = "All password fields are required";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "New passwords do not match";
        } elseif (strlen($new_password) < 6) {
            $error_message = "Password must be at least 6 characters long";
        } else {
            // Verify current password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if ($user && password_verify($current_password, $user['password'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update_stmt->bind_param("si", $hashed_password, $user_id);
                
                if ($update_stmt->execute()) {
                    $success_message = "Password changed successfully!";
                } else {
                    $error_message = "Failed to change password. Please try again.";
                }
                $update_stmt->close();
            } else {
                $error_message = "Current password is incorrect";
            }
            $stmt->close();
        }
    }
}

// Fetch user data from database
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $fetched_data = $result->fetch_assoc();
            // Merge fetched data with defaults
            $user_data = array_merge($user_data, $fetched_data);
            
            // Ensure all fields have values
            $user_data['first_name'] = $user_data['first_name'] ?? '';
            $user_data['last_name'] = $user_data['last_name'] ?? '';
            $user_data['email'] = $user_data['email'] ?? $user_email;
            $user_data['contact_number'] = $user_data['contact_number'] ?? '';
            $user_data['gender'] = $user_data['gender'] ?? '';
            $user_data['birthdate'] = $user_data['birthdate'] ?? '';
            $user_data['created_at'] = $user_data['created_at'] ?? date('Y-m-d H:i:s');
            $user_data['updated_at'] = $user_data['updated_at'] ?? date('Y-m-d H:i:s');
            
            // Update user_name if we have first and last name
            if (!empty($user_data['first_name']) && !empty($user_data['last_name'])) {
                $user_name = $user_data['first_name'] . ' ' . $user_data['last_name'];
            }
        } else {
            // User not found in database
            session_destroy();
            header("Location: ../login.php?error=invalid_user");
            exit;
        }
        $stmt->close();
    }
} catch (Exception $e) {
    // Handle exception
    error_log("Error fetching user data: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../css/navfot.css" rel="stylesheet">
    <link href="../css/user.css" rel="stylesheet">
    <style>
        .profile-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            background-color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        
        .profile-avatar i {
            font-size: 60px;
            color: #2c3e50;
        }
        
        .form-section {
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="../dashboard.php">
                <i class="fas fa-bus-alt me-2"></i>Ceres Bus for ISAT-U Commuters
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="../dashboard.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="routes.php">Routes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php">Schedule</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="booking.php">Book Ticket</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-wide">
        <div class="row g-4">
            <!-- Sidebar -->
            <div class="col-lg-3 sidebar">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-circle me-2"></i>Account Menu</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <a href="../dashboard.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                            <a href="profile.php" class="list-group-item list-group-item-action active">
                                <i class="fas fa-user me-2"></i>My Profile
                            </a>
                            <a href="mybookings.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-ticket-alt me-2"></i>My Bookings
                            </a>
                            <a href="booking.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-plus-circle me-2"></i>Book New Ticket
                            </a>
                            <a href="../logout.php" class="list-group-item list-group-item-action text-danger">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="col-lg-9">
                <div class="card">
                    <div class="card-body">
                        <!-- Profile Header -->
                        <div class="profile-header text-center">
                            <div class="profile-avatar mx-auto">
                                <i class="fas fa-user"></i>
                            </div>
                            <h3><?php echo htmlspecialchars($user_name); ?></h3>
                            <p><?php echo htmlspecialchars($user_email); ?></p>
                            <p class="mb-0">Member since: <?php echo date('F Y', strtotime($user_data['created_at'])); ?></p>
                        </div>

                        <?php if ($success_message): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error_message): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Profile Information Form -->
                        <div class="form-section">
                            <h4 class="mb-4"><i class="fas fa-user-edit me-2"></i>Profile Information</h4>
                            <form method="POST" action="">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="first_name" class="form-label">First Name</label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" 
                                               value="<?php echo htmlspecialchars($user_data['first_name']); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="last_name" class="form-label">Last Name</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" 
                                               value="<?php echo htmlspecialchars($user_data['last_name']); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="contact_number" class="form-label">Contact Number</label>
                                        <input type="tel" class="form-control" id="contact_number" name="contact_number" 
                                               value="<?php echo htmlspecialchars($user_data['contact_number']); ?>" 
                                               placeholder="09XXXXXXXXX">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="gender" class="form-label">Gender</label>
                                        <select class="form-control" id="gender" name="gender">
                                            <option value="">Select Gender</option>
                                            <option value="male" <?php echo ($user_data['gender'] == 'male') ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo ($user_data['gender'] == 'female') ? 'selected' : ''; ?>>Female</option>
                                            <option value="other" <?php echo ($user_data['gender'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="birthdate" class="form-label">Birthdate</label>
                                        <input type="date" class="form-control" id="birthdate" name="birthdate" 
                                               value="<?php echo htmlspecialchars($user_data['birthdate']); ?>">
                                    </div>
                                </div>
                                <button type="submit" name="update_profile" class="btn btn-warning">
                                    <i class="fas fa-save me-2"></i>Update Profile
                                </button>
                            </form>
                        </div>

                        <!-- Change Password Form -->
                        <div class="form-section">
                            <h4 class="mb-4"><i class="fas fa-lock me-2"></i>Change Password</h4>
                            <form method="POST" action="">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <input type="password" class="form-control" id="current_password" 
                                               name="current_password" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="new_password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="new_password" 
                                               name="new_password" required minlength="6">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" 
                                               name="confirm_password" required minlength="6">
                                    </div>
                                </div>
                                <button type="submit" name="change_password" class="btn btn-warning">
                                    <i class="fas fa-key me-2"></i>Change Password
                                </button>
                            </form>
                        </div>

                        <!-- Account Information -->
                        <div class="form-section">
                            <h4 class="mb-4"><i class="fas fa-info-circle me-2"></i>Account Information</h4>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Account Type:</strong> Commuter</p>
                                    <p><strong>User ID:</strong> #<?php echo htmlspecialchars($user_data['id']); ?></p>
                                    <p><strong>Verification Status:</strong> 
                                        <?php if ($user_data['is_verified'] ?? 0): ?>
                                            <span class="badge bg-success">Verified</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Not Verified</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Account Status:</strong> <span class="badge bg-success">Active</span></p>
                                    <p><strong>Last Updated:</strong> <?php echo date('F d, Y', strtotime($user_data['updated_at'] ?? $user_data['created_at'])); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer mt-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5>Ceres Bus Ticket System for ISAT-U Commuters</h5>
                    <p>Providing convenient Ceres bus transportation booking for ISAT-U students, faculty, and staff commuters.</p>
                </div>
                <div class="col-lg-4 mb-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="routes.php" class="text-white"><i class="fas fa-route me-2"></i>Routes</a></li>
                        <li><a href="schedule.php" class="text-white"><i class="fas fa-calendar-alt me-2"></i>Schedule</a></li>
                        <li><a href="booking.php" class="text-white"><i class="fas fa-ticket-alt me-2"></i>Book Ticket</a></li>
                        <li><a href="contact.php" class="text-white"><i class="fas fa-envelope me-2"></i>Contact Us</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 mb-4">
                    <h5>Contact</h5>
                    <address>
                        <i class="fas fa-map-marker-alt me-2"></i> Ceres Terminal, Iloilo City<br>
                        <i class="fas fa-phone me-2"></i> (033) 337-8888<br>
                        <i class="fas fa-envelope me-2"></i> isatucommuters@ceresbus.com
                    </address>
                </div>
            </div>
            <hr class="bg-light">
            <div class="text-center">
                <p class="copyright mb-0">&copy; 2025 Ceres Bus Terminal - ISAT-U Commuters Ticket System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('new_password').addEventListener('input', function() {
            var password = this.value;
            var strength = 0;
            
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[$@#&!]+/)) strength++;

        });

        // Confirm password validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            var password = document.getElementById('new_password').value;
            var confirmPassword = this.value;
            
            if (password !== confirmPassword) {
                this.setCustomValidity("Passwords don't match");
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>