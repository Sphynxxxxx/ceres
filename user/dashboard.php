<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Redirect to login page
    header("Location: login.php");
    exit;
}

// Get user info from session
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];

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

// Fetch user data from database
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $user_data = $result->fetch_assoc();
        } else {
            // User not found in database
            session_destroy();
            header("Location: login.php?error=invalid_user");
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
    <title>Dashboard - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="css/user.css">
    <style>
        .feature-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .feature-card .card-header {
            background: #1d3557;
            color: white;
            padding: 12px;
            text-align: center;
            font-weight: 600;
        }
        
        .feature-card .card-body {
            padding: 20px;
            text-align: center;
        }
        
        .feature-icon {
            font-size: 3rem;
            color: #1d3557;
            margin-bottom: 15px;
        }
        
        .feature-card .btn-warning {
            background-color: #ffb100;
            border-color: #ffb100;
        }
        
        .feature-card .btn-outline-warning {
            color: #212529;
            border-color: #ffb100;
        }
        
        .feature-card .btn-outline-warning:hover {
            background-color: #ffb100;
            border-color: #ffb100;
            color: #212529;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-bus-alt me-2"></i>Ceres Bus for ISAT-U Commuters
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="tabs/routes.php">Routes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php">Schedule</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="booking.php">Book Ticket</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contact.php">Contact</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($user_name); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item active" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>My Profile</a></li>
                            <li><a class="dropdown-item" href="my_bookings.php"><i class="fas fa-ticket-alt me-2"></i>My Bookings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-circle me-2"></i>Account Menu</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <a href="dashboard.php" class="list-group-item list-group-item-action active">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                            <a href="profile.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-user me-2"></i>My Profile
                            </a>
                            <a href="my_bookings.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-ticket-alt me-2"></i>My Bookings
                            </a>
                            <a href="book_ticket.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-plus-circle me-2"></i>Book New Ticket
                            </a>
                            <a href="logout.php" class="list-group-item list-group-item-action text-danger">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Account Info</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="rounded-circle bg-light d-inline-flex justify-content-center align-items-center" style="width: 80px; height: 80px;">
                                <i class="fas fa-user fa-2x text-secondary"></i>
                            </div>
                        </div>
                        <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($user_name); ?></p>
                        <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($user_email); ?></p>
                        <p class="mb-0"><strong>Account Type:</strong> Commuter</p>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i> Welcome to your ISAT-U Ceres Bus Commuter dashboard. From here, you can manage your bookings and account information.
                        </div>
                        
                        <!-- Feature Cards (First row) -->
                        <div class="row">
                            <!-- Routes Card -->
                            <div class="col-md-4">
                                <div class="feature-card">
                                    <div class="card-header">
                                        <i class="fas fa-route"></i> Routes
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-map-marked-alt"></i>
                                        </div>
                                        <h5>View Available Routes</h5>
                                        <p>Check all available routes connecting key locations.</p>
                                        <a href="tabs/routes.php" class="btn btn-warning">Explore Routes</a>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Schedule Card -->
                            <div class="col-md-4">
                                <div class="feature-card">
                                    <div class="card-header">
                                        <i class="fas fa-calendar-alt"></i> Schedule
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                        <h5>Bus Schedules</h5>
                                        <p>View departure and arrival times for all Ceres bus routes.</p>
                                        <a href="schedule.php" class="btn btn-warning">Check Schedules</a>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Booking Card -->
                            <div class="col-md-4">
                                <div class="feature-card">
                                    <div class="card-header">
                                        <i class="fas fa-ticket-alt"></i> Booking
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-clipboard-check"></i>
                                        </div>
                                        <h5>Book Your Ticket</h5>
                                        <p>Reserve your seat in advance with easy booking system.</p>
                                        <a href="booking.php" class="btn btn-warning">Book Now</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Feature Cards (Second row) -->
                        <div class="row">
                            <!-- Locations Card -->
                            <div class="col-md-4">
                                <div class="feature-card">
                                    <div class="card-header">
                                        <i class="fas fa-map-marker-alt"></i> Locations
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-map"></i>
                                        </div>
                                        <h5>Bus Stop Locations</h5>
                                        <p>Find detailed information about all bus stops and terminals.</p>
                                        <a href="locations.php" class="btn btn-outline-warning">View Locations</a>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Fares Card -->
                            <div class="col-md-4">
                                <div class="feature-card">
                                    <div class="card-header">
                                        <i class="fas fa-money-bill-wave"></i> Fares
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-coins"></i>
                                        </div>
                                        <h5>Ticket Fares</h5>
                                        <p>Check ticket prices for different routes and plan your budget.</p>
                                        <a href="fares.php" class="btn btn-outline-warning">View Fares</a>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Contact Card -->
                            <div class="col-md-4">
                                <div class="feature-card">
                                    <div class="card-header">
                                        <i class="fas fa-address-book"></i> Contact
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-headset"></i>
                                        </div>
                                        <h5>Need Help?</h5>
                                        <p>Reach out to our support team for assistance with your booking.</p>
                                        <a href="contact.php" class="btn btn-outline-warning">Contact Us</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Upcoming Trips</h5>
                            </div>
                            <div class="card-body">
                                <div class="text-center py-4">
                                    <i class="fas fa-bus fa-3x text-muted mb-3"></i>
                                    <p>No upcoming trips found.</p>
                                    <a href="book_ticket.php" class="btn btn-warning">Book a Trip</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Announcements</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mb-0">
                            <h5 class="alert-heading"><i class="fas fa-bullhorn me-2"></i>Additional Trips Available</h5>
                            <p>Additional buses have been scheduled for the upcoming finals week to accommodate increased commuter traffic. Please book your tickets in advance!</p>
                            <hr>
                            <p class="mb-0">For any inquiries, please contact our support team at (033) 337-8888.</p>
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
                <div class="col-md-4 mb-3">
                    <h5>Ceres Bus Ticket System for ISAT-U Commuters</h5>
                    <p>Providing convenient Ceres bus transportation booking for ISAT-U students, faculty, and staff commuters.</p>
                </div>
                <div class="col-md-4 mb-3">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="tabs/routes.php" class="text-white">Routes</a></li>
                        <li><a href="schedule.php" class="text-white">Schedule</a></li>
                        <li><a href="booking.php" class="text-white">Book Ticket</a></li>
                        <li><a href="contact.php" class="text-white">Contact Us</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-3">
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
                <p>&copy; 2025 Ceres Bus Terminal - ISAT-U Commuters Ticket System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>