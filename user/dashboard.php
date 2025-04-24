<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="css/user.css">
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
                        <a class="nav-link active" href="index.php">Home</a>
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
                    <li class="nav-item">
                        <a class="nav-link" href="contact.php">Contact</a>
                    </li>
                    <li class="nav-item ms-2">
                        <a class="btn btn-outline-light" href="login.php">Login</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
            <div class="hero-section">
        <div class="container text-center">
            <h1>Ceres Bus Ticket System for ISAT-U Commuters</h1>
            <p class="lead">Book your Ceres bus commute tickets with ease and comfort</p>
            <a href="booking.php" class="btn btn-warning btn-lg mt-3">
                <i class="fas fa-ticket-alt me-2"></i>Book Your Ticket Now
            </a>
        </div>
    </div>

    <div class="container">
        <!-- Announcement -->
        <div class="announcement">
            <div class="d-flex align-items-center">
                <i class="fas fa-bullhorn me-3 fs-4"></i>
                <div>
                    <strong>Important Notice:</strong> Additional buses available for finals week. Please book your tickets in advance!
                </div>
            </div>
        </div>

        <!-- Quick Booking Form -->
        <div class="quick-booking">
            <h3 class="mb-4"><i class="fas fa-search me-2"></i>Search for Available Buses</h3>
            <form class="row g-3">
                <div class="col-md-4">
                    <label for="origin" class="form-label">From</label>
                    <select class="form-select" id="origin" required>
                        <option value="" selected disabled>Select Origin</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="destination" class="form-label">To</label>
                    <select class="form-select" id="destination" required>
                        <option value="" selected disabled>Select Destination</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="travel-date" class="form-label">Date</label>
                    <input type="date" class="form-control" id="travel-date" required>
                </div>
                <div class="col-12 text-center">
                    <button type="submit" class="btn btn-warning mt-2">
                        <i class="fas fa-search me-2"></i>Search Buses
                    </button>
                </div>
            </form>
        </div>

        <!-- Feature Cards -->
        <h2 class="text-center mb-4">Our Services</h2>
        <div class="row">
            <!-- Route Card -->
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="card-header text-center">
                        <i class="fas fa-route"></i> Routes
                    </div>
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="fas fa-map-marked-alt"></i>
                        </div>
                        <h5 class="card-title">View Available Routes</h5>
                        <p class="card-text">Check all available routes connecting key locations.</p>
                        <a href="routes.php" class="btn btn-outline-warning text-dark">Explore Routes</a>
                    </div>
                </div>
            </div>
            
            <!-- Schedule Card -->
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="card-header text-center">
                        <i class="fas fa-calendar-alt"></i> Schedule
                    </div>
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h5 class="card-title">Bus Schedules</h5>
                        <p class="card-text">View departure and arrival times for all Ceres bus routes.</p>
                        <a href="schedule.php" class="btn btn-outline-warning text-dark">Check Schedules</a>
                    </div>
                </div>
            </div>
            
            <!-- Booking Card -->
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="card-header text-center">
                        <i class="fas fa-ticket-alt"></i> Booking
                    </div>
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <h5 class="card-title">Book Your Ticket</h5>
                        <p class="card-text">Reserve your seat in advance with  easy booking system.</p>
                        <a href="booking.php" class="btn btn-warning text-dark">Book Now</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <!-- Location Card -->
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="card-header text-center">
                        <i class="fas fa-map-marker-alt"></i> Locations
                    </div>
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="fas fa-map"></i>
                        </div>
                        <h5 class="card-title">Bus Stop Locations</h5>
                        <p class="card-text">Find detailed information about all bus stops and terminals.</p>
                        <a href="locations.php" class="btn btn-outline-warning text-dark">View Locations</a>
                    </div>
                </div>
            </div>
            
            <!-- Fare Card -->
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="card-header text-center">
                        <i class="fas fa-money-bill-wave"></i> Fares
                    </div>
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="fas fa-coins"></i>
                        </div>
                        <h5 class="card-title">Ticket Fares</h5>
                        <p class="card-text">Check ticket prices for different routes and plan your budget.</p>
                        <a href="fares.php" class="btn btn-outline-warning text-dark">View Fares</a>
                    </div>
                </div>
            </div>
            
            <!-- Contact Card -->
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="card-header text-center">
                        <i class="fas fa-address-book"></i> Contact
                    </div>
                    <div class="card-body text-center">
                        <div class="feature-icon">
                            <i class="fas fa-headset"></i>
                        </div>
                        <h5 class="card-title">Need Help?</h5>
                        <p class="card-text">Reach out to our support team for assistance with your booking.</p>
                        <a href="contact.php" class="btn btn-outline-warning text-dark">Contact Us</a>
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
                        <li><a href="routes.php" class="text-white">Routes</a></li>
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