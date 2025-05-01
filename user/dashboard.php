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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="css/user.css" rel="stylesheet">
    <link href="css/navfot.css" rel="stylesheet">
    
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-bus-alt me-2"></i>Ceres Bus for ISAT-U Commuters
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="tabs/routes.php">Routes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="tabs/schedule.php">Schedule</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="tabs/booking.php">Book Ticket</a>
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
                            <a href="dashboard.php" class="list-group-item list-group-item-action active">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                            <a href="profile.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-user me-2"></i>My Profile
                            </a>
                            <a href="tabs/mybookings.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-ticket-alt me-2"></i>My Bookings
                            </a>
                            <a href="tabs/booking.php" class="list-group-item list-group-item-action">
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
                        <div class="user-profile">
                            <div class="user-avatar">
                                <i class="fas fa-user fa-2x text-light"></i>
                            </div>
                            <div class="user-info">
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($user_name); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($user_email); ?></p>
                                <p class="mb-0"><strong>Account Type:</strong> Commuter</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="col-lg-9">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i> Welcome to your ISAT-U Ceres Bus Commuter dashboard. From here, you can manage your bookings and account information.
                        </div>

                        <!-- Feature Cards Row 1 -->
                        <div class="row g-4">
                            <!-- Routes Card -->
                            <div class="col-md-4">
                                <div class="feature-card card h-100">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-route me-2"></i>Routes</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-map-marked-alt"></i>
                                        </div>
                                        <h5>View Available Routes</h5>
                                        <p>Check all available routes connecting key locations.</p>
                                        <a href="tabs/routes.php" class="btn btn-warning w-100">Explore Routes</a>
                                    </div>
                                </div>
                            </div>

                            <!-- Schedule Card -->
                            <div class="col-md-4">
                                <div class="feature-card card h-100">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Schedule</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                        <h5>Bus Schedules</h5>
                                        <p>View departure and arrival times for all Ceres bus routes.</p>
                                        <a href="tabs/schedule.php" class="btn btn-warning w-100">Check Schedules</a>
                                    </div>
                                </div>
                            </div>

                            <!-- Booking Card -->
                            <div class="col-md-4">
                                <div class="feature-card card h-100">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-ticket-alt me-2"></i>Booking</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-clipboard-check"></i>
                                        </div>
                                        <h5>Book Your Ticket</h5>
                                        <p>Reserve your seat in advance with easy booking system.</p>
                                        <a href="tabs/booking.php" class="btn btn-warning w-100">Book Now</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Feature Cards Row 2 -->
                        <div class="row g-4 mt-2">
                            <!-- Locations Card -->
                            <div class="col-md-4">
                                <div class="feature-card card h-100">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>Locations</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-map"></i>
                                        </div>
                                        <h5>Bus Stop Locations</h5>
                                        <p>Find detailed information about all bus stops and terminals.</p>
                                        <a href="locations.php" class="btn btn-outline-warning w-100">View Locations</a>
                                    </div>
                                </div>
                            </div>

                            <!-- Fares Card -->
                            <div class="col-md-4">
                                <div class="feature-card card h-100">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Fares</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-coins"></i>
                                        </div>
                                        <h5>Ticket Fares</h5>
                                        <p>Check ticket prices for different routes and plan your budget.</p>
                                        <a href="fares.php" class="btn btn-outline-warning w-100">View Fares</a>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact Card -->
                            <div class="col-md-4">
                                <div class="feature-card card h-100">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-address-book me-2"></i>Contact</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="feature-icon">
                                            <i class="fas fa-headset"></i>
                                        </div>
                                        <h5>Need Help?</h5>
                                        <p>Reach out to our support team for assistance with your booking.</p>
                                        <a href="contact.php" class="btn btn-outline-warning w-100">Contact Us</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Upcoming Trips -->
                        <!--<div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Upcoming Trips</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="empty-state">
                                    <i class="fas fa-bus fa-3x mb-3"></i>
                                    <p>No upcoming trips found.</p>
                                    <a href="book_ticket.php" class="btn btn-warning">Book a Trip</a>
                                </div>
                            </div>
                        </div> -->

                        <!-- Announcements -->
                        <div class="card mt-4">
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
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5>Ceres Bus Ticket System for ISAT-U Commuters</h5>
                    <p>Providing convenient Ceres bus transportation booking for ISAT-U students, faculty, and staff commuters.</p>
                </div>
                <div class="col-lg-4 mb-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="tabs/routes.php" class="text-white"><i class="fas fa-route me-2"></i>Routes</a></li>
                        <li><a href="tabs/schedule.php" class="text-white"><i class="fas fa-calendar-alt me-2"></i>Schedule</a></li>
                        <li><a href="tabs/booking.php" class="text-white"><i class="fas fa-ticket-alt me-2"></i>Book Ticket</a></li>
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
                <p class="copyright">&copy; 2025 Ceres Bus Terminal - ISAT-U Commuters Ticket System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>